const { test, expect } = require('@playwright/test');

const validGtin = '012345678905';
const v2NameReviewEnvelope = {
	contract_version: 2,
	outcome: 'found',
	barcode: {
		scanned_gtin: validGtin,
		canonical_gtin: '00012345678905',
		equivalents_checked: [validGtin, '00012345678905'],
		status: 'unused',
		owner_product_id: null
	},
	suggestions: [
		{
			id: 'name:openfoodfacts:0',
			field: 'name',
			value: 'Fixture rolled oats',
			display_value: 'Fixture rolled oats',
			source: { id: 'openfoodfacts', label: 'Open Food Facts' },
			confidence_band: 'high',
			reason_code: 'canonical_structured_match',
			evidence_kind: 'structured_direct',
			retrieved_at: '2026-08-13T12:00:00Z',
			source_updated_at: null,
			target: null
		}
	],
	media: [],
	warnings: [],
	diagnostics: { trace_id: '4bf92f3577b34da6a3ce929d0e0e4736' }
};

test('@enr01 contract-v2 name review @enr05 @enr09 renders provenance and remains zero-write', async ({ page }) =>
{
	await page.route('**/api/**', async function (route)
	{
		const requestUrl = new URL(route.request().url());
		if (requestUrl.pathname === '/api/grocy-ai/products/enrich/upc/' + validGtin)
		{
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(v2NameReviewEnvelope)
			});
			return;
		}

		await route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"unexpected fixture route"}' });
	});

	await page.goto('/fixtures/productform.html');
	await page.locator('#name').fill('');
	await page.locator('#grocy-ai-upc').fill(validGtin);
	await expect(page.locator('#grocy-ai-search-button')).toBeEnabled();

	const response = page.waitForResponse(function (candidate)
	{
		return new URL(candidate.url()).pathname === '/api/grocy-ai/products/enrich/upc/' + validGtin;
	});
	await page.locator('#grocy-ai-search-button').click();
	await response;
	await page.waitForTimeout(50);

	const reviewRow = page.locator('[data-grocy-ai-field="name"]');
	if (await reviewRow.count() === 0)
	{
		process.stderr.write('EXPECTED_RED: contract.v2_name_review\n');
	}
	await expect(reviewRow, 'contract-v2 must render a name comparison row').toBeVisible();

	await expect(reviewRow.getByText('Current', { exact: true })).toBeVisible();
	await expect(reviewRow.getByText('Blank', { exact: true })).toBeVisible();
	await expect(reviewRow.getByText('Suggested', { exact: true })).toBeVisible();
	await expect(reviewRow.getByText('Fixture rolled oats', { exact: true })).toBeVisible();
	await expect(reviewRow.getByText('Open Food Facts', { exact: true })).toBeVisible();
	await expect(reviewRow.getByText('High confidence', { exact: true })).toBeVisible();
	await expect(reviewRow.getByText('Exact canonical barcode match', { exact: true })).toBeVisible();
	await expect(reviewRow.getByText('Source update time unavailable', { exact: true })).toBeVisible();

	const selection = reviewRow.getByRole('checkbox', { name: 'Use suggested value' });
	await expect(selection).toBeChecked();
	await expect(reviewRow.getByText('Preselected — blank field and exact structured match', { exact: true })).toBeVisible();

	expect(await page.locator('#name').inputValue()).toBe('');
	const counters = await page.evaluate(function () { return window.__fixtureCounters; });
	expect(counters.enrichment).toBe(1);
	expect(counters.product).toBe(0);
	expect(counters.barcode).toBe(0);
	expect(counters.stock).toBe(0);
	expect(counters.file).toBe(0);
	expect(counters.save).toBe(0);
});
