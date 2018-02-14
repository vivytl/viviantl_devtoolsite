<?php

// Register parent style
add_action( 'wp_enqueue_scripts', 'minimal_2017_enqueue_parent_styles' );
function minimal_2017_enqueue_parent_styles() {
    wp_enqueue_style( 'minimal_2017_enqueue_parent_styles', get_template_directory_uri().'/style.css' );
}

// Register language
add_action( 'after_setup_theme', 'minimal_2017_setup' );
function minimal_2017_setup() {
	load_child_theme_textdomain( 'minimal-2017', get_stylesheet_directory() . '/languages' );
}

// Remove the read more anchor because it has no place on this earth
add_filter('the_content_more_link', 'minimal_2017_remove_more_jump_link');
function minimal_2017_remove_more_jump_link($link) { 
	$offset = strpos($link, '#more-');
	if ($offset) { $end = strpos($link, '"',$offset); }
	if ($end) { $link = substr_replace($link, '', $offset, $end-$offset); }
	return $link;
}

// Disable selectable section for the homepage now that we have new page templates
add_filter( 'twentyseventeen_front_page_sections', 'minimal_2017_custom_front_sections' );
function minimal_2017_custom_front_sections( $num_sections ) {
	return 0;
}

// Jetpack support - Make titles gallery wider
add_filter( 'tiled_gallery_content_width', 'minimal_2017_tiled_gallery_width' );
function minimal_2017_tiled_gallery_width() {
    return '1140';
}

// Overide parent content width
add_filter( 'twentyseventeen_content_width', function( $content_width )
{	// Override the default 740 content width
    $content_width = 1140;
    return $content_width; 
} );

// TGM Recommended Plugin
require_once get_stylesheet_directory() . '/inc/tgm/class-tgm-plugin-activation.php';

add_action( 'tgmpa_register', 'minimal_2017_register_required_plugins' );
function minimal_2017_register_required_plugins() {

	$plugins = array(
		array(
			'name'      => __('Easy Social Icons', 'minimal-2017' ), //Plugin Name
			'slug'      => 'easy-social-icons', //Plugin Slug
			'required'  => false, //Recommend Only
		),
	);
	$config = array(
		'id'           => 'minimal-2017',
		'default_path' => '',
		'menu'         => 'tgmpa-install-plugins', 
		'has_notices'  => true,
		'dismissable'  => true,
		'dismiss_msg'  => '',
		'is_automatic' => false,
		'message'      => '', 
	);

	tgmpa( $plugins, $config );
}