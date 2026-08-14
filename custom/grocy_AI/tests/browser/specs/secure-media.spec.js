const { test, expect } = require('@playwright/test');

const validGtin = '012345678905';
const structuredThumbnailHandle = 'thumbnail_front_capability_0001';
const structuredFullHandle = 'full_front_capability_0000000001';
const searchThumbnailHandle = 'thumbnail_search_capability_001';
const searchFullHandle = 'full_search_capability_000000001';
const externalCanary = 'https://external-image.invalid/package.png';
const secretCanary = 'secret-media-canary';
const payloadCanary = 'payload-media-canary';

function mediaCandidate(overrides)
{
	return Object.assign({
		id: 'image:openfoodfacts:front',
		kind: 'front_package',
		thumbnail_handle: structuredThumbnailHandle,
		full_handle: structuredFullHandle,
		source: { id: 'openfoodfacts', label: 'Open Food Facts' },
		confidence_band: 'high',
		reason_code: 'canonical_structured_front_image',
		evidence_kind: 'structured_direct',
		retrieved_at: '2026-08-13T12:00:00Z'
	}, overrides || {});
}

function mediaEnvelope(media)
{
	return {
		contract_version: 2,
		outcome: 'found',
		barcode: {
			scanned_gtin: validGtin,
			canonical_gtin: '00012345678905',
			equivalents_checked: [validGtin, '00012345678905'],
			status: 'unused',
			owner_product_id: null
		},
		suggestions: [{
			id: 'name:openfoodfacts:0',
			field: 'name',
			value: 'Fixture package cereal',
			display_value: 'Fixture package cereal',
			source: { id: 'openfoodfacts', label: 'Open Food Facts' },
			confidence_band: 'high',
			reason_code: 'canonical_structured_match',
			evidence_kind: 'structured_direct',
			retrieved_at: '2026-08-13T12:00:00Z',
			source_updated_at: null,
			target: null
		}],
		media: media,
		warnings: [],
		diagnostics: { trace_id: '4bf92f3577b34da6a3ce929d0e0e4736' }
	};
}

const structuredCandidate = mediaCandidate();
const searchCandidate = mediaCandidate({
	id: 'image:searxng:0',
	kind: 'search_alternative',
	thumbnail_handle: searchThumbnailHandle,
	full_handle: searchFullHandle,
	source: { id: 'searxng', label: 'Search result' },
	confidence_band: 'unverified',
	reason_code: 'unverified_search_result',
	evidence_kind: 'search'
});

async function installEnvelope(page, media = [structuredCandidate, searchCandidate])
{
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify(mediaEnvelope(media))
		});
	});
}

async function search(page)
{
	await page.locator('#grocy-ai-upc').fill(validGtin);
	await page.locator('#grocy-ai-search-button').click();
	await page.waitForTimeout(50);
}

async function resetMediaCounts(request)
{
	await request.post('/__fixture/reset-media-counts');
}

async function serverMediaCounts(request)
{
	return (await request.get('/__fixture/media-counts')).json();
}

function expectZeroWrites(counters)
{
	expect(counters.product).toBe(0);
	expect(counters.barcode).toBe(0);
	expect(counters.category).toBe(0);
	expect(counters.conversion).toBe(0);
	expect(counters.userfield).toBe(0);
	expect(counters.stock).toBe(0);
	expect(counters.file).toBe(0);
	expect(counters.save).toBe(0);
}

async function expectNoMediaLeak(page, consoleLines)
{
	const disclosure = await page.evaluate(function ()
	{
		const attributes = Array.from(document.querySelectorAll('*')).flatMap(function (element)
		{
			return Array.from(element.attributes).map(function (attribute)
			{
				return attribute.name + '=' + attribute.value;
			});
		}).join('\n');
		const diagnostic = document.getElementById('grocy-ai-diagnostic-fallback');
		return {
			text: document.body.innerText,
			attributes: attributes,
			diagnostic: diagnostic ? diagnostic.value : ''
		};
	});
	for (const serialized of [disclosure.text, disclosure.attributes, disclosure.diagnostic, consoleLines.join('\n')])
	{
		expect(serialized).not.toContain(externalCanary);
		expect(serialized).not.toContain(structuredThumbnailHandle);
		expect(serialized).not.toContain(structuredFullHandle);
		expect(serialized).not.toContain(searchThumbnailHandle);
		expect(serialized).not.toContain(searchFullHandle);
		expect(serialized).not.toContain(secretCanary);
		expect(serialized).not.toContain(payloadCanary);
	}
}

