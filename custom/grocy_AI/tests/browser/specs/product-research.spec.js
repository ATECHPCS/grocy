const { test, expect } = require('@playwright/test');

const trip = { id: 7, created_at: '', created_by: 1, status: 'reviewing', default_location_id: 1, default_shopping_location_id: null, transaction_id: null, committed_at: null, checksum: null, module_version: '2.6.0' };
const line = { id: 31, trip_id: 7, seq: 1, scanned_barcode: '012345678905', canonical_gtin: '00012345678905', resolved_product_id: null, status: 'unknown', quantity: 1, price: null, best_before_override: null, selected: true, applied_at: null, outcome: null, created_at: '', updated_at: '' };
function draft(state = 'ready')
{
	return { id: 5, line_id: 31, seq: 1, revision: 2, outcome: state === 'ready' ? 'provisional' : 'needs_input', job_state: state, safe_error_code: state === 'retryable_failure' ? 'provider_unavailable' : null, scanned_barcode: line.scanned_barcode, canonical_gtin: line.canonical_gtin, selected: { name: 'Oat Milk', brand: 'Acme', package: '1 L' }, suggested: { sources: ['openfoodfacts'], categories: ['en:beverages'] }, name_alternatives: [{ value: 'Oat Milk', sources: ['bb-federation'], provenance: 'attributed' }, { value: 'Oat Drink', sources: ['openfoodfacts'], provenance: 'attributed' }], receipt_evidence: { receipt_line_id: 19, description: 'OAT MILK 1L' }, group_candidates: [{ id: 3, name: 'Beverages', source: 'openfoodfacts', provider_category: 'en:beverages' }], taxonomy_candidates: [{ slug: 'plant-milk', label: 'Plant milk', source: 'openfoodfacts', provider_category: 'en:beverages', ruleset_version: 1 }], possible_existing_products: [{ id: 82, name: 'Oat Milk', reason: 'exact_name' }], final_product_id: null };
}
async function setup(page, state = 'ready')
{
	const data = { draft: draft(state), writes: [], receipts: [], lines: [line], drafts: null, approved: false, evidenceReject: false, referenceGets: [] };
	await page.route('**/api/objects/**', route => { const path = new URL(route.request().url()).pathname; data.referenceGets.push(path); return route.fulfill({ json: path.endsWith('/locations') ? [{ id: 1, name: 'Pantry', active: 1 }] : path.endsWith('/products') ? [{ id: 82, name: 'Oat Milk', active: 1 }, { id: 91, name: 'Completely Different Pantry Item', active: 1 }] : [{ id: 2, name: 'Each', active: 1 }] }); });
	await page.route('**/api/grocy-ai/capture/**', route =>
	{
		const path = new URL(route.request().url()).pathname;
		const method = route.request().method();
		if (method !== 'GET') data.writes.push({ path, body: route.request().postDataJSON() });
		if (path.endsWith('/trips')) return route.fulfill({ json: { trips: [trip] } });
		if (path.endsWith('/trips/7')) return route.fulfill({ json: { trip, lines: data.approved ? data.lines.map(v => v.id === 31 ? { ...v, status: 'known', resolved_product_id: 82 } : v) : data.lines, checksum: 'a'.repeat(64) } });
		if (path.endsWith('/receipt-readiness')) return route.fulfill({ json: { ready: false, reasons: [data.approved ? 'no_receipts' : 'unknown_product'], receipts: data.receipts } });
		if (path.endsWith('/research') && method === 'GET') return route.fulfill({ json: { contract_version: 1, trip_id: 7, drafts: data.drafts || [data.draft] } });
		if (path.endsWith('/research') && method === 'PUT') { Object.assign(data.draft.selected, route.request().postDataJSON().changes); data.draft.revision++; return route.fulfill({ json: data.draft }); }
		if (path.endsWith('/receipt-evidence') && data.evidenceReject) return route.fulfill({ status: 400, json: { error_message: 'Invalid evidence' } });
		if (path.endsWith('/receipt-evidence')) { data.draft.receipt_evidence = { receipt_line_id: 19, description: 'OAT MILK 1L' }; data.draft.revision++; return route.fulfill({ json: data.draft }); }
		if (path.endsWith('/retry')) { data.draft.job_state = 'queued'; return route.fulfill({ json: data.draft }); }
		if (path.endsWith('/approve') || path.endsWith('/link')) { data.approved = true; return route.fulfill({ json: { product_id: 82, outcome: 'linked', revision: 3 } }); }
		return route.fulfill({ status: 404, json: {} });
	});
	await page.goto('/fixtures/capture-review.html');
	await page.locator('#grocyai-capture-review-trips button').click();
	await expect(page.locator('.grocy-ai-product-research')).toBeVisible();
	return data;
}

