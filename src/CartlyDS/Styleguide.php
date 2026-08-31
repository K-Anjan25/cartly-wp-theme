<?php
/**
 * Cartly DS — interactive styleguide renderer.
 *
 * Emits a single, self-contained, responsive HTML page: live dark-mode toggle,
 * a searchable token browser grouped by role (with copy-to-clipboard and WCAG
 * contrast ratings), a curated accessible-pair table, an interactive contrast
 * checker, the type scale, and a live component library. Works offline as one
 * file — no server, no framework.
 *
 * @package CartlyDS
 */

namespace CartlyDS;

final class Styleguide
{
	/** @var Tokens */
	private Tokens $tokens;

	/** @var string Compiled design-system CSS (inlined). */
	private string $css;

	/**
	 * @param Tokens $tokens Parsed tokens.
	 * @param string $css    Design-system CSS to inline (from Compiler).
	 */
	public function __construct( Tokens $tokens, string $css ) {
		$this->tokens = $tokens;
		$this->css    = $css;
	}

	/**
	 * Render the styleguide HTML.
	 *
	 * @param string $title Page title.
	 * @return string
	 */
	public function render( string $title = 'Cartly Design System' ): string
	{
		$groups       = $this->buildGroups();
		$pairs        = $this->buildPairs();
		$typeScale    = $this->typeScale();
		$components   = $this->components();
		$css          = $this->css;

		$ob = '';
		$ob .= '<!doctype html>' . "\n";
		$ob .= '<html lang="en">' . "\n";
		$ob .= "<head>\n<meta charset=\"utf-8\">\n";
		$ob .= "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
		$ob .= '<title>' . $this->esc( $title ) . "</title>\n";
		$ob .= "<style>\n" . $css . "\n</style>\n";
		$ob .= "<style>\n" . $this->styleguideCss() . "\n</style>\n";
		$ob .= "</head>\n<body class=\"cds\" data-theme=\"light\">\n";

		$ob .= $this->header();

		$ob .= '<main class="page-shell cds-main">' . "\n";

		// Intro
		$ob .= '<section class="cds-section" id="overview">';
		$ob .= '<p class="eyebrow">Design system</p>';
		$ob .= '<h1 class="cds-display">' . $this->esc( $title ) . '</h1>';
		$ob .= '<p class="page-subtitle">Colour, type and components from one token set. Toggle '
			. '<strong>dark mode</strong> with the button above, search the palette, and click any token to copy it. '
			. 'Every colour carries its WCAG contrast rating against a real surface.</p>';
		$ob .= '</section>' . "\n";

		// Toolbar
		$ob .= '<div class="cds-toolbar">';
		$ob .= '<input class="input-control" id="filter" type="search" placeholder="Filter tokens — e.g. brand, ink, success…">';
		$ob .= '<div class="cds-toolbar-meta" id="count"></div>';
		$ob .= '</div>' . "\n";

		// Token groups
		$ob .= '<section class="cds-section" id="tokens">';
		foreach ( $groups as $group => $items ) {
			$ob .= '<h2 class="section-title">' . $this->esc( ucfirst( $group ) ) . '</h2>';
			$ob .= '<div class="cds-grid ' . ( 'state' === $group ? 'cds-grid--cols-3' : 'cds-grid--cols-2' ) . '" '
				. 'data-group="' . $this->esc( $group ) . '">';
			foreach ( $items as $it ) {
				$ob .= $this->tokenCard( $it );
			}
			$ob .= '</div>' . "\n";
		}
		$ob .= '</section>' . "\n";

		// Accessible pairs
		$ob .= '<section class="cds-section" id="pairs">';
		$ob .= '<h2 class="section-title">Verified contrast pairs</h2>';
		$ob .= '<p class="page-subtitle">These foreground/background pairings are the ones the Cartly UI actually uses, '
			. 'rated by WCAG 2.x.</p>';
		$ob .= '<table class="cds-pairs"><thead><tr><th>Foreground</th><th>Background</th><th>Ratio</th><th>Rating</th></tr></thead><tbody>';
		foreach ( $pairs as $p ) {
			$ob .= '<tr><td><span class="cds-dot" style="background:' . $this->esc( $p['fgHex'] ) . '"></span><code>' . $this->esc( $p['fg'] ) . '</code></td>'
				. '<td><span class="cds-dot" style="background:' . $this->esc( $p['bgHex'] ) . '"></span><code>' . $this->esc( $p['bg'] ) . '</code></td>'
				. '<td><code>' . number_format( $p['ratio'], 2 ) . '</code></td>'
				. '<td><span class="cds-rating ' . strtolower( str_replace( '-', '', $p['rating'] ) ) . '">' . $this->esc( $p['rating'] ) . '</span></td></tr>';
		}
		$ob .= '</tbody></table>';
		$ob .= '</section>' . "\n";

		// Contrast checker
		$ob .= '<section class="cds-section" id="checker">';
		$ob .= '<h2 class="section-title">Contrast checker</h2>';
		$ob .= '<div class="cds-grid cds-grid--cols-2">';
		$ob .= '<div><label class="cds-label" for="fg">Foreground</label><select class="input-control" id="fg">' . $this->tokenOptions() . '</select></div>';
		$ob .= '<div><label class="cds-label" for="bg">Background</label><select class="input-control" id="bg">' . $this->tokenOptions() . '</select></div>';
		$ob .= '</div>';
		$ob .= '<div class="cds-check">';
		$ob .= '<div class="cds-check-preview" id="checkPreview"><span id="checkText">Aa</span></div>';
		$ob .= '<div><div class="cds-check-ratio" id="checkRatio">—</div><div class="cds-check-rating" id="checkRating"></div></div>';
		$ob .= '</div>';
		$ob .= '</section>' . "\n";

		// Type scale
		$ob .= '<section class="cds-section" id="type">';
		$ob .= '<h2 class="section-title">Type scale</h2>';
		$ob .= '<div class="cds-typescale">';
		foreach ( $typeScale as $t ) {
			$ob .= '<div class="cds-type-row"><span class="cds-type-spec">' . $this->esc( $t['spec'] ) . '</span>'
				. '<span class="cds-type-sample" style="font-size:' . $this->esc( $t['size'] ) . '">' . $this->esc( $t['sample'] ) . '</span></div>';
		}
		$ob .= '</div>';
		$ob .= '</section>' . "\n";

		// Components
		$ob .= '<section class="cds-section" id="components">';
		$ob .= '<h2 class="section-title">Components</h2>';
		$ob .= $components;
		$ob .= '</section>' . "\n";

		$ob .= '</main>' . "\n";

		$ob .= $this->footer();
		$ob .= "<script>\n" . $this->script() . "\n</script>\n";
		$ob .= "</body>\n</html>\n";

		return $ob;
	}

