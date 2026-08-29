@extends('layout.default')

@if($mode == 'edit')
@section('title', $__t('Edit QU conversion'))
@else
@section('title', $__t('Create QU conversion'))
@endif

@push('pageStyles')
<style>
	#qu-conversion-validation {
		margin-top: 16px;
		margin-bottom: 16px;
		padding: 16px;
	}
	#qu-conversion-validation-heading {
		font-size: 20px;
		font-weight: 500;
		line-height: 1.2;
	}
	#qu-conversion-validation-metadata {
		font-size: 14px;
		line-height: 1.5;
		overflow-wrap: anywhere;
	}
	#qu-conversion-validation-pair,
	#qu-conversion-validation-source {
		font-variant-numeric: tabular-nums;
	}
	#validate-quconversion-impact-button,
	#save-quconversion-button {
		min-height: 44px;
	}
	#validate-quconversion-impact-button:focus,
	#qu-conversion-validation-heading:focus {
		outline: 2px solid var(--primary);
		outline-offset: 2px;
	}
	@media (max-width: 575.98px) {
		#validate-quconversion-impact-button {
			width: 100%;
		}
	}
</style>
@endpush

@section('content')
<div class="row">
	<div class="col">
		<div class="title-related-links">
			<h2 class="title">
				@yield('title')<br>
				@if($product != null)
				<span class="text-muted small">{{ $__t('Override for product') }} <strong>{{ $product->name }}</strong></span>
				@else
				<span class="text-muted small">{{ $__t('Default for QU') }} <strong>{{ $defaultQuUnit->name }}</strong></span>
				@endif
			</h2>
		</div>
	</div>
</div>

<hr class="my-2">

