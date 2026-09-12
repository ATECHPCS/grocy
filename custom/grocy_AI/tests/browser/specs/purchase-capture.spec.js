const { test, expect } = require('@playwright/test');

// Mobile acceptance for the Phase 8 purchase-capture surface (CAP-01..CAP-05): the STOCK_PURCHASE-gated
// scan loop (capture.js) and the review + single-commit screen (capture-review.js). The capture API is
// mocked per test with page.route so the shared support/server.mjs stays stateless; the fixture harness
// (capture-harness.js) throws on any non-capture mutation, so every test also proves the surface books no
// stock while it scans and reviews. Only the final Commit writes, and it goes through the capture endpoint.

const TRIP_KEYS = ['id', 'created_at', 'created_by', 'status', 'default_location_id', 'default_shopping_location_id', 'transaction_id', 'committed_at', 'checksum', 'module_version'];
const LINE_KEYS = ['id', 'trip_id', 'seq', 'scanned_barcode', 'canonical_gtin', 'resolved_product_id', 'status', 'quantity', 'price', 'best_before_override', 'selected', 'applied_at', 'outcome', 'created_at', 'updated_at'];

const CHECKSUM = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
const KNOWN_GTIN = '012345678905';
const UNKNOWN_GTIN = '036000291452';

function makeTrip(overrides)
{
	return Object.assign({
		id: 7,
		created_at: '2026-09-02T10:00:00+00:00',
		created_by: 42,
		status: 'open',
		default_location_id: null,
		default_shopping_location_id: null,
		transaction_id: null,
		committed_at: null,
		checksum: null,
		module_version: '2.5.0'
	}, overrides || {});
}

function makeLine(overrides)
{
	return Object.assign({
		id: 100,
		trip_id: 7,
		seq: 1,
		scanned_barcode: '',
		canonical_gtin: null,
		resolved_product_id: null,
		status: 'unknown',
		quantity: 1,
		price: null,
		best_before_override: null,
		selected: 0,
		applied_at: null,
		outcome: null,
		created_at: '2026-09-02T10:00:00+00:00',
		updated_at: '2026-09-02T10:00:00+00:00'
	}, overrides || {});
}

function json(route, body, status)
{
	return route.fulfill({
		status: status || 200,
		contentType: 'application/json; charset=utf-8',
		headers: { 'Cache-Control': 'no-store' },
		body: JSON.stringify(body)
	});
}

// Read-only reference lookups the pages make through Grocy.Api.Get (product names, locations, stores).
async function installReferenceApi(page)
{
	await page.route('**/api/objects/**', function (route)
	{
		const pathname = new URL(route.request().url()).pathname;
		if (/\/api\/objects\/products\/101$/.test(pathname))
		{
			return json(route, { id: 101, name: 'Fixture oats' });
		}
		if (/\/api\/objects\/products\/102$/.test(pathname))
		{
			return json(route, { id: 102, name: 'Fixture beans' });
		}
		if (pathname.endsWith('/api/objects/locations'))
		{
			return json(route, [{ id: 1, name: 'Pantry' }, { id: 2, name: 'Fridge' }]);
		}
		if (pathname.endsWith('/api/objects/shopping_locations'))
		{
			return json(route, [{ id: 3, name: 'Corner store' }]);
		}
		return json(route, []);
	});
}

