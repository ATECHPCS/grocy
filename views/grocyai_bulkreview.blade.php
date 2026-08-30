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
	</div>
</div>
@stop