<div class="row">
	<div class="col-lg-6 col-12">

		<script>
			Grocy.EditMode = '{{ $mode }}';
		</script>

		@if($mode == 'edit')
		<script>
			Grocy.EditObjectId = {{ $quConversion->id }};
		</script>
		@endif

		<form id="quconversion-form"
			novalidate>

			@if($product != null)
			<input type="hidden"
				name="product_id"
				value="{{ $product->id }}">
			@endif

			<div class="form-group">
				<label for="from_qu_id">{{ $__t('Quantity unit from') }}</label>
				<select required
					class="custom-control custom-select input-group-qu"
					id="from_qu_id"
					name="from_qu_id">
					<option></option>
					@foreach($quantityunits as $quantityunit)
					@php
					$selected = false;
					if ($mode == 'edit')
					{
					if ($quantityunit->id == $quConversion->from_qu_id)
					{
					$selected = true;
					}
					}
					else
					{
					if ($product != null && $quantityunit->id == $product->qu_id_stock)
					{
					$selected = true;
					}
					else
					{
					if ($quantityunit->id == $defaultQuUnit->id)
					{
					$selected = true;
					}
					}
					}
					@endphp
					<option @if($selected)
						selected="selected"
						@endif
						value="{{ $quantityunit->id }}"
						data-plural-form="{{ $quantityunit->name_plural }}">{{ $quantityunit->name }}</option>
					@endforeach
				</select>
				<div class="invalid-feedback">{{ $__t('A quantity unit is required') }}</div>
			</div>

			<div class="form-group">
				<label for="to_qu_id">{{ $__t('Quantity unit to') }}</label>
				<select required
					class="custom-control custom-select input-group-qu"
					id="to_qu_id"
					name="to_qu_id">
					<option></option>
					@foreach($quantityunits as $quantityunit)
					<option @if($mode=='edit'
						&&
						$quantityunit->id == $quConversion->to_qu_id) selected="selected" @endif value="{{ $quantityunit->id }}" data-plural-form="{{ $quantityunit->name_plural }}">{{ $quantityunit->name }}</option>
					@endforeach
				</select>
				<div class="invalid-feedback">{{ $__t('A quantity unit is required') }}</div>
			</div>

			@php if($mode == 'edit') { $value = $quConversion->factor; } else { $value = 1; } @endphp
			@include('components.numberpicker', array(
			'id' => 'factor',
			'label' => 'Factor',
			'min' => $DEFAULT_MIN_AMOUNT,
			'decimals' => $userSettings['stock_decimal_places_amounts'],
			'value' => $value,
			'additionalHtmlElements' => '<p id="qu-conversion-info"
				class="form-text text-info d-none mb-0"></p>
			<p id="qu-conversion-inverse-info"
				class="form-text text-info d-none"></p>',
			'additionalCssClasses' => 'input-group-qu locale-number-input locale-number-quantity-amount'
			))

			<section id="qu-conversion-validation"
				class="border rounded"
				aria-labelledby="qu-conversion-validation-heading"
				data-incomplete="{{ $__t('Choose both quantity units and a positive factor to validate this conversion.') }}"
				data-initial="{{ $__t('Validate this conversion before saving.') }}"
				data-stale="{{ $__t('This validation is out of date. Validate the current conversion before saving.') }}"
				data-pending="{{ $__t('Validating conversion impact…') }}"
				data-impact-clear="{{ $__t('No blocking paths, cycles, reciprocal conflicts, or tolerance failures were found.') }}"
				data-request-failure="{{ $__t('This conversion could not be validated. Correct any visible fields or try again. Nothing was changed.') }}"
				data-cross-dimension="{{ $__t('Mass and volume cannot be used in one universal conversion. Use an explicitly assigned food-type profile or a measured product conversion instead.') }}"
				data-ineligible-pair="{{ $__t('This quantity-unit pair is not eligible for a reusable default. Keep package and count conversions on the product.') }}"
				data-generic-blocker="{{ $__t('This conversion is blocked by its current factor or conversion graph. Correct the values and validate again.') }}"
				data-product-helper="{{ $__t('This conversion takes precedence over any food-type profile and universal default.') }}"
				data-inactive-gate="{{ $__t('Reusable conversion profiles are inactive until both branch checks pass.') }}"
				data-heading="{{ $__t('Reusable conversion validation') }}"
				data-validate-label="{{ $__t('Validate conversion impact') }}"
				data-save-label="{{ $__t('Save conversion') }}"
				data-validation-required-label="{{ $__t('Validation required') }}"
				data-out-of-date-label="{{ $__t('Out of date') }}"
				data-validating-label="{{ $__t('Validating') }}"
				data-product-override-label="{{ $__t('Product override') }}"
				data-inactive-label="{{ $__t('Inactive — not saved or active') }}"
				data-blocked-label="{{ $__t('Blocked') }}"
				data-unavailable-label="{{ $__t('Validation unavailable') }}"
				data-dimension-label="{{ $__t('Dimension: %s') }}"
				data-source-label="{{ $__t('Source: NIST SP 811 · %s') }}">
				<h3 id="qu-conversion-validation-heading" tabindex="-1">{{ $__t('Reusable conversion validation') }}</h3>
				<div id="qu-conversion-validation-status"
					class="alert alert-secondary mb-0"
					role="status"
					aria-live="polite"
					aria-atomic="true"
					aria-busy="false">
					<div id="qu-conversion-validation-label"></div>
					<div id="qu-conversion-validation-message"></div>
					<div id="qu-conversion-validation-metadata">
						<div id="qu-conversion-validation-dimension"></div>
						<div id="qu-conversion-validation-pair"></div>
						<div id="qu-conversion-validation-source"></div>
						<div id="qu-conversion-validation-impact"></div>
					</div>
				</div>
				<button id="validate-quconversion-impact-button"
					class="btn btn-primary mt-3"
					type="button">{{ $__t('Validate conversion impact') }}</button>
			</section>

			@include('components.userfieldsform', array(
			'userfields' => $userfields,
			'entity' => 'quantity_unit_conversions'
			))

			<button id="save-quconversion-button"
				class="btn btn-success"
				disabled>{{ $__t('Save conversion') }}</button>

		</form>
	</div>
</div>
@stop
