const { test, expect } = require('@playwright/test');

const validGtin = '012345678905';
const viewportWidths = [320, 375, 390, 768];

function terminalEnvelope(outcome)
{
	return {
		found: false,
		outcome: outcome,
		product: {},
		images: [],
		sources: [],
		warnings: [],
		diagnostics: {
			schema_version: 1,
			versions: { grocy: '4.6.0', module: '1.0.0', companion: '0.1.0', contract: '1' },
			trace_id: '4bf92f3577b34da6a3ce929d0e0e4736',
			outcome: outcome,
			stages: [{ name: 'grocy_companion', status: 'timeout', error_code: 'deadline', cache: 'miss', duration_ms: 5000 }],
			overall_duration_ms: 5000
		}
	};
}

async function installTimeoutRoute(page, requests)
{
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		requests.push(route.request().url());
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify(terminalEnvelope('timeout'))
		});
	});
}

async function expectTouchTarget(locator, name)
{
	const box = await locator.boundingBox();
	expect(box, name + ' must be rendered').not.toBeNull();
	expect(box.width, name + ' width').toBeGreaterThanOrEqual(44);
	expect(box.height, name + ' height').toBeGreaterThanOrEqual(44);
}

for (const width of viewportWidths)
{
	test('@mob08 ' + width + 'px has no overflow, visible recovery, and 44px actions', async ({ page }) =>
	{
		const requests = [];
		await installTimeoutRoute(page, requests);
		await page.setViewportSize({ width: width, height: width === 768 ? 1024 : 844 });
		await page.goto('/fixtures/productform.html');

		await expect(page.getByLabel('GTIN', { exact: true })).toBeVisible();
		await expect(page.getByRole('button', { name: 'Scan barcode' })).toBeVisible();
		await expect(page.getByRole('button', { name: 'Search product' })).toBeVisible();
		await expectTouchTarget(page.getByLabel('GTIN', { exact: true }), 'GTIN input');
		await expectTouchTarget(page.getByRole('button', { name: 'Scan barcode' }), 'Scan barcode');
		await expectTouchTarget(page.getByRole('button', { name: 'Search product' }), 'Search product');

		await page.getByLabel('GTIN', { exact: true }).fill(validGtin);
		await page.getByRole('button', { name: 'Search product' }).click();
		await expect(page.locator('#grocy-ai-status')).toContainText('The search took too long. Retry, or continue editing manually.');
		await expect(page.getByRole('button', { name: 'Retry search' })).toBeVisible();
		await expect(page.locator('#grocy-ai-diagnostic-summary')).toBeVisible();
		await expectTouchTarget(page.getByRole('button', { name: 'Retry search' }), 'Retry search');
		await expectTouchTarget(page.locator('#grocy-ai-diagnostic-summary'), 'Diagnostics disclosure');

		await page.locator('#grocy-ai-diagnostics').evaluate(function (details) { details.open = true; });
		await expect(page.getByRole('button', { name: 'Copy diagnostic report' })).toBeVisible();
		await expectTouchTarget(page.getByRole('button', { name: 'Copy diagnostic report' }), 'Copy diagnostic report');

		const overflow = await page.evaluate(function ()
		{
			return {
				document: document.documentElement.scrollWidth - document.documentElement.clientWidth,
				body: document.body.scrollWidth - document.body.clientWidth
			};
		});
		expect(overflow.document).toBeLessThanOrEqual(0);
		expect(overflow.body).toBeLessThanOrEqual(0);
		expect(requests).toHaveLength(1);
	});
}

