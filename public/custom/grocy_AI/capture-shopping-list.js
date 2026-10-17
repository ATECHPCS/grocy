(function (global) {
	'use strict';
	function attach(root, options) {
		var panel = document.getElementById('grocyai-shopping-panel');
		if (!panel) return null;
		var content = document.getElementById('grocyai-shopping-content');
		var trip = null,
			generation = 0,
			readSequence = 0,
			model = null,
			target = null,
			targetVersion = null,
			busy = false;
		var editingCollection = false;
		var pending = null,
			form = null,
			status = '',
			denied = false,
			loading = null;
		var base = root.getAttribute('data-trips-endpoint');
		var targetBanner = document.createElement('p');
		targetBanner.setAttribute('role', 'status');
		targetBanner.setAttribute('aria-live', 'polite');
		var scanHeading = document.getElementById(
			'grocyai-capture-scan-heading',
		);
		if (scanHeading)
			scanHeading.insertAdjacentElement('afterend', targetBanner);
		function endpoint() {
			return base + '/' + encodeURIComponent(trip) + '/shopping';
		}
		function storageKey() {
			return 'grocy-shopping-pending-' + trip;
		}
		function savePending(value) {
			pending = value;
			try {
				if (value)
					sessionStorage.setItem(storageKey(), JSON.stringify(value));
				else sessionStorage.removeItem(storageKey());
			} catch (e) {
				if (value)
					status =
						'Keep this page open until the operation is verified.';
			}
		}
		function request(path, method, body) {
			return fetch(path, {
				method: method || 'GET',
				credentials: 'same-origin',
				cache: 'no-store',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			}).then(function (response) {
				return response
					.json()
					.catch(function () {
						return {};
					})
					.then(function (data) {
						if (!response.ok) {
							var error = new Error(
								data.error_message ||
									'Shopping list request failed.',
							);
							error.status = response.status;
							throw error;
						}
						return data;
					});
			});
		}
		function node(tag, text, parent) {
			var el = document.createElement(tag);
			if (text !== undefined) el.textContent = text;
			if (parent) parent.appendChild(el);
			return el;
		}
		function button(parent, text, action, disabled) {
			var b = node('button', text, parent);
			b.type = 'button';
			b.className = 'btn btn-outline-primary';
			b.disabled = !!disabled || busy || !!pending;
			b.addEventListener('click', action);
			return b;
		}
		function field(parent, label, type) {
			var wrapper = node('label', undefined, parent);
			node('span', label, wrapper);
			var input = node(
				type === 'select' ? 'select' : 'input',
				undefined,
				wrapper,
			);
			input.className = 'form-control';
			input.setAttribute('aria-label', label);
			if (type !== 'select') input.type = type || 'text';
			return input;
		}
		function choice(select, value, name) {
			var o = node('option', name, select);
			o.value = String(value);
		}
		function rowFor(id) {
			return (
				model &&
				model.items.find(function (r) {
					return r.id === Number(id);
				})
			);
		}
		function operationOK(op) {
			return (
				op &&
				typeof op.operation_id === 'string' &&
				[
					'awaiting_curation',
					'pending_selection',
					'pending_amount',
					'settled',
					'conflicted',
					'undone',
				].indexOf(op.state) !== -1 &&
				typeof op.needs_amount === 'boolean' &&
				(op.line === null ||
					global.GrocyAICapture.isLinePayload(op.line))
			);
		}
		function validModel(data) {
			return (
				data &&
				data.contract_version === 1 &&
				(data.selected_list_id === null ||
					Number.isInteger(data.selected_list_id)) &&
				Array.isArray(data.lists) &&
				Array.isArray(data.items) &&
				Array.isArray(data.operations) &&
				data.operations.every(operationOK) &&
				data.items.every(function (r) {
					return (
						Number.isInteger(r.id) &&
						typeof r.version === 'string' &&
						(r.amount === null || Number.isFinite(r.amount)) &&
						Number.isFinite(r.collected_amount) &&
						typeof r.done === 'boolean'
					);
				})
			);
		}
		function refresh() {
			if (!trip) return Promise.resolve();
			var owner = generation,
				sequence = ++readSequence,
				url = endpoint();
			return request(url)
				.then(function (data) {
					if (owner !== generation || sequence !== readSequence)
						return;
					if (!validModel(data))
						throw new Error(
							'Shopping list response could not be verified.',
						);
					model = data;
					denied = false;
					if (!form) render();
				})
				.catch(function (error) {
					if (owner !== generation) return;
					if (error.status === 403) {
						denied = true;
						model = null;
						status =
							'Shopping lists are unavailable for your permissions. Regular capture is available.';
					} else
						status =
							error.message +
							' Refresh the list before scanning.';
					if (!form) render();
				});
		}
		function validRecovery(record) {
			if (
				!record ||
				typeof record !== 'object' ||
				!record.body ||
				typeof record.body !== 'object'
			)
				return false;
			if (record.kind === 'selection')
				return (
					record.url === endpoint() + '/selection' &&
					record.operation_id === null
				);
			if (
				typeof record.operation_id !== 'string' ||
				!/^[A-Za-z0-9_-]{1,100}$/.test(record.operation_id)
			)
				return false;
			var path =
				record.kind === 'scan'
					? '/scan'
					: record.kind === 'done'
						? '/done'
						: ['curate', 'settle', 'undo'].includes(record.kind)
							? '/operations/' +
								encodeURIComponent(record.operation_id) +
								'/' +
								record.kind
							: null;
			return (
				path !== null &&
				record.url === endpoint() + path &&
				(!['scan', 'done'].includes(record.kind) ||
					record.body.operation_id === record.operation_id)
			);
		}

		function load(id) {
			generation++;
			readSequence++;
			trip = id === null ? null : String(id);
			model = null;
			target = null;
			form = null;
			editingCollection = false;
			pending = null;
			denied = false;
			status = '';
			if (trip) {
				try {
					pending = JSON.parse(
						sessionStorage.getItem(storageKey()) || 'null',
					);
					if (pending && !validRecovery(pending)) {
						pending = null;
						sessionStorage.removeItem(storageKey());
						status =
							'Invalid local recovery data was ignored. Review the saved scans before continuing.';
					}
				} catch (e) {
					pending = null;
				}
			}
			render();
			loading = refresh();
			return loading;
		}
		function uuid() {
			return global.crypto.randomUUID
				? global.crypto.randomUUID()
				: Array.from(global.crypto.getRandomValues(new Uint32Array(4)))
						.map(function (n) {
							return n.toString(16);
						})
						.join('-');
		}
		function change(path, body, operationId, kind) {
			if (busy || pending)
				return Promise.reject(
					new Error('Verify the pending operation first.'),
				);
			var owner = generation,
				url = endpoint() + path;
			busy = true;
			savePending({
				url: url,
				body: body,
				operation_id: operationId,
				kind: kind,
			});
			if (!form) render();
			else lockForm(true);
			return request(url, 'POST', body)
				.then(function (data) {
					if (owner !== generation)
						throw new Error('Session changed.');
					var op = data.operation || data;
					if (kind !== 'selection' && !operationOK(op))
						throw new Error(
							'Operation response could not be verified.',
						);
					savePending(null);
					editingCollection = false;
					status =
						kind === 'curate'
							? 'Permanent product link saved. Confirm collection below.'
							: 'Saved.';
					if (kind !== 'scan' && options.onChanged)
						options.onChanged();
					return data;
				})
				.catch(function (error) {
					if (owner === generation) {
						if (error.status && error.status < 500) {
							savePending(null);
							status = error.message;
						} else
							status =
								'The response was lost. Check the saved operation before trying again.';
					}
					throw error;
				})
				.finally(function () {
					if (owner === generation) {
						busy = false;
						if (!form) render();
						else lockForm(false);
					}
				});
		}
		function lockForm(disabled) {
			content
				.querySelectorAll(
					'.grocy-ai-shopping-curation input,.grocy-ai-shopping-curation select,.grocy-ai-shopping-curation button',
				)
				.forEach(function (el) {
					el.disabled = disabled;
				});
		}
		function action(work) {
			return Promise.resolve()
				.then(work)
				.catch(function (e) {
					status = e.message || 'Operation failed.';
				})
				.finally(function () {
					if (!form) refresh();
				});
		}
		function recover(retry) {
			if (!pending || busy) return;
			var saved = pending,
				owner = generation;
			busy = true;
			render();
			var url =
				saved.kind === 'selection'
					? endpoint()
					: endpoint() +
						'/operations/' +
						encodeURIComponent(saved.operation_id);
			var work = retry
				? request(saved.url, 'POST', saved.body)
				: request(url);
			return work
				.then(function (data) {
					if (owner !== generation) return;
					var op = data.operation || data;
					if (
						saved.kind === 'selection'
							? !validModel(data)
							: !operationOK(op)
					)
						throw new Error(
							'Recovery response could not be verified.',
						);

					var verified =
						saved.kind === 'curate'
							? !!op.product_id
							: saved.kind === 'settle' || saved.kind === 'done'
								? op.state === 'settled'
								: saved.kind === 'undo'
									? op.state === 'undone'
									: saved.kind === 'selection'
										? data.selected_list_id ===
											saved.body.list_id
										: true;
					if (!verified) {
						status =
							'This action is not yet verified. Retry the same operation safely; your confirmation is preserved.';
						render();
						return;
					}
					savePending(null);
					form = null;
					status =
						'Saved operation verified. Review its current state below.';
					if (options.onChanged) options.onChanged();
				})
				.catch(function (e) {
					if (owner === generation)
						status =
							e.status === 404
								? 'No saved operation found. Retry the same operation safely.'
								: e.message;
				})
				.finally(function () {
					if (owner === generation) {
						busy = false;
						if (pending) render();
						refresh();
					}
				});
		}
		function scan(barcode) {
			return Promise.resolve(loading).then(function () {
				if (denied || (model && model.selected_list_id === null))
					return options.fallbackScan(barcode);
				if (!model || busy || pending || form)
					throw new Error(
						'Finish or verify the shopping list operation before scanning.',
					);
				var row = rowFor(target),
					id = uuid();
				return change(
					'/scan',
					{
						operation_id: id,
						barcode: barcode,
						row_id: row ? row.id : null,
						expected_version: row ? targetVersion : null,
					},
					id,
					'scan',
				).then(function (op) {
					target = null;
					status =
						op.state === 'settled'
							? 'Scan saved and shopping list updated.'
							: 'Scan saved. Complete its list match below.';
					refresh();
					return op.line;
				});
			});
		}
		function settle(op, row, amount) {
			if (!row) {
				status = 'Choose an available entry from the original list.';
				render();
				return;
			}
			var body = { row_id: row.id, expected_version: row.version };
			if (amount !== undefined) body.amount = amount;
			return action(function () {
				return change(
					'/operations/' +
						encodeURIComponent(op.operation_id) +
						'/settle',
					body,
					op.operation_id,
					'settle',
				);
			});
		}
		function curation(op) {
			if (busy || pending || form) return;
			var owner = generation;
			busy = true;
			render();
			request(
				endpoint() +
					'/operations/' +
					encodeURIComponent(op.operation_id) +
					'/options',
			)
				.then(function (data) {
					if (owner !== generation) return;
					if (
						!Number.isInteger(data.revision) ||
						!Array.isArray(data.products) ||
						!Array.isArray(data.locations) ||
						!Array.isArray(data.quantity_units)
					)
						throw new Error('Curation options unavailable.');
					form = { op: op, options: data, row: rowFor(op.row_id) };
					render();
				})
				.catch(function (e) {
					status = e.message;
				})
				.finally(function () {
					if (owner === generation) {
						busy = false;
						render();
					}
				});
		}
		function renderForm(parent) {
			var context = form,
				op = context.op,
				row = context.row,
				catalog = context.options;
			if (!row) {
				form = null;
				status =
					'Return to the original shopping list to curate this UPC.';
				return;
			}
			var box = node('form', undefined, parent);
			box.className = 'grocy-ai-shopping-curation';
			node('h4', 'Curate UPC for ' + row.name, box);
			node(
				'p',
				'UPC ' +
					op.line.scanned_barcode +
					' — catalog approval is permanent. Stock is unchanged.',
				box,
			);
			var mode = field(box, 'Curation action', 'select');
			choice(mode, 'link', 'Link an existing product');
			if (
				row.product_id &&
				!catalog.products.some(function (p) {
					return p.id === row.product_id && p.parent_product_id;
				})
			)
				choice(mode, 'create', 'Create a branded child');
			var existing = field(box, 'Existing product', 'select');
			catalog.products
				.filter(function (p) {
					return (
						!row.has_children ||
						p.parent_product_id === row.product_id
					);
				})
				.forEach(function (p) {
					choice(existing, p.id, p.name);
				});
			var createFields = node('div', undefined, box);
			createFields.hidden = true;
			var name = field(createFields, 'Product name', 'text');
			name.maxLength = 200;
			function select(label, values) {
				var s = field(createFields, label, 'select');
				values.forEach(function (v) {
					choice(s, v.id, v.name);
				});
				return s;
			}
			var location = select('Inventory location', catalog.locations),
				purchase = select('Purchase unit', catalog.quantity_units),
				stock = select('Stock unit', catalog.quantity_units);
			var group = field(createFields, 'Product group', 'select');
			choice(group, '', 'No group');
			(catalog.product_groups || []).forEach(function (v) {
				choice(group, v.id, v.name);
			});
			var taxonomy = field(createFields, 'Food classification', 'select');
			choice(taxonomy, '', 'No classification');
			(catalog.taxonomy_leaves || []).forEach(function (v) {
				choice(taxonomy, v.slug, v.label);
			});
			var parent = catalog.products.find(function (p) {
				return p.id === row.product_id;
			});
			if (parent) {
				purchase.value = String(parent.qu_id_purchase);
				stock.value = String(parent.qu_id_stock);
			}
			var submit = node('button', 'Confirm permanent UPC link', box);
			submit.type = 'submit';
			submit.className = 'btn btn-primary';
			submit.disabled = busy || !!pending;
			var error = node('p', '', box);
			error.setAttribute('role', 'alert');
			mode.addEventListener('change', function () {
				createFields.hidden = mode.value !== 'create';
				existing.parentNode.hidden = mode.value !== 'link';
				name.required = mode.value === 'create';
				submit.textContent =
					mode.value === 'create'
						? 'Approve branded product and UPC'
						: 'Confirm permanent UPC link';
			});
			button(box, 'Cancel curation', function () {
				form = null;
				render();
			});
			var savedFields = [
				mode,
				existing,
				name,
				location,
				purchase,
				stock,
				group,
				taxonomy,
			];
			if (context.values)
				savedFields.forEach(function (el, i) {
					el.value = context.values[i];
				});
			mode.dispatchEvent(new Event('change'));
			box.addEventListener('input', function () {
				context.values = savedFields.map(function (el) {
					return el.value;
				});
			});
			box.addEventListener('change', function () {
				context.values = savedFields.map(function (el) {
					return el.value;
				});
			});
			box.addEventListener('submit', function (event) {
				event.preventDefault();
				if (busy || pending) return;
				var body = {
					mode: mode.value,
					revision: catalog.revision,
					expected_version: row.version,
				};
				if (mode.value === 'link') {
					if (!existing.value) {
						error.textContent =
							'Choose an existing product or create a branded child.';
						return;
					}
					body.product_id = Number(existing.value);
				} else {
					body.fields = {
						name: name.value.trim(),
						location_id: Number(location.value),
						qu_id_purchase: Number(purchase.value),
						qu_id_stock: Number(stock.value),
						parent_mode: 'existing',
						parent_product_id: row.product_id,
					};
					if (group.value)
						body.fields.product_group_id = Number(group.value);
					if (taxonomy.value)
						body.fields.taxonomy_leaf_slug = taxonomy.value;
				}
				submit.disabled = true;
				change(
					'/operations/' +
						encodeURIComponent(op.operation_id) +
						'/curate',
					body,
					op.operation_id,
					'curate',
				)
					.then(function (data) {
						form = null;
						return refresh().then(function () {
							return settle(data.operation, rowFor(op.row_id));
						});
					})
					.catch(function (e) {
						error.textContent = e.message;
						submit.disabled = false;
					})
					.finally(function () {
						if (!form || pending) render();
					});
			});
			setTimeout(function () {
				if (form === context) mode.focus();
			}, 0);
		}
		function renderOperation(op, parent) {
			if (op.state === 'undone') return;
			var box = node('div', undefined, parent);
			box.className = 'grocy-ai-shopping-operation';
			var row = rowFor(op.row_id);
			node(
				'p',
				(op.line ? 'UPC ' + op.line.scanned_barcode : 'List entry') +
					' · ' +
					op.state.replaceAll('_', ' '),
				box,
			);
			if (op.state === 'settled') {
				button(box, 'Undo collection', function () {
					action(function () {
						return change(
							'/operations/' +
								encodeURIComponent(op.operation_id) +
								'/undo',
							{},
							op.operation_id,
							'undo',
						);
					});
				});
				return;
			}
			if (
				op.state === 'awaiting_curation' &&
				!op.product_id &&
				row &&
				(!model.permissions || model.permissions.curate)
			)
				button(box, 'Curate UPC', function () {
					curation(op);
				});
			else if (
				op.state === 'awaiting_curation' &&
				!op.product_id &&
				model.permissions &&
				!model.permissions.curate
			)
				node('p', 'An authorized reviewer must approve this UPC.', box);
			if (op.product_id) {
				var select = field(box, 'Match list item', 'select');
				choice(select, '', 'Choose an entry');
				model.items.forEach(function (r) {
					choice(select, r.id, r.name);
				});
				if (row) select.value = String(row.id);
				var amount = null;
				if (op.needs_amount) {
					amount = field(
						box,
						'Collected amount in ' +
							((row && row.unit) || 'list units'),
						'number',
					);
					amount.min = '0.000001';
					amount.step = 'any';
				}

				select.addEventListener('change', function () {
					editingCollection = true;
					if (amount) {
						var chosen = rowFor(select.value);
						var label =
							'Collected amount in ' +
							((chosen && chosen.unit) || 'list units');
						amount.parentNode.querySelector('span').textContent =
							label;
						amount.setAttribute('aria-label', label);
						amount.value = '';
					}
				});
				if (amount)
					amount.addEventListener('input', function () {
						editingCollection = true;
					});
				button(box, 'Apply to list', function () {
					var r = rowFor(select.value);
					var value = amount ? Number(amount.value) : undefined;
					if (amount && (!Number.isFinite(value) || value <= 0)) {
						status = 'Enter a positive collected amount.';
						render();
						return;
					}
					settle(op, r, value);
				});
			} else if (!row)
				node(
					'p',
					'Review this unknown UPC or select a target before the next scan.',
					box,
				);
		}
		function render() {
			targetBanner.textContent =
				target && rowFor(target)
					? 'Next scan: ' + rowFor(target).name
					: '';
			targetBanner.hidden = !target;
			content.textContent = '';
			var message = node('p', status, content);
			message.id = 'grocyai-shopping-status';
			message.setAttribute('role', 'status');
			message.setAttribute('aria-live', 'polite');
			if (!trip) {
				node(
					'p',
					'Open a capture session to view a shopping list.',
					content,
				);
				return;
			}
			if (pending) {
				node(
					'p',
					'Verify the pending operation before scanning or leaving.',
					content,
				);
				var check = button(
					content,
					'Check saved operation',
					function () {
						recover(false);
					},
				);
				check.disabled = busy;
				var retry = button(
					content,
					'Retry same operation',
					function () {
						recover(true);
					},
				);
				retry.disabled = busy;
			}
			if (form) {
				renderForm(content);
				return;
			}
			if (!model) {
				if (!denied)
					button(
						content,
						'Refresh shopping list',
						function () {
							loading = refresh();
						},
						busy,
					);
				return;
			}
			var list = field(content, 'Shopping list', 'select');
			choice(list, '', 'No shopping list');
			model.lists.forEach(function (v) {
				choice(list, v.id, v.name);
			});
			list.value =
				model.selected_list_id === null
					? ''
					: String(model.selected_list_id);
			list.disabled = busy || !!pending || !model.can_change_list;
			list.addEventListener('change', function () {
				var next = list.value ? Number(list.value) : null;
				if (
					model.operations.length &&
					!global.confirm(
						'Change shopping lists? Previous collections and UPC links remain saved.',
					)
				) {
					render();
					return;
				}
				target = null;
				action(function () {
					return change(
						'/selection',
						{ list_id: next, confirm_change: true },
						null,
						'selection',
					);
				});
			});
			button(content, 'Refresh shopping list', function () {
				loading = refresh();
			});
			if (model.selected_list_id === null) return;
			if (
				!model.lists.some(function (l) {
					return l.id === model.selected_list_id;
				})
			) {
				node(
					'p',
					'This shopping list was deleted. Choose another list.',
					content,
				);
				return;
			}
			node(
				'p',
				target
					? 'Next scan: ' + (rowFor(target) || {}).name
					: 'Scan normally or choose an item first.',
				content,
			);
			if (target)
				button(content, 'Clear scan target', function () {
					target = null;
					render();
				});
			var rows = node('div', undefined, content);
			rows.className = 'grocy-ai-shopping-rows';
			model.items.forEach(function (r) {
				var item = node('section', undefined, rows);
				item.className = 'grocy-ai-shopping-row';
				item.setAttribute('data-shopping-row', String(r.id));
				node('h4', r.name, item);
				node(
					'p',
					r.collected_amount +
						' of ' +
						(r.amount === null
							? 'quantity needing confirmation'
							: r.amount) +
						' ' +
						(r.unit || 'list units') +
						' · ' +
						(r.done ? 'Collected' : 'Remaining'),
					item,
				);
				if (r.note && r.note !== r.name) node('p', r.note, item);
				button(
					item,
					'Scan for ' + r.name,
					function () {
						target = r.id;
						targetVersion = r.version;
						render();
						var scanHeading = document.getElementById(
							'grocyai-capture-scan-heading',
						);
						if (scanHeading)
							scanHeading.scrollIntoView({ block: 'start' });
						var scanButton = document.getElementById(
							'camerabarcodescanner-start-button',
						);
						if (scanButton)
							scanButton.focus({ preventScroll: true });
					},
					model.permissions && !model.permissions.collect,
				);
				button(
					item,
					(r.done ? 'Mark remaining: ' : 'Mark collected: ') + r.name,
					function () {
						var id = uuid();
						action(function () {
							return change(
								'/done',
								{
									operation_id: id,
									row_id: r.id,
									expected_version: r.version,
									done: !r.done,
								},
								id,
								'done',
							);
						});
					},
					model.permissions && !model.permissions.collect,
				);
			});
			var operations = node('details', undefined, content);
			operations.open = model.operations.length > 0;
			node('summary', 'Collection actions and Undo', operations);
			model.operations.forEach(function (op) {
				renderOperation(op, operations);
			});
		}
		setInterval(function () {
			if (
				trip &&
				panel.open &&
				!document.hidden &&
				!busy &&
				!pending &&
				!form &&
				!editingCollection &&
				!content.contains(document.activeElement)
			)
				refresh();
		}, 15000);
		return {
			load: load,
			tripId: function () {
				return trip;
			},
			scan: scan,
			hasPendingWrites: function () {
				return busy || !!pending;
			},
			hasUnsavedDrafts: function () {
				return !!form || editingCollection;
			},
			hasCollections: function () {
				return !!(model && model.operations.length);
			},
		};
	}
	global.GrocyAiShoppingCapture = { attach: attach };
})(window);
