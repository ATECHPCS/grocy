const { test, expect } = require('@playwright/test');
const trip = {
	id: 12,
	created_at: '2026-10-16',
	created_by: '1',
	status: 'open',
	default_location_id: null,
	default_shopping_location_id: null,
	transaction_id: null,
	committed_at: null,
	checksum: null,
	module_version: 'test',
};
function line(q = 1, known = true) {
	return {
		id: 100,
		trip_id: 12,
		seq: 1,
		scanned_barcode: '4006381333931',
		canonical_gtin: '04006381333931',
		resolved_product_id: known ? 2 : null,
		status: known ? 'known' : 'unknown',
		quantity: q,
		price: null,
		best_before_override: null,
		selected: 1,
		applied_at: null,
		outcome: null,
		created_at: '2026-10-16',
		updated_at: '2026-10-16',
	};
}
async function setup(
	page,
	{
		selected = 1,
		unknown = false,
		lost = false,
		deny = false,
		needsAmount = false,
		lostApproval = false,
		scanState = null,
		errorCode = null,
	} = {},
) {
	page.on('pageerror', (e) =>
		process.stderr.write('SHOPPING ERROR ' + e.message + '\n'),
	);
	const state = {
		contract_version: 1,
		selected_list_id: selected,
		lists: [
			{ id: 1, name: 'Weekly' },
			{ id: 2, name: 'Other' },
		],
		items: [
			{
				id: 1,
				list_id: 1,
				product_id: 1,
				name: 'Milk',
				note: 'Family size',
				amount: 3,
				qu_id: 1,
				unit: 'can',
				done: false,
				version: '1',
				collected_amount: 0,
				remaining_amount: 3,
				has_children: true,
			},
			{
				id: 2,
				list_id: 1,
				product_id: null,
				name: 'Paper towels',
				note: 'Paper towels',
				amount: 1,
				qu_id: null,
				unit: null,
				done: false,
				version: '1',
				collected_amount: 0,
				remaining_amount: 1,
				has_children: false,
			},
		],
		operations: [],
		can_change_list: true,
		permissions: { collect: true, curate: !deny },
	};
	const calls = [];
	let quantity = 0,
		known = !unknown,
		drop = lost;
	await page.route('**/api/**', async (route) => {
		const req = route.request(),
			path = new URL(req.url()).pathname,
			method = req.method(),
			body = method === 'GET' ? {} : req.postDataJSON();
		calls.push({ path, method, body });
		const json = (value, status = 200) =>
			route.fulfill({
				status,
				contentType: 'application/json',
				body: JSON.stringify(value),
			});
		if (path.includes('/objects/products/'))
			return json({ id: 2, name: 'Brand milk' });
		if (path === '/api/grocy-ai/capture/trips/12')
			return json({
				trip,
				lines: quantity ? [line(quantity, known)] : [],
			});
		if (path.endsWith('/shopping')) return json(state);
		if (path.endsWith('/shopping/selection')) {
			state.selected_list_id = body.list_id;
			return json(state);
		}
		if (path.endsWith('/trips/12/scan')) {
			quantity++;
			return json(line(quantity));
		}
		if (path.endsWith('/shopping/scan')) {
			let op = state.operations.find(
				(x) => x.operation_id === body.operation_id,
			);
			if (!op) {
				quantity++;
				state.items[0].collected_amount++;
				state.items[0].remaining_amount = Math.max(0, 3 - quantity);
				state.items[0].done = quantity >= 3 && !unknown;
				op = {
					operation_id: body.operation_id,
					state:
						scanState ||
						(unknown
							? 'awaiting_curation'
							: needsAmount
								? 'pending_amount'
								: 'settled'),
					line_id: 100,
					row_id: body.row_id || 1,
					product_id: known ? 2 : null,
					needs_amount: needsAmount,
					error_code: errorCode,
					product_name: known ? 'Brand milk' : null,
					compatible_row_ids:
						errorCode === 'product_mismatch' ||
						errorCode === 'not_on_list'
							? [2]
							: [1, 2],
					line: line(quantity, known),
				};
				state.operations.push(op);
			}
			if (drop) {
				drop = false;
				return route.abort();
			}
			return json(op);
		}
		if (path.endsWith('/options'))
			return json({
				revision: 1,
				products: [
					{
						id: 2,
						name: 'Brand milk',
						parent_product_id: 1,
						qu_id_purchase: 1,
						qu_id_stock: 1,
					},
				],
				locations: [{ id: 1, name: 'Pantry' }],
				quantity_units: [{ id: 1, name: 'can' }],
				product_groups: [],
				taxonomy_leaves: [],
			});
		const op = state.operations.find((x) =>
			path.includes('/' + x.operation_id),
		);
		if (op && path.endsWith('/curate')) {
			known = true;
			op.product_id = 2;
			op.line = line(quantity);
			if (lostApproval) {
				lostApproval = false;
				return route.abort();
			}
			return json({
				catalog: {
					product_id: 2,
					outcome: body.mode === 'create' ? 'approved' : 'linked',
					revision: 2,
				},
				operation: op,
			});
		}
		if (op && path.endsWith('/settle')) {
			op.state = 'settled';
			op.line = line(quantity, known);
			return json(op);
		}
		if (op && path.endsWith('/keep-capture')) {
			op.state = 'captured';
			op.error_code = null;
			op.row_id = null;
			return json(op);
		}
		if (op && path.endsWith('/undo')) {
			quantity--;
			op.state = 'undone';
			op.line = quantity ? line(quantity, known) : null;
			state.items[0].done = false;
			state.items[0].collected_amount = quantity;
			return json(op);
		}
		if (path.endsWith('/shopping/done')) {
			const r = state.items.find((x) => x.id === body.row_id);
			r.done = body.done;
			const op = {
				operation_id: body.operation_id,
				state: 'settled',
				line_id: null,
				kind: 'manual',
				manual_done: body.done,
				row_name: r.name,
				row_id: r.id,
				product_id: null,
				needs_amount: false,
				error_code: null,
				line: null,
			};
			state.operations.push(op);
			return json(op);
		}
		if (op && method === 'GET') return json(op);
		return json({ error_message: 'unexpected request ' + path }, 404);
	});
	page.on('dialog', (d) => d.accept());
	await page.goto('/fixtures/capture-shopping-list.html?saved=12');
	return { state, calls };
}
async function scan(page) {
	await page.locator('#grocyai-capture-barcode').fill('4006381333931');
	await page.locator('#grocyai-capture-add-button').click();
}
test('@shopping list is visible and three scans complete its quantity', async ({
	page,
}) => {
	const { calls } = await setup(page);
	await expect(page.getByLabel('Shopping list', { exact: true })).toHaveValue(
		'1',
	);
	await page
		.getByRole('button', { name: 'Scan for Milk', exact: true })
		.click();
	for (let i = 1; i <= 3; i++) {
		await scan(page);
		await expect(page.locator('[data-shopping-row="1"]')).toContainText(
			i + ' of 3',
		);
	}
	await expect(page.locator('[data-shopping-row="1"]')).toContainText(
		'Collected',
	);
	expect(calls.filter((x) => x.path.includes('/shopping/scan'))).toHaveLength(
		3,
	);
	expect(calls.some((x) => /commit|\/stock/.test(x.path))).toBe(false);
});
test('@shopping unknown UPC is explicitly curated before collection', async ({
	page,
}) => {
	const { calls } = await setup(page, { unknown: true });
	await page
		.getByRole('button', { name: 'Scan for Milk', exact: true })
		.click();
	await scan(page);
	await page.getByRole('button', { name: 'Curate UPC', exact: true }).click();
	await expect(page.getByLabel('Existing product')).toHaveValue('2');
	await page
		.getByRole('button', {
			name: 'Confirm permanent UPC link',
			exact: true,
		})
		.click();
	await expect(
		page.getByRole('button', { name: 'Curate UPC', exact: true }),
	).toHaveCount(0);
	expect(calls.filter((x) => x.path.endsWith('/curate'))).toHaveLength(1);
});
test('@shopping lost response rechecks operation without another scan', async ({
	page,
}) => {
	const { calls } = await setup(page, { lost: true });
	await expect(page.getByLabel('Shopping list', { exact: true })).toHaveValue(
		'1',
	);
	await scan(page);
	await page
		.getByRole('button', { name: 'Check saved operation', exact: true })
		.click();
	await expect(page.locator('#grocyai-shopping-status')).toContainText(
		'verified',
	);
	expect(calls.filter((x) => x.path.endsWith('/shopping/scan'))).toHaveLength(
		1,
	);
});
test('@shopping manual free text checkoff and Undo stay inside capture', async ({
	page,
}) => {
	await setup(page);
	await page
		.getByRole('button', {
			name: 'Mark collected: Paper towels',
			exact: true,
		})
		.click();
	await expect(page.locator('[data-shopping-row="2"]')).toContainText(
		'Collected',
	);
	await expect(
		page.getByRole('button', { name: 'Undo collection', exact: true }),
	).toBeVisible();
});
test('@shopping branded child approval requires explicit reviewed fields', async ({
	page,
}) => {
	const { calls } = await setup(page, { unknown: true });
	await page
		.getByRole('button', { name: 'Scan for Milk', exact: true })
		.click();
	await scan(page);
	await page.getByRole('button', { name: 'Curate UPC', exact: true }).click();
	await page.getByLabel('Curation action').selectOption('create');
	await page.getByLabel('Inventory location').selectOption('1');
	await page
		.getByLabel('Product name', { exact: true })
		.fill('New branded milk');
	await page
		.getByRole('button', {
			name: 'Approve branded product and UPC',
			exact: true,
		})
		.click();
	const call = calls.find((x) => x.path.endsWith('/curate'));
	expect(call.body.fields.parent_product_id).toBe(1);
	expect(call.body.fields.parent_mode).toBe('existing');
});
test('@shopping 320px layout and denied catalog permission remain usable', async ({
	page,
}) => {
	await page.setViewportSize({ width: 320, height: 800 });
	await setup(page, { unknown: true, deny: true });
	await page
		.getByRole('button', { name: 'Scan for Milk', exact: true })
		.click();
	await scan(page);
	await expect(
		page.getByRole('button', { name: 'Curate UPC', exact: true }),
	).toHaveCount(0);
	expect(
		await page.evaluate(
			() => document.documentElement.scrollWidth <= innerWidth,
		),
	).toBe(true);
});

