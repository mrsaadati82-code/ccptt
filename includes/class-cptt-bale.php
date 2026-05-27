<?php
/**
 * CPTT Bale Bot — v5.4.4 (Full inline-keyboard UX + states + edit-in-place)
 *
 * هرچه command-based بود به دکمه‌های شیشه‌ای تبدیل شد. wizard چندمرحله‌ای برای:
 *   - ایجاد پروژه توسط مدیر (مرحله‌به‌مرحله)
 *   - پاسخ مشتری به تسک (متن + فایل)
 *   - چت کارشناس در پروژه (متن + فایل)
 *   - علامت‌گذاری چک‌لیست
 *   - Mute هر پروژه
 *   - گزارش روزانه / هفتگی خودکار با کرون
 *   - OTP login (در فاز ۳ از همین کلاس استفاده می‌شود)
 *
 * State per chat_id در option `cptt_bale_states` (با TTL ۳۰ دقیقه) ذخیره می‌شود.
 */

if ( ! defined('ABSPATH') ) exit;

class CPTT_Bale {

	private static $instance = null;
	const API_BASE  = 'https://tapi.bale.ai/bot';
	const STATE_OPT = 'cptt_bale_states';
	const STATE_TTL = 1800; // 30 min

	public static function instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action('wp_ajax_nopriv_cptt_bale_webhook', [$this, 'handle_webhook']);
		add_action('wp_ajax_cptt_bale_webhook',         [$this, 'handle_webhook']);
		add_action('update_option_cptt_bale_settings',  [$this, 'set_webhook_on_save'], 10, 2);
		add_action('add_option_cptt_bale_settings',     [$this, 'set_webhook_on_add'], 10, 2);
		add_action('admin_init',                        [$this, 'handle_admin_actions']);

		// Cron
		add_filter('cron_schedules', [$this, 'add_cron_schedules']);
		add_action('cptt_bale_daily_report',  [$this, 'cron_daily_report']);
		add_action('cptt_bale_weekly_report', [$this, 'cron_weekly_report']);
		add_action('init', [$this, 'maybe_schedule_crons']);

