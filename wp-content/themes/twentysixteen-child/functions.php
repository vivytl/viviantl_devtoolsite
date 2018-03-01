<?php
add_action( 'wp_enqueue_scripts', 'enqueue_parent_styles' );

function enqueue_parent_styles() {
   wp_enqueue_style( 'parent-style', get_template_directory_uri().'/style.css' );
}
function add_google_fonts(){
	wp_enqueue_style ('google_web_fonts', 'https://fonts.googleapis.com/css?family=Raleway');
}
add_action ('wp_enqueue_scripts', 'add_google_fonts');