test('@shopping no selected list retains normal scan endpoint', async ({
	page,
}) => {
	const { calls } = await setup(page, { selected: null });
	await expect(page.getByLabel('Shopping list', { exact: true })).toHaveValue(
		'',
	);
	await scan(page);
	await expect(page.locator('#grocyai-capture-lines')).toContainText(
		'Brand milk',
	);
	expect(calls.filter((x) => x.path.endsWith('/trips/12/scan'))).toHaveLength(
		1,
	);
	expect(calls.filter((x) => x.path.endsWith('/shopping/scan'))).toHaveLength(
		0,
	);
});
test('@shopping choosing a list is remembered without creating a trip', async ({
	page,
}) => {
	const { calls } = await setup(page, { selected: null });
	await page.getByLabel('Shopping list', { exact: true }).selectOption('1');
	await expect(
		page.getByRole('button', { name: 'Scan for Milk', exact: true }),
	).toBeVisible();
	await page.reload();
	await expect(page.getByLabel('Shopping list', { exact: true })).toHaveValue(
		'1',
	);
	expect(calls.filter((x) => x.method === 'POST')).toHaveLength(1);
});
test('@shopping unknown quantity asks for explicit list-unit amount', async ({
	page,
}) => {
	const { calls } = await setup(page, { needsAmount: true });
	await page
		.getByRole('button', { name: 'Scan for Milk', exact: true })
		.click();
	await scan(page);
	await page.getByLabel('Collected amount in can').fill('0.5');
	await page
		.getByRole('button', { name: 'Apply to list', exact: true })
		.click();
	await expect
		.poll(() => calls.filter((x) => x.path.endsWith('/settle')).length)
		.toBe(1);
	expect(calls.find((x) => x.path.endsWith('/settle')).body.amount).toBe(0.5);
});
test('@shopping failed approval retains edited product fields', async ({
	page,
}) => {
	await setup(page, { unknown: true });
	let failed = false;
	await page.route('**/shopping/operations/*/curate', (route) => {
		if (!failed) {
			failed = true;
			return route.fulfill({
				status: 409,
				contentType: 'application/json',
				body: JSON.stringify({
					error_message: 'Please recheck the units',
				}),
			});
		}
		return route.fallback();
	});
	await page
		.getByRole('button', { name: 'Scan for Milk', exact: true })
		.click();
	await scan(page);
	await page.getByRole('button', { name: 'Curate UPC', exact: true }).click();
	await page.getByLabel('Curation action').selectOption('create');
	await page.getByLabel('Inventory location').selectOption('1');
	await page
		.getByLabel('Product name', { exact: true })
		.fill('Keep my edited name');
	await page
		.getByRole('button', {
			name: 'Approve branded product and UPC',
			exact: true,
		})
		.click();
	await expect(page.getByRole('alert')).toContainText('recheck the units');
	await expect(page.getByLabel('Product name', { exact: true })).toHaveValue(
		'Keep my edited name',
	);
});
test('@shopping lost scan survives reload and blocks Back until checked', async ({
	page,
}) => {
	const { calls } = await setup(page, { lost: true });
	await expect(page.getByLabel('Shopping list', { exact: true })).toHaveValue(
		'1',
	);
	await scan(page);
	await expect(
		page.getByRole('button', {
			name: 'Check saved operation',
			exact: true,
		}),
	).toBeVisible();
	await page.locator('#grocyai-capture-dashboard-link').click();
	await expect(page).toHaveURL(/capture-shopping-list/);
	await page.reload();
	await page
		.getByRole('button', { name: 'Check saved operation', exact: true })
		.click();
	await expect(page.locator('#grocyai-shopping-status')).toContainText(
		'verified',
	);
	expect(calls.filter((x) => x.path.endsWith('/shopping/scan'))).toHaveLength(
		1,
	);
});
test('@shopping lost approval is recovered without another product write', async ({
	page,
}) => {
	const { calls } = await setup(page, { unknown: true, lostApproval: true });
	await page
		.getByRole('button', { name: 'Scan for Milk', exact: true })
		.click();
	await scan(page);
	await page.getByRole('button', { name: 'Curate UPC', exact: true }).click();
	await page
		.getByRole('button', {
			name: 'Confirm permanent UPC link',
			exact: true,
		})
		.click();
	await page
		.getByRole('button', { name: 'Check saved operation', exact: true })
		.click();
	await page
		.getByRole('button', { name: 'Apply to list', exact: true })
		.click();
	await expect
		.poll(() => calls.filter((x) => x.path.endsWith('/settle')).length)
		.toBe(1);
	expect(calls.filter((x) => x.path.endsWith('/curate'))).toHaveLength(1);
});
test('@shopping Undo refreshes scanner quantity and shared status', async ({
	page,
}) => {
	const { calls } = await setup(page);
	await expect(page.getByLabel('Shopping list', { exact: true })).toHaveValue(
		'1',
	);
	await scan(page);
	await expect(
		page.getByRole('button', { name: 'Undo collection', exact: true }),
	).toBeVisible();
	await page
		.getByRole('button', { name: 'Undo collection', exact: true })
		.click();
	await expect(page.locator('#grocyai-capture-lines')).toContainText(
		'No items yet',
	);
	expect(calls.filter((x) => x.path.endsWith('/undo'))).toHaveLength(1);
});

