'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const review = require('./capture-review.js');

function trip(overrides = {})
{
	return Object.assign({
		id: 1,
		created_at: '2026-09-02 12:00:00',
		created_by: '1',
		status: 'open',
		default_location_id: null,
		default_shopping_location_id: null,
		transaction_id: null,
		committed_at: null,
		checksum: null,
		module_version: '2.5.0'
	}, overrides);
}

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

test('isTripListPayload requires closed trip DTOs and a valid status vocabulary', function ()
{
	assert.equal(review.isTripListPayload({ trips: [trip(), trip({ id: 2, status: 'reviewing' })] }), true);
	assert.equal(review.isTripListPayload({ trips: [trip({ status: 'archived' })] }), false);
	assert.equal(review.isTripListPayload({ trips: [Object.assign(trip(), { extra: 1 })] }), false);
	assert.equal(review.isTripListPayload({}), false);
	assert.equal(review.isTripListPayload({ trips: 'x' }), false);
});

test('isLoadedTripPayload requires {trip, lines} with closed shapes', function ()
{
	assert.equal(review.isLoadedTripPayload({ trip: trip(), lines: [line(), line({ id: 2, seq: 2, status: 'unknown', resolved_product_id: null })] }), true);
	// The trip GET carries the current commit checksum alongside {trip, lines}; the extra key is accepted.
	assert.equal(review.isLoadedTripPayload({ trip: trip(), lines: [line()], checksum: 'a'.repeat(64) }), true);
	assert.equal(review.isLoadedTripPayload({ trip: trip(), lines: [Object.assign(line(), { extra: 1 })] }), false);
	assert.equal(review.isLoadedTripPayload({ trip: trip() }), false);
	assert.equal(review.isLoadedTripPayload({ lines: [] }), false);
});

test('productNewUrl prefills the scanned barcode as a query param', function ()
{
	assert.equal(review.productNewUrl('/product/new', '012345678905'), '/product/new?barcode=012345678905');
});

test('lineLabel shows product name for known, needs-product prompt otherwise', function ()
{
	const copy = review.DEFAULT_COPY;
	assert.deepEqual(review.lineLabel(line({ status: 'known', resolved_product_id: 101 }), 'Milk', copy), { status: 'known', text: 'Milk' });
	assert.deepEqual(review.lineLabel(line({ status: 'known', resolved_product_id: 101 }), null, copy), { status: 'known', text: 'Product #101' });
	assert.deepEqual(review.lineLabel(line({ status: 'unknown', resolved_product_id: null }), null, copy), { status: 'unknown', text: copy.unknown });
	assert.deepEqual(review.lineLabel(line({ status: 'conflict', resolved_product_id: null }), null, copy), { status: 'conflict', text: copy.unknown });
});
