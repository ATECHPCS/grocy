const { test, expect } = require('@playwright/test');

const gtinA = '012345678905';
const gtinB = '4006381333931';

function responseFor(gtin, name, outcome)
{
	const traceId = '4bf92f3577b34da6a3ce929d0e0e4736';
	return {
		contract_version: 2,
		outcome: outcome === 'success' ? 'found' : outcome,
		barcode: { scanned_gtin: gtin, canonical_gtin: gtin.padStart(14, '0'), equivalents_checked: [gtin, gtin.padStart(14, '0')], status: 'unused', owner_product_id: null },
		suggestions: outcome === 'success' ? [{ id: 'name:openfoodfacts:0', field: 'name', value: name, display_value: name, source: { id: 'openfoodfacts', label: 'Open Food Facts' }, confidence_band: 'high', reason_code: 'canonical_structured_match', evidence_kind: 'structured_direct', retrieved_at: '2026-08-13T12:00:00Z', source_updated_at: null, target: null }] : [],
		media: [],
		warnings: [],
		diagnostics: { trace_id: traceId }
	};
}

async function start(page, gtin)
{
	await page.locator('#grocy-ai-upc').fill(gtin);
	await page.locator('#grocy-ai-upc').press('Enter');
}

test('@mob04 ten identical taps and scans coalesce; explicit retry is exactly one new request and trace', async ({ page }) =>
{
	const requests = [];
	let releaseFirst;
	const firstGate = new Promise(function (resolve) { releaseFirst = resolve; });
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		requests.push({
			traceparent: route.request().headers()['traceparent'],
			path: new URL(route.request().url()).pathname
		});
		if (requests.length === 1)
		{
			await firstGate;
			await route.fulfill({ status: 502, contentType: 'application/json', body: JSON.stringify(responseFor(gtinA, '', 'provider_error')) });
			return;
		}
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(responseFor(gtinA, 'Retry product', 'success')) });
	});

	await page.goto('/fixtures/productform.html');
	await page.locator('#grocy-ai-upc').fill(gtinA);
	await page.evaluate(function ()
	{
		for (let index = 0; index < 10; index++)
		{
			document.getElementById('grocy-ai-search-button').click();
			window.$(document).trigger('Grocy.BarcodeScanned', ['012345678905', 'grocy-ai-upc']);
		}
	});
	await expect.poll(function () { return requests.length; }).toBe(1);
	releaseFirst();
	await expect(page.locator('#grocy-ai-retry-button')).toBeVisible();
	await page.locator('#grocy-ai-retry-button').click();
	await expect.poll(function () { return requests.length; }).toBe(2);
	await expect(page.locator('[data-grocy-ai-field="name"]')).toContainText('Retry product');
	expect(requests[0].traceparent).toMatch(/^00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]$/);
	expect(requests[1].traceparent).toMatch(/^00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]$/);
	expect(requests[1].traceparent).not.toBe(requests[0].traceparent);

	await page.evaluate(function ()
	{
		window.dispatchEvent(new Event('online'));
		window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
	});
	expect(requests).toHaveLength(2);
});

test('@mob03 held A cannot overwrite newer intent B after GTIN edit or different scan', async ({ page }) =>
{
	let routeA;
	const requestGtins = [];
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		const gtin = decodeURIComponent(new URL(route.request().url()).pathname.split('/').pop());
		requestGtins.push(gtin);
		if (gtin === gtinA)
		{
			routeA = route;
			return;
		}
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(responseFor(gtinB, 'Current B product', 'success')) });
	});
	await page.goto('/fixtures/productform.html');
	await start(page, gtinA);
	await expect.poll(function () { return Boolean(routeA); }).toBe(true);
	await page.evaluate(function ()
	{
		window.$(document).trigger('Grocy.BarcodeScanned', ['4006381333931', 'grocy-ai-upc']);
	});
	await expect(page.locator('[data-grocy-ai-field="name"]')).toContainText('Current B product');
	await routeA.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(responseFor(gtinA, 'Obsolete A product', 'success')) }).catch(function () {});
	await expect(page.locator('[data-grocy-ai-field="name"]')).toContainText('Current B product');
	expect(await page.locator('body').innerText()).not.toContain('Obsolete A product');
	expect(requestGtins).toEqual([gtinA, gtinB]);
});

for (const invalidation of [
	{
		name: 'input edit',
		act: async function (page) { await page.locator('#grocy-ai-upc').fill(gtinB); },
		expected: 'GTIN ready.'
	},
	{
		name: 'Cancel',
		act: async function (page) { await page.locator('#grocy-ai-cancel-button').click(); },
		expected: 'Search cancelled. No changes were made.'
	},
	{
		name: 'Back pagehide',
		act: async function (page) {
			await page.evaluate(function () { window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })); });
		},
		expected: 'GTIN ready.'
	},
	{
		name: 'background visibility hidden',
		act: async function (page) {
			await page.evaluate(function ()
			{
				Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
				document.dispatchEvent(new Event('visibilitychange'));
			});
		},
		expected: 'GTIN ready.'
	}
])
{
	test('@mob03 held response is inert after ' + invalidation.name, async ({ page }) =>
	{
		let heldRoute;
		let requestCount = 0;
		await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
		{
			requestCount++;
			heldRoute = route;
		});
		await page.goto('/fixtures/productform.html');
		await start(page, gtinA);
		await expect.poll(function () { return Boolean(heldRoute); }).toBe(true);
		await invalidation.act(page);
		await expect(page.locator('#grocy-ai-status')).toHaveAttribute('aria-busy', 'false');
		await expect(page.locator('#grocy-ai-search-button')).toBeEnabled();
		await heldRoute.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(responseFor(gtinA, 'Obsolete late product', 'success')) }).catch(function () {});
		await expect(page.locator('#grocy-ai-status')).toContainText(invalidation.expected);
		expect(await page.locator('body').innerText()).not.toContain('Obsolete late product');
		expect(requestCount).toBe(1);
	});
}
