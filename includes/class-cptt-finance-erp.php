<?php
/**
 * CPTT Finance ERP — Bridge برای پنل React + Tailwind
 *
 * Phase 1 (v6.5.0): Embed بصری پنل React + Bootstrap اولیه (read-only).
 * Phase 2 (v6.6.0): اتصال داده زنده + CRUD کامل (این فایل).
 *
 * چه چیزی از کجا تامین می‌شود:
 *   - companies, branches, fiscalYears, costCenters, accounts (COA),
 *     vouchers, financialLocks → در WP options ذخیره می‌شوند.
 *   - treasuryAccounts → از CPTT_Finance::tbl_accounts (دیتابیس واقعی).
 *   - experts → از کاربران با role `cptt_expert` + جمع بدهی/تسویه از _cptt_steps.
 *   - projectSteps → از _cptt_steps پروژه‌ها (مراحل پرداخت‌نشده به کارشناس).
 *   - settlementHistory → از wp_cptt_ledger (type=expert_payout, expert_manual_payout).
 *   - incomesExpenses → از wp_cptt_fin_ledger.
 *
 * هیچ مسیر write ای داده‌های موجود پلاگین را خراب نمی‌کند. عملیات روی
 * مراحل پروژه از hookهای موجود (CPTT_Admin::ajax_step_settle و
 * cptt_after_*) عبور می‌کند تا notif و log‌های فعلی حفظ شوند.
 */

if (!defined('ABSPATH')) exit;

class CPTT_Finance_ERP {

	const PAGE_SLUG    = 'cptt-finance-erp';
	const NONCE        = 'cptt_finance_erp_nonce';

	const OPT_COMPANIES = 'cpttf_erp_companies';
	const OPT_BRANCHES  = 'cpttf_erp_branches';
	const OPT_FY        = 'cpttf_erp_fiscal_years';
	const OPT_COSTS     = 'cpttf_erp_cost_centers';
	const OPT_COA       = 'cpttf_erp_coa_nodes';
	const OPT_VOUCHERS  = 'cpttf_erp_vouchers';
	const OPT_LOCKS     = 'cpttf_erp_locks';

	private static $instance = null;
	public static function instance(){
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct(){
		add_action('admin_menu',           [$this, 'register_menu'], 14);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

		// Hide WP chrome completely on the ERP page (Phase 3)
		add_action('admin_init',            [$this, 'maybe_isolate_chrome'], 1);
		add_action('admin_print_styles',    [$this, 'inject_chrome_hide_css'], 999);
		add_action('admin_head',            [$this, 'suppress_admin_notices'], 1);

		// --- READ ---
		add_action('wp_ajax_cpttf_erp_bootstrap',      [$this, 'ajax_bootstrap']);          // legacy
		add_action('wp_ajax_cpttf_erp_kpis',           [$this, 'ajax_kpis']);
		add_action('wp_ajax_cpttf_erp_accounts',       [$this, 'ajax_accounts']);
		add_action('wp_ajax_cpttf_erp_ledger',         [$this, 'ajax_ledger']);
		add_action('wp_ajax_cpttf_erp_full_bootstrap', [$this, 'ajax_full_bootstrap']);      // NEW: full state

		// --- WRITE ---
		add_action('wp_ajax_cpttf_erp_voucher_save',      [$this, 'ajax_voucher_save']);
		add_action('wp_ajax_cpttf_erp_voucher_approve',   [$this, 'ajax_voucher_approve']);
		add_action('wp_ajax_cpttf_erp_voucher_delete',    [$this, 'ajax_voucher_delete']);
		add_action('wp_ajax_cpttf_erp_coa_save',          [$this, 'ajax_coa_save']);
		add_action('wp_ajax_cpttf_erp_coa_delete',        [$this, 'ajax_coa_delete']);
		add_action('wp_ajax_cpttf_erp_treasury_save',     [$this, 'ajax_treasury_save']);
		add_action('wp_ajax_cpttf_erp_treasury_delete',   [$this, 'ajax_treasury_delete']);
		add_action('wp_ajax_cpttf_erp_treasury_transfer', [$this, 'ajax_treasury_transfer']);
		add_action('wp_ajax_cpttf_erp_treasury_deposit',  [$this, 'ajax_treasury_deposit']);
		add_action('wp_ajax_cpttf_erp_treasury_withdraw', [$this, 'ajax_treasury_withdraw']);
		add_action('wp_ajax_cpttf_erp_settle_steps',      [$this, 'ajax_settle_steps']);
		add_action('wp_ajax_cpttf_erp_settle_manual',     [$this, 'ajax_settle_manual']);
		add_action('wp_ajax_cpttf_erp_ie_save',           [$this, 'ajax_ie_save']);
		add_action('wp_ajax_cpttf_erp_ie_delete',         [$this, 'ajax_ie_delete']);
		add_action('wp_ajax_cpttf_erp_cc_save',           [$this, 'ajax_cc_save']);
		add_action('wp_ajax_cpttf_erp_cc_delete',         [$this, 'ajax_cc_delete']);
		add_action('wp_ajax_cpttf_erp_fy_create',         [$this, 'ajax_fy_create']);
		add_action('wp_ajax_cpttf_erp_fy_close',          [$this, 'ajax_fy_close']);
		add_action('wp_ajax_cpttf_erp_lock_toggle',       [$this, 'ajax_lock_toggle']);

		// Phase 3
		add_action('wp_ajax_cpttf_erp_fincat_save',         [$this, 'ajax_fincat_save']);
		add_action('wp_ajax_cpttf_erp_fincat_delete',       [$this, 'ajax_fincat_delete']);
		add_action('wp_ajax_cpttf_erp_project_step_update', [$this, 'ajax_project_step_update']);

		// Phase 5: project-level quick collect from customer
		add_action('wp_ajax_cpttf_erp_project_quick_pay',   [$this, 'ajax_project_quick_pay']);
		add_action('wp_ajax_cpttf_erp_project_settle',      [$this, 'ajax_project_settle']);
	}

	/* ─────────────────────────────────────────────
	 * Detect ERP page (used to gate chrome-hiding hooks)
	 * ───────────────────────────────────────────── */
	public function is_erp_page(){
		return is_admin() && isset($_GET['page']) && $_GET['page'] === self::PAGE_SLUG;
	}

	/**
	 * On the ERP page we want a fullscreen app experience:
	 *   - hide the admin bar
	 *   - hide the entire side admin menu
	 *   - hide WP footer + screen options
	 *   - silence ALL admin notices/errors that other plugins may print
	 *   - swallow PHP notices/warnings so the React shell never gets dirty HTML
	 */
	public function maybe_isolate_chrome(){
		if (!$this->is_erp_page()) return;
		// 1. Admin bar off
		add_filter('show_admin_bar', '__return_false');
		// 2. Add body class for CSS targeting
		add_filter('admin_body_class', function($c){ return $c . ' cpttf-erp-fullscreen cpttf-erp-chromeless '; });
		// 3. Strip ALL notices added by anyone (no errors from other plugins)
		remove_all_actions('admin_notices');
		remove_all_actions('all_admin_notices');
		remove_all_actions('user_admin_notices');
		remove_all_actions('network_admin_notices');
		// 4. Hide PHP errors visually on THIS page only (still logged)
		@ini_set('display_errors', '0');
		@ini_set('display_startup_errors', '0');
	}

	public function inject_chrome_hide_css(){
		if (!$this->is_erp_page()) return;
		// IMPORTANT: keep CSS minimal & scoped to body.cpttf-erp-chromeless.
		// Don't touch html/body globals or .notice/.error (collisions with utility classes).
		echo '<style id="cpttf-erp-chromeless-css">
			body.cpttf-erp-chromeless #wpadminbar,
			body.cpttf-erp-chromeless #adminmenumain,
			body.cpttf-erp-chromeless #adminmenuback,
			body.cpttf-erp-chromeless #adminmenuwrap,
			body.cpttf-erp-chromeless #wpfooter,
			body.cpttf-erp-chromeless #screen-meta,
			body.cpttf-erp-chromeless #screen-meta-links,
			body.cpttf-erp-chromeless #contextual-help-link-wrap{ display:none !important; }
			html.wp-toolbar{ padding-top: 0 !important; }
			body.cpttf-erp-chromeless{ margin: 0 !important; }
			body.cpttf-erp-chromeless #wpcontent,
			body.cpttf-erp-chromeless #wpbody,
			body.cpttf-erp-chromeless #wpbody-content{
				margin: 0 !important;
				padding: 0 !important;
				min-height: 100vh;
				float: none !important;
				width: 100% !important;
				background: #f8fafc;
			}
			body.cpttf-erp-chromeless #wpwrap{ background:#f8fafc !important; min-height:100vh; }
			/* Hide stray WP admin notices that may have slipped past the action removals */
			body.cpttf-erp-chromeless > .notice,
			body.cpttf-erp-chromeless > .error,
			body.cpttf-erp-chromeless > .updated,
			body.cpttf-erp-chromeless > .update-nag,
			body.cpttf-erp-chromeless #wpbody-content > .notice,
			body.cpttf-erp-chromeless #wpbody-content > .error,
			body.cpttf-erp-chromeless #wpbody-content > .updated,
			body.cpttf-erp-chromeless #wpbody-content > .update-nag{ display:none !important; }
		</style>';
	}

