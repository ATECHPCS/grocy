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
		conflictNote: 'This item conflicts with the current data and cannot be part of the apply set.',

		// Rollback preview (BULK-09, D-11)
		rollbackPreviewError: 'The rollback preview could not be loaded. Try again.',
		rollbackPreviewHeading: 'Rollback preview',
		reversibleHeading: 'Reversible',
		refusedHeading: 'Refused (manual edit after apply)',
		emptyReversible: 'No items are reversible.',
		emptyRefused: 'No items are refused.',
		currentLabel: 'Current value',
		afterLabel: 'After apply',
		blockerLabel: 'Blocker',
		inverseOperationLabel: 'Restore operation',

		// Apply / rollback-execute actions (D-13)
		applyButtonLabel: 'Apply plan',
		applyConfirmPrompt: 'Apply this plan now? This performs a durable, audited write against Grocy and cannot be undone from this page except by a subsequent rollback.',
		applyError: 'The plan could not be applied. Try again.',
		applyResultHeading: 'Apply result',
		rollbackButtonLabel: 'Roll back reversible items',
		rollbackConfirmPrompt: 'Roll back the reversible items from this plan now? This performs a durable, audited write against Grocy.',
		rollbackExecuteError: 'The rollback could not be completed. Try again.',
		rollbackResultHeading: 'Rollback result',
		rollbackPreviewRequiredNote: 'Load the rollback preview before rolling back, so the action is bound to a reviewed checksum.',
		statusLabel: 'Status',
		actorLabel: 'Actor',
		blockersLabel: 'Blockers',
		noBlockers: 'None',

		// Export (BULK-10, D-12)
		exportHeading: 'Export',
		exportJsonLabel: 'Download JSON (non-authoritative)',
		exportCsvLabel: 'Download CSV (non-authoritative)',
		exportNote: 'These files are non-authoritative recovery evidence for independent human review only. They cannot be re-imported to change data; Grocy remains the sole durable authority.'
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

	// Closed vocabularies for the rollback-preview (05-09) and apply/rollback-execute (05-07/05-09) DTOs.
	// Rendered/validated verbatim against the same fail-closed discipline as the plan/diff shapes above.
	var ROLLBACK_PREVIEW_KEYS = ['plan_id', 'plan_checksum', 'checksum', 'items', 'reversible', 'refused'];
	var ROLLBACK_ITEM_KEYS = [
		'plan_item_id', 'object_type', 'object_id', 'before_image', 'after_image', 'current_value',
		'inverse_operation', 'reversible', 'blocker'
	];
	var MUTATION_RESULT_KEYS = ['plan_id', 'checksum', 'status', 'blockers', 'outcomes', 'actor'];
	var APPLY_OUTCOME_KEYS = ['applied', 'conflict', 'skipped'];
	var ROLLBACK_OUTCOME_KEYS = ['rolled_back', 'conflict', 'skipped'];
	var MAX_BLOCKERS = 20;
	var EXPORT_FORMATS = ['json', 'csv'];

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

	function isNullableBoundedString(value, max)
	{
		return value === null || isBoundedString(value, max);
	}

	function isBlockersList(value)
	{
		return Array.isArray(value) && value.length <= MAX_BLOCKERS && value.every(function (entry) { return isBoundedString(entry, 64); });
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
	 * Validate one `GET .../rollback-preview` reversal entry (05-09 `PreviewRollback`). A reversible entry
	 * must carry a non-null inverse operation and no blocker; a refused entry must carry the pinned
	 * `manual_edit_after_apply`-shaped blocker and withhold the inverse operation — so the UI can never
	 * present a refused item as actionable or vice versa.
	 */
	function isRollbackItem(value)
	{
		if (!hasExactKeys(value, ROLLBACK_ITEM_KEYS)
			|| !isPositiveInt(value.plan_item_id)
			|| !isBoundedString(value.object_type, 64)
			|| !isPositiveInt(value.object_id)
			|| !isNullableBoundedString(value.before_image, 200)
			|| !isNullableBoundedString(value.after_image, 200)
			|| !isNullableBoundedString(value.current_value, 200)
			|| !isNullableBoundedString(value.inverse_operation, 64)
			|| typeof value.reversible !== 'boolean'
			|| !isNullableBoundedString(value.blocker, 200))
		{
			return false;
		}
		return value.reversible
			? (value.blocker === null && value.inverse_operation !== null)
			: (value.blocker !== null && value.inverse_operation === null);
	}

	function isRollbackItemsArray(value)
	{
		return Array.isArray(value) && value.length <= MAX_ITEMS && value.every(isRollbackItem);
	}

	/**
	 * Validate the closed `GET .../rollback-preview` response shape: `{ plan_id, plan_checksum, checksum,
	 * items, reversible, refused }`. `reversible`/`refused` must partition `items` exactly and each entry's
	 * own `reversible` flag must agree with the bucket it was returned in — any mismatch fails closed.
	 */
	function isRollbackPreviewPayload(value)
	{
		if (!hasExactKeys(value, ROLLBACK_PREVIEW_KEYS)
			|| !isPositiveInt(value.plan_id)
			|| !CHECKSUM_PATTERN.test(value.plan_checksum)
			|| !CHECKSUM_PATTERN.test(value.checksum)
			|| !isRollbackItemsArray(value.items)
			|| !isRollbackItemsArray(value.reversible)
			|| !isRollbackItemsArray(value.refused)
			|| value.items.length !== value.reversible.length + value.refused.length)
		{
			return false;
		}
		return value.reversible.every(function (entry) { return entry.reversible === true; })
			&& value.refused.every(function (entry) { return entry.reversible === false; });
	}

	/**
	 * Validate the closed apply/rollback-execute outcome DTO shared by `POST .../apply` and
	 * `POST .../rollback` (D-13): `{ plan_id, checksum, status, blockers, outcomes, actor }`. `outcomeKeys`
	 * pins the exact per-action outcome vocabulary (`applied`/`conflict`/`skipped` vs.
	 * `rolled_back`/`conflict`/`skipped`) so the two actions can never be confused. A non-empty `blockers`
	 * list (e.g. a 409 `plan_checksum_mismatch`) is itself a valid, closed shape — the caller renders it, it
	 * never treats it as a malformed response.
	 */
	function isMutationResult(value, outcomeKeys)
	{
		return hasExactKeys(value, MUTATION_RESULT_KEYS)
			&& isPositiveInt(value.plan_id)
			&& CHECKSUM_PATTERN.test(value.checksum)
			&& isBoundedString(value.status, 32)
			&& isBlockersList(value.blockers)
			&& hasExactKeys(value.outcomes, outcomeKeys)
			&& outcomeKeys.every(function (key) { return isCount(value.outcomes[key]); })
			&& isBoundedString(value.actor, 255);
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

	function rollbackRow(entry)
	{
		return {
			planItemId: entry.plan_item_id,
			objectType: entry.object_type,
			objectId: entry.object_id,
			before: entry.before_image,
			after: entry.after_image,
			current: entry.current_value,
			inverseOperation: entry.inverse_operation,
			reversible: entry.reversible,
			blocker: entry.blocker
		};
	}

	/**
	 * Turn a raw `GET .../rollback-preview` response into a DOM-free presentation, distinguishing
	 * reversible from refused items. Fails closed to `valid: false` with empty lists on any malformed or
	 * out-of-contract payload, matching `describePlan`/`describeSelectedDiff`. Zero-write: this describes a
	 * read response only and never issues a rollback itself.
	 */
	function describeRollbackPreview(payload)
	{
		if (!isRollbackPreviewPayload(payload))
		{
			return { valid: false, planId: null, planChecksum: '', checksum: '', reversible: [], refused: [] };
		}
		return {
			valid: true,
			planId: payload.plan_id,
			planChecksum: payload.plan_checksum,
			checksum: payload.checksum,
			reversible: payload.reversible.map(rollbackRow),
			refused: payload.refused.map(rollbackRow)
		};
	}

	/**
	 * Turn a raw apply/rollback-execute outcome DTO into a DOM-free presentation. Fails closed the same way
	 * as the other `describe*` helpers. `blockers`/`outcomes` are carried through verbatim (closed-vocabulary
	 * tokens rendered as-is, never remapped to invented copy).
	 */
	function describeMutationResult(payload, outcomeKeys)
	{
		if (!isMutationResult(payload, outcomeKeys))
		{
			return { valid: false, planId: null, checksum: '', status: '', blockers: [], outcomes: [], actor: '' };
		}
		return {
			valid: true,
			planId: payload.plan_id,
			checksum: payload.checksum,
			status: payload.status,
			blockers: payload.blockers.slice(),
			outcomes: outcomeKeys.map(function (key) { return { term: key, value: String(payload.outcomes[key]) }; }),
			actor: payload.actor
		};
	}

	function describeApplyResult(payload)
	{
		return describeMutationResult(payload, APPLY_OUTCOME_KEYS);
	}

	function describeRollbackResult(payload)
	{
		return describeMutationResult(payload, ROLLBACK_OUTCOME_KEYS);
	}

	/**
	 * Build the zero-write `GET .../export` download URL for a plan (D-12/BULK-10). Returns `null` for an
	 * unsupported format so a caller can never construct a request for anything outside the closed
	 * `json`/`csv` vocabulary. This never issues the request itself — it is a pure URL builder consumed by
	 * a plain download link, so clicking it triggers only a browser-native file download, never a write.
	 */
	function exportUrl(plansEndpoint, planId, format)
	{
		if (EXPORT_FORMATS.indexOf(format) === -1)
		{
			return null;
		}
		return plansEndpoint + '/' + encodeURIComponent(String(planId)) + '/export?format=' + format;
	}

	function exportLinks(plansEndpoint, planId)
	{
		return {
			json: exportUrl(plansEndpoint, planId, 'json'),
			csv: exportUrl(plansEndpoint, planId, 'csv')
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
		var renderedRollback = 0;
		var renderedApply = 0;
		var renderedRollbackAction = 0;
		// The checksum the mutation actions bind to is sourced ONLY from the most recently rendered
		// server response (the loaded plan's `checksum`, the loaded rollback preview's `checksum`) — never
		// from a caller- or DOM-supplied value. This is what stops the browser from ever being able to
		// supply an item list/operation/value: apply()/rollback() below accept only a confirm flag.
		var currentPlanChecksum = null;
		var currentRollbackChecksum = null;

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
				currentPlanChecksum = presentation.checksum;
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

		/**
		 * Load the zero-write rollback preview and render it read-only. Never issues a write. Records the
		 * preview's own checksum as the ONLY value `rollback()` below may later send, so an execute can never
		 * fire without the caller having first seen a fresh, server-derived reversible/refused breakdown.
		 */
		function loadRollbackPreview()
		{
			sequence++;
			var owned = sequence;
			options.onBusy(true);

			return safePromise(options.requestRollbackPreview).then(function (payload)
			{
				var presentation = describeRollbackPreview(payload);
				if (!presentation.valid)
				{
					throw new Error('rollback_preview_invalid');
				}
				if (owned > renderedRollback)
				{
					renderedRollback = owned;
					currentRollbackChecksum = presentation.checksum;
					options.renderRollbackPreview(presentation);
				}
				options.onBusy(false);
				return presentation;
			}, function ()
			{
				if (owned > renderedRollback)
				{
					options.onError(COPY.rollbackPreviewError, 'rollback-preview');
				}
				options.onBusy(false);
				return null;
			});
		}

		/**
		 * Apply the currently loaded plan (D-13, durable mutation). Fires ONLY when `confirmed === true`
		 * (the caller must have already shown and resolved an explicit confirmation) AND a plan has been
		 * loaded (so a checksum is bound); a stray call with no confirm, or before any plan is loaded, issues
		 * no request and resolves to `null`. The checksum sent is exactly the currently rendered plan's own
		 * checksum — never a value the caller passes in — so the browser can supply no item list, operation,
		 * or value of its own. On success (no blockers) the plan/summary/diff are re-loaded fresh from the
		 * server rather than fabricated from the mutation DTO, which carries no item-level detail. On a
		 * bounded blocker (e.g. a 409 `plan_checksum_mismatch`) the result is still rendered verbatim; no
		 * state is invented.
		 */
		function apply(confirmed)
		{
			if (confirmed !== true || currentPlanChecksum === null)
			{
				return Promise.resolve(null);
			}
			var checksum = currentPlanChecksum;
			sequence++;
			var owned = sequence;
			options.onBusy(true);

			return safePromise(function () { return options.requestApply(checksum); }).then(function (payload)
			{
				var presentation = describeApplyResult(payload);
				if (!presentation.valid)
				{
					throw new Error('apply_invalid');
				}
				if (owned > renderedApply)
				{
					renderedApply = owned;
					options.renderApplyResult(presentation);
				}
				if (presentation.blockers.length === 0)
				{
					return load().then(function () { return presentation; });
				}
				options.onBusy(false);
				return presentation;
			}, function ()
			{
				if (owned > renderedApply)
				{
					options.onError(COPY.applyError, 'apply');
				}
				options.onBusy(false);
				return null;
			});
		}

		/**
		 * Execute a guarded rollback of the currently previewed plan (D-13, durable mutation). Fires ONLY
		 * when `confirmed === true` AND a rollback preview has been loaded (so a reviewed checksum is bound);
		 * otherwise it issues no request and resolves to `null` — mirroring `apply()`. The checksum sent is
		 * exactly the currently rendered rollback preview's own checksum, binding the executed reversal to the
		 * one the caller reviewed. On success the plan and the rollback preview are both re-loaded fresh from
		 * the server; on a bounded blocker the result is rendered verbatim with no fabricated state.
		 */
		function rollback(confirmed)
		{
			if (confirmed !== true || currentRollbackChecksum === null)
			{
				return Promise.resolve(null);
			}
			var checksum = currentRollbackChecksum;
			sequence++;
			var owned = sequence;
			options.onBusy(true);

			return safePromise(function () { return options.requestRollbackExecute(checksum); }).then(function (payload)
			{
				var presentation = describeRollbackResult(payload);
				if (!presentation.valid)
				{
					throw new Error('rollback_invalid');
				}
				if (owned > renderedRollbackAction)
				{
					renderedRollbackAction = owned;
					options.renderRollbackResult(presentation);
				}
				if (presentation.blockers.length === 0)
				{
					return Promise.all([load(), loadRollbackPreview()]).then(function () { return presentation; });
				}
				options.onBusy(false);
				return presentation;
			}, function ()
			{
				if (owned > renderedRollbackAction)
				{
					options.onError(COPY.rollbackExecuteError, 'rollback');
				}
				options.onBusy(false);
				return null;
			});
		}

		return { load: load, toggle: toggle, loadRollbackPreview: loadRollbackPreview, apply: apply, rollback: rollback };
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

	function rollbackEntryCard(document, tag, row)
	{
		var card = document.createElement('section');
		card.className = 'grocy-ai-bulk-rollback-item';
		card.setAttribute('data-grocy-ai-bulk-rollback-item-id', String(row.planItemId));

		var header = document.createElement('div');
		header.className = 'grocy-ai-field-header';
		header.appendChild(element(document, tag, row.objectType + ' #' + row.objectId));
		var badge = element(document, 'span', row.reversible ? 'reversible' : 'refused');
		badge.className = 'badge grocy-ai-bulk-outcome grocy-ai-bulk-rollback-badge-' + (row.reversible ? 'reversible' : 'refused');
		header.appendChild(badge);
		card.appendChild(header);

		var grid = document.createElement('div');
		grid.className = 'grocy-ai-diff-grid';
		var before = document.createElement('div');
		before.className = 'grocy-ai-value-cell';
		before.appendChild(element(document, 'strong', COPY.beforeLabel));
		before.appendChild(element(document, 'span', valueOrBlank(row.before)));
		var after = document.createElement('div');
		after.className = 'grocy-ai-value-cell';
		after.appendChild(element(document, 'strong', COPY.afterLabel));
		after.appendChild(element(document, 'span', valueOrBlank(row.after)));
		var current = document.createElement('div');
		current.className = 'grocy-ai-value-cell';
		current.appendChild(element(document, 'strong', COPY.currentLabel));
		current.appendChild(element(document, 'span', valueOrBlank(row.current)));
		grid.appendChild(before);
		grid.appendChild(after);
		grid.appendChild(current);
		card.appendChild(grid);

		if (row.reversible)
		{
			card.appendChild(element(document, 'p', COPY.inverseOperationLabel + ': ' + row.inverseOperation));
		}
		else
		{
			var blocker = element(document, 'p', COPY.blockerLabel + ': ' + row.blocker);
			blocker.className = 'grocy-ai-bulk-conflict-note alert alert-warning';
			blocker.setAttribute('role', 'alert');
			card.appendChild(blocker);
		}

		return card;
	}

	/**
	 * Render the zero-write rollback preview, visually and structurally separating reversible items from
	 * refused (`manual_edit_after_apply`) items (BULK-09). Read-only: this function issues no request and
	 * declares no rollback control of its own — the caller wires the rollback-execute button separately, so
	 * a render can never itself trigger a write.
	 */
	function renderRollbackPreview(document, container, presentation)
	{
		container.textContent = '';
		if (!presentation.valid)
		{
			container.appendChild(element(document, 'p', COPY.rollbackPreviewError));
			return;
		}

		var reversibleSection = document.createElement('section');
		reversibleSection.className = 'grocy-ai-bulk-rollback-reversible';
		reversibleSection.appendChild(element(document, 'h4', COPY.reversibleHeading + ' (' + presentation.reversible.length + ')'));
		if (presentation.reversible.length === 0)
		{
			reversibleSection.appendChild(element(document, 'p', COPY.emptyReversible));
		}
		else
		{
			presentation.reversible.forEach(function (row)
			{
				reversibleSection.appendChild(rollbackEntryCard(document, 'h5', row));
			});
		}
		container.appendChild(reversibleSection);

		var refusedSection = document.createElement('section');
		refusedSection.className = 'grocy-ai-bulk-rollback-refused';
		refusedSection.appendChild(element(document, 'h4', COPY.refusedHeading + ' (' + presentation.refused.length + ')'));
		if (presentation.refused.length === 0)
		{
			refusedSection.appendChild(element(document, 'p', COPY.emptyRefused));
		}
		else
		{
			presentation.refused.forEach(function (row)
			{
				refusedSection.appendChild(rollbackEntryCard(document, 'h5', row));
			});
		}
		container.appendChild(refusedSection);
	}

	/**
	 * Render an apply/rollback-execute outcome (D-13) verbatim: status, the closed blocker list, the
	 * per-action outcome counts, and the actor — exactly as returned, with no invented copy and no
	 * fabricated item-level state (the DTO carries none). Used for both the apply result and the rollback
	 * result panels via their own container/heading.
	 */
	function renderMutationResult(document, container, heading, presentation)
	{
		container.textContent = '';
		container.appendChild(element(document, 'h4', heading));
		if (!presentation.valid)
		{
			container.appendChild(element(document, 'p', COPY.loadError));
			return;
		}

		container.appendChild(element(document, 'p', COPY.statusLabel + ': ' + presentation.status));

		var blockersText = presentation.blockers.length === 0 ? COPY.noBlockers : presentation.blockers.join(', ');
		var blockersEl = element(document, 'p', COPY.blockersLabel + ': ' + blockersText);
		if (presentation.blockers.length > 0)
		{
			blockersEl.setAttribute('role', 'alert');
			blockersEl.className = 'alert alert-warning';
		}
		container.appendChild(blockersEl);

		var dl = document.createElement('dl');
		dl.className = 'grocy-ai-bulk-summary-list';
		presentation.outcomes.forEach(function (row)
		{
			dl.appendChild(element(document, 'dt', row.term));
			dl.appendChild(element(document, 'dd', row.value));
		});
		container.appendChild(dl);

		container.appendChild(element(document, 'p', COPY.actorLabel + ': ' + presentation.actor));
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
		var rollbackPreviewEl = document.getElementById('grocy-ai-bulk-rollback-preview');
		var rollbackPreviewButton = document.getElementById('grocy-ai-bulk-rollback-preview-button');
		var applyButton = document.getElementById('grocy-ai-bulk-apply-button');
		var applyResultEl = document.getElementById('grocy-ai-bulk-apply-result');
		var rollbackButton = document.getElementById('grocy-ai-bulk-rollback-button');
		var rollbackResultEl = document.getElementById('grocy-ai-bulk-rollback-result');
		var exportJsonLink = document.getElementById('grocy-ai-bulk-export-json');
		var exportCsvLink = document.getElementById('grocy-ai-bulk-export-csv');

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
			if (rollbackPreviewEl)
			{
				rollbackPreviewEl.textContent = '';
			}
			return null;
		}

		var planUrl = plansEndpoint + '/' + encodeURIComponent(planId);
		var diffUrl = planUrl + '/selected-diff';
		var rollbackPreviewUrl = planUrl + '/rollback-preview';
		var applyUrl = planUrl + '/apply';
		var rollbackExecuteUrl = planUrl + '/rollback';
		var downloadLinks = exportLinks(plansEndpoint, planId);

		// The export links are plain same-origin `<a href>` navigations, wired once from a pure URL builder
		// — never a fetch call, so a click can never mutate plan/selection state and requests exactly the
		// permission-checked, zero-write `GET .../export` read the server serves as a labelled file download.
		if (exportJsonLink && downloadLinks.json)
		{
			exportJsonLink.href = downloadLinks.json;
		}
		if (exportCsvLink && downloadLinks.csv)
		{
			exportCsvLink.href = downloadLinks.csv;
		}

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

		/**
		 * POST an apply/rollback-execute confirmation. Unlike `fetchJson`, a 409 response is NOT treated as a
		 * transport failure: the apply/rollback-execute endpoints return the same closed outcome DTO on a
		 * bounded blocker (e.g. `plan_checksum_mismatch`) with a 409 status, and that body must still reach
		 * `describeApplyResult`/`describeRollbackResult` for rendering — never be discarded as a generic error.
		 */
		function fetchMutation(url, checksum)
		{
			return fetch(url, {
				method: 'POST',
				credentials: 'same-origin',
				cache: 'no-store',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
				body: JSON.stringify({ checksum: checksum })
			}).then(function (response)
			{
				if (!response.ok && response.status !== 409)
				{
					throw new Error('http_status');
				}
				return response.json();
			});
		}

		function confirmAction(message)
		{
			return typeof window !== 'undefined' && typeof window.confirm === 'function' && window.confirm(message) === true;
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
			requestRollbackPreview: function () { return fetchJson(rollbackPreviewUrl, { method: 'GET' }); },
			// The checksum argument here is ALWAYS the controller's own last-rendered plan/preview checksum
			// (see `apply`/`rollback` in `createBulkReviewController`) — this function never receives, and
			// this module never lets the DOM layer supply, any browser-originated checksum, item list,
			// operation, or value.
			requestApply: function (checksum) { return fetchMutation(applyUrl, checksum); },
			requestRollbackExecute: function (checksum) { return fetchMutation(rollbackExecuteUrl, checksum); },
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
			renderRollbackPreview: function (presentation)
			{
				if (rollbackPreviewEl)
				{
					renderRollbackPreview(document, rollbackPreviewEl, presentation);
				}
			},
			renderApplyResult: function (presentation)
			{
				if (applyResultEl)
				{
					renderMutationResult(document, applyResultEl, COPY.applyResultHeading, presentation);
				}
			},
			renderRollbackResult: function (presentation)
			{
				if (rollbackResultEl)
				{
					renderMutationResult(document, rollbackResultEl, COPY.rollbackResultHeading, presentation);
				}
			},
			onBusy: function (busy)
			{
				root.setAttribute('aria-busy', busy ? 'true' : 'false');
			},
			onError: announceError
		});

		// Apply/rollback-execute are durable mutations (D-13): each button fires ONLY after an explicit
		// `window.confirm` the user must accept, and `controller.apply`/`controller.rollback` independently
		// refuse to issue a request unless their own `confirmed === true` gate is satisfied — so neither a
		// stray click nor a click before confirmation can ever reach the network. Rollback additionally
		// requires the read-only preview to have been loaded first, since that is the sole source of the
		// checksum the execute call binds to.
		if (applyButton)
		{
			applyButton.addEventListener('click', function ()
			{
				controller.apply(confirmAction(COPY.applyConfirmPrompt));
			});
		}
		if (rollbackPreviewButton)
		{
			rollbackPreviewButton.addEventListener('click', function ()
			{
				controller.loadRollbackPreview();
			});
		}
		if (rollbackButton)
		{
			rollbackButton.addEventListener('click', function ()
			{
				controller.rollback(confirmAction(COPY.rollbackConfirmPrompt));
			});
		}

		controller.load();
		return controller;
	}

	return {
		COPY: COPY,
		isPlanPayload: isPlanPayload,
		isSelectedDiffPayload: isSelectedDiffPayload,
		isRollbackPreviewPayload: isRollbackPreviewPayload,
		isMutationResult: isMutationResult,
		describePlan: describePlan,
		describeSelectedDiff: describeSelectedDiff,
		describeRollbackPreview: describeRollbackPreview,
		describeApplyResult: describeApplyResult,
		describeRollbackResult: describeRollbackResult,
		exportUrl: exportUrl,
		exportLinks: exportLinks,
		createBulkReviewController: createBulkReviewController,
		renderSummary: renderSummary,
		renderItems: renderItems,
		renderSelectedDiff: renderSelectedDiff,
		renderRollbackPreview: renderRollbackPreview,
		renderMutationResult: renderMutationResult,
		attachBulkReview: attachBulkReview
	};
});