// Scan-loop backend: a fresh trip, then one coalesced line per barcode (a repeat scan increments quantity).
async function installScanApi(page, options)
{
	const settings = options || {};
	const state = { seq: 0, byBarcode: {}, scans: 0 };
	await page.route('**/api/grocy-ai/capture/**', function (route)
	{
		const request = route.request();
		const method = request.method();
		const pathname = new URL(request.url()).pathname;

		if (method === 'POST' && /\/capture\/trips$/.test(pathname))
		{
			return json(route, makeTrip({ status: 'open' }));
		}

		if (method === 'POST' && /\/capture\/trips\/\d+\/scan$/.test(pathname))
		{
			state.scans++;
			const barcode = (JSON.parse(request.postData() || '{}').barcode) || '';
			if (settings.malformed)
			{
				const broken = makeLine({ scanned_barcode: barcode, status: 'unknown' });
				delete broken.updated_at;
				return json(route, broken);
			}
			let entry = state.byBarcode[barcode];
			if (!entry)
			{
				state.seq++;
				entry = { id: 100 + state.seq, seq: state.seq, quantity: 0 };
				state.byBarcode[barcode] = entry;
			}
			entry.quantity++;
			const known = barcode === KNOWN_GTIN;
			return json(route, makeLine({
				id: entry.id,
				seq: entry.seq,
				scanned_barcode: barcode,
				canonical_gtin: known ? '00012345678905' : null,
				resolved_product_id: known ? 101 : null,
				status: known ? 'known' : 'unknown',
				quantity: entry.quantity,
				selected: known ? 1 : 0
			}));
		}

		return json(route, { message: 'unexpected capture call' }, 404);
	});
	await installReferenceApi(page);
	return state;
}

// Review + commit backend. One reviewing trip with the supplied lines; commit succeeds only when the
// echoed checksum matches, mirroring the optimistic-concurrency guard the controller enforces.
async function installReviewApi(page, options)
{
	const settings = options || {};
	const lines = settings.lines || [makeLine({
		id: 101, seq: 1, scanned_barcode: KNOWN_GTIN, canonical_gtin: '00012345678905',
		resolved_product_id: 101, status: 'known', quantity: 2, selected: 1
	})];
	const state = { committed: false, commits: 0, lastCommitStatus: null, lines: lines };

	await page.route('**/api/grocy-ai/capture/**', function (route)
	{
		const request = route.request();
		const method = request.method();
		const pathname = new URL(request.url()).pathname;

		if (method === 'GET' && /\/capture\/trips$/.test(pathname))
		{
			return json(route, { trips: [makeTrip({ status: state.committed ? 'committed' : 'reviewing' })] });
		}

		if (method === 'GET' && /\/capture\/trips\/\d+$/.test(pathname))
		{
			const trip = makeTrip({
				status: state.committed ? 'committed' : 'reviewing',
				transaction_id: state.committed ? 'txn-1001' : null,
				committed_at: state.committed ? '2026-09-02T10:05:00+00:00' : null,
				checksum: CHECKSUM
			});
			const rendered = state.committed
				? state.lines.map(function (line) { return Object.assign({}, line, { applied_at: '2026-09-02T10:05:00+00:00', outcome: 'applied' }); })
				: state.lines;
			return json(route, { trip: trip, lines: rendered, checksum: CHECKSUM });
		}

		const commit = /\/capture\/trips\/\d+\/commit$/.exec(pathname);
		if (method === 'POST' && commit)
		{
			state.commits++;
			const sent = JSON.parse(request.postData() || '{}').confirmed_checksum;
			if (settings.forceMismatch || sent !== CHECKSUM)
			{
				state.lastCommitStatus = 409;
				return json(route, { outcome: 'checksum_mismatch', applied: 0, conflicted: 0, transaction_id: null }, 409);
			}
			state.committed = true;
			state.lastCommitStatus = 200;
			return json(route, { outcome: 'committed', applied: 1, conflicted: 0, transaction_id: 'txn-1001' });
		}

		const lineEdit = /\/capture\/trips\/\d+\/lines\/(\d+)$/.exec(pathname);
		if (method === 'PUT' && lineEdit)
		{
			const body = JSON.parse(request.postData() || '{}');
			state.lines = state.lines.map(function (line)
			{
				if (String(line.seq) !== lineEdit[1])
				{
					return line;
				}
				const next = Object.assign({}, line);
				if (Object.prototype.hasOwnProperty.call(body, 'quantity')) { next.quantity = body.quantity; }
				if (Object.prototype.hasOwnProperty.call(body, 'selected')) { next.selected = body.selected ? 1 : 0; }
				if (Object.prototype.hasOwnProperty.call(body, 'price')) { next.price = body.price; }
				return next;
			});
			return json(route, { trip: makeTrip({ status: 'reviewing', checksum: CHECKSUM }), lines: state.lines });
		}

		if (method === 'PUT' && /\/capture\/trips\/\d+$/.test(pathname))
		{
			return json(route, makeTrip({ status: 'reviewing', checksum: CHECKSUM }));
		}

		return json(route, { message: 'unexpected capture call' }, 404);
	});
	await installReferenceApi(page);
	return state;
}

