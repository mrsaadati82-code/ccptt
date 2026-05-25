<?php
if ( ! defined('ABSPATH') ) exit;

class CPTT_Settings {
	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action('admin_menu', [$this, 'menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_enqueue_scripts', [$this, 'assets']);
		add_action('wp_head', [$this, 'inject_dynamic_css'], 100);
		add_action('admin_head', [$this, 'inject_dynamic_css'], 100);
	}

	public static function get_styles() {
		$opt = get_option('cptt_styles', []);
		return array_merge([
			'primary_color'   => '#6366f1',
			'secondary_color' => '#8b5cf6',
			'success_color'   => '#22c55e',
			'warning_color'   => '#f59e0b',
			'danger_color'    => '#ef4444',
			'bg_color'        => '#f8fbff',
			'card_bg'         => '#ffffff',
			'text_color'      => '#1e293b',
			'border_radius'   => '16',
			'font_size_base'  => '13',
			'label_size'      => '14',
			'font_family'     => 'vazir',
		], is_array($opt) ? $opt : []);
	}

	public function menu() {
		add_submenu_page('edit.php?post_type=cptt_project', 'تنظیمات CPTT', 'تنظیمات افزونه', 'manage_options', 'cptt-settings', [$this, 'page']);
	}

	public function register_settings() {
		register_setting('cptt_settings_group', 'cptt_branding');
		register_setting('cptt_settings_group', 'cptt_styles');
		register_setting('cptt_settings_group', 'cptt_advanced');
	}

	public function assets($hook) {
		if (strpos($hook, 'cptt-settings') === false) return;
		wp_enqueue_media();
		wp_enqueue_style('wp-color-picker');
		wp_enqueue_script('cptt-settings-js', CPTT_URL . 'assets/js/settings.js', ['jquery', 'wp-color-picker'], CPTT_VERSION, true);
		wp_enqueue_style('cptt-settings-css', CPTT_URL . 'assets/css/settings.css', [], CPTT_VERSION);
	}

	public function inject_dynamic_css() {
		$s = self::get_styles();
		$adv = get_option('cptt_advanced', []);
		$font_stack = "'Vazirmatn', Tahoma, sans-serif !important";

		echo '<style id="cptt-v2-isolation-layer">
			/* 1. NEUTRALIZE WP INJECTED STYLES */
			.cptt-v2-scope, .cptt-v2-scope * {
				--wp--preset--font-size--normal: ' . esc_attr($s['font_size_base']) . 'px !important;
				--wp--preset--font-size--large: ' . esc_attr($s['font_size_base']) . 'px !important;
				--wp--preset--font-size--medium: ' . esc_attr($s['font_size_base']) . 'px !important;
				--wp--preset--font-size--small: 11px !important;
				--wp--preset--spacing--50: 0 !important;
				--wp--style--root--padding-right: 0 !important;
				--wp--style--root--padding-left: 0 !important;
				box-sizing: border-box !important;
			}

			/* 2. CORE VARIABLES */
			:root {
				--cptt-primary: ' . esc_attr($s['primary_color']) . ';
				--cptt-secondary: ' . esc_attr($s['secondary_color']) . ';
				--cptt-success: ' . esc_attr($s['success_color']) . ';
				--cptt-warning: ' . esc_attr($s['warning_color']) . ';
				--cptt-danger: ' . esc_attr($s['danger_color']) . ';
				--cptt-bg: ' . esc_attr($s['bg_color']) . ';
				--cptt-radius: ' . esc_attr($s['border_radius']) . 'px;
				--cptt-font-size: ' . esc_attr($s['font_size_base']) . 'px;
				--cptt-label-size: ' . esc_attr($s['label_size']) . 'px;
			}

			/* 3. HARD ELEMENT RESET */
			.cptt-v2-scope {
				font-family: ' . $font_stack . ';
				background-color: var(--cptt-bg);
				color: ' . esc_attr($s['text_color']) . ' !important;
				line-height: 1.6 !important;
				font-size: var(--cptt-font-size) !important;
			}

			.cptt-v2-scope button, 
			.cptt-v2-scope input, 
			.cptt-v2-scope select, 
			.cptt-v2-scope textarea {
				font-family: ' . $font_stack . ';
				font-size: var(--cptt-font-size) !important;
				line-height: 1.4 !important;
				margin: 0 !important;
				text-transform: none !important;
                letter-spacing: normal !important;
			}

			/* 4. FIX BUTTONS (ROUNDING & COLOR) */
			.cptt-v2-scope .cptt-btn, 
			.cptt-v2-scope .cptt-btn-primary, 
			.cptt-v2-scope .cptt-newProjectCta {
				border-radius: var(--cptt-radius) !important;
				font-weight: 900 !important;
				border: none !important;
				cursor: pointer !important;
				display: inline-flex !important;
				align-items: center !important;
				justify-content: center !important;
				transition: all 0.2s ease !important;
                padding: 10px 20px !important;
                height: auto !important;
                min-height: 42px !important;
			}
            
            .cptt-v2-scope .cptt-newProjectCta,
            .cptt-v2-scope .cptt-btn-primary {
                background: linear-gradient(135deg, var(--cptt-primary), var(--cptt-secondary)) !important;
                color: #ffffff !important;
                box-shadow: 0 8px 15px rgba(0,0,0,0.1) !important;
            }

			/* 5. FIX LABELS & INPUTS */
			.cptt-v2-scope label span, 
			.cptt-v2-scope .cptt-expert-sectionTitle {
				font-size: var(--cptt-label-size) !important;
				font-weight: 800 !important;
				color: #475569 !important;
				margin-bottom: 8px !important;
				display: block !important;
                line-height: 1.2 !important;
			}

			.cptt-v2-scope input[type="text"], 
			.cptt-v2-scope input[type="url"], 
			.cptt-v2-scope input[type="number"], 
			.cptt-v2-scope select, 
			.cptt-v2-scope textarea {
				width: 100% !important;
				border: 1px solid #d1d5db !important;
				border-radius: var(--cptt-radius) !important;
				padding: 10px 14px !important;
				background: #ffffff !important;
				color: #111827 !important;
				box-shadow: inset 0 1px 2px rgba(0,0,0,0.05) !important;
                height: auto !important;
                min-height: 44px !important;
			}

			.cptt-v2-scope input:focus {
				border-color: var(--cptt-primary) !important;
				box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1) !important;
				outline: none !important;
			}

