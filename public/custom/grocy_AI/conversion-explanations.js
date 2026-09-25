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
		root.GrocyAIConversionExplanations = api;
		if (root.document)
		{
			// The product panel is self-contained. The resolved table is attached by its own
			// view script instead, so enrichment always runs after native DataTables initialization.
			api.attachProductStatus(root.document);
		}
	}
})(typeof window !== 'undefined' ? window : null, function ()
{
	'use strict';

	var COPY = {
		heading: 'Reusable conversion status',
		productOverride: 'Product override',
		productHelper: 'This conversion takes precedence over any food-type profile and universal default.',
		inactiveGate: 'Reusable conversion profiles are inactive until both branch checks pass.',
		noProfileHeading: 'No estimate is available for this food type.',
		noProfileBody: 'No approved food-type conversion profile applies. Use a measured product conversion if you need this relationship.',
		noClassification: 'No estimate is available until this product has an explicit food classification.',
		blocked: 'Blocked',
		readFailure: 'The conversion status could not be loaded. Try again. Nothing was changed.',
		detailsLabel: 'Show conversion details',
		exactLabel: 'Exact',
		approximateLabel: 'Approximate',
		unavailableLabel: 'Unavailable',
		blockedLabel: 'Blocked'
	};

	var CONTRACT_KEYS = [
		'status', 'blockers', 'factor', 'dimension', 'approximate', 'winner_source', 'source_name', 'source_version',
		'source_status', 'source_item_id', 'profile_key', 'taxonomy_leaf', 'precedence', 'inactive_revision_id'
	];
	var CONTRACT_STATUSES = ['product_native', 'inactive', 'unavailable', 'blocked'];
	var CONTRACT_WINNERS = ['product_override', 'food_profile', 'universal'];
	var CONTRACT_DIMENSIONS = ['mass', 'volume', 'product_scoped'];
	var CONTRACT_SOURCE_STATUSES = ['native', 'inactive'];
	var TEXT_FIELDS = ['dimension', 'winner_source', 'source_name', 'source_version', 'source_status', 'source_item_id', 'profile_key', 'taxonomy_leaf', 'precedence', 'inactive_revision_id'];
	var MAX_TEXT_LENGTH = 200;
	var MAX_BLOCKERS = 8;
	var SAFE_TEXT = /^[A-Za-z0-9 ._:>()\/+-]{1,200}$/;
	var SAFE_CODE = /^[a-z][a-z0-9_]{0,63}$/;
	var SAFE_FACTOR = /^[0-9]+(\.[0-9]+)?$/;

	// Closed correction categories. A blocker code is never rendered raw.
	var CORRECTION_CATEGORIES = {
		dimension_mismatch: 'Mass and volume cannot resolve to one conversion.',
		mass_volume: 'Mass and volume cannot resolve to one conversion.',
		same_rank_collision: 'More than one conversion competes for this pair.',
		competing_path: 'More than one conversion competes for this pair.',
		competing_paths: 'More than one conversion competes for this pair.',
		cycle_detected: 'The conversion graph contains a cycle.',
		reciprocal_inconsistency: 'The stored factors are not reciprocal within tolerance.',
		reciprocal_mismatch: 'The stored factors are not reciprocal within tolerance.',
		factor_tolerance: 'The stored factors are not reciprocal within tolerance.',
		malformed_factor: 'A source rule has an invalid factor.',
		factor_non_positive: 'A source rule has an invalid factor.',
		factor_not_finite: 'A source rule has an invalid factor.',
		provenance_mismatch: 'A source rule has invalid provenance.',
		profile_invalid: 'A source rule has invalid provenance.',
		catalog_unit_invalid: 'A source rule has invalid provenance.',
		catalog_rule_invalid: 'A source rule has invalid provenance.',
		catalog_source_version_invalid: 'A source rule has invalid provenance.',
		catalog_rule_source_version_invalid: 'A source rule has invalid provenance.',
		revision_source_version_invalid: 'A source rule has invalid provenance.',
		inactive_revision_unavailable: 'A source rule has invalid provenance.',
		reusable_count_scope: 'Package and count conversions stay on the product.'
	};
	var CORRECTION_FALLBACK = 'Review this conversion in the native conversion form.';

	var SOURCE_LABELS = {
		product_override: COPY.productOverride,
		food_profile: 'Approximate profile',
		universal: 'Universal default'
	};

	function isPlainObject(value)
	{
		return value !== null && typeof value === 'object' && !Array.isArray(value);
	}

	function isBoundedText(value)
	{
		return typeof value === 'string' && value.length > 0 && value.length <= MAX_TEXT_LENGTH && SAFE_TEXT.test(value);
	}

	function isClosedContract(payload)
	{
		if (!isPlainObject(payload))
		{
			return false;
		}

		var keys = Object.keys(payload).sort();
		var expected = CONTRACT_KEYS.slice().sort();
		if (keys.length !== expected.length)
		{
			return false;
		}
		for (var index = 0; index < expected.length; index++)
		{
			if (keys[index] !== expected[index])
			{
				return false;
			}
		}

		if (CONTRACT_STATUSES.indexOf(payload.status) < 0)
		{
			return false;
		}
		if (!Array.isArray(payload.blockers) || payload.blockers.length > MAX_BLOCKERS)
		{
			return false;
		}
		for (var blocker = 0; blocker < payload.blockers.length; blocker++)
		{
			if (typeof payload.blockers[blocker] !== 'string' || !SAFE_CODE.test(payload.blockers[blocker]))
			{
				return false;
			}
		}
		if (payload.approximate !== null && typeof payload.approximate !== 'boolean')
		{
			return false;
		}
		if (payload.factor !== null && (typeof payload.factor !== 'string' || !SAFE_FACTOR.test(payload.factor)))
		{
			return false;
		}
		for (var field = 0; field < TEXT_FIELDS.length; field++)
		{
			var value = payload[TEXT_FIELDS[field]];
			if (value !== null && !isBoundedText(value))
			{
				return false;
			}
		}
		if (payload.winner_source !== null && CONTRACT_WINNERS.indexOf(payload.winner_source) < 0)
		{
			return false;
		}
		if (payload.dimension !== null && CONTRACT_DIMENSIONS.indexOf(payload.dimension) < 0)
		{
			return false;
		}
		if (payload.source_status !== null && CONTRACT_SOURCE_STATUSES.indexOf(payload.source_status) < 0)
		{
			return false;
		}

		return true;
	}

	function mappedCorrectionCategory(blockers)
	{
		var code = blockers.length > 0 ? blockers[0] : '';
		return Object.prototype.hasOwnProperty.call(CORRECTION_CATEGORIES, code) ? CORRECTION_CATEGORIES[code] : null;
	}

	function correctionCategory(blockers)
	{
		var code = blockers.length > 0 ? blockers[0] : '';
		return Object.prototype.hasOwnProperty.call(CORRECTION_CATEGORIES, code)
			? CORRECTION_CATEGORIES[code]
			: CORRECTION_FALLBACK;
	}

	function detail(rows, term, value)
	{
		if (typeof value === 'string' && value !== '')
		{
			rows.push({ term: term, value: value });
		}
	}

	function errorPresentation()
	{
		return {
			kind: 'error',
			statusLabel: COPY.unavailableLabel,
			statusVariant: 'warning',
			icon: 'triangle-exclamation',
			role: 'alert',
			headline: COPY.readFailure,
			note: null,
			factor: null,
			details: [],
			detailsLabel: COPY.detailsLabel
		};
	}

	function describeProductConversionStatus(payload)
	{
		if (!isClosedContract(payload))
		{
			return errorPresentation();
		}

		var presentation = {
			kind: 'unavailable',
			statusLabel: COPY.unavailableLabel,
			statusVariant: 'secondary',
			icon: 'circle-info',
			role: 'status',
			headline: COPY.noProfileHeading,
			note: COPY.noProfileBody,
			// D-10: only a native product conversion may ever carry a usable factor.
			factor: payload.status === 'product_native' ? payload.factor : null,
			details: [],
			detailsLabel: COPY.detailsLabel
		};

		if (payload.status === 'product_native')
		{
			presentation.kind = 'product-override';
			presentation.statusLabel = COPY.exactLabel;
			presentation.statusVariant = 'success';
			presentation.icon = 'circle-check';
			presentation.headline = COPY.productOverride;
			presentation.note = COPY.productHelper;
		}
		else if (payload.status === 'inactive' && payload.winner_source === 'food_profile')
		{
			presentation.kind = 'approximate-profile';
			presentation.statusLabel = COPY.approximateLabel;
			presentation.statusVariant = 'warning';
			presentation.icon = 'triangle-exclamation';
			presentation.headline = 'Approximate profile: ' + payload.profile_key + ' · ' + payload.source_name + ' ' + payload.source_version;
			presentation.note = COPY.inactiveGate;
		}
		else if (payload.status === 'inactive')
		{
			presentation.kind = 'inactive-universal';
			presentation.statusVariant = 'warning';
			presentation.icon = 'triangle-exclamation';
			presentation.headline = COPY.inactiveGate;
			presentation.note = payload.source_name === null
				? null
				: SOURCE_LABELS.universal + ' — ' + payload.source_name + ' ' + payload.source_version;
		}
		else if (payload.status === 'blocked')
		{
			presentation.kind = 'blocked';
			presentation.statusLabel = COPY.blockedLabel;
			presentation.statusVariant = 'danger';
			presentation.icon = 'circle-exclamation';
			presentation.role = 'alert';
			presentation.headline = COPY.blocked;
			presentation.note = null;
		}
		else if (payload.blockers.indexOf('explicit_taxonomy_required') >= 0)
		{
			presentation.headline = COPY.noClassification;
			presentation.note = null;
		}

		var rows = [];
		detail(rows, 'Status', presentation.statusLabel);
		if (payload.winner_source !== null)
		{
			detail(rows, 'Source', SOURCE_LABELS[payload.winner_source]);
		}
		detail(rows, 'Source name', payload.source_name);
		detail(rows, 'Source version', payload.source_version);
		detail(rows, 'Full precision factor', presentation.factor);
		detail(rows, 'Dimension', payload.dimension);
		detail(rows, 'Food type', payload.taxonomy_leaf);
		detail(rows, 'Precedence', payload.precedence);
		detail(rows, 'Rule revision', payload.inactive_revision_id);
		if (presentation.kind === 'blocked')
		{
			// A blocker always states a correction state, even for an unmapped future code.
			detail(rows, 'Correction needed', correctionCategory(payload.blockers));
		}
		else if (presentation.kind === 'unavailable')
		{
			// An unavailable result only names a correction category when its reason maps to a
			// closed one; the generic fallback would misdescribe the empty-estimate cases.
			var mapped = mappedCorrectionCategory(payload.blockers);
			if (mapped !== null)
			{
				detail(rows, 'Correction needed', mapped);
			}
		}
		presentation.details = rows;

		return presentation;
	}

	function createProductStatusController(options)
	{
		var sequence = 0;
		var rendered = 0;

		function load()
		{
			sequence++;
			var owned = sequence;
			var revision = options.getRevision();
			var request;
			try
			{
				request = Promise.resolve(options.requestStatus());
			}
			catch (error)
			{
				request = Promise.reject(error);
			}

			return request.then(function (payload)
			{
				return describeProductConversionStatus(payload);
			}, function ()
			{
				return errorPresentation();
			}).then(function (presentation)
			{
				// A late callback may only paint when it still owns both the form revision and the newest request.
				if (options.getRevision() !== revision || owned <= rendered)
				{
					return null;
				}
				rendered = owned;
				options.render(presentation);
				return presentation;
			});
		}

		return { load: load };
	}

	function createResolvedProvenanceController(options)
	{
		// One sequence per resolved row: a late or superseded answer may never paint a row,
		// and an answer can only ever reach the row that asked for it.
		var sequences = {};

		function loadRow(identity)
		{
			var rowKey = identity.rowKey;
			sequences[rowKey] = (sequences[rowKey] || 0) + 1;
			var owned = sequences[rowKey];
			var request;
			try
			{
				request = Promise.resolve(options.requestProvenance(identity));
			}
			catch (error)
			{
				request = Promise.reject(error);
			}

			return request.then(function (payload)
			{
				return describeProductConversionStatus(payload);
			}, function ()
			{
				return errorPresentation();
			}).then(function (presentation)
			{
				if (sequences[rowKey] !== owned)
				{
					return null;
				}
				options.renderRow(rowKey, presentation);
				return presentation;
			});
		}

		function loadRows(identities)
		{
			var seen = {};
			var pending = [];
			for (var index = 0; index < identities.length; index++)
			{
				var identity = identities[index];
				var key = identity.rowKey + ':' + identity.productId + ':' + identity.fromQuId + '>' + identity.toQuId;
				if (seen[key])
				{
					continue;
				}
				seen[key] = true;
				pending.push(loadRow(identity));
			}
			return Promise.all(pending);
		}

		return { loadRow: loadRow, loadRows: loadRows };
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

	function renderProductStatus(document, root, presentation)
	{
		var summary = root.querySelector('[data-grocy-ai-conversion-summary]');
		var badge = root.querySelector('[data-grocy-ai-conversion-badge]');
		var details = root.querySelector('[data-grocy-ai-conversion-details]');
		var disclosure = root.querySelector('[data-grocy-ai-conversion-disclosure]');

		summary.textContent = '';
		summary.setAttribute('role', presentation.role);
		summary.className = 'grocy-ai-conversion-summary alert alert-' + presentation.statusVariant;
		var icon = element(document, 'i');
		icon.className = 'fa-solid fa-' + presentation.icon;
		icon.setAttribute('aria-hidden', 'true');
		summary.appendChild(icon);
		summary.appendChild(element(document, 'span', ' ' + presentation.headline));
		if (presentation.note !== null)
		{
			summary.appendChild(element(document, 'p', presentation.note));
		}

		// Status is text, never colour alone.
		badge.textContent = presentation.statusLabel;
		badge.className = 'grocy-ai-conversion-badge badge badge-' + presentation.statusVariant;

		details.textContent = '';
		presentation.details.forEach(function (row)
		{
			details.appendChild(element(document, 'dt', row.term));
			details.appendChild(element(document, 'dd', row.value));
		});
		disclosure.hidden = presentation.details.length === 0;
	}

	function attachProductStatus(document)
	{
		var root = document.getElementById('grocy-ai-product-conversion-status');
		if (!root)
		{
			return null;
		}

		var productId = root.getAttribute('data-product-id');
		var fromUnitKey = root.getAttribute('data-from-unit-key');
		var toUnitKey = root.getAttribute('data-to-unit-key');
		if (!/^[1-9][0-9]{0,9}$/.test(productId || '') || !/^[a-z][a-z0-9_]{0,31}$/.test(fromUnitKey || '')
			|| !/^[a-z][a-z0-9_]{0,31}$/.test(toUnitKey || ''))
		{
			return null;
		}

		var controller = createProductStatusController({
			getRevision: function ()
			{
				return root.getAttribute('data-form-revision') || '';
			},
			requestStatus: function ()
			{
				return new Promise(function (resolve, reject)
				{
					var xhr = new XMLHttpRequest();
					xhr.open('GET', '/api/grocy-ai/products/' + productId + '/conversion-status?from_unit_key='
						+ encodeURIComponent(fromUnitKey) + '&to_unit_key=' + encodeURIComponent(toUnitKey));
					xhr.setRequestHeader('Accept', 'application/json');
					xhr.onload = function ()
					{
						if (xhr.status < 200 || xhr.status >= 300)
						{
							reject(new Error('unavailable'));
							return;
						}
						try
						{
							resolve(JSON.parse(xhr.responseText));
						}
						catch (error)
						{
							reject(new Error('unavailable'));
						}
					};
					xhr.onerror = function () { reject(new Error('unavailable')); };
					xhr.send(null);
				});
			},
			render: function (presentation)
			{
				renderProductStatus(document, root, presentation);
			}
		});

		controller.load();
		return controller;
	}

	function renderResolvedRow(document, row, presentation)
	{
		var source = row.querySelector('[data-grocy-ai-resolved-source]');
		var status = row.querySelector('[data-grocy-ai-resolved-status]');
		var details = row.querySelector('[data-grocy-ai-resolved-details]');
		var disclosure = row.querySelector('[data-grocy-ai-resolved-disclosure]');

		source.textContent = presentation.headline;
		status.textContent = '';
		var icon = element(document, 'i');
		icon.className = 'fa-solid fa-' + presentation.icon;
		icon.setAttribute('aria-hidden', 'true');
		status.appendChild(icon);
		// The outcome is always a visible word, never colour or an icon alone.
		status.appendChild(element(document, 'span', ' ' + presentation.statusLabel));
		status.className = 'grocy-ai-resolved-status text-' + presentation.statusVariant;

		details.textContent = '';
		presentation.details.forEach(function (detailRow)
		{
			details.appendChild(element(document, 'dt', detailRow.term));
			details.appendChild(element(document, 'dd', detailRow.value));
		});

		// The rounded factor and its native explanation move here rather than competing for
		// narrow table width. Both texts come from the row Grocy already rendered.
		[['Rounded factor', '[data-grocy-ai-resolved-factor]'], ['Conversion', '[data-grocy-ai-resolved-prose]']].forEach(function (native)
		{
			var cell = row.querySelector(native[1]);
			if (cell !== null && cell.textContent.trim() !== '')
			{
				details.appendChild(element(document, 'dt', native[0]));
				details.appendChild(element(document, 'dd', cell.textContent.trim()));
			}
		});
		disclosure.hidden = details.childElementCount === 0;
	}

	function attachResolvedProvenance(document, options)
	{
		var hooks = options || {};
		var table = document.getElementById('qu-conversions-resolved-table');
		if (!table)
		{
			return null;
		}
		var rows = Array.prototype.filter.call(
			table.querySelectorAll('tr[data-grocy-ai-resolved-row]'),
			function (row)
			{
				return /^[1-9][0-9]{0,9}$/.test(row.getAttribute('data-product-id') || '')
					&& /^[1-9][0-9]{0,9}$/.test(row.getAttribute('data-from-qu-id') || '')
					&& /^[1-9][0-9]{0,9}$/.test(row.getAttribute('data-to-qu-id') || '');
			}
		);
		if (rows.length === 0)
		{
			return null;
		}

		var byKey = {};
		var identities = rows.map(function (row, index)
		{
			var rowKey = row.getAttribute('data-grocy-ai-resolved-row') || String(index);
			byKey[rowKey] = row;
			return {
				rowKey: rowKey,
				productId: row.getAttribute('data-product-id'),
				fromQuId: row.getAttribute('data-from-qu-id'),
				toQuId: row.getAttribute('data-to-qu-id')
			};
		});

		var controller = createResolvedProvenanceController({
			requestProvenance: function (identity)
			{
				return new Promise(function (resolve, reject)
				{
					var xhr = new XMLHttpRequest();
					xhr.open('GET', '/api/grocy-ai/conversions/resolved-provenance?product_id='
						+ encodeURIComponent(identity.productId) + '&from_qu_id=' + encodeURIComponent(identity.fromQuId)
						+ '&to_qu_id=' + encodeURIComponent(identity.toQuId));
					xhr.setRequestHeader('Accept', 'application/json');
					xhr.onload = function ()
					{
						if (xhr.status < 200 || xhr.status >= 300)
						{
							reject(new Error('unavailable'));
							return;
						}
						try
						{
							resolve(JSON.parse(xhr.responseText));
						}
						catch (error)
						{
							reject(new Error('unavailable'));
						}
					};
					xhr.onerror = function () { reject(new Error('unavailable')); };
					xhr.send(null);
				});
			},
			renderRow: function (rowKey, presentation)
			{
				renderResolvedRow(document, byKey[rowKey], presentation);
				if (typeof hooks.onRowPainted === 'function')
				{
					hooks.onRowPainted(byKey[rowKey]);
				}
			}
		});

		controller.loadRows(identities).then(function ()
		{
			if (typeof hooks.onComplete === 'function')
			{
				hooks.onComplete();
			}
		});
		return controller;
	}

	return {
		COPY: COPY,
		describeProductConversionStatus: describeProductConversionStatus,
		createProductStatusController: createProductStatusController,
		createResolvedProvenanceController: createResolvedProvenanceController,
		renderProductStatus: renderProductStatus,
		renderResolvedRow: renderResolvedRow,
		attachProductStatus: attachProductStatus,
		attachResolvedProvenance: attachResolvedProvenance
	};
});