test('product research card shows status, provenance, evidence, and keeps commit disabled', async ({ page }) =>
{
	await setup(page);
	await expect(page.getByText('Ready to review')).toBeVisible();
	await expect(page.getByText('Oat Drink')).toBeVisible();
	await expect(page.getByText('Oat Drink — Open Food Facts')).toBeVisible();
	await expect(page.getByText('Oat Milk — Barcode Lookup Federation')).toBeVisible();
	await expect(page.getByText(/Receipt evidence: OAT MILK 1L/)).toBeVisible();
	await expect(page.getByRole('button', { name: 'Commit purchase' })).toBeDisabled();
});

test('product research edits, links, and requires two confirmations', async ({ page }) =>
{
	const data = await setup(page);
	await page.getByLabel('Proposed name').fill('Corrected Oat Milk');
	await page.getByRole('button', { name: 'Save research draft' }).click();
	await expect.poll(() => data.writes.some(w => w.body?.changes?.name === 'Corrected Oat Milk')).toBe(true);
	let prompts = 0;
	page.on('dialog', async dialog => { prompts++; await dialog.accept(); });
	await page.getByLabel('Existing product', { exact: true }).selectOption('82');
	await page.getByRole('button', { name: 'Link existing product' }).click();
	await expect.poll(() => data.writes.some(w => w.path.endsWith('/link'))).toBe(true);
	expect(prompts).toBe(2);
	expect(data.writes.find(w => w.path.endsWith('/link')).body.product_id).toBe(82);
});

test('product research failure offers retry and approval stays permission gated', async ({ page }) =>
{
	const data = await setup(page, 'retryable_failure');
	await expect(page.getByText('Provider unavailable')).toBeVisible();
	await page.getByRole('button', { name: 'Retry research' }).click();
	await expect.poll(() => data.writes.some(w => w.path.endsWith('/retry'))).toBe(true);
	await expect(page.locator('.grocy-ai-product-research .permission-MASTER_DATA_EDIT')).toHaveCount(2);
});

test('product research processing and miss remain provisional', async ({ page }) =>
{
	const data = await setup(page, 'queued');
	await expect(page.getByText('Researching')).toBeVisible();
	await expect(page.getByRole('button', { name: 'Commit purchase' })).toBeDisabled();
	data.draft.job_state = 'needs_input';
	await page.locator('#grocyai-capture-review-trips button').click();
	await expect(page.getByText('Needs details')).toBeVisible();
	await expect(page.getByRole('button', { name: 'Retry research' })).toBeVisible();
});

test('product research approval posts only after two confirmed summaries', async ({ page }) =>
{
	const data = await setup(page);
	await page.getByLabel('Purchase unit').selectOption('2');
	await page.getByLabel('Stock unit').selectOption('2');
	await page.getByLabel('Product group').selectOption('3');
	await page.getByLabel('Food classification').selectOption('plant-milk');
	let prompts = [];
	page.on('dialog', async dialog => { prompts.push(dialog.message()); await dialog.dismiss(); });
	await page.getByRole('button', { name: 'Approve new product' }).click();
	expect(data.writes.some(w => w.path.endsWith('/approve'))).toBe(false);
	page.removeAllListeners('dialog');
	page.on('dialog', async dialog => { prompts.push(dialog.message()); await dialog.accept(); });
	await page.getByRole('button', { name: 'Approve new product' }).click();
	await expect.poll(() => data.writes.some(w => w.path.endsWith('/approve'))).toBe(true);
	expect(prompts.length).toBe(3);
	expect(prompts[1]).toContain(line.scanned_barcode);
	expect(prompts[2]).toContain('Beverages');
	expect(data.writes.find(w => w.path.endsWith('/approve')).body.fields.name).toBe('Oat Milk');
});

