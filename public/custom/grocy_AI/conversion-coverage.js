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
		root.GrocyAIConversionCoverage = api;
		if (root.document)
		{
			api.attachCoverageReport(root.document);
		}
	}
})(typeof window !== 'undefined' ? window : null, function ()
{
	'use strict';

	var COPY = {
		title: 'Conversion coverage',
		ready: 'Ruleset ready',
		blockedPrefix: 'Ruleset has ',
		blockedSuffix: ' blockers',
		inactive: 'Reusable rules are inactive until characterization passed on both branches.',
		refresh: 'Refresh validation report',
		refreshBusy: 'Refreshing conversion validation report…',
		refreshError: 'The validation report could not be refreshed. Try again.',
		emptyBlockers: 'No blocking conversion issues were found.',
		unavailable: 'The validation report could not be refreshed. Try again.'
	};

	var REPORT_KEYS = [
		'ruleset_version', 'source_version', 'profile_source_version', 'gate', 'counts', 'blockers',
		'effective_sources', 'protected_behavior'
	];
	var GATE_KEYS = ['state', 'main_branch_evidence', 'stable_branch_evidence', 'selected_projection'];
	var COUNT_KEYS = [
		'catalog_units', 'universal_rules', 'profiles', 'covered_pairs', 'missing_paths',
		'unavailable_profiles', 'redundant_product_overrides', 'blockers'
	];
	var GATE_STATES = ['inactive', 'blocked', 'ready'];
	var EVIDENCE_STATES = ['absent', 'present'];
	var BLOCKER_CATEGORIES = [
		'malformed_factor', 'provenance_mismatch', 'dimension_mismatch', 'competing_path',
		'cycle_detected', 'reciprocal_inconsistency'
	];
	var SOURCE_KEYS = ['product_override', 'food_profile', 'universal'];
	var PROTECTED_CATEGORIES = [
		'stock', 'recipe', 'purchase', 'consumption', 'price', 'transfer', 'meal_plan', 'quantity_display'
	];
	var PROTECTED_STATES = ['unverified', 'passed', 'failed'];
	var SAFE_VERSION = /^[A-Za-z0-9 ._;:()-]{1,120}$/;
	var MAX_COUNT = 1000000000;

	var LABELS = {
		catalog_units: 'Catalog units',
		universal_rules: 'Universal rules',
		profiles: 'Approximate profiles',
		covered_pairs: 'Covered pairs',
		missing_paths: 'Missing paths',
		unavailable_profiles: 'Unavailable profiles',
		redundant_product_overrides: 'Redundant product overrides',
		blockers: 'Blockers',
		product_override: 'Product override',
		food_profile: 'Approximate profile',
		universal: 'Universal default',
		stock: 'Stock',
		recipe: 'Recipes',
		purchase: 'Purchase',
		consumption: 'Consumption',
		price: 'Price',
		transfer: 'Transfer',
		meal_plan: 'Meal plans',
		quantity_display: 'Quantity display',
		malformed_factor: 'Invalid factor',
		provenance_mismatch: 'Invalid provenance',
		dimension_mismatch: 'Mass and volume mixed',
		competing_path: 'Competing paths',
		cycle_detected: 'Cycle detected',
		reciprocal_inconsistency: 'Reciprocal inconsistency',
		unverified: 'Not verified',
		passed: 'Verified equal',
		failed: 'Not equal'
	};

	function isCount(value)
	{
		return typeof value === 'number' && isFinite(value) && Math.floor(value) === value && value >= 0 && value <= MAX_COUNT;
	}

	function isVersion(value)
	{
		return typeof value === 'string' && SAFE_VERSION.test(value);
	}

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

	function isClosedReport(report)
	{
		if (!hasExactKeys(report, REPORT_KEYS) || !hasExactKeys(report.gate, GATE_KEYS) || !hasExactKeys(report.counts, COUNT_KEYS))
		{
			return false;
		}
		if (!isVersion(report.ruleset_version) || !isVersion(report.source_version) || !isVersion(report.profile_source_version))
		{
			return false;
		}
		if (GATE_STATES.indexOf(report.gate.state) < 0
			|| EVIDENCE_STATES.indexOf(report.gate.main_branch_evidence) < 0
			|| EVIDENCE_STATES.indexOf(report.gate.stable_branch_evidence) < 0
			|| !isVersion(report.gate.selected_projection))
		{
			return false;
		}
		if (!COUNT_KEYS.every(function (key) { return isCount(report.counts[key]); }))
		{
			return false;
		}
		if (!Array.isArray(report.blockers) || report.blockers.length > BLOCKER_CATEGORIES.length)
		{
			return false;
		}
		if (!report.blockers.every(function (entry)
		{
			return hasExactKeys(entry, ['category', 'count']) && BLOCKER_CATEGORIES.indexOf(entry.category) >= 0 && isCount(entry.count);
		}))
		{
			return false;
		}
		if (!Array.isArray(report.effective_sources) || report.effective_sources.length !== SOURCE_KEYS.length)
		{
			return false;
		}
		if (!report.effective_sources.every(function (entry, index)
		{
			return hasExactKeys(entry, ['source', 'count']) && entry.source === SOURCE_KEYS[index] && isCount(entry.count);
		}))
		{
			return false;
		}
		if (!Array.isArray(report.protected_behavior) || report.protected_behavior.length !== PROTECTED_CATEGORIES.length)
		{
			return false;
		}
		return report.protected_behavior.every(function (entry, index)
		{
			return hasExactKeys(entry, ['category', 'state'])
				&& entry.category === PROTECTED_CATEGORIES[index]
				&& PROTECTED_STATES.indexOf(entry.state) >= 0;
		});
	}

	function label(key)
	{
		return Object.prototype.hasOwnProperty.call(LABELS, key) ? LABELS[key] : key;
	}

	function describeCoverageReport(report)
	{
		if (!isClosedReport(report))
		{
			return {
				kind: 'error',
				variant: 'warning',
				icon: 'triangle-exclamation',
				role: 'alert',
				headline: COPY.unavailable,
				lines: [],
				blockers: [{ term: '', value: COPY.emptyBlockers }],
				counts: [],
				sources: [],
				redundant: [],
				protectedBehavior: []
			};
		}

		var presentation = {
			kind: report.gate.state,
			variant: 'warning',
			icon: 'triangle-exclamation',
			role: 'status',
			headline: COPY.inactive,
			lines: [],
			blockers: [],
			counts: [],
			sources: [],
			redundant: [],
			protectedBehavior: []
		};

		if (report.gate.state === 'blocked')
		{
			presentation.variant = 'danger';
			presentation.icon = 'circle-exclamation';
			presentation.role = 'alert';
			// The exact count is always in the heading text, never only in the colour.
			presentation.headline = COPY.blockedPrefix + String(report.counts.blockers) + COPY.blockedSuffix;
		}
		else if (report.gate.state === 'ready')
		{
			presentation.variant = 'success';
			presentation.icon = 'circle-check';
			presentation.headline = COPY.ready;
		}

		presentation.lines = [
			{ term: 'Ruleset version', value: report.ruleset_version },
			{ term: 'Universal source', value: report.source_version },
			{ term: 'Profile source', value: report.profile_source_version },
			{ term: 'Main branch characterization', value: report.gate.main_branch_evidence },
			{ term: 'Stable branch characterization', value: report.gate.stable_branch_evidence },
			{ term: 'Selected projection', value: report.gate.selected_projection }
		];

		presentation.blockers = report.blockers.length === 0
			? [{ term: '', value: COPY.emptyBlockers }]
			: report.blockers.map(function (entry)
			{
				return { term: label(entry.category), value: String(entry.count) };
			});

		presentation.counts = ['catalog_units', 'universal_rules', 'profiles', 'covered_pairs', 'missing_paths', 'unavailable_profiles'].map(function (key)
		{
			return { term: label(key), value: String(report.counts[key]) };
		});

		presentation.sources = report.effective_sources.map(function (entry)
		{
			return { term: label(entry.source), value: String(entry.count) };
		});

		presentation.redundant = [{
			term: label('redundant_product_overrides'),
			value: String(report.counts.redundant_product_overrides)
		}];

		presentation.protectedBehavior = report.protected_behavior.map(function (entry)
		{
			return { term: label(entry.category), value: label(entry.state) };
		});

		return presentation;
	}

	function createCoverageController(options)
	{
		var sequence = 0;
		var rendered = 0;

		function refresh()
		{
			sequence++;
			var owned = sequence;
			options.onBusy(true);
			var request;
			try
			{
				request = Promise.resolve(options.requestReport());
			}
			catch (error)
			{
				request = Promise.reject(error);
			}

			return request.then(function (report)
			{
				if (!isClosedReport(report))
				{
					throw new Error('unavailable');
				}
				return describeCoverageReport(report);
			}).then(function (presentation)
			{
				// Last response wins: an older refresh may never replace a newer report.
				if (owned > rendered)
				{
					rendered = owned;
					options.render(presentation);
				}
				options.onBusy(false);
				return presentation;
			}, function ()
			{
				if (owned > rendered)
				{
					// A failed refresh keeps the last complete report and only announces recovery.
					options.onError(COPY.refreshError);
				}
				options.onBusy(false);
				return null;
			});
		}

		return { refresh: refresh };
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

	function renderList(document, list, rows)
	{
		list.textContent = '';
		rows.forEach(function (row)
		{
			list.appendChild(element(document, 'dt', row.term));
			list.appendChild(element(document, 'dd', row.value));
		});
	}

	function renderCoverageReport(document, root, presentation)
	{
		var summary = root.querySelector('[data-grocy-ai-coverage-summary]');
		summary.textContent = '';
		summary.setAttribute('role', presentation.role);
		summary.className = 'grocy-ai-coverage-summary alert alert-' + presentation.variant;
		var icon = element(document, 'i');
		icon.className = 'fa-solid fa-' + presentation.icon;
		icon.setAttribute('aria-hidden', 'true');
		summary.appendChild(icon);
		summary.appendChild(element(document, 'strong', ' ' + presentation.headline));
		var lines = element(document, 'dl');
		lines.className = 'grocy-ai-coverage-list';
		renderList(document, lines, presentation.lines);
		summary.appendChild(lines);

		renderList(document, root.querySelector('[data-grocy-ai-coverage-blockers]'), presentation.blockers);
		renderList(document, root.querySelector('[data-grocy-ai-coverage-counts]'), presentation.counts);
		renderList(document, root.querySelector('[data-grocy-ai-coverage-sources]'), presentation.sources);
		renderList(document, root.querySelector('[data-grocy-ai-coverage-redundant]'), presentation.redundant);
		renderList(document, root.querySelector('[data-grocy-ai-coverage-protected]'), presentation.protectedBehavior);
	}

	function attachCoverageReport(document)
	{
		var root = document.getElementById('grocy-ai-conversion-coverage');
		if (!root)
		{
			return null;
		}
		var refreshButton = document.getElementById('grocy-ai-coverage-refresh');

		function paint(presentation)
		{
			renderCoverageReport(document, root, presentation);
		}

		var initial = root.getAttribute('data-report');
		if (typeof initial === 'string' && initial !== '')
		{
			try
			{
				paint(describeCoverageReport(JSON.parse(initial)));
			}
			catch (error)
			{
				paint(describeCoverageReport(null));
			}
		}
		else
		{
			paint(describeCoverageReport(null));
		}

		var controller = createCoverageController({
			requestReport: function ()
			{
				return new Promise(function (resolve, reject)
				{
					var xhr = new XMLHttpRequest();
					xhr.open('GET', '/api/grocy-ai/conversions/coverage');
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
			render: paint,
			onBusy: function (busy)
			{
				if (!refreshButton)
				{
					return;
				}
				// Only the refresh control is disabled; the rest of the page stays usable.
				refreshButton.disabled = busy;
				refreshButton.setAttribute('aria-busy', busy ? 'true' : 'false');
			},
			onError: function (message)
			{
				var summary = root.querySelector('[data-grocy-ai-coverage-summary]');
				var recovery = summary.querySelector('[data-grocy-ai-coverage-recovery]');
				if (!recovery)
				{
					recovery = element(document, 'p');
					recovery.setAttribute('data-grocy-ai-coverage-recovery', '');
					recovery.setAttribute('role', 'alert');
					summary.appendChild(recovery);
				}
				recovery.textContent = message;
			}
		});

		if (refreshButton)
		{
			refreshButton.addEventListener('click', function () { controller.refresh(); });
		}
		return controller;
	}

	return {
		COPY: COPY,
		describeCoverageReport: describeCoverageReport,
		createCoverageController: createCoverageController,
		renderCoverageReport: renderCoverageReport,
		attachCoverageReport: attachCoverageReport
	};
});
