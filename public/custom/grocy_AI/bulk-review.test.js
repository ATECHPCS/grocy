'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const bulkReview = require('./bulk-review.js');

function deferred()
{
	let resolve;
	let reject;
	const promise = new Promise(function (resolvePromise, rejectPromise)
	{
		resolve = resolvePromise;
		reject = rejectPromise;
	});
	return { promise, resolve, reject };
}

function planHeader(overrides = {})
{
	return Object.assign({
		id: 5,
		created_at: '2026-08-29 12:00:00',
		created_by: null,
		ruleset_version: 'v1',
		operation_type: 'taxonomy_assignment',
		scope_json: '{"selector":"full_inventory"}',
		counts_json: '{"included":1}',
		checksum: 'a'.repeat(64),
		status: 'draft',
		module_version: '2.5.0'
	}, overrides);
}

function counts(overrides = {})
{
	return Object.assign({
		included: 1, excluded: 0, skipped: 0, conflicted: 0, changed: 1, unchanged: 0
	}, overrides);
}

function item(overrides = {})
{
	return Object.assign({
		seq: 0,
		object_type: 'product',
		object_id: 1,
		operation: 'assign_taxonomy_leaf',
		before_image: { leaf_slug: null },
		proposed_value: { leaf_slug: 'produce' },
		reason: 'canonical_structured_match',
		provenance: 'grocy_ai_taxonomy',
		selected: true,
		outcome: 'pending'
	}, overrides);
}

function planPayload(overrides = {})
{
	return Object.assign({
		plan: planHeader(),
		counts: counts(),
		items: [item()]
	}, overrides);
}

function selectedDiffPayload(overrides = {})
{
	return Object.assign({
		plan_id: 5,
		checksum: 'a'.repeat(64),
		operation_type: 'taxonomy_assignment',
		ruleset_version: 'v1',
		included: 1,
		items: [item()]
	}, overrides);
}

function reversibleRollbackItem(overrides = {})
{
	return Object.assign({
		plan_item_id: 1,
		object_type: 'product',
		object_id: 1,
		before_image: null,
		after_image: 'produce',
		current_value: 'produce',
		inverse_operation: 'set_unclassified',
		reversible: true,
		blocker: null
	}, overrides);
}

function refusedRollbackItem(overrides = {})
{
	return Object.assign({
		plan_item_id: 2,
		object_type: 'product',
		object_id: 2,
		before_image: null,
		after_image: 'produce',
		current_value: 'dairy-eggs',
		inverse_operation: null,
		reversible: false,
		blocker: 'manual_edit_after_apply'
	}, overrides);
}

function rollbackPreviewPayload(reversible, refused)
{
	const reversibleItems = reversible === undefined ? [reversibleRollbackItem()] : reversible;
	const refusedItems = refused === undefined ? [] : refused;
	return {
		plan_id: 5,
		plan_checksum: 'a'.repeat(64),
		checksum: 'b'.repeat(64),
		items: reversibleItems.concat(refusedItems),
		reversible: reversibleItems,
		refused: refusedItems
	};
}

function applyResultPayload(overrides = {})
{
	return Object.assign({
		plan_id: 5,
		checksum: 'a'.repeat(64),
		status: 'applied',
		blockers: [],
		outcomes: { applied: 1, conflict: 0, skipped: 0 },
		actor: 'test-user'
	}, overrides);
}

function rollbackResultPayload(overrides = {})
{
	return Object.assign({
		plan_id: 5,
		checksum: 'b'.repeat(64),
		status: 'rolled_back',
		blockers: [],
		outcomes: { rolled_back: 1, conflict: 0, skipped: 0 },
		actor: 'test-user'
	}, overrides);
}

test('a well-formed plan payload describes exact counts and one row per item', function ()
{
	const presentation = bulkReview.describePlan(planPayload());

	assert.equal(presentation.valid, true);
	assert.equal(presentation.planId, 5);
	assert.equal(presentation.status, 'draft');
	assert.deepEqual(presentation.counts, [
		{ term: 'included', value: '1' },
		{ term: 'excluded', value: '0' },
		{ term: 'skipped', value: '0' },
		{ term: 'conflicted', value: '0' },
		{ term: 'changed', value: '1' },
		{ term: 'unchanged', value: '0' }
	]);
	assert.equal(presentation.items.length, 1);
	assert.deepEqual(presentation.items[0], {
		seq: 0, objectType: 'product', objectId: 1, operation: 'assign_taxonomy_leaf',
		before: null, proposed: 'produce', reason: 'canonical_structured_match',
		provenance: 'grocy_ai_taxonomy', selected: true, outcome: 'pending'
	});
});

