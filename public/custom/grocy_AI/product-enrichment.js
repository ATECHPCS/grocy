(function ()
{
	'use strict';

	var root = document.getElementById('grocy-ai-product-enrichment');
	if (!root)
	{
		return;
	}

	var upcInput = document.getElementById('grocy-ai-upc');
	var scanButton = document.getElementById('grocy-ai-scan-button');
	var searchButton = document.getElementById('grocy-ai-search-button');
	var cancelButton = document.getElementById('grocy-ai-cancel-button');
	var errorBox = document.getElementById('grocy-ai-error');
	var statusBox = document.getElementById('grocy-ai-status');
	var results = document.getElementById('grocy-ai-results');
	var productNameInput = document.getElementById('name');
	var productPictureInput = document.getElementById('product-picture');
	var activeRequest = null;

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

	function clearError()
	{
		errorBox.textContent = '';
		errorBox.classList.add('d-none');
		upcInput.setAttribute('aria-invalid', 'false');
	}

	function showError(message)
	{
		errorBox.textContent = message;
		errorBox.classList.remove('d-none');
		upcInput.setAttribute('aria-invalid', 'true');
		statusBox.replaceChildren();
		statusBox.classList.add('d-none');
		statusBox.setAttribute('aria-busy', 'false');
		results.classList.add('d-none');
	}

	function setStatus(heading, body, state, busy)
	{
		statusBox.replaceChildren();
		statusBox.className = 'grocy-ai-status alert mt-3 mb-0 alert-' + state;
		statusBox.setAttribute('aria-busy', busy ? 'true' : 'false');

		if (heading)
		{
			statusBox.appendChild(textElement('h5', 'grocy-ai-status-heading', heading));
		}
		if (body)
		{
			statusBox.appendChild(textElement('span', '', body));
		}
	}

	function clearResults()
	{
		results.replaceChildren();
		results.classList.add('d-none');
	}

	function setSearching(searching)
	{
		searchButton.disabled = searching;
		cancelButton.classList.toggle('d-none', !searching);
	}

	function validateInput()
	{
		var validation = validateGtin(upcInput.value);
		clearResults();
		if (!validation.valid)
		{
			searchButton.disabled = true;
			showError(validation.error === 'checksum'
				? localized('invalidChecksum', 'That GTIN has an invalid check digit. Check the number and try again.')
				: localized('invalidLength', 'Enter an 8, 12, 13, or 14 digit GTIN.'));
			return validation;
		}

		clearError();
		searchButton.disabled = activeRequest !== null;
		setStatus('', localized('readyMessage', 'GTIN ready.'), 'secondary', false);
		return validation;
	}

	function feedback(message, isError)
	{
		var existing = results.querySelector('.grocy-ai-feedback');
		if (existing)
		{
			existing.remove();
		}
		var alert = textElement('div', 'grocy-ai-feedback alert ' + (isError ? 'alert-danger' : 'alert-success') + ' mt-3 mb-0', message);
		results.appendChild(alert);
	}

	function applySuggestedName(name)
	{
		if (!productNameInput || !name)
		{
			return;
		}
		productNameInput.value = name;
		productNameInput.dispatchEvent(new Event('keyup', { bubbles: true }));
		productNameInput.focus();
		feedback('Suggested product name applied. It will be saved only when you save the product.', false);
	}

	function imageFileExtension(contentType)
	{
		if (contentType === 'image/png')
		{
			return 'png';
		}
		if (contentType === 'image/webp')
		{
			return 'webp';
		}
		return 'jpg';
	}

	function useSelectedImage(candidate, card, button, upc)
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
				var fileName = 'grocy-ai-' + upc + '.' + imageFileExtension(xhr.response.type);
				var file = new File([xhr.response], fileName, { type: xhr.response.type });
				var transfer = new DataTransfer();
				transfer.items.add(file);
				productPictureInput.files = transfer.files;
				productPictureInput.dispatchEvent(new Event('change', { bubbles: true }));
				var pictureLabel = document.getElementById('product-picture-label');
				if (pictureLabel)
				{
					pictureLabel.textContent = fileName;
				}
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
			if (url.protocol === 'http:' || url.protocol === 'https:')
			{
				return url.href;
			}
		}
		catch (error)
		{
			// Invalid provider URLs are omitted from the preview.
		}
		return null;
	}

	function renderResult(data)
	{
		results.replaceChildren();

		if (!data.found)
		{
			setStatus('', localized('notFoundMessage', 'No exact product match was found. Check the GTIN or continue editing manually.'), 'warning', false);
			return;
		}

		setStatus(
			localized('successHeading', 'Product details found'),
			localized('successBody', 'Review the preview before applying anything. Changes are saved only when you save the product.'),
			'success',
			false
		);

		var product = data.product || {};
		var summary = document.createElement('div');
		summary.className = 'grocy-ai-summary mb-3';
		summary.appendChild(textElement('strong', '', product.name || 'Unnamed product'));
		if (product.brand)
		{
			summary.appendChild(textElement('span', '', 'Brand: ' + product.brand));
		}
		if (product.size)
		{
			summary.appendChild(textElement('span', '', 'Size: ' + product.size));
		}
		if (Array.isArray(data.sources) && data.sources.length > 0)
		{
			summary.appendChild(textElement('small', 'text-muted', 'Sources: ' + data.sources.join(', ')));
		}
		if (product.name)
		{
			var applyNameButton = textElement('button', 'btn btn-sm btn-outline-primary mt-2 align-self-start', 'Apply suggested name');
			applyNameButton.type = 'button';
			applyNameButton.addEventListener('click', function ()
			{
				applySuggestedName(product.name);
			});
			summary.appendChild(applyNameButton);
		}
		results.appendChild(summary);

		var images = Array.isArray(data.images) ? data.images.slice(0, 6) : [];
		var safeImages = images.filter(function (candidate)
		{
			candidate.safeUrl = safeCandidateUrl(candidate.url);
			return candidate.safeUrl !== null;
		});
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
				image.alt = product.name ? product.name + ' package candidate' : 'Product package candidate';
				image.loading = 'lazy';
				image.referrerPolicy = 'no-referrer';
				originalLink.appendChild(image);
				card.appendChild(originalLink);
				card.appendChild(textElement('small', 'grocy-ai-image-source text-muted', candidate.source || 'Unknown source'));
				var useButton = textElement('button', 'btn btn-sm btn-primary mt-auto', 'Use as product picture');
				useButton.type = 'button';
				useButton.disabled = !candidate.download_token;
				useButton.addEventListener('click', function ()
				{
					useSelectedImage(candidate, card, useButton, data.upc);
				});
				card.appendChild(useButton);
				imageGrid.appendChild(card);
			});

			results.appendChild(imageGrid);
		}
		else
		{
			results.appendChild(textElement('div', 'alert alert-secondary mb-0', 'Product metadata was found, but no safe image candidates were returned.'));
		}

		if (Array.isArray(data.warnings) && data.warnings.length > 0)
		{
			results.appendChild(textElement('small', 'd-block text-warning mt-3', data.warnings.join(' ')));
		}

		results.classList.remove('d-none');
	}

	function finishRequest(xhr)
	{
		if (activeRequest !== xhr)
		{
			return false;
		}
		activeRequest = null;
		setSearching(false);
		return true;
	}

	function search()
	{
		var validation = validateGtin(upcInput.value);
		if (!validation.valid)
		{
			validateInput();
			upcInput.focus();
			return;
		}
		if (activeRequest !== null)
		{
			return;
		}

		clearError();
		clearResults();
		setSearching(true);
		setStatus('', localized('busyMessage', 'Searching product details…'), 'secondary', true);

		var gtin = validation.gtin;
		var xhr = new XMLHttpRequest();
		activeRequest = xhr;
		xhr.open('GET', U('/api/grocy-ai/products/enrich/upc/' + encodeURIComponent(gtin)), true);
		xhr.timeout = 15000;
		xhr.onload = function ()
		{
			if (!finishRequest(xhr) || normalizeGtin(upcInput.value) !== gtin)
			{
				return;
			}
			if (xhr.status < 200 || xhr.status >= 300)
			{
				setStatus('', localized('errorMessage', 'Product search is temporarily unavailable. Retry, or continue editing manually.'), 'danger', false);
				return;
			}

			try
			{
				renderResult(JSON.parse(xhr.responseText));
			}
			catch (error)
			{
				setStatus('', localized('errorMessage', 'Product search is temporarily unavailable. Retry, or continue editing manually.'), 'danger', false);
			}
		};
		xhr.onerror = function ()
		{
			if (finishRequest(xhr))
			{
				setStatus('', localized('errorMessage', 'Product search is temporarily unavailable. Retry, or continue editing manually.'), 'danger', false);
			}
		};
		xhr.ontimeout = function ()
		{
			if (finishRequest(xhr))
			{
				setStatus('', localized('timeoutMessage', 'The search took too long. Retry, or continue editing manually.'), 'warning', false);
			}
		};
		xhr.onabort = function ()
		{
			if (finishRequest(xhr))
			{
				setStatus('', localized('cancelledMessage', 'Search cancelled. No changes were made.'), 'secondary', false);
			}
		};
		xhr.send();
	}

	function cancelSearch()
	{
		if (activeRequest)
		{
			activeRequest.abort();
		}
	}

	searchButton.addEventListener('click', search);
	cancelButton.addEventListener('click', cancelSearch);
	scanButton.addEventListener('click', function ()
	{
		var cameraControl = document.querySelector('#camerabarcodescanner-start-button[data-target="grocy-ai-upc"]');
		if (cameraControl)
		{
			cameraControl.click();
			return;
		}
		setStatus('', localized('cameraUnavailable', 'Camera scanning is unavailable. Enter the GTIN manually.'), 'secondary', false);
	});
	upcInput.addEventListener('input', validateInput);
	upcInput.addEventListener('keydown', function (event)
	{
		if (event.key === 'Enter')
		{
			event.preventDefault();
			search();
		}
		else if (event.key === 'Escape')
		{
			cancelSearch();
		}
	});

	$(document).on('Grocy.BarcodeScanned', function (event, barcode, target)
	{
		if (target !== 'grocy-ai-upc')
		{
			return;
		}
		upcInput.value = String(barcode || '');
		var validation = validateInput();
		if (validation.valid)
		{
			search();
		}
	});

	if (normalizeGtin(upcInput.value))
	{
		validateInput();
	}
	else
	{
		searchButton.disabled = true;
		upcInput.setAttribute('aria-invalid', 'false');
	}
})();
