const { test, expect } = require('@playwright/test');

const evidence = {
	product_id: 91,
	current_leaf: null,
	suggested_leaf: { id: 'leaf-produce', slug: 'produce', label: 'Produce' },
	evidence_source: 'provider_food_type',
	ruleset_version: 'v1',
	provider_category: 'produce',
	confidence_band: 'high',
	reason_code: 'mapped_provider_category'
};

test('@tax03 product taxonomy review is explicit, accessible, and isolated', async ({ page }) =>
{
	const writes = [];
	await page.route('**/api/grocy-ai/products/91/taxonomy', async route =>
	{
		if (route.request().method() === 'GET')
		{
			await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(evidence) });
			return;
		}
		writes.push(JSON.parse(route.request().postData()));
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ...evidence, current_leaf: { id: 'leaf-produce', slug: 'produce', label: 'Produce' } }) });
	});
	await page.goto('/fixtures/productform.html');

	await expect(page.getByRole('heading', { name: 'Food classification' })).toBeVisible();
	await expect(page.getByText('Why this type is suggested')).toBeVisible();
	await expect(page.getByText('Produce · high confidence')).toBeVisible();
	await expect(page.getByText('Evidence source: Provider food type · Value: produce · Ruleset: v1')).toBeVisible();
	await expect(page.getByRole('radio', { name: 'Produce' })).toBeVisible();
	await expect(page.getByRole('button', { name: 'Leave Unclassified' })).toBeVisible();
	await expect(page.locator('.save-product-button').first()).toBeEnabled();

	await page.getByRole('radio', { name: 'Produce' }).check();
	await page.getByRole('button', { name: 'Assign food type' }).click();
	await expect(page.locator('#grocy-ai-taxonomy-status')).toContainText('Food type updated.');
	await expect(page.getByRole('heading', { name: 'Food classification' })).toBeFocused();
	await page.getByRole('button', { name: 'Leave Unclassified' }).click();
	await expect.poll(() => writes.length).toBe(2);
	expect(writes).toEqual([
		{ leaf_slug: 'produce', ruleset_version: 'v1' },
		{ unclassified: true, ruleset_version: 'v1' }
	]);
	const counters = await page.evaluate(() => window.__fixtureCounters);
	expect(counters.taxonomy).toBe(3);
	expect(counters.product).toBe(0);
	expect(counters.stock).toBe(0);
	expect(counters.category).toBe(0);
	expect(counters.conversion).toBe(0);
	expect(counters.userfield).toBe(0);
	expect(counters.unknownWrites).toEqual([]);
});
