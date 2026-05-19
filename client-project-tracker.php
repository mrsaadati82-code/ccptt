<?php
/**
 * Plugin Name: Client Project Tracker (CPTT)
 * Description: مدیریت پروژه‌های مشتری و نمایش مراحل پیشرفت داخل پنل کاربری (Stepper + Popup).
 * Version: 1.2.7
 * Author: Your Name
 * Text Domain: cptt
 */

if ( ! defined('ABSPATH') ) exit;

define('CPTT_VERSION', '1.2.7');
define('CPTT_PATH', plugin_dir_path(__FILE__));
define('CPTT_URL', plugin_dir_url(__FILE__));

/* mPDF autoload if installed */
if (file_exists(CPTT_PATH . 'vendor/autoload.php')) {
	require_once CPTT_PATH . 'vendor/autoload.php';
}

require_once CPTT_PATH . 'includes/class-cptt-core.php';
require_once CPTT_PATH . 'includes/class-cptt-admin.php';
require_once CPTT_PATH . 'includes/class-cptt-frontend.php';
require_once CPTT_PATH . 'includes/class-cptt-settings.php';
require_once CPTT_PATH . 'includes/class-cptt-report.php';

register_activation_hook(__FILE__, ['CPTT_Core', 'activate']);
register_deactivation_hook(__FILE__, ['CPTT_Core', 'deactivate']);

add_action('plugins_loaded', function () {
	CPTT_Core::instance();
	CPTT_Admin::instance();
	CPTT_Frontend::instance();
	CPTT_Settings::instance();
	CPTT_Report::instance();
});