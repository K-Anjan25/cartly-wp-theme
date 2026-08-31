<?php
/**
 * Cartly DS — design-token model.
 *
 * Reads the canonical `assets/src/tokens.css` file (the single source of truth
 * shared with the React storefront) and turns it into a usable model: light
 * & dark values, hex conversions, semantic grouping and WCAG contrast ratios.
 *
 * Pure PHP, no extensions, no framework. This is the one thing every other
 * command in the tool builds on.
 *
 * @package CartlyDS
 */

namespace CartlyDS;

final class Tokens
{
	/** @var array<string,string> Light-mode custom properties (channel values). */
	private array $light = array();

	/** @var array<string,string> Dark-mode custom properties (channel values). */
	private array $dark = array();

	/** @var array<string,string> Non-colour tokens (shadows, scrollbar) — raw values. */
	private array $raw = array();

	/**
	 * Build from a parsed token set.
	 */
	public function __construct( array $light = array(), array $dark = array(), array $raw = array() ) {
		$this->light = $light;
		$this->dark  = $dark;
		$this->raw   = $raw;
	}

	/**
	 * Parse a Cartly `tokens.css` file into a Tokens instance.
	 *
	 * The file uses `:root { --c-*: r g b; }` for light and `.dark { ... }` for
	 * dark. Colour tokens are space-separated RGB channels; everything else
	 * (e.g. `--shadow-*`) is kept raw.
	 *
	 * @param string $path Absolute or relative file path.
	 * @return Tokens
	 * @throws \RuntimeException When the file is missing or unparseable.
	 */
	public static function fromCssFile( string $path ): Tokens {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new \RuntimeException( sprintf( 'Token file not found: %s', $path ) );
		}

		$css = file_get_contents( $path );
		if ( false === $css ) {
			throw new \RuntimeException( sprintf( 'Could not read token file: %s', $path ) );
		}

