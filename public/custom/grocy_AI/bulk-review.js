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
		root.GrocyAIBulkReview = api;
		if (root.document)
		{
			api.attachBulkReview(root.document);
		}
	}
})(typeof window !== 'undefined' ? window : null, function ()
{
	'use strict';

	/**
	 * The bulk plan review surface (Phase 5 Plan 05-05, D-04/D-13). This module owns no write path of
	 * its own: it reads a stored plan, toggles one item's selection via the server, and always
	 * re-renders from the server's response — it never fabricates, merges, or locally derives item
	 * state. All rendering below builds DOM nodes with `createElement`/`textContent` only; no server
	 * value is ever assigned to `innerHTML`, so no rendered field can inject markup.
	 */

	var COPY = {
		planLabel: 'Plan',
		loadError: 'The plan could not be loaded. Try again.',
		diffError: 'The selected diff could not be loaded. Try again.',
		toggleError: 'The selection could not be saved. Try again.',
		emptyItems: 'This plan has no items.',
		emptyDiff: 'No items are currently selected.',
		noPlanSelected: 'No plan is selected. Provide a plan id to review.',
		selectLabel: 'Include this change',
		beforeLabel: 'Before',
		proposedLabel: 'Proposed',
		operationLabel: 'Operation',
		reasonLabel: 'Reason',
		provenanceLabel: 'Provenance',
		checksumLabel: 'Checksum',
		includedLabel: 'Included in apply set',
		blankValue: 'None',
		conflictNote: 'This item conflicts with the current data and cannot be part of the apply set.'
	};

	// Closed vocabularies from the endpoint contract (05-05-PLAN). These tokens are rendered verbatim
	// — never remapped to invented copy — and are used only to validate the response shape.
	var COUNT_KEYS = ['included', 'excluded', 'skipped', 'conflicted', 'changed', 'unchanged'];
	var OUTCOME_VALUES = ['pending', 'applied', 'conflict', 'skipped', 'rejected', 'rolled_back'];
	var PLAN_HEADER_KEYS = [
		'id', 'created_at', 'created_by', 'ruleset_version', 'operation_type', 'scope_json',
		'counts_json', 'checksum', 'status', 'module_version'
	];
	var ITEM_KEYS = [
		'seq', 'object_type', 'object_id', 'operation', 'before_image', 'proposed_value', 'reason',
		'provenance', 'selected', 'outcome'
	];
	var IMAGE_KEYS = ['leaf_slug'];
	var CHECKSUM_PATTERN = /^[0-9a-f]{64}$/;
	var MAX_COUNT = 1000000000;
	var MAX_ITEMS = 10000;

	function hasExactKeys(value, keys)
	{
		if (value === null || typeof value !== 'object' || Array.isArray(value))
		{
			return false;
		}
		var actual = Object.keys(value).sort();
		var expected = keys.slice().sort();
		return actual.length === expected.length && actual.every(function (key, index) { return key === expected[index]; });
	}

	function isCount(value)
	{
		return typeof value === 'number' && isFinite(value) && Math.floor(value) === value && value >= 0 && value <= MAX_COUNT;
	}

	function isPositiveInt(value)
	{
		return isCount(value) && value > 0;
	}

	function isBoundedString(value, max)
	{
		return typeof value === 'string' && value.length > 0 && value.length <= max;
	}

	function isImage(value)
	{
		return hasExactKeys(value, IMAGE_KEYS) && (value.leaf_slug === null || isBoundedString(value.leaf_slug, 200));
	}

	function isItem(value)
	{
		return hasExactKeys(value, ITEM_KEYS)
			&& isCount(value.seq)
			&& isBoundedString(value.object_type, 64)
			&& isPositiveInt(value.object_id)
			&& isBoundedString(value.operation, 64)
			&& isImage(value.before_image)
			&& isImage(value.proposed_value)
			&& isBoundedString(value.reason, 200)
			&& isBoundedString(value.provenance, 200)
			&& typeof value.selected === 'boolean'
			&& OUTCOME_VALUES.indexOf(value.outcome) !== -1;
	}

	function isItemsArray(value)
	{
		if (!Array.isArray(value) || value.length > MAX_ITEMS)
		{
			return false;
		}
		var seen = {};
		return value.every(function (item)
		{
			if (!isItem(item) || Object.prototype.hasOwnProperty.call(seen, item.seq))
			{
				return false;
			}
			seen[item.seq] = true;
			return true;
		});
	}

	function isCounts(value)
	{
		return hasExactKeys(value, COUNT_KEYS) && COUNT_KEYS.every(function (key) { return isCount(value[key]); });
	}

	function isPlanHeader(value)
	{
		return hasExactKeys(value, PLAN_HEADER_KEYS)
			&& isPositiveInt(value.id)
			&& isBoundedString(value.created_at, 64)
			&& (value.created_by === null || isBoundedString(value.created_by, 255))
			&& isBoundedString(value.ruleset_version, 120)
			&& isBoundedString(value.operation_type, 64)
			&& typeof value.scope_json === 'string' && value.scope_json.length <= 5000
			&& typeof value.counts_json === 'string' && value.counts_json.length <= 2000
			&& CHECKSUM_PATTERN.test(value.checksum)
			&& isBoundedString(value.status, 32)
			&& isBoundedString(value.module_version, 64);
	}

	/**
	 * Validate the closed `GET/PUT .../plans/{planId}` response shape: `{ plan, counts, items }`. Any
	 * extra/missing key, out-of-range count, unknown outcome token, or duplicate item seq fails closed.
	 */
	function isPlanPayload(value)
	{
		return hasExactKeys(value, ['plan', 'counts', 'items'])
			&& isPlanHeader(value.plan)
			&& isCounts(value.counts)
			&& isItemsArray(value.items);
	}

	/**
	 * Validate the closed `GET .../selected-diff` response shape: `{ plan_id, checksum, operation_type,
	 * ruleset_version, included, items }`. Every item must be selected and `items.length` must equal
	 * `included`, matching the server's own apply-set count invariant.
	 */
	function isSelectedDiffPayload(value)
	{
		if (!hasExactKeys(value, ['plan_id', 'checksum', 'operation_type', 'ruleset_version', 'included', 'items']))
		{
			return false;
		}
		if (!isPositiveInt(value.plan_id) || !CHECKSUM_PATTERN.test(value.checksum)
			|| !isBoundedString(value.operation_type, 64) || !isBoundedString(value.ruleset_version, 120)
			|| !isCount(value.included) || !isItemsArray(value.items) || value.items.length !== value.included)
		{
			return false;
		}
		return value.items.every(function (item) { return item.selected === true; });
	}

	function itemRow(item)
	{
		return {
			seq: item.seq,
			objectType: item.object_type,
			objectId: item.object_id,
			operation: item.operation,
			before: item.before_image.leaf_slug,
			proposed: item.proposed_value.leaf_slug,
			reason: item.reason,
			provenance: item.provenance,
			selected: item.selected,
			outcome: item.outcome
		};
	}

	/**
	 * Turn a raw `GET/PUT .../plans/{planId}` response into a DOM-free presentation. A malformed or
	 * out-of-contract payload fails closed to `valid: false` with empty counts/items rather than
	 * rendering anything partial or guessed.
	 */
	function describePlan(payload)
	{
		if (!isPlanPayload(payload))
		{
			return { valid: false, planId: null, status: '', checksum: '', rulesetVersion: '', counts: [], items: [] };
		}
		return {
			valid: true,
			planId: payload.plan.id,
			status: payload.plan.status,
			checksum: payload.plan.checksum,
			rulesetVersion: payload.plan.ruleset_version,
			counts: COUNT_KEYS.map(function (key) { return { term: key, value: String(payload.counts[key]) }; }),
			items: payload.items.map(itemRow)
		};
	}

	/**
	 * Turn a raw `GET .../selected-diff` response into a DOM-free presentation. Fails closed the same
	 * way as `describePlan`.
	 */
	function describeSelectedDiff(payload)
	{
		if (!isSelectedDiffPayload(payload))
		{
			return { valid: false, planId: null, checksum: '', operationType: '', rulesetVersion: '', included: 0, items: [] };
		}
		return {
			valid: true,
			planId: payload.plan_id,
			checksum: payload.checksum,
			operationType: payload.operation_type,
			rulesetVersion: payload.ruleset_version,
			included: payload.included,
			items: payload.items.map(itemRow)
		};
	}

	function safePromise(fn)
	{
		try
		{
			return Promise.resolve(fn());
		}
		catch (error)
		{
			return Promise.reject(error);
		}
	}

	/**
	 * Drives the read-load and per-item-toggle flow. Every render call is guarded by a monotonic
	 * sequence number so a slow or out-of-order response can never overwrite a newer one, and every
	 * item/summary/diff render is built strictly from the server payload passed to it — the controller
	 * holds no local copy of item state to merge into a response.
	 */
	function createBulkReviewController(options)
	{
		var sequence = 0;
		var renderedPlan = 0;
		var renderedDiff = 0;

		function applyPlan(owned, payload)
		{
			var presentation = describePlan(payload);
			if (!presentation.valid)
			{
				throw new Error('plan_invalid');
			}
			if (owned > renderedPlan)
			{
				renderedPlan = owned;
				options.renderPlan(presentation);
			}
			return presentation;
		}

		function applyDiff(owned, payload)
		{
			var presentation = describeSelectedDiff(payload);
			if (!presentation.valid)
			{
				throw new Error('diff_invalid');
			}
			if (owned > renderedDiff)
			{
				renderedDiff = owned;
				options.renderSelectedDiff(presentation);
			}
			return presentation;
		}

		function refreshDiff(owned)
		{
			return safePromise(options.requestSelectedDiff).then(function (payload)
			{
				return applyDiff(owned, payload);
			}, function ()
			{
				if (owned > renderedDiff)
				{
					options.onError(COPY.diffError, 'diff');
				}
				return null;
			});
		}

		/** Load the plan and its selected diff together and render both from their own responses. */
		function load()
		{
			sequence++;
			var owned = sequence;
			options.onBusy(true);

			var planPromise = safePromise(options.requestPlan).then(function (payload)
			{
				return applyPlan(owned, payload);
			}, function ()
			{
				if (owned > renderedPlan)
				{
					options.onError(COPY.loadError, 'plan');
				}
				return null;
			});

			return Promise.all([planPromise, refreshDiff(owned)]).then(function (results)
			{
				options.onBusy(false);
				return { plan: results[0], diff: results[1] };
			});
		}

		/**
		 * Toggle exactly one item's selection. The PUT response (the re-read plan) is the sole source
		 * for the re-rendered summary/items; the selected diff is then re-fetched from its own endpoint
		 * and rendered from that separate response. Neither render step ever consults prior client state.
		 */
		function toggle(seq, selected)
		{
			sequence++;
			var owned = sequence;
			options.onBusy(true);

			return safePromise(function () { return options.requestSetSelection(seq, selected); })
				.then(function (payload)
				{
					var presentation = applyPlan(owned, payload);
					return refreshDiff(owned).then(function ()
					{
						options.onBusy(false);
						return presentation;
					});
				})
				.catch(function ()
				{
					if (owned > renderedPlan)
					{
						options.onError(COPY.toggleError, 'toggle');
					}
					options.onBusy(false);
					return null;
				});
		}

		return { load: load, toggle: toggle };
	}

	function element(document, tag, text)
	{
		var node = document.createElement(tag);
		if (typeof text === 'string')
		{
			node.textContent = text;
		}
		return node;
	}

	function valueOrBlank(value)
	{
		return value === null ? COPY.blankValue : value;
	}

	function outcomeBadge(document, outcome)
	{
		var badge = element(document, 'span', outcome);
		badge.className = 'badge grocy-ai-bulk-outcome grocy-ai-bulk-outcome-' + outcome;
		return badge;
	}

	/** Render the counts summary from a `describePlan` presentation. DOM-writing; no HTML strings. */
	function renderSummary(document, container, presentation)
	{
		container.textContent = '';
		if (!presentation.valid)
		{
			container.appendChild(element(document, 'p', COPY.loadError));
			return;
		}
		var heading = element(document, 'p', COPY.planLabel + ' #' + presentation.planId + ' — ' + presentation.status);
		heading.className = 'grocy-ai-bulk-summary-heading-line';
		container.appendChild(heading);

		var dl = document.createElement('dl');
		dl.className = 'grocy-ai-bulk-summary-list';
		presentation.counts.forEach(function (row)
		{
			dl.appendChild(element(document, 'dt', row.term));
			dl.appendChild(element(document, 'dd', row.value));
		});
		container.appendChild(dl);

		var checksum = element(document, 'p', COPY.checksumLabel + ': ' + presentation.checksum);
		checksum.className = 'grocy-ai-bulk-checksum';
		container.appendChild(checksum);
	}

	/**
	 * Render one item review card. `onToggle(seq, selected)` is called on checkbox change; the caller
	 * (the controller) is the only place that turns that into a PUT — this function never writes.
	 */
	function renderItemCard(document, row, callbacks)
	{
		var card = document.createElement('section');
		card.className = 'grocy-ai-bulk-item grocy-ai-field-review';
		card.setAttribute('data-grocy-ai-bulk-seq', String(row.seq));

		var headingId = 'grocy-ai-bulk-item-' + row.seq + '-heading';
		var beforeId = headingId + '-before';
		var proposedId = headingId + '-proposed';
		var metaId = headingId + '-meta';

		var header = document.createElement('div');
		header.className = 'grocy-ai-field-header';
		var heading = element(document, 'h4', row.objectType + ' #' + row.objectId);
		heading.id = headingId;
		header.appendChild(heading);
		header.appendChild(outcomeBadge(document, row.outcome));

		var selectionWrapper = document.createElement('div');
		selectionWrapper.className = 'custom-control custom-checkbox grocy-ai-selection-control';
		var checkbox = document.createElement('input');
		checkbox.type = 'checkbox';
		checkbox.className = 'custom-control-input';
		checkbox.id = 'grocy-ai-bulk-select-' + row.seq;
		checkbox.checked = row.selected;
		checkbox.setAttribute('aria-describedby', beforeId + ' ' + proposedId + ' ' + metaId);
		selectionWrapper.appendChild(checkbox);
		var label = element(document, 'label', COPY.selectLabel);
		label.className = 'custom-control-label';
		label.htmlFor = checkbox.id;
		selectionWrapper.appendChild(label);
		header.appendChild(selectionWrapper);
		card.appendChild(header);

		var comparison = document.createElement('div');
		comparison.className = 'grocy-ai-comparison-grid';
		var before = document.createElement('div');
		before.className = 'grocy-ai-value-cell grocy-ai-current-value';
		before.id = beforeId;
		before.appendChild(element(document, 'strong', COPY.beforeLabel));
		before.appendChild(element(document, 'span', valueOrBlank(row.before)));
		var proposed = document.createElement('div');
		proposed.className = 'grocy-ai-value-cell';
		proposed.id = proposedId;
		proposed.appendChild(element(document, 'strong', COPY.proposedLabel));
		proposed.appendChild(element(document, 'span', valueOrBlank(row.proposed)));
		comparison.appendChild(before);
		comparison.appendChild(proposed);
		card.appendChild(comparison);

		var meta = document.createElement('div');
		meta.className = 'grocy-ai-provenance';
		meta.id = metaId;
		meta.appendChild(element(document, 'span', COPY.operationLabel + ': ' + row.operation));
		meta.appendChild(element(document, 'span', COPY.reasonLabel + ': ' + row.reason));
		meta.appendChild(element(document, 'span', COPY.provenanceLabel + ': ' + row.provenance));
		card.appendChild(meta);

		if (row.outcome === 'conflict')
		{
			var conflictNote = element(document, 'p', COPY.conflictNote);
			conflictNote.className = 'grocy-ai-bulk-conflict-note alert alert-warning';
			conflictNote.setAttribute('role', 'alert');
			card.appendChild(conflictNote);
		}

		checkbox.addEventListener('change', function ()
		{
			callbacks.onToggle(row.seq, checkbox.checked);
		});

		return card;
	}

	/** Render every plan item from a `describePlan` presentation. */
	function renderItems(document, container, presentation, callbacks)
	{
		container.textContent = '';
		if (!presentation.valid)
		{
			container.appendChild(element(document, 'p', COPY.loadError));
			return;
		}
		if (presentation.items.length === 0)
		{
			container.appendChild(element(document, 'p', COPY.emptyItems));
			return;
		}
		presentation.items.forEach(function (row)
		{
			container.appendChild(renderItemCard(document, row, callbacks));
		});
	}

	function renderDiffItemCard(document, row)
	{
		var card = document.createElement('section');
		card.className = 'grocy-ai-diff-item grocy-ai-bulk-diff-item';
		card.setAttribute('data-grocy-ai-bulk-diff-seq', String(row.seq));

		var header = document.createElement('div');
		header.className = 'grocy-ai-field-header';
		header.appendChild(element(document, 'h5', row.objectType + ' #' + row.objectId));
		header.appendChild(outcomeBadge(document, row.outcome));
		card.appendChild(header);

		var grid = document.createElement('div');
		grid.className = 'grocy-ai-diff-grid';
		var before = document.createElement('div');
		before.className = 'grocy-ai-value-cell';
		before.appendChild(element(document, 'strong', COPY.beforeLabel));
		before.appendChild(element(document, 'span', valueOrBlank(row.before)));
		var proposed = document.createElement('div');
		proposed.className = 'grocy-ai-value-cell';
		proposed.appendChild(element(document, 'strong', COPY.proposedLabel));
		proposed.appendChild(element(document, 'span', valueOrBlank(row.proposed)));
		grid.appendChild(before);
		grid.appendChild(proposed);
		card.appendChild(grid);

		card.appendChild(element(document, 'p', COPY.reasonLabel + ': ' + row.reason + ' · ' + COPY.provenanceLabel + ': ' + row.provenance));
		return card;
	}

	/**
	 * Render the complete selected diff from a `describeSelectedDiff` presentation: the apply-set
	 * `included` count, and one card per selected item. No apply control is ever rendered here — this
	 * view declares no write action.
	 */
	function renderSelectedDiff(document, container, presentation)
	{
		container.textContent = '';
		if (!presentation.valid)
		{
			container.appendChild(element(document, 'p', COPY.diffError));
			return;
		}
		var summary = element(document, 'p', COPY.includedLabel + ': ' + String(presentation.included));
		summary.className = 'grocy-ai-bulk-diff-summary';
		container.appendChild(summary);

		if (presentation.items.length === 0)
		{
			container.appendChild(element(document, 'p', COPY.emptyDiff));
			return;
		}
		presentation.items.forEach(function (row)
		{
			container.appendChild(renderDiffItemCard(document, row));
		});
	}

	/**
	 * Wire the review surface to the live MASTER_DATA_EDIT-gated endpoints for the plan id carried on
	 * `#grocy-ai-bulk-review`. Returns `null` (and renders a "no plan selected" message) when the page
	 * carries no valid plan id, and issues no request in that case.
	 */
	function attachBulkReview(document)
	{
		var root = document.getElementById('grocy-ai-bulk-review');
		if (!root)
		{
			return null;
		}
		var summaryEl = document.getElementById('grocy-ai-bulk-summary');
		var itemsEl = document.getElementById('grocy-ai-bulk-items');
		var diffEl = document.getElementById('grocy-ai-bulk-selected-diff');

		var planIdRaw = root.getAttribute('data-plan-id') || '';
		var plansEndpoint = root.getAttribute('data-plans-endpoint') || '';
		var planId = /^[1-9][0-9]{0,9}$/.test(planIdRaw) ? planIdRaw : null;

		if (!planId)
		{
			if (summaryEl)
			{
				summaryEl.textContent = '';
				summaryEl.appendChild(element(document, 'p', COPY.noPlanSelected));
			}
			if (itemsEl)
			{
				itemsEl.textContent = '';
			}
			if (diffEl)
			{
				diffEl.textContent = '';
			}
			return null;
		}

		var planUrl = plansEndpoint + '/' + encodeURIComponent(planId);
		var diffUrl = planUrl + '/selected-diff';

		function selectionUrl(seq)
		{
			return planUrl + '/items/' + encodeURIComponent(String(seq)) + '/selection';
		}

		function fetchJson(url, requestOptions)
		{
			return fetch(url, Object.assign({
				credentials: 'same-origin',
				cache: 'no-store',
				headers: { Accept: 'application/json' }
			}, requestOptions)).then(function (response)
			{
				if (!response.ok)
				{
					throw new Error('http_status');
				}
				return response.json();
			});
		}

		function announceError(message)
		{
			if (!summaryEl)
			{
				return;
			}
			var recovery = summaryEl.querySelector('[data-grocy-ai-bulk-recovery]');
			if (!recovery)
			{
				recovery = element(document, 'p', '');
				recovery.setAttribute('data-grocy-ai-bulk-recovery', '');
				recovery.setAttribute('role', 'alert');
				summaryEl.appendChild(recovery);
			}
			recovery.textContent = message;
		}

		// The items list is fully rebuilt from the server on every render (never patched in place), so a
		// keyboard/screen-reader user's focus would otherwise be dropped back to the document body right
		// after they toggle a control. Track which seq was last toggled and restore focus to its rebuilt
		// checkbox once the server-driven re-render completes.
		var lastToggledSeq = null;

		var controller = createBulkReviewController({
			requestPlan: function () { return fetchJson(planUrl, { method: 'GET' }); },
			requestSelectedDiff: function () { return fetchJson(diffUrl, { method: 'GET' }); },
			requestSetSelection: function (seq, selected)
			{
				return fetchJson(selectionUrl(seq), {
					method: 'PUT',
					headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
					body: JSON.stringify({ selected: selected })
				});
			},
			renderPlan: function (presentation)
			{
				if (summaryEl)
				{
					renderSummary(document, summaryEl, presentation);
				}
				if (itemsEl)
				{
					renderItems(document, itemsEl, presentation, {
						onToggle: function (seq, selected)
						{
							lastToggledSeq = seq;
							controller.toggle(seq, selected);
						}
					});
					if (lastToggledSeq !== null)
					{
						var toFocus = itemsEl.querySelector('#grocy-ai-bulk-select-' + lastToggledSeq);
						if (toFocus)
						{
							toFocus.focus();
						}
					}
				}
			},
			renderSelectedDiff: function (presentation)
			{
				if (diffEl)
				{
					renderSelectedDiff(document, diffEl, presentation);
				}
			},
			onBusy: function (busy)
			{
				root.setAttribute('aria-busy', busy ? 'true' : 'false');
			},
			onError: announceError
		});

		controller.load();
		return controller;
	}

	return {
		COPY: COPY,
		isPlanPayload: isPlanPayload,
		isSelectedDiffPayload: isSelectedDiffPayload,
		describePlan: describePlan,
		describeSelectedDiff: describeSelectedDiff,
		createBulkReviewController: createBulkReviewController,
		renderSummary: renderSummary,
		renderItems: renderItems,
		renderSelectedDiff: renderSelectedDiff,
		attachBulkReview: attachBulkReview
	};
});
