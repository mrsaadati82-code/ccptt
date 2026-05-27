<?php
if ( ! defined('ABSPATH') ) exit;

class CPTT_Bale {
	private static $instance = null;

	public static function instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action('wp_ajax_nopriv_cptt_bale_webhook', [$this, 'handle_webhook']);
		add_action('wp_ajax_cptt_bale_webhook', [$this, 'handle_webhook']);
		add_action('update_option_cptt_bale_settings', [$this, 'set_webhook_on_save'], 10, 2);
		add_action('add_option_cptt_bale_settings', [$this, 'set_webhook_on_add'], 10, 2);
		add_action('admin_init', [$this, 'handle_admin_actions']);
	}

	public static function get_settings() {
		$defaults = [
			'token' => '',
			'admin_id' => '',
			'expert_assign' => '1',
			'expert_chat' => '1',
			'expert_payout' => '1',
			'client_complete' => '1',
			'client_task' => '1',
		];
		$opt = get_option('cptt_bale_settings', []);
		return array_merge($defaults, is_array($opt) ? $opt : []);
	}

	public function set_webhook_on_add($option, $value) {
		$this->set_webhook_on_save([], $value);
	}

	public function set_webhook_on_save($old_value, $new_value) {
		$token = isset($new_value['token']) ? trim($new_value['token']) : '';
		if ($token !== '') {
			$webhook_url = admin_url('admin-ajax.php?action=cptt_bale_webhook');
			$api_url = "https://tapi.bale.ai/bot{$token}/setWebhook?url=" . urlencode($webhook_url);
			wp_remote_get($api_url, ['sslverify' => false]);
		}
	}

	public function handle_admin_actions() {
		if (!current_user_can('manage_options')) return;
		if (empty($_GET['page']) || $_GET['page'] !== 'cptt-settings' || empty($_GET['bale_action'])) return;
		
		$action = sanitize_key($_GET['bale_action']);
		$settings = self::get_settings();
		$token = trim($settings['token']);
		
		if ($token === '') {
			add_settings_error('cptt_bale_messages', 'cptt_bale_error', 'لطفاً ابتدا توکن ربات بله را ذخیره کنید.', 'error');
			return;
		}
		
		if ($action === 'set_webhook') {
			$webhook_url = admin_url('admin-ajax.php?action=cptt_bale_webhook');
			$api_url = "https://tapi.bale.ai/bot{$token}/setWebhook?url=" . urlencode($webhook_url);
			$res = wp_remote_get($api_url, ['sslverify' => false]);
			if (is_wp_error($res)) {
				add_settings_error('cptt_bale_messages', 'cptt_bale_error', 'خطا در تنظیم وب‌هوک بله: ' . $res->get_error_message(), 'error');
			} else {
				add_settings_error('cptt_bale_messages', 'cptt_bale_success', 'وب‌هوک ربات بله با موفقیت بر روی هاست شما تنظیم شد.', 'updated');
			}
		} elseif ($action === 'delete_webhook') {
			$api_url = "https://tapi.bale.ai/bot{$token}/deleteWebhook";
			$res = wp_remote_get($api_url, ['sslverify' => false]);
			if (is_wp_error($res)) {
				add_settings_error('cptt_bale_messages', 'cptt_bale_error', 'خطا در حذف وب‌هوک: ' . $res->get_error_message(), 'error');
			} else {
				add_settings_error('cptt_bale_messages', 'cptt_bale_success', 'وب‌هوک ربات بله با موفقیت حذف شد.', 'updated');
			}
		} elseif ($action === 'test_connection') {
			$api_url = "https://tapi.bale.ai/bot{$token}/getMe";
			$res = wp_remote_get($api_url, ['sslverify' => false]);
			if (is_wp_error($res)) {
				add_settings_error('cptt_bale_messages', 'cptt_bale_error', 'خطا در تست اتصال ربات: ' . $res->get_error_message(), 'error');
			} else {
				$body = json_decode(wp_remote_retrieve_body($res), true);
				if (!empty($body['ok']) && !empty($body['result'])) {
					$bot_name = $body['result']['first_name'] ?? '';
					$bot_user = $body['result']['username'] ?? '';
					add_settings_error('cptt_bale_messages', 'cptt_bale_success', "اتصال با موفقیت برقرار شد! نام ربات شما: {$bot_name} (@{$bot_user})", 'updated');
				} else {
					add_settings_error('cptt_bale_messages', 'cptt_bale_error', 'خطا در اعتبارسنجی توکن بله. لطفاً توکن را بررسی کنید.', 'error');
				}
			}
		}
	}

	public static function send_message($chat_id, $text, $reply_markup = null) {
		$settings = self::get_settings();
		$token = trim($settings['token']);
		if ($token === '') return false;

		$api_url = "https://tapi.bale.ai/bot{$token}/sendMessage";
		$body = [
			'chat_id' => $chat_id,
			'text' => $text,
			'parse_mode' => 'Markdown',
		];
		if ($reply_markup) {
			$body['reply_markup'] = $reply_markup;
		}

		$response = wp_remote_post($api_url, [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => wp_json_encode($body),
			'data_format' => 'body',
			'sslverify' => false,
		]);

		return !is_wp_error($response);
	}

	public function handle_webhook() {
		$input = file_get_contents('php://input');
		$data = json_decode($input, true);

		if (!$data) {
			status_header(200);
			exit;
		}

		$chat_id = 0;
		$text = '';
		$is_callback = false;
		$callback_data = '';
		$callback_query_id = '';

		// 1. Process Callback Query (Inline Keyboard Clicks)
		if (!empty($data['callback_query'])) {
			$cq = $data['callback_query'];
			$chat_id = isset($cq['message']['chat']['id']) ? (int)$cq['message']['chat']['id'] : 0;
			$callback_data = isset($cq['data']) ? trim($cq['data']) : '';
			$callback_query_id = isset($cq['id']) ? $cq['id'] : '';
			$is_callback = true;
		} 
		// 2. Process Standard Message
		elseif (!empty($data['message'])) {
			$message = $data['message'];
			$chat_id = isset($message['chat']['id']) ? (int)$message['chat']['id'] : 0;
			$text = isset($message['text']) ? trim($message['text']) : '';
		}

		if (!$chat_id) {
			status_header(200);
			exit;
		}

		// Find user by Bale chat ID
		$user = $this->get_user_by_bale_id($chat_id);

		// Handle callback queries (Inline Keyboards)
		if ($is_callback && $user) {
			$this->process_callback_query($chat_id, $callback_data, $user);
			status_header(200);
			exit;
		}

		// Handle Broadcast / Messages for Admin
		if ($user && $this->get_user_role($user) === 'admin') {
			if (strpos($text, '/broadcast ') === 0) {
				$msg_to_send = trim(substr($text, 11));
				if ($msg_to_send !== '') {
					$this->send_broadcast($msg_to_send);
					self::send_message($chat_id, "📢 *پیام همگانی شما با موفقیت به تمام همکاران و مشتریان متصل به ربات ارسال شد.*");
				}
				status_header(200);
				exit;
			}
			if (strpos($text, '/msg ') === 0) {
				$parts = explode(' ', substr($text, 5), 2);
				$target_uid = (int)($parts[0] ?? 0);
				$msg_text = trim($parts[1] ?? '');
				if ($target_uid && $msg_text !== '') {
					$target_user = get_user_by('id', $target_uid);
					if ($target_user) {
						self::notify_via_bale($target_uid, "📨 *پیام مستقیم از مدیریت:*\n\n" . $msg_text);
						self::send_message($chat_id, "✓ *پیام شما با موفقیت برای همکار/مشتری " . esc_html($target_user->display_name) . " ارسال شد.*");
					} else {
						self::send_message($chat_id, "❌ کاربر مورد نظر یافت نشد.");
					}
				}
				status_header(200);
				exit;
			}
			if (strpos($text, '/create_project ') === 0) {
				$parts = explode(' @ ', substr($text, 16));
				$proj_title = trim($parts[0] ?? '');
				$cust_phone = trim($parts[1] ?? '');
				if ($proj_title !== '' && $cust_phone !== '') {
					$res = $this->create_project_via_bot($proj_title, $cust_phone);
					if (is_wp_error($res)) {
						self::send_message($chat_id, "❌ *خطا در ساخت پروژه:* " . $res->get_error_message());
					} else {
						self::send_message($chat_id, "✓ *پروژه جدید با عنوان `" . esc_html($proj_title) . "` با موفقیت برای مشتری ثبت شد.*");
					}
				}
				status_header(200);
				exit;
			}
		}

		// Handle Standard Commands
		if ($text === '/start') {
			if ($user) {
				$this->send_welcome_menu($chat_id, $user);
			} else {
				$welcome_msg = "🤝 *به ربات هماهنگ خوش آمدید*\n\n";
				$welcome_msg .= "برای اتصال حساب کاربری و دریافت اعلان‌های آنی، لطفاً شماره موبایل خود را ارسال کنید:\n";
				$welcome_msg .= "مثال: `09123456789`";
				self::send_message($chat_id, $welcome_msg);
			}
			status_header(200);
			exit;
		}

		// Handle registration
		if (!$user && preg_match('/^(09\d{9}|9\d{9})$/', $this->to_english_digits($text))) {
			$phone = $this->to_english_digits($text);
			if (strpos($phone, '09') !== 0) $phone = '0' . $phone;

			$found_user = $this->find_user_by_phone($phone);
			if ($found_user) {
				update_user_meta($found_user->ID, '_cptt_bale_chat_id', $chat_id);
				$success_msg = "🎉 *اتصال حساب با موفقیت انجام شد!*\n\n";
				$success_msg .= "👤 نام شما: *" . esc_html($found_user->display_name) . "*\n";
				$success_msg .= "💼 نقش کاربری: *" . esc_html($this->get_role_label($found_user)) . "*\n\n";
				$success_msg .= "از این پس تمام اعلان‌ها و تراکنش‌های مربوطه به صورت لحظه‌ای در همین ربات برای شما ارسال خواهد شد.";
				self::send_message($chat_id, $success_msg);
				$this->send_welcome_menu($chat_id, $found_user);
			} else {
				$fail_msg = "❌ شماره موبایل `" . esc_html($phone) . "` در سیستم ثبت نشده است.\n\n";
				$fail_msg .= "لطفاً ابتدا تلفن همراه خود را در پروفایل کاربری خود در وب‌سایت ثبت کنید و مجدداً تلاش نمایید.";
				self::send_message($chat_id, $fail_msg);
			}
			status_header(200);
			exit;
		}

		// Fallback for unregistered users
		if (!$user) {
			$fallback = "⚠️ شماره موبایل شما ثبت نشده است. لطفاً شماره موبایل ۱۱ رقمی خود را جهت اتصال حساب وارد کنید (مثال: 09123456789):";
			self::send_message($chat_id, $fallback);
		} else {
			$this->send_welcome_menu($chat_id, $user);
		}

		status_header(200);
		exit;
	}

	private function get_user_by_bale_id($chat_id) {
		$users = get_users([
			'meta_key' => '_cptt_bale_chat_id',
			'meta_value' => (int)$chat_id,
			'number' => 1,
		]);
		return !empty($users) ? $users[0] : null;
	}

	private function find_user_by_phone($phone) {
		$phone = trim($phone);
		$users = get_users([
			'meta_key' => 'billing_phone',
			'meta_value' => $phone,
			'number' => 1,
		]);
		if (!empty($users)) return $users[0];

		$user = get_user_by('login', $phone);
		if ($user) return $user;

		return null;
	}

	private function get_user_role($user) {
		$roles = (array)$user->roles;
		if (in_array('administrator', $roles, true)) return 'admin';
		if (in_array('cptt_expert', $roles, true)) return 'expert';
		return 'customer';
	}

	private function get_role_label($user) {
		$role = $this->get_user_role($user);
		if ($role === 'admin') return 'مدیر کل سیستم';
		if ($role === 'expert') return 'کارشناس / همکار';
		return 'کارفرما / مشتری';
	}

	private function send_welcome_menu($chat_id, $user) {
		$role = $this->get_user_role($user);
		$msg = "⚡ *پنل کاربری ربات هماهنگ*\n\n";
		$msg .= "سلام *" . esc_html($user->display_name) . "* عزیز خوش آمدید.\n";
		$msg .= "لطفاً جهت دسترسی به بخش‌های مختلف، از کلیدهای شیشه‌ای زیر استفاده کنید:";

		$inline_keyboard = [];
		if ($role === 'admin') {
			$inline_keyboard = [
				[['text' => '📊 آمار مالی کل سیستم', 'callback_data' => 'admin_global_stats']],
				[['text' => '📁 پروژه‌های فعال سیستم', 'callback_data' => 'admin_projects']],
				[['text' => '👥 لیست همکاران و کارشناسان', 'callback_data' => 'admin_experts']],
				[['text' => '📢 ارسال پیام همگانی', 'callback_data' => 'admin_broadcast_info']],
				[['text' => '➕ ثبت پروژه سریع جدید', 'callback_data' => 'admin_create_project_info']],
				[['text' => '⏰ ارسال یادآوری تسک‌ها', 'callback_data' => 'admin_send_reminders_trigger']],
			];
		} elseif ($role === 'expert') {
			$inline_keyboard = [
				[['text' => '📁 پروژه‌های تحت نظارت من', 'callback_data' => 'expert_projects']],
				[['text' => '📝 تغییر وضعیت مراحل پروژه', 'callback_data' => 'expert_edit_projects']],
				[['text' => '📊 آمار عملکرد من', 'callback_data' => 'expert_stats']],
				[['text' => '💵 وضعیت تسویه حساب من', 'callback_data' => 'expert_commission']],
				[['text' => '⚙ تنظیمات نوتیفیکیشن من', 'callback_data' => 'expert_notif_settings']],
			];
		} else {
			$inline_keyboard = [
				[['text' => '📁 لیست پروژه‌های من', 'callback_data' => 'customer_projects']],
				[['text' => '📄 پیش‌فاکتورهای صادر شده', 'callback_data' => 'customer_invoices']],
			];
		}

		$reply_markup = [
			'inline_keyboard' => $inline_keyboard,
		];

		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function process_callback_query($chat_id, $callback_data, $user) {
		$role = $this->get_user_role($user);

		// Admin triggers
		if ($role === 'admin') {
			if ($callback_data === 'admin_global_stats') {
				$this->send_admin_global_stats($chat_id);
			} elseif ($callback_data === 'admin_projects') {
				$this->send_admin_projects($chat_id);
			} elseif ($callback_data === 'admin_experts') {
				$this->send_admin_experts_list($chat_id);
			} elseif ($callback_data === 'admin_broadcast_info') {
				$this->send_admin_broadcast_instructions($chat_id);
			} elseif ($callback_data === 'admin_create_project_info') {
				$this->send_admin_create_project_instructions($chat_id);
			} elseif ($callback_data === 'admin_send_reminders_trigger') {
				$this->trigger_manual_reminders($chat_id);
			}
		}

		// Expert triggers
		if ($role === 'expert') {
			if ($callback_data === 'expert_projects') {
				$this->send_expert_projects($chat_id, $user->ID);
			} elseif ($callback_data === 'expert_stats') {
				$this->send_expert_stats($chat_id, $user->ID);
			} elseif ($callback_data === 'expert_commission') {
				$this->send_expert_commission($chat_id, $user->ID);
			} elseif ($callback_data === 'expert_notif_settings') {
				$this->send_expert_notif_settings($chat_id, $user->ID);
			} elseif ($callback_data === 'expert_edit_projects') {
				$this->send_expert_edit_projects_list($chat_id, $user->ID);
			} elseif (strpos($callback_data, 'toggle_notif_') === 0) {
				$meta_key = str_replace('toggle_notif_', '', $callback_data);
				$this->toggle_expert_notif_meta($chat_id, $user->ID, $meta_key);
			} elseif (strpos($callback_data, 'list_steps_') === 0) {
				$pid = (int)str_replace('list_steps_', '', $callback_data);
				$this->send_expert_project_steps_to_edit($chat_id, $pid, $user->ID);
			} elseif (strpos($callback_data, 'change_step_') === 0) {
				$parts = explode('_', str_replace('change_step_', '', $callback_data));
				$pid = (int)($parts[0] ?? 0);
				$step_id = $parts[1] ?? '';
				$this->show_step_status_options($chat_id, $pid, $step_id);
			} elseif (strpos($callback_data, 'set_st_status_') === 0) {
				$parts = explode('_', str_replace('set_st_status_', '', $callback_data));
				$pid = (int)($parts[0] ?? 0);
				$step_id = $parts[1] ?? '';
				$status = $parts[2] ?? '';
				$this->update_step_status_via_bot($chat_id, $pid, $step_id, $status, $user->ID);
			}
		}

		// Customer triggers
		if ($role === 'customer') {
			if ($callback_data === 'customer_projects') {
				$this->send_customer_projects($chat_id, $user->ID);
			} elseif ($callback_data === 'customer_invoices') {
				$this->send_customer_invoices($chat_id, $user->ID);
			}
		}

		// Common triggers
		if (strpos($callback_data, 'view_proj_') === 0) {
			$pid = (int)str_replace('view_proj_', '', $callback_data);
			$this->send_project_detail($chat_id, $pid, $user);
		} elseif (strpos($callback_data, 'view_pay_') === 0) {
			$pid = (int)str_replace('view_pay_', '', $callback_data);
			$this->send_project_payout_detail($chat_id, $pid, $user);
		} elseif ($callback_data === 'back_to_menu') {
			$this->send_welcome_menu($chat_id, $user);
		}
	}

	private function send_customer_projects($chat_id, $user_id) {
		$projects = get_posts([
			'post_type' => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => 10,
			'meta_key' => '_cptt_client_user_id',
			'meta_value' => (int)$user_id,
		]);

		if (empty($projects)) {
			self::send_message($chat_id, "❌ *هیچ پروژه‌ای برای شما در سیستم ثبت نشده است.*");
			return;
		}

		$msg = "📁 *لیست پروژه‌های فعال شما:*\n";
		$msg .= "برای دیدن پیشرفت مراحل پروژه خود، روی دکمه‌های زیر کلیک کنید:";
		
		$inline_keyboard = [];
		foreach ($projects as $p) {
			$inline_keyboard[] = [
				['text' => '📁 ' . get_the_title($p->ID), 'callback_data' => 'view_proj_' . $p->ID]
			];
		}
		$inline_keyboard[] = [['text' => '↩ بازگشت به منو اصلی', 'callback_data' => 'back_to_menu']];

		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function send_customer_invoices($chat_id, $user_id) {
		$projects = get_posts([
			'post_type' => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => 10,
			'meta_key' => '_cptt_client_user_id',
			'meta_value' => (int)$user_id,
		]);

		if (empty($projects)) {
			self::send_message($chat_id, "❌ *هیچ پیش‌فاکتوری برای شما صادر نشده است.*");
			return;
		}

		$msg = "📄 *لیست پیش‌فاکتورهای صادر شده شما:*\n";
		$msg .= "با کلیک روی دکمه‌های زیر می‌توانید نسخه چاپی پیش‌فاکتور را دریافت کنید:";
		
		$inline_keyboard = [];
		foreach ($projects as $p) {
			$invoice_url = wp_nonce_url(admin_url('admin-post.php?action=cptt_view_invoice&project_id=' . $p->ID), 'cptt_view_invoice_' . $p->ID);
			$inline_keyboard[] = [
				['text' => '📄 پیش‌فاکتور ' . get_the_title($p->ID), 'url' => $invoice_url]
			];
		}
		$inline_keyboard[] = [['text' => '↩ بازگشت به منو اصلی', 'callback_data' => 'back_to_menu']];

		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function send_expert_projects($chat_id, $user_id) {
		$projects = get_posts([
			'post_type' => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => 10,
			'meta_query' => [[
				'key' => '_cptt_experts_csv',
				'value' => ',' . (int)$user_id . ',',
				'compare' => 'LIKE',
			]]
		]);

		if (empty($projects)) {
			self::send_message($chat_id, "❌ *هیچ پروژه‌ای به شما اختصاص داده نشده است.*");
			return;
		}

		$msg = "📁 *لیست پروژه‌های تحت نظارت شما:*\n";
		$msg .= "برای مدیریت و مشاهده جزئیات بر روی نام پروژه کلیک کنید:";

		$inline_keyboard = [];
		foreach ($projects as $p) {
			$inline_keyboard[] = [
				['text' => '📁 ' . get_the_title($p->ID), 'callback_data' => 'view_proj_' . $p->ID]
			];
		}
		$inline_keyboard[] = [['text' => '↩ بازگشت به منو اصلی', 'callback_data' => 'back_to_menu']];

		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function send_expert_stats($chat_id, $user_id) {
		$projects = get_posts([
			'post_type' => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => -1,
			'meta_query' => [[
				'key' => '_cptt_experts_csv',
				'value' => ',' . (int)$user_id . ',',
				'compare' => 'LIKE',
			]]
		]);

		$total = count($projects);
		$completed = 0;
		$in_progress = 0;

		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			$total_steps = is_array($steps) ? count($steps) : 0;
			$done_steps = 0;
			if (is_array($steps)) {
				foreach ($steps as $s) {
					if (($s['status'] ?? 'todo') === 'done') $done_steps++;
				}
			}
			if ($total_steps > 0 && $done_steps >= $total_steps) $completed++; else $in_progress++;
		}

		$msg = "📊 *آمار و عملکرد کارشناسی شما:*\n\n";
		$msg .= "▫ تعداد کل پروژه‌ها: *" . $total . " پروژه*\n";
		$msg .= "▫ پروژه‌های در حال اجرا: *" . $in_progress . " مورد*\n";
		$msg .= "▫ پروژه‌های تکمیل شده: *" . $completed . " مورد*";

		$inline_keyboard = [
			[['text' => '↩ بازگشت به منو اصلی', 'callback_data' => 'back_to_menu']]
		];
		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function send_expert_commission($chat_id, $user_id) {
		$projects = get_posts([
			'post_type' => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => -1,
			'meta_query' => [[
				'key' => '_cptt_experts_csv',
				'value' => ',' . (int)$user_id . ',',
				'compare' => 'LIKE',
			]]
		]);

		$total_share = 0;
		$total_paid = 0;
		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (is_array($steps)) {
				foreach ($steps as $s) {
					$ae_id = isset($s['assigned_expert_id']) ? (int)$s['assigned_expert_id'] : 0;
					if ($ae_id === (int)$user_id) {
						$total_share += (float)($s['expert_share'] ?? 0);
						$total_paid += (float)($s['expert_paid'] ?? 0);
					}
				}
			}
		}
		$remain = $total_share - $total_paid;

		$msg = "💳 *وضعیت حساب و کتاب کارمزد همکار:*\n\n";
		$msg .= "💰 مجموع سهم کارمزد شما: *" . number_format($total_share) . " تومان*\n";
		$msg .= "💵 مجموع واریزی تسویه شده: *" . number_format($total_paid) . " تومان*\n";
		$msg .= "⏳ مانده طلب تسویه نشده: *" . number_format($remain) . " تومان*\n\n";
		
		if ($remain <= 0) {
			$msg .= "✅ حساب کارمزد شما تا این لحظه کاملاً تسویه شده است.";
		} else {
			$msg .= "⏳ این مبلغ پس از واریز توسط مدیریت به حساب شما تسویه خواهد شد.";
		}

		$inline_keyboard = [
			[['text' => '↩ بازگشت به منو اصلی', 'callback_data' => 'back_to_menu']]
		];
		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function send_expert_notif_settings($chat_id, $user_id) {
		$n_assign = get_user_meta($user_id, '_cptt_bale_notify_assign', true) !== '0';
		$n_chat = get_user_meta($user_id, '_cptt_bale_notify_chat', true) !== '0';
		$n_payout = get_user_meta($user_id, '_cptt_bale_notify_payout', true) !== '0';

		$msg = "⚙ *تنظیمات نوتیفیکیشن‌های ربات بله شما:*\n\n";
		$msg .= "با کلیک روی دکمه‌های زیر می‌توانید دریافت یا عدم دریافت نوتیفیکیش‌های هر بخش را در ربات بله کنترل کنید:";

		$inline_keyboard = [
			[[
				'text' => ($n_assign ? '✅ واگذاری پروژه: فعال' : '❌ واگذاری پروژه: غیرفعال'),
				'callback_data' => 'toggle_notif__cptt_bale_notify_assign'
			]],
			[[
				'text' => ($n_chat ? '✅ چت پروژه‌ها: فعال' : '❌ چت پروژه‌ها: غیرفعال'),
				'callback_data' => 'toggle_notif__cptt_bale_notify_chat'
			]],
			[[
				'text' => ($n_payout ? '✅ تسویه کارمزد: فعال' : '❌ تسویه کارمزد: غیرفعال'),
				'callback_data' => 'toggle_notif__cptt_bale_notify_payout'
			]],
			[['text' => '↩ بازگشت به منو اصلی', 'callback_data' => 'back_to_menu']]
		];

		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function toggle_expert_notif_meta($chat_id, $user_id, $meta_key) {
		if (!in_array($meta_key, ['_cptt_bale_notify_assign', '_cptt_bale_notify_chat', '_cptt_bale_notify_payout'], true)) {
			return;
		}
		$current = get_user_meta($user_id, $meta_key, true) !== '0';
		update_user_meta($user_id, $meta_key, $current ? '0' : '1');
		$this->send_expert_notif_settings($chat_id, $user_id);
	}

	private function send_expert_edit_projects_list($chat_id, $user_id) {
		$projects = get_posts([
			'post_type' => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => 10,
			'meta_query' => [[
				'key' => '_cptt_experts_csv',
				'value' => ',' . (int)$user_id . ',',
				'compare' => 'LIKE',
			]]
		]);

		if (empty($projects)) {
			self::send_message($chat_id, "❌ هیچ پروژه‌ای تحت نظارت شما یافت نشد.");
			return;
		}

		$msg = "📝 *انتخاب پروژه جهت تغییر مراحل:*\n\n";
		$msg .= "پروژه‌ای را که می‌خواهید وضعیت مراحل آن را تغییر دهید انتخاب کنید:";

		$inline_keyboard = [];
		foreach ($projects as $p) {
			$inline_keyboard[] = [
				['text' => '📁 ' . get_the_title($p->ID), 'callback_data' => 'list_steps_' . $p->ID]
			];
		}
		$inline_keyboard[] = [['text' => '↩ بازگشت به منو اصلی', 'callback_data' => 'back_to_menu']];

		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function send_expert_project_steps_to_edit($chat_id, $pid, $user_id) {
		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!is_array($steps) || empty($steps)) {
			self::send_message($chat_id, "❌ این پروژه هیچ مرحله‌ای ندارد.");
			return;
		}

		$msg = "📝 *لیست مراحل پروژه:* " . esc_html(get_the_title($pid)) . "\n\n";
		$msg .= "روی هر مرحله که می‌خواهید وضعیت آن را تغییر دهید کلیک کنید:";

		$inline_keyboard = [];
		foreach ($steps as $i => $s) {
			$st = $s['status'] ?? 'todo';
			$st_icon = $st === 'done' ? '✅' : ($st === 'current' ? '⏳' : '⚪');
			$step_id = $s['id'] ?? '';
			if ($step_id) {
				$inline_keyboard[] = [
					['text' => $st_icon . " " . ($i+1) . ". " . ($s['title'] ?? ''), 'callback_data' => 'change_step_' . $pid . '_' . $step_id]
				];
			}
		}
		$inline_keyboard[] = [['text' => '◀ بازگشت به لیست پروژه‌ها', 'callback_data' => 'expert_edit_projects']];

		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function show_step_status_options($chat_id, $pid, $step_id) {
		$steps = get_post_meta($pid, '_cptt_steps', true);
		$step_title = '';
		if (is_array($steps)) {
			foreach ($steps as $s) {
				if (($s['id'] ?? '') === $step_id) {
					$step_title = $s['title'] ?? '';
					break;
				}
			}
		}

		$msg = "⚙ *تغییر وضعیت مرحله:* " . esc_html($step_title) . "\n\n";
		$msg .= "وضعیت جدید این مرحله را مشخص کنید:";

		$inline_keyboard = [
			[
				['text' => '⏳ در حال انجام', 'callback_data' => 'set_st_status_' . $pid . '_' . $step_id . '_current'],
				['text' => '✅ انجام‌شده', 'callback_data' => 'set_st_status_' . $pid . '_' . $step_id . '_done']
			],
			[['text' => '◀ بازگشت به مراحل', 'callback_data' => 'list_steps_' . $pid]]
		];

		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function update_step_status_via_bot($chat_id, $pid, $step_id, $status, $user_id) {
		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!is_array($steps)) return;

		$found = false;
		$step_title = '';
		foreach ($steps as &$s) {
			if (($s['id'] ?? '') === $step_id) {
				$s['status'] = $status;
				$step_title = $s['title'] ?? '';
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

			$msg = "✓ *وضعیت مرحله `" . esc_html($step_title) . "` با موفقیت به " . ($status === 'done' ? 'انجام‌شده' : 'در حال انجام') . " تغییر یافت.*";
			self::send_message($chat_id, $msg);
		} else {
			self::send_message($chat_id, "❌ خطا در بروزرسانی مرحله.");
		}

		$this->send_expert_project_steps_to_edit($chat_id, $pid, $user_id);
	}

	private function send_admin_global_stats($chat_id) {
		$projects = get_posts(['post_type' => 'cptt_project', 'post_status' => 'any', 'numberposts' => -1]);
		$experts = get_users(['role' => 'cptt_expert']);
		$customers = get_users(['role' => 'customer']);

		$total_cost = 0;
		$total_paid = 0;
		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (is_array($steps)) {
				foreach ($steps as $s) {
					$total_cost += (float)($s['cost'] ?? 0);
					$total_paid += (float)($s['paid'] ?? 0);
				}
			}
		}

		$msg = "📊 *آمار جامع کل سیستم (CPTT):*\n\n";
		$msg .= "▫ تعداد کل پروژه‌ها: *" . count($projects) . " پروژه*\n";
		$msg .= "▫ کارشناسان فعال: *" . count($experts) . " همکار*\n";
		$msg .= "▫ مشتریان ثبت‌شده: *" . count($customers) . " کاربر*\n\n";
		$msg .= "💰 کل هزینه‌های مصوب سیستم: *" . number_format($total_cost) . " تومان*\n";
		$msg .= "💳 مجموع دریافتی‌ها تاکنون: *" . number_format($total_paid) . " تومان*\n";
		$msg .= "⏳ مجموع مانده کل سیستم: *" . number_format($total_cost - $total_paid) . " تومان*";

		$inline_keyboard = [
			[['text' => '↩ بازگشت به منو اصلی', 'callback_data' => 'back_to_menu']]
		];
		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function send_admin_projects($chat_id) {
		$projects = get_posts(['post_type' => 'cptt_project', 'post_status' => 'any', 'numberposts' => 10]);

		if (empty($projects)) {
			self::send_message($chat_id, "❌ هیچ پروژه‌ای در سیستم یافت نشد.");
			return;
		}

		$msg = "📁 *۱۰ پروژه اخیر ثبت شده در سیستم:*";
		
		$inline_keyboard = [];
		foreach ($projects as $p) {
			$inline_keyboard[] = [
				['text' => '📁 ' . get_the_title($p->ID), 'callback_data' => 'view_proj_' . $p->ID]
			];
		}
		$inline_keyboard[] = [['text' => '↩ بازگشت به منو اصلی', 'callback_data' => 'back_to_menu']];

		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function send_admin_experts_list($chat_id) {
		$experts = get_users(['role' => 'cptt_expert']);
		if (empty($experts)) {
			self::send_message($chat_id, "هیچ کارشناسی در سیستم یافت نشد.");
			return;
		}

		$msg = "🧑‍💼 *لیست همکاران و کارشناسان فعال:*\n\n";
		foreach ($experts as $ex) {
			$title = get_user_meta($ex->ID, 'cptt_expert_title', true) ?: 'کارشناس';
			$msg .= "👤 " . esc_html($ex->display_name) . " (`" . esc_html($title) . "`)\n";
			$msg .= "📞 شماره تماس: `" . (get_user_meta($ex->ID, 'billing_phone', true) ?: 'ثبت نشده') . "`\n\n";
		}

		$inline_keyboard = [
			[['text' => '↩ بازگشت به منو اصلی', 'callback_data' => 'back_to_menu']]
		];
		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function send_admin_broadcast_instructions($chat_id) {
		$msg = "📢 *راهنمای ارسال پیام همگانی:*\n\n";
		$msg .= "جهت ارسال یک پیام همگانی به تمام کاربران ثبت‌نام شده در ربات بله، دستور زیر را ارسال کنید:\n\n";
		$msg .= "`" . "/broadcast " . "پیام خود را در اینجا بنویسید`\n\n";
		$msg .= "*مثال:*\n";
		$msg .= "`/broadcast سلام همکاران گرامی، فردا پورتال بابت آپدیت به مدت ۲ ساعت در دسترس نخواهد بود.`";

		$inline_keyboard = [
			[['text' => '↩ بازگشت به منو اصلی', 'callback_data' => 'back_to_menu']]
		];
		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function send_admin_create_project_instructions($chat_id) {
		$msg = "➕ *راهنمای ثبت پروژه سریع از بله:*\n\n";
		$msg .= "جهت ثبت یک پروژه سریع بابت مشتری، دستور زیر را دقیقاً با الگو ارسال نمایید:\n\n";
		$msg .= "`" . "/create_project " . "عنوان پروژه @ شماره موبایل مشتری`\n\n";
		$msg .= "*مثال:*\n";
		$msg .= "`/create_project طراحی لوگو شرکت رادین @ 09123456789`\n\n";
		$msg .= "پس از ارسال، در صورت وجود مشتری با این شماره تماس، پروژه فورا ثبت شده و اعلان آن صادر می‌شود.";

		$inline_keyboard = [
			[['text' => '↩ بازگشت به منو اصلی', 'callback_data' => 'back_to_menu']]
		];
		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function trigger_manual_reminders($chat_id) {
		// Alert all experts with overdue steps and customers with pending tasks
		$projects = get_posts(['post_type' => 'cptt_project', 'post_status' => 'any', 'numberposts' => -1]);
		$now = current_time('timestamp', true);
		
		$notified_experts = 0;
		$notified_customers = 0;

		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) continue;

			// Remind experts of overdue steps
			if (class_exists('CPTT_Core')) {
				$expert_ids = CPTT_Core::get_project_expert_ids($p->ID);
				foreach ($steps as $s) {
					if (($s['status'] ?? 'todo') !== 'done' && !empty($s['due_at']) && (int)$s['due_at'] < $now) {
						foreach ($expert_ids as $eid) {
							self::notify_via_bale($eid, "⚠️ *هشدار تاخیر مرحله:* مرحله `" . ($s['title'] ?? '') . "` در پروژه `" . get_the_title($p->ID) . "` منقضی شده است! لطفا فورا پیگیری کنید.");
							$notified_experts++;
						}
					}
				}
			}

			// Remind clients of pending tasks
			$client_id = (int)get_post_meta($p->ID, '_cptt_client_user_id', true);
			if ($client_id) {
				$has_pending_tasks = false;
				foreach ($steps as $s) {
					$uts = isset($s['user_tasks']) && is_array($s['user_tasks']) ? $s['user_tasks'] : [];
					foreach ($uts as $ut) {
						if (empty($ut['done'])) {
							$has_pending_tasks = true;
							break;
						}
					}
					if ($has_pending_tasks) break;
				}
				if ($has_pending_tasks) {
					self::notify_via_bale($client_id, "⏰ *یادآوری اقدام مشتری:* شما تسک‌های معوقه و اقدام‌نشده در پروژه `" . get_the_title($p->ID) . "` دارید. لطفا جهت پیشبرد پروژه پاسخ خود را ثبت کنید.");
					$notified_customers++;
				}
			}
		}

		$msg = "⏰ *یادآوری‌ها با موفقیت صادر شد:*\n\n";
		$msg .= "▫ تعداد پیام‌های تاخیر صادر شده به کارشناسان: *" . $notified_experts . " مورد*\n";
		$msg .= "▫ تعداد پیام‌های یادآوری صادر شده به مشتریان: *" . $notified_customers . " مورد*";

		$inline_keyboard = [
			[['text' => '↩ بازگشت به منو اصلی', 'callback_data' => 'back_to_menu']]
		];
		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function send_broadcast($text) {
		$users = get_users([
			'meta_key' => '_cptt_bale_chat_id',
			'meta_compare' => 'EXISTS',
		]);
		foreach ($users as $u) {
			$cid = get_user_meta($u->ID, '_cptt_bale_chat_id', true);
			if ($cid) {
				self::send_message($cid, $text);
			}
		}
	}

	private function create_project_via_bot($title, $phone) {
		$phone = $this->to_english_digits($phone);
		$cust = $this->find_user_by_phone($phone);
		if (!$cust) {
			return new WP_Error('not_found', 'مشتری با این شماره موبایل یافت نشد.');
		}

		if (!class_exists('CPTT_Expert')) {
			return new WP_Error('not_loaded', 'افزونه به درستی لود نشده است.');
		}

		// Instantiate expert class and create project
		$data = [
			'title' => $title,
			'client_user_id' => $cust->ID,
			'expert_user_ids' => [get_current_user_id()],
		];
		
		// Use reflect to call private create_project method or replicate simple version
		$project_id = wp_insert_post([
			'post_type' => 'cptt_project',
			'post_status' => 'publish',
			'post_title' => $title,
		]);

		if (is_wp_error($project_id) || !$project_id) {
			return new WP_Error('db_error', 'خطا در ثبت پایگاه داده.');
		}

		update_post_meta($project_id, '_cptt_client_user_id', $cust->ID);
		// create a default step
		$steps = [[
			'id' => 'st_' . wp_rand(1000, 9999),
			'title' => 'شروع پروژه',
			'status' => 'current',
			'cost' => 0,
			'paid' => 0,
			'checklist' => [],
			'user_tasks' => [],
		]];
		update_post_meta($project_id, '_cptt_steps', $steps);
		
		$now = (int)current_time('timestamp', true);
		update_post_meta($project_id, '_cptt_last_update', $now);
		update_post_meta($project_id, '_cptt_last_update_fa', class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now));

		return $project_id;
	}

	private function send_project_detail($chat_id, $pid, $user) {
		$p = get_post($pid);
		if (!$p || $p->post_type !== 'cptt_project') {
			self::send_message($chat_id, "❌ پروژه مورد نظر یافت نشد.");
			return;
		}

		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];

		$total = count($steps);
		$done = 0;
		foreach ($steps as $s) if (($s['status'] ?? 'todo') === 'done') $done++;
		$percent = $total ? round(($done / $total) * 100) : 0;

		$msg = "🏢 *پروژه: " . esc_html(get_the_title($pid)) . "*\n";
		$msg .= "🔑 کد پروژه: `" . $pid . "`\n";
		$msg .= "📊 پیشرفت فیزیکی پروژه: *" . $percent . "%*\n\n";
		$msg .= "📝 *روند اجرای مراحل پروژه:*\n\n";

		foreach ($steps as $i => $s) {
			$st = $s['status'] ?? 'todo';
			$st_icon = $st === 'done' ? '✅' : ($st === 'current' ? '⏳' : '⚪');
			$st_label = $st === 'done' ? 'انجام‌شده' : ($st === 'current' ? 'در حال انجام' : 'انجام‌نشده');
			
			$msg .= $st_icon . " *" . ($i+1) . ". " . esc_html($s['title'] ?? '') . "* (" . $st_label . ")\n";
			if (!empty($s['due_at_fa'])) $msg .= "   📅 مهلت: " . esc_html($s['due_at_fa']) . "\n";
			
			$cl = isset($s['checklist']) && is_array($s['checklist']) ? $s['checklist'] : [];
			if (!empty($cl)) {
				$cl_total = count($cl);
				$cl_done = 0;
				foreach ($cl as $it) if (!empty($it['done'])) $cl_done++;
				$msg .= "   📋 چک‌لیست: " . $cl_done . " از " . $cl_total . "\n";
			}
			$msg .= "\n";
		}

		$inline_keyboard = [
			[['text' => '💳 مشاهده حساب و کتاب مالی پروژه', 'callback_data' => 'view_pay_' . $pid]],
			[['text' => '↩ بازگشت به منو اصلی', 'callback_data' => 'back_to_menu']]
		];
		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function send_project_payout_detail($chat_id, $pid, $user) {
		$p = get_post($pid);
		if (!$p || $p->post_type !== 'cptt_project') {
			self::send_message($chat_id, "❌ پروژه مورد نظر یافت نشد.");
			return;
		}

		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];

		$total_cost = 0;
		$total_paid = 0;
		$expert_share = 0;
		$expert_paid = 0;

		$user_id = $user->ID;
		foreach ($steps as $s) {
			$total_cost += (float)($s['cost'] ?? 0);
			$total_paid += (float)($s['paid'] ?? 0);
			
			$ae_id = isset($s['assigned_expert_id']) ? (int)$s['assigned_expert_id'] : 0;
			if ($ae_id === (int)$user_id) {
				$expert_share += (float)($s['expert_share'] ?? 0);
				$expert_paid += (float)($s['expert_paid'] ?? 0);
			}
		}

		$msg = "💵 *گزارش مالی پروژه: " . esc_html(get_the_title($pid)) . "*\n";
		$msg .= "🔑 کد پروژه: `" . $pid . "`\n\n";
		
		$role = $this->get_user_role($user);
		if ($role === 'admin' || $role === 'customer') {
			$msg .= "🏢 *حساب کارفرما با مجموعه:*\n";
			$msg .= "▫ مجموع کل هزینه پروژه: *" . number_format($total_cost) . " تومان*\n";
			$msg .= "▫ مجموع مبالغ پرداختی: *" . number_format($total_paid) . " تومان*\n";
			$msg .= "⏳ مانده بدهی کارفرما: *" . number_format($total_cost - $total_paid) . " تومان*\n\n";
		}
		
		if ($role === 'expert' || $role === 'admin') {
			$msg .= "💼 *حساب کارشناس بابت این پروژه:*\n";
			$msg .= "▫ سهم کارمزد کارشناس: *" . number_format($expert_share) . " تومان*\n";
			$msg .= "▫ پرداختی تسویه شده: *" . number_format($expert_paid) . " تومان*\n";
			$msg .= "⏳ مانده طلب کارشناس: *" . number_format($expert_share - $expert_paid) . " تومان*\n\n";
		}

		$inline_keyboard = [
			[['text' => '📝 مشاهده روند مراحل پروژه', 'callback_data' => 'view_proj_' . $pid]],
			[['text' => '↩ بازگشت به منو اصلی', 'callback_data' => 'back_to_menu']]
		];
		$reply_markup = ['inline_keyboard' => $inline_keyboard];
		self::send_message($chat_id, $msg, $reply_markup);
	}

	private function to_english_digits($string) {
		$persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
		$arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
		$num = range(0, 9);
		$string = str_replace($persian, $num, $string);
		return str_replace($arabic, $num, $string);
	}

	public static function notify_via_bale($user_id, $message, $type = '') {
		$settings = self::get_settings();
		
		$map = [
			'project_assigned'  => 'expert_assign',
			'project_chat'      => 'expert_chat',
			'direct_chat'       => 'expert_chat',
			'expert_payout'     => 'expert_payout',
			'project_completed' => 'client_complete',
			'user_task'         => 'client_task',
		];
		
		$mapped_type = isset($map[$type]) ? $map[$type] : $type;
		if ($mapped_type !== '') {
			$is_enabled = isset($settings[$mapped_type]) ? $settings[$mapped_type] : '1';
			if ($is_enabled !== '1') return;
		}

		$chat_id = get_user_meta((int)$user_id, '_cptt_bale_chat_id', true);
		if ($chat_id) {
			self::send_message($chat_id, "🔔 " . $message);
		}
	}
}
