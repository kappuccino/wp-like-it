<?php
/*
Plugin Name: WP Like It
Plugin URI: http://wordpress.org/plugins/wp-like-it/
Description: Standalone like system for Wordpress. "No UI" philosophy.
Version: 0.0.1
Author: bmosnier
License: GPLv3
*/

require __DIR__.'/lib/core.php';
new likeItCore(); // singleton

if(is_admin()){

	// Plugin life cycle
	register_activation_hook(__FILE__, ['likeItCore', 'activated']);
	register_deactivation_hook(__FILE__, ['likeItCore', 'desactivated']);
	register_uninstall_hook(__FILE__, ['likeItCore', 'uninstall']);

	// Hooks
	add_action('plugins_loaded', ['likeItCore', 'dbCheck']);

	require __DIR__.'/admin.php';
	new likeItAdmin();

}

