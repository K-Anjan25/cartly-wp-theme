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

	// Google Sign-In (OAuth 2.0 Authorization Code flow). Optional: when the
	// client_id is empty the "Continue with Google" button is hidden and the
	// /auth/google routes are inert. Set these via env or edit below. The
	// redirect_uri must be registered exactly in the Google Cloud Console; if
	// left empty it is derived from the incoming Host header at runtime so the
	// live preview (https://{port}-{sandboxId}.e2b.app) works without config.
	'google_oauth'        => array(
		'client_id'     => getenv( 'GOOGLE_CLIENT_ID' ) ?: '',
		'client_secret' => getenv( 'GOOGLE_CLIENT_SECRET' ) ?: '',
		// Order of precedence: env > explicit redirect_uri below > runtime Host.
		'redirect_uri'  => getenv( 'GOOGLE_REDIRECT_URI' ) ?: '',
	),

	// App.
	'name'                => 'PayKaro',
	'tagline'             => 'MSME invoice & receivables tracker',
);
