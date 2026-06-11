<?php
/**
 * Plugin Name: هماهنگ - افزونه ی مدیریت پروژه و تیم
 * Description: هماهنگ، اولین افزونه ی اختصاصی ایرانی مدیریت پروژه است که امکاناتی فراتر از مدیریت پروژه دارد و مطابق با نیاز کسب و کار های ایرانی ساخته شده است. 
 * Version: 7.1.0
 * Author: امیرحسین سعادتی
 * Text Domain: cptt
 */

if ( ! defined('ABSPATH') ) exit;

define('CPTT_VERSION', '7.1.0');
define('CPTT_PATH', plugin_dir_path(__FILE__));
define('CPTT_URL', plugin_dir_url(__FILE__));

/* mPDF autoload if installed */
if (file_exists(CPTT_PATH . 'vendor/autoload.php')) {
	require_once CPTT_PATH . 'vendor/autoload.php';
}

require_once CPTT_PATH . 'includes/class-cptt-core.php';
require_once CPTT_PATH . 'includes/class-cptt-admin.php';
require_once CPTT_PATH . 'includes/class-cptt-frontend.php';
require_once CPTT_PATH . 'includes/class-cptt-expert.php';
require_once CPTT_PATH . 'includes/class-cptt-settings.php';
require_once CPTT_PATH . 'includes/class-cptt-report.php';
require_once CPTT_PATH . 'includes/class-cptt-sms.php';
require_once CPTT_PATH . 'includes/class-cptt-woocommerce.php';
require_once CPTT_PATH . 'includes/class-cptt-analytics.php';
require_once CPTT_PATH . 'includes/class-cptt-bale.php';
require_once CPTT_PATH . 'includes/class-cptt-auth.php';
require_once CPTT_PATH . 'includes/class-cptt-payment.php';
require_once CPTT_PATH . 'includes/class-cptt-form-builder.php';
require_once CPTT_PATH . 'includes/class-cptt-file-manager.php';
require_once CPTT_PATH . 'includes/class-cptt-requests.php';
require_once CPTT_PATH . 'includes/class-cptt-ai-assistant.php';
require_once CPTT_PATH . 'includes/class-cptt-reminders.php';
require_once CPTT_PATH . 'includes/class-cptt-finance.php';
require_once CPTT_PATH . 'includes/class-cptt-finance-erp.php';

register_activation_hook(__FILE__, ['CPTT_Core', 'activate']);
register_activation_hook(__FILE__, function(){
	if (class_exists('CPTT_Auth')) { CPTT_Auth::instance()->add_rewrites(); }
	// پرداخت عمومی
	add_rewrite_rule('^cptt-pay/([a-zA-Z0-9\\-]+)/?$', 'index.php?cptt_pay_token=$matches[1]', 'top');
	if (class_exists('CPTT_Form_Builder')) { CPTT_Form_Builder::install_defaults(); }
	flush_rewrite_rules(false);
});
register_deactivation_hook(__FILE__, function(){ flush_rewrite_rules(false); });
register_deactivation_hook(__FILE__, ['CPTT_Core', 'deactivate']);



add_filter('admin_body_class', function($classes){
	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	$id = $screen ? (string)$screen->id : '';
	$post_type = $screen ? (string)$screen->post_type : '';
	$allowed_ids = ['cptt_project_page_cptt-project-dashboard','cptt_project_page_cptt-accounting','cptt_project_page_cptt-settings','cptt_project_page_cptt-sms-settings','cptt_project_page_cptt-project-labels','cptt_project_page_cptt-customers','cptt_project_page_cptt-experts-manage','cptt_project_page_cptt-experts-hub-settings','cptt_project_page_cptt-payments','cptt_project_page_cptt-form-builder','edit-cptt_order','cptt_order','cptt_template','cptt_checklist_tpl'];
	$allowed_posts = ['cptt_template','cptt_checklist_tpl','cptt_order'];
	if (in_array($id, $allowed_ids, true) || in_array($post_type, $allowed_posts, true)) $classes .= ' ham-admin-glass ';
	return $classes;
});

