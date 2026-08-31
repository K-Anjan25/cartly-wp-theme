<?php
/**
 * Template helpers.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wordmark or custom logo.
 */
function cartly_branding() {
	if ( has_custom_logo() ) {
		the_custom_logo();
		return;
	}
	?>
	<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="flex shrink-0 items-center gap-2 no-underline" rel="home">
		<span class="flex h-8 w-8 items-center justify-center rounded-md bg-contrast text-accent" aria-hidden="true">
			<?php cartly_icon( 'cart', 17 ); ?>
		</span>
		<span class="font-heading text-lg font-extrabold uppercase tracking-[0.18em] text-ink">
			<?php bloginfo( 'name' ); ?>
		</span>
	</a>
	<?php
}

/**
 * Inline SVG icons — no icon-font dependency.
 *
 * @param string $name Icon key.
 * @param int    $size Pixel size.
 */
function cartly_icon( $name, $size = 20 ) {
	$paths = array(
		'cart'    => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
		'search'  => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
		'menu'    => '<path d="M3 12h18M3 6h18M3 18h18"/>',
		'close'   => '<path d="M18 6 6 18M6 6l12 12"/>',
		'user'    => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
		'sun'     => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
		'moon'    => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
		'chevron' => '<path d="m9 18 6-6-6-6"/>',
		'arrow'   => '<path d="M5 12h14M12 5l7 7-7 7"/>',
		'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1V2l-2 1-2-1-2 1-2-1-2 1-2-1Z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
		'grid'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
		'truck'   => '<path d="M10 17h4V5H2v12h3"/><path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8h2"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
		'shield'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
		'refresh' => '<path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/>',
		'bolt'    => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
	);

	if ( ! isset( $paths[ $name ] ) ) {
		return;
	}

	printf(
		'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
		absint( $size ),
		wp_kses(
			$paths[ $name ],
			array(
				'path'   => array( 'd' => true ),
				'circle' => array( 'cx' => true, 'cy' => true, 'r' => true ),
				'rect'   => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true ),
			)
		)
	);
}

/**
 * Page header block (eyebrow / title / subtitle / actions).
 *
 * @param array $args Header args.
 */
function cartly_page_header( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'eyebrow'  => '',
			'title'    => '',
			'subtitle' => '',
			'actions'  => '',
		)
	);
	?>
	<header class="page-header flex flex-wrap items-end justify-between gap-4">
		<div class="min-w-0">
			<?php if ( $args['eyebrow'] ) : ?>
				<p class="eyebrow mb-1"><?php echo esc_html( $args['eyebrow'] ); ?></p>
			<?php endif; ?>
			<h1 class="page-title"><?php echo wp_kses_post( $args['title'] ); ?></h1>
			<?php if ( $args['subtitle'] ) : ?>
				<p class="page-subtitle"><?php echo wp_kses_post( $args['subtitle'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( $args['actions'] ) : ?>
			<div class="flex shrink-0 flex-wrap gap-2"><?php echo wp_kses_post( $args['actions'] ); ?></div>
		<?php endif; ?>
	</header>
	<?php
}

/**
 * Empty state.
 *
 * @param string $title    Heading.
 * @param string $subtitle Copy.
 * @param string $icon     Icon key.
 * @param string $action   Optional action HTML.
 */
function cartly_empty_state( $title, $subtitle = '', $icon = 'grid', $action = '' ) {
	?>
	<div class="panel">
		<div class="flex flex-col items-center justify-center px-6 py-16 text-center">
			<span class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-brand-soft text-brand">
				<?php cartly_icon( $icon, 26 ); ?>
			</span>
			<h2 class="font-heading text-lg font-bold text-ink"><?php echo esc_html( $title ); ?></h2>
			<?php if ( $subtitle ) : ?>
				<p class="mt-1 max-w-md text-sm text-ink-soft"><?php echo esc_html( $subtitle ); ?></p>
			<?php endif; ?>
			<?php if ( $action ) : ?>
				<div class="mt-6"><?php echo wp_kses_post( $action ); ?></div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * Pagination in the theme's chip language.
 */
function cartly_pagination() {
	$links = paginate_links(
		array(
			'type'      => 'array',
			'mid_size'  => 1,
			'prev_text' => __( 'Previous', 'cartly' ),
			'next_text' => __( 'Next', 'cartly' ),
		)
	);

	if ( empty( $links ) ) {
		return;
	}
	?>
	<nav class="cartly-pagination mt-10 flex flex-wrap justify-center gap-2" aria-label="<?php esc_attr_e( 'Posts navigation', 'cartly' ); ?>">
		<?php foreach ( $links as $link ) { echo wp_kses_post( $link ); } ?>
	</nav>
	<?php
}

/**
 * Post meta line.
 */
function cartly_post_meta() {
	?>
	<p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-muted">
		<time datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>">
			<?php echo esc_html( get_the_date() ); ?>
		</time>
		<span aria-hidden="true">·</span>
		<span><?php echo esc_html( get_the_author() ); ?></span>
		<?php
		$cats = get_the_category_list( ', ' );
		if ( $cats ) :
			?>
			<span aria-hidden="true">·</span>
			<span class="[&_a]:text-ink-muted [&_a]:no-underline hover:[&_a]:text-brand"><?php echo wp_kses_post( $cats ); ?></span>
		<?php endif; ?>
	</p>
	<?php
}

/**
 * Dark-mode toggle button.
 *
 * @param bool $with_label Render the labelled (drawer) variant.
 */
function cartly_scheme_toggle( $with_label = false ) {
	if ( $with_label ) {
		?>
		<button type="button"
			class="cartly-scheme-toggle flex w-full items-center justify-between rounded-sm px-3 py-2.5 text-sm font-semibold text-ink-soft transition hover:bg-sunken hover:text-ink"
			aria-pressed="false">
			<span class="flex items-center gap-2">
				<span class="cartly-icon-moon"><?php cartly_icon( 'moon', 18 ); ?></span>
				<span class="cartly-icon-sun hidden"><?php cartly_icon( 'sun', 18 ); ?></span>
				<span class="cartly-scheme-label"><?php esc_html_e( 'Dark mode', 'cartly' ); ?></span>
			</span>
		</button>
		<?php
		return;
	}
	?>
	<button type="button" class="cartly-scheme-toggle icon-button" aria-pressed="false"
		aria-label="<?php esc_attr_e( 'Switch to dark mode', 'cartly' ); ?>">
		<span class="cartly-icon-moon"><?php cartly_icon( 'moon', 20 ); ?></span>
		<span class="cartly-icon-sun hidden"><?php cartly_icon( 'sun', 20 ); ?></span>
	</button>
	<?php
}

/**
 * Blog/archive URL, pointing at the posts page (or home).
 *
 * @return string
 */
function cartly_shop_url() {
	$posts = get_option( 'page_for_posts' );
	if ( $posts ) {
		return get_permalink( $posts );
	}
	return home_url( '/' );
}

/**
 * Latest posts as a card grid, for the front page / archives.
 *
 * @param int $count How many posts to show.
 * @return void
 */
function cartly_recent_posts( int $count = 4 ): void {
	$query = new WP_Query(
		array(
			'posts_per_page'      => $count,
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
		)
	);

	if ( ! $query->have_posts() ) {
		return;
	}
	?>
	<ul class="grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-3 xl:grid-cols-4">
		<?php
		while ( $query->have_posts() ) {
			$query->the_post();
			get_template_part( 'template-parts/content/card' );
		}
		wp_reset_postdata();
		?>
	</ul>
	<?php
}
