'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const conversionValidation = require('../../viewjs/quantityunitconversionform.js');

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

function reusableCandidate(overrides = {})
{
	return Object.assign({
		productId: null,
		fromQuId: '1',
		fromName: 'kilogram',
		toQuId: '2',
		toName: 'gram',
		factor: '1000'
	}, overrides);
}

function harness(initialCandidate, requests = [])
{
	let candidate = initialCandidate;
	const states = [];
	const controller = conversionValidation.createValidationController({
		getCandidate: function () { return candidate; },
		requestValidation: function ()
		{
			assert.ok(requests.length > 0, 'test must provide a validation response');
			return requests.shift().promise;
		},
		render: function (state) { states.push(structuredClone(state)); }
	});
	return {
		controller,
		states,
		setCandidate: function (next) { candidate = next; },
		latest: function () { return states.at(-1); }
	};
}

test('a late prior-candidate response cannot replace current inactive validation or enable Save', async function ()
{
	const first = deferred();
	const second = deferred();
	const fixture = harness(reusableCandidate(), [first, second]);

	const firstValidation = fixture.controller.validate();
	assert.equal(fixture.latest().kind, 'pending');
	assert.equal(fixture.latest().saveEnabled, false);

	fixture.setCandidate(reusableCandidate({ factor: '1000.0000000001' }));
	fixture.controller.invalidate();
	assert.equal(fixture.latest().kind, 'stale');
	assert.equal(fixture.latest().saveEnabled, false);

	const secondValidation = fixture.controller.validate();
	second.resolve({
		status: 'inactive', scope: 'reusable', blockers: [], factor: '1000.0000000001',
		dimension: 'mass', source_version: 'NIST-SP-811-2008-Appendix-B.9', inactive_revision_id: 'conversion-catalog-v1'
	});
	await secondValidation;
	assert.equal(fixture.latest().kind, 'inactive-gate');
	assert.equal(fixture.latest().saveEnabled, false);

	first.resolve({
		status: 'active', scope: 'reusable', blockers: [], factor: '1000',
		dimension: 'mass', source_version: 'NIST-SP-811-2008-Appendix-B.9', inactive_revision_id: 'conversion-catalog-v1'
	});
	await firstValidation;
	assert.equal(fixture.latest().kind, 'inactive-gate');
	assert.equal(fixture.latest().factor, '1000.0000000001');
	assert.equal(fixture.states.some(function (state) { return state.saveEnabled; }), false);
});

test('inactive reusable result exposes precise reviewed evidence without claiming activation', async function ()
{
	const response = deferred();
	const fixture = harness(reusableCandidate({
		fromName: 'pound',
		toName: 'gram',
		factor: '453.5923700000001'
	}), [response]);
	const validation = fixture.controller.validate();
	response.resolve({
		status: 'inactive', scope: 'reusable', blockers: [], factor: '453.5923700000001',
		dimension: 'mass', source_version: 'NIST-SP-811-2008-Appendix-B.9', inactive_revision_id: 'conversion-catalog-v1'
	});
	await validation;

	const result = fixture.latest();
	assert.equal(result.kind, 'inactive-gate');
	assert.equal(result.pair, '1 pound = 453.5923700000001 gram');
	assert.equal(result.dimensionLabel, 'Dimension: Mass');
	assert.equal(result.sourceLabel, 'Source: NIST SP 811 · NIST-SP-811-2008-Appendix-B.9');
	assert.equal(result.impact, 'No blocking paths, cycles, reciprocal conflicts, or tolerance failures were found.');
	assert.equal(result.message, 'Reusable conversion profiles are inactive until both branch checks pass.');
	assert.equal(result.statusLabel, 'Inactive — not saved or active');
	assert.equal(result.saveEnabled, false);
	assert.doesNotMatch(JSON.stringify(result), /Ruleset ready|Active conversion/);
});

