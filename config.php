<?php
/**
 * PayKaro — configuration.
 *
 * PDO so the data layer is driver-agnostic. Demo runs on SQLite; point
 * PAYKARO_DB_DSN at MySQL for production.
 */

return array(
	'env'                 => 'demo',

	// PDO DSN.
	'db_dsn'              => getenv( 'PAYKARO_DB_DSN' ) ?: 'sqlite:' . __DIR__ . '/data/paykaro.sqlite',
	'db_user'             => getenv( 'PAYKARO_DB_USER' ) ?: '',
	'db_pass'             => getenv( 'PAYKARO_DB_PASS' ) ?: '',

	// Business rules (MSMED Act defaults).
	'currency'            => 'INR',
	'msme_due_days'       => 45,
	'default_tax_rate'    => 18,
	'bank_rate'           => 6.5,
	'interest_multiplier' => 3,
	'finance_ready_score' => 85,
	'required_evidence'   => array( 'po', 'delivery_ack', 'grn', 'invoice_copy' ),

	// Session lifetime (seconds).
	'session_ttl'         => 7 * 86400,

	// App.
	'name'                => 'PayKaro',
	'tagline'             => 'MSME invoice & receivables tracker',
);
