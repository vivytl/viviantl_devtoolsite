<?php
Template Name: [my-resume].php 
if (is_front_page()):
get_header ('home');
elseif (is_page ('Resume')):
get_header ('resume');
else:
get_header ();
endif;
?>