test('product research receipt evidence selection is explicit and does not commit', async ({ page }) =>
{
	const data = await setup(page);
	await page.getByLabel('Receipt line evidence').selectOption('');
	await expect.poll(() => data.writes.some(w => w.path.endsWith('/receipt-evidence'))).toBe(true);
	expect(data.writes.find(w => w.path.endsWith('/receipt-evidence')).body).toEqual({ receipt_line_id: null });
	await expect(page.getByRole('button', { name: 'Commit purchase' })).toBeDisabled();
});

test('product research forbidden approval keeps the draft and shows permission recovery', async ({ page }) =>
{
	const data = await setup(page);
	await page.route('**/research/approve', route => route.fulfill({ status: 403, json: { error_message: 'Forbidden' } }));
	await page.getByLabel('Purchase unit').selectOption('2');
	await page.getByLabel('Stock unit').selectOption('2');
	page.on('dialog', dialog => dialog.accept());
	await page.getByRole('button', { name: 'Approve new product' }).click();
	await expect(page.getByText('You need product edit permission.')).toBeVisible();
	expect(data.draft.final_product_id).toBeNull();
	await expect(page.getByRole('button', { name: 'Commit purchase' })).toBeDisabled();
});


test('product research save preserves unsaved receipt editor text', async ({ page }) =>
{
	const data = await setup(page);
	data.receipts.push({ receipt: { id: 9, status: 'needs_review', merchant: '', purchase_date: null, printed_total: null, shopping_location_id: null, difference_accepted_amount: null }, lines: [], totals: { entered_total: 0, printed_total: null, difference: null }, issues: ['no_lines'] });
	await page.locator('#grocyai-capture-review-trips button').click();
	await page.getByLabel('Merchant').fill('Corner Market');
	await page.getByLabel('Proposed name').fill('Corrected Oat Milk');
	await page.getByRole('button', { name: 'Save research draft' }).click();
	await expect.poll(() => data.writes.some(w => w.body?.changes?.name === 'Corrected Oat Milk')).toBe(true);
	await expect(page.getByLabel('Merchant')).toHaveValue('Corner Market');
});


test('product research shows sourced brand and package as research notes only', async ({ page }) =>
{
	const data = await setup(page);
	delete data.draft.selected.brand;
	delete data.draft.selected.package;
	data.draft.suggested.brand = 'Provider Brand';
	data.draft.suggested.package = '750 ml';
	await page.locator('#grocyai-capture-review-trips button').click();
	await expect(page.getByText('Provider Brand — Open Food Facts')).toBeVisible();
	await expect(page.getByText('750 ml — Open Food Facts')).toBeVisible();
	await expect(page.getByText(/research notes.*not saved to the Grocy product/i)).toBeVisible();
	await page.getByLabel('Brand research note').fill('Corrected Brand');
	await page.getByRole('button', { name: 'Save research draft' }).click();
	await expect.poll(() => data.writes.some(w => w.body?.changes?.brand === 'Corrected Brand')).toBe(true);
});

test('product research searches active products beyond exact-name candidates', async ({ page }) =>
{
	const data = await setup(page);
	await page.getByLabel('Search existing products').fill('Different Pantry');
	await expect(page.getByLabel('Existing product', { exact: true })).toContainText('Completely Different Pantry Item');
	await page.getByLabel('Existing product', { exact: true }).selectOption('91');
	page.on('dialog', dialog => dialog.accept());
	await page.getByRole('button', { name: 'Link existing product' }).click();
	await expect.poll(() => data.writes.some(w => w.path.endsWith('/link') && w.body.product_id === 91)).toBe(true);
});

