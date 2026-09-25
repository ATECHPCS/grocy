const { test, expect } = require('@playwright/test');

const scan = '012345678905';
const canonical = '00012345678905';

function barcodeEnvelope(status, ownerProductId = null, ownerLabel = null)
{
	return {
		contract_version: 2,
		outcome: 'found',
		barcode: {
			scanned_gtin: scan,
			canonical_gtin: canonical,
			equivalents_checked: [scan, canonical],
			status: status,
			owner_product_id: ownerProductId,
			owner_label: ownerLabel
		},
		suggestions: [],
		media: [],
		warnings: [],
		diagnostics: { trace_id: '4bf92f3577b34da6a3ce929d0e0e4736' }
	};
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

async function search(page)
{
	await page.locator('#grocy-ai-upc').fill(scan);
	await page.locator('#grocy-ai-search-button').click();
	await page.waitForTimeout(50);
	if (await page.locator('#grocy-ai-scanned-barcode').textContent() !== scan)
	{
		process.stderr.write('EXPECTED_RED: barcode.owner_handoff\n');
	}
}

async function configureSaveRoutes(page, options = {})
{
	const productId = options.productId || 501;
	const ownerStatuses = (options.ownerStatuses || ['unused']).slice();
	const barcodeStatuses = (options.barcodeStatuses || [201]).slice();
	const observed = { owner: 0, product: 0, barcode: 0, barcodeBodies: [] };

	await page.route('**/api/objects/products', async function (route)
	{
		observed.product++;
		await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ created_object_id: productId }) });
	});
	await page.route('**/api/grocy-ai/barcodes/resolve/**', async function (route)
	{
		const status = ownerStatuses[Math.min(observed.owner, ownerStatuses.length - 1)];
		observed.owner++;
		const ownerProductId = status === 'owned_current' ? productId : (status === 'owned_other' ? 777 : null);
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				contract_version: 2,
				barcode: {
					scanned_gtin: scan,
					canonical_gtin: canonical,
					equivalents_checked: [scan, canonical],
					status: status,
					owner_product_id: ownerProductId,
					owner_label: status === 'owned_other' ? 'Concurrent owner' : null
				}
			})
		});
	});
	await page.route('**/api/objects/product_barcodes', async function (route)
	{
		observed.barcode++;
		observed.barcodeBodies.push(route.request().postDataJSON());
		if (options.delayBarcodeMs) await new Promise(resolve => setTimeout(resolve, options.delayBarcodeMs));
		const status = barcodeStatuses[Math.min(observed.barcode - 1, barcodeStatuses.length - 1)];
		await route.fulfill({
			status: status,
			contentType: 'application/json',
			body: status >= 200 && status < 300 ? JSON.stringify({ created_object_id: 901 }) : JSON.stringify({ error_message: 'canonical conflict' })
		});
	});

	return observed;
}

async function enableNormalSave(page, productId = 501)
{
	await page.evaluate(function (id)
	{
		window.__fixturePersistence.enabled = true;
		window.__fixturePersistence.productId = id;
	}, productId);
}

test('@enr03 owner handoff @enr02 @enr09 preserves the scan and routes only from a server owner ID', async ({ page }) =>
{
	let enrichmentRequests = 0;
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		enrichmentRequests++;
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify(barcodeEnvelope('owned_other', 73, 'Existing local product'))
		});
	});

	await page.goto('/fixtures/productform.html?owner_product_id=999999&product_id=999999');
	await search(page);

	await expect(page.locator('#grocy-ai-scanned-barcode')).toHaveText(scan);
	await expect(page.locator('#grocy-ai-barcode-equivalents')).toContainText(scan);
	await expect(page.locator('#grocy-ai-barcode-equivalents')).toContainText(canonical);
	await expect(page.locator('#grocy-ai-barcode-outcome')).toContainText('This barcode already belongs to an existing product.');
	await expect(page.locator('#grocy-ai-barcode-outcome')).toContainText('Existing local product');
	const ownerLink = page.locator('#grocy-ai-open-existing-product');
	await expect(ownerLink).toHaveAttribute('href', '/product/73');
	await expect(ownerLink).not.toHaveAttribute('href', /91|999999|javascript:/);
	await expect(page.locator('[data-grocy-ai-field]')).toHaveCount(0);
	expect(enrichmentRequests).toBe(1);

	const counters = await page.evaluate(function () { return window.__fixtureCounters; });
	expectZeroWrites(counters);
});