test('a plan payload with an extra, missing, or unknown-outcome field fails closed to an invalid, empty presentation', function ()
{
	const extendedPlan = planPayload();
	extendedPlan.injected = 'x';
	const missingCounts = planPayload();
	delete missingCounts.counts;
	const badOutcome = planPayload({ items: [item({ outcome: 'deleted' })] });
	const badCount = planPayload({ counts: counts({ included: -1 }) });
	const duplicateSeq = planPayload({ items: [item({ seq: 0 }), item({ seq: 0 })] });
	const nonBooleanSelected = planPayload({ items: [item({ selected: 'yes' })] });
	const extraItemKey = planPayload({ items: [Object.assign(item(), { extra: 'x' })] });

	[extendedPlan, missingCounts, badOutcome, badCount, duplicateSeq, nonBooleanSelected, extraItemKey, null, 'x', [], 42].forEach(function (payload)
	{
		const presentation = bulkReview.describePlan(payload);
		assert.equal(presentation.valid, false, JSON.stringify(payload));
		assert.deepEqual(presentation.counts, []);
		assert.deepEqual(presentation.items, []);
	});
});

test('a well-formed selected-diff payload lists only its own selected items and the apply-set count', function ()
{
	const presentation = bulkReview.describeSelectedDiff(selectedDiffPayload());

	assert.equal(presentation.valid, true);
	assert.equal(presentation.included, 1);
	assert.equal(presentation.items.length, 1);
	assert.equal(presentation.items[0].selected, true);
});

test('a selected-diff payload carrying a rejected item or a mismatched included count fails closed', function ()
{
	const rejectedItemPresent = selectedDiffPayload({ items: [item({ selected: false })] });
	const mismatchedIncluded = selectedDiffPayload({ included: 2 });
	const badChecksum = selectedDiffPayload({ checksum: 'not-hex' });

	[rejectedItemPresent, mismatchedIncluded, badChecksum, {}].forEach(function (payload)
	{
		const presentation = bulkReview.describeSelectedDiff(payload);
		assert.equal(presentation.valid, false);
		assert.deepEqual(presentation.items, []);
	});
});

test('load() renders the plan and the selected diff from their own independent responses', async function ()
{
	const planResponses = [Promise.resolve(planPayload())];
	const diffResponses = [Promise.resolve(selectedDiffPayload())];
	const rendered = { plan: [], diff: [] };
	const controller = bulkReview.createBulkReviewController({
		requestPlan: function () { return planResponses.shift(); },
		requestSelectedDiff: function () { return diffResponses.shift(); },
		requestSetSelection: function () { throw new Error('not used in this test'); },
		renderPlan: function (presentation) { rendered.plan.push(presentation); },
		renderSelectedDiff: function (presentation) { rendered.diff.push(presentation); },
		onBusy: function () { },
		onError: function () { rendered.plan.push('error'); }
	});

	await controller.load();

	assert.equal(rendered.plan.length, 1);
	assert.equal(rendered.plan[0].valid, true);
	assert.equal(rendered.diff.length, 1);
	assert.equal(rendered.diff[0].valid, true);
});

test('toggling a row calls the selection endpoint with exactly one seq/selected pair and re-renders from its response, never from a locally invented item', async function ()
{
	const calls = [];
	const rendered = { plan: [], diff: [] };
	const controller = bulkReview.createBulkReviewController({
		requestPlan: function () { throw new Error('not used in this test'); },
		requestSelectedDiff: function ()
		{
			return Promise.resolve(selectedDiffPayload({ items: [item({ seq: 1, object_id: 2, selected: true })], included: 1 }));
		},
		requestSetSelection: function (seq, selected)
		{
			calls.push([seq, selected]);
			// The server is the sole source of the re-read item state, including an object_id the
			// caller never supplied — proving the render cannot have been fabricated client-side.
			return Promise.resolve(planPayload({
				items: [item({ seq: 1, object_id: 2, selected: true, outcome: 'pending' })]
			}));
		},
		renderPlan: function (presentation) { rendered.plan.push(presentation); },
		renderSelectedDiff: function (presentation) { rendered.diff.push(presentation); },
		onBusy: function () { },
		onError: function () { rendered.plan.push('error'); }
	});

	await controller.toggle(1, true);

	assert.deepEqual(calls, [[1, true]]);
	assert.equal(rendered.plan.length, 1);
	assert.equal(rendered.plan[0].items[0].objectId, 2);
	assert.equal(rendered.plan[0].items[0].seq, 1);
	assert.equal(rendered.diff.length, 1);
	assert.equal(rendered.diff[0].items[0].objectId, 2);
});

