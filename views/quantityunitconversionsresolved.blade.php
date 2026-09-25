@php require_frontend_packages(['datatables']); @endphp

@extends('layout.default')

@if(GROCY_FEATURE_FLAG_GROCY_AI)
@php
$grocyAiAssetVersion = '2.5.0';
@endphp
@push('pageStyles')
<link rel="stylesheet"
	href="{{ $U('/custom/grocy_AI/grocy-ai.css?v=', true) }}{{ $grocyAiAssetVersion }}">
@endpush
@push('pageScripts')
<script src="{{ $U('/custom/grocy_AI/conversion-explanations.js?v=', true) }}{{ $grocyAiAssetVersion }}"></script>
@endpush
@endif

@section('title', $__t('QU conversions resolved'))

@section('content')
<div class="row">
	<div class="col">
		<div class="title-related-links">
			<h2 class="title">
				@yield('title')<br>
				@if($product != null)
				<span class="text-muted font-italic small">{{ $__t('Product') }} <strong>{{ $product->name }}</strong></span>
				@endif
			</h2>
			<div class="float-right @if($embedded) pr-5 @endif">
				<button class="btn btn-outline-dark d-md-none mt-2"
					type="button"
					data-toggle="collapse"
					data-target="#table-filter-row">
					<i class="fa-solid fa-filter"></i>
				</button>
			</div>
		</div>
	</div>
</div>

<hr class="my-2">

<div class="row collapse d-md-flex"
	id="table-filter-row">
	<div class="col-12 col-md-6 col-xl-2">
		<div class="input-group">
			<div class="input-group-prepend">
				<span class="input-group-text"><i class="fa-solid fa-filter"></i>&nbsp;{{ $__t('Quantity unit') }}</span>
			</div>
			<select class="custom-control custom-select"
				id="quantity-unit-filter">
				<option value="all">{{ $__t('All') }}</option>
				@foreach($quantityUnits as $quantityUnit)
				<option value="{{ $quantityUnit->id }}">{{ $quantityUnit->name }}</option>
				@endforeach
			</select>
		</div>
	</div>
	<div class="col">
		<div class="float-right mt-1">
			<button id="clear-filter-button"
				class="btn btn-sm btn-outline-info"
				data-toggle="tooltip"
				title="{{ $__t('Clear filter') }}">
				<i class="fa-solid fa-filter-circle-xmark"></i>
			</button>
		</div>
	</div>
</div>

<div class="row">
	<div class="col">

		<div class="table-responsive">
		<table id="qu-conversions-resolved-table"
			class="table table-sm table-striped nowrap w-100">
			<thead>
				<tr>
					<th class="border-right"><a class="text-muted change-table-columns-visibility-button"
							data-toggle="tooltip"
							title="{{ $__t('Table options') }}"
							data-table-selector="#qu-conversions-resolved-table"
							href="#"><i class="fa-solid fa-eye"></i></a>
					</th>
					<th class="allow-grouping">{{ $__t('Quantity unit from') }}</th>
					<th class="allow-grouping">{{ $__t('Quantity unit to') }}</th>
					<th class="grocy-ai-resolved-factor-column">{{ $__t('Factor') }}</th>
					<th class="grocy-ai-resolved-prose-column"></th>
					@if(GROCY_FEATURE_FLAG_GROCY_AI)
					<th class="permission-MASTER_DATA_EDIT">{{ $__t('Source') }}</th>
					<th class="permission-MASTER_DATA_EDIT">{{ $__t('Status') }}</th>
					<th class="permission-MASTER_DATA_EDIT"></th>
					@endif
				</tr>
			</thead>
			<tbody class="d-none">
				@foreach($quantityUnitConversionsResolved as $quConversion)
				<tr @if(GROCY_FEATURE_FLAG_GROCY_AI && $product != null) data-grocy-ai-resolved-row="resolved-{{ $loop->index }}"
					data-product-id="{{ $product->id }}"
					data-from-qu-id="{{ $quConversion->from_qu_id }}"
					data-to-qu-id="{{ $quConversion->to_qu_id }}" @endif>
					<td class="fit-content border-right"></td>
					<td>
						{{ FindObjectInArrayByPropertyValue($quantityUnits, 'id', $quConversion->from_qu_id)->name }}
					</td>
					<td>
						{{ FindObjectInArrayByPropertyValue($quantityUnits, 'id', $quConversion->to_qu_id)->name }}
					</td>
					<td class="grocy-ai-resolved-factor-column"
						data-grocy-ai-resolved-factor>
						<span class="locale-number locale-number-quantity-amount">{{ $quConversion->factor }}</span>
					</td>
					<td class="font-italic grocy-ai-resolved-prose-column"
						data-grocy-ai-resolved-prose>
						{!! $__t('This means 1 %1$s is the same as %2$s %3$s', FindObjectInArrayByPropertyValue($quantityUnits, 'id', $quConversion->from_qu_id)->name, '<span class="locale-number locale-number-quantity-amount">' . $quConversion->factor . '</span>', $__n($quConversion->factor, FindObjectInArrayByPropertyValue($quantityUnits, 'id', $quConversion->to_qu_id)->name, FindObjectInArrayByPropertyValue($quantityUnits, 'id', $quConversion->to_qu_id)->name_plural, true)) !!}
					</td>
					@if(GROCY_FEATURE_FLAG_GROCY_AI)
					<td class="permission-MASTER_DATA_EDIT grocy-ai-resolved-source"
						data-grocy-ai-resolved-source></td>
					<td class="permission-MASTER_DATA_EDIT grocy-ai-resolved-status"
						data-grocy-ai-resolved-status></td>
					<td class="permission-MASTER_DATA_EDIT">
						<details class="grocy-ai-conversion-disclosure"
							data-grocy-ai-resolved-disclosure
							hidden>
							<summary aria-label="{{ $__t('Show conversion details') }} {{ FindObjectInArrayByPropertyValue($quantityUnits, 'id', $quConversion->from_qu_id)->name }} &rarr; {{ FindObjectInArrayByPropertyValue($quantityUnits, 'id', $quConversion->to_qu_id)->name }}">{{ $__t('Show conversion details') }}</summary>
							<dl class="grocy-ai-conversion-details grocy-ai-resolved-details"
								data-grocy-ai-resolved-details></dl>
						</details>
					</td>
					@endif
				</tr>
				@endforeach
			</tbody>
		</table>
		</div>

	</div>
</div>
@stop
