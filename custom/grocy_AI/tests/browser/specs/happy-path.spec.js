const { test, expect } = require('@playwright/test');

const validGtin = '012345678905';
const successEnvelope = {
	contract_version: 2,
	outcome: 'found',
	barcode: { scanned_gtin: validGtin, canonical_gtin: '00012345678905', equivalents_checked: [validGtin, '00012345678905'], status: 'unused', owner_product_id: null },
	suggestions: [{ id: 'name:openfoodfacts:0', field: 'name', value: 'Fixture rolled oats', display_value: 'Fixture rolled oats', source: { id: 'openfoodfacts', label: 'Open Food Facts' }, confidence_band: 'high', reason_code: 'canonical_structured_match', evidence_kind: 'structured_direct', retrieved_at: '2026-08-13T12:00:00Z', source_updated_at: null, target: null }],
	media: [], warnings: [], diagnostics: { trace_id: '4bf92f3577b34da6a3ce929d0e0e4736' }
};

test('@smoke harness serves only the fixture and real phase-owned assets', async ({ page, request }) =>
{
	const fixtureResponse = await request.get('/fixtures/productform.html');
	const scriptResponse = await request.get('/assets/product-enrichment.js');
	const taxonomyScriptResponse = await request.get('/assets/product-taxonomy.js');
	const conversionScriptResponse = await request.get('/assets/conversion-explanations.js');
	const styleResponse = await request.get('/assets/grocy-ai.css');
	const arbitraryResponse = await request.get('/package.json');
	const traversalResponse = await request.get('/assets/%2e%2e/package.json');

	expect(fixtureResponse.status()).toBe(200);
	expect(scriptResponse.status()).toBe(200);
	expect(await scriptResponse.text()).toContain("'use strict'");
	expect(taxonomyScriptResponse.status()).toBe(200);
	expect(await taxonomyScriptResponse.text()).toContain("'use strict'");
	expect(conversionScriptResponse.status()).toBe(200);
	expect(await conversionScriptResponse.text()).toContain("'use strict'");
	expect(styleResponse.status()).toBe(200);
	expect(await styleResponse.text()).toContain('.grocy-ai-card');
	expect(arbitraryResponse.status()).toBe(404);
	expect([403, 404]).toContain(traversalResponse.status());

	const loadedAssets = [];
	page.on('response', function (response)
	{
		if (response.url().includes('/assets/')) loadedAssets.push([response.url(), response.status()]);
	});
	await page.goto('/fixtures/productform.html');
	expect(await page.locator('#grocy-ai-product-enrichment').isVisible()).toBe(true);
	expect(loadedAssets).toHaveLength(4);
	expect(loadedAssets.every(function (entry) { return entry[1] === 200; })).toBe(true);
	const versions = await page.locator('html').evaluate(function (element)
	{
		return {
			core: element.dataset.coreVersion,
			module: element.dataset.grocyAiModuleVersion
		};
	});
	expect(versions.module).not.toBe(versions.core);
	expect(loadedAssets.map(function (entry)
	{
		return new URL(entry[0]).searchParams.get('v');
	})).toEqual([versions.module, versions.module, versions.module, versions.module]);
	expect(await page.evaluate(function () { return window.__fixtureAdapterVersion; })).toBe('jquery-compatible-fixture-1.0.0');
});

