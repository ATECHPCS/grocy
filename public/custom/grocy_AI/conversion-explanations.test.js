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

const productStatus = require('./conversion-explanations.js');

function nativeStatus(overrides = {})
{
	return Object.assign({
		status: 'product_native',
		blockers: [],
		factor: '453.5923700000001',
		dimension: 'mass',
		approximate: false,
		winner_source: 'product_override',
		source_name: 'Grocy native product conversion',
		source_version: null,
		source_status: 'native',
		source_item_id: null,
		profile_key: null,
		taxonomy_leaf: null,
		precedence: 'product_override>food_profile>universal',
		inactive_revision_id: null
	}, overrides);
}

function profileStatus(overrides = {})
{
	return Object.assign(nativeStatus(), {
		status: 'inactive',
		factor: null,
		approximate: true,
		winner_source: 'food_profile',
		source_name: 'USDA FoodData Central',
		source_version: 'FDC-2024-10',
		source_status: 'inactive',
		source_item_id: '747447',
		profile_key: 'produce-cup-gram',
		taxonomy_leaf: 'produce',
		inactive_revision_id: 'conversion-profile-v1'
	}, overrides);
}

function detailValues(presentation)
{
	return presentation.details.map(function (row) { return row.term + '=' + row.value; }).join('|');
}

function renderedText(presentation)
{
	return [presentation.statusLabel, presentation.headline, presentation.note || '', detailValues(presentation)].join(' ');
}

test('a native product conversion is Exact, names the override source, and discloses its full precision factor', function ()
{
	const presentation = productStatus.describeProductConversionStatus(nativeStatus());

	assert.equal(presentation.kind, 'product-override');
	assert.equal(presentation.statusLabel, 'Exact');
	assert.equal(presentation.role, 'status');
	assert.equal(presentation.factor, '453.5923700000001');
	assert.equal(presentation.note, 'This conversion takes precedence over any food-type profile and universal default.');
	assert.match(detailValues(presentation), /Full precision factor=453\.5923700000001/);
	assert.match(detailValues(presentation), /Precedence=product_override>food_profile>universal/);
	assert.equal(presentation.detailsLabel, 'Show conversion details');
});

test('an inactive food-type profile is Approximate, names source and version, and never exposes a usable factor', function ()
{
	const presentation = productStatus.describeProductConversionStatus(profileStatus());

	assert.equal(presentation.kind, 'approximate-profile');
	assert.equal(presentation.statusLabel, 'Approximate');
	assert.equal(presentation.headline, 'Approximate profile: produce-cup-gram · USDA FoodData Central FDC-2024-10');
	assert.equal(presentation.note, 'Reusable conversion profiles are inactive until both branch checks pass.');
	assert.equal(presentation.factor, null);
	assert.doesNotMatch(renderedText(presentation), /Full precision factor/);
});

test('an inactive profile factor supplied by a tampered response is still redacted', function ()
{
	const presentation = productStatus.describeProductConversionStatus(profileStatus({ factor: '236.588' }));

	assert.equal(presentation.factor, null);
	assert.doesNotMatch(renderedText(presentation), /236\.588/);
});

test('an inactive universal rule reports the incomplete gate instead of an active exact default', function ()
{
	const presentation = productStatus.describeProductConversionStatus(profileStatus({
		approximate: false, winner_source: 'universal', source_name: 'NIST SP 811',
		source_version: 'NIST-SP-811-2008-Appendix-B.9', source_item_id: null, profile_key: null,
		taxonomy_leaf: null, inactive_revision_id: 'conversion-catalog-v1'
	}));

	assert.equal(presentation.kind, 'inactive-universal');
	assert.equal(presentation.statusLabel, 'Unavailable');
	assert.equal(presentation.headline, 'Reusable conversion profiles are inactive until both branch checks pass.');
	assert.equal(presentation.factor, null);
});

test('an unclassified product is told it needs an explicit food classification', function ()
{
	const presentation = productStatus.describeProductConversionStatus(profileStatus({
		status: 'unavailable', blockers: ['explicit_taxonomy_required'], approximate: null, winner_source: null,
		source_name: null, source_version: null, source_status: null, source_item_id: null, profile_key: null,
		taxonomy_leaf: null, dimension: null, inactive_revision_id: null
	}));

	assert.equal(presentation.kind, 'unavailable');
	assert.equal(presentation.statusLabel, 'Unavailable');
	assert.equal(presentation.headline, 'No estimate is available until this product has an explicit food classification.');
	assert.equal(presentation.factor, null);
});

