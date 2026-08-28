const { test, expect } = require('@playwright/test');

const inactive = {
	status: 'inactive',
	scope: 'reusable',
	blockers: [],
	factor: '453.5923700000001',
	dimension: 'mass',
	source_version: 'NIST-SP-811-2008-Appendix-B.9',
	inactive_revision_id: 'conversion-catalog-v1'
};

async function choose(page, from, to, factor)
{
	await page.locator('#from_qu_id').selectOption(from);
	await page.locator('#to_qu_id').selectOption(to);
	await page.locator('#factor').fill(factor);
}

async function validateImpact(page)
{
	const button = page.locator('#validate-quconversion-impact-button');
	await button.focus();
	await button.press('Enter');
}

async function saveConversion(page)
{
	const button = page.locator('#save-quconversion-button');
	await button.focus();
	await button.press('Enter');
}

test('@conv04 inactive reusable validation shows reviewed evidence and never enables native Save', async ({ page }) =>
{
	const pageErrors = [];
	page.on('pageerror', function (error) { pageErrors.push(error.message); });
	const methods = [];
	await page.route('**/api/grocy-ai/conversions/validate?**', async function (route)
	{
		methods.push(route.request().method());
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(inactive) });
	});
	await page.setViewportSize({ width: 320, height: 720 });
	await page.goto('/fixtures/quantityunitconversionform.html');
	await choose(page, '1', '2', inactive.factor);
	await validateImpact(page);

	const status = page.locator('#qu-conversion-validation-status');
	await expect(status).toHaveAttribute('role', 'status');
	await expect(status).toContainText('Inactive — not saved or active');
	await expect(status).toContainText('Dimension: Mass');
	await expect(status).toContainText('1 pound = 453.5923700000001 gram');
	await expect(status).toContainText('Source: NIST SP 811 · NIST-SP-811-2008-Appendix-B.9');
	await expect(status).toContainText('No blocking paths, cycles, reciprocal conflicts, or tolerance failures were found.');
	await expect(status).toContainText('Reusable conversion profiles are inactive until both branch checks pass.');
	expect(pageErrors).toEqual([]);
	await expect(status).toHaveClass(/alert-warning/);
	await expect(page.locator('#save-quconversion-button')).toBeDisabled();
	await page.locator('#save-quconversion-button').evaluate(function (button) { button.click(); });
	expect(await page.evaluate(function () { return window.__fixtureNativeWrites || null; })).toEqual([]);
	await expect(page.locator('body')).not.toContainText('Ruleset ready');
	expect((await page.locator('#validate-quconversion-impact-button').boundingBox()).height).toBeGreaterThanOrEqual(44);
	expect((await page.locator('#save-quconversion-button').boundingBox()).height).toBeGreaterThanOrEqual(44);
	expect(await page.evaluate(function () { return document.documentElement.scrollWidth <= window.innerWidth; })).toBe(true);
	expect(methods).toEqual(['GET']);
});

test('@conv04 changed candidate rejects a late earlier response and preserves current inactive state', async ({ page }) =>
{
	let firstRoute;
	let announceFirstRoute;
	const firstRouteArrived = new Promise(function (resolve) { announceFirstRoute = resolve; });
	await page.route('**/api/grocy-ai/conversions/validate?**', async function (route)
	{
		const factor = new URL(route.request().url()).searchParams.get('factor');
		if (factor === '1000')
		{
			firstRoute = route;
			announceFirstRoute();
			return;
		}
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
			status: 'inactive', scope: 'reusable', blockers: [], factor: '1000.0000000001', dimension: 'mass',
			source_version: 'NIST-SP-811-2008-Appendix-B.9', inactive_revision_id: 'conversion-catalog-v1'
		}) });
	});
	await page.goto('/fixtures/quantityunitconversionform.html');
	await choose(page, '3', '2', '1000');
	await validateImpact(page);
	await firstRouteArrived;
	await expect(page.locator('#qu-conversion-validation-status')).toContainText('Validating conversion impact…');
	await expect(page.locator('#qu-conversion-validation-status')).toHaveAttribute('aria-busy', 'true');
	await expect(page.locator('#validate-quconversion-impact-button')).toBeDisabled();
	await expect(page.locator('#save-quconversion-button')).toBeDisabled();
	await page.locator('#factor').fill('1000.0000000001');
	await expect(page.locator('#qu-conversion-validation-status')).toContainText('This validation is out of date.');
	await validateImpact(page);
	await expect(page.locator('#qu-conversion-validation-pair')).toHaveText('1 kilogram = 1000.0000000001 gram');
	await firstRoute.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
		status: 'active', scope: 'reusable', blockers: [], factor: '1000', dimension: 'mass',
		source_version: 'old-source-must-not-render', inactive_revision_id: 'old-revision'
	}) });
	await expect(page.locator('#qu-conversion-validation-source')).not.toContainText('old-source');
	await expect(page.locator('#qu-conversion-validation-label')).toHaveText('Inactive — not saved or active');
	await expect(page.locator('#save-quconversion-button')).toBeDisabled();
});

