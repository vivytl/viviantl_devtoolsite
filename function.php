<?php

function wpb_add_google_font() {

wp_enqueue_style('wpb-google-fonts','https://fonts.googleapis.com/css?family=Joti+One}Ranga', false);
}

add_action('wp_enqueue_scripts','wpb_add_google_fonts');