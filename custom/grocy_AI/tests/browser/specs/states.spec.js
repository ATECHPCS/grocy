const { test, expect } = require('@playwright/test');

const validGtin = '012345678905';
const successProduct = {
	name: 'Fixture state product',
	brand: 'Fixture Foods',
	size: '12 oz'
};

function diagnostic(outcome, stageStatus, errorCode)
{
	return {
		schema_version: 1,
		versions: { grocy: '4.6.0', module: '1.0.0', companion: '0.1.0', contract: '1' },
		trace_id: '4bf92f3577b34da6a3ce929d0e0e4736',
		outcome: outcome,
		stages: [{ name: 'grocy_companion', status: stageStatus, error_code: errorCode, cache: 'unknown', duration_ms: 25 }],
		overall_duration_ms: 25
	};
}

function envelope(outcome, overrides)
{
	return Object.assign({
		found: outcome === 'success' || outcome === 'partial_image',
		product: successProduct,
		images: [],
		sources: ['fixture-provider'],
		warnings: [],
		outcome: outcome,
		diagnostics: diagnostic(outcome, outcome === 'provider_error' ? 'error' : 'ok', outcome === 'provider_error' ? 'provider_error' : null)
	}, overrides || {});
}

async function search(page)
{
	await page.goto('/fixtures/productform.html');
	await page.locator('#grocy-ai-upc').fill(validGtin);
	await page.locator('#grocy-ai-search-button').click();
}

test('@mob02 cancel restores controls immediately with exact copy', async ({ page }) =>
{
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function ()
	{
		await new Promise(function () {});
	});
	await search(page);
	await expect(page.locator('#grocy-ai-status')).toHaveAttribute('aria-busy', 'true');
	await page.locator('#grocy-ai-cancel-button').click();
	await expect(page.locator('#grocy-ai-status')).toContainText('Search cancelled. No changes were made.');
	await expect(page.locator('#grocy-ai-status')).toHaveAttribute('aria-busy', 'false');
	await expect(page.locator('#grocy-ai-search-button')).toBeEnabled();
	await expect(page.locator('#grocy-ai-cancel-button')).toBeHidden();
});

test('@mob02 exact 15,000ms browser deadline uses virtual clock and exposes explicit retry', async ({ page }) =>
{
	await page.clock.install({ time: new Date('2026-08-12T12:00:00Z') });
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function ()
	{
		await new Promise(function () {});
	});
	await search(page);

	await page.clock.runFor(14999);
	await expect(page.locator('#grocy-ai-status')).toContainText('Searching product details…');
	await expect(page.locator('#grocy-ai-status')).toHaveAttribute('aria-busy', 'true');

	await page.clock.runFor(1);
	await expect(page.locator('#grocy-ai-status')).toContainText('The search took too long. Retry, or continue editing manually.');
	await expect(page.locator('#grocy-ai-status')).toHaveAttribute('aria-busy', 'false');
	await expect(page.locator('#grocy-ai-retry-button')).toHaveText('Retry search');
});

test('@mob02 offline is distinct and reconnect or pageshow never auto-searches', async ({ page, context }) =>
{
	let requests = 0;
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		requests++;
		await route.abort('internetdisconnected');
	});
	await page.goto('/fixtures/productform.html');
	await page.locator('#grocy-ai-upc').fill(validGtin);
	await context.setOffline(true);
	await page.locator('#grocy-ai-search-button').click();
	await expect(page.locator('#grocy-ai-status')).toContainText('This phone is offline. Reconnect and retry, or continue editing manually.');
	await expect(page.locator('#grocy-ai-retry-button')).toBeVisible();
	await context.setOffline(false);
	await page.evaluate(function ()
	{
		window.dispatchEvent(new Event('online'));
		window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
	});
	await expect.poll(function () { return requests; }).toBeLessThanOrEqual(1);
	await expect(page.locator('#grocy-ai-status')).toContainText('This phone is offline.');
});

for (const scenario of [
	{
		name: 'not found',
		status: 200,
		body: envelope('not_found', { found: false }),
		copy: 'No exact product match was found. Check the GTIN or continue editing manually.',
		stateClass: 'alert-warning'
	},
	{
		name: 'companion unavailable',
		status: 503,
		body: envelope('provider_error', {
			found: false,
			diagnostics: diagnostic('provider_error', 'unavailable', 'connection')
		}),
		copy: 'Product search is temporarily unavailable. Retry, or continue editing manually.',
		stateClass: 'alert-danger'
	},
	{
		name: 'provider error',
		status: 502,
		body: envelope('provider_error', { found: false }),
		copy: 'A product data provider could not respond. Retry, or continue editing manually.',
		stateClass: 'alert-danger'
	},
	{
		name: 'partial image',
		status: 200,
		body: envelope('partial_image'),
		copy: 'Product details were found, but images are unavailable. You can continue without an image.',
		stateClass: 'alert-warning'
	},
	{
		name: 'success',
		status: 200,
		body: envelope('success'),
		copy: 'Product details found',
		stateClass: 'alert-success'
	}
])
{
	test('@mob02 finite ' + scenario.name + ' state uses exact safe copy and one result set', async ({ page }) =>
	{
		await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
		{
			await route.fulfill({ status: scenario.status, contentType: 'application/json', body: JSON.stringify(scenario.body) });
		});
		await search(page);
		await expect(page.locator('#grocy-ai-status')).toContainText(scenario.copy);
		await expect(page.locator('#grocy-ai-status')).toHaveClass(new RegExp(scenario.stateClass));
		await expect(page.locator('#grocy-ai-status')).toHaveAttribute('aria-busy', 'false');
		expect(await page.locator('#grocy-ai-status').count()).toBe(1);
		expect(await page.locator('#grocy-ai-results').count()).toBe(1);
		expect(await page.locator('body').innerText()).not.toContain('Something went wrong');
		expect(await page.locator('body').innerText()).not.toContain('raw exception');
	});
}

test('@mob02 Escape cancels only active work and Enter requires a locally valid GTIN', async ({ page }) =>
{
	let requests = 0;
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function ()
	{
		requests++;
		await new Promise(function () {});
	});
	await page.goto('/fixtures/productform.html');
	await page.locator('#grocy-ai-upc').fill('1234567');
	await page.locator('#grocy-ai-upc').press('Enter');
	expect(requests).toBe(0);
	await page.locator('#grocy-ai-upc').press('Escape');
	await expect(page.locator('#grocy-ai-error')).toHaveText('Enter an 8, 12, 13, or 14 digit GTIN.');

	await page.locator('#grocy-ai-upc').fill(validGtin);
	await page.locator('#grocy-ai-upc').press('Enter');
	await expect.poll(function () { return requests; }).toBe(1);
	await page.locator('#grocy-ai-upc').press('Escape');
	await expect(page.locator('#grocy-ai-status')).toContainText('Search cancelled. No changes were made.');
});
