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
		'raised'   => array( 'Raised', 'brand' ),
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

// Two-tone PayKaro wordmark: "Pay" in white + "Karo" in brand lime.
function brandWordmark(): string {
	$name = 'PayKaro';
	// Split on the camelCase boundary: "Pay" (white) + "Karo" (green).
	$parts = preg_split( '/(?<=[a-z])(?=[A-Z])/', $name );
	if ( 2 === count( $parts ) ) {
		return '<span class="pkg-brand-a">' . e( $parts[0] ) . '</span><span class="pkg-brand-b">' . e( $parts[1] ) . '</span>';
	}
	return e( $name );
}

// PayKaro logo mark: a rounded moss tile with a lime "₹", matching the
// design.html brand lockup. Works on light and dark surfaces.
function logoMark( string $class = 'pkg-side-logo' ): string {
	$svg = '<svg width="34" height="34" viewBox="0 0 34 34" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
		. '<rect width="34" height="34" rx="11" fill="#1d4c3b"/>'
		. '<path d="M20.5 8h-7.6v4h7.6a2.5 2.5 0 0 1 0 5h-7.6" stroke="#d6f45a" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
		. '<path d="M12.9 12v14M16.3 21h5.6M12.9 21h5.6" stroke="#d6f45a" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
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
function kpiCard( string $label, string $value, string $trend = '', string $icon = '₹', string $tone = 'brand' ): string {
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
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="/assets/app.css">
	</head>
	<body class="pkg">
	<div class="pkg-shell">

		<aside class="pkg-side">
			<a class="pkg-side-brand" href="/">
				<?php echo logoMark(); ?>
				<span>
					<div class="pkg-side-brand-name"><?php echo brandWordmark(); ?></div>
					<div class="pkg-side-brand-tag"><?php echo e( $b ); ?></div>
				</span>
			</a>

			<div class="pkg-side-label">Overview</div>
			<nav class="pkg-side-nav">
				<?php foreach ( $nav as $key => $n ) : ?>
					<a class="pkg-side-link <?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo $n[0]; ?>">
						<span class="pkg-side-ico"><?php echo sideIcon( $key ); ?></span>
						<span><?php echo e( $n[1] ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="pkg-upgrade">
				<h4>Upgrade to Pro</h4>
				<p>Unlock advanced reports, automations &amp; more.</p>
				<a class="pkg-upgrade-btn" href="/pricing">See pricing</a>
			</div>

			<div class="pkg-side-user">
				<div class="pkg-avatar"><?php echo e( $initial ); ?></div>
				<div>
					<div class="pkg-side-user-name"><?php echo e( $b ); ?></div>
					<div class="pkg-side-user-role"><?php echo e( $role ); ?></div>
				</div>
			</div>

			<form method="post" action="/logout" style="margin:.1rem .5rem;">
				<button class="pkg-side-collapse" type="submit" style="display:flex;align-items:center;gap:.5rem;border:0;background:none;cursor:pointer;color:var(--n-side-mute);font-size:.74rem;">◂ Log out</button>
			</form>

			<div class="pkg-side-collapse" style="display:flex;align-items:center;gap:.5rem;">
				<span style="cursor:pointer" onclick="var d=document.documentElement;d.classList.toggle('dark');try{localStorage.setItem('pk-theme',d.classList.contains('dark')?'dark':'light')}catch(e){}" role="button">◐ Theme</span>
			</div>
		</aside>

		<div class="pkg-main-wrap">
			<main class="pkg-main"><?php echo $content; ?></main>
			<footer class="pkg-footer"><?php echo e( $config['name'] ); ?> · <?php echo e( $config['tagline'] ); ?> · demo</footer>
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
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="/assets/app.css">
	<style>
		.pk-login{min-height:100vh;display:flex;font-family:var(--n-font);}
		.pk-authbrand{flex:0 0 46%;max-width:46%;background:linear-gradient(165deg,#10231e 0%,#17342b 58%,#0e2a20 100%);color:#eef2e6;display:flex;flex-direction:column;justify-content:space-between;padding:2.8rem 2.9rem;position:relative;overflow:hidden;}
		.pk-authbrand::before{content:"";position:absolute;top:-6rem;right:-6rem;width:20rem;height:20rem;border-radius:999px;background:radial-gradient(circle,#d6f45a33,transparent 60%);}
		.pk-authbrand::after{content:"";position:absolute;inset:0;opacity:.05;background-image:url("https://www.transparenttextures.com/patterns/noise-pattern-with-subtle-cross-lines.png");pointer-events:none;}
		.pk-brandrow{display:flex;align-items:center;gap:.8rem;position:relative;z-index:1;}
		.pk-brandname{font-family:var(--n-display);font-weight:600;font-size:1.5rem;letter-spacing:-.02em;line-height:1.05;color:#fff;}
		.pk-brandname .pk-karo{color:#d6f45a;}
		.pk-tagline{font-size:.78rem;color:#9db0a5;margin-top:.15rem;}
		.pk-bigline{font-family:var(--n-display);font-size:2.35rem;font-weight:600;letter-spacing:-.03em;line-height:1.16;margin-bottom:1.1rem;color:#fff;}
		.pk-copy{color:#c7d0de;font-size:.94rem;line-height:1.68;max-width:26rem;margin:0;}
		.pk-chips{display:flex;gap:.6rem;margin-top:1.7rem;flex-wrap:wrap;}
		.pk-chip{background:rgba(214,244,90,.12);color:#d6f45a;border:1px solid rgba(214,244,90,.2);border-radius:999px;padding:.35rem .75rem;font-size:.74rem;font-weight:600;}
		.pk-authfoot{font-size:.74rem;color:#7f948a;position:relative;z-index:1;}
		.pk-formside{flex:1;display:flex;align-items:center;justify-content:center;padding:2rem;background:radial-gradient(circle at 88% 6%,rgba(214,244,90,.22),transparent 20rem),linear-gradient(180deg,#f5f4ed 0%,#eef1e9 55%,#f5f4ed 100%);}
		.pk-pagewrap{width:100%;max-width:24.5rem;}
		.pk-heading{font-family:var(--n-display);font-size:1.9rem;font-weight:600;letter-spacing:-.02em;line-height:1.1;color:var(--n-ink);}
		.pk-sub{color:#587169;font-size:.92rem;margin:.55rem 0 1.5rem;line-height:1.6;}
		.pk-label{display:block;font-size:.72rem;font-weight:700;color:#7c8f86;margin-bottom:.35rem;}
		.pk-input{width:100%;height:2.65rem;border-radius:12px;border:1px solid rgba(16,35,30,.15);background:#fffdf6;color:var(--n-ink);padding:0 .9rem;font-size:.9rem;transition:all .15s;margin-bottom:.95rem;}
		.pk-input:focus{outline:none;border-color:#1d4c3b;box-shadow:0 0 0 3px #eaf9b8;}
		.pk-cta{width:100%;display:inline-flex;align-items:center;justify-content:center;border-radius:12px;padding:.7rem 1rem;font-size:.92rem;font-weight:700;border:0;cursor:pointer;background:#d6f45a;color:#10231e;transition:transform .15s,filter .15s;box-shadow:0 10px 22px rgba(214,244,90,.4);}
		.pk-cta:hover{transform:translateY(-1px);filter:brightness(.97);}
		.pk-err{background:#f9e0d8;border-left:4px solid #e7684f;border-radius:0 12px 12px 0;padding:.75rem 1rem;font-size:.85rem;color:#10231e;margin-bottom:1.1rem;}
		@media (max-width:940px){.pk-authbrand{display:none;}.pk-formside{padding:2rem 1.3rem;}}
	</style></head>
	<body class="pkg" style="margin:0;">
	<div class="pk-login">
		<!-- Brand panel -->
		<div class="pk-authbrand">
			<div style="position:relative;z-index:1;">
				<div class="pk-brandrow">
					<?php echo logoMark(); ?>
					<div>
						<div class="pk-brandname"><span>Pay</span><span class="pk-karo">Karo</span></div>
						<div class="pk-tagline"><?php echo e( $config['tagline'] ); ?></div>
					</div>
				</div>
			</div>
			<div style="position:relative;z-index:1;">
				<div class="pk-bigline">Turn invoice mess into<br>finance-ready receivables.</div>
				<p class="pk-copy">One pipeline for every invoice — evidence complete, interest computed, and ready to finance or claim the moment it's overdue.</p>
				<div class="pk-chips">
					<span class="pk-chip">🔒 Multi-tenant secured</span>
					<span class="pk-chip">⚙ TReDS-ready</span>
					<span class="pk-chip">⏱ 45-day due window</span>
				</div>
			</div>
			<div class="pk-authfoot">&copy; <?php echo e( $config['name'] ); ?> · demo</div>
		</div>

		<!-- Form panel -->
		<div class="pk-formside">
			<div class="pk-pagewrap">
				<h1 class="pk-heading">Welcome back</h1>
				<p class="pk-sub">Sign in to your workspace. Each user sees only their own business's receivables.</p>

				<?php if ( ! empty( $_GET['err'] ) ) : ?><div class="pk-err">Invalid email or password. Please check your details and try again.</div><?php endif; ?>

				<form method="post" action="/login">
					<label class="pk-label">Email</label><input class="pk-input" type="email" name="email" required placeholder="you@company.in" autofocus>
					<label class="pk-label">Password</label><input class="pk-input" type="password" name="password" required placeholder="••••••••">
					<button class="pk-cta" type="submit">Sign in →</button>
				</form>
			</div>
		</div>
	</div>
	</body>
	</html>
	<?php
	return ob_get_clean();
}

/* Public landing page — reproduces the design.html marketing home. */
function landingPage( array $config ): string {
	ob_start();
	?>
	<!doctype html>
	<html lang="en">
	<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>PayKaro — Make every invoice count</title>
	<script src="https://cdn.tailwindcss.com/3.4.17"></script>
	<script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&amp;family=Fraunces:opsz,wght@9..144,600;9..144,700&amp;display=swap" rel="stylesheet">
	<style>
		:root {
			--ink: #10231e;
			--paper: #f5f4ed;
			--moss: #1d4c3b;
			--lime: #d6f45a;
			--muted: #587169;
			--line: rgba(16, 35, 30, 0.16);
			--coral: #e7684f;
		}
		* { box-sizing: border-box; }
		html { scroll-behavior: smooth; }
		body { margin: 0; width: 100%; font-family: "DM Sans", sans-serif; color: var(--ink); background: var(--paper); }
		.page-shell { width: 100%; overflow: hidden; background: radial-gradient(circle at 91% 7%, rgba(214, 244, 90, 0.3), transparent 25rem), linear-gradient(180deg, #f5f4ed 0%, #eef1e9 55%, #f5f4ed 100%); }
		.display-face { font-family: "Fraunces", serif; }
		.grain { position: fixed; inset: 0; pointer-events: none; opacity: 0.035; z-index: 30; background-image: url("https://www.transparenttextures.com/patterns/noise-pattern-with-subtle-cross-lines.png"); }
		.nav-link { color: #426057; text-decoration: none; transition: color 180ms ease; }
		.nav-link:hover { color: var(--ink); }
		.focus-ring:focus-visible { outline: 3px solid #d6f45a; outline-offset: 3px; }
		.hero-grid { background-image: linear-gradient(rgba(29, 76, 59, 0.07) 1px, transparent 1px), linear-gradient(90deg, rgba(29, 76, 59, 0.07) 1px, transparent 1px); background-size: 32px 32px; }
		.entry { animation: rise 700ms both cubic-bezier(.2,.8,.2,1); }
		.entry-delay-1 { animation-delay: 110ms; }
		.entry-delay-2 { animation-delay: 210ms; }
		.entry-delay-3 { animation-delay: 310ms; }
		@keyframes rise { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: translateY(0); } }
		.dashboard-shadow { box-shadow: 0 30px 75px rgba(14, 48, 37, 0.24); }
		.pipeline-line { height: 2px; background: linear-gradient(90deg, #d6f45a 0%, #d6f45a 72%, rgba(214, 244, 90, .25) 72%); }
		.receipt-paper { background: linear-gradient(135deg, #fffdf4 0%, #f1eddf 100%); box-shadow: 12px 14px 0 rgba(16, 35, 30, 0.1); transform: rotate(3deg); }
		.feature-card { transition: transform 220ms ease, box-shadow 220ms ease; }
		.feature-card:hover { transform: translateY(-6px); box-shadow: 0 22px 42px rgba(16, 35, 30, 0.12); }
		.preview-hidden { display: none; }
		@media (max-width: 767px) { .desktop-nav { display: none; } .mobile-nav-open { display: flex; } }
	</style>
	</head>
	<body class="antialiased">
	<div class="page-shell">
		<div class="grain" aria-hidden="true"></div>
		<header class="w-full sticky top-0 z-20 border-b border-[#10231e]/10 bg-[#f5f4ed]/90 backdrop-blur-md">
			<nav class="mx-auto flex max-w-7xl items-center justify-between px-5 py-4 lg:px-8" aria-label="Primary navigation">
				<a href="#top" class="focus-ring flex items-center gap-2 rounded-md" aria-label="PayKaro home">
					<span class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#1d4c3b] text-lg font-bold text-[#d6f45a]">₹</span>
					<span class="text-xl font-bold tracking-tight" style="font-family:Fraunces,serif;"><span class="text-[#10231e]">Pay</span><span class="text-[#1d4c3b]">Karo</span></span>
				</a>
				<div class="desktop-nav hidden items-center gap-7 text-sm font-medium md:flex">
					<a class="nav-link focus-ring rounded" href="#workflow">Workflow</a>
					<a class="nav-link focus-ring rounded" href="#finance">Financing</a>
					<a class="nav-link focus-ring rounded" href="#evidence">Evidence</a>
					<a class="nav-link focus-ring rounded" href="#claims">Claims</a>
					<a class="nav-link focus-ring rounded font-bold text-[#1d4c3b]" href="/pricing">Pricing</a>
				</div>
				<div class="hidden items-center gap-2 md:flex">
					<a href="#demo-preview" id="header-preview-link" class="nav-link focus-ring rounded px-3 py-2.5 text-sm font-bold">See a demo</a>
					<a href="/login" class="focus-ring rounded-xl bg-[#1d4c3b] px-4 py-2.5 text-sm font-bold text-[#f5f4ed] transition hover:-translate-y-0.5">Sign in</a>
				</div>
				<button id="mobile-menu-button" type="button" class="focus-ring flex h-10 w-10 items-center justify-center rounded-xl border border-[#10231e]/15 text-[#10231e] md:hidden" aria-controls="mobile-menu" aria-expanded="false" aria-label="Open navigation menu"> <i data-lucide="menu" aria-hidden="true"></i> </button>
			</nav>
			<div id="mobile-menu" class="hidden border-t border-[#10231e]/10 bg-[#f5f4ed] px-5 py-5 md:hidden">
				<div class="flex flex-col gap-4 text-sm font-semibold">
					<a class="nav-link focus-ring rounded" href="#workflow">Workflow</a>
					<a class="nav-link focus-ring rounded" href="#finance">Financing</a>
					<a class="nav-link focus-ring rounded" href="#evidence">Evidence</a>
					<a class="nav-link focus-ring rounded" href="#claims">Claims</a>
					<a class="nav-link focus-ring rounded font-bold text-[#1d4c3b]" href="/pricing">Pricing</a>
					<button id="mobile-demo-button" type="button" class="focus-ring mt-2 rounded-xl bg-[#1d4c3b] px-4 py-3 text-left font-bold text-[#f5f4ed]">See a live demo</button>
					<a href="/login" class="mt-2 rounded-xl border border-[#10231e]/15 px-4 py-3 text-left font-bold">Sign in</a>
				</div>
			</div>
		</header>
		<main id="top">
			<section class="hero-grid relative w-full border-b border-[#10231e]/10">
				<div class="mx-auto grid max-w-7xl gap-12 px-5 py-16 lg:grid-cols-[1.02fr_.98fr] lg:px-8 lg:py-24">
					<div class="relative z-10 self-center">
						<div class="entry inline-flex items-center gap-2 rounded-full bg-[#1d4c3b] px-3 py-1.5 text-xs font-bold uppercase tracking-[0.14em] text-[#d6f45a]"><span class="h-2 w-2 rounded-full bg-current"></span>MSME receivables, simplified</div>
						<h1 class="display-face entry entry-delay-1 mt-6 max-w-3xl text-[clamp(3rem,6vw,5.75rem)] font-semibold leading-[0.98] tracking-[-0.055em]">Make every<br>invoice count.</h1>
						<p class="entry entry-delay-2 mt-6 max-w-xl text-lg leading-8 text-[#426057]">PayKaro turns an invoice into a finance-ready asset — one pipeline for what's owed, what's overdue, and what you could finance today, with the evidence and interest numbers that make a claim actually stand.</p>
						<div class="entry entry-delay-3 mt-8 flex flex-col gap-3 sm:flex-row">
							<button id="hero-demo-button" type="button" class="focus-ring rounded-xl bg-[#d6f45a] px-6 py-3.5 font-bold text-[#10231e] transition hover:-translate-y-0.5">See it in action</button>
							<a href="#workflow" class="focus-ring inline-flex items-center justify-center gap-2 rounded-xl border border-[#10231e]/20 bg-[#f5f4ed]/50 px-6 py-3.5 font-bold transition hover:bg-white"><span>How it works</span><i data-lucide="arrow-down-right" class="h-4 w-4" aria-hidden="true"></i></a>
						</div>
						<div class="mt-11 flex flex-wrap gap-x-7 gap-y-4 border-t border-[#10231e]/15 pt-6">
							<div class="flex items-center gap-2 text-sm font-semibold text-[#31564b]"><i data-lucide="shield-check" class="h-4 w-4 text-[#1d4c3b]" aria-hidden="true"></i> Statutory interest computed</div>
							<div class="flex items-center gap-2 text-sm font-semibold text-[#31564b]"><i data-lucide="file-check-2" class="h-4 w-4 text-[#1d4c3b]" aria-hidden="true"></i> TReDS-ready finance queue</div>
						</div>
					</div>
					<div class="entry entry-delay-2 relative mx-auto w-full max-w-2xl lg:pt-4">
						<div class="absolute -right-7 top-4 h-32 w-32 rounded-full bg-[#d6f45a] blur-2xl opacity-70" aria-hidden="true"></div>
						<div class="dashboard-shadow relative overflow-hidden rounded-[1.8rem] bg-[#10231e] p-4 sm:p-6">
							<div class="mb-6 flex items-center justify-between border-b border-white/10 pb-5">
								<div><p class="text-xs font-bold uppercase tracking-[0.16em] text-white/50">Live queue</p><p class="mt-1 text-lg font-semibold text-white">Shree Precision Components</p></div>
								<span class="rounded-full bg-[#d6f45a] px-3 py-1 text-xs font-bold text-[#10231e]">● Live</span>
							</div>
							<div class="grid grid-cols-2 gap-3">
								<article class="rounded-2xl bg-white/[0.08] p-4">
									<p class="text-xs font-medium text-white/60">Outstanding</p>
									<p class="mt-2 text-2xl font-bold tracking-tight text-white">₹42.8L</p>
									<p class="mt-1 text-xs text-white/50">across 15 invoices</p>
								</article>
								<article class="rounded-2xl bg-[#d6f45a] p-4">
									<p class="text-xs font-bold text-[#10231e]/70">Ready to finance</p>
									<p class="mt-2 text-2xl font-bold tracking-tight text-[#10231e]">₹18.2L</p>
									<p class="mt-1 text-xs text-[#10231e]/60">buyers on TReDS</p>
								</article>
							</div>
							<div class="mt-4 rounded-2xl bg-white p-4 sm:p-5">
								<div class="flex items-center justify-between">
									<div><p class="text-xs font-bold uppercase tracking-[0.14em] text-[#55726a]">Invoice 21-0112</p><p class="mt-1 text-sm font-bold text-[#10231e]">Tradeflow Solutions Pvt Ltd</p></div>
									<span class="rounded-full bg-[#d6f45a] px-2.5 py-1 text-xs font-bold text-[#10231e]">Accepted</span>
								</div>
								<div class="mt-5">
									<div class="pipeline-line"></div>
									<div class="mt-2 grid grid-cols-4 text-[10px] font-bold uppercase tracking-wide text-[#55726a]"><span>Raised</span><span class="text-center text-[#1d4c3b]">Accepted</span><span class="text-center">Financed</span><span class="text-right">Settled</span></div>
								</div>
								<div class="mt-5 flex items-end justify-between border-t border-[#10231e]/10 pt-4">
									<div><p class="text-xs font-medium text-[#55726a]">Balance</p><p class="mt-1 text-xl font-bold text-[#10231e]">₹6.40L</p></div>
									<div class="text-right"><p class="text-xs font-medium text-[#55726a]">Interest accruing</p><p class="mt-1 text-sm font-bold text-[#e7684f]">+ ₹18,240</p></div>
								</div>
							</div>
						</div>
						<div class="receipt-paper absolute -bottom-8 -left-4 hidden w-44 rounded-xl border border-[#10231e]/10 p-4 md:block">
							<div class="flex items-center justify-between"><i data-lucide="file-check-2" class="h-5 w-5 text-[#1d4c3b]" aria-hidden="true"></i> <span class="h-2 w-2 rounded-full bg-[#1d4c3b]"></span></div>
							<p class="mt-4 text-xs font-bold text-[#10231e]">Delivered</p>
							<p class="mt-1 text-[11px] leading-4 text-[#587169]">128 × M8 bolts<br>02 Sep 2026</p>
						</div>
					</div>
				</div>
			</section>
			<section class="w-full bg-[#1d4c3b] py-5">
				<div class="mx-auto grid max-w-7xl grid-cols-2 gap-y-5 px-5 sm:grid-cols-4 lg:px-8">
					<div class="border-r border-white/15 pr-4"><p class="text-2xl font-bold text-[#d6f45a]">₹4.2Cr+</p><p class="mt-1 text-xs font-medium text-white/60">Receivables tracked</p></div>
					<div class="border-r border-white/15 px-4"><p class="text-2xl font-bold text-[#d6f45a]">45 days</p><p class="mt-1 text-xs font-medium text-white/60">MSME due window</p></div>
					<div class="border-r border-white/15 pr-4 sm:px-4"><p class="text-2xl font-bold text-[#d6f45a]">3×</p><p class="mt-1 text-xs font-medium text-white/60">Bank-rate interest</p></div>
					<div class="px-4"><p class="text-2xl font-bold text-[#d6f45a]">60s</p><p class="mt-1 text-xs font-medium text-white/60">To onboard</p></div>
				</div>
			</section>
			<section id="workflow" class="w-full px-5 py-20 lg:px-8 lg:py-28">
				<div class="mx-auto max-w-7xl">
					<div class="grid gap-8 lg:grid-cols-[.7fr_1.3fr] lg:items-end">
						<div><p class="text-xs font-bold uppercase tracking-[0.16em] text-[#1d4c3b]">One pipeline</p><h2 class="display-face mt-4 text-4xl font-semibold leading-tight tracking-[-0.04em] sm:text-5xl">Raised → accepted → financed → settled</h2></div>
						<p class="max-w-2xl text-lg leading-8 text-[#587169]">No more scattered spreadsheets. Every invoice moves through the same pipeline with a live balance and accruing statutory interest — so you always know where the money is.</p>
					</div>
					<div class="mt-14 grid gap-4 md:grid-cols-4">
						<article class="rounded-2xl border border-[#10231e]/15 bg-[#fffdf6] p-5"><span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-[#10231e] text-sm font-bold text-white">01</span><h3 class="mt-7 text-xl font-bold">Raised</h3><p class="mt-3 text-sm leading-6 text-[#587169]">Log the invoice from day one — GST-valid copy, dates and terms all in one place.</p></article>
						<article class="rounded-2xl border border-[#10231e]/15 bg-[#fffdf6] p-5"><span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-[#d6f45a] text-sm font-bold text-[#10231e]">02</span><h3 class="mt-7 text-xl font-bold">Accepted</h3><p class="mt-3 text-sm leading-6 text-[#587169]">Capture the buyer's acceptance and the delivery acknowledgement that proves it.</p></article>
						<article class="rounded-2xl border border-[#10231e]/15 bg-[#fffdf6] p-5"><span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-[#e9ddd0] text-sm font-bold text-[#10231e]">03</span><h3 class="mt-7 text-xl font-bold">Financed</h3><p class="mt-3 text-sm leading-6 text-[#587169]">Discounted on TReDS the moment the evidence is complete and the buyer is onboard.</p></article>
						<article class="rounded-2xl border border-[#10231e]/15 bg-[#fffdf6] p-5"><span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-[#e7684f] text-sm font-bold text-white">04</span><h3 class="mt-7 text-xl font-bold">Settled</h3><p class="mt-3 text-sm leading-6 text-[#587169]">Reconciled, closed and out of your queue — with a clean paper trail behind it.</p></article>
					</div>
				</div>
			</section>
			<section id="finance" class="w-full bg-[#e8ede3] px-5 py-20 lg:px-8 lg:py-28">
				<div class="mx-auto grid max-w-7xl gap-12 lg:grid-cols-[.94fr_1.06fr] lg:items-center">
					<div>
						<p class="text-xs font-bold uppercase tracking-[0.16em] text-[#1d4c3b]">Financing</p>
						<h2 class="display-face mt-4 max-w-xl text-4xl font-semibold leading-tight tracking-[-0.04em] sm:text-5xl">Unlock cash that's already yours.</h2>
						<p class="mt-6 max-w-xl text-lg leading-8 text-[#587169]">Our finance queue separates the invoices you can finance today from the evidence gaps holding the rest back — buy it ready, not hoping.</p>
						<a href="/treds" class="mt-7 inline-flex items-center gap-2 rounded-lg font-bold text-[#1d4c3b] underline decoration-2 underline-offset-4"><span>Open the finance queue</span><i data-lucide="arrow-right" class="h-4 w-4" aria-hidden="true"></i></a>
					</div>
					<div class="rounded-[1.8rem] bg-[#10231e] p-5 text-white sm:p-7">
						<div class="flex items-start justify-between gap-4">
							<div><p class="text-xs font-bold uppercase tracking-[0.15em] text-white/50">TReDS queue</p><h3 class="mt-2 text-2xl font-bold">Financeable today</h3></div>
							<span class="rounded-full bg-[#d6f45a] px-3 py-1.5 text-sm font-bold text-[#10231e]">4 invoices</span>
						</div>
						<div class="mt-7 space-y-3">
							<div class="flex items-center justify-between rounded-xl bg-white/[0.08] p-4">
								<div class="flex items-center gap-3"><span class="h-2.5 w-2.5 rounded-full bg-[#d6f45a]"></span><div><p class="text-sm font-bold">Ready</p><p class="mt-1 text-xs text-white/60">Evidence complete · buyer on TReDS</p></div></div>
								<p class="text-sm font-bold">₹18.2L</p>
							</div>
							<div class="flex items-center justify-between rounded-xl bg-white/[0.08] p-4">
								<div class="flex items-center gap-3"><span class="h-2.5 w-2.5 rounded-full bg-[#e7684f]"></span><div><p class="text-sm font-bold">Gap — buyer not onboard</p><p class="mt-1 text-xs text-white/60">Confirm TReDS to unlock ₹24.6L</p></div></div>
								<p class="text-sm font-bold">₹24.6L</p>
							</div>
						</div>
					</div>
				</div>
			</section>
			<section id="evidence" class="w-full px-5 py-20 lg:px-8 lg:py-28">
				<div class="mx-auto max-w-7xl">
					<div class="mb-12 max-w-2xl">
						<p class="text-xs font-bold uppercase tracking-[0.16em] text-[#1d4c3b]">Evidence</p>
						<h2 class="display-face mt-4 text-4xl font-semibold leading-tight tracking-[-0.04em] sm:text-5xl">Stand on the right numbers.</h2>
					</div>
					<div class="grid gap-5 md:grid-cols-3">
						<article class="feature-card rounded-2xl border border-[#10231e]/15 bg-[#fffdf6] p-7">
							<div class="flex h-12 w-12 items-center justify-center rounded-xl bg-[#d6f45a] text-[#10231e]"><i data-lucide="clipboard-check" aria-hidden="true"></i></div>
							<h3 class="mt-7 text-2xl font-bold">Evidence that stands up</h3>
							<p class="mt-3 text-sm leading-6 text-[#587169]">PO, delivery ack, GRN and a GST-valid copy — a finance or dispute packet that's never missing a document.</p>
						</article>
						<article class="feature-card rounded-2xl border border-[#10231e]/15 bg-[#fffdf6] p-7">
							<div class="flex h-12 w-12 items-center justify-center rounded-xl bg-[#1d4c3b] text-[#d6f45a]"><i data-lucide="indian-rupee" aria-hidden="true"></i></div>
							<h3 class="mt-7 text-2xl font-bold">Interest that's correct</h3>
							<p class="mt-3 text-sm leading-6 text-[#587169]">Statutory interest computed from the invoice at 3× the bank rate, applied daily — so a claim always uses the right number.</p>
						</article>
						<article class="feature-card rounded-2xl border border-[#10231e]/15 bg-[#fffdf6] p-7">
							<div class="flex h-12 w-12 items-center justify-center rounded-xl bg-[#e9ddd0] text-[#10231e]"><i data-lucide="scale" aria-hidden="true"></i></div>
							<h3 class="mt-7 text-2xl font-bold">Claims that go through</h3>
							<p class="mt-3 text-sm leading-6 text-[#587169]">A guided evidence packet for MSEFC, mediation or arbitration — ready to file the moment a buyer stops paying.</p>
						</article>
					</div>
				</div>
			</section>
			<section id="demo-preview" class="preview-hidden w-full bg-[#10231e] px-5 py-16 lg:px-8 lg:py-20" aria-live="polite">
				<div class="mx-auto max-w-7xl">
					<div class="mb-8 flex flex-wrap items-end justify-between gap-5">
						<div><p class="text-xs font-bold uppercase tracking-[0.16em] text-[#d6f45a]">Live preview</p><h2 class="display-face mt-3 text-4xl font-semibold tracking-[-0.04em] text-white">A real receivables queue</h2></div>
						<div class="flex items-center gap-2"><a href="/login" class="focus-ring rounded-xl bg-[#d6f45a] px-4 py-2.5 text-sm font-bold text-[#10231e] transition hover:-translate-y-0.5">Open the full app →</a><button id="hide-preview-button" type="button" class="focus-ring rounded-xl border border-white/20 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-white/10">Hide</button></div>
					</div>
					<div class="grid gap-5 lg:grid-cols-[1.45fr_.55fr]">
						<div class="rounded-2xl bg-[#fffdf6] p-5 sm:p-7">
							<div class="flex flex-wrap items-center justify-between gap-3">
								<div><p class="text-lg font-bold text-[#10231e]">Outstanding invoices</p><p class="mt-1 text-sm text-[#587169]">live data for Shree Precision Components</p></div>
								<span class="rounded-full bg-[#d6f45a] px-3 py-1 text-xs font-bold text-[#10231e]">15 invoices</span>
							</div>
							<div class="mt-6 overflow-x-auto">
								<table class="w-full min-w-[560px] text-left text-sm">
									<thead><tr class="border-b border-[#10231e]/10"><th class="pb-3 pr-4 text-xs font-bold uppercase tracking-wide text-[#55726a]">Invoice</th><th class="pb-3 pr-4 text-xs font-bold uppercase tracking-wide text-[#55726a]">Buyer</th><th class="pb-3 pr-4 text-xs font-bold uppercase tracking-wide text-[#55726a]">Age</th><th class="pb-3 text-right text-xs font-bold uppercase tracking-wide text-[#55726a]">Amount</th></tr></thead>
									<tbody>
										<tr class="border-b border-[#10231e]/10"><td class="py-4 pr-4 font-bold text-[#10231e]">21-0112</td><td class="py-4 pr-4 text-[#31564b]">Tradeflow Solutions</td><td class="py-4 pr-4 text-[#e7684f]">Overdue (1-30)</td><td class="py-4 text-right font-bold text-[#10231e]">₹6.40L</td></tr>
										<tr><td class="py-4 pr-4 font-bold text-[#10231e]">21-0108</td><td class="py-4 pr-4 text-[#31564b]">Aster Auto Pvt Ltd</td><td class="py-4 pr-4 text-[#587169]">Due soon</td><td class="py-4 text-right font-bold text-[#10231e]">₹9.85L</td></tr>
									</tbody>
								</table>
							</div>
						</div>
						<aside class="rounded-2xl bg-[#1d4c3b] p-6"><i data-lucide="sparkles" class="h-6 w-6 text-[#d6f45a]" aria-hidden="true"></i><p class="mt-8 text-xs font-bold uppercase tracking-[0.15em] text-white/50">Interest to demand</p><p class="mt-2 text-3xl font-bold text-[#d6f45a]">₹2.4L</p><p class="mt-4 text-sm leading-6 text-white/70">Accruing at 3× bank rate on overdue invoices — computed continuously, not in a spreadsheet.</p></aside>
					</div>
				</div>
			</section>
			<section id="claims" class="w-full bg-[#f0d8cb] px-5 py-20 lg:px-8 lg:py-28">
				<div class="mx-auto grid max-w-7xl gap-10 lg:grid-cols-[.8fr_1.2fr] lg:items-center">
					<div><p class="text-xs font-bold uppercase tracking-[0.16em] text-[#684839]">Claims</p><h2 class="display-face mt-4 text-4xl font-semibold leading-tight tracking-[-0.04em] sm:text-5xl">When a buyer doesn't pay, you're already ready.</h2></div>
					<div>
						<p class="text-lg leading-8 text-[#684839]">The 2026 MSME rules tighten TReDS mandates and strengthen the delayed-payment forum. Businesses that keep clean, complete, dated records now have real leverage — statutory interest, a time-bound dispute forum and discounted financing.</p>
						<div class="mt-7 flex items-center gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-full bg-[#10231e] text-[#d6f45a]"><i data-lucide="folder-check" class="h-5 w-5" aria-hidden="true"></i></span><p class="font-bold">Guided evidence packet for MSEFC, mediation &amp; arbitration</p></div>
					</div>
				</div>
			</section>
			<section class="w-full px-5 py-20 lg:px-8">
				<div class="mx-auto max-w-7xl">
					<p class="text-center text-xs font-bold uppercase tracking-[0.16em] text-[#1d4c3b]">Loved by MSMEs</p>
					<div class="mt-10 grid gap-5 md:grid-cols-2">
						<figure class="rounded-2xl border border-[#10231e]/15 bg-[#fffdf6] p-7">
							<blockquote class="display-face text-2xl leading-snug tracking-[-0.025em] text-[#10231e]">"We stopped guessing. PayKaro told us exactly which invoices were financeable and gave us the evidence to back them."</blockquote>
							<figcaption class="mt-7 flex items-center gap-3"><div class="flex h-12 w-12 items-center justify-center rounded-full bg-[#1d4c3b] font-bold text-[#d6f45a]">S</div><div><p class="text-sm font-bold">Sunita Rao</p><p class="mt-1 text-xs text-[#587169]">Shree Precision Components</p></div></figcaption>
						</figure>
						<figure class="rounded-2xl border border-[#10231e]/15 bg-[#fffdf6] p-7">
							<blockquote class="display-face text-2xl leading-snug tracking-[-0.025em] text-[#10231e]">"The finance queue paid for itself in a month. It separates what's ready from what isn't, which is the whole game."</blockquote>
							<figcaption class="mt-7 flex items-center gap-3"><div class="flex h-12 w-12 items-center justify-center rounded-full bg-[#e7684f] font-bold text-white">F</div><div><p class="text-sm font-bold">Farhan Ali</p><p class="mt-1 text-xs text-[#587169]">MetRow Ceramics</p></div></figcaption>
						</figure>
					</div>
				</div>
			</section>
			<section class="w-full px-5 pb-20 lg:px-8">
				<div class="mx-auto max-w-7xl overflow-hidden rounded-[2rem] bg-[#1d4c3b] p-8 sm:p-12 lg:flex lg:items-end lg:justify-between">
					<div class="max-w-2xl">
						<p class="text-xs font-bold uppercase tracking-[0.16em] text-[#d6f45a]">Ready</p>
						<h2 class="display-face mt-4 text-4xl font-semibold leading-tight tracking-[-0.04em] text-white sm:text-5xl">Make every invoice count.</h2>
					</div>
					<a href="/login" class="focus-ring mt-7 inline-flex rounded-xl bg-[#d6f45a] px-6 py-3.5 font-bold text-[#10231e] transition hover:-translate-y-0.5 lg:mt-0">Try PayKaro free</a>
				</div>
			</section>
		</main>
		<footer class="w-full border-t border-[#10231e]/10 px-5 py-8 lg:px-8">
			<div class="mx-auto flex max-w-7xl flex-col justify-between gap-3 text-sm sm:flex-row">
				<p class="text-[#587169]">© <?php echo e( date('Y') ); ?> PayKaro · Turn invoice mess into finance-ready receivables.</p>
				<p class="font-medium text-[#31564b]">Made for India's MSMEs · <a class="nav-link" href="/login">Sign in</a></p>
			</div>
		</footer>
	</div>
	<script>
		document.addEventListener("DOMContentLoaded", function () {
			lucide.createIcons();
			var mobileMenuButton = document.getElementById("mobile-menu-button");
			var mobileMenu = document.getElementById("mobile-menu");
			var preview = document.getElementById("demo-preview");
			var hidePreviewButton = document.getElementById("hide-preview-button");
			mobileMenuButton.addEventListener("click", function () {
				var expanded = mobileMenuButton.getAttribute("aria-expanded") === "true";
				mobileMenuButton.setAttribute("aria-expanded", String(!expanded));
				mobileMenu.classList.toggle("hidden", expanded);
				mobileMenu.classList.toggle("mobile-nav-open", !expanded);
			});
			document.querySelectorAll("#mobile-menu a").forEach(function (link) {
				link.addEventListener("click", function () {
					mobileMenu.classList.add("hidden");
					mobileMenu.classList.remove("mobile-nav-open");
					mobileMenuButton.setAttribute("aria-expanded", "false");
				});
			});
			function showPreview() {
				preview.classList.remove("preview-hidden");
				preview.scrollIntoView({ behavior: "smooth", block: "start" });
			}
			["mobile-demo-button", "hero-demo-button", "header-preview-link"].forEach(function (id) {
				var el = document.getElementById(id);
				if (el) el.addEventListener("click", function (ev) { ev.preventDefault(); showPreview(); });
			});
			if (hidePreviewButton) hidePreviewButton.addEventListener("click", function () { preview.classList.add("preview-hidden"); });
		});
	</script>
	</body>
	</html>
	<?php
	return ob_get_clean();
}

/* Public pricing page — mirrors the design.html look (paper / moss / lime). */
function pricingPage( array $config ): string {
	$plans = array(
		array(
			'name' => 'Starter',
			'price' => 'Free',
			'per' => 'forever',
			'blurb' => 'For a single MSME getting its receivables in order.',
			'cta' => '/login',
			'cta_label' => 'Start free',
			'highlight' => false,
			'features' => array( 'Up to 25 invoices', '1 user (owner)', 'Invoice pipeline + interest', 'Evidence checklist', 'Demo data included' ),
		),
		array(
			'name' => 'Pro',
			'price' => '₹1,499',
			'per' => '/month · per business',
			'blurb' => 'For growing suppliers who finance and claim often.',
			'cta' => '/login',
			'cta_label' => 'Try Pro free',
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
			'highlight' => false,
			'features' => array( 'Everything in Pro', 'Multi-entity / portfolio view', 'API & data exports', 'Onboarding & training', 'Dedicated success manager' ),
		),
	);
	$cards = '';
	foreach ( $plans as $p ) {
		$hot  = (bool) $p['highlight'];
		$ink  = $hot ? '#eef2e6' : '#10231e';
		$soft = $hot ? 'rgba(238,242,230,.62)' : '#587169';
		$line = $hot ? 'rgba(238,242,230,.16)' : 'rgba(16,35,30,.12)';
		$feats = '';
		foreach ( $p['features'] as $f ) {
			$feats .= '<li style="display:flex;align-items:center;gap:.6rem;padding:.35rem 0;font-size:.9rem;color:' . $soft . ';"><i data-lucide="check" class="h-4 w-4" style="color:' . ( $hot ? '#d6f45a' : '#1d4c3b' ) . ';flex-shrink:0;" aria-hidden="true"></i>' . e( $f ) . '</li>';
		}
		$cards .= '<div style="background:' . ( $hot ? '#10231e' : '#fffdf6' ) . ';color:' . $ink . ';border:1px solid ' . ( $hot ? '#10231e' : 'rgba(16,35,30,.15)' ) . ';border-radius:1.5rem;padding:2rem 1.8rem;display:flex;flex-direction:column;box-shadow:' . ( $hot ? '0 30px 60px rgba(16,35,30,.25)' : '0 1px 2px rgba(16,35,30,.05)' ) . ';">'
			. '<div style="display:flex;align-items:center;justify-content:space-between;">'
			. '<span style="text-transform:uppercase;letter-spacing:.12em;font-weight:800;font-size:.72rem;color:' . $soft . ';">' . e( $p['name'] ) . '</span>'
			. ( $hot ? '<span style="background:#d6f45a;color:#10231e;border-radius:999px;padding:.25rem .7rem;font-size:.68rem;font-weight:800;white-space:nowrap;">Most popular</span>' : '' )
			. '</div>'
			. '<div style="margin-top:1.1rem;font-family:var(--n-display);font-size:2.4rem;font-weight:600;line-height:1;color:' . $ink . ';">' . e( $p['price'] ) . '</div>'
			. '<div style="font-size:.78rem;color:' . $soft . ';margin-top:.3rem;">' . e( $p['per'] ) . '</div>'
			. '<p style="font-size:.9rem;line-height:1.55;color:' . $soft . ';margin:.9rem 0 0;">' . e( $p['blurb'] ) . '</p>'
			. '<ul style="list-style:none;margin:1.2rem 0 1.6rem;padding:1rem 0 0;border-top:1px solid ' . $line . ';">' . $feats . '</ul>'
			. '<a href="' . e( $p['cta'] ) . '" style="display:inline-flex;justify-content:center;align-items:center;margin-top:auto;padding:.75rem 1rem;border-radius:12px;font-weight:800;font-size:.9rem;text-decoration:none;background:' . ( $hot ? '#d6f45a' : '#1d4c3b' ) . ';color:' . ( $hot ? '#10231e' : '#f5f4ed' ) . ';">' . e( $p['cta_label'] ) . '</a>'
			. '</div>';
	}
	ob_start();
	?>
	<!doctype html>
	<html lang="en">
	<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Pricing — <?php echo e( $config['name'] ); ?></title>
	<script src="https://cdn.tailwindcss.com/3.4.17"></script>
	<script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap" rel="stylesheet">
	<style>
		:root{--ink:#10231e;--paper:#f5f4ed;--moss:#1d4c3b;--lime:#d6f45a;--muted:#587169;--line:rgba(16,35,30,.16);--coral:#e7684f;}
		*{box-sizing:border-box;}html{scroll-behavior:smooth;}body{margin:0;width:100%;font-family:"DM Sans",sans-serif;color:var(--ink);background:var(--paper);}
		.page-shell{width:100%;overflow:hidden;background:radial-gradient(circle at 91% 7%,rgba(214,244,90,.28),transparent 24rem),linear-gradient(180deg,#f5f4ed 0%,#eef1e9 55%,#f5f4ed 100%);}
		.display-face{font-family:"Fraunces",serif;}
		.nav-link{color:#426057;text-decoration:none;transition:color .18s ease;}
		.nav-link:hover{color:var(--ink);}
		.focus-ring:focus-visible{outline:3px solid #d6f45a;outline-offset:3px;}
		.entry{animation:rise .7s both cubic-bezier(.2,.8,.2,1);}
		@keyframes rise{from{opacity:0;transform:translateY(18px);}to{opacity:1;transform:translateY(0);}}
	</style>
	</head>
	<body>
	<div class="page-shell">
		<header class="w-full sticky top-0 z-20 border-b border-[#10231e]/10 bg-[#f5f4ed]/90 backdrop-blur-md">
			<nav class="mx-auto flex max-w-7xl items-center justify-between px-5 py-4 lg:px-8" aria-label="Primary navigation">
				<a href="/" class="focus-ring flex items-center gap-2 rounded-md" aria-label="PayKaro home">
					<span class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#1d4c3b] text-lg font-bold text-[#d6f45a]">₹</span>
					<span class="text-xl font-bold tracking-tight" style="font-family:Fraunces,serif;"><span class="text-[#10231e]">Pay</span><span class="text-[#1d4c3b]">Karo</span></span>
				</a>
				<div class="hidden items-center gap-7 text-sm font-medium md:flex">
					<a class="nav-link focus-ring rounded" href="/#workflow">Workflow</a>
					<a class="nav-link focus-ring rounded" href="/#finance">Financing</a>
					<a class="nav-link focus-ring rounded" href="/#evidence">Evidence</a>
					<a class="nav-link focus-ring rounded" href="/#claims">Claims</a>
					<a class="nav-link focus-ring rounded font-bold text-[#1d4c3b]" href="/pricing">Pricing</a>
				</div>
				<div class="hidden md:block"><a href="/login" class="focus-ring rounded-xl bg-[#1d4c3b] px-4 py-2.5 text-sm font-bold text-[#f5f4ed] transition hover:-translate-y-0.5">Sign in</a></div>
			</nav>
		</header>
		<main>
			<section class="w-full px-5 py-16 lg:px-8 lg:py-24">
				<div class="mx-auto max-w-7xl">
					<div class="max-w-2xl">
						<p class="text-xs font-bold uppercase tracking-[0.16em] text-[#1d4c3b]">Pricing</p>
						<h1 class="display-face mt-4 text-4xl font-semibold leading-tight tracking-[-0.04em] sm:text-6xl">Simple plans that pay for themselves.</h1>
						<p class="mt-6 text-lg leading-8 text-[#587169]">Every plan tracks receivables, computes interest and builds an evidence-ready claim. Upgrade when you want to finance and export more.</p>
					</div>
					<div class="mt-14 grid gap-6 md:grid-cols-3">
						<?php echo $cards; ?>
					</div>
					<div class="mt-14 flex flex-col items-center gap-3 border-t border-[#10231e]/10 pt-10 text-center">
						<p class="display-face text-2xl font-semibold tracking-[-0.03em]">Not sure which plan?</p>
						<p class="max-w-xl text-[#587169]">Every plan starts on the free Starter tier — <a class="focus-ring rounded font-bold text-[#1d4c3b] underline decoration-2 underline-offset-4" href="/login">sign in</a> and track your first invoices today.</p>
					</div>
				</div>
			</section>
		</main>
		<footer class="w-full border-t border-[#10231e]/10 px-5 py-8 lg:px-8">
			<div class="mx-auto flex max-w-7xl flex-col justify-between gap-3 text-sm sm:flex-row">
				<p class="text-[#587169]">© <?php echo e( date('Y') ); ?> PayKaro · <a class="nav-link" href="/login">Sign in</a></p>
				<p class="font-medium text-[#31564b]">Made for India's MSMEs</p>
			</div>
		</footer>
	</div>
	<script>document.addEventListener("DOMContentLoaded",function(){if(window.lucide)lucide.createIcons();});</script>
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

	$ob = '<div class="pkg-pagehead">'
		. '<div><h1 class="pkg-h1">Overview</h1><p class="pkg-sub">Here’s what’s happening with your receivables.</p></div>'
		. '<div class="pkg-pagehead-actions">'
		. '<span class="pkg-filter">📅 ' . e( $range ) . '</span>'
		. '<span class="pkg-filter">⚙ Filters</span>'
		. '</div></div>';

	// KPI cards.
	$monthLabel = date( 'd M' ) . ' – ' . date( 'd M Y' );
	$ob .= '<div class="pkg-grid pkg-grid--4">';
	$ob .= kpiCard( 'Outstanding', money( $d['total'] ), '<span class="up">↗</span> 8.6% vs 01 Apr – 30 Apr 2025', '₹', 'brand' );
	$ob .= kpiCard( 'Overdue', money( $d['overdue'] ), '<span class="down">↘</span> 12.3% vs 01 Apr – 30 Apr 2025', '⏳', 'red' );
	$ob .= kpiCard( 'Interest', money( $d['interest'] ), '<span class="up">↗</span> 6.2% vs 01 Apr – 30 Apr 2025', '%', 'amber' );
	$ob .= kpiCard( 'Receivable in 30d', money( $d['in30d'] ), '<span class="up">↗</span> 10.7% vs 01 Apr – 30 Apr 2025', '↗', 'blue' );
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
		$ob .= '<div class="pkg-card"><div class="pkg-cardhead"><h2 class="pkg-h2">Needs attention</h2>'
			. '<form method="post" action="/alerts/read"><button class="pkg-btn pkg-btn--sm pkg-btn--ghost" type="submit">Dismiss all</button></form></div>'
			. '<ul class="pkg-alerts" style="margin:0;">' . $items . '</ul></div>';
	}

	$ob .= '<div class="pkg-grid pkg-grid--2">';

	// Ageing summary (bar chart) with y-axis gridlines, matching the reference.
	$buckets = $d['buckets'];
	$maxB = max( 0.001, max( array_map( 'floatval', array_values( $buckets ) ) ) );
	$barColor = array( 'Current' => 'brand', '1–30d' => 'blue', '31–60d' => 'amber', '61–90d' => 'orange', '90+' => 'red' );
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
	$pipe = array( 'Current' => 'brand', '1–30d' => 'blue', '31–60d' => 'amber', '61–90d' => 'orange', '90+' => 'red' );
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
		. '<span class="pkg-search">🔍 <input placeholder="Search invoices…" aria-label="Search"></span>'
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
		$ob .= '<div class="pkg-bucket"><div class="pkg-bucket-row"><span class="pkg-bucket-name">' . e( $name ) . '</span><span class="pkg-bucket-value num">' . e( money( $val ) ) . '</span></div><div class="pkg-bucket-bar"><span class="pkg-bucket-fill pkg-bucket-fill--brand" style="width:' . $pct . '%"></span></div></div>';
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
$public = array( '/login', '/logout', '/assets' );

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

// ---- Public pages: '/' landing, '/login', '/pricing' are public ----
if ( ! $user ) {
	$response['status'] = 200;
	if ( '/' === $path ) { $response['body'] = landingPage( $config ); }
	elseif ( '/pricing' === $path ) { $response['body'] = pricingPage( $config ); }
	else { $response['body'] = loginPage( $config ); }
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
			else { $id = $app->createInvoice( $p ); $loc = '/invoice?id=' . $id; }
			redirect( $loc, $response );
		echo json_encode( $response );
		exit;
	}
	if ( '/buyers' === $path ) {
		$app->createBuyer( $p );
		redirect( '/buyers', $response );
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
				$content = viewInvoiceForm( $app, $config, $editId );
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
		case '/pricing': $title = 'Pricing'; $active = 'pricing'; $content = pricingPage( $config ); break;
		default: $response['status'] = 404; $content = '<div class="pkg-card pkg-empty"><h2 class="pkg-h2">Not found</h2><a class="pkg-btn" href="/">Home</a></div>';
	}

$config['business'] = $app->business()['name'] ?? $config['name'];
$response['body'] = layout( $config, $title, $content, $active, $app->dashboard(), $user );
echo json_encode( $response );

function redirect( string $loc, array &$response ): void {
	$response['status']   = 302;
	$response['location'] = $loc;
}
