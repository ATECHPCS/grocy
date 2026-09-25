@extends('layout.default')

@php
$grocyAiAssetVersion = '2.5.0';
@endphp
@push('pageStyles')
<link rel="stylesheet"
	href="{{ $U('/custom/grocy_AI/grocy-ai.css?v=', true) }}{{ $grocyAiAssetVersion }}">
@endpush
@push('pageScripts')
<script src="{{ $U('/custom/grocy_AI/capture-review.js?v=', true) }}{{ $grocyAiAssetVersion }}"></script>
@endpush

@section('title', $__t('Purchase capture review'))

@section('content')
<div class="row">
	<div class="col">
		<h2 class="title">
			<i class="fa-solid fa-clipboard-check"
				aria-hidden="true"></i>
			@yield('title')
		</h2>
	</div>
</div>

<hr class="my-2">

{{-- Trip review surface (CAP-03/CAP-04). capture-review.js reads the trip list and the selected trip from
     the STOCK_PURCHASE-gated /api/grocy-ai/capture/trips endpoints and persists lean per-line edits +
     trip defaults through the PUT endpoints. Best-before is never edited here (auto). Unknown lines link
     out to the MASTER_DATA_EDIT-gated /product/new enrichment flow. This template declares no write form
     of its own and never writes stock. --}}
<div class="row permission-STOCK_PURCHASE"
	id="grocyai-capture-review"
	data-trips-endpoint="{{ $U('/api/grocy-ai/capture/trips', true) }}"
	data-product-new-url="{{ $U('/product/new', true) }}"
	data-trip-id="{{ $tripId !== null ? $tripId : '' }}"
	data-label-known="{{ $__t('Known') }}"
	data-label-unknown="{{ $__t('Unknown — needs product') }}"
	data-label-product-fallback="{{ $__t('Product #%s', '%s') }}"
	data-label-create-product="{{ $__t('Create product') }}"
	data-label-no-trips="{{ $__t('No trips yet. Capture one first.') }}"
	data-label-no-trip-selected="{{ $__t('Select a trip to review its items.') }}"
	data-label-empty-lines="{{ $__t('This trip has no items.') }}"
	data-label-load-error="{{ $__t('Could not load the trip. Try again.') }}"
	data-label-save-error="{{ $__t('That change could not be saved. Try again.') }}"
	data-label-delete="{{ $__t('Delete') }}"
	data-label-selected="{{ $__t('Include in purchase') }}"
	data-label-quantity="{{ $__t('Quantity') }}"
	data-label-price="{{ $__t('Price (optional)') }}"
	data-label-location="{{ $__t('Default location') }}"
	data-label-store="{{ $__t('Default store') }}"
	data-label-none="{{ $__t('(none)') }}"
	data-label-mark-reviewing="{{ $__t('Mark as reviewing') }}"
	data-label-status="{{ $__t('Status') }}"
	data-label-commit="{{ $__t('Commit purchase') }}"
	data-label-committed="{{ $__t('Committed — transaction %s', '%s') }}"
	data-label-commit-partial="{{ $__t('Committed %s item(s); unresolved items remain in the trip.', '%s') }}"
	data-label-commit-mismatch="{{ $__t('The trip changed since it was loaded. Reload and commit again.') }}"
	data-label-commit-confirm="{{ $__t('Commit the selected known items to stock as one purchase?') }}">

	<div class="col-12 col-md-4">
		<h3>{{ $__t('Trips') }}</h3>
		<div class="list-group grocy-ai-capture-review-trips"
			id="grocyai-capture-review-trips"
			role="list"
			aria-label="{{ $__t('Trips') }}"></div>
	</div>
	<div class="col-12 col-md-8">
		<div class="grocy-ai-capture-review-detail"
			id="grocyai-capture-review-detail"
			aria-live="polite"></div>
	</div>
</div>
@stop
