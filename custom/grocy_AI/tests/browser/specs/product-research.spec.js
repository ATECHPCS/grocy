const { test, expect } = require('@playwright/test');

const trip = { id: 7, created_at: '', created_by: 1, status: 'reviewing', default_location_id: 1, default_shopping_location_id: null, transaction_id: null, committed_at: null, checksum: null, module_version: '2.6.0' };
const line = { id: 31, trip_id: 7, seq: 1, scanned_barcode: '012345678905', canonical_gtin: '00012345678905', resolved_product_id: null, status: 'unknown', quantity: 1, price: null, best_before_override: null, selected: true, applied_at: null, outcome: null, created_at: '', updated_at: '' };
function draft(state = 'ready')
{
	return { id: 5, line_id: 31, seq: 1, revision: 2, outcome: state === 'ready' ? 'provisional' : 'needs_input', line_status: 'unknown', resolved_product_id: null, resolved_product_name: null, job_state: state, safe_error_code: state === 'retryable_failure' ? 'provider_unavailable' : null, scanned_barcode: line.scanned_barcode, canonical_gtin: line.canonical_gtin, selected: { name: 'Oat Milk', brand: 'Acme', package: '1 L' }, suggested: { sources: ['openfoodfacts'], categories: ['en:beverages'] }, name_alternatives: [{ value: 'Oat Milk', sources: ['bb-federation'], provenance: 'attributed' }, { value: 'Oat Drink', sources: ['openfoodfacts'], provenance: 'attributed' }], receipt_evidence: { receipt_line_id: 19, description: 'OAT MILK 1L' }, group_candidates: [{ id: 3, name: 'Beverages', source: 'openfoodfacts', provider_category: 'en:beverages' }], taxonomy_candidates: [{ slug: 'plant-milk', label: 'Plant milk', source: 'openfoodfacts', provider_category: 'en:beverages', ruleset_version: 1 }], possible_existing_products: [{ id: 82, name: 'Oat Milk', reason: 'exact_name' }], final_product_id: null };
}
async function setup(page, state = 'ready')
{
	const data = { draft: draft(state), writes: [], receipts: [], lines: [line], drafts: null, approved: false, evidenceReject: false, referenceGets: [], catalogGets: 0, catalog: { contract_version: 1, taxonomy_version: 'v1', product_groups: [{ id: 3, name: 'Beverages' }, { id: 4, name: 'Pantry' }], taxonomy_leaves: [{ slug: 'plant-milk', label: 'Plant milk' }, { slug: 'produce', label: 'Produce' }], generic_parents: [{ id: 95, name: 'Generic Produce', qu_id_stock: 2 }] } };
	await page.route('**/api/objects/**', route => { const path = new URL(route.request().url()).pathname; data.referenceGets.push(path); return route.fulfill({ json: path.endsWith('/products/95') ? { id: 95, name: 'Generic Produce', active: 1, parent_product_id: null, qu_id_stock: data.parentStockUnit || 2 } : path.endsWith('/quantity_unit_conversions') ? [] : path.endsWith('/locations') ? [{ id: 1, name: 'Pantry', active: 1 }] : path.endsWith('/products') ? [{ id: 82, name: 'Oat Milk', active: 1 }, { id: 91, name: 'Completely Different Pantry Item', active: 1 }] : [{ id: 2, name: 'Each', active: 1 }] }); });
	await page.route('**/api/grocy-ai/capture/**', route =>
	{
		const path = new URL(route.request().url()).pathname;
		const method = route.request().method();
		if (method !== 'GET') data.writes.push({ path, body: route.request().postDataJSON() });
		if (path.endsWith('/research/options')) { data.catalogGets++; return route.fulfill({ json: data.catalog }); }
		if (path.endsWith('/trips')) return route.fulfill({ json: { trips: [trip] } });
		if (path.endsWith('/trips/7')) return route.fulfill({ json: { trip, lines: data.approved ? data.lines.map(v => v.id === 31 ? { ...v, status: 'known', resolved_product_id: 82 } : v) : data.lines, checksum: 'a'.repeat(64) } });
		if (path.endsWith('/receipt-readiness')) return route.fulfill({ json: { ready: false, reasons: [data.approved ? 'no_receipts' : 'unknown_product'], receipts: data.receipts } });
		if (path.endsWith('/research') && method === 'GET') return route.fulfill({ json: { contract_version: 1, trip_id: 7, drafts: data.drafts || [data.draft] } });
		if (path.endsWith('/research') && method === 'PUT') { Object.assign(data.draft.selected, route.request().postDataJSON().changes); data.draft.revision++; return route.fulfill({ json: data.draft }); }
		if (path.endsWith('/receipt-evidence') && data.evidenceReject) return route.fulfill({ status: 400, json: { error_message: 'Invalid evidence' } });
		if (path.endsWith('/receipt-evidence')) { data.draft.receipt_evidence = { receipt_line_id: 19, description: 'OAT MILK 1L' }; data.draft.revision++; return route.fulfill({ json: data.draft }); }
		if (path.endsWith('/retry')) { data.draft.job_state = 'queued'; return route.fulfill({ json: data.draft }); }
		if (path.endsWith('/approve') || path.endsWith('/link')) { data.approved = true; data.draft.outcome = path.endsWith('/approve') ? 'approved' : 'linked'; return route.fulfill({ json: { product_id: 82, outcome: data.draft.outcome, revision: 3 } }); }
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
	expect(data.catalogGets).toBeLessThanOrEqual(2);
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

test('known scan with an unfinished draft can only link to its current owner', async ({ page }) =>
{
	const data = await setup(page);
	data.lines = [{ ...line, status: 'known', resolved_product_id: 82 }];
	Object.assign(data.draft, { line_status: 'known', resolved_product_id: 82, resolved_product_name: 'Oat Milk' });
	await page.locator('#grocyai-capture-review-trips button').click();
	await expect(page.getByText(/Current product: Oat Milk.*#82/)).toBeVisible();
	await expect(page.getByRole('button', { name: 'Approve new product' })).toHaveCount(0);
	await expect(page.getByRole('button', { name: 'Link existing product' })).toHaveCount(0);
	let prompts = [];
	page.on('dialog', dialog => { prompts.push(dialog.message()); return dialog.accept(); });
	await page.getByRole('button', { name: 'Link to current product' }).click();
	await expect.poll(() => data.writes.some(w => w.path.endsWith('/link'))).toBe(true);
	expect(data.writes.find(w => w.path.endsWith('/link')).body).toEqual({ revision: 2, product_id: 82 });
	expect(prompts).toHaveLength(2);
	await expect(page.locator('.grocy-ai-product-research')).toHaveCount(0);
	await expect(page.getByRole('button', { name: 'Commit purchase' })).toBeDisabled();
});

test('known-owner link keeps recovery visible after a permission failure', async ({ page }) =>
{
	const data = await setup(page);
	data.lines = [{ ...line, status: 'known', resolved_product_id: 82 }];
	Object.assign(data.draft, { line_status: 'known', resolved_product_id: 82, resolved_product_name: 'Oat Milk' });
	await page.route('**/research/link', route => route.fulfill({ status: 403, json: { error_message: 'Forbidden' } }));
	await page.locator('#grocyai-capture-review-trips button').click();
	page.on('dialog', dialog => dialog.accept());
	await page.getByRole('button', { name: 'Link to current product' }).click();
	await expect(page.getByText('You need product edit permission.')).toBeVisible();
	await expect(page.getByRole('button', { name: 'Link to current product' })).toBeVisible();
	await expect(page.getByRole('button', { name: 'Commit purchase' })).toBeDisabled();
});

test('provider miss allows local group, taxonomy leaf, and compatible generic parent', async ({ page }) =>
{
	const data = await setup(page);
	Object.assign(data.draft, { job_state: 'needs_input', group_candidates: [], taxonomy_candidates: [], suggested: { sources: [], categories: [] } });
	await page.locator('#grocyai-capture-review-trips button').click();
	await page.getByLabel('Product group').selectOption('4');
	await page.getByLabel('Food classification').selectOption('produce');
	await page.getByLabel('Generic parent').selectOption('95');
	await page.getByLabel('Purchase unit').selectOption('2');
	await page.getByLabel('Stock unit').selectOption('2');
	const prompts = [];
	page.on('dialog', dialog => { prompts.push(dialog.message()); return dialog.accept(); });
	await page.getByRole('button', { name: 'Approve new product' }).click();
	await expect.poll(() => data.writes.some(w => w.path.endsWith('/approve'))).toBe(true);
	const fields = data.writes.find(w => w.path.endsWith('/approve')).body.fields;
	expect(fields).toMatchObject({ product_group_id: 4, taxonomy_leaf_slug: 'produce', parent_product_id: 95, location_id: 1, qu_id_purchase: 2, qu_id_stock: 2 });
	expect(prompts).toHaveLength(2);
	expect(prompts[1]).toContain('Pantry');
	expect(prompts[1]).toContain('Produce');
	expect(prompts[1]).toContain('Generic Produce');
	expect(prompts[1]).toContain('Each');
});

test('saved local classification stays selected across reload despite another provider suggestion', async ({ page }) =>
{
	const data = await setup(page);
	await page.getByLabel('Product group').selectOption('4');
	await page.getByLabel('Food classification').selectOption('produce');
	await page.getByRole('button', { name: 'Save research draft' }).click();
	await expect.poll(() => data.writes.some(w => w.body?.changes?.taxonomy_leaf_slug === 'produce')).toBe(true);
	await page.locator('#grocyai-capture-review-trips button').click();
	await expect(page.getByLabel('Product group')).toHaveValue('4');
	await expect(page.getByLabel('Food classification')).toHaveValue('produce');
});


test('OpenAI web suggestion shows safe citations on narrow screens without persistence', async ({ page }) =>
{
	const data = await setup(page);
	data.draft.selected.name = 'Reviewer name';
	data.draft.name_alternatives = [{ value: '<img src=x onerror=alert(1)>', sources: ['openai-web'], provenance: 'attributed', web_evidence: { exact_gtin_claim: false, citations: [{ title: '<b>Package source</b>', domain: 'example.com', url: 'https://example.com/' + 'a'.repeat(500) }] } }];
	data.draft.group_candidates = []; data.draft.taxonomy_candidates = []; data.draft.receipt_evidence = null;
	await page.setViewportSize({ width: 320, height: 700 });
	await page.locator('#grocyai-capture-review-trips button').click();
	await expect(page.getByText('OpenAI web suggestion — verify UPC')).toBeVisible();
	await expect(page.getByText('Verify against package')).toBeVisible();
	const link = page.locator('.grocy-ai-product-research-web a');
	await expect(link).toHaveAttribute('target', '_blank');
	await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
	await expect(link).toContainText('<b>Package source</b>');
	await expect(link).toContainText('example.com');
	await expect(page.getByLabel('Proposed name')).toHaveValue('Reviewer name');
	await expect(page.getByLabel('Product group')).toHaveValue('');
	await expect(page.getByLabel('Food classification')).toHaveValue('');
	await expect(page.getByRole('button', { name: 'Commit purchase' })).toBeDisabled();
	expect(data.writes).toEqual([]);
	expect(await link.evaluate(el => el.getBoundingClientRect().right <= window.innerWidth)).toBe(true);
	await expect(page.locator('.grocy-ai-product-research-names img')).toHaveCount(0);
});

test('OpenAI unsafe citation URLs never become links', async ({ page }) =>
{
	const data = await setup(page);
	data.draft.name_alternatives = [{ value: 'Web name', sources: ['openai-web'], provenance: 'attributed', web_evidence: { exact_gtin_claim: true, citations: ['javascript:alert(1)', 'https://127.0.0.1/a', 'https://example.com/a'].map(url => ({ title: 'Source', domain: 'wrong.example', url })) } }];
	await page.locator('#grocyai-capture-review-trips button').click();
	await expect(page.getByText('OpenAI web suggestion — verify UPC')).toBeVisible();
	await expect(page.locator('.grocy-ai-product-research-web a')).toHaveCount(0);
	expect(data.writes).toEqual([]);
});


test('OpenAI special-use citation hosts are rejected while public domains remain linked', async ({ page }) =>
{
	const data = await setup(page);
	for (const host of ['router.home.arpa', 'hidden.onion', 'node.alt', 'router.home', 'intranet.corp', 'server.mail', 'node.arpa', 'host.local', 'host.localhost', 'host.internal', 'host.lan', 'host.test', 'host.invalid', 'host.example', 'www.openfoodfacts.org'])
	{
		data.draft.name_alternatives = [{ value: 'Web name', sources: ['openai-web'], provenance: 'attributed', web_evidence: { exact_gtin_claim: false, citations: [{ title: 'Source', domain: host, url: 'https://' + host + '/product' }] } }];
		await page.locator('#grocyai-capture-review-trips button').click();
		await expect(page.getByText('OpenAI web suggestion — verify UPC')).toBeVisible();
		await expect(page.locator('.grocy-ai-product-research-web a')).toHaveCount(host === 'www.openfoodfacts.org' ? 1 : 0);
	}
	expect(data.writes).toEqual([]);
});

test('classification suggestions show source and preserve explicit parent clearing on mobile', async ({ page }) =>
{
	const data = await setup(page);
	data.draft.group_candidates = [{ id: 4, name: 'Pantry', source: 'openai-classification' }];
	data.draft.taxonomy_candidates = [{ slug: 'produce', label: 'Produce', source: 'openai-classification' }];
	data.draft.parent_candidates = [{ id: 95, name: 'Generic Produce', qu_id_stock: 2, source: 'openai-classification' }];
	data.draft.selected = { ...data.draft.selected, product_group_id: 4, taxonomy_leaf_slug: 'produce', parent_product_id: 95 };
	data.draft.classification = { state: 'suggested', source: 'openai-classification', result: { status: 'suggested' } };
	await page.locator('#grocyai-capture-review-trips button').click();
	await expect(page.getByLabel('Generic parent')).toHaveValue('95');
	await expect(page.getByLabel('Product group').locator('option:checked')).toContainText('OpenAI classification');
	await expect(page.getByText('Classification suggestions ready')).toBeVisible();
	expect(data.writes).toHaveLength(0);
	await page.getByLabel('Generic parent').selectOption('');
	await page.getByRole('button', { name: 'Save research draft' }).click();
	await expect.poll(() => data.writes.some(w => w.body?.changes && Object.hasOwn(w.body.changes, 'parent_product_id') && w.body.changes.parent_product_id === null)).toBe(true);
	await expect(page.getByLabel('Generic parent')).toHaveValue('');
	expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('parent stock unit mismatch blocks confirmation before approval', async ({ page }) =>
{
	const data = await setup(page);
	data.parentStockUnit = 99;
	await page.getByLabel('Purchase unit').selectOption('2');
	await page.getByLabel('Stock unit').selectOption('2');
	await page.getByLabel('Generic parent').selectOption('95');
	await expect(page.getByText('Generic parent needs a compatible stock unit. Choose another parent or stock unit.')).toBeVisible();
	await page.getByRole('button', { name: 'Approve new product' }).click();
	expect(data.writes.some(w => w.path.endsWith('/approve'))).toBe(false);
});