	/* ------------------------------------------------------------------ */
	/* Data builders                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Build a group => list of token cards.
	 *
	 * @return array<string,array<int,array<string,string>>>
	 */
	private function buildGroups(): array {
		$groups = array();
		$all    = $this->tokens->toArray();

		foreach ( $all['light'] as $name => $channels ) {
			if ( 0 !== strpos( $name, 'c-' ) ) {
				continue;
			}
			$short = substr( $name, 2 );
			$group = Tokens::group( $short );
			$groups[ $group ][] = array(
				'name'        => $name,
				'short'       => $short,
				'label'       => Tokens::label( $name ),
				'channels'    => $channels,
				'hex'         => Tokens::toHex( $channels ),
				'hexDark'     => Tokens::toHex( $this->tokens->channels( $short, 'dark' ) ?? $channels ),
			);
		}

		$order = array( 'brand', 'accent', 'neutral', 'contrast', 'state', 'misc' );
		uksort( $groups, static function ( $a, $b ) use ( $order ) {
			$ia = array_search( $a, $order, true );
			$ib = array_search( $b, $order, true );
			$ia = false === $ia ? 99 : $ia;
			$ib = false === $ib ? 99 : $ib;
			return $ia <=> $ib;
		} );

		return $groups;
	}

	/**
	 * Curated foreground/background pairs with computed ratios.
	 *
	 * @return array<int,array<string,string|float>>
	 */
	private function buildPairs(): array {
		$pairs = array(
			array( 'oncontrast', 'contrast' ),
			array( 'ink',        'canvas' ),
			array( 'ink',        'paper' ),
			array( 'ink-soft',   'paper' ),
			array( 'brand',      'brand-tint' ),
			array( 'oncontrast', 'brand' ),
			array( 'success-on', 'success-soft' ),
			array( 'warning-on', 'warning-soft' ),
			array( 'danger-on',  'danger-soft' ),
		);

		$out = array();
		foreach ( $pairs as $pair ) {
			$fg = $this->tokens->channels( $pair[0] );
			$bg = $this->tokens->channels( $pair[1] );
			if ( null === $fg || null === $bg ) {
				continue;
			}
			$fgHex = Tokens::toHex( $fg );
			$bgHex = Tokens::toHex( $bg );
			$ratio = Tokens::contrast( $fgHex, $bgHex );
			$out[] = array(
				'fg'     => $pair[0],
				'bg'     => $pair[1],
				'fgHex'  => $fgHex,
				'bgHex'  => $bgHex,
				'ratio'  => $ratio,
				'rating' => Tokens::rating( $ratio ),
			);
		}
		// Sort by ratio desc so the best pass sits on top.
		usort( $out, static function ( $a, $b ) {
			return $b['ratio'] <=> $a['ratio'];
		} );
		return $out;
	}

