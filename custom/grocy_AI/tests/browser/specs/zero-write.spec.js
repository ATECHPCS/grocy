const { test, expect } = require('@playwright/test');

const gtin = '012345678905';
const canonical = '00012345678905';

function envelope(overrides = {})
{
	return Object.assign({
		contract_version: 2,
		outcome: 'found',
		barcode: {
			scanned_gtin: gtin,
			canonical_gtin: canonical,
			equivalents_checked: [gtin, canonical],
			status: 'unused',
			owner_product_id: null
		},
		suggestions: [{
			id: 'name:openfoodfacts:0',
			field: 'name',
			value: 'Selected fixture name',
			display_value: 'Selected fixture name',
			source: { id: 'openfoodfacts', label: 'Fixture source' },
			confidence_band: 'high',
			reason_code: 'canonical_structured_match',
			evidence_kind: 'structured_direct',
			retrieved_at: '2026-08-13T12:00:00Z',
			source_updated_at: null,
			target: null
		}],
		media: [],
		warnings: [],
		diagnostics: { trace_id: '4bf92f3577b34da6a3ce929d0e0e4736' }
	}, overrides);
}

async function durableSnapshot(page)
{
	return page.evaluate(async function ()
	{
		const picture = document.getElementById('product-picture').files[0] || null;
		return {
			controls: {
				name: document.getElementById('name').value,
				productGroup: document.getElementById('product_group_id').value,
				quantityUnit: document.getElementById('qu_id_stock').value,
				brand: document.getElementById('fixture-brand').value,
				brandDirty: document.getElementById('fixture-brand').classList.contains('is-dirty')
			},
			picture: picture ? {
				name: picture.name,
				type: picture.type,
				bytes: Array.from(new Uint8Array(await picture.arrayBuffer()))
			} : null,
			fieldEvents: JSON.parse(JSON.stringify(window.__fixtureCounters.fieldEvents)),
			writes: JSON.parse(JSON.stringify(window.__fixtureCounters.mutationFamilies || {})),
			unknownWrites: (window.__fixtureCounters.unknownWrites || []).slice(),
			objectUrls: {
				created: window.__fixtureCounters.objectUrlsCreated,
				revoked: window.__fixtureCounters.objectUrlsRevoked
			},
			saveClicks: window.__fixtureCounters.saveClicks
		};
	});
}

async function search(page)
{
	await page.locator('#grocy-ai-upc').fill(gtin);
	await page.locator('#grocy-ai-search-button').click();
	await expect(page.locator('#grocy-ai-scanned-barcode')).toHaveText(gtin);
}

test('@enr01 @enr02 @enr03 @enr05 @enr06 @enr07 @enr08 @enr09 default-deny matrix leaves every durable family untouched', async ({ page }) =>
{
	await page.route('**/api/grocy-ai/products/enrich/upc/**', route => route.fulfill({
		status: 200,
		contentType: 'application/json',
		body: JSON.stringify(envelope())
	}));
	await page.goto('/fixtures/productform.html');
	const initial = await durableSnapshot(page);

	await search(page);
	await page.locator('#grocy-ai-review-selected-button').click();
	await page.locator('#grocy-ai-back-to-suggestions-button').click();
	await page.locator('#grocy-ai-upc').fill('1234567');
	await expect(page.locator('#grocy-ai-error')).toContainText('Enter an 8, 12, 13, or 14 digit GTIN.');
	await page.evaluate(function ()
	{
		window.dispatchEvent(new Event('orientationchange'));
		window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
		Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
		document.dispatchEvent(new Event('visibilitychange'));
	});

	const after = await durableSnapshot(page);
	expect(after.controls).toEqual(initial.controls);
	expect(after.picture).toEqual(initial.picture);
	expect(after.fieldEvents).toEqual(initial.fieldEvents);
	expect(after.writes).toEqual({
		products: 0,
		product_barcodes: 0,
		userfields: 0,
		product_groups: 0,
		stock: 0,
		stock_log: 0,
		conversions: 0,
		upload: 0,
		delete: 0
	});
	expect(after.unknownWrites).toEqual([]);
	expect(after.saveClicks).toBe(0);

	const denial = await page.evaluate(async function ()
	{
		try
		{
			await fetch('/api/objects/unclassified_fixture_write', { method: 'POST', body: '{}' });
			return 'allowed';
		}
		catch (error)
		{
			return error.message;
		}
	});
	expect(denial).toContain('Default-deny fixture blocked unknown mutation');
	expect((await durableSnapshot(page)).unknownWrites).toEqual(['POST /api/objects/unclassified_fixture_write']);

	const protectedMutations = [
		['POST', '/api/objects/products'],
		['POST', '/api/objects/product_barcodes'],
		['POST', '/api/userfields/products/27'],
		['POST', '/api/objects/product_groups'],
		['POST', '/api/stock/products/91/add'],
		['POST', '/api/stock_log'],
		['POST', '/api/quantity_unit_conversions'],
		['POST', '/api/files/productpictures'],
		['DELETE', '/api/files/productpictures/fixture.png']
	];
	await page.evaluate(async function (mutations)
	{
		for (const mutation of mutations)
		{
			await fetch(mutation[1], { method: mutation[0], body: mutation[0] === 'DELETE' ? undefined : '{}' });
		}
	}, protectedMutations);
	expect((await durableSnapshot(page)).writes).toEqual({
		products: 1,
		product_barcodes: 1,
		userfields: 1,
		product_groups: 1,
		stock: 1,
		stock_log: 1,
		conversions: 1,
		upload: 1,
		delete: 1
	});
});

