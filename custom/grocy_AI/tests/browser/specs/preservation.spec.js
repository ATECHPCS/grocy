const { test, expect } = require('@playwright/test');

const validGtin = '012345678905';

function envelope(outcome, options)
{
	const settings = options || {};
	const v2Outcome = outcome === 'success' || outcome === 'partial_image' ? 'found' : outcome;
	return {
		contract_version: 2,
		outcome: v2Outcome,
		barcode: { scanned_gtin: validGtin, canonical_gtin: '00012345678905', equivalents_checked: [validGtin, '00012345678905'], status: 'unused', owner_product_id: null },
		suggestions: v2Outcome === 'found' ? [{ id: 'name:openfoodfacts:0', field: 'name', value: 'Provider suggestion', display_value: 'Provider suggestion', source: { id: 'openfoodfacts', label: 'Open Food Facts' }, confidence_band: 'high', reason_code: 'canonical_structured_match', evidence_kind: 'structured_direct', retrieved_at: '2026-08-13T12:00:00Z', source_updated_at: null, target: null }] : [],
		media: settings.media || [],
		warnings: outcome === 'partial_image' ? ['image_search_unavailable'] : [],
		diagnostics: { trace_id: settings.traceId || '4bf92f3577b34da6a3ce929d0e0e4736' }
	};
}

async function seedForm(page)
{
	await page.goto('/fixtures/productform.html');
	await page.locator('#name').fill('Manual product sentinel');
	await page.locator('#product-picture').setInputFiles({
		name: 'manual-selection.png',
		mimeType: 'image/png',
		buffer: Buffer.from('manual-file-byte-sentinel')
	});
	return page.evaluate(async function ()
	{
		const file = document.getElementById('product-picture').files[0];
		return {
			name: document.getElementById('name').value,
			fileName: file.name,
			fileType: file.type,
			fileBytes: Array.from(new Uint8Array(await file.arrayBuffer()))
		};
	});
}

async function assertPreserved(page, before)
{
	const after = await page.evaluate(async function ()
	{
		const file = document.getElementById('product-picture').files[0];
		return {
			name: document.getElementById('name').value,
			fileName: file.name,
			fileType: file.type,
			fileBytes: Array.from(new Uint8Array(await file.arrayBuffer())),
			counters: window.__fixtureCounters
		};
	});
	expect(after.name).toBe(before.name);
	expect(after.fileName).toBe(before.fileName);
	expect(after.fileType).toBe(before.fileType);
	expect(after.fileBytes).toEqual(before.fileBytes);
	for (const saveButton of await page.locator('.save-product-button').all()) await expect(saveButton).toBeEnabled();
	expect(after.counters.product).toBe(0);
	expect(after.counters.barcode).toBe(0);
	expect(after.counters.stock).toBe(0);
	expect(after.counters.file).toBe(0);
	expect(after.counters.save).toBe(0);
}

for (const scenario of [
	{ name: 'timeout', status: 504, outcome: 'timeout', copy: 'The search took too long.' },
	{ name: 'companion unavailable', status: 503, outcome: 'provider_error', copy: 'Product search is temporarily unavailable.' },
	{ name: 'provider failure', status: 502, outcome: 'provider_error', copy: 'A product data provider could not respond.' },
	{ name: 'partial image', status: 200, outcome: 'partial_image', copy: 'Product details found' }
])
{
	test('@mob07 ' + scenario.name + ' preserves ordinary fields, selected file, Saves, and zero writes', async ({ page }) =>
	{
		await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
		{
			const traceId = (route.request().headers()['traceparent'] || '').split('-')[1];
			await route.fulfill({ status: scenario.status, contentType: 'application/json', body: JSON.stringify(envelope(scenario.outcome, { traceId: traceId })) });
		});
		const before = await seedForm(page);
		await page.locator('#grocy-ai-upc').fill(validGtin);
		await page.locator('#grocy-ai-upc').press('Enter');
		await expect(page.locator('#grocy-ai-status')).toContainText(scenario.copy);
		await assertPreserved(page, before);
	});
}

test('@mob07 offline and explicit cancel preserve form/file/Save state with zero writes', async ({ page, context }) =>
{
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		await route.abort('internetdisconnected');
	});
	const before = await seedForm(page);
	await context.setOffline(true);
	await page.locator('#grocy-ai-upc').fill(validGtin);
	await page.locator('#grocy-ai-upc').press('Enter');
	await expect(page.locator('#grocy-ai-status')).toContainText('This phone is offline.');
	await context.setOffline(false);
	await assertPreserved(page, before);

	await page.unroute('**/api/grocy-ai/products/enrich/upc/**');
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function () { await new Promise(function () {}); });
	await page.locator('#grocy-ai-retry-button').click();
	await page.locator('#grocy-ai-cancel-button').click();
	await expect(page.locator('#grocy-ai-status')).toContainText('Search cancelled. No changes were made.');
	await assertPreserved(page, before);
});

test('@mob07 selected image download failure keeps the preselected file and normal Save controls', async ({ page }) =>
{
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		const traceId = (route.request().headers()['traceparent'] || '').split('-')[1];
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify(envelope('success', {
				traceId: traceId,
				media: [{ id: 'image:openfoodfacts:preservation', kind: 'front_package', thumbnail_handle: 'thumbnail_preservation_capability_01', full_handle: 'full_preservation_capability_000001', source: { id: 'openfoodfacts', label: 'Open Food Facts' }, confidence_band: 'high', reason_code: 'canonical_structured_front_image', evidence_kind: 'structured_direct', retrieved_at: '2026-08-13T12:00:00Z' }]
			}))
		});
	});
	await page.route('**/api/grocy-ai/images/**', async function (route) { await route.abort('connectionreset'); });
	const before = await seedForm(page);
	await page.locator('#grocy-ai-upc').fill(validGtin);
	await page.locator('#grocy-ai-upc').press('Enter');
	await page.getByRole('button', { name: 'Load thumbnail' }).click();
	await expect(page.locator('.grocy-ai-media-error')).toContainText('This image could not be loaded safely.');
	await assertPreserved(page, before);
});
