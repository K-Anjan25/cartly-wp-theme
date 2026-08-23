<?php
/**
 * Nav menu walkers that emit the theme's pill / chip language.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

/**
 * Header nav: pill links, ink pill when current.
 */
class Cartly_Nav_Walker extends Walker_Nav_Menu {

	/**
	 * Start element.
	 *
	 * @param string   $output Output buffer.
	 * @param WP_Post  $item   Menu item.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Args.
	 * @param int      $id     ID.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		$classes = (array) $item->classes;
		$current = in_array( 'current-menu-item', $classes, true )
			|| in_array( 'current-menu-ancestor', $classes, true )
			|| in_array( 'current_page_item', $classes, true );

		$class = $current
			? 'bg-contrast text-oncontrast'
			: 'text-ink-soft hover:bg-sunken hover:text-ink';

		$output .= sprintf(
			'<li class="list-none"><a href="%1$s" class="rounded-full px-3.5 py-2 text-sm font-semibold no-underline transition %2$s"%3$s>%4$s</a>',
			esc_url( $item->url ),
			esc_attr( $class ),
			$current ? ' aria-current="page"' : '',
			esc_html( $item->title )
		);
	}

	/**
	 * End element.
	 *
	 * @param string  $output Output buffer.
	 * @param WP_Post $item   Menu item.
	 * @param int     $depth  Depth.
	 * @param mixed   $args   Args.
	 */
	public function end_el( &$output, $item, $depth = 0, $args = null ) {
		$output .= '</li>';
	}
}

/**
 * Category rail: horizontally scrolling chips.
 */
class Cartly_Chip_Walker extends Walker_Nav_Menu {

	/**
	 * Start element.
	 *
	 * @param string   $output Output buffer.
	 * @param WP_Post  $item   Menu item.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Args.
	 * @param int      $id     ID.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		$classes = (array) $item->classes;
		$current = in_array( 'current-menu-item', $classes, true )
			|| in_array( 'current-menu-ancestor', $classes, true );

		$output .= sprintf(
			'<li class="list-none"><a href="%1$s" class="chip %2$s"%3$s>%4$s</a>',
			esc_url( $item->url ),
			$current ? 'chip-ink' : '',
			$current ? ' aria-current="page"' : '',
			esc_html( $item->title )
		);
	}

	/**
	 * End element.
	 *
	 * @param string  $output Output buffer.
	 * @param WP_Post $item   Menu item.
	 * @param int     $depth  Depth.
	 * @param mixed   $args   Args.
	 */
	public function end_el( &$output, $item, $depth = 0, $args = null ) {
		$output .= '</li>';
	}
}

/**
 * Mobile drawer: full-width rows with a chevron affordance.
 */
class Cartly_Drawer_Walker extends Walker_Nav_Menu {

	/**
	 * Start element.
	 *
	 * @param string   $output Output buffer.
	 * @param WP_Post  $item   Menu item.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Args.
	 * @param int      $id     ID.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		$classes = (array) $item->classes;
		$current = in_array( 'current-menu-item', $classes, true )
			|| in_array( 'current-menu-ancestor', $classes, true );

		$class = $current
			? 'bg-brand-soft text-brand'
			: 'text-ink-soft hover:bg-sunken hover:text-ink';

		$output .= sprintf(
			'<li class="list-none"><a href="%1$s" class="flex w-full items-center justify-between rounded-sm px-3 py-2.5 text-sm font-semibold no-underline transition %2$s"%3$s>%4$s<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></a>',
			esc_url( $item->url ),
			esc_attr( $class ),
			$current ? ' aria-current="page"' : '',
			esc_html( $item->title )
		);
	}

	/**
	 * End element.
	 *
	 * @param string  $output Output buffer.
	 * @param WP_Post $item   Menu item.
	 * @param int     $depth  Depth.
	 * @param mixed   $args   Args.
	 */
	public function end_el( &$output, $item, $depth = 0, $args = null ) {
		$output .= '</li>';
	}
}

/**
 * Drawer / footer: stacked rows.
 */
class Cartly_Stack_Walker extends Walker_Nav_Menu {

	/**
	 * Start element.
	 *
	 * @param string   $output Output buffer.
	 * @param WP_Post  $item   Menu item.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Args.
	 * @param int      $id     ID.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		$output .= sprintf(
			'<li class="list-none"><a href="%1$s" class="block text-sm text-ink-muted no-underline transition hover:text-accent">%2$s</a>',
			esc_url( $item->url ),
			esc_html( $item->title )
		);
	}

	/**
	 * End element.
	 *
	 * @param string  $output Output buffer.
	 * @param WP_Post $item   Menu item.
	 * @param int     $depth  Depth.
	 * @param mixed   $args   Args.
	 */
	public function end_el( &$output, $item, $depth = 0, $args = null ) {
		$output .= '</li>';
	}
}