	/**
	 * Type scale rows for the display section.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function typeScale(): array {
		return array(
			array( 'spec' => 'body · 0.9375rem · Inter', 'size' => '0.9375rem', 'sample' => 'Quiet canvas, loud actions.' ),
			array( 'spec' => 'small · 0.8125rem · Inter', 'size' => '0.8125rem', 'sample' => 'Meta and captions.' ),
			array( 'spec' => 'large · 1.3125rem · Inter', 'size' => '1.3125rem', 'sample' => 'Section lead.' ),
			array( 'spec' => 'x-large · 1.75rem · Inter Tight', 'size' => '1.75rem', 'sample' => 'Heading.' ),
			array( 'spec' => 'xx-large · 2.25rem · Inter Tight', 'size' => '2.25rem', 'sample' => 'Display.' ),
		);
	}

	/**
	 * Static component library HTML.
	 *
	 * @return string
	 */
	private function components(): string {
		$b  = '<h3 class="cds-h3">Buttons</h3>';
		$b .= '<div class="cds-grid cds-grid--cols-2"><div class="cds-stack">';
		$b .= '<a class="primary-button" href="#components">Primary</a>';
		$b .= '<a class="dark-button" href="#components">Dark</a>';
		$b .= '<a class="accent-button" href="#components">Accent</a>';
		$b .= '<a class="secondary-button" href="#components">Secondary</a>';
		$b .= '</div><div class="cds-stack">';
		$b .= '<button class="icon-button" aria-label="Search">' . $this->icon( 'search' ) . '</button>';
		$b .= '<button class="icon-button" aria-label="Menu">' . $this->icon( 'menu' ) . '</button>';
		$b .= '</div></div>';

		$b .= '<h3 class="cds-h3">Chips & badges</h3>';
		$b .= '<div class="cds-stack cds-stack--row">';
		$b .= '<span class="chip">Category</span>';
		$b .= '<span class="chip chip-active">Active</span>';
		$b .= '<span class="chip chip-ink">Ink</span>';
		$b .= '<span class="badge-sale">Sale</span>';
		$b .= '<span class="badge-stock-in">In stock</span>';
		$b .= '<span class="badge-stock-low">Low</span>';
		$b .= '<span class="badge-stock-out">Out</span>';
		$b .= '</div>';

		$b .= '<h3 class="cds-h3">Surfaces</h3>';
		$b .= '<div class="cds-grid cds-grid--cols-2">';
		$b .= '<div class="panel cds-surface-demo"><strong>Panel</strong><p class="page-subtitle">A bordered card surface.</p></div>';
		$b .= '<div class="panel-raised cds-surface-demo"><strong>Panel raised</strong><p class="page-subtitle">Lifted with a soft shadow.</p></div>';
		$b .= '</div>';
		$b .= '<div class="cds-grid cds-grid--cols-2">';
		$b .= '<div class="surface-contrast grain cds-surface-demo cds-surface-demo--contrast"><strong>Contrast</strong><p class="page-subtitle">Deliberately dark in both modes.</p></div>';
		$b .= '<div class="panel cds-surface-demo"><label class="cds-label" for="demoInput">Input</label><input class="input-control" id="demoInput" type="text" placeholder="Type here…"></div>';
		$b .= '</div>';

		$b .= '<h3 class="cds-h3">Product card (anatomy)</h3>';
		$b .= '<div class="cds-grid cds-grid--cols-3">';
		$b .= '<div class="panel cds-product"><div class="cds-product-media"><span class="badge-sale">−18%</span></div>'
			. '<div class="cds-product-body"><p class="eyebrow">Audio</p><h4 class="cds-h4">Studio over-ear headphones</h4>'
			. '<div class="cds-product-price"><span class="price-text">₹9,499</span><del>₹11,599</del></div></div>'
			. '<div class="cds-product-action"><button class="primary-button">Add to cart</button></div></div>';
		$b .= '<div class="panel cds-product"><div class="cds-product-media cds-product-media--empty"></div>'
			. '<div class="cds-product-body"><p class="eyebrow">Home</p><h4 class="cds-h4">Ceramic pour-over dripper</h4>'
			. '<div class="cds-product-price"><span class="price-text">₹2,299</span></div></div>'
			. '<div class="cds-product-action"><button class="dark-button">Add to cart</button></div></div>';
		$b .= '<div class="panel cds-product"><div class="cds-product-media cds-product-media--empty"></div>'
			. '<div class="cds-product-body"><p class="eyebrow">Desk</p><h4 class="cds-h4">Aluminium monitor arm</h4>'
			. '<div class="cds-product-price"><span class="price-text">₹6,799</span></div></div>'
			. '<div class="cds-product-action"><button class="accent-button">Add to cart</button></div></div>';
		$b .= '</div>';

		return $b;
	}

