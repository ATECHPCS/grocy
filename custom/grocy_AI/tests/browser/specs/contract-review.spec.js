const { test, expect } = require('@playwright/test');

const validGtin = '012345678905';
const source = { id: 'openfoodfacts', label: 'Open Food Facts' };

function suggestion(field, value, options = {})
{
	return {
		id: field + ':openfoodfacts:0',
		field: field,
		value: String(value),
		display_value: options.displayValue || String(value),
		source: source,
		confidence_band: options.confidenceBand || 'high',
		reason_code: options.reasonCode || 'canonical_structured_match',
		evidence_kind: options.evidenceKind || 'structured_direct',
		retrieved_at: '2026-08-13T12:00:00Z',
		source_updated_at: options.sourceUpdatedAt === undefined ? null : options.sourceUpdatedAt,
		target: options.target || null
	};
}

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

const sevenFamilyEnvelope = {
	...v2NameReviewEnvelope,
	suggestions: [
		suggestion('name', 'Fixture rolled oats', { sourceUpdatedAt: '2026-08-12T10:30:00Z' }),
		suggestion('brand', 'Fixture Farms', { target: { kind: 'userfield', id: 27, label: 'Brand' } }),
		suggestion('package_size', '18 oz <img src=x onerror="window.__providerCanary=1">'),
		suggestion('product_group', '9', {
			displayValue: 'Inactive group',
			reasonCode: 'mapped_local_option',
			evidenceKind: 'mapped',
			target: { kind: 'product_group', id: 9, label: 'Inactive group' }
		}),
		suggestion('quantity_unit', '5', {
			displayValue: 'Package',
			reasonCode: 'mapped_local_option',
			evidenceKind: 'mapped',
			target: { kind: 'quantity_unit', id: 5, label: 'Package' }
		}),
		suggestion('food_type', 'Whole grain cereal', {
			confidenceBand: 'medium',
			reasonCode: 'inferred_provider_data',
			evidenceKind: 'inferred'
		})
	],
	media: [{
		id: 'front:openfoodfacts:0',
		kind: 'front_package',
		thumbnail_handle: 'abcdefghijklmnopqrstuvwx',
		full_handle: 'zyxwvutsrqponmlkjihgfedc',
		source: source,
		confidence_band: 'high',
		reason_code: 'canonical_structured_front_image',
		evidence_kind: 'structured_direct',
		retrieved_at: '2026-08-13T12:00:00Z'
	}]
};

async function installEnvelope(page, envelope = sevenFamilyEnvelope)
{
	await page.route('**/api/**', async function (route)
	{
		const requestUrl = new URL(route.request().url());
		if (requestUrl.pathname === '/api/grocy-ai/products/enrich/upc/' + validGtin)
		{
			await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(envelope) });
			return;
		}

		await route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"unexpected fixture route"}' });
	});
}

async function search(page)
{
	await page.locator('#grocy-ai-upc').fill(validGtin);
	await page.locator('#grocy-ai-search-button').click();
	await page.waitForTimeout(100);
	if (await page.locator('[data-grocy-ai-field="name"]').count() === 0)
	{
		process.stderr.write('EXPECTED_RED: review.seven_family_diff\n');
	}
	await expect(page.locator('[data-grocy-ai-field="name"]')).toBeVisible();
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

test('@enr01 @enr05 direct evidence never preselects over a non-empty current name', async ({ page }) =>
{
	await page.route('**/api/**', async function (route)
	{
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(v2NameReviewEnvelope) });
	});
	await page.goto('/fixtures/productform.html');
	await page.locator('#grocy-ai-upc').fill(validGtin);
	await page.locator('#grocy-ai-search-button').click();

	const reviewRow = page.locator('[data-grocy-ai-field="name"]');
	await expect(reviewRow).toBeVisible();
	await expect(reviewRow.getByText('Existing manual product', { exact: true })).toBeVisible();
	await expect(reviewRow.getByRole('checkbox', { name: 'Use suggested value' })).not.toBeChecked();
	await expect(reviewRow.getByText('Preselected — blank field and exact structured match', { exact: true })).toHaveCount(0);
	expect(await page.locator('#name').inputValue()).toBe('Existing manual product');
});