test('an older toggle response can never overwrite a newer one', async function ()
{
	const first = deferred();
	const second = deferred();
	const setSelectionCalls = [first, second];
	const renderedPlan = [];
	let diffRequestCount = 0;
	const controller = bulkReview.createBulkReviewController({
		requestPlan: function () { throw new Error('not used in this test'); },
		requestSelectedDiff: function ()
		{
			diffRequestCount++;
			return Promise.resolve(selectedDiffPayload({ items: [], included: 0 }));
		},
		requestSetSelection: function () { return setSelectionCalls.shift().promise; },
		renderPlan: function (presentation) { renderedPlan.push(presentation.items[0].objectId); },
		renderSelectedDiff: function () { },
		onBusy: function () { },
		onError: function () { renderedPlan.push('error'); }
	});

	const firstToggle = controller.toggle(0, true);
	const secondToggle = controller.toggle(0, false);

	// The second toggle's PUT resolves first (e.g. the first request is slow); its render must win.
	second.resolve(planPayload({ items: [item({ object_id: 99 })] }));
	await secondToggle;
	// The first toggle's PUT resolves later but must never overwrite the newer, already-rendered state.
	first.resolve(planPayload({ items: [item({ object_id: 1 })] }));
	await firstToggle;

	assert.deepEqual(renderedPlan, [99]);
	assert.equal(diffRequestCount, 2);
});

test('a failed selection PUT announces the toggle error and renders no fabricated item state', async function ()
{
	const rendered = [];
	const errors = [];
	const controller = bulkReview.createBulkReviewController({
		requestPlan: function () { throw new Error('not used in this test'); },
		requestSelectedDiff: function () { throw new Error('not used in this test'); },
		requestSetSelection: function () { return Promise.reject(new Error('http_status')); },
		renderPlan: function (presentation) { rendered.push(presentation); },
		renderSelectedDiff: function () { },
		onBusy: function () { },
		onError: function (message) { errors.push(message); }
	});

	await controller.toggle(0, true);

	assert.deepEqual(rendered, []);
	assert.deepEqual(errors, [bulkReview.COPY.toggleError]);
});

test('a successful toggle whose diff refresh fails still shows the updated plan and only announces the diff error', async function ()
{
	const renderedPlan = [];
	const renderedDiff = [];
	const errors = [];
	const controller = bulkReview.createBulkReviewController({
		requestPlan: function () { throw new Error('not used in this test'); },
		requestSelectedDiff: function () { return Promise.reject(new Error('http_status')); },
		requestSetSelection: function () { return Promise.resolve(planPayload()); },
		renderPlan: function (presentation) { renderedPlan.push(presentation); },
		renderSelectedDiff: function (presentation) { renderedDiff.push(presentation); },
		onBusy: function () { },
		onError: function (message) { errors.push(message); }
	});

	await controller.toggle(0, true);

	assert.equal(renderedPlan.length, 1);
	assert.deepEqual(renderedDiff, []);
	assert.deepEqual(errors, [bulkReview.COPY.diffError]);
});

test('the selected-diff view lists only selected items with HTML-shaped text preserved verbatim, never interpreted as markup', function ()
{
	const hostile = '<img src=x onerror=alert(1)>&"\'';
	const presentation = bulkReview.describeSelectedDiff(selectedDiffPayload({
		items: [item({ reason: hostile, provenance: hostile })]
	}));

	assert.equal(presentation.valid, true);
	// describeSelectedDiff must carry the raw string through unchanged: rendering (renderSelectedDiff)
	// only ever assigns it via `textContent`, so no HTML-escaping step is needed or expected here.
	assert.equal(presentation.items[0].reason, hostile);
	assert.equal(presentation.items[0].provenance, hostile);
});

