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

const allowlistedFiles = new Map([
	['/fixtures/productform.html', {
		path: resolve(browserRoot, 'fixtures/productform.html'),
		contentType: 'text/html; charset=utf-8'
	}],
	['/assets/product-enrichment.js', {
		path: resolve(repositoryRoot, 'public/custom/grocy_AI/product-enrichment.js'),
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

	const allowed = allowlistedFiles.get(pathname);
	if (!allowed)
	{
		sendText(response, 404, 'Not found');
		return;
	}

	const allowedRoot = pathname.startsWith('/fixtures/')
		? resolve(browserRoot, 'fixtures')
		: resolve(repositoryRoot, 'public/custom/grocy_AI');
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
