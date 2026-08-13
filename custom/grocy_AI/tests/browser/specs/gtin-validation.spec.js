const { test, expect } = require('@playwright/test');

const validGtins = [
	'96385074',
	'012345678905',
	'4006381333931',
	'10012345000017'
];

const invalidChecksumGtins = [
	'96385075',
	'012345678906',
	'4006381333932',
	'10012345000018'
];

const invalidLengthValues = [
	'',
	'1234567',
	'123456789',
	'12345678901',
	'123456789012345',
	'01234567890A'
];

async function installSuccessRoute(page, requestedGtins)
{
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		const gtin = decodeURIComponent(new URL(route.request().url()).pathname.split('/').pop());
		requestedGtins.push(gtin);
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				found: true,
				upc: gtin,
				product: { name: 'Fixture product ' + gtin },
				sources: ['fixture-provider'],
				images: [],
				warnings: []
			})
		});
	});
}

test('@mob01 valid GTIN-8/12/13/14 values preserve text and start one request each', async ({ page }) =>
{
	const requestedGtins = [];
	await installSuccessRoute(page, requestedGtins);
	await page.goto('/fixtures/productform.html');

	const gtinInput = page.locator('#grocy-ai-upc');
	const status = page.locator('#grocy-ai-status');

	for (const gtin of validGtins)
	{
		await gtinInput.fill(gtin);
		await expect(status).toContainText(/ready/i, { timeout: 250 });
		expect(await gtinInput.inputValue()).toBe(gtin);
		await gtinInput.press('Enter');
		await expect.poll(function () { return requestedGtins.length; }).toBe(requestedGtins.indexOf(gtin) + 1);
		await expect(page.getByRole('heading', { name: 'Product details found' })).toBeVisible();
	}

	expect(requestedGtins).toEqual(validGtins);
});

test('@mob01 invalid supported-length check digits show checksum feedback and make zero requests', async ({ page }) =>
{
	const requestedGtins = [];
	await installSuccessRoute(page, requestedGtins);
	await page.goto('/fixtures/productform.html');

	const gtinInput = page.locator('#grocy-ai-upc');
	const error = page.locator('#grocy-ai-error');
	const searchButton = page.locator('#grocy-ai-search-button');

	for (const gtin of invalidChecksumGtins)
	{
		await gtinInput.fill(gtin);
		await expect(error).toHaveText('That GTIN has an invalid check digit. Check the number and try again.', { timeout: 250 });
		await expect(error).toHaveAttribute('role', 'alert');
		await expect(gtinInput).toHaveAttribute('aria-invalid', 'true');
		await expect(gtinInput).toHaveAttribute('aria-describedby', /grocy-ai-error/);
		await expect(searchButton).toBeDisabled();
		await gtinInput.press('Enter');
	}

	await page.waitForTimeout(50);
	expect(requestedGtins).toEqual([]);
});

test('@mob01 invalid lengths and non-digits show length feedback and make zero requests', async ({ page }) =>
{
	const requestedGtins = [];
	await installSuccessRoute(page, requestedGtins);
	await page.goto('/fixtures/productform.html');

	const gtinInput = page.locator('#grocy-ai-upc');
	const error = page.locator('#grocy-ai-error');

	for (const value of invalidLengthValues)
	{
		await gtinInput.fill(value);
		await expect(error).toHaveText('Enter an 8, 12, 13, or 14 digit GTIN.', { timeout: 250 });
		await expect(error).toHaveAttribute('role', 'alert');
		await gtinInput.press('Enter');
	}

	await page.waitForTimeout(50);
	expect(requestedGtins).toEqual([]);
});

test('@mob01 whitespace and hyphens normalize without numeric coercion', async ({ page }) =>
{
	const requestedGtins = [];
	await installSuccessRoute(page, requestedGtins);
	await page.goto('/fixtures/productform.html');

	const gtinInput = page.locator('#grocy-ai-upc');
	await gtinInput.fill(' 0123-4567 8905 ');
	await gtinInput.press('Enter');

	await expect.poll(function () { return requestedGtins; }).toEqual(['012345678905']);
	expect((await gtinInput.inputValue()).replace(/[ -]/g, '')).toBe('012345678905');
});

test('@mob01 matching camera event shares validation and starts one request while unrelated targets are ignored', async ({ page }) =>
{
	const requestedGtins = [];
	await installSuccessRoute(page, requestedGtins);
	await page.goto('/fixtures/productform.html');

	await page.evaluate(function ()
	{
		window.$(document).trigger('Grocy.BarcodeScanned', ['96385074', 'unrelated-target']);
	});
	await page.waitForTimeout(50);
	expect(requestedGtins).toEqual([]);

	await page.evaluate(function ()
	{
		window.$(document).trigger('Grocy.BarcodeScanned', ['012345678905', 'grocy-ai-upc']);
	});

	await expect(page.locator('#grocy-ai-upc')).toHaveValue('012345678905');
	await expect.poll(function () { return requestedGtins; }).toEqual(['012345678905']);
	await expect(page.getByRole('heading', { name: 'Product details found' })).toBeVisible();
});