test('the rendering helpers never assign to innerHTML, so no server value can be interpreted as markup', function ()
{
	const fs = require('node:fs');
	const source = fs.readFileSync(require.resolve('./bulk-review.js'), 'utf8');
	// Matches an actual `.innerHTML = ...` assignment, not the explanatory prose in the module's own
	// header comment (which mentions the property name to document that it is deliberately unused).
	assert.doesNotMatch(source, /\.innerHTML\s*=/);
});

// ---------------------------------------------------------------------------------------------------
// Rollback preview (BULK-09, D-11): a zero-write read distinguishing reversible from refused items.
// ---------------------------------------------------------------------------------------------------

test('a well-formed rollback-preview payload separates reversible from refused items verbatim', function ()
{
	const presentation = bulkReview.describeRollbackPreview(rollbackPreviewPayload(
		[reversibleRollbackItem()],
		[refusedRollbackItem()]
	));

	assert.equal(presentation.valid, true);
	assert.equal(presentation.planId, 5);
	assert.equal(presentation.reversible.length, 1);
	assert.equal(presentation.refused.length, 1);
	assert.deepEqual(presentation.reversible[0], {
		planItemId: 1, objectType: 'product', objectId: 1, before: null, after: 'produce',
		current: 'produce', inverseOperation: 'set_unclassified', reversible: true, blocker: null
	});
	assert.deepEqual(presentation.refused[0], {
		planItemId: 2, objectType: 'product', objectId: 2, before: null, after: 'produce',
		current: 'dairy-eggs', inverseOperation: null, reversible: false, blocker: 'manual_edit_after_apply'
	});
});

test('a rollback-preview payload with no reversible or refused items still describes as valid with empty lists', function ()
{
	const presentation = bulkReview.describeRollbackPreview(rollbackPreviewPayload([], []));

	assert.equal(presentation.valid, true);
	assert.deepEqual(presentation.reversible, []);
	assert.deepEqual(presentation.refused, []);
});

test('a rollback-preview payload with a self-contradictory or out-of-contract entry fails closed to an empty presentation', function ()
{
	const extraTopLevelKey = rollbackPreviewPayload();
	extraTopLevelKey.injected = 'x';

	const reversibleWithBlocker = rollbackPreviewPayload([reversibleRollbackItem({ blocker: 'manual_edit_after_apply' })], []);
	const reversibleWithNoInverse = rollbackPreviewPayload([reversibleRollbackItem({ inverse_operation: null })], []);
	const refusedWithInverse = rollbackPreviewPayload([], [refusedRollbackItem({ inverse_operation: 'assign_taxonomy_leaf' })]);
	const refusedWithNoBlocker = rollbackPreviewPayload([], [refusedRollbackItem({ blocker: null })]);
	const mismatchedItemsCount = rollbackPreviewPayload();
	mismatchedItemsCount.items = [];
	const badChecksum = rollbackPreviewPayload();
	badChecksum.checksum = 'not-hex';
	const badPlanChecksum = rollbackPreviewPayload();
	badPlanChecksum.plan_checksum = 'not-hex';
	const extraItemKey = rollbackPreviewPayload([Object.assign(reversibleRollbackItem(), { extra: 'x' })], []);

	[
		extraTopLevelKey, reversibleWithBlocker, reversibleWithNoInverse, refusedWithInverse, refusedWithNoBlocker,
		mismatchedItemsCount, badChecksum, badPlanChecksum, extraItemKey, null, 'x', [], {}
	].forEach(function (payload)
	{
		const presentation = bulkReview.describeRollbackPreview(payload);
		assert.equal(presentation.valid, false, JSON.stringify(payload));
		assert.deepEqual(presentation.reversible, []);
		assert.deepEqual(presentation.refused, []);
	});
});

// ---------------------------------------------------------------------------------------------------
// Apply / rollback-execute outcome DTOs (D-13): closed shape, verbatim blocker/outcome vocabulary.
// ---------------------------------------------------------------------------------------------------

test('a well-formed apply result describes its status, blockers, outcomes, and actor verbatim', function ()
{
	const presentation = bulkReview.describeApplyResult(applyResultPayload());

	assert.equal(presentation.valid, true);
	assert.equal(presentation.status, 'applied');
	assert.deepEqual(presentation.blockers, []);
	assert.deepEqual(presentation.outcomes, [
		{ term: 'applied', value: '1' }, { term: 'conflict', value: '0' }, { term: 'skipped', value: '0' }
	]);
	assert.equal(presentation.actor, 'test-user');
});