test('@shopping late refresh cannot replace a newer list selection', async ({
	page,
}) => {
	const { state } = await setup(page);
	await expect(page.getByLabel('Shopping list', { exact: true })).toHaveValue(
		'1',
	);
	const old = JSON.parse(JSON.stringify(state));
	let release,
		held = false;
	const gate = new Promise((r) => (release = r));
	await page.route('**/shopping', async (route) => {
		if (!held) {
			held = true;
			await gate;
			return route.fulfill({
				contentType: 'application/json',
				body: JSON.stringify(old),
			});
		}
		return route.fallback();
	});
	await page
		.getByRole('button', { name: 'Refresh shopping list', exact: true })
		.click();
	await expect.poll(() => held).toBe(true);
	await page.getByLabel('Shopping list', { exact: true }).selectOption('2');
	await expect(page.getByLabel('Shopping list', { exact: true })).toHaveValue(
		'2',
	);
	release();
	await expect.poll(() => state.selected_list_id).toBe(2);
	await expect(page.getByLabel('Shopping list', { exact: true })).toHaveValue(
		'2',
	);
});
test('@shopping unsaved curation can cancel navigation without a catalog write', async ({
	page,
}) => {
	const { calls } = await setup(page, { unknown: true });
	await page
		.getByRole('button', { name: 'Scan for Milk', exact: true })
		.click();
	await scan(page);
	await page.getByRole('button', { name: 'Curate UPC', exact: true }).click();
	page.removeAllListeners('dialog');
	page.on('dialog', (d) => d.dismiss());
	await page.locator('#grocyai-capture-header-review-link').click();
	await expect(page).toHaveURL(/capture-shopping-list/);
	await expect(page.getByLabel('Curation action')).toBeVisible();
	expect(calls.filter((x) => x.path.endsWith('/curate'))).toHaveLength(0);
});
test('@shopping recovery cannot replay an unrelated stored endpoint', async ({
	page,
}) => {
	await page.addInitScript(() =>
		sessionStorage.setItem(
			'grocy-shopping-pending-12',
			JSON.stringify({
				kind: 'scan',
				operation_id: 'malicious',
				url: '/api/stock/products/2/add',
				body: { operation_id: 'malicious', amount: 99 },
			}),
		),
	);
	const { calls } = await setup(page);
	await expect(page.getByLabel('Shopping list', { exact: true })).toHaveValue(
		'1',
	);
	await expect(
		page.getByRole('button', { name: 'Retry same operation', exact: true }),
	).toHaveCount(0);
	expect(calls.some((x) => x.method === 'POST')).toBe(false);
});
test('@shopping populated light and night layouts have touch targets', async ({
	page,
}, testInfo) => {
	await setup(page);
	await expect(
		page.getByRole('button', { name: 'Scan for Milk', exact: true }),
	).toBeVisible();
	const target = await page
		.getByRole('button', { name: 'Scan for Milk', exact: true })
		.boundingBox();
	expect(target.height).toBeGreaterThanOrEqual(44);
	if (testInfo.project.name === 'chromium-mobile') {
		await page.screenshot({
			path: '/tmp/grocy-shopping-light.png',
			fullPage: true,
		});
		await page.evaluate(() => document.body.classList.add('night-mode'));
		await page.screenshot({
			path: '/tmp/grocy-shopping-dark.png',
			fullPage: true,
		});
	}
});

