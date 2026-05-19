<?php
if ( ! defined('ABSPATH') ) exit;

class CPTT_Settings {
	private static $instance = null;
	private $option_key = 'cptt_branding';

	public static function instance() {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action('admin_menu', [$this, 'menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_enqueue_scripts', [$this, 'assets']);
	}

	public static function get() {
		$defaults = [
			'brand_name'    => get_bloginfo('name'),
			'site_url'      => home_url('/'),
			'primary_color' => '#22c55e',
			'footer_text'   => 'این گزارش به صورت خودکار تولید شده است.',
			'logo_id'       => 0,
			'sign_id'       => 0,
			'stamp_id'      => 0,
			'manager_name'  => '',
			'manager_title' => '',
		];
		$opt = get_option('cptt_branding', []);
		if (!is_array($opt)) $opt = [];
		return array_merge($defaults, $opt);
	}

	public function menu() {
		add_submenu_page(
			'edit.php?post_type=cptt_project',
			'تنظیمات گزارش PDF',
			'تنظیمات گزارش PDF',
			'manage_options',
			'cptt-branding',
			[$this, 'page']
		);
	}

	public function register_settings() {
		register_setting('cptt_branding_group', $this->option_key, [
			'sanitize_callback' => [$this, 'sanitize']
		]);

		add_settings_section('cptt_branding_main', 'برندینگ گزارش', function () {
			echo '<p>این تنظیمات داخل گزارش PDF نمایش داده می‌شود.</p>';
		}, 'cptt-branding');
	}

	public function sanitize($in) {
		if (!is_array($in)) $in = [];

		$out = [];
		$out['brand_name'] = sanitize_text_field($in['brand_name'] ?? '');
		$out['site_url']   = esc_url_raw($in['site_url'] ?? '');
		$out['primary_color'] = sanitize_hex_color($in['primary_color'] ?? '#22c55e') ?: '#22c55e';
		$out['footer_text'] = sanitize_text_field($in['footer_text'] ?? '');

		$out['logo_id']  = absint($in['logo_id'] ?? 0);
		$out['sign_id']  = absint($in['sign_id'] ?? 0);
		$out['stamp_id'] = absint($in['stamp_id'] ?? 0);

		$out['manager_name']  = sanitize_text_field($in['manager_name'] ?? '');
		$out['manager_title'] = sanitize_text_field($in['manager_title'] ?? '');

		return $out;
	}

	public function assets($hook) {
		if ($hook !== 'cptt_project_page_cptt-branding') return;

		wp_enqueue_media();
		wp_enqueue_script('cptt-settings', CPTT_URL . 'assets/js/settings.js', ['jquery'], CPTT_VERSION, true);
		wp_enqueue_style('cptt-settings', CPTT_URL . 'assets/css/settings.css', [], CPTT_VERSION);
	}

	private function media_field($name, $label) {
		$opt = self::get();
		$id  = (int)($opt[$name] ?? 0);
		$url = $id ? wp_get_attachment_image_url($id, 'medium') : '';
		?>
		<div class="cptt-mediaField" data-target="<?php echo esc_attr($name); ?>">
			<div class="cptt-mediaField__label"><?php echo esc_html($label); ?></div>
			<input type="hidden" name="<?php echo esc_attr($this->option_key); ?>[<?php echo esc_attr($name); ?>]" value="<?php echo esc_attr($id); ?>" />
			<div class="cptt-mediaField__preview">
				<?php if ($url): ?>
					<img src="<?php echo esc_url($url); ?>" alt="" />
				<?php else: ?>
					<div class="cptt-mediaField__empty">آپلود نشده</div>
				<?php endif; ?>
			</div>
			<div class="cptt-mediaField__actions">
				<button type="button" class="button cptt-media-select">انتخاب/آپلود</button>
				<button type="button" class="button cptt-media-remove">حذف</button>
			</div>
		</div>
		<?php
	}

	public function page() {
		if (!current_user_can('manage_options')) return;

		$opt = self::get();
		?>
		<div class="wrap" dir="rtl">
			<h1>تنظیمات گزارش PDF</h1>

			<form method="post" action="options.php">
				<?php settings_fields('cptt_branding_group'); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">نام برند</th>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr($this->option_key); ?>[brand_name]" value="<?php echo esc_attr($opt['brand_name']); ?>"></td>
					</tr>

					<tr>
						<th scope="row">لینک سایت</th>
						<td><input type="url" class="regular-text" name="<?php echo esc_attr($this->option_key); ?>[site_url]" value="<?php echo esc_attr($opt['site_url']); ?>"></td>
					</tr>

					<tr>
						<th scope="row">رنگ اصلی</th>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr($this->option_key); ?>[primary_color]" value="<?php echo esc_attr($opt['primary_color']); ?>" placeholder="#22c55e"></td>
					</tr>

					<tr>
						<th scope="row">متن فوتر</th>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr($this->option_key); ?>[footer_text]" value="<?php echo esc_attr($opt['footer_text']); ?>"></td>
					</tr>

					<tr>
						<th scope="row">اطلاعات امضا</th>
						<td>
							<p><label>نام/مسئول:</label>
							<input type="text" class="regular-text" name="<?php echo esc_attr($this->option_key); ?>[manager_name]" value="<?php echo esc_attr($opt['manager_name']); ?>"></p>
							<p><label>سمت:</label>
							<input type="text" class="regular-text" name="<?php echo esc_attr($this->option_key); ?>[manager_title]" value="<?php echo esc_attr($opt['manager_title']); ?>"></p>
						</td>
					</tr>

					<tr>
						<th scope="row">لوگو</th>
						<td><?php $this->media_field('logo_id', 'لوگو'); ?></td>
					</tr>

					<tr>
						<th scope="row">امضا دیجیتال</th>
						<td><?php $this->media_field('sign_id', 'امضا (PNG پیشنهاد می‌شود)'); ?></td>
					</tr>

					<tr>
						<th scope="row">مهر</th>
						<td><?php $this->media_field('stamp_id', 'مهر (PNG با پس‌زمینه شفاف)'); ?></td>
					</tr>
				</table>

				<?php submit_button('ذخیره تنظیمات'); ?>
			</form>
		</div>
		<?php
	}
}