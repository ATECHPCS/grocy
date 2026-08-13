(function ()
{
	'use strict';

	var root = document.getElementById('grocy-ai-product-enrichment');
	if (!root)
	{
		return;
	}

	var REQUEST_DEADLINE_MS = 15000;
	var ALLOWED_OUTCOMES = ['success', 'partial_image', 'found', 'not_found', 'timeout', 'provider_error', 'contract_invalid', 'cancelled', 'offline'];
	var ALLOWED_STAGE_NAMES = ['browser', 'grocy_connect', 'grocy_companion', 'federation', 'open_food_facts', 'image_search', 'image_fetch'];
	var ALLOWED_STAGE_STATUSES = ['ok', 'not_found', 'timeout', 'unavailable', 'error', 'malformed', 'skipped', 'cancelled', 'offline'];
	var ALLOWED_ERROR_CODES = [null, 'deadline', 'connection', 'http_status', 'invalid_response', 'invalid_gtin', 'not_configured', 'provider_error', 'budget_exhausted', 'cancelled', 'offline'];
	var ALLOWED_CACHE_STATUSES = ['hit', 'miss', 'bypass', 'unknown'];
	var VERSION_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._+-]{0,39}$/;
	var TRACE_ID_PATTERN = /^(?!0{32}$)[0-9a-f]{32}$/;

	var upcInput = document.getElementById('grocy-ai-upc');
	var scanButton = document.getElementById('grocy-ai-scan-button');
	var searchButton = document.getElementById('grocy-ai-search-button');
	var cancelButton = document.getElementById('grocy-ai-cancel-button');
	var errorBox = document.getElementById('grocy-ai-error');
	var statusBox = document.getElementById('grocy-ai-status');
	var results = document.getElementById('grocy-ai-results');
	var productNameInput = document.getElementById('name');
	var productPictureInput = document.getElementById('product-picture');
	var requestSequence = 0;
	var activeRequest = null;
	var currentDiagnostic = null;
	var reviewState = null;
	var FIELD_LABELS = {
		name: 'Name',
		brand: 'Brand',
		package_size: 'Package size',
		product_group: 'Product group',
		quantity_unit: 'Quantity unit',
		food_type: 'Food type',
		product_image: 'Product image'
	};

	function localized(name, fallback)
	{
		return root.dataset[name] || fallback;
	}

	function translated(value)
	{
		return typeof window.__t === 'function' ? window.__t(value) : value;
	}

	function textElement(tag, className, value)
	{
		var element = document.createElement(tag);
		if (className)
		{
			element.className = className;
		}
		element.textContent = value;
		return element;
	}

	function ensureUi()
	{
		var retryButton = document.getElementById('grocy-ai-retry-button');
		if (!retryButton)
		{
			retryButton = textElement('button', 'btn btn-primary d-none', localized('retryAction', 'Retry search'));
			retryButton.type = 'button';
			retryButton.id = 'grocy-ai-retry-button';
			cancelButton.parentNode.appendChild(retryButton);
		}

		var diagnostics = document.getElementById('grocy-ai-diagnostics');
		if (!diagnostics)
		{
			diagnostics = document.createElement('details');
			diagnostics.id = 'grocy-ai-diagnostics';
			diagnostics.className = 'grocy-ai-diagnostics mt-3 d-none';
			var summary = document.createElement('summary');
			summary.id = 'grocy-ai-diagnostic-summary';
			summary.textContent = localized('diagnosticsLabel', 'Diagnostics');
			diagnostics.appendChild(summary);
			var body = document.createElement('div');
			body.className = 'grocy-ai-diagnostic-body';
			var copyButton = textElement('button', 'btn btn-outline-secondary', localized('copyDiagnosticAction', 'Copy diagnostic report'));
			copyButton.type = 'button';
			copyButton.id = 'grocy-ai-copy-diagnostic-button';
			body.appendChild(copyButton);
			var feedback = document.createElement('div');
			feedback.id = 'grocy-ai-diagnostic-feedback';
			feedback.className = 'grocy-ai-diagnostic-feedback';
			feedback.setAttribute('role', 'status');
			feedback.setAttribute('aria-live', 'polite');
			body.appendChild(feedback);
			var fallback = document.createElement('textarea');
			fallback.id = 'grocy-ai-diagnostic-fallback';
			fallback.className = 'form-control d-none';
			fallback.readOnly = true;
			fallback.rows = 8;
			fallback.setAttribute('aria-label', localized('diagnosticFallbackLabel', 'Redacted diagnostic report'));
			body.appendChild(fallback);
			statusBox.insertAdjacentElement('afterend', diagnostics);
			diagnostics.insertAdjacentElement('afterend', body);
		}
		var diagnosticBody = document.querySelector('.grocy-ai-diagnostic-body');
		if (diagnosticBody && diagnosticBody.parentNode === diagnostics)
		{
			diagnostics.insertAdjacentElement('afterend', diagnosticBody);
		}

		return {
			retryButton: retryButton,
			diagnostics: diagnostics,
			diagnosticBody: diagnosticBody,
			diagnosticSummary: document.getElementById('grocy-ai-diagnostic-summary'),
			copyButton: document.getElementById('grocy-ai-copy-diagnostic-button'),
			diagnosticFeedback: document.getElementById('grocy-ai-diagnostic-feedback'),
			diagnosticFallback: document.getElementById('grocy-ai-diagnostic-fallback')
		};
	}

	var ui = ensureUi();
	var reviewUi = {
		section: document.getElementById('grocy-ai-field-review'),
		rows: document.getElementById('grocy-ai-field-rows'),
		selectionStatus: document.getElementById('grocy-ai-selection-status'),
		reviewButton: document.getElementById('grocy-ai-review-selected-button'),
		diff: document.getElementById('grocy-ai-final-diff'),
		diffHeading: document.getElementById('grocy-ai-final-diff-heading'),
		diffList: document.getElementById('grocy-ai-final-diff-list'),
		backButton: document.getElementById('grocy-ai-back-to-suggestions-button'),
		stageButton: document.getElementById('grocy-ai-stage-selected-button'),
		stagingFeedback: document.getElementById('grocy-ai-staging-feedback')
	};

	function normalizeGtin(value)
	{
		return String(value || '').trim().replace(/[ -]/g, '');
	}

	function hasValidCheckDigit(gtin)
	{
		var sum = 0;
		var weight = 3;
		for (var index = gtin.length - 2; index >= 0; index--)
		{
			sum += Number(gtin.charAt(index)) * weight;
			weight = weight === 3 ? 1 : 3;
		}

		return (10 - (sum % 10)) % 10 === Number(gtin.charAt(gtin.length - 1));
	}

	function validateGtin(value)
	{
		var gtin = normalizeGtin(value);
		if (!/^\d+$/.test(gtin) || [8, 12, 13, 14].indexOf(gtin.length) === -1)
		{
			return { valid: false, gtin: gtin, error: 'length' };
		}
		if (!hasValidCheckDigit(gtin))
		{
			return { valid: false, gtin: gtin, error: 'checksum' };
		}

		return { valid: true, gtin: gtin, error: null };
	}

	function randomHex(byteLength)
	{
		var value = '';
		do
		{
			var bytes = new Uint8Array(byteLength);
			window.crypto.getRandomValues(bytes);
			value = Array.prototype.map.call(bytes, function (byte)
			{
				return byte.toString(16).padStart(2, '0');
			}).join('');
		}
		while (/^0+$/.test(value));
		return value;
	}

	function createTraceparent()
	{
		return '00-' + randomHex(16) + '-' + randomHex(8) + '-01';
	}

	function traceIdFrom(traceparent)
	{
		var match = /^00-([0-9a-f]{32})-([0-9a-f]{16})-0[01]$/.exec(traceparent || '');
		return match && TRACE_ID_PATTERN.test(match[1]) && !/^0+$/.test(match[2]) ? match[1] : null;
	}

	function boundedDuration(value, maximum)
	{
		return typeof value === 'number' && Number.isFinite(value)
			? Math.max(0, Math.min(maximum, Math.round(value)))
			: null;
	}

	function safeVersion(value)
	{
		return typeof value === 'string' && VERSION_PATTERN.test(value) ? value : 'unknown';
	}

	function allowed(value, values, fallback)
	{
		return values.indexOf(value) !== -1 ? value : fallback;
	}

	function safeStage(rawStage)
	{
		if (!rawStage || typeof rawStage !== 'object' || ALLOWED_STAGE_NAMES.indexOf(rawStage.name) === -1)
		{
			return null;
		}

		return {
			name: rawStage.name,
			status: allowed(rawStage.status, ALLOWED_STAGE_STATUSES, 'malformed'),
			error_code: allowed(rawStage.error_code, ALLOWED_ERROR_CODES, 'invalid_response'),
			cache: allowed(rawStage.cache, ALLOWED_CACHE_STATUSES, 'unknown'),
			duration_ms: boundedDuration(rawStage.duration_ms, 10000)
		};
	}

	function onlineState(outcome)
	{
		if (outcome === 'cancelled') return 'cancelled';
		if (outcome === 'offline') return 'offline';
		if (navigator.onLine === true) return 'online';
		if (navigator.onLine === false) return 'offline';
		return 'unknown';
	}

	function makeDiagnostic(request, outcome, rawDiagnostic, deadlineReached)
	{
		var server = rawDiagnostic && typeof rawDiagnostic === 'object' ? rawDiagnostic : {};
		var rawVersions = server.versions && typeof server.versions === 'object' ? server.versions : {};
		var stages = [{
			name: 'browser',
			status: outcome === 'cancelled' ? 'cancelled' : (outcome === 'offline' ? 'offline' : (outcome === 'timeout' ? 'timeout' : 'ok')),
			error_code: outcome === 'cancelled' ? 'cancelled' : (outcome === 'offline' ? 'offline' : (outcome === 'timeout' ? 'deadline' : null)),
			cache: 'unknown',
			duration_ms: boundedDuration(performance.now() - request.start, REQUEST_DEADLINE_MS)
		}];
		if (Array.isArray(server.stages))
		{
			server.stages.slice(0, 6).forEach(function (rawStage)
			{
				var stage = safeStage(rawStage);
				if (stage) stages.push(stage);
			});
		}
		var elapsed = boundedDuration(performance.now() - request.start, REQUEST_DEADLINE_MS);
		return {
			schema_version: 1,
			generated_at: new Date().toISOString(),
			versions: {
				grocy: safeVersion(rawVersions.grocy),
				module: safeVersion(rawVersions.module),
				companion: safeVersion(rawVersions.companion),
				contract: safeVersion(rawVersions.contract)
			},
			trace_id: traceIdFrom(request.traceparent),
			outcome: allowed(outcome, ALLOWED_OUTCOMES, 'provider_error'),
			online_state: onlineState(outcome),
			stages: stages,
			overall_duration_ms: elapsed === null ? 0 : elapsed,
			browser_deadline_reached: Boolean(deadlineReached)
		};
	}

	function diagnosticJson()
	{
		if (!currentDiagnostic) return '';
		return JSON.stringify({
			schema_version: currentDiagnostic.schema_version,
			generated_at: currentDiagnostic.generated_at,
			versions: {
				grocy: currentDiagnostic.versions.grocy,
				module: currentDiagnostic.versions.module,
				companion: currentDiagnostic.versions.companion,
				contract: currentDiagnostic.versions.contract
			},
			trace_id: currentDiagnostic.trace_id,
			outcome: currentDiagnostic.outcome,
			online_state: currentDiagnostic.online_state,
			stages: currentDiagnostic.stages.map(function (stage)
			{
				return {
					name: stage.name,
					status: stage.status,
					error_code: stage.error_code,
					cache: stage.cache,
					duration_ms: stage.duration_ms
				};
			}),
			overall_duration_ms: currentDiagnostic.overall_duration_ms,
			browser_deadline_reached: currentDiagnostic.browser_deadline_reached
		}, null, 2);
	}

	function hideDiagnostics()
	{
		currentDiagnostic = null;
		ui.diagnostics.removeAttribute('open');
		ui.diagnostics.classList.add('d-none');
		ui.diagnosticBody.classList.add('d-none');
		ui.diagnosticFeedback.textContent = '';
		ui.diagnosticFallback.value = '';
		ui.diagnosticFallback.classList.add('d-none');
	}

	function showDiagnostics(diagnostic)
	{
		currentDiagnostic = diagnostic;
		ui.diagnostics.removeAttribute('open');
		ui.diagnostics.classList.remove('d-none');
		ui.diagnosticBody.classList.remove('d-none');
		ui.diagnosticFeedback.textContent = '';
		ui.diagnosticFallback.value = '';
		ui.diagnosticFallback.classList.add('d-none');
		ui.diagnosticSummary.textContent = localized('diagnosticsLabel', 'Diagnostics') + ': '
			+ diagnostic.outcome + ' · …' + diagnostic.trace_id.slice(-8) + ' · '
			+ diagnostic.overall_duration_ms + ' ms';
	}

	function clearError()
	{
		errorBox.textContent = '';
		errorBox.classList.remove('d-block');
		errorBox.classList.add('d-none');
		upcInput.classList.remove('is-invalid');
		upcInput.setAttribute('aria-invalid', 'false');
	}

	function showError(message)
	{
		errorBox.textContent = message;
		errorBox.classList.remove('d-none');
		errorBox.classList.add('d-block');
		upcInput.classList.add('is-invalid');
		upcInput.setAttribute('aria-invalid', 'true');
		statusBox.replaceChildren();
		statusBox.className = 'grocy-ai-status alert mt-3 mb-0 alert-secondary d-none';
		statusBox.setAttribute('aria-busy', 'false');
		results.classList.add('d-none');
		hideDiagnostics();
	}

	function setStatus(heading, body, style, busy, icon)
	{
		statusBox.replaceChildren();
		statusBox.className = 'grocy-ai-status alert mt-3 mb-0 alert-' + style;
		statusBox.setAttribute('aria-busy', busy ? 'true' : 'false');
		if (icon)
		{
			var iconElement = textElement('i', 'fa-solid ' + icon + ' grocy-ai-status-icon', '');
			iconElement.setAttribute('aria-hidden', 'true');
			statusBox.appendChild(iconElement);
		}
		if (heading) statusBox.appendChild(textElement('h5', 'grocy-ai-status-heading', heading));
		if (body) statusBox.appendChild(textElement('span', '', body));
	}

	function renderState(state)
	{
		var retry = ['offline', 'timeout', 'not_found', 'companion_unavailable', 'provider_error', 'partial_image', 'contract_invalid'].indexOf(state) !== -1;
		ui.retryButton.classList.toggle('d-none', !retry);
		cancelButton.classList.toggle('d-none', state !== 'searching');
		searchButton.disabled = state === 'searching' || !validateGtin(upcInput.value).valid;
		if (state === 'searching')
		{
			setStatus('', localized('busyMessage', 'Searching product details…'), 'secondary', true, 'fa-spinner fa-spin');
		}
		else if (state === 'cancelled')
		{
			setStatus('', localized('cancelledMessage', 'Search cancelled. No changes were made.'), 'secondary', false, 'fa-circle-info');
		}
		else if (state === 'offline')
		{
			setStatus('', localized('offlineMessage', 'This phone is offline. Reconnect and retry, or continue editing manually.'), 'warning', false, 'fa-triangle-exclamation');
		}
		else if (state === 'timeout')
		{
			setStatus('', localized('timeoutMessage', 'The search took too long. Retry, or continue editing manually.'), 'warning', false, 'fa-triangle-exclamation');
		}
		else if (state === 'not_found')
		{
			setStatus('', localized('notFoundMessage', 'No exact product match was found. Check the GTIN or continue editing manually.'), 'warning', false, 'fa-triangle-exclamation');
		}
		else if (state === 'companion_unavailable')
		{
			setStatus('', localized('companionUnavailableMessage', 'Product search is temporarily unavailable. Retry, or continue editing manually.'), 'danger', false, 'fa-circle-exclamation');
		}
		else if (state === 'provider_error')
		{
			setStatus('', localized('providerErrorMessage', 'A product data provider could not respond. Retry, or continue editing manually.'), 'danger', false, 'fa-circle-exclamation');
		}
		else if (state === 'contract_invalid')
		{
			setStatus('', localized('contractError', 'Suggestions could not be verified. Retry the search, or continue editing manually. Nothing was changed.'), 'danger', false, 'fa-circle-exclamation');
		}
		else if (state === 'partial_image')
		{
			setStatus('', localized('partialImageMessage', 'Product details were found, but images are unavailable. You can continue without an image.'), 'warning', false, 'fa-triangle-exclamation');
		}
		else if (state === 'success')
		{
			setStatus(localized('successHeading', 'Product details found'), localized('successBody', 'Review the preview before applying anything. Changes are saved only when you save the product.'), 'success', false, 'fa-circle-check');
		}
		else
		{
			setStatus('', localized('readyMessage', 'GTIN ready.'), 'secondary', false, 'fa-circle-info');
		}
	}

	function clearResults()
	{
		reviewState = null;
		if (reviewUi.rows) reviewUi.rows.replaceChildren();
		if (reviewUi.diffList) reviewUi.diffList.replaceChildren();
		if (reviewUi.diff) reviewUi.diff.classList.add('d-none');
		if (reviewUi.stagingFeedback)
		{
			reviewUi.stagingFeedback.textContent = '';
			reviewUi.stagingFeedback.classList.add('d-none');
		}
		if (reviewUi.selectionStatus) reviewUi.selectionStatus.textContent = localized('selectionSummary', '%s changes selected').replace('%s', '0');
		if (reviewUi.reviewButton) reviewUi.reviewButton.disabled = true;
		results.classList.add('d-none');
	}

	function isCurrent(request)
	{
		return activeRequest === request
			&& request.sequence === requestSequence
			&& normalizeGtin(upcInput.value) === request.gtin;
	}

	function invalidateActiveRequest(reason)
	{
		requestSequence++;
		var request = activeRequest;
		activeRequest = null;
		if (!request) return null;
		request.reason = reason;
		window.clearTimeout(request.deadlineTimer);
		request.xhr.abort();
		return request;
	}

	function validateInput()
	{
		var validation = validateGtin(upcInput.value);
		if (activeRequest && validation.gtin !== activeRequest.gtin)
		{
			invalidateActiveRequest('input_changed');
		}
		clearResults();
		hideDiagnostics();
		ui.retryButton.classList.add('d-none');
		cancelButton.classList.add('d-none');
		if (!validation.valid)
		{
			searchButton.disabled = true;
			showError(validation.error === 'checksum'
				? localized('invalidChecksum', 'That GTIN has an invalid check digit. Check the number and try again.')
				: localized('invalidLength', 'Enter an 8, 12, 13, or 14 digit GTIN.'));
			return validation;
		}
		clearError();
		renderState('ready');
		return validation;
	}

	function hasExactKeys(value, expected)
	{
		if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
		return Object.keys(value).sort().join('|') === expected.slice().sort().join('|');
	}

	function validTimestamp(value)
	{
		return typeof value === 'string'
			&& /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(value)
			&& !Number.isNaN(Date.parse(value));
	}

	function validText(value)
	{
		return typeof value === 'string' && value.trim() !== '' && value.length <= 500 && !/https?:\/\//i.test(value);
	}

	function validSource(source)
	{
		return hasExactKeys(source, ['id', 'label'])
			&& ['openfoodfacts', 'bb-federation', 'searxng'].indexOf(source.id) !== -1
			&& validText(source.label);
	}

	function validTarget(target)
	{
		return target === null || (hasExactKeys(target, ['kind', 'id', 'label'])
			&& ['product_field', 'userfield', 'product_group', 'quantity_unit', 'food_type'].indexOf(target.kind) !== -1
			&& Number.isInteger(target.id) && target.id > 0
			&& validText(target.label));
	}

	function validSuggestion(suggestion)
	{
		return hasExactKeys(suggestion, ['id', 'field', 'value', 'display_value', 'source', 'confidence_band', 'reason_code', 'evidence_kind', 'retrieved_at', 'source_updated_at', 'target'])
			&& validText(suggestion.id)
			&& ['name', 'brand', 'package_size', 'product_group', 'quantity_unit', 'food_type'].indexOf(suggestion.field) !== -1
			&& validText(suggestion.value)
			&& validText(suggestion.display_value)
			&& validSource(suggestion.source)
			&& ['high', 'medium', 'low', 'unverified'].indexOf(suggestion.confidence_band) !== -1
			&& ['canonical_structured_match', 'mapped_local_option', 'inferred_provider_data', 'unverified_search_result'].indexOf(suggestion.reason_code) !== -1
			&& ['structured_direct', 'mapped', 'inferred', 'search'].indexOf(suggestion.evidence_kind) !== -1
			&& validTimestamp(suggestion.retrieved_at)
			&& (suggestion.source_updated_at === null || validTimestamp(suggestion.source_updated_at))
			&& validTarget(suggestion.target);
	}

	function validMedia(media)
	{
		return hasExactKeys(media, ['id', 'kind', 'thumbnail_handle', 'full_handle', 'source', 'confidence_band', 'reason_code', 'evidence_kind', 'retrieved_at'])
			&& validText(media.id)
			&& media.kind === 'front_package'
			&& typeof media.thumbnail_handle === 'string' && /^[A-Za-z0-9_-]{20,200}$/.test(media.thumbnail_handle)
			&& typeof media.full_handle === 'string' && /^[A-Za-z0-9_-]{20,200}$/.test(media.full_handle)
			&& validSource(media.source)
			&& ['high', 'medium', 'low', 'unverified'].indexOf(media.confidence_band) !== -1
			&& media.reason_code === 'canonical_structured_front_image'
			&& ['structured_direct', 'mapped', 'inferred', 'search'].indexOf(media.evidence_kind) !== -1
			&& validTimestamp(media.retrieved_at);
	}

	function validContract(data, requestedGtin)
	{
		if (!hasExactKeys(data, ['contract_version', 'outcome', 'barcode', 'suggestions', 'media', 'warnings', 'diagnostics'])
			|| data.contract_version !== 2
			|| ['found', 'not_found', 'timeout', 'provider_error'].indexOf(data.outcome) === -1
			|| !hasExactKeys(data.barcode, ['scanned_gtin', 'canonical_gtin', 'equivalents_checked', 'status', 'owner_product_id'])
			|| data.barcode.scanned_gtin !== requestedGtin
			|| !/^\d{14}$/.test(data.barcode.canonical_gtin)
			|| !Array.isArray(data.barcode.equivalents_checked)
			|| ['unused', 'owned_current', 'owned_other'].indexOf(data.barcode.status) === -1
			|| !Array.isArray(data.suggestions) || !Array.isArray(data.media) || !Array.isArray(data.warnings)
			|| !hasExactKeys(data.diagnostics, ['trace_id']) || !TRACE_ID_PATTERN.test(data.diagnostics.trace_id))
		{
			return false;
		}
		if (/https?:\/\//i.test(JSON.stringify(data))) return false;
		var ids = {};
		var suggestionsValid = data.suggestions.every(function (suggestion)
		{
			if (!validSuggestion(suggestion) || ids[suggestion.id]) return false;
			ids[suggestion.id] = true;
			return true;
		});
		var mediaValid = data.media.every(function (media)
		{
			if (!validMedia(media) || ids[media.id]) return false;
			ids[media.id] = true;
			return true;
		});
		var warningsValid = data.warnings.every(function (warning)
		{
			return ['image_search_unavailable', 'no_structured_record', 'no_media', 'provider_timeout', 'provider_error'].indexOf(warning) !== -1;
		});
		return suggestionsValid && mediaValid && warningsValid;
	}

	function reasonLabel(reasonCode)
	{
		return translated({
			canonical_structured_match: 'Exact canonical barcode match',
			mapped_local_option: 'Mapped to a Grocy option',
			inferred_provider_data: 'Inferred from provider data',
			unverified_search_result: 'Unverified search result',
			canonical_structured_front_image: 'Front package image'
		}[reasonCode] || '');
	}

	function confidenceLabel(confidenceBand)
	{
		return translated(confidenceBand.charAt(0).toUpperCase() + confidenceBand.slice(1) + ' confidence');
	}

	function deepFreeze(value)
	{
		if (!value || typeof value !== 'object' || Object.isFrozen(value)) return value;
		Object.keys(value).forEach(function (key) { deepFreeze(value[key]); });
		return Object.freeze(value);
	}

	function activeOption(control, id, label)
	{
		if (!control) return null;
		return Array.from(control.options).find(function (option)
		{
			return option.value === String(id) && !option.disabled && option.textContent.trim() === label;
		}) || null;
	}

	function brandControl(suggestion)
	{
		if (!suggestion.target || suggestion.target.kind !== 'userfield'
			|| String(suggestion.target.id) !== root.dataset.brandTargetId
			|| root.dataset.brandTargetName !== 'products.brand') return null;
		var matches = Array.from(document.querySelectorAll('.userfield-input')).filter(function (control)
		{
			return control.dataset.userfieldName === root.dataset.brandTargetName && control.type === 'text';
		});
		return matches.length === 1 ? matches[0] : null;
	}

	function targetAdapter(suggestion)
	{
		var control = null;
		var unavailable = '';
		var stagedValue = suggestion.value;
		if (suggestion.field === 'name' && suggestion.target === null)
		{
			control = productNameInput;
		}
		else if (suggestion.field === 'brand')
		{
			control = brandControl(suggestion);
			unavailable = localized('noFieldMessage', 'No matching Grocy field is configured.');
		}
		else if (suggestion.field === 'package_size')
		{
			unavailable = localized('noFieldMessage', 'No matching Grocy field is configured.');
		}
		else if (suggestion.field === 'product_group')
		{
			control = document.getElementById('product_group_id');
			if (!suggestion.target || suggestion.target.kind !== 'product_group'
				|| !activeOption(control, suggestion.target.id, suggestion.display_value)) control = null;
			stagedValue = suggestion.target ? String(suggestion.target.id) : '';
			unavailable = localized('noOptionMessage', 'No matching Grocy option is available.');
		}
		else if (suggestion.field === 'quantity_unit')
		{
			control = document.getElementById('qu_id_stock');
			if (!suggestion.target || suggestion.target.kind !== 'quantity_unit'
				|| !activeOption(control, suggestion.target.id, suggestion.display_value)) control = null;
			stagedValue = suggestion.target ? String(suggestion.target.id) : '';
			unavailable = localized('noOptionMessage', 'No matching Grocy option is available.');
		}
		else if (suggestion.field === 'food_type')
		{
			unavailable = localized('noFoodTypeMessage', 'No local food type is configured.');
		}

		return {
			control: control,
			available: Boolean(control),
			stagedValue: stagedValue,
			unavailable: control ? '' : unavailable
		};
	}

	function currentDisplay(row, value)
	{
		if (row.field === 'product_image') return value || translated('No picture');
		if (value === '') return localized('blankLabel', 'Blank');
		if (row.control && row.control.tagName === 'SELECT')
		{
			var option = row.control.options[row.control.selectedIndex];
			return option ? option.textContent.trim() : value;
		}
		return value;
	}

	function selectionCount()
	{
		return reviewState ? Object.keys(reviewState.rows).filter(function (field) { return reviewState.rows[field].selected; }).length : 0;
	}

	function updateSelectionSummary()
	{
		var count = selectionCount();
		reviewUi.selectionStatus.textContent = localized('selectionSummary', '%s changes selected').replace('%s', String(count));
		reviewUi.reviewButton.disabled = count === 0;
	}

	function hideFinalDiff()
	{
		if (!reviewState) return;
		reviewState.finalDiffVisible = false;
		reviewUi.diff.classList.add('d-none');
		reviewUi.diffList.replaceChildren();
	}

	function renderProvenance(container, suggestion)
	{
		container.appendChild(textElement('span', 'grocy-ai-source', suggestion.source.label));
		container.appendChild(textElement('span', 'grocy-ai-confidence badge badge-' + (suggestion.confidence_band === 'high' ? 'success' : 'secondary'), confidenceLabel(suggestion.confidence_band)));
		container.appendChild(textElement('span', 'grocy-ai-reason', reasonLabel(suggestion.reason_code)));
		container.appendChild(textElement('span', 'grocy-ai-freshness', translated('Retrieved') + ' ' + new Date(suggestion.retrieved_at).toLocaleString()));
		container.appendChild(textElement('span', 'grocy-ai-freshness', suggestion.source_updated_at === null
			? localized('sourceUpdateUnavailable', 'Source update time unavailable')
			: translated('Source updated') + ' ' + new Date(suggestion.source_updated_at).toLocaleString()));
	}

	function renderReviewRow(row)
	{
		var section = document.createElement('section');
		section.className = 'grocy-ai-field-review';
		section.dataset.grocyAiField = row.field;
		var headingId = 'grocy-ai-' + row.field.replace(/_/g, '-') + '-heading';
		var currentId = headingId + '-current';
		var suggestedId = headingId + '-suggested';
		var provenanceId = headingId + '-provenance';
		var header = document.createElement('div');
		header.className = 'grocy-ai-field-header';
		var heading = textElement('h6', '', translated(FIELD_LABELS[row.field]));
		heading.id = headingId;
		header.appendChild(heading);
		var selectionWrapper = document.createElement('div');
		selectionWrapper.className = 'custom-control custom-checkbox grocy-ai-selection-control';
		var selection = document.createElement('input');
		selection.type = 'checkbox';
		selection.className = 'custom-control-input';
		selection.id = 'grocy-ai-use-' + row.field.replace(/_/g, '-');
		selection.checked = row.selected;
		selection.disabled = !row.available;
		selection.setAttribute('aria-labelledby', headingId + ' ' + selection.id + '-label');
		selection.setAttribute('aria-describedby', currentId + ' ' + suggestedId + ' ' + provenanceId);
		selectionWrapper.appendChild(selection);
		var selectionLabel = textElement('label', 'custom-control-label', localized('selectionLabel', 'Use suggested value'));
		selectionLabel.id = selection.id + '-label';
		selectionLabel.htmlFor = selection.id;
		selectionWrapper.appendChild(selectionLabel);
		header.appendChild(selectionWrapper);
		section.appendChild(header);

		var comparison = document.createElement('div');
		comparison.className = 'grocy-ai-comparison-grid';
		var current = document.createElement('div');
		current.className = 'grocy-ai-value-cell grocy-ai-current-value';
		current.id = currentId;
		current.appendChild(textElement('strong', '', localized('currentLabel', 'Current')));
		var currentValue = textElement('span', 'grocy-ai-value', currentDisplay(row, row.currentSnapshot));
		current.appendChild(currentValue);
		var suggested = document.createElement('div');
		suggested.className = 'grocy-ai-value-cell grocy-ai-suggested-value';
		suggested.id = suggestedId;
		suggested.appendChild(textElement('strong', '', localized('suggestedLabel', 'Suggested')));
		suggested.appendChild(textElement('span', 'grocy-ai-value', row.suggestion.display_value));
		var provenance = document.createElement('div');
		provenance.className = 'grocy-ai-provenance';
		provenance.id = provenanceId;
		renderProvenance(provenance, row.suggestion);
		suggested.appendChild(provenance);
		comparison.appendChild(current);
		comparison.appendChild(suggested);
		section.appendChild(comparison);

		var origin = textElement('div', 'grocy-ai-selection-origin', row.origin === 'automatic' ? localized('automaticOrigin', 'Preselected — blank field and exact structured match') : '');
		section.appendChild(origin);
		var unavailable = textElement('div', 'grocy-ai-unavailable text-muted', row.unavailable);
		if (!row.unavailable) unavailable.classList.add('d-none');
		section.appendChild(unavailable);
		var stale = textElement('div', 'grocy-ai-stale alert alert-warning d-none', '');
		stale.setAttribute('role', 'alert');
		section.appendChild(stale);

		row.element = section;
		row.checkbox = selection;
		row.currentValueElement = currentValue;
		row.originElement = origin;
		row.staleElement = stale;
		selection.addEventListener('change', function ()
		{
			row.selected = selection.checked;
			row.origin = selection.checked ? 'explicit' : null;
			row.stale = false;
			stale.textContent = '';
			stale.classList.add('d-none');
			origin.textContent = selection.checked ? localized('explicitOrigin', 'Selected by you') : '';
			hideFinalDiff();
			updateSelectionSummary();
		});
		reviewUi.rows.appendChild(section);
	}

	function mediaReviewRow(media)
	{
		return {
			field: 'product_image',
			suggestion: {
				display_value: translated('Front package image'),
				source: media.source,
				confidence_band: media.confidence_band,
				reason_code: media.reason_code,
				retrieved_at: media.retrieved_at,
				source_updated_at: null
			},
			control: productPictureInput,
			available: false,
			unavailable: '',
			stagedValue: '',
			currentSnapshot: productPictureInput && productPictureInput.files && productPictureInput.files.length ? productPictureInput.files[0].name : '',
			selected: false,
			origin: null,
			stale: false
		};
	}

	function renderReview(data)
	{
		clearResults();
		var frozenData = deepFreeze(data);
		reviewState = { data: frozenData, rows: {}, finalDiffVisible: false, staged: false };
		frozenData.suggestions.forEach(function (suggestion)
		{
			var adapter = targetAdapter(suggestion);
			var currentValue = adapter.control ? adapter.control.value : '';
			var automatic = adapter.available && currentValue === ''
				&& suggestion.confidence_band === 'high'
				&& suggestion.evidence_kind === 'structured_direct'
				&& suggestion.reason_code === 'canonical_structured_match';
			var row = {
				field: suggestion.field,
				suggestion: suggestion,
				control: adapter.control,
				available: adapter.available,
				unavailable: adapter.unavailable,
				stagedValue: adapter.stagedValue,
				currentSnapshot: currentValue,
				selected: automatic,
				origin: automatic ? 'automatic' : null,
				stale: false
			};
			reviewState.rows[row.field] = row;
			renderReviewRow(row);
		});
		if (frozenData.media.length > 0)
		{
			var imageRow = mediaReviewRow(frozenData.media[0]);
			reviewState.rows.product_image = imageRow;
			renderReviewRow(imageRow);
		}
		if (Object.keys(reviewState.rows).length === 0)
		{
			clearResults();
			return;
		}
		updateSelectionSummary();
		results.classList.remove('d-none');
	}

	function selectedRows()
	{
		return Object.keys(reviewState.rows).map(function (field) { return reviewState.rows[field]; }).filter(function (row) { return row.selected; });
	}

	function revalidateSelectedRows()
	{
		var firstStale = null;
		selectedRows().forEach(function (row)
		{
			if (!row.control || row.field === 'product_image') return;
			var liveValue = row.control.value;
			if (liveValue === row.currentSnapshot) return;
			row.currentSnapshot = liveValue;
			row.currentValueElement.textContent = currentDisplay(row, liveValue);
			row.selected = false;
			row.origin = null;
			row.stale = true;
			row.checkbox.checked = false;
			row.originElement.textContent = '';
			row.staleElement.textContent = localized('staleFieldMessage', 'This field changed after the search. Review it again before staging.');
			row.staleElement.classList.remove('d-none');
			if (!firstStale) firstStale = row;
		});
		updateSelectionSummary();
		return firstStale;
	}

	function renderDiffList()
	{
		reviewUi.diffList.replaceChildren();
		var rows = selectedRows();
		if (rows.length === 0)
		{
			var empty = document.createElement('div');
			empty.className = 'alert alert-secondary';
			empty.appendChild(textElement('h6', '', localized('emptySelectionHeading', 'No changes selected')));
			empty.appendChild(textElement('span', '', localized('emptySelectionBody', 'Select one or more suggestions, or continue editing the product manually.')));
			reviewUi.diffList.appendChild(empty);
			return;
		}
		rows.forEach(function (row)
		{
			var item = document.createElement('section');
			item.className = 'grocy-ai-diff-item';
			item.dataset.grocyAiDiffField = row.field;
			item.appendChild(textElement('h6', '', translated(FIELD_LABELS[row.field])));
			var grid = document.createElement('div');
			grid.className = 'grocy-ai-diff-grid';
			var current = document.createElement('div');
			current.className = 'grocy-ai-value-cell';
			current.appendChild(textElement('strong', '', localized('currentLabel', 'Current')));
			current.appendChild(textElement('span', 'grocy-ai-value', currentDisplay(row, row.currentSnapshot)));
			var suggested = document.createElement('div');
			suggested.className = 'grocy-ai-value-cell';
			suggested.appendChild(textElement('strong', '', localized('suggestedLabel', 'Suggested')));
			suggested.appendChild(textElement('span', 'grocy-ai-value', row.suggestion.display_value));
			grid.appendChild(current);
			grid.appendChild(suggested);
			item.appendChild(grid);
			item.appendChild(textElement('div', 'grocy-ai-diff-provenance', row.suggestion.source.label + ' · ' + (row.origin === 'automatic' ? translated('Preselected') : localized('explicitOrigin', 'Selected by you'))));
			reviewUi.diffList.appendChild(item);
		});
	}

	function openFinalDiff()
	{
		if (!reviewState || selectionCount() === 0) return;
		var firstStale = revalidateSelectedRows();
		renderDiffList();
		reviewState.finalDiffVisible = true;
		reviewUi.diff.classList.remove('d-none');
		if (firstStale) firstStale.checkbox.focus();
		else reviewUi.diffHeading.focus();
	}

	function backToSuggestions()
	{
		hideFinalDiff();
		reviewUi.reviewButton.focus();
	}

	function stageSelectedRows()
	{
		if (!reviewState) return;
		var firstStale = revalidateSelectedRows();
		renderDiffList();
		if (firstStale)
		{
			firstStale.checkbox.focus();
			return;
		}
		selectedRows().forEach(function (row)
		{
			if (!row.control || row.field === 'product_image') return;
			row.control.value = row.stagedValue;
			row.control.dispatchEvent(new Event('input', { bubbles: true }));
			row.control.dispatchEvent(new Event('change', { bubbles: true }));
			row.currentSnapshot = row.stagedValue;
		});
		reviewState.staged = true;
		reviewUi.stagingFeedback.textContent = localized('stagingSuccess', "Selected changes are staged in the form. Review the form, then use Grocy's Save button to save them.");
		reviewUi.stagingFeedback.classList.remove('d-none');
		updateSelectionSummary();
	}

	function companionUnavailable(data)
	{
		var stages = data && data.diagnostics && Array.isArray(data.diagnostics.stages) ? data.diagnostics.stages : [];
		return stages.some(function (stage)
		{
			return stage && stage.name === 'grocy_companion'
				&& (stage.status === 'unavailable' || stage.error_code === 'connection' || stage.error_code === 'not_configured');
		});
	}

	function contractInvalidFailure(data)
	{
		var stages = data && data.diagnostics && Array.isArray(data.diagnostics.stages) ? data.diagnostics.stages : [];
		return stages.some(function (stage)
		{
			return stage && stage.error_code === 'contract_invalid';
		});
	}

	function terminal(request, state, data, deadlineReached)
	{
		if (!isCurrent(request)) return false;
		window.clearTimeout(request.deadlineTimer);
		activeRequest = null;
		if (state === 'timeout')
		{
			requestSequence++;
			request.reason = 'timeout';
			request.xhr.abort();
		}
		renderState(state);
		var outcome = state === 'companion_unavailable' ? 'provider_error' : state;
		showDiagnostics(makeDiagnostic(request, outcome, data && data.diagnostics, deadlineReached));
		return true;
	}

	function handleResponse(request)
	{
		if (!isCurrent(request)) return;
		var data;
		try
		{
			data = JSON.parse(request.xhr.responseText);
		}
		catch (error)
		{
			terminal(request, 'companion_unavailable', null, false);
			return;
		}
		if (contractInvalidFailure(data))
		{
			clearResults();
			terminal(request, 'contract_invalid', data, false);
			return;
		}
		if (request.xhr.status >= 200 && request.xhr.status < 300 && !validContract(data, request.gtin))
		{
			clearResults();
			terminal(request, 'contract_invalid', data, false);
			return;
		}
		var outcome = allowed(data.outcome, ['found', 'not_found', 'timeout', 'provider_error'], 'provider_error');
		if (outcome === 'found')
		{
			renderReview(data);
			terminal(request, 'success', data, false);
		}
		else if (outcome === 'not_found') terminal(request, 'not_found', data, false);
		else if (outcome === 'timeout') terminal(request, 'timeout', data, true);
		else if (request.xhr.status === 503 || companionUnavailable(data)) terminal(request, 'companion_unavailable', data, false);
		else terminal(request, 'provider_error', data, false);
	}

	function search(reason)
	{
		var validation = validateGtin(upcInput.value);
		if (!validation.valid)
		{
			validateInput();
			upcInput.focus();
			return;
		}
		if (activeRequest)
		{
			if (activeRequest.gtin === validation.gtin) return;
			invalidateActiveRequest('replaced');
		}
		clearError();
		clearResults();
		hideDiagnostics();
		renderState('searching');
		var xhr = new XMLHttpRequest();
		var request = {
			sequence: ++requestSequence,
			gtin: validation.gtin,
			xhr: xhr,
			reason: reason || 'search',
			start: performance.now(),
			traceparent: createTraceparent(),
			deadlineTimer: null
		};
		activeRequest = request;
		xhr.open('GET', U('/api/grocy-ai/products/enrich/upc/' + encodeURIComponent(request.gtin)), true);
		xhr.timeout = REQUEST_DEADLINE_MS;
		xhr.setRequestHeader('traceparent', request.traceparent);
		xhr.onload = function () { if (isCurrent(request)) handleResponse(request); };
		xhr.onerror = function ()
		{
			if (!isCurrent(request)) return;
			terminal(request, navigator.onLine === false ? 'offline' : 'companion_unavailable', null, false);
		};
		xhr.ontimeout = function () { terminal(request, 'timeout', null, true); };
		xhr.onabort = function ()
		{
			if (!isCurrent(request)) return;
		};
		request.deadlineTimer = window.setTimeout(function () { terminal(request, 'timeout', null, true); }, REQUEST_DEADLINE_MS);
		xhr.send();
	}

	function cancelSearch()
	{
		if (!activeRequest) return;
		var request = invalidateActiveRequest('cancelled');
		renderState('cancelled');
		showDiagnostics(makeDiagnostic(request, 'cancelled', null, false));
	}

	function lifecycleCancel(reason)
	{
		if (activeRequest) invalidateActiveRequest(reason);
		clearResults();
		hideDiagnostics();
		if (validateGtin(upcInput.value).valid) renderState('ready');
	}

	function showCameraUnavailable()
	{
		setStatus('', localized('cameraUnavailable', 'Camera scanning is unavailable. Enter the GTIN manually.'), 'secondary', false, 'fa-circle-info');
		upcInput.focus();
		window.setTimeout(function () { upcInput.focus(); }, 0);
	}

	function startCameraScan()
	{
		var cameraControl = document.querySelector('#camerabarcodescanner-start-button[data-target="grocy-ai-upc"]');
		if (!cameraControl)
		{
			showCameraUnavailable();
			return;
		}

		var delegated = false;
		function delegateOnce()
		{
			if (delegated) return;
			delegated = true;
			cameraControl.click();
		}

		if (!navigator.permissions || typeof navigator.permissions.query !== 'function')
		{
			delegateOnce();
			return;
		}

		var permissionQuery;
		try
		{
			permissionQuery = navigator.permissions.query({ name: 'camera' });
		}
		catch (error)
		{
			delegateOnce();
			return;
		}

		Promise.resolve(permissionQuery).then(function (permissionStatus)
		{
			if (!permissionStatus || permissionStatus.state === 'denied')
			{
				showCameraUnavailable();
				return;
			}

			if (typeof permissionStatus.addEventListener === 'function')
			{
				var handlePermissionChange = function ()
				{
					permissionStatus.removeEventListener('change', handlePermissionChange);
					if (permissionStatus.state === 'denied') showCameraUnavailable();
				};
				permissionStatus.addEventListener('change', handlePermissionChange);
			}
			delegateOnce();
		}).catch(delegateOnce);
	}

	function copyDiagnostic()
	{
		var report = diagnosticJson();
		if (!report) return;
		var clipboard = navigator.clipboard && navigator.clipboard.writeText;
		var write = clipboard ? navigator.clipboard.writeText(report) : Promise.reject(new Error('clipboard unavailable'));
		write.then(function ()
		{
			ui.diagnosticFeedback.textContent = localized('diagnosticCopySuccess', 'Diagnostic report copied.');
			ui.diagnosticFallback.classList.add('d-none');
		}).catch(function ()
		{
			ui.diagnosticFallback.value = report;
			ui.diagnosticFallback.classList.remove('d-none');
			ui.diagnosticFallback.focus();
			ui.diagnosticFallback.select();
			ui.diagnosticFeedback.textContent = localized('diagnosticCopyFallback', 'Copy was blocked. Select and copy the redacted report manually.');
		});
	}

	searchButton.addEventListener('click', function () { search('search'); });
	if (reviewUi.reviewButton) reviewUi.reviewButton.addEventListener('click', openFinalDiff);
	if (reviewUi.backButton) reviewUi.backButton.addEventListener('click', backToSuggestions);
	if (reviewUi.stageButton) reviewUi.stageButton.addEventListener('click', stageSelectedRows);
	ui.retryButton.addEventListener('click', function () { search('retry'); });
	cancelButton.addEventListener('click', cancelSearch);
	ui.copyButton.addEventListener('click', copyDiagnostic);
	scanButton.addEventListener('click', startCameraScan);
	upcInput.addEventListener('input', validateInput);
	upcInput.addEventListener('keydown', function (event)
	{
		if (event.key === 'Enter')
		{
			event.preventDefault();
			if (validateGtin(upcInput.value).valid) search('keyboard');
			else validateInput();
		}
		else if (event.key === 'Escape' && activeRequest)
		{
			event.preventDefault();
			cancelSearch();
		}
	});

	$(document).on('Grocy.BarcodeScanned', function (event, barcode, target)
	{
		if (target !== 'grocy-ai-upc') return;
		var nextGtin = normalizeGtin(barcode);
		if (activeRequest && activeRequest.gtin === nextGtin) return;
		upcInput.value = String(barcode || '');
		var validation = validateInput();
		if (validation.valid) search('scan');
	});

	window.addEventListener('pagehide', function () { lifecycleCancel('pagehide'); });
	window.addEventListener('orientationchange', function () { lifecycleCancel('orientationchange'); });
	document.addEventListener('visibilitychange', function ()
	{
		if (document.visibilityState === 'hidden') lifecycleCancel('hidden');
	});
	window.addEventListener('pageshow', function ()
	{
		if (!activeRequest && validateGtin(upcInput.value).valid && currentDiagnostic === null) renderState('ready');
	});
	window.addEventListener('online', function ()
	{
		// Reconnect is informational only. Retry remains an explicit user action.
	});

	if (normalizeGtin(upcInput.value)) validateInput();
	else
	{
		searchButton.disabled = true;
		upcInput.setAttribute('aria-invalid', 'false');
	}
})();