test('a bounded 409 blocker on apply/rollback is still a valid, closed shape — never treated as malformed', function ()
{
	const applyBlocked = bulkReview.describeApplyResult(applyResultPayload({ blockers: ['plan_checksum_mismatch'] }));
	const rollbackBlocked = bulkReview.describeRollbackResult(rollbackResultPayload({ blockers: ['plan_checksum_mismatch'] }));

	assert.equal(applyBlocked.valid, true);
	assert.deepEqual(applyBlocked.blockers, ['plan_checksum_mismatch']);
	assert.equal(rollbackBlocked.valid, true);
	assert.deepEqual(rollbackBlocked.blockers, ['plan_checksum_mismatch']);
});

test('an apply result carrying rollback outcome keys (or vice versa), an extra field, or an out-of-range count fails closed', function ()
{
	const wrongOutcomeKeys = applyResultPayload({ outcomes: { rolled_back: 1, conflict: 0, skipped: 0 } });
	const extraKey = applyResultPayload();
	extraKey.injected = 'x';
	const badChecksum = applyResultPayload({ checksum: 'not-hex' });
	const negativeCount = applyResultPayload({ outcomes: { applied: -1, conflict: 0, skipped: 0 } });
	const nonStringActor = applyResultPayload({ actor: 42 });

	[wrongOutcomeKeys, extraKey, badChecksum, negativeCount, nonStringActor, null, [], 'x'].forEach(function (payload)
	{
		const presentation = bulkReview.describeApplyResult(payload);
		assert.equal(presentation.valid, false, JSON.stringify(payload));
		assert.deepEqual(presentation.blockers, []);
		assert.deepEqual(presentation.outcomes, []);
	});
});

// ---------------------------------------------------------------------------------------------------
// Export (BULK-10, D-12): a pure, zero-write URL builder over the closed json/csv vocabulary.
// ---------------------------------------------------------------------------------------------------

test('exportUrl builds the closed GET .../export?format=<json|csv> URL and rejects any other format', function ()
{
	assert.equal(bulkReview.exportUrl('/api/grocy-ai/bulk/plans', '5', 'json'), '/api/grocy-ai/bulk/plans/5/export?format=json');
	assert.equal(bulkReview.exportUrl('/api/grocy-ai/bulk/plans', '5', 'csv'), '/api/grocy-ai/bulk/plans/5/export?format=csv');
	assert.equal(bulkReview.exportUrl('/api/grocy-ai/bulk/plans', '5', 'xml'), null);
	assert.equal(bulkReview.exportUrl('/api/grocy-ai/bulk/plans', '5', ''), null);
});

test('exportLinks builds both download links for a plan id from the plans endpoint alone', function ()
{
	const links = bulkReview.exportLinks('/api/grocy-ai/bulk/plans', '5');

	assert.deepEqual(links, {
		json: '/api/grocy-ai/bulk/plans/5/export?format=json',
		csv: '/api/grocy-ai/bulk/plans/5/export?format=csv'
	});
});

// ---------------------------------------------------------------------------------------------------
// Apply/rollback-execute confirmation gating and checksum binding (D-13): the controller must never
// fire a mutation without an explicit confirm, and the checksum it sends must come only from the
// controller's own last-rendered server response — never from a caller- or browser-supplied value.
// ---------------------------------------------------------------------------------------------------

function baseControllerOptions(overrides = {})
{
	return Object.assign({
		requestPlan: function () { return Promise.resolve(planPayload()); },
		requestSelectedDiff: function () { return Promise.resolve(selectedDiffPayload()); },
		requestSetSelection: function () { throw new Error('not used in this test'); },
		requestRollbackPreview: function () { return Promise.resolve(rollbackPreviewPayload()); },
		requestApply: function () { throw new Error('not used in this test'); },
		requestRollbackExecute: function () { throw new Error('not used in this test'); },
		renderPlan: function () { },
		renderSelectedDiff: function () { },
		renderRollbackPreview: function () { },
		renderApplyResult: function () { },
		renderRollbackResult: function () { },
		onBusy: function () { },
		onError: function () { }
	}, overrides);
}