test('@shopping uncommitted approval recovery preserves the confirmation body', async ({
	page,
}) => {
	const { calls } = await setup(page, { unknown: true });
	await page.route('**/shopping/operations/*/curate', (route) =>
		route.abort(),
	);
	await page
		.getByRole('button', { name: 'Scan for Milk', exact: true })
		.click();
	await scan(page);
	await page.getByRole('button', { name: 'Curate UPC', exact: true }).click();
	await page.getByLabel('Curation action').selectOption('create');
	await page.getByLabel('Inventory location').selectOption('1');
	await page
		.getByLabel('Product name', { exact: true })
		.fill('Preserve this new child');
	await page
		.getByRole('button', {
			name: 'Approve branded product and UPC',
			exact: true,
		})
		.click();
	await page
		.getByRole('button', { name: 'Check saved operation', exact: true })
		.click();
	await expect(
		page.getByRole('button', { name: 'Retry same operation', exact: true }),
	).toBeVisible();
	const saved = await page.evaluate(() =>
		JSON.parse(sessionStorage.getItem('grocy-shopping-pending-12')),
	);
	expect(saved.body.fields.name).toBe('Preserve this new child');
	await expect(page.locator('#grocyai-shopping-status')).toContainText(
		'not yet verified',
	);
	await expect(
		page.getByRole('button', { name: 'Retry same operation', exact: true }),
	).toBeEnabled();
});
test('@shopping changed amount target updates units and clears the quantity draft', async ({
	page,
}) => {
	const { state } = await setup(page, { needsAmount: true });
	state.items[1].unit = 'pack';
	state.items[1].qu_id = 2;
	await scan(page);
	await page.getByLabel('Collected amount in can').fill('3');
	await page.getByLabel('Match list item').selectOption('2');
	await expect(page.getByLabel('Collected amount in pack')).toHaveValue('');
	await page.getByLabel('Collected amount in pack').fill('0.5');
	await page.clock.install();
	await page.clock.fastForward(16000);
	await expect(page.getByLabel('Collected amount in pack')).toHaveValue(
		'0.5',
	);
});
test('@shopping periodic refresh preserves an edited amount and navigation guard', async ({
	page,
}) => {
	await page.clock.install();
	await setup(page, { needsAmount: true });
	await scan(page);
	await page.getByLabel('Collected amount in can').fill('0.5');
	await page.getByLabel('Collected amount in can').blur();
	await page.clock.fastForward(16000);
	await expect(page.getByLabel('Collected amount in can')).toHaveValue('0.5');
	page.removeAllListeners('dialog');
	page.on('dialog', (d) => d.dismiss());
	await page.locator('#grocyai-capture-dashboard-link').click();
	await expect(page).toHaveURL(/capture-shopping-list/);
});
test('@shopping uncommitted settlement is not reported as saved', async ({
	page,
}) => {
	await setup(page, { needsAmount: true });
	let lost = true;
	await page.route('**/shopping/operations/*/settle', async (route) => {
		if (lost) {
			lost = false;
			return route.abort();
		}
		return route.fallback();
	});
	await scan(page);
	await page.getByLabel('Collected amount in can').fill('1');
	await page
		.getByRole('button', { name: 'Apply to list', exact: true })
		.click();
	await page
		.getByRole('button', { name: 'Check saved operation', exact: true })
		.click();
	await expect(page.locator('#grocyai-shopping-status')).toContainText(
		'not yet verified',
	);
	await page
		.getByRole('button', { name: 'Retry same operation', exact: true })
		.click();
	await expect(page.locator('#grocyai-shopping-status')).toContainText(
		'verified',
	);
	await expect(
		page.getByRole('button', {
			name: 'Check saved operation',
			exact: true,
		}),
	).toHaveCount(0);
});

