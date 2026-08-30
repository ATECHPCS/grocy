@extends('layout.default')

@php
$grocyAiAssetVersion = '2.5.0';
@endphp
@push('pageStyles')
<link rel="stylesheet"
	href="{{ $U('/custom/grocy_AI/grocy-ai.css?v=', true) }}{{ $grocyAiAssetVersion }}">
@endpush
@push('pageScripts')
<script src="{{ $U('/custom/grocy_AI/bulk-review.js?v=', true) }}{{ $grocyAiAssetVersion }}"></script>
@endpush

@section('title', $__t('Bulk plan review'))

@section('content')
<div class="row">
	<div class="col">
		<h2 class="title">
			<i class="fa-solid fa-list-check"
				aria-hidden="true"></i>
			@yield('title')
		</h2>
	</div>
</div>

<hr class="my-2">

{{-- Read-only bulk review surface. All selection state is read from and written to the
     MASTER_DATA_EDIT-gated /api/grocy-ai/bulk/plans endpoints by bulk-review.js; this template
     declares no POST/PUT/DELETE form action of its own. --}}
<div class="row permission-MASTER_DATA_EDIT"
	id="grocy-ai-bulk-review"
	data-plan-id="{{ $planId !== null ? $planId : '' }}"
	data-plans-endpoint="{{ $U('/api/grocy-ai/bulk/plans', true) }}">
	<div class="col">
		<section class="grocy-ai-bulk-summary-section"
			aria-labelledby="grocy-ai-bulk-summary-heading">
			<h3 id="grocy-ai-bulk-summary-heading">{{ $__t('Plan summary') }}</h3>
			<div class="grocy-ai-bulk-summary"
				id="grocy-ai-bulk-summary"
				role="status"
				aria-live="polite"
				aria-labelledby="grocy-ai-bulk-summary-heading"></div>
		</section>

		<section class="grocy-ai-bulk-items-section"
			aria-labelledby="grocy-ai-bulk-items-heading">
			<h3 id="grocy-ai-bulk-items-heading">{{ $__t('Plan items') }}</h3>
			<div class="grocy-ai-bulk-items"
				id="grocy-ai-bulk-items"
				aria-labelledby="grocy-ai-bulk-items-heading"></div>
		</section>

		<section class="grocy-ai-bulk-diff-section"
			aria-labelledby="grocy-ai-bulk-selected-diff-heading">
			<h3 id="grocy-ai-bulk-selected-diff-heading">{{ $__t('Selected diff') }}</h3>
			<div class="grocy-ai-bulk-selected-diff"
				id="grocy-ai-bulk-selected-diff"
				role="status"
				aria-live="polite"
				aria-labelledby="grocy-ai-bulk-selected-diff-heading"></div>
		</section>

		{{-- Durable mutation (D-13): bulk-review.js requires an explicit confirm before this ever fires,
		     and binds the write to exactly this page's own last-loaded plan checksum — never a value this
		     markup could submit on its own. There is no <form> action here; the click handler is the sole
		     trigger and it always goes through the MASTER_DATA_EDIT-gated apply endpoint. --}}
		<section class="grocy-ai-bulk-apply-section"
			aria-labelledby="grocy-ai-bulk-apply-heading">
			<h3 id="grocy-ai-bulk-apply-heading">{{ $__t('Apply') }}</h3>
			<div class="grocy-ai-actions">
				<button type="button"
					class="btn btn-primary"
					id="grocy-ai-bulk-apply-button">{{ $__t('Apply plan') }}</button>
			</div>
			<div class="grocy-ai-bulk-apply-result"
				id="grocy-ai-bulk-apply-result"
				role="status"
				aria-live="polite"></div>
		</section>

		{{-- Rollback preview (BULK-09) is a zero-write read; loading it never mutates anything. The
		     rollback-execute button below reuses the same explicit-confirm + bound-checksum discipline as
		     apply, and is bound to THIS preview's own checksum, so it cannot fire before a fresh preview
		     has been loaded. --}}
		<section class="grocy-ai-bulk-rollback-section"
			aria-labelledby="grocy-ai-bulk-rollback-heading">
			<h3 id="grocy-ai-bulk-rollback-heading">{{ $__t('Rollback') }}</h3>
			<div class="grocy-ai-actions">
				<button type="button"
					class="btn btn-secondary"
					id="grocy-ai-bulk-rollback-preview-button">{{ $__t('Load rollback preview') }}</button>
				<button type="button"
					class="btn btn-danger"
					id="grocy-ai-bulk-rollback-button">{{ $__t('Roll back reversible items') }}</button>
			</div>
			<div class="grocy-ai-bulk-rollback-preview"
				id="grocy-ai-bulk-rollback-preview"
				role="status"
				aria-live="polite"
				aria-labelledby="grocy-ai-bulk-rollback-heading"></div>
			<div class="grocy-ai-bulk-rollback-result"
				id="grocy-ai-bulk-rollback-result"
				role="status"
				aria-live="polite"></div>
		</section>

		{{-- Export (BULK-10, D-12): plain same-origin GET links to the zero-write, permission-checked
		     export read. The file is explicitly non-authoritative recovery evidence — it offers no
		     re-import path, so downloading it can never become a back-door write. --}}
		<section class="grocy-ai-bulk-export-section"
			aria-labelledby="grocy-ai-bulk-export-heading">
			<h3 id="grocy-ai-bulk-export-heading">{{ $__t('Export') }}</h3>
			<p class="grocy-ai-bulk-export-note">{{ $__t('Non-authoritative recovery snapshot for independent review. It cannot be re-imported to change data.') }}</p>
			<div class="grocy-ai-actions">
				<a class="btn btn-outline-secondary"
					id="grocy-ai-bulk-export-json"
					download>{{ $__t('Download JSON (non-authoritative)') }}</a>
				<a class="btn btn-outline-secondary"
					id="grocy-ai-bulk-export-csv"
					download>{{ $__t('Download CSV (non-authoritative)') }}</a>
			</div>
		</section>
	</div>
</div>
@stop
