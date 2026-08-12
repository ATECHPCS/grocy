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
		results.appendChild(summary);

		var images = Array.isArray(data.images) ? data.images.slice(0, 6) : [];
		if (images.length > 0)
		{
			results.appendChild(textElement('h5', '', 'Real image candidates'));
			var imageGrid = document.createElement('div');
			imageGrid.className = 'grocy-ai-images';

			images.forEach(function (candidate)
			{
				var card = document.createElement('a');
				card.className = 'grocy-ai-image-candidate img-thumbnail';
				card.href = candidate.url;
				card.target = '_blank';
				card.rel = 'noopener noreferrer';

				var image = document.createElement('img');
				image.src = candidate.url;
				image.alt = product.name ? product.name + ' package candidate' : 'Product package candidate';
				image.loading = 'lazy';
				image.referrerPolicy = 'no-referrer';
				card.appendChild(image);
				card.appendChild(textElement('small', 'grocy-ai-image-source text-muted', candidate.source || 'Unknown source'));
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