test('@enr03 @enr09 owner-current suppresses staging and remains zero-write', async ({ page }) =>
{
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(barcodeEnvelope('owned_current', 91, 'Current product')) });
	});
	await page.goto('/fixtures/productform.html');
	await search(page);

	await expect(page.locator('#grocy-ai-barcode-outcome')).toContainText('This barcode is already attached to this product.');
	await expect(page.locator('#grocy-ai-open-existing-product')).toHaveClass(/d-none/);
	await expect(page.locator('#grocy-ai-remove-staged-barcode')).toHaveClass(/d-none/);
	await expect(page.locator('#grocy-ai-selection-status')).toContainText('0 changes selected');
	const counters = await page.evaluate(function () { return window.__fixtureCounters; });
	expectZeroWrites(counters);
});

test('@enr02 @enr03 @enr09 unused barcode stages once transiently, is removable, and all non-Save paths write nothing', async ({ page }) =>
{
	await page.setViewportSize({ width: 320, height: 844 });
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(barcodeEnvelope('unused')) });
	});
	await page.goto('/fixtures/productform.html');
	await search(page);

	await expect(page.locator('#grocy-ai-scanned-barcode')).toHaveText(scan);
	await expect(page.locator('#grocy-ai-barcode-outcome')).toContainText('This barcode is not assigned in Grocy.');
	await expect(page.locator('#grocy-ai-barcode-outcome')).toContainText('Ready to add on Save');
	await expect(page.locator('#grocy-ai-selection-status')).toContainText('1 changes selected');
	await expect(page.locator('#grocy-ai-review-selected-button')).toBeEnabled();
	const removeBox = await page.locator('#grocy-ai-remove-staged-barcode').boundingBox();
	expect(removeBox).not.toBeNull();
	expect(removeBox.height).toBeGreaterThanOrEqual(44);
	expect(await page.evaluate(function () { return document.documentElement.scrollWidth - document.documentElement.clientWidth; })).toBeLessThanOrEqual(0);

	await page.locator('#grocy-ai-review-selected-button').click();
	const barcodeDiff = page.locator('[data-grocy-ai-diff-field="barcode"]');
	await expect(barcodeDiff).toContainText('Not attached');
	await expect(barcodeDiff).toContainText(scan);
	await page.locator('#grocy-ai-back-to-suggestions-button').click();
	await page.locator('#grocy-ai-remove-staged-barcode').click();
	await expect(page.locator('#grocy-ai-selection-status')).toContainText('0 changes selected');
	await expect(page.locator('#grocy-ai-review-selected-button')).toBeDisabled();

	await page.locator('#grocy-ai-upc').fill('96385074');
	await expect(page.locator('#grocy-ai-scanned-barcode')).toHaveText('');
	await expect(page.locator('#grocy-ai-barcode-outcome')).toHaveText('');
	const counters = await page.evaluate(function () { return window.__fixtureCounters; });
	expectZeroWrites(counters);
});

test('@enr02 @enr03 @enr09 stale owner response cannot restore navigation or staged barcode state', async ({ page }) =>
{
	let fulfillFirst;
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		if (!fulfillFirst)
		{
			fulfillFirst = function ()
			{
				return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(barcodeEnvelope('owned_other', 73, 'Stale owner')) });
			};
			return;
		}
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ...barcodeEnvelope('unused'), barcode: { ...barcodeEnvelope('unused').barcode, scanned_gtin: '96385074', canonical_gtin: '00000096385074', equivalents_checked: ['96385074', '00000096385074'] } }) });
	});

	await page.goto('/fixtures/productform.html');
	await page.locator('#grocy-ai-upc').fill(scan);
	await page.locator('#grocy-ai-search-button').click();
	await page.locator('#grocy-ai-upc').fill('96385074');
	await page.locator('#grocy-ai-search-button').click();
	await expect(page.locator('#grocy-ai-scanned-barcode')).toHaveText('96385074');
	await fulfillFirst();
	await page.waitForTimeout(50);
	await expect(page.locator('#grocy-ai-scanned-barcode')).toHaveText('96385074');
	await expect(page.locator('#grocy-ai-open-existing-product')).toHaveClass(/d-none/);
	const counters = await page.evaluate(function () { return window.__fixtureCounters; });
	expectZeroWrites(counters);
});

