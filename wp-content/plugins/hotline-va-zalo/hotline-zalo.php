<?php
/**
 * Plugin Name: Hotline và Zalo
 * Plugin URI: https://dangminhhai.net
 * Description: Plugin hiển thị số hotline và zalo
 * Version: 1.0.1 
 * Author: HaiDM
 * Author URI: https://www.facebook.com/minhhai0106
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Invalid request.' );
}

add_action( 'wp_enqueue_scripts', 'hotline_zalo_scripts_and_styles' );
function hotline_zalo_scripts_and_styles() {
    wp_enqueue_style( 'hotline-css', plugin_dir_url( __FILE__ ) . '/css/hotline.css', array(), '1.0.0' );
}

require_once( plugin_dir_path( __FILE__ ) . 'settings.php' );