add_action('admin_enqueue_scripts', function($hook){
	global $post_type;
	$allowed_hooks = [
		'cptt_project_page_cptt-project-dashboard','cptt_project_page_cptt-accounting','cptt_project_page_cptt-settings',
		'cptt_project_page_cptt-sms-settings','cptt_project_page_cptt-project-labels','cptt_project_page_cptt-customers',
		'cptt_project_page_cptt-experts-manage','cptt_project_page_cptt-experts-hub-settings','cptt_project_page_cptt-payments',
		'cptt_project_page_cptt-form-builder'
	];
	$allowed_posts = ['cptt_template','cptt_checklist_tpl','cptt_order'];
	if (in_array($hook, $allowed_hooks, true) || in_array((string)$post_type, $allowed_posts, true)) {
		wp_enqueue_style('ham-admin-glass', CPTT_URL . 'assets/css/admin-glass.css', [], CPTT_VERSION);
		wp_add_inline_script('jquery-core', "document.addEventListener('DOMContentLoaded',function(){document.body.classList.add('ham-admin-glass');});");
	}
});


/* HAM: mobile phone field in WordPress user profile */
add_action('show_user_profile', 'cptt_render_user_mobile_profile_field', 5);
add_action('edit_user_profile', 'cptt_render_user_mobile_profile_field', 5);
function cptt_render_user_mobile_profile_field($user) {
	if (!$user || !($user instanceof WP_User)) return;
	$phone = (string) get_user_meta($user->ID, 'billing_phone', true);
	if ($phone === '') $phone = (string) get_user_meta($user->ID, 'cptt_user_phone', true);
	if ($phone === '') $phone = (string) get_user_meta($user->ID, 'mobile', true);
	?>
	<h2>اطلاعات تماس هماهنگ</h2>
	<table class="form-table" role="presentation">
		<tr>
			<th><label for="cptt_profile_mobile">شماره موبایل</label></th>
			<td>
				<input type="text" id="cptt_profile_mobile" name="cptt_profile_mobile" value="<?php echo esc_attr($phone); ?>" class="regular-text" dir="ltr" placeholder="09123456789">
				<p class="description">این شماره برای اتصال حساب کاربری به ربات بله، مشتریان و اعلان‌های افزونه استفاده می‌شود.</p>
			</td>
		</tr>
	</table>
	<?php
}
add_action('personal_options_update', 'cptt_save_user_mobile_profile_field', 5);
add_action('edit_user_profile_update', 'cptt_save_user_mobile_profile_field', 5);
function cptt_save_user_mobile_profile_field($user_id) {
	if (!current_user_can('edit_user', $user_id)) return;
	if (!isset($_POST['cptt_profile_mobile'])) return;
	$phone = preg_replace('/[^0-9]/', '', (string) wp_unslash($_POST['cptt_profile_mobile']));
	if (strpos($phone, '9') === 0 && strlen($phone) === 10) $phone = '0' . $phone;
	update_user_meta($user_id, 'billing_phone', $phone);
	update_user_meta($user_id, 'cptt_user_phone', $phone);
	update_user_meta($user_id, 'mobile', $phone);
}

add_action('plugins_loaded', function () {
	CPTT_Core::instance();
	CPTT_Admin::instance();
	CPTT_Frontend::instance();
	CPTT_Expert::instance();
	CPTT_Settings::instance();
	CPTT_Report::instance();
	CPTT_SMS::instance();
	CPTT_WooCommerce::instance();
	CPTT_Analytics::instance();
	CPTT_Bale::instance();
	CPTT_Auth::instance();
	CPTT_Payment::instance();
	CPTT_Form_Builder::instance();
	CPTT_File_Manager::instance();
	CPTT_Requests::instance();
	CPTT_AI_Assistant::instance();
	CPTT_Reminders::instance();
	CPTT_Finance::instance();
	CPTT_Finance_ERP::instance();
});

/* v6.4.0 — Ensure finance tables exist on activation. */
register_activation_hook(__FILE__, function(){
	if (class_exists('CPTT_Finance')) {
		CPTT_Finance::install_tables();
		update_option('cptt_finance_db_version', CPTT_Finance::DB_VERSION);
		CPTT_Finance::seed_defaults();
	}
});