test('apply() fires no request without an explicit confirm, and none before any plan is loaded', async function ()
{
	const applyCalls = [];
	const controller = bulkReview.createBulkReviewController(baseControllerOptions({
		requestApply: function (checksum) { applyCalls.push(checksum); return Promise.resolve(applyResultPayload()); }
	}));

	// Before any plan is loaded there is no bound checksum, so even a confirmed apply issues no request.
	const beforeLoad = await controller.apply(true);
	assert.equal(beforeLoad, null);
	assert.deepEqual(applyCalls, []);

	await controller.load();

	// After loading, a call without an explicit `confirmed === true` still fires nothing.
	const unconfirmed = await controller.apply(false);
	assert.equal(unconfirmed, null);
	const notBoolean = await controller.apply('yes');
	assert.equal(notBoolean, null);
	assert.deepEqual(applyCalls, []);
});

test('apply(true), once a plan is loaded, sends exactly the loaded plan\'s own checksum and reloads on success', async function ()
{
	const applyCalls = [];
	const planCalls = [];
	const rendered = [];
	const controller = bulkReview.createBulkReviewController(baseControllerOptions({
		requestPlan: function () { planCalls.push(1); return Promise.resolve(planPayload()); },
		requestApply: function (checksum) { applyCalls.push(checksum); return Promise.resolve(applyResultPayload()); },
		renderApplyResult: function (presentation) { rendered.push(presentation); }
	}));

	await controller.load();
	const result = await controller.apply(true);

	assert.deepEqual(applyCalls, ['a'.repeat(64)]);
	assert.equal(result.valid, true);
	assert.deepEqual(result.blockers, []);
	assert.equal(rendered.length, 1);
	// A clean apply (no blockers) triggers a fresh authoritative reload of the plan — the second `requestPlan`
	// call — rather than fabricating item-level state from the mutation DTO, which carries none.
	assert.equal(planCalls.length, 2);
});

test('a blocked apply (e.g. a 409 plan_checksum_mismatch) renders the bounded result but never reloads or fabricates plan state', async function ()
{
	const planCalls = [];
	const rendered = [];
	const controller = bulkReview.createBulkReviewController(baseControllerOptions({
		requestPlan: function () { planCalls.push(1); return Promise.resolve(planPayload()); },
		requestApply: function () { return Promise.resolve(applyResultPayload({ blockers: ['plan_checksum_mismatch'] })); },
		renderApplyResult: function (presentation) { rendered.push(presentation); }
	}));

	await controller.load();
	const result = await controller.apply(true);

	assert.deepEqual(result.blockers, ['plan_checksum_mismatch']);
	assert.equal(rendered.length, 1);
	assert.equal(planCalls.length, 1);
});

test('a failed apply request announces the apply error and renders no fabricated result', async function ()
{
	const rendered = [];
	const errors = [];
	const controller = bulkReview.createBulkReviewController(baseControllerOptions({
		requestApply: function () { return Promise.reject(new Error('http_status')); },
		renderApplyResult: function (presentation) { rendered.push(presentation); },
		onError: function (message) { errors.push(message); }
	}));

	await controller.load();
	await controller.apply(true);

	assert.deepEqual(rendered, []);
	assert.deepEqual(errors, [bulkReview.COPY.applyError]);
});

test('rollback() fires no request until the rollback preview has been loaded, even when confirmed', async function ()
{
	const rollbackCalls = [];
	const controller = bulkReview.createBulkReviewController(baseControllerOptions({
		requestRollbackExecute: function (checksum) { rollbackCalls.push(checksum); return Promise.resolve(rollbackResultPayload()); }
	}));

	const beforePreview = await controller.rollback(true);
	assert.equal(beforePreview, null);
	assert.deepEqual(rollbackCalls, []);

	await controller.loadRollbackPreview();
	const unconfirmed = await controller.rollback(false);
	assert.equal(unconfirmed, null);
	assert.deepEqual(rollbackCalls, []);
});

test('rollback(true), once the preview is loaded, sends exactly the preview\'s own checksum and reloads plan + preview on success', async function ()
{
	const rollbackCalls = [];
	const previewCalls = [];
	const rendered = [];
	const controller = bulkReview.createBulkReviewController(baseControllerOptions({
		requestRollbackPreview: function () { previewCalls.push(1); return Promise.resolve(rollbackPreviewPayload()); },
		requestRollbackExecute: function (checksum) { rollbackCalls.push(checksum); return Promise.resolve(rollbackResultPayload()); },
		renderRollbackResult: function (presentation) { rendered.push(presentation); }
	}));

	await controller.loadRollbackPreview();
	const result = await controller.rollback(true);

	assert.deepEqual(rollbackCalls, ['b'.repeat(64)]);
	assert.equal(result.valid, true);
	assert.deepEqual(result.blockers, []);
	assert.equal(rendered.length, 1);
	// A clean rollback re-loads the preview fresh (the second `requestRollbackPreview` call) so items that
	// just left the reversible set are never left showing stale local state.
	assert.equal(previewCalls.length, 2);
});

