/**
 * Lint every PHP file in the repo using the php-wasm runtime (same runtime the
 * bridge uses), since no native PHP is available in this sandbox.
 *
 * Usage: VITEST=1 node lint.mjs <file.php> [file2.php ...]
 * (VITEST=1 is required — this @php-wasm/node version only assigns a processId
 * when that env var is present, otherwise the wasm runtime refuses to init.)
 */
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');

const files = process.argv.slice(2);
let failures = 0;

async function drain(s) {
	if (!s) return '';
	try {
		if (typeof s === 'string') return s;
		if (typeof s.text === 'function') return await s.text();
		if (s.getReader) {
			const r = s.getReader();
			const dec = new TextDecoder();
			let out = '';
			for (;;) {
				const { done, value } = await r.read();
				if (done) break;
				out += dec.decode(value, { stream: true });
			}
			return out;
		}
	} catch (e) {
		return `[drain error: ${e?.message}]`;
	}
	return String(s);
}

for (const file of files) {
	const abs = path.resolve(process.cwd(), file);
	const rel = path.relative(ROOT, abs);
	let ok = false;
	let out = '';
	let err = '';
	try {
		const rt = await loadNodeRuntime('8.3');
		const php = new PHP(rt);
		useHostFilesystem(php);
		const result = await php.cli(['php', '-l', abs]);
		const code = await result.exitCode;
		ok = code === 0;
		out = await drain(result.stdout);
		err = await drain(result.stderr);
	} catch (e) {
		ok = false;
		err = e?.message || String(e);
	}
	if (!ok) failures++;
	console.log(`[lint] ${rel} -> ${ok ? 'OK' : 'FAIL'}`);
	const text = (out + (err ? '\n' + err : '')).trim();
	if (!ok && text) console.log(text);
}
console.log(`\n${files.length} file(s) linted, ${failures} failure(s)`);
process.exit(failures ? 1 : 0);
