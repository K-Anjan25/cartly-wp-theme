<?php
/**
 * PayKaro — PDO connection.
 */

if ( ! function_exists( 'paykaro_db' ) ) {
	function paykaro_db(): PDO {
		static $pdo = null;
		if ( $pdo instanceof PDO ) {
			return $pdo;
		}
		$config = require __DIR__ . '/config.php';
		try {
			$pdo = new PDO( $config['db_dsn'], $config['db_user'], $config['db_pass'] );
		} catch ( PDOException $e ) {
			http_response_code( 500 );
			echo json_encode( array( 'error' => 'Database connection failed: ' . $e->getMessage() ) );
			exit;
		}
		$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
		if ( 0 === strpos( $config['db_dsn'], 'sqlite:' ) ) {
			$pdo->exec( 'PRAGMA foreign_keys = ON;' );
			paykaro_migrate( $pdo );
		}
		return $pdo;
	}
}

/**
 * Lightweight, idempotent runtime migration for SQLite.
 *
 * schema.sql covers fresh installs; this keeps an already-seeded demo DB (which
 * persists on disk) up to date with OAuth columns, a nullable users.password,
 * and the oauth_states table without requiring a reseed. Each step is guarded so
 * it is safe to run on every connection.
 */
if ( ! function_exists( 'paykaro_migrate' ) ) {
	function paykaro_migrate( PDO $pdo ): void {
		// Bail out entirely if the users table doesn't exist yet (brand-new DB);
		// schema.sql (via seed.php) builds the full schema, including these
		// columns/table, from scratch.
		$exists = $pdo->query( "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='users'" )->fetchColumn();
		if ( ! $exists ) {
			return;
		}
		// Inspect the users table.
		$cols = array();
		$info = array();
		foreach ( $pdo->query( 'PRAGMA table_info(users)' )->fetchAll() as $c ) {
			$cols[] = (string) $c['name'];
			$info[ (string) $c['name'] ] = (int) $c['notnull'];
		}

		// 1. Add OAuth columns if missing.
		if ( ! in_array( 'provider', $cols, true ) ) {
			$pdo->exec( "ALTER TABLE users ADD COLUMN provider TEXT NOT NULL DEFAULT 'email'" );
		}
		if ( ! in_array( 'google_id', $cols, true ) ) {
			$pdo->exec( 'ALTER TABLE users ADD COLUMN google_id TEXT' );
		}
		if ( ! in_array( 'avatar_url', $cols, true ) ) {
			$pdo->exec( 'ALTER TABLE users ADD COLUMN avatar_url TEXT' );
		}

		// 2. Make users.password nullable for OAuth-only users. SQLite can't ALTER
		//    a column constraint, so rebuild the table when it is still NOT NULL.
		if ( isset( $info['password'] ) && $info['password'] ) {
			$pdo->exec( "ALTER TABLE users RENAME TO _users_old" );
			$pdo->exec(
				'CREATE TABLE users (
					id          INTEGER PRIMARY KEY AUTOINCREMENT,
					business_id INTEGER NOT NULL,
					name        TEXT    NOT NULL,
					email       TEXT    NOT NULL UNIQUE,
					password    TEXT,                      -- NULL for OAuth-only users
					role        TEXT    NOT NULL DEFAULT \'owner\',
					provider    TEXT    NOT NULL DEFAULT \'email\',
					google_id   TEXT,
					avatar_url  TEXT,
					created_at  TEXT    NOT NULL DEFAULT (datetime(\'now\')),
					FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
				)'
			);
			$pdo->exec(
				'INSERT INTO users (id,business_id,name,email,password,role,provider,google_id,avatar_url,created_at)
				 SELECT id,business_id,name,email,password,role,provider,google_id,avatar_url,created_at FROM _users_old'
			);
			$pdo->exec( 'DROP TABLE _users_old' );
			$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)' );
			$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_users_google_id ON users(google_id)' );
		}

		// 3. oauth_states (one-time CSRF state).
		try {
			$pdo->exec(
				'CREATE TABLE IF NOT EXISTS oauth_states (
					state      TEXT PRIMARY KEY,
					created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
					expires_at TEXT NOT NULL
				)'
			);
		} catch ( PDOException $e ) {
			// ignore
		}
	}
}
