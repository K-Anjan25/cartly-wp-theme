-- PayKaro — schema. SQLite flavour for the zero-config demo; identical
-- semantics to MySQL. Bools are INTEGER 0/1.

PRAGMA foreign_keys = OFF;

CREATE TABLE IF NOT EXISTS businesses (
	id            INTEGER PRIMARY KEY AUTOINCREMENT,
	name          TEXT    NOT NULL,
	gstin         TEXT,
	pan           TEXT,
	udyam_no      TEXT,
	bank_name     TEXT,
	bank_acc_no   TEXT,
	bank_ifsc     TEXT,
	treds_registered INTEGER NOT NULL DEFAULT 0,
	created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS users (
	id          INTEGER PRIMARY KEY AUTOINCREMENT,
	business_id INTEGER NOT NULL,
	name        TEXT    NOT NULL,
	email       TEXT    NOT NULL UNIQUE,
	password    TEXT    NOT NULL,          -- password_hash()
	role        TEXT    NOT NULL DEFAULT 'owner',  -- owner|accountant|viewer
	created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
	FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sessions (
	token       TEXT PRIMARY KEY,
	user_id     INTEGER NOT NULL,
	created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
	expires_at  TEXT    NOT NULL,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS buyers (
	id              INTEGER PRIMARY KEY AUTOINCREMENT,
	business_id     INTEGER NOT NULL,
	name            TEXT    NOT NULL,
	gstin           TEXT,
	type            TEXT    NOT NULL DEFAULT 'private', -- cpse|psu|private
	treds_onboarded TEXT    NOT NULL DEFAULT 'unknown',  -- unknown|yes|no
	created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
	FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS invoices (
	id            INTEGER PRIMARY KEY AUTOINCREMENT,
	business_id   INTEGER NOT NULL,
	buyer_id      INTEGER NOT NULL,
	number        TEXT    NOT NULL,
	invoice_date  TEXT    NOT NULL,
	due_date      TEXT    NOT NULL,
	base_amount   REAL    NOT NULL DEFAULT 0,
	tax_amount    REAL    NOT NULL DEFAULT 0,
	total_amount  REAL    NOT NULL DEFAULT 0,
	status        TEXT    NOT NULL DEFAULT 'raised', -- draft|raised|accepted|financed|settled|disputed
	approval_date TEXT,
	paid_date     TEXT,
	payment_ref   TEXT,
	treds_status  TEXT    NOT NULL DEFAULT 'na',      -- na|ready|pending_buyer_onboard|financed|ineligible
	notes         TEXT,
	created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
	FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
	FOREIGN KEY (buyer_id) REFERENCES buyers(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS invoice_evidences (
	id         INTEGER PRIMARY KEY AUTOINCREMENT,
	invoice_id INTEGER NOT NULL,
	type       TEXT    NOT NULL, -- po|delivery_ack|grn|contract|invoice_copy
	present    INTEGER NOT NULL DEFAULT 0,
	note       TEXT,
	FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS payments (
	id          INTEGER PRIMARY KEY AUTOINCREMENT,
	invoice_id  INTEGER NOT NULL,
	amount      REAL    NOT NULL,
	paid_on     TEXT    NOT NULL,
	method      TEXT,
	reference   TEXT,
	FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS financing (
	id                INTEGER PRIMARY KEY AUTOINCREMENT,
	invoice_id        INTEGER NOT NULL,
	financier         TEXT,
	discount_rate     REAL,
	amount_disbursed  REAL,
	disbursed_on      TEXT,
	status            TEXT    NOT NULL DEFAULT 'disbursed',
	FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS disputes (
	id            INTEGER PRIMARY KEY AUTOINCREMENT,
	invoice_id    INTEGER NOT NULL,
	forum         TEXT    NOT NULL, -- msefc|mediation|arbitration
	stage         TEXT,
	filed_on      TEXT,
	deadline_on   TEXT,
	FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS alerts (
	id           INTEGER PRIMARY KEY AUTOINCREMENT,
	business_id  INTEGER NOT NULL,
	invoice_id   INTEGER,
	type         TEXT    NOT NULL,
	message      TEXT    NOT NULL,
	read_at      TEXT,
	created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
	FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_invoices_business_status ON invoices(business_id, status);
CREATE INDEX IF NOT EXISTS idx_invoices_business_due     ON invoices(business_id, due_date);
CREATE INDEX IF NOT EXISTS idx_invoices_buyer            ON invoices(buyer_id);
CREATE INDEX IF NOT EXISTS idx_evidences_invoice         ON invoice_evidences(invoice_id);
CREATE INDEX IF NOT EXISTS idx_users_email               ON users(email);
CREATE INDEX IF NOT EXISTS idx_sessions_token            ON sessions(token);
