(function ()
{
	'use strict';

	var root = document.getElementById('grocy-ai-product-enrichment');
	if (!root)
	{
		return;
	}

	var REQUEST_DEADLINE_MS = 15000;
	var ALLOWED_OUTCOMES = ['success', 'partial_image', 'not_found', 'timeout', 'provider_error', 'cancelled', 'offline'];
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

	function localized(name, fallback)
	{
		return root.dataset[name] || fallback;
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
		errorBox.classList.add('d-none');
		upcInput.classList.remove('is-invalid');
		upcInput.setAttribute('aria-invalid', 'false');
	}

	function showError(message)
	{
		errorBox.textContent = message;
		errorBox.classList.remove('d-none');
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
		var retry = ['offline', 'timeout', 'not_found', 'companion_unavailable', 'provider_error', 'partial_image'].indexOf(state) !== -1;
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
		results.replaceChildren();
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

	function feedback(message, isError)
	{
		var existing = results.querySelector('.grocy-ai-feedback');
		if (existing) existing.remove();
		var alert = textElement('div', 'grocy-ai-feedback alert ' + (isError ? 'alert-danger' : 'alert-success') + ' mt-3 mb-0', message);
		results.appendChild(alert);
	}

	function applySuggestedName(name)
	{
		if (!productNameInput || !name) return;
		productNameInput.value = name;
		productNameInput.dispatchEvent(new Event('keyup', { bubbles: true }));
		productNameInput.focus();
		feedback('Suggested product name applied. It will be saved only when you save the product.', false);
	}

	function imageFileExtension(contentType)
	{
		if (contentType === 'image/png') return 'png';
		if (contentType === 'image/webp') return 'webp';
		return 'jpg';
	}

	function useSelectedImage(candidate, card, button, gtin)
	{
		if (!candidate.download_token || !productPictureInput)
		{
			feedback('This image cannot be selected. Run the search again.', true);
			return;
		}
		button.disabled = true;
		button.textContent = 'Downloading…';
		var xhr = new XMLHttpRequest();
		xhr.open('GET', U('/api/grocy-ai/images/' + encodeURIComponent(candidate.download_token)), true);
		xhr.responseType = 'blob';
		xhr.onload = function ()
		{
			button.disabled = false;
			button.textContent = 'Use as product picture';
			if (xhr.status !== 200 || !xhr.response || !xhr.response.type.startsWith('image/'))
			{
				feedback('The selected image could not be downloaded. Search again or choose another candidate.', true);
				return;
			}
			try
			{
				var fileName = 'grocy-ai-' + gtin + '.' + imageFileExtension(xhr.response.type);
				var file = new File([xhr.response], fileName, { type: xhr.response.type });
				var transfer = new DataTransfer();
				transfer.items.add(file);
				productPictureInput.files = transfer.files;
				productPictureInput.dispatchEvent(new Event('change', { bubbles: true }));
				var pictureLabel = document.getElementById('product-picture-label');
				if (pictureLabel) pictureLabel.textContent = fileName;
				results.querySelectorAll('.grocy-ai-image-candidate-selected').forEach(function (selected)
				{
					selected.classList.remove('grocy-ai-image-candidate-selected');
				});
				card.classList.add('grocy-ai-image-candidate-selected');
				feedback('Product picture selected. It will be uploaded only when you save the product.', false);
			}
			catch (error)
			{
				feedback('This browser cannot attach the downloaded image to the form. Open the original image and upload it manually.', true);
			}
		};
		xhr.onerror = function ()
		{
			button.disabled = false;
			button.textContent = 'Use as product picture';
			feedback('The image download was interrupted.', true);
		};
		xhr.send();
	}

	function safeCandidateUrl(value)
	{
		try
		{
			var url = new URL(value, window.location.origin);
			if (url.protocol === 'http:' || url.protocol === 'https:') return url.href;
		}
		catch (error)
		{
			// Invalid provider URLs are omitted from the preview.
		}
		return null;
	}

	function renderPreview(data)
	{
		results.replaceChildren();
		var product = data.product && typeof data.product === 'object' ? data.product : {};
		var summary = document.createElement('div');
		summary.className = 'grocy-ai-summary mb-3';
		summary.appendChild(textElement('strong', '', typeof product.name === 'string' && product.name ? product.name : 'Unnamed product'));
		if (typeof product.brand === 'string' && product.brand) summary.appendChild(textElement('span', '', 'Brand: ' + product.brand));
		if (typeof product.size === 'string' && product.size) summary.appendChild(textElement('span', '', 'Size: ' + product.size));
		if (Array.isArray(data.sources) && data.sources.length > 0)
		{
			var sources = data.sources.filter(function (source) { return typeof source === 'string' && /^[a-z0-9_-]{1,40}$/i.test(source); }).slice(0, 4);
			if (sources.length > 0) summary.appendChild(textElement('small', 'text-muted', 'Sources: ' + sources.join(', ')));
		}
		if (typeof product.name === 'string' && product.name)
		{
			var applyNameButton = textElement('button', 'btn btn-sm btn-outline-primary mt-2 align-self-start', 'Apply suggested name');
			applyNameButton.type = 'button';
			applyNameButton.addEventListener('click', function () { applySuggestedName(product.name); });
			summary.appendChild(applyNameButton);
		}
		results.appendChild(summary);

		var images = Array.isArray(data.images) ? data.images.slice(0, 6) : [];
		var safeImages = images.map(function (candidate)
		{
			return candidate && typeof candidate === 'object' ? {
				safeUrl: safeCandidateUrl(candidate.url),
				download_token: typeof candidate.download_token === 'string' ? candidate.download_token : '',
				source: typeof candidate.source === 'string' && /^[a-z0-9_-]{1,40}$/i.test(candidate.source) ? candidate.source : 'Unknown source'
			} : null;
		}).filter(function (candidate) { return candidate && candidate.safeUrl !== null; });
		if (safeImages.length > 0)
		{
			results.appendChild(textElement('h5', '', 'Real image candidates'));
			var imageGrid = document.createElement('div');
			imageGrid.className = 'grocy-ai-images';
			safeImages.forEach(function (candidate)
			{
				var card = document.createElement('div');
				card.className = 'grocy-ai-image-candidate img-thumbnail';
				var originalLink = document.createElement('a');
				originalLink.href = candidate.safeUrl;
				originalLink.target = '_blank';
				originalLink.rel = 'noopener noreferrer';
				var image = document.createElement('img');
				image.src = candidate.safeUrl;
				image.alt = typeof product.name === 'string' && product.name ? product.name + ' package candidate' : 'Product package candidate';
				image.loading = 'lazy';
				image.referrerPolicy = 'no-referrer';
				originalLink.appendChild(image);
				card.appendChild(originalLink);
				card.appendChild(textElement('small', 'grocy-ai-image-source text-muted', candidate.source));
				var useButton = textElement('button', 'btn btn-sm btn-primary mt-auto', 'Use as product picture');
				useButton.type = 'button';
				useButton.disabled = !candidate.download_token;
				useButton.addEventListener('click', function () { useSelectedImage(candidate, card, useButton, data.upc); });
				card.appendChild(useButton);
				imageGrid.appendChild(card);
			});
			results.appendChild(imageGrid);
		}
		else
		{
			results.appendChild(textElement('div', 'alert alert-secondary mb-0', 'Product metadata was found, but no safe image candidates were returned.'));
		}
		results.classList.remove('d-none');
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
		var legacyOutcome = data.found === true ? 'success' : 'not_found';
		var outcome = allowed(data.outcome, ['success', 'partial_image', 'not_found', 'timeout', 'provider_error'], legacyOutcome);
		if (outcome === 'success' && data.found === true)
		{
			renderPreview(data);
			terminal(request, 'success', data, false);
		}
		else if (outcome === 'partial_image' && data.found === true)
		{
			renderPreview(data);
			terminal(request, 'partial_image', data, false);
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
		if (!activeRequest) return;
		invalidateActiveRequest(reason);
		clearResults();
		hideDiagnostics();
		renderState('ready');
	}

	function showCameraUnavailable()
	{
		setStatus('', localized('cameraUnavailable', 'Camera scanning is unavailable. Enter the GTIN manually.'), 'secondary', false, 'fa-circle-info');
		upcInput.focus();
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
