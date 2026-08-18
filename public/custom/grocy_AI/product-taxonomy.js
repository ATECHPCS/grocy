(function ()
{
	'use strict';

	var root = document.getElementById('grocy-ai-product-taxonomy');
	if (!root)
	{
		return;
	}

	var productId = root.getAttribute('data-product-id');
	if (!/^[1-9][0-9]{0,9}$/.test(productId || ''))
	{
		return;
	}

	var groups = [
		{ label: 'Pantry', leaves: [['baking', 'Baking'], ['beverages', 'Beverages'], ['grains-pasta', 'Grains & pasta'], ['snacks', 'Snacks']] },
		{ label: 'Fresh food', leaves: [['dairy-eggs', 'Dairy & eggs'], ['meat-seafood', 'Meat & seafood'], ['produce', 'Produce']] },
		{ label: 'Cooking basics', leaves: [['condiments', 'Condiments'], ['oils-vinegars', 'Oils & vinegars']] }
	];
	var groupSelect = document.getElementById('grocy-ai-taxonomy-group');
	var leaves = document.getElementById('grocy-ai-taxonomy-leaves');
	var current = document.getElementById('grocy-ai-taxonomy-current');
	var evidence = document.getElementById('grocy-ai-taxonomy-evidence-body');
	var status = document.getElementById('grocy-ai-taxonomy-status');
	var assign = document.getElementById('grocy-ai-taxonomy-assign');
	var unclassified = document.getElementById('grocy-ai-taxonomy-unclassified');
	var rulesetVersion = null;

	function element(tag, text)
	{
		var node = document.createElement(tag);
		node.textContent = text;
		return node;
	}

	function setBusy(busy)
	{
		groupSelect.disabled = busy;
		assign.disabled = busy;
		unclassified.disabled = busy;
		Array.prototype.forEach.call(leaves.querySelectorAll('input'), function (input) { input.disabled = busy; });
	}

	function renderLeaves(selectedSlug)
	{
		leaves.textContent = '';
		groups[Number(groupSelect.value)].leaves.forEach(function (leaf)
		{
			var control = element('div', '');
			control.className = 'custom-control custom-radio grocy-ai-taxonomy-choice';
			var input = document.createElement('input');
			input.type = 'radio';
			input.name = 'grocy-ai-taxonomy-leaf';
			input.id = 'grocy-ai-taxonomy-' + leaf[0];
			input.value = leaf[0];
			input.className = 'custom-control-input';
			input.checked = selectedSlug === leaf[0];
			var label = element('label', leaf[1]);
			label.className = 'custom-control-label';
			label.htmlFor = input.id;
			control.appendChild(input);
			control.appendChild(label);
			leaves.appendChild(control);
		});
	}

	function render(data)
	{
		rulesetVersion = data.ruleset_version;
		current.textContent = '';
		var heading = element('p', data.current_leaf ? 'Current: ' + data.current_leaf.label : 'Current: Unclassified');
		heading.className = 'grocy-ai-taxonomy-current';
		current.appendChild(heading);
		evidence.textContent = '';
		if (data.suggested_leaf)
		{
			evidence.appendChild(element('p', data.suggested_leaf.label + ' · ' + data.confidence_band + ' confidence'));
			evidence.appendChild(element('p', 'Provider category: ' + data.provider_category + ' · Ruleset: ' + data.ruleset_version));
			evidence.appendChild(element('p', 'Reason: ' + data.reason_code));
		}
		else
		{
			evidence.appendChild(element('strong', root.getAttribute('data-empty-title')));
			evidence.appendChild(element('p', root.getAttribute('data-empty-body')));
		}
		var selected = data.current_leaf || data.suggested_leaf;
		var index = groups.findIndex(function (group) { return group.leaves.some(function (leaf) { return selected && leaf[0] === selected.slug; }); });
		groupSelect.value = String(index < 0 ? 0 : index);
		renderLeaves(selected ? selected.slug : null);
	}

	function request(method, body, done, failed)
	{
		var xhr = new XMLHttpRequest();
		xhr.open(method, '/api/grocy-ai/products/' + productId + '/taxonomy');
		xhr.setRequestHeader('Accept', 'application/json');
		if (body !== null) xhr.setRequestHeader('Content-Type', 'application/json');
		xhr.onload = function ()
		{
			try
			{
				var response = JSON.parse(xhr.responseText);
				if (xhr.status >= 200 && xhr.status < 300) return done(response);
			}
			catch (error) { }
			failed();
		};
		xhr.onerror = failed;
		xhr.send(body === null ? null : JSON.stringify(body));
	}

	function save(payload)
	{
		if (!rulesetVersion) return;
		setBusy(true);
		request('PUT', payload, function (data)
		{
			render(data);
			status.className = 'alert alert-success';
			status.textContent = root.getAttribute('data-save-success');
			setBusy(false);
			document.getElementById('grocy-ai-taxonomy-heading').focus();
		}, function ()
		{
			status.className = 'alert alert-danger';
			status.textContent = root.getAttribute('data-save-error');
			setBusy(false);
		});
	}

	groups.forEach(function (group, index) { groupSelect.appendChild(new Option(group.label, String(index))); });
	groupSelect.addEventListener('change', function () { renderLeaves(null); });
	assign.addEventListener('click', function ()
	{
		var selected = leaves.querySelector('input:checked');
		if (selected) save({ leaf_slug: selected.value, ruleset_version: rulesetVersion });
	});
	unclassified.addEventListener('click', function () { save({ unclassified: true, ruleset_version: rulesetVersion }); });
	request('GET', null, render, function ()
	{
		status.className = 'alert alert-danger';
		status.textContent = root.getAttribute('data-save-error');
	});
}());
