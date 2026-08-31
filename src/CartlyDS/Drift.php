<?php
/**
 * Cartly DS — token drift guard.
 *
 * The whole point of this tool: the Cartly design language lives in one place
 * (the tokens file) but is consumed by both the WordPress theme and the React
 * storefront, which live in two repos. If they quietly diverge, the products
 * stop looking like the same brand. This checks a tokens file against a
 * canonical baseline and reports any difference, with a non-zero exit status so
 * it drops straight into a CI job.
 *
 * Pure PHP, cross-platform — replaces the old bash + Python + Node chain.
 *
 * @package CartlyDS
 */

namespace CartlyDS;

final class Drift
{
	/**
	 * Compare two token sets.
	 *
	 * @param Tokens $current   Local token set.
	 * @param Tokens $baseline  Canonical token set.
	 * @return array<int,array<string,string>> List of {type, token, current, baseline}.
	 */
	public function compare( Tokens $current, Tokens $baseline ): array {
		$diff = array();

		$currentLight = $current->toArray()['light'];
		$baseLight    = $baseline->toArray()['light'];

		$currentDark = $current->toArray()['dark'];
		$baseDark    = $baseline->toArray()['dark'];

		$names = array_unique( array_merge( array_keys( $baseLight ), array_keys( $currentLight ) ) );

		foreach ( $names as $name ) {
			$c = $currentLight[ $name ] ?? null;
			$b = $baseLight[ $name ] ?? null;

			if ( null === $b ) {
				$diff[] = array( 'type' => 'added', 'token' => $name, 'current' => $c ?? '', 'baseline' => '' );
			} elseif ( null === $c ) {
				$diff[] = array( 'type' => 'removed', 'token' => $name, 'current' => '', 'baseline' => $b );
			} elseif ( self::norm( $c ) !== self::norm( $b ) ) {
				$diff[] = array( 'type' => 'changed', 'token' => $name, 'current' => $c, 'baseline' => $b );
			}
		}

		// Also surface dark-mode drift on tokens present in both schemes.
		$darkNames = array_unique( array_merge( array_keys( $baseDark ), array_keys( $currentDark ) ) );
		foreach ( $darkNames as $name ) {
			$c = $currentDark[ $name ] ?? null;
			$b = $baseDark[ $name ] ?? null;
			if ( null !== $c && null !== $b && self::norm( $c ) !== self::norm( $b ) ) {
				$diff[] = array( 'type' => 'changed-dark', 'token' => $name, 'current' => $c, 'baseline' => $b );
			}
		}

		return $diff;
	}

	/**
	 * Normalise a value for comparison (collapse whitespace).
	 */
	private static function norm( string $v ): string {
		return preg_replace( '/\s+/u', ' ', trim( $v ) );
	}

	/**
	 * Render the diff as a plain-text table.
	 *
	 * @param array<int,array<string,string>> $diff
	 * @return string
	 */
	public function render( array $diff ): string {
		if ( ! $diff ) {
			return "OK — no token drift. The theme and the design system match.\n";
		}

		$lines = array();
		$lines[] = sprintf( "Token drift detected: %d change(s)\n", count( $diff ) );
		$lines[] = str_pad( 'TYPE', 16 ) . str_pad( 'TOKEN', 28 ) . str_pad( 'CURRENT', 24 ) . 'BASELINE';
		$lines[] = str_repeat( '-', 96 );

		foreach ( $diff as $d ) {
			$lines[] = str_pad( $d['type'], 16 )
				. str_pad( $d['token'], 28 )
				. str_pad( $d['current'], 24 )
				. $d['baseline'];
		}

		return implode( "\n", $lines ) . "\n";
	}
}
