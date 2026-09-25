@extends('layout.default')

@php
$grocyAiAssetVersion = '2.5.0';
@endphp
@push('pageStyles')
<link rel="stylesheet"
	href="{{ $U('/custom/grocy_AI/grocy-ai.css?v=', true) }}{{ $grocyAiAssetVersion }}">
@endpush
@push('pageScripts')
<script src="{{ $U('/custom/grocy_AI/capture.js?v=', true) }}{{ $grocyAiAssetVersion }}"></script>
@endpush

@include('components.camerabarcodescanner')

@section('title', $__t('Purchase capture'))

@section('content')
<div class="row">
	<div class="col">
		<h2 class="title">
			<i class="fa-solid fa-cart-shopping"
				aria-hidden="true"></i>
			@yield('title')
		</h2>
	</div>
</div>

<hr class="my-2">

{{-- Mobile scan-loop surface (CAP-01/CAP-02). capture.js starts a trip and appends each scan through the
     STOCK_PURCHASE-gated /api/grocy-ai/capture/trips endpoints; this page declares no write form of its own
     and never writes stock. Dynamic user-facing strings are passed as data-* attributes so $__t localizes
     them and capture.js reads them (mirrors the enrichment card's localized() pattern). --}}
<div class="row permission-STOCK_PURCHASE"
	id="grocyai-capture"
	data-trips-endpoint="{{ $U('/api/grocy-ai/capture/trips', true) }}"
	data-products-endpoint="{{ $U('/api/objects/products', true) }}"
	data-label-known="{{ $__t('Known') }}"
	data-label-unknown="{{ $__t('Unknown — needs product') }}"
	data-label-product-fallback="{{ $__t('Product #%s') }}"
	data-label-trip-started="{{ $__t('Trip started. Scan or enter a GTIN to add items.') }}"
	data-label-trip-error="{{ $__t('Could not start a capture trip. Reload the page to try again.') }}"
	data-label-scan-error="{{ $__t('That scan could not be added. Try again.') }}"
	data-label-empty="{{ $__t('No items yet. Scan or enter a GTIN above.') }}"
	data-label-quantity="{{ $__t('Quantity') }}">
	<div class="col">
		<section class="grocy-ai-capture-scan-section"
			aria-labelledby="grocyai-capture-scan-heading">
			<h3 id="grocyai-capture-scan-heading">{{ $__t('Scan') }}</h3>
			<div class="form-group mb-2">
				<label for="grocyai-capture-barcode">{{ $__t('GTIN') }}</label>
				<input type="text"
					class="form-control form-control-lg barcodescanner-input"
					id="grocyai-capture-barcode"
					inputmode="numeric"
					autocomplete="off"
					enterkeyhint="done"
					placeholder="{{ $__t('8, 12, 13, or 14 digits') }}"
					data-target="grocyai-capture-barcode">
			</div>
			<div class="grocy-ai-actions">
				<button type="button"
					class="btn btn-primary btn-lg"
					id="grocyai-capture-add-button">
					<i class="fa-solid fa-plus" aria-hidden="true"></i> {{ $__t('Add') }}
				</button>
				<button type="button"
					class="btn btn-outline-secondary btn-lg"
					id="grocyai-capture-new-trip-button">
					<i class="fa-solid fa-rotate" aria-hidden="true"></i> {{ $__t('Start new trip') }}
				</button>
			</div>
			<div class="grocy-ai-capture-status alert alert-secondary mt-3 mb-0"
				id="grocyai-capture-status"
				role="status"
				aria-live="polite"
				aria-atomic="true"></div>
		</section>

		<section class="grocy-ai-capture-lines-section mt-3"
			aria-labelledby="grocyai-capture-lines-heading">
			<h3 id="grocyai-capture-lines-heading">{{ $__t('Captured items') }}</h3>
			<ul class="list-group grocy-ai-capture-lines"
				id="grocyai-capture-lines"
				aria-live="polite"
				aria-labelledby="grocyai-capture-lines-heading"></ul>
		</section>
	</div>
</div>
@stop
