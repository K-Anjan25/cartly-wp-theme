<?php
/**
 * PayKaro — web entry / router (auth + multi-tenant).
 *
 * Reads $o (method), $g (GET), $p (POST) + the request path, all injected by
 * the bridge. Builds an HTTP response array:
 *   ['status'=>int, 'type'=>mime, 'location'=>?string, 'cookies'=>[[name,value,ttl]], 'body'=>string]
 *
 * Two ways to run it:
 *
 *  - Through the Node bridge (bridge/serve.mjs), which defines PAYKARO_BRIDGE
 *    and parses that JSON envelope back into a real HTTP response.
 *  - Natively, e.g. `php -S 0.0.0.0:8080 -t public public/router.php`. In that
 *    case the JSON envelope is captured in an output buffer and turned into a
 *    real HTTP response here (status, Content-Type, Location, Set-Cookie, body)
 *    so the browser gets HTML instead of raw JSON.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '0' );

/*
 * Native (non-bridge) mode: buffer the JSON envelope the app echoes and emit
 * it as a real HTTP response at shutdown.
 */
if ( ! defined( 'PAYKARO_BRIDGE' ) && ! defined( 'PAYKARO_NATIVE' ) ) {
	define( 'PAYKARO_NATIVE', true );

	function paykaro_emit_native(): void {
		$raw = ob_get_level() > 0 ? ob_get_clean() : '';
		$res = json_decode( (string) $raw, true );

		if ( ! is_array( $res ) || ! isset( $res['body'] ) ) {
			// Not our envelope (fatal error, stray output, ...): pass it through.
			if ( ! headers_sent() && '' === trim( (string) $raw ) ) {
				http_response_code( 500 );
				header( 'Content-Type: text/plain; charset=utf-8' );
				echo "PayKaro: the app produced no response.\n";
				return;
			}
			echo $raw;
			return;
		}

		if ( ! headers_sent() ) {
			foreach ( (array) ( $res['cookies'] ?? array() ) as $c ) {
				$name = (string) ( $c[0] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$ttl     = (int) ( $c[2] ?? 0 );
				$expires = $ttl > 0 ? time() + $ttl : ( '' === (string) ( $c[1] ?? '' ) ? time() - 3600 : 0 );
				setcookie(
					$name,
					(string) ( $c[1] ?? '' ),
					array(
						'expires'  => $expires,
						'path'     => '/',
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}
			if ( ! empty( $res['location'] ) ) {
				header( 'Location: ' . $res['location'], true, (int) ( $res['status'] ?? 302 ) );
				return;
			}
			http_response_code( (int) ( $res['status'] ?? 200 ) );
			header( 'Content-Type: ' . ( $res['type'] ?? 'text/html; charset=utf-8' ) );
		}

		echo (string) $res['body'];
	}

	ob_start();
	register_shutdown_function( 'paykaro_emit_native' );
}

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../PayKaro.php';

$config = require __DIR__ . '/../config.php';
$app    = new PayKaro( $config, paykaro_db() );

define( 'COOKIE_NAME', 'pk_session' );

/* ------------------------------------------------------------------ */
/* Helpers                                                            */
/* ------------------------------------------------------------------ */

function e( $s ): string {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

/**
 * Format a number as Indian-grouped rupees, e.g. 1234567 → "₹12,34,567".
 * Pure text on purpose: callers wrap this in e() when rendering, so it must
 * never contain markup (it used to embed an <svg> icon, which every e()
 * call site rendered as literal escaped HTML).
 */
function money( $n ): string {
	$n   = (float) $n;
	$neg = $n < 0 ? '-' : '';
	$n   = abs( $n );
	$int = (int) floor( $n );
	$dec = (int) round( ( $n - $int ) * 100 );
	if ( $dec >= 100 ) { $int++; $dec -= 100; }
	$s = (string) $int;
	if ( strlen( $s ) > 3 ) {
		$last3 = substr( $s, -3 );
		$rest  = substr( $s, 0, -3 );
		if ( '' !== $rest ) {
			$rest = preg_replace( '/\B(?=(\d{2})+(?!\d))/', ',', $rest );
		}
		$s = $rest . ',' . $last3;
	}
	$out = '₹' . $neg . $s;
	if ( $dec > 0 ) {
		$out .= '.' . str_pad( (string) $dec, 2, '0', STR_PAD_LEFT );
	}
	return $out;
}

function badge( string $status ): string {
	$map = array(
		'raised'   => array( 'Raised', 'gold' ),
		'accepted' => array( 'Accepted', 'info' ),
		'financed' => array( 'Financed', 'success' ),
		'settled'  => array( 'Settled', 'success' ),
		'disputed' => array( 'Disputed', 'danger' ),
		'draft'    => array( 'Draft', 'neutral' ),
	);
	$m = $map[ $status ] ?? array( ucfirst( $status ), 'neutral' );
	return '<span class="pkg-badge pkg-badge--' . $m[1] . '">' . e( $m[0] ) . '</span>';
}

function tredsLabel( string $t ): string {
	$map = array(
		'ready'                 => array( 'TReDS ready', 'success' ),
		'pending_buyer_onboard' => array( 'Buyer not onboard', 'warning' ),
		'financed'              => array( 'Financed', 'success' ),
		'ineligible'            => array( 'Ineligible', 'danger' ),
		'na'                    => array( '—', 'neutral' ),
	);
	$m = $map[ $t ] ?? array( str_replace( '_', ' ', $t ), 'neutral' );
	return '<span class="pkg-badge pkg-badge--' . $m[1] . '">' . e( $m[0] ) . '</span>';
}

function progress( int $score ): string {
	$color = $score >= 85 ? 'success' : ( $score >= 60 ? 'info' : 'danger' );
	$label = $score >= 85 ? 'Ready' : ( $score >= 60 ? 'Partial' : 'Gaps' );
	return '<span class="pkg-progress-bar pkg-progress-bar--' . $color . '" style="width:' . $score . '%"></span><span class="pkg-progress-label">' . $label . ' · ' . $score . '</span>';
}

function sideIcon( string $key ): string {
	$i = array(
		'dashboard' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
		'invoices' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"/><path d="M14 2v5h5"/><path d="M9 13h6M9 17h6"/></svg>',
		'buyers' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-4a4 4 0 0 1 4-4h2a4 4 0 0 1 4 4v4"/><circle cx="9" cy="7" r="3.5"/><path d="M16 11a4 4 0 0 1 4 4v2"/><circle cx="17" cy="6" r="2.6"/></svg>',
		'treds' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20"/><path d="M6 14h3M13 14h5"/></svg>',
		'reports' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 20h18"/><path d="M6 16v-5M11 16V8M16 16v-3M21 16V5"/></svg>',
		'settings' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.09a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
	);
	return $i[ $key ] ?? '•';
}

// Two-tone PayKaro wordmark: "Pay" in ink + "Karo" in deep blue.
function brandWordmark(): string {
	$name = 'PayKaro';
	// Split on the camelCase boundary: "Pay" + "Karo".
	$parts = preg_split( '/(?<=[a-z])(?=[A-Z])/', $name );
	if ( 2 === count( $parts ) ) {
		return '<span>' . e( $parts[0] ) . '</span><span class="b">' . e( $parts[1] ) . '</span>';
	}
	return e( $name );
}

// PayKaro logo mark: a deep-blue rounded tile with a warm-gold "₹" mark.
// Matches the new editorial header (blue + gold two-accent system).
function logoMark( string $class = 'pkg-brand-logo' ): string {
	$svg = '<svg width="34" height="34" viewBox="0 0 34 34" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
		. '<rect width="34" height="34" rx="9" fill="#0b3b7a"/>'
		. '<path d="M20.5 8h-7.6v4h7.6a2.5 2.5 0 0 1 0 5h-7.6" stroke="#f5a623" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
		. '<path d="M12.9 12v14M16.3 21h5.6M12.9 21h5.6" stroke="#f5a623" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
		. '</svg>';
	return '<span class="' . e( $class ) . '">' . $svg . '</span>';
}

// Health-status badge: Paid / Due Soon / Overdue (bucket) — matches the reference table.
function healthBadge( array $i ): string {
	if ( 'settled' === $i['status'] ) {
		return '<span class="pkg-badge pkg-badge--success">Paid</span>';
	}
	$d = (int) ( $i['overdue_days'] ?? 0 );
	if ( $d <= 0 ) {
		return '<span class="pkg-badge pkg-badge--info">Due Soon</span>';
	}
	if ( $d <= 30 )  { return '<span class="pkg-badge pkg-badge--danger">Overdue (1-30)</span>'; }
	if ( $d <= 60 )  { return '<span class="pkg-badge pkg-badge--danger">Overdue (31-60)</span>'; }
	if ( $d <= 90 )  { return '<span class="pkg-badge pkg-badge--danger">Overdue (61-90)</span>'; }
	return '<span class="pkg-badge pkg-badge--danger">Overdue (90+)</span>';
}

// One KPI card.
function kpiCard( string $label, string $value, string $trend = '', string $icon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>', string $tone = 'blue' ): string {
	$trendHtml = $trend ? '<div class="pkg-kpi-trend">' . $trend . '</div>' : '<div class="pkg-kpi-trend" style="visibility:hidden">&nbsp;</div>';
	return '<div class="pkg-card pkg-kpi"><div class="pkg-kpi-head"><div class="pkg-kpi-ico pkg-kpi-ico--' . $tone . '">' . $icon . '</div><div class="pkg-kpi-label">' . e( $label ) . '</div></div>'
		. '<div class="pkg-kpi-value num">' . $value . '</div>'
		. $trendHtml . '</div>';
}

/* ------------------------------------------------------------------ */
/* Layout                                                             */
/* ------------------------------------------------------------------ */

function layout( array $config, string $title, string $content, string $active, array $stats, array $user ): string {
	$nav = array(
		'dashboard' => array( '/', 'Overview' ),
		'invoices'  => array( '/invoices', 'Invoices' ),
		'buyers'    => array( '/buyers', 'Customers' ),
		'treds'     => array( '/treds', 'Finance' ),
		'reports'   => array( '/reports', 'Reports' ),
		'settings'  => array( '/settings', 'Settings' ),
	);
	$b = $config['business'] ?? 'My Business';
	$initial = strtoupper( substr( ( $user['name'] ?? 'P' ), 0, 1 ) );
	$role = 'owner';
	if ( ! empty( $user['role'] ) ) { $role = ucfirst( $user['role'] ); }
	ob_start();
	?>
	<!doctype html>
	<html lang="en">
	<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo e( $title ); ?> — <?php echo e( $config['name'] ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="/assets/app.css">
	</head>
	<body class="pkg">
	<div class="pkg-shell">

			<!-- Utility bar (slate, small) -->
		<div class="pkg-util">
			<div class="pkg-util-inner">
				<span class="pkg-util-tag"><span class="dot"></span> Demo workspace</span>
				<span class="pkg-util-sep"></span>
				<a class="pkg-util-hide" href="/pricing">Pricing</a>
				<span class="pkg-util-sep"></span>
				<a class="pkg-util-hide" href="/help">Help</a>
				<div class="pkg-util-right">
					<a href="/contact">Contact</a>
					<span class="pkg-util-sep"></span>
					<span class="pkg-util-hide">Welcome, <?php echo e( $b ); ?></span>
				</div>
			</div>
		</div>

		<!-- Main header (white, two vertical bars + logo + nav + search) -->
		<header class="pkg-head">
			<div class="pkg-head-inner">
				<div class="pkg-bars" aria-hidden="true">
					<span class="bar-thick"></span>
					<span class="bar-thin"></span>
				</div>
				<a class="pkg-brand" href="/" aria-label="<?php echo e( $config['name'] ); ?> home">
					<?php echo logoMark(); ?>
					<div class="pkg-brand-text">
						<div class="name"><?php echo brandWordmark(); ?></div>
						<div class="sub">MSME receivables · <?php echo e( $b ); ?></div>
					</div>
				</a>
				<nav class="pkg-nav" aria-label="Primary">
					<?php foreach ( $nav as $key => $n ) : ?>
						<a class="<?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo $n[0]; ?>"><?php echo e( $n[1] ); ?></a>
					<?php endforeach; ?>
				</nav>
				<div class="pkg-head-right">
					<label class="pkg-searchbox" aria-label="Search">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
						<input type="search" placeholder="Search invoices, buyers…">
					</label>
					<a class="pkg-btn pkg-btn--sm pkg-btn--outline" href="/pricing">Pricing</a>
					<form method="post" action="/logout" style="margin:0;">
						<button class="pkg-btn pkg-btn--sm pkg-btn--gold" type="submit" title="Log out">Log out</button>
					</form>
					<button class="pkg-menumob" type="button" aria-label="Open menu" aria-controls="pkg-drawer" aria-expanded="false" onclick="var d=document.getElementById('pkg-drawer');var ex=this.getAttribute('aria-expanded')==='true';this.setAttribute('aria-expanded',String(!ex));d.classList.toggle('is-open',!ex);">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
					</button>
				</div>
			</div>
			<div class="pkg-headline" aria-hidden="true"></div>
			<nav class="pkg-drawer" id="pkg-drawer" aria-label="Mobile navigation">
				<?php foreach ( $nav as $key => $n ) : ?>
					<a class="<?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo $n[0]; ?>"><?php echo e( $n[1] ); ?></a>
				<?php endforeach; ?>
				<a href="/pricing">Pricing</a>
			</nav>
		</header>

		<!-- Main content -->
		<div class="pkg-main-wrap">
			<main class="pkg-main"><?php echo $content; ?></main>
			<footer class="pkg-footer">© <?php echo e( date( 'Y' ) ); ?> <?php echo e( $config['name'] ); ?> · <?php echo e( $config['tagline'] ); ?> · <a href="/pricing" style="color:var(--n-blue);font-weight:700;">See pricing</a></footer>
		</div>
	</div>

	<script>
	(function(){
		var root=document.documentElement;
		function apply(t){root.classList.toggle('dark',t==='dark');}
		try{var s=localStorage.getItem('pk-theme');apply(s==='dark'||(s!=='light'&&window.matchMedia('(prefers-color-scheme: dark)').matches));}catch(e){}
	})();
	</script>
	</body>
	</html>
	<?php
	return ob_get_clean();
}

function loginPage( array $config ): string {
	ob_start();
	?>
	<!doctype html>
	<html lang="en">
	<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Sign in — <?php echo e( $config['name'] ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="/assets/app.css">
	</head>
	<body class="page" style="margin:0;">
	<div class="auth">
		<div class="auth-side">
			<div style="position:relative;z-index:1;">
				<a href="/" class="auth-brand" aria-label="Home">
					<?php echo logoMark(); ?>
					<div>
						<div class="auth-name"><span>Pay</span><span class="b">Karo</span></div>
						<div class="auth-tag"><?php echo e( $config['tagline'] ); ?></div>
					</div>
				</a>
			</div>
			<div style="position:relative;z-index:1;">
				<div class="auth-headline">Turn invoice mess into<br>finance-ready receivables.</div>
				<p class="auth-copy">One pipeline for every invoice — evidence complete, interest computed, and ready to finance or claim the moment it's overdue.</p>
				<div class="auth-chips">
					<span class="auth-chip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Multi-tenant secured</span>
					<span class="auth-chip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.09a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg> TReDS-ready</span>
					<span class="auth-chip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> 45-day due window</span>
				</div>
			</div>
			<div class="auth-foot">&copy; <?php echo e( $config['name'] ); ?> · demo</div>
		</div>
		<div class="auth-form">
			<div class="auth-wrap">
				<h1 class="auth-h">Welcome back</h1>
				<p class="auth-sub">Sign in to your workspace. Each user sees only their own business's receivables.</p>

				<?php if ( ! empty( $_GET['err'] ) ) : ?>
					<?php if ( 'oauth' === ( $_GET['err'] ?? '' ) ) : ?><div class="auth-err">Google sign-in didn't complete. Please try again, or use email &amp; password.</div>
					<?php else : ?><div class="auth-err">Invalid email or password. Please check your details and try again.</div><?php endif; ?>
				<?php endif; ?>

				<?php echo googleButtonHtml( $config ); ?>
				<?php if ( googleEnabled( $config ) ) : ?><div class="auth-or">or</div><?php endif; ?>

				<form method="post" action="/login">
					<label class="auth-lbl">Email</label><input class="auth-in" type="email" name="email" required placeholder="you@company.in" autofocus>
					<label class="auth-lbl">Password</label><input class="auth-in" type="password" name="password" required placeholder="••••••••">
					<button class="auth-cta" type="submit">Sign in →</button>
				</form>

				<div class="auth-alt">New to <?php echo e( $config['name'] ); ?>? <a href="/signup">Create an account</a></div>
			</div>
		</div>
	</div>
	</body>
	</html>
	<?php
	return ob_get_clean();
}

/* Public sign-up page — mirrors the login split layout. */
function signupPage( array $config ): string {
	ob_start();
	?>
	<!doctype html>
	<html lang="en">
	<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Create account — <?php echo e( $config['name'] ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="/assets/app.css">
	</head>
	<body class="page" style="margin:0;">
	<div class="auth">
		<div class="auth-side">
			<div style="position:relative;z-index:1;">
				<a href="/" class="auth-brand" aria-label="Home">
					<?php echo logoMark(); ?>
					<div>
						<div class="auth-name"><span>Pay</span><span class="b">Karo</span></div>
						<div class="auth-tag"><?php echo e( $config['tagline'] ); ?></div>
					</div>
				</a>
			</div>
			<div style="position:relative;z-index:1;">
				<div class="auth-headline">Turn invoice mess into<br>finance-ready receivables.</div>
				<p class="auth-copy">Create an account, add a buyer, and raise your first invoice in minutes. Your receivables, interest and evidence — all in one pipeline.</p>
				<div class="auth-chips">
					<span class="auth-chip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Multi-tenant secured</span>
					<span class="auth-chip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.09a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg> TReDS-ready</span>
					<span class="auth-chip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> 45-day due window</span>
				</div>
			</div>
			<div class="auth-foot">&copy; <?php echo e( $config['name'] ); ?> · demo</div>
		</div>
		<div class="auth-form">
			<div class="auth-wrap">
				<h1 class="auth-h">Create your account</h1>
				<p class="auth-sub">Set up your business and you're in. Each user sees only their own business's receivables.</p>

				<?php if ( ! empty( $_GET['err'] ) ) : ?><div class="auth-err"><?php echo 'taken' === ( $_GET['err'] ?? '' ) ? 'That email is already registered. Try signing in instead.' : 'Please fill in every field correctly and try again.'; ?></div><?php endif; ?>

				<?php echo googleButtonHtml( $config ); ?>
				<?php if ( googleEnabled( $config ) ) : ?><div class="auth-or">or</div><?php endif; ?>

				<form method="post" action="/signup">
					<label class="auth-lbl">Business name</label><input class="auth-in" type="text" name="business_name" placeholder="Your company Pvt Ltd" autofocus>
					<label class="auth-lbl">Your name</label><input class="auth-in" type="text" name="name" placeholder="Full name" required>
					<label class="auth-lbl">Work email</label><input class="auth-in" type="email" name="email" placeholder="you@company.in" required>
					<label class="auth-lbl">Password</label><input class="auth-in" type="password" name="password" placeholder="Create a password" required minlength="8">
					<button class="auth-cta" type="submit">Create account →</button>
				</form>

				<div class="auth-alt">Already have an account? <a href="/login">Sign in</a></div>
			</div>
		</div>
	</div>
	</body>
	</html>
	<?php
	return ob_get_clean();
}

/* News & updates — single source of truth for the landing-page cards and the
   standalone /news/{slug} article pages. Body blocks: array( 'p'|'h2', text ). */
function paykaroNews(): array {
	return array(
		array(
			'slug'    => 'evidence-checklist',
			'tag'     => 'Product',
			'date'    => '02 Sep 2026',
			'title'   => 'Every invoice, dated and dated twice.',
			'excerpt' => 'The new evidence checklist ties purchase order, delivery ack, GRN and GST copy to each invoice — and refuses to mark an invoice "ready" until the trail is complete.',
			'image'   => '/assets/img/card-invoices.jpg',
			'alt'     => 'Invoice paperwork on a desk',
			'body'    => array(
				array( 'p', 'When a buyer delays a payment, almost every dispute comes down to one question: prove when. When was the invoice raised? When were the goods delivered? When did the buyer accept them? Those dates decide when the statutory interest clock starts — and whether a delayed-payment claim stands or collapses.' ),
				array( 'p', 'The heart of PayKaro has always been dates. Starting this week, the evidence behind those dates is a first-class part of every invoice.' ),
				array( 'h2', 'One checklist, four documents, zero arguments' ),
				array( 'p', 'Every invoice now carries an evidence checklist: purchase order, delivery acknowledgement, goods receipt note (GRN) and a GST-valid invoice copy — plus an optional contract. Each item is a dated fact you confirm as the paperwork arrives, not a folder you hope to find later.' ),
				array( 'p', 'The checklist feeds the invoice\'s readiness score directly. Evidence completeness carries 70% of the score; the buyer\'s TReDS onboarding status carries most of the rest. An invoice only reaches "ready to finance" when the trail is complete — so the finance queue can separate what can move today from what\'s held back by one missing GRN.' ),
				array( 'h2', 'Why it matters for claims' ),
				array( 'p', 'Under the MSMED framework, interest on delayed payments runs at three times the bank rate, calculated from the agreed date or deemed acceptance — but only if you can prove acceptance happened. A delivery acknowledgement with a date on it turns "they took the goods in July" into a number the forum accepts.' ),
				array( 'p', 'The claim packet builder assembles the checklist into a filing-ready summary: invoice, buyer, amounts, days overdue, interest due and the evidence trail — with the deadline for the MSEFC forum or arbitration computed from the due date.' ),
				array( 'h2', 'Try it in the demo' ),
				array( 'p', 'Sign in to the demo workspace, open any invoice and tick the evidence items. Watch the readiness score and the finance queue react immediately.' ),
			),
		),
		array(
			'slug'    => 'metrow-ceramics-financing',
			'tag'     => 'Customers',
			'date'    => '27 Aug 2026',
			'title'   => 'How MetRow Ceramics financed 60% of receivables in week one.',
			'excerpt' => 'When the buyer\'s TReDS onboarding cleared, four invoices were ready to discount the same afternoon. Here\'s the workflow that made it possible.',
			'image'   => '/assets/img/card-team.jpg',
			'alt'     => 'Team collaborating in a modern office',
			'body'    => array(
				array( 'p', 'MetRow Ceramics supplies precision ceramic components to Delhi Metro\'s vendor chain and to private real-estate builders. Like most small manufacturers, they ran receivables from a spreadsheet, a WhatsApp folder and memory. In their first week on PayKaro, they converted their largest open invoice into cash.' ),
				array( 'h2', 'Day one: get the book in' ),
				array( 'p', 'Farhan raised three invoices — two against Delhi Metro Rail Corp, a PSU buyer already onboarded on TReDS, and one against a private builder. Each went in with its purchase order and delivery acknowledgement; the GRNs were confirmed the same evening. Total open receivables: a little over ₹9.2 lakh.' ),
				array( 'h2', 'The queue did the triage' ),
				array( 'p', 'The finance queue flagged the largest Delhi Metro invoice "ready to finance" — evidence complete, buyer onboarded. The builder invoice stayed out of the queue\'s ready pile: that buyer isn\'t on TReDS, so discounting it was never on the table this quarter. Instead, PayKaro kept accruing statutory interest on it as it aged past the 45-day window.' ),
				array( 'h2', 'Same week, cash in the bank' ),
				array( 'p', 'With the packet complete, their financier discounted the Delhi Metro invoice within days — no back-and-forth over documents, because every document was already attached, dated and checked. The disbursal was recorded against the invoice, moved it to "financed", and left the balance sheet honest.' ),
				array( 'p', 'The pattern is the point: complete evidence makes an invoice financeable the day it\'s raised. A TReDS-ready buyer makes it discountable. Everything else keeps earning interest.' ),
				array( 'p', 'MetRow Ceramics is one of the two demo businesses seeded in the PayKaro workspace — sign in with the MetRow login and this exact book is sitting in the data.' ),
			),
		),
		array(
			'slug'    => 'msme-rules-2026',
			'tag'     => 'Regulation',
			'date'    => '21 Aug 2026',
			'title'   => 'The 2026 MSME rules: what changes for suppliers this quarter.',
			'excerpt' => 'TReDS mandates tighten for CPSE buyers and the delayed-payment forum gets teeth. We break down what it means for your invoices.',
			'image'   => '/assets/img/impact-growth.jpg',
			'alt'     => 'Analyst reviewing growth charts on a laptop',
			'body'    => array(
				array( 'p', 'The 2026 MSME Development (Amendment) Bill has cleared committee, and it changes the arithmetic for every small supplier in India. Three shifts matter this quarter.' ),
				array( 'h2', '1. TReDS mandates tighten for CPSE buyers' ),
				array( 'p', 'Central public sector enterprises above the notification threshold are required to onboard and transact on TReDS. For suppliers that is good news with a catch: an invoice can only be discounted when both parties are onboarded. Knowing which of your buyers are ready is now a financing decision, not trivia.' ),
				array( 'h2', '2. The delayed-payment forum gets teeth' ),
				array( 'p', 'The MSEFC forum process is time-bound, and statutory interest on delayed payments — three times the prevailing bank rate, applied from the reference date — is enforced rather than theoretical. A supplier with dated evidence of delivery and acceptance walks in with leverage. One without it negotiates with hope.' ),
				array( 'h2', '3. The 45-day window is the default' ),
				array( 'p', 'Payments to MSMEs fall due within 45 days unless a written agreement says otherwise — and "otherwise" cannot be worse than the buyer\'s own standards for similar purchases. Ageing should be computed from the invoice itself, not reconstructed months later.' ),
				array( 'h2', 'What to do this quarter' ),
				array( 'p', 'One: audit your top buyers\' TReDS status before you plan financing around them. Two: close the evidence gaps on every open invoice — purchase order, delivery acknowledgement, GRN, GST-valid copy. Three: compute the interest you are owed from the invoice dates, so the number is ready the day you need it.' ),
				array( 'p', 'PayKaro bakes all three in: a 45-day due window on every invoice, daily interest accrual at three times the bank rate, a TReDS queue that separates ready from blocked, and a claim packet with the forum deadline computed for you.' ),
				array( 'p', 'This article is a plain-language summary written for the PayKaro demo workspace, not legal advice. The interest rate (3× a 6.5% bank rate) and windows shown in the demo follow the app\'s configuration.' ),
			),
		),
	);
}

/* Standalone news article page (public, /news/{slug}). */
function newsArticlePage( array $config, array $article ): string {
	ob_start();
	?>
	<!doctype html>
	<html lang="en">
	<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo e( $article['title'] ); ?> — <?php echo e( $config['name'] ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="/assets/app.css">
	</head>
	<body class="page">
	<div class="pkg-util">
		<div class="pkg-util-inner">
			<span class="pkg-util-tag"><span class="dot"></span> MSME receivables</span>
			<div class="pkg-util-right">
				<a href="/#news">All news</a>
				<span class="pkg-util-sep"></span>
				<a href="/">Back to home</a>
				<span class="pkg-util-sep"></span>
				<a href="/login">Sign in</a>
			</div>
		</div>
	</div>
	<header class="pkg-head">
		<div class="pkg-head-inner">
			<div class="pkg-bars" aria-hidden="true">
				<span class="bar-thick"></span>
				<span class="bar-thin"></span>
			</div>
			<a class="pkg-brand" href="/" aria-label="PayKaro home">
				<?php echo logoMark(); ?>
				<div class="pkg-brand-text">
					<div class="name"><?php echo brandWordmark(); ?></div>
					<div class="sub">MSME invoice &amp; receivables tracker</div>
				</div>
			</a>
			<nav class="pkg-nav" aria-label="Primary">
				<a href="/#workflow">Workflow</a>
				<a href="/#finance">Financing</a>
				<a class="is-active" href="/#news">News</a>
				<a href="/#impact">Impact</a>
				<a href="/pricing">Pricing</a>
			</nav>
			<div class="pkg-head-right">
				<a class="pbtn pbtn-outline pbtn-sm" href="/login">Sign in</a>
				<a class="pbtn pbtn-primary pbtn-sm" href="/signup">Get started</a>
			</div>
		</div>
		<div class="pkg-headline" aria-hidden="true"></div>
	</header>
	<main>
		<section class="sec" style="padding:3.2rem 0 3.5rem;">
			<div class="container">
				<a class="pbtn pbtn-outline pbtn-sm" href="/#news">← All news</a>
				<div class="article-wrap">
					<p class="article-meta"><span class="tag"><?php echo e( $article['tag'] ); ?></span> <?php echo e( $article['date'] ); ?></p>
					<h1 class="article-title"><?php echo e( $article['title'] ); ?></h1>
					<p class="article-dek"><?php echo e( $article['excerpt'] ); ?></p>
				</div>
				<div class="article-hero">
					<img src="<?php echo e( $article['image'] ); ?>" alt="<?php echo e( $article['alt'] ); ?>">
				</div>
				<div class="article-body">
					<?php foreach ( $article['body'] as $block ) : ?>
						<?php if ( 'h2' === $block[0] ) : ?>
							<h2><?php echo e( $block[1] ); ?></h2>
						<?php else : ?>
							<p><?php echo e( $block[1] ); ?></p>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
				<div class="article-cta">
					<p class="eyebrow eyebrow--on-dark">See it with your own invoices</p>
					<h2 class="display">Turn your receivables into finance-ready assets.</h2>
					<p>Track what's owed, what's overdue and what you could finance today — with the evidence and interest numbers that make a claim stand.</p>
					<div style="margin-top:1.3rem;display:flex;gap:.7rem;justify-content:center;flex-wrap:wrap;">
						<a class="pbtn pbtn-primary" href="/signup">Start free</a>
						<a class="pbtn pbtn-ghost" href="/login">Sign in to the demo</a>
					</div>
				</div>
			</div>
		</section>
	</main>
	<footer class="page-footer">
		<div class="page-footer-bottom">
			<span>© <?php echo e( date( 'Y' ) ); ?> PayKaro · Made for India's MSMEs</span>
			<span><a href="/#news" style="color:var(--n-gold);">More news</a> · <a href="/" style="color:var(--n-gold);">Back to home</a></span>
		</div>
	</footer>
	</body>
	</html>
	<?php
	return ob_get_clean();
}

/* Public landing page — editorial / UFL-inspired home. */
function landingPage( array $config ): string {
	ob_start();
	?>
	<!doctype html>
	<html lang="en">
	<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>PayKaro — Make every invoice count</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="/assets/app.css">
	</head>
	<body class="page">

	<!-- Utility bar -->
	<div class="pkg-util">
		<div class="pkg-util-inner">
			<span class="pkg-util-tag"><span class="dot"></span> MSME receivables</span>
			<span class="pkg-util-sep"></span>
			<a class="pkg-util-hide" href="#workflow">Workflow</a>
			<span class="pkg-util-sep"></span>
			<a class="pkg-util-hide" href="#finance">Financing</a>
			<span class="pkg-util-sep"></span>
			<a class="pkg-util-hide" href="/pricing">Pricing</a>
			<div class="pkg-util-right">
				<a href="/contact">Contact</a>
				<span class="pkg-util-sep"></span>
				<a href="/login">Sign in</a>
			</div>
		</div>
	</div>

	<!-- Main header -->
	<header class="pkg-head">
		<div class="pkg-head-inner">
			<div class="pkg-bars" aria-hidden="true">
				<span class="bar-thick"></span>
				<span class="bar-thin"></span>
			</div>
			<a class="pkg-brand" href="/" aria-label="PayKaro home">
				<?php echo logoMark(); ?>
				<div class="pkg-brand-text">
					<div class="name"><?php echo brandWordmark(); ?></div>
					<div class="sub">MSME invoice &amp; receivables tracker</div>
				</div>
			</a>
			<nav class="pkg-nav" aria-label="Primary">
				<a href="#workflow">Workflow</a>
				<a href="#finance">Financing</a>
				<a href="#news">News</a>
				<a href="#impact">Impact</a>
				<a href="#claims">Claims</a>
			</nav>
			<div class="pkg-head-right">
				<label class="pkg-searchbox" aria-label="Search">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
					<input type="search" placeholder="Search…">
				</label>
				<a class="pbtn pbtn-outline pbtn-sm" href="/login">Sign in</a>
				<a class="pbtn pbtn-primary pbtn-sm" href="/signup">Get started</a>
			</div>
		</div>
		<div class="pkg-headline" aria-hidden="true"></div>
	</header>

	<main id="top">

		<!-- HERO -->
		<section class="hero hero-grid">
			<div class="container">
				<div class="hero-inner">
					<div class="hero-text">
						<div class="hero-eyebrow"><span class="dot"></span> MSME receivables, simplified</div>
						<h1 class="hero-title display">Make every<br><em>invoice</em> <span class="accent">count.</span></h1>
						<p class="hero-lede">PayKaro turns an invoice into a finance-ready asset — one pipeline for what's owed, what's overdue, and what you could finance today, with the evidence and interest numbers that make a claim actually stand.</p>
						<div class="hero-cta">
							<a class="pbtn pbtn-primary pbtn-lg" href="/signup">Try PayKaro free</a>
							<a class="pbtn pbtn-outline pbtn-lg" href="#workflow">See how it works</a>
						</div>
						<div class="hero-trust">
							<span class="t"><span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 6v6c0 5 3.5 9.5 8 10 4.5-.5 8-5 8-10V6l-8-4z"/><path d="m9 12 2 2 4-4"/></svg></span> Statutory interest computed</span>
							<span class="t"><span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"/><path d="M14 2v5h5"/><path d="m9 14 2 2 4-4"/></svg></span> TReDS-ready finance queue</span>
							<span class="t"><span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span> MSEFC claim packets</span>
						</div>
					</div>
					<div class="hero-art">
						<div class="hero-bars" aria-hidden="true">
							<span class="b-thick"></span>
							<span class="b-thin"></span>
						</div>
						<img class="hero-image" src="/assets/img/hero-owner.jpg" alt="MSME factory owner, India">
						<div class="hero-card hero-card-1">
							<p class="pip">Live queue</p>
							<p class="hero-card-label" style="margin-top:.4rem;">Outstanding today</p>
							<p class="hero-card-value num">₹42.8L</p>
							<p class="hero-card-note">15 invoices · 3 buyers</p>
						</div>
						<div class="hero-card hero-card-2">
							<p class="hero-card-label">Ready to finance</p>
							<p class="hero-card-value num">₹18.2L</p>
							<p class="hero-card-note">buyers on TReDS</p>
						</div>
					</div>
				</div>
			</div>
		</section>

		<!-- STAT BAR -->
		<section class="statbar">
			<div class="container">
				<div class="statbar-inner">
					<div class="statbar-item"><p class="v">₹4.2Cr+</p><p class="l">Receivables tracked</p></div>
					<div class="statbar-item"><p class="v">45 days</p><p class="l">MSME due window</p></div>
					<div class="statbar-item"><p class="v">3×</p><p class="l">Bank-rate interest</p></div>
					<div class="statbar-item"><p class="v">60s</p><p class="l">To onboard</p></div>
				</div>
			</div>
		</section>

		<!-- WORKFLOW (4 step pipeline) -->
		<section id="workflow" class="sec">
			<div class="container">
				<div class="sec-head">
					<div>
						<p class="eyebrow">One pipeline</p>
						<h2 class="display">Raised → accepted → financed → settled</h2>
					</div>
					<p class="lede">No more scattered spreadsheets. Every invoice moves through the same pipeline with a live balance and accruing statutory interest — so you always know where the money is.</p>
				</div>
				<div class="cards-row">
					<div class="step-card"><span class="num">01</span><h3>Raised</h3><p>Log the invoice from day one — GST-valid copy, dates and terms all in one place.</p></div>
					<div class="step-card step-card--gold"><span class="num">02</span><h3>Accepted</h3><p>Capture the buyer's acceptance and the delivery acknowledgement that proves it.</p></div>
					<div class="step-card step-card--soft"><span class="num">03</span><h3>Financed</h3><p>Discounted on TReDS the moment the evidence is complete and the buyer is onboard.</p></div>
					<div class="step-card step-card--coral"><span class="num">04</span><h3>Settled</h3><p>Reconciled, closed and out of your queue — with a clean paper trail behind it.</p></div>
				</div>
			</div>
		</section>

		<!-- FINANCE (2-up) -->
		<section id="finance" class="sec sec-soft">
			<div class="container">
				<div class="split">
					<div>
						<p class="eyebrow">Financing</p>
						<h2 class="display">Unlock cash that's already yours.</h2>
						<p class="lede">Our finance queue separates the invoices you can finance today from the evidence gaps holding the rest back — buy it ready, not hoping.</p>
						<a class="pbtn pbtn-blue" href="/treds" style="margin-top:1.4rem;">Open the finance queue →</a>
					</div>
					<div class="queue-card">
						<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;">
							<div>
								<p class="label">TReDS queue</p>
								<h3>Financeable today</h3>
							</div>
							<span class="pbtn pbtn-ghost pbtn-sm" style="pointer-events:none;">4 invoices</span>
						</div>
						<div class="queue-row">
							<span class="dot gold"></span>
							<div><p class="name">Ready</p><p class="note">Evidence complete · buyer on TReDS</p></div>
							<p class="amt">₹18.2L</p>
						</div>
						<div class="queue-row">
							<span class="dot coral"></span>
							<div><p class="name">Gap — buyer not onboard</p><p class="note">Confirm TReDS to unlock ₹24.6L</p></div>
							<p class="amt">₹24.6L</p>
						</div>
					</div>
				</div>
			</div>
		</section>

		<!-- EVIDENCE (3-up) -->
		<section id="evidence" class="sec">
			<div class="container">
				<div class="sec-head">
					<div>
						<p class="eyebrow">Evidence</p>
						<h2 class="display">Stand on the right numbers.</h2>
					</div>
				</div>
				<div class="cards-row" style="grid-template-columns:repeat(3,1fr);">
					<article class="feature-card feature-card--gold">
						<div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></svg></div>
						<h3>Evidence that stands up</h3>
						<p>PO, delivery ack, GRN and a GST-valid copy — a finance or dispute packet that's never missing a document.</p>
					</article>
					<article class="feature-card">
						<div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12M6 8h12M6 13l8 4M6 18l8-4"/></svg></div>
						<h3>Interest that's correct</h3>
						<p>Statutory interest computed from the invoice at 3× the bank rate, applied daily — so a claim always uses the right number.</p>
					</article>
					<article class="feature-card feature-card--coral">
						<div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18M3 12h18M5.6 5.6l12.8 12.8M5.6 18.4 18.4 5.6"/></svg></div>
						<h3>Claims that go through</h3>
						<p>A guided evidence packet for MSEFC, mediation or arbitration — ready to file the moment a buyer stops paying.</p>
					</article>
				</div>
			</div>
		</section>

		<!-- NEWS (image card grid, UFL-style) -->
		<section id="news" class="sec sec-soft">
			<div class="container">
				<div class="sec-head">
					<div>
						<p class="eyebrow">News &amp; updates</p>
						<h2 class="display">From the PayKaro floor.</h2>
					</div>
					<p class="lede">What we're shipping, what we're seeing in the field, and the small changes that keep Indian MSMEs in the money.</p>
				</div>
				<div class="news-grid">
					<?php foreach ( paykaroNews() as $a ) : ?>
						<article class="news-card">
							<div class="img"><img src="<?php echo e( $a['image'] ); ?>" alt="<?php echo e( $a['alt'] ); ?>"></div>
							<div class="body">
								<p class="meta"><span class="tag"><?php echo e( $a['tag'] ); ?></span> <?php echo e( $a['date'] ); ?></p>
								<h3><?php echo e( $a['title'] ); ?></h3>
								<p><?php echo e( $a['excerpt'] ); ?></p>
								<a class="more" href="/news/<?php echo e( $a['slug'] ); ?>">Read more</a>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</div>
		</section>

		<!-- IMPACT (dark) -->
		<section id="impact" class="sec sec-dark">
			<div class="container">
				<div class="impact">
					<div>
						<p class="eyebrow eyebrow--on-dark">Momentum that moves MSMEs</p>
						<h2 class="display" style="margin-top:.6rem;">One platform for every invoice a small business raises.</h2>
						<p class="lede" style="margin-top:1.2rem;">From the corner-shop supplier to the precision-components shop, we're building the workflow that turns receivables into a finance-ready asset — with evidence, interest and a clear path to TReDS.</p>
						<div class="dontmiss">
							<a href="/signup">Try the free Starter</a>
							<a href="/pricing">See pricing</a>
							<a href="/login">Sign in to your workspace</a>
						</div>
					</div>
					<div>
						<div class="impact-art" style="margin-bottom:1.4rem;">
							<img src="/assets/img/impact-growth.jpg" alt="MSME business growth charts on a laptop">
							<div class="glaze"></div>
						</div>
						<div class="impact-stats">
							<div class="impact-stat"><p class="v num">₹4.2Cr+</p><div><p class="l">Receivables tracked</p><p class="n">Across active tenants in the last 30 days.</p></div></div>
							<div class="impact-stat"><p class="v num">3×</p><div><p class="l">Bank-rate interest</p><p class="n">Statutory multiplier on overdue invoices — applied daily.</p></div></div>
							<div class="impact-stat"><p class="v num">45d</p><div><p class="l">MSME due window</p><p class="n">The 2026 MSMED Act timeline, surfaced as a live deadline per invoice.</p></div></div>
						</div>
					</div>
				</div>
			</div>
		</section>

		<!-- CLAIMS (split) -->
		<section id="claims" class="sec">
			<div class="container">
				<div class="split split--reversed">
					<div class="split-art">
						<img src="/assets/img/hero-owner.jpg" alt="MSME owner in his workshop">
						<div class="art-cap">Shree Precision Components · 15 invoices tracked</div>
					</div>
					<div>
						<p class="eyebrow">Claims</p>
						<h2 class="display">When a buyer doesn't pay, you're already ready.</h2>
						<p class="lede">The 2026 MSME rules tighten TReDS mandates and strengthen the delayed-payment forum. Businesses that keep clean, complete, dated records now have real leverage — statutory interest, a time-bound dispute forum and discounted financing.</p>
						<div style="margin-top:1.4rem;display:flex;align-items:center;gap:.7rem;color:var(--n-blue);font-weight:700;">
							<span style="display:inline-flex;width:2.2rem;height:2.2rem;border-radius:999px;background:var(--n-blue);color:var(--n-gold);align-items:center;justify-content:center;">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
							</span>
							Guided evidence packet for MSEFC, mediation &amp; arbitration
						</div>
					</div>
				</div>
			</div>
		</section>

		<!-- TESTIMONIALS -->
		<section class="sec sec-soft">
			<div class="container">
				<div style="text-align:center;margin-bottom:2rem;">
					<p class="eyebrow">Loved by MSMEs</p>
				</div>
				<div class="testimonials">
					<figure class="testimonial">
						<blockquote>"We stopped guessing. PayKaro told us exactly which invoices were financeable and gave us the evidence to back them."</blockquote>
						<figcaption>
							<div class="av">S</div>
							<div><p class="name">Sunita Rao</p><p class="role">Shree Precision Components</p></div>
						</figcaption>
					</figure>
					<figure class="testimonial">
						<blockquote>"The finance queue paid for itself in a month. It separates what's ready from what isn't, which is the whole game."</blockquote>
						<figcaption>
							<div class="av" style="background:var(--n-coral);color:#fff;">F</div>
							<div><p class="name">Farhan Ali</p><p class="role">MetRow Ceramics</p></div>
						</figcaption>
					</figure>
				</div>
			</div>
		</section>

		<!-- CLOSING CTA -->
		<section class="sec">
			<div class="container">
				<div class="cta-banner">
					<div>
						<p class="eyebrow" style="color:#0f1d2e;">Ready</p>
						<h2 style="margin-top:.5rem;">Make every invoice count.</h2>
						<p>Free Starter tier. No credit card. Sign in, raise an invoice, and watch the pipeline do the work.</p>
					</div>
					<a class="pbtn pbtn-blue pbtn-lg" href="/signup">Try PayKaro free →</a>
				</div>
			</div>
		</section>
	</main>

	<footer class="page-footer">
		<div class="container">
			<div>
				<a class="pkg-brand" href="/" style="padding:0;color:#fff;" aria-label="PayKaro home">
					<?php echo logoMark(); ?>
					<div class="pkg-brand-text">
						<div class="name" style="color:#fff;"><?php echo brandWordmark(); ?></div>
						<div class="sub" style="color:rgba(255,255,255,.6);">MSME invoice &amp; receivables tracker</div>
					</div>
				</a>
				<p class="meta">© <?php echo e( date( 'Y' ) ); ?> PayKaro · Made for India's MSMEs.</p>
			</div>
			<div>
				<h4>Product</h4>
				<ul>
					<li><a href="#workflow">Workflow</a></li>
					<li><a href="#finance">Financing</a></li>
					<li><a href="#evidence">Evidence</a></li>
					<li><a href="#claims">Claims</a></li>
				</ul>
			</div>
			<div>
				<h4>Company</h4>
				<ul>
					<li><a href="#news">News</a></li>
					<li><a href="#impact">Impact</a></li>
					<li><a href="/pricing">Pricing</a></li>
					<li><a href="/login">Sign in</a></li>
				</ul>
			</div>
			<div>
				<h4>Legal</h4>
				<ul>
					<li><a href="/terms">Terms</a></li>
					<li><a href="/privacy">Privacy</a></li>
					<li><a href="/security">Security</a></li>
				</ul>
			</div>
		</div>
		<div class="page-footer-bottom">
			<span>© <?php echo e( date( 'Y' ) ); ?> PayKaro · Turn invoice mess into finance-ready receivables.</span>
			<span>Demo workspace · <a href="/login" style="color:var(--n-gold);">Sign in</a></span>
		</div>
	</footer>
	</body>
	</html>
	<?php
	return ob_get_clean();
}

/* Help page */
function helpPage( array $config ): string {
	ob_start();
	?>
	<!doctype html>
	<html lang="en">
	<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Help — <?php echo e( $config['name'] ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="/assets/app.css">
	</head>
	<body class="page">
	<div class="pkg-util">
		<div class="pkg-util-inner">
			<span class="pkg-util-tag"><span class="dot"></span> MSME receivables</span>
			<div class="pkg-util-right">
				<a href="/">Back to home</a>
				<span class="pkg-util-sep"></span>
				<a href="/login">Sign in</a>
			</div>
		</div>
	</div>
	<header class="pkg-head">
		<div class="pkg-head-inner">
			<div class="pkg-bars" aria-hidden="true">
				<span class="bar-thick"></span>
				<span class="bar-thin"></span>
			</div>
			<a class="pkg-brand" href="/" aria-label="PayKaro home">
				<?php echo logoMark(); ?>
				<div class="pkg-brand-text">
					<div class="name"><?php echo brandWordmark(); ?></div>
					<div class="sub">MSME invoice &amp; receivables tracker</div>
				</div>
			</a>
			<nav class="pkg-nav" aria-label="Primary">
				<a href="/#workflow">Workflow</a>
				<a href="/#finance">Financing</a>
				<a href="/#news">News</a>
				<a href="/pricing">Pricing</a>
			</nav>
			<div class="pkg-head-right">
				<a class="pbtn pbtn-outline pbtn-sm" href="/login">Sign in</a>
				<a class="pbtn pbtn-primary pbtn-sm" href="/signup">Get started</a>
			</div>
		</div>
		<div class="pkg-headline" aria-hidden="true"></div>
	</header>
	<main>
		<section class="sec" style="padding:5rem 0 4rem;">
			<div class="container">
				<div class="sec-head" style="grid-template-columns:1fr;">
					<div>
						<p class="eyebrow">Support</p>
						<h1 class="display" style="font-size:clamp(2rem,4vw,3rem);margin-top:.5rem;">How can we help you?</h1>
					</div>
				</div>
				<div class="cards-row" style="grid-template-columns:repeat(3,1fr);margin-top:2rem;">
					<article class="feature-card">
						<div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"/><path d="M14 2v5h5"/><path d="M12 11v6M9 14h6"/></svg></div>
						<h3>Getting started</h3>
						<p>Learn how to set up your account, add your first buyer, and raise your first invoice in minutes.</p>
					</article>
					<article class="feature-card">
						<div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 6v6c0 5 3.5 9.5 8 10 4.5-.5 8-5 8-10V6l-8-4z"/><path d="m9 12 2 2 4-4"/></svg></div>
						<h3>TReDS &amp; Financing</h3>
						<p>Understand how the finance queue works and how to get your invoices ready for TReDS financing.</p>
					</article>
					<article class="feature-card">
						<div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
						<h3>Claims &amp; Disputes</h3>
						<p>File MSEFC claims, start mediation, or build an evidence packet for arbitration.</p>
					</article>
				</div>
				<div class="cta-banner" style="margin-top:3rem;">
					<div>
						<h2 style="font-family:var(--n-display);font-size:1.5rem;">Still need help?</h2>
						<p>Contact our support team and we'll get back to you within 24 hours.</p>
					</div>
					<a class="pbtn pbtn-primary pbtn-lg" href="/contact">Contact support</a>
				</div>
			</div>
		</section>
	</main>
	<footer class="page-footer">
		<div class="page-footer-bottom">
			<span>© <?php echo e( date( 'Y' ) ); ?> PayKaro</span>
			<span><a href="/" style="color:var(--n-gold);">Back to home</a></span>
		</div>
	</footer>
	</body>
	</html>
	<?php
	return ob_get_clean();
}

/* Contact page */
function contactPage( array $config ): string {
	ob_start();
	?>
	<!doctype html>
	<html lang="en">
	<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Contact — <?php echo e( $config['name'] ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="/assets/app.css">
	</head>
	<body class="page">
	<div class="pkg-util">
		<div class="pkg-util-inner">
			<span class="pkg-util-tag"><span class="dot"></span> MSME receivables</span>
			<div class="pkg-util-right">
				<a href="/">Back to home</a>
				<span class="pkg-util-sep"></span>
				<a href="/login">Sign in</a>
			</div>
		</div>
	</div>
	<header class="pkg-head">
		<div class="pkg-head-inner">
			<div class="pkg-bars" aria-hidden="true">
				<span class="bar-thick"></span>
				<span class="bar-thin"></span>
			</div>
			<a class="pkg-brand" href="/" aria-label="PayKaro home">
				<?php echo logoMark(); ?>
				<div class="pkg-brand-text">
					<div class="name"><?php echo brandWordmark(); ?></div>
					<div class="sub">MSME invoice &amp; receivables tracker</div>
				</div>
			</a>
			<nav class="pkg-nav" aria-label="Primary">
				<a href="/#workflow">Workflow</a>
				<a href="/#finance">Financing</a>
				<a href="/#news">News</a>
				<a href="/pricing">Pricing</a>
			</nav>
			<div class="pkg-head-right">
				<a class="pbtn pbtn-outline pbtn-sm" href="/login">Sign in</a>
				<a class="pbtn pbtn-primary pbtn-sm" href="/signup">Get started</a>
			</div>
		</div>
		<div class="pkg-headline" aria-hidden="true"></div>
	</header>
	<main>
		<section class="sec" style="padding:5rem 0 4rem;">
			<div class="container">
				<div class="split">
					<div>
						<p class="eyebrow">Get in touch</p>
						<h1 class="display" style="font-size:clamp(2rem,4vw,3rem);margin-top:.5rem;">We'd love to hear from you</h1>
						<p class="lede" style="margin-top:1.2rem;">Whether you have a question about features, pricing, need a demo, or anything else — our team is ready to answer all your questions.</p>
						<div style="margin-top:2rem;">
							<div class="feature-card" style="margin-bottom:1rem;">
								<div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div>
								<h3>Email</h3>
								<p>support@paykaro.in</p>
							</div>
							<div class="feature-card" style="margin-bottom:1rem;">
								<div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></div>
								<h3>Phone</h3>
								<p>+91 98765 43210</p>
							</div>
						</div>
					</div>
					<div>
						<div class="price-card" style="padding:2rem;">
							<h2 style="font-family:var(--n-font);font-size:1.2rem;font-weight:700;margin-bottom:1.5rem;">Send us a message</h2>
							<form method="post" action="/contact" style="display:flex;flex-direction:column;gap:1rem;">
								<div class="pkg-field" style="margin:0;">
									<label class="pkg-label">Your name</label>
									<input class="pkg-input" type="text" name="name" placeholder="Full name" required>
								</div>
								<div class="pkg-field" style="margin:0;">
									<label class="pkg-label">Work email</label>
									<input class="pkg-input" type="email" name="email" placeholder="you@company.in" required>
								</div>
								<div class="pkg-field" style="margin:0;">
									<label class="pkg-label">Message</label>
									<textarea class="pkg-input pkg-textarea" name="message" placeholder="How can we help?" required style="min-height:8rem;"></textarea>
								</div>
								<button class="pbtn pbtn-primary pbtn-block" type="submit">Send message</button>
							</form>
						</div>
					</div>
				</div>
			</div>
		</section>
	</main>
	<footer class="page-footer">
		<div class="page-footer-bottom">
			<span>© <?php echo e( date( 'Y' ) ); ?> PayKaro</span>
			<span><a href="/" style="color:var(--n-gold);">Back to home</a></span>
		</div>
	</footer>
	</body>
	</html>
	<?php
	return ob_get_clean();
}

/* Public pricing page — uses the new editorial header + .price-grid cards. */
function pricingPage( array $config ): string {
	$plans = array(
		array(
			'name' => 'Starter',
			'price' => 'Free',
			'per' => 'forever',
			'blurb' => 'For a single MSME getting its receivables in order.',
			'cta' => '/signup',
			'cta_label' => 'Start free',
			'cta_class' => 'pbtn-outline',
			'highlight' => false,
			'features' => array( 'Up to 25 invoices', '1 user (owner)', 'Invoice pipeline + interest', 'Evidence checklist', 'Demo data included' ),
		),
		array(
			'name' => 'Pro',
			'price' => '₹1,499',
			'per' => '/month · per business',
			'blurb' => 'For growing suppliers who finance and claim often.',
			'cta' => '/signup',
			'cta_label' => 'Try Pro free',
			'cta_class' => 'pbtn-primary',
			'highlight' => true,
			'features' => array( 'Unlimited invoices', '3 users (owner + accountant)', 'TReDS / finance queue', 'MSEFC claim packet builder', 'Advanced reports & export', 'Priority support' ),
		),
		array(
			'name' => 'Enterprise',
			'price' => 'Custom',
			'per' => 'let’s talk',
			'blurb' => 'For CA firms, lenders and multi-entity groups.',
			'cta' => '/login',
			'cta_label' => 'Book a demo',
			'cta_class' => 'pbtn-blue',
			'highlight' => false,
			'features' => array( 'Everything in Pro', 'Multi-entity / portfolio view', 'API & data exports', 'Onboarding & training', 'Dedicated success manager' ),
		),
	);
	ob_start();
	?>
	<!doctype html>
	<html lang="en">
	<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Pricing — <?php echo e( $config['name'] ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="/assets/app.css">
	</head>
	<body class="page">
	<div class="pkg-util">
		<div class="pkg-util-inner">
			<span class="pkg-util-tag"><span class="dot"></span> MSME receivables</span>
			<div class="pkg-util-right">
				<a href="/">Back to home</a>
				<span class="pkg-util-sep"></span>
				<a href="/login">Sign in</a>
			</div>
		</div>
	</div>
	<header class="pkg-head">
		<div class="pkg-head-inner">
			<div class="pkg-bars" aria-hidden="true">
				<span class="bar-thick"></span>
				<span class="bar-thin"></span>
			</div>
			<a class="pkg-brand" href="/" aria-label="PayKaro home">
				<?php echo logoMark(); ?>
				<div class="pkg-brand-text">
					<div class="name"><?php echo brandWordmark(); ?></div>
					<div class="sub">MSME invoice &amp; receivables tracker</div>
				</div>
			</a>
			<nav class="pkg-nav" aria-label="Primary">
				<a href="/#workflow">Workflow</a>
				<a href="/#finance">Financing</a>
				<a href="/#news">News</a>
				<a href="/#impact">Impact</a>
				<a class="is-active" href="/pricing">Pricing</a>
			</nav>
			<div class="pkg-head-right">
				<a class="pbtn pbtn-outline pbtn-sm" href="/login">Sign in</a>
				<a class="pbtn pbtn-primary pbtn-sm" href="/signup">Get started</a>
			</div>
		</div>
		<div class="pkg-headline" aria-hidden="true"></div>
	</header>
	<main>
		<section class="sec" style="padding:5rem 0 4rem;">
			<div class="container">
				<div class="sec-head">
					<div>
						<p class="eyebrow">Pricing</p>
						<h1 class="display" style="font-size:clamp(2.4rem,4.8vw,3.8rem);">Simple plans that pay for themselves.</h1>
					</div>
					<p class="lede">Every plan tracks receivables, computes interest and builds an evidence-ready claim. Upgrade when you want to finance and export more.</p>
				</div>
				<div class="price-grid">
					<?php foreach ( $plans as $p ) : ?>
						<div class="price-card<?php echo $p['highlight'] ? ' is-hot' : ''; ?>">
							<div style="display:flex;align-items:center;justify-content:space-between;">
								<span class="label"><?php echo e( $p['name'] ); ?></span>
								<?php if ( $p['highlight'] ) : ?><span class="pbtn pbtn-ghost pbtn-sm" style="pointer-events:none;background:var(--n-gold);color:#0f1d2e;border-color:var(--n-gold);">Most popular</span><?php endif; ?>
							</div>
							<div class="price"><?php echo e( $p['price'] ); ?></div>
							<div class="per"><?php echo e( $p['per'] ); ?></div>
							<p class="blurb"><?php echo e( $p['blurb'] ); ?></p>
							<ul>
								<?php foreach ( $p['features'] as $f ) : ?>
									<li><span class="ck">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-11"/></svg>
									</span><?php echo e( $f ); ?></li>
								<?php endforeach; ?>
							</ul>
							<a class="pbtn <?php echo e( $p['cta_class'] ); ?> pbtn-block cta" href="<?php echo e( $p['cta'] ); ?>"><?php echo e( $p['cta_label'] ); ?></a>
						</div>
					<?php endforeach; ?>
				</div>
				<div style="margin-top:3rem;text-align:center;">
					<p class="display" style="font-size:1.6rem;">Not sure which plan?</p>
					<p class="lede" style="margin-top:.6rem;">Every plan starts on the free Starter tier — <a href="/login" style="color:var(--n-blue);font-weight:700;text-decoration:underline;text-underline-offset:4px;">sign in</a> and track your first invoices today.</p>
				</div>
			</div>
		</section>
	</main>
	<footer class="page-footer">
		<div class="page-footer-bottom">
			<span>© <?php echo e( date( 'Y' ) ); ?> PayKaro · Made for India's MSMEs</span>
			<span><a href="/" style="color:var(--n-gold);">Back to home</a></span>
		</div>
	</footer>
	</body>
	</html>
	<?php
	return ob_get_clean();
}

function pageHeader( string $title, string $subtitle = '', string $actions = '' ): string {
	return '<div class="pkg-pagehead"><div><h1 class="pkg-h1">' . e( $title ) . '</h1>'
		. ( $subtitle ? '<p class="pkg-sub">' . e( $subtitle ) . '</p>' : '' ) . '</div>'
		. ( $actions ? '<div class="pkg-pagehead-actions">' . $actions . '</div>' : '' ) . '</div>';
}

function statusTimeline( string $status ): string {
	$order = array( 'raised', 'accepted', 'financed', 'settled' );
	$labels = array( 'raised' => 'Raised', 'accepted' => 'Accepted', 'financed' => 'Financed', 'settled' => 'Settled', 'disputed' => 'Disputed' );
	$disc = 'disputed' === $status;
	$now  = $disc ? 'disputed' : $status;
	$ob = '<div class="pkg-timeline">';
	foreach ( $labels as $key => $label ) {
		if ( 'disputed' === $key ) {
			if ( $disc ) {
				$ob .= '<div class="pkg-tl-step is-warn"><span class="pkg-tl-dot"></span><span class="pkg-tl-label">' . e( $label ) . '</span></div>';
			}
			continue;
		}
		$idx = array_search( $now, $order, true );
		$pos = array_search( $key, $order, true );
		$done = ( false !== $idx && false !== $pos && $pos <= $idx );
		$cls = ( $now === $key ) ? 'is-now' : ( $done ? 'is-done' : '' );
		$ob .= '<div class="pkg-tl-step ' . $cls . '"><span class="pkg-tl-dot"></span><span class="pkg-tl-label">' . e( $label ) . '</span></div>';
	}
	$ob .= '</div>';
	return $ob;
}

/* ------------------------------------------------------------------ */
/* Views                                                              */
/* ------------------------------------------------------------------ */

function viewDashboard( PayKaro $app, array $config ): string {
	$d       = $app->dashboard();
	$invoices = $app->invoices();
	$monthStart = date( 'Y-m-01' );
	$range = '01 ' . date( 'M' ) . ' – ' . date( 'd M Y' );

	$ob = '<div class="pkg-crumbs"><a href="/">Home</a><span class="pkg-crumbs-sep">›</span><strong>Overview</strong></div>'
		. '<div class="pkg-pagehead">'
		. '<div><h1 class="pkg-h1">Overview</h1><p class="pkg-sub">Here’s what’s happening with your receivables.</p></div>'
		. '<div class="pkg-pagehead-actions">'
		. '<span class="pkg-filter"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> ' . e( $range ) . '</span>'
		. '<span class="pkg-filter"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.09a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg> Filters</span>'
		. '<a class="pkg-btn pkg-btn--sm pkg-btn--primary" href="/invoices/new">+ New Invoice</a>'
		. '</div></div>';

	// KPI cards.
	$monthLabel = date( 'd M' ) . ' – ' . date( 'd M Y' );
	$ob .= '<div class="pkg-grid pkg-grid--4">';
	$ob .= kpiCard( 'Outstanding', money( $d['total'] ), '<span class="up"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg></span> 8.6% vs 01 Apr – 30 Apr 2025', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>', 'blue' );
	$ob .= kpiCard( 'Overdue', money( $d['overdue'] ), '<span class="down"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg></span> 12.3% vs 01 Apr – 30 Apr 2025', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>', 'red' );
	$ob .= kpiCard( 'Interest', money( $d['interest'] ), '<span class="up"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg></span> 6.2% vs 01 Apr – 30 Apr 2025', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>', 'amber' );
	$ob .= kpiCard( 'Receivable in 30d', money( $d['in30d'] ), '<span class="up"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg></span> 10.7% vs 01 Apr – 30 Apr 2025', '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>', 'blue' );
	$ob .= '</div>';

	// Needs attention (alerts).
	$alerts = $app->alerts();
	if ( $alerts ) {
		$items = '';
		foreach ( $alerts as $a ) {
			$cls = 'danger' === ( $a['type'] ?? '' ) ? ' pkg-alert--danger' : ( 'success' === ( $a['type'] ?? '' ) ? ' pkg-alert--success' : '' );
			$items .= '<li class="pkg-alert' . $cls . '"><span class="pkg-alert-dot"></span>'
				. '<span>' . e( $a['message'] ) . '</span>'
				. ( ! empty( $a['number'] ) ? '<span class="pkg-alert-inv">' . e( $a['number'] ) . '</span>' : '' )
				. '</li>';
		}
		$ob .= '<div class="pkg-card is-accent"><div class="pkg-cardhead"><h2 class="pkg-h2">Needs attention</h2>'
			. '<form method="post" action="/alerts/read"><button class="pkg-btn pkg-btn--sm pkg-btn--ghost" type="submit">Dismiss all</button></form></div>'
			. '<ul class="pkg-alerts" style="margin:0;">' . $items . '</ul></div>';
	}

	$ob .= '<div class="pkg-grid pkg-grid--2">';

	// Ageing summary (bar chart) with y-axis gridlines, matching the reference.
	$buckets = $d['buckets'];
	$maxB = max( 0.001, max( array_map( 'floatval', array_values( $buckets ) ) ) );
	$barColor = array( 'Current' => 'blue', '1–30d' => 'blue', '31–60d' => 'amber', '61–90d' => 'orange', '90+' => 'red' );
	// Reference bucket labels: 0–30 Days, 31–60 Days, 61–90 Days, 91–120 Days, 120+ Days.
	$bucketLabel = array( 'Current' => '0–30 Days', '1–30d' => '31–60 Days', '31–60d' => '61–90 Days', '61–90d' => '91–120 Days', '90+' => '120+ Days' );
	// Data-driven y-axis: pick a "nice" round max derived from the leading
	// magnitude, and derive 4 evenly-spaced gridlines.
	$maxVal  = (float) max( $buckets ) ?: 1.0;
	$mag     = pow( 10, floor( log10( $maxVal ) ) );
	$niceMax = $mag;
	foreach ( array( 1, 2, 2.5, 5, 10 ) as $mult ) {
		if ( $mag * $mult >= $maxVal ) { $niceMax = $mag * $mult; break; }
	}
	$niceMax = max( 1, $niceMax );
	$tickCount = 4;
	$ytick = array();
	for ( $k = $tickCount; $k >= 0; $k-- ) { $ytick[] = round( $niceMax * $k / $tickCount ); }
	$ob .= '<div class="pkg-card"><div class="pkg-cardhead"><h2 class="pkg-h2">Ageing Summary</h2><span class="pkg-filter" style="padding:.35rem .6rem;">As on ' . e( date( 'd M Y' ) ) . '</span></div>';
	$ob .= '<div class="pkg-ageing-wrap"><div class="pkg-ageing-yaxis">';
	foreach ( $ytick as $t ) {
		$lakh = $t / 100000;
		if ( $t >= 100000 ) {
			$ob .= '<span class="pkg-yaxis num">₹' . ( fmod( $lakh, 1 ) ? number_format( $lakh, 1 ) : number_format( $lakh, 0 ) ) . 'L</span>';
		} elseif ( $t >= 1000 ) {
			$ob .= '<span class="pkg-yaxis num">₹' . number_format( $t / 1000, 0 ) . 'K</span>';
		} else {
			$ob .= '<span class="pkg-yaxis num">₹' . number_format( $t, 0 ) . '</span>';
		}
	}
	$ob .= '</div><div class="pkg-ageing"><div class="pkg-ageing-grid">';
	foreach ( $ytick as $t ) { $ob .= '<span class="pkg-hline"></span>'; }
	$ob .= '</div>';
	foreach ( $buckets as $name => $val ) {
		$h = max( 4, round( $val / $niceMax * 100 ) );
		$color = $barColor[ $name ] ?? 'brand';
		$lab = $bucketLabel[ $name ] ?? $name;
		$ob .= '<div class="col"><div class="barwrap"><div class="bar bar--' . $color . '" style="height:' . $h . '%"><span class="val num">' . e( money( $val ) ) . '</span></div></div><div class="lab">' . e( $lab ) . '</div></div>';
	}
	$ob .= '</div></div></div>';

	// Receivables status pipeline.
	$pipe = array( 'Current' => 'success', '1–30d' => 'blue', '31–60d' => 'amber', '61–90d' => 'orange', '90+' => 'red' );
	$counts = array_fill_keys( array_keys( $pipe ), 0 );
	$sums = array_fill_keys( array_keys( $pipe ), 0.0 );
	$totalO = 0.0;
	foreach ( $invoices as $i ) {
		if ( 'settled' === $i['status'] || 'draft' === $i['status'] ) { continue; }
		$bal = (float) $i['balance'];
		$totalO += $bal;
		$age = $i['ageing'];
		if ( ! isset( $counts[ $age ] ) ) { $age = 'Current'; }
		$counts[ $age ]++;
		$sums[ $age ] += $bal;
	}
	$pctBadge = array( 'Current' => 'success', '1–30d' => 'info', '31–60d' => 'warning', '61–90d' => 'warning', '90+' => 'danger' );
	$ob .= '<div class="pkg-card"><div class="pkg-cardhead"><h2 class="pkg-h2">Receivables Status Pipeline</h2><a class="pkg-btn pkg-btn--sm pkg-btn--ghost" href="/reports">View all →</a></div>';
	$ob .= '<ul class="pkg-pipe-list">';
	foreach ( $pipe as $name => $dot ) {
		$c = $counts[ $name ]; $sum = $sums[ $name ];
		$pct = $totalO > 0 ? round( $sum / $totalO * 100, 1 ) : 0;
		$ob .= '<li class="pkg-pipe-row"><span class="pkg-pipe-dot" style="background:var(--n-' . $dot . ');"></span>'
			. '<span class="pkg-pipe-name">' . e( $name ) . '</span>'
			. '<span class="pkg-pipe-count">' . $c . ' Invoices</span>'
			. '<span class="pkg-pipe-right"><span class="pkg-pipe-amt num">' . e( money( $sum ) ) . '</span>'
			. '<span class="pkg-pipe-pct pkg-badge--' . ( $pctBadge[ $name ] ?? 'neutral' ) . '">' . $pct . '%</span></span></li>';
	}
	$ob .= '</ul><div class="pkg-pipe-total"><span>Total</span><span class="num">' . e( money( $totalO ) ) . '</span></div></div>';
	$ob .= '</div>';

	// Recent invoices table.
	$rows = array_slice( $invoices, 0, 5 );
	$ob .= '<div class="pkg-card pkg-tablewrap">';
	$ob .= '<div class="pkg-cardhead"><h2 class="pkg-h2">Recent Invoices</h2>'
		. '<div class="pkg-toolbar">'
		. '<span class="pkg-search"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> <input placeholder="Search invoices…" aria-label="Search"></span>'
		. '<a class="pkg-btn pkg-btn--sm" href="/invoices">Download</a>'
		. '<a class="pkg-btn pkg-btn--sm pkg-btn--primary" href="/invoices/new">+ New Invoice</a>'
		. '</div></div>';
	if ( ! $rows ) {
		$ob .= '<div class="pkg-empty" style="padding:2.5rem 1rem;"><p class="pkg-sub">No invoices yet.</p><a class="pkg-btn pkg-btn--primary" href="/invoices/new">+ Raise an invoice</a></div>';
	} else {
		$ob .= '<table class="pkg-table"><thead><tr><th>Invoice #</th><th>Customer</th><th>Invoice Date</th><th>Due Date</th><th>Amount</th><th>Outstanding</th><th>Status</th><th>Days Overdue</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $i ) {
			$ob .= '<tr>'
				. '<td><a class="pkg-link" href="/invoice?id=' . (int) $i['id'] . '"><strong>' . e( $i['number'] ) . '</strong></a></td>'
				. '<td>' . e( $i['buyer_name'] ) . '</td>'
				. '<td>' . e( $i['invoice_date'] ) . '</td>'
				. '<td>' . e( $i['due_date'] ) . '</td>'
				. '<td class="num">' . e( money( (float) $i['total_amount'] ) ) . '</td>'
				. '<td class="num">' . e( money( (float) $i['balance'] ) ) . '</td>'
				. '<td>' . healthBadge( $i ) . '</td>'
				. '<td class="num">' . ( (int) $i['overdue_days'] > 0 ? (int) $i['overdue_days'] : '—' ) . '</td>'
				. '<td><span style="color:var(--n-ink-mute);cursor:pointer;">⋮</span></td>'
				. '</tr>';
		}
		$ob .= '</tbody></table>';
		$total = count( $invoices );
		$ob .= '<div class="pkg-pagination"><span>Showing 1 to ' . count( $rows ) . ' of ' . $total . ' Invoices</span>'
			. '<div class="pkg-pageno"><span class="pkg-pno is-active">1</span><span class="pkg-pno">2</span><span class="pkg-pno">3</span><span class="pkg-pno">…</span><span class="pkg-pno">' . max( 2, ceil( $total / 5 ) ) . '</span></div></div>';
	}
	$ob .= '</div>';

	return $ob;
}

function viewInvoices( PayKaro $app ): string {
	$f = array();
	if ( isset( $_GET['status'] ) ) {
		$f['status'] = $_GET['status'];
	}
	$invoices = $app->invoices( $f );
	$ob = pageHeader( 'Invoices', 'Every invoice, one pipeline.', '<a class="pkg-btn pkg-btn--primary" href="/invoices/new">+ New Invoice</a>' );
	$tabs = array( 'all' => 'All', 'raised' => 'Raised', 'accepted' => 'Accepted', 'financed' => 'Financed', 'settled' => 'Settled', 'disputed' => 'Disputed' );
	$ob .= '<div class="pkg-tabs">';
	foreach ( $tabs as $key => $label ) {
		$cur = $f['status'] ?? 'all';
		$ob .= '<a class="pkg-tab ' . ( $cur === $key ? 'is-active' : '' ) . '" href="/invoices' . ( 'all' === $key ? '' : '?status=' . $key ) . '">' . e( $label ) . '</a>';
	}
	$ob .= '</div>';
	if ( ! $invoices ) {
		return $ob . '<div class="pkg-card pkg-empty"><h2 class="pkg-h2">No invoices yet</h2><p class="pkg-sub">Raise your first invoice to start tracking receivables.</p><a class="pkg-btn pkg-btn--primary" href="/invoices/new">+ Raise an invoice</a></div>';
	}
	$ob .= '<div class="pkg-card pkg-tablewrap"><div class="pkg-cardhead"><h2 class="pkg-h2">All invoices</h2><span class="pkg-filter" style="padding:.35rem .6rem;">' . count( $invoices ) . ' results</span></div>'
		. '<table class="pkg-table"><thead><tr><th>Invoice</th><th>Customer</th><th>Due Date</th><th>Balance</th><th>Ageing</th><th>Status</th><th>Ready</th><th></th></tr></thead><tbody>';
	foreach ( $invoices as $i ) {
		$ob .= '<tr><td><a class="pkg-link" href="/invoice?id=' . (int) $i['id'] . '"><strong>' . e( $i['number'] ) . '</strong></a><div class="pkg-muted">' . e( $i['invoice_date'] ) . '</div></td>'
			. '<td>' . e( $i['buyer_name'] ) . '</td>'
			. '<td>' . e( $i['due_date'] ) . ( $i['overdue_days'] > 0 ? '<div class="pkg-muted pkg-neg">+' . (int) $i['overdue_days'] . 'd</div>' : '' ) . '</td>'
			. '<td><strong class="num">' . e( money( $i['balance'] ) ) . '</strong>' . ( $i['interest'] > 0 ? '<div class="pkg-muted pkg-neg">+ ' . e( money( $i['interest'] ) ) . '</div>' : '' ) . '</td>'
			. '<td>' . healthBadge( $i ) . '</td><td>' . badge( $i['status'] ) . '</td><td>' . progress( (int) $i['readiness'] ) . '</td>'
			. '<td><span style="color:var(--n-ink-mute);cursor:pointer;">⋮</span></td></tr>';
	}
	$ob .= '</tbody></table></div>';
	return $ob;
}

function viewInvoice( PayKaro $app, array $config, int $id ): string {
	$inv = $app->invoice( $id );
	if ( ! $inv ) {
		return '<div class="pkg-card pkg-empty"><h2 class="pkg-h2">Invoice not found</h2><a class="pkg-btn" href="/invoices">Back</a></div>';
	}
	$labels = array( 'po' => 'Purchase order', 'delivery_ack' => 'Delivery acknowledgement', 'grn' => 'Goods receipt note', 'contract' => 'Contract', 'invoice_copy' => 'GST-valid invoice copy' );
	$ob = pageHeader( 'Invoice ' . e( $inv['number'] ), e( $inv['buyer_name'] ) . ' · ' . e( $inv['invoice_date'] ),
		'<a class="pkg-btn pkg-btn--ghost" href="/invoices">← All invoices</a>'
		. '<a class="pkg-btn pkg-btn--sm" href="/invoice?edit=' . (int) $id . '">✎ Edit</a>' );
	ob_start();
	?>
	<div class="pkg-detail-grid">
		<div class="pkg-col-main">
			<div class="pkg-card">
				<div class="pkg-invoicemeta">
					<div><span class="pkg-meta">Status</span> <?php echo badge( $inv['status'] ); ?></div>
					<div><span class="pkg-meta">Due date</span> <strong><?php echo e( $inv['due_date'] ); ?></strong><?php if ( $inv['overdue_days'] > 0 ) { echo ' <span class="pkg-neg">+' . (int) $inv['overdue_days'] . 'd</span>'; } ?></div>
					<div><span class="pkg-meta">Balance due</span> <strong class="num"><?php echo e( money( $inv['balance'] ) ); ?></strong></div>
					<div><span class="pkg-meta">Interest accruing</span> <strong class="num pkg-neg"><?php echo e( money( $inv['interest'] ) ); ?></strong></div>
				</div>
				<div class="pkg-amounts">
					<div class="pkg-amount"><span class="pkg-meta">Base</span><span class="num"><?php echo e( money( (float) $inv['base_amount'] ) ); ?></span></div>
					<div class="pkg-amount"><span class="pkg-meta">Tax</span><span class="num"><?php echo e( money( (float) $inv['tax_amount'] ) ); ?></span></div>
					<div class="pkg-amount"><span class="pkg-meta">Total</span><span class="num"><?php echo e( money( (float) $inv['total_amount'] ) ); ?></span></div>
				</div>
				<div style="margin-top:1.2rem;"><?php echo statusTimeline( $inv['status'] ); ?></div>
			</div>

			<div class="pkg-card">
				<h2 class="pkg-h2">Move it forward</h2>
				<div class="pkg-statusbtns">
					<?php foreach ( array( 'accepted' => 'Accept', 'financed' => 'Finance', 'settled' => 'Mark settled', 'disputed' => 'Flag disputed' ) as $st => $label ) : ?>
						<form method="post" action="/invoice" class="pkg-inline">
							<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
							<input type="hidden" name="action" value="status">
							<input type="hidden" name="status" value="<?php echo e( $st ); ?>">
							<button class="pkg-btn pkg-btn--sm <?php echo $inv['status'] === $st ? 'is-disabled' : ''; ?>" type="submit" <?php echo $inv['status'] === $st ? 'disabled' : ''; ?>><?php echo e( $label ); ?></button>
						</form>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="pkg-card">
				<h2 class="pkg-h2">Evidence checklist</h2>
				<div class="pkg-evidence">
					<?php foreach ( $inv['evidences'] as $ev ) : ?>
						<form method="post" action="/invoice" class="pkg-evi">
							<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
							<input type="hidden" name="action" value="evidence">
							<input type="hidden" name="type" value="<?php echo e( $ev['type'] ); ?>">
							<input type="hidden" name="present" value="<?php echo (int) ( ! $ev['present'] ); ?>">
							<button class="pkg-check <?php echo $ev['present'] ? 'is-checked' : ''; ?>" type="submit">
								<span class="pkg-check-box"><?php echo $ev['present'] ? '✓' : ''; ?></span>
								<span><?php echo e( $labels[ $ev['type'] ] ?? ucfirst( $ev['type'] ) ); ?></span>
							</button>
						</form>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="pkg-card">
				<h2 class="pkg-h2">Payments</h2>
				<?php if ( $inv['payments'] ) : ?>
					<ul class="pkg-list">
						<?php foreach ( $inv['payments'] as $pm ) : ?>
							<li><span class="pkg-muted"><?php echo e( $pm['paid_on'] ); ?></span><span><?php echo e( $pm['method'] ); ?></span><span class="pkg-muted"><?php echo e( $pm['reference'] ); ?></span><span class="pkg-list-amt num"><?php echo e( money( (float) $pm['amount'] ) ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="pkg-sub">No payments recorded yet.</p>
				<?php endif; ?>
				<hr class="pkg-divider">
				<?php if ( 'settled' !== $inv['status'] ) : ?>
					<form method="post" action="/invoice" class="pkg-grid pkg-grid--2" style="gap:.6rem;align-items:end;">
						<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
						<input type="hidden" name="action" value="payment">
						<div class="pkg-field" style="margin:0"><label class="pkg-label">Amount (₹)</label><input class="pkg-input num" name="amount" type="number" step="0.01" min="0" max="<?php echo (float) $inv['balance']; ?>" value="<?php echo (float) $inv['balance']; ?>" required></div>
						<div class="pkg-field" style="margin:0"><label class="pkg-label">Paid on</label><input class="pkg-input" name="paid_on" type="date" value="<?php echo date( 'Y-m-d' ); ?>"></div>
						<div class="pkg-field" style="margin:0"><label class="pkg-label">Method</label><input class="pkg-input" name="method" placeholder="NEFT / UPI / Cheque"></div>
						<div class="pkg-field" style="margin:0"><label class="pkg-label">Ref</label><input class="pkg-input" name="reference" placeholder="UTR"></div>
						<div><button class="pkg-btn pkg-btn--primary pkg-btn--sm" type="submit">Record payment</button></div>
					</form>
				<?php endif; ?>
			</div>

			<div class="pkg-card">
				<h2 class="pkg-h2">Financing & TReDS</h2>
				<?php if ( $inv['financing'] ) : ?>
					<ul class="pkg-list">
						<?php foreach ( $inv['financing'] as $f ) : ?>
							<li><span><?php echo e( $f['financier'] ); ?></span><span class="pkg-muted"><?php echo e( $f['disbursed_on'] ); ?> · <?php echo e( $f['discount_rate'] ); ?>% discount</span><span class="pkg-list-amt num"><?php echo e( money( (float) $f['amount_disbursed'] ) ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="pkg-sub"><?php echo 'pending_buyer_onboard' === $inv['treds'] ? 'Buyer is not on TReDS — confirm their onboarding to unlock financing.' : 'No financing yet.'; ?></p>
				<?php endif; ?>
				<?php if ( ! in_array( $inv['status'], array( 'financed', 'settled', 'disputed' ), true ) ) : ?>
					<hr class="pkg-divider">
					<form method="post" action="/invoice" class="pkg-grid pkg-grid--3" style="gap:.6rem;align-items:end;">
						<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
						<input type="hidden" name="action" value="finance">
						<div class="pkg-field" style="margin:0"><label class="pkg-label">Financier</label><input class="pkg-input" name="financier" placeholder="Bank / NBFC"></div>
						<div class="pkg-field" style="margin:0"><label class="pkg-label">Discount %</label><input class="pkg-input num" name="discount_rate" type="number" step="0.1" value="1.5"></div>
						<div class="pkg-field" style="margin:0"><label class="pkg-label">Disbursed (₹)</label><input class="pkg-input num" name="amount_disbursed" type="number" step="0.01" value="<?php echo (float) $inv['balance']; ?>"></div>
						<div><button class="pkg-btn pkg-btn--primary pkg-btn--sm" type="submit">Finance this</button></div>
					</form>
				<?php endif; ?>
			</div>

			<div class="pkg-card">
				<h2 class="pkg-h2">Dispute / claim</h2>
				<?php if ( $inv['disputes'] ) : foreach ( $inv['disputes'] as $dp ) : ?>
					<div class="pkg-callout pkg-callout--coral" style="margin-bottom:.7rem;">
						<strong><?php echo e( strtoupper( $dp['forum'] ) ); ?> claim</strong> filed <?php echo e( $dp['filed_on'] ); ?> · stage <?php echo e( $dp['stage'] ); ?>
						<?php if ( $dp['deadline_on'] ) : $dt = $app->daysTo( $dp['deadline_on'] ); ?>
							<small>Deadline <?php echo e( $dp['deadline_on'] ); ?> — <?php echo $dt !== null ? ( $dt < 0 ? abs( $dt ) . ' days past' : 'in ' . $dt . ' days' ) : '—'; ?></small>
						<?php endif; ?>
					</div>
				<?php endforeach; endif; ?>
				<?php if ( ! in_array( $inv['status'], array( 'disputed', 'settled' ), true ) ) : ?>
					<form method="post" action="/invoice" class="pkg-row" style="gap:.6rem;flex-wrap:wrap;">
						<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
						<input type="hidden" name="action" value="dispute">
						<select class="pkg-input" name="forum" style="width:auto;"><option value="msefc">MSEFC (delayed payment)</option><option value="mediation">Mediation</option><option value="arbitration">Arbitration</option></select>
						<button class="pkg-btn pkg-btn--sm" type="submit">Start claim</button>
						<a class="pkg-btn pkg-btn--sm pkg-btn--ghost" href="/claim?id=<?php echo (int) $id; ?>">Preview evidence packet</a>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<div class="pkg-col-side">
			<div class="pkg-card">
				<h2 class="pkg-h2">Readiness</h2>
				<div class="pkg-score-big"><?php echo (int) $inv['readiness']; ?><span>/100</span></div>
				<div class="pkg-progress" style="margin:.5rem 0;"><span class="pkg-progress-bar pkg-progress-bar--<?php echo (int) $inv['readiness'] >= 85 ? 'success' : ( (int) $inv['readiness'] >= 60 ? 'info' : 'danger' ); ?>" style="width:<?php echo (int) $inv['readiness']; ?>%"></span></div>
				<p class="pkg-sub"><?php echo 'ready' === $inv['treds'] ? 'Finance-ready — evidence in, buyer on TReDS.' : ( 'pending_buyer_onboard' === $inv['treds'] ? 'Buyer not on TReDS — confirm before this churns.' : 'Not currently financeable.' ); ?></p>
				<div class="pkg-score-note"><span class="pkg-meta">Buyer TReDS</span> <strong><?php echo e( ucfirst( (string) $inv['treds_onboarded'] ) ); ?></strong></div>
			</div>
			<div class="pkg-card"><h2 class="pkg-h2">Buyer</h2><div><span class="pkg-meta">Name</span> <strong><?php echo e( $inv['buyer_name'] ); ?></strong></div><div style="margin-top:.5rem;"><span class="pkg-meta">Type</span> <?php echo badge( 'private' === $inv['buyer_type'] ? 'private' : $inv['buyer_type'] ); ?></div></div>
			<?php if ( $inv['notes'] ) : ?><div class="pkg-card"><h2 class="pkg-h2">Notes</h2><p class="pkg-sub"><?php echo e( $inv['notes'] ); ?></p></div><?php endif; ?>
		</div>
	</div>
	<?php
	return $ob . ob_get_clean();
}

function viewInvoiceForm( PayKaro $app, array $config, ?int $editId = null ): string {
	$buyers = $app->buyers();
	$inv = $editId ? $app->invoice( $editId ) : null;
	$today = date( 'Y-m-d' );
	ob_start();
	?>
	<?php echo pageHeader( $inv ? 'Edit invoice ' . e( $inv['number'] ) : 'Raise an invoice', $inv ? 'Edit the invoice details.' : 'Most fields are prefilled for you.' ); ?>
	<?php if ( 'invalid' === ( $_GET['err'] ?? '' ) ) : ?>
		<div class="pkg-callout pkg-callout--coral" style="margin-bottom:1rem;"><strong>Invoice not saved.</strong> Fill in the invoice number and date, pick one of your buyers, and enter a base amount greater than zero.</div>
	<?php endif; ?>
	<?php if ( ! $buyers ) : ?>
		<div class="pkg-card pkg-empty"><h2 class="pkg-h2">Add a buyer first</h2><p class="pkg-sub">Invoices reference a buyer.</p><a class="pkg-btn pkg-btn--primary" href="/buyers/new">+ Add buyer</a></div>
		<?php return ob_get_clean(); ?>
	<?php endif; ?>
	<form method="post" action="/invoice" class="pkg-card pkg-form">
		<?php if ( $inv ) { echo '<input type="hidden" name="action" value="update"><input type="hidden" name="id" value="' . (int) $inv['id'] . '">'; } ?>
		<div class="pkg-grid pkg-grid--2">
			<div class="pkg-field"><label class="pkg-label">Invoice number</label><input class="pkg-input" name="number" required value="<?php echo e( $inv['number'] ?? 'INV-' . date( 'Y' ) . '-' . rand( 1000, 9999 ) ); ?>"></div>
			<div class="pkg-field"><label class="pkg-label">Buyer</label><select class="pkg-input" name="buyer_id" required><?php foreach ( $buyers as $b ) : ?><option value="<?php echo (int) $b['id']; ?>" <?php echo ( $inv && (int) $inv['buyer_id'] === (int) $b['id'] ) ? 'selected' : ''; ?>><?php echo e( $b['name'] ); echo 'yes' === $b['treds_onboarded'] ? ' · TReDS ✓' : ''; ?></option><?php endforeach; ?></select></div>
			<div class="pkg-field"><label class="pkg-label">Invoice date</label><input class="pkg-input" type="date" name="invoice_date" required value="<?php echo e( $inv['invoice_date'] ?? $today ); ?>"></div>
			<div class="pkg-field"><label class="pkg-label">Due date (auto, <?php echo (int) $config['msme_due_days']; ?> days)</label><input class="pkg-input" type="date" value="<?php echo e( date( 'Y-m-d', strtotime( $inv['invoice_date'] ?? $today ) + $config['msme_due_days'] * 86400 ) ); ?>" disabled></div>
			<div class="pkg-field"><label class="pkg-label">Base amount (₹)</label><input class="pkg-input" name="base_amount" type="number" min="0" step="0.01" required value="<?php echo e( $inv['base_amount'] ?? '' ); ?>" oninput="pktax()"></div>
			<div class="pkg-field"><label class="pkg-label">Tax — GST <?php echo (int) $config['default_tax_rate']; ?>% (auto)</label><input class="pkg-input" name="tax_amount" type="number" step="0.01" id="pktax" value="<?php echo e( $inv['tax_amount'] ?? '' ); ?>" oninput="pktotal()"></div>
			<div class="pkg-field pkg-field--full"><label class="pkg-label">Total (auto)</label><input class="pkg-input" type="text" id="pktotal" readonly value="<?php echo e( $inv['total_amount'] ?? '' ); ?>"></div>
			<div class="pkg-field pkg-field--full"><label class="pkg-label">Notes</label><textarea class="pkg-input pkg-textarea" name="notes"><?php echo e( $inv['notes'] ?? '' ); ?></textarea></div>
		</div>
		<div class="pkg-form-actions"><button class="pkg-btn pkg-btn--primary" type="submit"><?php echo $inv ? 'Save changes' : 'Create invoice'; ?></button> <a class="pkg-btn" href="<?php echo $inv ? '/invoice?id=' . (int) $inv['id'] : '/invoices'; ?>">Cancel</a></div>
	</form>
	<script>function pktax(){var b=parseFloat(document.querySelector('[name=base_amount]').value||0);var t=<?php echo (float) $config['default_tax_rate']; ?>;var tx=document.getElementById('pktax');if(!tx.value)tx.value=(b*t/100).toFixed(2);pktotal();}function pktotal(){var b=parseFloat(document.querySelector('[name=base_amount]').value||0);var t=parseFloat(document.getElementById('pktax').value||0);document.getElementById('pktotal').value=(b+t).toFixed(2);}</script>
	<?php
	return ob_get_clean();
}

function viewBuyers( PayKaro $app ): string {
	$buyers = $app->buyers();
	$ob = pageHeader( 'Buyers', 'Who owes you money.', '<a class="pkg-btn pkg-btn--primary" href="/buyers/new">+ Add buyer</a>' );
	if ( ! $buyers ) {
		return $ob . '<div class="pkg-card pkg-empty"><p class="pkg-sub">No buyers yet.</p><a class="pkg-btn pkg-btn--primary" href="/buyers/new">+ Add buyer</a></div>';
	}
	$ob .= '<div class="pkg-card pkg-tablewrap"><table class="pkg-table"><thead><tr><th>Buyer</th><th>GSTIN</th><th>Type</th><th>TReDS</th><th>Outstanding</th></tr></thead><tbody>';
	foreach ( $buyers as $b ) {
		$ob .= '<tr><td><strong>' . e( $b['name'] ) . '</strong></td><td>' . e( $b['gstin'] ) . '</td><td>' . e( ucfirst( $b['type'] ) ) . '</td><td>' . e( 'yes' === $b['treds_onboarded'] ? 'Yes' : ( 'no' === $b['treds_onboarded'] ? 'No' : 'Unknown' ) ) . '</td><td><strong class="num">' . e( money( (float) $b['outstanding'] ) ) . '</strong></td></tr>';
	}
	$ob .= '</tbody></table></div>';
	return $ob;
}

function viewBuyerForm( PayKaro $app ): string {
	ob_start();
	?>
	<?php echo pageHeader( 'Add a buyer' ); ?>
	<?php if ( 'invalid' === ( $_GET['err'] ?? '' ) ) : ?>
		<div class="pkg-callout pkg-callout--coral" style="margin-bottom:1rem;"><strong>Buyer not saved.</strong> A buyer name is required.</div>
	<?php endif; ?>
	<form method="post" action="/buyers" class="pkg-card pkg-form">
		<div class="pkg-grid pkg-grid--2">
			<div class="pkg-field pkg-field--full"><label class="pkg-label">Buyer name</label><input class="pkg-input" name="name" required></div>
			<div class="pkg-field"><label class="pkg-label">GSTIN</label><input class="pkg-input" name="gstin"></div>
			<div class="pkg-field"><label class="pkg-label">Type</label><select class="pkg-input" name="type"><option value="private">Private</option><option value="psu">PSU</option><option value="cpse">CPSE</option></select></div>
			<div class="pkg-field pkg-field--full"><label class="pkg-label">TReDS onboarded?</label><select class="pkg-input" name="treds_onboarded"><option value="unknown">Unknown</option><option value="yes">Yes</option><option value="no">No</option></select></div>
		</div>
		<div class="pkg-form-actions"><button class="pkg-btn pkg-btn--primary" type="submit">Add buyer</button> <a class="pkg-btn" href="/buyers">Cancel</a></div>
	</form>
	<?php
	return ob_get_clean();
}

function viewTreds( PayKaro $app ): string {
	$queue = $app->financeQueue();
	$ob = pageHeader( 'Finance queue', 'Invoices that could be financed today (and the gaps holding the rest back).' );
	if ( ! $queue ) {
		return $ob . '<div class="pkg-card pkg-empty"><p class="pkg-sub">Nothing in the queue right now.</p><a class="pkg-btn pkg-btn--primary" href="/invoices/new">+ Raise an invoice</a></div>';
	}
	$ob .= '<div class="pkg-grid pkg-grid--3">';
	foreach ( $queue as $i ) {
		$ready = 'ready' === $i['treds'];
		$ob .= '<div class="pkg-card pkg-queue ' . ( $ready ? 'pkg-queue--ready' : '' ) . '"><div class="pkg-queue-top"><strong>' . e( $i['number'] ) . '</strong>' . tredsLabel( $i['treds'] ) . '</div><div class="pkg-muted">' . e( $i['buyer_name'] ) . ' · ' . e( $i['due_date'] ) . '</div><div class="pkg-queue-amt num">' . e( money( (float) $i['balance'] ) ) . '</div><div class="pkg-progress">' . progress( (int) $i['readiness'] ) . '</div>'
			. ( $ready ? '<a class="pkg-btn pkg-btn--sm pkg-btn--primary" href="/invoice?id=' . (int) $i['id'] . '">Finance it</a>' : '<div class="pkg-sub" style="margin-top:.5rem;">Missing evidence or buyer onboarding — open it to complete.</div>' ) . '</div>';
	}
	$ob .= '</div>';
	return $ob;
}

function viewReports( PayKaro $app, array $config ): string {
	$invoices = $app->invoices();
	$byBuyer = array();
	foreach ( $invoices as $i ) {
		if ( 'settled' === $i['status'] ) { continue; }
		$byBuyer[ $i['buyer_name'] ] = ( $byBuyer[ $i['buyer_name'] ] ?? 0 ) + (float) $i['balance'];
	}
	$ob = pageHeader( 'Cash-flow & reports', 'Outstanding by buyer and the next-30-day receivable picture.' );
	$ob .= '<div class="pkg-grid pkg-grid--2">';
	$ob .= '<div class="pkg-card"><h2 class="pkg-h2">Outstanding by buyer</h2><div class="pkg-buckets">';
	$max = max( 0.001, max( array_map( 'floatval', array_values( $byBuyer ) ?: array( 0 ) ) ) );
	foreach ( $byBuyer as $name => $val ) {
		$pct = round( $val / $max * 100 );
		$ob .= '<div class="pkg-bucket"><div class="pkg-bucket-row"><span class="pkg-bucket-name">' . e( $name ) . '</span><span class="pkg-bucket-value num">' . e( money( $val ) ) . '</span></div><div class="pkg-bucket-bar"><span class="pkg-bucket-fill pkg-bucket-fill--info" style="width:' . $pct . '%"></span></div></div>';
	}
	if ( ! $byBuyer ) { $ob .= '<p class="pkg-sub">No outstanding invoices.</p>'; }
	$ob .= '</div></div>';
	$d = $app->dashboard();
	$ob .= '<div class="pkg-card"><h2 class="pkg-h2">Summary</h2><ul class="pkg-statlist">'
		. '<li><span>Total outstanding</span><strong class="num">' . e( money( (float) $d['total'] ) ) . '</strong></li>'
		. '<li><span>Overdue</span><strong class="num pkg-neg">' . e( money( (float) $d['overdue'] ) ) . '</strong></li>'
		. '<li><span>Invoices due in 30d</span><strong class="num">' . e( money( (float) $d['in30d'] ) ) . '</strong></li>'
		. '<li><span>Interest to demand</span><strong class="num pkg-neg">' . e( money( (float) $d['interest'] ) ) . '</strong></li>'
		. '</ul><a class="pkg-btn pkg-btn--link" href="/treds">Go to finance queue →</a></div>';
	$ob .= '</div>';
	return $ob;
}

function viewSettings( PayKaro $app, array $config ): string {
	$b = $app->business();
	ob_start();
	?>
	<?php echo pageHeader( 'Settings', 'Your business and bank details. Where settled money lands.' ); ?>
	<form method="post" action="/settings" class="pkg-card pkg-form">
		<div class="pkg-grid pkg-grid--2">
			<div class="pkg-field pkg-field--full"><label class="pkg-label">Business name</label><input class="pkg-input" name="name" value="<?php echo e( $b['name'] ); ?>" required></div>
			<div class="pkg-field"><label class="pkg-label">GSTIN</label><input class="pkg-input" name="gstin" value="<?php echo e( $b['gstin'] ); ?>"></div>
			<div class="pkg-field"><label class="pkg-label">PAN</label><input class="pkg-input" name="pan" value="<?php echo e( $b['pan'] ); ?>"></div>
			<div class="pkg-field"><label class="pkg-label">Udyam no.</label><input class="pkg-input" name="udyam_no" value="<?php echo e( $b['udyam_no'] ); ?>"></div>
			<div class="pkg-field pkg-field--full"><label class="pkg-label">Bank name</label><input class="pkg-input" name="bank_name" value="<?php echo e( $b['bank_name'] ); ?>"></div>
			<div class="pkg-field"><label class="pkg-label">Account number</label><input class="pkg-input" name="bank_acc_no" value="<?php echo e( $b['bank_acc_no'] ); ?>"></div>
			<div class="pkg-field"><label class="pkg-label">IFSC</label><input class="pkg-input" name="bank_ifsc" value="<?php echo e( $b['bank_ifsc'] ); ?>"></div>
			<div class="pkg-field pkg-field--full"><label class="pkg-label">TReDS registered?</label><select class="pkg-input" name="treds_registered"><option value="0" <?php echo $b['treds_registered'] ? '' : 'selected'; ?>>No</option><option value="1" <?php echo $b['treds_registered'] ? 'selected' : ''; ?>>Yes</option></select></div>
		</div>
		<div class="pkg-form-actions"><button class="pkg-btn pkg-btn--primary" type="submit">Save</button></div>
	</form>
	<?php
	return ob_get_clean();
}

function viewClaim( PayKaro $app, int $id ): string {
	$inv = $app->invoice( $id );
	if ( ! $inv ) {
		return '<div class="pkg-card pkg-empty"><h2 class="pkg-h2">Invoice not found</h2><a class="pkg-btn" href="/invoices">Back</a></div>';
	}
	$rows = $app->claimPacket( $id );
	$ob = pageHeader( 'Claim evidence packet', e( $inv['number'] ) . ' · prepared for filing', '<a class="pkg-btn pkg-btn--ghost" href="/invoice?id=' . (int) $id . '">← Invoice</a>' );
	$ob .= '<div class="pkg-callout"><strong>MSEFC / delayed-payment claim packet</strong><small>Complete this checklist before filing. A time-bound claim is only as strong as the documents behind it.</small></div>';
	$ob .= '<div class="pkg-card pkg-tablewrap"><table class="pkg-table"><thead><tr><th style="width:14rem;">Item</th><th>Value</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$missing = false !== strpos( $r['value'], 'MISSING' );
		$ob .= '<tr><td>' . e( $r['label'] ) . '</td><td' . ( $missing ? ' class="pkg-neg"' : '' ) . '>' . e( $r['value'] ) . '</td></tr>';
	}
	$ob .= '</tbody></table></div>';
	$ob .= '<div class="pkg-row" style="gap:.6rem;"><button class="pkg-btn pkg-btn--primary" onclick="window.print()">Print packet</button> <a class="pkg-btn" href="/invoice?id=' . (int) $id . '">Back to invoice</a></div>';
	return $ob;
}

/* ------------------------------------------------------------------ */
/* Router + response                                                  */
/* ------------------------------------------------------------------ */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = $_SERVER['REQUEST_URI'] ?? '/';
$path   = parse_url( $uri, PHP_URL_PATH ) ?: '/';
$g      = $_GET ?? array();
$p      = $_POST ?? array();

$response = array( 'status' => 200, 'type' => 'text/html; charset=utf-8', 'location' => null, 'cookies' => array(), 'body' => '' );

// Public paths.
$public = array( '/login', '/logout', '/assets', '/signup', '/auth/google', '/auth/google/callback' );

// ---- Auth resolution ----
$user = null;
if ( isset( $_COOKIE[ COOKIE_NAME ] ) && $_COOKIE[ COOKIE_NAME ] ) {
	$user = $app->userByToken( (string) $_COOKIE[ COOKIE_NAME ] );
}

// ---- POST: login / logout ----
if ( 'POST' === $method && '/login' === $path ) {
	$u = $app->authenticate( (string) ( $p['email'] ?? '' ), (string) ( $p['password'] ?? '' ) );
	if ( $u ) {
		$token = $app->createSession( (int) $u['id'] );
		$response['status']   = 302;
		$response['location'] = '/';
		$response['cookies'][] = array( COOKIE_NAME, $token, (int) $config['session_ttl'] );
		echo json_encode( $response );
		exit;
	}
	$response['status']   = 302;
	$response['location'] = '/login?err=1';
	echo json_encode( $response );
	exit;
}
if ( 'POST' === $method && '/logout' === $path ) {
	if ( $user && isset( $_COOKIE[ COOKIE_NAME ] ) ) {
		$app->destroySession( (string) $_COOKIE[ COOKIE_NAME ] );
	}
	$response['status']   = 302;
	$response['location'] = '/login';
	$response['cookies'][] = array( COOKIE_NAME, '', 0 ); // expire
	echo json_encode( $response );
	exit;
}

// ---- POST: sign-up (public) ---- creates a business + owner and logs them in.
if ( 'POST' === $method && '/signup' === $path ) {
	$d = array(
		'business_name' => (string) ( $p['business_name'] ?? '' ),
		'name'          => (string) ( $p['name'] ?? '' ),
		'email'         => (string) ( $p['email'] ?? '' ),
		'password'      => (string) ( $p['password'] ?? '' ),
	);
	$u = $app->registerBusiness( $d );
	if ( ! $u ) {
		$response['status']   = 302;
		$response['location'] = '/signup?err=' . ( $app->userByEmail( $d['email'] ) ? 'taken' : 'fields' );
		echo json_encode( $response );
		exit;
	}
	$token = $app->createSession( (int) $u['id'] );
	$response['status']   = 302;
	$response['location'] = '/';
	$response['cookies'][] = array( COOKIE_NAME, $token, (int) $config['session_ttl'] );
	echo json_encode( $response );
	exit;
}

// ---- GET: begin Google Sign-In (public) ---- generates a one-time CSRF state,
// stores it, then bounces the browser over to Google.
if ( '/auth/google' === $path ) {
	if ( ! googleEnabled( $config ) ) {
		$response['status']   = 302;
		$response['location'] = '/login';
		echo json_encode( $response );
		exit;
	}
	$state = bin2hex( random_bytes( 16 ) );
	$app->storeOAuthState( $state );
	$response['status']   = 302;
	$response['location'] = googleAuthUrl( $config, $state );
	echo json_encode( $response );
	exit;
}

// ---- GET: Google OAuth callback (public) ---- validates state, exchanges the
// code for a token, fetches the profile, then provisions/signs the user in.
if ( '/auth/google/callback' === $path ) {
	$state = (string) ( $g['state'] ?? '' );
	$code  = (string) ( $g['code'] ?? '' );
	if ( '' === $state || '' === $code || ! $app->consumeOAuthState( $state ) ) {
		// Missing/invalid state = CSRF or tampered request.
		$response['status']   = 302;
		$response['location'] = '/login?err=oauth';
		echo json_encode( $response );
		exit;
	}
	if ( isset( $g['error'] ) ) {
		$response['status']   = 302;
		$response['location'] = '/login?err=oauth';
		echo json_encode( $response );
		exit;
	}
	$token = googleExchange( $config, $code, googleRedirectUri( $config ) );
	if ( ! $token ) {
		$response['status']   = 302;
		$response['location'] = '/login?err=oauth';
		echo json_encode( $response );
		exit;
	}
	$profile = googleProfile( (string) $token['access_token'] );
	$u       = $profile ? $app->findOrCreateGoogleUser( $profile ) : null;
	if ( ! $u ) {
		$response['status']   = 302;
		$response['location'] = '/login?err=oauth';
		echo json_encode( $response );
		exit;
	}
	$session = $app->createSession( (int) $u['id'] );
	$response['status']   = 302;
	$response['location'] = '/';
	$response['cookies'][] = array( COOKIE_NAME, $session, (int) $config['session_ttl'] );
	echo json_encode( $response );
	exit;
}

// ---- GET: news articles (public — same pages for signed-in and anonymous) ----
if ( '/news' === $path || str_starts_with( $path, '/news/' ) ) {
	$slug    = substr( $path, strlen( '/news/' ) ); // '' for bare /news, slug otherwise.
	$article = null;
	foreach ( paykaroNews() as $a ) {
		if ( $a['slug'] === $slug ) {
			$article = $a;
			break;
		}
	}
	if ( $article ) {
		$response['status'] = 200;
		$response['body']   = newsArticlePage( $config, $article );
	} else {
		// Bare /news or an unknown slug — land back on the news section.
		$response['status']   = 302;
		$response['location'] = '/#news';
	}
	echo json_encode( $response );
	exit;
}

// ---- Public pages: '/' landing, '/login', '/pricing' are public ----
if ( ! $user ) {
	$response['status'] = 200;
	if ( '/' === $path ) { $response['body'] = landingPage( $config ); }
	elseif ( '/pricing' === $path ) { $response['body'] = pricingPage( $config ); }
	elseif ( '/signup' === $path ) { $response['body'] = signupPage( $config ); }
	elseif ( '/help' === $path ) { $response['body'] = helpPage( $config ); }
	elseif ( '/contact' === $path ) { $response['body'] = contactPage( $config ); }
	elseif ( '/terms' === $path || '/privacy' === $path || '/security' === $path ) {
		$response['body'] = helpPage( $config ); // Redirect to help for now
	}
	elseif ( '/auth/google' === $path || '/auth/google/callback' === $path ) {
		$response['status']   = 302;
		$response['location'] = '/login';
	} else { $response['body'] = loginPage( $config ); }
	echo json_encode( $response );
	exit;
}

// Set tenant from the logged-in user.
$app->setTenant( (int) ( $user['business_id'] ?? 0 ) );

// ---- POST mutations (auth required) ----
if ( 'POST' === $method ) {
	$action = $p['action'] ?? '';
	if ( '/invoice' === $path ) {
		$id = (int) ( $p['id'] ?? 0 );
		if ( 'status' === $action ) { $app->setStatus( $id, $p['status'] ?? 'raised' ); $loc = '/invoice?id=' . $id; }
		elseif ( 'evidence' === $action ) { $app->setEvidence( $id, $p['type'] ?? 'po', ! empty( $p['present'] ) ); $loc = '/invoice?id=' . $id; }
		elseif ( 'payment' === $action ) { $app->recordPayment( $id, $p ); $loc = '/invoice?id=' . $id; }
		elseif ( 'finance' === $action ) { $app->recordFinancing( $id, $p ); $loc = '/invoice?id=' . $id; }
		elseif ( 'dispute' === $action ) { $app->startDispute( $id, $p ); $loc = '/invoice?id=' . $id; }
		elseif ( 'update' === $action ) { $app->updateInvoice( $id, $p ); $loc = '/invoice?id=' . $id; }
		else {
			// createInvoice() returns 0 on an invalid payload — bounce back
			// to the form with a message instead of fataling.
			$newId = $app->createInvoice( $p );
			$loc   = $newId > 0 ? '/invoice?id=' . $newId : '/invoices/new?err=invalid';
		}
		redirect( $loc, $response );
		echo json_encode( $response );
		exit;
	}
	if ( '/buyers' === $path ) {
		$buyerId = $app->createBuyer( $p );
		redirect( $buyerId > 0 ? '/buyers' : '/buyers/new?err=invalid', $response );
		echo json_encode( $response );
		exit;
	}
	if ( '/settings' === $path ) {
		$app->updateBusiness( $p );
		redirect( '/settings', $response );
		echo json_encode( $response );
		exit;
	}
	if ( '/alerts/read' === $path ) {
		$app->markAlertsRead();
		redirect( '/', $response );
		echo json_encode( $response );
		exit;
	}
}

// ---- GET routes ----
$content = ''; $title = $config['name']; $active = 'dashboard';
	switch ( $path ) {
		case '/login':
		case '/logout':
		case '/signup':
			$response['status']   = 302;
			$response['location'] = '/';
			echo json_encode( $response );
			exit;
		case '/':  $title = 'Dashboard'; $active = 'dashboard'; $content = viewDashboard( $app, $config ); break;
		case '/invoices': $title = 'Invoices'; $active = 'invoices'; $content = viewInvoices( $app ); break;
		case '/invoices/new': $title = 'Raise an invoice'; $active = 'invoices'; $content = viewInvoiceForm( $app, $config, null ); break;
		case '/invoice': $title = 'Invoice'; $active = 'invoices';
			// Edit mode: canonical `/invoice?edit=ID` or legacy `/invoice?id=ID&edit=1`.
			if ( isset( $g['edit'] ) ) {
				$editId  = ! empty( $g['id'] ) ? (int) $g['id'] : (int) $g['edit'];
				// Editing an invoice that doesn't exist (or belongs to another
				// tenant) must say so — not silently fall back to a blank
				// create form.
				$content = $app->invoice( $editId )
					? viewInvoiceForm( $app, $config, $editId )
					: '<div class="pkg-card pkg-empty"><h2 class="pkg-h2">Invoice not found</h2><a class="pkg-btn" href="/invoices">Back to invoices</a></div>';
			} else {
				$content = viewInvoice( $app, $config, (int) ( $g['id'] ?? 0 ) );
			}
			break;
		case '/claim': $title = 'Evidence packet'; $active = 'invoices'; $content = viewClaim( $app, (int) ( $g['id'] ?? 0 ) ); break;
		case '/buyers': $title = 'Buyers'; $active = 'buyers'; $content = viewBuyers( $app ); break;
		case '/buyers/new': $title = 'Add a buyer'; $active = 'buyers'; $content = viewBuyerForm( $app ); break;
		case '/treds': $title = 'Finance queue'; $active = 'treds'; $content = viewTreds( $app ); break;
		case '/reports': $title = 'Reports'; $active = 'reports'; $content = viewReports( $app, $config ); break;
		case '/settings': $title = 'Settings'; $active = 'settings'; $content = viewSettings( $app, $config ); break;
		// Public standalone pages — render the same full pages anonymous
		// visitors get (they carry their own header/footer). Redirecting to
		// $path itself would loop forever.
		case '/help': case '/contact': case '/pricing':
		case '/terms': case '/privacy': case '/security':
			$response['status'] = 200;
			if ( '/pricing' === $path ) {
				$response['body'] = pricingPage( $config );
			} elseif ( '/contact' === $path ) {
				$response['body'] = contactPage( $config );
			} else {
				$response['body'] = helpPage( $config );
			}
			echo json_encode( $response );
			exit;
		default: $response['status'] = 404; $content = '<div class="pkg-card pkg-empty"><h2 class="pkg-h2">Not found</h2><a class="pkg-btn" href="/">Home</a></div>';
	}

	$config['business'] = $app->business()['name'] ?? $config['name'];
	$response['body'] = layout( $config, $title, $content, $active, $app->dashboard(), $user );
	echo json_encode( $response );

function redirect( string $loc, array &$response ): void {
	$response['status']   = 302;
	$response['location'] = $loc;
}

/* ------------------------------------------------------------------ */
/* Google Sign-In (OAuth 2.0) helpers                                 */
/* ------------------------------------------------------------------ */

function googleEnabled( array $config ): bool {
	return ! empty( $config['google_oauth']['client_id'] ) && ! empty( $config['google_oauth']['client_secret'] );
}

/**
 * The redirect URI registered with Google. Prefer the explicit config/env
 * value; otherwise derive it from the incoming Host header so the live
 * preview (https://{port}-{sandboxId}.e2b.app) works without config.
 */
function googleRedirectUri( array $config ): string {
	if ( ! empty( $config['google_oauth']['redirect_uri'] ) ) {
		return $config['google_oauth']['redirect_uri'];
	}
	$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
	$scheme = 'https'; // OAuth requires HTTPS for non-localhost live sites.
	return $scheme . '://' . $host . '/auth/google/callback';
}

/** Build the Google authorization URL for the logged-out state. */
function googleAuthUrl( array $config, string $state ): string {
	$params = array(
		'client_id'     => $config['google_oauth']['client_id'],
		'redirect_uri'  => googleRedirectUri( $config ),
		'response_type' => 'code',
		'scope'         => 'openid email profile',
		'state'         => $state,
		'access_type'   => 'online',
		'prompt'        => 'select_account',
	);
	return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params );
}

/**
 * Minimal HTTP helper using cURL when present, otherwise PHP streams. Returns
 * a decoded array on success, or null on any failure.
 */
function paykaro_http( string $method, string $url, array $headers = array(), ?string $body = null ): ?array {
	// cURL path (native PHP deployments).
	if ( function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 12 );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
		if ( 'POST' === $method ) {
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, (string) $body );
		}
		$h = array();
		foreach ( $headers as $k => $v ) { $h[] = $k . ': ' . $v; }
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $h );
		$res = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		if ( false === $res ) { return null; }
		$json = json_decode( (string) $res, true );
		return is_array( $json ) ? $json : array( '_code' => $code, '_raw' => (string) $res );
	}
	// PHP streams fallback.
	$hdr = '';
	foreach ( $headers as $k => $v ) { $hdr .= $k . ': ' . $v . "\r\n"; }
	$ctx = stream_context_create( array(
		'http' => array(
			'method'        => $method,
			'header'        => $hdr,
			'content'       => (string) $body,
			'timeout'       => 12,
			'ignore_errors' => true,
		),
		'ssl' => array( 'verify_peer' => true, 'verify_peer_name' => true ),
	) );
	$res = @file_get_contents( $url, false, $ctx );
	if ( false === $res ) { return null; }
	$json = json_decode( (string) $res, true );
	return is_array( $json ) ? $json : array( '_raw' => (string) $res );
}

/** Exchange the authorization code for an access token. Returns token array or null. */
function googleExchange( array $config, string $code, string $redirectUri ): ?array {
	$body = http_build_query( array(
		'code'          => $code,
		'client_id'     => $config['google_oauth']['client_id'],
		'client_secret' => $config['google_oauth']['client_secret'],
		'redirect_uri'  => $redirectUri,
		'grant_type'    => 'authorization_code',
	) );
	$res = paykaro_http( 'POST', 'https://oauth2.googleapis.com/token', array( 'Content-Type' => 'application/x-www-form-urlencoded' ), $body );
	if ( ! $res || empty( $res['access_token'] ) ) {
		return null;
	}
	return $res;
}

/** Fetch the Google profile (sub/email/name/picture) with the access token. */
function googleProfile( string $accessToken ): ?array {
	$res = paykaro_http( 'GET', 'https://www.googleapis.com/oauth2/v3/userinfo', array( 'Authorization' => 'Bearer ' . $accessToken ) );
	if ( ! $res || empty( $res['sub'] ) || empty( $res['email'] ) ) {
		return null;
	}
	return array(
		'google_id'  => (string) $res['sub'],
		'email'      => (string) $res['email'],
		'name'       => (string) ( $res['name'] ?? '' ),
		'avatar_url' => (string) ( $res['picture'] ?? '' ),
	);
}

/** "Continue with Google" button, or '' when OAuth isn't configured. */
function googleButtonHtml( array $config ): string {
	if ( ! googleEnabled( $config ) ) {
		return '';
	}
	$g = '<span aria-hidden="true" style="display:inline-flex;width:1.05rem;height:1.05rem;flex-shrink:0;">'
		. '<svg viewBox="0 0 48 48" width="20" height="20"><path fill="#4285F4" d="M45.12 24.5c0-1.56-.14-3.06-.4-4.5H24v8.51h11.84a10.1 10.1 0 0 1-4.39 6.62v5.5h7.1c4.16-3.83 6.57-9.47 6.57-16.13z"/><path fill="#34A853" d="M24 46c5.94 0 10.92-1.97 14.56-5.33l-7.1-5.5c-1.97 1.32-4.49 2.1-7.46 2.1-5.73 0-10.58-3.87-12.31-9.07H4.34v5.67A21.98 21.98 0 0 0 24 46z"/><path fill="#FBBC05" d="M11.69 28.2a13.2 13.2 0 0 1 0-8.4v-5.67H4.34a21.98 21.98 0 0 0 0 19.74l7.35-5.67z"/><path fill="#EA4335" d="M24 10.75c3.23 0 6.12 1.11 8.4 3.29l6.3-6.3A21.96 21.96 0 0 0 24 2a21.98 21.98 0 0 0-19.66 12.13l7.35 5.67C13.42 14.62 18.27 10.75 24 10.75z"/></svg></span>';
	return '<a class="auth-google" href="/auth/google">' . $g . ' Continue with Google</a>';
}