test('a classified product without an approved profile keeps the contract empty copy', function ()
{
	const presentation = productStatus.describeProductConversionStatus(profileStatus({
		status: 'unavailable', blockers: ['profile_unavailable'], approximate: null, winner_source: null,
		source_name: null, source_version: null, source_status: null, source_item_id: null, profile_key: null,
		dimension: null, inactive_revision_id: null
	}));

	assert.equal(presentation.headline, 'No estimate is available for this food type.');
	assert.equal(presentation.note, 'No approved food-type conversion profile applies. Use a measured product conversion if you need this relationship.');
});

test('a blocked resolution names a closed correction category, alerts, and exposes no factor', function ()
{
	const presentation = productStatus.describeProductConversionStatus(profileStatus({
		status: 'blocked', blockers: ['same_rank_collision'], factor: '1000', approximate: null, winner_source: null,
		source_name: null, source_version: null, source_status: null, source_item_id: null, profile_key: null,
		taxonomy_leaf: null, dimension: null, inactive_revision_id: null
	}));

	assert.equal(presentation.kind, 'blocked');
	assert.equal(presentation.statusLabel, 'Blocked');
	assert.equal(presentation.role, 'alert');
	assert.equal(presentation.factor, null);
	assert.match(detailValues(presentation), /Correction needed=More than one conversion competes for this pair\./);
	assert.doesNotMatch(renderedText(presentation), /1000/);
});

test('an unknown blocker code is never rendered raw', function ()
{
	const presentation = productStatus.describeProductConversionStatus(profileStatus({
		status: 'blocked', blockers: ['secret_internal_table_leak'], approximate: null, winner_source: null,
		source_name: null, source_version: null, source_status: null, source_item_id: null, profile_key: null,
		taxonomy_leaf: null, dimension: null, inactive_revision_id: null, factor: null
	}));

	assert.doesNotMatch(renderedText(presentation), /secret_internal_table_leak/);
	assert.match(detailValues(presentation), /Correction needed=Review this conversion in the native conversion form\./);
});

test('a payload with unexpected members, a bad status, or an oversized field fails closed to bounded recovery copy', function ()
{
	const extra = Object.assign(nativeStatus(), { injected_sql: 'DROP TABLE products' });
	const missing = nativeStatus();
	delete missing.precedence;

	[extra, missing, nativeStatus({ status: 'active' }), nativeStatus({ blockers: 'none' }),
		nativeStatus({ source_name: 'x'.repeat(201) }), nativeStatus({ factor: '<img src=x onerror=alert(1)>' }),
		null, 'product_native', []].forEach(function (payload)
	{
		const presentation = productStatus.describeProductConversionStatus(payload);
		assert.equal(presentation.kind, 'error');
		assert.equal(presentation.headline, 'The conversion status could not be loaded. Try again. Nothing was changed.');
		assert.equal(presentation.factor, null);
		assert.deepEqual(presentation.details, []);
	});
});

test('every presented value is a plain string so the renderer can only insert text', function ()
{
	[nativeStatus(), profileStatus(), profileStatus({ status: 'blocked', blockers: ['cycle_detected'] })].forEach(function (payload)
	{
		const presentation = productStatus.describeProductConversionStatus(payload);
		assert.equal(typeof presentation.headline, 'string');
		assert.ok(presentation.note === null || typeof presentation.note === 'string');
		presentation.details.forEach(function (row)
		{
			assert.equal(typeof row.term, 'string');
			assert.equal(typeof row.value, 'string');
		});
	});
});

test('a late response for a superseded product revision cannot replace the current status', async function ()
{
	const first = deferred();
	const second = deferred();
	const pending = [first, second];
	const rendered = [];
	let revision = 'product-91-r1';
	const controller = productStatus.createProductStatusController({
		getRevision: function () { return revision; },
		requestStatus: function () { return pending.shift().promise; },
		render: function (presentation) { rendered.push(presentation); }
	});

	const firstLoad = controller.load();
	revision = 'product-91-r2';
	const secondLoad = controller.load();
	second.resolve(profileStatus());
	await secondLoad;
	assert.equal(rendered.at(-1).kind, 'approximate-profile');

	first.resolve(nativeStatus());
	await firstLoad;
	assert.equal(rendered.at(-1).kind, 'approximate-profile');
});

