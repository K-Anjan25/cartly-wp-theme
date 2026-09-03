/**
 * PayKaro — seed helper for the sandbox (no native PHP needed).
 *
 * Runs bin/seed.php inside @php-wasm/node exactly like the HTTP bridge does,
 * writing the SQLite file to the host data/ directory. Idempotent.
 *
 *   cd bridge && node seed.mjs
 */

import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const SEED = path.join(ROOT, 'bin', 'seed.php');

/** Drain a php-wasm output stream (string / web stream / node stream). */
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

async function main() {
	const rt = await loadNodeRuntime('8.3');
	const php = new PHP(rt);
	useHostFilesystem(php);
	const result = await php.cli(['php', SEED]);
	const out = await drain(result && result.stdout);
	const err = await drain(result && result.stderr);
	if (out) console.log((out || '').trim());
	if (err && err.trim()) console.error((err || '').trim());
}

main()
	.catch((e) => { console.error('[seed] error:', e && e.message); })
	.finally(() => process.exit(0));
