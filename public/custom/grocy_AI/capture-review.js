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
		barcode: 'UPC',
		noTrips: 'No trips yet. Capture one first.',
		noTripSelected: 'Select a trip to review its items.',
		emptyLines: 'This trip has no items.',
		loadError: 'Could not load the trip. Try again.',
		saveError: 'That change could not be saved. Try again.',
		deleteLabel: 'Delete scan',
		deleteTrip: 'Delete trip',
		selected: 'Include in purchase',
		quantity: 'Quantity',
		location: 'Default location',
		none: '(none)',
		markReviewing: 'Finish scanning',
		finishFirst: 'Finish scanning this trip and open review? This will not change stock.',
		finishSecond: 'Confirm again: Are you finished scanning this trip?',
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
			barcode: d.labelBarcode || DEFAULT_COPY.barcode,
			noTrips: d.labelNoTrips || DEFAULT_COPY.noTrips,
			noTripSelected: d.labelNoTripSelected || DEFAULT_COPY.noTripSelected,
			emptyLines: d.labelEmptyLines || DEFAULT_COPY.emptyLines,
			loadError: d.labelLoadError || DEFAULT_COPY.loadError,
			saveError: d.labelSaveError || DEFAULT_COPY.saveError,
			deleteLabel: d.labelDelete || DEFAULT_COPY.deleteLabel,
			deleteTrip: d.labelDeleteTrip || DEFAULT_COPY.deleteTrip,
			selected: d.labelSelected || DEFAULT_COPY.selected,
			quantity: d.labelQuantity || DEFAULT_COPY.quantity,
			location: d.labelLocation || DEFAULT_COPY.location,
			none: d.labelNone || DEFAULT_COPY.none,
			markReviewing: d.labelMarkReviewing || DEFAULT_COPY.markReviewing,
			continueScanning: d.labelContinueScanning || 'Continue scanning',
			finishFirst: d.labelFinishFirst || DEFAULT_COPY.finishFirst,
			finishSecond: d.labelFinishSecond || DEFAULT_COPY.finishSecond,
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
		var captureUrl = root.getAttribute('data-capture-url') || '';
		var tripIdRaw = root.getAttribute('data-trip-id') || '';
		var copy = readCopy(root);
		var tripsListEl = document.getElementById('grocyai-capture-review-trips');
		var detailEl = document.getElementById('grocyai-capture-review-detail');

		var currentTripId = /^[1-9][0-9]{0,9}$/.test(tripIdRaw) ? tripIdRaw : null;
		var currentTrip = null;
		var currentLines = [];
		var currentChecksum = null;
		var receiptReadiness = null;
		var receiptBusy = false;
		var commitMessage = '';
		var commitInFlight = false;
		var commitStates = {};
		var reviewStage = 'review';
		var receiptNotice = '';
		var receiptStates = {};
		var receiptPendingByTrip = {};
		var directPendingByTrip = {};
		var directRefreshRequiredByTrip = {};
		var directMutationVersions = {};
		var directErrorsByTrip = {};
		var researchComponent = null;
		var receiptComponent = null;
		var researchStates = {};
		var researchBusyByTrip = {};
		var researchNotices = {};
		var disclosuresByTrip = {};
		var selectRenderedCard = null;
		var activeReviewKey = null;
		var activeReviewIndex = 0;
		var reviewQueue = { cards: [], receiptOwnerByKey: {} };
		var renderRevision = 0;
		var loadRevision = 0;
		var loadingTrip = false;
		var requestedTripId = currentTripId;
		var productNames = {};
		var productMetadata = {};
		var quantityUnits = [];
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
					return response.json().catch(function () { return null; }).then(function (body)
					{
						var error = new Error('http_status');
						error.status = response.status;
						error.serverMessage = body && typeof body.error_message === 'string' ? body.error_message : null;
						throw error;
					});
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
					productMetadata[key] = product && !Array.isArray(product) ? product : null;
					if (!receiptBusy && !loadingTrip) renderDetail();
				}, function () { productNames[key] = null; });
			}
		}

		function loadReferenceData()
		{
			if (typeof window === 'undefined' || !window.Grocy || !window.Grocy.Api || typeof window.Grocy.Api.Get !== 'function')
			{
				return;
			}
			window.Grocy.Api.Get('objects/quantity_units', function (rows) { quantityUnits = Array.isArray(rows) ? rows : []; if (!receiptBusy && !loadingTrip) renderDetail(); }, function () {});
			window.Grocy.Api.Get('objects/locations', function (rows) { locations = Array.isArray(rows) ? rows : []; if (!receiptBusy && !loadingTrip) renderDetail(); }, function () {});
			window.Grocy.Api.Get('objects/shopping_locations', function (rows) { shoppingLocations = Array.isArray(rows) ? rows : []; if (!receiptBusy && !loadingTrip) renderDetail(); }, function () {});
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
			var previousReadiness = receiptReadiness;
			var directVersion = directMutationVersions[String(tripId)] || 0;
			var readAfterDirectSettlement = !(directPendingByTrip[String(tripId)] > 0);
			requestedTripId = String(tripId);
			var revision = ++loadRevision;
			loadingTrip = true;
			detailEl.inert = true;
			if (researchComponent) researchComponent.dispose();
			if (receiptComponent) receiptComponent.dispose();
			receiptReadiness = null;
			updateReviewState();
			return Promise.all([fetchJson(tripUrl(tripId), { method: 'GET' }), fetchJson(tripUrl(tripId) + '/receipt-readiness').catch(function () { return null; })]).then(function (results)
			{
				if (revision !== loadRevision) return;
				receiptReadiness = results[1] && typeof results[1].ready === 'boolean' && Array.isArray(results[1].reasons) && Array.isArray(results[1].receipts) ? results[1] : null;
				return results[0];
			}).then(function (payload)
			{
				if (revision !== loadRevision) return;
				detailEl.inert = false;
				if (!isLoadedTripPayload(payload))
				{
					throw new Error('invalid_trip_payload');
				}
				if (currentTripId !== String(tripId))
				{
					activeReviewKey = null;
					activeReviewIndex = 0;
					receiptNotice = '';
					commitMessage = '';
					reviewStage = 'review';
				}
				currentTripId = String(tripId);
				currentTrip = payload.trip;
				if ((commitStates[currentTripId] || {}).outcome === 'committed') { currentTrip.status = 'committed'; currentTrip.transaction_id = commitStates[currentTripId].transactionId || currentTrip.transaction_id; }
				if (currentTrip.status === 'committed') reviewStage = 'summary';
				commitMessage = (commitStates[currentTripId] || {}).message || '';
				currentLines = payload.lines;
				currentChecksum = typeof payload.checksum === 'string' ? payload.checksum : null;
				currentLines.forEach(function (line)
				{
					if (line.status === 'known')
					{
						resolveProductName(line.resolved_product_id);
					}
				});
				(receiptReadiness ? receiptReadiness.receipts : []).forEach(function (view) { (view.lines || []).forEach(function (line) { (line.allocations || []).forEach(function (allocation) { if (Number(allocation.active) !== 0) resolveProductName(allocation.product_id); }); }); });
				if (readAfterDirectSettlement && directVersion === (directMutationVersions[currentTripId] || 0) && !(directPendingByTrip[currentTripId] > 0) && receiptReadiness) delete directRefreshRequiredByTrip[currentTripId];
				loadingTrip = false;
				renderTripListActive();
				renderDetail();
				return payload;
			}).catch(function ()
			{
				if (revision !== loadRevision) return null;
				detailEl.inert = false; loadingTrip = false;
				if (currentTrip && currentTripId === String(tripId) && (reviewStage === 'summary' || ((commitStates[currentTripId] || {}).outcome && commitStates[currentTripId].outcome !== 'pending')))
				{
					var state = commitStates[currentTripId] = commitStates[currentTripId] || {};
					state.needsRecheck = true;
					if (state.outcome && state.outcome !== 'pending') reviewStage = 'summary';
					if (state.outcome === 'committed')
					{
						// The confirmed commit result is authoritative even if its follow-up read fails.
						reviewStage = 'summary';
						currentTrip.status = 'committed'; currentTrip.transaction_id = state.transactionId || currentTrip.transaction_id;
					}
					else if (state.outcome === 'partial') state.message = copy.commitPartial.replace('%s', String(state.applied)) + ' Could not reload purchase. Reload and recheck purchase before trying again.';
					else if (!state.message) state.message = 'Could not reload purchase. Reload and recheck purchase before trying again.';
					receiptReadiness = previousReadiness; commitMessage = state.message || '';
					renderDetail();
				}
				else detailEl.textContent = copy.loadError;
				return null;
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

		function directMutation(tripId, action)
		{
			directPendingByTrip[tripId] = (directPendingByTrip[tripId] || 0) + 1;
			directMutationVersions[tripId] = (directMutationVersions[tripId] || 0) + 1;
			directRefreshRequiredByTrip[tripId] = true;
			delete directErrorsByTrip[tripId];
			updateReviewState();
			return Promise.resolve().then(action).finally(function ()
			{
				directPendingByTrip[tripId]--;
				// Only a read begun after every direct write settles can release this trip's guard.
				if (tripId === currentTripId && tripId === requestedTripId && directPendingByTrip[tripId] === 0) return loadTrip(tripId);
				updateReviewState();
			});
		}

		function putTrip(body)
		{
			var tripId = currentTripId, revision = loadRevision;
			return directMutation(tripId, function ()
			{
				return fetchJson(tripUrl(tripId), { method: 'PUT', body: JSON.stringify(body) }).then(function (trip)
				{
					if (revision !== loadRevision || tripId !== currentTripId) return;
					if (isTripPayload(trip)) currentTrip = trip;
				}).catch(function () { directErrorsByTrip[tripId] = copy.saveError; if (tripId === currentTripId && tripId === requestedTripId) flashError(directErrorsByTrip[tripId]); });
			});
		}

		function putLine(seq, body)
		{
			var tripId = currentTripId, revision = loadRevision;
			var editedLine = currentLines.find(function (line) { return line.seq === seq; });
			var removed = false;
			return directMutation(tripId, function ()
			{
				return fetchJson(lineUrl(tripId, seq), { method: 'PUT', body: JSON.stringify(body) }).then(function (payload)
				{
					// Explicit deletion settles only the removed scan, including after a trip switch.
					removed = body.delete === true && editedLine && isLoadedTripPayload(payload) && String(payload.trip.id) === tripId && !payload.lines.some(function (line) { return line.id === editedLine.id; });
					if (removed && researchStates[tripId]) delete researchStates[tripId][editedLine.id];
					if (revision !== loadRevision || tripId !== currentTripId) return;
					if (isLoadedTripPayload(payload))
					{
						currentTrip = payload.trip;
						if (currentTrip.status === 'committed') reviewStage = 'summary';
						commitMessage = (commitStates[currentTripId] || {}).message || '';
						currentLines = payload.lines;
						currentLines.forEach(function (line) { if (line.status === 'known') resolveProductName(line.resolved_product_id); });
					}
				}).catch(function (error)
				{
					directErrorsByTrip[tripId] = body.delete === true && error.status === 409 && typeof error.serverMessage === 'string' && error.serverMessage.indexOf('This scan has receipt allocation history;') === 0 ? error.serverMessage : copy.saveError;
					if (tripId === currentTripId && tripId === requestedTripId) flashError(directErrorsByTrip[tripId]);
				});
			}).then(function ()
			{
				if (!removed || tripId !== currentTripId || tripId !== requestedTripId || loadingTrip || reviewStage !== 'review') return;
				// The deleted control no longer exists; keep the user at the remaining review card.
				var target = detailEl.querySelector('.grocy-ai-review-card.is-active .grocy-ai-review-heading') || detailEl.querySelector('#grocyai-review-announcement');
				if (target) { target.tabIndex = -1; target.focus({ preventScroll: true }); target.scrollIntoView({ block: 'start' }); }
			});
		}

		function flashError(message)
		{
			var notice = document.getElementById('grocyai-capture-review-error');
			if (notice)
			{
				notice.textContent = message || copy.saveError;
			}
		}

		function cancelTrip()
		{
			if (currentTripId === null || receiptBusy) return;
			var tripId = currentTripId;
			if (!window.confirm('Delete trip #' + tripId + ' from the active list? Receipt and scan history will be kept for audit.')) return;
			if (!window.confirm('Final confirmation: delete trip #' + tripId + '? This cannot be undone from this screen.')) return;
			var button = document.getElementById('grocyai-capture-review-delete-trip');
			if (button) button.disabled = true;
			return fetchJson(tripUrl(tripId) + '/cancel', { method: 'POST', body: '{}' }).then(function (result)
			{
				if (!result || result.canceled !== true || Number(result.trip_id) !== Number(tripId)) throw new Error('invalid_cancel_response');
				if (currentTripId !== tripId) return;
				++loadRevision;
				currentTripId = null;
				currentTrip = null;
				currentLines = [];
				receiptReadiness = null;
				activeReviewKey = null;
				delete receiptStates[tripId];
				delete researchStates[tripId];
				var url = new URL(window.location.href);
				url.searchParams.delete('trip');
				window.history.replaceState(null, '', url.pathname + url.search + url.hash);
				renderDetail();
				return loadTripList();
			}).catch(function ()
			{
				if (button) button.disabled = false;
				flashError('The trip could not be deleted. Reload it and try again.');
			});
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

		// Shared by review navigation and the following purchase-summary stage.
		function selectReviewTarget(target, focus)
		{
			if (!target || loadingTrip) return false;
			if (target.cardKey)
			{
				if (!reviewQueue.cards.some(function (card) { return card.key === target.cardKey; }) || !selectRenderedCard || !Array.prototype.some.call(detailEl.querySelectorAll('[data-review-key]'), function (card) { return card.getAttribute('data-review-key') === target.cardKey; })) return false;
				selectRenderedCard(target.cardKey, focus);
				return true;
			}
			var details = document.getElementById('grocyai-receipt-details-' + target.receiptId);
			if (!details || !detailEl.contains(details)) return false;
			var toggle = detailEl.querySelector('[data-receipt-toggle][aria-controls="' + details.id + '"]');
			if (toggle && toggle.getAttribute('aria-expanded') !== 'true') toggle.click();
			if (focus) { details.focus(); details.scrollIntoView({ block: 'nearest' }); }
			return true;
		}


		function setReviewStage(stage, target)
		{
			if (loadingTrip) return;
			reviewStage = stage === 'summary' ? 'summary' : 'review';
			var review = document.getElementById('grocyai-review-stage');
			var summary = document.getElementById('grocyai-purchase-summary');
			if (!review || !summary) return;
			review.hidden = reviewStage !== 'review';
			summary.hidden = reviewStage !== 'summary';
			if (reviewStage === 'summary')
			{
				var heading = summary.querySelector('h3'); heading.focus(); heading.scrollIntoView({ block: 'nearest' });
			}
			else if (!selectReviewTarget(target || { cardKey: activeReviewKey }, true))
			{
				var fallback = document.getElementById('grocyai-review-summary-button'); fallback.focus(); fallback.scrollIntoView({ block: 'nearest' });
			}
			updateReviewState();
		}

		function formatAmount(value)
		{
			return value !== null && value !== undefined && Number.isFinite(Number(value)) ? String(Number(Number(value).toFixed(8))) : 'Unavailable';
		}

		function appendPurchaseSummary(summary, views)
		{
			var products = {};
			var allocatedScans = {};
			views.forEach(function (view)
			{
				(view.lines || []).forEach(function (line)
				{
					if (line.kind !== 'item' || line.decision !== 'include') return;
					(line.allocations || []).forEach(function (allocation)
					{
						if (Number(allocation.active) === 0) return;
						var key = String(allocation.product_id);
						(products[key] = products[key] || []).push(allocation);
						if (allocation.capture_line_id !== null && allocation.capture_line_id !== undefined) allocatedScans[allocation.capture_line_id] = true;
					});
				});
			});
			var purchases = element('section', 'grocy-ai-flow-card grocy-ai-summary-purchases');
			purchases.appendChild(element('h4', null, Object.keys(products).length + ' included products'));
			purchases.appendChild(element('p', 'text-muted', 'Reviewed purchased quantities by allocation. Scan purchase units are unverified; package notes do not calculate stock conversions.'));
			Object.keys(products).forEach(function (id)
			{
				var metadata = productMetadata[id];
				var unit = metadata && quantityUnits.find(function (row) { return String(row.id) === String(metadata.qu_id_purchase); });
				var unitLabel = unit && typeof unit.name === 'string' ? unit.name : 'Purchase unit unavailable';
				var row = element('div', 'grocy-ai-summary-product'); row.setAttribute('data-summary-product', id);
				row.appendChild(element('h5', null, productNames[id] || 'Product #' + id + ' · Name unavailable'));
				var prices = element('ul');
				products[id].forEach(function (allocation)
				{
					var hasScan = allocation.capture_line_id !== null && allocation.capture_line_id !== undefined;
					var scan = hasScan && currentLines.find(function (line) { return String(line.id) === String(allocation.capture_line_id); });
					var description = hasScan
						? 'Scan #' + (scan ? scan.seq : allocation.capture_line_id) + ' · UPC ' + (scan && scan.scanned_barcode ? scan.scanned_barcode : 'unavailable') + ' · purchased quantity: ' + formatAmount(allocation.quantity) + ' · Effective purchase unit unverified'
						: 'Receipt-only allocation · ' + formatAmount(allocation.quantity) + ' ' + unitLabel;
					// Keep every allocation and reviewed price separate; scanned UPCs can override stock multipliers.
					prices.appendChild(element('li', null, description + ' · reviewed unit price: ' + formatAmount(allocation.unit_price)));
				});
				row.appendChild(prices); purchases.appendChild(row);
			});
			currentLines.filter(function (line) { return Number(line.selected) === 1 && !line.applied_at && !allocatedScans[line.id]; }).forEach(function (line)
			{
				purchases.appendChild(element('p', 'text-muted', 'Scan #' + line.seq + ' · Needs review: selected scan awaiting receipt allocation.'));
			});
			summary.appendChild(purchases);
			var catalog = element('section', 'grocy-ai-flow-card');
			catalog.appendChild(element('h4', null, 'Catalog actions'));
			catalog.appendChild(element('p', null, 'Product created means a catalog entry was created. Commit purchase adds the reviewed items to stock.'));
			currentLines.forEach(function (line) { var notice = researchNotices[currentTripId + ':' + line.seq]; if (notice) catalog.appendChild(element('p', null, notice)); });
			summary.appendChild(catalog);
			views.forEach(function (view)
			{
				var receipt = view.receipt, totals = view.totals || {};
				var audit = element('section', 'grocy-ai-flow-card');
				audit.appendChild(element('h4', null, 'Receipt #' + receipt.id + ' · ' + (receipt.merchant || 'Merchant unavailable')));
				audit.appendChild(element('p', null, 'Full receipt audit · Printed total: ' + formatAmount(totals.printed_total) + ' · Reviewed total: ' + formatAmount(totals.entered_total) + ' · Difference: ' + formatAmount(totals.difference)));
				var accepted = receipt.difference_accepted_amount !== null && receipt.difference_accepted_amount !== undefined && Number(receipt.difference_accepted_amount) === Number(totals.difference);
				audit.appendChild(element('p', null, totals.difference !== null && totals.difference !== undefined && Number(totals.difference) === 0 ? 'Difference reconciled' : accepted ? 'Difference explicitly accepted: ' + formatAmount(receipt.difference_accepted_amount) : 'Difference needs review'));
				audit.appendChild(element('p', 'text-muted', 'Ignored amounts and receipt adjustments remain in the full receipt audit; included purchases may differ from the printed total.'));
				var auditLines = element('ul');
				(view.lines || []).filter(function (line) { return line.decision === 'ignore' || line.kind !== 'item'; }).forEach(function (line) { auditLines.appendChild(element('li', null, line.description + ' · ' + (line.decision === 'ignore' ? 'Ignored amount' : 'Receipt adjustment (' + line.kind + ')') + ': ' + formatAmount(line.line_total))); });
				audit.appendChild(auditLines); summary.appendChild(audit);
			});
		}

		function reasonTarget(reason)
		{
			var scan = /^capture_line_(\d+)_/.exec(reason);
			if (scan) return { cardKey: 'scan:' + scan[1] };
			var receiptLine = /^receipt_(\d+)_line_(\d+)_/.exec(reason);
			if (receiptLine) return { cardKey: reviewQueue.receiptOwnerByKey['receipt:' + receiptLine[1] + ':' + receiptLine[2]] };
			var receipt = /^receipt_(\d+)_/.exec(reason);
			return receipt ? { receiptId: Number(receipt[1]) } : {};
		}

		function renderDetail()
		{
			if (!detailEl)
			{
				return;
			}
			if (researchComponent) researchComponent.dispose();
			researchComponent = null;
			if (receiptComponent) receiptComponent.dispose();
			receiptComponent = null;
			receiptBusy = false;
			var generation = ++renderRevision;
			var renderedTripId = currentTripId;
			var renderedLoadRevision = loadRevision;
			function reloadCurrent(notice)
			{
				if (generation !== renderRevision || renderedLoadRevision !== loadRevision || renderedTripId !== currentTripId) return Promise.resolve();
				if (notice) receiptNotice = notice;
				return loadTrip(renderedTripId);
			}
			selectRenderedCard = null;
			detailEl.textContent = '';
			if (currentTrip === null)
			{
				detailEl.appendChild(element('p', 'text-muted', copy.noTripSelected));
				return;
			}

			disclosuresByTrip[currentTripId] = disclosuresByTrip[currentTripId] || {};
			var disclosures = disclosuresByTrip[currentTripId];
			var error = element('div', 'invalid-feedback d-block', directErrorsByTrip[currentTripId] || '');
			error.id = 'grocyai-capture-review-error';
			error.setAttribute('role', 'alert');

			// Status + advance to reviewing.
			var statusRow = element('div', 'grocy-ai-capture-review-status d-flex flex-wrap align-items-center mb-2');
			statusRow.appendChild(element('span', 'mr-2', copy.status + ': '));
			statusRow.appendChild(element('span', 'badge badge-secondary', currentTrip.status));
			if (currentTrip.status === 'open')
			{
				var openActions = element('div', 'grocy-ai-capture-review-open-actions');
				var continueLink = element('a', 'btn btn-outline-primary', copy.continueScanning);
				continueLink.setAttribute('href', captureUrl + '?trip=' + encodeURIComponent(String(currentTripId)));
				openActions.appendChild(continueLink);
				var reviewingButton = element('button', 'btn btn-outline-primary', copy.markReviewing);
				reviewingButton.type = 'button';
				reviewingButton.addEventListener('click', function ()
				{
					if (typeof window === 'undefined' || typeof window.confirm !== 'function'
						|| !window.confirm(copy.finishFirst) || !window.confirm(copy.finishSecond))
					{
						return;
					}
					reviewingButton.disabled = true;
					putTrip({ status: 'reviewing' }).finally(function () { reviewingButton.disabled = false; });
				});
				openActions.appendChild(reviewingButton);
				statusRow.appendChild(openActions);
			}
			if (currentTrip.status !== 'committed' && !currentLines.some(function (line) { return line.applied_at !== null; }))
			{
				var deleteTripButton = element('button', 'btn btn-outline-danger grocy-ai-capture-delete-trip', copy.deleteTrip);
				deleteTripButton.id = 'grocyai-capture-review-delete-trip';
				deleteTripButton.type = 'button';
				deleteTripButton.addEventListener('click', cancelTrip);
				statusRow.appendChild(deleteTripButton);
			}
			detailEl.appendChild(statusRow);

			// Inventory location remains a trip default; purchase stores belong to receipts.
			var defaults = element('div', 'grocy-ai-capture-review-defaults row');
			var locCol = element('div', 'form-group col-12 col-sm-6');
			locCol.appendChild(element('label', null, copy.location));
			locCol.appendChild(locationSelect('grocyai-capture-review-location', currentTrip.default_location_id, locations, function (value) { putTrip({ default_location_id: value }); }));
			defaults.appendChild(locCol);
			detailEl.appendChild(defaults);
			detailEl.appendChild(element('p', 'text-muted', 'Correct purchase prices and stores in the receipts below.'));

			var overview = element('section', 'grocy-ai-flow-card');
			overview.id = 'grocyai-review-overview';
			var receiptCount = receiptReadiness ? receiptReadiness.receipts.length : 0;
			var checks = receiptReadiness ? (receiptReadiness.reasons || []).length : 0;
			overview.appendChild(element('p', 'grocy-ai-flow-badge', currentLines.length + ' item' + (currentLines.length === 1 ? '' : 's') + ' · ' + checks + ' unresolved checks · ' + receiptCount + ' receipt' + (receiptCount === 1 ? '' : 's')));
			detailEl.appendChild(overview);
			var receiptsHost = element('div', 'grocy-ai-receipts');
			overview.appendChild(receiptsHost);
			var receiptViews = receiptReadiness ? receiptReadiness.receipts : [];
			reviewQueue = window.GrocyAICaptureReviewQueue.build(currentLines, receiptViews);
			var receiptByKey = {};
			var unresolvedScans = {};
			var unresolvedReceipts = {};
			function recordIssue(issue, receiptId)
			{
				var capture = /^capture_line_(\d+)_/.exec(issue);
				var receipt = /^receipt_(\d+)_line_(\d+)_/.exec(issue);
				var line = /^line_(\d+)_/.exec(issue);
				if (capture) unresolvedScans[capture[1]] = true;
				if (receipt) unresolvedReceipts['receipt:' + receipt[1] + ':' + receipt[2]] = true;
				if (line && receiptId !== undefined) unresolvedReceipts['receipt:' + receiptId + ':' + line[1]] = true;
			}
			// Server-scoped issues determine which cards need work; header issues stay in the summary.
			(receiptReadiness ? receiptReadiness.reasons : []).forEach(function (issue) { recordIssue(issue); });
			receiptViews.forEach(function (view)
			{
				(view.lines || []).forEach(function (line) { receiptByKey['receipt:' + view.receipt.id + ':' + line.id] = line; });
				(view.issues || []).forEach(function (issue) { recordIssue(issue, view.receipt.id); });
			});
			function unresolved(card)
			{
				return !!unresolvedScans[card.scanLineId] || card.receiptKeys.some(function (key) { return !!unresolvedReceipts[key]; });
			}

			if (activeReviewKey === null)
			{
				var first = reviewQueue.cards.findIndex(unresolved);
				activeReviewIndex = Math.max(0, first);
			}
			activeReviewKey = window.GrocyAICaptureReviewQueue.retain(reviewQueue.cards, activeReviewKey, activeReviewIndex);
			var nav = element('div', 'grocy-ai-review-navigation');
			var previous = element('button', 'btn btn-outline-primary', 'Previous');
			previous.id = 'grocyai-review-prev'; previous.type = 'button';
			var progress = element('span'); progress.id = 'grocyai-review-progress';
			var next = element('button', 'btn btn-outline-primary', 'Next');
			next.id = 'grocyai-review-next'; next.type = 'button';
			nav.appendChild(previous); nav.appendChild(progress); nav.appendChild(next);
			detailEl.appendChild(nav);
			var jump = element('button', 'btn btn-outline-secondary', 'Jump to next unresolved');
			jump.id = 'grocyai-review-next-unresolved'; jump.type = 'button';
			detailEl.appendChild(jump);
			var announcement = element('p', 'grocy-ai-review-announcement');
			announcement.id = 'grocyai-review-announcement';
			announcement.setAttribute('role', 'status'); announcement.setAttribute('aria-live', 'polite');
			detailEl.appendChild(announcement);
			var linesList = element('ul', 'list-group grocy-ai-capture-review-lines');
			var hosts = {};
			reviewQueue.cards.forEach(function (card)
			{
				var scan = currentLines.find(function (line) { return line.id === card.scanLineId; });
				var item = scan ? renderLine(scan) : element('li', 'list-group-item');
				item.classList.add('grocy-ai-review-card', 'grocy-ai-flow-card');
				if (scan)
				{
					var participation = element('section', 'grocy-ai-flow-participation');
					participation.appendChild(item.querySelector('.grocy-ai-capture-review-line-controls'));
					item.appendChild(participation);
					item.appendChild(element('section', 'grocy-ai-flow-research'));
				}
				item.appendChild(element('section', 'grocy-ai-flow-receipt'));
				if (scan) { var actions = element('section', 'grocy-ai-flow-product-actions'); var legacyCreate = item.querySelector('.grocy-ai-legacy-create-product'); if (legacyCreate) actions.appendChild(legacyCreate); item.appendChild(actions); }
				item.setAttribute('data-review-key', card.key);
				item.setAttribute('data-review-status', unresolved(card) ? 'Needs review' : scan ? (Number(scan.selected) === 0 ? 'Not included' : scan.status === 'known' ? 'Known product' : 'Needs product') : receiptByKey[card.key].decision === 'ignore' ? 'Ignored' : 'Included');
				var heading = element('h3', 'grocy-ai-review-heading', scan ? 'Scan #' + scan.seq : (card.kind === 'adjustment' ? 'Receipt adjustment' : 'Receipt item') + ' · #' + card.receiptId);
				heading.tabIndex = -1; item.insertBefore(heading, item.firstChild);
				var createdNotice = scan && /^Product #(\d+) created\./.exec(researchNotices[currentTripId + ':' + scan.seq] || '');
				if (createdNotice) item.setAttribute('data-review-status', 'Product created (#' + createdNotice[1] + ')');
				var badge = element('span', 'grocy-ai-flow-badge', item.getAttribute('data-review-status'));
				item.insertBefore(badge, heading.nextSibling);
				hosts[card.key] = item; linesList.appendChild(item);
				card.receiptKeys.forEach(function (key)
				{
					if (reviewQueue.receiptOwnerByKey[key] === card.key) return;
					item.appendChild(element('p', 'text-muted', receiptByKey[key].description + ' · quantity ' + receiptByKey[key].quantity + ' · line total ' + receiptByKey[key].line_total));
					var shared = element('button', 'btn btn-outline-secondary', 'Review shared receipt line: ' + receiptByKey[key].description);
					shared.type = 'button';
					shared.addEventListener('click', function () { selectReviewTarget({ cardKey: reviewQueue.receiptOwnerByKey[key] }, true); });
					item.querySelector('.grocy-ai-flow-receipt').appendChild(shared);
				});
				var touch = null;
				item.addEventListener('touchstart', function (event)
				{
					touch = null;
					if (!window.matchMedia('(max-width: 767.98px)').matches || event.touches.length !== 1 || event.target.closest('input, select, textarea, button, a, label, [contenteditable], [role="button"]')) return;
					touch = { x: event.touches[0].clientX, y: event.touches[0].clientY };
				}, { passive: true });
				item.addEventListener('touchcancel', function () { touch = null; }, { passive: true });
				item.addEventListener('touchend', function (event)
				{
					if (!touch || !event.changedTouches.length) return;
					var dx = event.changedTouches[0].clientX - touch.x, dy = event.changedTouches[0].clientY - touch.y;
					touch = null;
					if (Math.abs(dx) >= 60 && Math.abs(dx) > Math.abs(dy) * 1.5) moveCard(dx < 0 ? 1 : -1, false);
				}, { passive: true });
			});
			if (!reviewQueue.cards.length) linesList.appendChild(element('li', 'list-group-item text-muted', copy.emptyLines));
			detailEl.appendChild(linesList);
			function selectCard(key, focus)
			{
				activeReviewKey = key;
				activeReviewIndex = reviewQueue.cards.findIndex(function (card) { return card.key === key; });
				Object.keys(hosts).forEach(function (id) { hosts[id].classList.toggle('is-active', id === key); });
				progress.textContent = (activeReviewIndex + 1) + ' of ' + reviewQueue.cards.length;
				previous.disabled = activeReviewIndex <= 0;
				next.disabled = activeReviewIndex >= reviewQueue.cards.length - 1;
				nav.hidden = !reviewQueue.cards.length;
				updateReviewState();
				if (focus && hosts[key]) hosts[key].querySelector('.grocy-ai-review-heading').focus();
			}
			function moveCard(delta, focus)
			{
				var card = reviewQueue.cards[activeReviewIndex + delta];
				if (card) selectCard(card.key, focus);
			}
			selectRenderedCard = selectCard;
			jump.addEventListener('click', function ()
			{
				for (var offset = 1; offset <= reviewQueue.cards.length; offset++)
				{
					var card = reviewQueue.cards[(activeReviewIndex + offset) % reviewQueue.cards.length];
					if (unresolved(card) && selectReviewTarget({ cardKey: card.key }, true)) return;
				}
				var header = (receiptReadiness ? receiptReadiness.reasons : []).find(function (issue) { return /^receipt_\d+_(?!line_)/.test(issue); });
				if (header) selectReviewTarget({ receiptId: Number(/^receipt_(\d+)_/.exec(header)[1]) }, true);
			});
			previous.addEventListener('click', function () { moveCard(-1, true); });
			next.addEventListener('click', function () { moveCard(1, true); });
			researchStates[currentTripId] = researchStates[currentTripId] || {};
			selectCard(activeReviewKey, false);
			var researchError = element('div', 'invalid-feedback d-block');
			detailEl.appendChild(researchError);
			var researchScopeBusy = false;
			if (window.GrocyAIProductResearch) researchComponent = window.GrocyAIProductResearch(linesList, { notices: researchNotices, disclosures: disclosures, sectionHostFor: function (lineId, section) { var owner = hosts['scan:' + lineId]; return owner ? owner.querySelector(section === 'actions' ? '.grocy-ai-flow-product-actions' : '.grocy-ai-flow-research') : null; }, editState: researchStates[currentTripId], onEdit: updateReviewState, onBusy: function (value) { if (researchScopeBusy === value) return; researchScopeBusy = value; researchBusyByTrip[renderedTripId] = Math.max(0, (researchBusyByTrip[renderedTripId] || 0) + (value ? 1 : -1)); if (renderedTripId === currentTripId) updateReviewState(); }, errorHost: researchError, tripId: currentTripId, lines: currentLines, receipts: receiptViews, locationId: currentTrip.default_location_id, readOnly: currentTrip.status === 'committed', reload: function (notice, seq)
			{
				if (generation !== renderRevision || renderedLoadRevision !== loadRevision || renderedTripId !== currentTripId) return Promise.resolve();
				researchNotices[renderedTripId + ':' + seq] = notice;
				return reloadCurrent().then(function ()
				{
					if (renderedTripId !== currentTripId) return;
					var feedback = detailEl.querySelector('.grocy-ai-capture-review-line[data-line-seq="' + seq + '"] .grocy-ai-product-review-notice');
					if (feedback) { feedback.focus(); feedback.scrollIntoView({ block: 'nearest' }); }
				});
			} });
			if (window.GrocyAIReceipts && receiptReadiness)
			{
				var receiptScopePending = false;
				receiptStates[currentTripId] = receiptStates[currentTripId] || { drafts: {}, uploads: [] };
				receiptComponent = window.GrocyAIReceipts(receiptsHost, { compact: true, disclosures: disclosures, state: receiptStates[currentTripId], inputRoot: detailEl, lineHostFor: function (receipt, line) { var owner = hosts[reviewQueue.receiptOwnerByKey['receipt:' + receipt.id + ':' + line.id]]; return owner ? owner.querySelector('.grocy-ai-flow-receipt') : null; }, url: tripUrl(currentTripId), receipts: receiptViews, lines: currentLines, names: productNames, stores: shoppingLocations, productNew: productNewBase, readOnly: currentTrip.status === 'committed', notice: receiptNotice, reload: reloadCurrent, onPending: function (value) { if (receiptScopePending === value) return; receiptScopePending = value; receiptPendingByTrip[renderedTripId] = Math.max(0, (receiptPendingByTrip[renderedTripId] || 0) + (value ? 1 : -1)); if (renderedTripId === currentTripId) updateReviewState(); }, onBusy: function (value) { if (generation !== renderRevision) return; receiptBusy = value; updateReviewState(); } });
			}

			var state = commitStates[currentTripId] || {};
			var committed = currentTrip.status === 'committed' || state.outcome === 'committed';
			var readiness = element('div', 'alert alert-info');
			readiness.id = 'grocyai-receipt-readiness';
			readiness.setAttribute('role', 'status');
			readiness.tabIndex = -1;
			if (committed) readiness.textContent = 'Purchase committed.';
			else if (!receiptReadiness) readiness.textContent = 'Could not check receipt readiness. Reload to try again.';
			else if (receiptReadiness.ready) readiness.textContent = 'Receipts reviewed — ready to commit purchase.';
			else
			{
				var reasons = receiptReadiness.reasons || [];
				readiness.appendChild(element('p', 'mb-2', reasons.length ? reasons.length + ' review checks remain. Work through the item cards and receipt lines before committing.' : 'Purchase review is not ready. Reload to check again.'));
				if (reasons.length)
				{
					var detailsButton = element('button', 'btn btn-outline-secondary', disclosures.readiness ? 'Hide review checks' : 'View ' + reasons.length + ' review checks');
					detailsButton.type = 'button';
					detailsButton.setAttribute('aria-expanded', String(!!disclosures.readiness));
					detailsButton.setAttribute('aria-controls', 'grocyai-receipt-readiness-details');
					var details = element('ul', 'grocy-ai-readiness-details');
					details.id = 'grocyai-receipt-readiness-details';
					details.hidden = !disclosures.readiness;
					reasons.forEach(function (reason) { details.appendChild(element('li', null, window.GrocyAIReceiptReason(reason))); });
					detailsButton.addEventListener('click', function ()
					{
						details.hidden = !details.hidden;
						disclosures.readiness = !details.hidden;
						var groups = document.getElementById('grocyai-summary-check-groups'); if (groups) groups.hidden = details.hidden;
						detailsButton.setAttribute('aria-expanded', String(!details.hidden));
						detailsButton.textContent = details.hidden ? 'View ' + reasons.length + ' review checks' : 'Hide review checks';
					});
					readiness.appendChild(detailsButton);
					readiness.appendChild(details);
				}
			}
			var footer = element('div', 'grocy-ai-flow-footer');
			footer.appendChild(element('p', 'text-muted', checks + ' unresolved review checks'));
			var summaryButton = element('button', 'btn btn-primary', 'Continue to purchase summary');
			summaryButton.type = 'button'; summaryButton.id = 'grocyai-review-summary-button';
			summaryButton.addEventListener('click', function () { setReviewStage('summary'); });
			footer.appendChild(summaryButton);
			detailEl.appendChild(footer);
			var reviewContainer = element('div'); reviewContainer.id = 'grocyai-review-stage';
			while (detailEl.firstChild) reviewContainer.appendChild(detailEl.firstChild);
			detailEl.appendChild(reviewContainer);
			var summary = element('section', 'grocy-ai-purchase-summary'); summary.id = 'grocyai-purchase-summary';
			var summaryHeading = element('h3', null, 'Confirm purchase'); summaryHeading.tabIndex = -1; summary.appendChild(summaryHeading);
			var summaryStatus = element('p', 'grocy-ai-flow-badge'); summaryStatus.id = 'grocyai-summary-status'; summary.appendChild(summaryStatus);
			appendPurchaseSummary(summary, receiptViews);
			if (receiptReadiness && receiptReadiness.reasons.length)
			{
				var groups = {};
				receiptReadiness.reasons.forEach(function (reason) { var target = reasonTarget(reason); var key = target.cardKey || (target.receiptId ? 'receipt:' + target.receiptId : 'global'); (groups[key] = groups[key] || { target: target, reasons: [] }).reasons.push(reason); });
				var checksSection = element('section', 'grocy-ai-flow-card'); checksSection.id = 'grocyai-summary-check-groups'; checksSection.hidden = !disclosures.readiness; checksSection.appendChild(element('h4', null, 'Outstanding checks'));
				Object.keys(groups).forEach(function (key)
				{
					var group = groups[key]; var row = element('div', 'grocy-ai-flow-actions');
					row.appendChild(element('span', null, (key === 'global' ? 'Purchase' : key.replace(':', ' #')) + ' · ' + group.reasons.length + ' checks'));
					var reviewButton = element('button', 'btn btn-outline-secondary', 'Review ' + (key === 'global' ? 'purchase checks' : key.replace(':', ' #')));
					reviewButton.type = 'button'; reviewButton.setAttribute('data-review-target', key);
					reviewButton.addEventListener('click', function () { setReviewStage('review', group.target); }); row.appendChild(reviewButton); checksSection.appendChild(row);
				}); summary.appendChild(checksSection);
			}
			summary.appendChild(readiness);
			detailEl.appendChild(summary);
			reviewContainer.hidden = reviewStage !== 'review'; summary.hidden = reviewStage !== 'summary';

			// Commit (CAP-05): the single stock-write trigger. Hidden once the trip is archived committed.
			var commitSection = element('div', 'grocy-ai-capture-review-commit grocy-ai-flow-footer');
			var back = element('button', 'btn btn-outline-secondary', 'Back to review'); back.type = 'button'; back.id = 'grocyai-purchase-summary-back'; back.addEventListener('click', function () { setReviewStage('review'); }); commitSection.appendChild(back);
			if (committed)
			{
				commitSection.appendChild(element('h4', 'alert alert-success mb-0', 'Purchase committed'));
				var transaction = state.transactionId || currentTrip.transaction_id;
				if (transaction) commitSection.appendChild(element('p', null, copy.committed.replace('%s', String(transaction))));
				var inventory = element('a', 'btn btn-primary', 'View inventory'); inventory.href = root.getAttribute('data-inventory-url') || '/stockoverview'; commitSection.appendChild(inventory);
				var start = element('a', 'btn btn-outline-primary', 'Start new trip'); start.href = captureUrl; commitSection.appendChild(start);
			}
			else
			{
				var commitButton = element('button', 'btn btn-success', copy.commit);
				commitButton.type = 'button';
				commitButton.disabled = receiptBusy || !receiptReadiness || receiptReadiness.ready !== true;
				commitButton.setAttribute('aria-describedby', 'grocyai-receipt-readiness');
				commitButton.id = 'grocyai-capture-review-commit';
				commitButton.addEventListener('click', function () { commitTrip(); });
				commitSection.appendChild(commitButton);
			}
			var commitResult = element('div', 'grocy-ai-capture-review-commit-result mt-2');
			commitResult.id = 'grocyai-capture-review-commit-result';
			commitResult.textContent = commitMessage;
			commitResult.tabIndex = -1;
			commitResult.setAttribute('role', 'status');
			commitResult.setAttribute('aria-live', 'polite');
			commitSection.appendChild(commitResult);
			summary.appendChild(commitSection);
			if (state.needsRecheck) { var recheck = element('button', 'btn btn-outline-primary', 'Reload and recheck purchase'); recheck.type = 'button'; recheck.addEventListener('click', function () { loadTrip(currentTripId).then(function (payload) { if (payload && currentTripId === renderedTripId && requestedTripId === renderedTripId && !loadingTrip && receiptReadiness) { state.needsRecheck = false; updateReviewState(); } }); }); commitSection.appendChild(recheck); }

			reviewContainer.appendChild(error);
			updateReviewState();
			if (currentTrip.status === 'committed') Array.prototype.forEach.call(detailEl.querySelectorAll('.grocy-ai-capture-review-line-controls input, .grocy-ai-capture-review-line-controls button, .grocy-ai-capture-review-defaults select'), function (control) { control.disabled = true; });
		}

		function hasResearchEdits()
		{
			return Object.keys(researchStates[currentTripId] || {}).some(function (id) { return Object.keys(researchStates[currentTripId][id]).length > 0; });
		}

		function updateReviewState()
		{
			var unsaved = hasResearchEdits() || receiptComponent && receiptComponent.hasUnsavedEdits();
			var button = document.getElementById('grocyai-capture-review-commit');
			if (button) button.disabled = !canCommit();
			var result = document.getElementById('grocyai-capture-review-commit-result');
			if (result && reviewStage === 'summary' && unsaved && !commitMessage) result.textContent = 'Unsaved changes — save before committing.';
			var summaryStatus = document.getElementById('grocyai-summary-status');
			var state = commitStates[currentTripId] || {};
			if (summaryStatus) summaryStatus.textContent = 'Trip #' + currentTripId + ' · ' + (currentTrip.status === 'committed' || state.outcome === 'committed' ? 'Committed' : state.outcome === 'pending' ? 'Committing purchase…' : canCommit() ? 'Ready' : 'Needs review');
			var announcement = document.getElementById('grocyai-review-announcement');
			var activeCard = detailEl.querySelector('.grocy-ai-review-card.is-active');
			if (announcement) announcement.textContent = (reviewQueue.cards.length ? (activeReviewIndex + 1) + ' of ' + reviewQueue.cards.length + ' · ' : '') + (unsaved ? 'Unsaved changes — save before committing.' : activeCard ? activeCard.getAttribute('data-review-status') : 'No review items');
		}

		function canCommit()
		{
			var state = commitStates[currentTripId] || {};
			return !loadingTrip && !(directPendingByTrip[currentTripId] > 0) && !directRefreshRequiredByTrip[currentTripId] && !(receiptPendingByTrip[currentTripId] > 0) && !(researchBusyByTrip[currentTripId] > 0) && !commitInFlight && !state.needsRecheck && state.outcome !== 'committed' && currentTrip && currentTrip.status !== 'committed' && !hasResearchEdits() && !(receiptComponent && receiptComponent.hasUnsavedEdits()) && !receiptBusy && receiptReadiness && receiptReadiness.ready === true && currentTripId !== null && typeof currentChecksum === 'string' && currentChecksum.length > 0;
		}

		function focusCommitResult()
		{
			var result = document.getElementById('grocyai-capture-review-commit-result');
			if (result && reviewStage === 'summary') { result.focus(); result.scrollIntoView({ block: 'nearest' }); }
		}

		function commitTrip()
		{
			if (!canCommit()) return Promise.resolve(null);
			if (typeof window !== 'undefined' && typeof window.confirm === 'function' && !window.confirm(copy.commitConfirm)) return Promise.resolve(null);
			var tripId = currentTripId;
			var state = commitStates[tripId] = { message: 'Committing purchase…', outcome: 'pending' };
			commitInFlight = true; commitMessage = state.message;
			var target = document.getElementById('grocyai-capture-review-commit-result'); if (target) target.textContent = state.message;
			updateReviewState();
			return fetch(tripUrl(tripId) + '/commit', {
				method: 'POST', credentials: 'same-origin', cache: 'no-store',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
				body: JSON.stringify({ confirmed_checksum: currentChecksum })
			}).then(function (response)
			{
				if (!response.ok && response.status !== 409) throw new Error('http_status');
				return response.json();
			}).then(function (result)
			{
				if (!result || typeof result.outcome !== 'string') throw new Error('invalid_commit_result');
				var outcome = result.outcome === 'already_committed' ? 'committed' : result.outcome;
				state.outcome = outcome; state.transactionId = result.transaction_id; state.applied = result.applied;
				if (outcome === 'committed') state.message = 'Purchase committed' + (result.transaction_id ? ' · ' + copy.committed.replace('%s', String(result.transaction_id)) : '');
				else if (outcome === 'partial') { state.needsRecheck = true; state.message = copy.commitPartial.replace('%s', String(result.applied)); }
				else { state.needsRecheck = true; state.message = outcome === 'checksum_mismatch' ? copy.commitMismatch : outcome === 'receipt_review_required' ? 'Receipt review is required before purchase commit. Reload and recheck purchase.' : 'Purchase could not be committed. Reload and recheck purchase.'; }
				commitInFlight = false;
				if (tripId !== currentTripId || tripId !== requestedTripId) { updateReviewState(); return; }
				reviewStage = 'summary';
				commitMessage = state.message;
				if (state.outcome === 'committed' || state.outcome === 'partial') return loadTrip(tripId).then(function () { if (currentTripId === tripId && requestedTripId === tripId) focusCommitResult(); });
				renderDetail(); focusCommitResult();
			}).catch(function ()
			{
				commitInFlight = false;
				state.outcome = 'error'; state.needsRecheck = true; state.message = 'Purchase result could not be confirmed. Reload and recheck purchase before trying again.';
				if (tripId !== currentTripId || tripId !== requestedTripId) { updateReviewState(); return; }
				reviewStage = 'summary'; commitMessage = state.message; renderDetail(); focusCommitResult();
			});
		}

		function renderLine(line)
		{
			var label = lineLabel(line, productNames[String(line.resolved_product_id)], copy);
			var item = element('li', 'list-group-item grocy-ai-capture-review-line status-' + label.status);
			item.setAttribute('data-line-seq', String(line.seq));
			if (researchNotices[currentTripId + ':' + line.seq])
			{
				var notice = element('p', 'alert alert-success grocy-ai-product-review-notice', researchNotices[currentTripId + ':' + line.seq]);
				notice.setAttribute('role', 'status'); notice.setAttribute('aria-live', 'polite'); notice.tabIndex = -1;
				item.appendChild(notice);
			}

			var header = element('div', 'd-flex justify-content-between align-items-center');
			var description = element('div', 'grocy-ai-capture-review-line-description');
			description.appendChild(element('span', 'grocy-ai-capture-review-line-name', label.text));
			description.appendChild(element('span', 'grocy-ai-capture-review-line-barcode text-muted', copy.barcode + ' ' + String(line.scanned_barcode)));
			header.appendChild(description);
			if (line.status !== 'known')
			{
				var createLink = element('a', 'btn btn-sm btn-outline-primary permission-MASTER_DATA_EDIT grocy-ai-legacy-create-product', copy.createProduct);
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
			deleteButton.addEventListener('click', function ()
			{
				if (window.confirm('Delete scan #' + line.seq + ' from this trip? Receipt lines will remain for review.')) putLine(line.seq, { delete: true });
			});
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
