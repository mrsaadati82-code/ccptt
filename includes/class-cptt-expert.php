<?php
if ( ! defined('ABSPATH') ) exit;

class CPTT_Expert {
	private static $instance = null;
	const QUERY_VAR = 'cptt_expert_dashboard';
	const REWRITE_OPTION = 'cptt_expert_rewrite_version';

	public static function instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_shortcode('cptt_expert_dashboard', [$this, 'shortcode_dashboard']);
		add_action('init', [$this, 'register_rewrite']);
		add_action('init', [$this, 'maybe_flush_rewrite'], 30);
		add_filter('query_vars', [$this, 'query_vars']);
		add_action('template_redirect', [$this, 'maybe_render_virtual_dashboard']);
		add_action('wp_enqueue_scripts', [$this, 'register_assets']);
		add_action('wp_ajax_cptt_expert_save_project', [$this, 'ajax_save_project']);
		add_action('wp_ajax_cptt_expert_create_project', [$this, 'ajax_create_project']);
		add_action('wp_ajax_cptt_expert_send_message', [$this, 'ajax_send_message']);
		add_action('wp_ajax_cptt_expert_fetch_messages', [$this, 'ajax_fetch_messages']);
		add_action('admin_init', [$this, 'redirect_experts_from_admin'], 1);
	}

	public static function dashboard_url() {
		return add_query_arg(self::QUERY_VAR, 1, home_url('/'));
	}

	public function register_rewrite() {
		add_rewrite_rule('^cptt-expert-dashboard/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
	}

	public function maybe_flush_rewrite() {
		if (get_option(self::REWRITE_OPTION) === CPTT_VERSION) return;
		flush_rewrite_rules(false);
		update_option(self::REWRITE_OPTION, CPTT_VERSION, false);
	}

	public function query_vars($vars) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	private function current_user_is_expert_only() {
		$user = wp_get_current_user();
		$roles = (array) $user->roles;
		return in_array('cptt_expert', $roles, true) && !in_array('administrator', $roles, true);
	}

	private function current_user_can_view_dashboard() {
		if (!is_user_logged_in()) return false;
		$user = wp_get_current_user();
		$roles = (array) $user->roles;
		return in_array('cptt_expert', $roles, true) || in_array('administrator', $roles, true);
	}

	public function redirect_experts_from_admin() {
		if (!is_admin()) return;
		if (!is_user_logged_in()) return;
		if (!$this->current_user_is_expert_only()) return;
		if (wp_doing_ajax()) return;
		$script = isset($_SERVER['PHP_SELF']) ? basename((string)$_SERVER['PHP_SELF']) : '';
		if (in_array($script, ['admin-ajax.php', 'admin-post.php', 'async-upload.php'], true)) return;
		wp_safe_redirect(self::dashboard_url());
		exit;
	}

	public function register_assets() {
		wp_register_script('cptt-expert', CPTT_URL . 'assets/js/expert.js', [], CPTT_VERSION, true);
		wp_localize_script('cptt-expert', 'CPTT_EXPERT', [
			'ajax' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('cptt_expert_nonce'),
			'dashboardUrl' => self::dashboard_url(),
			'texts' => [
				'saving' => 'در حال ذخیره...',
				'saved' => 'تغییرات با موفقیت ذخیره شد.',
				'error' => 'خطا در ذخیره اطلاعات',
			],
		]);
	}

	private function enqueue_assets() {
		if (!wp_style_is('cptt-frontend', 'registered')) {
			wp_register_style('cptt-frontend', CPTT_URL . 'assets/css/frontend.css', [], CPTT_VERSION);
		}
		if (!wp_script_is('cptt-frontend', 'registered')) {
			wp_register_script('cptt-frontend', CPTT_URL . 'assets/js/frontend.js', [], CPTT_VERSION, true);
			wp_localize_script('cptt-frontend', 'CPTT_FRONTEND', [
				'ajax' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('cptt_frontend_nonce'),
			]);
		}
		if (!wp_script_is('cptt-expert', 'registered')) {
			$this->register_assets();
		}
		wp_enqueue_style('cptt-frontend');
		wp_enqueue_script('cptt-frontend');
		wp_enqueue_script('cptt-expert');
	}

	public function maybe_render_virtual_dashboard() {
		if (!get_query_var(self::QUERY_VAR)) return;
		if (!is_user_logged_in()) {
			auth_redirect();
		}
		if (!$this->current_user_can_view_dashboard()) {
			wp_die('شما به داشبورد کارشناس دسترسی ندارید.');
		}

		$this->enqueue_assets();
		status_header(200);
		nocache_headers();
		?><!doctype html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class('cptt-expert-dashboard-page'); ?>>
	<?php if (function_exists('wp_body_open')) wp_body_open(); ?>
	<div class="cptt-shell-page">
		<?php echo $this->shortcode_dashboard([]); ?>
	</div>
	<?php wp_footer(); ?>
</body>
</html><?php
		exit;
	}

	private function get_expert_projects($user_id) {
		$user_id = (int) $user_id;
		$user = get_user_by('id', $user_id);
		if (!$user) return [];
		$roles = (array) $user->roles;
		$args = [
			'post_type' => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => -1,
			'orderby' => 'date',
			'order' => 'DESC',
		];
		if (!in_array('administrator', $roles, true)) {
			$args['meta_query'] = [[
				'key' => '_cptt_experts_csv',
				'value' => ',' . $user_id . ',',
				'compare' => 'LIKE',
			]];
		}
		return get_posts($args);
	}

	private function can_manage_project($project_id, $user_id) {
		$project_id = (int) $project_id;
		$user_id = (int) $user_id;
		if (!$project_id || !$user_id) return false;
		if (user_can($user_id, 'manage_options')) return true;
		if (!class_exists('CPTT_Core') || !method_exists('CPTT_Core', 'get_project_expert_ids')) return false;
		return in_array($user_id, CPTT_Core::get_project_expert_ids($project_id), true);
	}


	private static function get_existing_experts($project_id) {
		if (class_exists('CPTT_Core') && method_exists('CPTT_Core', 'get_project_expert_ids')) return CPTT_Core::get_project_expert_ids($project_id);
		return [];
	}

	private function progress_data($project_id) {
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps) || empty($steps)) {
			return ['percent' => 0, 'done' => 0, 'total' => 0, 'status' => 'in_progress', 'label' => 'در حال انجام'];
		}
		$total = count($steps);
		$done = 0;
		foreach ($steps as $s) if (($s['status'] ?? '') === 'done') $done++;
		$percent = $total ? (int) round(($done / $total) * 100) : 0;
		$status = ($total > 0 && $done >= $total) ? 'completed' : 'in_progress';
		return ['percent' => $percent, 'done' => $done, 'total' => $total, 'status' => $status, 'label' => $status === 'completed' ? 'تکمیل شده' : 'در حال انجام'];
	}

	private function financial_data($project_id) {
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];
		$cost = 0;
		$paid = 0;
		foreach ($steps as $s) {
			$cost += (float)($s['cost'] ?? 0);
			$paid += (float)($s['paid'] ?? 0);
		}
		$remain = $cost - $paid;
		$percent = $cost > 0 ? round(($paid / $cost) * 100, 1) : 0;
		return ['cost' => $cost, 'paid' => $paid, 'remain' => $remain, 'percent' => $percent];
	}

	private function get_product_title($product_id) {
		$product_id = (int) $product_id;
		if (!$product_id) return '—';
		$title = get_the_title($product_id);
		return $title ? $title : ('#' . $product_id);
	}

	private function get_customer_name($project_id) {
		$uid = (int) get_post_meta($project_id, '_cptt_client_user_id', true);
		$u = $uid ? get_user_by('id', $uid) : null;
		return $u ? $u->display_name : '—';
	}

	private function get_expert_names($project_id) {
		$names = [];
		if (class_exists('CPTT_Core') && method_exists('CPTT_Core', 'get_project_expert_ids')) {
			foreach (CPTT_Core::get_project_expert_ids($project_id) as $id) {
				$u = get_user_by('id', (int)$id);
				if ($u) $names[] = $u->display_name;
			}
		}
		return array_values(array_unique(array_filter($names)));
	}

	private function get_recent_notes($project_id, $limit = 4) {
		$notes = get_post_meta($project_id, '_cptt_project_notes', true);
		if (!is_array($notes)) return [];
		$notes = array_reverse($notes);
		return array_slice($notes, 0, $limit);
	}

	private function collect_dashboard_stats($projects) {
		$total = count($projects);
		$completed = 0;
		$in_progress = 0;
		$today = [];
		$overdue = [];
		$customer_pending = 0;
		$open_steps = 0;
		$today_start = strtotime('today midnight');
		$today_end = strtotime('tomorrow midnight') - 1;
		$now = (int) current_time('timestamp', true);

		foreach ($projects as $p) {
			$progress = $this->progress_data($p->ID);
			if ($progress['status'] === 'completed') $completed++; else $in_progress++;
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) $steps = [];
			foreach ($steps as $step) {
				if (($step['status'] ?? 'todo') !== 'done') $open_steps++;
				$due = (int)($step['due_at'] ?? 0);
				if ($due && ($step['status'] ?? 'todo') !== 'done') {
					$item = [
						'project_id' => $p->ID,
						'project_title' => get_the_title($p->ID),
						'step_title' => (string)($step['title'] ?? ''),
						'due_fa' => (string)($step['due_at_fa'] ?? ''),
					];
					if ($due >= $today_start && $due <= $today_end) $today[] = $item;
					elseif ($due < $now) $overdue[] = $item;
				}
				if (!empty($step['user_tasks']) && is_array($step['user_tasks'])) {
					foreach ($step['user_tasks'] as $task) {
						if (!is_array($task)) continue;
						if (empty($task['done'])) $customer_pending++;
					}
				}
			}
		}

		return [
			'total' => $total,
			'completed' => $completed,
			'in_progress' => $in_progress,
			'open_steps' => $open_steps,
			'customer_pending' => $customer_pending,
			'today' => $today,
			'overdue' => $overdue,
		];
	}

	private function normalize_expert_posted_steps($raw) {
		$out = [];
		if (!is_array($raw)) return $out;
		foreach ($raw as $step_id => $row) {
			$step_id = sanitize_text_field((string)$step_id);
			if ($step_id === '' || !is_array($row)) continue;
			$status = sanitize_key($row['status'] ?? 'todo');
			if (!in_array($status, ['todo', 'current', 'done'], true)) $status = 'todo';
			$checklist = [];
			if (!empty($row['checklist']) && is_array($row['checklist'])) {
				foreach ($row['checklist'] as $check_id => $item) {
					$check_id = sanitize_text_field((string)$check_id);
					if ($check_id === '') continue;
					if (is_array($item)) {
						$checklist[$check_id] = [
							'done' => !empty($item['done']) ? 1 : 0,
							'text' => sanitize_text_field($item['text'] ?? ''),
							'url' => esc_url_raw($item['url'] ?? ''),
						];
					} else {
						$checklist[$check_id] = ['done' => !empty($item) ? 1 : 0, 'text' => '', 'url' => ''];
					}
				}
			}
			$user_tasks = [];
			if (!empty($row['user_tasks']) && is_array($row['user_tasks'])) {
				foreach ($row['user_tasks'] as $task_id => $task) {
					$task_id = sanitize_text_field((string)$task_id);
					if ($task_id === '' || !is_array($task)) continue;
					$user_tasks[$task_id] = [
						'title' => sanitize_text_field($task['title'] ?? ''),
						'desc' => wp_kses_post($task['desc'] ?? ''),
						'due_at_local' => sanitize_text_field($task['due_at_local'] ?? ''),
						'sms_remind' => !empty($task['sms_remind']) ? 1 : 0,
					];
				}
			}
			$out[$step_id] = [
				'status' => $status,
				'title' => sanitize_text_field($row['title'] ?? ''),
				'desc' => wp_kses_post($row['desc'] ?? ''),
				'due_at_local' => sanitize_text_field($row['due_at_local'] ?? ''),
				'cost' => isset($row['cost']) ? (float)$row['cost'] : 0,
				'paid' => isset($row['paid']) ? (float)$row['paid'] : 0,
				'checklist' => $checklist,
				'user_tasks' => $user_tasks,
			];
		}
		return $out;
	}

	private function apply_checklist_timestamps(&$current_item, $new_done, $old_item, $now, $user_id) {
		if ($new_done) {
			$current_item['done'] = 1;
			if (!empty($old_item['done']) && !empty($old_item['done_at'])) {
				$current_item['done_at'] = (int)$old_item['done_at'];
				$current_item['done_at_fa'] = !empty($old_item['done_at_fa']) ? (string)$old_item['done_at_fa'] : (class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime((int)$old_item['done_at']) : date('Y-m-d H:i', (int)$old_item['done_at']));
				if (!empty($old_item['done_by'])) $current_item['done_by'] = (int)$old_item['done_by'];
			} else {
				$current_item['done_at'] = $now;
				$current_item['done_at_fa'] = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now);
				$current_item['done_by'] = $user_id;
			}
		} else {
			$current_item['done'] = 0;
			unset($current_item['done_at'], $current_item['done_at_fa'], $current_item['done_by']);
		}
	}

	private function apply_step_status_from_checklist($step, $fallback_status = 'todo') {
		$checklist = isset($step['checklist']) && is_array($step['checklist']) ? $step['checklist'] : [];
		$total = 0;
		$done = 0;
		foreach ($checklist as $it) {
			if (!is_array($it) || empty($it['text'])) continue;
			$total++;
			if (!empty($it['done'])) $done++;
		}
		if ($total > 0) {
			if ($done >= $total) return 'done';
			return $fallback_status === 'done' ? 'current' : $fallback_status;
		}
		return $fallback_status;
	}

	private function save_expert_project($project_id, $posted_steps, $note, $meta = []) {
		$project_id = (int) $project_id;
		$user_id = (int) get_current_user_id();
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];
		$now = (int) current_time('timestamp', true);
		$changed = false;
		$current_found = false;

		$project_title = sanitize_text_field($meta['project_title'] ?? '');
		if ($project_title !== '' && $project_title !== get_the_title($project_id)) {
			wp_update_post(['ID' => $project_id, 'post_title' => $project_title]);
			$changed = true;
		}
		$client_id = isset($meta['client_user_id']) ? absint($meta['client_user_id']) : (int)get_post_meta($project_id, '_cptt_client_user_id', true);
		$product_id = isset($meta['product_id']) ? absint($meta['product_id']) : (int)get_post_meta($project_id, '_cptt_product_id', true);
		$is_settled = !empty($meta['is_settled']) ? 1 : 0;
		$expert_ids = isset($meta['expert_user_ids']) && is_array($meta['expert_user_ids']) ? array_values(array_filter(array_unique(array_map('absint', $meta['expert_user_ids'])))) : self::get_existing_experts($project_id);
		$cat_ids = isset($meta['wc_cat_ids']) && is_array($meta['wc_cat_ids']) ? array_values(array_filter(array_unique(array_map('intval', $meta['wc_cat_ids'])))) : (array)get_post_meta($project_id, '_cptt_wc_cat_ids', true);
		update_post_meta($project_id, '_cptt_client_user_id', $client_id);
		update_post_meta($project_id, '_cptt_product_id', $product_id);
		update_post_meta($project_id, '_cptt_is_settled', $is_settled);
		update_post_meta($project_id, '_cptt_expert_user_ids', $expert_ids);
		update_post_meta($project_id, '_cptt_expert_user_id', !empty($expert_ids) ? (int)$expert_ids[0] : 0);
		update_post_meta($project_id, '_cptt_experts_csv', ',' . implode(',', $expert_ids) . ',');
		update_post_meta($project_id, '_cptt_wc_cat_ids', $cat_ids);
		update_post_meta($project_id, '_cptt_wc_cats_csv', ',' . implode(',', $cat_ids) . ',');
		$changed = true;
		if ($product_id) {
			update_post_meta($project_id, '_cptt_wc_product_id', $product_id);
		}
		$deadline_local = trim((string)($meta['deadline_local'] ?? ''));
		if ($deadline_local !== '' && class_exists('CPTT_Core') && method_exists('CPTT_Core', 'parse_jalali_datetime')) {
			$deadline = (int) CPTT_Core::parse_jalali_datetime($deadline_local);
			if ($deadline) {
				update_post_meta($project_id, '_cptt_deadline_at', $deadline);
				update_post_meta($project_id, '_cptt_deadline_at_fa', CPTT_Core::jalali_datetime($deadline));
			}
		}

		foreach ($steps as &$step) {
			if (!is_array($step)) continue;
			$step_id = !empty($step['id']) ? (string)$step['id'] : '';
			if ($step_id === '' || !isset($posted_steps[$step_id])) continue;
			$posted = $posted_steps[$step_id];
			$old_status = (string)($step['status'] ?? 'todo');
			$new_status = (string)($posted['status'] ?? $old_status);
			if (!in_array($new_status, ['todo', 'current', 'done'], true)) $new_status = 'todo';
			$step_changed = false;
			$new_title = sanitize_text_field($posted['title'] ?? ($step['title'] ?? ''));
			if (($step['title'] ?? '') !== $new_title) { $step['title'] = $new_title; $step_changed = true; }
			$new_desc = wp_kses_post($posted['desc'] ?? ($step['desc'] ?? ''));
			if (($step['desc'] ?? '') !== $new_desc) { $step['desc'] = $new_desc; $step_changed = true; }
			$new_cost = isset($posted['cost']) ? (float)$posted['cost'] : (float)($step['cost'] ?? 0);
			$new_paid = isset($posted['paid']) ? (float)$posted['paid'] : (float)($step['paid'] ?? 0);
			if ((float)($step['cost'] ?? 0) !== $new_cost) { $step['cost'] = $new_cost; $step_changed = true; }
			if ((float)($step['paid'] ?? 0) !== $new_paid) { $step['paid'] = $new_paid; $step_changed = true; }
			$due_local = trim((string)($posted['due_at_local'] ?? ''));
			if ($due_local !== '' && class_exists('CPTT_Core') && method_exists('CPTT_Core', 'parse_jalali_datetime')) {
				$new_due = (int) CPTT_Core::parse_jalali_datetime($due_local);
				if (!empty($new_due) && (int)($step['due_at'] ?? 0) !== $new_due) {
					$step['due_at'] = $new_due;
					$step['due_at_fa'] = CPTT_Core::jalali_datetime($new_due);
					$step_changed = true;
				}
			}

			if (!empty($step['checklist']) && is_array($step['checklist'])) {
				foreach ($step['checklist'] as &$item) {
					if (!is_array($item)) continue;
					$check_id = !empty($item['id']) ? (string)$item['id'] : '';
					if ($check_id === '') continue;
					$old_item = $item;
					$pitem = $posted['checklist'][$check_id] ?? [];
					$new_done = !empty($pitem['done']);
					$before_done = !empty($old_item['done']) ? 1 : 0;
					$this->apply_checklist_timestamps($item, $new_done, $old_item, $now, $user_id);
					$new_text = sanitize_text_field($pitem['text'] ?? ($item['text'] ?? ''));
					$new_url = esc_url_raw($pitem['url'] ?? ($item['url'] ?? ''));
					if (($item['text'] ?? '') !== $new_text) { $item['text'] = $new_text; $step_changed = true; }
					if (($item['url'] ?? '') !== $new_url) { $item['url'] = $new_url; $step_changed = true; }
					if ($before_done !== (int)!empty($item['done'])) { $changed = true; $step_changed = true; }
				}
				unset($item);
			}

			if (!empty($step['user_tasks']) && is_array($step['user_tasks'])) {
				foreach ($step['user_tasks'] as &$task) {
					if (!is_array($task)) continue;
					$task_id = !empty($task['id']) ? (string)$task['id'] : '';
					if ($task_id === '' || empty($posted['user_tasks'][$task_id])) continue;
					$ptask = $posted['user_tasks'][$task_id];
					$new_ut_title = sanitize_text_field($ptask['title'] ?? ($task['title'] ?? ''));
					$new_ut_desc = wp_kses_post($ptask['desc'] ?? ($task['desc'] ?? ''));
					$new_ut_sms = !empty($ptask['sms_remind']) ? 1 : 0;
					if (($task['title'] ?? '') !== $new_ut_title) { $task['title'] = $new_ut_title; $step_changed = true; }
					if (($task['desc'] ?? '') !== $new_ut_desc) { $task['desc'] = $new_ut_desc; $step_changed = true; }
					if ((int)($task['sms_remind'] ?? 0) !== $new_ut_sms) { $task['sms_remind'] = $new_ut_sms; $step_changed = true; }
					$ut_due_local = trim((string)($ptask['due_at_local'] ?? ''));
					if ($ut_due_local !== '' && class_exists('CPTT_Core') && method_exists('CPTT_Core', 'parse_jalali_datetime')) {
						$new_ut_due = (int) CPTT_Core::parse_jalali_datetime($ut_due_local);
						if (!empty($new_ut_due) && (int)($task['due_at'] ?? 0) !== $new_ut_due) {
							$task['due_at'] = $new_ut_due;
							$task['due_at_fa'] = CPTT_Core::jalali_datetime($new_ut_due);
							$step_changed = true;
						}
					}
				}
				unset($task);
			}

			$new_status = $this->apply_step_status_from_checklist($step, $new_status);
			if ($new_status === 'current') {
				if ($current_found) $new_status = 'todo';
				$current_found = true;
			}
			if ($old_status !== $new_status || $step_changed) {
				$step['status'] = $new_status;
				$step['updated_at'] = $now;
				$step['updated_at_fa'] = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now);
				$step['updated_by'] = $user_id;
				$changed = true;
			}
		}
		unset($step);

		if (!$current_found) {
			foreach ($steps as &$step) {
				if (!is_array($step)) continue;
				if (($step['status'] ?? 'todo') === 'todo') {
					$step['status'] = 'current';
					$step['updated_at'] = $now;
					$step['updated_at_fa'] = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now);
					$step['updated_by'] = $user_id;
					$changed = true;
					break;
				}
			}
			unset($step);
		}

		$note = trim((string)$note);
		if ($note !== '') {
			$notes = get_post_meta($project_id, '_cptt_project_notes', true);
			if (!is_array($notes)) $notes = [];
			$notes[] = ['user_id' => $user_id, 'time' => $now, 'content' => sanitize_textarea_field($note)];
			update_post_meta($project_id, '_cptt_project_notes', $notes);
			$changed = true;
		}

		if ($changed) {
			update_post_meta($project_id, '_cptt_steps', $steps);
			update_post_meta($project_id, '_cptt_last_update', $now);
			update_post_meta($project_id, '_cptt_last_update_fa', class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now));
			if (class_exists('CPTT_SMS') && method_exists('CPTT_SMS', 'maybe_notify_project_completed')) CPTT_SMS::maybe_notify_project_completed($project_id);
		}

		$progress = $this->progress_data($project_id);
		return ['changed' => $changed, 'last_update_fa' => (string)get_post_meta($project_id, '_cptt_last_update_fa', true), 'progress' => $progress, 'notes' => $this->get_recent_notes($project_id, 4)];
	}

	public function ajax_save_project() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
		if (!$project_id || get_post_type($project_id) !== 'cptt_project') wp_send_json_error('invalid_project', 400);
		if (!$this->can_manage_project($project_id, get_current_user_id())) wp_send_json_error('no_access', 403);
		$posted_steps = $this->normalize_expert_posted_steps(isset($_POST['steps']) ? $_POST['steps'] : []);
		$note = isset($_POST['note']) ? wp_unslash((string)$_POST['note']) : '';
		$result = $this->save_expert_project($project_id, $posted_steps, $note, $_POST);
		wp_send_json_success($result);
	}

	private function project_card_data($project_id) {
		$product_id = (int) get_post_meta($project_id, '_cptt_product_id', true);
		if (!$product_id) $product_id = (int) get_post_meta($project_id, '_cptt_wc_product_id', true);
		$progress = $this->progress_data($project_id);
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];
		$cat_ids = get_post_meta($project_id, '_cptt_wc_cat_ids', true);
		if (!is_array($cat_ids)) $cat_ids = [];
		$cat_names = [];
		foreach ($cat_ids as $cid) {
			$term = get_term((int)$cid, 'product_cat');
			if ($term && !is_wp_error($term)) $cat_names[] = $term->name;
		}
		$checklist_total = 0;
		$checklist_done = 0;
		$user_tasks_total = 0;
		$user_tasks_done = 0;
		foreach ($steps as $step) {
			if (!empty($step['checklist']) && is_array($step['checklist'])) {
				foreach ($step['checklist'] as $it) {
					if (!is_array($it) || empty($it['text'])) continue;
					$checklist_total++;
					if (!empty($it['done'])) $checklist_done++;
				}
			}
			if (!empty($step['user_tasks']) && is_array($step['user_tasks'])) {
				foreach ($step['user_tasks'] as $task) {
					if (!is_array($task) || empty($task['title'])) continue;
					$user_tasks_total++;
					if (!empty($task['done'])) $user_tasks_done++;
				}
			}
		}
		return [
			'progress' => $progress,
			'financial' => $this->financial_data($project_id),
			'customer' => $this->get_customer_name($project_id),
			'customer_id' => (int) get_post_meta($project_id, '_cptt_client_user_id', true),
			'product' => $this->get_product_title($product_id),
			'product_id' => $product_id,
			'term_ids' => $cat_ids,
			'term_names' => $cat_names,
			'experts' => $this->get_expert_names($project_id),
			'deadline' => (string) get_post_meta($project_id, '_cptt_deadline_at_fa', true),
			'last_update' => (string) get_post_meta($project_id, '_cptt_last_update_fa', true),
			'settled' => (int) get_post_meta($project_id, '_cptt_is_settled', true),
			'checklist_total' => $checklist_total,
			'checklist_done' => $checklist_done,
			'user_tasks_total' => $user_tasks_total,
			'user_tasks_done' => $user_tasks_done,
			'notes' => $this->get_recent_notes($project_id, 4),
			'messages' => $this->get_recent_messages($project_id, 8),
		];
	}

	private function render_note_list($notes) {
		if (empty($notes) || !is_array($notes)) {
			echo '<div class="cptt-expert-emptyMini">یادداشتی ثبت نشده است.</div>';
			return;
		}
		echo '<div class="cptt-expert-noteList">';
		foreach ($notes as $note) {
			$user = !empty($note['user_id']) ? get_user_by('id', (int)$note['user_id']) : null;
			$name = $user ? $user->display_name : 'کاربر';
			$time = !empty($note['time']) && class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime((int)$note['time']) : '';
			echo '<div class="cptt-expert-noteItem">';
			echo '<div class="cptt-expert-noteItem__head"><strong>' . esc_html($name) . '</strong><span>' . esc_html($time) . '</span></div>';
			echo '<div class="cptt-expert-noteItem__body">' . nl2br(esc_html((string)($note['content'] ?? ''))) . '</div>';
			echo '</div>';
		}
		echo '</div>';
	}

	private function render_project_manage_form($project_id) {
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];
		$project_title = get_the_title($project_id);
		$client_id = (int)get_post_meta($project_id, '_cptt_client_user_id', true);
		$product_id = (int)get_post_meta($project_id, '_cptt_product_id', true);
		if (!$product_id) $product_id = (int)get_post_meta($project_id, '_cptt_wc_product_id', true);
		$selected_cats = get_post_meta($project_id, '_cptt_wc_cat_ids', true);
		if (!is_array($selected_cats)) $selected_cats = [];
		$experts = self::get_existing_experts($project_id);
		$deadline_at = (int)get_post_meta($project_id, '_cptt_deadline_at', true);
		$deadline_local = $deadline_at && class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($deadline_at) : '';
		$is_settled = (int)get_post_meta($project_id, '_cptt_is_settled', true);
		$customers = $this->customer_users();
		$products = $this->products();
		$cats = $this->category_terms();
		$experts_all = get_users(['role__in' => ['cptt_expert','administrator'], 'fields' => ['ID','display_name','user_email']]);
		?>
		<form class="cptt-expert-project-form" data-project-id="<?php echo esc_attr($project_id); ?>">
			<input type="hidden" name="project_id" value="<?php echo esc_attr($project_id); ?>">
			<div class="cptt-expert-projectMeta">
				<div class="cptt-expert-sectionTitle">اطلاعات پروژه</div>
				<div class="cptt-createProjectGrid">
					<label><span>عنوان پروژه</span><input type="text" name="project_title" value="<?php echo esc_attr($project_title); ?>"></label>
					<label><span>مشتری</span><select name="client_user_id"><option value="">— انتخاب مشتری —</option><?php foreach ($customers as $u): ?><option value="<?php echo esc_attr($u->ID); ?>" <?php selected($client_id, $u->ID); ?>><?php echo esc_html($u->display_name . (!empty($u->user_email) ? ' (' . $u->user_email . ')' : '')); ?></option><?php endforeach; ?></select></label>
					<label><span>دسته‌بندی محصول</span><select multiple name="wc_cat_ids[]" class="cptt-expert-multiselect"><?php foreach ($cats as $cat): ?><option value="<?php echo esc_attr($cat->term_id); ?>" <?php echo in_array((int)$cat->term_id, array_map('intval',$selected_cats), true) ? 'selected' : ''; ?>><?php echo esc_html($cat->name); ?></option><?php endforeach; ?></select></label>
					<label><span>محصول مرتبط</span><select name="product_id"><option value="">— بدون محصول —</option><?php foreach ($products as $product): ?><option value="<?php echo esc_attr($product->ID); ?>" <?php selected($product_id, $product->ID); ?>><?php echo esc_html(get_the_title($product->ID)); ?></option><?php endforeach; ?></select></label>
					<label><span>کارشناسان پروژه</span><select multiple name="expert_user_ids[]" class="cptt-expert-multiselect"><?php foreach ($experts_all as $u): ?><option value="<?php echo esc_attr($u->ID); ?>" <?php echo in_array((int)$u->ID, array_map('intval',$experts), true) ? 'selected' : ''; ?>><?php echo esc_html($u->display_name); ?></option><?php endforeach; ?></select></label>
					<label><span>مهلت پروژه</span><input type="text" class="cptt-jalali-datetime" name="deadline_local" value="<?php echo esc_attr($deadline_local); ?>" placeholder="۱۴۰۳/۰۱/۳۱ ۱۴:۳۰"></label>
				</div>
				<label class="cptt-createProjectCheck"><span>وضعیت مالی</span><label><input type="checkbox" name="is_settled" value="1" <?php checked($is_settled,1); ?>> تسویه شده</label></label>
			</div>
			<div class="cptt-expert-steps">
				<?php foreach ($steps as $index => $step):
					if (!is_array($step)) continue;
					$step_id = !empty($step['id']) ? (string)$step['id'] : ('step_' . $index);
					$title = (string)($step['title'] ?? ('مرحله ' . ($index + 1)));
					$status = (string)($step['status'] ?? 'todo');
					if (!in_array($status, ['todo', 'current', 'done'], true)) $status = 'todo';
					$done_count = 0; $all_count = 0;
					if (!empty($step['checklist']) && is_array($step['checklist'])) foreach ($step['checklist'] as $it) { if (!is_array($it) || empty($it['text'])) continue; $all_count++; if (!empty($it['done'])) $done_count++; }
					$user_tasks = !empty($step['user_tasks']) && is_array($step['user_tasks']) ? $step['user_tasks'] : [];
					$status_label = $status === 'done' ? 'انجام‌شده' : ($status === 'current' ? 'در حال انجام' : 'انجام‌نشده');
					$badge_class = $status === 'done' ? 'done' : ($status === 'current' ? 'current' : 'todo');
					$due_local = !empty($step['due_at_fa']) ? (string)$step['due_at_fa'] : '';
				?>
				<div class="cptt-expert-step" data-step-id="<?php echo esc_attr($step_id); ?>">
					<button type="button" class="cptt-expert-step__toggle" aria-expanded="<?php echo $index === 0 ? 'true' : 'false'; ?>">
						<div class="cptt-expert-step__toggleMain">
							<strong><?php echo esc_html(($index + 1) . '. ' . $title); ?></strong>
							<span><?php echo esc_html($all_count ? ('چک‌لیست: ' . $done_count . '/' . $all_count) : 'بدون چک‌لیست'); ?></span>
						</div>
						<div class="cptt-expert-step__toggleSide">
							<span class="cptt-expert-status cptt-expert-status--<?php echo esc_attr($badge_class); ?>"><?php echo esc_html($status_label); ?></span>
							<span class="cptt-expert-step__chevron">⌄</span>
						</div>
					</button>
					<div class="cptt-expert-step__body" <?php echo $index === 0 ? '' : 'hidden'; ?>>
						<div class="cptt-expert-step__metaGrid">
							<label><span>عنوان مرحله</span><input type="text" name="steps[<?php echo esc_attr($step_id); ?>][title]" value="<?php echo esc_attr($title); ?>"></label>
							<label><span>وضعیت مرحله</span><select name="steps[<?php echo esc_attr($step_id); ?>][status]"><option value="todo" <?php selected($status, 'todo'); ?>>انجام‌نشده</option><option value="current" <?php selected($status, 'current'); ?>>در حال انجام</option><option value="done" <?php selected($status, 'done'); ?>>انجام‌شده</option></select></label>
							<label><span>مهلت مرحله</span><input type="text" class="cptt-jalali-datetime" name="steps[<?php echo esc_attr($step_id); ?>][due_at_local]" value="<?php echo esc_attr($due_local); ?>"></label>
							<label><span>هزینه مرحله</span><input type="number" step="any" name="steps[<?php echo esc_attr($step_id); ?>][cost]" value="<?php echo esc_attr((string)($step['cost'] ?? 0)); ?>"></label>
							<label><span>دریافتی مرحله</span><input type="number" step="any" name="steps[<?php echo esc_attr($step_id); ?>][paid]" value="<?php echo esc_attr((string)($step['paid'] ?? 0)); ?>"></label>
							<div class="cptt-expert-step__metaItem"><span>آخرین تغییر</span><strong><?php echo esc_html((string)($step['updated_at_fa'] ?? '—')); ?></strong></div>
						</div>
						<label class="cptt-expert-noteField"><span>توضیحات مرحله</span><textarea name="steps[<?php echo esc_attr($step_id); ?>][desc]" rows="3"><?php echo esc_textarea((string)($step['desc'] ?? '')); ?></textarea></label>
						<?php if (!empty($step['checklist']) && is_array($step['checklist'])): ?>
							<div class="cptt-expert-checklist">
								<div class="cptt-expert-sectionTitle">چک‌لیست</div>
								<?php foreach ($step['checklist'] as $item): if (!is_array($item) || empty($item['text'])) continue; $check_id = !empty($item['id']) ? (string)$item['id'] : ('chk_' . $index . '_' . wp_rand(1000,9999)); ?>
								<div class="cptt-expert-checkRow <?php echo !empty($item['done']) ? 'is-done' : ''; ?>">
									<label class="cptt-expert-checkItem"><input type="checkbox" name="steps[<?php echo esc_attr($step_id); ?>][checklist][<?php echo esc_attr($check_id); ?>][done]" value="1" <?php checked(!empty($item['done'])); ?>><span>انجام شد</span><?php if (!empty($item['done_at_fa'])): ?><small><?php echo esc_html((string)$item['done_at_fa']); ?></small><?php endif; ?></label>
									<input type="text" name="steps[<?php echo esc_attr($step_id); ?>][checklist][<?php echo esc_attr($check_id); ?>][text]" value="<?php echo esc_attr((string)($item['text'] ?? '')); ?>" placeholder="متن آیتم">
									<input type="url" name="steps[<?php echo esc_attr($step_id); ?>][checklist][<?php echo esc_attr($check_id); ?>][url]" value="<?php echo esc_attr((string)($item['url'] ?? '')); ?>" placeholder="لینک نتیجه">
								</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<?php if (!empty($user_tasks)): ?>
							<div class="cptt-expert-userTasks">
								<div class="cptt-expert-sectionTitle">تسک‌های مشتری</div>
								<?php foreach ($user_tasks as $task): if (!is_array($task) || empty($task['title'])) continue; $task_id = !empty($task['id']) ? (string)$task['id'] : ('ut_' . wp_rand(1000,9999)); $task_due = !empty($task['due_at_fa']) ? (string)$task['due_at_fa'] : ''; ?>
								<div class="cptt-expert-userTask <?php echo !empty($task['done']) ? 'is-done' : ''; ?>">
									<label><span>عنوان تسک</span><input type="text" name="steps[<?php echo esc_attr($step_id); ?>][user_tasks][<?php echo esc_attr($task_id); ?>][title]" value="<?php echo esc_attr((string)($task['title'] ?? '')); ?>"></label>
									<label><span>توضیحات</span><textarea name="steps[<?php echo esc_attr($step_id); ?>][user_tasks][<?php echo esc_attr($task_id); ?>][desc]" rows="2"><?php echo esc_textarea((string)($task['desc'] ?? '')); ?></textarea></label>
									<div class="cptt-expert-userTask__grid">
										<label><span>مهلت</span><input type="text" class="cptt-jalali-datetime" name="steps[<?php echo esc_attr($step_id); ?>][user_tasks][<?php echo esc_attr($task_id); ?>][due_at_local]" value="<?php echo esc_attr($task_due); ?>"></label>
										<label class="cptt-createProjectCheck"><span>یادآوری</span><label><input type="checkbox" name="steps[<?php echo esc_attr($step_id); ?>][user_tasks][<?php echo esc_attr($task_id); ?>][sms_remind]" value="1" <?php checked(!empty($task['sms_remind']) || !isset($task['sms_remind'])); ?>> فعال</label></label>
									</div>
									<div class="cptt-expert-userTask__meta"><?php echo esc_html(!empty($task['done']) ? 'تکمیل شده' : 'در انتظار مشتری'); ?></div>
									<?php if (!empty($task['response'])): ?><div class="cptt-expert-userTask__response"><?php echo nl2br(esc_html((string)$task['response'])); ?></div><?php endif; ?>
								</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<div class="cptt-expert-formFooter">
				<label class="cptt-expert-noteField"><span>یادداشت داخلی برای تیم</span><textarea name="note" rows="3" placeholder="اگر لازم است توضیح یا گزارش کوتاه ثبت کنید..."></textarea></label>
				<div class="cptt-expert-formActions">
					<button type="submit" class="cptt-btn cptt-btn--primary">ذخیره تغییرات</button>
					<div class="cptt-expert-formMsg" aria-live="polite"></div>
				</div>
			</div>
		</form>
		<?php
	}

	private function templates() {
		return get_posts([
			'post_type' => 'cptt_template',
			'numberposts' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
		]);
	}

	private function products() {
		if (!post_type_exists('product')) return [];
		return get_posts([
			'post_type' => 'product',
			'post_status' => ['publish','draft','private'],
			'numberposts' => 300,
			'orderby' => 'title',
			'order' => 'ASC',
		]);
	}

	private function customer_users() {
		return get_users(['fields' => ['ID','display_name','user_email']]);
	}

	private function category_terms() {
		if (!taxonomy_exists('product_cat')) return [];
		$terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
		return is_wp_error($terms) ? [] : $terms;
	}

	private function get_recent_messages($project_id, $limit = 8) {
		$messages = get_post_meta($project_id, '_cptt_expert_messages', true);
		if (!is_array($messages)) return [];
		$messages = array_reverse($messages);
		$messages = array_slice($messages, 0, $limit);
		$out = [];
		foreach ($messages as $message) {
			$sender = !empty($message['sender_id']) ? get_user_by('id', (int)$message['sender_id']) : null;
			$recipient = !empty($message['recipient_id']) ? get_user_by('id', (int)$message['recipient_id']) : null;
			$out[] = [
				'sender_id' => (int)($message['sender_id'] ?? 0),
				'recipient_id' => (int)($message['recipient_id'] ?? 0),
				'sender_name' => $sender ? $sender->display_name : 'کاربر',
				'recipient_name' => $recipient ? $recipient->display_name : 'همه',
				'time' => (int)($message['time'] ?? 0),
				'time_fa' => !empty($message['time']) && class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime((int)$message['time']) : '',
				'content' => (string)($message['content'] ?? ''),
			];
		}
		return $out;
	}

	private function render_message_list($messages) {
		if (empty($messages) || !is_array($messages)) {
			echo '<div class="cptt-expert-emptyMini">پیامی ثبت نشده است.</div>';
			return;
		}
		echo '<div class="cptt-expert-noteList">';
		foreach ($messages as $message) {
			$sender = !empty($message['sender_id']) ? get_user_by('id', (int)$message['sender_id']) : null;
			$recipient = !empty($message['recipient_id']) ? get_user_by('id', (int)$message['recipient_id']) : null;
			$sender_name = $sender ? $sender->display_name : 'کاربر';
			$recipient_name = $recipient ? $recipient->display_name : 'همه';
			$time = !empty($message['time']) && class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime((int)$message['time']) : '';
			echo '<div class="cptt-expert-noteItem">';
			echo '<div class="cptt-expert-noteItem__head"><strong>' . esc_html($sender_name . ' → ' . $recipient_name) . '</strong><span>' . esc_html($time) . '</span></div>';
			echo '<div class="cptt-expert-noteItem__body">' . nl2br(esc_html((string)($message['content'] ?? ''))) . '</div>';
			echo '</div>';
		}
		echo '</div>';
	}

	public function ajax_send_message() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
		$recipient_id = isset($_POST['recipient_id']) ? absint($_POST['recipient_id']) : 0;
		$content = sanitize_textarea_field((string)($_POST['content'] ?? ''));
		if (!$project_id || get_post_type($project_id) !== 'cptt_project') wp_send_json_error('invalid_project', 400);
		if (!$this->can_manage_project($project_id, get_current_user_id())) wp_send_json_error('no_access', 403);
		if ($content === '') wp_send_json_error('empty', 400);
		$messages = get_post_meta($project_id, '_cptt_expert_messages', true);
		if (!is_array($messages)) $messages = [];
		$messages[] = ['sender_id' => get_current_user_id(), 'recipient_id' => $recipient_id, 'time' => (int)current_time('timestamp', true), 'content' => $content];
		update_post_meta($project_id, '_cptt_expert_messages', $messages);
		wp_send_json_success(['messages' => $this->get_recent_messages($project_id, 8)]);
	}

	public function ajax_fetch_messages() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
		if (!$project_id || get_post_type($project_id) !== 'cptt_project') wp_send_json_error('invalid_project', 400);
		if (!$this->can_manage_project($project_id, get_current_user_id())) wp_send_json_error('no_access', 403);
		wp_send_json_success(['messages' => $this->get_recent_messages($project_id, 8)]);
	}

	private function prepare_template_steps_for_project($steps) {
		if (!is_array($steps)) return [];
		$out = [];
		foreach ($steps as $s) {
			if (!is_array($s)) continue;
			$s['id'] = !empty($s['id']) ? sanitize_text_field($s['id']) : (function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : ('st_' . wp_rand(1000,9999)));
			$s['status'] = 'todo';
			unset($s['updated_at'], $s['updated_at_fa'], $s['updated_by']);
			if (!empty($s['checklist']) && is_array($s['checklist'])) {
				foreach ($s['checklist'] as &$ci) {
					$ci['done'] = 0;
					unset($ci['done_at'], $ci['done_at_fa'], $ci['done_by']);
				}
				unset($ci);
			}
			if (!empty($s['user_tasks']) && is_array($s['user_tasks'])) {
				foreach ($s['user_tasks'] as &$ut) {
					$ut['done'] = 0;
					unset($ut['response'], $ut['response_url'], $ut['response_file_url'], $ut['response_file_name'], $ut['response_file_type'], $ut['response_files'], $ut['completed_at'], $ut['completed_at_fa'], $ut['completed_by'], $ut['last_reminder_at'], $ut['last_reminder_at_fa']);
				}
				unset($ut);
			}
			$out[] = $s;
		}
		if (!empty($out)) $out[0]['status'] = 'current';
		return $out;
	}

	private function sync_project_categories_from_product($project_id, $product_id) {
		$project_id = (int)$project_id;
		$product_id = (int)$product_id;
		if (!$project_id || !$product_id || !taxonomy_exists('product_cat')) return;
		$product_terms = get_the_terms($product_id, 'product_cat');
		if (is_wp_error($product_terms) || empty($product_terms)) return;
		$target_ids = [];
		foreach ($product_terms as $pt) $target_ids[] = (int)$pt->term_id;
		$target_ids = array_values(array_filter(array_unique($target_ids)));
		if (!empty($target_ids)) {
			update_post_meta($project_id, '_cptt_wc_cat_ids', $target_ids);
			update_post_meta($project_id, '_cptt_wc_cats_csv', ',' . implode(',', $target_ids) . ',');
		}
	}

	private function create_project($data) {
		$user_id = (int)get_current_user_id();
		$title = sanitize_text_field($data['title'] ?? '');
		$client_id = absint($data['client_user_id'] ?? 0);
		$product_id = absint($data['product_id'] ?? 0);
		$template_id = absint($data['template_id'] ?? 0);
		$deadline_local = trim((string)($data['deadline_local'] ?? ''));
		$is_settled = !empty($data['is_settled']) ? 1 : 0;
		$note = sanitize_textarea_field((string)($data['note'] ?? ''));
		$expert_ids = isset($data['expert_user_ids']) && is_array($data['expert_user_ids']) ? array_values(array_filter(array_unique(array_map('absint', $data['expert_user_ids'])))) : [$user_id];
		if (empty($expert_ids)) $expert_ids = [$user_id];
		$cat_ids = isset($data['wc_cat_ids']) && is_array($data['wc_cat_ids']) ? array_values(array_filter(array_unique(array_map('intval', $data['wc_cat_ids'])))) : [];
		if ($title === '') return new WP_Error('invalid', 'عنوان پروژه الزامی است.');
		$project_id = wp_insert_post([
			'post_type' => 'cptt_project',
			'post_status' => 'publish',
			'post_title' => $title,
			'post_author' => $user_id,
		], true);
		if (is_wp_error($project_id) || !$project_id) return new WP_Error('insert_failed', 'خطا در ایجاد پروژه');
		$project_id = (int)$project_id;
		update_post_meta($project_id, '_cptt_client_user_id', $client_id);
		update_post_meta($project_id, '_cptt_product_id', $product_id);
		update_post_meta($project_id, '_cptt_is_settled', $is_settled);
		update_post_meta($project_id, '_cptt_expert_user_ids', $expert_ids);
		update_post_meta($project_id, '_cptt_expert_user_id', !empty($expert_ids) ? (int)$expert_ids[0] : $user_id);
		update_post_meta($project_id, '_cptt_experts_csv', ',' . implode(',', $expert_ids) . ',');
		if (!empty($cat_ids)) {
			update_post_meta($project_id, '_cptt_wc_cat_ids', $cat_ids);
			update_post_meta($project_id, '_cptt_wc_cats_csv', ',' . implode(',', $cat_ids) . ',');
		}
		if ($product_id) {
			update_post_meta($project_id, '_cptt_wc_product_id', $product_id);
			if (empty($cat_ids)) $this->sync_project_categories_from_product($project_id, $product_id);
		}
		if ($template_id && get_post_type($template_id) === 'cptt_template') {
			$steps = get_post_meta($template_id, '_cptt_template_steps', true);
			$steps = $this->prepare_template_steps_for_project($steps);
			update_post_meta($project_id, '_cptt_steps', $steps);
		}
		if ($deadline_local !== '' && class_exists('CPTT_Core') && method_exists('CPTT_Core', 'parse_jalali_datetime')) {
			$deadline = (int) CPTT_Core::parse_jalali_datetime($deadline_local);
			if ($deadline) {
				update_post_meta($project_id, '_cptt_deadline_at', $deadline);
				update_post_meta($project_id, '_cptt_deadline_at_fa', CPTT_Core::jalali_datetime($deadline));
			}
		}
		$now = (int) current_time('timestamp', true);
		update_post_meta($project_id, '_cptt_last_update', $now);
		update_post_meta($project_id, '_cptt_last_update_fa', class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now));
		if ($note !== '') {
			update_post_meta($project_id, '_cptt_project_notes', [[
				'user_id' => $user_id,
				'time' => $now,
				'content' => $note,
			]]);
		}
		return $project_id;
	}

	public function ajax_create_project() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		if (!$this->current_user_can_view_dashboard()) wp_send_json_error('no_access', 403);
		$project_id = $this->create_project($_POST);
		if (is_wp_error($project_id)) {
			wp_send_json_error($project_id->get_error_message(), 400);
		}
		wp_send_json_success(['project_id' => (int)$project_id, 'redirect' => self::dashboard_url()]);
	}

	private function render_create_project_form() {
		$customers = $this->customer_users();
		$products = $this->products();
		$templates = $this->templates();
		$cats = $this->category_terms();
		$experts_all = get_users(['role__in' => ['cptt_expert','administrator'], 'fields' => ['ID','display_name','user_email']]);
		?>
		<div class="cptt-createProjectCard">
			<div class="cptt-sideBox__title">ایجاد پروژه جدید</div>
			<form class="cptt-expert-create-form">
				<div class="cptt-createProjectGrid">
					<label><span>عنوان پروژه</span><input type="text" name="title" placeholder="مثلاً پروژه طراحی سایت مشتری"></label>
					<label><span>مشتری</span><select name="client_user_id"><option value="">— انتخاب مشتری —</option><?php foreach ($customers as $u): ?><option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name . (!empty($u->user_email) ? ' (' . $u->user_email . ')' : '')); ?></option><?php endforeach; ?></select></label>
					<label><span>دسته‌بندی</span><select multiple name="wc_cat_ids[]" class="cptt-expert-multiselect"><?php foreach ($cats as $cat): ?><option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option><?php endforeach; ?></select></label>
					<label><span>محصول مرتبط</span><select name="product_id"><option value="">— بدون محصول —</option><?php foreach ($products as $product): ?><option value="<?php echo esc_attr($product->ID); ?>"><?php echo esc_html(get_the_title($product->ID)); ?></option><?php endforeach; ?></select></label>
					<label><span>تمپلیت مراحل</span><select name="template_id"><option value="">— بدون تمپلیت —</option><?php foreach ($templates as $t): ?><option value="<?php echo esc_attr($t->ID); ?>"><?php echo esc_html(get_the_title($t->ID)); ?></option><?php endforeach; ?></select></label>
					<label><span>کارشناسان پروژه</span><select multiple name="expert_user_ids[]" class="cptt-expert-multiselect"><?php foreach ($experts_all as $u): ?><option value="<?php echo esc_attr($u->ID); ?>" <?php echo ((int)$u->ID === (int)get_current_user_id()) ? 'selected' : ''; ?>><?php echo esc_html($u->display_name); ?></option><?php endforeach; ?></select></label>
					<label><span>مهلت پروژه</span><input type="text" class="cptt-jalali-datetime" name="deadline_local" placeholder="۱۴۰۳/۰۱/۳۱ ۱۴:۳۰"></label>
					<label class="cptt-createProjectCheck"><span>وضعیت مالی</span><label><input type="checkbox" name="is_settled" value="1"> تسویه شده</label></label>
				</div>
				<label class="cptt-expert-noteField"><span>یادداشت اولیه</span><textarea name="note" rows="3" placeholder="در صورت نیاز توضیح اولیه ثبت کنید..."></textarea></label>
				<div class="cptt-expert-formActions">
					<button type="submit" class="cptt-btn cptt-btn--primary">ایجاد پروژه</button>
					<div class="cptt-expert-formMsg" aria-live="polite"></div>
				</div>
			</form>
		</div>
		<?php
	}

	public function shortcode_dashboard($atts) {
		if (!is_user_logged_in()) {
			return '<div class="cptt-notice">برای مشاهده داشبورد کارشناس باید وارد حساب کاربری شوید.</div>';
		}
		if (!$this->current_user_can_view_dashboard()) {
			return '<div class="cptt-notice">این صفحه فقط برای کارشناسان و مدیران قابل مشاهده است.</div>';
		}

		$this->enqueue_assets();
		$user_id = (int) get_current_user_id();
		$projects = $this->get_expert_projects($user_id);
		$stats = $this->collect_dashboard_stats($projects);
		$clients_map = []; $products_map = []; $cats_map = [];
		foreach ($projects as $__p) {
			$__data = $this->project_card_data($__p->ID);
			$clients_map[(int)$__data['customer_id']] = $__data['customer'];
			$products_map[(int)$__data['product_id']] = $__data['product'];
			if (!empty($__data['term_ids']) && !empty($__data['term_names'])) {
				foreach ($__data['term_ids'] as $__i => $__tid) { if (!empty($__data['term_names'][$__i])) $cats_map[(int)$__tid] = $__data['term_names'][$__i]; }
			}
		}
		asort($clients_map); asort($products_map); asort($cats_map);
		$current_user = wp_get_current_user();
		ob_start();
		?>
		<div class="cptt-wrap cptt-expertWrap" dir="rtl">
			<div class="cptt-expertLayout">
				<aside class="cptt-expertSidebar">
					<div class="cptt-sideBox cptt-sideBox--profile">
						<div class="cptt-sideBox__title">پروفایل کارشناس</div>
						<div class="cptt-expertProfile">
							<strong><?php echo esc_html($current_user->display_name); ?></strong>
							<span><?php echo esc_html($current_user->user_email); ?></span>
						</div>
					</div>
					<div class="cptt-sideBox">
						<div class="cptt-sideBox__title">خلاصه سریع</div>
						<ul class="cptt-sideList">
							<li><span>کل پروژه‌ها</span><strong><?php echo esc_html(number_format_i18n($stats['total'])); ?></strong></li>
							<li><span>پروژه فعال</span><strong><?php echo esc_html(number_format_i18n($stats['in_progress'])); ?></strong></li>
							<li><span>تکمیل‌شده</span><strong><?php echo esc_html(number_format_i18n($stats['completed'])); ?></strong></li>
							<li><span>تاخیرها</span><strong><?php echo esc_html(number_format_i18n(count($stats['overdue']))); ?></strong></li>
						</ul>
					</div>
					<?php $this->render_create_project_form(); ?>
				</aside>
				<div class="cptt-expertMain">
			<section class="cptt-proHero cptt-proHero--expert">
				<div class="cptt-proHero__content">
					<span class="cptt-proHero__eyebrow">داشبورد حرفه‌ای کارشناس</span>
					<h1 class="cptt-proHero__title">سلام <?php echo esc_html($current_user->display_name); ?> 👋</h1>
					<p class="cptt-proHero__text">تمام پروژه‌های محول‌شده، مراحل باز، یادداشت‌ها و وضعیت مشتری‌ها را در یک صفحه یکپارچه مدیریت کنید.</p>
					<div class="cptt-proHero__actions">
						<a class="cptt-btn cptt-btn--primary" href="<?php echo esc_url(self::dashboard_url()); ?>">بروزرسانی داشبورد</a>
						<?php if (class_exists('CPTT_Report')): ?><span class="cptt-proHero__hint">برای پروژه‌های تکمیل‌شده امکان دریافت گزارش فعال است.</span><?php endif; ?>
					</div>
				</div>
				<div class="cptt-proHero__visual">
					<div class="cptt-proHero__metric"><strong><?php echo esc_html(number_format_i18n($stats['total'])); ?></strong><span>کل پروژه‌های شما</span></div>
					<div class="cptt-proHero__metric"><strong><?php echo esc_html(number_format_i18n($stats['today'] ? count($stats['today']) : 0)); ?></strong><span>کارهای امروز</span></div>
					<div class="cptt-proHero__metric"><strong><?php echo esc_html(number_format_i18n($stats['overdue'] ? count($stats['overdue']) : 0)); ?></strong><span>تاخیرها</span></div>
				</div>
			</section>

			<div class="cptt-kpiGrid cptt-kpiGrid--expert">
				<div class="cptt-kpiCard"><div class="cptt-kpiCard__label">پروژه‌های فعال</div><div class="cptt-kpiCard__value"><?php echo esc_html(number_format_i18n($stats['in_progress'])); ?></div></div>
				<div class="cptt-kpiCard"><div class="cptt-kpiCard__label">پروژه‌های تکمیل‌شده</div><div class="cptt-kpiCard__value"><?php echo esc_html(number_format_i18n($stats['completed'])); ?></div></div>
				<div class="cptt-kpiCard"><div class="cptt-kpiCard__label">مراحل باز</div><div class="cptt-kpiCard__value"><?php echo esc_html(number_format_i18n($stats['open_steps'])); ?></div></div>
				<div class="cptt-kpiCard"><div class="cptt-kpiCard__label">تسک‌های باز مشتری</div><div class="cptt-kpiCard__value"><?php echo esc_html(number_format_i18n($stats['customer_pending'])); ?></div></div>
			</div>

			<div class="cptt-expertInsights">
				<div class="cptt-insightBox">
					<div class="cptt-insightBox__title">کارهای امروز</div>
					<?php if (!empty($stats['today'])): ?><ul><?php foreach (array_slice($stats['today'], 0, 3) as $item): ?><li><strong><?php echo esc_html($item['project_title']); ?></strong><span><?php echo esc_html($item['step_title']); ?></span></li><?php endforeach; ?></ul><?php else: ?><div class="cptt-expert-emptyMini">برای امروز مرحله سررسیدشده‌ای ثبت نشده است.</div><?php endif; ?>
				</div>
				<div class="cptt-insightBox cptt-insightBox--warn">
					<div class="cptt-insightBox__title">مراحل عقب‌افتاده</div>
					<?php if (!empty($stats['overdue'])): ?><ul><?php foreach (array_slice($stats['overdue'], 0, 3) as $item): ?><li><strong><?php echo esc_html($item['project_title']); ?></strong><span><?php echo esc_html($item['step_title'] . ($item['due_fa'] ? ' — ' . $item['due_fa'] : '')); ?></span></li><?php endforeach; ?></ul><?php else: ?><div class="cptt-expert-emptyMini">هیچ مرحله عقب‌افتاده‌ای ندارید.</div><?php endif; ?>
				</div>
			</div>

			<div class="cptt-expertFilters">
				<label>جستجو<input type="search" id="cptt-expert-search" placeholder="عنوان پروژه، مشتری، محصول..."></label>
				<label>وضعیت<select id="cptt-expert-status"><option value="">همه</option><option value="completed">تکمیل شده</option><option value="in_progress">در حال انجام</option></select></label>
				<label>تسویه<select id="cptt-expert-settled"><option value="">همه</option><option value="1">تسویه شده</option><option value="0">تسویه نشده</option></select></label>
				<label>مشتری<select id="cptt-expert-client"><option value="">همه</option><?php foreach ($clients_map as $id => $name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<label>محصول<select id="cptt-expert-product"><option value="">همه</option><?php foreach ($products_map as $id => $name): if (!$id) continue; ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<label>دسته‌بندی<select id="cptt-expert-cat"><option value="">همه</option><?php foreach ($cats_map as $id => $name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<button type="button" class="cptt-btn" id="cptt-expert-reset">پاک کردن فیلترها</button>
			</div>

			<div class="cptt-expertGrid" id="cptt-expert-grid">
				<?php if (empty($projects)): ?>
					<div class="cptt-empty">در حال حاضر پروژه‌ای به شما اختصاص داده نشده است.</div>
				<?php else: foreach ($projects as $p):
					$data = $this->project_card_data($p->ID);
					$search = strtolower(get_the_title($p->ID) . ' ' . $data['customer'] . ' ' . $data['product'] . ' ' . implode(' ', $data['experts']));
				?>
				<article class="cptt-expertCard" data-search="<?php echo esc_attr($search); ?>" data-status="<?php echo esc_attr($data['progress']['status']); ?>" data-settled="<?php echo esc_attr((string)$data['settled']); ?>" data-client="<?php echo esc_attr((string)$data['customer_id']); ?>" data-product="<?php echo esc_attr((string)$data['product_id']); ?>" data-cats=",<?php echo esc_attr(implode(',', array_map('intval', (array)$data['term_ids']))); ?>,">
					<div class="cptt-expertCard__top">
						<div>
							<h3><?php echo esc_html(get_the_title($p->ID)); ?></h3>
							<div class="cptt-expertCard__meta">مشتری: <?php echo esc_html($data['customer']); ?><?php echo $data['product'] !== '—' ? esc_html(' — محصول: ' . $data['product']) : ''; ?></div>
							<?php if (!empty($data['experts'])): ?><div class="cptt-expertCard__meta">کارشناسان: <?php echo esc_html(implode('، ', $data['experts'])); ?></div><?php endif; ?>
						</div>
						<span class="cptt-expertStatusBadge cptt-expertStatusBadge--<?php echo esc_attr($data['progress']['status']); ?>"><?php echo esc_html($data['progress']['label']); ?></span>
					</div>
					<div class="cptt-expertCard__progress"><span style="width:<?php echo esc_attr($data['progress']['percent']); ?>%"></span></div>
					<div class="cptt-expertCard__stats">
						<div><strong><?php echo esc_html($data['progress']['percent']); ?>%</strong><span>پیشرفت</span></div>
						<div><strong><?php echo esc_html(number_format_i18n($data['checklist_done'])); ?>/<?php echo esc_html(number_format_i18n($data['checklist_total'])); ?></strong><span>چک‌لیست</span></div>
						<div><strong><?php echo esc_html(number_format_i18n($data['user_tasks_done'])); ?>/<?php echo esc_html(number_format_i18n($data['user_tasks_total'])); ?></strong><span>تسک مشتری</span></div>
						<div><strong><?php echo esc_html(number_format_i18n((int)$data['financial']['remain'])); ?></strong><span>مانده مالی</span></div>
					</div>
					<div class="cptt-expertCard__infoGrid">
						<div><span>مهلت پروژه</span><strong><?php echo esc_html($data['deadline'] ?: '—'); ?></strong></div>
						<div><span>آخرین بروزرسانی</span><strong class="cptt-expert-last-update"><?php echo esc_html($data['last_update'] ?: '—'); ?></strong></div>
						<div><span>وضعیت مالی</span><strong><?php echo $data['settled'] ? 'تسویه شده' : 'تسویه نشده'; ?></strong></div>
						<div><span>هزینه / دریافتی</span><strong><?php echo esc_html(number_format_i18n((int)$data['financial']['cost']) . ' / ' . number_format_i18n((int)$data['financial']['paid'])); ?></strong></div>
					</div>
					<div class="cptt-expertCard__actions">
						<button type="button" class="cptt-btn cptt-btn--primary cptt-expert-toggleProject">مدیریت پروژه</button>
						<?php if (class_exists('CPTT_Report') && CPTT_Report::is_project_complete($p->ID)): ?>
							<a class="cptt-btn" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cptt_view_report&project_id=' . $p->ID), 'cptt_view_report_' . $p->ID)); ?>" target="_blank" rel="noopener noreferrer">مشاهده گزارش</a>
						<?php endif; ?>
					</div>
					<div class="cptt-expertCard__details" hidden>
						<div class="cptt-expertCard__panels">
							<div class="cptt-expertCard__mainPanel">
								<?php $this->render_project_manage_form($p->ID); ?>
							</div>
							<aside class="cptt-expertCard__sidePanel">
								<div class="cptt-sideBox">
									<div class="cptt-sideBox__title">آخرین یادداشت‌ها</div>
									<div class="cptt-expert-notesWrap">
										<?php $this->render_note_list($data['notes']); ?>
									</div>
								</div>
								<div class="cptt-sideBox">
									<div class="cptt-sideBox__title">پیام بین کارشناسان</div>
									<button type="button" class="cptt-btn cptt-expert-chat-launch">باز کردن چت پروژه</button>
									<div class="cptt-expert-chatModal" hidden>
										<div class="cptt-expert-chatModal__backdrop"></div>
										<div class="cptt-expert-chatModal__dialog">
											<button type="button" class="cptt-expert-chatModal__close">×</button>
											<div class="cptt-sideBox__title">چت کارشناسان پروژه</div>
											<form class="cptt-expert-message-form" data-project-id="<?php echo esc_attr($p->ID); ?>">
												<select name="recipient_id">
													<option value="0">همه کارشناسان پروژه</option>
													<?php foreach (self::get_existing_experts($p->ID) as $eid): if ((int)$eid === (int)get_current_user_id()) continue; $eu = get_user_by('id', (int)$eid); if (!$eu) continue; ?><option value="<?php echo esc_attr($eid); ?>"><?php echo esc_html($eu->display_name); ?></option><?php endforeach; ?>
												</select>
												<textarea name="content" rows="3" placeholder="پیام کوتاه برای کارشناس دیگر..."></textarea>
												<div class="cptt-expert-formActions">
													<button type="submit" class="cptt-btn">ارسال پیام</button>
													<div class="cptt-expert-formMsg" aria-live="polite"></div>
												</div>
											</form>
											<div class="cptt-expert-notesWrap cptt-expert-messagesWrap">
												<?php $this->render_message_list($data['messages']); ?>
											</div>
										</div>
									</div>
								</div>
								<div class="cptt-sideBox">
									<div class="cptt-sideBox__title">خلاصه پروژه</div>
									<ul class="cptt-sideList">
										<li><span>کل مراحل</span><strong><?php echo esc_html(number_format_i18n($data['progress']['total'])); ?></strong></li>
										<li><span>مرحله‌های تکمیل‌شده</span><strong><?php echo esc_html(number_format_i18n($data['progress']['done'])); ?></strong></li>
										<li><span>چک‌لیست انجام‌شده</span><strong><?php echo esc_html(number_format_i18n($data['checklist_done'])); ?></strong></li>
										<li><span>تسک مشتری باز</span><strong><?php echo esc_html(number_format_i18n(max(0, $data['user_tasks_total'] - $data['user_tasks_done']))); ?></strong></li>
									</ul>
								</div>
							</aside>
						</div>
					</div>
				</article>
				<?php endforeach; endif; ?>
			</div>
			<div class="cptt-dashboard__empty" id="cptt-expert-empty" hidden>هیچ پروژه‌ای با این فیلترها پیدا نشد.</div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