		return self::fromCss( $css );
	}

	/**
	 * Parse a CSS string (as produced by tokens.css) into a Tokens instance.
	 *
	 * @param string $css CSS text.
	 * @return Tokens
	 */
	public static function fromCss( string $css ): Tokens {
		$light = self::extractBlockVars( $css, ':root' );
		$dark  = self::extractBlockVars( $css, '.dark' );

		$colourNames = array( 'c-' );
		$channel = $light;
		$raw     = array();

		foreach ( $light as $name => $value ) {
			if ( self::isColourToken( $name ) ) {
				continue;
			}
			// Mirror across schemes for raw tokens (default to light value).
			$raw[ $name ] = $value;
		}

		return new Tokens( $light, $dark, $raw );
	}

	/**
	 * Pull the `--name: value;` pairs from a rule block.
	 *
	 * @param string $css   The full CSS text.
	 * @param string $block The selector to find, e.g. ':root' or '.dark'.
	 * @return array<string,string>
	 */
	private static function extractBlockVars( string $css, string $block ): array {
		$pos = strpos( $css, $block );
		if ( false === $pos ) {
			return array();
		}

		// Find the opening brace after the selector, then its matching close,
		// respecting nesting so a nested `.dark` never swallows `:root`.
		$open  = strpos( $css, '{', $pos );
		if ( false === $open ) {
			return array();
		}

		$depth     = 0;
		$bodyStart = $open + 1;
		$bodyEnd   = null;
		$len       = strlen( $css );

		for ( $i = $bodyStart; $i < $len; $i++ ) {
			$ch = $css[ $i ];
			if ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				if ( 0 === $depth ) {
					$bodyEnd = $i;
					break;
				}
				$depth--;
			}
		}

		if ( null === $bodyEnd ) {
			return array();
		}

		$body = substr( $css, $bodyStart, $bodyEnd - $bodyStart );

		$vars  = array();
		$found = preg_match_all( '/--([a-zA-Z0-9][a-zA-Z0-9-]*)\s*:\s*([^;{}]+);/u', $body, $matches, PREG_SET_ORDER );
		if ( ! $found ) {
			return $vars;
		}

		foreach ( $matches as $m ) {
			$name  = $m[1];
			$value = trim( $m[2] );
			$vars[ $name ] = $value;
		}

		return $vars;
	}

	/**
	 * Is a token a channel-based colour (three numbers) rather than a raw value?
	 *
	 * @param string $name Property name without the leading `--`.
	 */
	private static function isColourToken( string $name ): bool {
		return 0 === strpos( $name, 'c-' );
	}

	/**
	 * All colour tokens (light) keyed by short name, e.g. 'brand' => '91 61 245'.
	 *
	 * @return array<string,string>
	 */
	public function coloursLight(): array {
		return $this->light;
	}

	/**
	 * All colour tokens (dark) keyed by short name.
	 *
	 * @return array<string,string>
	 */
	public function coloursDark(): array {
		return $this->dark;
	}

	/**
	 * The full custom-property set for a scheme — colours *and* raw values
	 * (shadows, scrollbar) as they appear in that scheme. Useful when emitting
	 * a clean `:root` / `.dark` block.
	 *
	 * @param string $scheme 'light' or 'dark'.
	 * @return array<string,string>
	 */
	public function schemeVars( string $scheme ): array {
		return 'dark' === $scheme ? $this->dark : $this->light;
	}

	/**
	 * Colour-only tokens for a scheme (excludes raw/shadow values).
	 *
	 * @param string $scheme 'light' or 'dark'.
	 * @return array<string,string>
	 */
	public function colourVars( string $scheme ): array {
		$all = $this->schemeVars( $scheme );
		$out = array();
		foreach ( $all as $name => $value ) {
			if ( 0 === strpos( $name, 'c-' ) ) {
				$out[ $name ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Raw (non-channel) tokens, e.g. shadows.
	 *
	 * @return array<string,string>
	 */
	public function raw(): array {
		return $this->raw;
	}

	/**
	 * A single colour token value (channels) for a scheme.
	 *
	 * @param string $short Short token name, e.g. 'brand'.
	 * @param string $scheme 'light' or 'dark'.
	 * @return string|null
	 */
	public function channels( string $short, string $scheme = 'light' ): ?string {
		$key = 'c-' . $short;
		$set = 'dark' === $scheme ? $this->dark : $this->light;
		return $set[ $key ] ?? $this->light[ $key ] ?? null;
	}

	/**
	 * Convert space-separated RGB channels to a hex string.
	 *
	 * @param string $channels e.g. '91 61 245'.
	 * @return string e.g. '#5b3df5'.
	 */
	public static function toHex( string $channels ): string {
		$parts = preg_split( '/\s+/', trim( $channels ) );
		if ( ! $parts || count( $parts ) < 3 ) {
			return '';
		}
		return sprintf(
			'#%02x%02x%02x',
			(int) $parts[0],
			(int) $parts[1],
			(int) $parts[2]
		);
	}

	/**
	 * WCAG 2.x relative luminance for a hex colour (0..1).
	 *
	 * @param string $hex e.g. '#5b3df5'.
	 * @return float
	 */
	public static function luminance( string $hex ): float {
		$hex = ltrim( strtolower( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return 0.0;
		}

		$r = hexdec( substr( $hex, 0, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 2, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 4, 2 ) ) / 255;

		$lin = static function ( float $c ): float {
			return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};

		return 0.2126 * $lin( $r ) + 0.7152 * $lin( $g ) + 0.0722 * $lin( $b );
	}

	/**
	 * WCAG contrast ratio between two hex colours (1..21).
	 *
	 * @param string $hexA Foreground.
	 * @param string $hexB Background.
	 * @return float
	 */
	public static function contrast( string $hexA, string $hexB ): float {
		$la = self::luminance( $hexA );
		$lb = self::luminance( $hexB );
		$l1 = max( $la, $lb );
		$l2 = min( $la, $lb );
		return round( ( $l1 + 0.05 ) / ( $l2 + 0.05 ), 2 );
	}

	/**
	 * WCAG rating label for a contrast ratio.
	 *
	 * @param float $ratio Contrast ratio.
	 * @return string 'AAA', 'AA-Large', 'AA' or 'Fail'.
	 */
	public static function rating( float $ratio ): string {
		$r = round( $ratio, 2 );
		if ( $r >= 7 ) {
			return 'AAA';
		}
		if ( $r >= 4.5 ) {
			return 'AA';
		}
		if ( $r >= 3 ) {
			return 'AA-Large';
		}
		return 'Fail';
	}

	/**
	 * Group a short token name into a semantic bucket.
	 *
	 * @param string $short e.g. 'brand', 'ink-soft', 'success-soft'.
	 * @return string e.g. 'brand', 'accent', 'neutral', 'contrast', 'state'.
	 */
	public static function group( string $short ): string {
		if ( 0 === strpos( $short, 'brand' ) ) {
			return 'brand';
		}
		if ( 0 === strpos( $short, 'accent' ) ) {
			return 'accent';
		}
		if ( 0 === strpos( $short, 'contrast' ) || 0 === strpos( $short, 'oncontrast' ) ) {
			return 'contrast';
		}
		if ( 0 === strpos( $short, 'ink' ) || in_array( $short, array( 'paper', 'canvas', 'sunken', 'line' ), true ) ) {
			return 'neutral';
		}
		if ( 0 === strpos( $short, 'success' ) || 0 === strpos( $short, 'warning' ) || 0 === strpos( $short, 'danger' ) || 0 === strpos( $short, 'info' ) ) {
			return 'state';
		}
		return 'misc';
	}

	/**
	 * Human-friendly name for a token.
	 *
	 * @param string $name Full property, e.g. 'c-brand-main'.
	 * @return string 'Brand main'.
	 */
	public static function label( string $name ): string {
		$short = 0 === strpos( $name, 'c-' ) ? substr( $name, 2 ) : $name;
		$short = preg_replace( '/[-_]+/', ' ', $short );
		return ucfirst( (string) $short );
	}

	/**
	 * Export the whole token set as an array (light, dark, raw).
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'light' => $this->light,
			'dark'  => $this->dark,
			'raw'   => $this->raw,
		);
	}
}
