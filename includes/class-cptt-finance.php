<?php
/**
 * CPTT Finance — Modern modular finance subsystem for the Hamahang plugin.
 *
 * v6.4.0 — Phase 1: data model, ledger, 5 standalone admin modules with
 * SaaS-style mobile-first UI.
 *
 * Modules (each has its own admin page + URL + nav):
 *   - cptt-finance               → Financial dashboard (KPIs + charts)
 *   - cptt-finance-receivables   → Customer receivables (debtors)
 *   - cptt-finance-payouts       → Expert payouts (settlements)
 *   - cptt-finance-transactions  → Standalone income/expense register
 *   - cptt-finance-treasury      → Cash & bank accounts + transfers + ledger
 *
 * Design rules:
 *   - Nothing existing is removed; the old "حساب و کتاب" page (CPTT_Admin) stays.
 *   - Every existing financial operation can ALSO write to the unified ledger
 *     (cptt_fin_ledger) via auto-hooks.
 *   - Tables are upgrade-safe (dbDelta) and use a stored version option.
 *   - Currency uses CPTT_Currency where available (toman/rial).
 *   - All UI is responsive, theme-aware, and accessible.
 */

if (!defined('ABSPATH')) exit;

class CPTT_Finance {

	const DB_VERSION = '1.0.1';
	const DB_OPTION  = 'cptt_finance_db_version';
	const NONCE      = 'cptt_finance_nonce';

	private static $instance = null;
	public static function instance(){
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct(){
		add_action('admin_menu',  [$this, 'register_menus'], 12);
		add_action('admin_init',  [$this, 'maybe_install_tables']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

		// AJAX: data CRUD
		add_action('wp_ajax_cptt_fin_account_save',   [$this, 'ajax_account_save']);
		add_action('wp_ajax_cptt_fin_account_delete', [$this, 'ajax_account_delete']);
		add_action('wp_ajax_cptt_fin_cat_save',       [$this, 'ajax_cat_save']);
		add_action('wp_ajax_cptt_fin_cat_delete',     [$this, 'ajax_cat_delete']);
		add_action('wp_ajax_cptt_fin_tx_save',        [$this, 'ajax_tx_save']);
		add_action('wp_ajax_cptt_fin_tx_delete',      [$this, 'ajax_tx_delete']);
		add_action('wp_ajax_cptt_fin_transfer',       [$this, 'ajax_transfer']);
		add_action('wp_ajax_cptt_fin_dashboard_data', [$this, 'ajax_dashboard_data']);

		// Auto-log existing project finance events into the ledger
		add_action('cptt_after_expert_payout',           [$this, 'log_expert_payout'], 10, 5);
		add_action('cptt_after_manual_expert_payment',   [$this, 'log_manual_payment'], 10, 3);
	}

	/* ───────────────────────────────────────────────────────────────────
	 * Table names
	 * ─────────────────────────────────────────────────────────────────── */
	public static function tbl_accounts(){   global $wpdb; return $wpdb->prefix . 'cptt_fin_accounts'; }
	public static function tbl_categories(){ global $wpdb; return $wpdb->prefix . 'cptt_fin_categories'; }
	public static function tbl_ledger(){     global $wpdb; return $wpdb->prefix . 'cptt_fin_ledger'; }

	/* ───────────────────────────────────────────────────────────────────
	 * Install / upgrade tables
	 * ─────────────────────────────────────────────────────────────────── */
	public function maybe_install_tables(){
		if (get_option(self::DB_OPTION) === self::DB_VERSION) return;
		self::install_tables();
		update_option(self::DB_OPTION, self::DB_VERSION);
		// Seed default categories on first install
		self::seed_defaults();
	}

	public static function install_tables(){
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t_acc = self::tbl_accounts();
		$t_cat = self::tbl_categories();
		$t_lgr = self::tbl_ledger();

		$sql1 = "CREATE TABLE $t_acc (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'cash',
			bank_name VARCHAR(190) DEFAULT NULL,
			account_number VARCHAR(60) DEFAULT NULL,
			iban VARCHAR(40) DEFAULT NULL,
			card_number VARCHAR(40) DEFAULT NULL,
			initial_balance DECIMAL(20,2) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT 'IRT',
			color VARCHAR(20) NOT NULL DEFAULT '#6366f1',
			icon VARCHAR(20) NOT NULL DEFAULT '🏦',
			status TINYINT NOT NULL DEFAULT 1,
			meta LONGTEXT DEFAULT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at INT NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY type_idx (type),
			KEY status_idx (status)
		) $charset;";

		$sql2 = "CREATE TABLE $t_cat (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			type VARCHAR(20) NOT NULL,
			parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			color VARCHAR(20) NOT NULL DEFAULT '#64748b',
			icon VARCHAR(20) NOT NULL DEFAULT '🏷',
			sort_order INT NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY type_idx (type)
		) $charset;";

		$sql3 = "CREATE TABLE $t_lgr (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(30) NOT NULL,
			direction TINYINT NOT NULL,
			amount DECIMAL(20,2) NOT NULL DEFAULT 0,
			category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			project_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			step_id VARCHAR(64) NOT NULL DEFAULT '',
			customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			expert_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ref_type VARCHAR(40) NOT NULL DEFAULT '',
			ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			linked_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			date_at INT NOT NULL,
			date_fa VARCHAR(40) NOT NULL DEFAULT '',
			description TEXT,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at INT NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY account_idx (account_id),
			KEY type_idx (type),
			KEY date_idx (date_at),
			KEY project_idx (project_id),
			KEY customer_idx (customer_id),
			KEY expert_idx (expert_id)
		) $charset;";

