// PayKaro — quick Node seeder for the sandbox (no native PHP / no php-wasm).
// Creates the SQLite database and seeds two demo tenants matching bin/seed.php.
//
// Usage:  node seed-node.mjs   (run from the repo root)
//
// Requires `better-sqlite3` and `bcryptjs` in node_modules. The repo's
// bridge/ folder already declares them, but they're also easy to install
// alongside:  npm i --no-save better-sqlite3 bcryptjs

import Database from 'better-sqlite3';
import bcrypt from 'bcryptjs';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DB_PATH = path.join(__dirname, 'data', 'paykaro.sqlite');
const SCHEMA = readFileSync(path.join(__dirname, 'schema.sql'), 'utf8');

const db = new Database(DB_PATH);
db.exec(SCHEMA);
console.log('Schema ensured.');

const userCount = db.prepare('SELECT COUNT(*) AS c FROM users').get().c;
if (userCount > 0) {
	console.log('Users already present; skipping demo seed.');
	process.exit(0);
}

const cfg = {
		msme_due_days: 45,
		default_tax_rate: 18,
		required_evidence: ['po', 'delivery_ack', 'grn', 'invoice_copy'],
	},
	pwd = (s) => bcrypt.hashSync(s, 10);

const d = (off) => {
	const t = new Date();
	t.setDate(t.getDate() + Number(off));
	return t.toISOString().slice(0, 10);
};

const insBiz = db.prepare(`INSERT INTO businesses (name,gstin,pan,udyam_no,bank_name,bank_acc_no,bank_ifsc,treds_registered)
	VALUES (?,?,?,?,?,?,?,?)`);
const insUser = db.prepare(`INSERT INTO users (business_id,name,email,password,role) VALUES (?,?,?,?,?)`);
const insBuyer = db.prepare(`INSERT INTO buyers (business_id,name,gstin,type,treds_onboarded) VALUES (?,?,?,?,?)`);
const insInv = db.prepare(`INSERT INTO invoices (business_id,buyer_id,number,invoice_date,due_date,base_amount,tax_amount,total_amount,status,approval_date)
	VALUES (?,?,?,?,?,?,?,?,?,?)`);
const insEv = db.prepare(`INSERT INTO invoice_evidences (invoice_id,type,present) VALUES (?,?,?)`);
const insAlert = db.prepare(`INSERT INTO alerts (business_id,invoice_id,type,message) VALUES (?,?,?,?)`);

// ---- Tenant 1: Shree Precision ----
const b1 = insBiz.run('Shree Precision Components', '36AAACS1234F1Z5', 'AAACS1234F', 'UDYAM-TS-12-3456789',
	'HDFC Bank', '50100234567890', 'HDFC0001234', 1).lastInsertRowid;
insUser.run(b1, 'Sunita Rao', 'sunita@shreeprecision.in', pwd('demo1234'), 'owner');
const buyers1 = [
	['Bharat Heavy Electricals Ltd',   '36AABCB1234C1Z3', 'cpse',    'yes'],
	['Telangana State Powergen',       '36AAACT5678D1Z8', 'psu',     'yes'],
	['Orbit Auto Components Pvt Ltd',  '36AAGCO9876E1Z2', 'private', 'no'],
	['Hydrofit Engineering LLP',       '36AAJFH2468F1Z9', 'private', 'unknown'],
].map(b => insBuyer.run(b1, b[0], b[1], b[2], b[3]).lastInsertRowid);