test('@shopping safe camera rejection retains queued UPC and curation draft', async ({
	page,
}) => {
	const { calls } = await setup(page, { unknown: true });
	await page
		.getByRole('button', { name: 'Scan for Milk', exact: true })
		.click();
	await scan(page);
	await page.getByRole('button', { name: 'Curate UPC', exact: true }).click();
	await page.getByLabel('Curation action').selectOption('create');
	await page.getByLabel('Inventory location').selectOption('1');
	await page.getByLabel('Product name', { exact: true }).fill('Keep draft');
	await page.evaluate(() =>
		window.Grocy.BarcodeScanned('036000291452', 'grocyai-capture-barcode'),
	);
	await page.locator('#grocyai-capture-camera-save-button').click();
	await expect(page.locator('#grocyai-capture-status')).toContainText(
		'Finish or verify',
	);
	await expect(page.locator('#grocyai-capture-camera-barcode')).toHaveValue(
		'036000291452',
	);
	await expect(page.getByLabel('Product name', { exact: true })).toHaveValue(
		'Keep draft',
	);
	await page
		.getByRole('button', { name: 'Cancel curation', exact: true })
		.click();
	await expect(page.locator('#grocyai-capture-add-button')).toBeEnabled();
	expect(calls.filter((x) => x.path.endsWith('/shopping/scan'))).toHaveLength(
		1,
	);
});
test('@shopping definitive rejected camera scan can be corrected without reload', async ({
	page,
}) => {
	await setup(page);
	await page.route('**/shopping/scan', (route) =>
		route.fulfill({
			status: 404,
			contentType: 'application/json',
			body: JSON.stringify({ error_message: 'Shopping entry not found' }),
		}),
	);
	await page.evaluate(() =>
		window.Grocy.BarcodeScanned('036000291452', 'grocyai-capture-barcode'),
	);
	await page.locator('#grocyai-capture-camera-save-button').click();
	await expect(page.locator('#grocyai-capture-status')).toContainText(
		'Shopping entry not found',
	);
	await expect(
		page.locator('#grocyai-capture-camera-save-button'),
	).toBeEnabled();
	await expect(page.locator('#grocyai-capture-camera-barcode')).toHaveValue(
		'036000291452',
	);
});
test('@shopping multiple brands require explicit selection before permanent approval', async ({
	page,
}) => {
	const { calls } = await setup(page, { unknown: true });
	await page.route('**/options', (route) =>
		route.fulfill({
			contentType: 'application/json',
			body: JSON.stringify({
				revision: 1,
				products: [
					{ id: 2, name: 'Alpha milk', parent_product_id: 1 },
					{ id: 3, name: 'Zeta milk', parent_product_id: 1 },
				],
				locations: [{ id: 1, name: 'Pantry' }],
				quantity_units: [{ id: 1, name: 'can' }],
			}),
		}),
	);
	await page
		.getByRole('button', { name: 'Scan for Milk', exact: true })
		.click();
	await scan(page);
	await page.getByRole('button', { name: 'Curate UPC', exact: true }).click();
	await expect(page.getByLabel('Existing product')).toHaveValue('');
	await page
		.getByRole('button', {
			name: 'Confirm permanent UPC link',
			exact: true,
		})
		.click();
	await expect(page.getByRole('alert')).toContainText(
		'Choose an existing product',
	);
	expect(calls.filter((x) => x.path.endsWith('/curate'))).toHaveLength(0);
	await page.getByLabel('Existing product').selectOption('3');
	await expect(page.locator('.grocy-ai-shopping-curation')).toContainText(
		'Zeta milk',
	);
	await page.getByLabel('Curation action').selectOption('create');
	await expect(page.getByLabel('Inventory location')).toHaveValue('');
});