	public function suppress_admin_notices(){
		// Reserved for future use; left as a no-op to avoid output-buffer races.
		return;
	}

	/**
	 * Strip every style/script registered by other plugins on this page.
	 * Only WP core essentials + our own bundle survive — so Tailwind v4
	 * styles inside the React shell are never overridden by polluting CSS.
	 */
	public function dequeue_3rd_party_assets(){
		if (!$this->is_erp_page()) return;
		global $wp_styles, $wp_scripts;
		$allow_prefixes = [
			'cpttf-erp-',         // our React bundle (only this!)
			'admin-bar',          // WP core; admin bar already hidden but tag survives
			'common',             // WP common
		];
		// Styles
		if ($wp_styles && !empty($wp_styles->queue)) {
			foreach ($wp_styles->queue as $handle) {
				$keep = false;
				foreach ($allow_prefixes as $p) { if (strpos($handle, $p) === 0) { $keep = true; break; } }
				if (!$keep) wp_dequeue_style($handle);
			}
		}
		// Scripts (keep jquery just in case other plugins still expect it on AJAX)
		$allow_scripts = $allow_prefixes;
		$allow_scripts[] = 'jquery';
		$allow_scripts[] = 'utils';
		if ($wp_scripts && !empty($wp_scripts->queue)) {
			foreach ($wp_scripts->queue as $handle) {
				$keep = false;
				foreach ($allow_scripts as $p) { if (strpos($handle, $p) === 0) { $keep = true; break; } }
				if (!$keep) wp_dequeue_script($handle);
			}
		}
	}

	/* ─────────────────────────────────────────────
	 * Menu + Assets
	 * ───────────────────────────────────────────── */
	public function register_menu(){
		add_submenu_page(
			'cptt-finance',
			'پنل حسابداری ERP',
			'⚡ پنل ERP جدید',
			'edit_cptt_projects',
			self::PAGE_SLUG,
			[$this, 'render_page']
		);
	}

	public function enqueue_assets($hook){
		if (!isset($_GET['page']) || $_GET['page'] !== self::PAGE_SLUG) return;
		add_filter('admin_body_class', function($c){ return $c . ' cpttf-erp-fullscreen '; });

		// Dequeue 3rd-party styles/scripts that would visually pollute our Tailwind shell.
		// Use a very small allowlist (only what WordPress core absolutely needs).
		add_action('admin_print_styles',  [$this, 'dequeue_3rd_party_assets'], 9999);
		add_action('admin_print_scripts', [$this, 'dequeue_3rd_party_assets'], 9999);

		wp_enqueue_style('cpttf-erp-app', CPTT_URL . 'assets/finance-ui/app.css', [], CPTT_VERSION);
		wp_enqueue_script('cpttf-erp-app', CPTT_URL . 'assets/finance-ui/app.js', [], CPTT_VERSION, true);
		add_filter('script_loader_tag', function($tag, $handle){
			if ($handle === 'cpttf-erp-app') {
				$tag = str_replace('<script ', '<script type="module" crossorigin ', $tag);
			}
			return $tag;
		}, 10, 2);

		$user  = wp_get_current_user();
		$roles = (array) $user->roles;
		$role  = 'cashier';
		if (in_array('administrator', $roles, true))     $role = 'ceo';
		elseif (in_array('cptt_expert', $roles, true))   $role = 'accountant';

		$bootstrap = [
			'env'        => 'wp',
			'version'    => CPTT_VERSION,
			'ajax'       => admin_url('admin-ajax.php'),
			'nonce'      => wp_create_nonce(self::NONCE),
			'user'       => ['id' => (int)$user->ID, 'name' => (string)$user->display_name, 'role' => $role],
			'company'    => ['name' => (string) get_bloginfo('name')],
			'currency'   => class_exists('CPTT_Currency') ? CPTT_Currency::label() : 'تومان',
			'counts'     => $this->counts_summary(),
			'urls'       => [
				'home'   => home_url('/'),
				'admin'  => admin_url(),
				'plugin' => admin_url('admin.php?page=cptt-finance'),
			],
		];
		wp_add_inline_script(
			'cpttf-erp-app',
			'window.CPTTF_ERP=' . wp_json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';',
			'before'
		);
	}

