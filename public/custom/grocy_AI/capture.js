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
		root.GrocyAICapture = api;
		if (root.document)
		{
			api.attachCapture(root.document);
		}
	}
})(typeof window !== 'undefined' ? window : null, function ()
{
	'use strict';

	// The closed capture-line DTO the scan endpoint returns (pinned by the Phase 8 contract, Plan 08-01).
	var LINE_KEYS = ['id', 'trip_id', 'seq', 'scanned_barcode', 'canonical_gtin', 'resolved_product_id', 'status', 'quantity', 'price', 'best_before_override', 'selected', 'applied_at', 'outcome', 'created_at', 'updated_at'];
	var LINE_STATUSES = ['known', 'unknown', 'conflict'];

	var DEFAULT_COPY = {
		known: 'Known',
		unknown: 'Unknown — needs product',
		productFallback: 'Product #%s',
		tripStarted: 'Trip started. Scan or enter a GTIN to add items.',
		tripError: 'Could not start a capture trip. Reload the page to try again.',
		scanError: 'That scan could not be added. Try again.',
		empty: 'No items yet. Scan or enter a GTIN above.',
		quantity: 'Quantity'
	};

	/**
	 * Validate that a payload is exactly the closed capture-line DTO with a known status vocabulary. Any
	 * missing/extra key or an out-of-vocabulary status is rejected, so a malformed response never renders.
	 */
	function isLinePayload(value)
	{
		if (!value || typeof value !== 'object' || Array.isArray(value))
		{
			return false;
		}
		var keys = Object.keys(value).sort();
		if (keys.join('|') !== LINE_KEYS.slice().sort().join('|'))
		{
			return false;
		}
		return LINE_STATUSES.indexOf(value.status) !== -1;
	}

	/**
	 * Coalesce a returned line into the live list: replace the same line id in place (a same-barcode scan
	 * that incremented its quantity server-side) or append a new one, always kept ordered by seq. This
	 * mirrors the server's COALESCE(canonical_gtin, scanned_barcode) coalescing on the client.
	 */
	function upsertLines(lines, line)
	{
		var next = lines.slice();
		var replaced = false;
		for (var i = 0; i < next.length; i++)
		{
			if (String(next[i].id) === String(line.id))
			{
				next[i] = line;
				replaced = true;
				break;
			}
		}
		if (!replaced)
		{
			next.push(line);
		}
		next.sort(function (a, b) { return Number(a.seq) - Number(b.seq); });
		return next;
	}

	function describeQuantity(quantity)
	{
		var value = Number(quantity);
		return Number.isFinite(value) ? String(value) : '1';
	}

	/**
	 * The per-line display model: a `known` line shows its resolved product name (or a stable
	 * `Product #<id>` fallback until the name resolves); an `unknown` (or `conflict`) line shows the
	 * needs-product prompt. Returns the primary label and the line status for styling.
	 */
	function lineLabel(line, productName, copy)
	{
		var strings = copy || DEFAULT_COPY;
		if (line.status === 'known')
		{
			var name = typeof productName === 'string' && productName !== '' ? productName : null;
			if (name === null)
			{
				name = strings.productFallback.replace('%s', String(line.resolved_product_id));
			}
			return { status: 'known', text: name };
		}
		return { status: line.status, text: strings.unknown };
	}

	function readCopy(root)
	{
		if (!root || !root.dataset)
		{
			return DEFAULT_COPY;
		}
		var d = root.dataset;
		return {
			known: d.labelKnown || DEFAULT_COPY.known,
			unknown: d.labelUnknown || DEFAULT_COPY.unknown,
			productFallback: d.labelProductFallback || DEFAULT_COPY.productFallback,
			tripStarted: d.labelTripStarted || DEFAULT_COPY.tripStarted,
			tripError: d.labelTripError || DEFAULT_COPY.tripError,
			scanError: d.labelScanError || DEFAULT_COPY.scanError,
			empty: d.labelEmpty || DEFAULT_COPY.empty,
			quantity: d.labelQuantity || DEFAULT_COPY.quantity
		};
	}

	/**
	 * Wire the scan-loop page: start a trip on load, then append/increment lines live as each scan (camera
	 * or manual) POSTs to the STOCK_PURCHASE-gated capture endpoints. No stock is ever written from here.
	 */
	function attachCapture(document)
	{
		var root = document.getElementById('grocyai-capture');
		if (!root)
		{
			return null;
		}

		var tripsEndpoint = root.getAttribute('data-trips-endpoint') || '';
		var copy = readCopy(root);
		var input = document.getElementById('grocyai-capture-barcode');
		var addButton = document.getElementById('grocyai-capture-add-button');
		var newTripButton = document.getElementById('grocyai-capture-new-trip-button');
		var statusEl = document.getElementById('grocyai-capture-status');
		var linesEl = document.getElementById('grocyai-capture-lines');

		var currentTripId = null;
		var lines = [];
		var productNames = {};

		function setStatus(message)
		{
			if (statusEl)
			{
				statusEl.textContent = message;
			}
		}

		function fetchJson(url, requestOptions)
		{
			return fetch(url, Object.assign({
				credentials: 'same-origin',
				cache: 'no-store',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json' }
			}, requestOptions || {})).then(function (response)
			{
				if (!response.ok)
				{
					throw new Error('http_status');
				}
				return response.json();
			});
		}

		function render()
		{
			if (!linesEl)
			{
				return;
			}
			linesEl.textContent = '';
			if (lines.length === 0)
			{
				var emptyItem = document.createElement('li');
				emptyItem.className = 'list-group-item text-muted grocy-ai-capture-empty';
				emptyItem.textContent = copy.empty;
				linesEl.appendChild(emptyItem);
				return;
			}
			lines.forEach(function (line)
			{
				var label = lineLabel(line, productNames[String(line.resolved_product_id)], copy);
				var item = document.createElement('li');
				item.className = 'list-group-item d-flex justify-content-between align-items-center grocy-ai-capture-line status-' + label.status;
				item.setAttribute('data-line-id', String(line.id));

				var name = document.createElement('span');
				name.className = 'grocy-ai-capture-line-name';
				name.textContent = label.text;

				var quantity = document.createElement('span');
				quantity.className = 'badge badge-pill grocy-ai-capture-line-quantity';
				quantity.textContent = '× ' + describeQuantity(line.quantity);
				quantity.setAttribute('aria-label', copy.quantity + ' ' + describeQuantity(line.quantity));

				item.appendChild(name);
				item.appendChild(quantity);
				linesEl.appendChild(item);
			});
		}

		function resolveProductName(productId)
		{
			if (productId === null || productId === undefined)
			{
				return;
			}
			var key = String(productId);
			if (Object.prototype.hasOwnProperty.call(productNames, key))
			{
				return;
			}
			productNames[key] = null;
			if (typeof window !== 'undefined' && window.Grocy && window.Grocy.Api && typeof window.Grocy.Api.Get === 'function')
			{
				window.Grocy.Api.Get('objects/products/' + encodeURIComponent(key),
					function (product)
					{
						productNames[key] = product && typeof product.name === 'string' ? product.name : null;
						render();
					},
					function () { productNames[key] = null; });
			}
		}

		function startTrip()
		{
			return fetchJson(tripsEndpoint, { method: 'POST', body: '{}' }).then(function (trip)
			{
				currentTripId = trip && trip.id !== undefined ? trip.id : null;
				lines = [];
				productNames = {};
				render();
				setStatus(copy.tripStarted);
				focusInput();
			}).catch(function ()
			{
				currentTripId = null;
				setStatus(copy.tripError);
			});
		}

		function submitBarcode(rawBarcode)
		{
			var barcode = String(rawBarcode === undefined || rawBarcode === null ? '' : rawBarcode).trim();
			if (barcode === '' || currentTripId === null)
			{
				return Promise.resolve(null);
			}
			var url = tripsEndpoint + '/' + encodeURIComponent(String(currentTripId)) + '/scan';
			return fetchJson(url, { method: 'POST', body: JSON.stringify({ barcode: barcode }) }).then(function (line)
			{
				if (!isLinePayload(line))
				{
					setStatus(copy.scanError);
					return null;
				}
				lines = upsertLines(lines, line);
				render();
				if (line.status === 'known')
				{
					resolveProductName(line.resolved_product_id);
				}
				clearInput();
				return line;
			}).catch(function ()
			{
				setStatus(copy.scanError);
				return null;
			});
		}

		function clearInput()
		{
			if (input)
			{
				input.value = '';
				focusInput();
			}
		}

		function focusInput()
		{
			if (input && typeof input.focus === 'function')
			{
				input.focus();
			}
		}

		if (addButton)
		{
			addButton.addEventListener('click', function () { submitBarcode(input ? input.value : ''); });
		}
		if (newTripButton)
		{
			newTripButton.addEventListener('click', function () { startTrip(); });
		}
		if (input)
		{
			input.addEventListener('keydown', function (event)
			{
				if (event.key === 'Enter')
				{
					event.preventDefault();
					submitBarcode(input.value);
				}
			});
		}

		// Reuse the enrichment card's camera scanner path: the core camera control fires this jQuery event
		// with the input's data-target; we filter to this page's input and submit exactly as a manual add.
		if (typeof window !== 'undefined' && typeof window.$ === 'function')
		{
			window.$(document).on('Grocy.BarcodeScanned', function (event, barcode, target)
			{
				if (target !== 'grocyai-capture-barcode')
				{
					return;
				}
				submitBarcode(barcode);
			});
		}

		startTrip();

		return {
			startTrip: startTrip,
			submitBarcode: submitBarcode
		};
	}

	return {
		LINE_KEYS: LINE_KEYS,
		LINE_STATUSES: LINE_STATUSES,
		DEFAULT_COPY: DEFAULT_COPY,
		isLinePayload: isLinePayload,
		upsertLines: upsertLines,
		describeQuantity: describeQuantity,
		lineLabel: lineLabel,
		attachCapture: attachCapture
	};
});
