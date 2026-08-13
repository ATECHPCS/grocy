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

	await page.goto('/fixtures/productform.html');
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
