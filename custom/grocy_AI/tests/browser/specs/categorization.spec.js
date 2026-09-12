const { test, expect } = require('@playwright/test');

// Phase 6 / 06-07 acceptance for the bulk-review categorization surface: the three passes on the shared
// bulk-review page (bulk-review.js) — product-group suggestion and conflict-first classification (both
// reviewed/applied/rolled back through the existing controls) and the read-only conversion audit report.
// The bulk API is mocked per test with page.route so support/server.mjs stays stateless and deterministic;
// the fixture loads the real production bulk-review.js from /assets, so these tests exercise the shipped
// client, not a stand-in.

const CHECKSUM = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
const ROLLBACK_CHECKSUM = 'b1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

const GROUP_PLAN_ID = 1001;
const CLASSIFICATION_PLAN_ID = 2002;

function json(route, body, status)
{
	return route.fulfill({
		status: status || 200,
		contentType: 'application/json; charset=utf-8',
		headers: { 'Cache-Control': 'no-store' },
		body: JSON.stringify(body)
	});
}

function planHeader(overrides)
{
	return Object.assign({
		id: GROUP_PLAN_ID,
		created_at: '2026-09-12 12:00:00',
		created_by: '1',
		ruleset_version: 'v1',
		operation_type: 'product_group_assignment',
		scope_json: '{"selector":"ungrouped_in_scope"}',
		counts_json: '{"included":2}',
		checksum: CHECKSUM,
		status: 'draft',
		module_version: '2.5.0'
	}, overrides || {});
}

function counts(overrides)
{
	return Object.assign({ included: 2, excluded: 0, skipped: 0, conflicted: 0, changed: 2, unchanged: 0 }, overrides || {});
}

// The product-group suggestion pass: items write ONLY the native product_group_id.
function groupItems(applied)
{
	const outcome = applied ? 'applied' : 'pending';
	return [
		{
			seq: 0, object_type: 'product', object_id: 501, operation: 'suggest_product_group',
			before_image: { product_group_id: null }, proposed_value: { product_group_id: 7 },
			reason: 'provider_category_matches_group', provenance: 'provider_category', selected: true, outcome: outcome
		},
		{
			seq: 1, object_type: 'product', object_id: 502, operation: 'suggest_product_group',
			before_image: { product_group_id: null }, proposed_value: { product_group_id: 9 },
			reason: 'name_contains_group', provenance: 'product_name', selected: false, outcome: 'pending'
		}
	];
}

// The conflict-first classification pass: conflicts -> low-confidence -> confident (DATA-02 / Q7).
function classificationItems()
{
	return [
		{
			seq: 0, object_type: 'product', object_id: 601, operation: 'assign_taxonomy_leaf',
			before_image: { leaf_slug: 'produce' }, proposed_value: { leaf_slug: 'meat-seafood' },
			reason: 'review_conflict', provenance: 'grocy_product_group', selected: false, outcome: 'pending'
		},
		{
			seq: 1, object_type: 'product', object_id: 602, operation: 'set_unclassified',
			before_image: { leaf_slug: null }, proposed_value: { leaf_slug: null },
			reason: 'below_confidence_threshold', provenance: 'grocy_ai_taxonomy_evidence', selected: false, outcome: 'pending'
		},
		{
			seq: 2, object_type: 'product', object_id: 603, operation: 'assign_taxonomy_leaf',
			before_image: { leaf_slug: null }, proposed_value: { leaf_slug: 'dairy-eggs' },
			reason: 'mapped_grocy_product_group', provenance: 'grocy_product_group', selected: true, outcome: 'pending'
		}
	];
}

function selectedDiff(planId, operationType, items)
{
	const selected = items.filter(function (row) { return row.selected === true; });
	return {
		plan_id: planId, checksum: CHECKSUM, operation_type: operationType, ruleset_version: 'v1',
		included: selected.length, items: selected
	};
}