async function expectNoForbiddenWrites(page)
{
	const forbidden = await page.evaluate(function () { return window.__captureFixture.forbiddenWrite; });
	expect(forbidden, 'the capture surface must never write outside its own /capture endpoints').toBeNull();
}

test.describe('purchase capture — scan loop', function ()
{
	test('@cap @mob @smoke phone scan loop appends known and unknown items and writes no stock', async function ({ page })
	{
		await installScanApi(page);
		await page.goto('/fixtures/capture.html');

		const status = page.locator('#grocyai-capture-status');
		await expect(status).toHaveText('Trip started. Scan or enter a GTIN to add items.');

		const input = page.locator('#grocyai-capture-barcode');
		await input.fill(KNOWN_GTIN);
		await input.press('Enter');
		const knownRow = page.locator('.grocy-ai-capture-line.status-known');
		await expect(knownRow.locator('.grocy-ai-capture-line-name')).toHaveText('Fixture oats');
		await expect(knownRow.locator('.grocy-ai-capture-line-quantity')).toHaveText('× 1');

		await input.fill(UNKNOWN_GTIN);
		await input.press('Enter');
		const unknownRow = page.locator('.grocy-ai-capture-line.status-unknown');
		await expect(unknownRow.locator('.grocy-ai-capture-line-name')).toHaveText('Unknown — needs product');

		// A repeat scan of the same GTIN coalesces into the existing line and increments its quantity.
		await input.fill(KNOWN_GTIN);
		await input.press('Enter');
		await expect(page.locator('.grocy-ai-capture-line.status-known')).toHaveCount(1);
		await expect(knownRow.locator('.grocy-ai-capture-line-quantity')).toHaveText('× 2');

		await expectNoForbiddenWrites(page);
		expect(await page.evaluate(function () { return window.__captureFixture.captureWrites; })).toBeGreaterThan(0);
	});

	test('@cap @mob a camera scan is added exactly like a manual entry, and a foreign target is ignored', async function ({ page })
	{
		await installScanApi(page);
		await page.goto('/fixtures/capture.html');
		await expect(page.locator('#grocyai-capture-status')).toHaveText('Trip started. Scan or enter a GTIN to add items.');

		await page.evaluate(function (gtin) { window.Grocy.BarcodeScanned(gtin, 'grocyai-capture-barcode'); }, KNOWN_GTIN);
		await expect(page.locator('.grocy-ai-capture-line.status-known .grocy-ai-capture-line-name')).toHaveText('Fixture oats');

		// A scan aimed at another input's target must not add a line to this page.
		await page.evaluate(function (gtin) { window.Grocy.BarcodeScanned(gtin, 'some-other-input'); }, UNKNOWN_GTIN);
		await expect(page.locator('.grocy-ai-capture-line')).toHaveCount(1);
		await expectNoForbiddenWrites(page);
	});

	test('@cap @mob the GTIN input and primary action keep a 44px touch target on phone widths', async function ({ page })
	{
		await installScanApi(page);
		for (const width of [320, 375, 390])
		{
			await page.setViewportSize({ width: width, height: 844 });
			await page.goto('/fixtures/capture.html');
			const inputBox = await page.locator('#grocyai-capture-barcode').boundingBox();
			expect(inputBox, width + 'px GTIN input must render').not.toBeNull();
			expect(inputBox.height, width + 'px GTIN input height').toBeGreaterThanOrEqual(44);
			const addBox = await page.locator('#grocyai-capture-add-button').boundingBox();
			expect(addBox.height, width + 'px Add button height').toBeGreaterThanOrEqual(44);
		}
	});

	test('@cap a malformed scan payload is rejected and never renders a line', async function ({ page })
	{
		await installScanApi(page, { malformed: true });
		await page.goto('/fixtures/capture.html');
		await expect(page.locator('#grocyai-capture-status')).toHaveText('Trip started. Scan or enter a GTIN to add items.');

		const input = page.locator('#grocyai-capture-barcode');
		await input.fill(KNOWN_GTIN);
		await input.press('Enter');

		await expect(page.locator('#grocyai-capture-status')).toHaveText('That scan could not be added. Try again.');
		await expect(page.locator('.grocy-ai-capture-line')).toHaveCount(0);
		await expect(page.locator('.grocy-ai-capture-empty')).toBeVisible();
		await expectNoForbiddenWrites(page);
	});
});