test('@enr04 normal Save attaches once @enr06 @enr09 and repeated continuations are idempotent', async ({ page }) =>
{
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(barcodeEnvelope('unused')) });
	});
	const observed = await configureSaveRoutes(page, { ownerStatuses: ['unused', 'owned_current'] });
	await page.goto('/fixtures/productform.html');
	await search(page);

	let counters = await page.evaluate(function () { return window.__fixtureCounters; });
	expect(counters.product).toBe(0);
	expect(counters.barcode).toBe(0);
	const hasContinuation = await page.evaluate(function ()
	{
		return Boolean(window.GrocyAI
			&& typeof window.GrocyAI.GetStagedBarcode === 'function'
			&& typeof window.GrocyAI.AttachStagedBarcode === 'function'
			&& typeof window.GrocyAI.RetryBarcodeAttachment === 'function');
	});
	if (!hasContinuation) process.stderr.write('EXPECTED_RED: barcode.save_once\n');
	expect(hasContinuation).toBe(true);

	await enableNormalSave(page);
	await page.locator('#save-product-button').click();
	await expect.poll(() => observed.barcode).toBe(1);
	await page.evaluate(function () { return window.__fixtureContinueProductSave(501); });
	await page.evaluate(function () { return window.__fixtureContinueProductSave(501); });
	expect(observed.product).toBe(1);
	expect(observed.barcode).toBe(1);
	expect(observed.barcodeBodies).toEqual([{ product_id: 501, barcode: scan, amount: 1 }]);
	counters = await page.evaluate(function () { return window.__fixtureCounters; });
	expect(counters.product).toBe(1);
	expect(counters.barcode).toBe(1);
});

test('@enr04 @enr09 duplicate callbacks and a delayed response coalesce to one barcode insert', async ({ page }) =>
{
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(barcodeEnvelope('unused')) }));
	const observed = await configureSaveRoutes(page, { delayBarcodeMs: 100, ownerStatuses: ['unused'] });
	await page.goto('/fixtures/productform.html');
	await search(page);
	await enableNormalSave(page);
	await page.locator('#save-product-button').click();
	await page.evaluate(function ()
	{
		window.__fixtureContinueProductSave(501);
		window.__fixtureContinueProductSave(501);
	});
	await expect.poll(() => observed.barcode).toBe(1);
	expect(observed.product).toBe(1);
	expect(observed.owner).toBe(1);
});

test('@enr04 @enr06 same-product insert race is idempotent success and another-owner race blocks', async ({ page }) =>
{
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(barcodeEnvelope('unused')) }));
	const observed = await configureSaveRoutes(page, { ownerStatuses: ['unused', 'owned_current'], barcodeStatuses: [409] });
	await page.goto('/fixtures/productform.html');
	await search(page);
	await enableNormalSave(page);
	await page.locator('#save-product-button').click();
	await expect.poll(() => observed.owner).toBe(2);
	expect(observed.product).toBe(1);
	expect(observed.barcode).toBe(1);
	await expect(page.locator('#grocy-ai-barcode-attachment-error')).toHaveCount(0);

	await page.reload();
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(barcodeEnvelope('unused')) }));
	const blocked = await configureSaveRoutes(page, { productId: 502, ownerStatuses: ['unused', 'owned_other'], barcodeStatuses: [409] });
	await search(page);
	await enableNormalSave(page, 502);
	await page.locator('#save-product-button').click();
	await expect.poll(() => blocked.owner).toBe(2);
	await expect(page.locator('#grocy-ai-barcode-attachment-error')).toContainText('The product was saved, but the barcode was not attached.');
	await expect(page.locator('#grocy-ai-open-existing-product')).toHaveAttribute('href', '/product/777');
	await expect(page.locator('#grocy-ai-remove-staged-barcode')).toHaveClass(/d-none/);
	await expect(page.locator('#grocy-ai-selection-status')).toContainText('0 changes selected');
});

test('@enr04 @enr06 barcode-only retry retains product context and never repeats product persistence', async ({ page }) =>
{
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(barcodeEnvelope('unused')) }));
	const observed = await configureSaveRoutes(page, {
		ownerStatuses: ['unused', 'unused', 'unused'],
		barcodeStatuses: [503, 201]
	});
	await page.goto('/fixtures/productform.html');
	await search(page);
	await enableNormalSave(page);
	await page.locator('#save-product-button').click();
	await expect(page.locator('#grocy-ai-barcode-attachment-error')).toContainText('The product was saved, but the barcode was not attached.');
	expect(observed.product).toBe(1);
	expect(observed.barcode).toBe(1);
	await page.locator('#grocy-ai-retry-barcode-attachment').click();
	await expect.poll(() => observed.barcode).toBe(2);
	expect(observed.product).toBe(1);
	expect(observed.barcodeBodies[1]).toEqual({ product_id: 501, barcode: scan, amount: 1 });
	await expect(page.locator('#grocy-ai-barcode-attachment-error')).toHaveCount(0);
});