		dbDelta($sql1);
		dbDelta($sql2);
		dbDelta($sql3);
	}

	public static function seed_defaults(){
		global $wpdb;
		$t = self::tbl_categories();
		$exists = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
		if ($exists > 0) return;
		$rows = [
			['name'=>'فروش پروژه','type'=>'income','color'=>'#16a34a','icon'=>'💼'],
			['name'=>'پیش پرداخت','type'=>'income','color'=>'#22c55e','icon'=>'💵'],
			['name'=>'سایر درآمدها','type'=>'income','color'=>'#06b6d4','icon'=>'💰'],
			['name'=>'دستمزد کارشناسان','type'=>'expense','color'=>'#f59e0b','icon'=>'👤'],
			['name'=>'هزینه‌های اداری','type'=>'expense','color'=>'#a855f7','icon'=>'🏢'],
			['name'=>'هزینه‌های بازاریابی','type'=>'expense','color'=>'#ec4899','icon'=>'📣'],
			['name'=>'هزینه نرم‌افزار/سرور','type'=>'expense','color'=>'#0ea5e9','icon'=>'☁️'],
			['name'=>'سایر هزینه‌ها','type'=>'expense','color'=>'#ef4444','icon'=>'📦'],
		];
		foreach ($rows as $r) $wpdb->insert($t, $r);

		// Seed a default cash account
		$ta = self::tbl_accounts();
		$cnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM $ta");
		if ($cnt === 0) {
			$wpdb->insert($ta, [
				'name'  => 'صندوق نقدی',
				'type'  => 'cash',
				'icon'  => '💵',
				'color' => '#16a34a',
				'created_at' => (int) current_time('timestamp', true),
				'created_by' => (int) get_current_user_id(),
			]);
		}
	}

	/* ───────────────────────────────────────────────────────────────────
	 * Admin Menus — every module is a standalone page
	 * ─────────────────────────────────────────────────────────────────── */
	public function register_menus(){
		// Parent menu
		add_menu_page(
			'مالی هماهنگ',
			'💰 مالی',
			'edit_cptt_projects',
			'cptt-finance',
			[$this, 'render_dashboard_page'],
			'dashicons-money-alt',
			25
		);
		// Sub-pages
		add_submenu_page('cptt-finance', 'داشبورد مالی',     'داشبورد مالی',     'edit_cptt_projects', 'cptt-finance',                [$this, 'render_dashboard_page']);
		add_submenu_page('cptt-finance', 'مطالبات مشتریان',  'مطالبات مشتریان',  'edit_cptt_projects', 'cptt-finance-receivables',    [$this, 'render_receivables_page']);
		add_submenu_page('cptt-finance', 'تسویه کارشناسان',  'تسویه کارشناسان',  'edit_cptt_projects', 'cptt-finance-payouts',        [$this, 'render_payouts_page']);
		add_submenu_page('cptt-finance', 'درآمد و هزینه',     'درآمد و هزینه',    'edit_cptt_projects', 'cptt-finance-transactions',   [$this, 'render_transactions_page']);
		add_submenu_page('cptt-finance', 'خزانه‌داری',         'خزانه‌داری',        'edit_cptt_projects', 'cptt-finance-treasury',       [$this, 'render_treasury_page']);
		add_submenu_page('cptt-finance', 'دسته‌بندی‌های مالی',  'دسته‌بندی‌ها',      'edit_cptt_projects', 'cptt-finance-categories',     [$this, 'render_categories_page']);
		// v6.4.1 — Classic project accounting view migrated under Finance menu.
		add_submenu_page('cptt-finance', 'حساب پروژه‌ها (کلاسیک)', '📒 حساب پروژه‌ها', 'edit_cptt_projects', 'cptt-finance-classic',        [$this, 'render_classic_accounting_page']);
		add_submenu_page('cptt-finance', 'گردش حساب',            '📑 گردش حساب',     'edit_cptt_projects', 'cptt-finance-ledger',         [$this, 'render_ledger_page']);
	}

	public function enqueue_admin_assets($hook){
		if (strpos($hook, 'cptt-finance') === false) return;
		// Skip on the standalone ERP panel page (it has its own Tailwind/React bundle).
		if (isset($_GET['page']) && $_GET['page'] === 'cptt-finance-erp') return;
		wp_enqueue_style('cptt-finance', CPTT_URL . 'assets/css/finance.css', [], CPTT_VERSION);
		wp_enqueue_script('cptt-finance', CPTT_URL . 'assets/js/finance.js', ['jquery'], CPTT_VERSION, true);
		wp_localize_script('cptt-finance', 'CPTT_FIN', [
			'ajax'  => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce(self::NONCE),
			'currency' => class_exists('CPTT_Currency') ? CPTT_Currency::label() : 'تومان',
		]);
	}

	/* ═══════════════════════════════════════════════════════════════════
	 * HELPERS
	 * ═══════════════════════════════════════════════════════════════════ */
	public static function fmt($n){
		return number_format_i18n((float)$n);
	}
	public static function fa_date($ts){
		$ts = (int)$ts;
		if ($ts <= 0) return '—';
		return class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($ts) : date('Y-m-d H:i', $ts);
	}
	public static function currency_label(){
		return class_exists('CPTT_Currency') ? CPTT_Currency::label() : 'تومان';
	}

	public static function get_accounts($only_active = true){
		global $wpdb;
		$where = $only_active ? 'WHERE status=1' : '';
		return $wpdb->get_results("SELECT * FROM " . self::tbl_accounts() . " $where ORDER BY id ASC");
	}
	public static function get_account($id){
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::tbl_accounts() . " WHERE id=%d", $id));
	}
	public static function get_categories($type = ''){
		global $wpdb;
		if ($type === '') {
			return $wpdb->get_results("SELECT * FROM " . self::tbl_categories() . " ORDER BY type ASC, sort_order ASC, id ASC");
		}
		return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::tbl_categories() . " WHERE type=%s ORDER BY sort_order ASC, id ASC", $type));
	}

	/**
	 * Compute current balance of an account = initial + ∑(direction * amount)
	 */
	public static function account_balance($account_id){
		global $wpdb;
		$acc = self::get_account($account_id);
		if (!$acc) return 0.0;
		$sum = (float) $wpdb->get_var($wpdb->prepare(
			"SELECT COALESCE(SUM(direction * amount), 0) FROM " . self::tbl_ledger() . " WHERE account_id=%d",
			$account_id
		));
		return (float)$acc->initial_balance + $sum;
	}

	/**
	 * v6.4.1 — Balance of an account at a given timestamp (inclusive of
	 * older transactions). Used by the ledger view for running-balance.
	 * Pass $ts=0 to get just the initial balance (no transactions yet).
	 */
	public static function account_balance_at($account_id, $ts){
		global $wpdb;
		$acc = self::get_account($account_id);
		if (!$acc) return 0.0;
		if ($ts <= 0) return (float) $acc->initial_balance;
		$sum = (float) $wpdb->get_var($wpdb->prepare(
			"SELECT COALESCE(SUM(direction * amount), 0) FROM " . self::tbl_ledger() . " WHERE account_id=%d AND date_at <= %d",
			$account_id, $ts
		));
		return (float)$acc->initial_balance + $sum;
	}

	/**
	 * Insert a ledger entry. $args:
	 *   account_id (req), type (req), direction (+1/-1), amount (req),
	 *   category_id, project_id, step_id, customer_id, expert_id,
	 *   ref_type, ref_id, linked_id, date_at, description
	 */
	public static function ledger_insert($args){
		global $wpdb;
		$defaults = [
			'account_id'  => 0,
			'type'        => 'manual',
			'direction'   => 1,
			'amount'      => 0,
			'category_id' => 0,
			'project_id'  => 0,
			'step_id'     => '',
			'customer_id' => 0,
			'expert_id'   => 0,
			'ref_type'    => '',
			'ref_id'      => 0,
			'linked_id'   => 0,
			'date_at'     => (int) current_time('timestamp', true),
			'description' => '',
			'created_by'  => (int) get_current_user_id(),
		];
		$a = array_merge($defaults, $args);
		$a['amount']    = (float) $a['amount'];
		$a['direction'] = (int) $a['direction'] >= 0 ? 1 : -1;
		$a['date_fa']   = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime((int)$a['date_at']) : date('Y-m-d H:i', (int)$a['date_at']);
		$a['created_at']= (int) current_time('timestamp', true);
		$wpdb->insert(self::tbl_ledger(), $a);
		return (int) $wpdb->insert_id;
	}

	/* ═══════════════════════════════════════════════════════════════════
	 * AUTO-HOOKS (link existing system to the ledger)
	 * ═══════════════════════════════════════════════════════════════════ */
	public function log_expert_payout($project_id, $step_id, $expert_id, $amount, $mode){
		$account_id = self::default_cash_account_id();
		if (!$account_id || (float)$amount <= 0) return;
		self::ledger_insert([
			'account_id'  => $account_id,
			'type'        => 'expert_payout',
			'direction'   => -1,
			'amount'      => (float)$amount,
			'project_id'  => (int)$project_id,
			'step_id'     => (string)$step_id,
			'expert_id'   => (int)$expert_id,
			'ref_type'    => 'expert_payout',
			'description' => 'تسویه با کارشناس - مرحله',
		]);
	}
	public function log_manual_payment($expert_id, $amount, $note){
		$account_id = self::default_cash_account_id();
		if (!$account_id || (float)$amount <= 0) return;
		self::ledger_insert([
			'account_id'  => $account_id,
			'type'        => 'expert_payout',
			'direction'   => -1,
			'amount'      => (float)$amount,
			'expert_id'   => (int)$expert_id,
			'ref_type'    => 'manual_payment',
			'description' => (string)$note ?: 'پرداخت دستی به کارشناس',
		]);
	}
	public static function default_cash_account_id(){
		global $wpdb;
		$id = (int) $wpdb->get_var("SELECT id FROM " . self::tbl_accounts() . " WHERE status=1 ORDER BY id ASC LIMIT 1");
		return $id;
	}

	/* ═══════════════════════════════════════════════════════════════════
	 * KPI computations (used by dashboard + receivables)
	 * ═══════════════════════════════════════════════════════════════════ */
	public static function compute_kpis(){
		$projects = get_posts(['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>-1]);
		$total_cost=0; $total_paid=0; $total_remain=0;
		$debt_projects = 0; $settled = 0; $unsettled = 0;
		$expert_payouts_total = 0;
		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) continue;
			$pc=0; $pp=0; $ep=0;
			foreach ($steps as $st) {
				$pc += (float)($st['cost'] ?? 0);
				$pp += (float)($st['paid'] ?? 0);
				$ep += (float)($st['expert_paid'] ?? 0);
				if (!empty($st['extra_finance']) && is_array($st['extra_finance'])) {
					foreach ($st['extra_finance'] as $ef) {
						$pc += (float)($ef['cost'] ?? 0);
						$pp += (float)($ef['paid'] ?? 0);
					}
				}
			}
			$total_cost += $pc;
			$total_paid += $pp;
			$remain = max(0, $pc - $pp);
			$total_remain += $remain;
			if ($remain > 0) $debt_projects++;
			if ((int)get_post_meta($p->ID,'_cptt_is_settled',true)) $settled++; else $unsettled++;
			$expert_payouts_total += $ep;
		}
		$gross_profit = $total_paid - $expert_payouts_total;
		return [
			'total_revenue'    => $total_paid,           // received money
			'total_invoiced'   => $total_cost,
			'receivables'      => $total_remain,
			'expert_payouts'   => $expert_payouts_total,
			'gross_profit'     => $gross_profit,
			'debt_projects'    => $debt_projects,
			'settled_projects' => $settled,
			'unsettled_projects'=> $unsettled,
			'projects_total'   => count($projects),
		];
	}

	/* v6.4.1 — Monthly aggregation combining BOTH the unified ledger AND
	 * existing project finance data. This way the dashboard correctly
	 * reflects revenue & expert payouts even before the user has imported
	 * everything into the ledger.
	 *
	 * Projects are matched by their post_date_gmt (creation month) because
	 * step-level dates aren't stored consistently. This is a pragmatic
	 * approximation that becomes more accurate as users start using the
	 * ledger for new income/expense events.
	 */
	public static function monthly_series(){
		global $wpdb;
		$series = []; // 'YYYY-MM' => ['income'=>0,'expense'=>0, 'jlabel'=>'YYYY/MM']
		$now = current_time('timestamp', true);

		// Use Jalali month labels so users see Persian dates
		$fa_months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];

		for ($i = 5; $i >= 0; $i--) {
			$ts = strtotime("-{$i} months", $now);
			$mk = date('Y-m', $ts);
			$jlabel = $mk;
			if (class_exists('CPTT_Core')) {
				$jfull = CPTT_Core::jalali_datetime($ts);
				// jfull is like "1403/03/15 12:00"
				if (preg_match('#^(\d{4})/(\d{1,2})/#', $jfull, $mm)) {
					$jy = (int)$mm[1]; $jmn = (int)$mm[2];
					$jlabel = (isset($fa_months[$jmn-1]) ? $fa_months[$jmn-1] : $jmn) . ' ' . $jy;
				}
			}
			$series[$mk] = ['label' => $jlabel, 'income' => 0, 'expense' => 0];
		}
		$cutoff = strtotime('-6 months', $now);

		// 1) From ledger (income/expense direction)
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT DATE_FORMAT(FROM_UNIXTIME(date_at), '%%Y-%%m') AS mk, direction, type, SUM(amount) AS s
			 FROM " . self::tbl_ledger() . "
			 WHERE date_at >= %d AND type NOT IN ('transfer_in','transfer_out')
			 GROUP BY mk, direction, type",
			$cutoff
		));
		foreach ($rows as $r) {
			if (!isset($series[$r->mk])) continue;
			if ((int)$r->direction > 0) $series[$r->mk]['income']  += (float)$r->s;
			else                         $series[$r->mk]['expense'] += (float)$r->s;
		}

		// 2) From projects (paid → income, expert_paid → expense) bucketed by
		// the project's creation month. This bridges old data into the chart.
		$projects = get_posts([
			'post_type'   => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => -1,
			'date_query'  => [['after' => date('Y-m-d', $cutoff), 'inclusive' => true]],
		]);
		foreach ($projects as $p) {
			$mk = date('Y-m', strtotime($p->post_date_gmt));
			if (!isset($series[$mk])) continue;
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) continue;
			$paid = 0; $epaid = 0;
			foreach ($steps as $st) {
				$paid  += (float)($st['paid']        ?? 0);
				$epaid += (float)($st['expert_paid'] ?? 0);
				if (!empty($st['extra_finance']) && is_array($st['extra_finance'])) {
					foreach ($st['extra_finance'] as $ef) $paid += (float)($ef['paid'] ?? 0);
				}
			}
			$series[$mk]['income']  += $paid;
			$series[$mk]['expense'] += $epaid;
		}

		return array_values($series);
	}

	/**
	 * v6.4.1 — Top customers by REVENUE (paid amount), top 6.
	 */
	public static function top_customers_series($limit = 6){
		$map = []; // cid => ['name'=>, 'paid'=>, 'remain'=>]
		$projects = get_posts(['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>-1]);
		foreach ($projects as $p) {
			$cid = (int) get_post_meta($p->ID, '_cptt_client_user_id', true);
			if (!$cid) continue;
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) continue;
			$paid = 0; $cost = 0;
			foreach ($steps as $st) {
				$cost += (float)($st['cost'] ?? 0);
				$paid += (float)($st['paid'] ?? 0);
				if (!empty($st['extra_finance']) && is_array($st['extra_finance'])) {
					foreach ($st['extra_finance'] as $ef) { $cost += (float)($ef['cost']??0); $paid += (float)($ef['paid']??0); }
				}
			}
			if (!isset($map[$cid])) {
				$u = get_user_by('id', $cid);
				$map[$cid] = ['name' => $u ? $u->display_name : ('#'.$cid), 'paid' => 0, 'remain' => 0];
			}
			$map[$cid]['paid']   += $paid;
			$map[$cid]['remain'] += max(0, $cost - $paid);
		}
		uasort($map, function($a,$b){ return ($b['paid'] <=> $a['paid']); });
		return array_slice(array_values($map), 0, $limit);
	}

	/**
	 * v6.4.1 — Income breakdown by category for current ledger entries.
	 */
	public static function category_breakdown($type = 'expense'){
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT l.category_id, c.name, c.color, c.icon, SUM(l.amount) AS s
			 FROM " . self::tbl_ledger() . " l
			 LEFT JOIN " . self::tbl_categories() . " c ON c.id = l.category_id
			 WHERE l.type=%s
			 GROUP BY l.category_id
			 ORDER BY s DESC
			 LIMIT 8",
			$type
		));
		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'name'  => $r->name ?: 'بدون دسته',
				'color' => $r->color ?: '#94a3b8',
				'icon'  => $r->icon  ?: '🏷',
				'value' => (float)$r->s,
			];
		}
		return $out;
	}

	/* ═══════════════════════════════════════════════════════════════════
	 * AJAX HANDLERS
	 * ═══════════════════════════════════════════════════════════════════ */
	private function check_ajax(){
		if (!is_user_logged_in() || !current_user_can('edit_cptt_projects')) {
			wp_send_json_error('no_access', 403);
		}
		check_ajax_referer(self::NONCE, 'nonce');
	}

	public function ajax_account_save(){
		$this->check_ajax();
		global $wpdb;
		$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
		$data = [
			'name'            => sanitize_text_field($_POST['name'] ?? ''),
			'type'            => in_array(($_POST['type'] ?? 'cash'), ['cash','bank'], true) ? $_POST['type'] : 'cash',
			'bank_name'       => sanitize_text_field($_POST['bank_name'] ?? ''),
			'account_number'  => sanitize_text_field($_POST['account_number'] ?? ''),
			'iban'            => sanitize_text_field($_POST['iban'] ?? ''),
			'card_number'     => sanitize_text_field($_POST['card_number'] ?? ''),
			'initial_balance' => self::parse_amount($_POST['initial_balance'] ?? 0),
			'color'           => sanitize_text_field($_POST['color'] ?? '#6366f1'),
			'icon'            => sanitize_text_field($_POST['icon'] ?? '🏦'),
			'status'          => isset($_POST['status']) ? (int)$_POST['status'] : 1,
		];
		if ($data['name'] === '') wp_send_json_error('نام حساب الزامی است', 400);
		if ($id > 0) {
			$wpdb->update(self::tbl_accounts(), $data, ['id'=>$id]);
		} else {
			$data['created_at'] = (int) current_time('timestamp', true);
			$data['created_by'] = (int) get_current_user_id();
			$wpdb->insert(self::tbl_accounts(), $data);
			$id = (int) $wpdb->insert_id;
		}
		wp_send_json_success(['id'=>$id]);
	}

	public function ajax_account_delete(){
		$this->check_ajax();
		global $wpdb;
		$id = (int)($_POST['id'] ?? 0);
		if (!$id) wp_send_json_error('invalid', 400);
		$has_ledger = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::tbl_ledger() . " WHERE account_id=%d", $id));
		if ($has_ledger > 0) {
			// Soft-disable instead of delete
			$wpdb->update(self::tbl_accounts(), ['status'=>0], ['id'=>$id]);
			wp_send_json_success(['archived'=>true]);
		}
		$wpdb->delete(self::tbl_accounts(), ['id'=>$id]);
		wp_send_json_success(['deleted'=>true]);
	}

	public function ajax_cat_save(){
		$this->check_ajax();
		global $wpdb;
		$id = (int)($_POST['id'] ?? 0);
		$data = [
			'name'      => sanitize_text_field($_POST['name'] ?? ''),
			'type'      => in_array(($_POST['type'] ?? ''), ['income','expense'], true) ? $_POST['type'] : 'expense',
			'parent_id' => (int)($_POST['parent_id'] ?? 0),
			'color'     => sanitize_text_field($_POST['color'] ?? '#64748b'),
			'icon'      => sanitize_text_field($_POST['icon'] ?? '🏷'),
			'sort_order'=> (int)($_POST['sort_order'] ?? 0),
		];
		if ($data['name'] === '') wp_send_json_error('نام دسته الزامی است', 400);
		if ($id > 0) $wpdb->update(self::tbl_categories(), $data, ['id'=>$id]);
		else { $wpdb->insert(self::tbl_categories(), $data); $id = (int)$wpdb->insert_id; }
		wp_send_json_success(['id'=>$id]);
	}

	public function ajax_cat_delete(){
		$this->check_ajax();
		global $wpdb;
		$id = (int)($_POST['id'] ?? 0);
		if (!$id) wp_send_json_error('invalid', 400);
		$wpdb->delete(self::tbl_categories(), ['id'=>$id]);
		wp_send_json_success(['deleted'=>true]);
	}

	public function ajax_tx_save(){
		$this->check_ajax();
		global $wpdb;
		$id          = (int)($_POST['id'] ?? 0);
		$type        = ($_POST['tx_type'] ?? 'expense') === 'income' ? 'income' : 'expense';
		$account_id  = (int)($_POST['account_id'] ?? 0);
		$amount      = self::parse_amount($_POST['amount'] ?? 0);
		$category_id = (int)($_POST['category_id'] ?? 0);
		$project_id  = (int)($_POST['project_id'] ?? 0);
		$customer_id = (int)($_POST['customer_id'] ?? 0);
		$description = sanitize_textarea_field($_POST['description'] ?? '');
		$date_local  = sanitize_text_field($_POST['date_local'] ?? '');

		if (!$account_id) wp_send_json_error('انتخاب حساب الزامی است', 400);
		if ($amount <= 0) wp_send_json_error('مبلغ نامعتبر', 400);

		$date_at = self::parse_jalali_local($date_local) ?: current_time('timestamp', true);

		$row = [
			'account_id'  => $account_id,
			'type'        => $type,
			'direction'   => $type === 'income' ? 1 : -1,
			'amount'      => $amount,
			'category_id' => $category_id,
			'project_id'  => $project_id,
			'customer_id' => $customer_id,
			'description' => $description,
			'date_at'     => $date_at,
			'date_fa'     => class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($date_at) : date('Y-m-d H:i', $date_at),
			'created_by'  => (int) get_current_user_id(),
			'created_at'  => (int) current_time('timestamp', true),
			'ref_type'    => 'manual_tx',
		];
		if ($id > 0) {
			$wpdb->update(self::tbl_ledger(), $row, ['id'=>$id]);
		} else {
			$wpdb->insert(self::tbl_ledger(), $row);
			$id = (int) $wpdb->insert_id;
		}
		wp_send_json_success(['id'=>$id]);
	}

	public function ajax_tx_delete(){
		$this->check_ajax();
		global $wpdb;
		$id = (int)($_POST['id'] ?? 0);
		if (!$id) wp_send_json_error('invalid', 400);
		$wpdb->delete(self::tbl_ledger(), ['id'=>$id]);
		wp_send_json_success(['deleted'=>true]);
	}

	public function ajax_transfer(){
		$this->check_ajax();
		$from = (int)($_POST['from_id'] ?? 0);
		$to   = (int)($_POST['to_id']   ?? 0);
		$amt  = self::parse_amount($_POST['amount'] ?? 0);
		$desc = sanitize_text_field($_POST['description'] ?? '');
		$date_local = sanitize_text_field($_POST['date_local'] ?? '');
		if (!$from || !$to || $from === $to) wp_send_json_error('حساب مبدا و مقصد نامعتبر', 400);
		if ($amt <= 0) wp_send_json_error('مبلغ نامعتبر', 400);
		$ts = self::parse_jalali_local($date_local) ?: current_time('timestamp', true);

		$out_id = self::ledger_insert([
			'account_id'=>$from,'type'=>'transfer_out','direction'=>-1,'amount'=>$amt,
			'date_at'=>$ts,'description'=>'انتقال به حساب #'.$to.($desc?(' — '.$desc):'')
		]);
		$in_id = self::ledger_insert([
			'account_id'=>$to,'type'=>'transfer_in','direction'=>1,'amount'=>$amt,
			'date_at'=>$ts,'description'=>'انتقال از حساب #'.$from.($desc?(' — '.$desc):''),
			'linked_id'=>$out_id
		]);
		// Update the OUT row's linked_id to point at the IN row
		global $wpdb;
		$wpdb->update(self::tbl_ledger(), ['linked_id'=>$in_id], ['id'=>$out_id]);
		wp_send_json_success(['ok'=>true]);
	}

	public function ajax_dashboard_data(){
		$this->check_ajax();
		wp_send_json_success([
			'kpis'    => self::compute_kpis(),
			'monthly' => self::monthly_series(),
		]);
	}

	/* ═══════════════════════════════════════════════════════════════════
	 * Page renderers (loaded from separate template files)
	 * ═══════════════════════════════════════════════════════════════════ */
	private function shell_open($title, $current){
		$tabs = [
			'cptt-finance'                => ['label' => '📊 داشبورد',         'icon' => '📊'],
			'cptt-finance-receivables'    => ['label' => '👥 مطالبات',         'icon' => '👥'],
			'cptt-finance-payouts'        => ['label' => '💼 تسویه‌ها',         'icon' => '💼'],
			'cptt-finance-transactions'   => ['label' => '💸 درآمد/هزینه',      'icon' => '💸'],
			'cptt-finance-treasury'       => ['label' => '🏦 خزانه',           'icon' => '🏦'],
			'cptt-finance-ledger'         => ['label' => '📑 گردش حساب',       'icon' => '📑'],
			'cptt-finance-classic'        => ['label' => '📒 حساب پروژه‌ها',    'icon' => '📒'],
			'cptt-finance-categories'     => ['label' => '🏷 دسته‌ها',          'icon' => '🏷'],
		];
		?>
		<div class="cpttf-app" dir="rtl">
			<header class="cpttf-app__topbar">
				<div class="cpttf-app__brand">
					<span class="cpttf-app__brand-mark">💰</span>
					<div>
						<strong>مالی هماهنگ</strong>
						<small><?php echo esc_html($title); ?></small>
					</div>
				</div>
				<nav class="cpttf-app__nav">
					<?php foreach ($tabs as $slug => $info): ?>
						<a class="cpttf-app__tab <?php echo $slug === $current ? 'is-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . $slug)); ?>"><?php echo esc_html($info['label']); ?></a>
					<?php endforeach; ?>
				</nav>
			</header>
			<main class="cpttf-app__main">
		<?php
	}
	private function shell_close(){
		?>
			</main>
		</div>
		<?php
	}

	public function render_dashboard_page(){
		require_once CPTT_PATH . 'includes/finance/page-dashboard.php';
		$this->shell_open('داشبورد مالی', 'cptt-finance');
		cpttf_render_dashboard();
		$this->shell_close();
	}
	public function render_receivables_page(){
		require_once CPTT_PATH . 'includes/finance/page-receivables.php';
		$this->shell_open('مطالبات مشتریان', 'cptt-finance-receivables');
		cpttf_render_receivables();
		$this->shell_close();
	}
	public function render_payouts_page(){
		require_once CPTT_PATH . 'includes/finance/page-payouts.php';
		$this->shell_open('تسویه کارشناسان', 'cptt-finance-payouts');
		cpttf_render_payouts();
		$this->shell_close();
	}
	public function render_transactions_page(){
		require_once CPTT_PATH . 'includes/finance/page-transactions.php';
		$this->shell_open('درآمد و هزینه', 'cptt-finance-transactions');
		cpttf_render_transactions();
		$this->shell_close();
	}
	public function render_treasury_page(){
		require_once CPTT_PATH . 'includes/finance/page-treasury.php';
		$this->shell_open('خزانه‌داری', 'cptt-finance-treasury');
		cpttf_render_treasury();
		$this->shell_close();
	}
	public function render_categories_page(){
		require_once CPTT_PATH . 'includes/finance/page-categories.php';
		$this->shell_open('دسته‌بندی‌های مالی', 'cptt-finance-categories');
		cpttf_render_categories();
		$this->shell_close();
	}
	public function render_ledger_page(){
		require_once CPTT_PATH . 'includes/finance/page-ledger.php';
		$this->shell_open('گردش حساب', 'cptt-finance-ledger');
		cpttf_render_ledger();
		$this->shell_close();
	}
	public function render_classic_accounting_page(){
		// Delegate to the original CPTT_Admin renderer so we don't duplicate
		// the (tested, complex) settle UI. We still wrap it in our shell.
		$this->shell_open('حساب پروژه‌ها — نمای کلاسیک', 'cptt-finance-classic');
		echo '<section class="cpttf-card"><div class="cpttf-card__body cpttf-classic-host">';
		if (class_exists('CPTT_Admin')) {
			CPTT_Admin::instance()->render_accounting_page();
		}
		echo '</div></section>';
		$this->shell_close();
	}

	/* ═══════════════════════════════════════════════════════════════════
	 * Small parsing helpers
	 * ═══════════════════════════════════════════════════════════════════ */
	public static function parse_amount($v){
		if (class_exists('CPTT_Currency')) return (float) CPTT_Currency::parse_input($v);
		$v = (string)$v;
		$v = preg_replace('/[^\d.\-]/', '', str_replace(',', '', $v));
		return (float)$v;
	}
	public static function parse_jalali_local($s){
		// Accept "YYYY/MM/DD HH:mm" Jalali OR fallback to strtotime
		$s = trim((string)$s);
		if ($s === '') return 0;
		// Convert Persian digits to ASCII
		$s = strtr($s, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
		if (preg_match('#^(\d{4})[/\-](\d{1,2})[/\-](\d{1,2})(?:[ T](\d{1,2}):(\d{1,2}))?$#', $s, $m)) {
			$jy = (int)$m[1]; $jm = (int)$m[2]; $jd = (int)$m[3];
			$h = isset($m[4]) ? (int)$m[4] : 0; $mi = isset($m[5]) ? (int)$m[5] : 0;
			if (class_exists('CPTT_Core') && method_exists('CPTT_Core', 'jalali_to_gregorian')) {
				$g = CPTT_Core::jalali_to_gregorian($jy, $jm, $jd);
				if (is_array($g) && count($g) === 3) {
					return mktime($h, $mi, 0, $g[1], $g[2], $g[0]);
				}
			}
			// rough fallback
			return strtotime("$jy-$jm-$jd $h:$mi");
		}
		return (int) strtotime($s);
	}
}
