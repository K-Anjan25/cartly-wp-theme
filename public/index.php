<?php
/**
 * PayKaro — web entry / router (auth + multi-tenant).
 *
 * Reads $o (method), $g (GET), $p (POST) + the request path, all injected by
 * the bridge. Builds an HTTP response array:
 *   ['status'=>int, 'type'=>mime, 'location'=>?string, 'cookies'=>[[name,value,ttl]], 'body'=>string]
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '0' );

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

// One KPI card, Northstar style.
function kpiCard( string $label, string $value, string $trend = '', string $icon = '₹', string $tone = 'brand' ): string {
	$trendHtml = $trend ? '<div class="pkg-kpi-trend">' . $trend . '</div>' : '<div class="pkg-kpi-trend" style="visibility:hidden">&nbsp;</div>';
	return '<div class="pkg-card pkg-kpi"><div class="pkg-kpi-ico pkg-kpi-ico--' . $tone . '">' . $icon . '</div>'
		. '<div class="pkg-kpi-label">' . e( $label ) . '</div>'
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
	<link rel="stylesheet" href="/assets/app.css">
	</head>
	<body class="pkg">
	<div class="pkg-shell">

		<aside class="pkg-side">
			<a class="pkg-side-brand" href="/">
				<span class="pkg-side-logo">₹</span>
				<span>
					<div class="pkg-side-brand-name"><?php echo e( $config['name'] ); ?></div>
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
				<button class="pkg-upgrade-btn" type="button">Upgrade Now</button>
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
	<title>Sign in — <?php echo e( $config['name'] ); ?></title><link rel="stylesheet" href="/assets/app.css"></head>
	<body class="pkg" style="margin:0;display:flex;min-height:100vh;background:var(--n-canvas);">
		<!-- Brand panel -->
		<div style="flex:0 0 44%;max-width:44%;background:linear-gradient(160deg,var(--n-side) 0%,var(--n-side-2) 60%,#0a2b21 100%);color:#fff;display:flex;flex-direction:column;justify-content:space-between;padding:2.6rem 2.8rem;">
			<div style="display:flex;align-items:center;gap:.75rem;">
				<span class="pkg-side-logo">₹</span>
				<div>
					<div style="font-weight:800;font-size:1.25rem;letter-spacing:-.02em;"><?php echo e( $config['name'] ); ?></div>
					<div style="font-size:.78rem;color:var(--n-side-mute);"><?php echo e( $config['tagline'] ); ?></div>
				</div>
			</div>
			<div>
				<div style="font-size:2rem;font-weight:800;letter-spacing:-.03em;line-height:1.2;margin-bottom:1rem;">Turn invoice mess into<br>finance-ready receivables.</div>
				<p style="color:var(--n-side-ink);font-size:.92rem;line-height:1.65;max-width:26rem;margin:0;">One pipeline for every invoice — evidence complete, interest computed, and ready to finance or claim the moment it's overdue.</p>
				<div style="display:flex;gap:.6rem;margin-top:1.6rem;flex-wrap:wrap;">
					<span style="background:rgba(16,185,129,.14);color:#34d399;border-radius:999px;padding:.35rem .7rem;font-size:.74rem;font-weight:600;">🔒 Multi-tenant secured</span>
					<span style="background:rgba(148,163,184,.12);color:#cbd5e1;border-radius:999px;padding:.35rem .7rem;font-size:.74rem;font-weight:600;">⚙ TReDS-ready</span>
				</div>
			</div>
			<div style="font-size:.74rem;color:var(--n-side-mute);">&copy; <?php echo e( $config['name'] ); ?> · demo</div>
		</div>

		<!-- Form panel -->
		<div style="flex:1;display:flex;align-items:center;justify-content:center;padding:2rem;">
			<div class="pkg-card" style="width:100%;max-width:24rem;padding:2.2rem;box-shadow:0 20px 48px rgba(16,24,40,.08);">
				<div class="pkg-h1" style="font-size:1.5rem;">Welcome back</div>
				<p class="pkg-sub" style="margin:.4rem 0 1.4rem;">Sign in to your workspace. Each user sees only their own business's receivables.</p>

				<?php if ( ! empty( $_GET['err'] ) ) : ?><div class="pkg-callout pkg-callout--coral" style="margin-bottom:1rem;">Invalid email or password.</div><?php endif; ?>

				<form method="post" action="/login">
					<div class="pkg-field"><label class="pkg-label">Email</label><input class="pkg-input" type="email" name="email" required placeholder="you@company.in" autofocus></div>
					<div class="pkg-field"><label class="pkg-label">Password</label><input class="pkg-input" type="password" name="password" required placeholder="••••••••"></div>
					<div class="pkg-form-actions"><button class="pkg-btn pkg-btn--primary pkg-btn--block" type="submit">Sign in</button></div>
				</form>

				<div style="margin-top:1.5rem;border-top:1px dashed var(--n-line);padding-top:1.1rem;">
					<div class="pkg-muted" style="margin-bottom:.6rem;">Demo logins (one click):</div>
					<div style="display:flex;flex-direction:column;gap:.5rem;">
						<a class="pkg-btn" href="/login?demo=1"><span style="flex:1;text-align:left;">Sunita · Shree Precision</span>→</a>
						<a class="pkg-btn" href="/login?demo=2"><span style="flex:1;text-align:left;">Farhan · MetRow Ceramics</span>→</a>
					</div>
					<p class="pkg-muted" style="margin-top:.7rem;font-size:.74rem;">Passwords: <code style="background:var(--n-paper-2);padding:.1rem .35rem;border-radius:6px;">demo1234</code></p>
				</div>
			</div>
		</div>
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
	$ob .= '<div class="pkg-grid pkg-grid--4">';
	$ob .= kpiCard( 'Outstanding', money( $d['total'] ), '<span class="up">↗</span> ' . $d['count'] . ' active invoices', '₹', 'brand' );
	$ob .= kpiCard( 'Overdue', money( $d['overdue'] ), '<span class="down">↘</span> ' . $d['overdueCount'] . ' invoices past due', '⏳', 'red' );
	$ob .= kpiCard( 'Interest', money( $d['interest'] ), '<span class="up">↗</span> at ' . ( $config['bank_rate'] * $config['interest_multiplier'] ) . '% p.a.', '%', 'amber' );
	$ob .= kpiCard( 'Receivable in 30d', money( $d['in30d'] ), '<span class="up">↗</span> next 30 days', '↗', 'blue' );
	$ob .= '</div>';

	$ob .= '<div class="pkg-grid pkg-grid--2">';

	// Ageing summary (bar chart).
	$buckets = $d['buckets'];
	$maxB = max( 0.001, max( array_map( 'floatval', array_values( $buckets ) ) ) );
	$barColor = array( 'Current' => 'brand', '1–30d' => 'blue', '31–60d' => 'amber', '61–90d' => 'orange', '90+' => 'red' );
	$ob .= '<div class="pkg-card"><div class="pkg-cardhead"><h2 class="pkg-h2">Ageing Summary</h2><span class="pkg-filter" style="padding:.35rem .6rem;">As on ' . e( date( 'd M Y' ) ) . '</span></div>';
	$ob .= '<div class="pkg-ageing">';
	foreach ( $buckets as $name => $val ) {
		$h = max( 4, round( $val / $maxB * 100 ) );
		$color = $barColor[ $name ] ?? 'brand';
		$ob .= '<div class="col"><div class="barwrap"><div class="bar bar--' . $color . '" style="height:' . $h . '%"><span class="val num">' . e( money( $val ) ) . '</span></div></div><div class="lab">' . e( $name ) . '<br>' . ( 'Current' === $name ? 'Days' : 'Days' ) . '</div></div>';
	}
	$ob .= '</div></div>';

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
	$ob = pageHeader( 'Invoice ' . e( $inv['number'] ), e( $inv['buyer_name'] ) . ' · ' . e( $inv['invoice_date'] ), '<a class="pkg-btn pkg-btn--ghost" href="/invoices">← All invoices</a>' );
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

// ---- One-click demo login ----
if ( '/login' === $path && isset( $g['demo'] ) ) {
	$email = '1' === $g['demo'] ? 'sunita@shreeprecision.in' : 'farhan@metrowceramics.in';
	$u = $app->authenticate( $email, 'demo1234' );
	if ( $u ) {
		$token = $app->createSession( (int) $u['id'] );
		$response['status']   = 302;
		$response['location'] = '/';
		$response['cookies'][] = array( COOKIE_NAME, $token, (int) $config['session_ttl'] );
		echo json_encode( $response );
		exit;
	}
}

// ---- Require auth for app routes ----
if ( ! $user && ! in_array( $path, array( '/login' ), true ) && 0 !== strpos( $path, '/assets' ) ) {
	$response['status'] = 200;
	$response['body']   = loginPage( $config );
	echo json_encode( $response );
	exit;
}

if ( ! $user && '/login' === $path ) {
	$response['status'] = 200;
	$response['body']   = loginPage( $config );
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
}

// ---- GET routes ----
$content = ''; $title = $config['name']; $active = 'dashboard';
switch ( $path ) {
	case '/':  $title = 'Dashboard'; $active = 'dashboard'; $content = viewDashboard( $app, $config ); break;
	case '/invoices': $title = 'Invoices'; $active = 'invoices'; $content = viewInvoices( $app ); break;
	case '/invoices/new': $title = 'Raise an invoice'; $active = 'invoices'; $content = viewInvoiceForm( $app, $config, null ); break;
	case '/invoice': $title = 'Invoice'; $active = 'invoices'; $content = ! empty( $g['edit'] ) ? viewInvoiceForm( $app, $config, (int) $g['edit'] ) : viewInvoice( $app, $config, (int) ( $g['id'] ?? 0 ) ); break;
	case '/claim': $title = 'Evidence packet'; $active = 'invoices'; $content = viewClaim( $app, (int) ( $g['id'] ?? 0 ) ); break;
	case '/buyers': $title = 'Buyers'; $active = 'buyers'; $content = viewBuyers( $app ); break;
	case '/buyers/new': $title = 'Add a buyer'; $active = 'buyers'; $content = viewBuyerForm( $app ); break;
	case '/treds': $title = 'Finance queue'; $active = 'treds'; $content = viewTreds( $app ); break;
	case '/reports': $title = 'Reports'; $active = 'reports'; $content = viewReports( $app, $config ); break;
	case '/settings': $title = 'Settings'; $active = 'settings'; $content = viewSettings( $app, $config ); break;
	default: $response['status'] = 404; $content = '<div class="pkg-card pkg-empty"><h2 class="pkg-h2">Not found</h2><a class="pkg-btn" href="/">Home</a></div>';
}

$config['business'] = $app->business()['name'] ?? $config['name'];
$response['body'] = layout( $config, $title, $content, $active, $app->dashboard(), $user );
echo json_encode( $response );

function redirect( string $loc, array &$response ): void {
	$response['status']   = 302;
	$response['location'] = $loc;
}