const data1 = [
	[buyers1[0], 'INV-2026-001', -92, 485000, 'settled',  ['po','delivery_ack','grn','invoice_copy','contract']],
	[buyers1[0], 'INV-2026-002', -48, 210000, 'accepted', ['po','delivery_ack','grn','invoice_copy']],
	[buyers1[0], 'INV-2026-003', -70, 360000, 'accepted', ['po','delivery_ack','grn','invoice_copy','contract']],
	[buyers1[0], 'INV-2026-004', -26, 92000,  'raised',   ['po','delivery_ack','grn','invoice_copy']],
	[buyers1[1], 'INV-2026-005', -80, 640000, 'accepted', ['po','delivery_ack','grn','invoice_copy']],
	[buyers1[1], 'INV-2026-006', -33, 150000, 'raised',   ['po','delivery_ack','grn']],
	[buyers1[1], 'INV-2026-007', -110,820000, 'disputed', ['po','delivery_ack','grn','contract']],
	[buyers1[2], 'INV-2026-008', -15, 76000,  'raised',   ['po','delivery_ack','grn','invoice_copy']],
	[buyers1[2], 'INV-2026-009', -60, 200000, 'accepted', ['po','delivery_ack','grn']],
	[buyers1[2], 'INV-2026-010', -5,  45000,  'raised',   ['po','grn']],
	[buyers1[3], 'INV-2026-011', -42, 130000, 'raised',   ['po','delivery_ack','grn','invoice_copy']],
	[buyers1[3], 'INV-2026-012', -100,300000, 'accepted', ['po','delivery_ack','grn','contract']],
	[buyers1[0], 'INV-2026-013', -18, 88000,  'financed', ['po','delivery_ack','grn','invoice_copy','contract']],
	[buyers1[1], 'INV-2026-014', -12, 64000,  'accepted', ['po','delivery_ack','grn','invoice_copy']],
	[buyers1[2], 'INV-2026-015', -8,  32000,  'raised',   ['po','invoice_copy']],
];
for (const r of data1) {
	const [bid, num, off, base, status, ev] = r;
	const idate = d(off);
	const tax = base * (cfg.default_tax_rate / 100);
	const due = new Date(idate); due.setDate(due.getDate() + cfg.msme_due_days);
	const dueS = due.toISOString().slice(0,10);
	const appr = ['accepted','financed','settled'].includes(status)
		? new Date(new Date(idate).getTime() + 12*86400000).toISOString().slice(0,10) : null;
	const invId = insInv.run(b1, bid, num, idate, dueS, base, tax, base+tax, status, appr).lastInsertRowid;
	for (const t of ['po','delivery_ack','grn','contract','invoice_copy']) {
		insEv.run(invId, t, ev.includes(t) ? 1 : 0);
	}
	const bt = db.prepare('SELECT treds_onboarded FROM buyers WHERE id=?').get(bid).treds_onboarded;
	if (!['settled','draft'].includes(status) && bt !== 'yes') {
		insAlert.run(b1, invId, 'treds', 'Buyer is not TReDS-onboarded — confirm before the invoice churns.');
	}
}
console.log('Seeded business 1 (Shree Precision) — 15 invoices, 1 user.');

// ---- Tenant 2: MetRow Ceramics ----
const b2 = insBiz.run('MetRow Ceramics', '29AAACM5678G1Z4', 'AAACM5678G', 'UDYAM-KA-11-7654321',
	'SBI', '30123456789', 'SBIN0004567', 0).lastInsertRowid;
insUser.run(b2, 'Farhan Ali', 'farhan@metrowceramics.in', pwd('demo1234'), 'owner');
const buyers2 = [
	['Delhi Metro Rail Corp',     '07AADCM2222H1Z1', 'psu',     'yes'],
	['Urban Structures Pvt Ltd',  '29AABFU3333K1Z7', 'private', 'unknown'],
].map(b => insBuyer.run(b2, b[0], b[1], b[2], b[3]).lastInsertRowid);
for (const r of [
	[buyers2[0], 'INV-2026-101', -60, 540000, 'accepted', ['po','delivery_ack','grn','invoice_copy']],
	[buyers2[0], 'INV-2026-102', -20, 180000, 'raised',   ['po','delivery_ack','grn']],
	[buyers2[1], 'INV-2026-103', -8,  64000,  'raised',   ['po','invoice_copy']],
]) {
	const [bid, num, off, base, status, ev] = r;
	const idate = d(off);
	const tax = base * (cfg.default_tax_rate / 100);
	const due = new Date(idate); due.setDate(due.getDate() + cfg.msme_due_days);
	const dueS = due.toISOString().slice(0,10);
	const appr = ['accepted','financed','settled'].includes(status)
		? new Date(new Date(idate).getTime() + 12*86400000).toISOString().slice(0,10) : null;
	const invId = insInv.run(b2, bid, num, idate, dueS, base, tax, base+tax, status, appr).lastInsertRowid;
	for (const t of ['po','delivery_ack','grn','contract','invoice_copy']) {
		insEv.run(invId, t, ev.includes(t) ? 1 : 0);
	}
	const bt = db.prepare('SELECT treds_onboarded FROM buyers WHERE id=?').get(bid).treds_onboarded;
	if (!['settled','draft'].includes(status) && bt !== 'yes') {
		insAlert.run(b2, invId, 'treds', 'Buyer is not TReDS-onboarded — confirm before the invoice churns.');
	}
}
console.log('Seeded business 2 (MetRow Ceramics) — 3 invoices, 1 user.');
console.log('Demo logins:');
console.log('  sunita@shreeprecision.in / demo1234  (tenant 1)');
console.log('  farhan@metrowceramics.in / demo1234  (tenant 2)');
console.log('Done.');