test('@enr08 same-origin media happy path @enr07 @enr09', async ({ page, request }) =>
{
	const consoleLines = [];
	page.on('console', function (message) { consoleLines.push(message.text()); });
	await resetMediaCounts(request);
	await installEnvelope(page);
	await page.goto('/fixtures/productform.html');
	await page.locator('#name').fill('');
	await search(page);

	const mediaSection = page.locator('#grocy-ai-media-review');
	if (await mediaSection.count() !== 1)
	{
		process.stderr.write('EXPECTED_RED: media.same_origin_happy_path\n');
	}
	await expect(mediaSection).toBeVisible();
	const headings = mediaSection.locator('h5, h6').filter({ hasText: /Front package image|Unverified search alternatives/ });
	await expect(headings).toHaveText(['Front package image', 'Unverified search alternatives']);
	await expect(mediaSection.getByText('Unverified', { exact: true })).toBeVisible();
	await expect(mediaSection.locator('.grocy-ai-media-source', { hasText: 'Search result' })).toBeVisible();

	expect(await serverMediaCounts(request)).toEqual({ thumbnail: 0, full: 0 });
	let browserCounters = await page.evaluate(function () { return window.__fixtureCounters; });
	expect(browserCounters.mediaRequests).toBe(0);
	expect(browserCounters.objectUrlsCreated).toBe(0);
	expect(await page.locator('#product-picture').evaluate(function (input) { return input.files.length; })).toBe(0);

	const structured = mediaSection.locator('[data-grocy-ai-media-id="image:openfoodfacts:front"]');
	const loadThumbnail = structured.getByRole('button', { name: 'Load thumbnail' });
	const loadBox = await loadThumbnail.boundingBox();
	expect(loadBox).not.toBeNull();
	expect(loadBox.height).toBeGreaterThanOrEqual(44);
	await loadThumbnail.click();
	await expect(structured.locator('img')).toHaveAttribute('src', /^blob:/);
	expect(await serverMediaCounts(request)).toEqual({ thumbnail: 1, full: 0 });

	await structured.getByRole('button', { name: 'Select image' }).click();
	await expect(structured.getByText('Selected', { exact: true })).toBeVisible();
	expect(await serverMediaCounts(request)).toEqual({ thumbnail: 1, full: 1 });
	expect(await page.locator('#product-picture').evaluate(function (input) { return input.files.length; })).toBe(0);

	await page.locator('#grocy-ai-review-selected-button').click();
	await expect(page.locator('[data-grocy-ai-diff-field="product_image"]')).toContainText('Selected by you');
	expect(await page.locator('#product-picture').evaluate(function (input) { return input.files.length; })).toBe(0);
	await page.locator('#grocy-ai-stage-selected-button').click();
	expect(await page.locator('#product-picture').evaluate(function (input) { return input.files.length; })).toBe(1);
	await expect(page.locator('.save-product-button').first()).toBeEnabled();
	await expect(page.locator('.save-product-button').last()).toBeEnabled();

	browserCounters = await page.evaluate(function () { return window.__fixtureCounters; });
	expect(browserCounters.mediaThumbnail).toBe(1);
	expect(browserCounters.mediaFull).toBe(1);
	expect(browserCounters.externalRequests).toBe(0);
	expect(browserCounters.saveClicks).toBe(0);
	expect(browserCounters.fieldEvents['product-picture'].change).toBeGreaterThan(0);
	expectZeroWrites(browserCounters);
	await expectNoMediaLeak(page, consoleLines);
});

test('@enr08 @enr09 media denial is candidate-local and preserves the form', async ({ page }) =>
{
	await installEnvelope(page, [structuredCandidate]);
	await page.route('**/api/grocy-ai/images/thumbnail/**', async function (route)
	{
		await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ error_message: secretCanary }) });
	});
	await page.goto('/fixtures/productform.html');
	await page.locator('#name').fill('Manual value');
	await search(page);
	const candidate = page.locator('[data-grocy-ai-media-id="image:openfoodfacts:front"]');
	await candidate.getByRole('button', { name: 'Load thumbnail' }).click();
	await expect(candidate).toContainText('This image could not be loaded safely. Choose another image or continue without one.');
	expect(await page.locator('#name').inputValue()).toBe('Manual value');
	expect(await page.locator('#product-picture').evaluate(function (input) { return input.files.length; })).toBe(0);
	await expect(page.locator('.save-product-button').first()).toBeEnabled();
	const counters = await page.evaluate(function () { return window.__fixtureCounters; });
	expect(counters.mediaThumbnail).toBe(1);
	expectZeroWrites(counters);
	await expect(page.locator('body')).not.toContainText(secretCanary);
});

test('@enr08 expired and wrong-variant media capabilities fail generically before bytes', async ({ page }) =>
{
	const expired = mediaCandidate({
		id: 'image:openfoodfacts:expired',
		thumbnail_handle: 'expired_thumbnail_capability_01',
		full_handle: 'expired_full_capability_0000001'
	});
	await installEnvelope(page, [expired]);
	await page.route('**/api/grocy-ai/images/thumbnail/**', async function (route)
	{
		await route.fulfill({ status: 404, contentType: 'application/json', body: '{}' });
	});
	await page.goto('/fixtures/productform.html');
	await search(page);
	const candidate = page.locator('[data-grocy-ai-media-id="image:openfoodfacts:expired"]');
	await candidate.getByRole('button', { name: 'Load thumbnail' }).click();
	await expect(candidate).toContainText('This image preview expired. Search again to load it.');
	expect(await page.locator('#product-picture').evaluate(function (input) { return input.files.length; })).toBe(0);
	const counters = await page.evaluate(function () { return window.__fixtureCounters; });
	expectZeroWrites(counters);
});

test('@enr08 @enr09 stale or cancelled media cannot restore a preview and revokes obsolete blobs', async ({ page }) =>
{
	await installEnvelope(page, [structuredCandidate]);
	let releaseThumbnail;
	await page.route('**/api/grocy-ai/images/thumbnail/**', async function (route)
	{
		await new Promise(function (resolve) { releaseThumbnail = resolve; });
		await route.fulfill({ status: 200, contentType: 'image/png', body: Buffer.from('89504e470d0a1a0a', 'hex') });
	});
	await page.goto('/fixtures/productform.html');
	await search(page);
	await page.locator('[data-grocy-ai-media-id="image:openfoodfacts:front"]').getByRole('button', { name: 'Load thumbnail' }).click({ noWaitAfter: true });
	await page.locator('#grocy-ai-upc').fill('96385074');
	releaseThumbnail();
	await page.waitForTimeout(50);
	await expect(page.locator('#grocy-ai-media-review')).toHaveCount(0);
	const counters = await page.evaluate(function () { return window.__fixtureCounters; });
	expect(counters.objectUrlsCreated).toBe(0);
	expectZeroWrites(counters);
});
