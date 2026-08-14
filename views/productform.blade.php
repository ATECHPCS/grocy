@php require_frontend_packages(['datatables', 'summernote']); @endphp

@extends('layout.default')

@if(GROCY_FEATURE_FLAG_GROCY_AI)
@php
$grocyAiAssetVersion = '2.4.0';
@endphp
@push('pageStyles')
<link rel="stylesheet"
	href="{{ $U('/custom/grocy_AI/grocy-ai.css?v=', true) }}{{ $grocyAiAssetVersion }}">
@endpush
@push('pageScripts')
<script src="{{ $U('/custom/grocy_AI/product-enrichment.js?v=', true) }}{{ $grocyAiAssetVersion }}"></script>
@endpush
@endif

@if($mode == 'edit')
@section('title', $__t('Edit product'))
@else
@section('title', $__t('Create product'))
@endif

@section('content')
<div class="row">
	<div class="col">
		<div class="title-related-links">
			<h2 class="title">@yield('title')</h2>
			@if($mode == 'edit')
			<div class="float-right">
				<button class="btn btn-outline-dark d-md-none mt-2 order-1 order-md-3"
					type="button"
					data-toggle="collapse"
					data-target="#related-links">
					<i class="fa-solid fa-ellipsis-v"></i>
				</button>
			</div>
			<div class="related-links collapse d-md-flex order-2 width-xs-sm-100"
				id="related-links">
				<a class="btn btn-outline-secondary m-1 mt-md-0 mb-md-0 float-right show-as-dialog-link"
					href="{{ $U('/stockentries?embedded&product=') }}{{ $product->id }}"
					data-dialog-type="table">
					{{ $__t('Stock entries') }}
				</a>
				<a class="btn btn-outline-secondary m-1 mt-md-0 mb-md-0 float-right show-as-dialog-link"
					href="{{ $U('/stockjournal?embedded&product=') }}{{ $product->id }}"
					data-dialog-type="table">
					{{ $__t('Stock journal') }}
				</a>
			</div>
			@endif
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
			Grocy.EditObjectId = {{ $product->id }};
		</script>

		@if(!empty($product->picture_file_name))
		<script>
			Grocy.ProductPictureFileName = '{{ $product->picture_file_name }}';
		</script>
		@endif
		@endif

		<form id="product-form"
			class="has-sticky-form-footer"
			novalidate>

			<div class="form-group">
				<label for="name">{{ $__t('Name') }}</label>
				<input type="text"
					class="form-control"
					required
					id="name"
					name="name"
					value="@if($mode == 'edit'){{ $product->name}}@endif">
				<div class="invalid-feedback">{{ $__t('A name is required') }}</div>
			</div>

			<div class="form-group">
				<div class="custom-control custom-checkbox">
					<input @if($mode=='create'
						)
						checked
						@elseif($mode=='edit'
						&&
						$product->active == 1) checked @endif class="form-check-input custom-control-input" type="checkbox" id="active" name="active" value="1">
					<label class="form-check-label custom-control-label"
						for="active">{{ $__t('Active') }}</label>
				</div>
			</div>

			@php $prefillById = ''; if($mode=='edit') { $prefillById = $product->parent_product_id; } @endphp
			@php
			$hint = '';
			if ($isSubProductOfOthers)
			{
			$hint = $__t('Not possible because this product is already used as a parent product in another product');
			}
			@endphp
			@include('components.productpicker', array(
			'products' => $products,
			'prefillById' => $prefillById,
			'disallowAllProductWorkflows' => true,
			'isRequired' => false,
			'label' => 'Parent product',
			'disabled' => $isSubProductOfOthers,
			'hint' => $hint
			))
			@php $hint = ''; @endphp

			<div class="form-group">
				<label for="description">{{ $__t('Description') }}</label>
				<textarea class="form-control wysiwyg-editor"
					id="description"
					name="description">@if($mode == 'edit'){{ $product->description }}@endif</textarea>
			</div>

			@if(GROCY_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
			<div class="form-group">
				<label for="location_id">{{ $__t('Default location') }}</label>
				<select required
					class="custom-control custom-select"
					id="location_id"
					name="location_id">
					<option></option>
					@foreach($locations as $location)
					<option @if($mode=='edit'
						&&
						$location->id == $product->location_id) selected="selected" @endif value="{{ $location->id }}">{{ $location->name }}</option>
					@endforeach
				</select>
				<div class="invalid-feedback">{{ $__t('A location is required') }}</div>
			</div>
			<div class="form-group">
				<label for="default_consume_location_id">
					{{ $__t('Default consume location') }}
					<i class="fa-solid fa-question-circle text-muted"
						data-toggle="tooltip"
						data-trigger="hover click"
						title="{{ $__t('Stock entries at this location will be consumed first') }}"></i>
				</label>
				<select class="custom-control custom-select"
					id="default_consume_location_id"
					name="default_consume_location_id">
					<option></option>
					@foreach($locations as $location)
					<option @if($mode=='edit'
						&&
						$location->id == $product->default_consume_location_id) selected="selected" @endif value="{{ $location->id }}">{{ $location->name }}</option>
					@endforeach
				</select>

				@if(GROCY_FEATURE_FLAG_STOCK_PRODUCT_OPENED_TRACKING)
				<div class="custom-control custom-checkbox">
					<input @if($mode=='edit'
						&&
						$product->move_on_open == 1) checked @endif class="form-check-input custom-control-input" type="checkbox" id="move_on_open" name="move_on_open" value="1">
					<label class="form-check-label custom-control-label"
						for="move_on_open">{{ $__t('Move on open') }}&nbsp;<i class="fa-solid fa-question-circle text-muted"
							data-toggle="tooltip"
							data-trigger="hover click"
							title="{{$__t("When enabled, on marking this product as opened, the corresponding amount will be moved to the default consume location")}}"></i>
					</label>
				</div>
				@endif

			</div>
			@else
			<input type="hidden"
				name="location_id"
				id="location_id"
				value="1">
			<input type="hidden"
				name="default_consume_location_id"
				id="default_consume_location_id"
				value="1">
			@endif

			@php $prefillById = ''; if($mode=='edit') { $prefillById = $product->shopping_location_id; } @endphp
			@if(GROCY_FEATURE_FLAG_STOCK_PRICE_TRACKING)
			@include('components.shoppinglocationpicker', array(
			'label' => 'Default store',
			'prefillById' => $prefillById,
			'shoppinglocations' => $shoppinglocations
			))
			@else
			<input type="hidden"
				name="shopping_location_id"
				id="shopping_location_id"
				value="1">
			@endif

			@php if($mode == 'edit') { $value = $product->min_stock_amount; } else { $value = 0; } @endphp
			@include('components.numberpicker', array(
			'id' => 'min_stock_amount',
			'label' => 'Minimum stock amount',
			'min' => '0.',
			'decimals' => $userSettings['stock_decimal_places_amounts'],
			'value' => $value,
			'additionalGroupCssClasses' => 'mb-1',
			'additionalCssClasses' => 'locale-number-input locale-number-quantity-amount'
			))

			<div class="form-group @if(GROCY_FEATURE_FLAG_STOCK_PRODUCT_OPENED_TRACKING) mb-1 @endif">
				<div class="custom-control custom-checkbox">
					<input @if($mode=='edit'
						&&
						$product->cumulate_min_stock_amount_of_sub_products == 1) checked @endif class="form-check-input custom-control-input" type="checkbox" id="cumulate_min_stock_amount_of_sub_products" name="cumulate_min_stock_amount_of_sub_products" value="1">
					<label class="form-check-label custom-control-label"
						for="cumulate_min_stock_amount_of_sub_products">{{ $__t('Accumulate sub products min. stock amount') }}
						&nbsp;<i class="fa-solid fa-question-circle text-muted"
							data-toggle="tooltip"
							data-trigger="hover click"
							title="{{ $__t('If enabled, the min. stock amount of sub products will be accumulated into this product, means the sub product will never be missing, only this product') }}"></i>
					</label>
				</div>
			</div>

			@if(GROCY_FEATURE_FLAG_STOCK_PRODUCT_OPENED_TRACKING)
			<div class="form-group">
				<div class="custom-control custom-checkbox">
					<input @if($mode=='edit'
						&&
						$product->treat_opened_as_out_of_stock == 1) checked @endif class="form-check-input custom-control-input" type="checkbox" id="treat_opened_as_out_of_stock" name="treat_opened_as_out_of_stock" value="1">
					<label class="form-check-label custom-control-label"
						for="treat_opened_as_out_of_stock">{{ $__t('Treat opened as out of stock') }}&nbsp;<i class="fa-solid fa-question-circle text-muted"
							data-toggle="tooltip"
							data-trigger="hover click"
							title="{{ $__t('When enabled, opened items will be counted as missing for calculating if this product is below its minimum stock amount') }}"></i>
					</label>
				</div>
			</div>
			@endif

			@if(GROCY_FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING)
			<div class="form-group">
				<label class="d-block my-0"
					for="location_id">{{ $__t('Due date type') }}
					<i class="fa-solid fa-question-circle text-muted"
						data-toggle="tooltip"
						data-trigger="hover click"
						title="{{ $__t('Based on the selected type, the highlighting on the stock overview page will be different') }}"></i>
				</label>
				<div class="custom-control custom-radio mt-n2">
					<input class="custom-control-input"
						type="radio"
						name="due_type"
						id="due-type-bestbefore"
						value="1"
						@if($mode=='edit'
						&&
						$product->due_type == 1) checked @else checked @endif>
					<label class="custom-control-label"
						for="due-type-bestbefore">{{ $__t('Best before date') }}
						<i class="fa-solid fa-question-circle text-muted"
							data-toggle="tooltip"
							data-trigger="hover click"
							title="{{ $__t('Means that the product is maybe still safe to be consumed after its due date is reached') }}"></i>
					</label>
				</div>
				<div class="custom-control custom-radio mt-n2">
					<input class="custom-control-input"
						type="radio"
						name="due_type"
						id="due-type-expiration"
						value="2"
						@if($mode=='edit'
						&&
						$product->due_type == 2) checked @endif>
					<label class="custom-control-label"
						for="due-type-expiration">{{ $__t('Expiration date') }}
						<i class="fa-solid fa-question-circle text-muted"
							data-toggle="tooltip"
							data-trigger="hover click"
							title="{{ $__t('Means that the product is not safe to be consumed after its due date is reached') }}"></i>
					</label>
				</div>
			</div>

			@php if($mode == 'edit') { $value = $product->default_best_before_days; } else { $value = 0; } @endphp
			@include('components.numberpicker', array(
			'id' => 'default_best_before_days',
			'label' => 'Default due days',
			'min' => -1,
			'value' => $value,
			'hint' => $__t('For purchases this amount of days will be added to today for the due date suggestion') . ' (' . $__t('-1 means that this product will be never overdue') . ')'
			))

			@if(GROCY_FEATURE_FLAG_STOCK_PRODUCT_OPENED_TRACKING)
			@php if($mode == 'edit') { $value = $product->default_best_before_days_after_open; } else { $value = 0; } @endphp
			@include('components.numberpicker', array(
			'id' => 'default_best_before_days_after_open',
			'label' => 'Default due days after opened',
			'min' => 0,
			'value' => $value,
			'hint' => $__t('When this product was marked as opened, the due date will be replaced by today + this amount of days, but only if the resulting date is not after the original due date (a value of 0 disables this)')
			))
			@else
			<input type="hidden"
				name="default_best_before_days_after_open"
				id="default_best_before_days_after_open"
				value="1">
			@endif
			@else
			<input type="hidden"
				name="default_best_before_days"
				id="default_best_before_days"
				value="1">
			<input type="hidden"
				name="due_type"
				id="due_type"
				value="1">
			@endif

			@if(GROCY_FEATURE_FLAG_STOCK_PRODUCT_FREEZING)
			@php if($mode == 'edit') { $value = $product->default_best_before_days_after_freezing; } else { $value = 0; } @endphp
			@include('components.numberpicker', array(
			'id' => 'default_best_before_days_after_freezing',
			'label' => 'Default due days after freezing',
			'min' => -1,
			'value' => $value,
			'hint' => $__t('On moving this product to a freezer location (so when freezing it), the due date will be replaced by today + this amount of days') . ' (' . $__t('-1 means that this product will be never overdue') . ')'
			))

			@php if($mode == 'edit') { $value = $product->default_best_before_days_after_thawing; } else { $value = 0; } @endphp
			@include('components.numberpicker', array(
			'id' => 'default_best_before_days_after_thawing',
			'label' => 'Default due days after thawing',
			'min' => 0,
			'value' => $value,
			'hint' => $__t('On moving this product from a freezer location (so when thawing it), the due date will be replaced by today + this amount of days')
			))

			<div class="form-group">
				<div class="custom-control custom-checkbox">
					<input @if($mode=='edit'
						&&
						$product->should_not_be_frozen == 1) checked @endif class="form-check-input custom-control-input" type="checkbox" id="should_not_be_frozen" name="should_not_be_frozen" value="1">
					<label class="form-check-label custom-control-label"
						for="should_not_be_frozen">{{ $__t('Should not be frozen') }}&nbsp;<i class="fa-solid fa-question-circle text-muted"
							data-toggle="tooltip"
							data-trigger="hover click"
							title="{{ $__t('When enabled, on moving this product to a freezer location (so when freezing it), a warning will be shown') }}"></i>
					</label>
				</div>
			</div>
			@else
			<input type="hidden"
				name="default_best_before_days_after_freezing"
				value="0">
			<input type="hidden"
				name="default_best_before_days_after_thawing"
				value="0">
			<input type="hidden"
				name="should_not_be_frozen"
				value="0">
			@endif

			<div class="form-group">
				<label for="product_group_id">{{ $__t('Product group') }}</label>
				<select class="custom-control custom-select"
					id="product_group_id"
					name="product_group_id">
					<option></option>
					@foreach($productgroups as $productgroup)
					<option @if($mode=='edit'
						&&
						$productgroup->id == $product->product_group_id) selected="selected" @endif value="{{ $productgroup->id }}">{{ $productgroup->name }}</option>
					@endforeach
				</select>
			</div>

			<div class="form-group">
				<label for="qu_id_stock">{{ $__t('Quantity unit stock') }}</label>
				<i class="fa-solid fa-question-circle text-muted"
					data-toggle="tooltip"
					data-trigger="hover click"
					title="{{ $__t('After this product was once in stock and when the desired quantity unit cannot be selected here, first create a corresponding unit conversion') }}"></i>
				<select required
					class="custom-control custom-select input-group-qu"
					id="qu_id_stock"
					name="qu_id_stock">
					<option></option>
					@foreach($quantityunitsReferenced as $qu)
					<option @if($mode=='edit'
						&&
						$qu->id == $product->qu_id_stock) selected="selected" @endif value="{{ $qu->id }}" data-plural-form="{{ $qu->name_plural }}">{{ $qu->name }}</option>
					@endforeach
				</select>
				<div class="invalid-feedback">{{ $__t('A quantity unit is required') }}</div>
			</div>

			<div class="form-group">
				<label for="qu_id_purchase">{{ $__t('Default quantity unit purchase') }}</label>
				<i class="fa-solid fa-question-circle text-muted"
					data-toggle="tooltip"
					data-trigger="hover click"
					title="{{ $__t('This is the default quantity unit used on purchase and when adding this product to the shopping list') }}"></i>
				<select required
					class="custom-control custom-select input-group-qu"
					id="qu_id_purchase"
					name="qu_id_purchase">
					<option></option>
					@foreach($quantityunitsReferenced as $qu)
					<option @if($mode=='edit'
						&&
						$qu->id == $product->qu_id_purchase) selected="selected" @endif value="{{ $qu->id }}">{{ $qu->name }}</option>
					@endforeach
				</select>
				<div class="invalid-feedback">{{ $__t('A quantity unit is required') }}</div>
			</div>

			<div class="form-group">
				<label for="qu_id_consume">{{ $__t('Default quantity unit consume') }}</label>
				<i class="fa-solid fa-question-circle text-muted"
					data-toggle="tooltip"
					data-trigger="hover click"
					title="{{ $__t('This is the default quantity unit used when consuming this product') }}"></i>
				<select required
					class="custom-control custom-select input-group-qu"
					id="qu_id_consume"
					name="qu_id_consume">
					<option></option>
					@foreach($quantityunitsReferenced as $qu)
					<option @if($mode=='edit'
						&&
						$qu->id == $product->qu_id_consume) selected="selected" @endif value="{{ $qu->id }}">{{ $qu->name }}</option>
					@endforeach
				</select>
				<div class="invalid-feedback">{{ $__t('A quantity unit is required') }}</div>
			</div>

			<div class="form-group">
				<label for="qu_id_price">{{ $__t('Quantity unit for prices') }}</label>
				<i class="fa-solid fa-question-circle text-muted"
					data-toggle="tooltip"
					data-trigger="hover click"
					title="{{ $__t('When displaying prices for this product, they will be related to this quantity unit') }}"></i>
				<select required
					class="custom-control custom-select input-group-qu"
					id="qu_id_price"
					name="qu_id_price">
					<option></option>
					@foreach($quantityunitsReferenced as $qu)
					<option @if($mode=='edit'
						&&
						$qu->id == $product->qu_id_price) selected="selected" @endif value="{{ $qu->id }}">{{ $qu->name }}</option>
					@endforeach
				</select>
				<div class="invalid-feedback">{{ $__t('A quantity unit is required') }}</div>
			</div>

			<div class="form-group mb-1">
				<div class="custom-control custom-checkbox">
					<input @if($mode=='edit'
						&&
						$product->enable_tare_weight_handling == 1) checked @endif class="form-check-input custom-control-input" type="checkbox" id="enable_tare_weight_handling" name="enable_tare_weight_handling" value="1">
					<label class="form-check-label custom-control-label"
						for="enable_tare_weight_handling">{{ $__t('Enable tare weight handling') }}
						&nbsp;<i class="fa-solid fa-question-circle text-muted"
							data-toggle="tooltip"
							data-trigger="hover click"
							title="{{ $__t('This is useful e.g. for flour in jars - on purchase/consume/inventory you always weigh the whole jar, the amount to be posted is then automatically calculated based on what is in stock and the tare weight defined below') }}"></i>
					</label>
				</div>
			</div>

			@php if($mode == 'edit') { $value = $product->tare_weight; } else { $value = 0; } @endphp
			@php if(($mode == 'edit' && $product->enable_tare_weight_handling == 0) || $mode == 'create') { $additionalAttributes = 'disabled'; } else { $additionalAttributes = ''; } @endphp
			@include('components.numberpicker', array(
			'id' => 'tare_weight',
			'label' => 'Tare weight',
			'min' => 0,
			'decimals' => $userSettings['stock_decimal_places_amounts'],
			'value' => $value,
			'additionalAttributes' => $additionalAttributes,
			'contextInfoId' => 'tare_weight_qu_info',
			'additionalCssClasses' => 'locale-number-input locale-number-quantity-amount'
			))
			@php $additionalAttributes = '' @endphp

			@if(GROCY_FEATURE_FLAG_RECIPES)
			<div class="form-group">
				<div class="custom-control custom-checkbox">
					<input @if($mode=='edit'
						&&
						$product->not_check_stock_fulfillment_for_recipes == 1) checked @endif class="form-check-input custom-control-input" type="checkbox" id="not_check_stock_fulfillment_for_recipes" name="not_check_stock_fulfillment_for_recipes" value="1">
					<label class="form-check-label custom-control-label"
						for="not_check_stock_fulfillment_for_recipes">{{ $__t('Disable stock fulfillment checking for this ingredient') }}
						&nbsp;<i class="fa-solid fa-question-circle text-muted"
							data-toggle="tooltip"
							data-trigger="hover click"
							title="{{ $__t('This will be used as the default setting when adding this product as a recipe ingredient') }}"></i>
					</label>
				</div>
			</div>
			@else
			<input type="hidden"
				name="not_check_stock_fulfillment_for_recipes"
				id="not_check_stock_fulfillment_for_recipes"
				value="0">
			@endif

			@php if($mode == 'edit') { $value = $product->calories; } else { $value = 0; } @endphp
			@include('components.numberpicker', array(
			'id' => 'calories',
			'label' => 'Energy',
			'min' => '0.' . str_repeat('0', $userSettings['stock_decimal_places_amounts']),
			'decimals' => $userSettings['stock_decimal_places_amounts'],
			'value' => $value,
			'hint' => $__t('Per stock quantity unit'),
			'contextInfoId' => 'energy_qu_info',
			'isRequired' => false,
			'additionalCssClasses' => 'locale-number-input locale-number-quantity-amount'
			))

			@php if($mode == 'edit') { $value = $product->quick_consume_amount; } else { $value = 1; } @endphp
			@include('components.numberpicker', array(
			'id' => 'quick_consume_amount',
			'label' => 'Quick consume amount',
			'min' => $DEFAULT_MIN_AMOUNT,
			'decimals' => $userSettings['stock_decimal_places_amounts'],
			'value' => $value,
			'hint' => $__t('This amount is used for the "quick consume button" on the stock overview page (related to quantity unit stock)'),
			'contextInfoId' => 'quick_consume_qu_info',
			'additionalCssClasses' => 'locale-number-input locale-number-quantity-amount'
			))

			@php if($mode == 'edit') { $value = $product->quick_open_amount; } else { $value = 1; } @endphp
			@include('components.numberpicker', array(
			'id' => 'quick_open_amount',
			'label' => 'Quick open amount',
			'min' => $DEFAULT_MIN_AMOUNT,
			'decimals' => $userSettings['stock_decimal_places_amounts'],
			'value' => $value,
			'hint' => $__t('This amount is used for the "quick open button" on the stock overview page (related to quantity unit stock)'),
			'contextInfoId' => 'quick_open_qu_info',
			'additionalCssClasses' => 'locale-number-input locale-number-quantity-amount'
			))

			@if(GROCY_FEATURE_FLAG_LABEL_PRINTER)
			<div class="form-group">
				<label for="default_stock_label_type">{{ $__t('Default stock entry label') }}</label>
				<i class="fa-solid fa-question-circle text-muted"
					data-toggle="tooltip"
					data-trigger="hover click"
					title="{{ $__t('This is the default which will be prefilled on purchase') }}"></i>
				<select class="custom-control custom-select"
					id="default_stock_label_type"
					name="default_stock_label_type">
					<option @if($mode=='edit'
						&&
						$product->default_stock_label_type == 0 ) selected="selected" @endif value="0">{{ $__t('No label') }}</option>
					<option @if($mode=='edit'
						&&
						$product->default_stock_label_type == 1 ) selected="selected" @endif value="1">{{ $__t('Single label') }}</option>
					<option @if($mode=='edit'
						&&
						$product->default_stock_label_type == 2 ) selected="selected" @endif value="2">{{ $__t('Label per unit') }}</option>
				</select>
			</div>

			<div class="form-group">
				<div class="custom-control custom-checkbox">
					<input @if($mode=='edit'
						&&
						$product->auto_reprint_stock_label == 1) checked @endif class="form-check-input custom-control-input" type="checkbox" id="auto_reprint_stock_label" name="auto_reprint_stock_label" value="1">
					<label class="form-check-label custom-control-label"
						for="auto_reprint_stock_label">{{ $__t('Auto reprint stock entry label') }}&nbsp;<i class="fa-solid fa-question-circle text-muted"
							data-toggle="tooltip"
							data-trigger="hover click"
							title="{{ $__t('When enabled, auto-changing the due date of a stock entry (by opening/freezing/thawing and having corresponding default due days set) will reprint its label') }}"></i>
					</label>
				</div>
			</div>
			@endif

			@if(GROCY_FEATURE_FLAG_STOCK_PRICE_TRACKING)
			<div class="form-group">
				<label class="d-block my-0"
					for="default_purchase_price_type">{{ $__t('Default purchase price type') }}
					<i class="fa-solid fa-question-circle text-muted"
						data-toggle="tooltip"
						data-trigger="hover click"
						title="{{ $__t('This will be used as the default price type selection on purchase') }}"></i>
				</label>
				<div class="custom-control custom-radio mt-n2">
					<input class="custom-control-input"
						type="radio"
						name="default_purchase_price_type"
						id="default-purchase-price-type-unspecified"
						value="1"
						@if($mode=='edit'
						&&
						$product->default_purchase_price_type == 1) checked @else checked @endif>
					<label class="custom-control-label"
						for="default-purchase-price-type-unspecified">{{ $__t('Unspecified') }}
					</label>
				</div>
				<div class="custom-control custom-radio mt-n2">
					<input class="custom-control-input"
						type="radio"
						name="default_purchase_price_type"
						id="default-purchase-price-type-unit-price"
						value="2"
						@if($mode=='edit'
						&&
						$product->default_purchase_price_type == 2) checked @endif>
					<label class="custom-control-label"
						for="default-purchase-price-type-unit-price">{{ $__t('Unit price') }}
					</label>
				</div>
				<div class="custom-control custom-radio mt-n2">
					<input class="custom-control-input"
						type="radio"
						name="default_purchase_price_type"
						id="default-purchase-price-type-total-price"
						value="3"
						@if($mode=='edit'
						&&
						$product->default_purchase_price_type == 3) checked @endif>
					<label class="custom-control-label"
						for="default-purchase-price-type-total-price">{{ $__t('Total price') }}
					</label>
				</div>
			</div>
			@endif

			@if(GROCY_FEATURE_FLAG_STOCK_PRODUCT_OPENED_TRACKING)
			<div class="form-group">
				<div class="custom-control custom-checkbox">
					<input @if($mode=='edit'
						&&
						$product->disable_open == 1) checked @endif class="form-check-input custom-control-input" type="checkbox" id="disable_open" name="disable_open" value="1">
					<label class="form-check-label custom-control-label"
						for="disable_open">{{ $__t('Can\'t be opened') }}
					</label>
				</div>
			</div>
			@endif

			<div class="form-group">
				<div class="custom-control custom-checkbox">
					<input @if($mode=='edit'
						&&
						$product->hide_on_stock_overview == 1) checked @endif class="form-check-input custom-control-input" type="checkbox" id="hide_on_stock_overview" name="hide_on_stock_overview" value="1">
					<label class="form-check-label custom-control-label"
						for="hide_on_stock_overview">{{ $__t('Never show on stock overview') }}&nbsp;<i class="fa-solid fa-question-circle text-muted"
							data-toggle="tooltip"
							data-trigger="hover click"
							title="{{ $__t('The stock overview page lists all products which are currently in stock or below their min. stock amount - enable this to hide this product there always') }}"></i>
					</label>
				</div>
			</div>

			<div class="form-group">
				<div class="custom-control custom-checkbox">
					<input @if($mode=='edit'
						&&
						$product->no_own_stock == 1) checked @endif class="form-check-input custom-control-input" type="checkbox" id="no_own_stock" name="no_own_stock" value="1">
					<label class="form-check-label custom-control-label"
						for="no_own_stock">{{ $__t('Disable own stock') }}&nbsp;<i class="fa-solid fa-question-circle text-muted"
							data-toggle="tooltip"
							data-trigger="hover click"
							title="{{ $__t('When enabled, this product can\'t have own stock, means it will not be selectable on purchase (useful for parent products which are just used as a summary/total view of the child products)') }}"></i>
					</label>
				</div>
			</div>

			@include('components.userfieldsform', array(
			'userfields' => $userfields,
			'entity' => 'products'
			))

			<div class="py-5"></div>
			<div class="sticky-form-footer pt-1">
				<small id="save-hint"
					class="my-1 form-text text-muted @if($mode == 'edit') d-none @endif">{{ $__t('Save & continue to add quantity unit conversions & barcodes') }}</small>

				<button id="save-product-button"
					class="save-product-button btn btn-success mb-2 default-submit-button"
					data-location="continue">{{ $__t('Save & continue') }}</button>
				<button class="save-product-button btn btn-info mb-2"
					data-location="return">{{ $__t('Save & return to products') }}</button>
			</div>
		</form>

	</div>

	<div class="col-lg-6 col-12">

		<div class="row @if($mode == 'create' || !GROCY_FEATURE_FLAG_STOCK) d-none @endif">
			<div class="col">
				<div class="title-related-links">
					<h4>
						{{ $__t('Barcodes') }}
					</h4>
					<button class="btn btn-outline-dark d-md-none mt-2 float-right order-1 order-md-3"
						type="button"
						data-toggle="collapse"
						data-target="#related-links">
						<i class="fa-solid fa-ellipsis-v"></i>
					</button>
					@if($mode == "edit")
					<div class="related-links collapse d-md-flex order-2 width-xs-sm-100"
						id="related-links">
						<a class="btn btn-primary btn-sm m-1 mt-md-0 mb-md-0 float-right show-as-dialog-link"
							href="{{ $U('/productbarcodes/new?embedded&product=' . $product->id ) }}">
							{{ $__t('Add') }}
						</a>
					</div>
					@endif
				</div>

				<h5 id="barcode-headline-info"
					class="text-muted font-italic"></h5>

				<table id="barcode-table"
					class="table table-sm table-striped nowrap w-100">
					<thead>
						<tr>
							<th class="border-right"><a class="text-muted change-table-columns-visibility-button"
									data-toggle="tooltip"
									title="{{ $__t('Table options') }}"
									data-table-selector="#barcode-table"
									href="#"><i class="fa-solid fa-eye"></i></a>
							</th>
							<th>{{ $__t('Barcode') }}</th>
							<th class="@if(!GROCY_FEATURE_FLAG_STOCK_PRICE_TRACKING) d-none @endif allow-grouping">{{ $__t('Store') }}</th>
							<th class="allow-grouping">{{ $__t('Quantity unit') }}</th>
							<th>{{ $__t('Amount') }}</th>
							<th class="@if(!GROCY_FEATURE_FLAG_STOCK_PRICE_TRACKING) d-none @endif">{{ $__t('Last price') }}</th>
							<th>{{ $__t('Note') }}</th>

							@include('components.userfields_thead', array(
							'userfields' => $productBarcodeUserfields
							))
						</tr>
					</thead>
					<tbody class="d-none">
						@if($mode == "edit")
						@foreach($barcodes as $barcode)
						@if($barcode->product_id == $product->id || $barcode->product_id == null)
						<tr>
							<td class="fit-content border-right">
								<a class="btn btn-sm btn-info show-as-dialog-link @if($barcode->product_id == null) disabled @endif"
									href="{{ $U('/productbarcodes/' . $barcode->id . '?embedded&product=' . $product->id ) }}">
									<i class="fa-solid fa-edit"></i>
								</a>
								<a class="btn btn-sm btn-danger barcode-delete-button @if($barcode->product_id == null) disabled @endif"
									href="#"
									data-barcode-id="{{ $barcode->id }}"
									data-barcode="{{ $barcode->barcode }}"
									data-product-barcode="{{ $product->barcode }}"
									data-product-id="{{ $product->id }}">
									<i class="fa-solid fa-trash"></i>
								</a>
							</td>
							<td>
								{{ $barcode->barcode }}
							</td>
							<td class="@if(!GROCY_FEATURE_FLAG_STOCK_PRICE_TRACKING) d-none @endif"
								id="barcode-shopping-location">
								@if (FindObjectInArrayByPropertyValue($shoppinglocations, 'id', $barcode->shopping_location_id) !== null)
								{{ FindObjectInArrayByPropertyValue($shoppinglocations, 'id', $barcode->shopping_location_id)->name }}
								@endif
							</td>
							<td>
								@if(!empty($barcode->qu_id))
								{{ FindObjectInArrayByPropertyValue($quantityunitsAll, 'id', $barcode->qu_id)->name }}
								@endif
							</td>
							<td>
								@if(!empty($barcode->amount))
								<span class="locale-number locale-number-quantity-amount">{{ $barcode->amount }}</span>
								@endif
							</td>
							<td class="@if(!GROCY_FEATURE_FLAG_STOCK_PRICE_TRACKING) d-none @endif">
								<span class="locale-number locale-number-currency">{{ $barcode->last_price }}</span>
							</td>
							<td>
								{{ $barcode->note }}
							</td>

							@include('components.userfields_tbody', array(
							'userfields' => $productBarcodeUserfields,
							'userfieldValues' => FindAllObjectsInArrayByPropertyValue($productBarcodeUserfieldValues, 'object_id', $barcode->id)
							))
						</tr>
						@endif
						@endforeach
						@endif
					</tbody>
				</table>
			</div>
		</div>

		<div class="row mt-2 @if($mode == 'create') d-none @endif">
			<div class="col clearfix">
				<div class="title-related-links">
					<h4>
						<span class="ls-n1">{{ $__t('Grocycode') }}</span>
						<i class="fa-solid fa-question-circle text-muted"
							data-toggle="tooltip"
							data-trigger="hover click"
							title="{{ $__t('Grocycode is a unique referer to this %s in your Grocy instance - print it onto a label and scan it like any other barcode', $__t('Product')) }}"></i>
					</h4>
					<p>
						@if($mode == 'edit')
						<img src="{{ $U('/product/' . $product->id . '/grocycode?size=60') }}"
							class="float-lg-left"
							loading="lazy">
						@endif
					</p>
					<p>
						<a class="btn btn-outline-primary btn-sm"
							href="{{ $U('/product/' . $product->id . '/grocycode?download=true') }}">{{ $__t('Download') }}</a>
						@if(GROCY_FEATURE_FLAG_LABEL_PRINTER)
						<a class="btn btn-outline-primary btn-sm product-grocycode-label-print"
							data-product-id="{{ $product->id }}"
							href="#">
							{{ $__t('Print on label printer') }}
						</a>
						@endif
					</p>
				</div>
			</div>
		</div>

		<div class="row @if(GROCY_FEATURE_FLAG_STOCK) mt-5 @endif @if($mode == 'create') d-none @endif">
			<div class="col">
				<div class="title-related-links">
					<h4>
						{{ $__t('Product specific QU conversions') }}
					</h4>
					<button class="btn btn-outline-dark d-md-none mt-2 float-right order-1 order-md-3"
						type="button"
						data-toggle="collapse"
						data-target="#related-links">
						<i class="fa-solid fa-ellipsis-v"></i>
					</button>
					@if($mode == "edit")
					<div class="related-links collapse d-md-flex order-2 width-xs-sm-100"
						id="related-links">
						<a class="btn btn-primary btn-sm m-1 mt-md-0 mb-md-0 float-right show-as-dialog-link"
							href="{{ $U('/quantityunitconversion/new?embedded&product=' . $product->id ) }}"
							data-dialog-type="wider">
							{{ $__t('Add') }}
						</a>
						<a class="btn btn-outline-primary btn-sm m-1 mt-md-0 mb-md-0 float-right show-as-dialog-link"
							href="{{ $U('/quantityunitconversionsresolved?embedded&product=' . $product->id ) }}"
							data-dialog-type="wider"
							data-toggle="tooltip"
							title="{{ $__t('This shows all to this product directly or indirectly related quantity units and their derived conversion factors') }}">
							{{ $__t('Show resolved conversions') }}
						</a>
					</div>
					@endif
				</div>

				<table id="qu-conversions-table-products"
					class="table table-sm table-striped nowrap w-100">
					<thead>
						<tr>
							<th class="border-right"><a class="text-muted change-table-columns-visibility-button"
									data-toggle="tooltip"
									title="{{ $__t('Table options') }}"
									data-table-selector="#qu-conversions-table-products"
									href="#"><i class="fa-solid fa-eye"></i></a>
							</th>
							<th class="allow-grouping">{{ $__t('Quantity unit from') }}</th>
							<th class="allow-grouping">{{ $__t('Quantity unit to') }}</th>
							<th>{{ $__t('Factor') }}</th>
							<th></th>
						</tr>
					</thead>
					<tbody class="d-none">
						@if($mode == "edit")
						@foreach($quConversions as $quConversion)
						<tr>
							<td class="fit-content border-right">
								<a class="btn btn-sm btn-info show-as-dialog-link @if($quConversion->product_id == null) disabled @endif"
									href="{{ $U('/quantityunitconversion/' . $quConversion->id . '?embedded&product=' . $product->id ) }}"
									data-dialog-type="wider">
									<i class="fa-solid fa-edit"></i>
								</a>
								<a class="btn btn-sm btn-danger qu-conversion-delete-button @if($quConversion->product_id == null) disabled @endif"
									href="#"
									data-qu-conversion-id="{{ $quConversion->id }}">
									<i class="fa-solid fa-trash"></i>
								</a>
							</td>
							<td>
								{{ FindObjectInArrayByPropertyValue($quantityunitsAll, 'id', $quConversion->from_qu_id)->name }}
							</td>
							<td>
								{{ FindObjectInArrayByPropertyValue($quantityunitsAll, 'id', $quConversion->to_qu_id)->name }}
							</td>
							<td>
								<span class="locale-number locale-number-quantity-amount">{{ $quConversion->factor }}</span>
							</td>
							<td class="font-italic">
								{!! $__t('This means 1 %1$s is the same as %2$s %3$s', FindObjectInArrayByPropertyValue($quantityunitsAll, 'id', $quConversion->from_qu_id)->name, '<span class="locale-number locale-number-quantity-amount">' . $quConversion->factor . '</span>', $__n($quConversion->factor, FindObjectInArrayByPropertyValue($quantityunitsAll, 'id', $quConversion->to_qu_id)->name, FindObjectInArrayByPropertyValue($quantityunitsAll, 'id', $quConversion->to_qu_id)->name_plural, true)) !!}
							</td>
						</tr>
						@endforeach
						@endif
					</tbody>
				</table>
			</div>
		</div>

		@if(GROCY_FEATURE_FLAG_GROCY_AI)
		@php
		$grocyAiBarcode = '';
		$grocyAiBrandTargets = [];
		if ($mode === 'edit')
		{
			foreach ($barcodes as $grocyAiProductBarcode)
			{
				if ($grocyAiProductBarcode->product_id == $product->id)
				{
					$grocyAiBarcode = $grocyAiProductBarcode->barcode;
					break;
				}
			}
		}
		foreach ($userfields as $grocyAiUserfield)
		{
			if ($grocyAiUserfield->name === 'products.brand' && $grocyAiUserfield->type === 'text-single-line')
			{
				$grocyAiBrandTargets[] = $grocyAiUserfield;
			}
		}
		$grocyAiBrandTarget = count($grocyAiBrandTargets) === 1 ? $grocyAiBrandTargets[0] : null;
		@endphp
		<div class="row @if($mode == 'edit') mt-5 @endif permission-MASTER_DATA_EDIT"
			id="grocy-ai-product-enrichment"
			data-current-product-id="@if($mode === 'edit'){{ $product->id }}@endif"
			data-product-route-template="{{ $U('/product/__PRODUCT_ID__') }}"
			data-brand-target-id="@if($grocyAiBrandTarget !== null){{ $grocyAiBrandTarget->id }}@endif"
			data-brand-target-name="@if($grocyAiBrandTarget !== null){{ $grocyAiBrandTarget->name }}@endif"
			data-brand-target-label="@if($grocyAiBrandTarget !== null){{ $grocyAiBrandTarget->caption }}@endif"
			data-package-size-target-available="false"
			data-food-type-target-available="false"
			data-contract-error="{{ $__t('Suggestions could not be verified. Retry the search, or continue editing manually. Nothing was changed.') }}"
			data-review-heading="{{ $__t('Review suggested fields') }}"
			data-current-label="{{ $__t('Current') }}"
			data-suggested-label="{{ $__t('Suggested') }}"
			data-blank-label="{{ $__t('Blank') }}"
			data-selection-label="{{ $__t('Use suggested value') }}"
			data-automatic-origin="{{ $__t('Preselected — blank field and exact structured match') }}"
			data-explicit-origin="{{ $__t('Selected by you') }}"
			data-no-field-message="{{ $__t('No matching Grocy field is configured.') }}"
			data-no-option-message="{{ $__t('No matching Grocy option is available.') }}"
			data-no-food-type-message="{{ $__t('No local food type is configured.') }}"
			data-source-update-unavailable="{{ $__t('Source update time unavailable') }}"
			data-image-section-heading="{{ $__t('Choose a product image') }}"
			data-structured-image-heading="{{ $__t('Front package image') }}"
			data-search-image-heading="{{ $__t('Unverified search alternatives') }}"
			data-thumbnail-action="{{ $__t('Load thumbnail') }}"
			data-thumbnail-busy="{{ $__t('Loading thumbnail…') }}"
			data-image-select="{{ $__t('Select image') }}"
			data-image-selected="{{ $__t('Selected') }}"
			data-image-remove="{{ $__t('Remove selection') }}"
			data-media-error="{{ $__t('This image could not be loaded safely. Choose another image or continue without one.') }}"
			data-media-expired="{{ $__t('This image preview expired. Search again to load it.') }}"
			data-selection-summary="{{ $__t('%s changes selected', '%s') }}"
			data-review-action="{{ $__t('Review selected changes') }}"
			data-empty-selection-heading="{{ $__t('No changes selected') }}"
			data-empty-selection-body="{{ $__t('Select one or more suggestions, or continue editing the product manually.') }}"
			data-stage-action="{{ $__t('Stage selected changes') }}"
			data-back-action="{{ $__t('Back to suggestions') }}"
			data-staging-success="{{ $__t('Selected changes are staged in the form. Review the form, then use Grocy\'s Save button to save them.') }}"
			data-stale-field-message="{{ $__t('This field changed after the search. Review it again before staging.') }}"
			data-scanned-barcode-label="{{ $__t('Scanned barcode') }}"
			data-equivalents-label="{{ $__t('Canonical equivalents checked') }}"
			data-unused-barcode-message="{{ $__t('This barcode is not assigned in Grocy.') }}"
			data-staged-barcode-label="{{ $__t('Ready to add on Save') }}"
			data-remove-staged-barcode="{{ $__t('Remove staged barcode') }}"
			data-owned-other-message="{{ $__t('This barcode already belongs to an existing product.') }}"
			data-owned-current-message="{{ $__t('This barcode is already attached to this product.') }}"
			data-open-existing-product="{{ $__t('Open existing product') }}"
			data-ready-message="{{ $__t('GTIN ready.') }}"
			data-busy-message="{{ $__t('Searching product details…') }}"
			data-success-heading="{{ $__t('Product details found') }}"
			data-success-body="{{ $__t('Review the preview before applying anything. Changes are saved only when you save the product.') }}"
			data-invalid-length="{{ $__t('Enter an 8, 12, 13, or 14 digit GTIN.') }}"
			data-invalid-checksum="{{ $__t('That GTIN has an invalid check digit. Check the number and try again.') }}"
			data-camera-unavailable="{{ $__t('Camera scanning is unavailable. Enter the GTIN manually.') }}"
			data-cancelled-message="{{ $__t('Search cancelled. No changes were made.') }}"
			data-offline-message="{{ $__t('This phone is offline. Reconnect and retry, or continue editing manually.') }}"
			data-timeout-message="{{ $__t('The search took too long. Retry, or continue editing manually.') }}"
			data-not-found-message="{{ $__t('No exact product match was found. Check the GTIN or continue editing manually.') }}"
			data-companion-unavailable-message="{{ $__t('Product search is temporarily unavailable. Retry, or continue editing manually.') }}"
			data-provider-error-message="{{ $__t('A product data provider could not respond. Retry, or continue editing manually.') }}"
			data-partial-image-message="{{ $__t('Product details were found, but images are unavailable. You can continue without an image.') }}"
			data-retry-action="{{ $__t('Retry search') }}"
			data-diagnostics-label="{{ $__t('Diagnostics') }}"
			data-copy-diagnostic-action="{{ $__t('Copy diagnostic report') }}"
			data-diagnostic-copy-success="{{ $__t('Diagnostic report copied.') }}"
			data-diagnostic-copy-fallback="{{ $__t('Copy was blocked. Select and copy the redacted report manually.') }}"
			data-diagnostic-fallback-label="{{ $__t('Redacted diagnostic report') }}">
			<div class="col">
				<div class="card grocy-ai-card">
					<div class="card-body">
						<h4 class="card-title">{{ $__t('grocy_AI product enrichment') }}</h4>
						<p class="text-muted grocy-ai-description">{{ $__t('Scan or enter a GTIN to find product suggestions. Review and stage selected changes; nothing is saved until you save the product.') }}</p>
						<div class="form-group mb-0">
							<label for="grocy-ai-upc">{{ $__t('GTIN') }}</label>
							<input type="text"
								class="form-control barcodescanner-input"
								id="grocy-ai-upc"
								inputmode="numeric"
								autocomplete="off"
								placeholder="{{ $__t('8, 12, 13, or 14 digits') }}"
								aria-describedby="grocy-ai-error grocy-ai-status"
								data-target="grocy-ai-upc"
								value="{{ $grocyAiBarcode }}">
						</div>
						<div class="invalid-feedback d-block d-none"
							id="grocy-ai-error"
							role="alert"></div>
						<div class="grocy-ai-actions">
							<button class="btn btn-outline-primary"
								type="button"
								id="grocy-ai-scan-button">
								<i class="fa-solid fa-camera" aria-hidden="true"></i> {{ $__t('Scan barcode') }}
							</button>
							<button class="btn btn-primary"
								type="button"
								id="grocy-ai-search-button"
								disabled>
								<i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> {{ $__t('Search product') }}
							</button>
							<button class="btn btn-outline-secondary d-none"
								type="button"
								id="grocy-ai-cancel-button">
								{{ $__t('Cancel search') }}
							</button>
							<button class="btn btn-primary d-none"
								type="button"
								id="grocy-ai-retry-button">
								{{ $__t('Retry search') }}
							</button>
						</div>
						<div class="grocy-ai-status alert alert-secondary mt-3 mb-0"
							id="grocy-ai-status"
							role="status"
							aria-live="polite"
							aria-atomic="true"
							aria-busy="false">
							<strong>{{ $__t('No enrichment result yet') }}</strong>
							<span>{{ $__t('Scan or enter a GTIN, then search. You can continue editing this product without enrichment.') }}</span>
						</div>
						<details class="grocy-ai-diagnostics mt-3 d-none"
							id="grocy-ai-diagnostics">
							<summary id="grocy-ai-diagnostic-summary">{{ $__t('Diagnostics') }}</summary>
							<div class="grocy-ai-diagnostic-body">
								<button class="btn btn-outline-secondary"
									type="button"
									id="grocy-ai-copy-diagnostic-button">
									<i class="fa-solid fa-copy" aria-hidden="true"></i> {{ $__t('Copy diagnostic report') }}
								</button>
								<div class="grocy-ai-diagnostic-feedback"
									id="grocy-ai-diagnostic-feedback"
									role="status"
									aria-live="polite"></div>
								<textarea class="form-control d-none"
									id="grocy-ai-diagnostic-fallback"
									rows="8"
									readonly
									aria-label="{{ $__t('Redacted diagnostic report') }}"></textarea>
							</div>
						</details>
						<div class="grocy-ai-results mt-3 d-none"
							id="grocy-ai-results"
							aria-label="{{ $__t('Product enrichment preview') }}">
							<section class="grocy-ai-barcode-ownership"
								id="grocy-ai-barcode-ownership"
								aria-labelledby="grocy-ai-barcode-heading">
								<h5 id="grocy-ai-barcode-heading">{{ $__t('Barcode ownership') }}</h5>
								<dl class="mb-0">
									<dt>{{ $__t('Scanned barcode') }}</dt>
									<dd id="grocy-ai-scanned-barcode"></dd>
									<dt>{{ $__t('Canonical equivalents checked') }}</dt>
									<dd id="grocy-ai-barcode-equivalents"></dd>
								</dl>
								<div id="grocy-ai-barcode-outcome"
									role="status"
									aria-live="polite"></div>
								<a class="btn btn-primary d-none"
									id="grocy-ai-open-existing-product"
									href="#">{{ $__t('Open existing product') }}</a>
								<button class="btn btn-outline-secondary d-none"
									id="grocy-ai-remove-staged-barcode"
									type="button">{{ $__t('Remove staged barcode') }}</button>
							</section>
							<section class="grocy-ai-review-section"
								id="grocy-ai-field-review"
								aria-labelledby="grocy-ai-review-heading">
								<h5 id="grocy-ai-review-heading"
									tabindex="-1">{{ $__t('Review suggested fields') }}</h5>
								<div class="grocy-ai-field-rows"
									id="grocy-ai-field-rows"></div>
							</section>
							<section class="grocy-ai-media-review d-none"
								id="grocy-ai-media-review"
								aria-labelledby="grocy-ai-media-heading">
								<h5 id="grocy-ai-media-heading">{{ $__t('Choose a product image') }}</h5>
								<section class="grocy-ai-media-group d-none" id="grocy-ai-structured-media-group">
									<h6>{{ $__t('Front package image') }}</h6>
									<div class="grocy-ai-media-grid" id="grocy-ai-structured-media"></div>
								</section>
								<section class="grocy-ai-media-group d-none" id="grocy-ai-search-media-group">
									<h6>{{ $__t('Unverified search alternatives') }}</h6>
									<div class="grocy-ai-media-grid" id="grocy-ai-search-media"></div>
								</section>
							</section>
							<div class="grocy-ai-selection-status"
								id="grocy-ai-selection-status"
								role="status"
								aria-live="polite">{{ $__t('%s changes selected', 0) }}</div>
							<button class="btn btn-primary"
								id="grocy-ai-review-selected-button"
								type="button"
								disabled>{{ $__t('Review selected changes') }}</button>
							<section class="grocy-ai-final-diff d-none"
								id="grocy-ai-final-diff"
								aria-labelledby="grocy-ai-final-diff-heading">
								<h5 id="grocy-ai-final-diff-heading"
									tabindex="-1">{{ $__t('Review selected changes') }}</h5>
								<div id="grocy-ai-final-diff-list"></div>
								<div class="grocy-ai-actions">
									<button class="btn btn-outline-secondary"
										id="grocy-ai-back-to-suggestions-button"
										type="button">{{ $__t('Back to suggestions') }}</button>
									<button class="btn btn-primary"
										id="grocy-ai-stage-selected-button"
										type="button">{{ $__t('Stage selected changes') }}</button>
								</div>
							</section>
							<div class="alert alert-success d-none"
								id="grocy-ai-staging-feedback"></div>
						</div>
					</div>
				</div>
			</div>
		</div>
		@endif

		<div class="row @if($mode == 'edit' && !GROCY_FEATURE_FLAG_GROCY_AI) mt-5 @endif">
			<div class="col">
				<div class="title-related-links">
					<h4>
						{{ $__t('Picture') }}
					</h4>
					<div class="form-group w-75 m-0">
						<div class="input-group">
							<div class="custom-file">
								<input type="file"
									class="custom-file-input"
									id="product-picture"
									accept="image/*">
								<label id="product-picture-label"
									class="custom-file-label @if(empty($product->picture_file_name)) d-none @endif"
									for="product-picture">
									{{ $product->picture_file_name }}
								</label>
								<label id="product-picture-label-none"
									class="custom-file-label @if(!empty($product->picture_file_name)) d-none @endif"
									for="product-picture">
									{{ $__t('No file selected') }}
								</label>
							</div>
							<div class="input-group-append">
								<span class="input-group-text"><i class="fa-solid fa-trash"
										id="delete-current-product-picture-button"></i></span>
							</div>
						</div>
					</div>
				</div>
				@if($mode == "edit" && !empty($product->picture_file_name))
				<img id="current-product-picture"
					src="{{ $U('/api/files/productpictures/' . base64_encode($product->picture_file_name) . '?force_serve_as=picture&best_fit_width=400') }}"
					class="img-fluid img-thumbnail mt-2 mb-5"
					loading="lazy">
				<p id="delete-current-product-picture-on-save-hint"
					class="form-text text-muted font-italic d-none pb-5">{{ $__t('The current picture will be deleted on save') }}</p>
				@else
				<p id="no-current-product-picture-hint"
					class="form-text text-muted font-italic pb-5">{{ $__t('No picture available') }}</p>
				@endif
			</div>
		</div>
	</div>
</div>
@stop
