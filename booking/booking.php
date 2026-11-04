<?php
/**
 * Plugin Name: Booking
 * Description: Bookings (30/45/60 minute slots) with Google Calendar and Google Meet integration.
 * Version: 1.1.0
 * Author: Syed Noman Ali
 * Author URI: https://syednomanali.vercel.app/
 * Requires PHP: 7.4
 * Update URI: false
 */

if (!defined('ABSPATH')) exit;

define('CCB_VERSION', '1.1.0');
define('CCB_PATH', plugin_dir_path(__FILE__));
define('CCB_URL', plugin_dir_url(__FILE__));

require_once CCB_PATH . 'includes/class-ccb-plugin.php';

register_activation_hook(__FILE__, ['CCB_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['CCB_Plugin', 'deactivate']);

add_action('plugins_loaded', function () {
    CCB_Plugin::instance();
});