test('@conv04 product package validation keeps the existing native Save available without invented provenance', async ({ page }) =>
{
	await page.route('**/api/grocy-ai/conversions/validate?**', async function (route)
	{
		const url = new URL(route.request().url());
		expect(url.searchParams.get('product_id')).toBe('91');
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
			status: 'product_native', scope: 'product', blockers: [], factor: '12', dimension: 'product_scoped',
			source_version: 'server-field-not-a-product-source', inactive_revision_id: 'conversion-catalog-v1'
		}) });
	});
	await page.goto('/fixtures/quantityunitconversionform.html');
	await page.locator('input[name="product_id"]').evaluate(function (input) { input.value = '91'; });
	await choose(page, '5', '7', '12');
	await validateImpact(page);

	const status = page.locator('#qu-conversion-validation-status');
	await expect(status).toContainText('Product override');
	await expect(status).toContainText('This conversion takes precedence over any food-type profile and universal default.');
	await expect(status).not.toContainText('server-field-not-a-product-source');
	await expect(status).not.toContainText('NIST');
	await expect(page.locator('#save-quconversion-button')).toBeEnabled();
	await saveConversion(page);
	expect(await page.evaluate(function () { return window.__fixtureNativeWrites || null; })).toEqual([{
		method: 'POST',
		url: 'objects/quantity_unit_conversions',
		data: { product_id: '91', from_qu_id: '5', to_qu_id: '7', factor: '12' }
	}]);
});

test('@conv04 product edit validation invokes the existing native PUT exactly once', async ({ page }) =>
{
	await page.route('**/api/grocy-ai/conversions/validate?**', async function (route)
	{
		const url = new URL(route.request().url());
		expect(url.searchParams.get('object_id')).toBe('42');
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
			status: 'product_native', scope: 'product', blockers: [], factor: '0.9', dimension: 'product_scoped',
			source_version: 'NIST-SP-811-2008-Appendix-B.9', inactive_revision_id: 'conversion-catalog-v1'
		}) });
	});
	await page.goto('/fixtures/quantityunitconversionform.html');
	await page.evaluate(function ()
	{
		window.Grocy.EditMode = 'edit';
		window.Grocy.EditObjectId = 42;
		document.querySelector('input[name="product_id"]').value = '91';
	});
	await choose(page, '4', '2', '0.9');
	await validateImpact(page);
	await expect(page.locator('#save-quconversion-button')).toBeEnabled();
	await saveConversion(page);
	expect(await page.evaluate(function () { return window.__fixtureNativeWrites || null; })).toEqual([{
		method: 'PUT',
		url: 'objects/quantity_unit_conversions/42',
		data: { product_id: '91', from_qu_id: '4', to_qu_id: '2', factor: '0.9' }
	}]);
});

test('@conv04 reusable package and dimension blockers use bounded alert copy and focus recovery', async ({ page }) =>
{
	await page.route('**/api/grocy-ai/conversions/validate?**', async function (route)
	{
		const from = new URL(route.request().url()).searchParams.get('from_qu_id');
		const blocker = from === '5' ? 'reusable_count_scope' : 'dimension_mismatch';
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
			status: 'blocked', scope: 'reusable', blockers: [blocker], factor: null, dimension: null,
			source_version: 'NIST-SP-811-2008-Appendix-B.9', inactive_revision_id: 'conversion-catalog-v1'
		}) });
	});
	await page.goto('/fixtures/quantityunitconversionform.html');
	await choose(page, '5', '7', '12');
	await validateImpact(page);
	await expect(page.locator('#qu-conversion-validation-status')).toHaveAttribute('role', 'alert');
	await expect(page.locator('#qu-conversion-validation-status')).toContainText('This quantity-unit pair is not eligible for a reusable default. Keep package and count conversions on the product.');
	await expect(page.locator('#qu-conversion-validation-heading')).toBeFocused();
	await expect(page.locator('#save-quconversion-button')).toBeDisabled();
	await page.locator('#save-quconversion-button').evaluate(function (button) { button.click(); });
	expect(await page.evaluate(function () { return window.__fixtureNativeWrites || null; })).toEqual([]);

	await choose(page, '1', '4', '1');
	await validateImpact(page);
	await expect(page.locator('#qu-conversion-validation-status')).toContainText('Mass and volume cannot be used in one universal conversion. Use an explicitly assigned food-type profile or a measured product conversion instead.');
	await expect(page.locator('#qu-conversion-validation-heading')).toBeFocused();
});