test.describe('purchase capture — review and commit', function ()
{
	test('@cap @smoke reviewing a trip and committing books one purchase and archives the trip', async function ({ page })
	{
		const state = await installReviewApi(page);
		await page.goto('/fixtures/capture-review.html');

		const tripButton = page.locator('#grocyai-capture-review-trips button').first();
		await expect(tripButton).toContainText('#7');
		await tripButton.click();

		const detail = page.locator('#grocyai-capture-review-detail');
		await expect(detail.locator('.grocy-ai-capture-review-line-name')).toHaveText('Fixture oats');
		await expect(detail.locator('.grocy-ai-capture-review-quantity input')).toHaveValue('2');

		page.once('dialog', function (dialog) { return dialog.accept(); });
		await page.locator('#grocyai-capture-review-commit').click();

		await expect(detail.locator('.alert-success')).toHaveText('Committed — transaction txn-1001');
		expect(state.commits, 'commit must be posted exactly once').toBe(1);
		expect(state.lastCommitStatus).toBe(200);
		await expectNoForbiddenWrites(page);
	});

	test('@cap a checksum mismatch refuses the commit and leaves the trip uncommitted', async function ({ page })
	{
		const state = await installReviewApi(page, { forceMismatch: true });
		await page.goto('/fixtures/capture-review.html');

		await page.locator('#grocyai-capture-review-trips button').first().click();
		const detail = page.locator('#grocyai-capture-review-detail');
		await expect(detail.locator('#grocyai-capture-review-commit')).toBeVisible();

		page.once('dialog', function (dialog) { return dialog.accept(); });
		await detail.locator('#grocyai-capture-review-commit').click();

		// The refusal returns 409 and the trip stays reviewing: the commit button remains and no success shows.
		await expect.poll(function () { return state.lastCommitStatus; }).toBe(409);
		await expect(detail.locator('#grocyai-capture-review-commit')).toBeVisible();
		await expect(detail.locator('.alert-success')).toHaveCount(0);
		expect(state.committed).toBe(false);
		await expectNoForbiddenWrites(page);
	});

	test('@cap adjusting a line quantity persists through the capture endpoint without touching stock', async function ({ page })
	{
		await installReviewApi(page);
		await page.goto('/fixtures/capture-review.html');
		await page.locator('#grocyai-capture-review-trips button').first().click();

		const quantity = page.locator('[data-line-seq="1"] .grocy-ai-capture-review-quantity input');
		await expect(quantity).toHaveValue('2');
		await page.locator('[data-line-seq="1"] .input-group-append button').click();
		await expect(quantity).toHaveValue('3');
		await expectNoForbiddenWrites(page);
	});

	test('@cap an unknown line offers a permission-gated create-product hand-off to the enrichment flow', async function ({ page })
	{
		await installReviewApi(page, {
			lines: [makeLine({ id: 200, seq: 1, scanned_barcode: UNKNOWN_GTIN, status: 'unknown', quantity: 1 })]
		});
		await page.goto('/fixtures/capture-review.html');
		await page.locator('#grocyai-capture-review-trips button').first().click();

		const createLink = page.locator('#grocyai-capture-review-detail a.permission-MASTER_DATA_EDIT');
		await expect(createLink).toHaveText('Create product');
		await expect(createLink).toHaveAttribute('href', '/product/new?barcode=' + UNKNOWN_GTIN);
		await expectNoForbiddenWrites(page);
	});
});