test('@mob08 ARIA, focus, Enter, Escape, and feedback budgets are exact', async ({ page }) =>
{
	let requestCount = 0;
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function ()
	{
		requestCount++;
		await new Promise(function () {});
	});
	await page.goto('/fixtures/productform.html');

	const input = page.getByLabel('GTIN', { exact: true });
	const error = page.locator('#grocy-ai-error');
	const status = page.locator('#grocy-ai-status');
	await input.focus();
	await input.fill('1234567');
	await expect(error).toHaveText('Enter an 8, 12, 13, or 14 digit GTIN.', { timeout: 250 });
	await expect(error).toHaveAttribute('role', 'alert');
	await expect(input).toHaveAttribute('aria-describedby', /grocy-ai-error/);
	await expect(input).toBeFocused();
	await input.press('Enter');
	expect(requestCount).toBe(0);

	await input.fill(validGtin);
	await expect(status).toContainText('GTIN ready.', { timeout: 250 });
	await expect(status).toHaveAttribute('role', 'status');
	await expect(status).toHaveAttribute('aria-live', 'polite');
	await input.press('Enter');
	await expect(status).toContainText('Searching product details…', { timeout: 250 });
	await expect(status).toHaveAttribute('aria-busy', 'true');
	await expect(input).toBeFocused();
	await expect.poll(function () { return requestCount; }).toBe(1);
	await input.press('Escape');
	await expect(status).toContainText('Search cancelled. No changes were made.', { timeout: 250 });
	await expect(status).toHaveAttribute('aria-busy', 'false');
	await expect(input).toBeFocused();
});

test('@mob08 reduced motion and lifecycle invalidation leave restored controls without requests', async ({ page }) =>
{
	let heldRoute;
	let requestCount = 0;
	await page.emulateMedia({ reducedMotion: 'reduce' });
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		requestCount++;
		heldRoute = route;
	});
	await page.goto('/fixtures/productform.html');
	await page.addStyleTag({ content: '@keyframes fixture-spin { to { transform: rotate(360deg); } } .fa-spin { animation: fixture-spin 1s linear infinite; }' });

	const input = page.getByLabel('GTIN', { exact: true });
	const status = page.locator('#grocy-ai-status');
	const search = page.getByRole('button', { name: 'Search product' });
	await input.fill(validGtin);
	await search.click();
	await expect.poll(function () { return Boolean(heldRoute); }).toBe(true);
	await expect(status.locator('.fa-spinner')).toHaveCSS('animation-name', 'none');

	await page.setViewportSize({ width: 844, height: 390 });
	await page.evaluate(function () { window.dispatchEvent(new Event('orientationchange')); });
	await expect(status).toHaveAttribute('aria-busy', 'false');
	await expect(status.locator('.fa-spinner')).toHaveCount(0);
	await expect(search).toBeEnabled();
	await expect(page.locator('.modal')).toHaveCount(0);
	await expect(page.locator('#grocy-ai-results')).toBeHidden();

	await heldRoute.fulfill({
		status: 200,
		contentType: 'application/json',
		body: JSON.stringify({ found: true, outcome: 'success', product: { name: 'Obsolete orientation result' }, images: [], sources: [] })
	}).catch(function () {});
	await expect(page.locator('body')).not.toContainText('Obsolete orientation result');

	await page.evaluate(function ()
	{
		Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
		document.dispatchEvent(new Event('visibilitychange'));
		Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
		document.dispatchEvent(new Event('visibilitychange'));
		window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
		window.dispatchEvent(new Event('online'));
	});
	expect(requestCount).toBe(1);
	await expect(status).toHaveAttribute('aria-busy', 'false');
	await expect(search).toBeEnabled();
});

test('@mob08 browser timeout transitions only at exactly 15000ms', async ({ page }) =>
{
	const clockStart = new Date('2026-08-12T12:00:00Z');
	await page.clock.install({ time: clockStart });
	await page.clock.pauseAt(clockStart);
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function ()
	{
		await new Promise(function () {});
	});
	await page.goto('/fixtures/productform.html');
	await page.getByLabel('GTIN', { exact: true }).fill(validGtin);
	await page.getByLabel('GTIN', { exact: true }).press('Enter');

	const status = page.locator('#grocy-ai-status');
	await page.clock.runFor(14999);
	await expect(status).toContainText('Searching product details…');
	await expect(status).toHaveAttribute('aria-busy', 'true');
	await page.clock.runFor(1);
	await expect(status).toContainText('The search took too long. Retry, or continue editing manually.');
	await expect(status).toHaveAttribute('aria-busy', 'false');
});
