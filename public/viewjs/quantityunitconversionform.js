(function (root, factory)
{
	'use strict';

	var api = factory();
	if (typeof module === 'object' && module.exports)
	{
		module.exports = api;
	}
	if (root)
	{
		root.GrocyAIConversionValidation = api;
		if (root.jQuery && root.Grocy)
		{
			api.attachNativeForm(root.jQuery, root);
		}
	}
})(typeof window !== 'undefined' ? window : null, function ()
{
	'use strict';

	var COPY = {
		incomplete: 'Choose both quantity units and a positive factor to validate this conversion.',
		initial: 'Validate this conversion before saving.',
		stale: 'This validation is out of date. Validate the current conversion before saving.',
		pending: 'Validating conversion impact…',
		impactClear: 'No blocking paths, cycles, reciprocal conflicts, or tolerance failures were found.',
		requestFailure: 'This conversion could not be validated. Correct any visible fields or try again. Nothing was changed.',
		crossDimension: 'Mass and volume cannot be used in one universal conversion. Use an explicitly assigned food-type profile or a measured product conversion instead.',
		ineligiblePair: 'This quantity-unit pair is not eligible for a reusable default. Keep package and count conversions on the product.',
		genericBlocker: 'This conversion is blocked by its current factor or conversion graph. Correct the values and validate again.',
		productHelper: 'This conversion takes precedence over any food-type profile and universal default.',
		inactiveGate: 'Reusable conversion profiles are inactive until both branch checks pass.',
		validationRequiredLabel: 'Validation required',
		outOfDateLabel: 'Out of date',
		validatingLabel: 'Validating',
		productOverrideLabel: 'Product override',
		inactiveLabel: 'Inactive — not saved or active',
		blockedLabel: 'Blocked',
		unavailableLabel: 'Validation unavailable',
		dimensionLabel: 'Dimension: %s',
		sourceLabel: 'Source: NIST SP 811 · %s'
	};

	function fixedState(overrides)
	{
		return Object.assign({
			kind: 'incomplete',
			role: 'status',
			statusLabel: 'Validation required',
			message: COPY.incomplete,
			pair: '',
			dimensionLabel: '',
			sourceLabel: '',
			impact: '',
			blocker: '',
			factor: '',
			saveEnabled: false,
			focusHeading: false
		}, overrides || {});
	}

	function candidateComplete(candidate)
	{
		if (!candidate || !String(candidate.fromQuId || '') || !String(candidate.toQuId || ''))
		{
			return false;
		}
		var factor = Number(candidate.factor);
		return Number.isFinite(factor) && factor > 0;
	}

	function candidateFingerprint(candidate)
	{
		return JSON.stringify([
			candidate && candidate.productId === null ? null : String(candidate && candidate.productId || ''),
			String(candidate && candidate.fromQuId || ''),
			String(candidate && candidate.toQuId || ''),
			String(candidate && candidate.factor || '')
		]);
	}

	function pairLabel(candidate, factor)
	{
		return '1 ' + String(candidate.fromName || '') + ' = ' + String(factor || candidate.factor || '') + ' ' + String(candidate.toName || '');
	}

	function blockerCopy(blocker, copy)
	{
		if (blocker === 'dimension_mismatch')
		{
			return copy.crossDimension;
		}
		if (blocker === 'unit_not_cataloged' || blocker === 'reusable_count_scope')
		{
			return copy.ineligiblePair;
		}
		return copy.genericBlocker;
	}

	function requestFailureState(copy)
	{
		return fixedState({
			kind: 'request-failure', role: 'alert', statusLabel: copy.unavailableLabel,
			message: copy.requestFailure, focusHeading: true
		});
	}

	function reusableEvidenceIsBounded(response)
	{
		return (response.dimension === 'mass' || response.dimension === 'volume')
			&& typeof response.source_version === 'string'
			&& /^[A-Za-z0-9][A-Za-z0-9 .;:_-]{0,127}$/.test(response.source_version)
			&& typeof response.inactive_revision_id === 'string'
			&& /^[a-z0-9][a-z0-9._-]{0,63}$/.test(response.inactive_revision_id);
	}

	function responseState(candidate, response, copy)
	{
		copy = Object.assign({}, COPY, copy || {});
		if (!response || typeof response !== 'object' || !Array.isArray(response.blockers))
		{
			return requestFailureState(copy);
		}

		var responseFactor = typeof response.factor === 'string' || typeof response.factor === 'number'
			? String(response.factor)
			: '';
		var currentFactor = String(candidate.factor || '');
		var candidateIsProduct = typeof candidate.productId === 'string' && /^[1-9][0-9]{0,9}$/.test(candidate.productId);
		var candidateIsReusable = candidate.productId === null || candidate.productId === '';
		if ((response.scope === 'product' && !candidateIsProduct)
			|| (response.scope === 'reusable' && !candidateIsReusable))
		{
			return requestFailureState(copy);
		}
		if (responseFactor && responseFactor !== currentFactor)
		{
			return fixedState({
				kind: 'stale', statusLabel: copy.outOfDateLabel, message: copy.stale
			});
		}
		if (response.status === 'product_native' && !responseFactor)
		{
			return requestFailureState(copy);
		}

		if (response.status === 'product_native' && response.scope === 'product' && response.blockers.length === 0)
		{
			return fixedState({
				kind: 'product-normal',
				statusLabel: copy.productOverrideLabel,
				message: copy.productHelper,
				pair: pairLabel(candidate, responseFactor),
				impact: copy.impactClear,
				factor: responseFactor,
				saveEnabled: true
			});
		}

		if (response.status === 'active' && response.scope === 'reusable')
		{
			return requestFailureState(copy);
		}

		if (response.status === 'inactive' && response.scope === 'reusable' && response.blockers.length === 0)
		{
			if (!responseFactor || !reusableEvidenceIsBounded(response))
			{
				return requestFailureState(copy);
			}
			return fixedState({
				kind: 'inactive-gate',
				statusLabel: copy.inactiveLabel,
				message: copy.inactiveGate,
				pair: pairLabel(candidate, responseFactor),
				dimensionLabel: copy.dimensionLabel.replace('%s', response.dimension.charAt(0).toUpperCase() + response.dimension.slice(1)),
				sourceLabel: copy.sourceLabel.replace('%s', response.source_version),
				impact: copy.impactClear,
				factor: responseFactor,
				saveEnabled: false
			});
		}

		var blocker = typeof response.blockers[0] === 'string' ? response.blockers[0] : '';
		return fixedState({
			kind: 'blocked',
			role: 'alert',
			statusLabel: copy.blockedLabel,
			message: blockerCopy(blocker, copy),
			blocker: blockerCopy(blocker, copy),
			focusHeading: true
		});
	}

	function createValidationController(options)
	{
		var revision = 0;
		var hasAttemptedValidation = false;
		var requestValidation = options.requestValidation;
		var render = options.render;
		var getCandidate = options.getCandidate;
		var copy = Object.assign({}, COPY, options.copy || {});

		function invalidate()
		{
			revision++;
			var candidate = getCandidate();
			if (!candidateComplete(candidate))
			{
				render(fixedState({ statusLabel: copy.validationRequiredLabel, message: copy.incomplete }));
				return;
			}
			render(fixedState({
				kind: hasAttemptedValidation ? 'stale' : 'incomplete',
				statusLabel: hasAttemptedValidation ? copy.outOfDateLabel : copy.validationRequiredLabel,
				message: hasAttemptedValidation ? copy.stale : copy.initial
			}));
		}

		async function validate()
		{
			var candidate = getCandidate();
			if (!candidateComplete(candidate))
			{
				revision++;
				render(fixedState({ statusLabel: copy.validationRequiredLabel, message: copy.incomplete }));
				return;
			}

			hasAttemptedValidation = true;
			var requestRevision = ++revision;
			var fingerprint = candidateFingerprint(candidate);
			render(fixedState({
				kind: 'pending', statusLabel: copy.validatingLabel, message: copy.pending
			}));
			try
			{
				var response = await requestValidation(candidate);
				if (revision !== requestRevision || candidateFingerprint(getCandidate()) !== fingerprint)
				{
					return;
				}
				render(responseState(candidate, response, copy));
			}
			catch (error)
			{
				if (revision !== requestRevision || candidateFingerprint(getCandidate()) !== fingerprint)
				{
					return;
				}
				render(fixedState({
					kind: 'request-failure', role: 'alert', statusLabel: copy.unavailableLabel,
					message: copy.requestFailure, focusHeading: true
				}));
			}
		}

		return {
			invalidate: invalidate,
			validate: validate,
			setRequestValidation: function (nextRequestValidation)
			{
				requestValidation = nextRequestValidation;
			}
		};
	}

	function attachNativeForm($, browser)
	{
		var $region = $('#qu-conversion-validation');
		if (!$region.length)
		{
			return;
		}

		function regionCopy(key, fallback)
		{
			var value = $region.data(key);
			return typeof value === 'string' && value.length > 0 ? value : fallback;
		}

		var copy = {
			incomplete: regionCopy('incomplete', COPY.incomplete),
			initial: regionCopy('initial', COPY.initial),
			stale: regionCopy('stale', COPY.stale),
			pending: regionCopy('pending', COPY.pending),
			impactClear: regionCopy('impact-clear', COPY.impactClear),
			requestFailure: regionCopy('request-failure', COPY.requestFailure),
			crossDimension: regionCopy('cross-dimension', COPY.crossDimension),
			ineligiblePair: regionCopy('ineligible-pair', COPY.ineligiblePair),
			genericBlocker: regionCopy('generic-blocker', COPY.genericBlocker),
			productHelper: regionCopy('product-helper', COPY.productHelper),
			inactiveGate: regionCopy('inactive-gate', COPY.inactiveGate),
			validationRequiredLabel: regionCopy('validation-required-label', COPY.validationRequiredLabel),
			outOfDateLabel: regionCopy('out-of-date-label', COPY.outOfDateLabel),
			validatingLabel: regionCopy('validating-label', COPY.validatingLabel),
			productOverrideLabel: regionCopy('product-override-label', COPY.productOverrideLabel),
			inactiveLabel: regionCopy('inactive-label', COPY.inactiveLabel),
			blockedLabel: regionCopy('blocked-label', COPY.blockedLabel),
			unavailableLabel: regionCopy('unavailable-label', COPY.unavailableLabel),
			dimensionLabel: regionCopy('dimension-label', COPY.dimensionLabel),
			sourceLabel: regionCopy('source-label', COPY.sourceLabel)
		};

		function getCandidate()
		{
			var $from = $('#from_qu_id option:selected');
			var $to = $('#to_qu_id option:selected');
			var productValue = $('#quconversion-form input[name="product_id"]').val();
			return {
				productId: productValue ? String(productValue) : null,
				fromQuId: String($('#from_qu_id').val() || ''),
				fromName: String($from.text() || ''),
				toQuId: String($('#to_qu_id').val() || ''),
				toName: String($to.text() || ''),
				factor: String($('#factor').val() || '')
			};
		}

		function requestValidation(candidate)
		{
			return new Promise(function (resolve, reject)
			{
				var query = [
					'from_qu_id=' + encodeURIComponent(candidate.fromQuId),
					'to_qu_id=' + encodeURIComponent(candidate.toQuId),
					'factor=' + encodeURIComponent(candidate.factor)
				];
				if (candidate.productId)
				{
					query.push('product_id=' + encodeURIComponent(candidate.productId));
				}
				if (browser.Grocy.EditMode === 'edit' && browser.Grocy.EditObjectId)
				{
					query.push('object_id=' + encodeURIComponent(String(browser.Grocy.EditObjectId)));
				}
				browser.Grocy.Api.Get('grocy-ai/conversions/validate?' + query.join('&'), resolve, reject);
			});
		}

		function render(state)
		{
			var $status = $('#qu-conversion-validation-status');
			$status.removeClass('alert-secondary alert-info alert-success alert-warning alert-danger');
			var stateClass = {
				pending: 'alert-info',
				'product-normal': 'alert-success',
				'inactive-gate': 'alert-warning',
				blocked: 'alert-danger',
				'request-failure': 'alert-warning',
				stale: 'alert-warning'
			}[state.kind] || 'alert-secondary';
			$status.addClass(stateClass);
			$status.attr('role', state.role);
			$status.attr('aria-busy', state.kind === 'pending' ? 'true' : 'false');
			$('#qu-conversion-validation-label').text(state.statusLabel);
			$('#qu-conversion-validation-message').text(state.message);
			$('#qu-conversion-validation-dimension').text(state.dimensionLabel);
			$('#qu-conversion-validation-pair').text(state.pair);
			$('#qu-conversion-validation-source').text(state.sourceLabel);
			$('#qu-conversion-validation-impact').text(state.impact);
			$('#save-quconversion-button').prop('disabled', !state.saveEnabled)
				.attr('aria-label', regionCopy('save-label', 'Save conversion'));
			$('#validate-quconversion-impact-button').prop('disabled', state.kind === 'pending');
			if (state.focusHeading)
			{
				$('#qu-conversion-validation-heading').trigger('focus');
			}
		}

		var validationController = createValidationController({
			getCandidate: getCandidate,
			requestValidation: requestValidation,
			render: render,
			copy: copy
		});
		browser.Grocy.ConversionValidation = validationController;

		$('#validate-quconversion-impact-button').on('click', function ()
		{
			validationController.validate();
		});
		$('.input-group-qu').on('change input', function ()
		{
			validationController.invalidate();
		});
		$('#save-quconversion-button').on('click', function (event)
		{
			if ($(this).prop('disabled'))
			{
				event.preventDefault();
				event.stopImmediatePropagation();
			}
		});
		validationController.invalidate();

	}
	return {
		createValidationController: createValidationController,
		responseState: responseState,
		attachNativeForm: attachNativeForm
	};
});

