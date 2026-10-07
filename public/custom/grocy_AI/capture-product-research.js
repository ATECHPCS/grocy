(function ()
{
	'use strict';

	var sources = { 'bb-federation': 'Barcode Lookup Federation', openfoodfacts: 'Open Food Facts', receipt_ocr: 'Receipt OCR', 'openai-classification': 'OpenAI classification', local_identity: 'Local product identity', local_package: 'Package evidence' };
	function node(tag, className, value)
	{
		var result = document.createElement(tag);
		if (className) result.className = className;
		if (value !== undefined) result.textContent = String(value);
		return result;
	}
	function field(host, label, value)
	{
		var wrap = node('label', 'grocy-ai-product-research-field', label);
		var input = node('input', 'form-control');
		input.value = value == null ? '' : value;
		input.setAttribute('aria-label', label);
		wrap.appendChild(input);
		host.appendChild(wrap);
		return input;
	}
	function select(host, label, options, selected)
	{
		var wrap = node('label', 'grocy-ai-product-research-field', label);
		var control = node('select', 'form-control');
		control.setAttribute('aria-label', label);
		options.forEach(function (option) { var item = node('option', null, option.label); item.value = option.value; control.appendChild(item); });
		control.value = selected == null ? '' : String(selected);
		wrap.appendChild(control);
		host.appendChild(wrap);
		return control;
	}
	function button(host, label, action, restricted)
	{
		var control = node('button', 'btn btn-outline-primary' + (restricted ? ' permission-MASTER_DATA_EDIT' : ''), label);
		control.type = 'button';
		control.addEventListener('click', action);
		host.appendChild(control);
		return control;
	}
	function request(url, method, body)
	{
		return fetch(url, { method: method, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) }).then(function (response)
		{
			if (!response.ok) throw new Error(response.status === 403 ? 'You need product edit permission.' : response.status === 409 ? 'This draft changed. Reload and review it again.' : method === 'GET' ? 'Could not load product research. Reload and try again.' : 'Could not save product review.');
			return response.json();
		});
	}
	function GrocyAIProductResearch(host, options)
	{
		var base = '/api/grocy-ai/capture/trips/' + encodeURIComponent(String(options.tripId));
		var active = true;
		var productList = null;
		var productListPromise = null;
		var referenceRequests = {};
		var pendingEdits = options.editState || {};
		var loadGeneration = 0;
		var catalogPromise = request('/api/grocy-ai/capture/research/options', 'GET');
		var canEditProducts = !window.Grocy || !Array.isArray(window.Grocy.UserPermissions) || window.Grocy.UserPermissions.some(function (permission) { return permission.permission_name === 'MASTER_DATA_EDIT' && Number(permission.has_permission) === 1; });
		function load()
		{
			var generation = ++loadGeneration;
			return Promise.all([request(base + '/research', 'GET'), catalogPromise]).then(function (results)
			{
				var payload = results[0], catalog = results[1];
				if (!active || generation !== loadGeneration) return;
				if (!payload || payload.contract_version !== 1 || Number(payload.trip_id) !== Number(options.tripId) || !Array.isArray(payload.drafts)) throw new Error('Research review is unavailable.');
				if (!catalog || catalog.contract_version !== 1 || !Array.isArray(catalog.product_groups) || !Array.isArray(catalog.taxonomy_leaves) || !Array.isArray(catalog.generic_parents)) throw new Error('Product choices are unavailable. Reload and try again.');
				Array.prototype.forEach.call(host.querySelectorAll('.grocy-ai-product-research'), function (card) { card.remove(); });
				if (options.errorHost) options.errorHost.textContent = '';
				payload.drafts.forEach(function (draft)
				{
					var line = options.lines.find(function (candidate) { return candidate.id === draft.line_id && (candidate.status === 'unknown' || candidate.status === 'known' && draft.line_status === 'known' && Number(draft.resolved_product_id) > 0 && Number(candidate.resolved_product_id) === Number(draft.resolved_product_id)); });
					if (line && draft.outcome !== 'approved' && draft.outcome !== 'linked') render(draft, line, catalog);
				});
			}).catch(function (error) { if (active && generation === loadGeneration && options.errorHost) options.errorHost.textContent = error.message === 'You need product edit permission.' ? 'You need purchase permission to review product research.' : error.message; });
		}
		function render(draft, line, catalog)
		{
			var card = node('section', 'grocy-ai-product-research');
			card.setAttribute('data-line-seq', String(line.seq));
			card.appendChild(node('h4', null, 'Scan #' + line.seq + ' · Product research · ' + draft.scanned_barcode));
			var status = draft.job_state === 'ready' ? 'Ready to review' : draft.job_state === 'queued' || draft.job_state === 'leased' ? 'Researching' : draft.job_state === 'retryable_failure' ? 'Provider unavailable' : 'Needs details';
			card.appendChild(node('p', 'grocy-ai-product-research-status', status));
			card.appendChild(node('p', 'text-muted', line.status === 'known' ? 'The scan now resolves to a product. This research draft still needs an explicit link.' : 'Provisional research only. No product or stock has been saved.'));
			if (draft.safe_error_code) card.appendChild(node('p', 'text-muted', 'Research service could not finish. You can retry or enter details.'));
			var path = base + '/lines/' + encodeURIComponent(String(line.seq));
			var message = node('p', 'grocy-ai-product-research-message');
			message.setAttribute('role', 'status');
			function mutate(url, method, body, reloadTrip, onFailure, onSuccess)
			{
				message.textContent = 'Saving…';
				return request(url, method, body).then(function ()
				{
					// A successful write still settles its matching draft fields after disposal.
					if (onSuccess) onSuccess();
					if (reloadTrip) delete pendingEdits[line.id];
					if (options.onEdit) options.onEdit();
					if (!active) return;
					message.textContent = 'Saved.';
					return reloadTrip ? options.reload() : load();
				}).catch(function (error)
				{
					if (!active) return;
					if (onFailure) onFailure();
					message.textContent = error.message;
				});
			}
			function finishCard()
			{
				card.appendChild(message);
				if (Number(line.selected) === 0)
				{
					card.appendChild(node('p', 'text-muted', 'Include this scan in the purchase before approving or linking a product. Research draft edits can still be saved.'));
					Array.prototype.forEach.call(card.querySelectorAll('.permission-MASTER_DATA_EDIT'), function (control) { control.disabled = true; });
				}
				if (!canEditProducts)
				{
					card.appendChild(node('p', 'text-muted', 'Product edit permission is required to approve or link.'));
					Array.prototype.forEach.call(card.querySelectorAll('.permission-MASTER_DATA_EDIT'), function (control) { control.disabled = true; });
				}
				if (options.readOnly) Array.prototype.forEach.call(card.querySelectorAll('input, select, button'), function (control) { control.disabled = true; });
				var row = host.querySelector('.grocy-ai-capture-review-line[data-line-seq="' + String(line.seq) + '"]');
				if (row) row.appendChild(card);
			}
			if (line.status === 'known')
			{
				card.appendChild(node('p', null, 'Current product: ' + (draft.resolved_product_name || 'Product') + ' (#' + draft.resolved_product_id + ')'));
				var knownControls = node('div', 'grocy-ai-product-research-actions');
				card.appendChild(knownControls);
				button(knownControls, 'Link to current product', function ()
				{
					var summary = (draft.resolved_product_name || 'Product') + ' (#' + draft.resolved_product_id + ') · barcode ' + draft.scanned_barcode;
					if (!window.confirm('Review link: ' + summary + '. Continue?')) return;
					if (!window.confirm('Final confirmation: ' + summary + '. This links the research draft to the current product. Confirm?')) return;
					mutate(path + '/research/link', 'POST', { revision: draft.revision, product_id: Number(draft.resolved_product_id) }, true);
				}, true);
				finishCard();
				return;
			}
			if (draft.name_alternatives.length)
			{
				var names = node('div', 'grocy-ai-product-research-names');
				names.appendChild(node('strong', null, 'Suggested names'));
				draft.name_alternatives.forEach(function (candidate)
				{
					var label = node('p', null, candidate.value + ' — ' + (candidate.sources.length ? candidate.sources.map(function (source) { return sources[source] || source; }).join(', ') : 'Source unknown'));
					names.appendChild(label);
					if (candidate.sources.indexOf('openai-web') !== -1 && candidate.web_evidence)
					{
						var web = node('div', 'grocy-ai-product-research-web');
						web.appendChild(node('strong', null, 'OpenAI web suggestion — verify UPC'));
						web.appendChild(node('p', null, 'Verify against package'));
						web.appendChild(node('p', null, candidate.web_evidence.exact_gtin_claim ? 'Source claims this exact GTIN; verify against package.' : 'Exact GTIN association is unconfirmed.'));
						(candidate.web_evidence.citations || []).forEach(function (citation)
						{
							try
							{
								var url = new URL(citation.url);
								if (typeof citation.url !== 'string' || citation.url.length > 2048 || /[\s\\\x00-\x1f\x7f]|%(?![0-9a-f]{2})|%(?:0[0-9a-f]|1[0-9a-f]|7f)/i.test(citation.url) || url.protocol !== 'https:' || url.username || url.password || url.port && url.port !== '443' || url.hostname !== citation.domain || !/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/.test(url.hostname) || /\.(local|localhost|internal|lan|test|invalid|example|arpa|onion|alt|home|corp|mail)$/.test(url.hostname) || typeof citation.title !== 'string' || !citation.title || citation.title.length > 400 || /[\x00-\x1f\x7f]/.test(citation.title)) return;
								var anchor = node('a', null, citation.title + ' — ' + citation.domain);
								anchor.href = citation.url;
								anchor.target = '_blank';
								anchor.rel = 'noopener noreferrer';
								web.appendChild(anchor);
							}
							catch (error) { /* Invalid citations remain noninteractive. */ }
						});
						names.appendChild(web);
					}
				});
				card.appendChild(names);
			}
			if (draft.receipt_evidence) card.appendChild(node('p', null, 'Receipt evidence: ' + draft.receipt_evidence.description + ' (Receipt OCR; supporting evidence)'));
			if (draft.suggested && draft.suggested.categories && draft.suggested.categories.length) card.appendChild(node('p', 'text-muted', 'Open Food Facts categories: ' + draft.suggested.categories.join(', ')));
			if (draft.classification) card.appendChild(node('p', 'grocy-ai-product-research-classification', draft.classification.state === 'suggested' ? 'Classification suggestions ready' : ['pending', 'queued'].indexOf(draft.classification.state) !== -1 ? 'Classification queued; manual choices are available.' : draft.classification.state === 'leased' ? 'Classification in progress; manual choices are available.' : draft.classification.state === 'not_needed' ? 'Open Food Facts classification suggestions ready' : 'Classification unavailable; choose manually.'));
			var name = field(card, 'Proposed name', draft.selected.name);
			card.appendChild(node('p', 'grocy-ai-product-research-notes text-muted', 'Brand and package are research notes only; they are not saved to the Grocy product by this approval. Save research draft stores your corrections for review.'));
			if (draft.suggested && draft.suggested.brand) card.appendChild(node('p', 'grocy-ai-product-research-brand-source', draft.suggested.brand + ' — Open Food Facts'));
			if (draft.suggested && draft.suggested.package) card.appendChild(node('p', 'grocy-ai-product-research-package-source', draft.suggested.package + ' — Open Food Facts'));
			var brand = field(card, 'Brand research note', draft.selected.brand == null && !(draft.user_edits || {}).brand ? draft.suggested.brand : draft.selected.brand);
			var packageField = field(card, 'Package research note', draft.selected.package == null && !(draft.user_edits || {}).package ? draft.suggested.package : draft.selected.package);
			var groups = [{ value: '', label: 'No product group' }].concat(catalog.product_groups.map(function (groupOption)
			{
				var suggested = draft.group_candidates.find(function (candidate) { return Number(candidate.id) === Number(groupOption.id); });
				return { value: groupOption.id, label: groupOption.name + (suggested ? ' · ' + (sources[suggested.source] || suggested.source) + ' suggestion' : '') };
			}));
			var group = select(card, 'Product group', groups, draft.selected.product_group_id);
			var taxonomy = select(card, 'Food classification', [{ value: '', label: 'Unclassified' }].concat(catalog.taxonomy_leaves.map(function (leafOption)
			{
				var suggested = draft.taxonomy_candidates.find(function (candidate) { return candidate.slug === leafOption.slug; });
				return { value: leafOption.slug, label: leafOption.label + (suggested ? ' · ' + (sources[suggested.source] || suggested.source) + ' suggestion' : '') };
			})), draft.selected.taxonomy_leaf_slug);
			var parent = select(card, 'Generic parent', [{ value: '', label: 'No generic parent' }].concat(catalog.generic_parents.map(function (product) { var candidate = (draft.parent_candidates || []).find(function (item) { return Number(item.id) === Number(product.id); }); return { value: product.id, label: product.name + ' (#' + product.id + ')' + (candidate ? ' · ' + (sources[candidate.source] || candidate.source) + ' suggestion' : '') }; })), draft.selected.parent_product_id);
			var savedEvidenceId = draft.receipt_evidence ? String(draft.receipt_evidence.receipt_line_id) : '';
			var evidenceChoices = (options.receipts || []).flatMap(function (receipt)
			{
				var receiptRecord = receipt.receipt || {};
				return (receipt.lines || []).filter(function (receiptLine) { return receiptLine.kind === 'item' && receiptLine.decision !== 'ignore' && typeof receiptLine.description === 'string' && receiptLine.description.trim() !== ''; }).map(function (receiptLine)
				{
					return { value: receiptLine.id, label: 'Receipt #' + receiptRecord.id + (receiptRecord.merchant ? ' ' + receiptRecord.merchant : '') + ' · line #' + receiptLine.id + ': ' + receiptLine.description };
				});
			});
			var evidence = select(card, 'Receipt line evidence', [{ value: '', label: 'No receipt line linked' }].concat(evidenceChoices), savedEvidenceId);
			if (draft.receipt_evidence && !evidence.value) { var current = node('option', null, 'Receipt #' + draft.receipt_evidence.receipt_id + ' · line #' + savedEvidenceId + ': ' + draft.receipt_evidence.description); current.value = savedEvidenceId; evidence.appendChild(current); evidence.value = savedEvidenceId; }
			var controls = node('div', 'grocy-ai-product-research-actions');
			card.appendChild(controls);
			var initialFields = { name: name.value.trim(), brand: brand.value.trim() || null, package: packageField.value.trim() || null, product_group_id: group.value ? Number(group.value) : null, taxonomy_leaf_slug: taxonomy.value || null, parent_product_id: parent.value ? Number(parent.value) : null };
			var fieldEdits = pendingEdits[line.id] || {};
			pendingEdits[line.id] = fieldEdits;
			var touchedFields = {};
			[['name', name], ['brand', brand], ['package', packageField], ['product_group_id', group], ['taxonomy_leaf_slug', taxonomy], ['parent_product_id', parent]].forEach(function (entry)
			{
				var key = entry[0], control = entry[1];
				if (Object.prototype.hasOwnProperty.call(fieldEdits, key)) control.value = fieldEdits[key] == null ? '' : String(fieldEdits[key]);
				function remember()
				{
					fieldEdits[key] = control.tagName === 'SELECT' ? (control.value ? (key === 'taxonomy_leaf_slug' ? control.value : Number(control.value)) : null) : control.value;
					if (options.onEdit) options.onEdit();
				}
				control.addEventListener('input', remember);
				control.addEventListener('change', remember);
			});
			[['product_group_id', group], ['taxonomy_leaf_slug', taxonomy], ['parent_product_id', parent]].forEach(function (entry) { entry[1].addEventListener('change', function () { touchedFields[entry[0]] = true; }); });
			button(controls, 'Save research draft', function ()
			{
				var values = { name: name.value.trim(), brand: brand.value.trim() || null, package: packageField.value.trim() || null, product_group_id: group.value ? Number(group.value) : null, taxonomy_leaf_slug: taxonomy.value || null, parent_product_id: parent.value ? Number(parent.value) : null };
				if (!values.name) { message.textContent = 'Enter a product name.'; return; }
				if (unitsReady)
				{
					values.qu_id_purchase = purchaseUnit.value ? Number(purchaseUnit.value) : null;
					values.qu_id_stock = stockUnit.value ? Number(stockUnit.value) : null;
				}
				var changes = {};
				Object.keys(values).forEach(function (key) { if (values[key] !== initialFields[key] || touchedFields[key] || Object.prototype.hasOwnProperty.call(fieldEdits, key)) changes[key] = values[key]; });
				if (!Object.keys(changes).length) { message.textContent = 'No research draft changes to save.'; return; }
				var submittedEdits = Object.assign({}, fieldEdits);
				mutate(path + '/research', 'PUT', { revision: draft.revision, changes: changes }, false, null, function ()
				{
					Object.keys(changes).forEach(function (key) { if (fieldEdits[key] === submittedEdits[key]) delete fieldEdits[key]; });
				});
			});
			evidence.addEventListener('change', function () { mutate(path + '/receipt-evidence', 'PUT', { receipt_line_id: evidence.value ? Number(evidence.value) : null }, false, function () { evidence.value = savedEvidenceId; }); });
			if (draft.job_state === 'needs_input' || draft.job_state === 'retryable_failure') button(controls, 'Retry research', function () { mutate(path + '/retry', 'POST', {}); });
			function reference(label, entity, initial)
			{
				var control = select(card, label, [{ value: '', label: 'Choose ' + label.toLowerCase() }], '');
				if (!referenceRequests[entity]) referenceRequests[entity] = request('/api/objects/' + entity, 'GET').catch(function () { return []; });
				referenceRequests[entity].then(function (rows)
				{
					if (!active || !Array.isArray(rows)) return;
					rows.filter(function (row) { return row.active == null || Number(row.active) !== 0; }).forEach(function (row)
					{
						var item = node('option', null, row.name); item.value = String(row.id); control.appendChild(item);
					});
					control.value = initial ? String(initial) : '';
				}).catch(function () {});
				return control;
			}
			var location = reference('Location', 'locations', options.locationId);
			var unitsReady = false;
			var unitMessage = node('p', 'grocy-ai-product-research-unit-message text-muted');
			unitMessage.setAttribute('role', 'status');
			function unitControl(label, key, candidates)
			{
				var control = select(card, label, [{ value: '', label: 'Choose ' + label.toLowerCase() }], '');
				var source = node('p', 'grocy-ai-product-research-unit-source text-muted');
				control.parentNode.appendChild(source);
				candidates = Array.isArray(candidates) ? candidates : [];
				initialFields[key] = draft.selected[key] == null ? null : Number(draft.selected[key]);
				function updateSource()
				{
					var candidate = candidates.find(function (item) { return Number(item.id) === Number(control.value); });
					source.textContent = Object.prototype.hasOwnProperty.call(fieldEdits, key) ? 'Reviewer choice (unsaved)' : Object.prototype.hasOwnProperty.call(draft.user_edits || {}, key) ? 'Saved reviewer choice' : candidate ? (sources[candidate.source] || candidate.source) + ' suggestion' : 'No unit suggestion; choose manually.';
					unitMessage.textContent = ['qu_id_purchase', 'qu_id_stock'].some(function (key) { return Object.prototype.hasOwnProperty.call(fieldEdits, key); }) ? 'Unit changes are unsaved. Save research draft to keep them.' : '';
				}
				control.addEventListener('change', function ()
				{
					fieldEdits[key] = control.value ? Number(control.value) : null;
					if (options.onEdit) options.onEdit();
					updateSource();
				});
				if (!referenceRequests.quantity_units) referenceRequests.quantity_units = request('/api/objects/quantity_units', 'GET');
				control.disabled = true;
				referenceRequests.quantity_units.then(function (rows)
				{
					if (!active || !Array.isArray(rows)) return;
					rows.filter(function (row) { return row.active == null || Number(row.active) !== 0; }).forEach(function (row)
					{
						var item = node('option', null, row.name);
						item.value = String(row.id); control.appendChild(item);
					});
					var value = Object.prototype.hasOwnProperty.call(fieldEdits, key) ? fieldEdits[key] : initialFields[key];
					control.value = value == null ? '' : String(value);
					control.disabled = !!options.readOnly;
					updateSource();
					unitsReady = true;
					checkParentUnits();
				}).catch(function () { source.textContent = 'Unit choices unavailable. Reload and try again.'; });
				return control;
			}
			var purchaseUnit = unitControl('Purchase unit', 'qu_id_purchase', draft.purchase_unit_candidates);
			var stockUnit = unitControl('Stock unit', 'qu_id_stock', draft.stock_unit_candidates);
			card.appendChild(unitMessage);
			var parentCompatible = true;
			var compatibilityGeneration = 0;
			var compatibilityMessage = node('p', 'grocy-ai-product-research-compatibility');
			compatibilityMessage.setAttribute('role', 'status');
			card.appendChild(compatibilityMessage);
			function checkParentUnits()
			{
				var generation = ++compatibilityGeneration;
				parentCompatible = !parent.value || !stockUnit.value;
				compatibilityMessage.textContent = '';
				if (parentCompatible) return;
				compatibilityMessage.textContent = 'Checking generic parent stock unit…';
				Promise.all([request('/api/objects/products/' + encodeURIComponent(parent.value), 'GET'), request('/api/objects/quantity_unit_conversions', 'GET')]).then(function (results)
				{
					if (!active || generation !== compatibilityGeneration) return;
					var product = results[0], conversions = results[1];
					if (!product || !Array.isArray(conversions)) throw new Error('Unavailable compatibility check');
					parentCompatible = Number(product.active) !== 0 && product.parent_product_id == null && (Number(product.qu_id_stock) === Number(stockUnit.value) || conversions.some(function (conversion) { return conversion.product_id == null && Number(conversion.from_qu_id) === Number(product.qu_id_stock) && Number(conversion.to_qu_id) === Number(stockUnit.value) && Number(conversion.factor) > 0; }));
					compatibilityMessage.textContent = parentCompatible ? 'Generic parent stock unit is compatible.' : 'Generic parent needs a compatible stock unit. Choose another parent or stock unit.';
				}).catch(function ()
				{
					if (!active || generation !== compatibilityGeneration) return;
					parentCompatible = true;
					compatibilityMessage.textContent = 'Could not check parent units. Approval will validate compatibility.';
				});
			}
			parent.addEventListener('change', checkParentUnits);
			stockUnit.addEventListener('change', checkParentUnits);
			purchaseUnit.addEventListener('change', checkParentUnits);
			function confirmWrite(kind, summary, body)
			{
				if (!window.confirm('Review ' + kind + ': ' + summary + '. Continue?')) return;
				if (!window.confirm('Final confirmation: ' + summary + '. This changes the product catalog and barcode ownership. Confirm?')) return;
				mutate(path + '/research/' + kind, 'POST', body, true);
			}
			button(controls, 'Approve new product', function ()
			{
				var fields = { name: name.value.trim(), location_id: Number(location.value), qu_id_purchase: Number(purchaseUnit.value), qu_id_stock: Number(stockUnit.value) };
				if (!fields.name || !fields.location_id || !fields.qu_id_purchase || !fields.qu_id_stock) { message.textContent = 'Enter a name, location, purchase unit, and stock unit first.'; return; }
				if (!parentCompatible) { message.textContent = 'Review generic parent stock unit compatibility first.'; return; }
				if (group.value) fields.product_group_id = Number(group.value);
				if (taxonomy.value) fields.taxonomy_leaf_slug = taxonomy.value;
				if (parent.value) fields.parent_product_id = Number(parent.value);
				confirmWrite('approve', fields.name + ' · barcode ' + draft.scanned_barcode + ' · location ' + (location.selectedOptions[0] || {}).textContent + ' · purchase unit ' + (purchaseUnit.selectedOptions[0] || {}).textContent + ' · stock unit ' + (stockUnit.selectedOptions[0] || {}).textContent + ' · group ' + (group.selectedOptions[0] || {}).textContent + ' · classification ' + (taxonomy.selectedOptions[0] || {}).textContent + ' · parent ' + (parent.selectedOptions[0] || {}).textContent, { revision: draft.revision, fields: fields });
			}, true);
			var search = field(card, 'Search existing products', '');
			var products = select(card, 'Existing product', [{ value: '', label: 'Choose an existing product' }].concat(draft.possible_existing_products.map(function (product) { return { value: product.id, label: product.name + ' (#' + product.id + '; exact name suggestion)' }; })), '');
			function showMatches()
			{
				var term = search.value.trim().toLocaleLowerCase();
				Array.prototype.forEach.call(products.querySelectorAll('option[data-search-result]'), function (item) { item.remove(); });
				if (term.length < 2 || !productList) return;
				productList.filter(function (product) { return (product.active == null || Number(product.active) !== 0) && typeof product.name === 'string' && product.name.toLocaleLowerCase().indexOf(term) !== -1; }).slice(0, 20).forEach(function (product)
				{
					if (Array.prototype.some.call(products.options, function (option) { return option.value === String(product.id); })) return;
					var option = node('option', null, product.name + ' (#' + product.id + '; search result)'); option.value = String(product.id); option.setAttribute('data-search-result', ''); products.appendChild(option);
				});
			}
			search.addEventListener('input', function ()
			{
				if (search.value.trim().length < 2) { showMatches(); return; }
				if (!productListPromise) productListPromise = request('/api/objects/products', 'GET').then(function (rows) { productList = Array.isArray(rows) ? rows : []; return productList; }).catch(function () { message.textContent = 'Could not search existing products.'; return []; });
				productListPromise.then(showMatches);
			});
			button(controls, 'Link existing product', function ()
			{
				if (!products.value) { message.textContent = 'Choose an existing product first.'; return; }
				confirmWrite('link', products.selectedOptions[0].textContent + ' · barcode ' + draft.scanned_barcode, { revision: draft.revision, product_id: Number(products.value) });
			}, true);
			finishCard();
		}
		load();
		return { dispose: function () { active = false; } };
	}
	window.GrocyAIProductResearch = GrocyAIProductResearch;
})();
