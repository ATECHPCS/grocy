const { test, expect } = require('@playwright/test');

const validGtin = '012345678905';
const canaries = [
	'api-key-canary-01',
	'cookie-canary-02',
	'bearer-canary-03',
	'request-body-canary-04',
	'response-body-canary-05',
	'https://provider.invalid/image.png?query-canary-06',
	'opaque-image-token-canary-07',
	'arbitrary-header-canary-08',
	'raw-exception-canary-09',
	'product-value-canary-10',
	'inventory-value-canary-11'
];

function traceIdFrom(traceparent)
{
	const match = /^00-([0-9a-f]{32})-[0-9a-f]{16}-0[01]$/.exec(traceparent || '');
	return match ? match[1] : null;
}

function safeEnvelope(traceId, outcome)
{
	return {
		found: outcome === 'success',
		product: outcome === 'success' ? { name: 'Safe fixture product', brand: 'Safe brand', size: '12 oz' } : {},
		images: [],
		sources: ['fixture-provider'],
		warnings: [],
		outcome: outcome,
		diagnostics: {
			schema_version: 1,
			versions: { grocy: '4.6.0', module: '1.0.0', companion: '0.1.0', contract: '1' },
			trace_id: traceId,
			outcome: outcome,
			stages: [
				{ name: 'grocy_connect', status: 'ok', error_code: null, cache: 'unknown', duration_ms: 4 },
				{ name: 'grocy_companion', status: outcome === 'success' ? 'ok' : 'timeout', error_code: outcome === 'success' ? null : 'deadline', cache: 'unknown', duration_ms: 24 },
				{ name: 'openfoodfacts', status: outcome === 'success' ? 'ok' : 'timeout', error_code: outcome === 'success' ? null : 'deadline', cache: 'miss', duration_ms: 20 }
			],
			overall_duration_ms: 28,
			api_key: canaries[0],
			cookie: canaries[1],
			authorization: 'Bearer ' + canaries[2],
			request_body: canaries[3],
			response_body: canaries[4],
			provider_url: canaries[5],
			download_token: canaries[6],
			headers: { 'x-canary': canaries[7] },
			stack: canaries[8],
			product_name: canaries[9],
			inventory: canaries[10]
		},
		raw_exception: canaries[8]
	};
}

async function installClipboard(page, blocked)
{
	await page.addInitScript(function (shouldBlock)
	{
		window.__copiedReports = [];
		Object.defineProperty(navigator, 'clipboard', {
			configurable: true,
			value: {
				writeText: function (value)
				{
					if (shouldBlock) return Promise.reject(new Error('clipboard denied'));
					window.__copiedReports.push(value);
					return Promise.resolve();
				}
			}
		});
	}, blocked);
}

function expectClosedReport(report, expectedTraceId, expectedOutcome)
{
	expect(Object.keys(report).sort()).toEqual([
		'browser_deadline_reached',
		'generated_at',
		'online_state',
		'outcome',
		'overall_duration_ms',
		'schema_version',
		'stages',
		'trace_id',
		'versions'
	]);
	expect(Object.keys(report.versions).sort()).toEqual(['companion', 'contract', 'grocy', 'module']);
	for (const stage of report.stages)
	{
		expect(Object.keys(stage).sort()).toEqual(['cache', 'duration_ms', 'error_code', 'name', 'status']);
	}
	expect(report.schema_version).toBe(1);
	expect(report.generated_at).toMatch(/^\d{4}-\d{2}-\d{2}T/);
	expect(report.trace_id).toBe(expectedTraceId);
	expect(report.outcome).toBe(expectedOutcome);
	expect(['online', 'offline', 'unknown', 'cancelled']).toContain(report.online_state);
	expect(typeof report.overall_duration_ms).toBe('number');
	expect(typeof report.browser_deadline_reached).toBe('boolean');
}

