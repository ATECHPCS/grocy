const { test, expect } = require('@playwright/test');

const cameraUnavailable = 'Camera scanning is unavailable. Enter the GTIN manually.';
const invalidLength = 'Enter an 8, 12, 13, or 14 digit GTIN.';
const invalidChecksum = 'That GTIN has an invalid check digit. Check the number and try again.';
const validGtin = '012345678905';

async function installCameraPermission(page, state, rejectQuery)
{
	await page.addInitScript(function (options)
	{
		var listeners = [];
		var permissionStatus = {
			state: options.state,
			addEventListener: function (type, listener)
			{
				if (type === 'change') listeners.push(listener);
			},
			removeEventListener: function (type, listener)
			{
				if (type !== 'change') return;
				listeners = listeners.filter(function (candidate) { return candidate !== listener; });
			},
			changeTo: function (nextState)
			{
				permissionStatus.state = nextState;
				listeners.slice().forEach(function (listener) { listener.call(permissionStatus, new Event('change')); });
			}
		};
		window.__cameraPermissionStatus = permissionStatus;
		Object.defineProperty(navigator, 'permissions', {
			configurable: true,
			value: {
				query: function ()
				{
					if (options.rejectQuery) return Promise.reject(new Error('fixture permissions query rejection'));
					return Promise.resolve(permissionStatus);
				}
			}
		});
	}, { state: state, rejectQuery: Boolean(rejectQuery) });
}

async function expectNoEnrichmentRequests(page)
{
	await expect.poll(async function ()
	{
		return page.evaluate(function () { return window.__fixtureCounters.enrichment; });
	}).toBe(0);
}

test('@mob08 GTIN input owns its 44px touch target at supported widths', async ({ page }) =>
{
	for (const width of [320, 375, 390, 768])
	{
		await page.setViewportSize({ width: width, height: width === 768 ? 1024 : 844 });
		await page.goto('/fixtures/productform.html');
		const box = await page.getByLabel('GTIN', { exact: true }).boundingBox();
		expect(box, width + 'px GTIN input must be rendered').not.toBeNull();
		expect(box.height, width + 'px GTIN input height').toBeGreaterThanOrEqual(44);
	}
});

test('@mob01 invalid styling and accessibility state clear on a valid edit', async ({ page }) =>
{
	await page.goto('/fixtures/productform.html');
	const input = page.getByLabel('GTIN', { exact: true });
	const error = page.locator('#grocy-ai-error');
	const search = page.getByRole('button', { name: 'Search product' });

	for (const invalid of [
		{ value: '1234567', message: invalidLength },
		{ value: '012345678906', message: invalidChecksum }
	])
	{
		await input.fill(invalid.value);
		await expect(error).toHaveText(invalid.message, { timeout: 250 });
		await expect(input).toHaveClass(/\bis-invalid\b/);
		await expect(input).toHaveAttribute('aria-invalid', 'true');
		await expect(input).toHaveCSS('border-color', 'rgb(220, 53, 69)');
		await expect(search).toBeDisabled();
		await input.press('Enter');
	}

	await expectNoEnrichmentRequests(page);
	await input.fill(validGtin);
	await expect(input).not.toHaveClass(/\bis-invalid\b/);
	await expect(input).toHaveAttribute('aria-invalid', 'false');
	await expect(error).toBeHidden();
	await expect(search).toBeEnabled();
});

test('@mob08 already-denied camera permission recovers to manual entry without delegation', async ({ page }) =>
{
	await installCameraPermission(page, 'denied', false);
	await page.goto('/fixtures/productform.html');
	const input = page.getByLabel('GTIN', { exact: true });
	await input.fill(validGtin);
	await page.getByRole('button', { name: 'Scan barcode' }).click();

	await expect(page.locator('#grocy-ai-status')).toHaveText(cameraUnavailable);
	await expect(input).toBeFocused();
	expect(await input.inputValue()).toBe(validGtin);
	expect(await page.evaluate(function () { return window.__fixtureCounters.cameraDelegations; })).toBe(0);
	await expect(page.locator('.modal')).toHaveCount(0);
	await expect(page.locator('#grocy-ai-status')).toHaveAttribute('aria-busy', 'false');
	await expectNoEnrichmentRequests(page);
});

test('@mob08 prompt-to-denied camera permission recovers once after one scanner delegation', async ({ page }) =>
{
	await installCameraPermission(page, 'prompt', false);
	await page.goto('/fixtures/productform.html');
	const input = page.getByLabel('GTIN', { exact: true });
	await input.fill(validGtin);
	await page.getByRole('button', { name: 'Scan barcode' }).click();
	await expect.poll(async function ()
	{
		return page.evaluate(function () { return window.__fixtureCounters.cameraDelegations; });
	}).toBe(1);

	await page.evaluate(function () { window.__cameraPermissionStatus.changeTo('denied'); });
	await expect(page.locator('#grocy-ai-status')).toHaveText(cameraUnavailable);
	await expect(page.getByText(cameraUnavailable, { exact: true })).toHaveCount(1);
	await expect(input).toBeFocused();
	expect(await input.inputValue()).toBe(validGtin);
	expect(await page.evaluate(function () { return window.__fixtureCounters.cameraDelegations; })).toBe(1);
	await expect(page.locator('.modal')).toHaveCount(0);
	await expect(page.locator('#grocy-ai-status')).toHaveAttribute('aria-busy', 'false');
	await expectNoEnrichmentRequests(page);
});

for (const permissionCase of [
	{ name: 'granted', state: 'granted' },
	{ name: 'prompt without denial', state: 'prompt' },
	{ name: 'query rejection', state: 'prompt', rejectQuery: true }
])
{
	test('@mob08 ' + permissionCase.name + ' camera permission delegates exactly once', async ({ page }) =>
	{
		await installCameraPermission(page, permissionCase.state, permissionCase.rejectQuery);
		await page.goto('/fixtures/productform.html');
		await page.getByRole('button', { name: 'Scan barcode' }).click();
		await expect.poll(async function ()
		{
			return page.evaluate(function () { return window.__fixtureCounters.cameraDelegations; });
		}).toBe(1);
		await expectNoEnrichmentRequests(page);
	});
}

test('@mob08 unsupported Permissions API delegates exactly once', async ({ page }) =>
{
	await page.addInitScript(function ()
	{
		Object.defineProperty(navigator, 'permissions', { configurable: true, value: undefined });
	});
	await page.goto('/fixtures/productform.html');
	await page.getByRole('button', { name: 'Scan barcode' }).click();
	expect(await page.evaluate(function () { return window.__fixtureCounters.cameraDelegations; })).toBe(1);
	await expectNoEnrichmentRequests(page);
});