test('@enr04 @enr05 @enr06 @enr08 @enr09 one normal Save persists the selected live value and barcode exactly once', async ({ page }) =>
{
	const requests = [];
	await page.route('**/api/grocy-ai/products/enrich/upc/**', route => route.fulfill({
		status: 200,
		contentType: 'application/json',
		body: JSON.stringify(envelope())
	}));
	await page.route('**/api/objects/products', async function (route)
	{
		requests.push({ family: 'products', body: route.request().postDataJSON() });
		await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ created_object_id: 501 }) });
	});
	await page.route('**/api/grocy-ai/barcodes/resolve/**', async function (route)
	{
		requests.push({ family: 'owner', method: route.request().method() });
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
			contract_version: 2,
			barcode: envelope().barcode
		}) });
	});
	await page.route('**/api/objects/product_barcodes', async function (route)
	{
		requests.push({ family: 'product_barcodes', body: route.request().postDataJSON() });
		await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ created_object_id: 901 }) });
	});

	await page.goto('/fixtures/productform.html');
	await page.locator('#name').fill('');
	await page.locator('#grocy-ai-upc').focus();
	const before = await durableSnapshot(page);
	await search(page);
	await page.locator('#grocy-ai-review-selected-button').click();
	await expect(page.locator('[data-grocy-ai-diff-field="name"]')).toContainText('Selected fixture name');
	await expect(page.locator('[data-grocy-ai-diff-field="barcode"]')).toContainText(gtin);
	await page.locator('#grocy-ai-stage-selected-button').click();

	const staged = await durableSnapshot(page);
	expect(staged.controls.name).toBe('Selected fixture name');
	expect(staged.controls.productGroup).toBe(before.controls.productGroup);
	expect(staged.controls.quantityUnit).toBe(before.controls.quantityUnit);
	expect(staged.controls.brand).toBe(before.controls.brand);
	expect(staged.fieldEvents.name.input - before.fieldEvents.name.input).toBe(1);
	expect(staged.fieldEvents.name.change - before.fieldEvents.name.change).toBe(1);
	expect(staged.fieldEvents.product_group_id).toEqual(before.fieldEvents.product_group_id);
	expect(staged.fieldEvents.qu_id_stock).toEqual(before.fieldEvents.qu_id_stock);
	expect(staged.fieldEvents['fixture-brand']).toEqual(before.fieldEvents['fixture-brand']);
	expect(requests).toEqual([]);

	await page.evaluate(function () { window.__fixturePersistence.enabled = true; });
	await page.locator('#save-product-button').click();
	await expect.poll(() => requests.length).toBe(3);
	await page.evaluate(function ()
	{
		window.__fixtureContinueProductSave(501);
		window.__fixtureContinueProductSave(501);
	});
	await page.waitForTimeout(50);
	expect(requests).toEqual([
		{ family: 'products', body: { name: 'Selected fixture name' } },
		{ family: 'owner', method: 'GET' },
		{ family: 'product_barcodes', body: { product_id: 501, barcode: gtin, amount: 1 } }
	]);
	const final = await durableSnapshot(page);
	expect(final.writes.products).toBe(1);
	expect(final.writes.product_barcodes).toBe(1);
	expect(final.writes.userfields).toBe(0);
	expect(final.writes.upload).toBe(0);
	expect(final.unknownWrites).toEqual([]);
	expect(final.saveClicks).toBe(1);
});