test('@mob05 @mob06 owned trace reaches Grocy and companion, copied diagnostics are closed, provider gets no trace', async ({ page }) =>
{
	await installClipboard(page, false);
	const hops = { browserToGrocy: null, grocyToCompanion: null, companionToProvider: {} };
	const consoleLines = [];
	page.on('console', function (message) { consoleLines.push(message.text()); });

	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		hops.browserToGrocy = route.request().headers()['traceparent'];
		const traceId = traceIdFrom(hops.browserToGrocy);
		expect(traceId).not.toBeNull();
		hops.grocyToCompanion = '00-' + traceId + '-1111111111111111-01';
		hops.companionToProvider = { accept: 'application/json' };
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(safeEnvelope(traceId, 'success')) });
	});

	await page.goto('/fixtures/productform.html');
	await page.locator('#grocy-ai-upc').fill(validGtin);
	await page.locator('#grocy-ai-upc').press('Enter');
	await expect(page.getByRole('heading', { name: 'Product details found' })).toBeVisible();

	const traceId = traceIdFrom(hops.browserToGrocy);
	expect(traceIdFrom(hops.grocyToCompanion)).toBe(traceId);
	expect(hops.companionToProvider.traceparent).toBeUndefined();
	expect(hops.companionToProvider.tracestate).toBeUndefined();
	await expect(page.locator('#grocy-ai-diagnostics')).not.toHaveAttribute('open', '');
	await expect(page.locator('#grocy-ai-diagnostic-summary')).toContainText(traceId.slice(-8));
	await expect(page.locator('#grocy-ai-diagnostic-summary')).toContainText('success');
	await expect(page.locator('#grocy-ai-diagnostic-summary')).toContainText(/\d+ ms/);
	await page.locator('#grocy-ai-copy-diagnostic-button').click();
	await expect(page.locator('#grocy-ai-diagnostic-feedback')).toHaveText('Diagnostic report copied.');

	const copied = await page.evaluate(function () { return window.__copiedReports[0]; });
	const report = JSON.parse(copied);
	expectClosedReport(report, traceId, 'success');
	const surfaces = [await page.locator('body').innerText(), copied, consoleLines.join('\n')].join('\n');
	expect(surfaces).not.toContain(validGtin);
	for (const canary of canaries) expect(surfaces).not.toContain(canary);
});

test('@mob06 blocked clipboard reveals selected read-only redacted fallback with exact feedback', async ({ page }) =>
{
	await installClipboard(page, true);
	let expectedTraceId;
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		expectedTraceId = traceIdFrom(route.request().headers()['traceparent']);
		await route.fulfill({ status: 504, contentType: 'application/json', body: JSON.stringify(safeEnvelope(expectedTraceId, 'timeout')) });
	});
	await page.goto('/fixtures/productform.html');
	await page.locator('#grocy-ai-upc').fill(validGtin);
	await page.locator('#grocy-ai-upc').press('Enter');
	await page.locator('#grocy-ai-copy-diagnostic-button').click();
	await expect(page.locator('#grocy-ai-diagnostic-feedback')).toHaveText('Copy was blocked. Select and copy the redacted report manually.');
	const fallback = page.locator('#grocy-ai-diagnostic-fallback');
	await expect(fallback).toBeVisible();
	await expect(fallback).toHaveAttribute('readonly', '');
	const selection = await fallback.evaluate(function (element)
	{
		return { start: element.selectionStart, end: element.selectionEnd, length: element.value.length, value: element.value };
	});
	expect(selection.start).toBe(0);
	expect(selection.end).toBe(selection.length);
	const report = JSON.parse(selection.value);
	expectClosedReport(report, expectedTraceId, 'timeout');
	for (const canary of canaries) expect(selection.value).not.toContain(canary);
});

test('@mob05 cancelled request keeps its trace in a copyable local diagnostic without retrying', async ({ page }) =>
{
	await installClipboard(page, false);
	let traceId;
	let requests = 0;
	await page.route('**/api/grocy-ai/products/enrich/upc/**', async function (route)
	{
		requests++;
		traceId = traceIdFrom(route.request().headers()['traceparent']);
		await new Promise(function () {});
	});
	await page.goto('/fixtures/productform.html');
	await page.locator('#grocy-ai-upc').fill(validGtin);
	await page.locator('#grocy-ai-upc').press('Enter');
	await expect.poll(function () { return requests; }).toBe(1);
	await page.locator('#grocy-ai-cancel-button').click();
	await page.locator('#grocy-ai-copy-diagnostic-button').click();
	const report = JSON.parse(await page.evaluate(function () { return window.__copiedReports[0]; }));
	expectClosedReport(report, traceId, 'cancelled');
	expect(report.online_state).toBe('cancelled');
	expect(requests).toBe(1);
});