	/**
	 * A single token card.
	 *
	 * @param array<string,string> $it
	 * @return string
	 */
	private function tokenCard( array $it ): string {
		$ob  = '<div class="panel cds-token" data-name="' . $this->esc( $it['short'] ) . '">';
		$ob .= '<div class="cds-token-head">';
		$ob .= '<span class="cds-swatch" style="--sw:' . $this->esc( $it['hex'] ) . ';--swd:' . $this->esc( $it['hexDark'] ) . '" role="img" aria-label="Swatch ' . $this->esc( $it['short'] ) . '"></span>';
		$ob .= '<span class="cds-token-name">' . $this->esc( $it['label'] ) . '</span>';
		$ob .= '</div>';
		$ob .= '<div class="cds-token-meta">';
		$ob .= '<div class="cds-value">--' . $this->esc( $it['name'] ) . '</div>';
		$ob .= '<div class="cds-value">' . $this->esc( $it['channels'] ) . '</div>';
		$ob .= '<div class="cds-token-hexes"><code>' . $this->esc( $it['hex'] ) . '</code><code class="cds-darkvalue">' . $this->esc( $it['hexDark'] ) . '</code></div>';
		$ob .= '</div>';
		$ob .= '<button class="cds-copy" type="button" data-copy="' . $this->esc( '--' . $it['name'] . ': ' . $it['channels'] . ';' ) . '">Copy</button>';
		$ob .= '</div>';
		return $ob;
	}