// A single stateful mock of the bulk API, serving whichever pass the browser generated. Tracks apply and
// rollback so the review -> apply -> rollback flow is coherent, and records that only /bulk endpoints were hit.
async function installBulkApi(page)
{
	const state = { generated: null, applied: false, rolledBack: false, appliedCalls: 0, rollbackCalls: 0, auditCalls: 0 };

	function currentPlan()
	{
		if (state.generated === 'group')
		{
			const applied = state.applied && !state.rolledBack;
			return {
				plan: planHeader({ id: GROUP_PLAN_ID, operation_type: 'product_group_assignment', status: applied ? 'applied' : 'draft' }),
				counts: counts(),
				items: groupItems(applied)
			};
		}
		return {
			plan: planHeader({ id: CLASSIFICATION_PLAN_ID, operation_type: 'taxonomy_assignment', status: 'draft', counts_json: '{"included":3}' }),
			counts: counts({ included: 3, skipped: 1, changed: 2, unchanged: 1 }),
			items: classificationItems()
		};
	}

	await page.route('**/api/grocy-ai/bulk/**', function (route)
	{
		const request = route.request();
		const method = request.method();
		const pathname = new URL(request.url()).pathname;

		if (method === 'POST' && /\/bulk\/plans$/.test(pathname))
		{
			const body = JSON.parse(request.postData() || '{}');
			state.applied = false;
			state.rolledBack = false;
			if (body.operation_type === 'product_group_assignment')
			{
				state.generated = 'group';
			}
			else if (body.operation_type === 'classification_review')
			{
				state.generated = 'classification';
			}
			else
			{
				state.generated = 'group';
			}
			return json(route, currentPlan(), 201);
		}

		const planId = state.generated === 'classification' ? CLASSIFICATION_PLAN_ID : GROUP_PLAN_ID;
		const operationType = state.generated === 'classification' ? 'taxonomy_assignment' : 'product_group_assignment';

		if (method === 'GET' && /\/bulk\/plans\/\d+\/selected-diff$/.test(pathname))
		{
			return json(route, selectedDiff(planId, operationType, currentPlan().items));
		}
		if (method === 'GET' && /\/bulk\/plans\/\d+\/rollback-preview$/.test(pathname))
		{
			const reversible = currentPlan().items
				.filter(function (row) { return row.selected === true; })
				.map(function (row)
				{
					return {
						plan_item_id: row.seq + 1, object_type: 'product', object_id: row.object_id,
						before_image: null, after_image: 'x', current_value: 'x',
						inverse_operation: state.generated === 'group' ? 'suggest_product_group' : 'set_unclassified',
						reversible: true, blocker: null
					};
				});
			return json(route, {
				plan_id: planId, plan_checksum: CHECKSUM, checksum: ROLLBACK_CHECKSUM,
				items: reversible, reversible: reversible, refused: []
			});
		}
		if (method === 'POST' && /\/bulk\/plans\/\d+\/apply$/.test(pathname))
		{
			state.appliedCalls++;
			state.applied = true;
			return json(route, {
				plan_id: planId, checksum: CHECKSUM, status: 'applied', blockers: [],
				outcomes: { applied: 1, conflict: 0, skipped: 0 }, actor: 'test-user'
			});
		}
		if (method === 'POST' && /\/bulk\/plans\/\d+\/rollback$/.test(pathname))
		{
			state.rollbackCalls++;
			state.rolledBack = true;
			return json(route, {
				plan_id: planId, checksum: ROLLBACK_CHECKSUM, status: 'rolled_back', blockers: [],
				outcomes: { rolled_back: 1, conflict: 0, skipped: 0 }, actor: 'test-user'
			});
		}
		if (method === 'GET' && /\/bulk\/plans\/\d+$/.test(pathname))
		{
			return json(route, currentPlan());
		}
		if (method === 'GET' && /\/bulk\/conversion-audit$/.test(pathname))
		{
			state.auditCalls++;
			return json(route, {
				global_count: 62, product_specific_count: 18, expected_count: 18,
				expected_product_count: 9, suspicious: [], ok: true
			});
		}

		return json(route, { message: 'unexpected bulk call ' + method + ' ' + pathname }, 404);
	});

	return state;
}

