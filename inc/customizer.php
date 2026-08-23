<?php
/**
 * Customizer options for the pieces the design exposes as content:
 * the announcement bar and the storefront hero.
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register controls.
 *
 * @param WP_Customize_Manager $wp_customize Manager.
 */
function cartly_customize_register( $wp_customize ) {

	/* ---------------------------------------------------- announcement --- */
	$wp_customize->add_section(
		'cartly_announcement',
		array(
			'title'       => __( 'Announcement bar', 'cartly' ),
			'priority'    => 30,
			'description' => __( 'The thin ink strip above the header. Leave the text empty to hide it.', 'cartly' ),
		)
	);

	$wp_customize->add_setting(
		'cartly_announcement_text',
		array(
			'default'           => __( 'Free shipping over ₹999', 'cartly' ),
			'sanitize_callback' => 'wp_kses_post',
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'cartly_announcement_text',
		array(
			'label'   => __( 'Text', 'cartly' ),
			'section' => 'cartly_announcement',
			'type'    => 'text',
		)
	);

	$wp_customize->add_setting(
		'cartly_announcement_highlight',
		array(
			'default'           => __( 'Flash sale live', 'cartly' ),
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'cartly_announcement_highlight',
		array(
			'label'       => __( 'Highlighted phrase', 'cartly' ),
			'description' => __( 'Rendered in lime after the main text.', 'cartly' ),
			'section'     => 'cartly_announcement',
			'type'        => 'text',
		)
	);

	$wp_customize->add_setting(
		'cartly_announcement_link',
		array(
			'default'           => '',
			'sanitize_callback' => 'esc_url_raw',
		)
	);
	$wp_customize->add_control(
		'cartly_announcement_link',
		array(
			'label'   => __( 'Link (optional)', 'cartly' ),
			'section' => 'cartly_announcement',
			'type'    => 'url',
		)
	);

	/* ------------------------------------------------------------ hero --- */
	$wp_customize->add_section(
		'cartly_hero',
		array(
			'title'       => __( 'Storefront hero', 'cartly' ),
			'priority'    => 31,
			'description' => __( 'The ink hero on the front page and shop archive.', 'cartly' ),
		)
	);

	$fields = array(
		'cartly_hero_eyebrow'    => array( __( 'Eyebrow', 'cartly' ), __( 'New season', 'cartly' ), 'text' ),
		'cartly_hero_title'      => array( __( 'Headline', 'cartly' ), __( 'Everything you', 'cartly' ), 'text' ),
		'cartly_hero_title_alt'  => array( __( 'Headline (accent line)', 'cartly' ), __( 'need, one cart.', 'cartly' ), 'text' ),
		'cartly_hero_text'       => array( __( 'Supporting copy', 'cartly' ), __( 'A catalog you can actually search, a checkout that does not fight you, and rewards that stack.', 'cartly' ), 'textarea' ),
		'cartly_hero_cta_label'  => array( __( 'Primary button label', 'cartly' ), __( 'Shop the catalog', 'cartly' ), 'text' ),
		'cartly_hero_cta_url'    => array( __( 'Primary button URL', 'cartly' ), '', 'url' ),
		'cartly_hero_cta2_label' => array( __( 'Secondary button label', 'cartly' ), __( 'View deals', 'cartly' ), 'text' ),
		'cartly_hero_cta2_url'   => array( __( 'Secondary button URL', 'cartly' ), '', 'url' ),
	);

	foreach ( $fields as $id => $field ) {
		list( $label, $default, $type ) = $field;

		$wp_customize->add_setting(
			$id,
			array(
				'default'           => $default,
				'sanitize_callback' => 'url' === $type ? 'esc_url_raw' : 'sanitize_text_field',
			)
		);
		$wp_customize->add_control(
			$id,
			array(
				'label'   => $label,
				'section' => 'cartly_hero',
				'type'    => $type,
			)
		);
	}

	$wp_customize->add_setting(
		'cartly_hero_enabled',
		array(
			'default'           => true,
			'sanitize_callback' => 'wp_validate_boolean',
		)
	);
	$wp_customize->add_control(
		'cartly_hero_enabled',
		array(
			'label'   => __( 'Show the hero', 'cartly' ),
			'section' => 'cartly_hero',
			'type'    => 'checkbox',
		)
	);

	$wp_customize->add_setting(
		'cartly_hero_image',
		array( 'sanitize_callback' => 'absint' )
	);
	$wp_customize->add_control(
		new WP_Customize_Media_Control(
			$wp_customize,
			'cartly_hero_image',
			array(
				'label'     => __( 'Hero image', 'cartly' ),
				'section'   => 'cartly_hero',
				'mime_type' => 'image',
			)
		)
	);

	/* ---------------------------------------------------------- footer --- */
	$wp_customize->add_section(
		'cartly_footer',
		array(
			'title'    => __( 'Footer', 'cartly' ),
			'priority' => 32,
		)
	);

	$wp_customize->add_setting(
		'cartly_footer_blurb',
		array(
			'default'           => __( 'Everything you need, one cart.', 'cartly' ),
			'sanitize_callback' => 'sanitize_textarea_field',
		)
	);
	$wp_customize->add_control(
		'cartly_footer_blurb',
		array(
			'label'   => __( 'Blurb', 'cartly' ),
			'section' => 'cartly_footer',
			'type'    => 'textarea',
		)
	);

	$wp_customize->add_setting(
		'cartly_footer_badges',
		array(
			'default'           => 'Visa, Mastercard, UPI, Razorpay, COD',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'cartly_footer_badges',
		array(
			'label'       => __( 'Payment badges', 'cartly' ),
			'description' => __( 'Comma separated.', 'cartly' ),
			'section'     => 'cartly_footer',
			'type'        => 'text',
		)
	);
}
add_action( 'customize_register', 'cartly_customize_register' );
