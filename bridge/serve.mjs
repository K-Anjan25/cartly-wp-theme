/**
 * PayKaro — Node HTTP bridge for the sandbox demo.
 *
 * Runs the real PHP app (public/index.php) inside @php-wasm/node, translating
 * browser HTTP requests into PHP superglobals and the app's JSON response back
 * into real HTTP (status, headers, cookies, body). Static assets (app.css) are
 * served straight off disk.
 */

import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT      = path.resolve(__dirname, '..');
const ASSETS    = path.join(ROOT, 'public', 'assets');
const BOOT      = path.join(__dirname, 'boot.php');

const PORT = Number(process.env.PORT || 8080);
const HOST = '0.0.0.0';

/* ------------------------------------------------------------------ */
/* PHP runtime                                                        */
/* ------------------------------------------------------------------ */

// Every request gets a fresh PHP runtime + process, so no superglobal / session /
// function-definition state leaks between requests. The SQLite file on the host
// filesystem is what carries persistent state (users, sessions, invoices).
let chain = Promise.resolve();
function runPhp(args) {
	const job = chain.then(async () => {
		const rt  = await loadNodeRuntime('8.3');
		const php = new PHP(rt);
		useHostFilesystem(php);
		return php.cli(args);
	});
	chain = job.then(() => {}, () => {});
	return job;
}

function drain(stream) {
	if (!stream) return Promise.resolve('');
	try {
		if (typeof stream === 'string') return Promise.resolve(stream);
		if (typeof stream.text === 'function') return stream.text();
		if (typeof stream.getReader === 'function') {
			const reader = stream.getReader();
			const dec = new TextDecoder();
			let s = '';
			return (async () => {
				while (true) {
					const { done, value } = await reader.read();
					if (done) break;
					s += dec.decode(value, { stream: true });
				}
				return s;
			})();
		}
		if (typeof stream.pipe === 'function') {
			return new Promise((res) => {
				const chunks = [];
				stream.on('data', (c) => chunks.push(c));
				stream.on('end', () => res(Buffer.concat(chunks).toString()));
				stream.on('error', () => res(''));
			});
		}
		return Promise.resolve(String(stream));
	} catch (e) {
		return Promise.resolve('');
	}
}

async function runApp(requestCtx) {
	const result = await runPhp(['php', BOOT, JSON.stringify(requestCtx)]);
	const stdout = await drain(result && result.stdout);
	const stderr = await drain(result && result.stderr);
	if (stderr && stderr.trim()) {
		// Warnings / fatal errors surface here. Non-fatal for a demo, but log them.
		console.error('[php]', stderr.slice(0, 500));
	}
	try {
		return JSON.parse(stdout);
	} catch (e) {
		console.error('[bridge] could not parse PHP response:', stdout.slice(0, 300));
		return { status: 500, type: 'text/html; charset=utf-8', body: '<pre>Internal bridge error</pre>' };
	}
}

/* ------------------------------------------------------------------ */
/* Request helpers                                                    */
/* ------------------------------------------------------------------ */

function parseCookies(header) {
	const out = {};
	if (!header) return out;
	for (const part of String(header).split(';')) {
		const idx = part.indexOf('=');
		if (idx <= 0) continue;
		const k = part.slice(0, idx).trim();
		const v = part.slice(idx + 1).trim();
		if (k) out[k] = decodeURIComponent(v);
	}
	return out;
}

function parseBody(req) {
	return new Promise((resolve) => {
		const chunks = [];
		let size = 0;
		req.on('data', (c) => {
			size += c.length;
			if (size > 1_000_000) { req.destroy(); return; }
			chunks.push(c);
		});
		req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
		req.on('error', () => resolve(''));
	});
}

function parsePost(raw, contentType) {
	const ct = (contentType || '').toLowerCase();
	if (ct.includes('application/json')) {
		try { return JSON.parse(raw); } catch (e) { return {}; }
	}
	const params = new URLSearchParams(raw || '');
	const post = {};
	for (const [k, v] of params.entries()) post[k] = v;
	return post;
}

function send(res, j) {
	res.statusCode = j.status || 200;
	if (j.type) res.setHeader('Content-Type', j.type);
	if (j.location) {
		res.setHeader('Location', j.location);
	}
	if (Array.isArray(j.cookies)) {
		for (const c of j.cookies) {
			if (!c || !c[0]) continue;
			const [name, value, ttl] = c;
			const maxAge = Number(ttl) > 0 ? `Max-Age=${Math.floor(Number(ttl))}` : 'Max-Age=0';
			const cookie = `${name}=${value ? encodeURIComponent(value) : ''}; Path=/; HttpOnly; SameSite=Lax; ${maxAge}`;
			const existing = res.getHeader('Set-Cookie');
			res.setHeader('Set-Cookie', (existing ? [].concat(existing) : []).concat(cookie));
		}
	}
	// Allow the live preview to frame this demo app.
	res.setHeader('Content-Security-Policy', "frame-ancestors * 'self'");
	const body = j.body || '';
	res.setHeader('Content-Length', Buffer.byteLength(body));
	res.end(body);
}

function serveFile(res, filePath) {
	const ext = path.extname(filePath).toLowerCase();
	const types = { '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };
	try {
		const data = fs.readFileSync(filePath);
		res.statusCode = 200;
		res.setHeader('Content-Type', types[ext] || 'application/octet-stream');
		res.setHeader('Cache-Control', 'no-cache');
		res.setHeader('Content-Length', data.length);
		res.end(data);
	} catch (e) {
		res.statusCode = 404;
		res.setHeader('Content-Type', 'text/plain; charset=utf-8');
		res.end('Not found');
	}
}

/* ------------------------------------------------------------------ */
/* HTTP server                                                        */
/* ------------------------------------------------------------------ */

const server = http.createServer(async (req, res) => {
	try {
		const rawUrl = req.url || '/';
		const url = new URL(rawUrl, 'http://x');
		const pathname = url.pathname;
		const host = req.headers['x-forwarded-host'] || req.headers.host || 'localhost';

		// Static assets.
		if (pathname.startsWith('/assets/')) {
			const rel = pathname.replace('/assets/', '');
			serveFile(res, path.join(ASSETS, rel));
			return;
		}

		const query = {};
		for (const [k, v] of url.searchParams.entries()) query[k] = v;

		const rawBody = await parseBody(req);
		const post = parsePost(rawBody, req.headers['content-type']);

		// Reproduce config.php's getenv() reads inside the fresh PHP process.
		const env = {};
		for (const k of ['GOOGLE_CLIENT_ID','GOOGLE_CLIENT_SECRET','GOOGLE_REDIRECT_URI','PAYKARO_DB_DSN','PAYKARO_DB_USER','PAYKARO_DB_PASS']) {
			if (process.env[k]) env[k] = process.env[k];
		}

		const requestCtx = {
			method: req.method || 'GET',
			uri: rawUrl,
			query,
			post,
			cookies: parseCookies(req.headers.cookie),
			host,
			env,
		};

		const j = await runApp(requestCtx);
		send(res, j);
	} catch (e) {
		console.error('[bridge] error:', e && e.message);
		res.statusCode = 500;
		res.setHeader('Content-Type', 'text/plain; charset=utf-8');
		res.end('Server error: ' + ((e && e.message) || ''));
	}
});

server.listen(PORT, HOST, () => {
	console.log(`PayKaro bridge listening on http://${HOST}:${PORT}`);
});
