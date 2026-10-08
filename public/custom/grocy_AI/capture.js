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
		tripResumed: 'Trip #%s resumed. Scan or enter a GTIN to add items.',
		resumeError: 'This trip cannot be resumed. Open Review trip or start a new trip.',
		tripError: 'Could not start a capture trip. Reload the page to try again.',
		scanError: 'That scan could not be added. Try again.',
		finishFirst: 'Finish scanning this trip and open review? This will not change stock.',
		finishSecond: 'Confirm again: Are you finished scanning this trip?',
		finishError: 'Could not finish this trip. Your scans are saved; try again.',
		finishPending: 'Wait for the current scan to finish, then try again.',
		cameraPending: 'Confirm or cancel the scanned UPC before finishing.',
		cameraUnverified: 'Scan outcome could not be verified. Reload this page and review the trip before scanning again.',
		barcode: 'UPC',
		empty: 'No items yet. Scan or enter a GTIN above.',
		quantity: 'Quantity', distinctItems: 'distinct items', totalQuantity: 'Total quantity', saved: 'Saved', needsDetails: 'Needs details', showAll: 'Show all items', showRecent: 'Show recent items', trip: 'Trip', scanBarcode: 'Scan barcode', deleteFirst: 'Delete this trip from the active list? Receipt and scan history will be kept for audit.', deleteSecond: 'Final confirmation: delete this trip? This cannot be undone from this screen.', deleteError: 'The trip could not be deleted. Reload it and try again.', deleted: 'Trip deleted. Start a new trip to scan more items.'
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
			statusOpen: d.labelStatusOpen || 'Open',
			statusReviewing: d.labelStatusReviewing || 'Reviewing',
			statusCommitted: d.labelStatusCommitted || 'Committed',
			known: d.labelKnown || DEFAULT_COPY.known,
			unknown: d.labelUnknown || DEFAULT_COPY.unknown,
			productFallback: d.labelProductFallback || DEFAULT_COPY.productFallback,
			tripStarted: d.labelTripStarted || DEFAULT_COPY.tripStarted,
			tripResumed: d.labelTripResumed || DEFAULT_COPY.tripResumed,
			resumeError: d.labelResumeError || DEFAULT_COPY.resumeError,
			tripError: d.labelTripError || DEFAULT_COPY.tripError,
			scanError: d.labelScanError || DEFAULT_COPY.scanError,
			finishFirst: d.labelFinishFirst || DEFAULT_COPY.finishFirst,
			finishSecond: d.labelFinishSecond || DEFAULT_COPY.finishSecond,
			finishError: d.labelFinishError || DEFAULT_COPY.finishError,
			finishPending: d.labelFinishPending || DEFAULT_COPY.finishPending,
			cameraPending: d.labelCameraPending || DEFAULT_COPY.cameraPending,
			cameraUnverified: d.labelCameraUnverified || DEFAULT_COPY.cameraUnverified,
			barcode: d.labelBarcode || DEFAULT_COPY.barcode,
			empty: d.labelEmpty || DEFAULT_COPY.empty,
			quantity: d.labelQuantity || DEFAULT_COPY.quantity,
			distinctItems: d.labelDistinctItems || DEFAULT_COPY.distinctItems,
			totalQuantity: d.labelTotalQuantity || DEFAULT_COPY.totalQuantity,
			saved: d.labelSaved || DEFAULT_COPY.saved,
			needsDetails: d.labelNeedsDetails || DEFAULT_COPY.needsDetails,
			showAll: d.labelShowAll || DEFAULT_COPY.showAll,
			showRecent: d.labelShowRecent || DEFAULT_COPY.showRecent,
			trip: d.labelTrip || DEFAULT_COPY.trip,
			scanBarcode: d.labelScanBarcode || DEFAULT_COPY.scanBarcode,
			deleteFirst: d.labelDeleteFirst || DEFAULT_COPY.deleteFirst,
			deleteSecond: d.labelDeleteSecond || DEFAULT_COPY.deleteSecond,
			deleteError: d.labelDeleteError || DEFAULT_COPY.deleteError,
			deleted: d.labelDeleted || DEFAULT_COPY.deleted
		};
	}

	/**
	 * Wire the scan-loop page: resume a requested open trip or start one, then append/increment lines as each scan (camera
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
		var finishButton = document.getElementById('grocyai-capture-finish-button');
		var reviewLink = document.getElementById('grocyai-capture-review-link');
		var reviewUrl = reviewLink ? reviewLink.getAttribute('href') : '';
		var statusEl = document.getElementById('grocyai-capture-status');
		var linesEl = document.getElementById('grocyai-capture-lines');
		var cameraConfirmation = document.getElementById('grocyai-capture-camera-confirmation');
		var cameraInput = document.getElementById('grocyai-capture-camera-barcode');
		var cameraSaveButton = document.getElementById('grocyai-capture-camera-save-button');
		var cameraCancelButton = document.getElementById('grocyai-capture-camera-cancel-button');

		var summaryEl = document.getElementById('grocyai-capture-trip-summary');
		var latestEl = document.getElementById('grocyai-capture-latest');
		var showAllButton = document.getElementById('grocyai-capture-show-all');
		var deleteButton = document.getElementById('grocyai-capture-delete-trip-button');
		var recentIds = [];
		var latestId = null;
		var showAll = false;
		var currentStatus = null;
		var currentTripId = null;
		var lines = [];
		var productNames = {};
		var pendingScans = 0;
		var finishing = false;
		var pendingCameraReads = [];
		var cameraSubmitting = false;
		var cameraLocked = false;

		function setReviewUrl(url)
		{
			if (reviewLink) reviewLink.setAttribute('href', url);
			var headerLink = document.getElementById('grocyai-capture-header-review-link');
			if (headerLink) headerLink.setAttribute('href', url);
		}

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
			if (summaryEl) summaryEl.textContent = (currentTripId === null ? '' : copy.trip + ' #' + currentTripId + ' · ' + (currentStatus === 'open' ? copy.statusOpen : currentStatus === 'reviewing' ? copy.statusReviewing : copy.statusCommitted) + ' · ') + lines.length + ' ' + copy.distinctItems + ' · ' + copy.totalQuantity + ': ' + Number(lines.reduce(function (sum, line) { return sum + Number(line.quantity); }, 0).toPrecision(12));
			if (showAllButton) { showAllButton.hidden = lines.length <= 3; showAllButton.textContent = showAll ? copy.showRecent : copy.showAll; showAllButton.setAttribute('aria-expanded', String(showAll)); }
			if (finishButton) finishButton.hidden = currentStatus !== 'open';
			if (deleteButton) deleteButton.disabled = currentTripId === null || currentStatus === 'committed' || lines.some(function (line) { return line.applied_at !== null; }) || pendingScans > 0 || finishing || cameraLocked || cameraSubmitting;
			if (latestEl) latestEl.textContent = '';
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
				item.hidden = !showAll && recentIds.slice(-3).indexOf(String(line.id)) === -1;

				var name = document.createElement('span');
				name.className = 'grocy-ai-capture-line-name';
				name.textContent = label.text;
				var detail = document.createElement('div');
				detail.className = 'grocy-ai-capture-line-detail';
				var barcode = document.createElement('span');
				barcode.className = 'grocy-ai-capture-line-barcode grocy-ai-flow-upc text-muted';
				barcode.textContent = copy.barcode + ' ' + String(line.scanned_barcode);
				detail.appendChild(name);
				detail.appendChild(barcode);

				var quantity = document.createElement('span');
				quantity.className = 'badge badge-pill grocy-ai-capture-line-quantity';
				quantity.textContent = '× ' + describeQuantity(line.quantity);
				quantity.setAttribute('aria-label', copy.quantity + ' ' + describeQuantity(line.quantity));

				item.appendChild(detail);
				item.appendChild(quantity);
				linesEl.appendChild(item);
				if (latestEl && String(line.id) === latestId) {
					var latest = document.createElement('div');
					latest.className = 'grocy-ai-capture-latest-body d-flex align-items-center';
					var latestDetail = detail.cloneNode(true);
					latestDetail.querySelector('.grocy-ai-capture-line-name').className = 'grocy-ai-capture-latest-name';
					latestDetail.querySelector('.grocy-ai-capture-line-barcode').className = 'grocy-ai-capture-latest-barcode grocy-ai-flow-upc text-muted';
					var latestQuantity = quantity.cloneNode(true);
					latestQuantity.className = 'grocy-ai-flow-badge';
					latest.appendChild(latestDetail);
					latest.appendChild(latestQuantity);
					var badge = document.createElement('span'); badge.className = 'grocy-ai-flow-badge'; badge.textContent = line.status === 'known' ? copy.saved : copy.needsDetails;
					latest.appendChild(badge); latestEl.appendChild(latest);
				}
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
			if (finishing || cameraLocked || cameraSubmitting || pendingScans > 0)
			{
				return Promise.resolve(null);
			}
			return fetchJson(tripsEndpoint, { method: 'POST', body: '{}' }).then(function (trip)
			{
				currentTripId = trip && trip.id !== undefined ? trip.id : null;
				currentStatus = trip.status; recentIds = []; latestId = null; showAll = false;
				if (reviewLink)
				{
					setReviewUrl(currentTripId === null ? reviewUrl : reviewUrl + '?trip=' + encodeURIComponent(String(currentTripId)));
				}
				lines = [];
				productNames = {};
				pendingCameraReads = [];
				showNextCameraRead();
				render();
				setStatus(copy.tripStarted);
				if (typeof window !== 'undefined' && window.history && window.location)
				{
				var nextUrl = new URL(window.location.href);
				nextUrl.searchParams.delete('trip');
				window.history.replaceState(null, '', nextUrl.pathname + nextUrl.search + nextUrl.hash);
				}
				focusInput();
			}).catch(function ()
			{
				currentTripId = null;
				if (reviewLink)
				{
					setReviewUrl(reviewUrl);
				}
				setStatus(copy.tripError);
			});
		}

		function resumeTrip(tripId)
		{
			return fetchJson(tripsEndpoint + '/' + encodeURIComponent(tripId), { method: 'GET' }).then(function (payload)
			{
				if (!payload || !payload.trip || String(payload.trip.id) !== tripId || payload.trip.status !== 'open'
					|| !Array.isArray(payload.lines) || payload.lines.some(function (line)
					{
						return !isLinePayload(line) || String(line.trip_id) !== tripId;
					}))
				{
					throw new Error('invalid_resume_trip');
				}
				currentTripId = tripId;
				currentStatus = payload.trip.status;
				lines = payload.lines;
				recentIds = lines.map(function (line) { return String(line.id); });
				latestId = null; showAll = false;
				productNames = {};
				if (reviewLink) setReviewUrl(reviewUrl + '?trip=' + encodeURIComponent(tripId));
				lines.forEach(function (line) { if (line.status === 'known') resolveProductName(line.resolved_product_id); });
				render();
				setStatus(copy.tripResumed.replace('%s', tripId));
				focusInput();
			}).catch(function ()
			{
				currentTripId = null;
				lines = [];
				render();
				if (reviewLink) setReviewUrl(reviewUrl);
				setStatus(copy.resumeError);
			});
		}

		function submitBarcode(rawBarcode, failClosed)
		{
			var barcode = String(rawBarcode === undefined || rawBarcode === null ? '' : rawBarcode).trim();
			if (barcode === '' || currentTripId === null || cameraLocked || finishing || (cameraSubmitting && !failClosed))
			{
				return Promise.resolve(null);
			}
			pendingScans++;
			var url = tripsEndpoint + '/' + encodeURIComponent(String(currentTripId)) + '/scan';
			return fetchJson(url, { method: 'POST', body: JSON.stringify({ barcode: barcode }) }).then(function (line)
			{
				if (!isLinePayload(line))
				{
					throw new Error('invalid_scan_payload');
				}
				lines = upsertLines(lines, line);
				latestId = String(line.id);
				recentIds = recentIds.filter(function (id) { return id !== latestId; }); recentIds.push(latestId);
				render();
				if (line.status === 'known')
				{
					resolveProductName(line.resolved_product_id);
				}
				clearInput();
				return line;
			}).catch(function (error)
			{
				setStatus(copy.scanError);
				if (failClosed) throw error;
				return null;
			}).finally(function ()
			{
				pendingScans--;
				render();
			});
		}

		function lockCameraAfterFailedScan()
		{
			cameraLocked = true;
			configureScanner();
			if (cameraSaveButton) cameraSaveButton.disabled = true;
			if (cameraCancelButton) cameraCancelButton.disabled = true;
			if (newTripButton) newTripButton.disabled = true;
			if (addButton) addButton.disabled = true;
			if (input) input.disabled = true;
			if (cameraInput) cameraInput.disabled = true;
			setStatus(copy.cameraUnverified);
		}

		function showNextCameraRead()
		{
			if (!cameraConfirmation || !cameraInput)
			{
				return;
			}
			if (pendingCameraReads.length === 0)
			{
				cameraConfirmation.hidden = true;
				return;
			}
			cameraInput.value = pendingCameraReads[0];
			cameraConfirmation.hidden = false;
			cameraInput.focus();
		}

		function finishCameraRead(save)
		{
			if (pendingCameraReads.length === 0 || cameraSubmitting || cameraLocked)
			{
				return;
			}
			var barcode = cameraInput ? cameraInput.value.trim() : '';
			if (save && barcode === '')
			{
				if (cameraInput) cameraInput.focus();
				return;
			}
			if (save && pendingScans > 0)
			{
				setStatus(copy.finishPending);
				return;
			}
			if (!save)
			{
				pendingCameraReads.shift();
				showNextCameraRead();
				return;
			}
			cameraSubmitting = true;
			configureScanner();
			if (cameraSaveButton) cameraSaveButton.disabled = true;
			if (cameraCancelButton) cameraCancelButton.disabled = true;
			if (newTripButton) newTripButton.disabled = true;
			if (addButton) addButton.disabled = true;
			if (input) input.disabled = true;
			submitBarcode(barcode, true).then(function (line)
			{
				if (line)
				{
					pendingCameraReads.shift();
					showNextCameraRead();
				}
			}).catch(function () { lockCameraAfterFailedScan(); }).finally(function ()
			{
				cameraSubmitting = false;
				configureScanner();
				if (!cameraLocked)
				{
					if (cameraSaveButton) cameraSaveButton.disabled = false;
					if (cameraCancelButton) cameraCancelButton.disabled = false;
					if (newTripButton) newTripButton.disabled = false;
					if (addButton) addButton.disabled = false;
					if (input) input.disabled = false;
				}
				render();
			});
		}

		function finishScanning()
		{
			if (currentTripId === null || finishing)
			{
				return Promise.resolve(null);
			}
			if (cameraLocked)
			{
				setStatus(copy.cameraUnverified);
				return Promise.resolve(null);
			}
			if (pendingScans > 0)
			{
				setStatus(copy.finishPending);
				return Promise.resolve(null);
			}
			if (pendingCameraReads.length > 0)
			{
				setStatus(copy.cameraPending);
				return Promise.resolve(null);
			}
			if (typeof window === 'undefined' || typeof window.confirm !== 'function'
				|| !window.confirm(copy.finishFirst) || !window.confirm(copy.finishSecond))
			{
				return Promise.resolve(null);
			}
			finishing = true;
			if (finishButton)
			{
				finishButton.disabled = true;
			}
			var tripId = currentTripId;
			return fetchJson(tripsEndpoint + '/' + encodeURIComponent(String(tripId)), {
				method: 'PUT',
				body: JSON.stringify({ status: 'reviewing' })
			}).then(function (trip)
			{
				if (!trip || String(trip.id) !== String(tripId) || trip.status !== 'reviewing')
				{
					throw new Error('invalid_trip_status');
				}
				window.location.assign(reviewUrl + '?trip=' + encodeURIComponent(String(tripId)));
			}).catch(function ()
			{
				finishing = false;
				if (finishButton)
				{
					finishButton.disabled = false;
				}
				setStatus(copy.finishError);
			});
		}

		function cancelTrip()
		{
			if (currentTripId === null || finishing || pendingScans > 0 || cameraLocked || cameraSubmitting || currentStatus === 'committed' || lines.some(function (line) { return line.applied_at !== null; })) return Promise.resolve(null);
			if (!window.confirm(copy.deleteFirst) || !window.confirm(copy.deleteSecond)) return Promise.resolve(null);
			var tripId = currentTripId; finishing = true; render();
			return fetchJson(tripsEndpoint + '/' + encodeURIComponent(String(tripId)) + '/cancel', { method: 'POST', body: '{}' }).then(function (result) {
				if (!result || result.canceled !== true || String(result.trip_id) !== String(tripId)) throw new Error('invalid_cancel_response');
				currentTripId = null; currentStatus = null; lines = []; recentIds = []; latestId = null; pendingCameraReads = []; showNextCameraRead();
				if (reviewLink) setReviewUrl(reviewUrl);
				var url = new URL(window.location.href); url.searchParams.delete('trip'); window.history.replaceState(null, '', url.pathname + url.search + url.hash);
				setStatus(copy.deleted);
			}).catch(function () { setStatus(copy.deleteError); }).finally(function () { finishing = false; render(); });
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

		if (showAllButton) showAllButton.addEventListener('click', function () { showAll = !showAll; render(); });
		if (deleteButton) deleteButton.addEventListener('click', cancelTrip);
		// The core creates its trigger after this module loads in some layouts. Only label/style
		// that trigger; never move it away from the input used by the core's .prev() lookup.
		function configureScanner()
		{
			var trigger = root.querySelector('#camerabarcodescanner-start-button');
			if (!trigger) return;
			if (trigger.textContent !== copy.scanBarcode) trigger.textContent = copy.scanBarcode;
			var disabled = cameraLocked || cameraSubmitting || trigger.classList.contains('disabled') || (input && input.disabled);
			trigger.setAttribute('aria-label', copy.scanBarcode);
			trigger.setAttribute('role', 'button');
			trigger.setAttribute('tabindex', disabled ? '-1' : '0');
			trigger.setAttribute('aria-disabled', String(disabled));
		}
		configureScanner();
		if (typeof MutationObserver !== 'undefined') {
			var scannerObserver = new MutationObserver(configureScanner); scannerObserver.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'disabled'] });
		}
		root.addEventListener('click', function (event)
		{
			var trigger = event.target.closest && event.target.closest('#camerabarcodescanner-start-button');
			if (trigger && (cameraLocked || cameraSubmitting || trigger.classList.contains('disabled') || (input && input.disabled)))
			{
				event.preventDefault();
				event.stopImmediatePropagation();
			}
		}, true);
		root.addEventListener('keydown', function (event) { if (event.target.id === 'camerabarcodescanner-start-button' && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); event.target.click(); } });
		if (addButton)
		{
			addButton.addEventListener('click', function () { submitBarcode(input ? input.value : ''); });
		}
		if (newTripButton)
		{
			newTripButton.addEventListener('click', function () { startTrip(); });
		}
		if (finishButton)
		{
			finishButton.addEventListener('click', function () { finishScanning(); });
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
		if (cameraSaveButton) cameraSaveButton.addEventListener('click', function () { finishCameraRead(true); });
		if (cameraCancelButton) cameraCancelButton.addEventListener('click', function () { finishCameraRead(false); });
		if (cameraInput) cameraInput.addEventListener('keydown', function (event)
		{
			if (event.key === 'Enter')
			{
				event.preventDefault();
				finishCameraRead(true);
			}
		});

		// Reuse the enrichment card's camera scanner path: the core camera control fires this jQuery event
		// with the input's data-target; hold this page's camera reads for confirmation before saving.
		if (typeof window !== 'undefined' && typeof window.$ === 'function')
		{
			window.$(document).on('Grocy.BarcodeScanned', function (event, barcode, target)
			{
				if (target !== 'grocyai-capture-barcode' || cameraLocked)
				{
					return;
				}
				pendingCameraReads.push(String(barcode));
				if (pendingCameraReads.length === 1) showNextCameraRead();
			});
		}

		var requestedTrip = typeof window !== 'undefined' && window.location
			? new URLSearchParams(window.location.search).get('trip') : null;
		if (requestedTrip === null) requestedTrip = root.getAttribute('data-default-trip-id') || null;
		if (requestedTrip === null) startTrip();
		else if (/^[1-9][0-9]{0,9}$/.test(requestedTrip)) resumeTrip(requestedTrip);
		else setStatus(copy.resumeError);

		return {
			startTrip: startTrip,
			resumeTrip: resumeTrip,
			submitBarcode: submitBarcode,
			finishScanning: finishScanning
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