			' . ($adv['custom_css'] ?? '') . '
		</style>';
	}

	public function page() {
		$style = self::get_styles();
		$adv = get_option('cptt_advanced', []);
		$tab = $_GET['tab'] ?? 'style';
		?>
		<div class="cptt-settings-wrap cptt-v2-scope" dir="rtl">
			<header class="cptt-set-header">
				<div class="cptt-set-logo">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <div><h1>تنظیمات نهایی CPTT</h1><p>نسخه 2.6.0 - ایزولاسیون و زیبایی حداکثری</p></div>
                </div>
				<button type="submit" form="cptt-settings-form" class="cptt-btn-primary">ذخیره تنظیمات</button>
			</header>
			<div class="cptt-set-body">
				<aside class="cptt-set-sidebar">
					<a href="?post_type=cptt_project&page=cptt-settings&tab=style" class="<?php echo $tab==='style'?'is-active':'';?>">استایل و فونت</a>
					<a href="?post_type=cptt_project&page=cptt-settings&tab=branding" class="<?php echo $tab==='branding'?'is-active':'';?>">برندینگ</a>
					<a href="?post_type=cptt_project&page=cptt-settings&tab=advanced" class="<?php echo $tab==='advanced'?'is-active':'';?>">پیشرفته</a>
				</aside>
				<main class="cptt-set-main">
					<form method="post" action="options.php" id="cptt-settings-form">
						<?php settings_fields('cptt_settings_group'); ?>
						<?php if ($tab === 'style'): ?>
							<div class="cptt-set-grid">
								<div class="cptt-set-field"><label>رنگ اصلی (گرادینت ۱)</label><input type="text" class="cptt-color-picker" name="cptt_styles[primary_color]" value="<?php echo esc_attr($style['primary_color']); ?>" /></div>
								<div class="cptt-set-field"><label>رنگ ثانویه (گرادینت ۲)</label><input type="text" class="cptt-color-picker" name="cptt_styles[secondary_color]" value="<?php echo esc_attr($style['secondary_color']); ?>" /></div>
								<div class="cptt-set-field"><label>سایز متن فیلدها</label><input type="number" name="cptt_styles[font_size_base]" value="<?php echo esc_attr($style['font_size_base']); ?>" /></div>
								<div class="cptt-set-field"><label>سایز تیتر فیلدها</label><input type="number" name="cptt_styles[label_size]" value="<?php echo esc_attr($style['label_size']); ?>" /></div>
								<div class="cptt-set-field"><label>گردی لبه‌ها (Pixel)</label><input type="number" name="cptt_styles[border_radius]" value="<?php echo esc_attr($style['border_radius']); ?>" /></div>
								<div class="cptt-set-field"><label>پس‌زمینه داشبورد</label><input type="text" class="cptt-color-picker" name="cptt_styles[bg_color]" value="<?php echo esc_attr($style['bg_color']); ?>" /></div>
							</div>
						<?php elseif ($tab === 'advanced'): ?>
							<div class="cptt-set-field full">
								<label>CSS سفارشی برای ایزولاسیون بیشتر</label>
								<textarea name="cptt_advanced[custom_css]" style="height:300px;font-family:monospace;"><?php echo esc_textarea($adv['custom_css'] ?? ''); ?></textarea>
							</div>
						<?php endif; ?>
					</form>
				</main>
			</div>
		</div>
		<?php
	}
}
