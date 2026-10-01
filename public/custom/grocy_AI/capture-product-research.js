(function ()
{
	'use strict';

	var sources = { 'bb-federation': 'Barcode Lookup Federation', openfoodfacts: 'Open Food Facts', receipt_ocr: 'Receipt OCR' };
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
			if (!response.ok) throw new Error(response.status === 403 ? 'You need product edit permission.' : response.status === 409 ? 'This draft changed. Reload and review it again.' : 'Could not save product review.');
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
		var canEditProducts = !window.Grocy || !Array.isArray(window.Grocy.UserPermissions) || window.Grocy.UserPermissions.some(function (permission) { return permission.permission_name === 'MASTER_DATA_EDIT' && Number(permission.has_permission) === 1; });
		function load()
		{
			return request(base + '/research', 'GET').then(function (payload)
			{
				if (!active) return;
				if (!payload || payload.contract_version !== 1 || Number(payload.trip_id) !== Number(options.tripId) || !Array.isArray(payload.drafts)) throw new Error('Research review is unavailable.');
				Array.prototype.forEach.call(host.querySelectorAll('.grocy-ai-product-research'), function (card) { card.remove(); });
				if (options.errorHost) options.errorHost.textContent = '';
				payload.drafts.forEach(function (draft)
				{
					var line = options.lines.find(function (candidate) { return candidate.id === draft.line_id && candidate.selected && candidate.status === 'unknown'; });
					if (line) render(draft, line);
				});
			}).catch(function (error) { if (active && options.errorHost) options.errorHost.textContent = error.message; });
		}
		function render(draft, line)
		{
			var card = node('section', 'grocy-ai-product-research');
			card.setAttribute('data-line-seq', String(line.seq));
			card.appendChild(node('h4', null, 'Scan #' + line.seq + ' · Product research · ' + draft.scanned_barcode));
			var status = draft.job_state === 'ready' ? 'Ready to review' : draft.job_state === 'queued' || draft.job_state === 'leased' ? 'Researching' : draft.job_state === 'retryable_failure' ? 'Provider unavailable' : 'Needs details';
			card.appendChild(node('p', 'grocy-ai-product-research-status', status));
			card.appendChild(node('p', 'text-muted', 'Provisional research only. No product or stock has been saved.'));
			if (draft.safe_error_code) card.appendChild(node('p', 'text-muted', 'Research service could not finish. You can retry or enter details.'));
			var path = base + '/lines/' + encodeURIComponent(String(line.seq));
			var message = node('p', 'grocy-ai-product-research-message');
			message.setAttribute('role', 'status');
			function mutate(url, method, body, reloadTrip, onFailure)
			{
				message.textContent = 'Saving…';
				return request(url, method, body).then(function () { message.textContent = 'Saved.'; return reloadTrip ? options.reload() : load(); }).catch(function (error) { if (onFailure) onFailure(); message.textContent = error.message; });
			}
			if (draft.name_alternatives.length)
			{
				var names = node('div', 'grocy-ai-product-research-names');
				names.appendChild(node('strong', null, 'Suggested names'));
				draft.name_alternatives.forEach(function (candidate)
				{
					var label = node('p', null, candidate.value + ' — ' + (candidate.sources.length ? candidate.sources.map(function (source) { return sources[source] || source; }).join(', ') : 'Source unknown'));
					names.appendChild(label);
				});
				card.appendChild(names);
			}
			if (draft.receipt_evidence) card.appendChild(node('p', null, 'Receipt evidence: ' + draft.receipt_evidence.description + ' (Receipt OCR; supporting evidence)'));
			if (draft.suggested && draft.suggested.categories && draft.suggested.categories.length) card.appendChild(node('p', 'text-muted', 'Open Food Facts categories: ' + draft.suggested.categories.join(', ')));
			var name = field(card, 'Proposed name', draft.selected.name);
			card.appendChild(node('p', 'grocy-ai-product-research-notes text-muted', 'Brand and package are research notes only; they are not saved to the Grocy product by this approval. Save research draft stores your corrections for review.'));
			if (draft.suggested && draft.suggested.brand) card.appendChild(node('p', 'grocy-ai-product-research-brand-source', draft.suggested.brand + ' — Open Food Facts'));
			if (draft.suggested && draft.suggested.package) card.appendChild(node('p', 'grocy-ai-product-research-package-source', draft.suggested.package + ' — Open Food Facts'));
			var brand = field(card, 'Brand research note', draft.selected.brand == null ? draft.suggested.brand : draft.selected.brand);
			var packageField = field(card, 'Package research note', draft.selected.package == null ? draft.suggested.package : draft.selected.package);
			var groups = [{ value: '', label: 'No product group' }].concat(draft.group_candidates.map(function (group) { return { value: group.id, label: group.name + ' · exact Open Food Facts category' }; }));
			var group = select(card, 'Product group', groups, draft.selected.product_group_id);
			var taxonomy = select(card, 'Food classification', [{ value: '', label: 'Unclassified' }].concat(draft.taxonomy_candidates.map(function (leaf) { return { value: leaf.slug, label: leaf.label + ' · Open Food Facts mapping v' + leaf.ruleset_version }; })), draft.selected.taxonomy_leaf_slug);
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
			var initialFields = { name: name.value.trim(), brand: brand.value.trim() || null, package: packageField.value.trim() || null, product_group_id: group.value ? Number(group.value) : null, taxonomy_leaf_slug: taxonomy.value || null };
			button(controls, 'Save research draft', function ()
			{
				var values = { name: name.value.trim(), brand: brand.value.trim() || null, package: packageField.value.trim() || null, product_group_id: group.value ? Number(group.value) : null, taxonomy_leaf_slug: taxonomy.value || null };
				if (!values.name) { message.textContent = 'Enter a product name.'; return; }
				var changes = {};
				Object.keys(values).forEach(function (key) { if (values[key] !== initialFields[key]) changes[key] = values[key]; });
				if (!Object.keys(changes).length) { message.textContent = 'No research draft changes to save.'; return; }
				mutate(path + '/research', 'PUT', { revision: draft.revision, changes: changes });
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
			var purchaseUnit = reference('Purchase unit', 'quantity_units', null);
			var stockUnit = reference('Stock unit', 'quantity_units', null);
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
				if (group.value) fields.product_group_id = Number(group.value);
				if (taxonomy.value) fields.taxonomy_leaf_slug = taxonomy.value;
				confirmWrite('approve', fields.name + ' · barcode ' + draft.scanned_barcode + ' · group ' + (group.selectedOptions[0] || {}).textContent + ' · classification ' + (taxonomy.selectedOptions[0] || {}).textContent, { revision: draft.revision, fields: fields });
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
			card.appendChild(message);
			if (!canEditProducts)
			{
				card.appendChild(node('p', 'text-muted', 'Product edit permission is required to approve or link.'));
				Array.prototype.forEach.call(card.querySelectorAll('.permission-MASTER_DATA_EDIT'), function (control) { control.disabled = true; });
			}
			if (options.readOnly) Array.prototype.forEach.call(card.querySelectorAll('input, select, button'), function (control) { control.disabled = true; });
			var row = host.querySelector('.grocy-ai-capture-review-line[data-line-seq=\"' + String(line.seq) + '\"]');
			if (row) row.appendChild(card);
		}
		load();
		return { dispose: function () { active = false; } };
	}
	window.GrocyAIProductResearch = GrocyAIProductResearch;
})();
