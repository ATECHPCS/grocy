(function (root)
{
	'use strict';

	function reason(code)
	{
		var descriptions = {
			no_lines: 'Add at least one receipt line',
			printed_total_missing: 'Enter the printed total',
			total_difference: 'Reconcile or accept the receipt total difference',
			needs_review: 'Choose Include or Ignore',
			unallocated: 'Choose a match and confirm purchase quantity and unit price',
			unknown_product: 'Choose a known Grocy product',
			quantity_overallocated: 'Allocated quantity exceeds the receipt quantity',
			quantity_unmatched: 'Match receipt allocations to the full scanned quantity',
			unselected_allocated: 'Include this scanned item or remove its allocations',
			capture_conflict: 'Correct the scanned item match',
			invalid_amount: 'Confirm a positive quantity and nonnegative unit price',
			product_conflict: 'Correct conflicting product matches',
			store_conflict: 'Correct the receipt store allocation'
		};
		var text = String(code).replace(/^no_receipts$/, 'Add at least one receipt').replace(/^receipt_(\d+)_unfinished$/, 'Finish receipt #$1').replace(/^receipt_(\d+)_/, 'Receipt #$1: ').replace(/^capture_line_(\d+)_/, 'Scanned item #$1: ').replace(/line_(\d+)_/, 'Line #$1: ');
		Object.keys(descriptions).forEach(function (key)
		{
			if (text.endsWith(key)) text = text.slice(0, -key.length) + descriptions[key];
		});
		return text.replace(/_/g, ' ');
	}
	root.GrocyAIReceipts = function (host, options)
	{
		var busy = false;
		var disposed = false;
		var inputRoot = options.inputRoot || host;
		var lineEditors = [];
		var state = options.state;
		state.pairingChoices = state.pairingChoices || {};
		var dirty = Object.keys(state.drafts).length > 0;
		var readOnly = options.readOnly;
		var status;
		var notice = options.notice || '';

		function el(tag, text, className)
		{
			var node = document.createElement(tag);
			node.textContent = text || '';
			if (className) node.className = className;
			return node;
		}

		function restoreDraft(input, parent, label)
		{
			var scope = parent.getAttribute('data-draft-scope');
			if (!scope || input.type === 'file') return;
			input.setAttribute('data-draft-scope', scope);
			input.setAttribute('data-draft-field', label);
			var draft = state.drafts[scope] && state.drafts[scope][label];
			if (!draft) return;
			if (input.tagName === 'SELECT' && !Array.prototype.some.call(input.options, function (option) { return option.value === draft.value; }))
			{
				var option = el('option', draft.text);
				option.value = draft.value;
				input.appendChild(option);
			}
			input.value = draft.value;
		}

		function field(parent, label, value, type)
		{
			var wrapper = el('label', label, 'grocy-ai-receipt-field');
			var input = el('input', '', 'form-control');
			input.type = type || 'text';
			input.value = value === null || value === undefined ? '' : value;
			if (type === 'number')
			{
				input.step = 'any';
				input.inputMode = 'decimal';
			}
			restoreDraft(input, parent, label);
			wrapper.appendChild(input);
			parent.appendChild(wrapper);
			return input;
		}

		function select(parent, label, choices, value)
		{
			var wrapper = el('label', label, 'grocy-ai-receipt-field');
			var input = el('select', '', 'form-control');
			choices.forEach(function (choice)
			{
				var option = el('option', choice[1]);
				option.value = choice[0];
				input.appendChild(option);
			});
			input.value = value;
			restoreDraft(input, parent, label);
			wrapper.appendChild(input);
			parent.appendChild(wrapper);
			return input;
		}

		function button(parent, text, action)
		{
			var node = el('button', text, 'btn btn-outline-primary');
			node.type = 'button';
			node.addEventListener('click', action);
			parent.appendChild(node);
			return node;
		}

		function request(path, method, body)
		{
			return fetch(options.url + path,
			{
				method: method || 'GET',
				credentials: 'same-origin',
				cache: 'no-store',
				headers: body instanceof FormData ?
				{
					Accept: 'application/json'
				} :
				{
					Accept: 'application/json',
					'Content-Type': 'application/json'
				},
				body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body)
			}).then(function (response)
			{
				return response.json().then(function (data)
				{
					if (!response.ok) throw new Error(data.error_message || data.message || 'Could not save. Try again.');
					return data;
				});
			});
		}

		function lock(value)
		{
			if (disposed) return;
			busy = value;
			if (host.isConnected) options.onBusy(value || dirty);
			[host].concat(lineEditors).forEach(function (parent)
			{
				Array.prototype.forEach.call(parent.querySelectorAll('input,select,button:not([data-receipt-toggle])'), function (node)
				{
					node.disabled = value || readOnly;
				});
			});
		}

		function run(action, scope)
		{
			if (busy || readOnly) return;
			if (dirty && !scope)
			{
				status.textContent = 'Save all edited sections before this action.';
				return;
			}
			var savedDraft = scope ? state.drafts[scope] : null;
			lock(true);
			status.textContent = 'Saving…';
			Promise.resolve().then(action).then(function (result)
			{
				if (scope && state.drafts[scope] === savedDraft) delete state.drafts[scope];
				dirty = Object.keys(state.drafts).length > 0;
				return options.reload(result && result.message ? result.message : 'Saved.');
			}).catch(function (error)
			{
				status.textContent = error.message;
			}).finally(function ()
			{
				lock(false);
			});
		}

		function save(path, method, body, scope)
		{
			run(function ()
			{
				return request(path, method, body).then(function (result)
				{
					// Saving a non-inventory decision hides its allocation editors.
					if (body.decision && body.decision !== 'include')
					{
						Object.keys(state.drafts).forEach(function (key)
						{
							if (key.indexOf(path + '/allocation/') === 0) delete state.drafts[key];
						});
					}
					return result;
				});
			}, scope);
		}

		function discardButton(parent, label)
		{
			button(parent, label, function ()
			{
				run(function () { return { message: 'Edits discarded.' }; }, parent.getAttribute('data-draft-scope'));
			});
		}

		function number(input)
		{
			return input.value.trim() === '' ? null : Number(input.value);
		}

		function allocation(parent, path, line, existing)
		{
			var box = el('div', '', 'grocy-ai-receipt-allocation');
			box.setAttribute('data-draft-scope', path + '/allocation/' + (existing ? existing.id : 'new'));
			parent.appendChild(box);
			box.appendChild(el('h5', existing ? 'Confirmed purchase allocation' : 'Match and confirm purchase'));
			var choices = [
				['', 'Choose a scanned item or known product']
			];
			options.lines.filter(function (item)
			{
				return Number(item.selected) === 1 && item.resolved_product_id;
			}).forEach(function (item)
			{
				choices.push(['capture:' + item.id + ':' + item.resolved_product_id, 'Scanned #' + item.seq + ' — ' + (options.names[item.resolved_product_id] || 'Product #' + item.resolved_product_id)]);
			});
			var pairedScan = !existing && line.paired_capture_line_id ? options.lines.find(function (item)
			{
				return Number(item.id) === Number(line.paired_capture_line_id) && Number(item.selected) === 1 && item.resolved_product_id;
			}) : null;
			var chosen = existing ? (existing.capture_line_id ? 'capture:' + existing.capture_line_id + ':' + existing.product_id : 'product:' + existing.product_id) : pairedScan ? 'capture:' + pairedScan.id + ':' + pairedScan.resolved_product_id : '';
			if (existing && !existing.capture_line_id) choices.push([chosen, 'Product #' + existing.product_id]);
			var match = select(box, 'Purchase match', choices, chosen);
			button(box, 'Find product suggestions', function ()
			{
				request(path + '/matches').then(function (data)
				{
					(data.products || []).forEach(function (product)
					{
						var option = el('option', product.name);
						option.value = 'product:' + product.id;
						match.appendChild(option);
					});
					status.textContent = data.products.length ? 'Choose a suggestion, then save the allocation.' : 'No product suggestions. Correct the description or create a product.';
				}).catch(function ()
				{
					status.textContent = 'Could not load suggestions. Try again.';
				});
			});
			var quantity = field(box, 'Purchase quantity', existing ? existing.quantity : line.quantity, 'number');
			var derivedPrice = !existing && pairedScan && Number(line.quantity) > 0 && Number(line.line_total) >= 0 && line.line_total !== null ? Number((Number(line.line_total) / Number(line.quantity)).toFixed(6)) : null;
			var price = field(box, 'Confirmed unit price', existing ? existing.unit_price : derivedPrice, 'number');
			if (derivedPrice !== null) box.appendChild(el('p', 'Price calculated from this receipt line. Confirm quantity and price before adding the allocation.'));
			quantity.min = '0.000001';
			price.min = '0';
			button(box, existing ? 'Save allocation' : 'Add allocation', function ()
			{
				if (!match.value || number(quantity) === null || number(quantity) <= 0 || number(price) === null || number(price) < 0)
				{
					status.textContent = 'Choose a match, positive quantity and a nonnegative unit price.';
					return;
				}
				var parts = match.value.split(':');
				var body = {
					capture_line_id: parts[0] === 'capture' ? Number(parts[1]) : null,
					product_id: Number(parts[parts.length - 1]),
					quantity: number(quantity),
					unit_price: number(price)
				};
				if (existing) body.id = Number(existing.id);
				save(path + '/allocation', 'PUT', body, box.getAttribute('data-draft-scope'));
			});
			discardButton(box, 'Discard allocation edits');
			if (existing) button(box, 'Remove allocation', function ()
			{
				save(path + '/allocation', 'PUT',
				{
					id: Number(existing.id),
					delete: true
				});
			});
		}

		function renderLine(parent, receiptPath, line)
		{
			var box = el('fieldset', '', 'grocy-ai-receipt-line');
			box.appendChild(el('legend', 'Receipt line #' + line.id));
			parent.appendChild(box);
			lineEditors.push(box);
			var path = receiptPath + '/lines/' + line.id;
			box.setAttribute('data-draft-scope', path);
			if ((line.kind === undefined || line.kind === 'item') && line.decision !== 'ignore')
			{
				var disregard = button(box, 'Disregard receipt item', function ()
				{
					if (busy || readOnly) return;
					if ((line.allocations || []).some(function (allocation) { return Number(allocation.active) !== 0; }))
					{
						status.textContent = 'Remove this receipt item’s allocations before disregarding it. Use Remove allocation below, then try again.';
						return;
					}
					if (!window.confirm('Disregard receipt item “' + line.description + '”? It will be excluded from the purchase and unapproved receipt research. Its receipt amount and history will be kept. Unsaved edits to this line will be discarded.')) return;
					save(path, 'PUT', { decision: 'ignore' }, path);
				});
				disregard.className = 'btn btn-outline-danger mb-2';
			}
			if (line.paired_capture_line_id)
			{
				var pairedLine = (options.lines || []).find(function (item) { return Number(item.id) === Number(line.paired_capture_line_id); });
				box.appendChild(el('p', 'Paired with scanned item #' + (pairedLine ? pairedLine.seq : line.paired_capture_line_id) + ' for product review.'));
				if (pairedLine && pairedLine.status === 'unknown') button(box, 'Clear scan pairing', function ()
				{
					run(function () { return request('/lines/' + pairedLine.seq + '/receipt-evidence', 'PUT', { receipt_line_id: null }); });
				});
			}
			if (line.kind === undefined || line.kind === 'item')
			{
				var unknownScans = (options.lines || []).filter(function (item)
				{
					return Number(item.selected) === 1 && !item.resolved_product_id && item.status === 'unknown';
				});
				if (unknownScans.length && line.decision !== 'ignore')
				{
					var candidates = Array.isArray(line.capture_candidates) ? line.capture_candidates : [];
					var available = Array.isArray(line.capture_options) ? line.capture_options.filter(function (item)
					{
						return !item.paired_receipt_line_id || Number(item.paired_receipt_line_id) === Number(line.id);
					}) : unknownScans.map(function (item)
					{
						return { capture_line_id: item.id, seq: item.seq, scanned_barcode: item.scanned_barcode, display_name: '', source: '', paired_receipt_line_id: null };
					});
					var choices = [['', 'Choose a scanned UPC']];
					candidates.forEach(function (candidate)
					{
						if (!available.some(function (item) { return Number(item.capture_line_id) === Number(candidate.capture_line_id); })) return;
						choices.push([String(candidate.capture_line_id), 'Scanned #' + candidate.seq + ' — ' + candidate.display_name + ' (suggested match)']);
					});
					available.forEach(function (item)
					{
						if (choices.some(function (choice) { return choice[0] === String(item.capture_line_id); })) return;
						choices.push([String(item.capture_line_id), 'Scanned #' + item.seq + ' — ' + (item.display_name || 'UPC ' + item.scanned_barcode)]);
					});
					var suggestedId = line.paired_capture_line_id ? String(line.paired_capture_line_id) : line.capture_match_status === 'unique' && candidates.length ? String(candidates[0].capture_line_id) : '';
					var pairing = el('div', '', 'grocy-ai-receipt-pairing');
					box.appendChild(pairing);
					// This selection is an action input, not an edit saved by Save line.
					var scanned = select(pairing, 'Scanned item for this receipt line', choices, Object.prototype.hasOwnProperty.call(state.pairingChoices, path) ? state.pairingChoices[path] : suggestedId);
					scanned.addEventListener('change', function () { state.pairingChoices[path] = scanned.value; });
					pairing.appendChild(el('p', 'Pair the UPC with this receipt description for product review. Confirm product details before adding a purchase allocation.'));
					var pairingMessage = el('p', '', 'grocy-ai-receipt-pairing-message');
					pairingMessage.setAttribute('role', 'status');
					button(pairing, 'Pair scanned item', function ()
					{
						if (!scanned.value) { pairingMessage.textContent = 'Choose a scanned UPC first.'; return; }
						if (state.drafts[path]) { pairingMessage.textContent = 'Save or discard edits to this receipt line before pairing.'; return; }
						var selected = unknownScans.find(function (item) { return String(item.id) === scanned.value; });
						if (!selected) { pairingMessage.textContent = 'This scan is no longer available. Reload the trip.'; return; }
						run(function () { return request('/lines/' + selected.seq + '/receipt-evidence', 'PUT', { receipt_line_id: Number(line.id) }).then(function (result) { delete state.pairingChoices[path]; return result; }); }, path + '/pairing');
					});
					pairing.appendChild(pairingMessage);
				}
			}
			var description = field(box, 'Description', line.description);
			var quantity = field(box, 'Receipt quantity', line.quantity, 'number');
			var total = field(box, 'Line total', line.line_total, 'number');
			var decision = select(box, 'Decision', [
				['needs_review', 'Needs review'],
				['include', 'Include'],
				['ignore', 'Ignore']
			], line.decision);
			button(box, 'Save line', function ()
			{
				save(path, 'PUT',
				{
					description: description.value,
					quantity: number(quantity),
					line_total: number(total),
					decision: decision.value
				}, path);
			});
			discardButton(box, 'Discard line edits');
			if (line.decision === 'ignore') box.appendChild(el('p', 'Ignored — retained on the receipt, excluded from stock.'));
			if (line.decision === 'include')
			{
				(line.allocations || []).filter(function (a)
				{
					return Number(a.active) !== 0;
				}).forEach(function (a)
				{
					allocation(box, path, line, a);
				});
				allocation(box, path, line, null);
				var link = el('a', 'Create product', 'btn btn-outline-primary permission-MASTER_DATA_EDIT');
				link.href = options.productNew;
				link.target = '_blank';
				link.rel = 'noopener';
				box.appendChild(link);
				box.appendChild(el('p', 'After creating a product, return here and find suggestions. Remove existing allocations before changing Include to Ignore. Saving Ignore or Needs review discards unsaved allocation edits.'));
			}
		}

		function render(view)
		{
			var receipt = view.receipt,
				path = '/receipts/' + receipt.id;
			var card = el('section', '', 'grocy-ai-receipt');
			card.setAttribute('data-draft-scope', path);
			host.appendChild(card);
			card.appendChild(el('h4', 'Receipt #' + receipt.id + ' — ' + receipt.status.replace(/_/g, ' ')));
			if (options.compact)
			{
				state.expanded = state.expanded || {};
				var summary = el('div', '', 'grocy-ai-receipt-compact-summary');
				summary.appendChild(el('p', receipt.merchant || 'Merchant not entered'));
				summary.appendChild(el('p', 'Printed total: ' + (view.totals.printed_total == null ? 'not entered' : view.totals.printed_total) + ' · Entered total: ' + view.totals.entered_total + ' · Difference: ' + (view.totals.difference == null ? 'not checked' : view.totals.difference)));
				if (receipt.difference_accepted_amount != null) summary.appendChild(el('p', 'Difference accepted: ' + receipt.difference_accepted_amount));
				var details = el('div', '', 'grocy-ai-receipt-details');
				details.id = 'grocyai-receipt-details-' + receipt.id;
				details.setAttribute('data-draft-scope', path);
				var toggle = button(summary, '', function ()
				{
					state.expanded[receipt.id] = !state.expanded[receipt.id];
					expand();
				});
				toggle.setAttribute('data-receipt-toggle', '');
				toggle.setAttribute('aria-controls', details.id);
				function expand()
				{
					var expanded = !!state.expanded[receipt.id];
					details.classList.toggle('is-expanded', expanded);
					toggle.setAttribute('aria-expanded', String(expanded));
					toggle.textContent = expanded ? 'Hide receipt details' : 'Show receipt details';
				}
				expand();
				card.appendChild(summary);
				card.appendChild(details);
				card = details;
			}

			var image = el('img');
			image.src = options.url + path + '/image';
			image.alt = 'Receipt #' + receipt.id + ' photo';
			image.loading = 'lazy';
			card.appendChild(image);
			button(card, 'Read receipt', function ()
			{
				save(path + '/extract', 'POST',
				{});
			});
			button(card, 'Retry reading receipt', function ()
			{
				save(path + '/retry', 'POST',
				{});
			});
			card.appendChild(el('p', 'Enter the receipt manually if reading is unavailable. Reading never approves purchases.'));
			var merchant = field(card, 'Merchant', receipt.merchant);
			var date = field(card, 'Purchase date', receipt.purchase_date, 'date');
			var printed = field(card, 'Printed total', receipt.printed_total, 'number');
			var stores = [
				['', '(none)']
			].concat(options.stores.map(function (s)
			{
				return [String(s.id), s.name];
			}));
			var store = select(card, 'Receipt store', stores, receipt.shopping_location_id === null || receipt.shopping_location_id === undefined ? '' : String(receipt.shopping_location_id));
			button(card, 'Save receipt details', function ()
			{
				save(path, 'PUT',
				{
					merchant: merchant.value,
					purchase_date: date.value || null,
					printed_total: number(printed),
					shopping_location_id: store.value ? Number(store.value) : null
				}, path);
			});
			discardButton(card, 'Discard receipt edits');
			card.appendChild(el('p', 'Entered total: ' + view.totals.entered_total + ' · Difference: ' + (view.totals.difference === null ? 'enter printed total' : view.totals.difference)));
			if (receipt.difference_accepted_amount !== null && receipt.difference_accepted_amount !== undefined) card.appendChild(el('p', 'Difference accepted: ' + receipt.difference_accepted_amount));
			else if (view.totals.difference !== null && Math.abs(view.totals.difference) >= 0.005) button(card, 'Accept difference and leave as is', function ()
			{
				save(path, 'PUT',
				{
					accept_difference: true
				});
			});
			card.appendChild(el('p', 'Reconcile by correcting the printed total or line amounts. Edits reopen the receipt and clear difference acceptance.'));
			(view.lines || []).forEach(function (line)
			{
				var lineHost = options.lineHostFor ? options.lineHostFor(receipt, line) : card;
				// Keep every line editable if a queue card could not be mounted.
				renderLine(lineHost || card, path, line);
			});
			button(card, 'Add manual line', function ()
			{
				save(path + '/lines', 'POST',
				{
					description: '',
					quantity: 1,
					decision: 'needs_review'
				});
			});
			var issues = el('ul');
			(view.issues || []).forEach(function (issue)
			{
				issues.appendChild(el('li', reason(issue)));
			});
			card.appendChild(issues);
			button(card, receipt.status === 'finished' ? 'Reopen receipt' : 'Finish receipt', function ()
			{
				save(path + (receipt.status === 'finished' ? '/reopen' : '/finish'), 'POST',
				{});
			});
		}
		host.textContent = '';
		host.appendChild(el('h3', 'Receipts'));
		status = el('p', notice, 'grocy-ai-receipt-status');
		status.setAttribute('role', 'status');
		host.appendChild(status);
		var upload = field(host, 'Add receipt photos', '', 'file');
		upload.accept = 'image/jpeg,image/png,image/webp';
		upload.multiple = true;
		var camera = field(host, 'Take receipt photo', '', 'file');
		camera.accept = upload.accept;
		camera.setAttribute('capture', 'environment');

		var uploadProgress = el('ul');
		host.appendChild(uploadProgress);
		function renderUploadProgress()
		{
			uploadProgress.textContent = '';
			state.uploads.forEach(function (job, index)
			{
				uploadProgress.appendChild(el('li', 'Photo ' + (index + 1) + ': ' + job.status + (job.status === 'failed' ? ' — ' + job.error : '')));
			});
		}
		renderUploadProgress();

		function uploadPending()
		{
			run(function ()
			{
				return state.uploads.filter(function (job) { return job.status !== 'uploaded'; }).reduce(function (chain, job)
				{
					return chain.then(function ()
					{
						job.status = 'uploading';
						renderUploadProgress();
						status.textContent = 'Uploading receipt photo…';
						var data = new FormData();
						data.append('image', job.file);
						data.append('request_id', job.id);
						return request('/receipts', 'POST', data).then(function ()
						{
							job.status = 'uploaded';
							job.file = null;
							renderUploadProgress();
						}).catch(function (error)
						{
							job.status = 'failed';
							job.error = error.message;
							renderUploadProgress();
						});
					});
				}, Promise.resolve()).then(function ()
				{
					var failed = state.uploads.filter(function (job) { return job.status === 'failed'; }).length;
					return { message: failed ? 'Uploaded photos are shown below. ' + failed + ' photo(s) failed; retry failed photos.' : 'Receipt photos saved.' };
				});
			});
		}

		function uploadFiles(input)
		{
			if (dirty)
			{
				status.textContent = 'Save all edited sections before this action.';
				input.value = '';
				return;
			}
			Array.prototype.forEach.call(input.files, function (file)
			{
				var key = JSON.stringify([file.name, file.size, file.type, file.lastModified]);
				if (state.uploads.some(function (job) { return job.key === key; })) return;
				var id = Array.prototype.map.call(root.crypto.getRandomValues(new Uint8Array(16)), function (value)
				{
					return value.toString(16).padStart(2, '0');
				}).join('');
				state.uploads.push({ key: key, id: id, file: file, status: 'pending' });
			});
			uploadPending();
		}
		if (state.uploads.some(function (job) { return job.status !== 'uploaded'; }))
		{
			button(host, 'Retry failed photos', uploadPending);
		}
		upload.addEventListener('change', function ()
		{
			uploadFiles(upload);
		});
		camera.addEventListener('change', function ()
		{
			uploadFiles(camera);
		});
		(options.receipts || []).forEach(render);
		function trackInput(event)
		{
			if (!host.contains(event.target) && !lineEditors.some(function (box) { return box.contains(event.target); })) return;
			if (event.target.type !== 'file')
			{
				var scope = event.target.getAttribute('data-draft-scope');
				var label = event.target.getAttribute('data-draft-field');
				if (!scope || !label) return;
				state.drafts[scope] = Object.assign({}, state.drafts[scope] || {});
				state.drafts[scope][label] = { value: event.target.value, text: event.target.tagName === 'SELECT' && event.target.selectedIndex >= 0 ? event.target.options[event.target.selectedIndex].text : '' };
				dirty = true;
				options.onBusy(true);
				status.textContent = 'Unsaved changes — save this section before committing.';
			}
		}
		inputRoot.addEventListener('input', trackInput);
		lock(false);
		return {
			dispose: function ()
			{
				disposed = true;
				inputRoot.removeEventListener('input', trackInput);
			},
			hasUnsavedEdits: function ()
			{
				return Object.keys(state.drafts).length > 0;
			}
		};
	};
	root.GrocyAIReceiptReason = reason;
})(window);