test('a blocked rollback renders the bounded result but never reloads the plan or the preview', async function ()
{
	const previewCalls = [];
	const rendered = [];
	const controller = bulkReview.createBulkReviewController(baseControllerOptions({
		requestRollbackPreview: function () { previewCalls.push(1); return Promise.resolve(rollbackPreviewPayload()); },
		requestRollbackExecute: function () { return Promise.resolve(rollbackResultPayload({ blockers: ['plan_checksum_mismatch'] })); },
		renderRollbackResult: function (presentation) { rendered.push(presentation); }
	}));

	await controller.loadRollbackPreview();
	const result = await controller.rollback(true);

	assert.deepEqual(result.blockers, ['plan_checksum_mismatch']);
	assert.equal(rendered.length, 1);
	assert.equal(previewCalls.length, 1);
});

// ---------------------------------------------------------------------------------------------------
// Generate plan (BULK-01 UI, D-13): the user-facing plan-CREATION control. Closed request body, no
// auto-generation, verbatim bounded error surfacing, and the returned id becoming the active plan.
// ---------------------------------------------------------------------------------------------------

test('buildGeneratePlanBody returns exactly the closed { operation_type: "taxonomy_assignment" } body and takes no arguments', function ()
{
	assert.deepEqual(bulkReview.buildGeneratePlanBody(), { operation_type: 'taxonomy_assignment' });
	// Zero declared parameters means there is structurally no path from caller/DOM input into the body.
	assert.equal(bulkReview.buildGeneratePlanBody.length, 0);
});

test('generate() calls requestGenerate with no arguments and renders the returned plan, refreshing the diff for the new plan id', async function ()
{
	const rendered = { plan: [], diff: [] };
	const generateCalls = [];
	const diffCalls = [];
	const controller = bulkReview.createBulkReviewController({
		requestPlan: function () { throw new Error('not used in this test'); },
		requestSelectedDiff: function (planId) { diffCalls.push(planId); return Promise.resolve(selectedDiffPayload({ plan_id: 42 })); },
		requestSetSelection: function () { throw new Error('not used in this test'); },
		requestGenerate: function () { generateCalls.push(arguments.length); return Promise.resolve(planPayload({ plan: planHeader({ id: 42 }) })); },
		renderPlan: function (presentation) { rendered.plan.push(presentation); },
		renderSelectedDiff: function (presentation) { rendered.diff.push(presentation); },
		onBusy: function () { },
		onError: function () { rendered.plan.push('error'); }
	});

	const result = await controller.generate();

	assert.equal(result.valid, true);
	assert.equal(result.planId, 42);
	assert.deepEqual(generateCalls, [0]);
	assert.equal(rendered.plan.length, 1);
	assert.equal(rendered.plan[0].planId, 42);
	assert.equal(rendered.diff.length, 1);
	assert.deepEqual(diffCalls, [42]);
});

test('a 400/503 from generate surfaces the bounded server error_message verbatim and renders no fabricated plan state', async function ()
{
	const rendered = [];
	const errors = [];
	const boundedError = Object.assign(new Error('generate_failed'), { boundedMessage: 'Invalid plan generation request' });
	const controller = bulkReview.createBulkReviewController({
		requestPlan: function () { throw new Error('not used in this test'); },
		requestSelectedDiff: function () { throw new Error('not used in this test'); },
		requestSetSelection: function () { throw new Error('not used in this test'); },
		requestGenerate: function () { return Promise.reject(boundedError); },
		renderPlan: function (presentation) { rendered.push(presentation); },
		renderSelectedDiff: function () { },
		onBusy: function () { },
		onError: function (message) { errors.push(message); }
	});

	const result = await controller.generate();

	assert.equal(result, null);
	assert.deepEqual(rendered, []);
	assert.deepEqual(errors, ['Invalid plan generation request']);
});