test('@shopping mismatch explains actual product and offers normal capture or scan Undo', async ({
	page,
}) => {
	const { calls } = await setup(page, {
		scanState: 'conflicted',
		errorCode: 'product_mismatch',
	});
	await scan(page);
	const op = page.locator('.grocy-ai-shopping-operation');
	await expect(op).toContainText('Brand milk');
	await expect(op).toContainText('different product');
	await expect(
		op.getByLabel('Match list item').locator('option'),
	).toHaveCount(2);
	await expect(
		op.getByRole('button', { name: 'Undo scan', exact: true }),
	).toBeVisible();
	await op
		.getByRole('button', { name: 'Keep as normal capture', exact: true })
		.click();
	await expect(op).toContainText('Normal capture');
	expect(calls.filter((x) => x.path.endsWith('/shopping/scan'))).toHaveLength(
		1,
	);
	await op.getByRole('button', { name: 'Undo scan', exact: true }).click();
	await expect(op).toHaveCount(0);
});
test('@shopping conflict settlement does not announce success', async ({
	page,
}) => {
	const { state } = await setup(page, { needsAmount: true });
	await scan(page);
	await page.route('**/settle', (route) => {
		state.operations[0].state = 'conflicted';
		state.operations[0].error_code = 'stale_row';
		return route.fulfill({
			contentType: 'application/json',
			body: JSON.stringify(state.operations[0]),
		});
	});
	await page.getByLabel('Collected amount in can').fill('1');
	await page
		.getByRole('button', { name: 'Apply to list', exact: true })
		.click();
	await expect(page.locator('#grocyai-shopping-status')).toContainText(
		'changed',
	);
	await expect(page.locator('#grocyai-shopping-status')).not.toHaveText(
		'Saved.',
	);
});
test('@shopping progress displays rounded this-trip quantities and shared completion', async ({
	page,
}) => {
	const { state } = await setup(page);
	state.items[0].collected_amount = 0.30000000000000004;
	state.items[0].done = true;
	await page
		.getByRole('button', { name: 'Refresh shopping list', exact: true })
		.click();
	const row = page.locator('[data-shopping-row="1"]');
	await expect(row).toContainText('This trip: 0.3 of 3');
	await expect(row).toContainText('Collected on shared list');
});