test('@smoke @mob01 @mob02 @mob04 @mob07 @mob08 phone enrichment happy path remains review-only', async ({ page }) =>
{
	const violations = [];
	const networkRequests = [];
	let releaseEnrichment;
	const enrichmentGate = new Promise(function (resolve) { releaseEnrichment = resolve; });

	await page.route('**/api/**', async function (route)
	{
		const request = route.request();
		networkRequests.push({ method: request.method(), url: new URL(request.url()).pathname });
		if (new URL(request.url()).pathname === '/api/grocy-ai/products/enrich/upc/' + validGtin)
		{
			await enrichmentGate;
			await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(successEnvelope) });
			return;
		}
		await route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"unexpected fixture route"}' });
	});

	await page.goto('/fixtures/productform.html');
	networkRequests.length = 0;
	expect(page.viewportSize()).toEqual({ width: 390, height: 844 });

	const nameInput = page.locator('#name');
	const pictureInput = page.locator('#product-picture');
	const gtinInput = page.locator('#grocy-ai-upc');
	const searchButton = page.locator('#grocy-ai-search-button');
	const saveButtons = page.locator('.save-product-button');
	const initialName = await nameInput.inputValue();

	await pictureInput.setInputFiles({
		name: 'manual-selection.png',
		mimeType: 'image/png',
		buffer: Buffer.from('fixture-picture')
	});

	for (let index = 0; index < await saveButtons.count(); index++)
	{
		expect(await saveButtons.nth(index).isEnabled()).toBe(true);
		await saveButtons.nth(index).click();
	}

	const validationStartedAt = Date.now();
	await gtinInput.fill(validGtin);
	await page.waitForTimeout(Math.max(0, 250 - (Date.now() - validationStartedAt)));
	const cardTextAfterValidation = await page.locator('#grocy-ai-product-enrichment').innerText();
	if (!/ready/i.test(cardTextAfterValidation))
	{
		violations.push('valid GTIN feedback was not visible within 250ms');
	}
	if (await gtinInput.inputValue() !== validGtin)
	{
		violations.push('leading-zero GTIN was not preserved');
	}

	await searchButton.click();
	await expect.poll(function () { return networkRequests.length; }).toBe(1);

	for (let index = 0; index < await saveButtons.count(); index++)
	{
		expect(await saveButtons.nth(index).isEnabled()).toBe(true);
		await saveButtons.nth(index).click();
	}

	releaseEnrichment();
	await expect(page.locator('[data-grocy-ai-field="name"]')).toContainText('Fixture rolled oats');

	for (let index = 0; index < await saveButtons.count(); index++)
	{
		expect(await saveButtons.nth(index).isEnabled()).toBe(true);
		await saveButtons.nth(index).click();
	}

	if (!await page.getByRole('heading', { name: 'Product details found' }).isVisible())
	{
		violations.push('success heading "Product details found" was not rendered');
	}

	expect(await nameInput.inputValue()).toBe(initialName);
	expect(await pictureInput.evaluate(function (input) {
		return input.files.length === 1 && input.files[0].name === 'manual-selection.png';
	})).toBe(true);
	expect(networkRequests).toEqual([
		{ method: 'GET', url: '/api/grocy-ai/products/enrich/upc/' + validGtin }
	]);

	const counters = await page.evaluate(function () { return window.__fixtureCounters; });
	expect(counters.enrichment).toBe(1);
	expect(counters.product).toBe(0);
	expect(counters.barcode).toBe(0);
	expect(counters.stock).toBe(0);
	expect(counters.file).toBe(0);
	expect(counters.save).toBe(0);
	expect(counters.saveClicks).toBe(6);

	expect(violations, 'Phase 1 phone happy-path behavior gaps').toEqual([]);
});

test('@mob01 enrichment card stays above Picture without phone-width overflow', async ({ page }) =>
{
	for (const width of [320, 390])
	{
		await page.setViewportSize({ width: width, height: 844 });
		await page.goto('/fixtures/productform.html');

		expect(await page.evaluate(function ()
		{
			return document.documentElement.scrollWidth <= document.documentElement.clientWidth;
		})).toBe(true);

		expect(await page.evaluate(function ()
		{
			var card = document.getElementById('grocy-ai-product-enrichment');
			var picture = document.getElementById('product-picture');
			return Boolean(card.compareDocumentPosition(picture) & Node.DOCUMENT_POSITION_FOLLOWING);
		})).toBe(true);

		for (const selector of ['#grocy-ai-scan-button', '#grocy-ai-search-button'])
		{
			const bounds = await page.locator(selector).boundingBox();
			expect(bounds.height).toBeGreaterThanOrEqual(44);
		}

		for (const saveButton of await page.locator('.save-product-button').all())
		{
			await expect(saveButton).toBeEnabled();
		}
	}
});
