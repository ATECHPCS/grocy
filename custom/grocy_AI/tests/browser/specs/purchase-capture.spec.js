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
	const state = { seq: 0, byBarcode: {}, scans: 0, tripReads: 0, failScans: settings.failScans || 0, savedResponseFailures: settings.savedResponseFailures || 0, tripId: 6, tripCreations: 0, finishUpdates: 0 };
	await page.route('**/api/grocy-ai/capture/**', function (route)
	{
		const request = route.request();
		const method = request.method();
		const pathname = new URL(request.url()).pathname;

		if (method === 'POST' && /\/capture\/trips$/.test(pathname))
		{
			state.tripCreations++;
			state.tripId++;
			return json(route, makeTrip({ id: state.tripId, status: 'open' }));
		}

		if (method === 'GET' && /\/capture\/trips\/12$/.test(pathname))
		{
			if (settings.resumeUnavailable) return json(route, { error_message: 'Unknown trip' }, 404);
			return json(route, {
				trip: makeTrip({ id: 12, status: settings.resumeClosed ? 'reviewing' : 'open' }),
				lines: [makeLine({ id: 201, trip_id: 12, seq: 1, scanned_barcode: KNOWN_GTIN, canonical_gtin: '00012345678905', status: 'known', resolved_product_id: 101, quantity: 2, selected: 1 })]
			});
		}
		if (method === 'GET' && /\/capture\/trips\/\d+$/.test(pathname))
		{
			state.tripReads++;
			return json(route, {
				trip: makeTrip({ id: state.tripId, status: 'open' }),
				lines: Object.entries(state.byBarcode).map(function ([barcode, entry])
				{
					const known = barcode === KNOWN_GTIN;
					return makeLine({ id: entry.id, trip_id: state.tripId, seq: entry.seq, scanned_barcode: barcode,
						canonical_gtin: barcode.padStart(14, '0'), resolved_product_id: known ? 101 : null,
						status: known ? 'known' : 'unknown', quantity: entry.quantity, selected: known ? 1 : 0 });
				})
			});
		}

		if (method === 'POST' && /\/capture\/trips\/\d+\/scan$/.test(pathname))
		{
			state.scans++;
			if (settings.holdScan && !state.releaseScan)
			{
				return new Promise(function (resolve) { state.releaseScan = resolve; }).then(function () { return json(route, { error_message: 'Temporary scan failure' }, 503); });
			}
			const barcode = (JSON.parse(request.postData() || '{}').barcode) || '';
			if (state.failScans > 0)
			{
				state.failScans--;
				return json(route, { error_message: 'Temporary scan failure' }, 503);
			}
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
			if (state.savedResponseFailures > 0)
			{
				state.savedResponseFailures--;
				return json(route, { error_message: 'Response lost after save' }, 503);
			}
			const known = barcode === KNOWN_GTIN;
			return json(route, makeLine({
				id: entry.id,
				seq: entry.seq,
				scanned_barcode: barcode,
				canonical_gtin: barcode.padStart(14, '0'),
				resolved_product_id: known ? 101 : null,
				status: known ? 'known' : 'unknown',
				quantity: entry.quantity,
				selected: known ? 1 : 0
			}));
		}

		if (method === 'PUT' && /\/capture\/trips\/\d+$/.test(pathname))
		{
			if (JSON.stringify(JSON.parse(request.postData() || '{}')) !== JSON.stringify({ status: 'reviewing' }))
			{
				return json(route, { message: 'unexpected trip update' }, 400);
			}
			state.finishUpdates++;
			return json(route, makeTrip({ id: state.tripId, status: 'reviewing' }));
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
	const state = { committed: false, reviewing: !settings.open, finishUpdates: 0, commits: 0, lastCommitStatus: null, lines: lines };

	await page.route('**/api/grocy-ai/capture/**', function (route)
	{
		const request = route.request();
		const method = request.method();
		const pathname = new URL(request.url()).pathname;

		if (method === 'GET' && /\/capture\/trips$/.test(pathname))
		{
			return json(route, { trips: [makeTrip({ status: state.committed ? 'committed' : (state.reviewing ? 'reviewing' : 'open') })] });
		}

		if (method === 'GET' && /\/capture\/trips\/\d+$/.test(pathname))
		{
			const trip = makeTrip({
				status: state.committed ? 'committed' : (state.reviewing ? 'reviewing' : 'open'),
				transaction_id: state.committed ? 'txn-1001' : null,
				committed_at: state.committed ? '2026-09-02T10:05:00+00:00' : null,
				checksum: CHECKSUM
			});
			const rendered = state.committed
				? state.lines.map(function (line) { return Object.assign({}, line, { applied_at: '2026-09-02T10:05:00+00:00', outcome: 'applied' }); })
				: state.lines;
			return json(route, { trip: trip, lines: rendered, checksum: CHECKSUM });
		}

		if (method === 'GET' && pathname.endsWith('/receipt-readiness')) return json(route, { ready: true, reasons: [], receipts: [] });

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
			const body = JSON.parse(request.postData() || '{}');
			if (body.status === 'reviewing' && !state.reviewing)
			{
				state.finishUpdates++;
				state.reviewing = true;
			}
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
	test('@cap @mob main capture resumes the saved nonempty trip when the URL has no trip query', async function ({ page })
	{
		const state = await installScanApi(page);
		await page.goto('/fixtures/capture.html?saved=12');
		await expect(page.locator('#grocyai-capture-status')).toContainText('Trip #12 resumed');
		await expect(page.locator('.grocy-ai-capture-line')).toHaveCount(1);
		await expect(page.locator('#grocyai-capture-review-link')).toHaveAttribute('href', '/grocyai/capture/review?trip=12');
		expect(state.tripCreations).toBe(0);
	});

	test('@cap @mob resumes an existing open trip without creating a new one', async function ({ page })
	{
		const state = await installScanApi(page);
		await page.goto('/fixtures/capture.html?trip=12');
		await expect(page.locator('#grocyai-capture-status')).toContainText('Trip #12');
		await expect(page.locator('.grocy-ai-capture-line')).toHaveCount(1);
		await expect(page.locator('.grocy-ai-capture-line-quantity')).toHaveText('× 2');
		await expect(page.locator('.grocy-ai-capture-line-barcode')).toHaveText('UPC ' + KNOWN_GTIN);
		await expect(page.locator('#grocyai-capture-review-link')).toHaveAttribute('href', '/grocyai/capture/review?trip=12');
		expect(state.tripCreations).toBe(0);
		await expectNoForbiddenWrites(page);
	});

	test('@cap @mob refuses a closed or missing resume trip without creating a replacement', async function ({ page })
	{
		const state = await installScanApi(page, { resumeClosed: true });
		await page.goto('/fixtures/capture.html?trip=12');
		await expect(page.locator('#grocyai-capture-status')).toContainText('cannot be resumed');
		expect(state.tripCreations).toBe(0);
		await page.locator('#grocyai-capture-new-trip-button').click();
		await expect(page.locator('#grocyai-capture-review-link')).toHaveAttribute('href', '/grocyai/capture/review?trip=7');
		expect(state.tripCreations).toBe(1);
	});

	test('@cap @mob Finish scanning requires two confirmations, writes no stock, then opens review', async function ({ page })
	{
		const state = await installScanApi(page);
		await page.route('**/grocyai/capture/review?trip=*', function (route)
		{
			return route.fulfill({ status: 200, contentType: 'text/html', body: '<title>Review trip</title>' });
		});
		await page.goto('/fixtures/capture.html');
		await expect(page.locator('#grocyai-capture-status')).toHaveText('Trip started. Scan or enter a GTIN to add items.');
		const finish = page.locator('#grocyai-capture-finish-button');
		let decisions = [false];
		let dialogs = 0;
		const prompts = [];
		page.on('dialog', function (dialog)
		{
			dialogs++;
			prompts.push(dialog.message());
			return decisions.shift() ? dialog.accept() : dialog.dismiss();
		});
		await finish.click();
		expect(dialogs).toBe(1);
		expect(state.finishUpdates).toBe(0);

		decisions = [true, false];
		await finish.click();
		expect(dialogs).toBe(3);
		expect(state.finishUpdates).toBe(0);
		await expectNoForbiddenWrites(page);

		decisions = [true, true];
		await finish.click();
		await page.waitForURL('**/grocyai/capture/review?trip=7');
		expect(dialogs).toBe(5);
		expect(prompts[3]).toContain('will not change stock');
		expect(prompts[4]).toContain('Confirm again');
		expect(state.finishUpdates).toBe(1);
	});

	test('@cap @mob Review trip opens the current trip and follows Start new trip', async function ({ page })
	{
		await installScanApi(page);
		await page.goto('/fixtures/capture.html');
		const review = page.locator('#grocyai-capture-review-link');
		await expect(review).toHaveAttribute('href', '/grocyai/capture/review?trip=7');
		await page.locator('#grocyai-capture-new-trip-button').click();
		await expect(review).toHaveAttribute('href', '/grocyai/capture/review?trip=8');
		await expectNoForbiddenWrites(page);
	});

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
		await expect(knownRow.locator('.grocy-ai-capture-line-barcode')).toHaveText('UPC ' + KNOWN_GTIN);

		await input.fill(UNKNOWN_GTIN);
		await input.press('Enter');
		const unknownRow = page.locator('.grocy-ai-capture-line.status-unknown');
		await expect(unknownRow.locator('.grocy-ai-capture-line-name')).toHaveText('Unknown — needs product');
		await expect(unknownRow.locator('.grocy-ai-capture-line-barcode')).toHaveText('UPC ' + UNKNOWN_GTIN);

		// A repeat scan of the same GTIN coalesces into the existing line and increments its quantity.
		await input.fill(KNOWN_GTIN);
		await input.press('Enter');
		await expect(page.locator('.grocy-ai-capture-line.status-known')).toHaveCount(1);
		await expect(knownRow.locator('.grocy-ai-capture-line-quantity')).toHaveText('× 2');

		await expectNoForbiddenWrites(page);
		expect(await page.evaluate(function () { return window.__captureFixture.captureWrites; })).toBeGreaterThan(0);
	});

	test('@cap @mob camera reads require confirmation and may be edited before saving', async function ({ page })
	{
		const state = await installScanApi(page);
		await page.goto('/fixtures/capture.html');
		await expect(page.locator('#grocyai-capture-status')).toHaveText('Trip started. Scan or enter a GTIN to add items.');

		await page.evaluate(function (gtin) { window.Grocy.BarcodeScanned(gtin, 'grocyai-capture-barcode'); }, KNOWN_GTIN);
		const confirmation = page.locator('#grocyai-capture-camera-confirmation');
		await expect(confirmation).toBeVisible();
		await expect(page.locator('#grocyai-capture-camera-barcode')).toHaveValue(KNOWN_GTIN);
		expect(state.scans).toBe(0);
		await page.locator('#grocyai-capture-camera-barcode').fill(UNKNOWN_GTIN);
		await page.locator('#grocyai-capture-camera-save-button').click();
		await expect(confirmation).toBeHidden();
		await expect(page.locator('.grocy-ai-capture-line.status-unknown .grocy-ai-capture-line-barcode')).toHaveText('UPC ' + UNKNOWN_GTIN);
		expect(state.scans).toBe(1);
		expect(state.byBarcode[KNOWN_GTIN]).toBeUndefined();

		// A scan aimed at another input's target must not add a line to this page.
		await page.evaluate(function (gtin) { window.Grocy.BarcodeScanned(gtin, 'some-other-input'); }, KNOWN_GTIN);
		await expect(page.locator('.grocy-ai-capture-line')).toHaveCount(1);
		await expect(confirmation).toBeHidden();
		expect(state.scans).toBe(1);

		await page.evaluate(function (gtin) { window.Grocy.BarcodeScanned(gtin, 'grocyai-capture-barcode'); }, KNOWN_GTIN);
		await expect(confirmation).toBeVisible();
		await page.locator('#grocyai-capture-camera-cancel-button').click();
		await expect(confirmation).toBeHidden();
		expect(state.scans).toBe(1);
		await expectNoForbiddenWrites(page);
	});

	test('@cap a pending camera read cannot be saved into a newly started trip', async function ({ page })
	{
		const state = await installScanApi(page);
		await page.goto('/fixtures/capture.html');
		await expect(page.locator('#grocyai-capture-status')).toContainText('Trip started');
		await page.evaluate(function (gtin) { window.Grocy.BarcodeScanned(gtin, 'grocyai-capture-barcode'); }, KNOWN_GTIN);
		await expect(page.locator('#grocyai-capture-camera-confirmation')).toBeVisible();
		await page.locator('#grocyai-capture-new-trip-button').click();
		await expect(page.locator('#grocyai-capture-camera-confirmation')).toBeHidden();
		expect(state.scans).toBe(0);
		await expectNoForbiddenWrites(page);
	});

	test('@cap a pending camera read blocks Finish scanning before confirmation dialogs', async function ({ page })
	{
		const state = await installScanApi(page);
		await page.goto('/fixtures/capture.html');
		await expect(page.locator('#grocyai-capture-status')).toContainText('Trip started');
		await page.evaluate(function (gtin) { window.Grocy.BarcodeScanned(gtin, 'grocyai-capture-barcode'); }, KNOWN_GTIN);
		await expect(page.locator('#grocyai-capture-camera-confirmation')).toBeVisible();
		let dialogs = 0;
		page.on('dialog', function (dialog) { dialogs++; return dialog.dismiss(); });
		await page.locator('#grocyai-capture-finish-button').click();
		expect(dialogs).toBe(0);
		expect(state.finishUpdates).toBe(0);
		await expect(page.locator('#grocyai-capture-status')).toContainText('Confirm or cancel');
	});

	test('@cap a failed camera submission locks capture without retrying', async function ({ page })
	{
		const state = await installScanApi(page, { failScans: 1 });
		await page.goto('/fixtures/capture.html');
		await expect(page.locator('#grocyai-capture-status')).toContainText('Trip started');
		await page.evaluate(function (gtin) { window.Grocy.BarcodeScanned(gtin, 'grocyai-capture-barcode'); }, UNKNOWN_GTIN);
		const cameraInput = page.locator('#grocyai-capture-camera-barcode');
		await cameraInput.fill(KNOWN_GTIN);
		await page.locator('#grocyai-capture-camera-save-button').click();
		await expect(page.locator('#grocyai-capture-status')).toContainText('Reload this page');
		await expect(page.locator('#grocyai-capture-camera-confirmation')).toBeVisible();
		await expect(cameraInput).toHaveValue(KNOWN_GTIN);
		await expect(page.locator('.grocy-ai-capture-line')).toHaveCount(0);
		await expect(page.locator('#grocyai-capture-camera-save-button')).toBeDisabled();
		expect(state.scans).toBe(1);
		expect(state.tripReads).toBe(0);
	});

	test('@cap a lost response with a new saved scan locks capture for review', async function ({ page })
	{
		const state = await installScanApi(page, { savedResponseFailures: 1 });
		await page.goto('/fixtures/capture.html');
		await expect(page.locator('#grocyai-capture-status')).toContainText('Trip started');
		await page.evaluate(function (gtin) { window.Grocy.BarcodeScanned(gtin, 'grocyai-capture-barcode'); }, KNOWN_GTIN);
		await page.locator('#grocyai-capture-camera-save-button').click();
		await expect(page.locator('#grocyai-capture-camera-confirmation')).toBeVisible();
		await expect(page.locator('#grocyai-capture-camera-save-button')).toBeDisabled();
		await expect(page.locator('#grocyai-capture-status')).toContainText('Reload this page');
		expect(state.scans).toBe(1);
		expect(state.byBarcode[KNOWN_GTIN].quantity).toBe(1);
	});

	test('@cap a lost camera response with one increment to an existing scan remains ambiguous', async function ({ page })
	{
		const state = await installScanApi(page);
		await page.goto('/fixtures/capture.html');
		await expect(page.locator('#grocyai-capture-status')).toContainText('Trip started');
		await page.locator('#grocyai-capture-barcode').fill(KNOWN_GTIN);
		await page.locator('#grocyai-capture-add-button').click();
		await expect(page.locator('.grocy-ai-capture-line-quantity')).toHaveText('× 1');
		state.savedResponseFailures = 1;
		await page.evaluate(function (gtin) { window.Grocy.BarcodeScanned(gtin, 'grocyai-capture-barcode'); }, KNOWN_GTIN);
		await page.locator('#grocyai-capture-camera-save-button').click();
		await expect(page.locator('#grocyai-capture-camera-confirmation')).toBeVisible();
		await expect(page.locator('#grocyai-capture-camera-save-button')).toBeDisabled();
		await expect(page.locator('#grocyai-capture-status')).toContainText('Reload this page');
		expect(state.byBarcode[KNOWN_GTIN].quantity).toBe(2);
		expect(state.scans).toBe(2);
	});

	test('@cap a malformed camera response locks retry until the trip is reloaded', async function ({ page })
	{
		const state = await installScanApi(page, { malformed: true });
		await page.goto('/fixtures/capture.html');
		await expect(page.locator('#grocyai-capture-status')).toContainText('Trip started');
		await page.evaluate(function (gtin) { window.Grocy.BarcodeScanned(gtin, 'grocyai-capture-barcode'); }, KNOWN_GTIN);
		await page.locator('#grocyai-capture-camera-save-button').click();
		await expect(page.locator('#grocyai-capture-status')).toContainText('Reload this page');
		await expect(page.locator('#grocyai-capture-camera-confirmation')).toBeVisible();
		await expect(page.locator('#grocyai-capture-camera-save-button')).toBeDisabled();
		await expect(page.locator('#grocyai-capture-camera-cancel-button')).toBeDisabled();
		await expect(page.locator('#grocyai-capture-new-trip-button')).toBeDisabled();
		expect(state.scans).toBe(1);
		expect(state.tripReads).toBe(0);
	});

	test('@cap a camera scan in flight cannot be moved to a newly started trip', async function ({ page })
	{
		const state = await installScanApi(page, { holdScan: true });
		await page.goto('/fixtures/capture.html');
		await expect(page.locator('#grocyai-capture-status')).toContainText('Trip started');
		await page.evaluate(function (gtin) { window.Grocy.BarcodeScanned(gtin, 'grocyai-capture-barcode'); }, KNOWN_GTIN);
		await page.locator('#grocyai-capture-camera-save-button').click();
		await expect.poll(function () { return state.scans; }).toBe(1);
		await expect(page.locator('#grocyai-capture-new-trip-button')).toBeDisabled();
		await expect(page.locator('#grocyai-capture-add-button')).toBeDisabled();
		await expect(page.locator('#grocyai-capture-barcode')).toBeDisabled();
		await page.evaluate(function (gtin)
		{
			const input = document.getElementById('grocyai-capture-barcode');
			input.value = gtin;
			input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
		}, KNOWN_GTIN);
		await page.evaluate(function (gtin) { window.Grocy.BarcodeScanned(gtin, 'grocyai-capture-barcode'); }, UNKNOWN_GTIN);
		expect(state.scans).toBe(1);
		state.releaseScan();
		await expect(page.locator('#grocyai-capture-status')).toContainText('Reload this page');
		await expect(page.locator('#grocyai-capture-camera-save-button')).toBeDisabled();
		expect(state.tripCreations).toBe(1);
	});

	test('@cap @mob the GTIN input and camera button have separate touch targets on phone widths', async function ({ page })
	{
		await installScanApi(page);
		for (const width of [320, 375, 390])
		{
			await page.setViewportSize({ width: width, height: 844 });
			await page.goto('/fixtures/capture.html');
			const inputBox = await page.locator('#grocyai-capture-barcode').boundingBox();
			expect(inputBox, width + 'px GTIN input must render').not.toBeNull();
			expect(inputBox.height, width + 'px GTIN input height').toBeGreaterThanOrEqual(44);
			const cameraBox = await page.locator('#camerabarcodescanner-start-button').boundingBox();
			expect(cameraBox.width, width + 'px camera button width').toBeGreaterThanOrEqual(44);
			expect(cameraBox.height, width + 'px camera button height').toBeGreaterThanOrEqual(44);
			expect(cameraBox.x, width + 'px camera button must follow the input').toBeGreaterThanOrEqual(inputBox.x + inputBox.width);
			expect(cameraBox.x + cameraBox.width, width + 'px camera button must stay inside viewport').toBeLessThanOrEqual(width);
			const addBox = await page.locator('#grocyai-capture-add-button').boundingBox();
			expect(addBox.height, width + 'px Add button height').toBeGreaterThanOrEqual(44);
			expect(addBox.y, width + 'px Add button must be below camera').toBeGreaterThanOrEqual(cameraBox.y + cameraBox.height);
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
	test('@cap @mob open trip offers Continue scanning back to the same trip', async function ({ page })
	{
		await installReviewApi(page, { open: true });
		await page.goto('/fixtures/capture-review.html?trip=7');
		await page.locator('#grocyai-capture-review-trips button').first().click();
		const link = page.getByRole('link', { name: 'Continue scanning' });
		await expect(link).toHaveAttribute('href', '/grocyai/capture?trip=7');
		await expectNoForbiddenWrites(page);
	});

	test('@cap @mob an existing open trip also requires two confirms to finish scanning', async function ({ page })
	{
		const state = await installReviewApi(page, { open: true });
		await page.goto('/fixtures/capture-review.html?trip=7');
		await page.locator('#grocyai-capture-review-trips button').first().click();
		const finish = page.getByRole('button', { name: 'Finish scanning' });
		await expect(finish).toBeVisible();
		let decisions = [false];
		let dialogs = 0;
		page.on('dialog', function (dialog)
		{
			dialogs++;
			return decisions.shift() ? dialog.accept() : dialog.dismiss();
		});
		await finish.click();
		expect(state.finishUpdates).toBe(0);
		decisions = [true, false];
		await finish.click();
		expect(state.finishUpdates).toBe(0);
		decisions = [true, true];
		await finish.click();
		await expect(page.locator('.grocy-ai-capture-review-status')).toContainText('reviewing');
		expect(dialogs).toBe(5);
		expect(state.finishUpdates).toBe(1);
		await expectNoForbiddenWrites(page);
	});

	test('@cap @smoke reviewing a trip and committing books one purchase and archives the trip', async function ({ page })
	{
		const state = await installReviewApi(page);
		await page.goto('/fixtures/capture-review.html');

		const tripButton = page.locator('#grocyai-capture-review-trips button').first();
		await expect(tripButton).toContainText('#7');
		await tripButton.click();

		const detail = page.locator('#grocyai-capture-review-detail');
		await expect(detail.locator('.grocy-ai-capture-review-line-name')).toHaveText('Fixture oats');
		await expect(detail.locator('.grocy-ai-capture-review-line-barcode')).toHaveText('UPC ' + KNOWN_GTIN);
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
		await expect(page.locator('.grocy-ai-capture-review-line-barcode')).toHaveText('UPC ' + UNKNOWN_GTIN);
		await expectNoForbiddenWrites(page);
	});
});

async function unitReview(page, legacy = false)
{
	const state = { selected: legacy ? { name: 'Milk' } : { name: 'Milk', qu_id_purchase: 2, qu_id_stock: 2 }, writes: [], fail: false, reads: 0, revision: 2 };
	state.capture = await installReviewApi(page, { lines: [makeLine({ id: 31, seq: 1, scanned_barcode: UNKNOWN_GTIN, selected: 1 })] });
	await page.route('**/api/objects/quantity_units', route => json(route, [{ id: 2, name: 'Each', active: 1 }, { id: 3, name: 'Bottle', active: 1 }, { id: 4, name: 'Inactive', active: 0 }]));
	await page.route('**/capture/research/options', route => json(route, { contract_version: 1, product_groups: [], taxonomy_leaves: [], generic_parents: [] }));
	await page.route('**/trips/7/research', async route => { state.reads++; const payload = JSON.parse(JSON.stringify({ contract_version: 1, trip_id: 7, drafts: [{ line_id: 31, revision: state.revision, scanned_barcode: UNKNOWN_GTIN, job_state: 'ready', selected: state.selected, suggested: {}, name_alternatives: [], group_candidates: [], taxonomy_candidates: [], possible_existing_products: [], ...(legacy ? {} : { purchase_unit_candidates: [{ id: 2, name: 'Each', source: 'local_package' }], stock_unit_candidates: [{ id: 2, name: 'Each', source: 'openai-classification' }] }) }] })); if (state.holdRead) { state.holdRead = false; await new Promise(resolve => { state.releaseRead = resolve; }); } return json(route, payload); });
	await page.route('**/lines/1/research', route => { const changes = route.request().postDataJSON().changes; state.writes.push(changes); if (state.fail) return json(route, {}, 503); Object.assign(state.selected, changes); state.revision++; return json(route, {}); });
	await page.route('**/lines/1/receipt-evidence', route => json(route, {}));
	await page.goto('/fixtures/capture-review.html');
	await page.locator('#grocyai-capture-review-trips button').first().click();
	await expect(page.getByLabel('Purchase unit', { exact: true }).locator('option')).toHaveCount(3);
	return state;
}
for (const width of [390, 320]) test('@units suggestions fit ' + width + 'px and confirmation names both units', async ({ page }) =>
{
	await page.setViewportSize({ width, height: 844 }); const state = await unitReview(page);
	for (const label of ['Purchase unit', 'Stock unit'])
	{
		const control = page.getByLabel(label, { exact: true }); await expect(control).toHaveValue('2');
		const box = await control.boundingBox(); expect(box.height).toBeGreaterThanOrEqual(44); expect(box.x + box.width).toBeLessThanOrEqual(width);
	}
	await expect(page.locator('.grocy-ai-product-research-unit-source')).toContainText(['Package evidence', 'OpenAI classification']);
	expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
	await page.getByLabel('Location', { exact: true }).selectOption('1');
	const prompts = []; page.on('dialog', d => { prompts.push(d.message()); return prompts.length === 1 ? d.accept() : d.dismiss(); });
	await page.getByRole('button', { name: 'Approve new product' }).click();
	expect(prompts).toHaveLength(2); expect(prompts[1]).toContain('purchase unit Each'); expect(prompts[1]).toContain('stock unit Each'); expect(state.writes).toEqual([]);
});
test('@units changes and clearing persist through reload', async ({ page }) =>
{
	const state = await unitReview(page);
	await page.getByLabel('Purchase unit', { exact: true }).selectOption('3'); await page.getByLabel('Stock unit', { exact: true }).selectOption('');
	await page.getByRole('button', { name: 'Save research draft' }).click();
	await expect.poll(() => state.writes).toEqual([{ qu_id_purchase: 3, qu_id_stock: null }]);
	await page.reload(); await page.locator('#grocyai-capture-review-trips button').first().click();
	await expect(page.getByLabel('Purchase unit', { exact: true })).toHaveValue('3'); await expect(page.getByLabel('Stock unit', { exact: true })).toHaveValue('');
});
test('@units unsaved choices survive late refresh and rejected save', async ({ page }) =>
{
	const state = await unitReview(page);
	await page.getByLabel('Purchase unit', { exact: true }).selectOption('3'); await page.getByLabel('Stock unit', { exact: true }).selectOption('');
	await expect(page.getByText('Unit changes are unsaved. Save research draft to keep them.')).toBeVisible();
	await page.getByLabel('Receipt line evidence').dispatchEvent('change'); await expect.poll(() => state.reads).toBe(2);
	await expect(page.getByLabel('Purchase unit', { exact: true })).toHaveValue('3'); await expect(page.getByLabel('Stock unit', { exact: true })).toHaveValue('');
	state.fail = true; await page.getByRole('button', { name: 'Save research draft' }).click(); await expect(page.getByText('Could not save product review.')).toBeVisible();
	await expect(page.getByLabel('Purchase unit', { exact: true })).toBeEnabled(); await expect(page.getByLabel('Purchase unit', { exact: true })).toHaveValue('3');
	state.fail = false; await page.getByRole('button', { name: 'Save research draft' }).click(); await expect.poll(() => state.selected.qu_id_stock).toBeNull();
});
test('@units legacy results remain manually reviewable', async ({ page }) =>
{
	await unitReview(page, true); await expect(page.getByLabel('Purchase unit', { exact: true })).toHaveValue(''); await expect(page.getByLabel('Stock unit', { exact: true })).toHaveValue('');
	await expect(page.locator('.grocy-ai-product-research-unit-source')).toContainText(['No unit suggestion; choose manually.', 'No unit suggestion; choose manually.']);
});

test('@units purchase clearing and stock selection persist as explicit reviewer edits', async ({ page }) =>
{
	const state = await unitReview(page);
	await page.getByLabel('Purchase unit', { exact: true }).selectOption(''); await page.getByLabel('Stock unit', { exact: true }).selectOption('3');
	await page.getByRole('button', { name: 'Save research draft' }).click();
	await expect.poll(() => state.writes).toEqual([{ qu_id_purchase: null, qu_id_stock: 3 }]);
	await expect(page.getByLabel('Purchase unit', { exact: true })).toHaveValue(''); await expect(page.getByLabel('Stock unit', { exact: true })).toHaveValue('3');
});

test('@units an older research response cannot overwrite a successful unit save', async ({ page }) =>
{
	const state = await unitReview(page);
	state.holdRead = true;
	await page.getByLabel('Receipt line evidence').dispatchEvent('change');
	await expect.poll(() => typeof state.releaseRead).toBe('function');
	await page.getByLabel('Purchase unit', { exact: true }).selectOption('3');
	await page.getByLabel('Stock unit', { exact: true }).selectOption('');
	await page.getByRole('button', { name: 'Save research draft' }).click();
	await expect.poll(() => state.reads).toBe(3);
	await expect(page.getByText('Unit changes are unsaved. Save research draft to keep them.')).toHaveCount(0);
	await expect(page.getByLabel('Purchase unit', { exact: true })).toHaveValue('3');
	const oldResponse = page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/trips/7/research'));
	state.releaseRead();
	await oldResponse;
	await page.waitForLoadState('networkidle');
	await expect(page.getByLabel('Purchase unit', { exact: true })).toHaveValue('3');
	await expect(page.getByLabel('Stock unit', { exact: true })).toHaveValue('');
});

// Losing stable identity or ignoring scan sequence breaks navigation.
test('review queue retains stable scan keys across reorder and removal', async ({ page }) =>
{
	await page.addScriptTag({ path: require('path').resolve(__dirname, '../../../../../public/custom/grocy_AI/capture-review-queue.js') });
	const result = await page.evaluate(() =>
	{
		const q = window.GrocyAICaptureReviewQueue;
		const cards = q.build([{ id: 20, seq: 2, selected: 0 }, { id: 10, seq: 1, selected: 1 }], []).cards;
		return { cards, reordered: q.retain(cards.slice().reverse(), 'scan:10', 0), removed: q.retain(cards, 'scan:30', 1), clamped: q.retain(cards.slice(0, 1), 'scan:30', 8), first: q.retain(cards, null, 0), empty: q.retain([], 'scan:10', 0) };
	});
	expect(result).toEqual({ cards: [
		{ key: 'scan:10', kind: 'scan', scanLineId: 10, receiptKeys: [] },
		{ key: 'scan:20', kind: 'scan', scanLineId: 20, receiptKeys: [] }
	], reordered: 'scan:10', removed: 'scan:20', clamped: 'scan:10', first: 'scan:10', empty: null });
});

for (const width of [320, 390]) test('@mobilequeue single card navigation, focus and swipe at ' + width, async ({ page }) =>
{
	await page.setViewportSize({ width, height: 844 });
	await installReviewApi(page, { lines: [1, 2, 3].map(seq => makeLine({ id: seq, seq, scanned_barcode: UNKNOWN_GTIN })) });
	await page.goto('/fixtures/capture-review.html');
	await page.locator('#grocyai-capture-review-trips button').first().click();
	await expect(page.locator('.grocy-ai-review-card:visible')).toHaveCount(1);
	await expect(page.locator('#grocyai-review-progress')).toHaveText('1 of 3');
	for (const id of ['prev', 'next']) expect((await page.locator('#grocyai-review-' + id).boundingBox()).height).toBeGreaterThanOrEqual(44);
	await page.locator('#grocyai-review-next').click();
	await expect(page.locator('.grocy-ai-review-card:visible')).toHaveAttribute('data-review-key', 'scan:2');
	await expect(page.locator('.grocy-ai-review-card:visible .grocy-ai-review-heading')).toBeFocused();
	await expect(page.locator('#grocyai-review-announcement')).toContainText('2 of 3');
	async function swipe(selector, dx, dy)
	{
		await page.locator(selector).dispatchEvent('touchstart', { touches: [{ clientX: 200, clientY: 200 }] });
		await page.locator(selector).dispatchEvent('touchend', { changedTouches: [{ clientX: 200 + dx, clientY: 200 + dy }] });
	}
	await swipe('.grocy-ai-review-card:visible input[type=number]', -100, 0);
	await swipe('.grocy-ai-review-card:visible', -100, 180);
	await expect(page.locator('#grocyai-review-progress')).toHaveText('2 of 3');
	await swipe('.grocy-ai-review-card:visible', -100, 5);
	await expect(page.locator('#grocyai-review-progress')).toHaveText('3 of 3');
	await swipe('.grocy-ai-review-card:visible', -100, 0);
	await expect(page.locator('#grocyai-review-next')).toBeDisabled();
	await page.locator('#grocyai-review-prev').click();
	await page.locator('#grocyai-capture-review-trips button').first().click();
	await expect(page.locator('#grocyai-review-progress')).toHaveText('2 of 3');
	expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
	await page.setViewportSize({ width: 1280, height: 800 });
	await expect(page.locator('.grocy-ai-review-card:visible')).toHaveCount(3);
});

test('@mobilequeue research edits survive trip refresh and failed save', async ({ page }) =>
{
	const state = await unitReview(page, true);
	await page.route('**/trips/7/receipt-readiness', route => json(route, { ready: true, reasons: [], receipts: [{ receipt: { id: 1, status: 'needs_review', merchant: '' }, lines: [{ id: 1, kind: 'item', description: 'Milk receipt', decision: 'include', quantity: 1, line_total: 2, paired_capture_line_id: 31, allocations: [] }], totals: { entered_total: 2, printed_total: 2, difference: 0 }, issues: [] }] }));
	await page.route('**/receipts/1/lines/1', route => json(route, {}));
	await page.locator('#grocyai-capture-review-trips button').first().click();
	await expect(page.getByLabel('Line total', { exact: true })).toHaveValue('2');
	await page.getByLabel('Proposed name').fill('Unsaved milk');
	await page.getByLabel('Brand research note').fill('My brand');
	await page.getByLabel('Purchase unit', { exact: true }).selectOption('3');
	await page.getByRole('button', { name: 'Save line', exact: true }).click();
	await expect(page.getByLabel('Proposed name')).toHaveValue('Unsaved milk');
	await expect(page.getByLabel('Brand research note')).toHaveValue('My brand');
	await expect(page.getByLabel('Purchase unit', { exact: true })).toHaveValue('3');
	await expect(page.locator('#grocyai-review-announcement')).toContainText('Unsaved');
	await expect(page.locator('#grocyai-capture-review-commit')).toBeDisabled();
	state.fail = true;
	await page.getByRole('button', { name: 'Save research draft' }).click();
	await expect(page.getByText('Could not save product review.')).toBeVisible();
	await expect(page.getByLabel('Proposed name')).toHaveValue('Unsaved milk');
	state.fail = false;
	await page.getByRole('button', { name: 'Save research draft' }).click();
	await expect.poll(() => state.selected.name).toBe('Unsaved milk');
	await expect(page.locator('#grocyai-review-announcement')).not.toContainText('Unsaved');
});

test('@mobilequeue late receipt save cannot replace a switched trip', async ({ page }) =>
{
	await installReviewApi(page, { lines: [] });
	await page.route('**/capture/trips', route => json(route, { trips: [makeTrip({ id: 7 }), makeTrip({ id: 8 })] }));
	await page.route('**/trips/8', route => json(route, { trip: makeTrip({ id: 8 }), lines: [makeLine({ id: 81, seq: 1, trip_id: 8, status: 'known', selected: 1, resolved_product_id: 101 }), makeLine({ id: 82, seq: 2, trip_id: 8, selected: 1 })], checksum: CHECKSUM }));
	await page.route('**/trips/7/receipt-readiness', route => json(route, { ready: false, reasons: ['receipt_1_unfinished'], receipts: [{ receipt: { id: 1, status: 'needs_review', merchant: '' }, lines: [{ id: 1, kind: 'item', description: 'Old receipt', decision: 'needs_review', quantity: 1, line_total: 2, allocations: [] }], totals: { entered_total: 2, printed_total: 2, difference: 0 }, issues: [] }] }));
	let release;
	await page.route('**/receipts/1/lines/1', async route => { await new Promise(resolve => release = resolve); await json(route, {}); });
	let releaseTrip;
	await page.route('**/trips/8/receipt-readiness', async route => { await new Promise(resolve => releaseTrip = resolve); await json(route, { ready: false, reasons: ['no_receipts', 'capture_line_82_product_review_required'], receipts: [] }); });
	await page.goto('/fixtures/capture-review.html');
	await page.locator('[data-trip-id="7"]').click();
	await page.getByLabel('Description', { exact: true }).fill('Edited old receipt');
	await page.getByRole('button', { name: 'Save line', exact: true }).click();
	await expect.poll(() => typeof release).toBe('function');
	await page.locator('[data-trip-id="8"]').click();
	await expect.poll(() => typeof releaseTrip).toBe('function');
	release();
	await page.waitForTimeout(100);
	releaseTrip();
	await expect(page.locator('.grocy-ai-review-card:visible')).toHaveAttribute('data-review-key', 'scan:82');
	await expect(page.locator('#grocyai-review-progress')).toHaveText('2 of 2');
	await expect(page.locator('[data-trip-id="8"]')).toHaveClass(/active/);
});

test('@mobilequeue removal retains nearest card and announces its new position', async ({ page }) =>
{
	const state = await installReviewApi(page, { lines: [1, 2, 3].map(seq => makeLine({ id: seq, seq, scanned_barcode: UNKNOWN_GTIN })) });
	await page.goto('/fixtures/capture-review.html');
	await page.locator('#grocyai-capture-review-trips button').first().click();
	await page.locator('#grocyai-review-next').click();
	state.lines = state.lines.filter(line => line.id !== 2);
	await page.locator('#grocyai-capture-review-trips button').first().click();
	await expect(page.locator('.grocy-ai-review-card:visible')).toHaveAttribute('data-review-key', 'scan:3');
	await expect(page.locator('#grocyai-review-announcement')).toContainText('2 of 2');
	await expect(page.locator('#grocyai-review-next')).toBeDisabled();
});

test('@mobilequeue a saved research field clears after component disposal without discarding newer edits', async ({ page }) =>
{
	const state = await unitReview(page);
	let release;
	await page.route('**/lines/1/research', async route =>
	{
		const changes = route.request().postDataJSON().changes;
		await new Promise(resolve => release = resolve);
		Object.assign(state.selected, changes);
		await json(route, {});
	});
	await page.getByLabel('Proposed name').fill('Saved later');
	await page.getByRole('button', { name: 'Save research draft' }).click();
	await expect.poll(() => typeof release).toBe('function');
	await page.locator('#grocyai-capture-review-trips button').first().click();
	await expect(page.getByLabel('Proposed name')).toHaveValue('Saved later');
	await page.getByLabel('Brand research note').fill('Keep newer brand');
	const response = page.waitForResponse(r => r.request().method() === 'PUT' && r.url().endsWith('/lines/1/research'));
	release(); await response;
	await page.waitForLoadState('networkidle');
	await page.locator('#grocyai-capture-review-trips button').first().click();
	await expect(page.getByLabel('Brand research note')).toHaveValue('Keep newer brand');
	await expect(page.locator('#grocyai-review-announcement')).toContainText('Unsaved');
	await page.unroute('**/lines/1/research');
	await page.route('**/lines/1/research', route => { const changes = route.request().postDataJSON().changes; state.writes.push(changes); Object.assign(state.selected, changes); return json(route, {}); });
	await page.getByRole('button', { name: 'Save research draft' }).click();
	await expect.poll(() => state.writes).toEqual([{ brand: 'Keep newer brand' }]);
});


test('@mobilequeue unselected scans retain accessible research edits and can save before commit', async ({ page }) =>
{
	const state = await unitReview(page);
	await page.getByLabel('Proposed name').fill('Keep while excluded');
	await page.getByLabel('Include in purchase').uncheck();
	await expect(page.getByLabel('Include in purchase')).not.toBeChecked();
	await expect(page.getByLabel('Proposed name')).toBeVisible();
	await expect(page.getByLabel('Proposed name')).toHaveValue('Keep while excluded');
	await expect(page.getByRole('button', { name: 'Approve new product' })).toBeDisabled();
	await expect(page.locator('#grocyai-capture-review-commit')).toBeDisabled();
	await page.getByRole('button', { name: 'Save research draft' }).click();
	await expect.poll(() => state.selected.name).toBe('Keep while excluded');
	await page.getByLabel('Include in purchase').check();
	await expect(page.getByLabel('Proposed name')).toHaveValue('Keep while excluded');
});

test('@mobilequeue only confirmed deletion clears removed scan research edits', async ({ page }) =>
{
	const state = await unitReview(page);
	let reject = true;
	await page.route('**/trips/7/lines/1', route =>
	{
		if (reject) return json(route, {}, 503);
		state.capture.lines = [];
		return json(route, { trip: makeTrip({ status: 'reviewing' }), lines: [] });
	});
	await page.getByLabel('Proposed name').fill('Delete after confirmation');
	await page.getByRole('button', { name: 'Delete', exact: true }).click();
	await expect(page.locator('#grocyai-capture-review-error')).toContainText('could not be saved');
	await expect(page.getByLabel('Proposed name')).toHaveValue('Delete after confirmation');
	await expect(page.locator('#grocyai-capture-review-commit')).toBeDisabled();
	reject = false;
	await page.getByRole('button', { name: 'Delete', exact: true }).click();
	await expect(page.locator('.grocy-ai-review-card')).toHaveCount(0);
	await expect(page.locator('#grocyai-capture-review-commit')).toBeEnabled();
});

for (const scenario of [
	{ name: 'capture quantity mismatch', reasons: ['capture_line_202_quantity_unmatched'], quantity: 2 },
	{ name: 'capture product review', reasons: ['capture_line_202_product_review_required'] },
	{ name: 'excluded allocated scan', reasons: ['capture_line_202_unselected_allocated'], selected: 0 },
	{ name: 'receipt allocation conflict', reasons: ['receipt_9_line_20_product_conflict'] },
	{ name: 'receipt local store issue', reasons: [], issues: ['line_20_store_conflict'] },
	{ name: 'shared receipt allocation issue', reasons: ['receipt_9_line_20_quantity_overallocated'], shared: true }
]) test('@readinessqueue selects the server-conflicted card: ' + scenario.name, async ({ page }) =>
{
	const lines = [makeLine({ id: 101, seq: 1, selected: 1, status: 'known', resolved_product_id: 101 }), makeLine({ id: 202, seq: 2, selected: scenario.selected ?? 1, quantity: scenario.quantity || 1, status: 'known', resolved_product_id: 102 })];
	if (scenario.shared) lines.push(makeLine({ id: 303, seq: 3, selected: 1, status: 'known', resolved_product_id: 102 }));
	await installReviewApi(page, { lines });
	const receiptLines = [10, 20].map((id, index) => ({ id, kind: 'item', description: 'Receipt item ' + id, decision: 'include', quantity: 1, line_total: 2, allocations: [{ id, active: 1, capture_line_id: lines[index].id, product_id: lines[index].resolved_product_id, quantity: 1, unit_price: 2 }] }));
	if (scenario.shared) receiptLines[1].allocations.push({ id: 21, active: 1, capture_line_id: 303, product_id: 102, quantity: 1, unit_price: 2 });
	await page.route('**/trips/7/receipt-readiness', route => json(route, { ready: false, reasons: ['receipt_9_unfinished', 'receipt_9_total_difference'].concat(scenario.reasons), receipts: [{ receipt: { id: 9, status: 'needs_review', merchant: 'Market' }, lines: receiptLines, totals: { entered_total: 4, printed_total: 5, difference: 1 }, issues: ['total_difference'].concat(scenario.issues || []) }] }));
	await page.goto('/fixtures/capture-review.html');
	await page.locator('#grocyai-capture-review-trips button').first().click();
	await expect(page.locator('.grocy-ai-review-card:visible')).toHaveAttribute('data-review-key', 'scan:202');
	await expect(page.locator('#grocyai-review-announcement')).toContainText('Needs review');
	await expect(page.locator('[data-review-key="scan:101"]')).toHaveAttribute('data-review-status', 'Known product');
	await expect(page.locator('#grocyai-capture-review-commit')).toBeDisabled();
	if (scenario.shared)
	{
		await page.getByRole('button', { name: 'Review shared receipt line: Receipt item 20' }).click();
		await expect(page.locator('.grocy-ai-review-card:visible')).toHaveAttribute('data-review-key', 'receipt:9:20');
		await expect(page.locator('#grocyai-review-announcement')).toContainText('Needs review');
	}
});
