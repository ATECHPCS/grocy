import { createReadStream } from 'node:fs';
import { stat } from 'node:fs/promises';
import { createServer } from 'node:http';
import { dirname, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const host = '127.0.0.1';
const port = Number.parseInt(process.env.GROCY_AI_BROWSER_PORT || '4173', 10);
const supportDirectory = dirname(fileURLToPath(import.meta.url));
const browserRoot = resolve(supportDirectory, '..');
const repositoryRoot = resolve(supportDirectory, '../../../../..');
const mediaCounts = { thumbnail: 0, full: 0 };
const pngBytes = Buffer.concat([
	Buffer.from('89504e470d0a1a0a', 'hex'),
	Buffer.alloc(2492, 0x78)
]);
const conversionCounts = {
	status: 0,
	nativeProductPost: 0,
	nativeProductPut: 0,
	nativeUniversalPost: 0,
	nativeUniversalPut: 0,
	activation: 0,
	projection: 0,
	cache: 0,
	unknownApi: 0
};
const conversionState = { rulesetActivated: false };

function conversionStatusEnvelope(productId)
{
	const base = {
		status: 'unavailable',
		blockers: ['profile_unavailable'],
		factor: null,
		dimension: null,
		approximate: null,
		winner_source: null,
		source_name: null,
		source_version: null,
		source_status: null,
		source_item_id: null,
		profile_key: null,
		taxonomy_leaf: null,
		precedence: 'product_override>food_profile>universal',
		inactive_revision_id: null
	};
	if (productId === '91')
	{
		return Object.assign(base, {
			status: 'product_native', blockers: [], factor: '236.588', dimension: 'product_scoped',
			approximate: false, winner_source: 'product_override', source_name: 'Grocy native product conversion',
			source_status: 'native'
		});
	}
	if (productId === '92')
	{
		return Object.assign(base, {
			status: 'inactive', blockers: [], factor: null, dimension: 'volume', approximate: true,
			winner_source: 'food_profile', source_name: 'USDA FoodData Central', source_version: 'FDC-2024-10',
			source_status: 'inactive', source_item_id: '171265', profile_key: 'whole-milk', taxonomy_leaf: 'dairy-eggs',
			inactive_revision_id: 'conversion-profile-v1'
		});
	}
	if (productId === '93')
	{
		return Object.assign(base, { blockers: ['explicit_taxonomy_required'] });
	}
	if (productId === '94')
	{
		return Object.assign(base, { status: 'blocked', blockers: ['same_rank_collision'] });
	}
	return base;
}

const mediaCapabilities = new Map([
	['thumbnail/thumbnail_front_capability_0001', 'thumbnail'],
	['full/full_front_capability_0000000001', 'full'],
	['thumbnail/thumbnail_search_capability_001', 'thumbnail'],
	['full/full_search_capability_000000001', 'full']
]);

const allowlistedFiles = new Map([
	['/fixtures/productform.html', {
		path: resolve(browserRoot, 'fixtures/productform.html'),
		contentType: 'text/html; charset=utf-8'
	}],
	['/fixtures/quantityunitconversionform.html', {
		path: resolve(browserRoot, 'fixtures/quantityunitconversionform.html'),
		contentType: 'text/html; charset=utf-8'
	}],
	['/assets/quantityunitconversionform.js', {
		path: resolve(repositoryRoot, 'public/viewjs/quantityunitconversionform.js'),
		root: resolve(repositoryRoot, 'public/viewjs'),
		contentType: 'text/javascript; charset=utf-8'
	}],
	['/assets/product-enrichment.js', {
		path: resolve(repositoryRoot, 'public/custom/grocy_AI/product-enrichment.js'),
		contentType: 'text/javascript; charset=utf-8'
	}],
	['/assets/product-taxonomy.js', {
		path: resolve(repositoryRoot, 'public/custom/grocy_AI/product-taxonomy.js'),
		contentType: 'text/javascript; charset=utf-8'
	}],
	['/assets/conversion-explanations.js', {
		path: resolve(repositoryRoot, 'public/custom/grocy_AI/conversion-explanations.js'),
		contentType: 'text/javascript; charset=utf-8'
	}],
	['/assets/grocy-ai.css', {
		path: resolve(repositoryRoot, 'public/custom/grocy_AI/grocy-ai.css'),
		contentType: 'text/css; charset=utf-8'
	}]
]);

function isInsideRoot(filePath, rootPath)
{
	return filePath === rootPath || filePath.startsWith(rootPath + sep);
}

function hasTraversal(rawUrl)
{
	try
	{
		return decodeURIComponent(rawUrl).split(/[\\/]/).includes('..');
	}
	catch (error)
	{
		return true;
	}
}

function sendText(response, statusCode, body)
{
	response.writeHead(statusCode, {
		'Content-Type': 'text/plain; charset=utf-8',
		'Cache-Control': 'no-store'
	});
	response.end(body);
}

const server = createServer(async function (request, response)
{
	const rawUrl = request.url || '/';
	if (hasTraversal(rawUrl))
	{
		sendText(response, 403, 'Forbidden');
		return;
	}

	let pathname;
	try
	{
		pathname = new URL(rawUrl, `http://${host}:${port}`).pathname;
	}
	catch (error)
	{
		sendText(response, 400, 'Bad request');
		return;
	}

	if (pathname === '/health')
	{
		sendText(response, 200, 'ok');
		return;
	}
	if (pathname === '/__fixture/media-counts')
	{
		response.writeHead(200, {
			'Content-Type': 'application/json; charset=utf-8',
			'Cache-Control': 'no-store'
		});
		response.end(JSON.stringify(mediaCounts));
		return;
	}
	if (pathname === '/__fixture/reset-media-counts' && request.method === 'POST')
	{
		mediaCounts.thumbnail = 0;
		mediaCounts.full = 0;
		response.writeHead(204, { 'Cache-Control': 'no-store' });
		response.end();
		return;
	}
	if (pathname === '/__fixture/conversion-counts')
	{
		response.writeHead(200, {
			'Content-Type': 'application/json; charset=utf-8',
			'Cache-Control': 'no-store'
		});
		response.end(JSON.stringify(Object.assign({ rulesetActivated: conversionState.rulesetActivated }, conversionCounts)));
		return;
	}
	if (pathname === '/__fixture/reset-conversion-counts' && request.method === 'POST')
	{
		Object.keys(conversionCounts).forEach(function (key) { conversionCounts[key] = 0; });
		conversionState.rulesetActivated = false;
		response.writeHead(204, { 'Cache-Control': 'no-store' });
		response.end();
		return;
	}
	// Test-only activation of the reusable ruleset. It is deliberately outside /api so that a
	// product-page request can never reach it; the product UI must still make zero activation calls.
	if (pathname === '/__fixture/activate-ruleset' && request.method === 'POST')
	{
		conversionState.rulesetActivated = true;
		response.writeHead(204, { 'Cache-Control': 'no-store' });
		response.end();
		return;
	}

	const productStatusMatch = /^\/api\/grocy-ai\/products\/([1-9][0-9]{0,9})\/conversion-status$/.exec(pathname);
	if (productStatusMatch)
	{
		if (request.method !== 'GET')
		{
			conversionCounts.unknownApi++;
			sendText(response, 405, 'Method not allowed');
			return;
		}
		conversionCounts.status++;
		response.writeHead(200, {
			'Content-Type': 'application/json; charset=utf-8',
			'Cache-Control': 'no-store'
		});
		response.end(JSON.stringify(conversionStatusEnvelope(productStatusMatch[1])));
		return;
	}

	if (pathname === '/api/objects/quantity_unit_conversions' || pathname.startsWith('/api/objects/quantity_unit_conversions/'))
	{
		const scoped = (request.headers['x-fixture-conversion-scope'] || '') === 'product';
		if (request.method === 'POST')
		{
			conversionCounts[scoped ? 'nativeProductPost' : 'nativeUniversalPost']++;
		}
		else if (request.method === 'PUT')
		{
			conversionCounts[scoped ? 'nativeProductPut' : 'nativeUniversalPut']++;
		}
		else
		{
			conversionCounts.unknownApi++;
			sendText(response, 405, 'Method not allowed');
			return;
		}
		// Only a product-scoped native save is modelled as succeeding. A generic reusable-universal
		// write is rejected here so no browser fixture can imply that the bypass works.
		if (!scoped)
		{
			sendText(response, 400, 'Reusable universal conversions are rejected before native work');
			return;
		}
		response.writeHead(200, {
			'Content-Type': 'application/json; charset=utf-8',
			'Cache-Control': 'no-store'
		});
		response.end(JSON.stringify({ created_object_id: 4021 }));
		return;
	}

	if (pathname.startsWith('/api/grocy-ai/conversions/activate'))
	{
		conversionCounts.activation++;
		sendText(response, 403, 'Forbidden');
		return;
	}
	if (pathname.startsWith('/api/grocy-ai/conversions/project'))
	{
		conversionCounts.projection++;
		sendText(response, 403, 'Forbidden');
		return;
	}
	if (pathname.startsWith('/api/system/db-changed-time') || pathname.includes('quantity_unit_conversions_resolved'))
	{
		conversionCounts.cache++;
		sendText(response, 403, 'Forbidden');
		return;
	}

	if (pathname.startsWith('/api/grocy-ai/images/'))
	{
		const capabilityPath = pathname.slice('/api/grocy-ai/images/'.length);
		const variant = mediaCapabilities.get(capabilityPath);
		if (!variant)
		{
			sendText(response, 404, 'Image unavailable');
			return;
		}
		mediaCounts[variant]++;
		response.writeHead(200, {
			'Content-Type': 'image/png',
			'Content-Length': pngBytes.length,
			'Cache-Control': 'private, no-store',
			'X-Content-Type-Options': 'nosniff',
			'Content-Disposition': 'inline; filename="product-image.png"'
		});
		response.end(pngBytes);
		return;
	}

	const allowed = allowlistedFiles.get(pathname);
	if (!allowed)
	{
		// Default-deny counter for unclassified conversion, activation, projection, and cache surfaces.
		// Unrelated fixture reads (for example the Phase 3 taxonomy panel) are not conversion traffic.
		if (/^\/api\/.*(quantity_unit_conversion|\/conversions\/|activate|project|cache)/.test(pathname))
		{
			conversionCounts.unknownApi++;
		}
		sendText(response, 404, 'Not found');
		return;
	}

	const allowedRoot = allowed.root || (pathname.startsWith('/fixtures/')
		? resolve(browserRoot, 'fixtures')
		: resolve(repositoryRoot, 'public/custom/grocy_AI'));
	if (!isInsideRoot(allowed.path, allowedRoot))
	{
		sendText(response, 403, 'Forbidden');
		return;
	}

	try
	{
		const fileStat = await stat(allowed.path);
		if (!fileStat.isFile())
		{
			sendText(response, 404, 'Not found');
			return;
		}

		response.writeHead(200, {
			'Content-Type': allowed.contentType,
			'Content-Length': fileStat.size,
			'Cache-Control': 'no-store',
			'X-Content-Type-Options': 'nosniff'
		});
		createReadStream(allowed.path).pipe(response);
	}
	catch (error)
	{
		sendText(response, 500, 'Fixture asset unavailable');
	}
});

server.listen(port, host, function ()
{
	process.stdout.write(`grocy_AI browser fixture listening on http://${host}:${port}\n`);
});

function shutdown()
{
	server.close(function ()
	{
		process.exit(0);
	});
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