if (typeof window !== 'undefined')
{
$('#save-quconversion-button').on('click', function(e)
{
	e.preventDefault();

	if (!Grocy.FrontendHelpers.ValidateForm("quconversion-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#quconversion-form').serializeJSON();
	jsonData.from_qu_id = $("#from_qu_id").val();
	Grocy.FrontendHelpers.BeginUiBusy("quconversion-form");

	if (Grocy.EditMode === 'create')
	{
		Grocy.Api.Post('objects/quantity_unit_conversions', jsonData,
			function(result)
			{
				Grocy.EditObjectId = result.created_object_id;
				Grocy.Components.UserfieldsForm.Save(function()
				{
					if (typeof GetUriParam("qu-unit") !== "undefined")
					{
						if (GetUriParam("embedded") !== undefined)
						{
							window.parent.postMessage(WindowMessageBag("Reload"), Grocy.BaseUrl);
						}
						else
						{
							window.location.href = U("/quantityunit/" + GetUriParam("qu-unit"));
						}
					}
					else
					{
						window.parent.postMessage(WindowMessageBag("ProductQUConversionChanged"), Grocy.BaseUrl);
						window.parent.postMessage(WindowMessageBag("CloseLastModal"), Grocy.BaseUrl);
					}
				});
			},
			function(xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("quconversion-form");
				Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Grocy.Api.Put('objects/quantity_unit_conversions/' + Grocy.EditObjectId, jsonData,
			function(result)
			{
				Grocy.Components.UserfieldsForm.Save(function()
				{
					if (typeof GetUriParam("qu-unit") !== "undefined")
					{
						if (GetUriParam("embedded") !== undefined)
						{
							window.parent.postMessage(WindowMessageBag("Reload"), Grocy.BaseUrl);
						}
						else
						{
							window.location.href = U("/quantityunit/" + GetUriParam("qu-unit"));
						}
					}
					else
					{
						window.parent.postMessage(WindowMessageBag("ProductQUConversionChanged"), Grocy.BaseUrl);
						window.parent.postMessage(WindowMessageBag("CloseLastModal"), Grocy.BaseUrl);
					}
				});
			},
			function(xhr)
			{
				Grocy.FrontendHelpers.EndUiBusy("quconversion-form");
				Grocy.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

$('#quconversion-form input').keyup(function(event)
{
	$('.input-group-qu').trigger('change');
	Grocy.FrontendHelpers.ValidateForm('quconversion-form');
});

$('#quconversion-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Grocy.FrontendHelpers.ValidateForm('quconversion-form'))
		{
			return false;
		}
		else
		{
			$('#save-quconversion-button').click();
		}
	}
});

$('.input-group-qu').on('change', function(e)
{
	var fromQuId = $("#from_qu_id").val();
	var toQuId = $("#to_qu_id").val();
	var factor = Number.parseFloat($('#factor').val());

	if (fromQuId == toQuId)
	{
		var validationMessage = __t('This cannot be equal to %s', $("#from_qu_id option:selected").text());
		$("#to_qu_id").parent().find(".invalid-feedback").text(validationMessage);
		$("#to_qu_id")[0].setCustomValidity(validationMessage);
	}
	else
	{
		$("#to_qu_id")[0].setCustomValidity("");
	}

	if (fromQuId && toQuId)
	{
		$('#qu-conversion-info').text(__t('This means 1 %1$s is the same as %2$s %3$s', $("#from_qu_id option:selected").text(), (1.0 * factor).toLocaleString({ minimumFractionDigits: 0, maximumFractionDigits: Grocy.UserSettings.stock_decimal_places_amounts }), __n((1.0 * factor).toLocaleString({ minimumFractionDigits: 0, maximumFractionDigits: Grocy.UserSettings.stock_decimal_places_amounts }), $("#to_qu_id option:selected").text(), $("#to_qu_id option:selected").data("plural-form"), true)));
		$('#qu-conversion-info').removeClass('d-none');
		$('#qu-conversion-inverse-info').removeClass('d-none');
		$('#qu-conversion-inverse-info').text(__t('This means 1 %1$s is the same as %2$s %3$s', $("#to_qu_id option:selected").text(), (1.0 / factor).toLocaleString({ minimumFractionDigits: 0, maximumFractionDigits: Grocy.UserSettings.stock_decimal_places_amounts }), __n((1.0 / factor), $("#from_qu_id option:selected").text(), $("#from_qu_id option:selected").data("plural-form"), true)));
	}
	else
	{
		$('#qu-conversion-info').addClass('d-none');
		$('#qu-conversion-inverse-info').addClass('d-none');
	}

	Grocy.FrontendHelpers.ValidateForm('quconversion-form');
});

Grocy.Components.UserfieldsForm.Load();
$('.input-group-qu').trigger('change');
Grocy.FrontendHelpers.ValidateForm('quconversion-form');
setTimeout(function()
{
	$('#from_qu_id').focus();
}, Grocy.FormFocusDelay);

if (GetUriParam("qu-unit") !== undefined)
{
	$("#from_qu_id").attr("disabled", "");
}
}