test('an out-of-order response for the current revision cannot overwrite a newer result', async function ()
{
	const first = deferred();
	const second = deferred();
	const pending = [first, second];
	const rendered = [];
	const controller = productStatus.createProductStatusController({
		getRevision: function () { return 'product-91-r1'; },
		requestStatus: function () { return pending.shift().promise; },
		render: function (presentation) { rendered.push(presentation); }
	});

	const firstLoad = controller.load();
	const secondLoad = controller.load();
	second.resolve(profileStatus());
	await secondLoad;
	first.resolve(nativeStatus());
	await firstLoad;

	assert.equal(rendered.at(-1).kind, 'approximate-profile');
});

test('a failed status read renders bounded recovery copy without a factor or raw error text', async function ()
{
	const failure = deferred();
	const rendered = [];
	const controller = productStatus.createProductStatusController({
		getRevision: function () { return 'product-91-r1'; },
		requestStatus: function () { return failure.promise; },
		render: function (presentation) { rendered.push(presentation); }
	});

	const load = controller.load();
	failure.reject(new Error('SQLSTATE[HY000]: /var/lib/grocy/grocy.db is locked'));
	await load;

	assert.equal(rendered.at(-1).kind, 'error');
	assert.equal(rendered.at(-1).headline, 'The conversion status could not be loaded. Try again. Nothing was changed.');
	assert.doesNotMatch(renderedText(rendered.at(-1)), /SQLSTATE|grocy\.db/);
});

function resolvedIdentity(overrides = {})
{
	return Object.assign({ rowKey: 'row-1', productId: '1', fromQuId: '4', toQuId: '2' }, overrides);
}

function resolvedHarness(pending)
{
	const painted = [];
	const requested = [];
	const controller = productStatus.createResolvedProvenanceController({
		requestProvenance: function (identity)
		{
			requested.push(identity.rowKey + ':' + identity.fromQuId + '>' + identity.toQuId);
			return pending.shift().promise;
		},
		renderRow: function (rowKey, presentation) { painted.push({ rowKey, kind: presentation.kind, statusLabel: presentation.statusLabel }); }
	});
	return { controller, painted, requested };
}

test('a resolved row presents the same closed contract as the product status boundary', async function ()
{
	const answer = deferred();
	const fixture = resolvedHarness([answer]);

	const load = fixture.controller.loadRow(resolvedIdentity());
	answer.resolve(nativeStatus());
	await load;

	assert.deepEqual(fixture.painted, [{ rowKey: 'row-1', kind: 'product-override', statusLabel: 'Exact' }]);
	assert.deepEqual(fixture.requested, ['row-1:4>2']);
});

test('a late response for one resolved row can never paint a different row', async function ()
{
	const first = deferred();
	const second = deferred();
	const fixture = resolvedHarness([first, second]);

	const firstLoad = fixture.controller.loadRow(resolvedIdentity({ rowKey: 'row-1' }));
	const secondLoad = fixture.controller.loadRow(resolvedIdentity({ rowKey: 'row-2', fromQuId: '1' }));
	second.resolve(profileStatus());
	await secondLoad;
	first.resolve(nativeStatus());
	await firstLoad;

	assert.deepEqual(fixture.painted.map(function (entry) { return entry.rowKey; }), ['row-2', 'row-1']);
	assert.equal(fixture.painted.find(function (entry) { return entry.rowKey === 'row-2'; }).kind, 'approximate-profile');
	assert.equal(fixture.painted.find(function (entry) { return entry.rowKey === 'row-1'; }).kind, 'product-override');
});

test('a superseded response for the same resolved row is dropped', async function ()
{
	const first = deferred();
	const second = deferred();
	const fixture = resolvedHarness([first, second]);

	const firstLoad = fixture.controller.loadRow(resolvedIdentity());
	const secondLoad = fixture.controller.loadRow(resolvedIdentity());
	second.resolve(profileStatus());
	await secondLoad;
	first.resolve(nativeStatus());
	await firstLoad;

	assert.deepEqual(fixture.painted, [{ rowKey: 'row-1', kind: 'approximate-profile', statusLabel: 'Approximate' }]);
});