	/**
	 * Token <option> list for the checker selects (shows hex in label).
	 *
	 * @return string
	 */
	private function tokenOptions(): string {
		$out = '';
		foreach ( $this->tokens->toArray()['light'] as $name => $channels ) {
			if ( 0 !== strpos( $name, 'c-' ) ) {
				continue;
			}
			$short = substr( $name, 2 );
			$out .= '<option value="' . $this->esc( $short ) . '">' . $this->esc( $short . '  ' . Tokens::toHex( $channels ) ) . '</option>';
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Shell                                                              */
	/* ------------------------------------------------------------------ */

	private function header(): string {
		$b  = '<header class="surface-contrast cds-header">';
		$b .= '<div class="page-shell cds-header-inner">';
		$b .= '<div class="cds-brand"><span class="cds-brand-mark">' . $this->icon( 'cart' ) . '</span><span class="cds-brand-name">Cartly <em>DS</em></span></div>';
		$b .= '<nav class="cds-nav">';
		foreach ( array( 'overview' => 'Overview', 'tokens' => 'Tokens', 'pairs' => 'Contrast', 'type' => 'Type', 'components' => 'Components' ) as $id => $label ) {
			$b .= '<a href="#' . $this->esc( $id ) . '">' . $this->esc( $label ) . '</a>';
		}
		$b .= '</nav>';
		$b .= '<button class="icon-button cds-darkbtn" id="themeToggle" type="button" aria-label="Toggle dark mode">' . $this->icon( 'moon' ) . '</button>';
		$b .= '</div></header>';
		return $b;
	}

	private function footer(): string {
		return '<footer class="surface-contrast cds-footer"><div class="page-shell"><p>Generated by <code>bin/cartly styleguide</code> · pure PHP · '
			. 'no Node, no framework — a single self-contained file.</p></div></footer>';
	}

	private function styleguideCss(): string {
		return <<<CSS
.cds-header-inner { display:flex; align-items:center; gap:1.5rem; padding-top:1rem; padding-bottom:1rem; }
.cds-brand { display:flex; align-items:center; gap:0.5rem; color:rgb(var(--c-oncontrast)); font-weight:800; letter-spacing:0.04em; }
.cds-brand-mark { display:inline-flex; align-items:center; justify-content:center; width:2rem; height:2rem; border-radius:0.5rem; background:rgb(var(--c-accent)); color:rgb(var(--c-ink)); }
.cds-brand-name em { color:rgb(var(--c-accent)); font-style:normal; }
.cds-nav { display:flex; gap:0.75rem; margin-left:auto; flex-wrap:wrap; }
.cds-nav a { color:rgb(var(--c-oncontrast)); font-size:0.8125rem; font-weight:600; text-decoration:none; opacity:0.85; }
.cds-nav a:hover { opacity:1; color:rgb(var(--c-accent)); }
.cds-darkbtn { color:rgb(var(--c-oncontrast)); border:1px solid rgb(var(--c-oncontrast) / 0.2); }

.cds-main { padding-top:2rem; padding-bottom:4rem; }
.cds-display { font-family:'Inter Tight', Inter, sans-serif; font-size:clamp(2rem, 6vw, 3.5rem); font-weight:800; letter-spacing:-0.03em; margin:0.5rem 0 0.75rem; }
.cds-section { margin-top:3rem; }
.cds-section > .section-title { margin-bottom:1rem; }
.cds-h3 { margin:2rem 0 0.75rem; font-size:1.125rem; }
.cds-h4 { font-size:0.9375rem; margin:0 0 0.25rem; }
.cds-toolbar { display:flex; gap:0.75rem; align-items:center; border:1px solid rgb(var(--c-line)); border-radius:0.5rem; padding:0.75rem; margin-top:1rem; }
.cds-toolbar .input-control { max-width:24rem; }
.cds-toolbar-meta { margin-left:auto; font-size:0.75rem; color:rgb(var(--c-ink-muted)); }
.cds-label { display:block; font-size:0.6875rem; font-weight:700; text-transform:uppercase; letter-spacing:0.16em; color:rgb(var(--c-ink-muted)); margin-bottom:0.5rem; }
.cds-stack { display:flex; flex-direction:column; gap:0.75rem; }
.cds-stack--row { flex-direction:row; flex-wrap:wrap; align-items:center; }

.cds-token { padding:0.75rem; position:relative; }
.cds-token-head { display:flex; align-items:center; gap:0.75rem; }
.cds-swatch { --sw:#000; --swd:#fff; width:2.25rem; height:2.25rem; border-radius:0.5rem; border:1px solid rgb(var(--c-line)); background:var(--sw); flex-shrink:0; }
html.dark .cds-swatch { background:var(--swd); }
.cds-token-name { font-weight:600; font-size:0.875rem; }
.cds-token-meta { margin-top:0.5rem; display:flex; flex-direction:column; gap:0.15rem; }
.cds-token-hexes { display:flex; gap:0.5rem; }
.cds-token-hexes code { font-family:ui-monospace, monospace; font-size:0.75rem; }
.cds-token-hexes .cds-darkvalue { color:rgb(var(--c-ink-muted)); }
.cds-darkvalue:not(.cds-token-hexes code) { }
.cds-copy { position:absolute; top:0.75rem; right:0.75rem; border:1px solid rgb(var(--c-line)); background:rgb(var(--c-paper)); color:rgb(var(--c-ink-soft)); border-radius:0.375rem; font-size:0.6875rem; font-weight:600; padding:0.25rem 0.5rem; cursor:pointer; }
.cds-copy:hover { color:rgb(var(--c-brand)); border-color:rgb(var(--c-brand)); }

.cds-pairs { width:100%; border-collapse:collapse; border:1px solid rgb(var(--c-line)); border-radius:0.5rem; overflow:hidden; }
.cds-pairs th, .cds-pairs td { padding:0.75rem; text-align:left; border-bottom:1px solid rgb(var(--c-line)); }
.cds-pairs th { background:rgb(var(--c-canvas)); font-size:0.6875rem; text-transform:uppercase; letter-spacing:0.16em; color:rgb(var(--c-ink-muted)); }
.cds-pairs td { vertical-align:middle; font-size:0.8125rem; }
.cds-pairs code { background:rgb(var(--c-sunken)); border-radius:0.25rem; padding:0.1rem 0.35rem; }
.cds-dot { display:inline-block; width:0.75rem; height:0.75rem; border-radius:0.25rem; border:1px solid rgb(var(--c-line)); margin-right:0.5rem; vertical-align:middle; }
.cds-rating { border-radius:9999px; padding:0.15rem 0.5rem; font-size:0.6875rem; font-weight:700; }
.cds-rating.aaa { background:rgb(var(--c-success-soft)); color:rgb(var(--c-success-on)); }
.cds-rating.aa, .cds-rating.aalarge { background:rgb(var(--c-warning-soft)); color:rgb(var(--c-warning-on)); }
.cds-rating.fail { background:rgb(var(--c-danger-soft)); color:rgb(var(--c-danger-on)); }

.cds-check { display:flex; gap:1rem; align-items:center; margin-top:1rem; }
.cds-check-preview { display:flex; align-items:center; justify-content:center; width:12rem; height:6rem; border-radius:0.5rem; border:1px solid rgb(var(--c-line)); font-size:2.5rem; font-weight:800; }
.cds-check-ratio { font-size:1.75rem; font-weight:800; }
.cds-check-rating { font-size:0.8125rem; color:rgb(var(--c-ink-soft)); }

.cds-typescale { display:flex; flex-direction:column; gap:1rem; }
.cds-type-row { display:flex; align-items:baseline; gap:1.5rem; border-bottom:1px solid rgb(var(--c-line)); padding-bottom:0.75rem; }
.cds-type-spec { width:14rem; font-family:ui-monospace, monospace; font-size:0.75rem; color:rgb(var(--c-ink-muted)); flex-shrink:0; }
.cds-type-sample { font-family:Inter, system-ui, sans-serif; color:rgb(var(--c-ink)); }

.cds-surface-demo { padding:1.25rem; }
.cds-surface-demo--contrast { color:rgb(var(--c-oncontrast)); }
.cds-surface-demo--contrast .page-subtitle { color:rgb(var(--c-oncontrast)); opacity:0.8; }
.cds-product { padding:0.5rem; display:flex; flex-direction:column; gap:0.5rem; }
.cds-product-media { aspect-ratio:4/3; background:rgb(var(--c-sunken)); border-radius:0.5rem; display:flex; align-items:flex-start; padding:0.5rem; }
.cds-product-media--empty { background:linear-gradient(135deg, rgb(var(--c-sunken)), rgb(var(--c-canvas))); }
.cds-product-body { padding:0.25rem 0.25rem 0; }
.cds-product-price { display:flex; align-items:baseline; gap:0.5rem; margin-top:0.25rem; }
.cds-product-price del { font-size:0.75rem; color:rgb(var(--c-ink-muted)); }
.cds-product-action { margin-top:auto; }

.cds-footer { margin-top:4rem; padding-bottom:2rem; }
.cds-footer .page-shell { padding-top:2rem; }
.cds-footer p { color:rgb(var(--c-oncontrast)); font-size:0.8125rem; }
.cds-footer code { background:rgb(var(--c-contrast-lift)); padding:0.1rem 0.35rem; border-radius:0.25rem; }

@media (max-width: 767px) {
	.cds-nav { display:none; }
	.cds-check { flex-direction:column; align-items:flex-start; }
	.cds-type-row { flex-direction:column; gap:0.25rem; }
	.cds-type-spec { width:auto; }
}
CSS;
	}

	private function script(): string {
		return <<<JS
(function () {
  var root = document.documentElement;
  var toggle = document.getElementById('themeToggle');
  function apply(theme) {
    root.classList.toggle('dark', theme === 'dark');
    document.querySelector('body').setAttribute('data-theme', theme);
  }
  try {
    var stored = localStorage.getItem('cartly-ds-theme');
    var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
    apply(dark ? 'dark' : 'light');
  } catch (e) {}
  toggle.addEventListener('click', function () {
    var next = root.classList.contains('dark') ? 'light' : 'dark';
    apply(next);
    try { localStorage.setItem('cartly-ds-theme', next); } catch (e) {}
  });

  // Filter tokens.
  var filter = document.getElementById('filter');
  var count = document.getElementById('count');
  var cards = Array.prototype.slice.call(document.querySelectorAll('.cds-token'));
  var sections = Array.prototype.slice.call(document.querySelectorAll('[data-group]'));
  function applyFilter() {
    var q = (filter.value || '').trim().toLowerCase();
    var shown = 0;
    cards.forEach(function (c) {
      var name = (c.getAttribute('data-name') || '').toLowerCase();
      var text = (c.textContent || '').toLowerCase();
      var ok = !q || name.indexOf(q) !== -1 || text.indexOf(q) !== -1;
      c.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });
    sections.forEach(function (s) {
      var any = Array.prototype.some.call(s.querySelectorAll('.cds-token'), function (c) { return c.style.display !== 'none'; });
      s.style.display = any ? '' : 'none';
    });
    count.textContent = shown + ' tokens';
  }
  if (filter) { filter.addEventListener('input', applyFilter); applyFilter(); }

  // Copy token.
  Array.prototype.slice.call(document.querySelectorAll('.cds-copy')).forEach(function (btn) {
    btn.addEventListener('click', function () {
      var val = btn.getAttribute('data-copy') || '';
      function done() {
        btn.textContent = 'Copied';
        setTimeout(function () { btn.textContent = 'Copy'; }, 1200);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(val).then(done, done);
      } else {
        var ta = document.createElement('textarea');
        ta.value = val; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta); done();
      }
    });
  });

  // Contrast checker.
  var tokens = {};
  Array.prototype.slice.call(document.querySelectorAll('.cds-token')).forEach(function (c) {
    var name = c.getAttribute('data-name');
    var hex = c.querySelector('.cds-swap') || c.querySelector('.cds-token-hexes code');
    tokens[name] = { hex: hex ? hex.textContent.trim() : '#000' };
  });
  var fg = document.getElementById('fg'), bg = document.getElementById('bg');
  function lum(h) {
    h = h.replace('#','');
    if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
    var r = parseInt(h.slice(0,2),16)/255, g = parseInt(h.slice(2,4),16)/255, b = parseInt(h.slice(4,6),16)/255;
    function f(c){ return c <= 0.03928 ? c/12.92 : Math.pow((c+0.055)/1.055,2.4); }
    return 0.2126*f(r)+0.7152*f(g)+0.0722*f(b);
  }
  function contrast(a,b){ var l1=Math.max(lum(a),lum(b)), l2=Math.min(lum(a),lum(b)); return (l1+0.05)/(l2+0.05); }
  var preview = document.getElementById('checkPreview');
  var ratioEl = document.getElementById('checkRatio');
  var ratingEl = document.getElementById('checkRating');
  var textEl = document.getElementById('checkText');
  function updateChecker() {
    var fc = fg.options[fg.selectedIndex] ? fg.value : null;
    var bc = bg.options[bg.selectedIndex] ? bg.value : null;
    var fh = tokenHex(fc), bh = tokenHex(bc);
    preview.style.color = fh; preview.style.backgroundColor = bh;
    var r = contrast(fh, bh);
    ratioEl.textContent = Number(r).toFixed(2) + ' : 1';
    ratingEl.textContent = r >= 7 ? 'AAA' : r >= 4.5 ? 'AA' : r >= 3 ? 'AA Large' : 'Fail';
  }
  // We need token hex by short name; build from options label.
  function tokenHex(short) {
    var sel = fg; for (var i=0;i<sel.options.length;i++){ if (sel.options[i].value === short) { var lbl=sel.options[i].textContent||''; var m=lbl.match(/#[0-9a-fA-F]{6}/); return m ? m[0] : '#000'; } }
    return '#000';
  }
  if (fg && bg) { fg.addEventListener('change', updateChecker); bg.addEventListener('change', updateChecker); updateChecker(); }
})();
JS;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	private function esc( string $s ): string {
		return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * A small inline SVG icon set (subset used by the styleguide).
	 *
	 * @param string $name
	 * @return string
	 */
	private function icon( string $name ): string {
		$paths = array(
			'cart'   => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
			'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
			'menu'   => '<path d="M3 12h18M3 6h18M3 18h18"/>',
			'moon'   => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
		);
		$p = $paths[ $name ] ?? $paths['cart'];
		return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
	}
}
