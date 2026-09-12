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
		root.GrocyAICaptureReview = api;
		if (root.document)
		{
			api.attachCaptureReview(root.document);
		}
	}
})(typeof window !== 'undefined' ? window : null, function ()
{
	'use strict';

	var TRIP_KEYS = ['id', 'created_at', 'created_by', 'status', 'default_location_id', 'default_shopping_location_id', 'transaction_id', 'committed_at', 'checksum', 'module_version'];
	var LINE_KEYS = ['id', 'trip_id', 'seq', 'scanned_barcode', 'canonical_gtin', 'resolved_product_id', 'status', 'quantity', 'price', 'best_before_override', 'selected', 'applied_at', 'outcome', 'created_at', 'updated_at'];
	var LINE_STATUSES = ['known', 'unknown', 'conflict'];

	var DEFAULT_COPY = {
		known: 'Known',
		unknown: 'Unknown — needs product',
		productFallback: 'Product #%s',
		createProduct: 'Create product',
		noTrips: 'No trips yet. Capture one first.',
		noTripSelected: 'Select a trip to review its items.',
		emptyLines: 'This trip has no items.',
		loadError: 'Could not load the trip. Try again.',
		saveError: 'That change could not be saved. Try again.',
		deleteLabel: 'Delete',
		selected: 'Include in purchase',
		quantity: 'Quantity',
		price: 'Price (optional)',
		location: 'Default location',
		store: 'Default store',
		none: '(none)',
		markReviewing: 'Mark as reviewing',
		status: 'Status',
		commit: 'Commit purchase',
		committed: 'Committed — transaction %s',
		commitPartial: 'Committed %s item(s); unresolved items remain in the trip.',
		commitMismatch: 'The trip changed since it was loaded. Reload and commit again.',
		commitConfirm: 'Commit the selected known items to stock as one purchase?'
	};

	function hasClosedKeys(value, keys)
	{
		if (!value || typeof value !== 'object' || Array.isArray(value))
		{
			return false;
		}
		return Object.keys(value).sort().join('|') === keys.slice().sort().join('|');
	}

	function isTripPayload(value)
	{
		return hasClosedKeys(value, TRIP_KEYS) && ['open', 'reviewing', 'committed'].indexOf(value.status) !== -1;
	}

	function isLinePayload(value)
	{
		return hasClosedKeys(value, LINE_KEYS) && LINE_STATUSES.indexOf(value.status) !== -1;
	}

	function isTripListPayload(value)
	{
		return value && typeof value === 'object' && Array.isArray(value.trips) && value.trips.every(isTripPayload);
	}

	function isLoadedTripPayload(value)
	{
		return value && typeof value === 'object' && !Array.isArray(value)
			&& isTripPayload(value.trip) && Array.isArray(value.lines) && value.lines.every(isLinePayload);
	}

	function productNewUrl(base, barcode)
	{
		return String(base) + '?barcode=' + encodeURIComponent(String(barcode));
	}

	function describeQuantity(quantity)
	{
		var value = Number(quantity);
		return Number.isFinite(value) ? String(value) : '1';
	}

	function lineLabel(line, productName, copy)
	{
		var strings = copy || DEFAULT_COPY;
		if (line.status === 'known')
		{
			var name = typeof productName === 'string' && productName !== '' ? productName : null;
			return { status: 'known', text: name === null ? strings.productFallback.replace('%s', String(line.resolved_product_id)) : name };
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
			createProduct: d.labelCreateProduct || DEFAULT_COPY.createProduct,
			noTrips: d.labelNoTrips || DEFAULT_COPY.noTrips,
			noTripSelected: d.labelNoTripSelected || DEFAULT_COPY.noTripSelected,
			emptyLines: d.labelEmptyLines || DEFAULT_COPY.emptyLines,
			loadError: d.labelLoadError || DEFAULT_COPY.loadError,
			saveError: d.labelSaveError || DEFAULT_COPY.saveError,
			deleteLabel: d.labelDelete || DEFAULT_COPY.deleteLabel,
			selected: d.labelSelected || DEFAULT_COPY.selected,
			quantity: d.labelQuantity || DEFAULT_COPY.quantity,
			price: d.labelPrice || DEFAULT_COPY.price,
			location: d.labelLocation || DEFAULT_COPY.location,
			store: d.labelStore || DEFAULT_COPY.store,
			none: d.labelNone || DEFAULT_COPY.none,
			markReviewing: d.labelMarkReviewing || DEFAULT_COPY.markReviewing,
			status: d.labelStatus || DEFAULT_COPY.status,
			commit: d.labelCommit || DEFAULT_COPY.commit,
			committed: d.labelCommitted || DEFAULT_COPY.committed,
			commitPartial: d.labelCommitPartial || DEFAULT_COPY.commitPartial,
			commitMismatch: d.labelCommitMismatch || DEFAULT_COPY.commitMismatch,
			commitConfirm: d.labelCommitConfirm || DEFAULT_COPY.commitConfirm
		};
	}

	function attachCaptureReview(document)
	{
		var root = document.getElementById('grocyai-capture-review');
		if (!root)
		{
			return null;
		}

		var tripsEndpoint = root.getAttribute('data-trips-endpoint') || '';
		var productNewBase = root.getAttribute('data-product-new-url') || '';
		var tripIdRaw = root.getAttribute('data-trip-id') || '';
		var copy = readCopy(root);
		var tripsListEl = document.getElementById('grocyai-capture-review-trips');
		var detailEl = document.getElementById('grocyai-capture-review-detail');

		var currentTripId = /^[1-9][0-9]{0,9}$/.test(tripIdRaw) ? tripIdRaw : null;
		var currentTrip = null;
		var currentLines = [];
		var currentChecksum = null;
		var productNames = {};
		var locations = [];
		var shoppingLocations = [];

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

		function tripUrl(tripId)
		{
			return tripsEndpoint + '/' + encodeURIComponent(String(tripId));
		}

		function lineUrl(tripId, seq)
		{
			return tripUrl(tripId) + '/lines/' + encodeURIComponent(String(seq));
		}

		function element(tag, className, text)
		{
			var el = document.createElement(tag);
			if (className)
			{
				el.className = className;
			}
			if (text !== undefined && text !== null)
			{
				el.textContent = text;
			}
			return el;
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
				window.Grocy.Api.Get('objects/products/' + encodeURIComponent(key), function (product)
				{
					productNames[key] = product && typeof product.name === 'string' ? product.name : null;
					renderDetail();
				}, function () { productNames[key] = null; });
			}
		}

		function loadReferenceData()
		{
			if (typeof window === 'undefined' || !window.Grocy || !window.Grocy.Api || typeof window.Grocy.Api.Get !== 'function')
			{
				return;
			}
			window.Grocy.Api.Get('objects/locations', function (rows) { locations = Array.isArray(rows) ? rows : []; renderDetail(); }, function () {});
			window.Grocy.Api.Get('objects/shopping_locations', function (rows) { shoppingLocations = Array.isArray(rows) ? rows : []; renderDetail(); }, function () {});
		}

		function loadTripList()
		{
			return fetchJson(tripsEndpoint, { method: 'GET' }).then(function (payload)
			{
				if (!isTripListPayload(payload))
				{
					return;
				}
				renderTripList(payload.trips);
				if (currentTripId !== null)
				{
					loadTrip(currentTripId);
				}
			}).catch(function () {});
		}

		function loadTrip(tripId)
		{
			return fetchJson(tripUrl(tripId), { method: 'GET' }).then(function (payload)
			{
				if (!isLoadedTripPayload(payload))
				{
					detailEl.textContent = copy.loadError;
					return;
				}
				currentTripId = String(tripId);
				currentTrip = payload.trip;
				currentLines = payload.lines;
				currentChecksum = typeof payload.checksum === 'string' ? payload.checksum : null;
				currentLines.forEach(function (line)
				{
					if (line.status === 'known')
					{
						resolveProductName(line.resolved_product_id);
					}
				});
				renderTripListActive();
				renderDetail();
			}).catch(function ()
			{
				detailEl.textContent = copy.loadError;
			});
		}

		function renderTripList(trips)
		{
			if (!tripsListEl)
			{
				return;
			}
			tripsListEl.textContent = '';
			if (trips.length === 0)
			{
				tripsListEl.appendChild(element('p', 'text-muted', copy.noTrips));
				return;
			}
			trips.forEach(function (trip)
			{
				var button = element('button', 'list-group-item list-group-item-action grocy-ai-capture-review-trip');
				button.type = 'button';
				button.setAttribute('data-trip-id', String(trip.id));
				button.appendChild(element('span', 'grocy-ai-capture-review-trip-id', '#' + String(trip.id)));
				button.appendChild(element('span', 'badge badge-secondary ml-2', trip.status));
				button.addEventListener('click', function () { loadTrip(trip.id); });
				tripsListEl.appendChild(button);
			});
			renderTripListActive();
		}

		function renderTripListActive()
		{
			if (!tripsListEl)
			{
				return;
			}
			var buttons = tripsListEl.querySelectorAll('.grocy-ai-capture-review-trip');
			Array.prototype.forEach.call(buttons, function (button)
			{
				if (button.getAttribute('data-trip-id') === String(currentTripId))
				{
					button.classList.add('active');
				}
				else
				{
					button.classList.remove('active');
				}
			});
		}

		function putTrip(body)
		{
			return fetchJson(tripUrl(currentTripId), { method: 'PUT', body: JSON.stringify(body) }).then(function (trip)
			{
				if (isTripPayload(trip))
				{
					currentTrip = trip;
				}
				loadTrip(currentTripId);
			}).catch(function () { flashError(); });
		}

		function putLine(seq, body)
		{
			return fetchJson(lineUrl(currentTripId, seq), { method: 'PUT', body: JSON.stringify(body) }).then(function (payload)
			{
				if (isLoadedTripPayload(payload))
				{
					currentTrip = payload.trip;
					currentLines = payload.lines;
					currentLines.forEach(function (line) { if (line.status === 'known') { resolveProductName(line.resolved_product_id); } });
					renderDetail();
				}
			}).catch(function () { flashError(); });
		}

		function flashError()
		{
			var notice = document.getElementById('grocyai-capture-review-error');
			if (notice)
			{
				notice.textContent = copy.saveError;
			}
		}

		function locationSelect(id, current, rows, onChange)
		{
			var select = element('select', 'form-control');
			select.id = id;
			var noneOption = element('option', null, copy.none);
			noneOption.value = '';
			select.appendChild(noneOption);
			rows.forEach(function (row)
			{
				var option = element('option', null, typeof row.name === 'string' ? row.name : String(row.id));
				option.value = String(row.id);
				if (current !== null && current !== undefined && String(current) === String(row.id))
				{
					option.selected = true;
				}
				select.appendChild(option);
			});
			select.addEventListener('change', function ()
			{
				onChange(select.value === '' ? null : parseInt(select.value, 10));
			});
			return select;
		}

		function renderDetail()
		{
			if (!detailEl)
			{
				return;
			}
			detailEl.textContent = '';
			if (currentTrip === null)
			{
				detailEl.appendChild(element('p', 'text-muted', copy.noTripSelected));
				return;
			}

			var error = element('div', 'invalid-feedback d-block', '');
			error.id = 'grocyai-capture-review-error';
			error.setAttribute('role', 'alert');

			// Status + advance to reviewing.
			var statusRow = element('div', 'grocy-ai-capture-review-status d-flex align-items-center mb-2');
			statusRow.appendChild(element('span', 'mr-2', copy.status + ': '));
			statusRow.appendChild(element('span', 'badge badge-secondary', currentTrip.status));
			if (currentTrip.status === 'open')
			{
				var reviewingButton = element('button', 'btn btn-sm btn-outline-primary ml-3', copy.markReviewing);
				reviewingButton.type = 'button';
				reviewingButton.addEventListener('click', function () { putTrip({ status: 'reviewing' }); });
				statusRow.appendChild(reviewingButton);
			}
			detailEl.appendChild(statusRow);

			// Trip-level defaults (Q11): location + store only.
			var defaults = element('div', 'grocy-ai-capture-review-defaults row');
			var locCol = element('div', 'form-group col-12 col-sm-6');
			locCol.appendChild(element('label', null, copy.location));
			locCol.appendChild(locationSelect('grocyai-capture-review-location', currentTrip.default_location_id, locations, function (value) { putTrip({ default_location_id: value }); }));
			var storeCol = element('div', 'form-group col-12 col-sm-6');
			storeCol.appendChild(element('label', null, copy.store));
			storeCol.appendChild(locationSelect('grocyai-capture-review-store', currentTrip.default_shopping_location_id, shoppingLocations, function (value) { putTrip({ default_shopping_location_id: value }); }));
			defaults.appendChild(locCol);
			defaults.appendChild(storeCol);
			detailEl.appendChild(defaults);

			// Lines.
			var linesList = element('ul', 'list-group grocy-ai-capture-review-lines');
			if (currentLines.length === 0)
			{
				linesList.appendChild(element('li', 'list-group-item text-muted', copy.emptyLines));
			}
			currentLines.forEach(function (line) { linesList.appendChild(renderLine(line)); });
			detailEl.appendChild(linesList);

			// Commit (CAP-05): the single stock-write trigger. Hidden once the trip is archived committed.
			var commitSection = element('div', 'grocy-ai-capture-review-commit mt-3');
			if (currentTrip.status === 'committed')
			{
				commitSection.appendChild(element('div', 'alert alert-success mb-0', copy.committed.replace('%s', String(currentTrip.transaction_id))));
			}
			else
			{
				var commitButton = element('button', 'btn btn-success', copy.commit);
				commitButton.type = 'button';
				commitButton.id = 'grocyai-capture-review-commit';
				commitButton.addEventListener('click', function () { commitTrip(); });
				commitSection.appendChild(commitButton);
			}
			var commitResult = element('div', 'grocy-ai-capture-review-commit-result mt-2');
			commitResult.id = 'grocyai-capture-review-commit-result';
			commitResult.setAttribute('role', 'status');
			commitResult.setAttribute('aria-live', 'polite');
			commitSection.appendChild(commitResult);
			detailEl.appendChild(commitSection);

			detailEl.appendChild(error);
		}

		function commitTrip()
		{
			if (currentTripId === null || typeof currentChecksum !== 'string')
			{
				return Promise.resolve(null);
			}
			if (typeof window !== 'undefined' && typeof window.confirm === 'function' && !window.confirm(copy.commitConfirm))
			{
				return Promise.resolve(null);
			}
			return fetch(tripUrl(currentTripId) + '/commit', {
				method: 'POST',
				credentials: 'same-origin',
				cache: 'no-store',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
				body: JSON.stringify({ confirmed_checksum: currentChecksum })
			}).then(function (response)
			{
				if (!response.ok && response.status !== 409)
				{
					throw new Error('http_status');
				}
				return response.json();
			}).then(function (result)
			{
				renderCommitResult(result);
				loadTrip(currentTripId);
			}).catch(function () { flashError(); });
		}

		function renderCommitResult(result)
		{
			var target = document.getElementById('grocyai-capture-review-commit-result');
			if (!target || !result || typeof result !== 'object')
			{
				return;
			}
			var message;
			if (result.outcome === 'committed')
			{
				message = copy.committed.replace('%s', String(result.transaction_id));
			}
			else if (result.outcome === 'partial')
			{
				message = copy.commitPartial.replace('%s', String(result.applied));
			}
			else if (result.outcome === 'checksum_mismatch')
			{
				message = copy.commitMismatch;
			}
			else
			{
				message = copy.saveError;
			}
			target.textContent = message;
		}

		function renderLine(line)
		{
			var label = lineLabel(line, productNames[String(line.resolved_product_id)], copy);
			var item = element('li', 'list-group-item grocy-ai-capture-review-line status-' + label.status);
			item.setAttribute('data-line-seq', String(line.seq));

			var header = element('div', 'd-flex justify-content-between align-items-center');
			header.appendChild(element('span', 'grocy-ai-capture-review-line-name', label.text));
			if (line.status !== 'known')
			{
				var createLink = element('a', 'btn btn-sm btn-outline-primary permission-MASTER_DATA_EDIT', copy.createProduct);
				createLink.href = productNewUrl(productNewBase, line.scanned_barcode);
				header.appendChild(createLink);
			}
			item.appendChild(header);

			var controls = element('div', 'grocy-ai-capture-review-line-controls d-flex flex-wrap align-items-end mt-2');

			// Quantity stepper.
			var qtyGroup = element('div', 'form-group mr-3 mb-0');
			qtyGroup.appendChild(element('label', 'small mb-0', copy.quantity));
			var qtyRow = element('div', 'input-group input-group-sm grocy-ai-capture-review-quantity');
			var minus = stepperButton('−');
			var qtyInput = element('input', 'form-control text-center');
			qtyInput.type = 'number';
			qtyInput.min = '1';
			qtyInput.step = '1';
			qtyInput.value = describeQuantity(line.quantity);
			var plus = stepperButton('+');
			minus.addEventListener('click', function () { changeQuantity(line, -1); });
			plus.addEventListener('click', function () { changeQuantity(line, 1); });
			qtyInput.addEventListener('change', function ()
			{
				var next = parseFloat(qtyInput.value);
				if (Number.isFinite(next) && next > 0)
				{
					putLine(line.seq, { quantity: next });
				}
			});
			qtyRow.appendChild(prependGroup(minus));
			qtyRow.appendChild(qtyInput);
			qtyRow.appendChild(appendGroup(plus));
			qtyGroup.appendChild(qtyRow);
			controls.appendChild(qtyGroup);

			// Optional price.
			var priceGroup = element('div', 'form-group mr-3 mb-0');
			priceGroup.appendChild(element('label', 'small mb-0', copy.price));
			var priceInput = element('input', 'form-control form-control-sm');
			priceInput.type = 'number';
			priceInput.step = '0.01';
			priceInput.min = '0';
			priceInput.value = line.price === null || line.price === undefined ? '' : String(line.price);
			priceInput.addEventListener('change', function ()
			{
				var raw = priceInput.value.trim();
				putLine(line.seq, { price: raw === '' ? null : parseFloat(raw) });
			});
			priceGroup.appendChild(priceInput);
			controls.appendChild(priceGroup);

			// Selection.
			var selectGroup = element('div', 'form-group form-check mr-3 mb-0 align-self-center');
			var checkbox = element('input', 'form-check-input');
			checkbox.type = 'checkbox';
			checkbox.id = 'grocyai-capture-review-selected-' + String(line.seq);
			checkbox.checked = Number(line.selected) === 1;
			checkbox.addEventListener('change', function () { putLine(line.seq, { selected: checkbox.checked }); });
			var checkLabel = element('label', 'form-check-label', copy.selected);
			checkLabel.setAttribute('for', checkbox.id);
			selectGroup.appendChild(checkbox);
			selectGroup.appendChild(checkLabel);
			controls.appendChild(selectGroup);

			// Delete.
			var deleteButton = element('button', 'btn btn-sm btn-outline-danger mb-0 align-self-center', copy.deleteLabel);
			deleteButton.type = 'button';
			deleteButton.addEventListener('click', function () { putLine(line.seq, { delete: true }); });
			controls.appendChild(deleteButton);

			item.appendChild(controls);
			return item;
		}

		function changeQuantity(line, delta)
		{
			var next = Number(line.quantity) + delta;
			if (next >= 1)
			{
				putLine(line.seq, { quantity: next });
			}
		}

		function stepperButton(text)
		{
			var button = element('button', 'btn btn-outline-secondary', text);
			button.type = 'button';
			return button;
		}

		function prependGroup(button)
		{
			var span = element('div', 'input-group-prepend');
			span.appendChild(button);
			return span;
		}

		function appendGroup(button)
		{
			var span = element('div', 'input-group-append');
			span.appendChild(button);
			return span;
		}

		loadReferenceData();
		loadTripList();

		return {
			loadTripList: loadTripList,
			loadTrip: loadTrip
		};
	}

	return {
		TRIP_KEYS: TRIP_KEYS,
		LINE_KEYS: LINE_KEYS,
		DEFAULT_COPY: DEFAULT_COPY,
		isTripPayload: isTripPayload,
		isLinePayload: isLinePayload,
		isTripListPayload: isTripListPayload,
		isLoadedTripPayload: isLoadedTripPayload,
		productNewUrl: productNewUrl,
		describeQuantity: describeQuantity,
		lineLabel: lineLabel,
		attachCaptureReview: attachCaptureReview
	};
});
