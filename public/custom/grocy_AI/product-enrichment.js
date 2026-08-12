(function ()
{
	'use strict';

	var root = document.getElementById('grocy-ai-product-enrichment');
	if (!root)
	{
		return;
	}

	var upcInput = document.getElementById('grocy-ai-upc');
	var searchButton = document.getElementById('grocy-ai-search-button');
	var errorBox = document.getElementById('grocy-ai-error');
	var results = document.getElementById('grocy-ai-results');
	var productNameInput = document.getElementById('name');
	var productPictureInput = document.getElementById('product-picture');

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

	function showError(message)
	{
		errorBox.textContent = message;
		errorBox.classList.remove('d-none');
		results.classList.add('d-none');
	}

	function clearOutput()
	{
		errorBox.textContent = '';
		errorBox.classList.add('d-none');
		results.replaceChildren();
		results.classList.add('d-none');
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

	function renderResult(data)
	{
		results.replaceChildren();

		if (!data.found)
		{
			results.appendChild(textElement('div', 'alert alert-warning mb-0', 'No exact product match was found for ' + data.upc + '.'));
			results.classList.remove('d-none');
			return;
		}

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
		if (images.length > 0)
		{
			results.appendChild(textElement('h5', '', 'Real image candidates'));
			var imageGrid = document.createElement('div');
			imageGrid.className = 'grocy-ai-images';

			images.forEach(function (candidate)
			{
				var card = document.createElement('div');
				card.className = 'grocy-ai-image-candidate img-thumbnail';

				var originalLink = document.createElement('a');
				originalLink.href = candidate.url;
				originalLink.target = '_blank';
				originalLink.rel = 'noopener noreferrer';
				var image = document.createElement('img');
				image.src = candidate.url;
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

	function search()
	{
		clearOutput();
		var upc = upcInput.value.trim().replace(/[ -]/g, '');
		if (!/^(\d{8}|\d{12,14})$/.test(upc))
		{
			showError('Enter an 8, 12, 13, or 14 digit UPC, EAN, or GTIN.');
			return;
		}

		searchButton.disabled = true;
		var originalButtonContent = searchButton.innerHTML;
		searchButton.textContent = 'Searching…';

		Grocy.Api.Get('grocy-ai/products/enrich/upc/' + encodeURIComponent(upc), function (data)
		{
			searchButton.disabled = false;
			searchButton.innerHTML = originalButtonContent;
			renderResult(data);
		}, function (xhr)
		{
			searchButton.disabled = false;
			searchButton.innerHTML = originalButtonContent;
			var message = 'Product enrichment failed.';
			try
			{
				message = JSON.parse(xhr.responseText).error_message || message;
			}
			catch (error)
			{
				// Keep the generic message when the server response is not JSON.
			}
			showError(message);
		});
	}

	searchButton.addEventListener('click', search);
	upcInput.addEventListener('keydown', function (event)
	{
		if (event.key === 'Enter')
		{
			event.preventDefault();
			search();
		}
	});
})();
