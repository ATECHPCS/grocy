'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const capture = require('./capture.js');

function line(overrides = {})
{
	return Object.assign({
		id: 1,
		trip_id: 1,
		seq: 1,
		scanned_barcode: '012345678905',
		canonical_gtin: '00012345678905',
		resolved_product_id: 101,
		status: 'known',
		quantity: 1,
		price: null,
		best_before_override: null,
		selected: 1,
		applied_at: null,
		outcome: null,
		created_at: '2026-09-02 12:00:00',
		updated_at: '2026-09-02 12:00:00'
	}, overrides);
}

test('isLinePayload accepts the closed line DTO and rejects malformed shapes', function ()
{
	assert.equal(capture.isLinePayload(line()), true);
	assert.equal(capture.isLinePayload(line({ status: 'unknown', resolved_product_id: null })), true);

	// Missing key, extra key, out-of-vocabulary status, and non-objects are all rejected.
	const missing = line();
	delete missing.updated_at;
	assert.equal(capture.isLinePayload(missing), false);
	assert.equal(capture.isLinePayload(Object.assign(line(), { extra: 1 })), false);
	assert.equal(capture.isLinePayload(line({ status: 'applied' })), false);
	assert.equal(capture.isLinePayload(null), false);
	assert.equal(capture.isLinePayload([]), false);
});

test('upsertLines coalesces a repeated line id and appends distinct lines in seq order', function ()
{
	let lines = [];
	lines = capture.upsertLines(lines, line({ id: 1, seq: 1, quantity: 1 }));
	lines = capture.upsertLines(lines, line({ id: 2, seq: 2, status: 'unknown', resolved_product_id: null }));
	assert.equal(lines.length, 2);

	// A same-id return (the server incremented the coalesced line) replaces in place, not appends.
	lines = capture.upsertLines(lines, line({ id: 1, seq: 1, quantity: 2 }));
	assert.equal(lines.length, 2);
	assert.equal(Number(lines[0].quantity), 2);
	assert.ok(Number(lines[0].seq) < Number(lines[1].seq));
});

test('lineLabel shows the product name for known lines and the needs-product prompt otherwise', function ()
{
	const copy = capture.DEFAULT_COPY;
	assert.deepEqual(capture.lineLabel(line({ status: 'known', resolved_product_id: 101 }), 'Milk', copy), { status: 'known', text: 'Milk' });
	assert.deepEqual(capture.lineLabel(line({ status: 'known', resolved_product_id: 101 }), null, copy), { status: 'known', text: 'Product #101' });
	assert.deepEqual(capture.lineLabel(line({ status: 'unknown', resolved_product_id: null }), null, copy), { status: 'unknown', text: copy.unknown });
});

test('describeQuantity renders finite numbers and falls back to 1', function ()
{
	assert.equal(capture.describeQuantity(3), '3');
	assert.equal(capture.describeQuantity('2'), '2');
	assert.equal(capture.describeQuantity('not-a-number'), '1');
});
