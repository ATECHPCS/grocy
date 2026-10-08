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
		var receiptNotice = '';
		var receiptStates = {};
		var researchComponent = null;
		var receiptComponent = null;
		var researchStates = {};
		var researchNotices = {};
		var activeReviewKey = null;
		var activeReviewIndex = 0;
		var reviewQueue = { cards: [], receiptOwnerByKey: {} };
		var renderRevision = 0;
		var loadRevision = 0;
		var loadingTrip = false;
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
			var revision = ++loadRevision;
			loadingTrip = true;
			detailEl.inert = true;
			if (researchComponent) researchComponent.dispose();
			if (receiptComponent) receiptComponent.dispose();
			receiptReadiness = null;
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
					loadingTrip = false;
					detailEl.textContent = copy.loadError;
					return;
				}
				if (currentTripId !== String(tripId))
				{
					activeReviewKey = null;
					activeReviewIndex = 0;
					receiptNotice = '';
					commitMessage = '';
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
				loadingTrip = false;
				renderTripListActive();
				renderDetail();
			}).catch(function ()
			{
				if (revision === loadRevision) { detailEl.inert = false; loadingTrip = false; detailEl.textContent = copy.loadError; }
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
			var tripId = currentTripId, revision = loadRevision;
			return fetchJson(tripUrl(tripId), { method: 'PUT', body: JSON.stringify(body) }).then(function (trip)
			{
				if (revision !== loadRevision || tripId !== currentTripId) return;
				if (isTripPayload(trip))
				{
					currentTrip = trip;
				}
				loadTrip(currentTripId);
			}).catch(function () { flashError(); });
		}

		function putLine(seq, body)
		{
			var tripId = currentTripId, revision = loadRevision;
			var editedLine = currentLines.find(function (line) { return line.seq === seq; });
			return fetchJson(lineUrl(tripId, seq), { method: 'PUT', body: JSON.stringify(body) }).then(function (payload)
			{
				// Explicit deletion settles only the removed scan, including after a trip switch.
				if (body.delete === true && editedLine && isLoadedTripPayload(payload) && String(payload.trip.id) === tripId && !payload.lines.some(function (line) { return line.id === editedLine.id; }) && researchStates[tripId]) delete researchStates[tripId][editedLine.id];
				if (revision !== loadRevision || tripId !== currentTripId) return;
				if (isLoadedTripPayload(payload))
				{
					currentTrip = payload.trip;
					currentLines = payload.lines;
					currentLines.forEach(function (line) { if (line.status === 'known') { resolveProductName(line.resolved_product_id); } });
					return loadTrip(currentTripId);
				}
			}).catch(function (error)
			{
				flashError(body.delete === true && error.status === 409 && typeof error.serverMessage === 'string' && error.serverMessage.indexOf('This scan has receipt allocation history;') === 0 ? error.serverMessage : null);
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

			var receiptsHost = element('div', 'grocy-ai-receipts');
			detailEl.appendChild(receiptsHost);
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
				item.classList.add('grocy-ai-review-card');
				item.setAttribute('data-review-key', card.key);
				item.setAttribute('data-review-status', unresolved(card) ? 'Needs review' : scan ? (Number(scan.selected) === 0 ? 'Not included' : scan.status === 'known' ? 'Known product' : 'Needs product') : receiptByKey[card.key].decision === 'ignore' ? 'Ignored' : 'Included');
				var heading = element('h3', 'grocy-ai-review-heading', scan ? 'Scan #' + scan.seq : (card.kind === 'adjustment' ? 'Receipt adjustment' : 'Receipt item') + ' · #' + card.receiptId);
				heading.tabIndex = -1; item.insertBefore(heading, item.firstChild);
				hosts[card.key] = item; linesList.appendChild(item);
				card.receiptKeys.forEach(function (key)
				{
					if (reviewQueue.receiptOwnerByKey[key] === card.key) return;
					item.appendChild(element('p', 'text-muted', receiptByKey[key].description + ' · quantity ' + receiptByKey[key].quantity + ' · line total ' + receiptByKey[key].line_total));
					var shared = element('button', 'btn btn-outline-secondary', 'Review shared receipt line: ' + receiptByKey[key].description);
					shared.type = 'button';
					shared.addEventListener('click', function () { selectCard(reviewQueue.receiptOwnerByKey[key], true); });
					item.appendChild(shared);
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
			previous.addEventListener('click', function () { moveCard(-1, true); });
			next.addEventListener('click', function () { moveCard(1, true); });
			researchStates[currentTripId] = researchStates[currentTripId] || {};
			selectCard(activeReviewKey, false);
			var researchError = element('div', 'invalid-feedback d-block');
			detailEl.appendChild(researchError);
			if (window.GrocyAIProductResearch) researchComponent = window.GrocyAIProductResearch(linesList, { editState: researchStates[currentTripId], onEdit: updateReviewState, errorHost: researchError, tripId: currentTripId, lines: currentLines, receipts: receiptViews, locationId: currentTrip.default_location_id, readOnly: currentTrip.status === 'committed', reload: function (notice, seq)
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
				receiptStates[currentTripId] = receiptStates[currentTripId] || { drafts: {}, uploads: [] };
				receiptComponent = window.GrocyAIReceipts(receiptsHost, { compact: true, state: receiptStates[currentTripId], inputRoot: detailEl, lineHostFor: function (receipt, line) { return hosts[reviewQueue.receiptOwnerByKey['receipt:' + receipt.id + ':' + line.id]]; }, url: tripUrl(currentTripId), receipts: receiptViews, lines: currentLines, names: productNames, stores: shoppingLocations, productNew: productNewBase, readOnly: currentTrip.status === 'committed', notice: receiptNotice, reload: reloadCurrent, onBusy: function (value) { if (generation !== renderRevision) return; receiptBusy = value; updateReviewState(); } });
			}

			var readiness = element('div', 'alert alert-info');
			readiness.id = 'grocyai-receipt-readiness';
			readiness.setAttribute('role', 'status');
			if (!receiptReadiness) readiness.textContent = 'Could not check receipt readiness. Reload to try again.';
			else if (receiptReadiness.ready) readiness.textContent = 'Receipts reviewed — ready to commit purchase.';
			else
			{
				var reasons = receiptReadiness.reasons || [];
				readiness.appendChild(element('p', 'mb-2', reasons.length ? reasons.length + ' review checks remain. Work through the item cards and receipt lines before committing.' : 'Purchase review is not ready. Reload to check again.'));
				if (reasons.length)
				{
					var detailsButton = element('button', 'btn btn-outline-secondary', 'View ' + reasons.length + ' review checks');
					detailsButton.type = 'button';
					detailsButton.setAttribute('aria-expanded', 'false');
					detailsButton.setAttribute('aria-controls', 'grocyai-receipt-readiness-details');
					var details = element('ul', 'grocy-ai-readiness-details');
					details.id = 'grocyai-receipt-readiness-details';
					details.hidden = true;
					reasons.forEach(function (reason) { details.appendChild(element('li', null, window.GrocyAIReceiptReason(reason))); });
					detailsButton.addEventListener('click', function ()
					{
						details.hidden = !details.hidden;
						detailsButton.setAttribute('aria-expanded', String(!details.hidden));
						detailsButton.textContent = details.hidden ? 'View ' + reasons.length + ' review checks' : 'Hide review checks';
					});
					readiness.appendChild(detailsButton);
					readiness.appendChild(details);
				}
			}
			detailEl.appendChild(readiness);

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
				commitButton.disabled = receiptBusy || !receiptReadiness || receiptReadiness.ready !== true;
				commitButton.setAttribute('aria-describedby', 'grocyai-receipt-readiness');
				commitButton.id = 'grocyai-capture-review-commit';
				commitButton.addEventListener('click', function () { commitTrip(); });
				commitSection.appendChild(commitButton);
			}
			var commitResult = element('div', 'grocy-ai-capture-review-commit-result mt-2');
			commitResult.id = 'grocyai-capture-review-commit-result';
			commitResult.textContent = commitMessage;
			commitResult.setAttribute('role', 'status');
			commitResult.setAttribute('aria-live', 'polite');
			commitSection.appendChild(commitResult);
			detailEl.appendChild(commitSection);

			detailEl.appendChild(error);
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
			if (button) button.disabled = receiptBusy || unsaved || !receiptReadiness || receiptReadiness.ready !== true;
			var announcement = document.getElementById('grocyai-review-announcement');
			var activeCard = detailEl.querySelector('.grocy-ai-review-card.is-active');
			if (announcement) announcement.textContent = (reviewQueue.cards.length ? (activeReviewIndex + 1) + ' of ' + reviewQueue.cards.length + ' · ' : '') + (unsaved ? 'Unsaved changes — save before committing.' : activeCard ? activeCard.getAttribute('data-review-status') : 'No review items');
		}

		function commitTrip()
		{
			if (hasResearchEdits() || receiptComponent && receiptComponent.hasUnsavedEdits() || receiptBusy || !receiptReadiness || receiptReadiness.ready !== true || currentTripId === null || typeof currentChecksum !== 'string')
			{
				return Promise.resolve(null);
			}
			if (typeof window !== 'undefined' && typeof window.confirm === 'function' && !window.confirm(copy.commitConfirm))
			{
				return Promise.resolve(null);
			}
			receiptBusy = true;
			document.getElementById('grocyai-capture-review-commit').disabled = true;
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
				receiptBusy = false;
				renderCommitResult(result);
				loadTrip(currentTripId);
			}).catch(function () { receiptBusy = false; flashError(); var button = document.getElementById('grocyai-capture-review-commit'); if (button) button.disabled = false; });
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
				message = result.outcome === 'receipt_review_required' ? 'Receipt review is required before purchase commit.' : copy.saveError;
			}
			commitMessage = message;
			target.textContent = message;
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