test('@conv04 failed read validation preserves values and exposes only bounded recovery copy', async ({ page }) =>
{
	await page.route('**/api/grocy-ai/conversions/validate?**', async function (route)
	{
		await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ error_message: 'raw backend diagnostic' }) });
	});
	await page.goto('/fixtures/quantityunitconversionform.html');
	await choose(page, '1', '2', '453.59237');
	await validateImpact(page);
	await expect(page.locator('#qu-conversion-validation-status')).toHaveAttribute('role', 'alert');
	await expect(page.locator('#qu-conversion-validation-status')).toContainText('This conversion could not be validated. Correct any visible fields or try again. Nothing was changed.');
	await expect(page.locator('body')).not.toContainText('raw backend diagnostic');
	await expect(page.locator('#factor')).toHaveValue('453.59237');
	await expect(page.locator('#qu-conversion-validation-heading')).toBeFocused();
	await expect(page.locator('#save-quconversion-button')).toBeDisabled();
});

test('@conv04 native rejection preserves entered values and exposes no invented source or cache state', async ({ page }) =>
{
	await page.route('**/api/grocy-ai/conversions/validate?**', async function (route)
	{
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
			status: 'product_native', scope: 'product', blockers: [], factor: '12', dimension: 'product_scoped',
			source_version: 'server-field-not-a-product-source', inactive_revision_id: 'conversion-catalog-v1'
		}) });
	});
	await page.goto('/fixtures/quantityunitconversionform.html');
	await page.evaluate(function ()
	{
		document.querySelector('input[name="product_id"]').value = '91';
		window.__fixtureNativeOutcome = 'reject';
	});
	await choose(page, '5', '7', '12');
	await validateImpact(page);
	await saveConversion(page);

	expect(await page.evaluate(function () { return window.__fixtureNativeWrites || null; })).toHaveLength(1);
	await expect(page.locator('#from_qu_id')).toHaveValue('5');
	await expect(page.locator('#to_qu_id')).toHaveValue('7');
	await expect(page.locator('#factor')).toHaveValue('12');
	expect(await page.evaluate(function () { return window.__fixtureGenericErrors || null; })).toHaveLength(1);
	await expect(page.locator('#qu-conversion-validation-status')).not.toContainText('NIST');
	await expect(page.locator('body')).not.toContainText('cache active');
});

async function resetConversionCounts(page)
{
	const response = await page.request.post('/__fixture/reset-conversion-counts');
	expect(response.status()).toBe(204);
}

async function conversionCounts(page)
{
	const response = await page.request.get('/__fixture/conversion-counts');
	expect(response.status()).toBe(200);
	return response.json();
}

function expectNoReusableMutation(counts)
{
	expect(counts.nativeUniversalPost).toBe(0);
	expect(counts.nativeUniversalPut).toBe(0);
	expect(counts.activation).toBe(0);
	expect(counts.projection).toBe(0);
	expect(counts.cache).toBe(0);
	expect(counts.unknownApi).toBe(0);
}

async function openProduct(page, productId)
{
	await page.goto('/fixtures/productform.html?product=' + productId);
	await expect(page.locator('[data-grocy-ai-conversion-summary]')).not.toBeEmpty();
}

test('@conv04 product conversion status reads make zero reusable mutation, activation, projection, or cache calls', async ({ page }) =>
{
	const pageErrors = [];
	page.on('pageerror', function (error) { pageErrors.push(error.message); });
	await resetConversionCounts(page);

	await openProduct(page, '91');
	await expect(page.locator('[data-grocy-ai-conversion-summary]')).toContainText('Product override');
	await expect(page.locator('[data-grocy-ai-conversion-badge]')).toHaveText('Exact');

	// The same read must stay inert after the ruleset is activated out of band.
	expect((await page.request.post('/__fixture/activate-ruleset')).status()).toBe(204);
	await openProduct(page, '92');
	await expect(page.locator('[data-grocy-ai-conversion-badge]')).toHaveText('Approximate');

	const counts = await conversionCounts(page);
	expect(counts.status).toBe(2);
	expect(counts.rulesetActivated).toBe(true);
	expect(counts.nativeProductPost).toBe(0);
	expect(counts.nativeProductPut).toBe(0);
	expectNoReusableMutation(counts);
	expect(pageErrors).toEqual([]);
});

test('@conv04 an inactive profile shows source and version but no usable factor and never claims to be active', async ({ page }) =>
{
	await resetConversionCounts(page);
	await openProduct(page, '92');

	const summary = page.locator('[data-grocy-ai-conversion-summary]');
	await expect(summary).toContainText('Approximate profile: whole-milk · USDA FoodData Central FDC-2024-10');
	await expect(summary).toContainText('Reusable conversion profiles are inactive until both branch checks pass.');
	await expect(page.locator('[data-grocy-ai-conversion-badge]')).toHaveText('Approximate');

	await page.locator('[data-grocy-ai-conversion-disclosure] > summary').click();
	const details = page.locator('[data-grocy-ai-conversion-details]');
	await expect(details).toContainText('Source version');
	await expect(details).not.toContainText('Full precision factor');
	await expect(page.locator('#grocy-ai-product-conversion-status')).not.toContainText('244');

	expectNoReusableMutation(await conversionCounts(page));
});