test('a malformed 201 payload from generate fails closed to the generic generate error, never a fabricated or partial render', async function ()
{
	const rendered = [];
	const errors = [];
	const controller = bulkReview.createBulkReviewController({
		requestPlan: function () { throw new Error('not used in this test'); },
		requestSelectedDiff: function () { throw new Error('not used in this test'); },
		requestSetSelection: function () { throw new Error('not used in this test'); },
		requestGenerate: function () { return Promise.resolve({}); },
		renderPlan: function (presentation) { rendered.push(presentation); },
		renderSelectedDiff: function () { },
		onBusy: function () { },
		onError: function (message) { errors.push(message); }
	});

	const result = await controller.generate();

	assert.equal(result, null);
	assert.deepEqual(rendered, []);
	assert.deepEqual(errors, [bulkReview.COPY.generateError]);
});

test('generation never fires on initial load — only an explicit generate() call ever calls requestGenerate', async function ()
{
	let generateCalls = 0;
	const controller = bulkReview.createBulkReviewController({
		requestPlan: function () { return Promise.resolve(planPayload()); },
		requestSelectedDiff: function () { return Promise.resolve(selectedDiffPayload()); },
		requestSetSelection: function () { throw new Error('not used in this test'); },
		requestGenerate: function () { generateCalls++; return Promise.resolve(planPayload()); },
		renderPlan: function () { },
		renderSelectedDiff: function () { },
		onBusy: function () { },
		onError: function () { }
	});

	await controller.load();

	assert.equal(generateCalls, 0);
});

test('the plan id returned by generate() becomes the target for every subsequent toggle and rollback-preview call', async function ()
{
	const diffCalls = [];
	const setSelectionCalls = [];
	const rollbackPreviewCalls = [];
	const controller = bulkReview.createBulkReviewController({
		requestPlan: function () { throw new Error('not used in this test'); },
		requestSelectedDiff: function (planId) { diffCalls.push(planId); return Promise.resolve(selectedDiffPayload({ plan_id: 42, items: [], included: 0 })); },
		requestSetSelection: function (seq, selected, planId)
		{
			setSelectionCalls.push(planId);
			return Promise.resolve(planPayload({ plan: planHeader({ id: 42 }), items: [item({ seq: seq, selected: selected })] }));
		},
		requestGenerate: function () { return Promise.resolve(planPayload({ plan: planHeader({ id: 42 }) })); },
		requestRollbackPreview: function (planId) { rollbackPreviewCalls.push(planId); return Promise.resolve(rollbackPreviewPayload()); },
		renderPlan: function () { },
		renderSelectedDiff: function () { },
		renderRollbackPreview: function () { },
		onBusy: function () { },
		onError: function () { }
	});

	await controller.generate();
	await controller.toggle(0, false);
	await controller.loadRollbackPreview();

	assert.deepEqual(diffCalls, [42, 42]);
	assert.deepEqual(setSelectionCalls, [42]);
	assert.deepEqual(rollbackPreviewCalls, [42]);
});

test('apply(), after generate(), sends the generated plan\'s own id and checksum — never the id of any previously loaded plan', async function ()
{
	const applyCalls = [];
	const controller = bulkReview.createBulkReviewController(baseControllerOptions({
		requestGenerate: function () { return Promise.resolve(planPayload({ plan: planHeader({ id: 42 }) })); },
		requestSelectedDiff: function () { return Promise.resolve(selectedDiffPayload({ plan_id: 42 })); },
		requestApply: function (checksum, planId) { applyCalls.push([planId, checksum]); return Promise.resolve(applyResultPayload()); },
		requestPlan: function () { return Promise.resolve(planPayload({ plan: planHeader({ id: 42 }) })); }
	}));

	await controller.generate();
	const result = await controller.apply(true);

	assert.equal(result.valid, true);
	assert.deepEqual(applyCalls, [[42, 'a'.repeat(64)]]);
});

test('a failed rollback-preview load announces its own error and never blocks a later successful load', async function ()
{
	const errors = [];
	let shouldFail = true;
	const controller = bulkReview.createBulkReviewController(baseControllerOptions({
		requestRollbackPreview: function ()
		{
			if (shouldFail)
			{
				return Promise.reject(new Error('http_status'));
			}
			return Promise.resolve(rollbackPreviewPayload());
		},
		onError: function (message) { errors.push(message); }
	}));

	const failed = await controller.loadRollbackPreview();
	assert.equal(failed, null);
	assert.deepEqual(errors, [bulkReview.COPY.rollbackPreviewError]);

	shouldFail = false;
	const succeeded = await controller.loadRollbackPreview();
	assert.equal(succeeded.valid, true);
});
