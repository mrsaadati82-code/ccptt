<?php
/**
 * CPTT Reminders — v6.3.0
 *
 * On-screen popup reminders + (optional) browser push notifications
 * for upcoming project/step deadlines.
 *
 * - Per-user preferences (lead times, frequency, snooze).
 * - Per-user state (dismissed/snoozed/last-shown per reminder).
 * - Lightweight server endpoint: walks the current user's projects once
 *   per poll and returns only the items that are due to be shown right now.
 */

if (!defined('ABSPATH')) exit;

class CPTT_Reminders {

	const PREFS_META  = 'cptt_reminder_prefs';
	const STATE_META  = 'cptt_reminder_state';
	const NONCE       = 'cptt_reminders_nonce';

	private static $instance = null;
	public static function instance(){
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct(){
		add_action('wp_ajax_cptt_reminders_check',    [$this, 'ajax_check']);
		add_action('wp_ajax_cptt_reminders_action',   [$this, 'ajax_action']);  // dismiss / snooze
		add_action('wp_ajax_cptt_reminders_save_prefs',[$this, 'ajax_save_prefs']);
		add_action('wp_ajax_cptt_reminders_get_prefs', [$this, 'ajax_get_prefs']);
	}

	/* =====================================================================
	 * Defaults
	 * ===================================================================== */
	public static function default_prefs(){
		return [
			'enabled'        => '1',
			'leads'          => [86400, 3600, 600], // 1d, 1h, 10m before
			'frequency'      => 'until_done',       // once|every_login|until_done|interval
			'interval_min'   => 30,                 // for "interval"
			'snooze_min'     => 15,
			'browser_notif'  => '1',
			'sound'          => '0',
		];
	}

	public static function get_prefs($user_id){
		$saved = get_user_meta((int)$user_id, self::PREFS_META, true);
		if (!is_array($saved)) $saved = [];
		// sanitize leads to integers > 0
		$d = self::default_prefs();
		$prefs = array_merge($d, $saved);
		$prefs['leads'] = isset($prefs['leads']) && is_array($prefs['leads'])
			? array_values(array_filter(array_map('intval', $prefs['leads']), function($x){ return $x > 0; }))
			: $d['leads'];
		if (empty($prefs['leads'])) $prefs['leads'] = $d['leads'];
		$prefs['interval_min']  = max(1, intval($prefs['interval_min']));
		$prefs['snooze_min']    = max(1, intval($prefs['snooze_min']));
		return $prefs;
	}

	public static function get_state($user_id){
		$s = get_user_meta((int)$user_id, self::STATE_META, true);
		return is_array($s) ? $s : [];
	}

	private static function save_state($user_id, $state){
		// prune very old entries (>14 days past) to keep meta size tiny
		$cutoff = time() - (14 * DAY_IN_SECONDS);
		foreach ($state as $k => $v) {
			$keep_until = max(
				intval($v['snoozedUntil'] ?? 0),
				intval($v['lastShownAt'] ?? 0)
			);
			if (!empty($v['dismissed'])) {
				// dismissed-permanent items are kept; "session-dismiss" should expire
				if (!empty($v['dismissUntil']) && $v['dismissUntil'] < time()) {
					unset($state[$k]); continue;
				}
				continue;
			}
			if ($keep_until && $keep_until < $cutoff) unset($state[$k]);
		}
		update_user_meta((int)$user_id, self::STATE_META, $state);
	}

	/* =====================================================================
	 * AJAX: settings
	 * ===================================================================== */
	public function ajax_get_prefs(){
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer(self::NONCE, 'nonce');
		wp_send_json_success(['prefs' => self::get_prefs(get_current_user_id())]);
	}

	public function ajax_save_prefs(){
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer(self::NONCE, 'nonce');
		$raw  = isset($_POST['prefs']) ? wp_unslash($_POST['prefs']) : '';
		$data = is_string($raw) ? json_decode($raw, true) : (array)$raw;
		if (!is_array($data)) wp_send_json_error('invalid', 400);
		$d = self::default_prefs();
		$out = [];
		$out['enabled']       = !empty($data['enabled']) ? '1' : '0';
		$out['frequency']     = in_array(($data['frequency'] ?? ''), ['once','every_login','until_done','interval'], true) ? $data['frequency'] : $d['frequency'];
		$out['interval_min']  = max(1, intval($data['interval_min'] ?? $d['interval_min']));
		$out['snooze_min']    = max(1, intval($data['snooze_min']   ?? $d['snooze_min']));
		$out['browser_notif'] = !empty($data['browser_notif']) ? '1' : '0';
		$out['sound']         = !empty($data['sound']) ? '1' : '0';
		$leads = (isset($data['leads']) && is_array($data['leads'])) ? $data['leads'] : $d['leads'];
		$leads = array_values(array_unique(array_filter(array_map('intval', $leads), function($x){ return $x > 0 && $x < 30*DAY_IN_SECONDS; })));
		if (empty($leads)) $leads = $d['leads'];
		sort($leads); // ascending: smallest lead first
		$out['leads'] = $leads;
		update_user_meta(get_current_user_id(), self::PREFS_META, $out);
		wp_send_json_success(['prefs' => $out]);
	}

	/* =====================================================================
	 * AJAX: per-reminder action (snooze / dismiss)
	 * ===================================================================== */
	public function ajax_action(){
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer(self::NONCE, 'nonce');
		$uid    = get_current_user_id();
		$prefs  = self::get_prefs($uid);
		$state  = self::get_state($uid);
		$key    = isset($_POST['key']) ? sanitize_text_field((string)wp_unslash($_POST['key'])) : '';
		$action = isset($_POST['op'])  ? sanitize_key($_POST['op']) : '';
		if ($key === '' || $action === '') wp_send_json_error('invalid', 400);

		if (!isset($state[$key]) || !is_array($state[$key])) $state[$key] = [];

		switch ($action) {
			case 'snooze':
				$state[$key]['snoozedUntil'] = time() + ($prefs['snooze_min'] * 60);
				$state[$key]['lastShownAt']  = time();
				break;
			case 'dismiss':
				// "don't show again" → permanent dismiss for this reminder
				$state[$key]['dismissed']   = 1;
				$state[$key]['lastShownAt'] = time();
				break;
			case 'shown':
				// remember we showed it (used for "once" / "interval" modes)
				$state[$key]['lastShownAt'] = time();
				break;
			case 'clear':
				unset($state[$key]);
				break;
		}
		self::save_state($uid, $state);
		wp_send_json_success(['key' => $key, 'op' => $action]);
	}

	/* =====================================================================
	 * AJAX: check — return list of reminders that should pop right now
	 * ===================================================================== */
	public function ajax_check(){
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer(self::NONCE, 'nonce');
		$uid   = get_current_user_id();
		$prefs = self::get_prefs($uid);
		if ($prefs['enabled'] !== '1') wp_send_json_success(['reminders' => []]);

		$state = self::get_state($uid);
		$now   = time();
		$out   = [];
		$projects = $this->user_projects($uid);
		foreach ($projects as $p) {
			$pid = (int)$p->ID;

			// Skip archived
			if ((string) get_post_meta($pid, '_cptt_archived', true) === '1') continue;

			// v6.3.1 — Skip projects that are already completed.
			$steps = get_post_meta($pid, '_cptt_steps', true);
			if (!is_array($steps)) $steps = [];
			$project_done = $this->is_project_completed($steps);

			// Project deadline (only when not completed)
			if (!$project_done) {
				$pdl = (int) get_post_meta($pid, '_cptt_deadline_at', true);
				if ($pdl > 0) {
					$key  = "project:{$pid}";
					$item = $this->maybe_build_item('project', $key, $p, null, $pdl, $now, $prefs, $state);
					if ($item) $out[] = $item;
				}
			}

			// Step deadlines (skip done steps; also skip ALL steps if the
			// project is fully completed because the user has no work left)
			if (!$project_done) {
				foreach ($steps as $st) {
					if (!is_array($st)) continue;
					$status = (string)($st['status'] ?? 'todo');
					if ($status === 'done') continue;
					$due = (int)($st['due_at'] ?? 0);
					if ($due <= 0) continue;
					$sid = (string)($st['id'] ?? '');
					if ($sid === '') continue;
					$key  = "step:{$pid}:{$sid}";
					$item = $this->maybe_build_item('step', $key, $p, $st, $due, $now, $prefs, $state);
					if ($item) $out[] = $item;
				}
			}
		}

		// Sort: most imminent first
		usort($out, function($a, $b){
			return ($a['deadline_ts'] <=> $b['deadline_ts']);
		});
		// Limit to avoid overwhelming the UI in one poll
		$out = array_slice($out, 0, 6);
		wp_send_json_success(['reminders' => $out]);
	}

	/**
	 * v6.3.1 — A project is "completed" when it has at least one step and
	 * every step has status === 'done'. (Same definition used elsewhere.)
	 * Settled projects (`_cptt_is_settled = 1`) are also treated as
	 * completed for reminder purposes — work is finished, no need to nag.
	 */
	private function is_project_completed($steps){
		if (!is_array($steps) || empty($steps)) return false;
		$total = 0; $done = 0;
		foreach ($steps as $s) {
			if (!is_array($s)) continue;
			$total++;
			if (($s['status'] ?? 'todo') === 'done') $done++;
		}
		return ($total > 0 && $done >= $total);
	}

	private function user_projects($uid){
		$is_admin = user_can($uid, 'manage_options');
		$args = [
			'post_type'   => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids', // we'll re-fetch as needed via get_post
		];
		if (!$is_admin) {
			$args['meta_query'] = [[
				'key' => '_cptt_experts_csv',
				'value' => ',' . (int)$uid . ',',
				'compare' => 'LIKE',
			]];
		}
		$ids = get_posts($args);
		$posts = [];
		foreach ($ids as $id) {
			$post = get_post((int)$id);
			if ($post) $posts[] = $post;
		}
		return $posts;
	}

	/**
	 * Decide whether a project/step deadline should trigger a reminder right now,
	 * given the user's prefs and stored state.
	 */
	private function maybe_build_item($type, $key, $project, $step, $deadline_ts, $now, $prefs, $state){
		// Permanently dismissed?
		$s = isset($state[$key]) && is_array($state[$key]) ? $state[$key] : [];
		if (!empty($s['dismissed'])) return null;
		// Snoozed?
		if (!empty($s['snoozedUntil']) && intval($s['snoozedUntil']) > $now) return null;
		// Frequency control
		$last = intval($s['lastShownAt'] ?? 0);
		if ($prefs['frequency'] === 'once' && $last > 0) return null;
		if ($prefs['frequency'] === 'interval' && $last > 0
		    && ($now - $last) < ($prefs['interval_min'] * 60)) return null;
		// "every_login" handled client-side via sessionStorage; server still returns
		// these items and JS decides whether to show.

		$diff = $deadline_ts - $now;
		// Past-due — always remind (with a clear "expired" label) unless dismissed
		$matched_lead = null;
		if ($diff <= 0) {
			$matched_lead = 0;
		} else {
			// Pick smallest lead that is >= diff (we're inside that window)
			$leads = $prefs['leads'];
			foreach ($leads as $L) {
				if ($diff <= $L) { $matched_lead = $L; break; }
			}
		}
		if ($matched_lead === null) return null; // not yet within any lead window

		$title         = ($type === 'step') ? (string)($step['title'] ?? 'مرحله') : get_the_title($project);
		$project_title = get_the_title($project);
		$deadline_fa   = '';
		if ($type === 'step') {
			$deadline_fa = (string)($step['due_at_fa'] ?? '');
		}
		if ($deadline_fa === '' && class_exists('CPTT_Core')) {
			$deadline_fa = CPTT_Core::jalali_datetime($deadline_ts);
		}

		return [
			'key'           => $key,
			'type'          => $type,
			'project_id'    => (int)$project->ID,
			'project_title' => $project_title,
			'title'         => $title,
			'deadline_ts'   => (int)$deadline_ts,
			'deadline_fa'   => $deadline_fa,
			'diff_seconds'  => $diff,
			'lead_label'    => self::format_lead($matched_lead, $diff),
			'expired'       => $diff < 0,
		];
	}

	public static function format_lead($lead, $diff){
		if ($diff < 0) {
			$past = -$diff;
			if ($past < 60)             return 'مهلت گذشته (تازه)';
			if ($past < 3600)           return 'مهلت ' . round($past/60) . ' دقیقه پیش گذشته';
			if ($past < 86400)          return 'مهلت ' . round($past/3600) . ' ساعت پیش گذشته';
			return 'مهلت ' . round($past/86400) . ' روز پیش گذشته';
		}
		if ($lead >= 86400) return 'تا ' . round($lead/86400) . ' روز دیگر';
		if ($lead >= 3600)  return 'تا ' . round($lead/3600)  . ' ساعت دیگر';
		return 'تا ' . round($lead/60) . ' دقیقه دیگر';
	}

	/* =====================================================================
	 * Convenient: JS config for the dashboard page
	 * ===================================================================== */
	public static function localized_config($user){
		$prefs = self::get_prefs($user ? $user->ID : 0);
		return [
			'ajax'       => admin_url('admin-ajax.php'),
			'nonce'      => wp_create_nonce(self::NONCE),
			'prefs'      => $prefs,
			'poll_secs'  => 60, // server check every 60s
		];
	}
}
