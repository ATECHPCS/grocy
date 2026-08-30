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

{{-- Placeholder review surface. The per-item select/reject controls and the selected-diff markup are
     built by the UI Designer against the MASTER_DATA_EDIT-gated read endpoints. This container carries
     only the server-owned plan id and the read endpoint base; it declares no write action. --}}
<div class="row permission-MASTER_DATA_EDIT"
	id="grocy-ai-bulk-review"
	data-plan-id="{{ $planId !== null ? $planId : '' }}"
	data-plans-endpoint="{{ $U('/api/grocy-ai/bulk/plans', true) }}">
	<div class="col">
		<div class="grocy-ai-bulk-summary"
			id="grocy-ai-bulk-summary"
			role="status"
			aria-live="polite"></div>
		<div class="grocy-ai-bulk-items"
			id="grocy-ai-bulk-items"></div>
		<div class="grocy-ai-bulk-selected-diff"
			id="grocy-ai-bulk-selected-diff"></div>
	</div>
</div>
@stop
