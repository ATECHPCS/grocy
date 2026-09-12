(function ()
{
	'use strict';

	// Zero-write proof harness shared by capture.html and capture-review.html. The purchase-capture
	// surface may POST/PUT only to its own /api/grocy-ai/capture/** endpoints (which the mocked server
	// models as writing module trip tables, never stock). Any other mutation is a bug: it is recorded and
	// thrown so a test fails loudly instead of silently booking stock. GET/HEAD/OPTIONS are always allowed.
	window.__captureFixture = {
		calls: [],
		forbiddenWrite: null,
		captureWrites: 0
	};

	function classify(method, url)
	{
		method = String(method || 'GET').toUpperCase();
		var pathname;
		try
		{
			pathname = new URL(url, window.location.origin).pathname;
		}
		catch (error)
		{
			return;
		}
		window.__captureFixture.calls.push({ method: method, path: pathname });
		if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS')
		{
			return;
		}
		if (/^\/api\/grocy-ai\/capture\//.test(pathname))
		{
			window.__captureFixture.captureWrites++;
			return;
		}
		window.__captureFixture.forbiddenWrite = method + ' ' + pathname;
		throw new Error('capture fixture blocked non-capture write: ' + method + ' ' + pathname);
	}

	var nativeXhrOpen = window.XMLHttpRequest.prototype.open;
	window.XMLHttpRequest.prototype.open = function (method, url)
	{
		classify(method, url);
		return nativeXhrOpen.apply(this, arguments);
	};

	var nativeFetch = window.fetch.bind(window);
	window.fetch = function (input, init)
	{
		var url = input instanceof Request ? input.url : String(input);
		var method = (init && init.method) || (input instanceof Request && input.method) || 'GET';
		classify(method, url);
		return nativeFetch(input, init);
	};

	// Minimal jQuery-compatible shim: capture.js binds the core camera control's `Grocy.BarcodeScanned`
	// event on `$(document)` and treats a scan exactly like a manual add.
	function JQueryCompatible(target)
	{
		if (!(this instanceof JQueryCompatible))
		{
			return new JQueryCompatible(target);
		}
		this.elements = (target === window || target === document || target instanceof Element) ? [target] : [];
	}
	JQueryCompatible.prototype.on = function (eventName, handler)
	{
		this.elements.forEach(function (element)
		{
			element.addEventListener(eventName, function (event)
			{
				var args = Array.isArray(event.detail) ? event.detail : [];
				handler.apply(element, [event].concat(args));
			});
		});
		return this;
	};
	JQueryCompatible.prototype.trigger = function (eventName, args)
	{
		this.elements.forEach(function (element)
		{
			element.dispatchEvent(new CustomEvent(eventName, { bubbles: true, detail: args || [] }));
		});
		return this;
	};
	window.$ = window.jQuery = JQueryCompatible;

	window.Grocy = {
		Api: {},
		Components: { CameraBarcodeScanner: { CurrentTarget: 'grocyai-capture-barcode' } }
	};

	// The core camera control fires this after decoding; the page filters by the input's data-target.
	window.Grocy.BarcodeScanned = function (barcode, target)
	{
		window.$(document).trigger('Grocy.BarcodeScanned', [barcode, target]);
	};

	// Read-only reference lookups used by capture.js / capture-review.js (product names, locations, stores).
	window.Grocy.Api.Get = function (apiFunction, success, error)
	{
		var xhr = new XMLHttpRequest();
		xhr.onreadystatechange = function ()
		{
			if (xhr.readyState !== XMLHttpRequest.DONE)
			{
				return;
			}
			if (xhr.status === 200 || xhr.status === 204)
			{
				if (success)
				{
					success(xhr.status === 200 ? JSON.parse(xhr.responseText) : {});
				}
			}
			else if (error)
			{
				error(xhr);
			}
		};
		xhr.open('GET', '/api/' + apiFunction, true);
		xhr.send();
		return xhr;
	};

	// Writes never travel through Grocy.Api from these pages; wire them to the same default-deny guard.
	['Post', 'Put', 'Delete', 'UploadFile', 'DeleteFile'].forEach(function (methodName)
	{
		window.Grocy.Api[methodName] = function (apiFunction)
		{
			classify(methodName.toUpperCase(), '/api/' + apiFunction);
			throw new Error('Persistence is intentionally unavailable in the capture fixture');
		};
	});
})();