test('@shopping ambiguous camera scan remains locked until saved outcome is reviewed', async ({
	page,
}) => {
	await setup(page, { lost: true });
	await page.evaluate(() =>
		window.Grocy.BarcodeScanned('4006381333931', 'grocyai-capture-barcode'),
	);
	await page.locator('#grocyai-capture-camera-save-button').click();
	await expect(page.locator('#grocyai-capture-status')).toContainText(
		'could not be verified',
	);
	await expect(page.locator('#grocyai-capture-add-button')).toBeDisabled();
	await expect(
		page.getByRole('button', {
			name: 'Check saved operation',
			exact: true,
		}),
	).toBeVisible();
});
test('@shopping lost normal-capture detach recovers after reload without another scan', async ({
	page,
}) => {
	const { state, calls } = await setup(page, {
		scanState: 'pending_selection',
		errorCode: 'not_on_list',
	});
	await scan(page);
	let lose = true;
	await page.route('**/keep-capture', (route) => {
		if (lose) {
			lose = false;
			state.operations[0].state = 'captured';
			state.operations[0].error_code = null;
			state.operations[0].row_id = null;
			return route.abort();
		}
		return route.fallback();
	});
	await page
		.getByRole('button', { name: 'Keep as normal capture', exact: true })
		.click();
	await page.reload();
	await page
		.getByRole('button', { name: 'Check saved operation', exact: true })
		.click();
	await expect(page.locator('#grocyai-shopping-status')).toContainText(
		'verified',
	);
	await expect(page.locator('.grocy-ai-shopping-operation')).toContainText(
		'Normal capture',
	);
	expect(calls.filter((x) => x.path.endsWith('/shopping/scan'))).toHaveLength(
		1,
	);
});

