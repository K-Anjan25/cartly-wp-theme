<?php
/**
 * Header: announcement · sticky command-search header · category rail.
 * Wireframe 01 (design/wireframes/01-global-shell-desktop.svg).
 *
 * @package Cartly
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div class="flex min-h-screen flex-col bg-canvas">

	<a class="screen-reader-text" href="#cartly-main"><?php esc_html_e( 'Skip to content', 'cartly' ); ?></a>

	<?php get_template_part( 'template-parts/header/announcement' ); ?>
	<?php get_template_part( 'template-parts/header/navbar' ); ?>
	<?php get_template_part( 'template-parts/header/category-rail' ); ?>

	<main id="cartly-main" class="animate-fade-up flex-1 pb-24 pt-6 lg:pb-12">