test('@conv04 a native product conversion discloses its full precision factor only after an explicit disclosure', async ({ page }) =>
{
	await resetConversionCounts(page);
	await openProduct(page, '91');

	const disclosure = page.locator('[data-grocy-ai-conversion-disclosure]');
	await expect(disclosure).toBeVisible();
	await expect(page.locator('[data-grocy-ai-conversion-details]')).not.toBeVisible();

	const control = disclosure.locator('summary');
	const box = await control.boundingBox();
	expect(box.height).toBeGreaterThanOrEqual(44);
	await control.focus();
	await control.press('Enter');

	await expect(page.locator('[data-grocy-ai-conversion-details]')).toContainText('Full precision factor');
	await expect(page.locator('[data-grocy-ai-conversion-details]')).toContainText('236.588');
});

test('@conv04 blocked and unavailable product status stay non-actionable and free of any factor at 320px', async ({ page }) =>
{
	await resetConversionCounts(page);
	await page.setViewportSize({ width: 320, height: 720 });

	await openProduct(page, '94');
	const status = page.locator('#grocy-ai-product-conversion-status');
	await expect(page.locator('[data-grocy-ai-conversion-badge]')).toHaveText('Blocked');
	await expect(page.locator('[data-grocy-ai-conversion-summary]')).toHaveAttribute('role', 'alert');
	await page.locator('[data-grocy-ai-conversion-disclosure] > summary').click();
	await expect(page.locator('[data-grocy-ai-conversion-details]')).toContainText('Correction needed');
	await expect(page.locator('[data-grocy-ai-conversion-details]')).toContainText('More than one conversion competes for this pair.');
	await expect(status).not.toContainText('same_rank_collision');
	// Read-only: the status region owns no control other than its disclosure.
	expect(await status.locator('button, input, select, a').count()).toBe(0);
	expect(await status.evaluate(function (node) { return node.scrollWidth - node.clientWidth; })).toBeLessThanOrEqual(0);

	await openProduct(page, '93');
	await expect(page.locator('[data-grocy-ai-conversion-summary]')).toContainText('No estimate is available until this product has an explicit food classification.');
	await expect(page.locator('[data-grocy-ai-conversion-badge]')).toHaveText('Unavailable');
	expect(await status.locator('button, input, select, a').count()).toBe(0);

	expectNoReusableMutation(await conversionCounts(page));
});

test('@conv04 normal product-scoped package and measured-density saves still reach only the native Grocy routes', async ({ page }) =>
{
	await resetConversionCounts(page);
	await openProduct(page, '91');

	await page.locator('#fixture-save-product-conversion').click();
	await expect(page.locator('#fixture-conversion-outcome')).toHaveText('Conversion saved');
	await page.locator('#fixture-save-product-density').click();
	await expect(page.locator('#fixture-conversion-outcome')).toHaveText('Conversion saved');

	const counts = await conversionCounts(page);
	expect(counts.nativeProductPost).toBe(1);
	expect(counts.nativeProductPut).toBe(1);
	expectNoReusableMutation(counts);

	// The status read did not rewrite the existing product conversion row.
	await expect(page.locator('#qu-conversion-row-4020')).toContainText('Package');
	await expect(page.locator('#qu-conversion-row-4020')).toContainText('6');
});

test('@conv04 a failed product status read preserves the native conversion table and shows bounded recovery copy', async ({ page }) =>
{
	await resetConversionCounts(page);
	await page.route('**/api/grocy-ai/products/*/conversion-status*', function (route)
	{
		return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ error_message: 'SQLSTATE[HY000] /config/data/grocy.db is locked' }) });
	});

	await page.goto('/fixtures/productform.html?product=91');
	const status = page.locator('#grocy-ai-product-conversion-status');
	await expect(status).toContainText('The conversion status could not be loaded. Try again. Nothing was changed.');
	await expect(status).not.toContainText('SQLSTATE');
	await expect(status).not.toContainText('grocy.db');
	await expect(page.locator('[data-grocy-ai-conversion-details]')).not.toBeVisible();

	await expect(page.locator('#qu-conversions-table-products')).toBeVisible();
	await expect(page.locator('#qu-conversion-row-4020')).toContainText('Package');
	await page.locator('#fixture-save-product-conversion').click();
	await expect(page.locator('#fixture-conversion-outcome')).toHaveText('Conversion saved');
});
