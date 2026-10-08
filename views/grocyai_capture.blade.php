@extends('layout.default')

@php
$grocyAiAssetVersion = '2.6.8';
@endphp
@push('pageStyles')
<link rel="stylesheet"
	href="{{ $U('/custom/grocy_AI/grocy-ai.css?v=', true) }}{{ $grocyAiAssetVersion }}">
@endpush
@push('pageScripts')
<script src="{{ $U('/custom/grocy_AI/capture.js?v=', true) }}{{ $grocyAiAssetVersion }}">
			</script>
@endpush

@include('components.camerabarcodescanner')

@section('title', $__t('Purchase capture'))

@section('content')
<div class="row">
	<div class="col">
		<h2 class="title">
			<i class="fa-solid fa-cart-shopping"
				aria-hidden="true">
			</i>
			@yield('title')
		</h2>
	</div>
</div>

<hr class="my-2">

{{-- Mobile scan-loop surface (CAP-01/CAP-02). capture.js starts a trip and appends each scan through the
     STOCK_PURCHASE-gated /api/grocy-ai/capture/trips endpoints; this page declares no write form of its own
     and never writes stock. Dynamic user-facing strings are passed as data-* attributes so $__t localizes
     them and capture.js reads them (mirrors the enrichment card's localized() pattern). --}}
<div class="row permission-STOCK_PURCHASE grocy-ai-purchase-flow"
	id="grocyai-capture"
	data-trips-endpoint="{{ $U('/api/grocy-ai/capture/trips', true) }}"
	data-default-trip-id="{{ $defaultTripId !== null ? $defaultTripId : '' }}"
	data-products-endpoint="{{ $U('/api/objects/products', true) }}"
	data-label-known="{{ $__t('Known') }}"
	data-label-unknown="{{ $__t('Unknown — needs product') }}"
	data-label-product-fallback="{{ $__t('Product #%s', '%s') }}"
	data-label-trip-started="{{ $__t('Trip started. Scan or enter a GTIN to add items.') }}"
	data-label-trip-resumed="{{ $__t('Trip #%s resumed. Scan or enter a GTIN to add items.', '%s') }}"
	data-label-resume-error="{{ $__t('This trip cannot be resumed. Open Review trip or start a new trip.') }}"
	data-label-trip-error="{{ $__t('Could not start a capture trip. Reload the page to try again.') }}"
	data-label-scan-error="{{ $__t('That scan could not be added. Try again.') }}"
	data-label-finish-first="{{ $__t('Finish scanning this trip and open review? This will not change stock.') }}"
	data-label-finish-second="{{ $__t('Confirm again: Are you finished scanning this trip?') }}"
	data-label-finish-error="{{ $__t('Could not finish this trip. Your scans are saved; try again.') }}"
	data-label-finish-pending="{{ $__t('Wait for the current scan to finish, then try again.') }}"
	data-label-camera-pending="{{ $__t('Confirm or cancel the scanned UPC before finishing.') }}"
	data-label-camera-unverified="{{ $__t('Scan outcome could not be verified. Reload this page and review the trip before scanning again.') }}"
	data-label-barcode="{{ $__t('UPC') }}"
	data-label-empty="{{ $__t('No items yet. Scan or enter a GTIN above.') }}"
	data-label-scan-barcode="{{ $__t('Scan barcode') }}"
	data-label-distinct-items="{{ $__t('distinct items') }}"
	data-label-total-quantity="{{ $__t('Total quantity') }}"
	data-label-saved="{{ $__t('Saved') }}"
	data-label-needs-details="{{ $__t('Needs details') }}"
	data-label-show-all="{{ $__t('Show all items') }}"
	data-label-show-recent="{{ $__t('Show recent items') }}"
	data-label-trip="{{ $__t('Trip') }}"
	data-label-delete-first="{{ $__t('Delete this trip from the active list? Receipt and scan history will be kept for audit.') }}"
	data-label-delete-second="{{ $__t('Final confirmation: delete this trip? This cannot be undone from this screen.') }}"
	data-label-delete-error="{{ $__t('The trip could not be deleted. Reload it and try again.') }}"
	data-label-deleted="{{ $__t('Trip deleted. Start a new trip to scan more items.') }}"
	data-label-quantity="{{ $__t('Quantity') }}">
	<div class="col">
		<section class="grocy-ai-flow-card">
			<h3>{{ $__t('Current trip') }}</h3>
			<div id="grocyai-capture-trip-summary" role="status" aria-live="polite"></div>
			<a class="btn btn-outline-primary" id="grocyai-capture-header-review-link" href="{{ $U('/grocyai/capture/review') }}">{{ $__t('Review trip') }}</a>
			<details id="grocyai-capture-trip-actions">
				<summary>{{ $__t('Trip actions') }}</summary>
				<div class="grocy-ai-flow-actions">
					<button type="button"
						class="btn btn-outline-secondary btn-lg"
						id="grocyai-capture-new-trip-button">
						<i class="fa-solid fa-rotate" aria-hidden="true"></i> {{ $__t('Start new trip') }}
					</button>
					<button type="button" class="btn btn-outline-danger" id="grocyai-capture-delete-trip-button">{{ $__t('Delete trip') }}</button>
				</div>
			</details>
		</section>
		<section class="grocy-ai-capture-scan-section grocy-ai-flow-card"
			aria-labelledby="grocyai-capture-scan-heading">
			<h3 id="grocyai-capture-scan-heading">{{ $__t('Scan') }}</h3>
			<div class="form-group mb-2">
				<div class="grocy-ai-capture-input-row">
					<label for="grocyai-capture-barcode">{{ $__t('Enter UPC manually') }}</label>
					<input type="text"
						class="form-control form-control-lg barcodescanner-input"
						id="grocyai-capture-barcode"
						inputmode="numeric"
						autocomplete="off"
						enterkeyhint="done"
						placeholder="{{ $__t('8, 12, 13, or 14 digits') }}"
						data-target="grocyai-capture-barcode">
				</div>
			</div>
			<div id="grocyai-capture-camera-confirmation" class="grocy-ai-capture-camera-confirmation alert alert-secondary mb-2" hidden>
				<label for="grocyai-capture-camera-barcode">{{ $__t('Confirm scanned UPC or edit it') }}</label>
				<input type="text" class="form-control form-control-lg" id="grocyai-capture-camera-barcode"
					inputmode="numeric" autocomplete="off" enterkeyhint="done">
				<div class="grocy-ai-actions grocy-ai-flow-actions mt-2">
					<button type="button" class="btn btn-primary btn-lg" id="grocyai-capture-camera-save-button">{{ $__t('Add scanned item') }}</button>
					<button type="button" class="btn btn-outline-secondary btn-lg" id="grocyai-capture-camera-cancel-button">{{ $__t('Cancel scan') }}</button>
				</div>
			</div>
			<div class="grocy-ai-actions grocy-ai-flow-actions">
				<button type="button"
					class="btn btn-primary btn-lg"
					id="grocyai-capture-add-button">
					<i class="fa-solid fa-plus" aria-hidden="true"></i> {{ $__t('Add') }}
				</button>
			</div>
			<div class="grocy-ai-capture-status alert alert-secondary mt-3 mb-0"
				id="grocyai-capture-status"
				role="status"
				aria-live="polite"
				aria-atomic="true"></div>
		</section>
		<section class="grocy-ai-flow-card">
			<h3>{{ $__t('Latest scanned item') }}</h3>
			<div id="grocyai-capture-latest" aria-live="polite"></div>
		</section>
		<section class="grocy-ai-capture-lines-section grocy-ai-flow-card mt-3"
			aria-labelledby="grocyai-capture-lines-heading">
			<h3 id="grocyai-capture-lines-heading">{{ $__t('Captured items') }}</h3>
			<ul class="list-group grocy-ai-capture-lines"
				id="grocyai-capture-lines"
				aria-live="polite"
				aria-labelledby="grocyai-capture-lines-heading"></ul>
			<button type="button" class="btn btn-outline-secondary" id="grocyai-capture-show-all" aria-expanded="false" aria-controls="grocyai-capture-lines">{{ $__t('Show all items') }}</button>
		</section>
		<footer class="grocy-ai-flow-footer grocy-ai-flow-actions">
			<button type="button"
				class="btn btn-outline-primary btn-lg"
				id="grocyai-capture-finish-button">
				<i class="fa-solid fa-check-double" aria-hidden="true"></i> {{ $__t('Finish scanning') }}
			</button>
			<a class="btn btn-primary btn-lg"
				id="grocyai-capture-review-link"
				href="{{ $U('/grocyai/capture/review') }}">
				<i class="fa-solid fa-clipboard-check" aria-hidden="true"></i> {{ $__t('Review trip') }}
			</a>
		</footer>
	</div>
</div>
@stop
