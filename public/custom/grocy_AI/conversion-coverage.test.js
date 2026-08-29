'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const coverage = require('./conversion-coverage.js');

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

function report(overrides = {})
{
	return Object.assign({
		ruleset_version: 'v1',
		source_version: 'NIST-SP-811-2008-Appendix-B.9',
		profile_source_version: 'SR Legacy 2018-04; published 2019-04-01',
		gate: { state: 'inactive', main_branch_evidence: 'absent', stable_branch_evidence: 'absent', selected_projection: 'none' },
		counts: {
			catalog_units: 14, universal_rules: 12, profiles: 3, covered_pairs: 24,
			missing_paths: 68, unavailable_profiles: 6, redundant_product_overrides: 0, blockers: 0
		},
		blockers: [],
		effective_sources: [
			{ source: 'product_override', count: 0 },
			{ source: 'food_profile', count: 3 },
			{ source: 'universal', count: 12 }
		],
		protected_behavior: ['stock', 'recipe', 'purchase', 'consumption', 'price', 'transfer', 'meal_plan', 'quantity_display'].map(function (category)
		{
			return { category: category, state: 'unverified' };
		})
	}, overrides);
}

function rendered(presentation)
{
	return [presentation.headline]
		.concat(presentation.lines.map(function (row) { return row.term + '=' + row.value; }))
		.concat(presentation.blockers.map(function (row) { return row.term + '=' + row.value; }))
		.concat(presentation.counts.map(function (row) { return row.term + '=' + row.value; }))
		.concat(presentation.protectedBehavior.map(function (row) { return row.term + '=' + row.value; }))
		.join(' | ');
}

test('an incomplete characterization gate uses the exact inactive copy and never claims readiness', function ()
{
	const presentation = coverage.describeCoverageReport(report());

	assert.equal(presentation.kind, 'inactive');
	assert.equal(presentation.headline, 'Reusable rules are inactive until characterization passed on both branches.');
	assert.equal(presentation.role, 'status');
	assert.match(rendered(presentation), /Selected projection=none/);
	assert.match(rendered(presentation), /Main branch characterization=absent/);
	assert.match(rendered(presentation), /Stable branch characterization=absent/);
	assert.doesNotMatch(rendered(presentation), /Ruleset ready/);
});

test('a blocked ruleset states its exact blocker count in text, not colour alone', function ()
{
	const presentation = coverage.describeCoverageReport(report({
		gate: { state: 'blocked', main_branch_evidence: 'absent', stable_branch_evidence: 'absent', selected_projection: 'none' },
		counts: Object.assign(report().counts, { blockers: 2 }),
		blockers: [{ category: 'malformed_factor', count: 1 }, { category: 'cycle_detected', count: 1 }]
	}));

	assert.equal(presentation.kind, 'blocked');
	assert.equal(presentation.headline, 'Ruleset has 2 blockers');
	assert.equal(presentation.role, 'alert');
	assert.equal(presentation.variant, 'danger');
	assert.match(rendered(presentation), /Invalid factor=1/);
	assert.match(rendered(presentation), /Cycle detected=1/);
	// A raw blocker code never reaches the report surface.
	assert.doesNotMatch(rendered(presentation), /malformed_factor|cycle_detected/);
});

test('a ready ruleset uses the exact ready copy', function ()
{
	const presentation = coverage.describeCoverageReport(report({
		gate: { state: 'ready', main_branch_evidence: 'present', stable_branch_evidence: 'present', selected_projection: 'native-cache-v1' }
	}));

	assert.equal(presentation.kind, 'ready');
	assert.equal(presentation.headline, 'Ruleset ready');
	assert.equal(presentation.variant, 'success');
});

test('an empty blocker list uses the exact empty-state copy instead of hiding the section', function ()
{
	const presentation = coverage.describeCoverageReport(report());

	assert.deepEqual(presentation.blockers, [{ term: '', value: 'No blocking conversion issues were found.' }]);
});

test('a malformed, extended, or out-of-range report fails closed to bounded recovery copy', function ()
{
	const extended = report();
	extended.injected = 'DROP TABLE products';
	const badGate = report();
	badGate.gate.state = 'active';
	const badCount = report();
	badCount.counts.blockers = -1;
	const badCategory = report({ blockers: [{ category: 'secret_internal_code', count: 1 }] });
	const badProtected = report();
	badProtected.protected_behavior[0].state = 'leaked';

	[extended, badGate, badCount, badCategory, badProtected, null, 'inactive', [], { gate: {} }].forEach(function (payload)
	{
		const presentation = coverage.describeCoverageReport(payload);
		assert.equal(presentation.kind, 'error');
		assert.equal(presentation.headline, 'The validation report could not be refreshed. Try again.');
		assert.deepEqual(presentation.counts, []);
		assert.deepEqual(presentation.protectedBehavior, []);
		assert.doesNotMatch(rendered(presentation), /DROP TABLE|secret_internal_code|leaked/);
	});
});

test('an older refresh response can never replace a newer complete report', async function ()
{
	const first = deferred();
	const second = deferred();
	const pending = [first, second];
	const painted = [];
	const busy = [];
	const controller = coverage.createCoverageController({
		requestReport: function () { return pending.shift().promise; },
		render: function (presentation) { painted.push(presentation.headline); },
		onBusy: function (value) { busy.push(value); },
		onError: function () { painted.push('error'); }
	});

	const firstRefresh = controller.refresh();
	const secondRefresh = controller.refresh();
	second.resolve(report({
		gate: { state: 'ready', main_branch_evidence: 'present', stable_branch_evidence: 'present', selected_projection: 'native-cache-v1' }
	}));
	await secondRefresh;
	first.resolve(report());
	await firstRefresh;

	assert.deepEqual(painted, ['Ruleset ready']);
	assert.deepEqual(busy, [true, true, false, false]);
});

test('a failed refresh retains the last complete report and only announces recovery copy', async function ()
{
	const failure = deferred();
	const painted = [];
	const errors = [];
	const controller = coverage.createCoverageController({
		requestReport: function () { return failure.promise; },
		render: function (presentation) { painted.push(presentation.headline); },
		onBusy: function () { },
		onError: function (message) { errors.push(message); }
	});

	const refresh = controller.refresh();
	failure.reject(new Error('SQLSTATE[HY000] /config/data/grocy.db is locked'));
	await refresh;

	assert.deepEqual(painted, []);
	assert.deepEqual(errors, ['The validation report could not be refreshed. Try again.']);
});

test('a refresh that returns a malformed report is treated as a failed refresh, not a new report', async function ()
{
	const answer = deferred();
	const painted = [];
	const errors = [];
	const controller = coverage.createCoverageController({
		requestReport: function () { return answer.promise; },
		render: function (presentation) { painted.push(presentation.headline); },
		onBusy: function () { },
		onError: function (message) { errors.push(message); }
	});

	const refresh = controller.refresh();
	answer.resolve({ gate: { state: 'ready' } });
	await refresh;

	assert.deepEqual(painted, []);
	assert.deepEqual(errors, ['The validation report could not be refreshed. Try again.']);
});