test.describe('bulk review — Phase 6 categorization passes', function ()
{
	test('@cat @mob @smoke the product-group suggestion pass generates, reviews, applies, and rolls back', async function ({ page })
	{
		const state = await installBulkApi(page);
		await page.goto('/fixtures/bulk-review.html');

		await page.locator('#grocy-ai-bulk-generate-group-button').click();

		// The group plan renders as suggest_product_group proposals writing the native product_group_id.
		const items = page.locator('#grocy-ai-bulk-items .grocy-ai-bulk-item');
		await expect(items).toHaveCount(2);
		const firstMeta = items.nth(0).locator('.grocy-ai-provenance');
		await expect(firstMeta).toContainText('Operation: suggest_product_group');
		await expect(items.nth(0)).toContainText('7');

		// The selected diff reflects only the pre-selected confident suggestion.
		await expect(page.locator('#grocy-ai-bulk-selected-diff')).toContainText('Included in apply set: 1');

		// Apply (durable mutation) requires the explicit confirm the client enforces.
		page.once('dialog', function (dialog) { return dialog.accept(); });
		await page.locator('#grocy-ai-bulk-apply-button').click();
		await expect(page.locator('#grocy-ai-bulk-apply-result')).toContainText('applied');
		expect(state.appliedCalls, 'apply must POST exactly once').toBe(1);

		// Rollback: preview first (binds the reviewed checksum), then execute.
		await page.locator('#grocy-ai-bulk-rollback-preview-button').click();
		await expect(page.locator('#grocy-ai-bulk-rollback-preview')).toContainText('Reversible (1)');
		page.once('dialog', function (dialog) { return dialog.accept(); });
		await page.locator('#grocy-ai-bulk-rollback-button').click();
		await expect(page.locator('#grocy-ai-bulk-rollback-result')).toContainText('rolled_back');
		expect(state.rollbackCalls, 'rollback must POST exactly once').toBe(1);
	});

	test('@cat @mob @smoke the classification pass renders conflicts before low-confidence before confident, retaining Unclassified', async function ({ page })
	{
		await installBulkApi(page);
		await page.goto('/fixtures/bulk-review.html');

		await page.locator('#grocy-ai-bulk-generate-classification-button').click();

		const items = page.locator('#grocy-ai-bulk-items .grocy-ai-bulk-item');
		await expect(items).toHaveCount(3);

		// Confidence bands are visible and ordered conflicts -> low-confidence -> confident.
		const bands = page.locator('#grocy-ai-bulk-items [data-grocy-ai-bulk-band]');
		await expect(bands).toHaveCount(3);
		await expect(bands.nth(0)).toHaveAttribute('data-grocy-ai-bulk-band', 'conflict');
		await expect(bands.nth(1)).toHaveAttribute('data-grocy-ai-bulk-band', 'low-confidence');
		await expect(bands.nth(2)).toHaveAttribute('data-grocy-ai-bulk-band', 'confident');
		await expect(bands.nth(0)).toHaveText('Conflict');
		await expect(bands.nth(1)).toHaveText('Low confidence');

		// The conflict item is deselected pending human review; the low-confidence item RETAINS Unclassified
		// (proposes None) and is deselected, never forced to a leaf; the confident change is pre-selected.
		await expect(items.nth(0).locator('input[type=checkbox]')).not.toBeChecked();
		const lowItem = items.nth(1);
		await expect(lowItem.locator('.grocy-ai-provenance')).toContainText('Operation: set_unclassified');
		await expect(lowItem).toContainText('None');
		await expect(lowItem.locator('input[type=checkbox]')).not.toBeChecked();
		await expect(items.nth(2).locator('input[type=checkbox]')).toBeChecked();

		// Only the confident, pre-selected change is in the apply set.
		await expect(page.locator('#grocy-ai-bulk-selected-diff')).toContainText('Included in apply set: 1');
	});

	test('@cat @mob the conversion audit renders as a read-only report with no apply control', async function ({ page })
	{
		const state = await installBulkApi(page);
		await page.goto('/fixtures/bulk-review.html');

		await page.locator('#grocy-ai-bulk-conversion-audit-button').click();

		const report = page.locator('#grocy-ai-bulk-conversion-audit');
		await expect(report.locator('[data-grocy-ai-conversion-audit-ok]')).toHaveAttribute('data-grocy-ai-conversion-audit-ok', 'true');
		await expect(report).toContainText('62');
		await expect(report).toContainText('18');
		await expect(report).toContainText('9');
		expect(state.auditCalls, 'the audit is a single read').toBe(1);

		// The report is not a plan: it drives no plan render, so no apply/rollback happens against it.
		await expect(page.locator('#grocy-ai-bulk-apply-result')).toBeEmpty();
		expect(state.appliedCalls).toBe(0);
	});
});
