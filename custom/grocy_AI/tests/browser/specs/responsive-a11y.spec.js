const { test, expect } = require('@playwright/test');

const validGtin = '012345678905';
const viewportWidths = [320, 375, 390, 768];

function terminalEnvelope(outcome)
{
	return {
		contract_version: 2,
		outcome: outcome,
		barcode: { scanned_gtin: validGtin, canonical_gtin: '00012345678905', equivalents_checked: [validGtin, '00012345678905'], status: 'unused', owner_product_id: null },
		suggestions: [],
		media: [],
		warnings: [],
		diagnostics: { trace_id: '4bf92f3577b34da6a3ce929d0e0e4736' }
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
	await page.clock.pauseAt(new Date(clockStart.getTime() + 1000));
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

function integratedEnvelope()
{
	const media = function (id, kind, suffix, source, evidence)
	{
		return {
			id: id,
			kind: kind,
			thumbnail_handle: 'thumbnail_' + suffix + '_capability_001',
			full_handle: 'full_' + suffix + '_capability_0000001',
			source: source,
			confidence_band: evidence === 'search' ? 'unverified' : 'high',
			reason_code: evidence === 'search' ? 'unverified_search_result' : 'canonical_structured_front_image',
			evidence_kind: evidence,
			retrieved_at: '2026-08-13T12:00:00Z'
		};
	};
	return {
		contract_version: 2,
		outcome: 'found',
		barcode: {
			scanned_gtin: validGtin,
			canonical_gtin: '00012345678905',
			equivalents_checked: [validGtin, '00012345678905'],
			status: 'unused',
			owner_product_id: null
		},
		suggestions: [{
			id: 'name:openfoodfacts:responsive',
			field: 'name',
			value: 'A deliberately long rolled-oats product value that must wrap without hiding the decision',
			display_value: 'A deliberately long rolled-oats product value that must wrap without hiding the decision',
			source: { id: 'openfoodfacts', label: 'Open Food Facts — long localized source label' },
			confidence_band: 'high',
			reason_code: 'canonical_structured_match',
			evidence_kind: 'structured_direct',
			retrieved_at: '2026-08-13T12:00:00Z',
			source_updated_at: null,
			target: null
		}],
		media: [
			media('image:openfoodfacts:responsive', 'front_package', 'front', { id: 'openfoodfacts', label: 'Open Food Facts' }, 'structured_direct'),
			media('image:openfoodfacts:responsive-secondary', 'front_package', 'frontsecondary', { id: 'openfoodfacts', label: 'Open Food Facts alternate' }, 'structured_direct'),
			media('image:searxng:responsive', 'search_alternative', 'search', { id: 'searxng', label: 'Search result' }, 'search')
		],
		warnings: [],
		diagnostics: { trace_id: '4bf92f3577b34da6a3ce929d0e0e4736' }
	};
}

async function installIntegratedRoute(page)
{
	await page.route('**/api/grocy-ai/products/enrich/upc/**', route => route.fulfill({
		status: 200,
		contentType: 'application/json',
		body: JSON.stringify(integratedEnvelope())
	}));
}

for (const width of viewportWidths)
{
	test('@enr05 @enr07 @enr08 @enr09 responsive integrated review at ' + width + 'px stays side by side and follows the image grid', async ({ page }) =>
	{
		await installIntegratedRoute(page);
		await page.setViewportSize({ width: width, height: width === 768 ? 1024 : 844 });
		await page.goto('/fixtures/productform.html');
		await page.locator('#name').fill('');
		await page.locator('#grocy-ai-upc').fill(validGtin);
		await page.locator('#grocy-ai-search-button').click();
		const row = page.locator('[data-grocy-ai-field="name"]');
		await expect(row).toBeVisible();

		const cells = row.locator('.grocy-ai-comparison-grid .grocy-ai-value-cell');
		await expect(cells).toHaveCount(2);
		const currentBox = await cells.nth(0).boundingBox();
		const suggestedBox = await cells.nth(1).boundingBox();
		expect(Math.abs(currentBox.y - suggestedBox.y)).toBeLessThan(2);
		expect(suggestedBox.x).toBeGreaterThan(currentBox.x);
		await expect(row.getByText('A deliberately long rolled-oats product value', { exact: false })).toBeVisible();

		const candidates = page.locator('#grocy-ai-structured-media .grocy-ai-media-candidate');
		await expect(candidates).toHaveCount(2);
		await expect(page.locator('.grocy-ai-media-candidate')).toHaveCount(3);
		const first = await candidates.nth(0).boundingBox();
		const second = await candidates.nth(1).boundingBox();
		if (width === 320) expect(second.y).toBeGreaterThan(first.y);
		else expect(Math.abs(second.y - first.y)).toBeLessThan(2);
		expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);

		await page.locator('#grocy-ai-review-selected-button').click();
		const diffCells = page.locator('[data-grocy-ai-diff-field="name"] .grocy-ai-value-cell');
		await expect(diffCells).toHaveCount(2);
		const beforeBox = await diffCells.nth(0).boundingBox();
		const afterBox = await diffCells.nth(1).boundingBox();
		expect(Math.abs(beforeBox.y - afterBox.y)).toBeLessThan(2);
	});
}

test('@enr05 @enr06 @enr07 @enr09 accessibility focus, checkbox, live-region, night, and reduced-motion contracts compose', async ({ page }) =>
{
	await page.emulateMedia({ reducedMotion: 'reduce', colorScheme: 'dark' });
	await installIntegratedRoute(page);
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto('/fixtures/productform.html');
	await page.evaluate(() => document.body.classList.add('night-mode'));
	await page.locator('#name').fill('');
	await page.locator('#grocy-ai-upc').fill(validGtin);
	await page.locator('#grocy-ai-search-button').click();

	const row = page.locator('[data-grocy-ai-field="name"]');
	const checkbox = row.getByRole('checkbox', { name: 'Use suggested value' });
	await expect(checkbox).toHaveAttribute('type', 'checkbox');
	await expect(checkbox).toBeChecked();
	const label = row.locator('label[for="' + await checkbox.getAttribute('id') + '"]');
	await expectTouchTarget(label, 'suggestion checkbox label');
	await expect(page.locator('#grocy-ai-selection-status')).toHaveAttribute('aria-live', 'polite');
	await expect(page.locator('#grocy-ai-error')).toHaveAttribute('role', 'alert');
	await expect(row.getByText('Preselected — blank field and exact structured match', { exact: true })).toBeVisible();

	await page.locator('#grocy-ai-review-selected-button').click();
	await expect(page.locator('#grocy-ai-final-diff-heading')).toBeFocused();
	await page.locator('#grocy-ai-back-to-suggestions-button').click();
	await expect(page.locator('#grocy-ai-review-selected-button')).toBeFocused();
	await expect(row).toHaveCSS('border-color', 'rgb(108, 117, 125)');

	const reducedMotionContract = await page.evaluate(function ()
	{
		function containsReducedMotionRule(rules, insideReducedMotion)
		{
			for (const rule of Array.from(rules || []))
			{
				const reduced = insideReducedMotion || (rule.conditionText || '').includes('prefers-reduced-motion: reduce');
				if (reduced && (rule.selectorText || '').includes('.grocy-ai-card .fa-spin') && rule.style.animationName === 'none') return true;
				if (rule.cssRules && containsReducedMotionRule(rule.cssRules, reduced)) return true;
			}
			return false;
		}
		return {
			matches: matchMedia('(prefers-reduced-motion: reduce)').matches,
			rule: Array.from(document.styleSheets).some(sheet => containsReducedMotionRule(sheet.cssRules, false))
		};
	});
	expect(reducedMotionContract).toEqual({ matches: true, rule: true });
});