test('@shopping targeted unknown scan focuses explicit curation control', async ({
	page,
}) => {
	await setup(page, { unknown: true });
	await page
		.getByRole('button', { name: 'Scan for Milk', exact: true })
		.click();
	await scan(page);
	await expect(
		page.getByRole('button', { name: 'Curate UPC', exact: true }),
	).toBeFocused();
});

for (const [label, options] of [
	['unknown', { unknown: true }],
	['off list', { scanState: 'pending_selection', errorCode: 'not_on_list' }],
]) {
	test(
		'@shopping ' +
			label +
			' leaves wedge focus on scanner and Enter scans again',
		async ({ page }) => {
			const { calls } = await setup(page, options);
			await scan(page);
			await expect(
				page.locator('#grocyai-capture-barcode'),
			).toBeFocused();
			await page.keyboard.type('036000291452');
			await page.keyboard.press('Enter');
			await expect
				.poll(
					() =>
						calls.filter((x) => x.path.endsWith('/shopping/scan'))
							.length,
				)
				.toBe(2);
			expect(calls.filter((x) => x.path.endsWith('/undo'))).toHaveLength(
				0,
			);
		},
	);
}

test('@shopping manual actions name the row without claiming a scan', async ({
	page,
}) => {
	await setup(page);
	await page
		.getByRole('button', {
			name: 'Mark collected: Paper towels',
			exact: true,
		})
		.click();
	await expect(page.locator('#grocyai-shopping-status')).toContainText(
		'Checked off Paper towels',
	);
	await expect(
		page.locator('.grocy-ai-shopping-operation'),
	).not.toContainText('Unknown product');
});
test('@shopping no compatible entries hides unusable match controls', async ({
	page,
}) => {
	const { state } = await setup(page, {
		scanState: 'pending_selection',
		errorCode: 'not_on_list',
	});
	await scan(page);
	state.operations[0].compatible_row_ids = [];
	await page
		.getByRole('button', { name: 'Refresh shopping list', exact: true })
		.click();
	await expect(page.getByLabel('Match list item')).toHaveCount(0);
	await expect(
		page.getByRole('button', {
			name: 'Keep as normal capture',
			exact: true,
		}),
	).toBeVisible();
});
test('@shopping catalog success survives row removal before collection', async ({
	page,
}) => {
	const { state } = await setup(page, { unknown: true });
	await page
		.getByRole('button', { name: 'Scan for Milk', exact: true })
		.click();
	await scan(page);
	await page.getByRole('button', { name: 'Curate UPC', exact: true }).click();
	await page.route('**/curate', (route) => {
		state.items = [];
		state.operations[0].product_id = 2;
		return route.fulfill({
			contentType: 'application/json',
			body: JSON.stringify({
				catalog: { product_id: 2 },
				operation: state.operations[0],
			}),
		});
	});
	await page
		.getByRole('button', {
			name: 'Confirm permanent UPC link',
			exact: true,
		})
		.click();
	await expect(page.locator('#grocyai-shopping-status')).toContainText(
		'Permanent product link saved',
	);
	await expect(page.locator('#grocyai-shopping-status')).toContainText(
		'Choose an available entry',
	);
});