test('product research cards stay beside their scan rows for fifteen unknowns', async ({ page }) =>
{
	const data = await setup(page);
	data.lines = Array.from({ length: 15 }, (_, index) => ({ ...line, id: 31 + index, seq: index + 1, scanned_barcode: String(index + 1).padStart(12, '0') }));
	data.drafts = data.lines.map((item, index) => ({ ...draft(), id: 5 + index, line_id: item.id, seq: item.seq, scanned_barcode: item.scanned_barcode }));
	await page.locator('#grocyai-capture-review-trips button').click();
	await expect(page.locator('.grocy-ai-product-research')).toHaveCount(15);
	for (let seq = 1; seq <= 15; seq++) await expect(page.locator('.grocy-ai-capture-review-line[data-line-seq="' + seq + '"] > .grocy-ai-product-research')).toHaveCount(1);
	await expect.poll(() => data.referenceGets.filter(path => path.endsWith('/quantity_units')).length).toBeLessThanOrEqual(3);
});

test('product research evidence choices identify receipt and line, exclude unusable lines, and restore rejection', async ({ page }) =>
{
	const data = await setup(page);
	data.receipts = [9, 10].map(id => ({ receipt: { id, merchant: id === 9 ? 'North Market' : 'South Market', status: 'needs_review' }, lines: [{ id: id * 10 + 1, kind: 'item', decision: 'include', description: 'Milk' }, { id: id * 10 + 2, kind: 'item', decision: 'ignore', description: 'Milk' }, { id: id * 10 + 3, kind: 'discount', decision: 'include', description: 'Milk' }], totals: { entered_total: 0, printed_total: null, difference: null }, issues: [] }));
	data.draft.receipt_evidence = { receipt_line_id: 91, receipt_id: 9, description: 'Milk', source: 'receipt_ocr' };
	await page.locator('#grocyai-capture-review-trips button').click();
	const evidence = page.getByLabel('Receipt line evidence');
	await expect(evidence.locator('option')).toHaveCount(3);
	await expect(evidence.locator('option[value="91"]')).toContainText('Receipt #9');
	await expect(evidence.locator('option[value="101"]')).toContainText('South Market');
	data.evidenceReject = true;
	await evidence.selectOption('101');
	await expect(page.getByText('Could not save product review.')).toBeVisible();
	await expect(evidence).toHaveValue('91');
});

test('product research denies catalog actions visibly without edit permission', async ({ page }) =>
{
	await setup(page);
	await page.evaluate(() => { window.Grocy.UserPermissions = [{ permission_name: 'MASTER_DATA_EDIT', has_permission: 0 }]; });
	await page.locator('#grocyai-capture-review-trips button').click();
	await expect(page.getByRole('button', { name: 'Approve new product' })).toBeDisabled();
	await expect(page.getByRole('button', { name: 'Link existing product' })).toBeDisabled();
	await expect(page.getByText(/product edit permission is required/i)).toBeVisible();
});

test('product research approval resolves the scan while receipt readiness stays blocked', async ({ page }) =>
{
	const data = await setup(page);
	await page.getByLabel('Purchase unit').selectOption('2');
	await page.getByLabel('Stock unit').selectOption('2');
	page.on('dialog', dialog => dialog.accept());
	await page.getByRole('button', { name: 'Approve new product' }).click();
	await expect(page.locator('.grocy-ai-capture-review-line.status-known')).toHaveCount(1);
	await expect(page.locator('.grocy-ai-product-research')).toHaveCount(0);
	await expect(page.getByRole('button', { name: 'Commit purchase' })).toBeDisabled();
	await expect(page.locator('#grocyai-receipt-readiness')).toContainText('Add at least one receipt');
	expect(data.approved).toBe(true);
});

test('product research save sends only fields the reviewer changed', async ({ page }) =>
{
	const data = await setup(page);
	data.draft.selected = { name: 'Oat Milk' };
	data.draft.suggested.brand = 'Provider Brand';
	data.draft.suggested.package = '750 ml';
	await page.locator('#grocyai-capture-review-trips button').click();
	await page.getByLabel('Proposed name').fill('Corrected Oat Milk');
	await page.getByRole('button', { name: 'Save research draft' }).click();
	await expect.poll(() => data.writes.some(w => w.path.endsWith('/research') && w.body?.changes)).toBe(true);
	expect(data.writes.find(w => w.path.endsWith('/research') && w.body?.changes).body.changes).toEqual({ name: 'Corrected Oat Milk' });
});
