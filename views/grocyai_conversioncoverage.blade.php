@extends('layout.default')

@php
$grocyAiAssetVersion = '2.5.0';
@endphp
@push('pageStyles')
<link rel="stylesheet"
	href="{{ $U('/custom/grocy_AI/grocy-ai.css?v=', true) }}{{ $grocyAiAssetVersion }}">
@endpush
@push('pageScripts')
<script src="{{ $U('/custom/grocy_AI/conversion-coverage.js?v=', true) }}{{ $grocyAiAssetVersion }}"></script>
@endpush

@section('title', $__t('Conversion coverage'))

@section('content')
<div class="row">
	<div class="col">
		<h2 class="title">
			<i class="fa-solid fa-scale-balanced"
				aria-hidden="true"></i>
			@yield('title')
		</h2>
	</div>
</div>

<hr class="my-2">

<div class="row permission-MASTER_DATA_EDIT"
	id="grocy-ai-conversion-coverage"
	data-report="{{ $reportAvailable ? json_encode($report, JSON_THROW_ON_ERROR) : '' }}">
	<div class="col">
		<div class="grocy-ai-coverage-summary"
			id="grocy-ai-coverage-summary"
			role="status"
			aria-live="polite"
			data-grocy-ai-coverage-summary></div>

		<div class="grocy-ai-actions">
			<button class="btn btn-outline-primary"
				type="button"
				id="grocy-ai-coverage-refresh">
				<i class="fa-solid fa-rotate"
					aria-hidden="true"></i>
				{{ $__t('Refresh validation report') }}
			</button>
		</div>

		<section class="grocy-ai-coverage-section"
			aria-labelledby="grocy-ai-coverage-blockers-heading">
			<h3 id="grocy-ai-coverage-blockers-heading"
				tabindex="-1">{{ $__t('Blocking issues') }}</h3>
			<dl class="grocy-ai-coverage-list"
				data-grocy-ai-coverage-blockers></dl>
		</section>

		<section class="grocy-ai-coverage-section"
			aria-labelledby="grocy-ai-coverage-coverage-heading">
			<h3 id="grocy-ai-coverage-coverage-heading">{{ $__t('Coverage and missing paths') }}</h3>
			<dl class="grocy-ai-coverage-list"
				data-grocy-ai-coverage-counts></dl>
		</section>

		<section class="grocy-ai-coverage-section"
			aria-labelledby="grocy-ai-coverage-sources-heading">
			<h3 id="grocy-ai-coverage-sources-heading">{{ $__t('Effective sources') }}</h3>
			<dl class="grocy-ai-coverage-list"
				data-grocy-ai-coverage-sources></dl>
		</section>

		<section class="grocy-ai-coverage-section"
			aria-labelledby="grocy-ai-coverage-redundant-heading">
			<h3 id="grocy-ai-coverage-redundant-heading">{{ $__t('Redundant product overrides') }}</h3>
			<dl class="grocy-ai-coverage-list"
				data-grocy-ai-coverage-redundant></dl>
		</section>

		<section class="grocy-ai-coverage-section"
			aria-labelledby="grocy-ai-coverage-protected-heading">
			<h3 id="grocy-ai-coverage-protected-heading">{{ $__t('Characterization and protected behavior') }}</h3>
			<dl class="grocy-ai-coverage-list"
				data-grocy-ai-coverage-protected></dl>
		</section>
	</div>
</div>
@stop