test('@enr01 @enr09 malformed decoded contract renders only recovery and remains zero-write', async ({ page }) =>
{
	const malformed = { ...v2NameReviewEnvelope, provider_payload: { secret: 'canary' } };
	await page.route('**/api/**', async function (route)
	{
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(malformed) });
	});
	await page.goto('/fixtures/productform.html');
	await page.locator('#grocy-ai-upc').fill(validGtin);
	await page.locator('#grocy-ai-search-button').click();

	await expect(page.locator('#grocy-ai-status')).toContainText('Suggestions could not be verified. Retry the search, or continue editing manually. Nothing was changed.');
	await expect(page.locator('[data-grocy-ai-field]')).toHaveCount(0);
	expect(await page.locator('#name').inputValue()).toBe('Existing manual product');
	const counters = await page.evaluate(function () { return window.__fixtureCounters; });
	expect(counters.product + counters.barcode + counters.stock + counters.file + counters.save).toBe(0);
});

test('@enr05 seven-family final diff @enr06 @enr09 is independent, stale-safe, selected-only, and zero-write', async ({ page }) =>
{
	await installEnvelope(page);
	await page.setViewportSize({ width: 320, height: 844 });
	await page.goto('/fixtures/productform.html');
	await page.locator('#name').fill('');
	await search(page);

	const families = ['name', 'brand', 'package_size', 'product_group', 'quantity_unit', 'food_type', 'product_image'];
	const rows = page.locator('[data-grocy-ai-field]');
	if (await rows.count() !== families.length)
	{
		process.stderr.write('EXPECTED_RED: review.seven_family_diff\n');
	}
	await expect(rows).toHaveCount(families.length);

	for (const family of families)
	{
		const row = page.locator('[data-grocy-ai-field="' + family + '"]');
		await expect(row.getByText('Current', { exact: true })).toBeVisible();
		await expect(row.getByText('Suggested', { exact: true })).toBeVisible();
		await expect(row.getByText('Open Food Facts', { exact: true })).toBeVisible();
		await expect(row).toContainText('Retrieved');
	}

	await expect(page.locator('[data-grocy-ai-field="name"]')).toContainText('Source updated');
	await expect(page.locator('[data-grocy-ai-field="brand"]')).toContainText('Source update time unavailable');
	await expect(page.locator('[data-grocy-ai-field="package_size"]')).toContainText('No matching Grocy field is configured.');
	await expect(page.locator('[data-grocy-ai-field="package_size"] img')).toHaveCount(0);
	expect(await page.evaluate(function () { return window.__providerCanary || 0; })).toBe(0);
	await expect(page.locator('[data-grocy-ai-field="food_type"]')).toContainText('No local food type is configured.');
	await expect(page.locator('[data-grocy-ai-field="product_group"]')).toContainText('No matching Grocy option is available.');
	await expect(page.locator('[data-grocy-ai-field="package_size"] input[type="checkbox"]')).toBeDisabled();
	await expect(page.locator('[data-grocy-ai-field="food_type"] input[type="checkbox"]')).toBeDisabled();
	await expect(page.locator('[data-grocy-ai-field="product_group"] input[type="checkbox"]')).toBeDisabled();
	await expect(page.locator('[data-grocy-ai-field="product_image"] input[type="checkbox"]')).toBeDisabled();
	for (const control of [
		page.locator('#grocy-ai-use-name-label'),
		page.locator('#grocy-ai-use-brand-label'),
		page.locator('#grocy-ai-review-selected-button')
	])
	{
		const box = await control.boundingBox();
		expect(box).not.toBeNull();
		expect(box.width).toBeGreaterThanOrEqual(44);
		expect(box.height).toBeGreaterThanOrEqual(44);
	}
	const nameSelection = page.locator('[data-grocy-ai-field="name"] input[type="checkbox"]');
	const brandSelection = page.locator('[data-grocy-ai-field="brand"] input[type="checkbox"]');
	const groupSelection = page.locator('[data-grocy-ai-field="product_group"] input[type="checkbox"]');
	const unitSelection = page.locator('[data-grocy-ai-field="quantity_unit"] input[type="checkbox"]');
	await expect(nameSelection).toHaveAttribute('aria-describedby', /grocy-ai-name-heading-current.*grocy-ai-name-heading-suggested.*grocy-ai-name-heading-provenance/);
	await expect(nameSelection).toBeChecked();
	await expect(brandSelection).toBeChecked();
	await expect(groupSelection).not.toBeChecked();
	await expect(unitSelection).not.toBeChecked();

	await brandSelection.uncheck();
	await brandSelection.check();
	await expect(page.locator('[data-grocy-ai-field="brand"]')).toContainText('Selected by you');
	await unitSelection.check();
	await expect(page.locator('[data-grocy-ai-field="quantity_unit"]')).toContainText('Selected by you');

	await page.locator('#fixture-brand').fill('Manual brand after response');
	await page.locator('#grocy-ai-review-selected-button').click();
	await expect(page.locator('[data-grocy-ai-field="brand"]')).toContainText('This field changed after the search. Review it again before staging.');
	await expect(brandSelection).not.toBeChecked();
	await expect(brandSelection).toBeFocused();
	await expect(page.locator('#grocy-ai-final-diff [data-grocy-ai-diff-field="brand"]')).toHaveCount(0);

	await brandSelection.check();
	await page.locator('#grocy-ai-review-selected-button').click();
	await expect(page.locator('#grocy-ai-final-diff-heading')).toBeFocused();
	await expect(page.locator('#grocy-ai-final-diff [data-grocy-ai-diff-field]')).toHaveCount(4);
	await expect(page.locator('#grocy-ai-final-diff [data-grocy-ai-diff-field="barcode"]')).toContainText(validGtin);
	await expect(page.locator('#grocy-ai-final-diff [data-grocy-ai-diff-field="name"]')).toContainText('Preselected');
	await expect(page.locator('#grocy-ai-final-diff [data-grocy-ai-diff-field="brand"]')).toContainText('Selected by you');
	await expect(page.locator('#grocy-ai-final-diff [data-grocy-ai-diff-field="quantity_unit"]')).toContainText('Selected by you');
	await expect(page.locator('#grocy-ai-final-diff [data-grocy-ai-diff-field="product_group"]')).toHaveCount(0);

	await page.locator('#grocy-ai-back-to-suggestions-button').click();
	await expect(page.locator('#grocy-ai-review-selected-button')).toBeFocused();
	await page.locator('#grocy-ai-review-selected-button').click();
	await page.locator('#grocy-ai-stage-selected-button').click();

	await expect(page.locator('#grocy-ai-staging-feedback')).toContainText("Selected changes are staged in the form. Review the form, then use Grocy's Save button to save them.");
	await expect(page.locator('#grocy-ai-selection-status')).toContainText('4 changes selected');
	expect(await page.locator('#name').inputValue()).toBe('Fixture rolled oats');
	expect(await page.locator('#fixture-brand').inputValue()).toBe('Fixture Farms');
	expect(await page.locator('#qu_id_stock').inputValue()).toBe('5');
	expect(await page.locator('#product_group_id').inputValue()).toBe('2');
	await expect(page.locator('#fixture-brand')).toHaveClass(/is-dirty/);
	await expect(page.locator('.save-product-button').first()).toBeEnabled();
	await expect(page.locator('.save-product-button').last()).toBeEnabled();

	const counters = await page.evaluate(function () { return window.__fixtureCounters; });
	expectZeroWrites(counters);
	expect(counters.fieldEvents.name.input + counters.fieldEvents.name.change).toBeGreaterThan(0);
	expect(counters.fieldEvents['fixture-brand'].input + counters.fieldEvents['fixture-brand'].change).toBeGreaterThan(0);
	expect(counters.fieldEvents.qu_id_stock.input + counters.fieldEvents.qu_id_stock.change).toBeGreaterThan(0);
	expect(counters.fieldEvents.product_group_id.input + counters.fieldEvents.product_group_id.change).toBe(0);
	expect(counters.fieldEvents['product-picture'].input + counters.fieldEvents['product-picture'].change).toBe(0);

	const overflow = await page.evaluate(function ()
	{
		const grids = Array.from(document.querySelectorAll('.grocy-ai-comparison-grid, .grocy-ai-diff-grid'));
		return {
			document: document.documentElement.scrollWidth - document.documentElement.clientWidth,
			columns: grids.map(function (grid) { return getComputedStyle(grid).gridTemplateColumns.split(' ').length; })
		};
	});
	expect(overflow.document).toBeLessThanOrEqual(0);
	expect(overflow.columns.every(function (count) { return count === 2; })).toBe(true);
});

test('@enr05 seven-family zero selection stays reversible, disabled, and zero-write at 390px', async ({ page }) =>
{
	await installEnvelope(page);
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto('/fixtures/productform.html');
	await page.locator('#name').fill('');
	await search(page);
	await page.locator('[data-grocy-ai-field="name"] input[type="checkbox"]').uncheck();
	await page.locator('[data-grocy-ai-field="brand"] input[type="checkbox"]').uncheck();
	await page.locator('#grocy-ai-remove-staged-barcode').click();
	await expect(page.locator('#grocy-ai-selection-status')).toContainText('0 changes selected');
	await expect(page.locator('#grocy-ai-review-selected-button')).toBeDisabled();
	const counters = await page.evaluate(function () { return window.__fixtureCounters; });
	expectZeroWrites(counters);
	const overflow = await page.evaluate(function () { return document.documentElement.scrollWidth - document.documentElement.clientWidth; });
	expect(overflow).toBeLessThanOrEqual(0);
});