test('a blocked or unavailable resolved row can never present a factor even when the response carries one', async function ()
{
	const blocked = deferred();
	const unavailable = deferred();
	const presentations = [];
	const controller = productStatus.createResolvedProvenanceController({
		requestProvenance: function () { return (presentations.length === 0 ? blocked : unavailable).promise; },
		renderRow: function (rowKey, presentation) { presentations.push(presentation); }
	});

	const blockedLoad = controller.loadRow(resolvedIdentity({ rowKey: 'row-1' }));
	blocked.resolve(profileStatus({ status: 'blocked', blockers: ['cycle_detected'], factor: '999' }));
	await blockedLoad;
	const unavailableLoad = controller.loadRow(resolvedIdentity({ rowKey: 'row-2' }));
	unavailable.resolve(profileStatus({ status: 'unavailable', blockers: ['reusable_count_scope'], factor: '888' }));
	await unavailableLoad;

	presentations.forEach(function (presentation)
	{
		assert.equal(presentation.factor, null);
		assert.doesNotMatch(renderedText(presentation), /999|888/);
	});
	assert.equal(presentations[0].statusLabel, 'Blocked');
	assert.equal(presentations[1].statusLabel, 'Unavailable');
});

test('a failed resolved row read paints bounded recovery copy for that row only', async function ()
{
	const failure = deferred();
	const success = deferred();
	const fixture = resolvedHarness([failure, success]);

	const failedLoad = fixture.controller.loadRow(resolvedIdentity({ rowKey: 'row-1' }));
	failure.reject(new Error('SQLSTATE[HY000] /config/data/grocy.db is locked'));
	await failedLoad;
	const okLoad = fixture.controller.loadRow(resolvedIdentity({ rowKey: 'row-2' }));
	success.resolve(nativeStatus());
	await okLoad;

	assert.deepEqual(fixture.painted, [
		{ rowKey: 'row-1', kind: 'error', statusLabel: 'Unavailable' },
		{ rowKey: 'row-2', kind: 'product-override', statusLabel: 'Exact' }
	]);
});

test('resolved rows are requested at most once per identity for one load pass', async function ()
{
	const answers = [deferred(), deferred()];
	const fixture = resolvedHarness(answers.slice());

	const loads = fixture.controller.loadRows([
		resolvedIdentity({ rowKey: 'row-1' }),
		resolvedIdentity({ rowKey: 'row-2', fromQuId: '1' }),
		resolvedIdentity({ rowKey: 'row-1' })
	]);
	answers[0].resolve(nativeStatus());
	answers[1].resolve(profileStatus());
	await loads;

	assert.deepEqual(fixture.requested, ['row-1:4>2', 'row-2:1>2']);
});

test('an unavailable result names a correction category only when its reason maps to a closed one', function ()
{
	const scoped = productStatus.describeProductConversionStatus(profileStatus({
		status: 'unavailable', blockers: ['reusable_count_scope'], factor: null, approximate: null, winner_source: null,
		source_name: null, source_version: null, source_status: null, source_item_id: null, profile_key: null,
		taxonomy_leaf: null, dimension: null, inactive_revision_id: null
	}));
	assert.match(detailValues(scoped), /Correction needed=Package and count conversions stay on the product\./);

	const unclassified = productStatus.describeProductConversionStatus(profileStatus({
		status: 'unavailable', blockers: ['explicit_taxonomy_required'], factor: null, approximate: null, winner_source: null,
		source_name: null, source_version: null, source_status: null, source_item_id: null, profile_key: null,
		taxonomy_leaf: null, dimension: null, inactive_revision_id: null
	}));
	// The headline already explains this case; a generic correction sentence would mislead.
	assert.doesNotMatch(detailValues(unclassified), /Correction needed/);

	const blockedUnknown = productStatus.describeProductConversionStatus(profileStatus({
		status: 'blocked', blockers: ['unmapped_future_code'], factor: null, approximate: null, winner_source: null,
		source_name: null, source_version: null, source_status: null, source_item_id: null, profile_key: null,
		taxonomy_leaf: null, dimension: null, inactive_revision_id: null
	}));
	// A blocker always states a correction state, even when its code is not yet mapped.
	assert.match(detailValues(blockedUnknown), /Correction needed=Review this conversion in the native conversion form\./);
});
