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

async function main() {
	const rt = await loadNodeRuntime('8.3');
	const php = new PHP(rt);
	useHostFilesystem(php);
	const result = await php.cli(['php', SEED]);
	const out = await (result && result.stdout && result.stdout.text ? result.stdout.text() : '');
	const err = await (result && result.stderr && result.stderr.text ? result.stderr.text() : '');
	if (out) console.log((out || '').trim());
	if (err && err.trim()) console.error((err || '').trim());
}

main()
	.catch((e) => { console.error('[seed] error:', e && e.message); })
	.finally(() => process.exit(0));