		// User task done → also notify customer in Bale with quick-reply
		add_action('cptt_user_task_assigned',  [$this, 'on_user_task_assigned'], 10, 3);
	}

	/* ====================================================================
	 * SETTINGS
	 * ==================================================================== */
	public static function get_settings() {
		$defaults = [
			'token'                => '',
			'admin_id'             => '',
			'expert_assign'        => '1',
			'expert_chat'          => '1',
			'expert_payout'        => '1',
			'client_complete'      => '1',
			'client_task'          => '1',
			'daily_report'         => '1',
			'weekly_report'        => '1',
			'enable_otp_login'     => '0',
			'otp_login_only'       => '0',
		];
		$opt = get_option('cptt_bale_settings', []);
		return array_merge($defaults, is_array($opt) ? $opt : []);
	}

	public function set_webhook_on_add($option, $value) { $this->set_webhook_on_save([], $value); }

	public function set_webhook_on_save($old_value, $new_value) {
		$token = isset($new_value['token']) ? trim($new_value['token']) : '';
		if ($token !== '') {
			$webhook_url = admin_url('admin-ajax.php?action=cptt_bale_webhook');
			$api_url = self::API_BASE . "{$token}/setWebhook?url=" . urlencode($webhook_url);
			wp_remote_get($api_url, ['sslverify' => false, 'timeout' => 15]);
		}
	}

	public function handle_admin_actions() {
		if (!current_user_can('manage_options')) return;
		if (empty($_GET['page']) || $_GET['page'] !== 'cptt-settings' || empty($_GET['bale_action'])) return;
		$action   = sanitize_key($_GET['bale_action']);
		$settings = self::get_settings();
		$token    = trim($settings['token']);
		if ($token === '') {
			add_settings_error('cptt_bale_messages', 'cptt_bale_error', 'لطفاً ابتدا توکن ربات بله را ذخیره کنید.', 'error');
			return;
		}
		if ($action === 'set_webhook') {
			$webhook_url = admin_url('admin-ajax.php?action=cptt_bale_webhook');
			$api_url = self::API_BASE . "{$token}/setWebhook?url=" . urlencode($webhook_url);
			$res = wp_remote_get($api_url, ['sslverify' => false, 'timeout' => 15]);
			if (is_wp_error($res)) add_settings_error('cptt_bale_messages', 'cptt_bale_error', 'خطا در تنظیم وب‌هوک: ' . $res->get_error_message(), 'error');
			else add_settings_error('cptt_bale_messages', 'cptt_bale_success', '✅ وب‌هوک با موفقیت تنظیم شد.', 'updated');
		} elseif ($action === 'delete_webhook') {
			$res = wp_remote_get(self::API_BASE . "{$token}/deleteWebhook", ['sslverify' => false, 'timeout' => 15]);
			if (is_wp_error($res)) add_settings_error('cptt_bale_messages', 'cptt_bale_error', 'خطا در حذف وب‌هوک: ' . $res->get_error_message(), 'error');
			else add_settings_error('cptt_bale_messages', 'cptt_bale_success', '✅ وب‌هوک حذف شد.', 'updated');
		} elseif ($action === 'test_connection') {
			$res = wp_remote_get(self::API_BASE . "{$token}/getMe", ['sslverify' => false, 'timeout' => 15]);
			if (is_wp_error($res)) add_settings_error('cptt_bale_messages', 'cptt_bale_error', 'خطا در تست: ' . $res->get_error_message(), 'error');
			else {
				$body = json_decode(wp_remote_retrieve_body($res), true);
				if (!empty($body['ok']) && !empty($body['result']['username'])) {
					$bot_name = $body['result']['first_name'] ?? 'Bot';
					$bot_user = $body['result']['username'];
					add_settings_error('cptt_bale_messages', 'cptt_bale_success', "✅ اتصال برقرار است. نام ربات: {$bot_name} (@{$bot_user})", 'updated');
				} else {
					add_settings_error('cptt_bale_messages', 'cptt_bale_error', 'خطا در اعتبارسنجی توکن.', 'error');
				}
			}
		}
	}

	/* ====================================================================
	 * CRON
	 * ==================================================================== */
	public function add_cron_schedules($schedules) {
		if (!isset($schedules['daily_morning'])) {
			$schedules['daily_morning'] = ['interval' => DAY_IN_SECONDS, 'display' => 'یک‌بار در روز'];
		}
		if (!isset($schedules['weekly_monday'])) {
			$schedules['weekly_monday'] = ['interval' => 7 * DAY_IN_SECONDS, 'display' => 'هفتگی (دوشنبه)'];
		}
		return $schedules;
	}

	public function maybe_schedule_crons() {
		if (!wp_next_scheduled('cptt_bale_daily_report')) {
			// شروع از فردا صبح ساعت ۸ (UTC → local Tehran). برای سادگی +24h از الان.
			wp_schedule_event(time() + 60, 'daily_morning', 'cptt_bale_daily_report');
		}
		if (!wp_next_scheduled('cptt_bale_weekly_report')) {
			wp_schedule_event(time() + 120, 'weekly_monday', 'cptt_bale_weekly_report');
		}
	}

	/* ====================================================================
	 * STATE MANAGEMENT
	 * ==================================================================== */
	private function get_states() {
		$all = get_option(self::STATE_OPT, []);
		if (!is_array($all)) $all = [];
		$now = time();
		$changed = false;
		foreach ($all as $k => $v) {
			if (!is_array($v) || empty($v['t']) || ($now - (int)$v['t']) > self::STATE_TTL) {
				unset($all[$k]); $changed = true;
			}
		}
		if ($changed) update_option(self::STATE_OPT, $all, false);
		return $all;
	}
	private function set_state($chat_id, $state, $data = []) {
		$all = $this->get_states();
		$all[(string)$chat_id] = ['s' => $state, 'd' => $data, 't' => time()];
		update_option(self::STATE_OPT, $all, false);
	}
	private function get_state($chat_id) {
		$all = $this->get_states();
		$k = (string)$chat_id;
		return isset($all[$k]) ? $all[$k] : null;
	}
	private function clear_state($chat_id) {
		$all = $this->get_states();
		unset($all[(string)$chat_id]);
		update_option(self::STATE_OPT, $all, false);
	}

	/* ====================================================================
	 * BALE API WRAPPERS
	 * ==================================================================== */
	public static function send_message($chat_id, $text, $reply_markup = null) {
		$settings = self::get_settings();
		$token = trim($settings['token']);
		if ($token === '') return false;
		$body = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'Markdown'];
		if ($reply_markup) $body['reply_markup'] = $reply_markup;
		$res = wp_remote_post(self::API_BASE . "{$token}/sendMessage", [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => wp_json_encode($body), 'data_format' => 'body',
			'sslverify' => false, 'timeout' => 15,
		]);
		if (is_wp_error($res)) return false;
		$body_decoded = json_decode(wp_remote_retrieve_body($res), true);
		return (is_array($body_decoded) && !empty($body_decoded['ok'])) ? ($body_decoded['result'] ?? true) : false;
	}

	public static function edit_message($chat_id, $message_id, $text, $reply_markup = null) {
		$settings = self::get_settings();
		$token = trim($settings['token']);
		if ($token === '') return false;
		$body = ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text, 'parse_mode' => 'Markdown'];
		if ($reply_markup) $body['reply_markup'] = $reply_markup;
		$res = wp_remote_post(self::API_BASE . "{$token}/editMessageText", [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => wp_json_encode($body), 'data_format' => 'body',
			'sslverify' => false, 'timeout' => 15,
		]);
		// اگر edit نشد (مثلاً پیام خیلی قدیمی)، یک پیام جدید بفرستیم
		if (is_wp_error($res)) {
			return self::send_message($chat_id, $text, $reply_markup);
		}
		$body_decoded = json_decode(wp_remote_retrieve_body($res), true);
		if (empty($body_decoded['ok'])) {
			return self::send_message($chat_id, $text, $reply_markup);
		}
		return $body_decoded['result'] ?? true;
	}

	public static function answer_callback($callback_id, $text = '') {
		$settings = self::get_settings();
		$token = trim($settings['token']);
		if ($token === '' || !$callback_id) return;
		$body = ['callback_query_id' => $callback_id];
		if ($text !== '') $body['text'] = $text;
		wp_remote_post(self::API_BASE . "{$token}/answerCallbackQuery", [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => wp_json_encode($body), 'data_format' => 'body',
			'sslverify' => false, 'timeout' => 8,
		]);
	}

	public static function get_file_url($file_id) {
		$settings = self::get_settings();
		$token = trim($settings['token']);
		if ($token === '' || !$file_id) return '';
		$res = wp_remote_get(self::API_BASE . "{$token}/getFile?file_id=" . urlencode($file_id), ['sslverify' => false, 'timeout' => 10]);
		if (is_wp_error($res)) return '';
		$body = json_decode(wp_remote_retrieve_body($res), true);
		if (empty($body['ok']) || empty($body['result']['file_path'])) return '';
		return 'https://tapi.bale.ai/file/bot' . $token . '/' . $body['result']['file_path'];
	}

	/**
	 * فایل را از بله دانلود می‌کند و در media library وردپرس آپلود می‌کند.
	 * @return array{id:int,url:string,name:string}|WP_Error
	 */
	public static function download_to_media($file_id, $file_name = '') {
		$url = self::get_file_url($file_id);
		if (!$url) return new WP_Error('no_file', 'فایل از بله قابل دریافت نیست.');
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url($url, 60);
		if (is_wp_error($tmp)) return $tmp;
		$file_array = [
			'name'     => $file_name ?: ('bale_' . time() . '.bin'),
			'tmp_name' => $tmp,
		];
		$id = media_handle_sideload($file_array, 0);
		if (is_wp_error($id)) { @unlink($tmp); return $id; }
		return ['id' => (int)$id, 'url' => wp_get_attachment_url($id), 'name' => $file_array['name']];
	}

	/* ====================================================================
	 * WEBHOOK ENTRY
	 * ==================================================================== */
	public function handle_webhook() {
		$input = file_get_contents('php://input');
		$data  = json_decode($input, true);
		if (!$data) { status_header(200); exit; }

		// --- Callback query ---
		if (!empty($data['callback_query'])) {
			$cq = $data['callback_query'];
			$chat_id = isset($cq['message']['chat']['id']) ? (int)$cq['message']['chat']['id'] : 0;
			$msg_id  = isset($cq['message']['message_id']) ? (int)$cq['message']['message_id'] : 0;
			$cb_data = isset($cq['data']) ? trim($cq['data']) : '';
			$cb_id   = isset($cq['id']) ? $cq['id'] : '';

			self::answer_callback($cb_id);

			$user = $chat_id ? $this->get_user_by_bale_id($chat_id) : null;
			if (!$user) {
				self::send_message($chat_id, "🔐 برای استفاده از ربات ابتدا حساب خود را وصل کنید.\nشماره موبایل ۱۱ رقمی خود را ارسال کنید.");
				status_header(200); exit;
			}
			$this->route_callback($chat_id, $msg_id, $cb_data, $user);
			status_header(200); exit;
		}

		// --- Regular message ---
		if (empty($data['message'])) { status_header(200); exit; }
		$message = $data['message'];
		$chat_id = isset($message['chat']['id']) ? (int)$message['chat']['id'] : 0;
		if (!$chat_id) { status_header(200); exit; }

		$text    = isset($message['text']) ? trim($message['text']) : '';
		$user    = $this->get_user_by_bale_id($chat_id);
		$state   = $this->get_state($chat_id);

		// Files in message
		$file_id = '';
		$file_name = '';
		if (!empty($message['document'])) {
			$file_id = $message['document']['file_id'] ?? '';
			$file_name = $message['document']['file_name'] ?? '';
		} elseif (!empty($message['photo'])) {
			$ph = end($message['photo']); // بزرگ‌ترین
			$file_id = $ph['file_id'] ?? '';
			$file_name = 'photo_' . time() . '.jpg';
		} elseif (!empty($message['video'])) {
			$file_id = $message['video']['file_id'] ?? '';
			$file_name = 'video_' . time() . '.mp4';
		} elseif (!empty($message['voice'])) {
			$file_id = $message['voice']['file_id'] ?? '';
			$file_name = 'voice_' . time() . '.ogg';
		} elseif (!empty($message['audio'])) {
			$file_id = $message['audio']['file_id'] ?? '';
			$file_name = $message['audio']['file_name'] ?? ('audio_' . time() . '.mp3');
		}

		// 1) Stateful flow (wizard)
		if ($user && $state) {
			$handled = $this->route_state($chat_id, $user, $state, $text, $file_id, $file_name);
			if ($handled) { status_header(200); exit; }
		}

		// 2) /start
		if ($text === '/start') {
			if ($user) {
				$this->send_welcome_menu($chat_id, $user);
			} else {
				self::send_message($chat_id,
					"🤝 *به ربات «هماهنگ» خوش آمدید*\n\n" .
					"این ربات یار شما در مدیریت پروژه‌هاست.\n\n" .
					"🔐 برای اتصال حساب کاربری، *شماره موبایل ۱۱ رقمی* خود را ارسال کنید.\n" .
					"_مثال:_ `09123456789`"
				);
			}
			status_header(200); exit;
		}

		// 3) Phone registration
		if (!$user && $text !== '' && preg_match('/^(09\d{9}|9\d{9})$/', $this->to_english_digits($text))) {
			$phone = $this->to_english_digits($text);
			if (strpos($phone, '09') !== 0) $phone = '0' . $phone;
			$found = $this->find_user_by_phone($phone);
			if ($found) {
				update_user_meta($found->ID, '_cptt_bale_chat_id', $chat_id);
				self::send_message($chat_id,
					"🎉 *اتصال حساب با موفقیت انجام شد!*\n\n" .
					"👤 نام: *" . esc_html($found->display_name) . "*\n" .
					"💼 نقش: *" . esc_html($this->get_role_label($found)) . "*"
				);
				$this->send_welcome_menu($chat_id, $found);
			} else {
				self::send_message($chat_id,
					"❌ شماره `" . esc_html($phone) . "` در سیستم ثبت نشده است.\n\n" .
					"💡 شماره موبایل خود را در پروفایل وب‌سایت ثبت کنید و دوباره تلاش کنید."
				);
			}
			status_header(200); exit;
		}

		// 4) Fallback
		if (!$user) {
			self::send_message($chat_id, "⚠️ برای استفاده از ربات، *شماره موبایل ۱۱ رقمی* خود را ارسال کنید (مثال: `09123456789`).");
		} else {
			$this->send_welcome_menu($chat_id, $user);
		}
		status_header(200); exit;
	}

	/* ====================================================================
	 * CALLBACK ROUTER
	 * ==================================================================== */
	private function route_callback($chat_id, $msg_id, $data, $user) {
		$role = $this->get_user_role($user);

		// Common: back to menu
		if ($data === 'back_to_menu') {
			$this->clear_state($chat_id);
			$this->edit_or_send($chat_id, $msg_id, $this->welcome_text($user), $this->welcome_keyboard($user));
			return;
		}
		if ($data === 'cancel_wizard') {
			$this->clear_state($chat_id);
			$this->edit_or_send($chat_id, $msg_id, "↩️ عملیات لغو شد.", ['inline_keyboard' => [[['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']]]]);
			return;
		}

		// Customer
		if ($role === 'customer') {
			if ($data === 'cust_projects')              { $this->cust_projects_list($chat_id, $msg_id, $user); return; }
			if ($data === 'cust_tasks')                 { $this->cust_pending_tasks($chat_id, $msg_id, $user); return; }
			if ($data === 'cust_invoices')              { $this->cust_invoices_list($chat_id, $msg_id, $user); return; }
			if (strpos($data, 'cust_view_proj_') === 0) { $this->cust_view_project($chat_id, $msg_id, (int)substr($data, 15), $user); return; }
			if (strpos($data, 'cust_ut_reply_') === 0)  { $this->cust_start_task_reply($chat_id, $msg_id, substr($data, 14), $user); return; }
			if (strpos($data, 'cust_ut_skip_') === 0)   { $this->cust_finish_task_skip_file($chat_id, $msg_id, $user); return; }
		}

		// Expert
		if ($role === 'expert' || $role === 'admin') {
			if ($data === 'expert_projects')            { $this->expert_projects_list($chat_id, $msg_id, $user); return; }
			if ($data === 'expert_stats')               { $this->expert_stats($chat_id, $msg_id, $user); return; }
			if ($data === 'expert_payouts')             { $this->expert_payouts($chat_id, $msg_id, $user); return; }
			if ($data === 'expert_notif_settings')      { $this->expert_notif_settings($chat_id, $msg_id, $user); return; }
			if (strpos($data, 'toggle_notif_') === 0)   { $this->toggle_notif_meta($chat_id, $msg_id, $user, substr($data, 13)); return; }
			if (strpos($data, 'view_proj_') === 0)      { $this->expert_view_project($chat_id, $msg_id, (int)substr($data, 10), $user); return; }
			if (strpos($data, 'proj_steps_') === 0)     { $this->expert_project_steps($chat_id, $msg_id, (int)substr($data, 11), $user); return; }
			if (strpos($data, 'step_view_') === 0)      {
				$parts = explode('_', substr($data, 10), 2);
				$this->expert_step_view($chat_id, $msg_id, (int)$parts[0], $parts[1] ?? '', $user); return;
			}
			if (strpos($data, 'set_status_') === 0) {
				$parts = explode('_', substr($data, 11), 3);
				$this->expert_set_step_status($chat_id, $msg_id, (int)$parts[0], $parts[1] ?? '', $parts[2] ?? '', $user); return;
			}
			if (strpos($data, 'chk_toggle_') === 0) {
				$parts = explode('_', substr($data, 11), 3);
				$this->expert_toggle_checklist($chat_id, $msg_id, (int)$parts[0], $parts[1] ?? '', $parts[2] ?? '', $user); return;
			}
			if (strpos($data, 'proj_chat_') === 0)      { $this->expert_start_chat($chat_id, $msg_id, (int)substr($data, 10), $user); return; }
			if (strpos($data, 'proj_mute_') === 0)      { $this->show_mute_options($chat_id, $msg_id, (int)substr($data, 10), $user); return; }
			if (strpos($data, 'mute_') === 0) {
				$parts = explode('_', substr($data, 5), 2); // duration_pid
				$this->apply_mute($chat_id, $msg_id, (int)($parts[1] ?? 0), $parts[0] ?? '', $user); return;
			}
		}

		// Admin
		if ($role === 'admin') {
			if ($data === 'admin_stats')               { $this->admin_global_stats($chat_id, $msg_id); return; }
			if ($data === 'admin_today')               { $this->admin_today_summary($chat_id, $msg_id); return; }
			if ($data === 'admin_overdue')             { $this->admin_overdue_list($chat_id, $msg_id); return; }
			if ($data === 'admin_unsettled')           { $this->admin_unsettled_list($chat_id, $msg_id); return; }
			if ($data === 'admin_projects')            { $this->admin_recent_projects($chat_id, $msg_id); return; }
			if ($data === 'admin_experts')             { $this->admin_experts_list($chat_id, $msg_id); return; }
			if ($data === 'admin_create_proj_start')   { $this->wizard_create_proj_start($chat_id, $msg_id, $user); return; }
			if (strpos($data, 'wiz_cp_tpl_') === 0)    { $this->wizard_create_proj_pick_tpl($chat_id, $msg_id, substr($data, 11), $user); return; }
			if (strpos($data, 'wiz_cp_exp_') === 0)    { $this->wizard_create_proj_pick_expert($chat_id, $msg_id, substr($data, 11), $user); return; }
			if ($data === 'wiz_cp_confirm')            { $this->wizard_create_proj_confirm($chat_id, $msg_id, $user); return; }
			if ($data === 'admin_broadcast_start')     { $this->wizard_broadcast_start($chat_id, $msg_id, $user); return; }
			if ($data === 'admin_reminders_trigger')   { $this->trigger_manual_reminders($chat_id, $msg_id); return; }
		}

		// Unknown
		$this->edit_or_send($chat_id, $msg_id, "⚠️ این عملیات شناخته نشد یا منقضی شده است.", ['inline_keyboard' => [[['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']]]]);
	}

	/* ====================================================================
	 * STATE ROUTER (wizard / reply collection)
	 * ==================================================================== */
	private function route_state($chat_id, $user, $state, $text, $file_id, $file_name) {
		$s = isset($state['s']) ? $state['s'] : '';
		$d = isset($state['d']) && is_array($state['d']) ? $state['d'] : [];

		// Wizard: create project (admin)
		if ($s === 'wiz_cp_title' && $text !== '') {
			$d['title'] = sanitize_text_field($text);
			$this->set_state($chat_id, 'wiz_cp_phone', $d);
			self::send_message($chat_id, "📞 *مرحله ۲ از ۵*\n\nشماره موبایل *مشتری* را ارسال کنید (مثال: `09123456789`)", $this->kb_cancel());
			return true;
		}
		if ($s === 'wiz_cp_phone' && $text !== '') {
			$phone = $this->to_english_digits($text);
			if (strpos($phone, '09') !== 0 && strpos($phone, '9') === 0) $phone = '0' . $phone;
			if (!preg_match('/^09\d{9}$/', $phone)) {
				self::send_message($chat_id, "❌ شماره نامعتبر است. لطفاً شماره ۱۱ رقمی صحیح وارد کنید.");
				return true;
			}
			$cust = $this->find_user_by_phone($phone);
			if (!$cust) {
				self::send_message($chat_id, "⚠️ مشتری با این شماره در سیستم نیست. شماره دیگری وارد کنید یا /cancel ⇒ لغو.");
				return true;
			}
			$d['client_id']   = (int)$cust->ID;
			$d['client_name'] = $cust->display_name;
			$this->set_state($chat_id, 'wiz_cp_pick_tpl', $d);
			$this->wizard_create_proj_show_templates($chat_id, 0, $user);
			return true;
		}
		if ($s === 'wiz_cp_cost' && $text !== '') {
			$cost = (float) preg_replace('/[^\d.]/', '', $this->to_english_digits($text));
			$d['cost'] = $cost;
			$this->set_state($chat_id, 'wiz_cp_pick_expert', $d);
			$this->wizard_create_proj_show_experts($chat_id, 0, $user);
			return true;
		}

		// Customer task reply: collect text
		if ($s === 'cust_ut_reply_text') {
			if ($text === '' && $file_id === '') return true;
			$d['text'] = $text;
			if ($file_id) $d['file_id'] = $file_id;
			if ($file_name) $d['file_name'] = $file_name;
			// اگر هم متن داده هم فایل، در یک پیام، ذخیره کن
			$this->cust_save_task_reply($chat_id, $user, $d);
			return true;
		}
		if ($s === 'cust_ut_reply_file') {
			if ($file_id === '') {
				if ($text !== '' && trim($text) === '/skip') {
					$this->cust_save_task_reply($chat_id, $user, $d);
				} else {
					self::send_message($chat_id, "📎 فایل خود را آپلود کنید یا برای رد کردن `/skip` ارسال کنید.");
				}
				return true;
			}
			$d['file_id'] = $file_id;
			$d['file_name'] = $file_name;
			$this->cust_save_task_reply($chat_id, $user, $d);
			return true;
		}

		// Expert: project chat
		if ($s === 'expert_chat_msg') {
			if ($text === '' && $file_id === '') return true;
			$this->expert_save_chat_message($chat_id, $user, (int)($d['pid'] ?? 0), $text, $file_id, $file_name);
			return true;
		}

		// Broadcast
		if ($s === 'admin_broadcast_msg' && $text !== '') {
			$this->clear_state($chat_id);
			$this->send_broadcast($text);
			self::send_message($chat_id, "📢 پیام همگانی شما برای همه‌ی کاربران متصل به ربات ارسال شد.",
				['inline_keyboard' => [[['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']]]]);
			return true;
		}

		// Cancel
		if (in_array($text, ['/cancel', 'لغو'], true)) {
			$this->clear_state($chat_id);
			self::send_message($chat_id, "↩️ عملیات لغو شد.",
				['inline_keyboard' => [[['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']]]]);
			return true;
		}

		return false;
	}

	/* ====================================================================
	 * WELCOME MENU
	 * ==================================================================== */
	private function welcome_text($user) {
		$role  = $this->get_user_role($user);
		$label = $this->get_role_label($user);
		$emoji = $role === 'admin' ? '👑' : ($role === 'expert' ? '🧑‍💼' : '👋');
		return "{$emoji} *سلام " . esc_html($user->display_name) . " عزیز*\n\n" .
		       "به پنل _" . esc_html($label) . "_ ربات «هماهنگ» خوش آمدید.\n" .
		       "از منوی زیر بخش مورد نظر را انتخاب کنید 👇";
	}
	private function welcome_keyboard($user) {
		$role = $this->get_user_role($user);
		$kb   = [];
		if ($role === 'admin') {
			$kb[] = [['text' => '📊 آمار کل سیستم', 'callback_data' => 'admin_stats']];
			$kb[] = [
				['text' => '🌅 وضعیت امروز', 'callback_data' => 'admin_today'],
				['text' => '⏰ کارهای معوق', 'callback_data' => 'admin_overdue'],
			];
			$kb[] = [
				['text' => '💸 تسویه‌های در انتظار', 'callback_data' => 'admin_unsettled'],
				['text' => '📁 پروژه‌های اخیر', 'callback_data' => 'admin_projects'],
			];
			$kb[] = [
				['text' => '➕ ایجاد پروژه جدید', 'callback_data' => 'admin_create_proj_start'],
				['text' => '👥 لیست کارشناسان', 'callback_data' => 'admin_experts'],
			];
			$kb[] = [
				['text' => '📢 پیام همگانی', 'callback_data' => 'admin_broadcast_start'],
				['text' => '⏰ ارسال یادآوری', 'callback_data' => 'admin_reminders_trigger'],
			];
			$kb[] = [['text' => '⚙ تنظیمات اعلان‌های من', 'callback_data' => 'expert_notif_settings']];
		} elseif ($role === 'expert') {
			$kb[] = [['text' => '📁 پروژه‌های فعال من', 'callback_data' => 'expert_projects']];
			$kb[] = [
				['text' => '📊 آمار عملکرد من', 'callback_data' => 'expert_stats'],
				['text' => '💰 موجودی و تسویه‌ها', 'callback_data' => 'expert_payouts'],
			];
			$kb[] = [['text' => '⚙ تنظیمات اعلان‌های من', 'callback_data' => 'expert_notif_settings']];
		} else {
			$kb[] = [['text' => '📁 پروژه‌های من', 'callback_data' => 'cust_projects']];
			$kb[] = [
				['text' => '📝 تسک‌های در انتظار من', 'callback_data' => 'cust_tasks'],
				['text' => '📄 پیش‌فاکتورها', 'callback_data' => 'cust_invoices'],
			];
		}
		return ['inline_keyboard' => $kb];
	}
	private function send_welcome_menu($chat_id, $user) {
		self::send_message($chat_id, $this->welcome_text($user), $this->welcome_keyboard($user));
	}

	/* ====================================================================
	 * CUSTOMER FLOWS
	 * ==================================================================== */
	private function cust_projects_list($chat_id, $msg_id, $user) {
		$projects = $this->get_user_projects($user, 'customer', 15);
		if (empty($projects)) {
			$this->edit_or_send($chat_id, $msg_id, "📭 شما هنوز پروژه‌ای ثبت‌شده ندارید.", $this->kb_back());
			return;
		}
		$kb = [];
		foreach ($projects as $p) {
			$pct = $this->project_progress_percent($p->ID);
			$kb[] = [['text' => '📁 ' . get_the_title($p->ID) . " — {$pct}%", 'callback_data' => 'cust_view_proj_' . $p->ID]];
		}
		$kb[] = [['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']];
		$this->edit_or_send($chat_id, $msg_id, "📁 *پروژه‌های شما*\n\nبرای دیدن جزئیات روی نام پروژه کلیک کنید 👇", ['inline_keyboard' => $kb]);
	}

	private function cust_view_project($chat_id, $msg_id, $pid, $user) {
		$p = get_post($pid);
		if (!$p || (int)get_post_meta($pid, '_cptt_client_user_id', true) !== (int)$user->ID) {
			$this->edit_or_send($chat_id, $msg_id, "⚠️ این پروژه برای شما قابل دسترسی نیست.", $this->kb_back());
			return;
		}
		$msg = $this->render_project_summary($pid);
		$kb = [];
		// تسک‌های در انتظار این پروژه
		$tasks = $this->collect_pending_user_tasks($pid);
		foreach ($tasks as $t) {
			$kb[] = [['text' => '📝 پاسخ به: ' . $this->shorten($t['title'], 30), 'callback_data' => 'cust_ut_reply_' . $t['ref']]];
		}
		$kb[] = [['text' => '◀ بازگشت', 'callback_data' => 'cust_projects'], ['text' => '🏠 منو', 'callback_data' => 'back_to_menu']];
		$this->edit_or_send($chat_id, $msg_id, $msg, ['inline_keyboard' => $kb]);
	}

	private function cust_pending_tasks($chat_id, $msg_id, $user) {
		$projects = $this->get_user_projects($user, 'customer', 50);
		$rows = [];
		foreach ($projects as $p) {
			$tasks = $this->collect_pending_user_tasks($p->ID);
			foreach ($tasks as $t) {
				$rows[] = ['pid' => $p->ID, 'p_title' => get_the_title($p->ID), 'task' => $t];
			}
		}
		if (empty($rows)) {
			$this->edit_or_send($chat_id, $msg_id, "🎉 شما هیچ تسک در انتظاری ندارید.", $this->kb_back());
			return;
		}
		$msg = "📝 *تسک‌های در انتظار پاسخ شما:*\n\n";
		$kb = [];
		foreach ($rows as $r) {
			$msg .= "• *" . esc_html($r['p_title']) . "* — " . esc_html($r['task']['title']) . "\n";
			$kb[] = [['text' => '📝 پاسخ: ' . $this->shorten($r['task']['title'], 24), 'callback_data' => 'cust_ut_reply_' . $r['task']['ref']]];
		}
		$kb[] = [['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']];
		$this->edit_or_send($chat_id, $msg_id, $msg, ['inline_keyboard' => $kb]);
	}

	private function cust_invoices_list($chat_id, $msg_id, $user) {
		$projects = $this->get_user_projects($user, 'customer', 15);
		if (empty($projects)) { $this->edit_or_send($chat_id, $msg_id, "📭 پیش‌فاکتوری یافت نشد.", $this->kb_back()); return; }
		$kb = [];
		foreach ($projects as $p) {
			$url = wp_nonce_url(admin_url('admin-post.php?action=cptt_view_invoice&project_id=' . $p->ID), 'cptt_view_invoice_' . $p->ID);
			$kb[] = [['text' => '📄 ' . get_the_title($p->ID), 'url' => $url]];
		}
		$kb[] = [['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']];
		$this->edit_or_send($chat_id, $msg_id, "📄 *پیش‌فاکتورهای شما*\n\nبا کلیک روی هرکدام نسخه‌ی چاپی باز می‌شود 👇", ['inline_keyboard' => $kb]);
	}

	private function cust_start_task_reply($chat_id, $msg_id, $ref, $user) {
		// $ref = pid:step_id:task_id
		$parts = explode(':', $ref);
		if (count($parts) !== 3) { $this->edit_or_send($chat_id, $msg_id, "⚠️ شناسه تسک نامعتبر است.", $this->kb_back()); return; }
		$pid = (int)$parts[0]; $step_id = $parts[1]; $task_id = $parts[2];
		if ((int)get_post_meta($pid, '_cptt_client_user_id', true) !== (int)$user->ID) {
			$this->edit_or_send($chat_id, $msg_id, "⚠️ دسترسی ندارید.", $this->kb_back()); return;
		}
		$this->set_state($chat_id, 'cust_ut_reply_text', ['pid' => $pid, 'step_id' => $step_id, 'task_id' => $task_id]);
		$this->edit_or_send($chat_id, $msg_id,
			"✍️ *پاسخ خود را ارسال کنید*\n\n" .
			"می‌توانید *متن* بنویسید، *فایل* بفرستید، یا هر دو را در یک پیام ارسال کنید.\n\n" .
			"_برای لغو_: ارسال `/cancel`",
			$this->kb_cancel()
		);
	}

	private function cust_save_task_reply($chat_id, $user, $d) {
		$pid = (int)($d['pid'] ?? 0); $step_id = $d['step_id'] ?? ''; $task_id = $d['task_id'] ?? '';
		$text = (string)($d['text'] ?? '');
		$file_id = $d['file_id'] ?? '';
		$file_name = $d['file_name'] ?? '';

		$file_data = null;
		if ($file_id) {
			$res = self::download_to_media($file_id, $file_name);
			if (!is_wp_error($res)) $file_data = $res;
		}

		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!is_array($steps)) { self::send_message($chat_id, "⚠️ پروژه پیدا نشد."); $this->clear_state($chat_id); return; }
		$saved = false;
		foreach ($steps as &$st) {
			if (($st['id'] ?? '') !== $step_id) continue;
			if (empty($st['user_tasks']) || !is_array($st['user_tasks'])) continue;
			foreach ($st['user_tasks'] as &$ut) {
				if (($ut['id'] ?? '') !== $task_id) continue;
				$ut['done'] = 1;
				$ut['response'] = $text;
				if ($file_data) {
					$ut['response_file_url']  = $file_data['url'];
					$ut['response_file_name'] = $file_data['name'];
					$rf = isset($ut['response_files']) && is_array($ut['response_files']) ? $ut['response_files'] : [];
					$rf[] = ['url' => $file_data['url'], 'name' => $file_data['name']];
					$ut['response_files'] = $rf;
				}
				$now = (int)current_time('timestamp', true);
				$ut['completed_at'] = $now;
				$ut['completed_at_fa'] = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now);
				$ut['completed_by'] = (int)$user->ID;
				$saved = true;
				break;
			}
			unset($ut);
			break;
		}
		unset($st);

		if (!$saved) { self::send_message($chat_id, "⚠️ تسک پیدا نشد."); $this->clear_state($chat_id); return; }
		update_post_meta($pid, '_cptt_steps', $steps);
		$now = (int)current_time('timestamp', true);
		update_post_meta($pid, '_cptt_last_update', $now);
		update_post_meta($pid, '_cptt_last_update_fa', class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now));

		$this->clear_state($chat_id);
		self::send_message($chat_id,
			"✅ *پاسخ شما با موفقیت ثبت شد.*\n\nسپاس از همکاری شما 🙏",
			['inline_keyboard' => [
				[['text' => '📝 تسک‌های دیگر', 'callback_data' => 'cust_tasks']],
				[['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']],
			]]
		);

		// اعلان به کارشناسان پروژه
		if (class_exists('CPTT_Expert') && class_exists('CPTT_Core')) {
			$expert_ids = CPTT_Core::get_project_expert_ids($pid);
			foreach ($expert_ids as $eid) {
				CPTT_Expert::instance()->insert_notification(
					$eid, 'user_task_done',
					'مشتری به یکی از تسک‌های پروژه «' . get_the_title($pid) . '» پاسخ داد.',
					$pid, CPTT_Expert::dashboard_url() . '#project-' . $pid
				);
			}
		}
	}

	private function cust_finish_task_skip_file($chat_id, $msg_id, $user) {
		$state = $this->get_state($chat_id);
		if (!$state) { $this->edit_or_send($chat_id, $msg_id, "⚠️ نشست منقضی شده.", $this->kb_back()); return; }
		$this->cust_save_task_reply($chat_id, $user, $state['d']);
	}

	/* ====================================================================
	 * EXPERT FLOWS
	 * ==================================================================== */
	private function expert_projects_list($chat_id, $msg_id, $user) {
		$projects = $this->get_user_projects($user, 'expert', 15);
		if (empty($projects)) { $this->edit_or_send($chat_id, $msg_id, "📭 پروژه‌ای به شما واگذار نشده.", $this->kb_back()); return; }
		$kb = [];
		foreach ($projects as $p) {
			$pct = $this->project_progress_percent($p->ID);
			$icon = $pct >= 100 ? '✅' : ($pct >= 50 ? '⏳' : '🔵');
			$kb[] = [['text' => $icon . ' ' . get_the_title($p->ID) . " — {$pct}%", 'callback_data' => 'view_proj_' . $p->ID]];
		}
		$kb[] = [['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']];
		$this->edit_or_send($chat_id, $msg_id, "📁 *پروژه‌های فعال شما*\n\nبرای دیدن جزئیات روی هر پروژه کلیک کنید 👇", ['inline_keyboard' => $kb]);
	}

	private function expert_view_project($chat_id, $msg_id, $pid, $user) {
		$msg = $this->render_project_summary($pid);
		$muted = $this->is_project_muted($user->ID, $pid);
		$mute_label = $muted ? '🔕 پروژه Mute است' : '🔔 Mute این پروژه';
		$kb = [
			[
				['text' => '📋 مدیریت مراحل', 'callback_data' => 'proj_steps_' . $pid],
				['text' => '💬 ارسال پیام چت', 'callback_data' => 'proj_chat_' . $pid],
			],
			[
				['text' => $mute_label, 'callback_data' => 'proj_mute_' . $pid],
			],
			[
				['text' => '◀ پروژه‌های من', 'callback_data' => 'expert_projects'],
				['text' => '🏠 منو', 'callback_data' => 'back_to_menu'],
			],
		];
		$this->edit_or_send($chat_id, $msg_id, $msg, ['inline_keyboard' => $kb]);
	}

	private function expert_project_steps($chat_id, $msg_id, $pid, $user) {
		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!is_array($steps) || empty($steps)) { $this->edit_or_send($chat_id, $msg_id, "❌ این پروژه مرحله‌ای ندارد.", $this->kb_back()); return; }
		$kb = [];
		foreach ($steps as $i => $s) {
			$st = $s['status'] ?? 'todo';
			$icon = $st === 'done' ? '✅' : ($st === 'current' ? '⏳' : '⚪');
			$step_id = $s['id'] ?? '';
			if (!$step_id) continue;
			$kb[] = [['text' => $icon . ' ' . ($i+1) . '. ' . $this->shorten($s['title'] ?? '', 35), 'callback_data' => 'step_view_' . $pid . '_' . $step_id]];
		}
		$kb[] = [['text' => '◀ بازگشت', 'callback_data' => 'view_proj_' . $pid]];
		$this->edit_or_send($chat_id, $msg_id, "📋 *مراحل پروژه:* " . esc_html(get_the_title($pid)) . "\n\nبرای مدیریت روی هر مرحله کلیک کنید 👇", ['inline_keyboard' => $kb]);
	}

	private function expert_step_view($chat_id, $msg_id, $pid, $step_id, $user) {
		$steps = get_post_meta($pid, '_cptt_steps', true);
		$step = null;
		if (is_array($steps)) foreach ($steps as $s) if (($s['id'] ?? '') === $step_id) { $step = $s; break; }
		if (!$step) { $this->edit_or_send($chat_id, $msg_id, "❌ مرحله یافت نشد.", $this->kb_back()); return; }
		$st = $step['status'] ?? 'todo';
		$icon = $st === 'done' ? '✅' : ($st === 'current' ? '⏳' : '⚪');
		$msg = "📋 *مرحله:* " . esc_html($step['title'] ?? '') . "\n";
		$msg .= "🏷 وضعیت: {$icon} " . $this->status_label($st) . "\n";
		if (!empty($step['due_at_fa'])) $msg .= "📅 مهلت: " . esc_html($step['due_at_fa']) . "\n";
		$cl = isset($step['checklist']) && is_array($step['checklist']) ? $step['checklist'] : [];
		if (!empty($cl)) {
			$total = count($cl); $done = 0;
			foreach ($cl as $c) if (!empty($c['done'])) $done++;
			$msg .= "📝 چک‌لیست: {$done} از {$total}\n";
		}
		$kb = [];
		// تغییر وضعیت
		$kb[] = [
			['text' => $st==='current' ? '⏳ (فعلی)' : '⏳ در حال انجام', 'callback_data' => 'set_status_' . $pid . '_' . $step_id . '_current'],
			['text' => $st==='done' ? '✅ (فعلی)' : '✅ انجام‌شده', 'callback_data' => 'set_status_' . $pid . '_' . $step_id . '_done'],
		];
		// چک‌لیست (حداکثر ۸ تا)
		$shown = 0;
		foreach ($cl as $idx => $c) {
			if ($shown >= 8) break;
			if (empty($c['id'])) continue;
			$mark = !empty($c['done']) ? '☑' : '⬜';
			$kb[] = [['text' => $mark . ' ' . $this->shorten($c['text'] ?? '', 38), 'callback_data' => 'chk_toggle_' . $pid . '_' . $step_id . '_' . $c['id']]];
			$shown++;
		}
		$kb[] = [
			['text' => '◀ مراحل', 'callback_data' => 'proj_steps_' . $pid],
			['text' => '🏠 منو', 'callback_data' => 'back_to_menu'],
		];
		$this->edit_or_send($chat_id, $msg_id, $msg, ['inline_keyboard' => $kb]);
	}

	private function expert_set_step_status($chat_id, $msg_id, $pid, $step_id, $status, $user) {
		if (!in_array($status, ['todo', 'current', 'done'], true)) $status = 'current';
		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!is_array($steps)) { $this->edit_or_send($chat_id, $msg_id, "❌ پروژه پیدا نشد.", $this->kb_back()); return; }
		$found = false;
		foreach ($steps as &$s) {
			if (($s['id'] ?? '') === $step_id) {
				$s['status'] = $status;
				$s['updated_at'] = (int)current_time('timestamp', true);
				$s['updated_at_fa'] = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($s['updated_at']) : date('Y-m-d H:i', $s['updated_at']);
				$s['updated_by'] = (int)$user->ID;
				$found = true;
				break;
			}
		}
		unset($s);
		if ($found) {
			update_post_meta($pid, '_cptt_steps', $steps);
			$now = (int)current_time('timestamp', true);
			update_post_meta($pid, '_cptt_last_update', $now);
			update_post_meta($pid, '_cptt_last_update_fa', class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now));
			if (class_exists('CPTT_Expert')) {
				CPTT_Expert::instance()->notify_project_experts($pid, $user->ID, 'step_completed', 'وضعیت مرحله‌ای در پروژه «' . get_the_title($pid) . '» توسط ' . $user->display_name . ' تغییر کرد.', CPTT_Expert::dashboard_url() . '#project-' . $pid);
			}
		}
		$this->expert_step_view($chat_id, $msg_id, $pid, $step_id, $user);
	}

	private function expert_toggle_checklist($chat_id, $msg_id, $pid, $step_id, $chk_id, $user) {
		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!is_array($steps)) { $this->edit_or_send($chat_id, $msg_id, "❌ پروژه پیدا نشد.", $this->kb_back()); return; }
		foreach ($steps as &$s) {
			if (($s['id'] ?? '') !== $step_id) continue;
			if (empty($s['checklist']) || !is_array($s['checklist'])) continue;
			foreach ($s['checklist'] as &$c) {
				if (($c['id'] ?? '') !== $chk_id) continue;
				$now = (int)current_time('timestamp', true);
				if (!empty($c['done'])) {
					$c['done'] = 0;
					unset($c['done_at'], $c['done_at_fa'], $c['done_by']);
				} else {
					$c['done'] = 1;
					$c['done_at'] = $now;
					$c['done_at_fa'] = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now);
					$c['done_by'] = (int)$user->ID;
				}
				break;
			}
			unset($c);
			// اگر همه‌ی چک‌ها done شدند، خود مرحله را done کنیم
			$total = 0; $done = 0;
			foreach ($s['checklist'] as $cc) { if (!empty($cc['text'])) { $total++; if (!empty($cc['done'])) $done++; } }
			if ($total > 0 && $done >= $total) $s['status'] = 'done';
			break;
		}
		unset($s);
		update_post_meta($pid, '_cptt_steps', $steps);
		$now = (int)current_time('timestamp', true);
		update_post_meta($pid, '_cptt_last_update', $now);
		update_post_meta($pid, '_cptt_last_update_fa', class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now));
		$this->expert_step_view($chat_id, $msg_id, $pid, $step_id, $user);
	}

	private function expert_start_chat($chat_id, $msg_id, $pid, $user) {
		$this->set_state($chat_id, 'expert_chat_msg', ['pid' => $pid]);
		$this->edit_or_send($chat_id, $msg_id,
			"💬 *ارسال پیام در چت پروژه*\n_" . esc_html(get_the_title($pid)) . "_\n\n" .
			"متن پیام را بنویسید یا فایل بفرستید (یا هر دو در یک پیام).\n\n" .
			"_برای لغو_: ارسال `/cancel`",
			$this->kb_cancel()
		);
	}

	private function expert_save_chat_message($chat_id, $user, $pid, $text, $file_id, $file_name) {
		$file_data = null;
		if ($file_id) {
			$res = self::download_to_media($file_id, $file_name);
			if (!is_wp_error($res)) $file_data = $res;
		}
		$msgs = get_post_meta($pid, '_cptt_messages', true);
		if (!is_array($msgs)) $msgs = [];
		$content = $text;
		if ($file_data) {
			$content .= "\n\n<a href=\"" . esc_url($file_data['url']) . "\" target=\"_blank\" class=\"cptt-chat-file-link\">📎 " . esc_html($file_data['name']) . "</a>";
		}
		$now = (int)current_time('timestamp', true);
		$msgs[] = [
			'id'        => 'm_' . wp_generate_uuid4(),
			'sender_id' => (int)$user->ID,
			'recipient' => 'all',
			'content'   => $content,
			'time'      => $now,
			'time_fa'   => class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now),
			'via'       => 'bale',
		];
		update_post_meta($pid, '_cptt_messages', $msgs);
		update_post_meta($pid, '_cptt_last_update', $now);
		update_post_meta($pid, '_cptt_last_update_fa', class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now));

		if (class_exists('CPTT_Expert')) {
			CPTT_Expert::instance()->notify_project_experts($pid, $user->ID, 'project_chat', $user->display_name . ' پیامی در چت پروژه «' . get_the_title($pid) . '» فرستاد.', CPTT_Expert::dashboard_url() . '#project-' . $pid . '#chat-' . $pid);
		}
		$this->clear_state($chat_id);
		self::send_message($chat_id, "✅ پیام شما در چت پروژه ثبت شد.", ['inline_keyboard' => [
			[['text' => '◀ بازگشت به پروژه', 'callback_data' => 'view_proj_' . $pid]],
			[['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']],
		]]);
	}

	private function show_mute_options($chat_id, $msg_id, $pid, $user) {
		$kb = [
			[['text' => '🔕 ۱ ساعت', 'callback_data' => 'mute_1h_' . $pid], ['text' => '🔕 ۱ روز', 'callback_data' => 'mute_1d_' . $pid]],
			[['text' => '🔕 تا اطلاع ثانوی', 'callback_data' => 'mute_inf_' . $pid]],
			[['text' => '🔔 لغو Mute (فعال‌سازی)', 'callback_data' => 'mute_off_' . $pid]],
			[['text' => '◀ بازگشت', 'callback_data' => 'view_proj_' . $pid]],
		];
		$this->edit_or_send($chat_id, $msg_id, "🔕 *Mute پروژه:* " . esc_html(get_the_title($pid)) . "\n\nمدت زمان موردنظر را انتخاب کنید 👇", ['inline_keyboard' => $kb]);
	}

	private function apply_mute($chat_id, $msg_id, $pid, $dur, $user) {
		$mutes = get_user_meta($user->ID, '_cptt_bale_mutes', true);
		if (!is_array($mutes)) $mutes = [];
		$now = time();
		if ($dur === 'off') {
			unset($mutes[$pid]);
			$txt = "🔔 اعلان‌های این پروژه دوباره فعال شد.";
		} elseif ($dur === '1h') { $mutes[$pid] = $now + HOUR_IN_SECONDS;  $txt = "🔕 اعلان‌ها برای ۱ ساعت Mute شد."; }
		elseif ($dur === '1d')   { $mutes[$pid] = $now + DAY_IN_SECONDS;   $txt = "🔕 اعلان‌ها برای ۱ روز Mute شد."; }
		else                     { $mutes[$pid] = 0;                       $txt = "🔕 اعلان‌ها تا اطلاع ثانوی Mute شد."; }
		update_user_meta($user->ID, '_cptt_bale_mutes', $mutes);
		$this->edit_or_send($chat_id, $msg_id, $txt, ['inline_keyboard' => [
			[['text' => '◀ بازگشت به پروژه', 'callback_data' => 'view_proj_' . $pid]],
			[['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']],
		]]);
	}

	private function is_project_muted($user_id, $pid) {
		$mutes = get_user_meta($user_id, '_cptt_bale_mutes', true);
		if (!is_array($mutes) || !isset($mutes[$pid])) return false;
		$until = (int)$mutes[$pid];
		if ($until === 0) return true; // infinity
		if ($until > time()) return true;
		// expired
		unset($mutes[$pid]);
		update_user_meta($user_id, '_cptt_bale_mutes', $mutes);
		return false;
	}

	private function expert_stats($chat_id, $msg_id, $user) {
		$projects = $this->get_user_projects($user, 'expert', -1);
		$total = count($projects); $done = 0; $in_progress = 0; $overdue = 0;
		$now = current_time('timestamp', true);
		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			$tt = is_array($steps) ? count($steps) : 0; $dd = 0;
			if (is_array($steps)) {
				foreach ($steps as $s) {
					if (($s['status'] ?? 'todo') === 'done') $dd++;
					elseif (!empty($s['due_at']) && (int)$s['due_at'] < $now) $overdue++;
				}
			}
			if ($tt > 0 && $dd >= $tt) $done++; else $in_progress++;
		}
		$msg = "📊 *آمار عملکرد شما*\n\n";
		$msg .= "📁 کل پروژه‌ها: *{$total}*\n";
		$msg .= "✅ تکمیل‌شده: *{$done}*\n";
		$msg .= "⏳ در حال انجام: *{$in_progress}*\n";
		$msg .= "⚠️ مراحل معوق: *{$overdue}*";
		$this->edit_or_send($chat_id, $msg_id, $msg, $this->kb_back());
	}

	private function expert_payouts($chat_id, $msg_id, $user) {
		$projects = $this->get_user_projects($user, 'expert', -1);
		$share = 0; $paid = 0; $settled = 0;
		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) continue;
			foreach ($steps as $s) {
				$ae = (int)($s['assigned_expert_id'] ?? 0);
				if ($ae !== (int)$user->ID) continue;
				$share += (float)($s['expert_share'] ?? 0);
				$paid  += (float)($s['expert_paid'] ?? 0);
				if (!empty($s['step_settled'])) $settled += (float)($s['expert_paid'] ?? 0);
			}
		}
		$remain = max(0, $share - $paid);
		$msg = "💰 *وضعیت تسویه‌حساب شما*\n\n";
		$msg .= "💼 سهم کل شما: *" . number_format($share) . " تومان*\n";
		$msg .= "💵 پرداخت‌شده تاکنون: *" . number_format($paid) . " تومان*\n";
		$msg .= "✅ از این مقدار تسویه نهایی: *" . number_format($settled) . " تومان*\n";
		$msg .= "⏳ مانده طلب: *" . number_format($remain) . " تومان*";
		$this->edit_or_send($chat_id, $msg_id, $msg, $this->kb_back());
	}

	private function expert_notif_settings($chat_id, $msg_id, $user) {
		$labels = [
			'_cptt_bale_notify_assign'  => 'واگذاری پروژه',
			'_cptt_bale_notify_chat'    => 'چت پروژه‌ها',
			'_cptt_bale_notify_payout'  => 'تسویه‌حساب',
			'_cptt_bale_notify_task'    => 'پاسخ تسک مشتری',
			'_cptt_bale_notify_overdue' => 'هشدار مهلت',
		];
		$msg = "⚙ *تنظیمات اعلان‌های ربات بله*\n\nبا کلیک روی هر گزینه، وضعیت آن تغییر می‌کند 👇";
		$kb = [];
		foreach ($labels as $k => $lbl) {
			$on = get_user_meta($user->ID, $k, true) !== '0';
			$kb[] = [['text' => ($on ? '✅ ' : '❌ ') . $lbl . ($on ? ' — فعال' : ' — غیرفعال'), 'callback_data' => 'toggle_notif_' . $k]];
		}
		$kb[] = [['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']];
		$this->edit_or_send($chat_id, $msg_id, $msg, ['inline_keyboard' => $kb]);
	}

	private function toggle_notif_meta($chat_id, $msg_id, $user, $meta_key) {
		$allowed = ['_cptt_bale_notify_assign','_cptt_bale_notify_chat','_cptt_bale_notify_payout','_cptt_bale_notify_task','_cptt_bale_notify_overdue'];
		if (!in_array($meta_key, $allowed, true)) return;
		$on = get_user_meta($user->ID, $meta_key, true) !== '0';
		update_user_meta($user->ID, $meta_key, $on ? '0' : '1');
		$this->expert_notif_settings($chat_id, $msg_id, $user);
	}

	/* ====================================================================
	 * ADMIN FLOWS
	 * ==================================================================== */
	private function admin_global_stats($chat_id, $msg_id) {
		$projects = get_posts(['post_type' => 'cptt_project', 'post_status' => 'any', 'numberposts' => -1]);
		$experts  = get_users(['role' => 'cptt_expert']);
		$customers = get_users(['role' => 'customer']);
		$total_cost = 0; $total_paid = 0; $settled = 0; $unsettled = 0;
		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (is_array($steps)) foreach ($steps as $s) {
				$total_cost += (float)($s['cost'] ?? 0);
				$total_paid += (float)($s['paid'] ?? 0);
			}
			if ((int)get_post_meta($p->ID, '_cptt_is_settled', true)) $settled++; else $unsettled++;
		}
		$msg = "📊 *آمار جامع سیستم*\n\n";
		$msg .= "📁 پروژه‌ها: *" . count($projects) . "*  (✅ {$settled} / ⏳ {$unsettled})\n";
		$msg .= "🧑‍💼 کارشناسان: *" . count($experts) . "*\n";
		$msg .= "👥 مشتریان: *" . count($customers) . "*\n\n";
		$msg .= "💰 کل هزینه: *" . number_format($total_cost) . " تومان*\n";
		$msg .= "💳 دریافتی کل: *" . number_format($total_paid) . " تومان*\n";
		$msg .= "⏳ مانده کل: *" . number_format($total_cost - $total_paid) . " تومان*";
		$this->edit_or_send($chat_id, $msg_id, $msg, $this->kb_back());
	}

	private function admin_today_summary($chat_id, $msg_id) {
		$projects = get_posts(['post_type' => 'cptt_project', 'post_status' => 'any', 'numberposts' => -1]);
		$now = current_time('timestamp', true);
		$today_due = []; $overdue = []; $updated_today = 0;
		$start_of_today = strtotime(date('Y-m-d', $now)); $end_of_today = $start_of_today + DAY_IN_SECONDS - 1;
		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) continue;
			foreach ($steps as $s) {
				if (($s['status'] ?? 'todo') === 'done') continue;
				$due = (int)($s['due_at'] ?? 0);
				if ($due >= $start_of_today && $due <= $end_of_today) $today_due[] = ['p' => $p, 's' => $s];
				elseif ($due > 0 && $due < $start_of_today) $overdue[] = ['p' => $p, 's' => $s];
			}
			$last = (int)get_post_meta($p->ID, '_cptt_last_update', true);
			if ($last >= $start_of_today) $updated_today++;
		}
		$msg = "🌅 *وضعیت امروز*\n\n";
		$msg .= "🗓 مراحل با مهلت امروز: *" . count($today_due) . "*\n";
		$msg .= "⚠️ مراحل معوق: *" . count($overdue) . "*\n";
		$msg .= "🔄 پروژه‌های بروزشده امروز: *{$updated_today}*\n";
		if (!empty($today_due)) {
			$msg .= "\n*📅 امروز:*\n";
			$cnt = 0;
			foreach ($today_due as $r) { if ($cnt++ >= 8) break; $msg .= "• " . esc_html($r['s']['title'] ?? '') . " — " . esc_html(get_the_title($r['p']->ID)) . "\n"; }
		}
		if (!empty($overdue)) {
			$msg .= "\n*⚠️ معوق:*\n";
			$cnt = 0;
			foreach ($overdue as $r) { if ($cnt++ >= 8) break; $msg .= "• " . esc_html($r['s']['title'] ?? '') . " — " . esc_html(get_the_title($r['p']->ID)) . "\n"; }
		}
		$this->edit_or_send($chat_id, $msg_id, $msg, $this->kb_back());
	}

	private function admin_overdue_list($chat_id, $msg_id) {
		$projects = get_posts(['post_type' => 'cptt_project', 'post_status' => 'any', 'numberposts' => -1]);
		$now = current_time('timestamp', true);
		$rows = [];
		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) continue;
			foreach ($steps as $s) {
				if (($s['status'] ?? 'todo') === 'done') continue;
				$due = (int)($s['due_at'] ?? 0);
				if ($due > 0 && $due < $now) $rows[] = ['p' => $p, 's' => $s, 'late' => $now - $due];
			}
		}
		if (empty($rows)) { $this->edit_or_send($chat_id, $msg_id, "✅ هیچ مرحله‌ی معوقی نیست.", $this->kb_back()); return; }
		usort($rows, function($a,$b){ return $b['late'] - $a['late']; });
		$msg = "⏰ *مراحل معوق سیستم (به ترتیب تاخیر):*\n\n";
		$cnt = 0;
		foreach ($rows as $r) {
			if ($cnt++ >= 15) break;
			$days = floor($r['late'] / DAY_IN_SECONDS);
			$msg .= "• *" . esc_html($r['s']['title'] ?? '') . "* — " . esc_html(get_the_title($r['p']->ID)) . " — `{$days} روز تاخیر`\n";
		}
		$this->edit_or_send($chat_id, $msg_id, $msg, $this->kb_back());
	}

	private function admin_unsettled_list($chat_id, $msg_id) {
		$projects = get_posts(['post_type' => 'cptt_project', 'post_status' => 'any', 'numberposts' => -1]);
		$rows = []; $sum = 0;
		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) continue;
			foreach ($steps as $s) {
				$paid = (float)($s['paid'] ?? 0);
				if ($paid <= 0) continue;
				if (!empty($s['step_settled'])) continue;
				$rows[] = ['p' => $p, 's' => $s, 'paid' => $paid];
				$sum += $paid;
			}
		}
		if (empty($rows)) { $this->edit_or_send($chat_id, $msg_id, "✅ همه‌ی مراحل پولی، تسویه شده‌اند.", $this->kb_back()); return; }
		$msg = "💸 *تسویه‌های در انتظار*\n\nمجموع: *" . number_format($sum) . " تومان*\n\n";
		$cnt = 0;
		foreach ($rows as $r) {
			if ($cnt++ >= 12) break;
			$msg .= "• " . esc_html($r['s']['title'] ?? '') . " — " . esc_html(get_the_title($r['p']->ID)) . " — *" . number_format($r['paid']) . " ت*\n";
		}
		$kb = [
			[['text' => '🔗 رفتن به صفحه حساب و کتاب', 'url' => admin_url('edit.php?post_type=cptt_project&page=cptt-project-accounting')]],
			[['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']],
		];
		$this->edit_or_send($chat_id, $msg_id, $msg, ['inline_keyboard' => $kb]);
	}

	private function admin_recent_projects($chat_id, $msg_id) {
		$projects = get_posts(['post_type' => 'cptt_project', 'post_status' => 'any', 'numberposts' => 10]);
		if (empty($projects)) { $this->edit_or_send($chat_id, $msg_id, "📭 پروژه‌ای ثبت نشده.", $this->kb_back()); return; }
		$kb = [];
		foreach ($projects as $p) {
			$pct = $this->project_progress_percent($p->ID);
			$kb[] = [['text' => '📁 ' . get_the_title($p->ID) . " — {$pct}%", 'callback_data' => 'view_proj_' . $p->ID]];
		}
		$kb[] = [['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']];
		$this->edit_or_send($chat_id, $msg_id, "📁 *۱۰ پروژه‌ی اخیر:*", ['inline_keyboard' => $kb]);
	}

	private function admin_experts_list($chat_id, $msg_id) {
		$experts = get_users(['role' => 'cptt_expert']);
		if (empty($experts)) { $this->edit_or_send($chat_id, $msg_id, "👥 کارشناسی ثبت نشده.", $this->kb_back()); return; }
		$msg = "👥 *لیست کارشناسان فعال:*\n\n";
		foreach ($experts as $e) {
			$title = get_user_meta($e->ID, 'cptt_expert_title', true) ?: 'کارشناس';
			$phone = get_user_meta($e->ID, 'billing_phone', true) ?: '—';
			$bot   = get_user_meta($e->ID, '_cptt_bale_chat_id', true) ? '🤖' : '✖';
			$msg .= "• *" . esc_html($e->display_name) . "* ({$title})\n   📞 {$phone}  |  ربات: {$bot}\n\n";
		}
		$this->edit_or_send($chat_id, $msg_id, $msg, $this->kb_back());
	}

	/* --- Wizard: ایجاد پروژه --- */
	private function wizard_create_proj_start($chat_id, $msg_id, $user) {
		$this->set_state($chat_id, 'wiz_cp_title', []);
		$this->edit_or_send($chat_id, $msg_id,
			"➕ *ایجاد پروژه جدید — مرحله ۱ از ۵*\n\n" .
			"📝 *عنوان پروژه* را در پیام بعدی ارسال کنید.\n\n" .
			"_برای لغو در هر مرحله_: ارسال `/cancel`",
			$this->kb_cancel()
		);
	}
	private function wizard_create_proj_show_templates($chat_id, $msg_id, $user) {
		$tpls = get_posts(['post_type' => 'cptt_template', 'post_status' => 'any', 'numberposts' => 30]);
		$msg = "🧩 *مرحله ۳ از ۵*\n\nیک *تمپلیت مراحل* انتخاب کنید یا بدون تمپلیت ادامه دهید:";
		$kb  = [[['text' => '⏭ بدون تمپلیت (مرحله پیش‌فرض)', 'callback_data' => 'wiz_cp_tpl_0']]];
		foreach ($tpls as $t) $kb[] = [['text' => '🧩 ' . get_the_title($t->ID), 'callback_data' => 'wiz_cp_tpl_' . $t->ID]];
		$kb[] = [['text' => '✖ لغو', 'callback_data' => 'cancel_wizard']];
		$this->edit_or_send($chat_id, $msg_id, $msg, ['inline_keyboard' => $kb]);
	}
	private function wizard_create_proj_pick_tpl($chat_id, $msg_id, $tpl_id, $user) {
		$state = $this->get_state($chat_id);
		if (!$state) return;
		$d = $state['d']; $d['tpl_id'] = (int)$tpl_id;
		$this->set_state($chat_id, 'wiz_cp_cost', $d);
		$this->edit_or_send($chat_id, $msg_id,
			"💰 *مرحله ۴ از ۵*\n\n*هزینه کل پروژه* (تومان) را ارسال کنید.\nبرای پروژه‌ی رایگان عدد `0` بفرستید.",
			$this->kb_cancel()
		);
	}
	private function wizard_create_proj_show_experts($chat_id, $msg_id, $user) {
		$experts = get_users(['role' => 'cptt_expert']);
		$msg = "🧑‍💼 *مرحله ۵ از ۵*\n\nیک *کارشناس مسئول* انتخاب کنید (می‌توانید بعداً تغییر دهید):";
		$kb  = [[['text' => '⏭ بدون کارشناس فعلاً', 'callback_data' => 'wiz_cp_exp_0']]];
		foreach ($experts as $e) $kb[] = [['text' => '👤 ' . $e->display_name, 'callback_data' => 'wiz_cp_exp_' . $e->ID]];
		$kb[] = [['text' => '✖ لغو', 'callback_data' => 'cancel_wizard']];
		$this->edit_or_send($chat_id, $msg_id, $msg, ['inline_keyboard' => $kb]);
	}
	private function wizard_create_proj_pick_expert($chat_id, $msg_id, $exp_id, $user) {
		$state = $this->get_state($chat_id);
		if (!$state) return;
		$d = $state['d']; $d['expert_id'] = (int)$exp_id;
		$this->set_state($chat_id, 'wiz_cp_confirm', $d);

		$summary  = "📋 *تأیید نهایی ایجاد پروژه*\n\n";
		$summary .= "📝 عنوان: *" . esc_html($d['title']) . "*\n";
		$summary .= "👤 مشتری: *" . esc_html($d['client_name']) . "*\n";
		$summary .= "💰 هزینه: *" . number_format((float)($d['cost'] ?? 0)) . " تومان*\n";
		$tpl_title = !empty($d['tpl_id']) ? get_the_title((int)$d['tpl_id']) : 'پیش‌فرض';
		$summary .= "🧩 تمپلیت: *" . esc_html($tpl_title) . "*\n";
		$exp_title = $exp_id ? (($u = get_user_by('id', (int)$exp_id)) ? $u->display_name : 'کارشناس') : 'بدون کارشناس';
		$summary .= "🧑‍💼 کارشناس: *" . esc_html($exp_title) . "*\n\nآیا تأیید می‌کنید؟";
		$kb = [
			[['text' => '✅ تأیید و ایجاد', 'callback_data' => 'wiz_cp_confirm']],
			[['text' => '✖ لغو', 'callback_data' => 'cancel_wizard']],
		];
		$this->edit_or_send($chat_id, $msg_id, $summary, ['inline_keyboard' => $kb]);
	}
	private function wizard_create_proj_confirm($chat_id, $msg_id, $user) {
		$state = $this->get_state($chat_id);
		if (!$state || ($state['s'] !== 'wiz_cp_confirm')) {
			$this->edit_or_send($chat_id, $msg_id, "⚠️ نشست منقضی شده.", $this->kb_back()); return;
		}
		$d = $state['d'];

		$project_id = wp_insert_post([
			'post_type' => 'cptt_project',
			'post_status' => 'publish',
			'post_title' => sanitize_text_field($d['title']),
		]);
		if (is_wp_error($project_id) || !$project_id) {
			$this->edit_or_send($chat_id, $msg_id, "❌ خطا در ایجاد پروژه.", $this->kb_back()); return;
		}
		update_post_meta($project_id, '_cptt_client_user_id', (int)$d['client_id']);
		if (!empty($d['expert_id'])) {
			$exp_ids = [(int)$d['expert_id']];
			update_post_meta($project_id, '_cptt_expert_user_ids', $exp_ids);
			update_post_meta($project_id, '_cptt_expert_user_id', (int)$d['expert_id']);
			update_post_meta($project_id, '_cptt_experts_csv', ',' . (int)$d['expert_id'] . ',');
		}
		// Steps from template
		$steps = [];
		if (!empty($d['tpl_id'])) {
			$tpl_steps = get_post_meta((int)$d['tpl_id'], '_cptt_steps', true);
			if (is_array($tpl_steps)) {
				foreach ($tpl_steps as $ts) {
					$ts['id']     = 'st_' . wp_generate_uuid4();
					$ts['status'] = 'todo';
					$steps[]      = $ts;
				}
				if (!empty($steps)) $steps[0]['status'] = 'current';
			}
		}
		if (empty($steps)) {
			$cost = (float)($d['cost'] ?? 0);
			$steps[] = [
				'id' => 'st_' . wp_generate_uuid4(),
				'title' => 'شروع پروژه', 'status' => 'current',
				'cost' => $cost, 'paid' => 0,
				'checklist' => [], 'user_tasks' => [],
			];
		}
		update_post_meta($project_id, '_cptt_steps', $steps);
		$now = (int)current_time('timestamp', true);
		update_post_meta($project_id, '_cptt_last_update', $now);
		update_post_meta($project_id, '_cptt_last_update_fa', class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now));

		// Notify expert
		if (!empty($d['expert_id']) && class_exists('CPTT_Expert')) {
			CPTT_Expert::instance()->insert_notification(
				(int)$d['expert_id'], 'project_assigned',
				'پروژه‌ی جدید «' . $d['title'] . '» به شما واگذار شد.',
				$project_id, CPTT_Expert::dashboard_url() . '#project-' . $project_id
			);
		}

		$this->clear_state($chat_id);
		$kb = [
			[['text' => '📁 مشاهده در ربات', 'callback_data' => 'view_proj_' . $project_id]],
			[['text' => '🔗 ویرایش کامل در ادمین', 'url' => get_edit_post_link($project_id, '')]],
			[['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']],
		];
		$this->edit_or_send($chat_id, $msg_id, "🎉 *پروژه با موفقیت ایجاد شد!*\n\n📁 *" . esc_html($d['title']) . "*\n👤 مشتری: " . esc_html($d['client_name']), ['inline_keyboard' => $kb]);
	}

	/* --- Broadcast wizard --- */
	private function wizard_broadcast_start($chat_id, $msg_id, $user) {
		$this->set_state($chat_id, 'admin_broadcast_msg', []);
		$this->edit_or_send($chat_id, $msg_id, "📢 *ارسال پیام همگانی*\n\nمتن پیامی را که می‌خواهید برای همه‌ی کاربران متصل به ربات ارسال شود بفرستید.\n\n_برای لغو_: `/cancel`", $this->kb_cancel());
	}
	private function send_broadcast($text) {
		$users = get_users(['meta_key' => '_cptt_bale_chat_id', 'meta_compare' => 'EXISTS']);
		foreach ($users as $u) {
			$cid = get_user_meta($u->ID, '_cptt_bale_chat_id', true);
			if ($cid) self::send_message($cid, "📢 *پیام از مدیریت سیستم:*\n\n" . $text);
		}
	}

	private function trigger_manual_reminders($chat_id, $msg_id) {
		$projects = get_posts(['post_type' => 'cptt_project', 'post_status' => 'any', 'numberposts' => -1]);
		$now = current_time('timestamp', true);
		$ne = 0; $nc = 0;
		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) continue;
			if (class_exists('CPTT_Core')) {
				$expert_ids = CPTT_Core::get_project_expert_ids($p->ID);
				foreach ($steps as $s) {
					if (($s['status'] ?? 'todo') !== 'done' && !empty($s['due_at']) && (int)$s['due_at'] < $now) {
						foreach ($expert_ids as $eid) {
							self::notify_via_bale($eid, "⚠️ *هشدار تاخیر:* مرحله «" . ($s['title'] ?? '') . "» در پروژه «" . get_the_title($p->ID) . "» منقضی شده است.", 'overdue', $p->ID);
							$ne++;
						}
					}
				}
			}
			$client_id = (int)get_post_meta($p->ID, '_cptt_client_user_id', true);
			if ($client_id) {
				$has_pending = false;
				foreach ($steps as $s) {
					$uts = isset($s['user_tasks']) && is_array($s['user_tasks']) ? $s['user_tasks'] : [];
					foreach ($uts as $ut) if (empty($ut['done'])) { $has_pending = true; break 2; }
				}
				if ($has_pending) {
					self::notify_via_bale($client_id, "⏰ شما تسک‌های در انتظار در پروژه «" . get_the_title($p->ID) . "» دارید.", 'user_task', $p->ID);
					$nc++;
				}
			}
		}
		$this->edit_or_send($chat_id, $msg_id, "⏰ یادآوری‌ها ارسال شد:\n• به کارشناسان: *{$ne}* پیام\n• به مشتریان: *{$nc}* پیام", $this->kb_back());
	}

	/* ====================================================================
	 * HELPERS / RENDERING
	 * ==================================================================== */
	private function render_project_summary($pid) {
		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];
		$total = count($steps); $done = 0;
		foreach ($steps as $s) if (($s['status'] ?? 'todo') === 'done') $done++;
		$pct = $total ? round(($done / $total) * 100) : 0;
		$msg = "🏢 *" . esc_html(get_the_title($pid)) . "*\n";
		$msg .= "🔑 کد: `{$pid}`  |  📊 پیشرفت: *{$pct}%*\n\n";
		$shown = 0;
		foreach ($steps as $i => $s) {
			if ($shown++ >= 10) { $msg .= "_… و " . (count($steps) - 10) . " مرحله‌ی دیگر_\n"; break; }
			$st = $s['status'] ?? 'todo';
			$icon = $st === 'done' ? '✅' : ($st === 'current' ? '⏳' : '⚪');
			$msg .= $icon . " " . ($i+1) . ". " . esc_html($s['title'] ?? '') . "\n";
		}
		return $msg;
	}

	private function project_progress_percent($pid) {
		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!is_array($steps) || empty($steps)) return 0;
		$total = count($steps); $done = 0;
		foreach ($steps as $s) if (($s['status'] ?? 'todo') === 'done') $done++;
		return $total ? (int)round(($done / $total) * 100) : 0;
	}

	private function status_label($s) {
		if ($s === 'done') return 'انجام‌شده';
		if ($s === 'current') return 'در حال انجام';
		return 'انجام‌نشده';
	}

	private function collect_pending_user_tasks($pid) {
		$out = [];
		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!is_array($steps)) return $out;
		foreach ($steps as $s) {
			$step_id = $s['id'] ?? '';
			if (!$step_id) continue;
			$uts = isset($s['user_tasks']) && is_array($s['user_tasks']) ? $s['user_tasks'] : [];
			foreach ($uts as $ut) {
				if (empty($ut['done']) && !empty($ut['id'])) {
					$out[] = ['ref' => $pid . ':' . $step_id . ':' . $ut['id'], 'title' => $ut['title'] ?? '', 'desc' => $ut['desc'] ?? ''];
				}
			}
		}
		return $out;
	}

	private function get_user_projects($user, $role, $limit) {
		$args = ['post_type' => 'cptt_project', 'post_status' => 'any', 'numberposts' => $limit];
		if ($role === 'customer') {
			$args['meta_key']   = '_cptt_client_user_id';
			$args['meta_value'] = (int)$user->ID;
		} elseif ($role === 'expert') {
			$args['meta_query'] = [[
				'key' => '_cptt_experts_csv',
				'value' => ',' . (int)$user->ID . ',',
				'compare' => 'LIKE',
			]];
		}
		return get_posts($args);
	}

	private function get_user_by_bale_id($chat_id) {
		$users = get_users(['meta_key' => '_cptt_bale_chat_id', 'meta_value' => (int)$chat_id, 'number' => 1]);
		return !empty($users) ? $users[0] : null;
	}

	private function find_user_by_phone($phone) {
		$phone = trim($phone);
		$users = get_users(['meta_key' => 'billing_phone', 'meta_value' => $phone, 'number' => 1]);
		if (!empty($users)) return $users[0];
		$users = get_users(['meta_key' => 'cptt_user_phone', 'meta_value' => $phone, 'number' => 1]);
		if (!empty($users)) return $users[0];
		$u = get_user_by('login', $phone);
		if ($u) return $u;
		return null;
	}

	private function get_user_role($user) {
		$roles = (array)$user->roles;
		if (in_array('administrator', $roles, true)) return 'admin';
		if (in_array('cptt_expert', $roles, true))   return 'expert';
		return 'customer';
	}
	private function get_role_label($user) {
		$r = $this->get_user_role($user);
		if ($r === 'admin')  return 'مدیر کل';
		if ($r === 'expert') return 'کارشناس';
		return 'مشتری';
	}

	private function shorten($txt, $n) {
		$txt = (string)$txt;
		if (mb_strlen($txt) <= $n) return $txt;
		return mb_substr($txt, 0, $n - 1) . '…';
	}
	private function to_english_digits($string) {
		$p = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
		$a = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
		$e = range(0, 9);
		$string = str_replace($p, $e, (string)$string);
		return str_replace($a, $e, $string);
	}

	private function kb_back() {
		return ['inline_keyboard' => [[['text' => '🏠 منوی اصلی', 'callback_data' => 'back_to_menu']]]];
	}
	private function kb_cancel() {
		return ['inline_keyboard' => [[['text' => '✖ لغو', 'callback_data' => 'cancel_wizard']]]];
	}

	/** Try to edit message; fall back to sending a new one. */
	private function edit_or_send($chat_id, $msg_id, $text, $reply_markup = null) {
		if ($msg_id) return self::edit_message($chat_id, $msg_id, $text, $reply_markup);
		return self::send_message($chat_id, $text, $reply_markup);
	}

	/* ====================================================================
	 * NOTIFICATION OUT (called from other classes)
	 * ==================================================================== */
	public static function notify_via_bale($user_id, $message, $type = '', $project_id = 0) {
		$settings = self::get_settings();
		// نگاشت type → کلید تنظیمات سراسری
		$map = [
			'project_assigned'  => 'expert_assign',
			'project_chat'      => 'expert_chat',
			'direct_chat'       => 'expert_chat',
			'expert_payout'     => 'expert_payout',
			'project_completed' => 'client_complete',
			'user_task'         => 'client_task',
			'user_task_done'    => 'expert_chat',
			'overdue'           => 'expert_assign',
		];
		$mt = isset($map[$type]) ? $map[$type] : '';
		if ($mt !== '') {
			$enabled = isset($settings[$mt]) ? $settings[$mt] : '1';
			if ($enabled !== '1') return;
		}
		// نگاشت type → meta سطح کاربر
		$user_map = [
			'project_assigned'  => '_cptt_bale_notify_assign',
			'project_chat'      => '_cptt_bale_notify_chat',
			'direct_chat'       => '_cptt_bale_notify_chat',
			'expert_payout'     => '_cptt_bale_notify_payout',
			'user_task_done'    => '_cptt_bale_notify_task',
			'overdue'           => '_cptt_bale_notify_overdue',
		];
		if (!empty($user_map[$type])) {
			$on = get_user_meta((int)$user_id, $user_map[$type], true) !== '0';
			if (!$on) return;
		}
		// Mute per-project
		if ($project_id) {
			$mutes = get_user_meta((int)$user_id, '_cptt_bale_mutes', true);
			if (is_array($mutes) && isset($mutes[$project_id])) {
				$until = (int)$mutes[$project_id];
				if ($until === 0 || $until > time()) return;
			}
		}
		$chat_id = get_user_meta((int)$user_id, '_cptt_bale_chat_id', true);
		if (!$chat_id) return;
		$kb = null;
		if ($project_id && in_array($type, ['project_assigned','project_chat','project_completed','overdue','user_task_done'], true)) {
			$kb = ['inline_keyboard' => [[['text' => '📁 مشاهده پروژه', 'callback_data' => 'view_proj_' . $project_id]]]];
		}
		if ($type === 'project_completed' && $project_id) {
			$report_url = wp_nonce_url(admin_url('admin-post.php?action=cptt_view_report&project_id=' . $project_id), 'cptt_view_report_' . $project_id);
			$kb = ['inline_keyboard' => [
				[['text' => '📄 مشاهده گزارش نهایی', 'url' => $report_url]],
				[['text' => '📁 مشاهده پروژه در ربات', 'callback_data' => 'view_proj_' . $project_id]],
			]];
		}
		self::send_message($chat_id, "🔔 " . $message, $kb);
	}

	/**
	 * هنگام assign شدن تسک به مشتری، علاوه بر اعلان معمول، یک دکمه‌ی «📝 پاسخ» هم بدهیم.
	 */
	public function on_user_task_assigned($pid, $step_id, $task_id) {
		$client_id = (int)get_post_meta($pid, '_cptt_client_user_id', true);
		if (!$client_id) return;
		$chat_id = get_user_meta($client_id, '_cptt_bale_chat_id', true);
		if (!$chat_id) return;
		$enabled = get_user_meta($client_id, '_cptt_bale_notify_task', true) !== '0';
		if (!$enabled) return;
		$title = get_the_title($pid);
		$kb = ['inline_keyboard' => [
			[['text' => '📝 پاسخ به تسک', 'callback_data' => 'cust_ut_reply_' . $pid . ':' . $step_id . ':' . $task_id]],
			[['text' => '📁 مشاهده پروژه', 'callback_data' => 'cust_view_proj_' . $pid]],
		]];
		self::send_message($chat_id, "📝 *تسک جدید برای شما در پروژه «" . $title . "»*\n\nبرای پاسخ روی دکمه‌ی زیر کلیک کنید 👇", $kb);
	}

	/* ====================================================================
	 * CRON REPORTS
	 * ==================================================================== */
	public function cron_daily_report() {
		$settings = self::get_settings();
		if (($settings['daily_report'] ?? '1') !== '1') return;

		// به ادمین: امروز + معوق
		$admin_id = trim((string)($settings['admin_id'] ?? ''));
		if ($admin_id !== '') {
			$msg = $this->build_admin_daily_report();
			self::send_message($admin_id, $msg);
		}

		// به هر کارشناس: کارهای امروز و معوق خودش
		$experts = get_users(['role' => 'cptt_expert', 'meta_key' => '_cptt_bale_chat_id']);
		foreach ($experts as $e) {
			$chat = get_user_meta($e->ID, '_cptt_bale_chat_id', true);
			if (!$chat) continue;
			$msg = $this->build_expert_daily_report($e);
			if ($msg) self::send_message($chat, $msg);
		}
	}

	public function cron_weekly_report() {
		$settings = self::get_settings();
		if (($settings['weekly_report'] ?? '1') !== '1') return;
		$admin_id = trim((string)($settings['admin_id'] ?? ''));
		if ($admin_id === '') return;

		$now = time(); $week_ago = $now - 7 * DAY_IN_SECONDS;
		$projects = get_posts(['post_type' => 'cptt_project', 'post_status' => 'any', 'numberposts' => -1]);
		$completed = 0; $created = 0; $paid_sum = 0;
		foreach ($projects as $p) {
			$ts = strtotime($p->post_date_gmt);
			if ($ts >= $week_ago) $created++;
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (is_array($steps)) {
				foreach ($steps as $s) {
					if (!empty($s['settle_at']) && (int)$s['settle_at'] >= $week_ago) $paid_sum += (float)($s['paid'] ?? 0);
				}
			}
			$last = (int)get_post_meta($p->ID, '_cptt_last_update', true);
			$prog_status = get_post_meta($p->ID, '_cptt_progress_status_cache', true);
			if ($prog_status === 'completed' && $last >= $week_ago) $completed++;
		}
		$msg = "📅 *گزارش هفتگی سیستم*\n_۷ روز اخیر_\n\n";
		$msg .= "🆕 پروژه‌های جدید: *{$created}*\n";
		$msg .= "✅ پروژه‌های تکمیل‌شده: *{$completed}*\n";
		$msg .= "💸 جمع تسویه‌ها: *" . number_format($paid_sum) . " تومان*\n";
		self::send_message($admin_id, $msg);
	}

	private function build_admin_daily_report() {
		$projects = get_posts(['post_type' => 'cptt_project', 'post_status' => 'any', 'numberposts' => -1]);
		$now = current_time('timestamp', true);
		$start_today = strtotime(date('Y-m-d', $now));
		$end_today   = $start_today + DAY_IN_SECONDS - 1;
		$today_due = 0; $overdue = 0; $updated_today = 0;
		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (is_array($steps)) foreach ($steps as $s) {
				if (($s['status'] ?? 'todo') === 'done') continue;
				$d = (int)($s['due_at'] ?? 0);
				if ($d >= $start_today && $d <= $end_today) $today_due++;
				elseif ($d > 0 && $d < $start_today) $overdue++;
			}
			$last = (int)get_post_meta($p->ID, '_cptt_last_update', true);
			if ($last >= $start_today) $updated_today++;
		}
		$msg = "🌅 *گزارش روز خوش، مدیر گرامی*\n\n";
		$msg .= "📅 مراحل با مهلت امروز: *{$today_due}*\n";
		$msg .= "⚠️ مراحل معوق: *{$overdue}*\n";
		$msg .= "🔄 پروژه‌های بروزشده دیروز/امروز: *{$updated_today}*\n\n";
		$msg .= "_برای جزئیات از منوی ربات استفاده کنید._";
		return $msg;
	}

	private function build_expert_daily_report($user) {
		$projects = $this->get_user_projects($user, 'expert', -1);
		if (empty($projects)) return '';
		$now = current_time('timestamp', true);
		$start_today = strtotime(date('Y-m-d', $now));
		$end_today   = $start_today + DAY_IN_SECONDS - 1;
		$today = []; $overdue = [];
		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) continue;
			foreach ($steps as $s) {
				if (($s['status'] ?? 'todo') === 'done') continue;
				$d = (int)($s['due_at'] ?? 0);
				if ($d >= $start_today && $d <= $end_today) $today[] = ['p' => $p, 's' => $s];
				elseif ($d > 0 && $d < $start_today) $overdue[] = ['p' => $p, 's' => $s];
			}
		}
		if (empty($today) && empty($overdue)) return ''; // پیامی نده اگر چیزی نیست
		$msg = "🌅 *صبح بخیر " . esc_html($user->display_name) . "*\n\n*برنامه‌ی امروز شما:*\n\n";
		if (!empty($today)) {
			$msg .= "📅 *مراحل امروز:*\n";
			$cnt = 0;
			foreach ($today as $r) { if ($cnt++ >= 8) break; $msg .= "• " . esc_html($r['s']['title'] ?? '') . " — " . esc_html(get_the_title($r['p']->ID)) . "\n"; }
			$msg .= "\n";
		}
		if (!empty($overdue)) {
			$msg .= "⚠️ *مراحل معوق:*\n";
			$cnt = 0;
			foreach ($overdue as $r) { if ($cnt++ >= 8) break; $msg .= "• " . esc_html($r['s']['title'] ?? '') . " — " . esc_html(get_the_title($r['p']->ID)) . "\n"; }
		}
		return $msg;
	}
}