test('product-native validation retains Save while reusable package and failures remain bounded', async function ()
{
	const productResponse = deferred();
	const fixture = harness(reusableCandidate({
		productId: '91', fromQuId: '5', fromName: 'package', toQuId: '7', toName: 'piece', factor: '12'
	}), [productResponse]);
	const productValidation = fixture.controller.validate();
	productResponse.resolve({
		status: 'product_native', scope: 'product', blockers: [], factor: '12',
		dimension: 'product_scoped', source_version: 'NIST-SP-811-2008-Appendix-B.9', inactive_revision_id: 'conversion-catalog-v1'
	});
	await productValidation;
	assert.deepEqual(fixture.latest(), {
		kind: 'product-normal',
		role: 'status',
		statusLabel: 'Product override',
		message: 'This conversion takes precedence over any food-type profile and universal default.',
		pair: '1 package = 12 piece',
		dimensionLabel: '',
		sourceLabel: '',
		impact: 'No blocking paths, cycles, reciprocal conflicts, or tolerance failures were found.',
		blocker: '',
		factor: '12',
		saveEnabled: true,
		focusHeading: false
	});

	const blockedResponse = deferred();
	fixture.setCandidate(reusableCandidate({ fromName: 'package', toName: 'piece', factor: '12' }));
	fixture.controller.invalidate();
	fixture.controller.setRequestValidation(function () { return blockedResponse.promise; });
	const blockedValidation = fixture.controller.validate();
	blockedResponse.resolve({
		status: 'blocked', scope: 'reusable', blockers: ['reusable_count_scope'], factor: null,
		dimension: null, source_version: 'NIST-SP-811-2008-Appendix-B.9', inactive_revision_id: 'conversion-catalog-v1'
	});
	await blockedValidation;
	assert.equal(fixture.latest().kind, 'blocked');
	assert.equal(fixture.latest().blocker, 'This quantity-unit pair is not eligible for a reusable default. Keep package and count conversions on the product.');
	assert.equal(fixture.latest().role, 'alert');
	assert.equal(fixture.latest().focusHeading, true);
	assert.equal(fixture.latest().saveEnabled, false);

	const failedResponse = deferred();
	fixture.controller.setRequestValidation(function () { return failedResponse.promise; });
	const failedValidation = fixture.controller.validate();
	failedResponse.reject(new Error('raw upstream details must stay hidden'));
	await failedValidation;
	assert.equal(fixture.latest().kind, 'request-failure');
	assert.equal(fixture.latest().message, 'This conversion could not be validated. Correct any visible fields or try again. Nothing was changed.');
	assert.doesNotMatch(JSON.stringify(fixture.latest()), /raw upstream/);
	assert.equal(fixture.latest().saveEnabled, false);
});

test('incomplete candidate remains neutral and never starts a request', async function ()
{
	const fixture = harness(reusableCandidate({ toQuId: '', toName: '', factor: '0' }), []);
	await fixture.controller.validate();
	assert.equal(fixture.latest().kind, 'incomplete');
	assert.equal(fixture.latest().message, 'Choose both quantity units and a positive factor to validate this conversion.');
	assert.equal(fixture.latest().saveEnabled, false);
});

test('malformed reusable provenance is bounded as a validation failure and cannot enable Save', async function ()
{
	const response = deferred();
	const fixture = harness(reusableCandidate(), [response]);
	const validation = fixture.controller.validate();
	response.resolve({
		status: 'active', scope: 'reusable', blockers: [], factor: '1000', dimension: 'mass<script>',
		source_version: 'raw\nprovider\nresponse', inactive_revision_id: 'conversion-catalog-v1'
	});
	await validation;
	assert.equal(fixture.latest().kind, 'request-failure');
	assert.equal(fixture.latest().message, 'This conversion could not be validated. Correct any visible fields or try again. Nothing was changed.');
	assert.equal(fixture.latest().saveEnabled, false);
	assert.doesNotMatch(JSON.stringify(fixture.latest()), /script|provider/);
});

test('a current server-active reusable response remains fail-closed before Plan 08', async function ()
{
	const response = deferred();
	const fixture = harness(reusableCandidate(), [response]);
	const validation = fixture.controller.validate();
	response.resolve({
		status: 'active', scope: 'reusable', blockers: [], factor: '1000', dimension: 'mass',
		source_version: 'NIST-SP-811-2008-Appendix-B.9', inactive_revision_id: 'conversion-catalog-v1'
	});
	await validation;
	assert.equal(fixture.latest().kind, 'request-failure');
	assert.equal(fixture.latest().statusLabel, 'Validation unavailable');
	assert.equal(fixture.latest().saveEnabled, false);
	assert.doesNotMatch(JSON.stringify(fixture.latest()), /active-ready|Active and eligible/);
});

test('a response scope that disagrees with the current form scope fails closed', async function ()
{
	const response = deferred();
	const fixture = harness(reusableCandidate(), [response]);
	const validation = fixture.controller.validate();
	response.resolve({
		status: 'product_native', scope: 'product', blockers: [], factor: '1000', dimension: 'product_scoped',
		source_version: 'NIST-SP-811-2008-Appendix-B.9', inactive_revision_id: 'conversion-catalog-v1'
	});
	await validation;
	assert.equal(fixture.latest().kind, 'request-failure');
	assert.equal(fixture.latest().saveEnabled, false);
});

test('a product-native response without the exact current factor fails closed', async function ()
{
	const response = deferred();
	const fixture = harness(reusableCandidate({ productId: '91' }), [response]);
	const validation = fixture.controller.validate();
	response.resolve({
		status: 'product_native', scope: 'product', blockers: [], factor: null, dimension: 'product_scoped',
		source_version: 'NIST-SP-811-2008-Appendix-B.9', inactive_revision_id: 'conversion-catalog-v1'
	});
	await validation;
	assert.equal(fixture.latest().kind, 'request-failure');
	assert.equal(fixture.latest().saveEnabled, false);
});