	public function render_page(){
		?>
		<style>
			/* Root mount: stays in normal document flow (NOT fixed) so the
			   React app's flex h-screen layout can take the full viewport
			   without colliding with the WP-removed chrome. */
			#cpttf-erp-root-wrap{
				background: #f8fafc;
				direction: rtl;
				width: 100%;
				min-height: 100vh;
			}
			#cpttf-erp-root-wrap > #root{ width:100%; min-height:100vh; display:block; }
			#cpttf-erp-loading{
				display:flex; flex-direction:column; align-items:center; justify-content:center;
				width:100%; min-height:100vh; gap:12px; color:#6366f1; font-family:Tahoma,sans-serif;
			}
			#cpttf-erp-loading .sp{
				width:48px; height:48px; border-radius:50%;
				border:3px solid #e0e7ff; border-top-color:#6366f1;
				animation: cpttfErpSpin 1s linear infinite;
			}
			@keyframes cpttfErpSpin{ to{ transform: rotate(360deg); } }
		</style>
		<div id="cpttf-erp-root-wrap">
			<div id="root">
				<div id="cpttf-erp-loading">
					<div class="sp"></div>
					<div>در حال بارگذاری پنل حسابداری ERP...</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ─────────────────────────────────────────────
	 * Helpers
	 * ───────────────────────────────────────────── */
	private function check(){
		if (!current_user_can('edit_cptt_projects')) wp_send_json_error('no_access', 403);
		check_ajax_referer(self::NONCE, 'nonce');
	}

	private function counts_summary(){
		global $wpdb;
		$pp = wp_count_posts('cptt_project');
		$projects = (int)($pp->publish ?? 0) + (int)($pp->draft ?? 0);
		$cu = count_users();
		$accounts = class_exists('CPTT_Finance')
			? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . CPTT_Finance::tbl_accounts())
			: 0;
		$categories = class_exists('CPTT_Finance')
			? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . CPTT_Finance::tbl_categories())
			: 0;
		$ledger = class_exists('CPTT_Finance')
			? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . CPTT_Finance::tbl_ledger())
			: 0;
		return [
			'projects'   => $projects,
			'users'      => (int)($cu['total_users'] ?? 0),
			'accounts'   => $accounts,
			'categories' => $categories,
			'ledger'     => $ledger,
		];
	}

	private function fa_today(){
		if (class_exists('CPTT_Core')) {
			$j = CPTT_Core::jalali_datetime(current_time('timestamp', true));
			if (preg_match('#^(\d{4}/\d{2}/\d{2})#', $j, $m)) return $m[1];
			return $j;
		}
		return date('Y/m/d');
	}

	private function fa_date_of($ts){
		if (!$ts) return $this->fa_today();
		if (class_exists('CPTT_Core')) {
			$j = CPTT_Core::jalali_datetime((int)$ts);
			if (preg_match('#^(\d{4}/\d{2}/\d{2})#', $j, $m)) return $m[1];
			return $j;
		}
		return date('Y/m/d', $ts);
	}

	/* ─────────────────────────────────────────────
	 * Defaults / Seeds
	 * ───────────────────────────────────────────── */
	private function default_companies(){
		return [[
			'id'                 => 'c_site',
			'name'               => (string) get_bloginfo('name'),
			'registrationNumber' => '',
			'logoColor'          => 'indigo',
		]];
	}
	private function default_branches(){
		return [[
			'id'        => 'b_main',
			'companyId' => 'c_site',
			'name'      => 'دفتر مرکزی',
			'code'      => '۱۰۰',
		]];
	}
	private function default_fiscal_years(){
		$jy = $this->fa_today();
		$y  = (int) substr($jy, 0, 4);
		if (!$y) $y = 1404;
		return [[
			'id'        => 'fy_' . $y,
			'companyId' => 'c_site',
			'name'      => 'سال مالی ' . $y,
			'startDate' => $y . '/۰۱/۰۱',
			'endDate'   => $y . '/۱۲/۲۹',
			'status'    => 'OPEN',
		]];
	}
	private function default_coa(){
		return [
			['id'=>'a1','code'=>'۱','name'=>'دارایی‌های جاری','type'=>'group','parentId'=>null,'balance'=>0],
			['id'=>'a2','code'=>'۲','name'=>'دارایی‌های غیرجاری','type'=>'group','parentId'=>null,'balance'=>0],
			['id'=>'a3','code'=>'۳','name'=>'بدهی‌های جاری','type'=>'group','parentId'=>null,'balance'=>0],
			['id'=>'a4','code'=>'۴','name'=>'حقوق صاحبان سهام','type'=>'group','parentId'=>null,'balance'=>0],
			['id'=>'a5','code'=>'۵','name'=>'درآمدها (فروش و خدمات)','type'=>'group','parentId'=>null,'balance'=>0],
			['id'=>'a6','code'=>'۶','name'=>'هزینه‌های عملیاتی و اداری','type'=>'group','parentId'=>null,'balance'=>0],

			['id'=>'a1_1','code'=>'۱۰۱','name'=>'موجودی نقد و بانک','type'=>'general','parentId'=>'a1','balance'=>0],
			['id'=>'a1_2','code'=>'۱۰۲','name'=>'حساب‌ها و اسناد دریافتنی','type'=>'general','parentId'=>'a1','balance'=>0],
			['id'=>'a6_1','code'=>'۶۰۱','name'=>'هزینه حقوق و دستمزد کارشناسان','type'=>'general','parentId'=>'a6','balance'=>0],
			['id'=>'a6_2','code'=>'۶۰۲','name'=>'هزینه‌های اداری و عمومی','type'=>'general','parentId'=>'a6','balance'=>0],

			['id'=>'a1_1_1','code'=>'۱۰۱۰۰۱','name'=>'بانک‌های ریالی','type'=>'subsidiary','parentId'=>'a1_1','balance'=>0],
			['id'=>'a1_1_2','code'=>'۱۰۱۰۰۲','name'=>'صندوق‌های ریالی','type'=>'subsidiary','parentId'=>'a1_1','balance'=>0],
			['id'=>'a1_2_1','code'=>'۱۰۲۰۰۱','name'=>'بدهکاران تجاری (مشتریان پروژه‌ها)','type'=>'subsidiary','parentId'=>'a1_2','balance'=>0],
		];
	}

	private function get_companies(){ $v=get_option(self::OPT_COMPANIES); return is_array($v)&&$v ? $v : $this->default_companies(); }
	private function get_branches(){  $v=get_option(self::OPT_BRANCHES);  return is_array($v)&&$v ? $v : $this->default_branches(); }
	private function get_fy(){        $v=get_option(self::OPT_FY);        return is_array($v)&&$v ? $v : $this->default_fiscal_years(); }
	private function get_costs(){     $v=get_option(self::OPT_COSTS, []); return is_array($v) ? $v : []; }
	private function get_coa(){       $v=get_option(self::OPT_COA);       return is_array($v)&&$v ? $v : $this->default_coa(); }
	private function get_vouchers(){  $v=get_option(self::OPT_VOUCHERS, []); return is_array($v) ? $v : []; }
	private function get_locks(){     $v=get_option(self::OPT_LOCKS, []); return is_array($v) ? $v : []; }

	/* ─────────────────────────────────────────────
	 * Compute treasury (real)
	 * ───────────────────────────────────────────── */
	private function build_treasury(){
		if (!class_exists('CPTT_Finance')) return [];
		$accs = CPTT_Finance::get_accounts(false);
		$out  = [];
		foreach ($accs as $a) {
			$out[] = [
				'id'             => 't' . (int)$a->id,
				'name'           => $a->name,
				'type'           => $a->type === 'bank' ? 'BANK' : 'CASH',
				'accountNumber'  => isset($a->account_number) ? $a->account_number : '',
				'iban'           => isset($a->iban) ? $a->iban : '',
				'balance'        => (float) CPTT_Finance::account_balance((int)$a->id),
				'currency'       => 'TOMAN',
				'_db_id'         => (int)$a->id,
				'status'         => (int)$a->status,
			];
		}
		return $out;
	}

	/* ─────────────────────────────────────────────
	 * Compute experts + project steps (real)
	 * ───────────────────────────────────────────── */
	private function build_experts_and_steps(){
		$users = get_users(['role' => 'cptt_expert', 'orderby' => 'display_name']);
		$expert_map = [];
		foreach ($users as $u) {
			$expert_map[(int)$u->ID] = [
				'id'              => 'e' . (int)$u->ID,
				'name'            => (string)$u->display_name,
				'role'            => 'کارشناس',
				'pendingBalance'  => 0.0,
				'paidBalance'     => 0.0,
				'projectsCount'   => 0,
			];
		}
		$projects = get_posts(['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>-1]);
		$steps_out = [];
		$counted_proj_per_expert = []; // expert_id => [project_id]
		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) continue;
			foreach ($steps as $idx => $st) {
				$sid = isset($st['id']) ? (string)$st['id'] : (string)$idx;
				$assigned = [];
				if (!empty($st['assigned_expert_ids']) && is_array($st['assigned_expert_ids'])) {
					$assigned = array_values(array_filter(array_unique(array_map('intval', $st['assigned_expert_ids']))));
				} elseif (!empty($st['assigned_expert_id'])) {
					$assigned = [(int)$st['assigned_expert_id']];
				}
				$exp_to_expert = (float)($st['exp_to_expert'] ?? 0);
				$exp_paid      = (float)($st['expert_paid']   ?? 0);
				$remain        = max(0, $exp_to_expert - $exp_paid);
				$settled       = !empty($st['settled']);
				foreach ($assigned as $eid) {
					if (!isset($expert_map[$eid])) {
						$u = get_user_by('id', $eid);
						$expert_map[$eid] = [
							'id'             => 'e' . $eid,
							'name'           => $u ? $u->display_name : ('#' . $eid),
							'role'           => 'کارشناس',
							'pendingBalance' => 0.0,
							'paidBalance'    => 0.0,
							'projectsCount'  => 0,
						];
					}
					if (!isset($counted_proj_per_expert[$eid])) $counted_proj_per_expert[$eid] = [];
					if (!in_array((int)$p->ID, $counted_proj_per_expert[$eid], true)) {
						$counted_proj_per_expert[$eid][] = (int)$p->ID;
						$expert_map[$eid]['projectsCount']++;
					}
					$expert_map[$eid]['paidBalance']    += $exp_paid;
					$expert_map[$eid]['pendingBalance'] += $remain;

					if (!$settled && $remain > 0) {
						$steps_out[] = [
							'id'           => 'ps_' . (int)$p->ID . '_' . $sid . '_' . $eid,
							'projectId'    => 'p' . (int)$p->ID,
							'projectName'  => get_the_title($p->ID),
							'expertId'     => 'e' . $eid,
							'expertName'   => $expert_map[$eid]['name'],
							'stepName'     => (string)($st['name'] ?? 'مرحله'),
							'amount'       => $remain,
							'status'       => 'PENDING',
							'date'         => $this->fa_date_of(strtotime($p->post_date_gmt)),
							'_raw'         => [
								'project_id' => (int)$p->ID,
								'step_id'    => $sid,
								'expert_id'  => $eid,
							],
						];
					}
				}
			}
		}
		return [
			'experts'      => array_values($expert_map),
			'projectSteps' => $steps_out,
		];
	}

	private function build_settlement_history(){
		global $wpdb;
		$tbl = $wpdb->prefix . 'cptt_ledger';
		$exists = (int) $wpdb->get_var("SHOW TABLES LIKE '$tbl'");
		if (!$exists) return [];
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $tbl WHERE type IN (%s,%s) ORDER BY id DESC LIMIT 200",
			'expert_payout', 'expert_manual_payout'
		));
		$out = [];
		foreach ((array)$rows as $r) {
			$uid = (int)($r->user_id ?? 0);
			$u   = $uid ? get_user_by('id', $uid) : null;
			$out[] = [
				'id'            => 'sh' . (int)$r->id,
				'expertId'      => 'e' . $uid,
				'expertName'    => $u ? $u->display_name : '—',
				'amount'        => abs((float)($r->amount ?? 0)),
				'date'          => $this->fa_date_of(strtotime($r->created_at ?? 'now')),
				'paymentMethod' => $r->type === 'expert_manual_payout' ? 'پرداخت دستی' : 'تسویه مرحله',
				'voucherId'     => '',
				'description'   => (string)($r->note ?? ''),
			];
		}
		return $out;
	}

	private function build_incomes_expenses(){
		if (!class_exists('CPTT_Finance')) return [];
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT * FROM " . CPTT_Finance::tbl_ledger() .
			" WHERE type IN ('income','expense','manual') ORDER BY id DESC LIMIT 300"
		);
		$out = [];
		foreach ((array)$rows as $r) {
			$out[] = [
				'id'            => 'ie' . (int)$r->id,
				'type'          => ((int)$r->direction > 0) ? 'INCOME' : 'EXPENSE',
				'title'         => mb_substr((string)$r->description, 0, 80),
				'amount'        => (float)$r->amount,
				'currency'      => 'TOMAN',
				'date'          => $this->fa_date_of((int)$r->date_at),
				'category'      => '',
				'projectId'     => (int)$r->project_id ? ('p' . (int)$r->project_id) : null,
				'costCenterId'  => null,
				'accountId'     => 'a5',
				'bankAccountId' => 't' . (int)$r->account_id,
				'description'   => (string)$r->description,
				'voucherId'     => '',
				'_db_id'        => (int)$r->id,
			];
		}
		return $out;
	}

	private function categories_payload(){
		$inc = []; $exp = [];
		if (class_exists('CPTT_Finance')) {
			foreach (CPTT_Finance::get_categories('income')  as $c) $inc[] = (string)$c->name;
			foreach (CPTT_Finance::get_categories('expense') as $c) $exp[] = (string)$c->name;
		}
		if (!$inc) $inc = ['درآمد پروژه‌ای', 'فروش محصول', 'خدمات مشاوره', 'سایر درآمدها'];
		if (!$exp) $exp = ['هزینه‌های اداری', 'هزینه نرم‌افزار/سرور', 'حقوق و دستمزد', 'بازاریابی و تبلیغات', 'سایر هزینه‌ها'];
		return ['income'=>$inc, 'expense'=>$exp];
	}

	private function finance_categories_payload(){
		$out = [];
		if (class_exists('CPTT_Finance')) {
			foreach (CPTT_Finance::get_categories('') as $c) {
				$out[] = [
					'id'    => 'fc' . (int)$c->id,
					'name'  => (string)$c->name,
					'type'  => (string)$c->type,
					'color' => (string)($c->color ?: '#64748b'),
					'icon'  => (string)($c->icon  ?: '🏷'),
				];
			}
		}
		return $out;
	}

	/* ─────────────────────────────────────────────
	 * Receivables: per-project per-customer outstanding
	 * ───────────────────────────────────────────── */
	private function build_receivables(){
		$out = [];
		$projects = get_posts(['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>-1]);
		foreach ($projects as $p) {
			$cid   = (int) get_post_meta($p->ID, '_cptt_client_user_id', true);
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			$cost = 0; $paid = 0;
			if (is_array($steps)) {
				foreach ($steps as $st) {
					$cost += (float)($st['cost'] ?? 0);
					$paid += (float)($st['paid'] ?? 0);
					if (!empty($st['extra_finance']) && is_array($st['extra_finance'])) {
						foreach ($st['extra_finance'] as $ef) {
							$cost += (float)($ef['cost'] ?? 0);
							$paid += (float)($ef['paid'] ?? 0);
						}
					}
				}
			}
			$is_settled = (int) get_post_meta($p->ID, '_cptt_is_settled', true) === 1;
			$customer_name = '—'; $customer_role = '';
			if ($cid) {
				$u = get_user_by('id', $cid);
				if ($u) {
					$customer_name = $u->display_name;
					$customer_role = !empty($u->roles) ? (string)$u->roles[0] : '';
				}
			}
			$out[] = [
				'projectId'    => 'p' . (int)$p->ID,
				'projectTitle' => (string) $p->post_title,
				'customerId'   => $cid,
				'customerName' => $customer_name,
				'customerRole' => $customer_role,
				'cost'         => $cost,
				'paid'         => $paid,
				'remain'       => max(0, $cost - $paid),
				'isSettled'    => $is_settled,
			];
		}
		// Sort: largest remain first
		usort($out, function($a, $b){ return $b['remain'] <=> $a['remain']; });
		return $out;
	}

	/* ─────────────────────────────────────────────
	 * Full projects with steps (for "حساب پروژه‌ها")
	 * ───────────────────────────────────────────── */
	private function build_projects_full(){
		$out = [];
		$projects = get_posts(['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>-1, 'orderby'=>'date', 'order'=>'DESC']);
		foreach ($projects as $p) {
			$cid   = (int) get_post_meta($p->ID, '_cptt_client_user_id', true);
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) $steps = [];

			$total_cost = 0; $total_paid = 0; $expert_paid_total = 0;
			$total_extra_cost = 0; $total_extra_paid = 0;
			$steps_out  = [];
			foreach ($steps as $idx => $st) {
				$base_cost = (float)($st['cost']        ?? 0);
				$base_paid = (float)($st['paid']        ?? 0);
				$cost = $base_cost;
				$paid = $base_paid;
				$ex_to = (float)($st['exp_to_expert'] ?? 0);
				$ex_pd = (float)($st['expert_paid']   ?? 0);
				$ef_cost = 0; $ef_paid = 0;
				if (!empty($st['extra_finance']) && is_array($st['extra_finance'])) {
					foreach ($st['extra_finance'] as $ef) {
						$ef_cost += (float)($ef['cost'] ?? 0);
						$ef_paid += (float)($ef['paid'] ?? 0);
					}
					$cost += $ef_cost;
					$paid += $ef_paid;
					$total_extra_cost += $ef_cost;
					$total_extra_paid += $ef_paid;
				}
				$total_cost        += $cost;
				$total_paid        += $paid;
				$expert_paid_total += $ex_pd;
				$assigned = [];
				if (!empty($st['assigned_expert_ids']) && is_array($st['assigned_expert_ids'])) {
					$assigned = array_values(array_filter(array_unique(array_map('intval', $st['assigned_expert_ids']))));
				} elseif (!empty($st['assigned_expert_id'])) {
					$assigned = [(int)$st['assigned_expert_id']];
				}
				$steps_out[] = [
					'id'                  => isset($st['id']) ? (string)$st['id'] : (string)$idx,
					'name'                => (string)($st['name'] ?? 'مرحله ' . ($idx+1)),
					'cost'                => $cost,
					'paid'                => $paid,
					'base_cost'           => $base_cost,
					'base_paid'           => $base_paid,
					'extra_cost'          => $ef_cost,
					'extra_paid'          => $ef_paid,
					'expert_to_pay'       => $ex_to,
					'expert_paid'         => $ex_pd,
					'settled'             => !empty($st['settled']),
					'assigned_expert_ids' => $assigned,
				];
			}

			$cust_name = '—';
			if ($cid) {
				$u = get_user_by('id', $cid);
				$cust_name = $u ? $u->display_name : '#' . $cid;
			}
			$out[] = [
				'id'              => 'p' . (int)$p->ID,
				'numericId'       => (int) $p->ID,
				'title'           => (string) $p->post_title,
				'customerId'      => $cid,
				'customerName'    => $cust_name,
				'totalCost'       => $total_cost,        // includes extra_finance
				'totalPaid'       => $total_paid,        // includes extra_finance
				'totalExtraCost'  => $total_extra_cost,  // breakdown
				'totalExtraPaid'  => $total_extra_paid,  // breakdown
				'expertPaid'      => $expert_paid_total,
				'isSettled'       => (int) get_post_meta($p->ID, '_cptt_is_settled', true) === 1,
				'steps'           => $steps_out,
				'date'            => $this->fa_date_of(strtotime($p->post_date_gmt)),
			];
		}
		return $out;
	}

	/* ─────────────────────────────────────────────
	 * Full Bootstrap (the heart of Phase 2)
	 * ───────────────────────────────────────────── */
	public function ajax_full_bootstrap(){
		$this->check();
		$user = wp_get_current_user();
		$roles = (array)$user->roles;
		$role = 'cashier';
		if (in_array('administrator', $roles, true))     $role = 'ceo';
		elseif (in_array('cptt_expert', $roles, true))   $role = 'accountant';

		$es = $this->build_experts_and_steps();
		$payload = [
			'companies'         => $this->get_companies(),
			'branches'          => $this->get_branches(),
			'currencies'        => [],
			'fiscalYears'       => $this->get_fy(),
			'costCenters'       => $this->get_costs(),
			'accounts'          => $this->get_coa(),
			'vouchers'          => $this->get_vouchers(),
			'experts'           => $es['experts'],
			'projectSteps'      => $es['projectSteps'],
			'settlementHistory' => $this->build_settlement_history(),
			'incomesExpenses'   => $this->build_incomes_expenses(),
			'treasuryAccounts'  => $this->build_treasury(),
			'financialLocks'    => $this->get_locks(),
			'categories'        => $this->categories_payload(),
			'financeCategories' => $this->finance_categories_payload(),
			'receivables'       => $this->build_receivables(),
			'projectsFull'      => $this->build_projects_full(),
			'user'              => ['id'=>(int)$user->ID, 'name'=>(string)$user->display_name, 'role'=>$role],
			'kpis'              => class_exists('CPTT_Finance') ? CPTT_Finance::compute_kpis() : [],
			'monthlySeries'     => class_exists('CPTT_Finance') ? CPTT_Finance::monthly_series() : [],
			'topCustomers'      => class_exists('CPTT_Finance') ? CPTT_Finance::top_customers_series(6) : [],
			'categoryBreakdownExpense' => class_exists('CPTT_Finance') ? CPTT_Finance::category_breakdown('expense') : [],
			'categoryBreakdownIncome'  => class_exists('CPTT_Finance') ? CPTT_Finance::category_breakdown('income') : [],
			'counts'            => $this->counts_summary(),
		];
		wp_send_json_success($payload);
	}

	/* ─────────────────────────────────────────────
	 * Phase 3: Finance categories CRUD (real DB)
	 * ───────────────────────────────────────────── */
	public function ajax_fincat_save(){
		$this->check();
		if (!class_exists('CPTT_Finance')) wp_send_json_error('finance missing', 500);
		$raw = isset($_POST['category']) ? wp_unslash($_POST['category']) : '';
		$c   = json_decode($raw, true);
		if (!is_array($c) || empty($c['name'])) wp_send_json_error('invalid', 400);
		global $wpdb;
		$type = ($c['type'] === 'income') ? 'income' : 'expense';
		$row  = [
			'name'  => sanitize_text_field((string)$c['name']),
			'type'  => $type,
			'color' => sanitize_text_field((string)($c['color'] ?? '#64748b')),
			'icon'  => (string)($c['icon'] ?? '🏷'),
		];
		$db_id = 0;
		if (!empty($c['id']) && preg_match('/^fc(\d+)$/', (string)$c['id'], $m)) {
			$db_id = (int)$m[1];
			$wpdb->update(CPTT_Finance::tbl_categories(), $row, ['id'=>$db_id]);
		} else {
			$wpdb->insert(CPTT_Finance::tbl_categories(), $row);
			$db_id = (int)$wpdb->insert_id;
		}
		wp_send_json_success(['id'=>'fc'.$db_id]);
	}
	public function ajax_fincat_delete(){
		$this->check();
		if (!class_exists('CPTT_Finance')) wp_send_json_error('finance missing', 500);
		$id = (string)($_POST['id'] ?? '');
		if (!preg_match('/^fc(\d+)$/', $id, $m)) wp_send_json_error('invalid', 400);
		global $wpdb;
		$wpdb->delete(CPTT_Finance::tbl_categories(), ['id'=>(int)$m[1]]);
		wp_send_json_success();
	}

	/* ─────────────────────────────────────────────
	 * Phase 5: Quick collect payment from customer (project-level)
	 * Distributes amount across pending steps (cost > paid) FIFO,
	 * also writes a ledger entry to chosen treasury account.
	 * ───────────────────────────────────────────── */
	public function ajax_project_quick_pay(){
		$this->check();
		$pid     = (int)($_POST['project_id'] ?? 0);
		$amount  = (float)($_POST['amount']  ?? 0);
		$payment = (string)($_POST['payment_method_id'] ?? '');
		$note    = sanitize_text_field((string)($_POST['note'] ?? ''));
		if (!$pid || $amount <= 0) wp_send_json_error('invalid', 400);
		if (get_post_type($pid) !== 'cptt_project') wp_send_json_error('not_project', 400);

		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];
		$applied = 0; $remaining = $amount;
		foreach ($steps as &$s) {
			$cost = (float)($s['cost'] ?? 0);
			$paid = (float)($s['paid'] ?? 0);
			if ($cost > 0 && $paid < $cost) {
				$need = $cost - $paid;
				$pay  = min($remaining, $need);
				$s['paid'] = $paid + $pay;
				$applied   += $pay;
				$remaining -= $pay;
				if ($remaining <= 0) break;
			}
		}
		unset($s);
		if ($applied <= 0) wp_send_json_error('nothing_payable', 400);
		update_post_meta($pid, '_cptt_steps', $steps);

		// Log into finance ledger (treasury account credit)
		if (class_exists('CPTT_Finance') && preg_match('/^t(\d+)$/', $payment, $mp)) {
			CPTT_Finance::ledger_insert([
				'account_id'  => (int)$mp[1],
				'type'        => 'income',
				'direction'   => 1,
				'amount'      => $applied,
				'project_id'  => $pid,
				'description' => 'دریافت از مشتری پروژه: ' . get_the_title($pid) . ($note ? ' — ' . $note : ''),
				'date_at'     => (int) current_time('timestamp', true),
				'ref_type'    => 'erp_project_quick_pay',
			]);
		}

		// Bale notif to customer + activity log
		$cid = (int) get_post_meta($pid, '_cptt_client_user_id', true);
		if ($cid && class_exists('CPTT_Bale')) {
			CPTT_Bale::notify_via_bale($cid, '✅ پرداخت ' . number_format($applied) . ' تومان برای پروژه «' . get_the_title($pid) . '» ثبت شد.', 'payment', $pid);
		}
		if (class_exists('CPTT_Core')) {
			CPTT_Core::activity_log('project', $pid, 'erp_quick_pay', 'ERP — دریافت ' . number_format($applied) . ' تومان از مشتری');
		}
		wp_send_json_success(['applied' => $applied, 'remaining_unallocated' => $remaining]);
	}

	/* Mark a whole project as settled (toggle) */
	public function ajax_project_settle(){
		$this->check();
		$pid = (int)($_POST['project_id'] ?? 0);
		$on  = (int)($_POST['settled']    ?? 1);
		if (!$pid) wp_send_json_error('invalid', 400);
		update_post_meta($pid, '_cptt_is_settled', $on ? 1 : 0);
		if (class_exists('CPTT_Core')) {
			CPTT_Core::activity_log('project', $pid, 'erp_project_settle', $on ? 'پروژه تسویه‌شده علامت زده شد' : 'پروژه به وضعیت باز برگشت');
		}
		wp_send_json_success(['settled' => (bool)$on]);
	}

	/* ─────────────────────────────────────────────
	 * Phase 3: Update finance fields of a project step
	 * (used by حساب پروژه‌ها if user edits inline)
	 * ───────────────────────────────────────────── */
	public function ajax_project_step_update(){
		$this->check();
		$pid    = (int)($_POST['project_id'] ?? 0);
		$stepid = (string)($_POST['step_id'] ?? '');
		$raw    = isset($_POST['fields']) ? wp_unslash($_POST['fields']) : '';
		$flds   = json_decode($raw, true);
		if (!$pid || $stepid === '' || !is_array($flds)) wp_send_json_error('invalid', 400);
		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!is_array($steps)) wp_send_json_error('no steps', 404);
		$found = false;
		foreach ($steps as $i => $st) {
			$ssid = isset($st['id']) ? (string)$st['id'] : (string)$i;
			if ($ssid !== $stepid) continue;
			$found = true;
			foreach (['cost','paid','exp_to_expert','expert_paid'] as $f) {
				if (array_key_exists($f, $flds)) $steps[$i][$f] = (float)$flds[$f];
			}
			if (array_key_exists('settled', $flds)) $steps[$i]['settled'] = (bool)$flds['settled'];
			break;
		}
		if (!$found) wp_send_json_error('step not found', 404);
		update_post_meta($pid, '_cptt_steps', $steps);
		wp_send_json_success();
	}

	/* legacy */
	public function ajax_bootstrap(){
		$this->check();
		wp_send_json_success([
			'counts' => $this->counts_summary(),
			'kpis'   => class_exists('CPTT_Finance') ? CPTT_Finance::compute_kpis() : [],
		]);
	}
	public function ajax_kpis(){
		$this->check();
		wp_send_json_success(class_exists('CPTT_Finance') ? CPTT_Finance::compute_kpis() : []);
	}
	public function ajax_accounts(){
		$this->check();
		wp_send_json_success($this->build_treasury());
	}
	public function ajax_ledger(){
		$this->check();
		global $wpdb;
		if (!class_exists('CPTT_Finance')) wp_send_json_success([]);
		$rows = $wpdb->get_results("SELECT * FROM " . CPTT_Finance::tbl_ledger() . " ORDER BY date_at DESC LIMIT 500");
		wp_send_json_success($rows);
	}

	/* ─────────────────────────────────────────────
	 * VOUCHERS (stored in options)
	 * ───────────────────────────────────────────── */
	public function ajax_voucher_save(){
		$this->check();
		$raw = isset($_POST['voucher']) ? wp_unslash($_POST['voucher']) : '';
		$v   = json_decode($raw, true);
		if (!is_array($v)) wp_send_json_error('invalid voucher', 400);

		$vouchers = $this->get_vouchers();
		$max = 1000;
		foreach ($vouchers as $vv) if (!empty($vv['voucherNumber'])) $max = max($max, (int)$vv['voucherNumber']);

		$next_number = (int)$max + 1;
		$id = isset($v['id']) && $v['id'] ? (string)$v['id'] : ('v_' . time() . '_' . wp_generate_password(4, false, false));

		$voucher = wp_parse_args($v, [
			'id'              => $id,
			'voucherNumber'   => $next_number,
			'date'            => $this->fa_today(),
			'description'     => '',
			'status'          => 'DRAFT',
			'companyId'       => 'c_site',
			'branchId'        => 'b_main',
			'fiscalYearId'    => '',
			'currency'        => 'TOMAN',
			'exchangeRate'    => 1,
			'rows'            => [],
			'createdBy'       => wp_get_current_user()->display_name,
			'isAutoGenerated' => false,
		]);
		$replaced = false;
		foreach ($vouchers as $i => $vv) {
			if ((string)$vv['id'] === (string)$voucher['id']) { $vouchers[$i] = $voucher; $replaced = true; break; }
		}
		if (!$replaced) array_unshift($vouchers, $voucher);
		update_option(self::OPT_VOUCHERS, $vouchers, false);
		wp_send_json_success(['id'=>$voucher['id'], 'voucherNumber'=>$voucher['voucherNumber']]);
	}

	public function ajax_voucher_approve(){
		$this->check();
		$id   = sanitize_text_field((string)($_POST['id'] ?? ''));
		$role = sanitize_text_field((string)($_POST['role'] ?? ''));
		$vouchers = $this->get_vouchers();
		foreach ($vouchers as $i => $v) {
			if ((string)$v['id'] !== $id) continue;
			$st = (string)($v['status'] ?? 'DRAFT');
			$new = $st;
			if      ($role === 'accountant' && $st === 'DRAFT')              { $new = 'ACCOUNTANT_APPROVED'; $vouchers[$i]['approvedByAccountant'] = wp_get_current_user()->display_name . ' (حسابدار)'; }
			elseif  ($role === 'financial_manager' && $st === 'ACCOUNTANT_APPROVED') { $new = 'MANAGER_APPROVED'; $vouchers[$i]['approvedByManager'] = wp_get_current_user()->display_name . ' (مدیر مالی)'; }
			elseif  ($role === 'ceo' && in_array($st, ['MANAGER_APPROVED','ACCOUNTANT_APPROVED'], true)) { $new = 'FINALIZED'; $vouchers[$i]['approvedByCEO'] = wp_get_current_user()->display_name; }
			elseif  ($role === 'ceo' && $st === 'DRAFT') {
				$new = 'FINALIZED';
				$vouchers[$i]['approvedByAccountant'] = 'سیستم (تأیید سریع)';
				$vouchers[$i]['approvedByManager']    = 'سیستم (تأیید سریع)';
				$vouchers[$i]['approvedByCEO']        = wp_get_current_user()->display_name;
			}
			$vouchers[$i]['status'] = $new;
			break;
		}
		update_option(self::OPT_VOUCHERS, $vouchers, false);
		wp_send_json_success();
	}

	public function ajax_voucher_delete(){
		$this->check();
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		$vouchers = $this->get_vouchers();
		$vouchers = array_values(array_filter($vouchers, function($v) use ($id){ return (string)$v['id'] !== $id; }));
		update_option(self::OPT_VOUCHERS, $vouchers, false);
		wp_send_json_success();
	}

	/* ─────────────────────────────────────────────
	 * CHART OF ACCOUNTS (options)
	 * ───────────────────────────────────────────── */
	public function ajax_coa_save(){
		$this->check();
		$raw  = isset($_POST['node']) ? wp_unslash($_POST['node']) : '';
		$node = json_decode($raw, true);
		if (!is_array($node) || empty($node['name']) || empty($node['code'])) wp_send_json_error('invalid', 400);
		$coa = $this->get_coa();
		$id  = !empty($node['id']) ? (string)$node['id'] : ('a_' . time());
		$row = [
			'id'       => $id,
			'code'     => (string)$node['code'],
			'name'     => (string)$node['name'],
			'type'     => in_array(($node['type'] ?? 'detail'), ['group','general','subsidiary','detail'], true) ? $node['type'] : 'detail',
			'parentId' => isset($node['parentId']) ? (is_null($node['parentId']) ? null : (string)$node['parentId']) : null,
			'balance'  => isset($node['balance']) ? (float)$node['balance'] : 0,
		];
		$found = false;
		foreach ($coa as $i => $n) if ((string)$n['id'] === $id) { $coa[$i] = $row; $found = true; break; }
		if (!$found) $coa[] = $row;
		update_option(self::OPT_COA, $coa, false);
		wp_send_json_success(['id'=>$id]);
	}

	public function ajax_coa_delete(){
		$this->check();
		$id  = sanitize_text_field((string)($_POST['id'] ?? ''));
		$coa = $this->get_coa();
		$coa = array_values(array_filter($coa, function($n) use ($id){ return (string)$n['id'] !== $id; }));
		update_option(self::OPT_COA, $coa, false);
		wp_send_json_success();
	}

	/* ─────────────────────────────────────────────
	 * TREASURY (real cptt_fin_accounts)
	 * ───────────────────────────────────────────── */
	public function ajax_treasury_save(){
		$this->check();
		if (!class_exists('CPTT_Finance')) wp_send_json_error('finance missing', 500);
		$raw = isset($_POST['account']) ? wp_unslash($_POST['account']) : '';
		$a   = json_decode($raw, true);
		if (!is_array($a) || empty($a['name'])) wp_send_json_error('invalid', 400);
		global $wpdb;
		$db_id = 0;
		if (!empty($a['_db_id'])) $db_id = (int)$a['_db_id'];
		elseif (!empty($a['id']) && preg_match('/^t(\d+)$/', (string)$a['id'], $m)) $db_id = (int)$m[1];

		$row = [
			'name'           => sanitize_text_field((string)$a['name']),
			'type'           => (isset($a['type']) && $a['type'] === 'BANK') ? 'bank' : 'cash',
			'account_number' => isset($a['accountNumber']) ? sanitize_text_field((string)$a['accountNumber']) : '',
			'iban'           => isset($a['iban']) ? sanitize_text_field((string)$a['iban']) : '',
			'initial_balance'=> isset($a['balance']) ? (float)$a['balance'] : 0,
			'status'         => 1,
		];
		if ($db_id > 0) {
			$wpdb->update(CPTT_Finance::tbl_accounts(), $row, ['id'=>$db_id]);
		} else {
			$row['created_at'] = (int) current_time('timestamp', true);
			$row['created_by'] = (int) get_current_user_id();
			$wpdb->insert(CPTT_Finance::tbl_accounts(), $row);
			$db_id = (int)$wpdb->insert_id;
		}
		wp_send_json_success(['id'=>'t'.$db_id, '_db_id'=>$db_id]);
	}
	public function ajax_treasury_delete(){
		$this->check();
		if (!class_exists('CPTT_Finance')) wp_send_json_error('finance missing', 500);
		$id = (string)($_POST['id'] ?? '');
		if (!preg_match('/^t(\d+)$/', $id, $m)) wp_send_json_error('invalid', 400);
		$db_id = (int)$m[1];
		global $wpdb;
		$wpdb->update(CPTT_Finance::tbl_accounts(), ['status'=>0], ['id'=>$db_id]);
		wp_send_json_success();
	}
	public function ajax_treasury_transfer(){
		$this->check();
		if (!class_exists('CPTT_Finance')) wp_send_json_error('finance missing', 500);
		$from = (string)($_POST['from_id'] ?? '');
		$to   = (string)($_POST['to_id']   ?? '');
		$amt  = (float)($_POST['amount']   ?? 0);
		$desc = sanitize_text_field((string)($_POST['description'] ?? ''));
		if (!preg_match('/^t(\d+)$/', $from, $mf)) wp_send_json_error('bad from', 400);
		if (!preg_match('/^t(\d+)$/', $to,   $mt)) wp_send_json_error('bad to', 400);
		$f = (int)$mf[1]; $t = (int)$mt[1];
		if ($f === $t || $amt <= 0) wp_send_json_error('invalid', 400);
		$ts = (int) current_time('timestamp', true);
		$out_id = CPTT_Finance::ledger_insert([
			'account_id'=>$f, 'type'=>'transfer_out', 'direction'=>-1, 'amount'=>$amt, 'date_at'=>$ts,
			'description'=>'انتقال به حساب #'.$t.($desc?(' — '.$desc):''),
		]);
		$in_id = CPTT_Finance::ledger_insert([
			'account_id'=>$t, 'type'=>'transfer_in', 'direction'=>1, 'amount'=>$amt, 'date_at'=>$ts,
			'description'=>'انتقال از حساب #'.$f.($desc?(' — '.$desc):''),
			'linked_id'=>$out_id,
		]);
		global $wpdb;
		$wpdb->update(CPTT_Finance::tbl_ledger(), ['linked_id'=>$in_id], ['id'=>$out_id]);
		wp_send_json_success();
	}
	public function ajax_treasury_deposit(){
		$this->check();
		if (!class_exists('CPTT_Finance')) wp_send_json_error('finance missing', 500);
		$acc_id = (string)($_POST['account_id'] ?? '');
		$amt    = (float)($_POST['amount']      ?? 0);
		$desc   = sanitize_text_field((string)($_POST['description'] ?? ''));
		if (!preg_match('/^t(\d+)$/', $acc_id, $m)) wp_send_json_error('bad acc', 400);
		if ($amt <= 0) wp_send_json_error('invalid amount', 400);
		CPTT_Finance::ledger_insert([
			'account_id'=>(int)$m[1], 'type'=>'income', 'direction'=>1, 'amount'=>$amt,
			'date_at'=>(int)current_time('timestamp', true),
			'description'=>$desc ?: 'واریز دستی به حساب',
		]);
		wp_send_json_success();
	}
	public function ajax_treasury_withdraw(){
		$this->check();
		if (!class_exists('CPTT_Finance')) wp_send_json_error('finance missing', 500);
		$acc_id = (string)($_POST['account_id'] ?? '');
		$amt    = (float)($_POST['amount']      ?? 0);
		$desc   = sanitize_text_field((string)($_POST['description'] ?? ''));
		if (!preg_match('/^t(\d+)$/', $acc_id, $m)) wp_send_json_error('bad acc', 400);
		if ($amt <= 0) wp_send_json_error('invalid amount', 400);
		CPTT_Finance::ledger_insert([
			'account_id'=>(int)$m[1], 'type'=>'expense', 'direction'=>-1, 'amount'=>$amt,
			'date_at'=>(int)current_time('timestamp', true),
			'description'=>$desc ?: 'برداشت دستی از حساب',
		]);
		wp_send_json_success();
	}

	/* ─────────────────────────────────────────────
	 * EXPERTS SETTLEMENT (واقعی، از طریق پلاگین)
	 * ───────────────────────────────────────────── */
	public function ajax_settle_steps(){
		$this->check();
		$payment       = (string)($_POST['payment_method_id'] ?? '');
		$desc          = sanitize_text_field((string)($_POST['description'] ?? ''));
		$step_ids_raw  = isset($_POST['step_ids']) ? wp_unslash($_POST['step_ids']) : '[]';
		$step_ids      = json_decode($step_ids_raw, true);
		if (!is_array($step_ids)) wp_send_json_error('no steps', 400);

		// step_ids look like 'ps_<projectId>_<stepId>_<expertId>'
		$processed = []; $total = 0; $expert_id = 0; $any = false;
		foreach ($step_ids as $sid) {
			if (!preg_match('/^ps_(\d+)_(.+)_(\d+)$/', (string)$sid, $m)) continue;
			$pid = (int)$m[1]; $step = (string)$m[2]; $eid = (int)$m[3];
			if (!$expert_id) $expert_id = $eid;
			$steps = get_post_meta($pid, '_cptt_steps', true);
			if (!is_array($steps)) continue;
			foreach ($steps as $i => $st) {
				$ssid = isset($st['id']) ? (string)$st['id'] : (string)$i;
				if ($ssid !== $step) continue;
				$exp_to_expert = (float)($st['exp_to_expert'] ?? 0);
				$exp_paid      = (float)($st['expert_paid']   ?? 0);
				$remain        = max(0, $exp_to_expert - $exp_paid);
				if ($remain <= 0) continue;
				$steps[$i]['expert_paid'] = $exp_paid + $remain;
				$steps[$i]['settled']     = !empty($steps[$i]['settled']) ? $steps[$i]['settled'] : true;
				update_post_meta($pid, '_cptt_steps', $steps);
				$total += $remain;
				$any = true;
				do_action('cptt_after_expert_payout', $pid, $step, $eid, $remain, 'erp_panel');
				$processed[] = ['pid'=>$pid,'step'=>$step,'amount'=>$remain,'expert'=>$eid];
				break;
			}
		}

		if (!$any) wp_send_json_error('nothing_to_settle', 400);

		if (class_exists('CPTT_Finance') && preg_match('/^t(\d+)$/', $payment, $mp)) {
			CPTT_Finance::ledger_insert([
				'account_id'  => (int)$mp[1],
				'type'        => 'expert_payout',
				'direction'   => -1,
				'amount'      => $total,
				'expert_id'   => $expert_id,
				'description' => 'تسویه با کارشناس از پنل ERP — ' . count($processed) . ' مرحله' . ($desc ? ' — ' . $desc : ''),
				'date_at'     => (int)current_time('timestamp', true),
				'ref_type'    => 'erp_settle_steps',
			]);
		}

		wp_send_json_success(['settled'=>count($processed), 'total'=>$total]);
	}

	public function ajax_settle_manual(){
		$this->check();
		$expert_id_str = (string)($_POST['expert_id'] ?? '');
		$amount        = (float)($_POST['amount']    ?? 0);
		$payment       = (string)($_POST['payment_method_id'] ?? '');
		$desc          = sanitize_text_field((string)($_POST['description'] ?? ''));
		if (!preg_match('/^e(\d+)$/', $expert_id_str, $m)) wp_send_json_error('bad expert', 400);
		if ($amount <= 0) wp_send_json_error('invalid amount', 400);
		$eid = (int)$m[1];

		if (class_exists('CPTT_Core')) {
			CPTT_Core::ledger_add(['user_id'=>$eid,'type'=>'expert_manual_payout','amount'=>-$amount,'note'=>$desc]);
			CPTT_Core::activity_log('user', $eid, 'manual_expert_payment', 'ERP — پرداخت دستی به کارشناس: ' . number_format($amount));
		}
		if (class_exists('CPTT_Bale')) {
			CPTT_Bale::notify_via_bale($eid, '💸 پرداخت دستی به مبلغ ' . number_format($amount) . ' تومان ثبت شد.' . ($desc ? "\n" . $desc : ''), 'expert_payout', 0);
		}
		do_action('cptt_after_manual_expert_payment', $eid, $amount, $desc);

		if (class_exists('CPTT_Finance') && preg_match('/^t(\d+)$/', $payment, $mp)) {
			CPTT_Finance::ledger_insert([
				'account_id'  => (int)$mp[1],
				'type'        => 'expert_payout',
				'direction'   => -1,
				'amount'      => $amount,
				'expert_id'   => $eid,
				'description' => 'پرداخت دستی از پنل ERP' . ($desc ? ' — ' . $desc : ''),
				'date_at'     => (int)current_time('timestamp', true),
				'ref_type'    => 'erp_settle_manual',
			]);
		}
		wp_send_json_success(['amount'=>$amount]);
	}

	/* ─────────────────────────────────────────────
	 * INCOME / EXPENSE (داخل cptt_fin_ledger)
	 * ───────────────────────────────────────────── */
	public function ajax_ie_save(){
		$this->check();
		if (!class_exists('CPTT_Finance')) wp_send_json_error('finance missing', 500);
		$raw = isset($_POST['item']) ? wp_unslash($_POST['item']) : '';
		$it  = json_decode($raw, true);
		if (!is_array($it)) wp_send_json_error('invalid', 400);
		$type   = ($it['type'] ?? 'INCOME') === 'INCOME' ? 'income' : 'expense';
		$amount = (float)($it['amount'] ?? 0);
		if ($amount <= 0) wp_send_json_error('amount', 400);
		$bank_id = 0;
		if (!empty($it['bankAccountId']) && preg_match('/^t(\d+)$/', (string)$it['bankAccountId'], $m)) $bank_id = (int)$m[1];
		if (!$bank_id) $bank_id = CPTT_Finance::default_cash_account_id();
		$pid = 0;
		if (!empty($it['projectId']) && preg_match('/^p(\d+)$/', (string)$it['projectId'], $mp)) $pid = (int)$mp[1];

		$id = CPTT_Finance::ledger_insert([
			'account_id'  => $bank_id,
			'type'        => $type,
			'direction'   => $type === 'income' ? 1 : -1,
			'amount'      => $amount,
			'project_id'  => $pid,
			'description' => sanitize_text_field((string)($it['title'] ?? '')) . (!empty($it['description']) ? ' — ' . sanitize_text_field((string)$it['description']) : ''),
			'date_at'     => (int) current_time('timestamp', true),
			'ref_type'    => 'erp_ie',
		]);
		wp_send_json_success(['id'=>'ie'.$id]);
	}
	public function ajax_ie_delete(){
		$this->check();
		if (!class_exists('CPTT_Finance')) wp_send_json_error('finance missing', 500);
		$id = (string)($_POST['id'] ?? '');
		if (!preg_match('/^ie(\d+)$/', $id, $m)) wp_send_json_error('invalid', 400);
		global $wpdb;
		$wpdb->delete(CPTT_Finance::tbl_ledger(), ['id'=>(int)$m[1]]);
		wp_send_json_success();
	}

	/* ─────────────────────────────────────────────
	 * COST CENTERS, FISCAL YEARS, LOCKS (options)
	 * ───────────────────────────────────────────── */
	public function ajax_cc_save(){
		$this->check();
		$raw = isset($_POST['center']) ? wp_unslash($_POST['center']) : '';
		$cc  = json_decode($raw, true);
		if (!is_array($cc) || empty($cc['name'])) wp_send_json_error('invalid', 400);
		$id   = !empty($cc['id']) ? (string)$cc['id'] : ('cc_' . time());
		$type = in_array(($cc['type'] ?? 'PROJECT'), ['PROJECT','UNIT','TEAM','BRANCH'], true) ? $cc['type'] : 'PROJECT';
		$row  = [
			'id'     => $id,
			'name'   => sanitize_text_field((string)$cc['name']),
			'type'   => $type,
			'code'   => sanitize_text_field((string)($cc['code'] ?? '')),
			'budget' => isset($cc['budget']) ? (float)$cc['budget'] : 0,
		];
		$all = $this->get_costs();
		$found = false;
		foreach ($all as $i => $r) if ((string)$r['id'] === $id) { $all[$i] = $row; $found = true; break; }
		if (!$found) $all[] = $row;
		update_option(self::OPT_COSTS, $all, false);
		wp_send_json_success(['id'=>$id]);
	}
	public function ajax_cc_delete(){
		$this->check();
		$id  = sanitize_text_field((string)($_POST['id'] ?? ''));
		$all = $this->get_costs();
		$all = array_values(array_filter($all, function($r) use ($id){ return (string)$r['id'] !== $id; }));
		update_option(self::OPT_COSTS, $all, false);
		wp_send_json_success();
	}
	public function ajax_fy_create(){
		$this->check();
		$name  = sanitize_text_field((string)($_POST['name']       ?? ''));
		$start = sanitize_text_field((string)($_POST['start_date'] ?? ''));
		$end   = sanitize_text_field((string)($_POST['end_date']   ?? ''));
		if ($name === '') wp_send_json_error('name', 400);
		$all = $this->get_fy();
		$row = [
			'id'        => 'fy_' . time(),
			'companyId' => isset($all[0]['companyId']) ? $all[0]['companyId'] : 'c_site',
			'name'      => $name,
			'startDate' => $start,
			'endDate'   => $end,
			'status'    => 'OPEN',
		];
		$all[] = $row;
		update_option(self::OPT_FY, $all, false);
		wp_send_json_success(['id'=>$row['id']]);
	}
	public function ajax_fy_close(){
		$this->check();
		$id  = sanitize_text_field((string)($_POST['id'] ?? ''));
		$all = $this->get_fy();
		foreach ($all as $i => $r) if ((string)$r['id'] === $id) { $all[$i]['status'] = 'CLOSED'; break; }
		update_option(self::OPT_FY, $all, false);
		wp_send_json_success();
	}
	public function ajax_lock_toggle(){
		$this->check();
		$start = sanitize_text_field((string)($_POST['start_date'] ?? ''));
		$end   = sanitize_text_field((string)($_POST['end_date']   ?? ''));
		$all   = $this->get_locks();
		$fyAll = $this->get_fy();
		$fyId  = !empty($fyAll) ? $fyAll[0]['id'] : 'fy_default';
		$found = false;
		foreach ($all as $i => $l) {
			if (($l['fiscalYearId'] ?? '') === $fyId) {
				$all[$i]['isLocked']  = empty($l['isLocked']);
				$all[$i]['startDate'] = $start;
				$all[$i]['endDate']   = $end;
				$all[$i]['lockedBy']  = wp_get_current_user()->display_name;
				$found = true;
				break;
			}
		}
		if (!$found) {
			$all[] = [
				'id'           => 'fl_' . time(),
				'fiscalYearId' => $fyId,
				'startDate'    => $start,
				'endDate'      => $end,
				'isLocked'     => true,
				'lockedBy'     => wp_get_current_user()->display_name,
			];
		}
		update_option(self::OPT_LOCKS, $all, false);
		wp_send_json_success();
	}
}
