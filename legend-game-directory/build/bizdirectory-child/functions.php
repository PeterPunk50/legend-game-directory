<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'wp_enqueue_scripts', function() {
	wp_enqueue_style( 'bizdirectory-parent', get_template_directory_uri() . '/style.css', array(), wp_get_theme( 'bizdirectory' )->get( 'Version' ) );
	wp_enqueue_style( 'bizdirectory-legend', get_stylesheet_uri(), array( 'bizdirectory-parent' ), wp_get_theme()->get( 'Version' ) );
}, 20 );
add_action( 'after_setup_theme', function() { add_theme_support( 'responsive-embeds' ); add_theme_support( 'align-wide' ); } );
