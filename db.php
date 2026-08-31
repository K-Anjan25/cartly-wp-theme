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
		}
		return $pdo;
	}
}
