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
		add_action('wp_head', [$this, 'inject_dynamic_css'], 5);
		add_action('admin_head', [$this, 'inject_dynamic_css'], 5);
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
			'text_color'      => '#1e293b',
			'border_radius'   => '16',
			'font_size_base'  => '13',
			'label_size'      => '14',
			'font_family'     => 'dana', 
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
		$base = CPTT_URL . 'assets/fonts/';
		
		$f = $s['font_family'];
		$faces = '';
		$font_stack = '';

		if ($f === 'dana') {
			$faces = "
				@font-face { font-family: 'Dana'; src: url('{$base}Dana/Dana-FaNum-Regular.ttf') format('truetype'); font-weight: 400; }
				@font-face { font-family: 'Dana'; src: url('{$base}Dana/Dana-FaNum-Medium.ttf') format('truetype'); font-weight: 500; }
				@font-face { font-family: 'Dana'; src: url('{$base}Dana/Dana-FaNum-Bold.ttf') format('truetype'); font-weight: 700; }
			";
			$font_stack = "'Dana', Tahoma, sans-serif";
		} elseif ($f === 'iransans') {
			$faces = "
				@font-face { font-family: 'IranSans'; src: url('{$base}iransans/IRANSansWeb(FaNum).woff2') format('woff2'); font-weight: 400; }
				@font-face { font-family: 'IranSans'; src: url('{$base}iransans/IRANSansWeb(FaNum)_Medium.woff2') format('woff2'); font-weight: 500; }
				@font-face { font-family: 'IranSans'; src: url('{$base}iransans/IRANSansWeb(FaNum)_Bold.woff2') format('woff2'); font-weight: 700; }
			";
			$font_stack = "'IranSans', Tahoma, sans-serif";
		} elseif ($f === 'iranyekan') {
			$faces = "
				@font-face { font-family: 'IranYekan'; src: url('{$base}iranyekan/IRANYekanX-Regular.woff2') format('woff2'); font-weight: 400; }
				@font-face { font-family: 'IranYekan'; src: url('{$base}iranyekan/IRANYekanX-Bold.woff2') format('woff2'); font-weight: 700; }
			";
			$font_stack = "'IranYekan', Tahoma, sans-serif";
		} elseif ($f === 'kalameh') {
			$faces = "
				@font-face { font-family: 'Kalameh'; src: url('{$base}kalameh/KalamehWebFaNum-Medium.woff2') format('woff2'); font-weight: 400; }
				@font-face { font-family: 'Kalameh'; src: url('{$base}kalameh/KalamehWebFaNum-Bold.woff2') format('woff2'); font-weight: 700; }
			";
			$font_stack = "'Kalameh', Tahoma, sans-serif";
		} elseif ($f === 'peyda') {
			$faces = "
				@font-face { font-family: 'Peyda'; src: url('{$base}peyda/PeydaWeb-Regular.woff') format('woff'); font-weight: 400; }
				@font-face { font-family: 'Peyda'; src: url('{$base}peyda/PeydaWeb-Bold.woff') format('woff'); font-weight: 700; }
			";
			$font_stack = "'Peyda', Tahoma, sans-serif";
		} else {
			$font_stack = "'Vazirmatn', Tahoma, sans-serif";
		}

		echo '<style id="cptt-v2-absolute-isolation">
			' . $faces . '
			body.cptt-v2-scope, .cptt-v2-scope {
				--cptt-primary: ' . esc_attr($s['primary_color']) . ';
				--cptt-secondary: ' . esc_attr($s['secondary_color']) . ';
				--cptt-radius: ' . esc_attr($s['border_radius']) . 'px;
				--cptt-font-main: ' . $font_stack . ' !important;
				font-family: var(--cptt-font-main) !important;
				background-color: ' . esc_attr($s['bg_color']) . ' !important;
				font-size: ' . esc_attr($s['font_size_base']) . 'px !important;
				color: ' . esc_attr($s['text_color']) . ' !important;
			}
			body.cptt-v2-scope *, body.cptt-v2-scope *::before, body.cptt-v2-scope *::after {
				font-family: var(--cptt-font-main) !important;
				box-sizing: border-box !important;
			}
			body.cptt-v2-scope .cptt-btn, 
			body.cptt-v2-scope .cptt-btn-primary, 
			body.cptt-v2-scope .cptt-newProjectCta {
				background: linear-gradient(135deg, var(--cptt-primary), var(--cptt-secondary)) !important;
				color: #ffffff !important;
				border-radius: var(--cptt-radius) !important;
				border: none !important;
				padding: 10px 24px !important;
				font-weight: 700 !important;
				font-size: ' . esc_attr($s['font_size_base']) . 'px !important;
				min-height: 44px !important;
				display: inline-flex !important;
				align-items: center !important;
				justify-content: center !important;
				box-shadow: 0 10px 25px rgba(0,0,0,0.1) !important;
				cursor: pointer !important;
			}
			body.cptt-v2-scope input[type="text"], 
			body.cptt-v2-scope input[type="url"], 
			body.cptt-v2-scope select, 
			body.cptt-v2-scope textarea {
				background: #ffffff !important;
				border: 1px solid #d1d5db !important;
				border-radius: var(--cptt-radius) !important;
				padding: 10px 14px !important;
				font-size: ' . esc_attr($s['font_size_base']) . 'px !important;
				height: auto !important;
				min-height: 44px !important;
				box-shadow: none !important;
			}
			body.cptt-v2-scope label span, 
			body.cptt-v2-scope .cptt-expert-sectionTitle {
				font-size: ' . esc_attr($s['label_size']) . 'px !important;
				font-weight: 700 !important;
				margin-bottom: 8px !important;
				display: block !important;
				color: #475569 !important;
			}
			' . ($adv['custom_css'] ?? '') . '
		</style>';
	}

	public function page() {
		$style = self::get_styles();
		$tab = $_GET['tab'] ?? 'style';
		?>
		<div class="cptt-settings-wrap cptt-v2-scope" dir="rtl">
			<header class="cptt-set-header">
				<div class="cptt-set-logo"><h1>تنظیمات CPTT v2.8.5</h1></div>
				<button type="submit" form="cptt-settings-form" class="cptt-btn-primary">ذخیره تغییرات</button>
			</header>
			<div class="cptt-set-body">
				<aside class="cptt-set-sidebar">
					<a href="?post_type=cptt_project&page=cptt-settings&tab=style" class="<?php echo $tab==='style'?'is-active':'';?>">استایل و فونت</a>
					<a href="?post_type=cptt_project&page=cptt-settings&tab=branding" class="<?php echo $tab==='branding'?'is-active':'';?>">برندینگ</a>
					<a href="?post_type=cptt_project&page=cptt-settings&tab=advanced" class="<?php echo $tab==='advanced'?'is-active':'';?>">تنظیمات سیستمی</a>
				</aside>
				<main class="cptt-set-main">
					<form method="post" action="options.php" id="cptt-settings-form">
						<?php settings_fields('cptt_settings_group'); ?>
						<?php if ($tab === 'style'): ?>
							<div class="cptt-set-grid">
								<div class="cptt-set-field"><label>انتخاب فونت</label>
									<select name="cptt_styles[font_family]">
										<option value="dana" <?php selected($style['font_family'], 'dana');?>>دانا (Dana)</option>
										<option value="iransans" <?php selected($style['font_family'], 'iransans');?>>ایران سنس (IranSans)</option>
										<option value="iranyekan" <?php selected($style['font_family'], 'iranyekan');?>>ایران یکان (IranYekan)</option>
										<option value="kalameh" <?php selected($style['font_family'], 'kalameh');?>>کلمه (Kalameh)</option>
										<option value="peyda" <?php selected($style['font_family'], 'peyda');?>>پیدا (Peyda)</option>
										<option value="vazir" <?php selected($style['font_family'], 'vazir');?>>وزیر (Vazirmatn)</option>
									</select>
								</div>
								<div class="cptt-set-field"><label>رنگ دکمه (۱)</label><input type="text" class="cptt-color-picker" name="cptt_styles[primary_color]" value="<?php echo esc_attr($style['primary_color']); ?>" /></div>
								<div class="cptt-set-field"><label>رنگ دکمه (۲)</label><input type="text" class="cptt-color-picker" name="cptt_styles[secondary_color]" value="<?php echo esc_attr($style['secondary_color']); ?>" /></div>
								<div class="cptt-set-field"><label>گردی گوشه‌ها</label><input type="number" name="cptt_styles[border_radius]" value="<?php echo esc_attr($style['border_radius']); ?>" /></div>
								<div class="cptt-set-field"><label>سایز متن پایه</label><input type="number" name="cptt_styles[font_size_base]" value="<?php echo esc_attr($style['font_size_base']); ?>" /></div>
								<div class="cptt-set-field"><label>سایز تیتر فیلدها</label><input type="number" name="cptt_styles[label_size]" value="<?php echo esc_attr($style['label_size']); ?>" /></div>
							</div>
						<?php elseif ($tab === 'advanced'): ?>
							<div class="cptt-set-field full">
								<label>CSS سفارشی</label>
								<textarea name="cptt_advanced[custom_css]" style="height:300px;"><?php echo esc_textarea(get_option('cptt_advanced')['custom_css'] ?? ''); ?></textarea>
							</div>
						<?php endif; ?>
					</form>
				</main>
			</div>
		</div>
		<?php
	}
}
