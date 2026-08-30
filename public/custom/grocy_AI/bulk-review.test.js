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
