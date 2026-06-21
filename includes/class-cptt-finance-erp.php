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
		add_action('admin_init',           [$this, 'maybe_run_migrations']);
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
		add_action('wp_ajax_cpttf_erp_voucher_approve', [$this, 'ajax_voucher_approve']);
		add_action('wp_ajax_cpttf_erp_voucher_delete',  [$this, 'ajax_voucher_delete']);
		add_action('wp_ajax_cpttf_erp_voucher_reverse', [$this, 'ajax_voucher_reverse']);
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

		// ─── Phase 1 توسعه: Accounting Engine + Audit + Party + Permissions ───
		add_action('wp_ajax_cpttf_erp_vouchers_list',       [$this, 'ajax_vouchers_list']);
		add_action('wp_ajax_cpttf_erp_party_statement',     [$this, 'ajax_party_statement']);
		add_action('wp_ajax_cpttf_erp_audit_list',          [$this, 'ajax_audit_list']);
		add_action('wp_ajax_cpttf_erp_permissions_save',    [$this, 'ajax_permissions_save']);

		// ─── Phase 2: Account Mapping & Live COA balances ───
		add_action('wp_ajax_cpttf_erp_mapping_get',         [$this, 'ajax_mapping_get']);
		add_action('wp_ajax_cpttf_erp_mapping_save',        [$this, 'ajax_mapping_save']);
		add_action('wp_ajax_cpttf_erp_coa_balances',        [$this, 'ajax_coa_balances']);
		add_action('wp_ajax_cpttf_erp_coa_import_standard', [$this, 'ajax_coa_import_standard']);
		add_action('wp_ajax_cpttf_erp_coa_treasury_map_save', [$this, 'ajax_coa_treasury_map_save']);

		// ─── Phase 3: Chunked bootstrap endpoints (lazy loading) ───
		add_action('wp_ajax_cpttf_erp_bootstrap_core',     [$this, 'ajax_bootstrap_core']);
		add_action('wp_ajax_cpttf_erp_bootstrap_treasury', [$this, 'ajax_bootstrap_treasury']);
		add_action('wp_ajax_cpttf_erp_bootstrap_projects', [$this, 'ajax_bootstrap_projects']);
		add_action('wp_ajax_cpttf_erp_bootstrap_reports',  [$this, 'ajax_bootstrap_reports']);
		add_action('wp_ajax_cpttf_erp_bootstrap_kpis',     [$this, 'ajax_bootstrap_kpis']);
		add_action('wp_ajax_cpttf_erp_migration_status',   [$this, 'ajax_migration_status']);

		// ─── Phase 5: Period locking + Fiscal Year closing ───
		add_action('wp_ajax_cpttf_erp_period_check',       [$this, 'ajax_period_check']);
		add_action('wp_ajax_cpttf_erp_fy_close_preview',   [$this, 'ajax_fy_close_preview']);
		add_action('wp_ajax_cpttf_erp_fy_close_execute',   [$this, 'ajax_fy_close_execute']);
		add_action('wp_ajax_cpttf_erp_fy_reopen',          [$this, 'ajax_fy_reopen']);
		add_action('wp_ajax_cpttf_erp_lock_clear',         [$this, 'ajax_lock_clear']);

		// ─── Phase 6: Advanced Reports ───
		add_action('wp_ajax_cpttf_erp_cash_flow',          [$this, 'ajax_cash_flow']);
		add_action('wp_ajax_cpttf_erp_period_compare',     [$this, 'ajax_period_compare']);
		add_action('wp_ajax_cpttf_erp_budget_actual',      [$this, 'ajax_budget_actual']);
		add_action('wp_ajax_cpttf_erp_aging_custom',       [$this, 'ajax_aging_custom']);
		add_action('wp_ajax_cpttf_erp_aging_cheques',      [$this, 'ajax_aging_cheques']);
		add_action('wp_ajax_cpttf_erp_wip_report',         [$this, 'ajax_wip_report']);
		add_action('wp_ajax_cpttf_erp_bank_recon',         [$this, 'ajax_bank_recon']);
		add_action('wp_ajax_cpttf_erp_bank_recon_save',    [$this, 'ajax_bank_recon_save']);

		// ─── Phase 7: Operational Polish ───
		add_action('wp_ajax_cpttf_erp_kpi_drilldown',      [$this, 'ajax_kpi_drilldown']);
		add_action('wp_ajax_cpttf_erp_voucher_batch',      [$this, 'ajax_voucher_batch']);
		add_action('wp_ajax_cpttf_erp_voucher_unfinalize', [$this, 'ajax_voucher_unfinalize']);
		add_action('wp_ajax_cpttf_erp_recurring_list',     [$this, 'ajax_recurring_list']);
		add_action('wp_ajax_cpttf_erp_recurring_save',     [$this, 'ajax_recurring_save']);
		add_action('wp_ajax_cpttf_erp_recurring_delete',   [$this, 'ajax_recurring_delete']);
		add_action('wp_ajax_cpttf_erp_recurring_run_now',  [$this, 'ajax_recurring_run_now']);
		add_action('wp_ajax_cpttf_erp_chequebook_list',    [$this, 'ajax_chequebook_list']);
		add_action('wp_ajax_cpttf_erp_chequebook_save',    [$this, 'ajax_chequebook_save']);
		add_action('wp_ajax_cpttf_erp_chequebook_delete',  [$this, 'ajax_chequebook_delete']);
		add_action('wp_ajax_cpttf_erp_chequebook_next',    [$this, 'ajax_chequebook_next']);
		add_action('wp_ajax_cpttf_erp_cron_run_now',       [$this, 'ajax_cron_run_now']);

		// Phase 7: Print endpoints (return HTML for new-window print)
		add_action('wp_ajax_cpttf_erp_print_voucher',  [$this, 'ajax_print_voucher']);
		add_action('wp_ajax_cpttf_erp_print_cheque',   [$this, 'ajax_print_cheque']);
		add_action('wp_ajax_cpttf_erp_print_payslip',  [$this, 'ajax_print_payslip']);
		add_action('wp_ajax_cpttf_erp_print_statement',[$this, 'ajax_print_statement']);

		// ─── Phase 8: Integrations & Automations ───
		// Bulk Import
		add_action('wp_ajax_cpttf_erp_import_preview', [$this, 'ajax_import_preview']);
		add_action('wp_ajax_cpttf_erp_import_commit',  [$this, 'ajax_import_commit']);
		// Multi-currency
		add_action('wp_ajax_cpttf_erp_rates_list',     [$this, 'ajax_rates_list']);
		add_action('wp_ajax_cpttf_erp_rates_save',     [$this, 'ajax_rates_save']);
		add_action('wp_ajax_cpttf_erp_rates_delete',   [$this, 'ajax_rates_delete']);
		add_action('wp_ajax_cpttf_erp_currency_convert', [$this, 'ajax_currency_convert']);
		// Invoices
		add_action('wp_ajax_cpttf_erp_invoice_list',   [$this, 'ajax_invoice_list']);
		add_action('wp_ajax_cpttf_erp_invoice_save',   [$this, 'ajax_invoice_save']);
		add_action('wp_ajax_cpttf_erp_invoice_delete', [$this, 'ajax_invoice_delete']);
		add_action('wp_ajax_cpttf_erp_invoice_status', [$this, 'ajax_invoice_status']);
		add_action('wp_ajax_cpttf_erp_invoice_to_voucher', [$this, 'ajax_invoice_to_voucher']);
		add_action('wp_ajax_cpttf_erp_print_invoice',  [$this, 'ajax_print_invoice']);
		// Webhooks
		add_action('wp_ajax_cpttf_erp_webhook_list',   [$this, 'ajax_webhook_list']);
		add_action('wp_ajax_cpttf_erp_webhook_save',   [$this, 'ajax_webhook_save']);
		add_action('wp_ajax_cpttf_erp_webhook_delete', [$this, 'ajax_webhook_delete']);
		add_action('wp_ajax_cpttf_erp_webhook_test',   [$this, 'ajax_webhook_test']);
		// REST API keys
		add_action('wp_ajax_cpttf_erp_apikey_list',    [$this, 'ajax_apikey_list']);
		add_action('wp_ajax_cpttf_erp_apikey_create',  [$this, 'ajax_apikey_create']);
		add_action('wp_ajax_cpttf_erp_apikey_revoke',  [$this, 'ajax_apikey_revoke']);
		// 2FA
		add_action('wp_ajax_cpttf_erp_2fa_status',     [$this, 'ajax_2fa_status']);
		add_action('wp_ajax_cpttf_erp_2fa_enable',     [$this, 'ajax_2fa_enable']);
		add_action('wp_ajax_cpttf_erp_2fa_verify',     [$this, 'ajax_2fa_verify']);
		add_action('wp_ajax_cpttf_erp_2fa_disable',    [$this, 'ajax_2fa_disable']);

		// REST API (public read-only)
		add_action('rest_api_init', [$this, 'register_rest_routes']);

		// WP-Cron hooks (registered always, scheduled on activation/admin_init)
		add_action('cpttf_erp_daily_cron',      [$this, 'cron_daily_tasks']);
		add_action('cpttf_erp_hourly_cron',     [$this, 'cron_hourly_tasks']);
		add_action('admin_init',                [$this, 'maybe_schedule_crons']);

		// Hook into existing operations to auto-generate vouchers
		add_action('cptt_after_expert_payout',         [$this, 'auto_voucher_expert_payout'], 20, 5);
		add_action('cptt_after_manual_expert_payment', [$this, 'auto_voucher_manual_payment'], 20, 3);

		// ─── Phase 2: Cheques, Installments, Payables, Attachments, Notifications ───
		add_action('wp_ajax_cpttf_erp_cheques_list',         [$this, 'ajax_cheques_list']);
		add_action('wp_ajax_cpttf_erp_cheque_save',          [$this, 'ajax_cheque_save']);
		add_action('wp_ajax_cpttf_erp_cheque_delete',        [$this, 'ajax_cheque_delete']);
		add_action('wp_ajax_cpttf_erp_cheque_status',        [$this, 'ajax_cheque_status']);

		add_action('wp_ajax_cpttf_erp_installments_list',         [$this, 'ajax_installments_list']);
		add_action('wp_ajax_cpttf_erp_installment_plan_create',   [$this, 'ajax_installment_plan_create']);
		add_action('wp_ajax_cpttf_erp_installment_pay',           [$this, 'ajax_installment_pay']);
		add_action('wp_ajax_cpttf_erp_installment_delete',        [$this, 'ajax_installment_delete']);

		add_action('wp_ajax_cpttf_erp_payables_list', [$this, 'ajax_payables_list']);
		add_action('wp_ajax_cpttf_erp_payable_save',  [$this, 'ajax_payable_save']);
		add_action('wp_ajax_cpttf_erp_payable_pay',   [$this, 'ajax_payable_pay']);
		add_action('wp_ajax_cpttf_erp_payable_delete',[$this, 'ajax_payable_delete']);

		add_action('wp_ajax_cpttf_erp_notifications_list', [$this, 'ajax_notifications_list']);
		add_action('wp_ajax_cpttf_erp_notif_read',         [$this, 'ajax_notif_read']);
		add_action('wp_ajax_cpttf_erp_notif_read_all',     [$this, 'ajax_notif_read_all']);
		add_action('wp_ajax_cpttf_erp_notif_delete',       [$this, 'ajax_notif_delete']);

		add_action('wp_ajax_cpttf_erp_attachment_upload',  [$this, 'ajax_attachment_upload']);
		add_action('wp_ajax_cpttf_erp_attachments_list',   [$this, 'ajax_attachments_list']);
		add_action('wp_ajax_cpttf_erp_attachment_delete',  [$this, 'ajax_attachment_delete']);
	}

	const OPT_CHEQUES      = 'cpttf_erp_cheques';
	const OPT_INSTALLMENTS = 'cpttf_erp_installments';
	const OPT_PAYABLES     = 'cpttf_erp_payables';
	const OPT_NOTIFS_READ  = 'cpttf_erp_notifs_read';
	const OPT_NOTIFS_DEL   = 'cpttf_erp_notifs_deleted';
	const OPT_ATTACHMENTS  = 'cpttf_erp_attachments';

	const OPT_PERMS    = 'cpttf_erp_role_permissions';
	const OPT_VNUMBERS = 'cpttf_erp_voucher_numbers'; // {RV: 5, PV: 12, JV: 3, ...} per fiscal year
	const OPT_ACCOUNT_MAPPING = 'cpttf_erp_account_mapping';  // Phase 2: configurable defaults
	const OPT_TREASURY_COA_MAP = 'cpttf_erp_treasury_coa_map'; // Phase 2: per-treasury-account → CoA subsidiary
	const OPT_BANK_RECON       = 'cpttf_erp_bank_recon';       // Phase 6: reconciled ledger ids per account
	const OPT_RECURRING        = 'cpttf_erp_recurring';        // Phase 7: recurring transactions
	const OPT_CHEQUEBOOKS      = 'cpttf_erp_chequebooks';      // Phase 7: cheque-books (auto-numbering)
	const OPT_CRON_LOG         = 'cpttf_erp_cron_log';         // Phase 7: last cron run timestamps
	const OPT_CURRENCY_RATES   = 'cpttf_erp_currency_rates';   // Phase 8: { 'USD/2026-06-13': 65000 }
	const OPT_WEBHOOKS         = 'cpttf_erp_webhooks';         // Phase 8: outbound webhook subscriptions
	const OPT_API_KEYS         = 'cpttf_erp_api_keys';         // Phase 8: REST API keys
	const OPT_2FA              = 'cpttf_erp_2fa';              // Phase 8: 2FA opt-in flags per user
	const OPT_DB_VERSION       = 'cpttf_erp_db_version';       // Phase 3: schema version tracker

	const TBL_INVOICES         = 'cptt_erp_invoices';          // Phase 8: invoice headers
	const TBL_INVOICE_ROWS     = 'cptt_erp_invoice_rows';      // Phase 8: invoice line items
	const DB_VERSION           = '3.0.0';                        // Phase 3: bump on schema change

	const TBL_VOUCHERS         = 'cptt_erp_vouchers';
	const TBL_VOUCHER_ROWS     = 'cptt_erp_voucher_rows';
	const TBL_CHEQUES          = 'cptt_erp_cheques';
	const TBL_PAYABLES         = 'cptt_erp_payables';
	const TBL_INSTALLMENTS     = 'cptt_erp_installments';
	const TBL_INSTALLMENT_ROWS = 'cptt_erp_installment_rows';
	const TBL_AUDIT    = 'cptt_erp_audit';

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
		// Phase 2: standalone Account Mapping page (CEO/admin only)
		add_submenu_page(
			'cptt-finance',
			'نگاشت حساب‌های پیش‌فرض',
			'🧭 نگاشت حساب‌ها',
			'manage_options',
			self::PAGE_SLUG . '-mapping',
			[$this, 'render_mapping_page']
		);
		// Phase 5: Fiscal Year closing page (CEO/admin only)
		add_submenu_page(
			'cptt-finance',
			'بستن سال مالی',
			'🗓 بستن سال مالی',
			'manage_options',
			self::PAGE_SLUG . '-fyclose',
			[$this, 'render_fyclose_page']
		);
		// Phase 6: Advanced Reports page (manager+ only)
		add_submenu_page(
			'cptt-finance',
			'گزارش‌های پیشرفته',
			'📊 گزارش‌های پیشرفته',
			'edit_cptt_projects',
			self::PAGE_SLUG . '-reports',
			[$this, 'render_reports_page']
		);
		// Phase 7: Operations page (recurring + chequebooks + cron)
		add_submenu_page(
			'cptt-finance',
			'عملیات و اتوماسیون',
			'⚙ عملیات و اتوماسیون',
			'edit_cptt_projects',
			self::PAGE_SLUG . '-ops',
			[$this, 'render_ops_page']
		);
		// Phase 8: Integrations page
		add_submenu_page(
			'cptt-finance',
			'یکپارچه‌سازی و اتوماسیون',
			'🔌 یکپارچه‌سازی',
			'manage_options',
			self::PAGE_SLUG . '-int',
			[$this, 'render_integrations_page']
		);
		// Phase 8: Invoices page
		add_submenu_page(
			'cptt-finance',
			'فاکتورها و پیش‌فاکتورها',
			'🧾 فاکتورها',
			'edit_cptt_projects',
			self::PAGE_SLUG . '-inv',
			[$this, 'render_invoices_page']
		);
	}

	/* Phase 2: Render the Account Mapping admin page */
	public function render_mapping_page(){
		if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');
		$mapping = $this->get_account_mapping();
		$treasury_map = $this->get_treasury_coa_map();
		$coa = $this->get_coa_with_balances();
		$purposes = $this->account_mapping_purpose_labels();
		$nonce = wp_create_nonce(self::NONCE);
		$ajax  = admin_url('admin-ajax.php');
		$treasury_accounts = [];
		if (class_exists('CPTT_Finance')) {
			global $wpdb;
			$rows = $wpdb->get_results('SELECT id, name, type FROM ' . CPTT_Finance::tbl_accounts() . ' WHERE status=1 ORDER BY id ASC');
			foreach ((array)$rows as $r) $treasury_accounts[] = $r;
		}
		?>
		<style>
			body.toplevel_page_cptt-finance #wpwrap { background: #f8fafc; }
			.cptt-map-wrap { max-width: 1100px; margin: 18px 8px; font-family: Tahoma, sans-serif; direction: rtl; }
			.cptt-map-wrap h1 { font-size: 1.4rem; color: #1e293b; margin: 0 0 4px; }
			.cptt-map-wrap p.lead { color: #64748b; margin: 0 0 14px; }
			.cptt-map-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 18px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
			.cptt-map-card h2 { font-size: 1.1rem; color: #4f46e5; margin: 0 0 12px; padding-bottom: 8px; border-bottom: 1px dashed #cbd5e1; }
			.cptt-map-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }
			.cptt-map-field { display: flex; flex-direction: column; gap: 4px; }
			.cptt-map-field label { font-size: .8rem; font-weight: 700; color: #475569; }
			.cptt-map-field .hint { font-size: .68rem; color: #94a3b8; }
			.cptt-map-field select {
				width: 100%; padding: 8px 12px; border-radius: 10px;
				border: 1.5px solid #e2e8f0; background: #fff; font-size: .85rem;
				font-family: inherit; direction: rtl; text-align: right;
			}
			.cptt-map-field select:focus { outline: 0; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(99,102,241,.15); }
			.cptt-map-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
			.cptt-map-btn {
				padding: 10px 22px; border-radius: 10px; border: 0;
				font-size: .85rem; font-weight: 700; cursor: pointer;
				font-family: inherit;
			}
			.cptt-map-btn-primary { background: #4f46e5; color: #fff; }
			.cptt-map-btn-primary:hover { background: #4338ca; }
			.cptt-map-btn-ghost { background: #f1f5f9; color: #334155; }
			.cptt-map-btn-warning { background: #f59e0b; color: #fff; }
			.cptt-map-btn-danger { background: #e11d48; color: #fff; }
			.cptt-map-msg { padding: 10px 14px; border-radius: 10px; margin: 12px 0; font-size: .85rem; }
			.cptt-map-msg.ok { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
			.cptt-map-msg.err { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
			.cptt-map-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
			.cptt-map-table th, .cptt-map-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; text-align: right; }
			.cptt-map-table th { background: #f8fafc; font-weight: 700; color: #475569; }
			.cptt-map-group { font-size: .65rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-top: 16px; margin-bottom: 6px; letter-spacing: 0.05em; }
		</style>
		<div class="cptt-map-wrap">
			<h1>🧭 نگاشت حساب‌های پیش‌فرض</h1>
			<p class="lead">با تنظیم این نگاشت، تمام عملیات خودکار (Voucher Engine) از حساب‌های صحیح استفاده می‌کنند. اگر کدینگ حساب‌ها را تغییر دادید، اینجا را به‌روز کنید.</p>

			<div id="cptt-map-msg-host"></div>

			<div class="cptt-map-card">
				<h2>📥 وارد کردن سرفصل استاندارد ایران</h2>
				<p style="color:#64748b; font-size:.85rem; margin:0 0 12px;">سرفصل ۴-سطحی استاندارد جامعه‌ی حسابداران رسمی ایران را به کدینگ فعلی اضافه یا جایگزین کنید.</p>
				<div class="cptt-map-actions">
					<button class="cptt-map-btn cptt-map-btn-primary" id="cptt-import-merge">➕ افزودن حساب‌های جدید (Merge)</button>
					<button class="cptt-map-btn cptt-map-btn-danger" id="cptt-import-replace">⚠ جایگزینی کامل کدینگ (Replace)</button>
				</div>
				<p style="font-size:.7rem; color:#94a3b8; margin-top:8px;">Merge: حساب‌های موجود حفظ می‌شوند، فقط حساب‌های جدید اضافه می‌شوند. Replace: کل کدینگ پاک و سرفصل استاندارد جایگزین می‌شود.</p>
			</div>

			<div class="cptt-map-card">
				<h2>🎯 نگاشت پیش‌فرض رویدادهای حسابداری</h2>
				<form id="cptt-mapping-form">
					<?php
					$groups = [];
					foreach ($purposes as $key => $meta) {
						$g = $meta['group'] ?? 'سایر';
						$groups[$g][$key] = $meta;
					}
					foreach ($groups as $gname => $items) {
						echo '<div class="cptt-map-group">' . esc_html($gname) . '</div>';
						echo '<div class="cptt-map-grid">';
						foreach ($items as $key => $meta) {
							$val = isset($mapping[$key]) ? $mapping[$key] : '';
							echo '<div class="cptt-map-field">';
							echo '  <label>' . esc_html($meta['label']) . '</label>';
							echo '  <select name="' . esc_attr($key) . '" data-key="' . esc_attr($key) . '">';
							echo '    <option value="">— انتخاب نشده —</option>';
							foreach ($coa as $n) {
								$indent = '';
								if (($n['type'] ?? '') === 'general') $indent = '— ';
								elseif (($n['type'] ?? '') === 'subsidiary') $indent = '—— ';
								elseif (($n['type'] ?? '') === 'detail') $indent = '——— ';
								$sel = ((string)$val === (string)$n['id']) ? ' selected' : '';
								echo '<option value="' . esc_attr($n['id']) . '"' . $sel . '>' . esc_html($indent . $n['code'] . ' — ' . $n['name']) . '</option>';
							}
							echo '  </select>';
							echo '  <span class="hint">' . esc_html($meta['hint']) . '</span>';
							echo '</div>';
						}
						echo '</div>';
					}
					?>
				</form>
			</div>

			<?php if (!empty($treasury_accounts)): ?>
			<div class="cptt-map-card">
				<h2>🏦 نگاشت per-حساب treasury → سرفصل CoA</h2>
				<p style="color:#64748b; font-size:.85rem; margin:0 0 12px;">اگر می‌خواهید هر صندوق/بانک به یک حساب CoA خاص متصل شود (به‌جای fallback پیش‌فرض)، اینجا تعیین کنید.</p>
				<table class="cptt-map-table">
					<thead>
						<tr>
							<th>حساب treasury</th>
							<th>نوع</th>
							<th>نگاشت به CoA</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($treasury_accounts as $tr): $tid = (int)$tr->id; $cur = isset($treasury_map[(string)$tid]) ? $treasury_map[(string)$tid] : ''; ?>
						<tr>
							<td><?php echo esc_html($tr->name); ?> <span style="color:#94a3b8;">#<?php echo $tid; ?></span></td>
							<td><?php echo esc_html($tr->type ?: '—'); ?></td>
							<td>
								<select data-tid="<?php echo $tid; ?>" class="cptt-treasury-coa-select">
									<option value="">— پیش‌فرض (بر اساس نوع) —</option>
									<?php foreach ($coa as $n):
										if (($n['type'] ?? '') === 'group') continue;
										$indent = '';
										if (($n['type'] ?? '') === 'general') $indent = '— ';
										elseif (($n['type'] ?? '') === 'subsidiary') $indent = '—— ';
										elseif (($n['type'] ?? '') === 'detail') $indent = '——— ';
										$sel = ((string)$cur === (string)$n['id']) ? ' selected' : '';
									?>
										<option value="<?php echo esc_attr($n['id']); ?>"<?php echo $sel; ?>><?php echo esc_html($indent . $n['code'] . ' — ' . $n['name']); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<div class="cptt-map-actions">
				<button class="cptt-map-btn cptt-map-btn-primary" id="cptt-save-mapping">💾 ذخیره‌ی نگاشت</button>
				<a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>" class="cptt-map-btn cptt-map-btn-ghost" style="text-decoration:none; display:inline-block;">↩ بازگشت به پنل ERP</a>
			</div>

			<div class="cptt-map-card" style="margin-top:16px;">
				<h2>📊 مانده‌ی زنده‌ی سرفصل‌ها (سطح بالا)</h2>
				<table class="cptt-map-table">
					<thead><tr><th>کد</th><th>نام</th><th>نوع</th><th>مانده</th></tr></thead>
					<tbody>
					<?php foreach ($coa as $n): if (($n['type'] ?? '') !== 'group' && ($n['type'] ?? '') !== 'general') continue; ?>
						<tr>
							<td style="font-family:monospace;"><?php echo esc_html($n['code']); ?></td>
							<td><?php echo esc_html($n['name']); ?></td>
							<td><?php echo esc_html(($n['type'] === 'group') ? 'گروه' : 'کل'); ?></td>
							<td style="font-weight:700; color: <?php echo ((float)$n['balance'] >= 0) ? '#4338ca' : '#b91c1c'; ?>;">
								<?php echo number_format((float)$n['balance']); ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<script>
		(function(){
			var nonce = '<?php echo esc_js($nonce); ?>';
			var ajax  = '<?php echo esc_js($ajax); ?>';
			function msg(text, ok){
				var host = document.getElementById('cptt-map-msg-host');
				host.innerHTML = '<div class="cptt-map-msg ' + (ok ? 'ok' : 'err') + '">' + text + '</div>';
				setTimeout(function(){ host.innerHTML = ''; }, 5000);
			}
			function call(action, data, cb){
				var fd = new FormData();
				fd.append('action', action);
				fd.append('nonce', nonce);
				Object.keys(data || {}).forEach(function(k){
					var v = data[k];
					if (typeof v === 'object') v = JSON.stringify(v);
					fd.append(k, v);
				});
				fetch(ajax, { method:'POST', body: fd, credentials:'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(j){ cb(j); })
					.catch(function(e){ cb({ success: false, data: { message: String(e) }}); });
			}
			document.getElementById('cptt-save-mapping').onclick = function(){
				var form = document.getElementById('cptt-mapping-form');
				var mapping = {};
				form.querySelectorAll('select[data-key]').forEach(function(s){
					mapping[s.getAttribute('data-key')] = s.value;
				});
				var tm = {};
				document.querySelectorAll('.cptt-treasury-coa-select').forEach(function(s){
					var tid = s.getAttribute('data-tid');
					if (s.value) tm[tid] = s.value;
				});
				call('cpttf_erp_mapping_save', { mapping: mapping, treasury_map: tm }, function(r){
					if (r.success) msg('✅ نگاشت با موفقیت ذخیره شد. از این پس عملیات خودکار از این حساب‌ها استفاده می‌کنند.', true);
					else msg('❌ خطا: ' + (r.data && r.data.message ? r.data.message : 'ناشناخته'), false);
				});
			};
			document.getElementById('cptt-import-merge').onclick = function(){
				if (!confirm('سرفصل استاندارد ایران به کدینگ فعلی اضافه شود؟')) return;
				call('cpttf_erp_coa_import_standard', { mode: 'merge' }, function(r){
					if (r.success) {
						msg('✅ ' + (r.data.added || 0) + ' حساب جدید اضافه شد. لطفاً صفحه را رفرش کنید تا در لیست‌ها نمایش داده شوند.', true);
						setTimeout(function(){ location.reload(); }, 1500);
					} else msg('❌ خطا: ' + (r.data && r.data.message ? r.data.message : 'ناشناخته'), false);
				});
			};
			document.getElementById('cptt-import-replace').onclick = function(){
				if (!confirm('⚠ کل کدینگ فعلی پاک می‌شود و سرفصل استاندارد جایگزین می‌شود. ادامه می‌دهید؟')) return;
				if (!confirm('این عملیات قابل بازگشت نیست. تأیید نهایی؟')) return;
				call('cpttf_erp_coa_import_standard', { mode: 'replace' }, function(r){
					if (r.success) {
						msg('✅ ' + (r.data.count || 0) + ' حساب جایگزین شد.', true);
						setTimeout(function(){ location.reload(); }, 1500);
					} else msg('❌ خطا: ' + (r.data && r.data.message ? r.data.message : 'ناشناخته'), false);
				});
			};
		})();
		</script>
		<?php
	}

	/* Phase 5: Fiscal Year close admin page */
	public function render_fyclose_page(){
		if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');
		$fy_list = $this->get_fy();
		$locks   = $this->get_locks();
		$nonce   = wp_create_nonce(self::NONCE);
		$ajax    = admin_url('admin-ajax.php');
		?>
		<style>
			.cptt-fyc-wrap { max-width: 1100px; margin: 18px 8px; font-family: Tahoma, sans-serif; direction: rtl; }
			.cptt-fyc-wrap h1 { font-size: 1.4rem; color: #1e293b; margin: 0 0 4px; }
			.cptt-fyc-wrap p.lead { color: #64748b; margin: 0 0 14px; }
			.cptt-fyc-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 18px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
			.cptt-fyc-card h2 { font-size: 1.05rem; color: #4f46e5; margin: 0 0 10px; padding-bottom: 6px; border-bottom: 1px dashed #cbd5e1; }
			.cptt-fyc-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
			.cptt-fyc-table th, .cptt-fyc-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; text-align: right; }
			.cptt-fyc-table th { background: #f8fafc; font-weight: 700; color: #475569; }
			.cptt-fyc-pill { display:inline-block; padding: 2px 10px; border-radius: 999px; font-size: .7rem; font-weight: 700; }
			.cptt-fyc-pill.open { background: #d1fae5; color: #065f46; }
			.cptt-fyc-pill.closed { background: #fee2e2; color: #991b1b; }
			.cptt-fyc-actions { display: flex; gap: 8px; flex-wrap: wrap; }
			.cptt-fyc-btn { padding: 8px 16px; border-radius: 8px; border: 0; font-size: .8rem; font-weight: 700; cursor: pointer; font-family: inherit; }
			.cptt-fyc-btn.primary { background: #4f46e5; color: #fff; }
			.cptt-fyc-btn.warning { background: #f59e0b; color: #fff; }
			.cptt-fyc-btn.danger  { background: #e11d48; color: #fff; }
			.cptt-fyc-btn.ghost   { background: #f1f5f9; color: #334155; }
			.cptt-fyc-msg { padding: 10px 14px; border-radius: 10px; margin: 12px 0; font-size: .85rem; }
			.cptt-fyc-msg.ok  { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
			.cptt-fyc-msg.err { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
			.cptt-fyc-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin: 12px 0; }
			.cptt-fyc-stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px; }
			.cptt-fyc-stat .label { font-size: .7rem; color: #64748b; }
			.cptt-fyc-stat .value { font-size: 1.1rem; font-weight: 700; margin-top: 4px; }
			.cptt-fyc-stat.green .value  { color: #059669; }
			.cptt-fyc-stat.red   .value  { color: #dc2626; }
			.cptt-fyc-stat.indigo .value { color: #4338ca; }
		</style>
		<div class="cptt-fyc-wrap">
			<h1>🗓 بستن سال مالی</h1>
			<p class="lead">بستن سال مالی → تولید سند اختتامیه (CV) خودکار + قفل دوره + (اختیاری) تولید سند افتتاحیه (OV) برای سال بعدی.</p>

			<div id="cptt-fyc-msg-host"></div>

			<div class="cptt-fyc-card">
				<h2>📋 سال‌های مالی</h2>
				<table class="cptt-fyc-table">
					<thead><tr><th>نام</th><th>از</th><th>تا</th><th>وضعیت</th><th>عملیات</th></tr></thead>
					<tbody>
					<?php foreach ($fy_list as $f):
						$status = (string)($f['status'] ?? 'OPEN');
						$lock   = null;
						foreach ($locks as $l) if (($l['fiscalYearId'] ?? '') === ($f['id'] ?? '') && !empty($l['isLocked'])) { $lock = $l; break; }
					?>
						<tr>
							<td><strong><?php echo esc_html($f['name'] ?? '—'); ?></strong>
								<?php if ($lock): ?><br><small style="color:#b45309;">🔒 قفل توسط <?php echo esc_html($lock['lockedBy'] ?? '—'); ?></small><?php endif; ?>
							</td>
							<td><?php echo esc_html($f['startDate'] ?? '—'); ?></td>
							<td><?php echo esc_html($f['endDate'] ?? '—'); ?></td>
							<td><span class="cptt-fyc-pill <?php echo $status === 'CLOSED' ? 'closed' : 'open'; ?>"><?php echo $status === 'CLOSED' ? 'بسته' : 'باز'; ?></span></td>
							<td>
								<div class="cptt-fyc-actions">
									<?php if ($status !== 'CLOSED'): ?>
										<button class="cptt-fyc-btn primary cptt-fyc-preview" data-id="<?php echo esc_attr($f['id']); ?>" data-name="<?php echo esc_attr($f['name'] ?? '—'); ?>">📊 پیش‌نمایش بستن</button>
									<?php else: ?>
										<button class="cptt-fyc-btn warning cptt-fyc-reopen" data-id="<?php echo esc_attr($f['id']); ?>">🔓 بازکردن دوباره</button>
									<?php endif; ?>
									<?php if ($lock): ?>
										<button class="cptt-fyc-btn ghost cptt-fyc-lock-clear" data-id="<?php echo esc_attr($f['id']); ?>">حذف قفل</button>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if (empty($fy_list)): ?>
					<p style="color:#94a3b8; text-align:center; padding: 20px;">هنوز سال مالی تعریف نکرده‌اید. ابتدا از پنل ERP → «سال مالی» یکی تعریف کنید.</p>
				<?php endif; ?>
			</div>

			<div id="cptt-fyc-preview-host" style="display:none;"></div>

			<div class="cptt-fyc-card">
				<h2>ℹ راهنمای بستن سال مالی</h2>
				<ul style="color: #475569; font-size: .85rem; line-height: 1.9;">
					<li><strong>۱. پیش‌نمایش:</strong> ابتدا روی «پیش‌نمایش بستن» کلیک کنید تا سود/زیان دوره و مانده‌ی حساب‌های دائمی را ببینید.</li>
					<li><strong>۲. اجرای بستن:</strong> پس از تأیید، سیستم خودکار یک سند CV ایجاد می‌کند که حساب‌های موقت (درآمد/هزینه) را به «سود/زیان انباشته» منتقل می‌کند.</li>
					<li><strong>۳. قفل دوره:</strong> پس از بستن، تمام تاریخ‌های آن سال مالی قفل می‌شوند. هیچ تراکنش جدیدی در آن دوره ثبت نمی‌شود.</li>
					<li><strong>۴. سند افتتاحیه:</strong> اگر سال مالی بعدی را انتخاب کنید، یک سند OV با مانده‌ی حساب‌های دائمی به‌عنوان مانده‌ی افتتاحیه‌ی سال جدید ساخته می‌شود.</li>
					<li><strong>۵. بازکردن:</strong> در صورت اشتباه، CEO می‌تواند سال را دوباره باز کند. سند CV/OV حذف نمی‌شود؛ برای حذف اثر آن از دکمه‌ی «↻ برگشت سند» استفاده کنید.</li>
				</ul>
			</div>

			<div class="cptt-fyc-card">
				<h2>🔧 تنظیم حساب «سود انباشته»</h2>
				<p style="color: #64748b; font-size: .85rem; margin: 0 0 8px;">برای اینکه سند اختتامیه به حساب درست منتقل شود، مطمئن شوید نگاشت <code>retained_earnings</code> در صفحه‌ی <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '-mapping')); ?>" style="color:#4f46e5;">🧭 نگاشت حساب‌ها</a> تنظیم شده است.</p>
			</div>
		</div>

		<script>
		(function(){
			var nonce = '<?php echo esc_js($nonce); ?>';
			var ajax  = '<?php echo esc_js($ajax); ?>';
			function fa(n){
				try { return new Intl.NumberFormat('fa-IR').format(n); } catch(e){ return String(n); }
			}
			function msg(text, ok){
				var host = document.getElementById('cptt-fyc-msg-host');
				host.innerHTML = '<div class="cptt-fyc-msg ' + (ok ? 'ok' : 'err') + '">' + text + '</div>';
				if (ok) setTimeout(function(){ host.innerHTML = ''; }, 5000);
			}
			function call(action, data, cb){
				var fd = new FormData();
				fd.append('action', action); fd.append('nonce', nonce);
				Object.keys(data || {}).forEach(function(k){
					var v = data[k]; if (typeof v === 'object') v = JSON.stringify(v);
					fd.append(k, v);
				});
				fetch(ajax, { method:'POST', body: fd, credentials:'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(j){ cb(j); })
					.catch(function(e){ cb({ success:false, data:{ message:String(e) }}); });
			}
			function preview(id, name){
				call('cpttf_erp_fy_close_preview', { id: id }, function(r){
					if (!r.success) { msg('❌ ' + (r.data && r.data.message ? r.data.message : 'خطا'), false); return; }
					var d = r.data;
					var pl = d.profit_loss;
					var html = '<div class="cptt-fyc-card">';
					html += '<h2>📊 پیش‌نمایش بستن: ' + name + '</h2>';
					html += '<div class="cptt-fyc-summary">';
					html += '<div class="cptt-fyc-stat green"><div class="label">مجموع درآمدها</div><div class="value">' + fa(pl.revenue) + '</div></div>';
					html += '<div class="cptt-fyc-stat red"><div class="label">مجموع هزینه‌ها</div><div class="value">' + fa(pl.expense) + '</div></div>';
					html += '<div class="cptt-fyc-stat indigo"><div class="label">' + (pl.profit >= 0 ? 'سود دوره' : 'زیان دوره') + '</div><div class="value">' + fa(Math.abs(pl.profit)) + '</div></div>';
					html += '<div class="cptt-fyc-stat"><div class="label">حساب‌های دائمی</div><div class="value">' + fa((d.permanent_balances||[]).length) + ' حساب</div></div>';
					html += '</div>';
					if ((pl.rev_by_account || []).length || (pl.exp_by_account || []).length) {
						html += '<details style="margin-top:8px;"><summary style="cursor:pointer;color:#4f46e5;font-weight:700;">جزئیات حساب‌های موقت</summary>';
						html += '<table class="cptt-fyc-table" style="margin-top:8px;"><thead><tr><th>کد</th><th>نام</th><th>مبلغ</th></tr></thead><tbody>';
						(pl.rev_by_account || []).forEach(function(a){ html += '<tr><td>' + (a.code||'') + '</td><td>' + a.name + '</td><td style="color:#059669;">' + fa(a.amount) + '</td></tr>'; });
						(pl.exp_by_account || []).forEach(function(a){ html += '<tr><td>' + (a.code||'') + '</td><td>' + a.name + '</td><td style="color:#dc2626;">' + fa(a.amount) + '</td></tr>'; });
						html += '</tbody></table></details>';
					}
					// FY chooser for OV
					html += '<div style="margin-top:14px; padding: 12px; background:#f8fafc; border-radius:10px;">';
					html += '<label style="display:block;font-size:.8rem;font-weight:700;color:#475569;margin-bottom:6px;">سال مالی بعدی (برای سند افتتاحیه — اختیاری):</label>';
					html += '<select id="cptt-fyc-next-fy" style="width:100%;padding:8px;border-radius:8px;border:1.5px solid #e2e8f0;direction:rtl;">';
					html += '<option value="">— بدون سند افتتاحیه —</option>';
					<?php foreach ($fy_list as $f): if (($f['status'] ?? '') === 'CLOSED') continue; ?>
					html += '<option value="<?php echo esc_js($f['id']); ?>"><?php echo esc_js($f['name'] ?? ''); ?> (<?php echo esc_js($f['startDate'] ?? ''); ?> تا <?php echo esc_js($f['endDate'] ?? ''); ?>)</option>';
					<?php endforeach; ?>
					html += '</select>';
					html += '</div>';
					html += '<div class="cptt-fyc-actions" style="margin-top:14px;">';
					html += '<button class="cptt-fyc-btn danger" id="cptt-fyc-execute" data-id="' + id + '" data-name="' + name + '">⚠ اجرای بستن سال مالی</button>';
					html += '<button class="cptt-fyc-btn ghost" onclick="document.getElementById(\'cptt-fyc-preview-host\').style.display=\'none\';">لغو</button>';
					html += '</div></div>';
					var host = document.getElementById('cptt-fyc-preview-host');
					host.innerHTML = html;
					host.style.display = 'block';
					host.scrollIntoView({ behavior: 'smooth' });
					document.getElementById('cptt-fyc-execute').onclick = function(){
						if (!confirm('⚠ بستن سال مالی غیرقابل‌برگشت است (مگر با reopen). ادامه می‌دهید؟')) return;
						var nextId = document.getElementById('cptt-fyc-next-fy').value || '';
						call('cpttf_erp_fy_close_execute', { id: id, next_fy_id: nextId }, function(rr){
							if (!rr.success) { msg('❌ ' + (rr.data && rr.data.message ? rr.data.message : 'خطا'), false); return; }
							msg('✅ سال مالی با موفقیت بسته شد. سند CV: ' + (rr.data.closing_voucher_id || '—') + (rr.data.opening_voucher_id ? '، سند OV: ' + rr.data.opening_voucher_id : ''), true);
							setTimeout(function(){ location.reload(); }, 2000);
						});
					};
				});
			}
			document.querySelectorAll('.cptt-fyc-preview').forEach(function(b){
				b.onclick = function(){ preview(b.getAttribute('data-id'), b.getAttribute('data-name')); };
			});
			document.querySelectorAll('.cptt-fyc-reopen').forEach(function(b){
				b.onclick = function(){
					if (!confirm('سال مالی دوباره باز شود؟ (سند CV/OV حذف نمی‌شود)')) return;
					call('cpttf_erp_fy_reopen', { id: b.getAttribute('data-id') }, function(r){
						if (r.success) { msg('✅ سال مالی بازشد', true); setTimeout(function(){ location.reload(); }, 1500); }
						else msg('❌ ' + (r.data && r.data.message ? r.data.message : 'خطا'), false);
					});
				};
			});
			document.querySelectorAll('.cptt-fyc-lock-clear').forEach(function(b){
				b.onclick = function(){
					if (!confirm('قفل دوره برداشته شود؟')) return;
					call('cpttf_erp_lock_clear', { fiscal_year_id: b.getAttribute('data-id') }, function(r){
						if (r.success) { msg('✅ قفل برداشته شد', true); setTimeout(function(){ location.reload(); }, 1500); }
						else msg('❌ ' + (r.data && r.data.message ? r.data.message : 'خطا'), false);
					});
				};
			});
		})();
		</script>
		<?php
	}

	/* Phase 6: Advanced Reports admin page */
	public function render_reports_page(){
		if (!current_user_can('edit_cptt_projects')) wp_die('دسترسی غیرمجاز');
		$ccs = $this->get_costs();
		$today = $this->fa_today();
		$treasury_accs = [];
		if (class_exists('CPTT_Finance')) {
			global $wpdb;
			$rows = $wpdb->get_results('SELECT id, name, type FROM ' . CPTT_Finance::tbl_accounts() . ' WHERE status=1 ORDER BY id ASC');
			foreach ((array)$rows as $r) $treasury_accs[] = $r;
		}
		$nonce = wp_create_nonce(self::NONCE);
		$ajax  = admin_url('admin-ajax.php');
		?>
		<style>
			.cptt-rep-wrap { max-width: 1200px; margin: 18px 8px; font-family: Tahoma, sans-serif; direction: rtl; }
			.cptt-rep-wrap h1 { font-size: 1.4rem; color: #1e293b; margin: 0 0 4px; }
			.cptt-rep-wrap p.lead { color: #64748b; margin: 0 0 14px; }
			.cptt-rep-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; background: #fff; padding: 6px; border-radius: 12px; border: 1px solid #e2e8f0; }
			.cptt-rep-tab { padding: 8px 14px; border-radius: 8px; cursor: pointer; font-size: .8rem; font-weight: 700; color: #475569; background: transparent; border: 0; font-family: inherit; }
			.cptt-rep-tab.active { background: #4f46e5; color: #fff; }
			.cptt-rep-tab:hover:not(.active) { background: #f1f5f9; }
			.cptt-rep-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 18px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
			.cptt-rep-card h2 { font-size: 1.05rem; color: #4f46e5; margin: 0 0 12px; padding-bottom: 6px; border-bottom: 1px dashed #cbd5e1; }
			.cptt-rep-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; align-items: end; margin-bottom: 12px; }
			.cptt-rep-field label { display: block; font-size: .72rem; font-weight: 700; color: #475569; margin-bottom: 4px; }
			.cptt-rep-field input, .cptt-rep-field select { width: 100%; padding: 7px 10px; border-radius: 8px; border: 1.5px solid #e2e8f0; font-size: .8rem; font-family: inherit; direction: rtl; text-align: right; }
			.cptt-rep-field input:focus, .cptt-rep-field select:focus { outline: 0; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(99,102,241,.12); }
			.cptt-rep-btn { padding: 8px 18px; border-radius: 8px; border: 0; background: #4f46e5; color: #fff; font-size: .8rem; font-weight: 700; cursor: pointer; font-family: inherit; }
			.cptt-rep-btn:hover { background: #4338ca; }
			.cptt-rep-btn.ghost { background: #f1f5f9; color: #334155; }
			.cptt-rep-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin: 10px 0; }
			.cptt-rep-stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px; }
			.cptt-rep-stat .label { font-size: .68rem; color: #64748b; }
			.cptt-rep-stat .value { font-size: 1.05rem; font-weight: 700; margin-top: 4px; color: #1e293b; }
			.cptt-rep-stat.green .value { color: #059669; }
			.cptt-rep-stat.red .value { color: #dc2626; }
			.cptt-rep-stat.indigo .value { color: #4338ca; }
			.cptt-rep-stat.amber .value { color: #b45309; }
			.cptt-rep-table { width: 100%; border-collapse: collapse; font-size: .78rem; margin-top: 8px; }
			.cptt-rep-table th, .cptt-rep-table td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; text-align: right; }
			.cptt-rep-table th { background: #f8fafc; font-weight: 700; color: #475569; }
			.cptt-rep-section-title { font-size: .9rem; font-weight: 700; color: #1e293b; margin: 16px 0 8px; padding: 6px 10px; background: linear-gradient(90deg, #eef2ff, transparent); border-right: 3px solid #4f46e5; }
			.cptt-rep-pill { display:inline-block; padding: 2px 8px; border-radius: 999px; font-size: .65rem; font-weight: 700; }
			.cptt-rep-pill.ok { background: #d1fae5; color: #065f46; }
			.cptt-rep-pill.warn { background: #fef3c7; color: #92400e; }
			.cptt-rep-pill.over { background: #fee2e2; color: #991b1b; }
			.cptt-rep-pill.neutral { background: #f1f5f9; color: #334155; }
			.cptt-rep-progress { background: #e2e8f0; border-radius: 999px; height: 8px; overflow: hidden; margin-top: 4px; }
			.cptt-rep-progress > div { height: 100%; border-radius: 999px; transition: width .3s; }
			.cptt-rep-msg { padding: 10px; border-radius: 8px; font-size: .8rem; margin: 8px 0; }
			.cptt-rep-msg.err { background: #fee2e2; color: #991b1b; }
			.cptt-rep-empty { text-align: center; padding: 30px; color: #94a3b8; font-size: .85rem; }
			.cptt-rep-num { font-feature-settings: "tnum" 1; font-variant-numeric: tabular-nums; }
			.cptt-rep-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
		</style>
		<div class="cptt-rep-wrap">
			<h1>📊 گزارش‌های پیشرفته</h1>
			<p class="lead">گزارش‌های اضافی فاز ۶: صورت جریان نقد، مقایسه دوره‌ای، بودجه vs واقعی، Aging سفارشی، Aging چک‌ها، WIP، تطبیق بانک.</p>

			<div class="cptt-rep-tabs">
				<button class="cptt-rep-tab active" data-tab="cashflow">صورت جریان نقد</button>
				<button class="cptt-rep-tab" data-tab="compare">مقایسه دوره‌ای</button>
				<button class="cptt-rep-tab" data-tab="budget">بودجه vs واقعی</button>
				<button class="cptt-rep-tab" data-tab="aging">Aging مطالبات</button>
				<button class="cptt-rep-tab" data-tab="cheques">Aging چک‌ها</button>
				<button class="cptt-rep-tab" data-tab="wip">WIP پروژه‌ها</button>
				<button class="cptt-rep-tab" data-tab="recon">تطبیق بانک</button>
			</div>

			<!-- ── Cash Flow ── -->
			<div class="cptt-rep-card" data-pane="cashflow">
				<h2>💸 صورت جریان وجوه نقد</h2>
				<div class="cptt-rep-filters">
					<div class="cptt-rep-field"><label>از تاریخ</label><input type="text" id="cf-from" placeholder="مثال ۱۴۰۴/۰۱/۰۱"></div>
					<div class="cptt-rep-field"><label>تا تاریخ</label><input type="text" id="cf-to" placeholder="<?php echo esc_attr($today); ?>" value="<?php echo esc_attr($today); ?>"></div>
					<div><button class="cptt-rep-btn" id="cf-run">📊 محاسبه</button></div>
				</div>
				<div id="cf-result"><div class="cptt-rep-empty">برای مشاهده روی «محاسبه» کلیک کنید</div></div>
			</div>

			<!-- ── Period Compare ── -->
			<div class="cptt-rep-card" data-pane="compare" style="display:none;">
				<h2>📈 مقایسه دوره‌ای</h2>
				<div class="cptt-rep-filters">
					<div class="cptt-rep-field"><label>دوره A — از</label><input type="text" id="cmp-a-from"></div>
					<div class="cptt-rep-field"><label>دوره A — تا</label><input type="text" id="cmp-a-to"></div>
					<div class="cptt-rep-field"><label>دوره B — از</label><input type="text" id="cmp-b-from"></div>
					<div class="cptt-rep-field"><label>دوره B — تا</label><input type="text" id="cmp-b-to"></div>
					<div><button class="cptt-rep-btn" id="cmp-run">📊 مقایسه</button></div>
				</div>
				<div id="cmp-result"><div class="cptt-rep-empty">دو بازه را مشخص کنید و کلیک کنید</div></div>
			</div>

			<!-- ── Budget vs Actual ── -->
			<div class="cptt-rep-card" data-pane="budget" style="display:none;">
				<h2>🎯 بودجه vs واقعی (به تفکیک مرکز هزینه)</h2>
				<div class="cptt-rep-filters">
					<div class="cptt-rep-field"><label>از تاریخ</label><input type="text" id="bg-from"></div>
					<div class="cptt-rep-field"><label>تا تاریخ</label><input type="text" id="bg-to" value="<?php echo esc_attr($today); ?>"></div>
					<div><button class="cptt-rep-btn" id="bg-run">📊 محاسبه</button></div>
				</div>
				<div id="bg-result"><div class="cptt-rep-empty">برای مشاهده روی «محاسبه» کلیک کنید</div></div>
			</div>

			<!-- ── Aging Customisable ── -->
			<div class="cptt-rep-card" data-pane="aging" style="display:none;">
				<h2>⏰ Aging مطالبات با پارامترهای دلخواه</h2>
				<div class="cptt-rep-filters">
					<div class="cptt-rep-field"><label>تاریخ پایه (پیش‌فرض امروز)</label><input type="text" id="ag-base" value="<?php echo esc_attr($today); ?>"></div>
					<div class="cptt-rep-field"><label>بازه‌ها (با کاما، روز)</label><input type="text" id="ag-buckets" value="30,60,90,99999" placeholder="30,60,90,99999"></div>
					<div><button class="cptt-rep-btn" id="ag-run">📊 محاسبه</button></div>
				</div>
				<div id="ag-result"><div class="cptt-rep-empty">پارامترها را تنظیم و کلیک کنید</div></div>
			</div>

			<!-- ── Cheque Aging ── -->
			<div class="cptt-rep-card" data-pane="cheques" style="display:none;">
				<h2>📅 Aging چک‌ها</h2>
				<div class="cptt-rep-filters">
					<div class="cptt-rep-field"><label>تاریخ پایه</label><input type="text" id="ch-base" value="<?php echo esc_attr($today); ?>"></div>
					<div><button class="cptt-rep-btn" id="ch-run">📊 محاسبه</button></div>
				</div>
				<div id="ch-result"><div class="cptt-rep-empty">برای مشاهده روی «محاسبه» کلیک کنید</div></div>
			</div>

			<!-- ── WIP ── -->
			<div class="cptt-rep-card" data-pane="wip" style="display:none;">
				<h2>🚧 گزارش WIP (Work-In-Progress)</h2>
				<p style="color:#64748b; font-size:.8rem; margin:0 0 10px;">مقایسه‌ی فاکتورشده، تکمیل‌شده و وصول‌شده per پروژه</p>
				<button class="cptt-rep-btn" id="wip-run">📊 محاسبه</button>
				<div id="wip-result" style="margin-top:12px;"><div class="cptt-rep-empty">برای مشاهده روی «محاسبه» کلیک کنید</div></div>
			</div>

			<!-- ── Bank Reconciliation ── -->
			<div class="cptt-rep-card" data-pane="recon" style="display:none;">
				<h2>🏦 تطبیق بانک</h2>
				<div class="cptt-rep-filters">
					<div class="cptt-rep-field"><label>حساب</label>
						<select id="bk-acc">
							<?php foreach ($treasury_accs as $a): ?>
								<option value="t<?php echo (int)$a->id; ?>"><?php echo esc_html($a->name); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="cptt-rep-field"><label>از تاریخ</label><input type="text" id="bk-from"></div>
					<div class="cptt-rep-field"><label>تا تاریخ</label><input type="text" id="bk-to" value="<?php echo esc_attr($today); ?>"></div>
					<div><button class="cptt-rep-btn" id="bk-run">📊 بارگذاری</button></div>
				</div>
				<div id="bk-result"><div class="cptt-rep-empty">حساب و بازه را انتخاب و کلیک کنید</div></div>
			</div>
		</div>

		<script>
		(function(){
			var nonce = '<?php echo esc_js($nonce); ?>';
			var ajax  = '<?php echo esc_js($ajax); ?>';
			function fa(n){ try { return new Intl.NumberFormat('fa-IR').format(Math.round(n)); } catch(e){ return String(n); } }
			function call(action, data, cb){
				var fd = new FormData();
				fd.append('action', action); fd.append('nonce', nonce);
				Object.keys(data || {}).forEach(function(k){
					var v = data[k]; if (typeof v === 'object') v = JSON.stringify(v);
					fd.append(k, v);
				});
				fetch(ajax, { method:'POST', body: fd, credentials:'same-origin' })
					.then(function(r){ return r.json(); }).then(cb)
					.catch(function(e){ cb({ success:false, data:{ message: String(e) } }); });
			}
			function show(host, html){ document.getElementById(host).innerHTML = html; }
			function err(host, m){ show(host, '<div class="cptt-rep-msg err">❌ ' + (m || 'خطا') + '</div>'); }

			// Tabs
			document.querySelectorAll('.cptt-rep-tab').forEach(function(b){
				b.onclick = function(){
					document.querySelectorAll('.cptt-rep-tab').forEach(function(x){ x.classList.remove('active'); });
					b.classList.add('active');
					var t = b.getAttribute('data-tab');
					document.querySelectorAll('[data-pane]').forEach(function(p){
						p.style.display = (p.getAttribute('data-pane') === t) ? '' : 'none';
					});
				};
			});

			// Cash Flow
			document.getElementById('cf-run').onclick = function(){
				call('cpttf_erp_cash_flow', { from: document.getElementById('cf-from').value, to: document.getElementById('cf-to').value }, function(r){
					if (!r.success) return err('cf-result', r.data && r.data.message);
					var d = r.data;
					var h = '<div class="cptt-rep-summary">';
					h += '<div class="cptt-rep-stat indigo"><div class="label">مانده ابتدای دوره</div><div class="value cptt-rep-num">' + fa(d.opening_cash) + '</div></div>';
					h += '<div class="cptt-rep-stat ' + (d.net_change >= 0 ? 'green' : 'red') + '"><div class="label">تغییر خالص نقد</div><div class="value cptt-rep-num">' + fa(d.net_change) + '</div></div>';
					h += '<div class="cptt-rep-stat indigo"><div class="label">مانده انتهای دوره</div><div class="value cptt-rep-num">' + fa(d.closing_cash) + '</div></div>';
					h += '</div>';
					['operating','investing','financing'].forEach(function(sec){
						var label = {operating:'فعالیت‌های عملیاتی', investing:'فعالیت‌های سرمایه‌گذاری', financing:'فعالیت‌های تأمین مالی'}[sec];
						var s = d[sec];
						h += '<div class="cptt-rep-section-title">' + label + ' — جمع: <span class="' + (s.total >= 0 ? 'cptt-rep-num" style="color:#059669"' : 'cptt-rep-num" style="color:#dc2626"') + '>' + fa(s.total) + '</span></div>';
						if (s.items.length === 0) { h += '<div class="cptt-rep-empty">رکوردی نیست</div>'; return; }
						h += '<table class="cptt-rep-table"><thead><tr><th>تاریخ</th><th>شرح</th><th>حساب طرف</th><th>مبلغ</th></tr></thead><tbody>';
						s.items.forEach(function(it){
							h += '<tr><td>' + it.date + '</td><td>' + it.description + '</td><td>' + it.account + '</td><td class="cptt-rep-num" style="color:' + (it.amount >= 0 ? '#059669' : '#dc2626') + ';">' + fa(it.amount) + '</td></tr>';
						});
						h += '</tbody></table>';
					});
					show('cf-result', h);
				});
			};

			// Period Compare
			document.getElementById('cmp-run').onclick = function(){
				call('cpttf_erp_period_compare', {
					a_from: document.getElementById('cmp-a-from').value, a_to: document.getElementById('cmp-a-to').value,
					b_from: document.getElementById('cmp-b-from').value, b_to: document.getElementById('cmp-b-to').value
				}, function(r){
					if (!r.success) return err('cmp-result', r.data && r.data.message);
					var d = r.data;
					var fmt = function(n, ref){ if (n === null) return '—'; return n.toFixed(2) + '٪'; };
					var h = '<table class="cptt-rep-table"><thead><tr><th>مورد</th><th>دوره A (' + (d.a.from||'—') + ' تا ' + (d.a.to||'—') + ')</th><th>دوره B (' + (d.b.from||'—') + ' تا ' + (d.b.to||'—') + ')</th><th>تغییر</th><th>درصد</th></tr></thead><tbody>';
					['revenue','expense','profit'].forEach(function(k){
						var label = {revenue:'درآمد', expense:'هزینه', profit:'سود/زیان'}[k];
						var delta = d.delta[k];
						var pct = d.percent[k];
						var color = delta >= 0 ? '#059669' : '#dc2626';
						h += '<tr><td><strong>' + label + '</strong></td><td class="cptt-rep-num">' + fa(d.a.data[k]) + '</td><td class="cptt-rep-num">' + fa(d.b.data[k]) + '</td><td class="cptt-rep-num" style="color:' + color + ';">' + (delta >= 0 ? '+' : '') + fa(delta) + '</td><td style="color:' + color + ';">' + (pct === null ? '—' : (pct >= 0 ? '+' : '') + fmt(pct)) + '</td></tr>';
					});
					h += '</tbody></table>';
					show('cmp-result', h);
				});
			};

			// Budget Actual
			document.getElementById('bg-run').onclick = function(){
				call('cpttf_erp_budget_actual', { from: document.getElementById('bg-from').value, to: document.getElementById('bg-to').value }, function(r){
					if (!r.success) return err('bg-result', r.data && r.data.message);
					var rows = r.data.rows;
					if (!rows.length) return show('bg-result', '<div class="cptt-rep-empty">مرکز هزینه‌ای یافت نشد</div>');
					var h = '<table class="cptt-rep-table"><thead><tr><th>کد</th><th>نام</th><th>بودجه</th><th>واقعی</th><th>اختلاف</th><th>درصد مصرف</th><th>وضعیت</th></tr></thead><tbody>';
					rows.forEach(function(c){
						var pillClass = c.status === 'over' ? 'over' : (c.status === 'warning' ? 'warn' : (c.status === 'no_budget' ? 'neutral' : 'ok'));
						var pillText = c.status === 'over' ? 'بیش از بودجه' : (c.status === 'warning' ? 'هشدار' : (c.status === 'no_budget' ? 'بدون بودجه' : 'سالم'));
						var pct = c.used_pct;
						var bar = pct === null ? '' : '<div class="cptt-rep-progress"><div style="width:' + Math.min(100, pct) + '%; background:' + (pct > 100 ? '#dc2626' : (pct > 80 ? '#f59e0b' : '#10b981')) + ';"></div></div>';
						h += '<tr><td>' + (c.code || '—') + '</td><td><strong>' + c.name + '</strong></td><td class="cptt-rep-num">' + fa(c.budget) + '</td><td class="cptt-rep-num">' + fa(c.actual) + '</td><td class="cptt-rep-num" style="color:' + (c.variance >= 0 ? '#059669' : '#dc2626') + ';">' + fa(c.variance) + '</td><td>' + (pct === null ? '—' : pct + '٪' + bar) + '</td><td><span class="cptt-rep-pill ' + pillClass + '">' + pillText + '</span></td></tr>';
					});
					h += '</tbody></table>';
					show('bg-result', h);
				});
			};

			// Aging Custom
			document.getElementById('ag-run').onclick = function(){
				call('cpttf_erp_aging_custom', { base_date: document.getElementById('ag-base').value, buckets: document.getElementById('ag-buckets').value }, function(r){
					if (!r.success) return err('ag-result', r.data && r.data.message);
					var d = r.data;
					var buckets = d.buckets;
					var prev = 0;
					var h = '<div class="cptt-rep-summary">';
					buckets.forEach(function(b){
						var lbl = b >= 99999 ? prev + '+ روز' : prev + ' تا ' + b + ' روز';
						var color = b >= 99999 ? 'red' : (b >= 90 ? 'amber' : (b >= 60 ? 'amber' : 'green'));
						h += '<div class="cptt-rep-stat ' + color + '"><div class="label">' + lbl + '</div><div class="value cptt-rep-num">' + fa(d.totals[b] || 0) + '</div></div>';
						prev = b;
					});
					h += '</div>';
					h += '<table class="cptt-rep-table"><thead><tr><th>مشتری</th><th>مانده باز</th><th>آخرین پرداخت</th><th>سن (روز)</th><th>بازه</th></tr></thead><tbody>';
					d.rows.forEach(function(c){
						h += '<tr><td>' + c.customerName + '</td><td class="cptt-rep-num" style="color:#dc2626;font-weight:700;">' + fa(c.totalRemain) + '</td><td>' + c.last_payment_date + '</td><td class="cptt-rep-num">' + c.age_days + '</td><td>' + (c.bucket >= 99999 ? 'بیش از ' + prev : '≤ ' + c.bucket) + ' روز</td></tr>';
					});
					h += '</tbody></table>';
					show('ag-result', h);
				});
			};

			// Cheque Aging
			document.getElementById('ch-run').onclick = function(){
				call('cpttf_erp_aging_cheques', { base_date: document.getElementById('ch-base').value }, function(r){
					if (!r.success) return err('ch-result', r.data && r.data.message);
					var d = r.data;
					var h = '<table class="cptt-rep-table"><thead><tr><th>شماره</th><th>بانک</th><th>طرف</th><th>سررسید</th><th>روزها تا سررسید</th><th>مبلغ</th><th>وضعیت</th></tr></thead><tbody>';
					d.rows.forEach(function(c){
						var color = c.days_to_due < 0 ? '#dc2626' : (c.days_to_due <= 7 ? '#f59e0b' : '#475569');
						h += '<tr><td>' + c.number + '</td><td>' + c.bank + '</td><td>' + (c.partyName || '—') + '</td><td>' + c.dueDate + '</td><td class="cptt-rep-num" style="color:' + color + ';font-weight:700;">' + c.days_to_due + '</td><td class="cptt-rep-num">' + fa(c.amount) + '</td><td><span class="cptt-rep-pill ' + (c.kind === 'receivable' ? 'ok' : 'warn') + '">' + (c.kind === 'receivable' ? 'دریافتی' : 'پرداختی') + '</span></td></tr>';
					});
					if (d.rows.length === 0) h += '<tr><td colspan="7"><div class="cptt-rep-empty">چکی برای aging نیست</div></td></tr>';
					h += '</tbody></table>';
					show('ch-result', h);
				});
			};

			// WIP
			document.getElementById('wip-run').onclick = function(){
				call('cpttf_erp_wip_report', {}, function(r){
					if (!r.success) return err('wip-result', r.data && r.data.message);
					var rows = r.data.rows;
					if (!rows.length) return show('wip-result', '<div class="cptt-rep-empty">پروژه‌ای با cost وجود ندارد</div>');
					var h = '<table class="cptt-rep-table"><thead><tr><th>پروژه</th><th>فاکتورشده</th><th>تکمیل‌شده</th><th>وصول‌شده</th><th>over/under</th><th>درصد تکمیل</th><th>درصد وصول</th></tr></thead><tbody>';
					rows.forEach(function(p){
						var statusColor = p.status === 'over_billed' ? '#dc2626' : (p.status === 'under_billed' ? '#f59e0b' : '#059669');
						h += '<tr><td><strong>' + p.projectTitle + '</strong></td><td class="cptt-rep-num">' + fa(p.billed) + '</td><td class="cptt-rep-num">' + fa(p.completed) + '</td><td class="cptt-rep-num">' + fa(p.collected) + '</td><td class="cptt-rep-num" style="color:' + statusColor + ';font-weight:700;">' + fa(p.over_under) + '</td><td>' + p.pct_complete + '٪<div class="cptt-rep-progress"><div style="width:' + Math.min(100, p.pct_complete) + '%; background:#4f46e5;"></div></div></td><td>' + p.pct_collected + '٪<div class="cptt-rep-progress"><div style="width:' + Math.min(100, p.pct_collected) + '%; background:#10b981;"></div></div></td></tr>';
					});
					h += '</tbody></table>';
					show('wip-result', h);
				});
			};

			// Bank Recon
			var lastRecon = null;
			document.getElementById('bk-run').onclick = function(){
				call('cpttf_erp_bank_recon', { account_id: document.getElementById('bk-acc').value, from: document.getElementById('bk-from').value, to: document.getElementById('bk-to').value }, function(r){
					if (!r.success) return err('bk-result', r.data && r.data.message);
					lastRecon = r.data;
					var s = r.data.summary;
					var h = '<div class="cptt-rep-summary">';
					h += '<div class="cptt-rep-stat green"><div class="label">جمع ورودی</div><div class="value cptt-rep-num">' + fa(s.total_debit) + '</div></div>';
					h += '<div class="cptt-rep-stat red"><div class="label">جمع خروجی</div><div class="value cptt-rep-num">' + fa(s.total_credit) + '</div></div>';
					h += '<div class="cptt-rep-stat indigo"><div class="label">تطبیق‌نشده</div><div class="value cptt-rep-num">' + fa(s.unrecon_balance) + '</div></div>';
					h += '</div>';
					h += '<div class="cptt-rep-actions"><button class="cptt-rep-btn" id="bk-save">💾 ذخیره‌ی تطبیق</button> <button class="cptt-rep-btn ghost" onclick="document.querySelectorAll(\'[data-recon]\').forEach(function(x){x.checked=true;})">انتخاب همه</button> <button class="cptt-rep-btn ghost" onclick="document.querySelectorAll(\'[data-recon]\').forEach(function(x){x.checked=false;})">انتخاب هیچ‌کدام</button></div>';
					h += '<table class="cptt-rep-table"><thead><tr><th>✓</th><th>تاریخ</th><th>شرح</th><th>ورود</th><th>خروج</th><th>منبع</th></tr></thead><tbody>';
					r.data.rows.forEach(function(it){
						var d = it.direction > 0 ? it.amount : 0;
						var c = it.direction < 0 ? it.amount : 0;
						h += '<tr><td><input type="checkbox" data-recon data-id="' + it.id + '"' + (it.reconciled ? ' checked' : '') + '></td><td>' + it.date + '</td><td>' + it.description + '</td><td class="cptt-rep-num" style="color:#059669;">' + (d > 0 ? fa(d) : '') + '</td><td class="cptt-rep-num" style="color:#dc2626;">' + (c > 0 ? fa(c) : '') + '</td><td style="color:#94a3b8;">' + (it.ref_type || '—') + '</td></tr>';
					});
					if (!r.data.rows.length) h += '<tr><td colspan="6"><div class="cptt-rep-empty">رکوردی در این بازه نیست</div></td></tr>';
					h += '</tbody></table>';
					show('bk-result', h);
					var sv = document.getElementById('bk-save');
					if (sv) sv.onclick = function(){
						var ids = [];
						document.querySelectorAll('[data-recon]').forEach(function(cb){ if (cb.checked) ids.push(cb.getAttribute('data-id')); });
						call('cpttf_erp_bank_recon_save', { account_id: document.getElementById('bk-acc').value, ids: ids.join(',') }, function(rr){
							if (rr.success) alert('✅ ذخیره شد: ' + (rr.data.count || 0) + ' رکورد تطبیق‌شده');
							else alert('❌ ' + (rr.data && rr.data.message ? rr.data.message : 'خطا'));
						});
					};
				});
			};
		})();
		</script>
		<?php
	}

	public function enqueue_assets($hook){
		if (!isset($_GET['page']) || $_GET['page'] !== self::PAGE_SLUG) return;
		add_filter('admin_body_class', function($c){ return $c . ' cpttf-erp-fullscreen '; });

		// Dequeue 3rd-party styles/scripts that would visually pollute our Tailwind shell.
		// Use a very small allowlist (only what WordPress core absolutely needs).
		add_action('admin_print_styles',  [$this, 'dequeue_3rd_party_assets'], 9999);
		add_action('admin_print_scripts', [$this, 'dequeue_3rd_party_assets'], 9999);

		wp_enqueue_style('cpttf-erp-fonts', CPTT_URL . 'assets/finance-ui/fonts.css', [], CPTT_VERSION);
		wp_enqueue_style('cpttf-erp-app', CPTT_URL . 'assets/finance-ui/app.css', ['cpttf-erp-fonts'], CPTT_VERSION);
		wp_enqueue_script('cpttf-erp-app', CPTT_URL . 'assets/finance-ui/app.js', [], CPTT_VERSION, true);
		add_filter('script_loader_tag', function($tag, $handle){
			if ($handle === 'cpttf-erp-app') {
				$tag = str_replace('<script ', '<script type="module" crossorigin ', $tag);
			}
			return $tag;
		}, 10, 2);

		$user  = wp_get_current_user();
		// Phase 4: use centralised role resolver (RBAC)
		$role  = $this->current_role_key();

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
			'assets'     => [
				'fontsBase' => CPTT_URL . 'assets/fonts/',
				'fontCss'   => CPTT_URL . 'assets/finance-ui/fonts.css',
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

	/* ═════════════════════════════════════════════════════════════════
	 * ▼▼▼ Phase 4 — Real Backend RBAC + Security ▼▼▼
	 * ═════════════════════════════════════════════════════════════════ */

	/**
	 * Resolve current user's ERP role from WordPress role + DEFAULT mapping.
	 * cptt_expert → has ONLY view-level access (downgrade from old behaviour).
	 * administrator → ceo
	 * cptt_financial_manager → financial_manager
	 * cptt_accountant → accountant
	 * cptt_cashier → cashier
	 * Anyone else → 'viewer' (read-only, very limited).
	 */
	private function current_role_key(){
		$u = wp_get_current_user();
		if (!$u || !$u->ID) return 'viewer';
		$roles = (array)$u->roles;
		if (in_array('administrator', $roles, true))           return 'ceo';
		if (in_array('cptt_financial_manager', $roles, true))  return 'financial_manager';
		if (in_array('cptt_accountant', $roles, true))         return 'accountant';
		if (in_array('cptt_cashier', $roles, true))            return 'cashier';
		// cptt_expert is intentionally limited (Phase 4 hardening)
		if (in_array('cptt_expert', $roles, true))             return 'viewer';
		return 'viewer';
	}

	/**
	 * The "ground truth" permission matrix — used as defaults when the
	 * user hasn't customised the matrix via the Permissions UI yet.
	 * Each role → array of permission keys it has.
	 */
	private function default_role_perms(){
		$all = [
			'voucher_view','voucher_create','voucher_approve','voucher_delete','voucher_reverse',
			'reports_view','treasury_view','treasury_payment','project_finance_view',
			'audit_view','settings_manage','mapping_manage',
		];
		return [
			'ceo'               => array_fill_keys($all, true),
			'financial_manager' => array_fill_keys(['voucher_view','voucher_create','voucher_approve','voucher_reverse','reports_view','treasury_view','treasury_payment','project_finance_view','audit_view'], true),
			'accountant'        => array_fill_keys(['voucher_view','voucher_create','voucher_approve','reports_view','treasury_view','project_finance_view'], true),
			'cashier'           => array_fill_keys(['voucher_view','treasury_view','treasury_payment'], true),
			'viewer'            => array_fill_keys(['voucher_view','treasury_view','reports_view','project_finance_view'], true),
		];
	}

	/**
	 * Whether the CURRENT user has $perm. Reads the saved permissions
	 * matrix (cpttf_erp_role_permissions) first; falls back to defaults.
	 * Administrator (role=ceo) ALWAYS passes (super-admin escape hatch).
	 */
	public function role_has_perm($perm){
		// Super-admin & site admins always pass (escape hatch / disaster recovery)
		if (current_user_can('manage_options')) return true;
		$role = $this->current_role_key();
		if ($role === 'ceo') return true;
		$saved = get_option(self::OPT_PERMS, []);
		if (is_array($saved) && isset($saved[$role]) && is_array($saved[$role])) {
			return !empty($saved[$role][$perm]);
		}
		$def = $this->default_role_perms();
		return !empty($def[$role][$perm]);
	}

	/**
	 * Permission-aware check. Replaces $this->check() at security-sensitive
	 * endpoints. Verifies nonce + base capability + the specific permission.
	 * On failure sends 403 with a clear Persian message.
	 */
	private function check_perm($perm){
		if (!is_user_logged_in()) wp_send_json_error('باید وارد شوید', 401);
		check_ajax_referer(self::NONCE, 'nonce');
		// Base capability gate (anyone touching ERP must have at least this)
		if (!current_user_can('edit_cptt_projects') && !current_user_can('manage_options')) {
			wp_send_json_error('دسترسی به ماژول ERP ندارید', 403);
		}
		if (!$this->role_has_perm($perm)) {
			wp_send_json_error('برای این عملیات («' . $perm . '») مجوز ندارید', 403);
		}
		// Rate-limit per user per action (max 60 reqs / 60 sec on writes)
		if (in_array($perm, ['voucher_create','voucher_approve','voucher_delete','voucher_reverse','treasury_payment','settings_manage','mapping_manage'], true)) {
			$this->rate_limit_check($perm, 60, 60);
		}
	}

	/**
	 * Simple sliding-window rate-limiter using a single transient per
	 * (user, perm). Sends 429 if exceeded.
	 */
	private function rate_limit_check($key, $max = 60, $window_sec = 60){
		$uid = get_current_user_id();
		$tk  = 'cpttf_erp_rl_' . $key . '_' . (int)$uid;
		$cur = (int)get_transient($tk);
		if ($cur >= $max) {
			wp_send_json_error('تعداد درخواست‌های شما در دقیقه‌ی اخیر از حد مجاز عبور کرد. لطفاً کمی صبر کنید.', 429);
		}
		set_transient($tk, $cur + 1, $window_sec);
	}

	/**
	 * Strict MIME validation for uploaded files. Uses WordPress
	 * wp_check_filetype_and_ext() which inspects actual file contents,
	 * not the user-supplied extension. Returns true|WP_Error.
	 */
	private function validate_uploaded_file($file, $allowed_mimes = []){
		if (empty($file) || !is_array($file) || empty($file['tmp_name'])) {
			return new WP_Error('no_file', 'فایلی برای آپلود ارسال نشده است');
		}
		if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
			return new WP_Error('upload_error', 'خطای آپلود کد ' . (int)$file['error']);
		}
		$max = (int) apply_filters('cpttf_erp_max_upload_bytes', 10 * 1024 * 1024); // 10 MB default
		if (!empty($file['size']) && (int)$file['size'] > $max) {
			return new WP_Error('too_large', 'حداکثر حجم مجاز ' . round($max / 1048576, 1) . ' مگابایت است');
		}
		if (empty($allowed_mimes)) {
			$allowed_mimes = [
				'pdf'  => 'application/pdf',
				'jpg|jpeg' => 'image/jpeg',
				'png'  => 'image/png',
				'zip'  => 'application/zip',
			];
		}
		$check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed_mimes);
		if (empty($check['type']) || empty($check['ext'])) {
			return new WP_Error('bad_mime', 'نوع فایل مجاز نیست. فقط PDF / JPG / PNG / ZIP پذیرفته می‌شود.');
		}
		return ['ext' => $check['ext'], 'type' => $check['type']];
	}

	/**
	 * Safe rich-text sanitizer for description / note fields that may
	 * legitimately contain newlines and basic formatting. Strips ALL
	 * tags by default; pass $allow_basic=true for very limited HTML.
	 */
	private function safe_text($v, $allow_basic = false){
		$v = (string)$v;
		$v = wp_unslash($v);
		if ($allow_basic) {
			return wp_kses($v, [
				'br' => [], 'p' => [], 'b' => [], 'strong' => [], 'i' => [], 'em' => [],
				'u' => [], 'span' => ['style'=>[]], 'a' => ['href'=>[], 'title'=>[], 'target'=>[]],
			]);
		}
		// strip everything and normalize whitespace
		$v = wp_strip_all_tags($v, true);
		return mb_substr($v, 0, 5000);
	}

	/* ═════════════════════════════════════════════════════════════════
	 * ▼▼▼ Phase 5 — Period Locking & Fiscal Year Closing ▼▼▼
	 * ═════════════════════════════════════════════════════════════════ */

	/**
	 * Compare two Jalali dates in "YYYY/MM/DD" format. Returns -1/0/+1.
	 * Used by period-lock and FY enforcement.
	 */
	private function fa_date_cmp($a, $b){
		$an = preg_replace('/\D/', '', $this->to_en_digits((string)$a));
		$bn = preg_replace('/\D/', '', $this->to_en_digits((string)$b));
		if ($an === $bn) return 0;
		return $an < $bn ? -1 : 1;
	}

	/** Convert Persian digits to English (for safe string compare) */
	private function to_en_digits($s){
		$fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
		$ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
		$en = ['0','1','2','3','4','5','6','7','8','9'];
		return str_replace($fa, $en, str_replace($ar, $en, (string)$s));
	}

	/**
	 * Check whether a given Jalali date falls inside ANY active lock or
	 * a CLOSED fiscal year. Returns ['locked'=>true, 'reason'=>'…'] or
	 * ['locked'=>false].
	 *
	 * Lock entries: { fiscalYearId, startDate, endDate, isLocked, lockedBy }
	 * If startDate/endDate of lock are empty, the whole fiscal year is locked.
	 */
	public function is_date_locked($jalali_date){
		$jalali_date = (string)$jalali_date;
		if ($jalali_date === '') return ['locked'=>false];

		// 1) Check closed fiscal years
		foreach ($this->get_fy() as $fy) {
			if (($fy['status'] ?? '') !== 'CLOSED') continue;
			$fs = (string)($fy['startDate'] ?? '');
			$fe = (string)($fy['endDate'] ?? '');
			if ($fs && $fe) {
				if ($this->fa_date_cmp($jalali_date, $fs) >= 0 && $this->fa_date_cmp($jalali_date, $fe) <= 0) {
					return ['locked'=>true, 'reason'=>'سال مالی «' . ($fy['name'] ?? '—') . '» بسته شده است (' . $fs . ' تا ' . $fe . ')'];
				}
			}
		}

		// 2) Check explicit period locks
		foreach ($this->get_locks() as $l) {
			if (empty($l['isLocked'])) continue;
			$ls = (string)($l['startDate'] ?? '');
			$le = (string)($l['endDate'] ?? '');
			// Empty range = whole FY locked
			if ($ls === '' && $le === '') {
				$fy_id = (string)($l['fiscalYearId'] ?? '');
				$fy_match = null;
				foreach ($this->get_fy() as $fy) if (($fy['id'] ?? '') === $fy_id) { $fy_match = $fy; break; }
				if ($fy_match) {
					$fs = (string)($fy_match['startDate'] ?? '');
					$fe = (string)($fy_match['endDate'] ?? '');
					if ($fs && $fe && $this->fa_date_cmp($jalali_date, $fs) >= 0 && $this->fa_date_cmp($jalali_date, $fe) <= 0) {
						return ['locked'=>true, 'reason'=>'دوره‌ی سال مالی «' . ($fy_match['name'] ?? '—') . '» قفل شده توسط ' . ($l['lockedBy'] ?? '—')];
					}
				}
				continue;
			}
			$ok_start = $ls === '' ? true : $this->fa_date_cmp($jalali_date, $ls) >= 0;
			$ok_end   = $le === '' ? true : $this->fa_date_cmp($jalali_date, $le) <= 0;
			if ($ok_start && $ok_end) {
				return ['locked'=>true, 'reason'=>'بازه‌ی ' . ($ls ?: '—') . ' تا ' . ($le ?: '—') . ' قفل شده توسط ' . ($l['lockedBy'] ?? '—')];
			}
		}
		return ['locked'=>false];
	}

	/**
	 * Convenience: if date is locked, immediately send a 423 (Locked)
	 * JSON error and exit. Use at the top of write endpoints AFTER
	 * check_perm() and AFTER you know the target date.
	 */
	private function reject_if_locked($jalali_date, $context = ''){
		// Super-admin always allowed even on closed periods (escape hatch)
		if (current_user_can('manage_options')) return;
		$res = $this->is_date_locked($jalali_date);
		if ($res['locked']) {
			wp_send_json_error(($context ? $context . ' — ' : '') . $res['reason'], 423);
		}
	}

	/**
	 * Find the fiscal year whose date range encloses the given Jalali date.
	 * Returns null if none. Used by closing/preview.
	 */
	private function fy_for_date($jalali_date){
		foreach ($this->get_fy() as $fy) {
			$s = (string)($fy['startDate'] ?? '');
			$e = (string)($fy['endDate']   ?? '');
			if ($s && $e && $this->fa_date_cmp($jalali_date, $s) >= 0 && $this->fa_date_cmp($jalali_date, $e) <= 0) {
				return $fy;
			}
		}
		return null;
	}

	/**
	 * Sum revenue / expense / profit for the given fiscal year, derived
	 * from FINALIZED-only vouchers whose rows reference accounts under
	 * groups 5 (revenue) or 6 (expense).
	 *
	 * The "group" of an account is inferred from the first character of
	 * its `code` ('5' or '۵', '6' or '۶'). This matches the legacy
	 * convention used in FinancialReports.IncomeStatement.
	 *
	 * Returns ['revenue', 'expense', 'profit', 'rev_by_account', 'exp_by_account'].
	 */
	private function fy_pl_summary($fy){
		$fs = (string)($fy['startDate'] ?? '');
		$fe = (string)($fy['endDate']   ?? '');
		$accs = $this->get_coa();
		$by_id = [];
		foreach ($accs as $a) $by_id[(string)$a['id']] = $a;
		$rev = 0.0; $exp = 0.0;
		$rev_by = []; $exp_by = [];
		foreach ($this->get_vouchers() as $v) {
			$st = (string)($v['status'] ?? '');
			if ($st !== 'FINALIZED') continue;
			$d  = (string)($v['date'] ?? '');
			if ($fs && $this->fa_date_cmp($d, $fs) < 0) continue;
			if ($fe && $this->fa_date_cmp($d, $fe) > 0) continue;
			// Skip closing/opening vouchers (they're the closing transfer itself)
			if (in_array((string)($v['voucherType'] ?? ''), ['CV','OV'], true)) continue;
			foreach ((array)($v['rows'] ?? []) as $r) {
				$aid = (string)($r['accountId'] ?? '');
				if (!isset($by_id[$aid])) continue;
				$code = (string)($by_id[$aid]['code'] ?? '');
				$first = $this->to_en_digits(mb_substr($code, 0, 1));
				$dv = (float)($r['debit']  ?? 0);
				$cv = (float)($r['credit'] ?? 0);
				if ($first === '5') {
					$delta = $cv - $dv;
					$rev += $delta;
					if (!isset($rev_by[$aid])) $rev_by[$aid] = ['accountId'=>$aid,'name'=>$by_id[$aid]['name'],'code'=>$code,'amount'=>0];
					$rev_by[$aid]['amount'] += $delta;
				} elseif ($first === '6') {
					$delta = $dv - $cv;
					$exp += $delta;
					if (!isset($exp_by[$aid])) $exp_by[$aid] = ['accountId'=>$aid,'name'=>$by_id[$aid]['name'],'code'=>$code,'amount'=>0];
					$exp_by[$aid]['amount'] += $delta;
				}
			}
		}
		return [
			'revenue' => round($rev, 2),
			'expense' => round($exp, 2),
			'profit'  => round($rev - $exp, 2),
			'rev_by_account' => array_values($rev_by),
			'exp_by_account' => array_values($exp_by),
		];
	}

	/**
	 * Compute balances of permanent accounts (groups 1-4) on the closing
	 * date of a fiscal year. These are used to generate the OV (opening
	 * voucher) of the NEXT fiscal year.
	 */
	private function fy_permanent_balances($fy){
		$fe = (string)($fy['endDate'] ?? '');
		$accs = $this->get_coa();
		$by_id = [];
		foreach ($accs as $a) $by_id[(string)$a['id']] = $a;
		$bal = []; // accountId → debit-credit running
		foreach ($this->get_vouchers() as $v) {
			$st = (string)($v['status'] ?? '');
			if ($st !== 'FINALIZED') continue;
			$d  = (string)($v['date'] ?? '');
			if ($fe && $this->fa_date_cmp($d, $fe) > 0) continue;
			foreach ((array)($v['rows'] ?? []) as $r) {
				$aid = (string)($r['accountId'] ?? '');
				if (!isset($by_id[$aid])) continue;
				$code = (string)($by_id[$aid]['code'] ?? '');
				$first = $this->to_en_digits(mb_substr($code, 0, 1));
				// Only groups 1, 2, 3, 4 (assets, liabilities, equity)
				if (!in_array($first, ['1','2','3','4'], true)) continue;
				if (!isset($bal[$aid])) $bal[$aid] = ['accountId'=>$aid,'name'=>$by_id[$aid]['name'],'code'=>$code,'first'=>$first,'amount'=>0];
				$bal[$aid]['amount'] += (float)($r['debit'] ?? 0) - (float)($r['credit'] ?? 0);
			}
		}
		// Filter out zero balances
		return array_values(array_filter($bal, function($b){ return abs($b['amount']) > 0.005; }));
	}

	/* ─── Phase 5 endpoints ─── */

	/** Check whether a date is currently locked (used by UI before forms) */
	public function ajax_period_check(){
		$this->check();
		$d = sanitize_text_field((string)($_POST['date'] ?? ''));
		wp_send_json_success($this->is_date_locked($d));
	}

	/** Preview the closing entries for a fiscal year */
	public function ajax_fy_close_preview(){
		$this->check_perm('settings_manage');
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		$fy = null;
		foreach ($this->get_fy() as $f) if (($f['id'] ?? '') === $id) { $fy = $f; break; }
		if (!$fy) wp_send_json_error('سال مالی پیدا نشد', 404);
		if (($fy['status'] ?? 'OPEN') === 'CLOSED') wp_send_json_error('این سال مالی قبلاً بسته شده است', 400);
		$pl   = $this->fy_pl_summary($fy);
		$open = $this->fy_permanent_balances($fy);
		wp_send_json_success([
			'fiscal_year'         => $fy,
			'profit_loss'         => $pl,
			'permanent_balances'  => $open,
		]);
	}

	/**
	 * Execute fiscal-year closing:
	 *   1. Generate CV (closing voucher): zero-out revenue + expense → retained_earnings
	 *   2. Mark fiscal year status = CLOSED
	 *   3. Add a full-FY lock entry
	 *   4. (If next FY exists) Generate OV (opening voucher) for next FY
	 *
	 * Safety: only CEO + manage_options can execute. Idempotent — refuses
	 * if FY already CLOSED.
	 */
	public function ajax_fy_close_execute(){
		$this->check_perm('settings_manage');
		if (!current_user_can('manage_options')) wp_send_json_error('فقط مدیر سیستم می‌تواند سال مالی را ببندد', 403);
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		$next_id = sanitize_text_field((string)($_POST['next_fy_id'] ?? '')); // optional
		$fy = null;
		$all = $this->get_fy();
		foreach ($all as $i => $f) if (($f['id'] ?? '') === $id) { $fy = $f; $fy_idx = $i; break; }
		if (!$fy) wp_send_json_error('سال مالی پیدا نشد', 404);
		if (($fy['status'] ?? 'OPEN') === 'CLOSED') wp_send_json_error('این سال مالی قبلاً بسته شده است', 400);

		$pl  = $this->fy_pl_summary($fy);
		$fe  = (string)($fy['endDate'] ?? $this->fa_today());
		$re_acc = $this->map_account('retained_earnings') ?: 'a4_3';
		$rows = [];
		$rno = 1;
		// Revenue accounts: credit balance → DEBIT them (zero out)
		foreach ($pl['rev_by_account'] as $row) {
			$amt = abs((float)$row['amount']);
			if ($amt < 0.005) continue;
			$rows[] = [
				'id' => 'r' . $rno++, 'accountId' => $row['accountId'], 'accountName' => $row['name'],
				'debit' => $amt, 'credit' => 0,
				'description' => 'بستن حساب درآمد در پایان سال مالی',
			];
		}
		// Expense accounts: debit balance → CREDIT them (zero out)
		foreach ($pl['exp_by_account'] as $row) {
			$amt = abs((float)$row['amount']);
			if ($amt < 0.005) continue;
			$rows[] = [
				'id' => 'r' . $rno++, 'accountId' => $row['accountId'], 'accountName' => $row['name'],
				'debit' => 0, 'credit' => $amt,
				'description' => 'بستن حساب هزینه در پایان سال مالی',
			];
		}
		// Net to retained earnings
		$profit = (float)$pl['profit'];
		if (abs($profit) >= 0.005) {
			if ($profit > 0) {
				// Profit: credit retained earnings
				$rows[] = ['id'=>'r'.$rno++, 'accountId'=>$re_acc, 'accountName'=>$this->coa_name($re_acc), 'debit'=>0, 'credit'=>$profit, 'description'=>'انتقال سود دوره به سود انباشته'];
			} else {
				// Loss: debit retained earnings
				$rows[] = ['id'=>'r'.$rno++, 'accountId'=>$re_acc, 'accountName'=>$this->coa_name($re_acc), 'debit'=>abs($profit), 'credit'=>0, 'description'=>'انتقال زیان دوره به سود انباشته'];
			}
		}

		$cv_id = null;
		if (!empty($rows)) {
			$cv_id = $this->gen_voucher([
				'type'        => 'CV',
				'description' => 'سند اختتامیه سال مالی ' . ($fy['name'] ?? '—'),
				'date'        => $fe,
				'source_type' => 'fy_close',
				'ref_type'    => 'fy_close',
				'ref_id'      => 0,
				'rows'        => $rows,
				'strict'      => false, // closing might have rounding mismatches; we accept ≤ 1 rial
			]);
			if (is_wp_error($cv_id)) wp_send_json_error('خطا در ایجاد سند اختتامیه: ' . $cv_id->get_error_message(), 500);
		}

		// 2. Mark FY as CLOSED
		$all[$fy_idx]['status']   = 'CLOSED';
		$all[$fy_idx]['closedAt'] = $this->fa_today();
		$all[$fy_idx]['closedBy'] = wp_get_current_user()->display_name;
		update_option(self::OPT_FY, $all, false);

		// 3. Add a full-FY lock entry (idempotent)
		$locks = $this->get_locks();
		$has_lock = false;
		foreach ($locks as $i => $l) if (($l['fiscalYearId'] ?? '') === $id) {
			$locks[$i]['isLocked'] = true;
			$locks[$i]['startDate'] = (string)($fy['startDate'] ?? '');
			$locks[$i]['endDate']   = $fe;
			$locks[$i]['lockedBy']  = wp_get_current_user()->display_name;
			$has_lock = true; break;
		}
		if (!$has_lock) {
			$locks[] = [
				'id' => 'fl_' . time(),
				'fiscalYearId' => $id,
				'startDate' => (string)($fy['startDate'] ?? ''),
				'endDate'   => $fe,
				'isLocked'  => true,
				'lockedBy'  => wp_get_current_user()->display_name,
			];
		}
		update_option(self::OPT_LOCKS, $locks, false);

		// 4. Generate OV for next FY (if provided + exists)
		$ov_id = null;
		if ($next_id) {
			$next_fy = null;
			foreach ($all as $f) if (($f['id'] ?? '') === $next_id) { $next_fy = $f; break; }
			if ($next_fy && (string)$next_fy['id'] !== (string)$fy['id']) {
				$perm = $this->fy_permanent_balances($fy);
				// Add retained earnings (closing balance) as well
				// retained_earnings was already debited/credited in CV; its
				// net balance is already reflected in $perm if a4_3 is in groups 1-4.
				$rows2 = [];
				$rno2 = 1;
				foreach ($perm as $b) {
					$amt = (float)$b['amount'];
					if (abs($amt) < 0.005) continue;
					$rows2[] = [
						'id' => 'r' . $rno2++,
						'accountId' => $b['accountId'],
						'accountName' => $b['name'],
						'debit'  => $amt > 0 ? $amt : 0,
						'credit' => $amt < 0 ? abs($amt) : 0,
						'description' => 'مانده افتتاحیه از سال ' . ($fy['name'] ?? '—'),
					];
				}
				if (!empty($rows2)) {
					// Balance-check: sum of debits should equal sum of credits
					$td = 0; $tc = 0;
					foreach ($rows2 as $r) { $td += $r['debit']; $tc += $r['credit']; }
					$diff = round($td - $tc, 2);
					if (abs($diff) > 0.005) {
						// Plug to retained earnings
						$re_acc2 = $this->map_account('retained_earnings') ?: 'a4_3';
						$rows2[] = [
							'id' => 'r' . $rno2++,
							'accountId' => $re_acc2,
							'accountName' => $this->coa_name($re_acc2),
							'debit'  => $diff < 0 ? abs($diff) : 0,
							'credit' => $diff > 0 ? $diff : 0,
							'description' => 'تسویه‌ی مانده‌ی افتتاحیه با سود انباشته',
						];
					}
					$ov_id = $this->gen_voucher([
						'type'        => 'OV',
						'description' => 'سند افتتاحیه سال مالی ' . ($next_fy['name'] ?? '—'),
						'date'        => (string)($next_fy['startDate'] ?? $this->fa_today()),
						'source_type' => 'fy_open',
						'ref_type'    => 'fy_open',
						'ref_id'      => 0,
						'rows'        => $rows2,
						'strict'      => false,
					]);
					if (is_wp_error($ov_id)) {
						// Don't fail closing if OV fails; just report
						$ov_id = 'error: ' . $ov_id->get_error_message();
					}
				}
			}
		}

		$this->audit_log('close', 'fiscal_year', $id, $fy, [
			'closing_voucher' => $cv_id,
			'opening_voucher' => $ov_id,
			'profit'          => $profit,
		]);
		wp_send_json_success([
			'closing_voucher_id' => $cv_id,
			'opening_voucher_id' => $ov_id,
			'profit'             => $profit,
		]);
	}

	/**
	 * Re-open a closed fiscal year. CEO/admin only. Marks status=OPEN
	 * and removes the period lock. The CV/OV vouchers are LEFT intact
	 * (audit trail). User can then run «برگشت سند» on them if needed.
	 */
	public function ajax_fy_reopen(){
		$this->check_perm('settings_manage');
		if (!current_user_can('manage_options')) wp_send_json_error('فقط مدیر سیستم می‌تواند سال مالی را بازکند', 403);
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		$all = $this->get_fy();
		$found = null;
		foreach ($all as $i => $f) {
			if (($f['id'] ?? '') === $id) {
				$found = $f;
				$all[$i]['status']     = 'OPEN';
				$all[$i]['reopenedAt'] = $this->fa_today();
				$all[$i]['reopenedBy'] = wp_get_current_user()->display_name;
				unset($all[$i]['closedAt'], $all[$i]['closedBy']);
				break;
			}
		}
		if (!$found) wp_send_json_error('سال مالی پیدا نشد', 404);
		update_option(self::OPT_FY, $all, false);
		// Remove related lock
		$locks = $this->get_locks();
		$locks = array_values(array_filter($locks, function($l) use ($id){ return ($l['fiscalYearId'] ?? '') !== $id; }));
		update_option(self::OPT_LOCKS, $locks, false);
		$this->audit_log('reopen', 'fiscal_year', $id, $found, null);
		wp_send_json_success();
	}

	/** Clear all explicit period locks for a fiscal year (CEO only) */
	public function ajax_lock_clear(){
		$this->check_perm('settings_manage');
		if (!current_user_can('manage_options')) wp_send_json_error('فقط مدیر سیستم', 403);
		$fy_id = sanitize_text_field((string)($_POST['fiscal_year_id'] ?? ''));
		if (!$fy_id) wp_send_json_error('fiscal_year_id', 400);
		$locks = $this->get_locks();
		$locks = array_values(array_filter($locks, function($l) use ($fy_id){ return ($l['fiscalYearId'] ?? '') !== $fy_id; }));
		update_option(self::OPT_LOCKS, $locks, false);
		$this->audit_log('clear', 'period_lock', $fy_id, null, null);
		wp_send_json_success();
	}

	/* ═════════════════════════════════════════════════════════════════
	 * ▼▼▼ Phase 6 — Advanced Reports ▼▼▼
	 * ═════════════════════════════════════════════════════════════════ */

	/**
	 * Cash Flow Statement (روش غیرمستقیم ساده‌شده).
	 * 3 سکشن:
	 *   - عملیاتی: تغییرات حساب‌های جاری (1xx, 3xx) + سود/زیان
	 *   - سرمایه‌گذاری: حساب‌های گروه 2 (دارایی‌های غیرجاری)
	 *   - تأمین مالی: حساب‌های گروه 4 (سرمایه/سود انباشته)
	 *
	 * استراتژی ساده: تمام حرکت حساب‌های نقد (a1_1_*) را در دوره جمع می‌کنیم
	 * (debit-credit = ورودی، credit-debit = خروجی) و آن‌ها را بر اساس
	 * طرف مقابل (حساب counter در voucher row) دسته‌بندی می‌کنیم.
	 */
	public function ajax_cash_flow(){
		$this->check_perm('reports_view');
		$from = sanitize_text_field((string)($_POST['from'] ?? ''));
		$to   = sanitize_text_field((string)($_POST['to']   ?? ''));
		$coa  = $this->get_coa();
		$by_id = [];
		foreach ($coa as $a) $by_id[(string)$a['id']] = $a;

		// Identify cash accounts (start with code "1" and under "موجودی نقد و بانک" — group a1_1)
		$cash_ids = [];
		foreach ($coa as $a) {
			$first = $this->to_en_digits(mb_substr((string)($a['code'] ?? ''), 0, 1));
			$parent = (string)($a['parentId'] ?? '');
			// Either explicit subsidiary of a1_1 OR top "موجودی نقد" general
			if ($parent === 'a1_1' || $a['id'] === 'a1_1' || $a['id'] === 'a1_1_1' || $a['id'] === 'a1_1_2') {
				$cash_ids[(string)$a['id']] = true;
			}
		}
		// Walk vouchers (FINALIZED only)
		$operating = []; $investing = []; $financing = [];
		$ops_total = 0; $inv_total = 0; $fin_total = 0; $net = 0;
		$opening = 0; $closing = 0;
		foreach ($this->get_vouchers() as $v) {
			if (($v['status'] ?? '') !== 'FINALIZED') continue;
			$d = (string)($v['date'] ?? '');
			// Opening balance: anything BEFORE $from
			$is_before = $from && $this->fa_date_cmp($d, $from) < 0;
			$in_period = (!$from || $this->fa_date_cmp($d, $from) >= 0) && (!$to || $this->fa_date_cmp($d, $to) <= 0);
			if (!$is_before && !$in_period) continue;
			$rows = (array)($v['rows'] ?? []);
			// Cash impact = sum of (debit - credit) on cash rows
			$cash_delta = 0;
			foreach ($rows as $r) {
				$aid = (string)($r['accountId'] ?? '');
				if (!isset($cash_ids[$aid])) continue;
				$cash_delta += (float)($r['debit'] ?? 0) - (float)($r['credit'] ?? 0);
			}
			if (abs($cash_delta) < 0.005) continue;
			if ($is_before) {
				$opening += $cash_delta;
				continue;
			}
			// $in_period: classify by counter accounts
			$counters = [];
			foreach ($rows as $r) {
				$aid = (string)($r['accountId'] ?? '');
				if (isset($cash_ids[$aid])) continue;
				$counters[] = $r;
			}
			$desc = (string)($v['description'] ?? ($v['voucherCode'] ?? ''));
			foreach ($counters as $r) {
				$aid = (string)($r['accountId'] ?? '');
				if (!isset($by_id[$aid])) continue;
				$code = (string)($by_id[$aid]['code'] ?? '');
				$first = $this->to_en_digits(mb_substr($code, 0, 1));
				$amount = (float)($r['credit'] ?? 0) - (float)($r['debit'] ?? 0);
				// If counter has credit, money came IN to cash (positive operating inflow if revenue)
				// We use the side of the counter row to know inflow/outflow sign
				$row = [
					'date'        => $d,
					'description' => $desc . ($r['description'] ? ' — ' . $r['description'] : ''),
					'account'     => (string)($by_id[$aid]['name'] ?? $aid),
					'amount'      => $amount,
					'voucher_id'  => $v['id'] ?? '',
				];
				if (in_array($first, ['1','3'], true)) {
					$operating[] = $row;
					$ops_total += $amount;
				} elseif ($first === '2') {
					$investing[] = $row;
					$inv_total += $amount;
				} elseif ($first === '4') {
					$financing[] = $row;
					$fin_total += $amount;
				} elseif (in_array($first, ['5','6'], true)) {
					// Revenue/expense → operating
					$operating[] = $row;
					$ops_total += $amount;
				}
			}
			$net += $cash_delta;
		}
		$closing = $opening + $net;
		wp_send_json_success([
			'from'        => $from,
			'to'          => $to,
			'opening_cash'=> round($opening, 2),
			'closing_cash'=> round($closing, 2),
			'net_change'  => round($net, 2),
			'operating'   => ['total' => round($ops_total, 2), 'items' => $operating],
			'investing'   => ['total' => round($inv_total, 2), 'items' => $investing],
			'financing'   => ['total' => round($fin_total, 2), 'items' => $financing],
		]);
	}

	/**
	 * Period-over-period comparison. Computes the trial balance, income
	 * statement, and balance sheet for TWO ranges and returns both
	 * side-by-side with delta + percent change.
	 */
	public function ajax_period_compare(){
		$this->check_perm('reports_view');
		$a_from = sanitize_text_field((string)($_POST['a_from'] ?? ''));
		$a_to   = sanitize_text_field((string)($_POST['a_to']   ?? ''));
		$b_from = sanitize_text_field((string)($_POST['b_from'] ?? ''));
		$b_to   = sanitize_text_field((string)($_POST['b_to']   ?? ''));
		$a = $this->compute_pl_range($a_from, $a_to);
		$b = $this->compute_pl_range($b_from, $b_to);
		$pct = function($curr, $prev){ if (abs($prev) < 0.005) return null; return round((($curr - $prev) / abs($prev)) * 100, 2); };
		wp_send_json_success([
			'a' => ['from'=>$a_from, 'to'=>$a_to, 'data' => $a],
			'b' => ['from'=>$b_from, 'to'=>$b_to, 'data' => $b],
			'delta' => [
				'revenue' => round($a['revenue'] - $b['revenue'], 2),
				'expense' => round($a['expense'] - $b['expense'], 2),
				'profit'  => round($a['profit']  - $b['profit'],  2),
			],
			'percent' => [
				'revenue' => $pct($a['revenue'], $b['revenue']),
				'expense' => $pct($a['expense'], $b['expense']),
				'profit'  => $pct($a['profit'],  $b['profit']),
			],
		]);
	}

	/** Helper: compute revenue/expense/profit between two Jalali dates */
	private function compute_pl_range($from, $to){
		$coa = $this->get_coa();
		$by_id = [];
		foreach ($coa as $a) $by_id[(string)$a['id']] = $a;
		$rev = 0; $exp = 0;
		foreach ($this->get_vouchers() as $v) {
			if (($v['status'] ?? '') !== 'FINALIZED') continue;
			if (in_array((string)($v['voucherType'] ?? ''), ['CV','OV'], true)) continue;
			$d = (string)($v['date'] ?? '');
			if ($from && $this->fa_date_cmp($d, $from) < 0) continue;
			if ($to   && $this->fa_date_cmp($d, $to)   > 0) continue;
			foreach ((array)($v['rows'] ?? []) as $r) {
				$aid = (string)($r['accountId'] ?? '');
				if (!isset($by_id[$aid])) continue;
				$first = $this->to_en_digits(mb_substr((string)$by_id[$aid]['code'], 0, 1));
				$dv = (float)($r['debit']  ?? 0);
				$cv = (float)($r['credit'] ?? 0);
				if ($first === '5')      $rev += $cv - $dv;
				elseif ($first === '6')  $exp += $dv - $cv;
			}
		}
		return ['revenue'=>round($rev,2), 'expense'=>round($exp,2), 'profit'=>round($rev-$exp,2)];
	}

	/**
	 * Budget vs Actual report (per cost center).
	 * Reads CostCenter.budget and aggregates actual spend by costCenterId
	 * in voucher rows over the supplied date range.
	 */
	public function ajax_budget_actual(){
		$this->check_perm('reports_view');
		$from = sanitize_text_field((string)($_POST['from'] ?? ''));
		$to   = sanitize_text_field((string)($_POST['to']   ?? ''));
		$ccs  = $this->get_costs();
		$coa  = $this->get_coa();
		$by_id = [];
		foreach ($coa as $a) $by_id[(string)$a['id']] = $a;
		$actuals = []; // cc_id → {debit, credit}
		foreach ($this->get_vouchers() as $v) {
			if (($v['status'] ?? '') !== 'FINALIZED') continue;
			$d = (string)($v['date'] ?? '');
			if ($from && $this->fa_date_cmp($d, $from) < 0) continue;
			if ($to   && $this->fa_date_cmp($d, $to)   > 0) continue;
			foreach ((array)($v['rows'] ?? []) as $r) {
				$cc = (string)($r['costCenterId'] ?? '');
				if ($cc === '') continue;
				$aid = (string)($r['accountId'] ?? '');
				$first = isset($by_id[$aid]) ? $this->to_en_digits(mb_substr((string)$by_id[$aid]['code'], 0, 1)) : '';
				// Count only expense rows (debit on group 6)
				if ($first !== '6') continue;
				$amt = (float)($r['debit'] ?? 0) - (float)($r['credit'] ?? 0);
				if (!isset($actuals[$cc])) $actuals[$cc] = 0;
				$actuals[$cc] += $amt;
			}
		}
		$out = [];
		foreach ($ccs as $cc) {
			$id  = (string)($cc['id'] ?? '');
			$bud = (float)($cc['budget'] ?? 0);
			$act = isset($actuals[$id]) ? round($actuals[$id], 2) : 0;
			$variance = round($bud - $act, 2);
			$used_pct = $bud > 0 ? round(($act / $bud) * 100, 2) : null;
			$out[] = [
				'id'         => $id,
				'name'       => (string)($cc['name'] ?? '—'),
				'code'       => (string)($cc['code'] ?? ''),
				'type'       => (string)($cc['type'] ?? ''),
				'budget'     => $bud,
				'actual'     => $act,
				'variance'   => $variance,
				'used_pct'   => $used_pct,
				'status'     => $used_pct === null ? 'no_budget' : ($used_pct > 100 ? 'over' : ($used_pct > 80 ? 'warning' : 'ok')),
			];
		}
		usort($out, function($a, $b){ return ($b['actual'] ?? 0) <=> ($a['actual'] ?? 0); });
		wp_send_json_success(['rows' => $out, 'from' => $from, 'to' => $to]);
	}

	/**
	 * Aging report with custom base date and custom bucket ranges.
	 * `base_date` defaults to today. `buckets` is array of integer day caps
	 * (e.g. [30, 60, 90, 999999] → 0-30, 30-60, 60-90, 90+).
	 */
	public function ajax_aging_custom(){
		$this->check_perm('reports_view');
		$base = sanitize_text_field((string)($_POST['base_date'] ?? '')) ?: $this->fa_today();
		$raw_b = (string)($_POST['buckets'] ?? '');
		$buckets = $raw_b ? array_map('intval', explode(',', $raw_b)) : [30, 60, 90, 99999];
		if (count($buckets) < 2) $buckets = [30, 60, 90, 99999];
		sort($buckets);

		// Convert base Jalali to Gregorian timestamp for diff math
		$base_g = $this->fa_to_gregorian_ts($base);

		// Aggregate per customer from receivables (project-based)
		$rec_by_customer = [];
		foreach ($this->build_receivables() as $r) {
			if ((float)$r['remain'] <= 0) continue;
			$cid = (int)$r['customerId'];
			if (!isset($rec_by_customer[$cid])) $rec_by_customer[$cid] = [
				'customerId' => $cid,
				'customerName' => (string)$r['customerName'],
				'totalRemain' => 0,
				'last_payment_ts' => 0,
			];
			$rec_by_customer[$cid]['totalRemain'] += (float)$r['remain'];
		}

		// Find last payment date for each customer from ledger
		if (class_exists('CPTT_Finance')) {
			global $wpdb;
			$tbl = CPTT_Finance::tbl_ledger();
			$rows = $wpdb->get_results("SELECT customer_id, MAX(date_at) AS last_at FROM $tbl WHERE customer_id IS NOT NULL AND type='income' GROUP BY customer_id");
			foreach ((array)$rows as $r) {
				$cid = (int)$r->customer_id;
				if (isset($rec_by_customer[$cid])) $rec_by_customer[$cid]['last_payment_ts'] = (int)$r->last_at;
			}
		}

		// Bucket each customer
		$result = [];
		$totals = [];
		foreach ($buckets as $b) $totals[$b] = 0;
		foreach ($rec_by_customer as $c) {
			$age_days = $c['last_payment_ts'] > 0
				? max(0, floor(($base_g - $c['last_payment_ts']) / 86400))
				: 9999; // No payment ever
			$bucket = $buckets[count($buckets) - 1];
			foreach ($buckets as $b) {
				if ($age_days <= $b) { $bucket = $b; break; }
			}
			$c['age_days'] = (int)$age_days;
			$c['bucket']   = $bucket;
			$c['last_payment_date'] = $c['last_payment_ts'] > 0 ? $this->fa_date_of($c['last_payment_ts']) : '—';
			$totals[$bucket] += $c['totalRemain'];
			unset($c['last_payment_ts']);
			$result[] = $c;
		}
		usort($result, function($a, $b){ return $b['totalRemain'] <=> $a['totalRemain']; });
		wp_send_json_success(['rows' => $result, 'base_date' => $base, 'buckets' => $buckets, 'totals' => $totals]);
	}

	/** Aging report for cheques (in_collection + awaiting_due) */
	public function ajax_aging_cheques(){
		$this->check_perm('reports_view');
		$base = sanitize_text_field((string)($_POST['base_date'] ?? '')) ?: $this->fa_today();
		$base_g = $this->fa_to_gregorian_ts($base);
		$buckets = [-30, 0, 30, 60, 99999]; // overdue (-30), due-this-month, 30, 60, 60+
		$out = []; $totals = [];
		foreach ($buckets as $b) $totals[$b] = 0;
		foreach ($this->get_cheques() as $c) {
			$status = (string)($c['status'] ?? '');
			if (!in_array($status, ['received','in_collection','awaiting_due','issued'], true)) continue;
			$due = (string)($c['dueDate'] ?? '');
			if (!$due) continue;
			$due_g = $this->fa_to_gregorian_ts($due);
			$diff_days = floor(($due_g - $base_g) / 86400);
			$bucket = $buckets[count($buckets) - 1];
			foreach ($buckets as $b) {
				if ($diff_days <= $b) { $bucket = $b; break; }
			}
			$out[] = [
				'id'         => (string)$c['id'],
				'number'     => (string)$c['number'],
				'bank'       => (string)$c['bank'],
				'amount'     => (float)$c['amount'],
				'dueDate'    => $due,
				'status'     => $status,
				'partyName'  => (string)($c['partyName'] ?? ''),
				'kind'       => (string)($c['kind'] ?? ''),
				'days_to_due'=> (int)$diff_days,
				'bucket'     => $bucket,
			];
			$totals[$bucket] += (float)$c['amount'];
		}
		usort($out, function($a, $b){ return $a['days_to_due'] <=> $b['days_to_due']; });
		wp_send_json_success(['rows' => $out, 'base_date' => $base, 'buckets' => $buckets, 'totals' => $totals]);
	}

	/**
	 * WIP (Work-In-Progress) report: per project, compare
	 *   billed_cost = sum of step.cost (what we charged)
	 *   completed_cost = sum of step.cost where step is "completed" (status=true OR settled)
	 *   collected = sum of step.paid (cash received)
	 *
	 * Indicates over/under billing per project.
	 */
	public function ajax_wip_report(){
		$this->check_perm('reports_view');
		$projects = get_posts(['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>-1,'no_found_rows'=>true]);
		if (!$projects) wp_send_json_success(['rows' => []]);
		$pids = array_map(function($p){ return (int)$p->ID; }, $projects);
		update_meta_cache('post', $pids);
		$out = [];
		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) continue;
			$billed = 0; $completed = 0; $collected = 0;
			foreach ($steps as $st) {
				$cost = (float)($st['cost'] ?? 0);
				$paid = (float)($st['paid'] ?? 0);
				$billed += $cost;
				$collected += $paid;
				$is_done = !empty($st['settled']) || !empty($st['done']) || (!empty($st['status']) && in_array((string)$st['status'], ['done','completed','settled'], true));
				if ($is_done) $completed += $cost;
				if (!empty($st['extra_finance']) && is_array($st['extra_finance'])) {
					foreach ($st['extra_finance'] as $ef) {
						$billed += (float)($ef['cost'] ?? 0);
						$collected += (float)($ef['paid'] ?? 0);
						if ($is_done) $completed += (float)($ef['cost'] ?? 0);
					}
				}
			}
			if ($billed <= 0) continue;
			$over_under = $billed - $completed; // >0 = over-billing (billed more than completed)
			$pct_complete = $billed > 0 ? round(($completed / $billed) * 100, 2) : 0;
			$pct_collected = $billed > 0 ? round(($collected / $billed) * 100, 2) : 0;
			$out[] = [
				'projectId'   => 'p' . (int)$p->ID,
				'projectTitle'=> (string)$p->post_title,
				'billed'      => round($billed, 2),
				'completed'   => round($completed, 2),
				'collected'   => round($collected, 2),
				'over_under'  => round($over_under, 2),
				'pct_complete'=> $pct_complete,
				'pct_collected'=> $pct_collected,
				'status'      => $over_under > 0 ? 'over_billed' : ($over_under < 0 ? 'under_billed' : 'matched'),
			];
		}
		usort($out, function($a, $b){ return abs($b['over_under']) <=> abs($a['over_under']); });
		wp_send_json_success(['rows' => $out]);
	}

	/**
	 * Bank Reconciliation: returns ledger entries for a treasury account
	 * in a date range, with their reconciled status. Frontend can mark
	 * entries as reconciled (saved in OPT_BANK_RECON).
	 */
	public function ajax_bank_recon(){
		$this->check_perm('reports_view');
		if (!class_exists('CPTT_Finance')) wp_send_json_error('finance missing', 500);
		$acc = (string)($_POST['account_id'] ?? '');
		if (!preg_match('/^t(\d+)$/', $acc, $m)) wp_send_json_error('bad acc', 400);
		$aid = (int)$m[1];
		$from = sanitize_text_field((string)($_POST['from'] ?? ''));
		$to   = sanitize_text_field((string)($_POST['to']   ?? ''));
		global $wpdb;
		$tbl = CPTT_Finance::tbl_ledger();
		$where = ['account_id=%d'];
		$params = [$aid];
		if ($from) {
			$from_ts = $this->fa_to_gregorian_ts($from);
			$where[] = 'date_at>=%d'; $params[] = $from_ts;
		}
		if ($to) {
			$to_ts = $this->fa_to_gregorian_ts($to) + 86399;
			$where[] = 'date_at<=%d'; $params[] = $to_ts;
		}
		$sql = "SELECT * FROM $tbl WHERE " . implode(' AND ', $where) . " ORDER BY date_at DESC";
		$rows = $wpdb->get_results($wpdb->prepare($sql, $params));
		$recon = get_option(self::OPT_BANK_RECON, []);
		if (!is_array($recon)) $recon = [];
		$recon_ids = isset($recon[$aid]) && is_array($recon[$aid]) ? $recon[$aid] : [];
		$out = [];
		$total_d = 0; $total_c = 0; $recon_d = 0; $recon_c = 0;
		foreach ((array)$rows as $r) {
			$d = (float)$r->amount * ($r->direction > 0 ? 1 : 0);
			$c = (float)$r->amount * ($r->direction < 0 ? 1 : 0);
			$is_recon = in_array((int)$r->id, array_map('intval', $recon_ids), true);
			$out[] = [
				'id'         => (int)$r->id,
				'date'       => $this->fa_date_of((int)$r->date_at),
				'type'       => (string)$r->type,
				'direction'  => (int)$r->direction,
				'amount'     => (float)$r->amount,
				'description'=> (string)$r->description,
				'ref_type'   => (string)$r->ref_type,
				'reconciled' => $is_recon,
			];
			$total_d += $d; $total_c += $c;
			if ($is_recon) { $recon_d += $d; $recon_c += $c; }
		}
		wp_send_json_success([
			'rows' => $out,
			'summary' => [
				'total_debit'  => round($total_d, 2),
				'total_credit' => round($total_c, 2),
				'recon_debit'  => round($recon_d, 2),
				'recon_credit' => round($recon_c, 2),
				'unrecon_balance' => round(($total_d - $total_c) - ($recon_d - $recon_c), 2),
			],
		]);
	}

	/** Save reconciliation marks (per account, ids that are reconciled) */
	public function ajax_bank_recon_save(){
		$this->check_perm('treasury_payment');
		$acc = (string)($_POST['account_id'] ?? '');
		if (!preg_match('/^t(\d+)$/', $acc, $m)) wp_send_json_error('bad acc', 400);
		$aid = (int)$m[1];
		$raw = (string)($_POST['ids'] ?? '');
		$ids = $raw ? array_map('intval', explode(',', $raw)) : [];
		$recon = get_option(self::OPT_BANK_RECON, []);
		if (!is_array($recon)) $recon = [];
		$recon[$aid] = array_values(array_unique($ids));
		update_option(self::OPT_BANK_RECON, $recon, false);
		$this->audit_log('save', 'bank_recon', (string)$aid, null, ['count' => count($ids)]);
		wp_send_json_success(['count' => count($ids)]);
	}

	/** Helper: Convert Jalali "YYYY/MM/DD" to Gregorian Unix timestamp (UTC) */
	private function fa_to_gregorian_ts($jalali){
		$en = preg_replace('/[^\d\/]/', '', $this->to_en_digits((string)$jalali));
		if (!preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $en, $m)) return (int)current_time('timestamp', true);
		if (!class_exists('CPTT_Core')) return (int)current_time('timestamp', true);
		$g = CPTT_Core::jalali_to_gregorian((int)$m[1], (int)$m[2], (int)$m[3]);
		return (int) mktime(0, 0, 0, (int)$g[1], (int)$g[2], (int)$g[0]);
	}

	/** Helper: add N Gregorian days to a Jalali date and return new Jalali date */
	private function fa_add_days($jalali, $days){
		$ts = $this->fa_to_gregorian_ts($jalali);
		$ts += (int)$days * 86400;
		return $this->fa_date_of($ts);
	}

	/* ═════════════════════════════════════════════════════════════════
	 * ▼▼▼ Phase 7 — Operational Polish ▼▼▼
	 * ═════════════════════════════════════════════════════════════════ */

	/* ─── KPI Drill-down ─── */

	/**
	 * Return underlying transactions for a clicked KPI on the dashboard.
	 * Params: `metric` (revenue|expense|profit|cash_in|cash_out|receivables|payables),
	 *         `from`, `to` (optional Jalali range).
	 */
	public function ajax_kpi_drilldown(){
		$this->check_perm('reports_view');
		$metric = sanitize_key((string)($_POST['metric'] ?? 'revenue'));
		$from = sanitize_text_field((string)($_POST['from'] ?? ''));
		$to   = sanitize_text_field((string)($_POST['to']   ?? ''));
		$coa  = $this->get_coa();
		$by_id = [];
		foreach ($coa as $a) $by_id[(string)$a['id']] = $a;
		$out = [];
		$total = 0;
		// metric → which voucher row group + side
		$conf = [
			'revenue' => ['groups' => ['5'], 'side' => 'credit'],
			'expense' => ['groups' => ['6'], 'side' => 'debit'],
			'cash_in' => ['groups' => ['1'], 'side' => 'debit',  'subgroup_prefix' => 'a1_1'],
			'cash_out'=> ['groups' => ['1'], 'side' => 'credit', 'subgroup_prefix' => 'a1_1'],
		];
		if ($metric === 'profit') {
			// Sum revenue minus expense per voucher
			foreach ($this->get_vouchers() as $v) {
				if (($v['status'] ?? '') !== 'FINALIZED') continue;
				if (in_array((string)($v['voucherType'] ?? ''), ['CV','OV'], true)) continue;
				$d = (string)($v['date'] ?? '');
				if ($from && $this->fa_date_cmp($d, $from) < 0) continue;
				if ($to   && $this->fa_date_cmp($d, $to)   > 0) continue;
				$rev_v = 0; $exp_v = 0;
				foreach ((array)($v['rows'] ?? []) as $r) {
					$aid = (string)($r['accountId'] ?? '');
					if (!isset($by_id[$aid])) continue;
					$first = $this->to_en_digits(mb_substr((string)$by_id[$aid]['code'], 0, 1));
					if ($first === '5') $rev_v += (float)($r['credit'] ?? 0) - (float)($r['debit'] ?? 0);
					if ($first === '6') $exp_v += (float)($r['debit']  ?? 0) - (float)($r['credit'] ?? 0);
				}
				$delta = $rev_v - $exp_v;
				if (abs($delta) < 0.005) continue;
				$total += $delta;
				$out[] = [
					'date'        => $d,
					'description' => (string)($v['description'] ?? ''),
					'voucher_id'  => (string)$v['id'],
					'voucher_code'=> (string)($v['voucherCode'] ?? ''),
					'amount'      => round($delta, 2),
				];
			}
		} elseif (isset($conf[$metric])) {
			$c = $conf[$metric];
			foreach ($this->get_vouchers() as $v) {
				if (($v['status'] ?? '') !== 'FINALIZED') continue;
				$d = (string)($v['date'] ?? '');
				if ($from && $this->fa_date_cmp($d, $from) < 0) continue;
				if ($to   && $this->fa_date_cmp($d, $to)   > 0) continue;
				foreach ((array)($v['rows'] ?? []) as $r) {
					$aid = (string)($r['accountId'] ?? '');
					if (!isset($by_id[$aid])) continue;
					$first = $this->to_en_digits(mb_substr((string)$by_id[$aid]['code'], 0, 1));
					if (!in_array($first, $c['groups'], true)) continue;
					if (!empty($c['subgroup_prefix'])) {
						$parent = (string)($by_id[$aid]['parentId'] ?? '');
						if (strpos($parent, $c['subgroup_prefix']) !== 0 && $aid !== $c['subgroup_prefix']) continue;
					}
					$amt = $c['side'] === 'debit'
						? ((float)($r['debit']  ?? 0) - (float)($r['credit'] ?? 0))
						: ((float)($r['credit'] ?? 0) - (float)($r['debit']  ?? 0));
					if ($amt <= 0) continue;
					$total += $amt;
					$out[] = [
						'date'        => $d,
						'description' => (string)($v['description'] ?? '') . ($r['description'] ? ' — ' . $r['description'] : ''),
						'account'     => (string)$by_id[$aid]['name'],
						'voucher_id'  => (string)$v['id'],
						'voucher_code'=> (string)($v['voucherCode'] ?? ''),
						'amount'      => round($amt, 2),
					];
				}
			}
		} elseif ($metric === 'receivables') {
			foreach ($this->build_receivables() as $r) {
				if ((float)$r['remain'] <= 0) continue;
				$total += (float)$r['remain'];
				$out[] = [
					'date'        => '—',
					'description' => $r['projectTitle'] . ' — ' . $r['customerName'],
					'account'     => 'بدهکاران تجاری',
					'amount'      => round((float)$r['remain'], 2),
				];
			}
		} elseif ($metric === 'payables') {
			foreach ($this->get_payables_recomputed() as $p) {
				if ((float)($p['remain'] ?? 0) <= 0) continue;
				$total += (float)$p['remain'];
				$out[] = [
					'date'        => (string)($p['dueDate'] ?? '—'),
					'description' => $p['partyName'] . ' — ' . (string)($p['description'] ?? ''),
					'account'     => 'بستانکاران تجاری',
					'amount'      => round((float)$p['remain'], 2),
				];
			}
		}
		usort($out, function($a, $b){ return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')); });
		wp_send_json_success(['metric'=>$metric, 'total'=>round($total, 2), 'rows'=>$out, 'count'=>count($out)]);
	}

	/* ─── Voucher Batch Entry ─── */

	/**
	 * Save multiple vouchers in one call. Useful for bulk import.
	 * Validates each independently; returns per-voucher success/error.
	 */
	public function ajax_voucher_batch(){
		$this->check_perm('voucher_create');
		$raw = isset($_POST['vouchers']) ? wp_unslash($_POST['vouchers']) : '';
		$vs  = json_decode($raw, true);
		if (!is_array($vs) || empty($vs)) wp_send_json_error('ساختار نامعتبر یا خالی', 400);
		$results = [];
		$success = 0; $failed = 0;
		foreach ($vs as $i => $v) {
			$idx = $i + 1;
			if (!is_array($v)) { $results[] = ['idx'=>$idx,'ok'=>false,'message'=>'ساختار نامعتبر']; $failed++; continue; }
			// Validate
			$check = $this->validate_voucher_rows($v['rows'] ?? []);
			if (!$check['ok']) { $results[] = ['idx'=>$idx,'ok'=>false,'message'=>$check['message']]; $failed++; continue; }
			// Lock check
			$date = (string)($v['date'] ?? $this->fa_today());
			if (!current_user_can('manage_options')) {
				$lock = $this->is_date_locked($date);
				if ($lock['locked']) { $results[] = ['idx'=>$idx,'ok'=>false,'message'=>'دوره قفل: ' . $lock['reason']]; $failed++; continue; }
			}
			// Compose
			$voucher = wp_parse_args($v, [
				'id'              => 'v_' . time() . '_' . wp_generate_password(4, false, false) . '_' . $i,
				'voucherNumber'   => time() + $i,
				'voucherCode'     => $this->next_voucher_code((string)($v['voucherType'] ?? 'JV')),
				'voucherType'     => 'JV',
				'date'            => $date,
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
				'sourceType'      => 'batch_import',
				'refType'         => 'batch',
				'refId'           => 0,
			]);
			$this->insert_voucher_into_table($voucher);
			$this->audit_log('create', 'voucher', $voucher['id'], null, $voucher);
		$this->fire_webhook('voucher.created', ['id'=>$voucher['id'],'code'=>$voucher['voucherCode'] ?? '','type'=>$voucher['voucherType'] ?? '','status'=>$voucher['status'] ?? '']);
			$results[] = ['idx'=>$idx,'ok'=>true,'id'=>$voucher['id'],'code'=>$voucher['voucherCode']];
			$success++;
		}
		wp_send_json_success(['success'=>$success, 'failed'=>$failed, 'results'=>$results]);
	}

	/* ─── Unfinalize Voucher (CEO only) ─── */

	public function ajax_voucher_unfinalize(){
		$this->check_perm('voucher_approve');
		if (!current_user_can('manage_options')) wp_send_json_error('فقط مدیر سیستم می‌تواند سند نهایی را بازکند', 403);
		$id   = sanitize_text_field((string)($_POST['id'] ?? ''));
		$note = $this->safe_text((string)($_POST['note'] ?? ''));
		if (!$id) wp_send_json_error('invalid', 400);
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_VOUCHERS;
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%s", $id), ARRAY_A);
		if (!$row) wp_send_json_error('سند پیدا نشد', 404);
		if (($row['status'] ?? '') !== 'FINALIZED') wp_send_json_error('فقط سند نهایی قابل بازکردن است', 400);
		// Reject if voucher date is in a CLOSED FY
		if (!empty($row['date_fa'])) {
			$lock = $this->is_date_locked((string)$row['date_fa']);
			if ($lock['locked']) wp_send_json_error('سند در دوره‌ی بسته است: ' . $lock['reason'], 423);
		}
		$wpdb->update($tbl, ['status' => 'DRAFT', 'approved_by_ceo' => '', 'approved_by_manager' => '', 'approved_by_accountant' => ''], ['id' => $id]);
		$this->audit_log('unfinalize', 'voucher', $id, $row, ['note' => $note]);
		wp_send_json_success();
	}

	/* ─── Recurring Transactions ─── */

	private function get_recurring(){ $v = get_option(self::OPT_RECURRING, []); return is_array($v) ? $v : []; }
	private function save_recurring($a){ update_option(self::OPT_RECURRING, $a, false); }

	/**
	 * Recurring item shape:
	 *  id, name, type (INCOME|EXPENSE), amount, currency, category,
	 *  projectId, costCenterId, accountId, bankAccountId, description,
	 *  frequency (daily|weekly|monthly|yearly), interval (int),
	 *  startDate (Jalali), endDate (optional), nextRun (Jalali),
	 *  lastRun (Jalali), runsCount, isActive
	 */
	public function ajax_recurring_list(){
		$this->check_perm('reports_view');
		wp_send_json_success(['rows' => $this->get_recurring()]);
	}

	public function ajax_recurring_save(){
		$this->check_perm('treasury_payment');
		$raw = isset($_POST['item']) ? wp_unslash($_POST['item']) : '';
		$it = json_decode($raw, true);
		if (!is_array($it) || empty($it['name']) || empty($it['amount'])) wp_send_json_error('invalid', 400);
		$id = !empty($it['id']) ? (string)$it['id'] : ('rec_' . time() . '_' . wp_generate_password(4, false, false));
		$row = [
			'id'            => $id,
			'name'          => $this->safe_text((string)$it['name']),
			'type'          => ($it['type'] ?? 'EXPENSE') === 'INCOME' ? 'INCOME' : 'EXPENSE',
			'amount'        => (float)$it['amount'],
			'category'      => sanitize_text_field((string)($it['category'] ?? '')),
			'projectId'     => sanitize_text_field((string)($it['projectId'] ?? '')),
			'costCenterId'  => sanitize_text_field((string)($it['costCenterId'] ?? '')),
			'bankAccountId' => sanitize_text_field((string)($it['bankAccountId'] ?? '')),
			'description'   => $this->safe_text((string)($it['description'] ?? '')),
			'frequency'     => in_array(($it['frequency'] ?? 'monthly'), ['daily','weekly','monthly','yearly'], true) ? $it['frequency'] : 'monthly',
			'interval'      => max(1, (int)($it['interval'] ?? 1)),
			'startDate'     => sanitize_text_field((string)($it['startDate'] ?? $this->fa_today())),
			'endDate'       => sanitize_text_field((string)($it['endDate'] ?? '')),
			'nextRun'       => sanitize_text_field((string)($it['nextRun'] ?? ($it['startDate'] ?? $this->fa_today()))),
			'lastRun'       => sanitize_text_field((string)($it['lastRun'] ?? '')),
			'runsCount'     => (int)($it['runsCount'] ?? 0),
			'isActive'      => !empty($it['isActive']) ? 1 : 0,
		];
		$all = $this->get_recurring();
		$existing = null;
		foreach ($all as $i => $r) if ((string)$r['id'] === $id) { $existing = $r; $all[$i] = $row; break; }
		if (!$existing) $all[] = $row;
		$this->save_recurring($all);
		$this->audit_log($existing ? 'update' : 'create', 'recurring', $id, $existing, $row);
		wp_send_json_success(['id' => $id]);
	}

	public function ajax_recurring_delete(){
		$this->check_perm('treasury_payment');
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		$all = $this->get_recurring();
		$before = null;
		foreach ($all as $r) if ((string)$r['id'] === $id) { $before = $r; break; }
		$all = array_values(array_filter($all, function($r) use ($id){ return (string)$r['id'] !== $id; }));
		$this->save_recurring($all);
		if ($before) $this->audit_log('delete', 'recurring', $id, $before, null);
		wp_send_json_success();
	}

	/** Manually trigger a recurring item right now (admin testing) */
	public function ajax_recurring_run_now(){
		$this->check_perm('treasury_payment');
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		$all = $this->get_recurring();
		foreach ($all as $i => $r) {
			if ((string)$r['id'] !== $id) continue;
			$res = $this->execute_recurring_item($r);
			if (is_wp_error($res)) wp_send_json_error($res->get_error_message(), 500);
			// Update state
			$all[$i]['lastRun']   = $this->fa_today();
			$all[$i]['runsCount'] = (int)($r['runsCount'] ?? 0) + 1;
			$all[$i]['nextRun']   = $this->next_run_after($r);
			$this->save_recurring($all);
			wp_send_json_success(['ledger_id' => $res, 'next_run' => $all[$i]['nextRun']]);
		}
		wp_send_json_error('not found', 404);
	}

	private function next_run_after($rec){
		$base = $rec['nextRun'] ?: $rec['startDate'] ?: $this->fa_today();
		$int  = max(1, (int)$rec['interval']);
		$days = 30;
		if ($rec['frequency'] === 'daily')   $days = 1;
		if ($rec['frequency'] === 'weekly')  $days = 7;
		if ($rec['frequency'] === 'monthly') $days = 30;
		if ($rec['frequency'] === 'yearly')  $days = 365;
		return $this->fa_add_days($base, $days * $int);
	}

	/** Execute a single recurring item — inserts ledger + voucher. */
	private function execute_recurring_item($rec){
		if (!class_exists('CPTT_Finance')) return new WP_Error('finance_missing', 'CPTT_Finance unavailable');
		$amount = (float)($rec['amount'] ?? 0);
		if ($amount <= 0) return new WP_Error('bad_amount', 'مبلغ صفر');
		$type   = ($rec['type'] ?? 'EXPENSE') === 'INCOME' ? 'income' : 'expense';
		$bank_id = 0;
		if (!empty($rec['bankAccountId']) && preg_match('/^t(\d+)$/', (string)$rec['bankAccountId'], $m)) $bank_id = (int)$m[1];
		if (!$bank_id) $bank_id = CPTT_Finance::default_cash_account_id();
		$pid = 0;
		if (!empty($rec['projectId']) && preg_match('/^p(\d+)$/', (string)$rec['projectId'], $mp)) $pid = (int)$mp[1];
		$ledger_id = CPTT_Finance::ledger_insert([
			'account_id' => $bank_id,
			'type'       => $type,
			'direction'  => $type === 'income' ? 1 : -1,
			'amount'     => $amount,
			'project_id' => $pid,
			'description'=> 'تراکنش تکرارشونده: ' . $rec['name'] . ($rec['description'] ? ' — ' . $rec['description'] : ''),
			'date_at'    => (int)current_time('timestamp', true),
			'ref_type'   => 'erp_recurring',
		]);
		$tmap = $this->map_treasury_to_coa($bank_id);
		if ($type === 'income') {
			$rows = [
				['accountId'=>$tmap['id'], 'accountName'=>$tmap['name'], 'debit'=>$amount, 'credit'=>0, 'description'=>'دریافت تکرارشونده'],
				['accountId'=>$this->map_account('revenue_default'), 'accountName'=>$this->coa_name($this->map_account('revenue_default')), 'debit'=>0, 'credit'=>$amount, 'description'=>$rec['name']],
			];
			$vtype = 'RV';
		} else {
			$rows = [
				['accountId'=>$this->map_account('expense_default'), 'accountName'=>$this->coa_name($this->map_account('expense_default')), 'debit'=>$amount, 'credit'=>0, 'description'=>$rec['name']],
				['accountId'=>$tmap['id'], 'accountName'=>$tmap['name'], 'debit'=>0, 'credit'=>$amount, 'description'=>'پرداخت تکرارشونده'],
			];
			$vtype = 'PV';
		}
		$this->gen_voucher([
			'type'        => $vtype,
			'description' => ($type === 'income' ? 'درآمد تکرارشونده: ' : 'هزینه تکرارشونده: ') . $rec['name'],
			'source_type' => 'recurring',
			'ref_type'    => 'erp_recurring',
			'ref_id'      => (int)$ledger_id,
			'rows'        => $rows,
		]);
		return $ledger_id;
	}

	/* ─── Cheque-Book Numbering ─── */

	private function get_chequebooks(){ $v = get_option(self::OPT_CHEQUEBOOKS, []); return is_array($v) ? $v : []; }
	private function save_chequebooks($a){ update_option(self::OPT_CHEQUEBOOKS, $a, false); }

	public function ajax_chequebook_list(){
		$this->check_perm('treasury_view');
		wp_send_json_success(['rows' => $this->get_chequebooks()]);
	}

	/**
	 * Cheque-book: id, name, bank, accountNumber (treasury), seriesPrefix,
	 * startNumber, endNumber, currentNumber, sayyadiSeries, isActive.
	 */
	public function ajax_chequebook_save(){
		$this->check_perm('treasury_payment');
		$raw = isset($_POST['item']) ? wp_unslash($_POST['item']) : '';
		$it = json_decode($raw, true);
		if (!is_array($it) || empty($it['name'])) wp_send_json_error('invalid', 400);
		$id = !empty($it['id']) ? (string)$it['id'] : ('cb_' . time());
		$row = [
			'id'            => $id,
			'name'          => $this->safe_text((string)$it['name']),
			'bank'          => sanitize_text_field((string)($it['bank'] ?? '')),
			'treasuryId'    => sanitize_text_field((string)($it['treasuryId'] ?? '')),
			'seriesPrefix'  => sanitize_text_field((string)($it['seriesPrefix'] ?? '')),
			'sayyadiSeries' => sanitize_text_field((string)($it['sayyadiSeries'] ?? '')),
			'startNumber'   => (int)($it['startNumber'] ?? 1),
			'endNumber'     => (int)($it['endNumber']   ?? 50),
			'currentNumber' => (int)($it['currentNumber'] ?? ($it['startNumber'] ?? 1)),
			'isActive'      => !empty($it['isActive']) ? 1 : 0,
		];
		$all = $this->get_chequebooks();
		$existing = null;
		foreach ($all as $i => $r) if ((string)$r['id'] === $id) { $existing = $r; $all[$i] = $row; break; }
		if (!$existing) $all[] = $row;
		$this->save_chequebooks($all);
		$this->audit_log($existing ? 'update' : 'create', 'chequebook', $id, $existing, $row);
		wp_send_json_success(['id'=>$id]);
	}

	public function ajax_chequebook_delete(){
		$this->check_perm('treasury_payment');
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		$all = $this->get_chequebooks();
		$before = null;
		foreach ($all as $r) if ((string)$r['id'] === $id) { $before = $r; break; }
		$all = array_values(array_filter($all, function($r) use ($id){ return (string)$r['id'] !== $id; }));
		$this->save_chequebooks($all);
		if ($before) $this->audit_log('delete', 'chequebook', $id, $before, null);
		wp_send_json_success();
	}

	/**
	 * Return the next available cheque number for a given chequebook.
	 * Also checks for sayyadi duplicates across the existing cheques.
	 */
	public function ajax_chequebook_next(){
		$this->check_perm('treasury_view');
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		$all = $this->get_chequebooks();
		foreach ($all as $cb) {
			if ((string)$cb['id'] !== $id) continue;
			$next = (int)$cb['currentNumber'];
			if ($next > (int)$cb['endNumber']) wp_send_json_error('دفترچه چک تمام شده است', 400);
			// Check existing cheques for this number → suggest skipping duplicates
			$exists = false;
			foreach ($this->get_cheques() as $ch) {
				if ((string)$ch['bank'] === (string)$cb['bank'] && (string)$ch['number'] === (string)$next) {
					$exists = true; break;
				}
			}
			wp_send_json_success([
				'next_number'   => (string)$next,
				'sayyadi_hint'  => (string)$cb['sayyadiSeries'],
				'is_duplicate'  => $exists,
				'remaining'     => max(0, (int)$cb['endNumber'] - $next + 1),
			]);
		}
		wp_send_json_error('chequebook not found', 404);
	}

	/* ─── WP-Cron ─── */

	public function maybe_schedule_crons(){
		if (!wp_next_scheduled('cpttf_erp_daily_cron'))  wp_schedule_event(time() + 60, 'daily',  'cpttf_erp_daily_cron');
		if (!wp_next_scheduled('cpttf_erp_hourly_cron')) wp_schedule_event(time() + 60, 'hourly', 'cpttf_erp_hourly_cron');
	}

	/**
	 * Daily cron tasks:
	 *  - Recompute overdue status for cheques, installments, payables
	 *  - Send reminders (Bale + SMS) for things due in 7/3/1 days
	 *  - Prune audit_log older than 1 year
	 */
	public function cron_daily_tasks(){
		$log = get_option(self::OPT_CRON_LOG, []);
		if (!is_array($log)) $log = [];

		// Recompute (read-only, just touch caches)
		$this->get_installment_plans_recomputed();
		$this->get_payables_recomputed();
		$log['recompute_overdue'] = current_time('mysql');

		// Reminders
		$reminders_sent = $this->send_due_reminders();
		$log['reminders_sent'] = $reminders_sent;
		$log['reminders_at']   = current_time('mysql');

		// Audit prune
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_AUDIT;
		$one_year_ago = time() - (365 * 86400);
		$pruned = $wpdb->query($wpdb->prepare("DELETE FROM $tbl WHERE created_at < %d", $one_year_ago));
		$log['audit_pruned'] = (int)$pruned;
		$log['daily_at']     = current_time('mysql');
		update_option(self::OPT_CRON_LOG, $log, false);
	}

	public function cron_hourly_tasks(){
		// Run recurring transactions whose nextRun ≤ today
		$today = $this->fa_today();
		$all = $this->get_recurring();
		$ran = 0;
		foreach ($all as $i => $rec) {
			if (empty($rec['isActive'])) continue;
			if (!empty($rec['endDate']) && $this->fa_date_cmp($today, $rec['endDate']) > 0) continue;
			$next = (string)($rec['nextRun'] ?? $rec['startDate'] ?? $today);
			if ($this->fa_date_cmp($today, $next) < 0) continue;
			$res = $this->execute_recurring_item($rec);
			if (!is_wp_error($res)) {
				$all[$i]['lastRun']   = $today;
				$all[$i]['runsCount'] = (int)($rec['runsCount'] ?? 0) + 1;
				$all[$i]['nextRun']   = $this->next_run_after($all[$i]);
				$ran++;
			}
		}
		if ($ran > 0) $this->save_recurring($all);
		$log = get_option(self::OPT_CRON_LOG, []);
		if (!is_array($log)) $log = [];
		$log['recurring_ran'] = $ran;
		$log['hourly_at']     = current_time('mysql');
		update_option(self::OPT_CRON_LOG, $log, false);
	}

	/** Send reminders for cheques, installments, payables due soon */
	private function send_due_reminders(){
		$sent = 0;
		$today = $this->fa_today();
		$today_ts = $this->fa_to_gregorian_ts($today);
		// Cheques due in 7/3/1 days
		foreach ($this->get_cheques() as $c) {
			if (!in_array((string)$c['status'], ['in_collection','received','awaiting_due','issued'], true)) continue;
			$due_ts = $this->fa_to_gregorian_ts((string)$c['dueDate']);
			$diff = (int) floor(($due_ts - $today_ts) / 86400);
			if (!in_array($diff, [7, 3, 1, 0], true)) continue;
			$msg = '⏰ یادآور: چک شماره ' . $c['number'] . ' بانک ' . $c['bank'] . ' در ' . ($diff > 0 ? $diff . ' روز' : 'امروز') . ' سررسید است (مبلغ: ' . number_format((float)$c['amount']) . ').';
			if (class_exists('CPTT_Bale')) {
				// Send to all financial managers
				$users = get_users(['role__in' => ['cptt_financial_manager','administrator']]);
				foreach ($users as $u) {
					CPTT_Bale::notify_via_bale((int)$u->ID, $msg, 'cheque_due', 0);
					$sent++;
				}
			}
		}
		// Installments overdue
		foreach ($this->get_installment_plans_recomputed() as $plan) {
			foreach ((array)($plan['installments'] ?? []) as $inst) {
				if (($inst['status'] ?? '') !== 'overdue') continue;
				if (class_exists('CPTT_Bale')) {
					$users = get_users(['role__in' => ['cptt_financial_manager','administrator']]);
					$msg = '⏰ قسط معوق: ' . $plan['customerName'] . ' — قسط ' . $inst['no'] . ' به مبلغ ' . number_format((float)$inst['amount']);
					foreach ($users as $u) {
						CPTT_Bale::notify_via_bale((int)$u->ID, $msg, 'installment_overdue', 0);
						$sent++;
					}
				}
			}
		}
		return $sent;
	}

	public function ajax_cron_run_now(){
		$this->check_perm('settings_manage');
		if (!current_user_can('manage_options')) wp_send_json_error('فقط مدیر سیستم', 403);
		$which = sanitize_key((string)($_POST['which'] ?? 'daily'));
		if ($which === 'hourly') $this->cron_hourly_tasks();
		else $this->cron_daily_tasks();
		wp_send_json_success(['log' => get_option(self::OPT_CRON_LOG, [])]);
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
			['id'=>'a1_1_3','code'=>'۱۰۱۰۰۳','name'=>'اسناد دریافتنی (چک‌های دریافتی)','type'=>'subsidiary','parentId'=>'a1_1','balance'=>0],
			['id'=>'a1_2_1','code'=>'۱۰۲۰۰۱','name'=>'بدهکاران تجاری (مشتریان پروژه‌ها)','type'=>'subsidiary','parentId'=>'a1_2','balance'=>0],

			// Liabilities (Phase 1)
			['id'=>'a3_1','code'=>'۳۰۱','name'=>'حساب‌ها و اسناد پرداختنی','type'=>'general','parentId'=>'a3','balance'=>0],
			['id'=>'a3_1_1','code'=>'۳۰۱۰۰۱','name'=>'بستانکاران تجاری (پیمانکاران/تامین‌کنندگان)','type'=>'subsidiary','parentId'=>'a3_1','balance'=>0],
			['id'=>'a3_1_2','code'=>'۳۰۱۰۰۲','name'=>'اسناد پرداختنی (چک‌های پرداختی)','type'=>'subsidiary','parentId'=>'a3_1','balance'=>0],
			['id'=>'a3_1_3','code'=>'۳۰۱۰۰۳','name'=>'بدهی به کارشناسان','type'=>'subsidiary','parentId'=>'a3_1','balance'=>0],

			// Revenue (Phase 1)
			['id'=>'a5_1','code'=>'۵۰۱','name'=>'درآمد ارائه خدمات','type'=>'general','parentId'=>'a5','balance'=>0],
			['id'=>'a5_2','code'=>'۵۰۲','name'=>'سایر درآمدها','type'=>'general','parentId'=>'a5','balance'=>0],
		];
	}

	private function get_companies(){ $v=get_option(self::OPT_COMPANIES); return is_array($v)&&$v ? $v : $this->default_companies(); }
	private function get_branches(){  $v=get_option(self::OPT_BRANCHES);  return is_array($v)&&$v ? $v : $this->default_branches(); }
	private function get_fy(){        $v=get_option(self::OPT_FY);        return is_array($v)&&$v ? $v : $this->default_fiscal_years(); }
	private function get_costs(){     $v=get_option(self::OPT_COSTS, []); return is_array($v) ? $v : []; }
	private function get_coa(){       $v=get_option(self::OPT_COA);       return is_array($v)&&$v ? $v : $this->default_coa(); }
	private function get_vouchers(){
		// Phase 3: prefer table; fall back to option if table missing
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_VOUCHERS;
		$exists = (int)$wpdb->get_var("SHOW TABLES LIKE '$tbl'");
		if ($exists) return $this->read_vouchers_from_table();
		return $this->get_vouchers_legacy();
	}
	private function get_locks(){     $v=get_option(self::OPT_LOCKS, []); return is_array($v) ? $v : []; }

	/* ─────────────────────────────────────────────
	 * Phase 2: Account Mapping (configurable defaults)
	 * Stored at OPT_ACCOUNT_MAPPING. Each key maps a logical "purpose"
	 * to a real CoA node id. gen_voucher() consults this for every row.
	 * ───────────────────────────────────────────── */
	private function default_account_mapping(){
		return [
			'cash_default'         => 'a1_1_2', // صندوق پیش‌فرض (برای CASH treasury)
			'bank_default'         => 'a1_1_1', // بانک پیش‌فرض (برای BANK treasury)
			'receivable_default'   => 'a1_2_1', // بدهکاران تجاری (مشتری)
			'notes_receivable'     => 'a1_1_3', // اسناد دریافتنی (چک‌های دریافتی)
			'payable_default'      => 'a3_1_1', // بستانکاران تجاری (پیمانکار/تامین)
			'notes_payable'        => 'a3_1_2', // اسناد پرداختنی (چک‌های پرداختی)
			'expert_payable'       => 'a3_1_3', // بدهی به کارشناسان
			'revenue_default'      => 'a5_1',   // درآمد خدمات
			'other_income'         => 'a5_2',   // سایر درآمدها
			'expense_default'      => 'a6_2',   // هزینه‌های اداری
			'expert_payroll'       => 'a6_1',   // دستمزد کارشناس
			'transfer_clearing'    => '',       // اختیاری: حساب کلیرینگ بین‌حسابی
			// Phase 5: year-end closing
			'retained_earnings'    => 'a4_3',   // سود (زیان) انباشته
			'profit_loss_summary'  => 'a4_3',   // خلاصه سود/زیان (در نبود، از retained_earnings استفاده می‌کند)
		];
	}
	public function get_account_mapping(){
		$saved = get_option(self::OPT_ACCOUNT_MAPPING, []);
		if (!is_array($saved)) $saved = [];
		return array_merge($this->default_account_mapping(), $saved);
	}
	/**
	 * Resolve a logical purpose to a CoA node id.
	 * Falls back to default if not configured. Returns '' if no fallback.
	 */
	public function map_account($purpose){
		$map = $this->get_account_mapping();
		return isset($map[$purpose]) ? (string)$map[$purpose] : '';
	}

	/**
	 * Returns CoA tree with each node's `balance` populated from real
	 * voucher data (debit-credit, rolled up to parents). Used by the
	 * bootstrap and the ChartOfAccounts page so the user always sees
	 * live numbers.
	 */
	public function get_coa_with_balances(){
		$coa = $this->get_coa();
		$vouchers = $this->get_vouchers();
		$by_id = [];
		foreach ($coa as $n) $by_id[(string)$n['id']] = $n;
		$chain = [];
		foreach ($coa as $n) {
			$ch = []; $cur = $n;
			while ($cur) {
				$ch[] = (string)$cur['id'];
				$pid  = $cur['parentId'] ?? null;
				$cur  = $pid && isset($by_id[$pid]) ? $by_id[$pid] : null;
			}
			$chain[(string)$n['id']] = $ch;
		}
		$agg = [];
		foreach ($vouchers as $v) {
			$st = (string)($v['status'] ?? '');
			if (!in_array($st, ['FINALIZED','MANAGER_APPROVED','ACCOUNTANT_APPROVED'], true)) continue;
			foreach ((array)($v['rows'] ?? []) as $r) {
				$aid = (string)($r['accountId'] ?? '');
				if (!$aid || !isset($chain[$aid])) continue;
				$d = (float)($r['debit']  ?? 0);
				$c = (float)($r['credit'] ?? 0);
				foreach ($chain[$aid] as $cid) {
					if (!isset($agg[$cid])) $agg[$cid] = 0.0;
					$agg[$cid] += ($d - $c);
				}
			}
		}
		// Apply
		$out = [];
		foreach ($coa as $n) {
			$n['balance'] = isset($agg[(string)$n['id']]) ? round($agg[(string)$n['id']], 2) : 0;
			$out[] = $n;
		}
		return $out;
	}

	/* ── Per-treasury-account → CoA subsidiary mapping ──
	 * Stored as { "<treasury_int_id>": "<coa_id>" }. Optional override.
	 * If not set, map_treasury_to_coa() falls back to bank_default/cash_default.
	 */
	private function get_treasury_coa_map(){
		$v = get_option(self::OPT_TREASURY_COA_MAP, []);
		return is_array($v) ? $v : [];
	}

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
		$projects = get_posts(['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>-1, 'no_found_rows'=>true]);
		if (!$projects) return [];
		// Phase 3: warm-load all post_meta in ONE query (kills N+1)
		$pids = array_map(function($p){ return (int)$p->ID; }, $projects);
		update_meta_cache('post', $pids);
		// Pre-collect customer IDs and bulk-load users (one query)
		$cids = [];
		foreach ($projects as $p) {
			$cid = (int) get_post_meta($p->ID, '_cptt_client_user_id', true);
			if ($cid) $cids[$cid] = true;
		}
		$users_map = [];
		if ($cids) {
			$users = get_users(['include' => array_keys($cids), 'fields' => ['ID','display_name']]);
			foreach ($users as $u) $users_map[(int)$u->ID] = $u;
		}
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
			if ($cid && isset($users_map[$cid])) {
				$u = $users_map[$cid];
				$customer_name = (string)$u->display_name;
				// Roles still need a separate call for accuracy, but only once per unique cid
				static $roles_cache = [];
				if (!isset($roles_cache[$cid])) {
					$wpu = get_userdata($cid);
					$roles_cache[$cid] = $wpu && !empty($wpu->roles) ? (string)$wpu->roles[0] : '';
				}
				$customer_role = $roles_cache[$cid];
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
		$projects = get_posts(['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>-1, 'orderby'=>'date', 'order'=>'DESC', 'no_found_rows'=>true]);
		if (!$projects) return [];
		// Phase 3: warm post_meta cache to kill N+1
		$pids = array_map(function($p){ return (int)$p->ID; }, $projects);
		update_meta_cache('post', $pids);
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
		// Phase 4: use centralised role resolver
		$role = $this->current_role_key();

		$es = $this->build_experts_and_steps();
		$payload = [
			'companies'         => $this->get_companies(),
			'branches'          => $this->get_branches(),
			'currencies'        => [],
			'fiscalYears'       => $this->get_fy(),
			'costCenters'       => $this->get_costs(),
			'accounts'          => $this->get_coa_with_balances(),
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
			// Phase 1 additions:
			'partyAccounts'     => $this->build_party_accounts(),
			'rolePermissions'   => $this->get_role_permissions(),
			// Phase 2: Account Mapping
			'accountMapping'    => $this->get_account_mapping(),
			'treasuryCoaMap'    => $this->get_treasury_coa_map(),
			'mappingPurposes'   => $this->account_mapping_purpose_labels(),
			// Phase 2 additions:
			'cheques'           => $this->get_cheques(),
			'installmentPlans'  => $this->get_installment_plans_recomputed(),
			'payables'          => $this->get_payables_recomputed(),
			'notifications'     => $this->build_notifications(),
			'financialHealth'   => $this->build_financial_health(),
		];
		wp_send_json_success($payload);
	}

	/* ─────────────────────────────────────────────
	 * Phase 3: Finance categories CRUD (real DB)
	 * ───────────────────────────────────────────── */
	public function ajax_fincat_save(){
		$this->check_perm('settings_manage');
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
		$this->check_perm('settings_manage');
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
		$this->check_perm('treasury_payment');
		$this->reject_if_locked($this->fa_today(), 'دریافت از مشتری');
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
		$voucher_id = null;
		if (class_exists('CPTT_Finance') && preg_match('/^t(\d+)$/', $payment, $mp)) {
			$bank_id = (int)$mp[1];
			CPTT_Finance::ledger_insert([
				'account_id'  => $bank_id,
				'type'        => 'income',
				'direction'   => 1,
				'amount'      => $applied,
				'project_id'  => $pid,
				'description' => 'دریافت از مشتری پروژه: ' . get_the_title($pid) . ($note ? ' — ' . $note : ''),
				'date_at'     => (int) current_time('timestamp', true),
				'ref_type'    => 'erp_project_quick_pay',
			]);

			// === Phase 1: Generate RV voucher (debit treasury / credit مطالبات) ===
			$tmap = $this->map_treasury_to_coa($bank_id);
			$customer_id = (int) get_post_meta($pid, '_cptt_client_user_id', true);
			$vid = $this->gen_voucher([
				'type' => 'RV',
				'description' => 'دریافت از مشتری پروژه: ' . get_the_title($pid) . ($note ? ' — ' . $note : ''),
				'source_type' => 'invoice',
				'ref_type'    => 'project_quick_pay',
				'ref_id'      => $pid,
				'rows' => [
					['id'=>'r1', 'accountId'=>$tmap['id'], 'accountName'=>$tmap['name'], 'debit'=>$applied, 'credit'=>0, 'description'=>'دریافت وجه', 'projectId'=>$pid, 'customerId'=>$customer_id],
					['id'=>'r2', 'accountId'=>$this->map_account('receivable_default'),    'accountName'=>$this->coa_name($this->map_account('receivable_default')), 'debit'=>0, 'credit'=>$applied, 'description'=>'تسویه مطالبه', 'projectId'=>$pid, 'customerId'=>$customer_id],
				],
			]);
			if (!is_wp_error($vid)) $voucher_id = $vid;
		}

		// Bale notif to customer + activity log
		$cid = (int) get_post_meta($pid, '_cptt_client_user_id', true);
		if ($cid && class_exists('CPTT_Bale')) {
			CPTT_Bale::notify_via_bale($cid, '✅ پرداخت ' . number_format($applied) . ' تومان برای پروژه «' . get_the_title($pid) . '» ثبت شد.', 'payment', $pid);
		}
		if (class_exists('CPTT_Core')) {
			CPTT_Core::activity_log('project', $pid, 'erp_quick_pay', 'ERP — دریافت ' . number_format($applied) . ' تومان از مشتری');
		}
		wp_send_json_success(['applied' => $applied, 'remaining_unallocated' => $remaining, 'voucher_id' => $voucher_id]);
	}

	/* Mark a whole project as settled (toggle) */
	public function ajax_project_settle(){
		$this->check_perm('project_finance_view');
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
		$this->check_perm('project_finance_view');
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
		$this->check_perm('voucher_create');
		$raw = isset($_POST['voucher']) ? wp_unslash($_POST['voucher']) : '';
		$v   = json_decode($raw, true);
		if (!is_array($v)) wp_send_json_error('invalid voucher', 400);

		// === Phase 1: validate debit==credit on backend ===
		$check = $this->validate_voucher_rows($v['rows'] ?? []);
		if (!$check['ok']) wp_send_json_error($check['message'], 400);

		// === Phase 5: enforce period lock on voucher date ===
		$this->reject_if_locked((string)($v['date'] ?? $this->fa_today()), 'ثبت سند');

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
		// Phase 3: write to table (preferred) + legacy option fallback removed
		$this->insert_voucher_into_table($voucher);
		// Also remove from old wp_options if present (keep one-way migration)
		$opt = get_option(self::OPT_VOUCHERS, []);
		if (is_array($opt)) {
			$opt = array_values(array_filter($opt, function($vv) use ($voucher){ return (string)($vv['id'] ?? '') !== (string)$voucher['id']; }));
			update_option(self::OPT_VOUCHERS, $opt, false);
		}
		wp_send_json_success(['id'=>$voucher['id'], 'voucherNumber'=>$voucher['voucherNumber']]);
	}

	public function ajax_voucher_approve(){
		$this->check_perm('voucher_approve');
		$id   = sanitize_text_field((string)($_POST['id'] ?? ''));
		$role = sanitize_text_field((string)($_POST['role'] ?? ''));
		// Phase 3: read voucher from table
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_VOUCHERS;
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%s", $id), ARRAY_A);
		if (!$row) wp_send_json_error('voucher not found', 404);
		$st = (string)($row['status'] ?? 'DRAFT');
		$update = [];
		$me = wp_get_current_user()->display_name;
		if      ($role === 'accountant' && $st === 'DRAFT')                                                { $update['status'] = 'ACCOUNTANT_APPROVED'; $update['approved_by_accountant'] = $me . ' (حسابدار)'; }
		elseif  ($role === 'financial_manager' && $st === 'ACCOUNTANT_APPROVED')                            { $update['status'] = 'MANAGER_APPROVED';    $update['approved_by_manager']    = $me . ' (مدیر مالی)'; }
		elseif  ($role === 'ceo' && in_array($st, ['MANAGER_APPROVED','ACCOUNTANT_APPROVED'], true))         { $update['status'] = 'FINALIZED';           $update['approved_by_ceo']        = $me; }
		elseif  ($role === 'ceo' && $st === 'DRAFT') {
			$update['status'] = 'FINALIZED';
			$update['approved_by_accountant'] = 'سیستم (تأیید سریع)';
			$update['approved_by_manager']    = 'سیستم (تأیید سریع)';
			$update['approved_by_ceo']        = $me;
		}
		if ($update) $wpdb->update($tbl, $update, ['id' => $id]);
		wp_send_json_success();
	}

	public function ajax_voucher_delete(){
		$this->check_perm('voucher_delete');
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		global $wpdb;
		$v_tbl = $wpdb->prefix . self::TBL_VOUCHERS;
		$r_tbl = $wpdb->prefix . self::TBL_VOUCHER_ROWS;
		$before = $this->read_vouchers_from_table(1, 'id=%s', [$id]);
		// Phase 5: enforce period lock based on the voucher's own date
		if (!empty($before[0]['date'])) $this->reject_if_locked((string)$before[0]['date'], 'حذف سند');
		$wpdb->delete($r_tbl, ['voucher_id' => $id]);
		$wpdb->delete($v_tbl, ['id' => $id]);
		if (!empty($before[0])) $this->audit_log('delete', 'voucher', $id, $before[0], null);
		wp_send_json_success();
	}

	/**
	 * Phase 1: Reverse-voucher endpoint. Generates a new voucher with
	 * debit/credit swapped, status FINALIZED, with ref to the original.
	 * The original voucher itself is preserved (audit trail).
	 */
	public function ajax_voucher_reverse(){
		$this->check_perm('voucher_reverse');
		$id   = sanitize_text_field((string)($_POST['id'] ?? ''));
		$note = sanitize_text_field((string)($_POST['note'] ?? ''));
		if ($id === '') wp_send_json_error('invalid', 400);
		$res = $this->gen_reverse_voucher($id, $note);
		if (is_wp_error($res)) wp_send_json_error($res->get_error_message(), 400);
		wp_send_json_success(['voucher_id' => $res]);
	}

	/* ─────────────────────────────────────────────
	 * CHART OF ACCOUNTS (options)
	 * ───────────────────────────────────────────── */
	public function ajax_coa_save(){
		$this->check_perm('settings_manage');
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
		$this->check_perm('settings_manage');
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
		$this->check_perm('treasury_payment');
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
		$this->check_perm('settings_manage');
		if (!class_exists('CPTT_Finance')) wp_send_json_error('finance missing', 500);
		$id = (string)($_POST['id'] ?? '');
		if (!preg_match('/^t(\d+)$/', $id, $m)) wp_send_json_error('invalid', 400);
		$db_id = (int)$m[1];
		global $wpdb;
		$wpdb->update(CPTT_Finance::tbl_accounts(), ['status'=>0], ['id'=>$db_id]);
		wp_send_json_success();
	}
	public function ajax_treasury_transfer(){
		$this->check_perm('treasury_payment');
		$this->reject_if_locked($this->fa_today(), 'انتقال بین حساب‌ها');
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

		// === Phase 1: Generate TV voucher BEFORE ledger so we can link it ===
		$from_map = $this->map_treasury_to_coa($f);
		$to_map   = $this->map_treasury_to_coa($t);
		$desc_v   = 'انتقال بین حساب‌ها' . ($desc ? ' — ' . $desc : '');
		$voucher_id = $this->gen_voucher([
			'type' => 'TV',
			'description' => $desc_v,
			'source_type' => 'treasury_transfer',
			'ref_type'    => 'treasury_transfer',
			'ref_id'      => $f * 1000000 + $t, // synthetic composite
			'rows' => [
				['id'=>'r1', 'accountId'=>$to_map['id'],   'accountName'=>$to_map['name'],   'debit'=>$amt, 'credit'=>0,    'description'=>'انتقال ورودی به حساب #'.$t],
				['id'=>'r2', 'accountId'=>$from_map['id'], 'accountName'=>$from_map['name'], 'debit'=>0,    'credit'=>$amt, 'description'=>'انتقال خروجی از حساب #'.$f],
			],
		]);
		if (is_wp_error($voucher_id)) wp_send_json_error($voucher_id->get_error_message(), 400);

		$out_id = CPTT_Finance::ledger_insert([
			'account_id'=>$f, 'type'=>'transfer_out', 'direction'=>-1, 'amount'=>$amt, 'date_at'=>$ts,
			'description'=>'انتقال به حساب #'.$t.($desc?(' — '.$desc):''),
			'ref_type'=>'erp_treasury_transfer_out',
		]);
		$in_id = CPTT_Finance::ledger_insert([
			'account_id'=>$t, 'type'=>'transfer_in', 'direction'=>1, 'amount'=>$amt, 'date_at'=>$ts,
			'description'=>'انتقال از حساب #'.$f.($desc?(' — '.$desc):''),
			'linked_id'=>$out_id,
			'ref_type'=>'erp_treasury_transfer_in',
		]);
		global $wpdb;
		$wpdb->update(CPTT_Finance::tbl_ledger(), ['linked_id'=>$in_id], ['id'=>$out_id]);
		$this->audit_log('create', 'treasury_transfer', (string)$out_id, null, [
			'from'=>$f, 'to'=>$t, 'amount'=>$amt, 'description'=>$desc, 'voucher_id'=>$voucher_id
		]);
		wp_send_json_success(['voucher_id'=>$voucher_id, 'out_id'=>$out_id, 'in_id'=>$in_id]);
	}
	public function ajax_treasury_deposit(){
		$this->check_perm('treasury_payment');
		$this->reject_if_locked($this->fa_today(), 'واریز به حساب');
		if (!class_exists('CPTT_Finance')) wp_send_json_error('finance missing', 500);
		$acc_id = (string)($_POST['account_id'] ?? '');
		$amt    = (float)($_POST['amount']      ?? 0);
		$desc   = sanitize_text_field((string)($_POST['description'] ?? ''));
		if (!preg_match('/^t(\d+)$/', $acc_id, $m)) wp_send_json_error('bad acc', 400);
		if ($amt <= 0) wp_send_json_error('invalid amount', 400);
		$id_int = (int)$m[1];
		$desc_clean = $desc ?: 'واریز دستی به حساب';

		// === Phase 1: Generate RV voucher (debit treasury / credit "سایر درآمدها") ===
		$tmap = $this->map_treasury_to_coa($id_int);
		$voucher_id = $this->gen_voucher([
			'type' => 'RV',
			'description' => $desc_clean,
			'source_type' => 'treasury_deposit',
			'ref_type'    => 'treasury_deposit',
			'ref_id'      => $id_int,
			'rows' => [
				['id'=>'r1', 'accountId'=>$tmap['id'], 'accountName'=>$tmap['name'], 'debit'=>$amt, 'credit'=>0,    'description'=>'واریز به حساب'],
				['id'=>'r2', 'accountId'=>$this->map_account('other_income'),    'accountName'=>$this->coa_name($this->map_account('other_income')), 'debit'=>0, 'credit'=>$amt, 'description'=>$desc_clean],
			],
		]);
		if (is_wp_error($voucher_id)) wp_send_json_error($voucher_id->get_error_message(), 400);

		CPTT_Finance::ledger_insert([
			'account_id'=>$id_int, 'type'=>'income', 'direction'=>1, 'amount'=>$amt,
			'date_at'=>(int)current_time('timestamp', true),
			'description'=>$desc_clean,
			'ref_type'=>'erp_treasury_deposit',
		]);
		$this->audit_log('create', 'treasury_deposit', (string)$id_int, null, ['amount'=>$amt,'description'=>$desc_clean,'voucher_id'=>$voucher_id]);
		wp_send_json_success(['voucher_id'=>$voucher_id]);
	}
	public function ajax_treasury_withdraw(){
		$this->check_perm('treasury_payment');
		$this->reject_if_locked($this->fa_today(), 'برداشت از حساب');
		if (!class_exists('CPTT_Finance')) wp_send_json_error('finance missing', 500);
		$acc_id = (string)($_POST['account_id'] ?? '');
		$amt    = (float)($_POST['amount']      ?? 0);
		$desc   = sanitize_text_field((string)($_POST['description'] ?? ''));
		if (!preg_match('/^t(\d+)$/', $acc_id, $m)) wp_send_json_error('bad acc', 400);
		if ($amt <= 0) wp_send_json_error('invalid amount', 400);
		$id_int = (int)$m[1];
		$desc_clean = $desc ?: 'برداشت دستی از حساب';

		// === Phase 1: Generate PV voucher (debit "هزینه" / credit treasury) ===
		$tmap = $this->map_treasury_to_coa($id_int);
		$voucher_id = $this->gen_voucher([
			'type' => 'PV',
			'description' => $desc_clean,
			'source_type' => 'treasury_withdraw',
			'ref_type'    => 'treasury_withdraw',
			'ref_id'      => $id_int,
			'rows' => [
				['id'=>'r1', 'accountId'=>$this->map_account('expense_default'),    'accountName'=>$this->coa_name($this->map_account('expense_default')), 'debit'=>$amt, 'credit'=>0, 'description'=>$desc_clean],
				['id'=>'r2', 'accountId'=>$tmap['id'], 'accountName'=>$tmap['name'], 'debit'=>0, 'credit'=>$amt, 'description'=>'برداشت از حساب'],
			],
		]);
		if (is_wp_error($voucher_id)) wp_send_json_error($voucher_id->get_error_message(), 400);

		CPTT_Finance::ledger_insert([
			'account_id'=>$id_int, 'type'=>'expense', 'direction'=>-1, 'amount'=>$amt,
			'date_at'=>(int)current_time('timestamp', true),
			'description'=>$desc_clean,
			'ref_type'=>'erp_treasury_withdraw',
		]);
		$this->audit_log('create', 'treasury_withdraw', (string)$id_int, null, ['amount'=>$amt,'description'=>$desc_clean,'voucher_id'=>$voucher_id]);
		wp_send_json_success(['voucher_id'=>$voucher_id]);
	}

	/* ─────────────────────────────────────────────
	 * EXPERTS SETTLEMENT (واقعی، از طریق پلاگین)
	 * ───────────────────────────────────────────── */
	public function ajax_settle_steps(){
		$this->check_perm('treasury_payment');
		$this->reject_if_locked($this->fa_today(), 'تسویه‌ی مراحل کارشناس');
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
		$this->check_perm('treasury_payment');
		$this->reject_if_locked($this->fa_today(), 'پرداخت دستی به کارشناس');
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
		$this->check_perm('treasury_payment');
		if (!class_exists('CPTT_Finance')) wp_send_json_error('finance missing', 500);
		$raw = isset($_POST['item']) ? wp_unslash($_POST['item']) : '';
		$it  = json_decode($raw, true);
		if (!is_array($it)) wp_send_json_error('invalid', 400);
		// Phase 5: enforce period lock on user-supplied date
		$this->reject_if_locked((string)($it['date'] ?? $this->fa_today()), 'ثبت تراکنش مالی');
		$type   = ($it['type'] ?? 'INCOME') === 'INCOME' ? 'income' : 'expense';
		$amount = (float)($it['amount'] ?? 0);
		if ($amount <= 0) wp_send_json_error('amount', 400);
		$bank_id = 0;
		if (!empty($it['bankAccountId']) && preg_match('/^t(\d+)$/', (string)$it['bankAccountId'], $m)) $bank_id = (int)$m[1];
		if (!$bank_id) $bank_id = CPTT_Finance::default_cash_account_id();
		$pid = 0;
		if (!empty($it['projectId']) && preg_match('/^p(\d+)$/', (string)$it['projectId'], $mp)) $pid = (int)$mp[1];
		$title = sanitize_text_field((string)($it['title'] ?? ''));
		$desc_long = $title . (!empty($it['description']) ? ' — ' . sanitize_text_field((string)$it['description']) : '');

		$id = CPTT_Finance::ledger_insert([
			'account_id'  => $bank_id,
			'type'        => $type,
			'direction'   => $type === 'income' ? 1 : -1,
			'amount'      => $amount,
			'project_id'  => $pid,
			'description' => $desc_long,
			'date_at'     => (int) current_time('timestamp', true),
			'ref_type'    => 'erp_ie',
		]);

		// === Phase 1: Generate corresponding voucher ===
		$tmap = $this->map_treasury_to_coa($bank_id);
		if ($type === 'income') {
			$rows = [
				['id'=>'r1', 'accountId'=>$tmap['id'], 'accountName'=>$tmap['name'], 'debit'=>$amount, 'credit'=>0, 'description'=>'دریافت وجه', 'projectId'=>$pid],
				['id'=>'r2', 'accountId'=>$this->map_account('revenue_default'),    'accountName'=>$this->coa_name($this->map_account('revenue_default')), 'debit'=>0, 'credit'=>$amount, 'description'=>$title, 'projectId'=>$pid],
			];
			$vtype = 'RV';
		} else {
			$rows = [
				['id'=>'r1', 'accountId'=>$this->map_account('expense_default'),    'accountName'=>$this->coa_name($this->map_account('expense_default')), 'debit'=>$amount, 'credit'=>0, 'description'=>$title, 'projectId'=>$pid],
				['id'=>'r2', 'accountId'=>$tmap['id'], 'accountName'=>$tmap['name'], 'debit'=>0, 'credit'=>$amount, 'description'=>'پرداخت وجه', 'projectId'=>$pid],
			];
			$vtype = 'PV';
		}
		$voucher_id = $this->gen_voucher([
			'type' => $vtype,
			'description' => ($type === 'income' ? 'ثبت درآمد: ' : 'ثبت هزینه: ') . $title,
			'source_type' => $type === 'income' ? 'income' : 'expense',
			'ref_type'    => 'erp_ie',
			'ref_id'      => (int)$id,
			'rows' => $rows,
		]);
		if (is_wp_error($voucher_id)) wp_send_json_error($voucher_id->get_error_message(), 400);

		$this->audit_log('create', 'income_expense', 'ie'.$id, null, [
			'type'=>$type, 'amount'=>$amount, 'title'=>$title, 'project_id'=>$pid, 'voucher_id'=>$voucher_id
		]);
		wp_send_json_success(['id'=>'ie'.$id, 'voucher_id'=>$voucher_id]);
	}
	public function ajax_ie_delete(){
		$this->check_perm('treasury_payment');
		if (!class_exists('CPTT_Finance')) wp_send_json_error('finance missing', 500);
		$id = (string)($_POST['id'] ?? '');
		if (!preg_match('/^ie(\d+)$/', $id, $m)) wp_send_json_error('invalid', 400);
		$num_id = (int)$m[1];

		// === Phase 1: Find and REVERSE the matching voucher ===
		$vouchers = $this->get_vouchers();
		$reversed = null;
		foreach ($vouchers as $v) {
			if (($v['refType'] ?? '') === 'erp_ie' && (int)($v['refId'] ?? 0) === $num_id) {
				$reversed = $this->gen_reverse_voucher($v['id'], 'حذف تراکنش ie#'.$num_id);
				break;
			}
		}

		global $wpdb;
		// Fetch before-state for audit
		$before = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CPTT_Finance::tbl_ledger() . ' WHERE id=%d', $num_id), ARRAY_A);
		$wpdb->delete(CPTT_Finance::tbl_ledger(), ['id'=>$num_id]);
		$this->audit_log('delete', 'income_expense', 'ie'.$num_id, $before, ['reverse_voucher_id'=>$reversed]);
		wp_send_json_success();
	}

	/* ─────────────────────────────────────────────
	 * COST CENTERS, FISCAL YEARS, LOCKS (options)
	 * ───────────────────────────────────────────── */
	public function ajax_cc_save(){
		$this->check_perm('settings_manage');
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
		$this->audit_log($found ? 'update' : 'create', 'cost_center', $id, null, $row);
		wp_send_json_success(['id'=>$id]);
	}
	public function ajax_cc_delete(){
		$this->check_perm('settings_manage');
		$id  = sanitize_text_field((string)($_POST['id'] ?? ''));
		$all = $this->get_costs();
		$before = null;
		foreach ($all as $r) if ((string)$r['id'] === $id) { $before = $r; break; }
		$all = array_values(array_filter($all, function($r) use ($id){ return (string)$r['id'] !== $id; }));
		update_option(self::OPT_COSTS, $all, false);
		$this->audit_log('delete', 'cost_center', $id, $before, null);
		wp_send_json_success();
	}
	public function ajax_fy_create(){
		$this->check_perm('settings_manage');
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
		$this->audit_log('create', 'fiscal_year', $row['id'], null, $row);
		wp_send_json_success(['id'=>$row['id']]);
	}
	public function ajax_fy_close(){
		$this->check_perm('settings_manage');
		$id  = sanitize_text_field((string)($_POST['id'] ?? ''));
		$all = $this->get_fy();
		foreach ($all as $i => $r) if ((string)$r['id'] === $id) { $all[$i]['status'] = 'CLOSED'; break; }
		update_option(self::OPT_FY, $all, false);
		$this->audit_log('close', 'fiscal_year', $id, null, null);
		wp_send_json_success();
	}
	public function ajax_lock_toggle(){
		$this->check_perm('settings_manage');
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
		$this->audit_log('toggle', 'period_lock', $fyId, null, ['startDate'=>$start,'endDate'=>$end]);
		wp_send_json_success();
	}

	/* ═════════════════════════════════════════════════════════════════
	 * ▼▼▼ Phase 1 — Accounting Engine, Audit, Party, Permissions ▼▼▼
	 * ═════════════════════════════════════════════════════════════════ */

	/* ─── Voucher numbering per type per fiscal year ─────────────── */
	private function next_voucher_code($type){
		$type = in_array($type, ['JV','RV','PV','TV','CV','OV'], true) ? $type : 'JV';
		$fyAll = $this->get_fy();
		$fy_name = !empty($fyAll[0]['name']) ? preg_replace('/\D/', '', $fyAll[0]['name']) : date('Y');
		if (!$fy_name) $fy_name = date('Y');
		$counters = get_option(self::OPT_VNUMBERS, []);
		if (!is_array($counters)) $counters = [];
		$key = $type . '_' . $fy_name;
		$n = isset($counters[$key]) ? (int)$counters[$key] + 1 : 1;
		$counters[$key] = $n;
		update_option(self::OPT_VNUMBERS, $counters, false);
		return sprintf('%s-%s-%06d', $type, $fy_name, $n);
	}

	/* ─── Phase 1 Helpers — resolve account names & treasury mapping ─── */

	/**
	 * Resolve a COA node id → human-readable name. Falls back to the id
	 * itself if not found. Used to populate voucher row `accountName`
	 * automatically so the engine doesn't depend on hardcoded labels.
	 */
	private function coa_name($account_id){
		$coa = $this->get_coa();
		foreach ($coa as $node) {
			if (isset($node['id']) && $node['id'] === $account_id) {
				return (string)($node['name'] ?? $account_id);
			}
		}
		return (string)$account_id;
	}

	/**
	 * Map a treasury account (db id "t<int>") to its CoA subsidiary code.
	 * Phase 2: First check per-treasury override map (OPT_TREASURY_COA_MAP).
	 * Else fall back to mapping (cash_default / bank_default) based on type.
	 */
	private function map_treasury_to_coa($treasury_id_or_int){
		$fallback_bank = $this->map_account('bank_default') ?: 'a1_1_1';
		$fallback_cash = $this->map_account('cash_default') ?: 'a1_1_2';
		if (!class_exists('CPTT_Finance')) return ['id'=>$fallback_bank,'name'=>$this->coa_name($fallback_bank)];
		global $wpdb;
		$id_int = 0;
		if (is_string($treasury_id_or_int) && preg_match('/^t(\d+)$/', $treasury_id_or_int, $m)) $id_int = (int)$m[1];
		elseif (is_numeric($treasury_id_or_int)) $id_int = (int)$treasury_id_or_int;
		if (!$id_int) return ['id'=>$fallback_bank,'name'=>$this->coa_name($fallback_bank)];
		// 1) Per-treasury override
		$override_map = $this->get_treasury_coa_map();
		if (!empty($override_map[(string)$id_int])) {
			$coa_id = (string)$override_map[(string)$id_int];
			$row = $wpdb->get_row($wpdb->prepare('SELECT name FROM ' . CPTT_Finance::tbl_accounts() . ' WHERE id=%d', $id_int));
			$base  = $this->coa_name($coa_id);
			$label = $row ? ($base . ' — ' . (string)$row->name) : $base;
			return ['id'=>$coa_id, 'name'=>$label, 'treasury_id'=>$id_int];
		}
		// 2) Fallback by type
		$row = $wpdb->get_row($wpdb->prepare('SELECT name, type FROM ' . CPTT_Finance::tbl_accounts() . ' WHERE id=%d', $id_int));
		$is_cash = ($row && strtoupper((string)$row->type) === 'CASH');
		$coa_id  = $is_cash ? $fallback_cash : $fallback_bank;
		$base    = $this->coa_name($coa_id);
		$label   = $row ? ($base . ' — ' . (string)$row->name) : $base;
		return ['id'=>$coa_id, 'name'=>$label, 'treasury_id'=>$id_int];
	}

	/**
	 * Validate voucher rows: debit==credit total, at least one row, no
	 * negative numbers, no row with both debit AND credit > 0.
	 * Returns ['ok'=>true] or ['ok'=>false,'message'=>'...'].
	 */
	private function validate_voucher_rows($rows){
		if (!is_array($rows) || count($rows) < 1) {
			return ['ok'=>false, 'message'=>'سند باید حداقل یک ردیف داشته باشد'];
		}
		$td = 0.0; $tc = 0.0;
		foreach ($rows as $r) {
			$d = (float)($r['debit']  ?? 0);
			$c = (float)($r['credit'] ?? 0);
			if ($d < 0 || $c < 0) return ['ok'=>false,'message'=>'مبلغ منفی مجاز نیست'];
			if ($d > 0 && $c > 0) return ['ok'=>false,'message'=>'یک ردیف نمی‌تواند هم بدهکار و هم بستانکار باشد'];
			if (empty($r['accountId'])) return ['ok'=>false,'message'=>'حساب ردیف الزامی است'];
			$td += $d; $tc += $c;
		}
		if ($td <= 0) return ['ok'=>false,'message'=>'جمع مبالغ سند صفر است'];
		// Allow 1 rial rounding tolerance
		if (abs($td - $tc) > 1) {
			return ['ok'=>false,'message'=>'بدهکار (' . number_format($td) . ') با بستانکار (' . number_format($tc) . ') برابر نیست'];
		}
		return ['ok'=>true];
	}

	/**
	 * Reverse-voucher: given an existing voucher id, generates a new
	 * voucher with debit/credit swapped, status FINALIZED, and a ref back
	 * to the original. Returns the new voucher id or WP_Error.
	 */
	private function gen_reverse_voucher($voucher_id, $note = ''){
		$vouchers = $this->get_vouchers();
		$src = null;
		foreach ($vouchers as $v) if (($v['id'] ?? '') === $voucher_id) { $src = $v; break; }
		if (!$src) return new WP_Error('not_found', 'سند مرجع پیدا نشد');
		$rows = [];
		foreach ((array)($src['rows'] ?? []) as $i => $r) {
			$rows[] = [
				'id'          => 'rev_' . ($i+1),
				'accountId'   => $r['accountId'] ?? '',
				'accountName' => $r['accountName'] ?? $this->coa_name($r['accountId'] ?? ''),
				'debit'       => (float)($r['credit'] ?? 0),
				'credit'      => (float)($r['debit']  ?? 0),
				'description' => 'برگشت: ' . ($r['description'] ?? ''),
				'projectId'   => $r['projectId']   ?? null,
				'customerId'  => $r['customerId']  ?? null,
				'expertId'    => $r['expertId']    ?? null,
				'costCenterId'=> $r['costCenterId'] ?? null,
			];
		}
		$new_id = $this->gen_voucher([
			'type'        => $src['voucherType'] ?? 'JV',
			'description' => 'سند برگشتی برای ' . ($src['voucherCode'] ?? $voucher_id) . ($note ? ' — ' . $note : ''),
			'source_type' => 'reverse',
			'ref_type'    => 'reverse_of',
			'ref_id'      => 0,
			'rows'        => $rows,
		]);
		return $new_id;
	}

	/* ─── Accounting Engine: generate a voucher from a business event ── */
	private function gen_voucher($args){
		$args = wp_parse_args($args, [
			'type'        => 'JV',
			'description' => '',
			'date'        => '',
			'rows'        => [],
			'source_type' => '',
			'ref_type'    => '',
			'ref_id'      => 0,
			'strict'      => true,   // Phase 1: validate by default
		]);

		// Normalize rows: auto-fill accountName from CoA if empty,
		// coerce numbers to floats, cast ids to ints.
		$rows = [];
		foreach ((array)$args['rows'] as $i => $r) {
			$accId = (string)($r['accountId'] ?? '');
			$rows[] = [
				'id'           => (string)($r['id'] ?? ('r' . ($i+1))),
				'accountId'    => $accId,
				'accountName'  => $r['accountName'] ?? $this->coa_name($accId),
				'debit'        => round((float)($r['debit']  ?? 0), 2),
				'credit'       => round((float)($r['credit'] ?? 0), 2),
				'description'  => sanitize_text_field((string)($r['description'] ?? '')),
				'projectId'    => isset($r['projectId'])    && $r['projectId']    !== '' ? (int)$r['projectId']    : null,
				'customerId'   => isset($r['customerId'])   && $r['customerId']   !== '' ? (int)$r['customerId']   : null,
				'expertId'     => isset($r['expertId'])     && $r['expertId']     !== '' ? (int)$r['expertId']     : null,
				'costCenterId' => isset($r['costCenterId']) && $r['costCenterId'] !== '' ? (string)$r['costCenterId'] : null,
			];
		}

		// Validate
		if ($args['strict']) {
			$check = $this->validate_voucher_rows($rows);
			if (!$check['ok']) return new WP_Error('invalid_voucher', $check['message']);
		}

		$fyAll = $this->get_fy();
		$date_fa = !empty($args['date']) ? (string)$args['date'] : $this->fa_today();
		$voucher = [
			'id'              => 'v_auto_' . time() . '_' . wp_generate_password(4, false, false),
			'voucherNumber'   => time(),
			'voucherCode'     => $this->next_voucher_code($args['type']),
			'voucherType'     => $args['type'],
			'date'            => $date_fa,
			'description'     => sanitize_text_field($args['description']),
			'status'          => 'FINALIZED',
			'companyId'       => 'c_site',
			'branchId'        => 'b_main',
			'fiscalYearId'    => !empty($fyAll[0]['id']) ? $fyAll[0]['id'] : '',
			'currency'        => 'TOMAN',
			'exchangeRate'    => 1,
			'rows'            => $rows,
			'createdBy'       => 'سیستم (خودکار)',
			'isAutoGenerated' => true,
			'sourceType'      => $args['source_type'],
			'refType'         => $args['ref_type'],
			'refId'           => (int)$args['ref_id'],
		];
		// Phase 3: write to table (primary) instead of wp_options
		$this->insert_voucher_into_table($voucher);
		$this->audit_log('create', 'voucher', $voucher['id'], null, $voucher);
		$this->fire_webhook('voucher.created', ['id'=>$voucher['id'],'code'=>$voucher['voucherCode'] ?? '','type'=>$voucher['voucherType'] ?? '','status'=>$voucher['status'] ?? '']);
		return $voucher['id'];
	}

	/* ─── Auto-voucher: expert step settlement ─── */
	public function auto_voucher_expert_payout($project_id, $step_id, $expert_id, $amount, $mode){
		$project_title = get_the_title($project_id);
		$expert = get_user_by('id', $expert_id);
		$expert_name = $expert ? $expert->display_name : ('#' . $expert_id);
		$this->gen_voucher([
			'type'        => 'PV',
			'description' => 'پرداخت به کارشناس: ' . $expert_name . ' — مرحله پروژه ' . $project_title,
			'source_type' => 'settlement',
			'ref_type'    => 'expert_payout',
			'ref_id'      => (int)$project_id,
			'rows'        => [
				[ 'id'=>'r1', 'accountId'=>$this->map_account('expert_payroll'), 'accountName'=>$this->coa_name($this->map_account('expert_payroll')), 'debit'=>(float)$amount, 'credit'=>0, 'description'=>'دستمزد کارشناس', 'projectId'=>(int)$project_id, 'expertId'=>(int)$expert_id ],
				[ 'id'=>'r2', 'accountId'=>$this->map_account('bank_default'), 'accountName'=>$this->coa_name($this->map_account('bank_default')), 'debit'=>0, 'credit'=>(float)$amount, 'description'=>'پرداخت از حساب', 'projectId'=>(int)$project_id, 'expertId'=>(int)$expert_id ],
			],
		]);
	}

	/* ─── Auto-voucher: manual expert payment ─── */
	public function auto_voucher_manual_payment($expert_id, $amount, $note){
		$expert = get_user_by('id', $expert_id);
		$expert_name = $expert ? $expert->display_name : ('#' . $expert_id);
		$this->gen_voucher([
			'type'        => 'PV',
			'description' => 'پرداخت دستی به کارشناس: ' . $expert_name . ($note ? ' — ' . $note : ''),
			'source_type' => 'payment',
			'ref_type'    => 'expert_manual_payout',
			'ref_id'      => (int)$expert_id,
			'rows'        => [
				[ 'id'=>'r1', 'accountId'=>$this->map_account('expert_payroll'), 'accountName'=>$this->coa_name($this->map_account('expert_payroll')), 'debit'=>(float)$amount, 'credit'=>0, 'description'=>'دستمزد دستی', 'expertId'=>(int)$expert_id ],
				[ 'id'=>'r2', 'accountId'=>$this->map_account('bank_default'), 'accountName'=>$this->coa_name($this->map_account('bank_default')), 'debit'=>0, 'credit'=>(float)$amount, 'description'=>'پرداخت دستی', 'expertId'=>(int)$expert_id ],
			],
		]);
	}

	/* ─── Vouchers list with server-side filters (advanced) ─── */
	public function ajax_vouchers_list(){
		$this->check();
		$all = $this->get_vouchers();
		wp_send_json_success(['rows' => $all]);
	}

	/* ─── Party accounts (تفصیلی اشخاص) ─── */
	private function build_party_accounts(){
		$out = [];
		// Customers from receivables
		$customers = [];
		foreach ($this->build_receivables() as $r) {
			$cid = (int)$r['customerId'];
			if ($cid && !isset($customers[$cid])) {
				$customers[$cid] = ['name' => $r['customerName'], 'debit' => 0, 'credit' => 0];
			}
			if ($cid) {
				$customers[$cid]['debit']  += (float)$r['cost'];
				$customers[$cid]['credit'] += (float)$r['paid'];
			}
		}
		foreach ($customers as $cid => $c) {
			$bal = $c['debit'] - $c['credit'];
			$out[] = [
				'id'      => 'pc' . $cid,
				'code'    => '۱۰۲۰۰۱' . str_pad((string)$cid, 4, '0', STR_PAD_LEFT),
				'name'    => $c['name'],
				'type'    => 'customer',
				'refId'   => $cid,
				'debit'   => $c['debit'],
				'credit'  => $c['credit'],
				'balance' => $bal,
				'status'  => 'active',
			];
		}
		// Experts
		$experts = get_users(['role' => 'cptt_expert', 'orderby' => 'display_name']);
		foreach ($experts as $u) {
			$eid = (int)$u->ID;
			// Sum from settlement history + pending
			global $wpdb;
			$tbl = $wpdb->prefix . 'cptt_ledger';
			$paid = 0;
			$exists = (int) $wpdb->get_var("SHOW TABLES LIKE '$tbl'");
			if ($exists) {
				$paid = (float) $wpdb->get_var($wpdb->prepare(
					"SELECT COALESCE(SUM(ABS(amount)),0) FROM $tbl WHERE user_id=%d AND type IN ('expert_payout','expert_manual_payout')",
					$eid
				));
			}
			// Compute pending from steps
			$pending = 0;
			$projects = get_posts(['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>-1]);
			foreach ($projects as $p) {
				$steps = get_post_meta($p->ID, '_cptt_steps', true);
				if (!is_array($steps)) continue;
				foreach ($steps as $st) {
					$assigned = [];
					if (!empty($st['assigned_expert_ids']) && is_array($st['assigned_expert_ids'])) {
						$assigned = array_map('intval', $st['assigned_expert_ids']);
					} elseif (!empty($st['assigned_expert_id'])) {
						$assigned = [(int)$st['assigned_expert_id']];
					}
					if (in_array($eid, $assigned, true)) {
						$pending += max(0, (float)($st['exp_to_expert'] ?? 0) - (float)($st['expert_paid'] ?? 0));
					}
				}
			}
			$out[] = [
				'id'      => 'pe' . $eid,
				'code'    => '۲۰۲۰۰۱' . str_pad((string)$eid, 4, '0', STR_PAD_LEFT),
				'name'    => $u->display_name,
				'type'    => 'expert',
				'refId'   => $eid,
				'debit'   => $paid,
				'credit'  => $paid + $pending,
				'balance' => -$pending, // negative = ما به کارشناس بدهکار هستیم
				'status'  => 'active',
			];
		}
		return $out;
	}

	/* ─── Party statement ─── */
	public function ajax_party_statement(){
		$this->check();
		$party_type = sanitize_text_field((string)($_POST['party_type'] ?? ''));
		$party_id   = (int)($_POST['party_id'] ?? 0);
		$date_from  = sanitize_text_field((string)($_POST['date_from'] ?? ''));
		$date_to    = sanitize_text_field((string)($_POST['date_to'] ?? ''));
		$project_id = (int)($_POST['project_id'] ?? 0);

		if (!$party_id) wp_send_json_success(['rows' => [], 'opening_balance' => 0]);

		$rows = [];
		$opening = 0;
		$vouchers = $this->get_vouchers();
		$key = $party_type === 'customer' ? 'customerId' : 'expertId';

		foreach ($vouchers as $v) {
			$dt = $v['date'] ?? '';
			if ($date_from && $dt && strcmp($dt, $date_from) < 0) {
				// Add to opening
				foreach ($v['rows'] as $r) {
					if ((int)($r[$key] ?? 0) !== $party_id) continue;
					if ($project_id && (int)($r['projectId'] ?? 0) !== $project_id) continue;
					$opening += (float)($r['debit'] ?? 0) - (float)($r['credit'] ?? 0);
				}
				continue;
			}
			if ($date_to && $dt && strcmp($dt, $date_to) > 0) continue;

			foreach ($v['rows'] as $r) {
				if ((int)($r[$key] ?? 0) !== $party_id) continue;
				if ($project_id && (int)($r['projectId'] ?? 0) !== $project_id) continue;
				$rows[] = [
					'date'        => $v['date'] ?? '',
					'voucherCode' => $v['voucherCode'] ?? '',
					'description' => ($v['description'] ?? '') . ($r['description'] ? ' — ' . $r['description'] : ''),
					'debit'       => (float)($r['debit'] ?? 0),
					'credit'      => (float)($r['credit'] ?? 0),
				];
			}
		}

		// Add ledger entries too (incomes/expenses + settlements)
		if ($party_type === 'expert' && class_exists('CPTT_Finance')) {
			global $wpdb;
			$tbl = $wpdb->prefix . 'cptt_ledger';
			$exists = (int) $wpdb->get_var("SHOW TABLES LIKE '$tbl'");
			if ($exists) {
				$entries = $wpdb->get_results($wpdb->prepare(
					"SELECT * FROM $tbl WHERE user_id=%d AND type IN ('expert_payout','expert_manual_payout') ORDER BY id DESC LIMIT 500",
					$party_id
				));
				foreach ($entries as $e) {
					$rows[] = [
						'date'        => $this->fa_date_of(strtotime($e->created_at ?? 'now')),
						'voucherCode' => 'AUTO',
						'description' => 'تسویه — ' . ($e->note ?? ''),
						'debit'       => abs((float)$e->amount),
						'credit'      => 0,
					];
				}
			}
		}

		// Sort by date ASC
		usort($rows, function($a, $b){ return strcmp($a['date'], $b['date']); });

		wp_send_json_success(['rows' => $rows, 'opening_balance' => $opening]);
	}

	/* ═════════════════════════════════════════════════════════════════
	 * ▼▼▼ Phase 3 — Database Schema & Migrations ▼▼▼
	 * ═════════════════════════════════════════════════════════════════ */

	/**
	 * Idempotent migration runner. Runs on every admin_init but actually
	 * only performs work when DB_VERSION option ≠ current code constant.
	 */
	public function maybe_run_migrations(){
		$current = get_option(self::OPT_DB_VERSION, '0.0.0');
		if (version_compare($current, self::DB_VERSION, '>=')) return;
		// Create / upgrade all tables
		$this->install_all_tables();
		// Add voucher_id column to legacy ledger if missing
		$this->ensure_ledger_has_voucher_id();
		// Migrate data from wp_options to new tables (idempotent — checks before insert)
		$this->migrate_options_to_tables();
		// Disable autoload on big options (they're written elsewhere via tables now)
		$this->disable_autoload_for_legacy_options();
		update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
	}

	private function disable_autoload_for_legacy_options(){
		global $wpdb;
		$opts = [self::OPT_VOUCHERS, self::OPT_CHEQUES, self::OPT_PAYABLES, self::OPT_INSTALLMENTS, self::OPT_COA, self::OPT_VNUMBERS, self::OPT_ATTACHMENTS];
		foreach ($opts as $o) {
			$wpdb->update($wpdb->options, ['autoload' => 'no'], ['option_name' => $o]);
		}
	}

	public function install_all_tables(){
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Audit table (created earlier in v7.x)
		$tbl = $wpdb->prefix . self::TBL_AUDIT;
		dbDelta("CREATE TABLE $tbl (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_name VARCHAR(190) DEFAULT NULL,
			action_type VARCHAR(40) NOT NULL,
			entity_type VARCHAR(40) NOT NULL,
			entity_id VARCHAR(64) NOT NULL DEFAULT '',
			before_data LONGTEXT,
			after_data LONGTEXT,
			created_at INT NOT NULL DEFAULT 0,
			date_fa VARCHAR(40) DEFAULT NULL,
			PRIMARY KEY (id),
			KEY user_idx (user_id),
			KEY action_idx (action_type),
			KEY entity_idx (entity_type),
			KEY date_idx (date_fa)
		) $charset;");

		// Vouchers (header)
		$tbl = $wpdb->prefix . self::TBL_VOUCHERS;
		dbDelta("CREATE TABLE $tbl (
			id VARCHAR(64) NOT NULL,
			voucher_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
			voucher_code VARCHAR(64) DEFAULT NULL,
			voucher_type VARCHAR(8) NOT NULL DEFAULT 'JV',
			date_fa VARCHAR(20) DEFAULT NULL,
			description TEXT,
			status VARCHAR(40) NOT NULL DEFAULT 'DRAFT',
			company_id VARCHAR(40) DEFAULT NULL,
			branch_id VARCHAR(40) DEFAULT NULL,
			fiscal_year_id VARCHAR(40) DEFAULT NULL,
			currency VARCHAR(8) DEFAULT 'TOMAN',
			exchange_rate DECIMAL(18,6) DEFAULT 1,
			created_by VARCHAR(190) DEFAULT NULL,
			approved_by_accountant VARCHAR(190) DEFAULT NULL,
			approved_by_manager VARCHAR(190) DEFAULT NULL,
			approved_by_ceo VARCHAR(190) DEFAULT NULL,
			is_auto_generated TINYINT NOT NULL DEFAULT 0,
			source_type VARCHAR(40) DEFAULT NULL,
			ref_type VARCHAR(40) DEFAULT NULL,
			ref_id BIGINT UNSIGNED DEFAULT 0,
			created_at INT NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY type_idx (voucher_type),
			KEY status_idx (status),
			KEY date_idx (date_fa),
			KEY ref_idx (ref_type, ref_id),
			KEY fy_idx (fiscal_year_id),
			KEY number_idx (voucher_number)
		) $charset;");

		// Voucher rows
		$tbl = $wpdb->prefix . self::TBL_VOUCHER_ROWS;
		dbDelta("CREATE TABLE $tbl (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			voucher_id VARCHAR(64) NOT NULL,
			row_no INT NOT NULL DEFAULT 0,
			account_id VARCHAR(64) NOT NULL,
			account_name VARCHAR(190) DEFAULT NULL,
			debit DECIMAL(18,2) NOT NULL DEFAULT 0,
			credit DECIMAL(18,2) NOT NULL DEFAULT 0,
			description TEXT,
			project_id BIGINT UNSIGNED DEFAULT NULL,
			customer_id BIGINT UNSIGNED DEFAULT NULL,
			expert_id BIGINT UNSIGNED DEFAULT NULL,
			cost_center_id VARCHAR(40) DEFAULT NULL,
			PRIMARY KEY (id),
			KEY voucher_idx (voucher_id),
			KEY account_idx (account_id),
			KEY project_idx (project_id),
			KEY customer_idx (customer_id),
			KEY expert_idx (expert_id)
		) $charset;");

		// Cheques
		$tbl = $wpdb->prefix . self::TBL_CHEQUES;
		dbDelta("CREATE TABLE $tbl (
			id VARCHAR(64) NOT NULL,
			kind VARCHAR(20) NOT NULL DEFAULT 'receivable',
			number VARCHAR(40) NOT NULL,
			sayyadi VARCHAR(40) DEFAULT NULL,
			bank VARCHAR(190) DEFAULT NULL,
			branch VARCHAR(190) DEFAULT NULL,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			issue_date VARCHAR(20) DEFAULT NULL,
			due_date VARCHAR(20) DEFAULT NULL,
			party_type VARCHAR(20) DEFAULT NULL,
			party_id BIGINT UNSIGNED DEFAULT 0,
			party_name VARCHAR(190) DEFAULT NULL,
			project_id BIGINT UNSIGNED DEFAULT 0,
			treasury_account_id VARCHAR(40) DEFAULT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'received',
			description TEXT,
			attachment_id VARCHAR(64) DEFAULT NULL,
			created_at_fa VARCHAR(20) DEFAULT NULL,
			created_at INT NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY kind_idx (kind),
			KEY status_idx (status),
			KEY due_idx (due_date),
			KEY party_idx (party_type, party_id),
			KEY sayyadi_idx (sayyadi),
			KEY bank_idx (bank)
		) $charset;");

		// Payables
		$tbl = $wpdb->prefix . self::TBL_PAYABLES;
		dbDelta("CREATE TABLE $tbl (
			id VARCHAR(64) NOT NULL,
			party_type VARCHAR(20) NOT NULL DEFAULT 'other',
			party_id BIGINT UNSIGNED DEFAULT 0,
			party_name VARCHAR(190) DEFAULT NULL,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			issue_date VARCHAR(20) DEFAULT NULL,
			due_date VARCHAR(20) DEFAULT NULL,
			project_id BIGINT UNSIGNED DEFAULT 0,
			description TEXT,
			status VARCHAR(30) NOT NULL DEFAULT 'open',
			created_at_fa VARCHAR(20) DEFAULT NULL,
			created_at INT NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY party_idx (party_type, party_id),
			KEY status_idx (status),
			KEY due_idx (due_date)
		) $charset;");

		// Installment plans
		$tbl = $wpdb->prefix . self::TBL_INSTALLMENTS;
		dbDelta("CREATE TABLE $tbl (
			id VARCHAR(64) NOT NULL,
			customer_id BIGINT UNSIGNED NOT NULL,
			customer_name VARCHAR(190) DEFAULT NULL,
			project_id BIGINT UNSIGNED DEFAULT 0,
			project_name VARCHAR(190) DEFAULT NULL,
			total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			installments_count INT NOT NULL DEFAULT 0,
			interval_days INT NOT NULL DEFAULT 30,
			first_due_date VARCHAR(20) DEFAULT NULL,
			description TEXT,
			created_at_fa VARCHAR(20) DEFAULT NULL,
			created_at INT NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY customer_idx (customer_id),
			KEY project_idx (project_id),
			KEY first_due_idx (first_due_date)
		) $charset;");

		// Installment rows
		$tbl = $wpdb->prefix . self::TBL_INSTALLMENT_ROWS;
		dbDelta("CREATE TABLE $tbl (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plan_id VARCHAR(64) NOT NULL,
			no INT NOT NULL DEFAULT 0,
			due_date VARCHAR(20) DEFAULT NULL,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			paid_date VARCHAR(20) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
			PRIMARY KEY (id),
			UNIQUE KEY plan_no (plan_id, no),
			KEY due_idx (due_date),
			KEY status_idx (status)
		) $charset;");

		// Phase 8: Invoices (header)
		$tbl = $wpdb->prefix . self::TBL_INVOICES;
		dbDelta("CREATE TABLE $tbl (
			id VARCHAR(64) NOT NULL,
			number VARCHAR(40) NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'invoice',
			customer_id BIGINT UNSIGNED DEFAULT 0,
			customer_name VARCHAR(190) DEFAULT NULL,
			project_id BIGINT UNSIGNED DEFAULT 0,
			project_name VARCHAR(190) DEFAULT NULL,
			issue_date VARCHAR(20) DEFAULT NULL,
			due_date VARCHAR(20) DEFAULT NULL,
			subtotal DECIMAL(18,2) NOT NULL DEFAULT 0,
			discount DECIMAL(18,2) NOT NULL DEFAULT 0,
			tax_rate DECIMAL(8,3) NOT NULL DEFAULT 0,
			tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			total DECIMAL(18,2) NOT NULL DEFAULT 0,
			currency VARCHAR(8) DEFAULT 'TOMAN',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			notes TEXT,
			voucher_id VARCHAR(64) DEFAULT NULL,
			created_by VARCHAR(190) DEFAULT NULL,
			created_at INT NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY number_uniq (number, type),
			KEY type_idx (type),
			KEY status_idx (status),
			KEY customer_idx (customer_id),
			KEY date_idx (issue_date)
		) $charset;");

		// Phase 8: Invoice rows (line items)
		$tbl = $wpdb->prefix . self::TBL_INVOICE_ROWS;
		dbDelta("CREATE TABLE $tbl (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id VARCHAR(64) NOT NULL,
			row_no INT NOT NULL DEFAULT 0,
			title VARCHAR(255) NOT NULL,
			description TEXT,
			quantity DECIMAL(18,3) NOT NULL DEFAULT 1,
			unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY invoice_idx (invoice_id)
		) $charset;");
	}

	/**
	 * Ensure wp_cptt_fin_ledger has a `voucher_id` column for back-ref.
	 * Also adds beneficial indexes that the legacy code didn't have.
	 */
	public function ensure_ledger_has_voucher_id(){
		if (!class_exists('CPTT_Finance')) return;
		global $wpdb;
		$tbl = CPTT_Finance::tbl_ledger();
		$col = $wpdb->get_var("SHOW COLUMNS FROM $tbl LIKE 'voucher_id'");
		if (!$col) {
			$wpdb->query("ALTER TABLE $tbl ADD COLUMN voucher_id VARCHAR(64) DEFAULT NULL AFTER ref_type");
			$wpdb->query("ALTER TABLE $tbl ADD INDEX voucher_id_idx (voucher_id)");
		}
		// Add missing performance indexes (idempotent)
		$idx = $wpdb->get_results("SHOW INDEX FROM $tbl");
		$existing = [];
		foreach ((array)$idx as $r) $existing[] = (string)$r->Key_name;
		if (!in_array('date_at_idx', $existing, true))   $wpdb->query("ALTER TABLE $tbl ADD INDEX date_at_idx (date_at)");
		if (!in_array('project_id_idx', $existing, true))$wpdb->query("ALTER TABLE $tbl ADD INDEX project_id_idx (project_id)");
		if (!in_array('customer_id_idx', $existing, true))$wpdb->query("ALTER TABLE $tbl ADD INDEX customer_id_idx (customer_id)");
		if (!in_array('ref_type_idx', $existing, true)) $wpdb->query("ALTER TABLE $tbl ADD INDEX ref_type_idx (ref_type)");
	}

	/**
	 * One-way migration: copy data from wp_options arrays into the new
	 * tables. After successful copy, the option rows are KEPT (legacy
	 * read-fallback for safety). The DB read-path now uses the tables.
	 */
	public function migrate_options_to_tables(){
		global $wpdb;

		// ── Vouchers ──
		$v_tbl = $wpdb->prefix . self::TBL_VOUCHERS;
		$r_tbl = $wpdb->prefix . self::TBL_VOUCHER_ROWS;
		$has = (int)$wpdb->get_var("SELECT COUNT(*) FROM $v_tbl");
		if ($has === 0) {
			$opt = get_option(self::OPT_VOUCHERS, []);
			if (is_array($opt) && $opt) {
				foreach ($opt as $v) {
					$this->insert_voucher_into_table($v);
				}
			}
		}

		// ── Cheques ──
		$c_tbl = $wpdb->prefix . self::TBL_CHEQUES;
		$has = (int)$wpdb->get_var("SELECT COUNT(*) FROM $c_tbl");
		if ($has === 0) {
			$opt = get_option(self::OPT_CHEQUES, []);
			if (is_array($opt) && $opt) foreach ($opt as $c) $this->insert_cheque_into_table($c);
		}

		// ── Payables ──
		$p_tbl = $wpdb->prefix . self::TBL_PAYABLES;
		$has = (int)$wpdb->get_var("SELECT COUNT(*) FROM $p_tbl");
		if ($has === 0) {
			$opt = get_option(self::OPT_PAYABLES, []);
			if (is_array($opt) && $opt) foreach ($opt as $p) $this->insert_payable_into_table($p);
		}

		// ── Installments ──
		$i_tbl = $wpdb->prefix . self::TBL_INSTALLMENTS;
		$has = (int)$wpdb->get_var("SELECT COUNT(*) FROM $i_tbl");
		if ($has === 0) {
			$opt = get_option(self::OPT_INSTALLMENTS, []);
			if (is_array($opt) && $opt) foreach ($opt as $pl) $this->insert_installment_plan_into_table($pl);
		}
	}

	/* ─── Phase 3: Table writers (used by both migration and runtime CRUD) ─── */

	private function insert_voucher_into_table($v){
		global $wpdb;
		$id = (string)($v['id'] ?? '');
		if (!$id) return;
		$wpdb->replace($wpdb->prefix . self::TBL_VOUCHERS, [
			'id'                     => $id,
			'voucher_number'         => (int)($v['voucherNumber'] ?? 0),
			'voucher_code'           => (string)($v['voucherCode'] ?? ''),
			'voucher_type'           => (string)($v['voucherType'] ?? 'JV'),
			'date_fa'                => (string)($v['date'] ?? ''),
			'description'            => (string)($v['description'] ?? ''),
			'status'                 => (string)($v['status'] ?? 'DRAFT'),
			'company_id'             => (string)($v['companyId'] ?? ''),
			'branch_id'              => (string)($v['branchId'] ?? ''),
			'fiscal_year_id'         => (string)($v['fiscalYearId'] ?? ''),
			'currency'               => (string)($v['currency'] ?? 'TOMAN'),
			'exchange_rate'          => (float)($v['exchangeRate'] ?? 1),
			'created_by'             => (string)($v['createdBy'] ?? ''),
			'approved_by_accountant' => (string)($v['approvedByAccountant'] ?? ''),
			'approved_by_manager'    => (string)($v['approvedByManager'] ?? ''),
			'approved_by_ceo'        => (string)($v['approvedByCEO'] ?? ''),
			'is_auto_generated'      => !empty($v['isAutoGenerated']) ? 1 : 0,
			'source_type'            => (string)($v['sourceType'] ?? ''),
			'ref_type'               => (string)($v['refType'] ?? ''),
			'ref_id'                 => (int)($v['refId'] ?? 0),
			'created_at'             => (int)current_time('timestamp', true),
		]);
		// Replace rows
		$wpdb->delete($wpdb->prefix . self::TBL_VOUCHER_ROWS, ['voucher_id' => $id]);
		foreach ((array)($v['rows'] ?? []) as $i => $r) {
			$wpdb->insert($wpdb->prefix . self::TBL_VOUCHER_ROWS, [
				'voucher_id'    => $id,
				'row_no'        => $i + 1,
				'account_id'    => (string)($r['accountId'] ?? ''),
				'account_name'  => (string)($r['accountName'] ?? ''),
				'debit'         => (float)($r['debit'] ?? 0),
				'credit'        => (float)($r['credit'] ?? 0),
				'description'   => (string)($r['description'] ?? ''),
				'project_id'    => !empty($r['projectId'])    ? (int)$r['projectId']    : null,
				'customer_id'   => !empty($r['customerId'])   ? (int)$r['customerId']   : null,
				'expert_id'     => !empty($r['expertId'])     ? (int)$r['expertId']     : null,
				'cost_center_id'=> !empty($r['costCenterId']) ? (string)$r['costCenterId'] : null,
			]);
		}
	}

	private function insert_cheque_into_table($c){
		global $wpdb;
		$id = (string)($c['id'] ?? '');
		if (!$id) return;
		$wpdb->replace($wpdb->prefix . self::TBL_CHEQUES, [
			'id'                  => $id,
			'kind'                => (string)($c['kind'] ?? 'receivable'),
			'number'              => (string)($c['number'] ?? ''),
			'sayyadi'             => (string)($c['sayyadi'] ?? ''),
			'bank'                => (string)($c['bank'] ?? ''),
			'branch'              => (string)($c['branch'] ?? ''),
			'amount'              => (float)($c['amount'] ?? 0),
			'issue_date'          => (string)($c['issueDate'] ?? ''),
			'due_date'            => (string)($c['dueDate'] ?? ''),
			'party_type'          => (string)($c['partyType'] ?? ''),
			'party_id'            => (int)($c['partyId'] ?? 0),
			'party_name'          => (string)($c['partyName'] ?? ''),
			'project_id'          => (int)($c['projectId'] ?? 0),
			'treasury_account_id' => (string)($c['treasuryAccountId'] ?? ''),
			'status'              => (string)($c['status'] ?? 'received'),
			'description'         => (string)($c['description'] ?? ''),
			'attachment_id'       => (string)($c['attachmentId'] ?? ''),
			'created_at_fa'       => (string)($c['createdAt'] ?? $this->fa_today()),
			'created_at'          => (int)current_time('timestamp', true),
		]);
	}

	private function insert_payable_into_table($p){
		global $wpdb;
		$id = (string)($p['id'] ?? '');
		if (!$id) return;
		$wpdb->replace($wpdb->prefix . self::TBL_PAYABLES, [
			'id'            => $id,
			'party_type'    => (string)($p['partyType'] ?? 'other'),
			'party_id'      => (int)($p['partyId'] ?? 0),
			'party_name'    => (string)($p['partyName'] ?? ''),
			'amount'        => (float)($p['amount'] ?? 0),
			'paid_amount'   => (float)($p['paidAmount'] ?? 0),
			'issue_date'    => (string)($p['issueDate'] ?? ''),
			'due_date'      => (string)($p['dueDate'] ?? ''),
			'project_id'    => (int)($p['projectId'] ?? 0),
			'description'   => (string)($p['description'] ?? ''),
			'status'        => (string)($p['status'] ?? 'open'),
			'created_at_fa' => (string)($p['createdAt'] ?? $this->fa_today()),
			'created_at'    => (int)current_time('timestamp', true),
		]);
	}

	private function insert_installment_plan_into_table($pl){
		global $wpdb;
		$id = (string)($pl['id'] ?? '');
		if (!$id) return;
		$wpdb->replace($wpdb->prefix . self::TBL_INSTALLMENTS, [
			'id'                  => $id,
			'customer_id'         => (int)($pl['customerId'] ?? 0),
			'customer_name'       => (string)($pl['customerName'] ?? ''),
			'project_id'          => (int)($pl['projectId'] ?? 0),
			'project_name'        => (string)($pl['projectName'] ?? ''),
			'total_amount'        => (float)($pl['totalAmount'] ?? 0),
			'installments_count'  => (int)($pl['installmentsCount'] ?? 0),
			'interval_days'       => (int)($pl['intervalDays'] ?? 30),
			'first_due_date'      => (string)($pl['firstDueDate'] ?? ''),
			'description'         => (string)($pl['description'] ?? ''),
			'created_at_fa'       => (string)($pl['createdAt'] ?? $this->fa_today()),
			'created_at'          => (int)current_time('timestamp', true),
		]);
		$wpdb->delete($wpdb->prefix . self::TBL_INSTALLMENT_ROWS, ['plan_id' => $id]);
		foreach ((array)($pl['installments'] ?? []) as $row) {
			$wpdb->insert($wpdb->prefix . self::TBL_INSTALLMENT_ROWS, [
				'plan_id'     => $id,
				'no'          => (int)($row['no'] ?? 0),
				'due_date'    => (string)($row['dueDate'] ?? ''),
				'amount'      => (float)($row['amount'] ?? 0),
				'paid_amount' => (float)($row['paidAmount'] ?? 0),
				'paid_date'   => (string)($row['paidDate'] ?? ''),
				'status'      => (string)($row['status'] ?? 'unpaid'),
			]);
		}
	}

	/* ─── Phase 3: Table readers (used by build_* methods) ─── */

	private function read_vouchers_from_table($limit = 0, $where = '', $params = []){
		global $wpdb;
		$v_tbl = $wpdb->prefix . self::TBL_VOUCHERS;
		$r_tbl = $wpdb->prefix . self::TBL_VOUCHER_ROWS;
		// Detect missing table → fall back to legacy
		$exists = (int)$wpdb->get_var("SHOW TABLES LIKE '$v_tbl'");
		if (!$exists) return $this->get_vouchers_legacy();
		$sql = "SELECT * FROM $v_tbl";
		if ($where) $sql .= ' WHERE ' . $where;
		$sql .= ' ORDER BY created_at DESC, voucher_number DESC';
		if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit;
		$rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);
		if (!$rows) return [];
		$ids  = array_map(function($r){ return (string)$r->id; }, $rows);
		$ph   = implode(',', array_fill(0, count($ids), '%s'));
		$rrows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $r_tbl WHERE voucher_id IN ($ph) ORDER BY voucher_id, row_no", $ids));
		$by_v = [];
		foreach ($rrows as $rr) {
			$by_v[$rr->voucher_id][] = [
				'id'           => 'r' . (int)$rr->id,
				'accountId'    => (string)$rr->account_id,
				'accountName'  => (string)$rr->account_name,
				'debit'        => (float)$rr->debit,
				'credit'       => (float)$rr->credit,
				'description'  => (string)$rr->description,
				'projectId'    => is_null($rr->project_id)   ? null : (int)$rr->project_id,
				'customerId'   => is_null($rr->customer_id)  ? null : (int)$rr->customer_id,
				'expertId'     => is_null($rr->expert_id)    ? null : (int)$rr->expert_id,
				'costCenterId' => is_null($rr->cost_center_id) ? null : (string)$rr->cost_center_id,
			];
		}
		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'id'                   => (string)$r->id,
				'voucherNumber'        => (int)$r->voucher_number,
				'voucherCode'          => (string)$r->voucher_code,
				'voucherType'          => (string)$r->voucher_type,
				'date'                 => (string)$r->date_fa,
				'description'          => (string)$r->description,
				'status'               => (string)$r->status,
				'companyId'            => (string)$r->company_id,
				'branchId'             => (string)$r->branch_id,
				'fiscalYearId'         => (string)$r->fiscal_year_id,
				'currency'             => (string)$r->currency,
				'exchangeRate'         => (float)$r->exchange_rate,
				'rows'                 => $by_v[(string)$r->id] ?? [],
				'createdBy'            => (string)$r->created_by,
				'approvedByAccountant' => (string)$r->approved_by_accountant,
				'approvedByManager'    => (string)$r->approved_by_manager,
				'approvedByCEO'        => (string)$r->approved_by_ceo,
				'isAutoGenerated'      => (bool)$r->is_auto_generated,
				'sourceType'           => (string)$r->source_type,
				'refType'              => (string)$r->ref_type,
				'refId'                => (int)$r->ref_id,
			];
		}
		return $out;
	}

	/** Legacy (option-based) reader kept for fallback safety */
	private function get_vouchers_legacy(){
		$v = get_option(self::OPT_VOUCHERS, []);
		return is_array($v) ? $v : [];
	}

	/* ─── Audit log ─── */
	public function maybe_install_audit_table(){
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_AUDIT;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = "CREATE TABLE $tbl (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_name VARCHAR(190) DEFAULT NULL,
			action_type VARCHAR(40) NOT NULL,
			entity_type VARCHAR(40) NOT NULL,
			entity_id VARCHAR(64) NOT NULL DEFAULT '',
			before_data LONGTEXT,
			after_data LONGTEXT,
			created_at INT NOT NULL DEFAULT 0,
			date_fa VARCHAR(40) DEFAULT NULL,
			PRIMARY KEY (id),
			KEY user_idx (user_id),
			KEY action_idx (action_type),
			KEY entity_idx (entity_type)
		) $charset;";
		dbDelta($sql);
	}

	public function audit_log($action, $entity_type, $entity_id, $before = null, $after = null){
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_AUDIT;
		// Auto-install if missing
		$exists = (int) $wpdb->get_var("SHOW TABLES LIKE '$tbl'");
		if (!$exists) { $this->maybe_install_audit_table(); }
		$user = wp_get_current_user();
		$ts = (int) current_time('timestamp', true);
		$wpdb->insert($tbl, [
			'user_id'    => (int)$user->ID,
			'user_name'  => (string)$user->display_name,
			'action_type' => sanitize_key($action),
			'entity_type' => sanitize_key($entity_type),
			'entity_id'   => (string)$entity_id,
			'before_data' => $before === null ? null : wp_json_encode($before, JSON_UNESCAPED_UNICODE),
			'after_data'  => $after === null ? null : wp_json_encode($after, JSON_UNESCAPED_UNICODE),
			'created_at'  => $ts,
			'date_fa'     => $this->fa_date_of($ts),
		]);
	}

	public function ajax_audit_list(){
		$this->check_perm('audit_view');
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_AUDIT;
		$exists = (int) $wpdb->get_var("SHOW TABLES LIKE '$tbl'");
		if (!$exists) { $this->maybe_install_audit_table(); wp_send_json_success(['rows' => []]); }

		$action  = sanitize_key((string)($_POST['action_type'] ?? ''));
		$entity  = sanitize_key((string)($_POST['entity'] ?? ''));
		$user_id = (int)($_POST['user_id'] ?? 0);
		$df = sanitize_text_field((string)($_POST['date_from'] ?? ''));
		$dt = sanitize_text_field((string)($_POST['date_to'] ?? ''));
		// Phase 3: real pagination
		$per_page = max(10, min(200, (int)($_POST['per_page'] ?? 50)));
		$page     = max(1, (int)($_POST['page'] ?? 1));
		$offset   = ($page - 1) * $per_page;

		$where = ['1=1']; $params = [];
		if ($action) { $where[] = 'action_type=%s'; $params[] = $action; }
		if ($entity) { $where[] = 'entity_type=%s'; $params[] = $entity; }
		if ($user_id){ $where[] = 'user_id=%d'; $params[] = $user_id; }
		if ($df)     { $where[] = 'date_fa>=%s'; $params[] = $df; }
		if ($dt)     { $where[] = 'date_fa<=%s'; $params[] = $dt; }

		$where_sql = implode(' AND ', $where);
		$total_sql = "SELECT COUNT(*) FROM $tbl WHERE $where_sql";
		$total = $params ? (int)$wpdb->get_var($wpdb->prepare($total_sql, $params)) : (int)$wpdb->get_var($total_sql);

		$sql = "SELECT * FROM $tbl WHERE $where_sql ORDER BY id DESC LIMIT $per_page OFFSET $offset";
		$rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);
		$out = [];
		foreach ((array)$rows as $r) {
			$out[] = [
				'id'          => (int)$r->id,
				'user_id'     => (int)$r->user_id,
				'user_name'   => (string)$r->user_name,
				'action'      => (string)$r->action_type,
				'entity_type' => (string)$r->entity_type,
				'entity_id'   => (string)$r->entity_id,
				'before_data' => $r->before_data ? json_decode($r->before_data, true) : null,
				'after_data'  => $r->after_data ? json_decode($r->after_data, true) : null,
				'date'        => $r->date_fa ?: $this->fa_date_of((int)$r->created_at),
			];
		}
		wp_send_json_success([
			'rows'       => $out,
			'total'      => $total,
			'page'       => $page,
			'per_page'   => $per_page,
			'total_pages'=> (int)ceil($total / $per_page),
		]);
	}

	/* ─── Permissions matrix ─── */
	private function get_role_permissions(){
		$v = get_option(self::OPT_PERMS, []);
		return is_array($v) ? $v : [];
	}

	public function ajax_permissions_save(){
		$this->check_perm('settings_manage');
		if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
		$raw = isset($_POST['matrix']) ? wp_unslash($_POST['matrix']) : '';
		$matrix = json_decode($raw, true);
		if (!is_array($matrix)) wp_send_json_error('invalid', 400);
		$before = $this->get_role_permissions();
		update_option(self::OPT_PERMS, $matrix, false);
		$this->audit_log('update', 'permissions', 'role_matrix', $before, $matrix);
		wp_send_json_success();
	}

	/* ═════════════════════════════════════════════════════════════════
	 * ▼▼▼ Phase 2 — Account Mapping + Live Balances + Standard COA ▼▼▼
	 * ═════════════════════════════════════════════════════════════════ */

	/**
	 * Return current account mapping merged with defaults + list of CoA
	 * options (id, code, name, type) for select widgets, plus a snapshot
	 * of treasury accounts for the per-treasury override UI.
	 */
	public function ajax_mapping_get(){
		$this->check();
		$mapping = $this->get_account_mapping();
		$treasury_map = $this->get_treasury_coa_map();
		$coa = $this->get_coa();
		// Treasury accounts list for per-account override widget
		$treasury = [];
		if (class_exists('CPTT_Finance')) {
			global $wpdb;
			$rows = $wpdb->get_results('SELECT id, name, type FROM ' . CPTT_Finance::tbl_accounts() . ' WHERE status=1 ORDER BY id ASC');
			foreach ((array)$rows as $r) {
				$treasury[] = ['id' => 't' . (int)$r->id, 'numericId' => (int)$r->id, 'name' => (string)$r->name, 'type' => (string)$r->type];
			}
		}
		wp_send_json_success([
			'mapping'       => $mapping,
			'defaults'      => $this->default_account_mapping(),
			'treasury_map'  => $treasury_map,
			'accounts'      => $coa,
			'treasury'      => $treasury,
			'purposes'      => $this->account_mapping_purpose_labels(),
		]);
	}

	/**
	 * Save the user-supplied mapping. Validates that every value (if
	 * non-empty) is an actual CoA node id.
	 */
	public function ajax_mapping_save(){
		$this->check_perm('mapping_manage');
		if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
		$raw = isset($_POST['mapping']) ? wp_unslash($_POST['mapping']) : '';
		$new = json_decode($raw, true);
		if (!is_array($new)) wp_send_json_error('invalid', 400);
		$coa_ids = array_map(function($n){ return (string)($n['id'] ?? ''); }, $this->get_coa());
		$coa_ids = array_filter($coa_ids);
		$defaults = $this->default_account_mapping();
		$clean = [];
		foreach ($defaults as $key => $_def) {
			$val = isset($new[$key]) ? (string)$new[$key] : '';
			if ($val !== '' && !in_array($val, $coa_ids, true)) {
				wp_send_json_error('حساب نامعتبر برای ' . $key . ': ' . $val, 400);
			}
			$clean[$key] = $val;
		}
		$before = $this->get_account_mapping();
		update_option(self::OPT_ACCOUNT_MAPPING, $clean, false);
		$this->audit_log('update', 'account_mapping', 'global', $before, $clean);

		// Also accept treasury per-account override map if provided
		if (isset($_POST['treasury_map'])) {
			$tm_raw = wp_unslash($_POST['treasury_map']);
			$tm     = json_decode($tm_raw, true);
			if (is_array($tm)) {
				$tm_clean = [];
				foreach ($tm as $tid => $cid) {
					$tid_int = (int)$tid;
					$cid     = (string)$cid;
					if ($tid_int <= 0) continue;
					if ($cid !== '' && !in_array($cid, $coa_ids, true)) continue;
					if ($cid !== '') $tm_clean[(string)$tid_int] = $cid;
				}
				update_option(self::OPT_TREASURY_COA_MAP, $tm_clean, false);
			}
		}

		wp_send_json_success(['mapping' => $clean]);
	}

	/**
	 * Persian labels for purpose keys (used in the UI to render the form).
	 */
	private function account_mapping_purpose_labels(){
		return [
			'cash_default'       => ['label' => 'صندوق پیش‌فرض',    'group' => 'دارایی', 'hint' => 'صندوق پیش‌فرض برای حساب‌های نقدی (CASH)'],
			'bank_default'       => ['label' => 'بانک پیش‌فرض',     'group' => 'دارایی', 'hint' => 'حساب بانک پیش‌فرض برای حساب‌های بانکی (BANK)'],
			'receivable_default' => ['label' => 'بدهکاران تجاری',   'group' => 'دارایی', 'hint' => 'مطالبات از مشتریان پروژه‌ها'],
			'notes_receivable'   => ['label' => 'اسناد دریافتنی',   'group' => 'دارایی', 'hint' => 'برای چک‌های دریافتی'],
			'payable_default'    => ['label' => 'بستانکاران تجاری', 'group' => 'بدهی',   'hint' => 'بدهی به پیمانکار/تامین‌کننده'],
			'notes_payable'      => ['label' => 'اسناد پرداختنی',   'group' => 'بدهی',   'hint' => 'برای چک‌های پرداختی'],
			'expert_payable'     => ['label' => 'بدهی به کارشناسان','group' => 'بدهی',   'hint' => 'حساب بستانکار کارشناسان'],
			'revenue_default'    => ['label' => 'درآمد ارائه خدمات','group' => 'درآمد',  'hint' => 'برای ثبت درآمدها'],
			'other_income'       => ['label' => 'سایر درآمدها',     'group' => 'درآمد',  'hint' => 'برای واریزهای متفرقه (deposit دستی)'],
			'expense_default'    => ['label' => 'هزینه پیش‌فرض',     'group' => 'هزینه',  'hint' => 'برای ثبت هزینه‌های اداری/عمومی'],
			'expert_payroll'     => ['label' => 'دستمزد کارشناس',   'group' => 'هزینه',  'hint' => 'هزینه‌ی پرداخت به کارشناسان'],
			'transfer_clearing'  => ['label' => 'حساب کلیرینگ انتقال','group' => 'اختیاری','hint' => 'در صورت استفاده از روش ۴-طرفه برای transfer'],
			'retained_earnings'  => ['label' => 'سود (زیان) انباشته', 'group' => 'بستن سال', 'hint' => 'حساب نهایی که سود/زیان دوره را نگهداری می‌کند'],
			'profit_loss_summary'=> ['label' => 'خلاصه سود/زیان',     'group' => 'بستن سال', 'hint' => 'حساب کنترلی برای جمع‌بندی درآمد/هزینه قبل از انتقال به سود انباشته'],
		];
	}

	/**
	 * Save per-treasury → CoA override (sub-API of mapping). Separate
	 * endpoint for finer permission control if needed.
	 */
	public function ajax_coa_treasury_map_save(){
		$this->check_perm('mapping_manage');
		if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
		$raw = isset($_POST['map']) ? wp_unslash($_POST['map']) : '';
		$tm  = json_decode($raw, true);
		if (!is_array($tm)) wp_send_json_error('invalid', 400);
		$coa_ids = array_filter(array_map(function($n){ return (string)($n['id'] ?? ''); }, $this->get_coa()));
		$clean = [];
		foreach ($tm as $tid => $cid) {
			$tid_int = (int)$tid; $cid = (string)$cid;
			if ($tid_int > 0 && $cid !== '' && in_array($cid, $coa_ids, true)) {
				$clean[(string)$tid_int] = $cid;
			}
		}
		update_option(self::OPT_TREASURY_COA_MAP, $clean, false);
		wp_send_json_success(['count' => count($clean)]);
	}

	/**
	 * Compute live balance for every CoA node based on the vouchers
	 * option. Returns {coa_id: {debit, credit, balance}}.
	 * The balance is rolled-up: parents include the sum of all descendants.
	 */
	public function ajax_coa_balances(){
		$this->check();
		$coa = $this->get_coa();
		$vouchers = $this->get_vouchers();
		// Build parent index
		$by_id = [];
		foreach ($coa as $n) $by_id[(string)$n['id']] = $n;
		// Build chain map: each node → list of self + ancestors
		$chain = [];
		foreach ($coa as $n) {
			$ch = []; $cur = $n;
			while ($cur) {
				$ch[] = (string)$cur['id'];
				$pid  = $cur['parentId'] ?? null;
				$cur  = $pid && isset($by_id[$pid]) ? $by_id[$pid] : null;
			}
			$chain[(string)$n['id']] = $ch;
		}
		// Aggregate
		$agg = [];
		foreach ($vouchers as $v) {
			$st = (string)($v['status'] ?? '');
			// Only count finalized/approved vouchers
			if (!in_array($st, ['FINALIZED','MANAGER_APPROVED','ACCOUNTANT_APPROVED'], true)) continue;
			foreach ((array)($v['rows'] ?? []) as $r) {
				$aid = (string)($r['accountId'] ?? '');
				if (!$aid || !isset($chain[$aid])) continue;
				$d = (float)($r['debit']  ?? 0);
				$c = (float)($r['credit'] ?? 0);
				foreach ($chain[$aid] as $cid) {
					if (!isset($agg[$cid])) $agg[$cid] = ['debit'=>0.0,'credit'=>0.0,'balance'=>0.0];
					$agg[$cid]['debit']  += $d;
					$agg[$cid]['credit'] += $c;
					$agg[$cid]['balance'] = $agg[$cid]['debit'] - $agg[$cid]['credit'];
				}
			}
		}
		wp_send_json_success(['balances' => $agg]);
	}

	/**
	 * Standard Iran COA structure (سرفصل استاندارد جامعه‌ی حسابداران رسمی).
	 * Imported on demand. Existing accounts are kept; only new ones are added.
	 */
	private function standard_iran_coa(){
		return [
			// ── دارایی‌های جاری (۱) ──
			['id'=>'a1',    'code'=>'۱',  'name'=>'دارایی‌های جاری',                'type'=>'group',      'parentId'=>null],
			['id'=>'a1_1',  'code'=>'۱۱', 'name'=>'موجودی نقد و بانک',              'type'=>'general',    'parentId'=>'a1'],
			['id'=>'a1_1_1','code'=>'۱۱۰۱','name'=>'بانک‌های ریالی',                  'type'=>'subsidiary','parentId'=>'a1_1'],
			['id'=>'a1_1_2','code'=>'۱۱۰۲','name'=>'صندوق‌های ریالی',                'type'=>'subsidiary','parentId'=>'a1_1'],
			['id'=>'a1_1_3','code'=>'۱۱۰۳','name'=>'اسناد دریافتنی (چک‌های دریافتی)','type'=>'subsidiary','parentId'=>'a1_1'],
			['id'=>'a1_1_4','code'=>'۱۱۰۴','name'=>'وجوه در راه',                    'type'=>'subsidiary','parentId'=>'a1_1'],
			['id'=>'a1_2',  'code'=>'۱۲', 'name'=>'حساب‌ها و اسناد دریافتنی تجاری',  'type'=>'general',    'parentId'=>'a1'],
			['id'=>'a1_2_1','code'=>'۱۲۰۱','name'=>'بدهکاران تجاری (مشتریان)',       'type'=>'subsidiary','parentId'=>'a1_2'],
			['id'=>'a1_2_2','code'=>'۱۲۰۲','name'=>'بدهکاران غیرتجاری',              'type'=>'subsidiary','parentId'=>'a1_2'],
			['id'=>'a1_2_3','code'=>'۱۲۰۳','name'=>'پیش‌پرداخت‌ها',                   'type'=>'subsidiary','parentId'=>'a1_2'],
			['id'=>'a1_3',  'code'=>'۱۳', 'name'=>'موجودی کالا',                    'type'=>'general',    'parentId'=>'a1'],
			['id'=>'a1_3_1','code'=>'۱۳۰۱','name'=>'موجودی مواد اولیه',             'type'=>'subsidiary','parentId'=>'a1_3'],
			['id'=>'a1_3_2','code'=>'۱۳۰۲','name'=>'موجودی کالای ساخته‌شده',         'type'=>'subsidiary','parentId'=>'a1_3'],
			['id'=>'a1_4',  'code'=>'۱۴', 'name'=>'سفارشات و پیش‌پرداخت‌ها',         'type'=>'general',    'parentId'=>'a1'],

			// ── دارایی‌های غیرجاری (۲) ──
			['id'=>'a2',    'code'=>'۲',  'name'=>'دارایی‌های غیرجاری',             'type'=>'group',      'parentId'=>null],
			['id'=>'a2_1',  'code'=>'۲۱', 'name'=>'دارایی‌های ثابت مشهود',           'type'=>'general',    'parentId'=>'a2'],
			['id'=>'a2_1_1','code'=>'۲۱۰۱','name'=>'زمین',                          'type'=>'subsidiary','parentId'=>'a2_1'],
			['id'=>'a2_1_2','code'=>'۲۱۰۲','name'=>'ساختمان',                       'type'=>'subsidiary','parentId'=>'a2_1'],
			['id'=>'a2_1_3','code'=>'۲۱۰۳','name'=>'ماشین‌آلات و تجهیزات',           'type'=>'subsidiary','parentId'=>'a2_1'],
			['id'=>'a2_1_4','code'=>'۲۱۰۴','name'=>'اثاثیه و منصوبات اداری',         'type'=>'subsidiary','parentId'=>'a2_1'],
			['id'=>'a2_1_5','code'=>'۲۱۰۵','name'=>'وسایل نقلیه',                    'type'=>'subsidiary','parentId'=>'a2_1'],
			['id'=>'a2_2',  'code'=>'۲۲', 'name'=>'استهلاک انباشته',                'type'=>'general',    'parentId'=>'a2'],
			['id'=>'a2_3',  'code'=>'۲۳', 'name'=>'دارایی‌های نامشهود',             'type'=>'general',    'parentId'=>'a2'],

			// ── بدهی‌های جاری (۳) ──
			['id'=>'a3',    'code'=>'۳',  'name'=>'بدهی‌های جاری',                  'type'=>'group',      'parentId'=>null],
			['id'=>'a3_1',  'code'=>'۳۱', 'name'=>'حساب‌ها و اسناد پرداختنی',        'type'=>'general',    'parentId'=>'a3'],
			['id'=>'a3_1_1','code'=>'۳۱۰۱','name'=>'بستانکاران تجاری',              'type'=>'subsidiary','parentId'=>'a3_1'],
			['id'=>'a3_1_2','code'=>'۳۱۰۲','name'=>'اسناد پرداختنی (چک‌های پرداختی)','type'=>'subsidiary','parentId'=>'a3_1'],
			['id'=>'a3_1_3','code'=>'۳۱۰۳','name'=>'بدهی به کارشناسان',             'type'=>'subsidiary','parentId'=>'a3_1'],
			['id'=>'a3_1_4','code'=>'۳۱۰۴','name'=>'بدهی به کارکنان',               'type'=>'subsidiary','parentId'=>'a3_1'],
			['id'=>'a3_2',  'code'=>'۳۲', 'name'=>'پیش‌دریافت‌ها',                  'type'=>'general',    'parentId'=>'a3'],
			['id'=>'a3_3',  'code'=>'۳۳', 'name'=>'مالیات و عوارض پرداختنی',       'type'=>'general',    'parentId'=>'a3'],
			['id'=>'a3_3_1','code'=>'۳۳۰۱','name'=>'مالیات بر درآمد',               'type'=>'subsidiary','parentId'=>'a3_3'],
			['id'=>'a3_3_2','code'=>'۳۳۰۲','name'=>'مالیات بر ارزش افزوده',         'type'=>'subsidiary','parentId'=>'a3_3'],
			['id'=>'a3_3_3','code'=>'۳۳۰۳','name'=>'بیمه‌ی سهم کارفرما',             'type'=>'subsidiary','parentId'=>'a3_3'],
			['id'=>'a3_4',  'code'=>'۳۴', 'name'=>'تسهیلات کوتاه‌مدت بانکی',        'type'=>'general',    'parentId'=>'a3'],

			// ── حقوق صاحبان سهام (۴) ──
			['id'=>'a4',    'code'=>'۴',  'name'=>'حقوق صاحبان سهام',               'type'=>'group',      'parentId'=>null],
			['id'=>'a4_1',  'code'=>'۴۱', 'name'=>'سرمایه',                         'type'=>'general',    'parentId'=>'a4'],
			['id'=>'a4_2',  'code'=>'۴۲', 'name'=>'اندوخته قانونی',                 'type'=>'general',    'parentId'=>'a4'],
			['id'=>'a4_3',  'code'=>'۴۳', 'name'=>'سود (زیان) انباشته',             'type'=>'general',    'parentId'=>'a4'],

			// ── درآمدها (۵) ──
			['id'=>'a5',    'code'=>'۵',  'name'=>'درآمدها',                        'type'=>'group',      'parentId'=>null],
			['id'=>'a5_1',  'code'=>'۵۱', 'name'=>'درآمد ارائه خدمات',              'type'=>'general',    'parentId'=>'a5'],
			['id'=>'a5_2',  'code'=>'۵۲', 'name'=>'سایر درآمدها',                   'type'=>'general',    'parentId'=>'a5'],
			['id'=>'a5_3',  'code'=>'۵۳', 'name'=>'درآمد فروش کالا',                'type'=>'general',    'parentId'=>'a5'],
			['id'=>'a5_4',  'code'=>'۵۴', 'name'=>'سود حاصل از سرمایه‌گذاری',        'type'=>'general',    'parentId'=>'a5'],

			// ── هزینه‌ها (۶) ──
			['id'=>'a6',    'code'=>'۶',  'name'=>'هزینه‌ها',                       'type'=>'group',      'parentId'=>null],
			['id'=>'a6_1',  'code'=>'۶۱', 'name'=>'هزینه حقوق و دستمزد',           'type'=>'general',    'parentId'=>'a6'],
			['id'=>'a6_2',  'code'=>'۶۲', 'name'=>'هزینه‌های اداری و عمومی',        'type'=>'general',    'parentId'=>'a6'],
			['id'=>'a6_2_1','code'=>'۶۲۰۱','name'=>'اجاره',                         'type'=>'subsidiary','parentId'=>'a6_2'],
			['id'=>'a6_2_2','code'=>'۶۲۰۲','name'=>'آب، برق، گاز، تلفن',            'type'=>'subsidiary','parentId'=>'a6_2'],
			['id'=>'a6_2_3','code'=>'۶۲۰۳','name'=>'اینترنت و خدمات IT',           'type'=>'subsidiary','parentId'=>'a6_2'],
			['id'=>'a6_2_4','code'=>'۶۲۰۴','name'=>'لوازم اداری و مصرفی',          'type'=>'subsidiary','parentId'=>'a6_2'],
			['id'=>'a6_2_5','code'=>'۶۲۰۵','name'=>'حمل و نقل',                     'type'=>'subsidiary','parentId'=>'a6_2'],
			['id'=>'a6_3',  'code'=>'۶۳', 'name'=>'هزینه‌های فروش و بازاریابی',     'type'=>'general',    'parentId'=>'a6'],
			['id'=>'a6_4',  'code'=>'۶۴', 'name'=>'هزینه‌های مالی',                 'type'=>'general',    'parentId'=>'a6'],
			['id'=>'a6_4_1','code'=>'۶۴۰۱','name'=>'هزینه بهره و کارمزد',          'type'=>'subsidiary','parentId'=>'a6_4'],
			['id'=>'a6_5',  'code'=>'۶۵', 'name'=>'هزینه استهلاک',                  'type'=>'general',    'parentId'=>'a6'],
			['id'=>'a6_6',  'code'=>'۶۶', 'name'=>'سایر هزینه‌ها',                  'type'=>'general',    'parentId'=>'a6'],
		];
	}

	/**
	 * Import standard Iran COA. Mode `merge` keeps existing nodes; mode
	 * `replace` wipes and re-installs. Both add `balance=0` to every node.
	 */
	public function ajax_coa_import_standard(){
		$this->check_perm('mapping_manage');
		if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
		$mode = sanitize_key((string)($_POST['mode'] ?? 'merge'));
		if (!in_array($mode, ['merge','replace'], true)) $mode = 'merge';
		$std = $this->standard_iran_coa();
		foreach ($std as &$n) { $n['balance'] = 0; }
		unset($n);
		if ($mode === 'replace') {
			update_option(self::OPT_COA, $std, false);
			$this->audit_log('replace', 'coa', 'standard_import', null, ['count' => count($std)]);
			wp_send_json_success(['count' => count($std), 'mode' => 'replace']);
		}
		// merge
		$existing = $this->get_coa();
		$ids = array_map(function($n){ return (string)$n['id']; }, $existing);
		$added = 0;
		foreach ($std as $n) {
			if (!in_array($n['id'], $ids, true)) {
				$existing[] = $n;
				$added++;
			}
		}
		update_option(self::OPT_COA, $existing, false);
		$this->audit_log('import', 'coa', 'standard_merge', null, ['added' => $added]);
		wp_send_json_success(['added' => $added, 'mode' => 'merge', 'total' => count($existing)]);
	}

	/* Hook audit into other operations (best-effort wrappers) */

	/* ═════════════════════════════════════════════════════════════════
	 * ▼▼▼ Phase 2 — Cheques / Installments / Payables / Notif / Attach ▼▼▼
	 * ═════════════════════════════════════════════════════════════════ */

	private function get_cheques(){
		// Phase 3: prefer table; fallback to option
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_CHEQUES;
		if ((int)$wpdb->get_var("SHOW TABLES LIKE '$tbl'")) {
			$rows = $wpdb->get_results("SELECT * FROM $tbl ORDER BY created_at DESC");
			$out = [];
			foreach ($rows as $r) {
				$out[] = [
					'id'                => (string)$r->id,
					'kind'              => (string)$r->kind,
					'number'            => (string)$r->number,
					'sayyadi'           => (string)$r->sayyadi,
					'bank'              => (string)$r->bank,
					'branch'            => (string)$r->branch,
					'amount'            => (float)$r->amount,
					'issueDate'         => (string)$r->issue_date,
					'dueDate'           => (string)$r->due_date,
					'partyType'         => (string)$r->party_type,
					'partyId'           => (int)$r->party_id,
					'partyName'         => (string)$r->party_name,
					'projectId'         => (int)$r->project_id,
					'treasuryAccountId' => (string)$r->treasury_account_id,
					'status'            => (string)$r->status,
					'description'       => (string)$r->description,
					'attachmentId'      => (string)$r->attachment_id,
					'createdAt'         => (string)$r->created_at_fa,
				];
			}
			return $out;
		}
		$v = get_option(self::OPT_CHEQUES, []); return is_array($v) ? $v : [];
	}
	private function save_cheques($a){
		// Phase 3: write entire array to table (legacy callers send full array)
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_CHEQUES;
		if ((int)$wpdb->get_var("SHOW TABLES LIKE '$tbl'")) {
			// Strategy: rebuild table from $a (idempotent — callers always send full list)
			$wpdb->query("TRUNCATE TABLE $tbl");
			foreach ((array)$a as $c) $this->insert_cheque_into_table($c);
		}
		// Keep option as legacy snapshot
		update_option(self::OPT_CHEQUES, $a, false);
	}

	public function ajax_cheques_list(){ $this->check(); wp_send_json_success(['rows' => $this->get_cheques()]); }

	public function ajax_cheque_save(){
		$this->check_perm('treasury_payment');
		$raw = isset($_POST['cheque']) ? wp_unslash($_POST['cheque']) : '';
		$c = json_decode($raw, true);
		if (!is_array($c) || empty($c['number']) || empty($c['amount']) || empty($c['dueDate'])) wp_send_json_error('invalid', 400);
		// Phase 5: enforce period lock based on issue date (or today)
		$this->reject_if_locked((string)($c['issueDate'] ?? $this->fa_today()), 'ثبت چک');
		$list = $this->get_cheques();
		$id = !empty($c['id']) ? (string)$c['id'] : ('chq_' . time() . '_' . wp_generate_password(4, false, false));
		$row = wp_parse_args($c, [
			'id' => $id, 'kind' => 'receivable', 'number' => '', 'sayyadi' => '', 'bank' => '',
			'branch' => '', 'amount' => 0, 'issueDate' => '', 'dueDate' => '',
			'partyType' => '', 'partyId' => 0, 'partyName' => '',
			'projectId' => 0, 'treasuryAccountId' => '', 'status' => 'received',
			'description' => '', 'createdAt' => $this->fa_today(),
		]);
		$row['id'] = $id;
		$existing = null;
		foreach ($list as $i => $cc) if ((string)$cc['id'] === $id) { $existing = $cc; $list[$i] = $row; break; }
		if (!$existing) array_unshift($list, $row);
		$this->save_cheques($list);
		$this->audit_log($existing ? 'update' : 'create', 'cheque', $id, $existing, $row);

		if (!$existing) {
			// Auto-voucher on receipt of cheque (RV or PV depending kind)
			$kind = $row['kind'];
			$amount = (float)$row['amount'];
			$person = $row['partyName'] ?: '';
			if ($kind === 'receivable') {
				$this->gen_voucher([
					'type' => 'JV',
					'description' => 'ثبت چک دریافتی از ' . $person . ' — شماره ' . $row['number'],
					'source_type' => 'invoice', 'ref_type' => 'cheque_in', 'ref_id' => 0,
					'rows' => [
						['id'=>'r1','accountId'=>$this->map_account('notes_receivable'),'accountName'=>$this->coa_name($this->map_account('notes_receivable')),'debit'=>$amount,'credit'=>0,'description'=>'چک دریافتی','customerId'=>(int)$row['partyId'],'projectId'=>(int)$row['projectId']],
						['id'=>'r2','accountId'=>$this->map_account('receivable_default'),'accountName'=>$this->coa_name($this->map_account('receivable_default')),'debit'=>0,'credit'=>$amount,'description'=>'مشتری','customerId'=>(int)$row['partyId'],'projectId'=>(int)$row['projectId']],
					],
				]);
			} else {
				$this->gen_voucher([
					'type' => 'JV',
					'description' => 'صدور چک پرداختی به ' . $person . ' — شماره ' . $row['number'],
					'source_type' => 'payment', 'ref_type' => 'cheque_out', 'ref_id' => 0,
					'rows' => [
						['id'=>'r1','accountId'=>$this->map_account('payable_default'),'accountName'=>$this->coa_name($this->map_account('payable_default')),'debit'=>$amount,'credit'=>0,'description'=>'تعهد به ذی‌نفع','expertId'=>(int)$row['partyId']],
						['id'=>'r2','accountId'=>$this->map_account('notes_payable'),'accountName'=>$this->coa_name($this->map_account('notes_payable')),'debit'=>0,'credit'=>$amount,'description'=>'صدور چک','expertId'=>(int)$row['partyId']],
					],
				]);
			}
		}

		wp_send_json_success(['id' => $id]);
	}

	public function ajax_cheque_delete(){
		$this->check_perm('treasury_payment');
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		$list = $this->get_cheques();
		$before = null;
		foreach ($list as $i => $c) if ((string)$c['id'] === $id) { $before = $c; unset($list[$i]); break; }
		$this->save_cheques(array_values($list));
		if ($before) $this->audit_log('delete', 'cheque', $id, $before, null);
		wp_send_json_success();
	}

	public function ajax_cheque_status(){
		$this->check_perm('treasury_payment');
		$this->reject_if_locked($this->fa_today(), 'تغییر وضعیت چک');
		$id     = sanitize_text_field((string)($_POST['id'] ?? ''));
		$status = sanitize_text_field((string)($_POST['status'] ?? ''));
		$acc    = sanitize_text_field((string)($_POST['account_id'] ?? ''));
		$note   = sanitize_text_field((string)($_POST['note'] ?? ''));
		$list = $this->get_cheques();
		$before = null; $row = null;
		foreach ($list as $i => $c) if ((string)$c['id'] === $id) { $before = $c; $list[$i]['status'] = $status; $row = $list[$i]; break; }
		if (!$row) wp_send_json_error('not_found', 404);
		$this->save_cheques($list);
		$this->audit_log('update', 'cheque_status', $id, $before, $row);

		// Auto-voucher when collected or paid
		$amount = (float)$row['amount'];
		$person = $row['partyName'] ?: '';
		if ($status === 'collected' && $row['kind'] === 'receivable') {
			// Update treasury balance
			if (class_exists('CPTT_Finance') && preg_match('/^t(\d+)$/', $acc, $m)) {
				CPTT_Finance::ledger_insert([
					'account_id' => (int)$m[1], 'type' => 'income', 'direction' => 1, 'amount' => $amount,
					'project_id' => (int)$row['projectId'], 'customer_id' => (int)$row['partyId'],
					'description' => 'وصول چک ' . $row['number'] . ' از ' . $person . ($note ? ' — ' . $note : ''),
					'date_at' => (int)current_time('timestamp', true), 'ref_type' => 'cheque_collect',
				]);
			}
			$this->gen_voucher([
				'type' => 'RV', 'description' => 'وصول چک از ' . $person,
				'source_type' => 'income', 'ref_type' => 'cheque_collect', 'ref_id' => 0,
				'rows' => [
					['id'=>'r1','accountId'=>$this->map_account('bank_default'),'accountName'=>$this->coa_name($this->map_account('bank_default')),'debit'=>$amount,'credit'=>0,'description'=>'دریافت وجه','customerId'=>(int)$row['partyId']],
					['id'=>'r2','accountId'=>$this->map_account('notes_receivable'),'accountName'=>$this->coa_name($this->map_account('notes_receivable')),'debit'=>0,'credit'=>$amount,'description'=>'تسویه چک','customerId'=>(int)$row['partyId']],
				],
			]);
		} elseif ($status === 'paid' && $row['kind'] === 'payable') {
			if (class_exists('CPTT_Finance') && preg_match('/^t(\d+)$/', $acc, $m)) {
				CPTT_Finance::ledger_insert([
					'account_id' => (int)$m[1], 'type' => 'expense', 'direction' => -1, 'amount' => $amount,
					'project_id' => (int)$row['projectId'], 'expert_id' => (int)$row['partyId'],
					'description' => 'پرداخت چک ' . $row['number'] . ' به ' . $person . ($note ? ' — ' . $note : ''),
					'date_at' => (int)current_time('timestamp', true), 'ref_type' => 'cheque_pay',
				]);
			}
			$this->gen_voucher([
				'type' => 'PV', 'description' => 'پرداخت چک به ' . $person,
				'source_type' => 'payment', 'ref_type' => 'cheque_pay', 'ref_id' => 0,
				'rows' => [
					['id'=>'r1','accountId'=>$this->map_account('notes_payable'),'accountName'=>$this->coa_name($this->map_account('notes_payable')),'debit'=>$amount,'credit'=>0,'description'=>'تسویه چک پرداختی','expertId'=>(int)$row['partyId']],
					['id'=>'r2','accountId'=>$this->map_account('bank_default'),'accountName'=>$this->coa_name($this->map_account('bank_default')),'debit'=>0,'credit'=>$amount,'description'=>'برداشت وجه','expertId'=>(int)$row['partyId']],
				],
			]);
		}
		wp_send_json_success();
	}

	/* ─── Installments ─── */
	private function get_installment_plans(){
		// Phase 3: prefer table
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_INSTALLMENTS;
		$rtbl = $wpdb->prefix . self::TBL_INSTALLMENT_ROWS;
		if ((int)$wpdb->get_var("SHOW TABLES LIKE '$tbl'")) {
			$plans = $wpdb->get_results("SELECT * FROM $tbl ORDER BY created_at DESC");
			if (!$plans) return [];
			$ids = array_map(function($p){ return (string)$p->id; }, $plans);
			$ph  = implode(',', array_fill(0, count($ids), '%s'));
			$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $rtbl WHERE plan_id IN ($ph) ORDER BY plan_id, no", $ids));
			$by_p = [];
			foreach ($rows as $r) {
				$by_p[(string)$r->plan_id][] = [
					'no'         => (int)$r->no,
					'dueDate'    => (string)$r->due_date,
					'amount'     => (float)$r->amount,
					'paidAmount' => (float)$r->paid_amount,
					'paidDate'   => (string)$r->paid_date,
					'status'     => (string)$r->status,
				];
			}
			$out = [];
			foreach ($plans as $p) {
				$out[] = [
					'id'                => (string)$p->id,
					'customerId'        => (int)$p->customer_id,
					'customerName'      => (string)$p->customer_name,
					'projectId'         => (int)$p->project_id,
					'projectName'       => (string)$p->project_name,
					'totalAmount'       => (float)$p->total_amount,
					'installmentsCount' => (int)$p->installments_count,
					'intervalDays'      => (int)$p->interval_days,
					'firstDueDate'      => (string)$p->first_due_date,
					'description'       => (string)$p->description,
					'installments'      => $by_p[(string)$p->id] ?? [],
					'createdAt'         => (string)$p->created_at_fa,
				];
			}
			return $out;
		}
		$v = get_option(self::OPT_INSTALLMENTS, []); return is_array($v) ? $v : [];
	}
	private function save_installment_plans($a){
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_INSTALLMENTS;
		$rtbl = $wpdb->prefix . self::TBL_INSTALLMENT_ROWS;
		if ((int)$wpdb->get_var("SHOW TABLES LIKE '$tbl'")) {
			$wpdb->query("TRUNCATE TABLE $tbl");
			$wpdb->query("TRUNCATE TABLE $rtbl");
			foreach ((array)$a as $pl) $this->insert_installment_plan_into_table($pl);
		}
		update_option(self::OPT_INSTALLMENTS, $a, false);
	}

	private function get_installment_plans_recomputed(){
		$today = $this->fa_today();
		$plans = $this->get_installment_plans();
		foreach ($plans as &$plan) {
			if (!isset($plan['installments']) || !is_array($plan['installments'])) continue;
			foreach ($plan['installments'] as &$inst) {
				$paid = (float)($inst['paidAmount'] ?? 0);
				$amount = (float)($inst['amount'] ?? 0);
				if ($paid >= $amount && $amount > 0) {
					$inst['status'] = 'paid';
				} elseif (!empty($inst['dueDate']) && strcmp($inst['dueDate'], $today) < 0) {
					$inst['status'] = 'overdue';
				} else {
					$inst['status'] = 'unpaid';
				}
			}
		}
		return $plans;
	}

	public function ajax_installments_list(){ $this->check(); wp_send_json_success(['rows' => $this->get_installment_plans_recomputed()]); }

	public function ajax_installment_plan_create(){
		$this->check_perm('treasury_payment');
		$raw = isset($_POST['plan']) ? wp_unslash($_POST['plan']) : '';
		$p = json_decode($raw, true);
		if (!is_array($p) || empty($p['customerId']) || empty($p['totalAmount']) || empty($p['installmentsCount']) || empty($p['firstDueDate'])) wp_send_json_error('invalid', 400);

		$count = (int)$p['installmentsCount'];
		$interval = (int)$p['intervalDays'] ?: 30;
		$first = (string)$p['firstDueDate'];
		$amount = (float)$p['totalAmount'];
		$each = $count > 0 ? round($amount / $count) : 0;

		// Generate due dates (Jalali → Gregorian → add days → Jalali)
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$installments = [];
		$first_en = str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], ['0','1','2','3','4','5','6','7','8','9'], $first);
		$m = preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $first_en, $mm);
		if (!$m) wp_send_json_error('bad_date', 400);
		$jy = (int)$mm[1]; $jm = (int)$mm[2]; $jd = (int)$mm[3];
		$g = CPTT_Core::jalali_to_gregorian($jy, $jm, $jd);
		$cur_ts = mktime(0,0,0, $g[1], $g[2], $g[0]);
		for ($i = 1; $i <= $count; $i++) {
			$amt = ($i === $count) ? ($amount - $each * ($count - 1)) : $each;
			$jdate = $this->fa_date_of($cur_ts);
			$installments[] = [
				'no' => $i, 'dueDate' => $jdate, 'amount' => (float)$amt, 'paidAmount' => 0,
				'paidDate' => '', 'status' => 'unpaid',
			];
			$cur_ts = strtotime('+' . $interval . ' days', $cur_ts);
		}

		// Resolve names
		$customer = get_user_by('id', (int)$p['customerId']);
		$projectName = '';
		if (!empty($p['projectId'])) $projectName = get_the_title((int)$p['projectId']);

		$plan = [
			'id' => 'plan_' . time() . '_' . wp_generate_password(4, false, false),
			'customerId' => (int)$p['customerId'],
			'customerName' => $customer ? $customer->display_name : ('#' . (int)$p['customerId']),
			'projectId' => (int)($p['projectId'] ?? 0),
			'projectName' => $projectName,
			'totalAmount' => $amount,
			'installmentsCount' => $count,
			'intervalDays' => $interval,
			'firstDueDate' => $first,
			'description' => sanitize_text_field((string)($p['description'] ?? '')),
			'installments' => $installments,
			'createdAt' => $this->fa_today(),
		];

		$plans = $this->get_installment_plans();
		array_unshift($plans, $plan);
		$this->save_installment_plans($plans);
		$this->audit_log('create', 'installment_plan', $plan['id'], null, $plan);
		wp_send_json_success(['id' => $plan['id']]);
	}

	public function ajax_installment_pay(){
		$this->check_perm('treasury_payment');
		$this->reject_if_locked($this->fa_today(), 'دریافت قسط');
		$plan_id = sanitize_text_field((string)($_POST['plan_id'] ?? ''));
		$no      = (int)($_POST['installment_no'] ?? 0);
		$amount  = (float)($_POST['amount'] ?? 0);
		$acc     = sanitize_text_field((string)($_POST['account_id'] ?? ''));
		$note    = sanitize_text_field((string)($_POST['note'] ?? ''));
		if (!$plan_id || $no <= 0 || $amount <= 0) wp_send_json_error('invalid', 400);

		$plans = $this->get_installment_plans();
		$plan = null;
		foreach ($plans as &$pl) if ((string)$pl['id'] === $plan_id) { $plan = &$pl; break; }
		if (!$plan) wp_send_json_error('not_found', 404);

		$found = false;
		foreach ($plan['installments'] as &$inst) {
			if ((int)$inst['no'] === $no) {
				$before = $inst;
				$inst['paidAmount'] = (float)$inst['paidAmount'] + $amount;
				$inst['paidDate'] = $this->fa_today();
				if ($inst['paidAmount'] >= $inst['amount']) $inst['status'] = 'paid';
				$found = true;
				$this->audit_log('payment', 'installment', $plan_id . '#' . $no, $before, $inst);
				break;
			}
		}
		unset($inst);
		if (!$found) wp_send_json_error('no_inst', 404);
		$this->save_installment_plans($plans);

		// Ledger + RV voucher
		if (class_exists('CPTT_Finance') && preg_match('/^t(\d+)$/', $acc, $m)) {
			CPTT_Finance::ledger_insert([
				'account_id' => (int)$m[1], 'type' => 'income', 'direction' => 1, 'amount' => $amount,
				'project_id' => (int)$plan['projectId'], 'customer_id' => (int)$plan['customerId'],
				'description' => 'دریافت قسط ' . $no . ' از ' . $plan['customerName'] . ($note ? ' — ' . $note : ''),
				'date_at' => (int)current_time('timestamp', true), 'ref_type' => 'installment_pay',
			]);
		}
		$this->gen_voucher([
			'type' => 'RV',
			'description' => 'دریافت قسط ' . $no . ' از ' . $plan['customerName'] . ' — برنامه ' . $plan_id,
			'source_type' => 'income', 'ref_type' => 'installment', 'ref_id' => 0,
			'rows' => [
				['id'=>'r1','accountId'=>$this->map_account('bank_default'),'accountName'=>$this->coa_name($this->map_account('bank_default')),'debit'=>$amount,'credit'=>0,'description'=>'دریافت قسط','customerId'=>(int)$plan['customerId'],'projectId'=>(int)$plan['projectId']],
				['id'=>'r2','accountId'=>$this->map_account('receivable_default'),'accountName'=>$this->coa_name($this->map_account('receivable_default')),'debit'=>0,'credit'=>$amount,'description'=>'تسویه قسط','customerId'=>(int)$plan['customerId'],'projectId'=>(int)$plan['projectId']],
			],
		]);
		wp_send_json_success();
	}

	public function ajax_installment_delete(){
		$this->check_perm('treasury_payment');
		$id = sanitize_text_field((string)($_POST['plan_id'] ?? ''));
		$plans = $this->get_installment_plans();
		$before = null;
		foreach ($plans as $i => $p) if ((string)$p['id'] === $id) { $before = $p; unset($plans[$i]); break; }
		$this->save_installment_plans(array_values($plans));
		if ($before) $this->audit_log('delete', 'installment_plan', $id, $before, null);
		wp_send_json_success();
	}

	/* ─── Payables ─── */
	private function get_payables(){
		// Phase 3: prefer table
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_PAYABLES;
		if ((int)$wpdb->get_var("SHOW TABLES LIKE '$tbl'")) {
			$rows = $wpdb->get_results("SELECT * FROM $tbl ORDER BY created_at DESC");
			$out = [];
			foreach ($rows as $r) {
				$out[] = [
					'id'          => (string)$r->id,
					'partyType'   => (string)$r->party_type,
					'partyId'     => (int)$r->party_id,
					'partyName'   => (string)$r->party_name,
					'amount'      => (float)$r->amount,
					'paidAmount'  => (float)$r->paid_amount,
					'issueDate'   => (string)$r->issue_date,
					'dueDate'     => (string)$r->due_date,
					'projectId'   => (int)$r->project_id,
					'description' => (string)$r->description,
					'status'      => (string)$r->status,
					'createdAt'   => (string)$r->created_at_fa,
				];
			}
			return $out;
		}
		$v = get_option(self::OPT_PAYABLES, []); return is_array($v) ? $v : [];
	}
	private function save_payables($a){
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_PAYABLES;
		if ((int)$wpdb->get_var("SHOW TABLES LIKE '$tbl'")) {
			$wpdb->query("TRUNCATE TABLE $tbl");
			foreach ((array)$a as $p) $this->insert_payable_into_table($p);
		}
		update_option(self::OPT_PAYABLES, $a, false);
	}

	private function get_payables_recomputed(){
		$today = $this->fa_today();
		$list = $this->get_payables();
		foreach ($list as &$p) {
			$amount = (float)($p['amount'] ?? 0);
			$paid = (float)($p['paidAmount'] ?? 0);
			$p['remain'] = max(0, $amount - $paid);
			if ($p['remain'] <= 0 && $amount > 0) $p['status'] = 'paid';
			elseif ($paid > 0 && $paid < $amount) $p['status'] = 'partially_paid';
			elseif (!empty($p['dueDate']) && strcmp($p['dueDate'], $today) < 0 && $p['remain'] > 0) $p['status'] = 'overdue';
			else $p['status'] = 'open';
		}
		return $list;
	}

	public function ajax_payables_list(){ $this->check(); wp_send_json_success(['rows' => $this->get_payables_recomputed()]); }

	public function ajax_payable_save(){
		$this->check_perm('treasury_payment');
		$raw = isset($_POST['payable']) ? wp_unslash($_POST['payable']) : '';
		$p = json_decode($raw, true);
		if (!is_array($p) || empty($p['partyName']) || empty($p['amount'])) wp_send_json_error('invalid', 400);
		$list = $this->get_payables();
		$id = !empty($p['id']) ? (string)$p['id'] : ('pay_' . time() . '_' . wp_generate_password(4, false, false));
		$row = wp_parse_args($p, [
			'id' => $id, 'partyType' => 'expert', 'partyId' => 0, 'partyName' => '',
			'amount' => 0, 'paidAmount' => 0, 'remain' => 0,
			'issueDate' => $this->fa_today(), 'dueDate' => $this->fa_today(),
			'projectId' => 0, 'description' => '', 'status' => 'open', 'createdAt' => $this->fa_today(),
		]);
		$row['id'] = $id;
		$row['remain'] = max(0, (float)$row['amount'] - (float)$row['paidAmount']);
		$existing = null;
		foreach ($list as $i => $pp) if ((string)$pp['id'] === $id) { $existing = $pp; $list[$i] = $row; break; }
		if (!$existing) array_unshift($list, $row);
		$this->save_payables($list);
		$this->audit_log($existing ? 'update' : 'create', 'payable', $id, $existing, $row);
		wp_send_json_success(['id' => $id]);
	}

	public function ajax_payable_pay(){
		$this->check_perm('treasury_payment');
		$this->reject_if_locked($this->fa_today(), 'پرداخت بدهی');
		$id     = sanitize_text_field((string)($_POST['id'] ?? ''));
		$amount = (float)($_POST['amount'] ?? 0);
		$acc    = sanitize_text_field((string)($_POST['account_id'] ?? ''));
		$note   = sanitize_text_field((string)($_POST['note'] ?? ''));
		if (!$id || $amount <= 0) wp_send_json_error('invalid', 400);
		$list = $this->get_payables();
		$found = null; $before = null;
		foreach ($list as $i => $p) if ((string)$p['id'] === $id) { $before = $p; $list[$i]['paidAmount'] = (float)$list[$i]['paidAmount'] + $amount; $list[$i]['remain'] = max(0, (float)$list[$i]['amount'] - (float)$list[$i]['paidAmount']); $found = $list[$i]; break; }
		if (!$found) wp_send_json_error('not_found', 404);
		$this->save_payables($list);
		$this->audit_log('payment', 'payable', $id, $before, $found);

		// Ledger + PV
		if (class_exists('CPTT_Finance') && preg_match('/^t(\d+)$/', $acc, $m)) {
			CPTT_Finance::ledger_insert([
				'account_id' => (int)$m[1], 'type' => 'expense', 'direction' => -1, 'amount' => $amount,
				'project_id' => (int)$found['projectId'], 'expert_id' => (int)$found['partyId'],
				'description' => 'پرداخت بدهی به ' . $found['partyName'] . ($note ? ' — ' . $note : ''),
				'date_at' => (int)current_time('timestamp', true), 'ref_type' => 'payable_pay',
			]);
		}
		$this->gen_voucher([
			'type' => 'PV', 'description' => 'پرداخت بدهی به ' . $found['partyName'] . ($note ? ' — ' . $note : ''),
			'source_type' => 'payment', 'ref_type' => 'payable', 'ref_id' => 0,
			'rows' => [
				['id'=>'r1','accountId'=>$this->map_account('payable_default'),'accountName'=>$this->coa_name($this->map_account('payable_default')),'debit'=>$amount,'credit'=>0,'description'=>'تسویه بدهی','expertId'=>(int)$found['partyId']],
				['id'=>'r2','accountId'=>$this->map_account('bank_default'),'accountName'=>$this->coa_name($this->map_account('bank_default')),'debit'=>0,'credit'=>$amount,'description'=>'پرداخت وجه','expertId'=>(int)$found['partyId']],
			],
		]);
		wp_send_json_success();
	}

	public function ajax_payable_delete(){
		$this->check_perm('treasury_payment');
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		$list = $this->get_payables();
		$before = null;
		foreach ($list as $i => $p) if ((string)$p['id'] === $id) { $before = $p; unset($list[$i]); break; }
		$this->save_payables(array_values($list));
		if ($before) $this->audit_log('delete', 'payable', $id, $before, null);
		wp_send_json_success();
	}

	/* ─── Notifications (computed) ─── */
	private function build_notifications(){
		$today = $this->fa_today();
		$read = get_user_meta(get_current_user_id(), self::OPT_NOTIFS_READ, true);
		if (!is_array($read)) $read = [];
		$del = get_user_meta(get_current_user_id(), self::OPT_NOTIFS_DEL, true);
		if (!is_array($del)) $del = [];
		$out = [];

		// Cheque due in 7 days
		$g = explode('/', $today); $gy = (int)$g[0]; $gm = (int)$g[1]; $gd = (int)$g[2];
		if (class_exists('CPTT_Core')) {
			$g_gr = CPTT_Core::jalali_to_gregorian($gy, $gm, $gd);
			$today_ts = mktime(0,0,0, $g_gr[1], $g_gr[2], $g_gr[0]);
			foreach ($this->get_cheques() as $c) {
				if (!in_array($c['status'], ['received','in_collection','issued','awaiting_due'])) continue;
				$en = str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], ['0','1','2','3','4','5','6','7','8','9'], $c['dueDate']);
				if (!preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $en, $mm)) continue;
				$gg = CPTT_Core::jalali_to_gregorian((int)$mm[1], (int)$mm[2], (int)$mm[3]);
				$due_ts = mktime(0,0,0, $gg[1], $gg[2], $gg[0]);
				$diff = ceil(($due_ts - $today_ts) / 86400);
				if ($diff <= 7 && $diff >= -30) {
					$nid = 'n_chq_' . $c['id'];
					$out[] = [
						'id' => $nid, 'kind' => 'cheque_due',
						'title' => ($c['kind'] === 'receivable' ? '📥' : '📤') . ' سررسید چک ' . $c['number'],
						'message' => 'مبلغ ' . number_format((float)$c['amount']) . ' تومان ' . ($diff < 0 ? '(گذشته) ' : '') . 'در تاریخ ' . $c['dueDate'],
						'link' => 'cheques', 'recordType' => 'cheque', 'recordId' => $c['id'],
						'isRead' => in_array($nid, $read, true),
						'createdAt' => $c['dueDate'],
					];
				}
			}
		}

		// Overdue installments
		foreach ($this->get_installment_plans_recomputed() as $plan) {
			foreach (($plan['installments'] ?? []) as $inst) {
				if ($inst['status'] !== 'overdue') continue;
				$nid = 'n_inst_' . $plan['id'] . '_' . $inst['no'];
				$out[] = [
					'id' => $nid, 'kind' => 'installment_due',
					'title' => '⏰ قسط معوق — ' . $plan['customerName'],
					'message' => 'قسط ' . $inst['no'] . ' به مبلغ ' . number_format((float)$inst['amount']) . ' تومان معوق شده است',
					'link' => 'installments', 'recordType' => 'installment', 'recordId' => $plan['id'],
					'isRead' => in_array($nid, $read, true),
					'createdAt' => $inst['dueDate'],
				];
			}
		}

		// Overdue payables
		foreach ($this->get_payables_recomputed() as $p) {
			if ($p['status'] !== 'overdue') continue;
			$nid = 'n_pay_' . $p['id'];
			$out[] = [
				'id' => $nid, 'kind' => 'payable_due',
				'title' => '💳 بدهی سررسید گذشته',
				'message' => 'به ' . $p['partyName'] . ' — مانده ' . number_format((float)$p['remain']) . ' تومان',
				'link' => 'payables', 'recordType' => 'payable', 'recordId' => $p['id'],
				'isRead' => in_array($nid, $read, true),
				'createdAt' => $p['dueDate'],
			];
		}

		// Budget over
		$costs = $this->get_costs();
		if (!empty($costs) && class_exists('CPTT_Finance')) {
			global $wpdb;
			$rows = $wpdb->get_results("SELECT * FROM " . CPTT_Finance::tbl_ledger() . " WHERE type='expense'");
			$spend = [];
			foreach ($rows as $r) {
				// We don't have costCenterId on ledger table; skip for now (fallback to client-side compute)
			}
		}

		// Receivables overdue (>90 days)
		// (computed lightweight)
		// Filter out deleted notifications
		if (!empty($del)) {
			$out = array_values(array_filter($out, function($n) use ($del) {
				return !in_array($n['id'], $del, true);
			}));
		}
		// Sort by createdAt desc
		usort($out, function($a, $b){ return strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''); });
		return $out;
	}

	public function ajax_notifications_list(){ $this->check(); wp_send_json_success(['rows' => $this->build_notifications()]); }

	public function ajax_notif_read(){
		$this->check();
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		$uid = get_current_user_id();
		$read = get_user_meta($uid, self::OPT_NOTIFS_READ, true);
		if (!is_array($read)) $read = [];
		if (!in_array($id, $read, true)) $read[] = $id;
		update_user_meta($uid, self::OPT_NOTIFS_READ, $read);
		wp_send_json_success();
	}

	public function ajax_notif_read_all(){
		$this->check();
		$uid = get_current_user_id();
		$all = array_map(function($n){ return $n['id']; }, $this->build_notifications());
		update_user_meta($uid, self::OPT_NOTIFS_READ, array_values(array_unique($all)));
		wp_send_json_success();
	}

	public function ajax_notif_delete(){
		$this->check();
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		if ($id === '') wp_send_json_error('invalid_id', 400);
		$uid = get_current_user_id();
		$del = get_user_meta($uid, self::OPT_NOTIFS_DEL, true);
		if (!is_array($del)) $del = [];
		if (!in_array($id, $del, true)) $del[] = $id;
		update_user_meta($uid, self::OPT_NOTIFS_DEL, array_values(array_unique($del)));
		wp_send_json_success();
	}

	/* ─── Attachments (uses WP media library) ─── */
	private function get_attachments(){ $v = get_option(self::OPT_ATTACHMENTS, []); return is_array($v) ? $v : []; }
	private function save_attachments($a){ update_option(self::OPT_ATTACHMENTS, $a, false); }

	public function ajax_attachment_upload(){
		$this->check_perm('treasury_payment');
		$entity_type = sanitize_key((string)($_POST['entity_type'] ?? ''));
		$entity_id   = sanitize_text_field((string)($_POST['entity_id'] ?? ''));
		if (!$entity_type || !$entity_id || empty($_FILES['file'])) wp_send_json_error('invalid', 400);

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$file = $_FILES['file'];
		// Phase 4: REAL MIME validation (inspects file contents, not just extension)
		$check = $this->validate_uploaded_file($file);
		if (is_wp_error($check)) wp_send_json_error($check->get_error_message(), 400);

		$overrides = ['test_form' => false, 'mimes' => [
			'pdf'  => 'application/pdf',
			'jpg|jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'zip'  => 'application/zip',
		]];
		$movefile = wp_handle_upload($file, $overrides);
		if (!$movefile || isset($movefile['error'])) wp_send_json_error('upload_failed: ' . ($movefile['error'] ?? ''), 500);

		$att = [
			'id'          => 'att_' . time() . '_' . wp_generate_password(6, false, false),
			'entity_type' => $entity_type,
			'entity_id'   => $entity_id,
			'url'         => $movefile['url'],
			'path'        => $movefile['file'],
			'name'        => sanitize_text_field($file['name']),
			'mime'        => $movefile['type'] ?? 'application/octet-stream',
			'size'        => (int)$file['size'],
			'user_id'     => get_current_user_id(),
			'date'        => $this->fa_today(),
		];
		$all = $this->get_attachments();
		$all[] = $att;
		$this->save_attachments($all);
		$this->audit_log('create', 'attachment', $att['id'], null, ['name'=>$att['name'],'entity_type'=>$entity_type,'entity_id'=>$entity_id]);
		wp_send_json_success(['id' => $att['id'], 'url' => $att['url']]);
	}

	public function ajax_attachments_list(){
		$this->check();
		$entity_type = sanitize_key((string)($_POST['entity_type'] ?? ''));
		$entity_id   = sanitize_text_field((string)($_POST['entity_id'] ?? ''));
		$all = $this->get_attachments();
		$out = array_values(array_filter($all, function($a) use ($entity_type, $entity_id) {
			return $a['entity_type'] === $entity_type && (string)$a['entity_id'] === $entity_id;
		}));
		wp_send_json_success(['items' => $out]);
	}

	public function ajax_attachment_delete(){
		$this->check_perm('treasury_payment');
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		$all = $this->get_attachments();
		$before = null; $new = [];
		foreach ($all as $a) {
			if ($a['id'] === $id) { $before = $a; if (!empty($a['path']) && file_exists($a['path'])) @unlink($a['path']); continue; }
			$new[] = $a;
		}
		$this->save_attachments($new);
		if ($before) $this->audit_log('delete', 'attachment', $id, ['name'=>$before['name']], null);
		wp_send_json_success();
	}

	/* ─── Financial Health ─── */
	/* ═════════════════════════════════════════════════════════════════
	 * ▼▼▼ Phase 3 — Chunked Bootstrap Endpoints (lazy loading) ▼▼▼
	 * For React app to fetch state in small focused chunks instead of
	 * one giant payload. The legacy `full_bootstrap` still works.
	 * ═════════════════════════════════════════════════════════════════ */

	/** Core bootstrap: companies, branches, fy, accounts, role, mapping */
	public function ajax_bootstrap_core(){
		$this->check();
		$user = wp_get_current_user();
		$role = $this->current_role_key();
		wp_send_json_success([
			'companies'       => $this->get_companies(),
			'branches'        => $this->get_branches(),
			'fiscalYears'     => $this->get_fy(),
			'accounts'        => $this->get_coa_with_balances(),
			'user'            => ['id'=>(int)$user->ID, 'name'=>(string)$user->display_name, 'role'=>$role],
			'rolePermissions' => $this->get_role_permissions(),
			'accountMapping'  => $this->get_account_mapping(),
			'mappingPurposes' => $this->account_mapping_purpose_labels(),
		]);
	}

	/** Treasury bootstrap: treasuryAccounts, cheques, payables, installments */
	public function ajax_bootstrap_treasury(){
		$this->check();
		wp_send_json_success([
			'treasuryAccounts'  => $this->build_treasury(),
			'cheques'           => $this->get_cheques(),
			'installmentPlans'  => $this->get_installment_plans_recomputed(),
			'payables'          => $this->get_payables_recomputed(),
			'treasuryCoaMap'    => $this->get_treasury_coa_map(),
		]);
	}

	/** Projects bootstrap: projectsFull, receivables, experts, steps */
	public function ajax_bootstrap_projects(){
		$this->check();
		$es = $this->build_experts_and_steps();
		wp_send_json_success([
			'projectsFull'      => $this->build_projects_full(),
			'receivables'       => $this->build_receivables(),
			'experts'           => $es['experts'],
			'projectSteps'      => $es['projectSteps'],
			'partyAccounts'     => $this->build_party_accounts(),
		]);
	}

	/** Reports bootstrap: vouchers, settlements, ie, categories, locks */
	public function ajax_bootstrap_reports(){
		$this->check();
		wp_send_json_success([
			'vouchers'          => $this->get_vouchers(),
			'settlementHistory' => $this->build_settlement_history(),
			'incomesExpenses'   => $this->build_incomes_expenses(),
			'categories'        => $this->categories_payload(),
			'financeCategories' => $this->finance_categories_payload(),
			'costCenters'       => $this->get_costs(),
			'financialLocks'    => $this->get_locks(),
		]);
	}

	/** Analytics bootstrap: kpis, monthly, top customers, breakdowns, notifs, health */
	public function ajax_bootstrap_kpis(){
		$this->check();
		wp_send_json_success([
			'kpis'                     => class_exists('CPTT_Finance') ? CPTT_Finance::compute_kpis() : [],
			'monthlySeries'            => class_exists('CPTT_Finance') ? CPTT_Finance::monthly_series() : [],
			'topCustomers'             => class_exists('CPTT_Finance') ? CPTT_Finance::top_customers_series(6) : [],
			'categoryBreakdownExpense' => class_exists('CPTT_Finance') ? CPTT_Finance::category_breakdown('expense') : [],
			'categoryBreakdownIncome'  => class_exists('CPTT_Finance') ? CPTT_Finance::category_breakdown('income') : [],
			'notifications'            => $this->build_notifications(),
			'financialHealth'          => $this->build_financial_health(),
			'counts'                   => $this->counts_summary(),
		]);
	}

	/** Diagnostics: DB schema version + table row counts */
	public function ajax_migration_status(){
		$this->check();
		global $wpdb;
		$tables = [self::TBL_VOUCHERS, self::TBL_VOUCHER_ROWS, self::TBL_CHEQUES, self::TBL_PAYABLES, self::TBL_INSTALLMENTS, self::TBL_INSTALLMENT_ROWS, self::TBL_AUDIT];
		$stats = [];
		foreach ($tables as $t) {
			$full = $wpdb->prefix . $t;
			$exists = (int)$wpdb->get_var("SHOW TABLES LIKE '$full'");
			$stats[$t] = $exists ? (int)$wpdb->get_var("SELECT COUNT(*) FROM $full") : -1;
		}
		// Legacy option sizes
		$opt_stats = [];
		foreach ([self::OPT_VOUCHERS, self::OPT_CHEQUES, self::OPT_INSTALLMENTS, self::OPT_PAYABLES] as $o) {
			$v = get_option($o, []);
			$opt_stats[$o] = is_array($v) ? count($v) : 0;
		}
		wp_send_json_success([
			'db_version' => get_option(self::OPT_DB_VERSION, '0.0.0'),
			'target_version' => self::DB_VERSION,
			'tables' => $stats,
			'legacy_options' => $opt_stats,
		]);
	}

	private function build_financial_health(){
		$kpis = class_exists('CPTT_Finance') ? CPTT_Finance::compute_kpis() : [];
		$totalRev = (float)($kpis['total_revenue'] ?? 0);
		$totalInv = (float)($kpis['total_invoiced'] ?? 0);
		$collectionRatio = $totalInv > 0 ? ($totalRev / $totalInv) * 100 : 0;

		// Overdue payables sum
		$overduePay = 0;
		foreach ($this->get_payables_recomputed() as $p) {
			if ($p['status'] === 'overdue') $overduePay += (float)$p['remain'];
		}

		// Monthly net profit (current Jalali month from monthly_series)
		$monthly = class_exists('CPTT_Finance') ? CPTT_Finance::monthly_series() : [];
		$net = 0;
		if (!empty($monthly)) {
			$last = end($monthly);
			$net = (float)($last['income'] ?? 0) - (float)($last['expense'] ?? 0);
		}

		return [
			'collectionRatio'    => round($collectionRatio, 1),
			'avgCollectionDays'  => 30, // rough — TODO compute from real ledger pairs
			'monthlyNetProfit'   => $net,
			'budgetConsumption'  => 0, // computed on client from costCenters
			'overduePayables'    => $overduePay,
		];
	}

	/* Phase 7: Operations & Automation admin page */
	public function render_ops_page(){
		if (!current_user_can('edit_cptt_projects')) wp_die('دسترسی غیرمجاز');
		$treasury_accs = [];
		if (class_exists('CPTT_Finance')) {
			global $wpdb;
			$rows = $wpdb->get_results('SELECT id, name, type FROM ' . CPTT_Finance::tbl_accounts() . ' WHERE status=1 ORDER BY id ASC');
			foreach ((array)$rows as $r) $treasury_accs[] = $r;
		}
		$recurring   = $this->get_recurring();
		$chequebooks = $this->get_chequebooks();
		$cron_log    = get_option(self::OPT_CRON_LOG, []);
		$next_daily  = wp_next_scheduled('cpttf_erp_daily_cron');
		$next_hourly = wp_next_scheduled('cpttf_erp_hourly_cron');
		$nonce = wp_create_nonce(self::NONCE);
		$ajax  = admin_url('admin-ajax.php');
		?>
		<style>
			.cptt-ops-wrap { max-width: 1200px; margin: 18px 8px; font-family: Tahoma, sans-serif; direction: rtl; }
			.cptt-ops-wrap h1 { font-size: 1.4rem; color: #1e293b; margin: 0 0 4px; }
			.cptt-ops-wrap p.lead { color: #64748b; margin: 0 0 14px; }
			.cptt-ops-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; background: #fff; padding: 6px; border-radius: 12px; border: 1px solid #e2e8f0; }
			.cptt-ops-tab { padding: 8px 14px; border-radius: 8px; cursor: pointer; font-size: .8rem; font-weight: 700; color: #475569; background: transparent; border: 0; font-family: inherit; }
			.cptt-ops-tab.active { background: #4f46e5; color: #fff; }
			.cptt-ops-tab:hover:not(.active) { background: #f1f5f9; }
			.cptt-ops-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 18px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
			.cptt-ops-card h2 { font-size: 1.05rem; color: #4f46e5; margin: 0 0 12px; padding-bottom: 6px; border-bottom: 1px dashed #cbd5e1; }
			.cptt-ops-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; }
			.cptt-ops-field label { display: block; font-size: .72rem; font-weight: 700; color: #475569; margin-bottom: 4px; }
			.cptt-ops-field input, .cptt-ops-field select { width: 100%; padding: 7px 10px; border-radius: 8px; border: 1.5px solid #e2e8f0; font-size: .8rem; font-family: inherit; direction: rtl; text-align: right; }
			.cptt-ops-btn { padding: 8px 18px; border-radius: 8px; border: 0; background: #4f46e5; color: #fff; font-size: .8rem; font-weight: 700; cursor: pointer; font-family: inherit; }
			.cptt-ops-btn:hover { background: #4338ca; }
			.cptt-ops-btn.ghost { background: #f1f5f9; color: #334155; }
			.cptt-ops-btn.danger { background: #e11d48; color: #fff; }
			.cptt-ops-btn.success { background: #059669; color: #fff; }
			.cptt-ops-table { width: 100%; border-collapse: collapse; font-size: .78rem; margin-top: 8px; }
			.cptt-ops-table th, .cptt-ops-table td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; text-align: right; }
			.cptt-ops-table th { background: #f8fafc; font-weight: 700; color: #475569; }
			.cptt-ops-pill { display:inline-block; padding: 2px 8px; border-radius: 999px; font-size: .65rem; font-weight: 700; }
			.cptt-ops-pill.active { background: #d1fae5; color: #065f46; }
			.cptt-ops-pill.inactive { background: #f1f5f9; color: #64748b; }
			.cptt-ops-msg { padding: 10px; border-radius: 8px; font-size: .8rem; margin: 8px 0; }
			.cptt-ops-msg.ok { background: #d1fae5; color: #065f46; }
			.cptt-ops-msg.err { background: #fee2e2; color: #991b1b; }
		</style>
		<div class="cptt-ops-wrap">
			<h1>⚙ عملیات و اتوماسیون</h1>
			<p class="lead">تراکنش‌های تکرارشونده، دفترچه‌های چک، WP-Cron و دسترسی به صفحات چاپ.</p>

			<div id="cptt-ops-msg-host"></div>

			<div class="cptt-ops-tabs">
				<button class="cptt-ops-tab active" data-tab="recurring">🔁 تکرارشونده</button>
				<button class="cptt-ops-tab" data-tab="chequebook">📑 دفترچه چک</button>
				<button class="cptt-ops-tab" data-tab="cron">🕒 WP-Cron</button>
				<button class="cptt-ops-tab" data-tab="print">🖨 چاپ سند</button>
			</div>

			<!-- ── Recurring ── -->
			<div class="cptt-ops-card" data-pane="recurring">
				<h2>🔁 تراکنش‌های تکرارشونده</h2>
				<table class="cptt-ops-table">
					<thead><tr><th>نام</th><th>نوع</th><th>مبلغ</th><th>تکرار</th><th>اجرای بعدی</th><th>اجراهای انجام‌شده</th><th>وضعیت</th><th>عملیات</th></tr></thead>
					<tbody id="rec-tbody">
					<?php if (empty($recurring)): ?>
						<tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:20px;">هنوز تراکنش تکرارشونده‌ای تعریف نشده</td></tr>
					<?php else: foreach ($recurring as $r): ?>
						<tr>
							<td><strong><?php echo esc_html($r['name']); ?></strong><br><small style="color:#94a3b8;"><?php echo esc_html($r['description'] ?? ''); ?></small></td>
							<td><?php echo $r['type'] === 'INCOME' ? '<span style="color:#059669;">درآمد</span>' : '<span style="color:#dc2626;">هزینه</span>'; ?></td>
							<td class="num"><?php echo number_format((float)$r['amount']); ?></td>
							<td>هر <?php echo (int)$r['interval']; ?> <?php echo esc_html(['daily'=>'روز','weekly'=>'هفته','monthly'=>'ماه','yearly'=>'سال'][$r['frequency']] ?? $r['frequency']); ?></td>
							<td><?php echo esc_html($r['nextRun'] ?? '—'); ?></td>
							<td class="num"><?php echo (int)($r['runsCount'] ?? 0); ?></td>
							<td><span class="cptt-ops-pill <?php echo !empty($r['isActive']) ? 'active' : 'inactive'; ?>"><?php echo !empty($r['isActive']) ? 'فعال' : 'غیرفعال'; ?></span></td>
							<td>
								<button class="cptt-ops-btn ghost rec-run" data-id="<?php echo esc_attr($r['id']); ?>">⚡ اجرای الان</button>
								<button class="cptt-ops-btn danger rec-del" data-id="<?php echo esc_attr($r['id']); ?>">حذف</button>
							</td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
				<h3 style="font-size:.9rem;margin-top:16px;color:#1e293b;">➕ تعریف تراکنش جدید</h3>
				<div class="cptt-ops-grid">
					<div class="cptt-ops-field"><label>نام</label><input type="text" id="rec-name" placeholder="مثلاً: اجاره دفتر"></div>
					<div class="cptt-ops-field"><label>نوع</label><select id="rec-type"><option value="EXPENSE">هزینه</option><option value="INCOME">درآمد</option></select></div>
					<div class="cptt-ops-field"><label>مبلغ</label><input type="number" id="rec-amount" placeholder="0"></div>
					<div class="cptt-ops-field"><label>حساب بانکی</label><select id="rec-bank"><option value="">— انتخاب —</option><?php foreach ($treasury_accs as $a): ?><option value="t<?php echo (int)$a->id; ?>"><?php echo esc_html($a->name); ?></option><?php endforeach; ?></select></div>
					<div class="cptt-ops-field"><label>تکرار</label><select id="rec-freq"><option value="daily">روزانه</option><option value="weekly">هفتگی</option><option value="monthly" selected>ماهانه</option><option value="yearly">سالانه</option></select></div>
					<div class="cptt-ops-field"><label>فاصله</label><input type="number" id="rec-interval" value="1" min="1"></div>
					<div class="cptt-ops-field"><label>اولین اجرا</label><input type="text" id="rec-start" placeholder="<?php echo esc_attr($this->fa_today()); ?>" value="<?php echo esc_attr($this->fa_today()); ?>"></div>
					<div class="cptt-ops-field"><label>پایان (اختیاری)</label><input type="text" id="rec-end" placeholder="بدون پایان"></div>
					<div class="cptt-ops-field" style="grid-column:span 2;"><label>توضیحات</label><input type="text" id="rec-desc"></div>
				</div>
				<button class="cptt-ops-btn success" id="rec-save" style="margin-top:10px;">💾 ذخیره</button>
			</div>

			<!-- ── Chequebook ── -->
			<div class="cptt-ops-card" data-pane="chequebook" style="display:none;">
				<h2>📑 دفترچه‌های چک</h2>
				<table class="cptt-ops-table">
					<thead><tr><th>نام</th><th>بانک</th><th>سری</th><th>محدوده</th><th>شماره فعلی</th><th>باقی‌مانده</th><th>عملیات</th></tr></thead>
					<tbody id="cb-tbody">
					<?php if (empty($chequebooks)): ?>
						<tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:20px;">دفترچه‌ای ثبت نشده</td></tr>
					<?php else: foreach ($chequebooks as $cb): $remain = max(0, (int)$cb['endNumber'] - (int)$cb['currentNumber'] + 1); ?>
						<tr>
							<td><strong><?php echo esc_html($cb['name']); ?></strong></td>
							<td><?php echo esc_html($cb['bank'] ?: '—'); ?></td>
							<td><?php echo esc_html($cb['seriesPrefix'] ?: '—'); ?></td>
							<td><?php echo (int)$cb['startNumber']; ?> تا <?php echo (int)$cb['endNumber']; ?></td>
							<td class="num"><strong><?php echo (int)$cb['currentNumber']; ?></strong></td>
							<td class="num" style="color:<?php echo $remain < 5 ? '#dc2626' : '#059669'; ?>;"><?php echo $remain; ?> برگ</td>
							<td><button class="cptt-ops-btn ghost cb-next" data-id="<?php echo esc_attr($cb['id']); ?>">شماره بعدی</button> <button class="cptt-ops-btn danger cb-del" data-id="<?php echo esc_attr($cb['id']); ?>">حذف</button></td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
				<h3 style="font-size:.9rem;margin-top:16px;color:#1e293b;">➕ تعریف دفترچه جدید</h3>
				<div class="cptt-ops-grid">
					<div class="cptt-ops-field"><label>نام دفترچه</label><input type="text" id="cb-name" placeholder="مثلاً: ملت دفتر مرکزی"></div>
					<div class="cptt-ops-field"><label>بانک</label><input type="text" id="cb-bank"></div>
					<div class="cptt-ops-field"><label>سری حروف</label><input type="text" id="cb-prefix" placeholder="مثلاً: الف"></div>
					<div class="cptt-ops-field"><label>سری صیادی</label><input type="text" id="cb-sayyadi"></div>
					<div class="cptt-ops-field"><label>شماره شروع</label><input type="number" id="cb-start" value="1"></div>
					<div class="cptt-ops-field"><label>شماره پایان</label><input type="number" id="cb-end" value="50"></div>
				</div>
				<button class="cptt-ops-btn success" id="cb-save" style="margin-top:10px;">💾 ذخیره</button>
			</div>

			<!-- ── Cron ── -->
			<div class="cptt-ops-card" data-pane="cron" style="display:none;">
				<h2>🕒 وضعیت WP-Cron</h2>
				<div class="cptt-ops-grid">
					<div class="cptt-ops-field"><label>اجرای روزانه بعدی</label><div style="padding:8px;background:#f8fafc;border-radius:8px;font-weight:700;color:#4338ca;"><?php echo $next_daily ? date('Y-m-d H:i:s', $next_daily) : '— تنظیم نشده —'; ?></div></div>
					<div class="cptt-ops-field"><label>اجرای ساعتی بعدی</label><div style="padding:8px;background:#f8fafc;border-radius:8px;font-weight:700;color:#4338ca;"><?php echo $next_hourly ? date('Y-m-d H:i:s', $next_hourly) : '— تنظیم نشده —'; ?></div></div>
				</div>
				<h3 style="font-size:.9rem;margin-top:16px;color:#1e293b;">📋 آخرین گزارش اجرا</h3>
				<table class="cptt-ops-table">
					<tbody>
					<?php foreach ((array)$cron_log as $k => $v): ?>
						<tr><td><strong><?php echo esc_html($k); ?></strong></td><td><?php echo esc_html(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v); ?></td></tr>
					<?php endforeach; ?>
					<?php if (empty($cron_log)): ?>
						<tr><td style="text-align:center;color:#94a3b8;padding:20px;">هنوز اجرا نشده</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
				<div style="margin-top:14px;display:flex;gap:8px;">
					<button class="cptt-ops-btn" id="cron-run-daily">⚡ اجرای دستی روزانه</button>
					<button class="cptt-ops-btn" id="cron-run-hourly">⚡ اجرای دستی ساعتی</button>
				</div>
				<p style="font-size:.75rem;color:#94a3b8;margin-top:10px;">دستی فقط برای تست و عیب‌یابی. اجراهای خودکار توسط WordPress Cron انجام می‌شوند.</p>
			</div>

			<!-- ── Print direct links ── -->
			<div class="cptt-ops-card" data-pane="print" style="display:none;">
				<h2>🖨 صفحات چاپ مستقیم</h2>
				<p style="font-size:.85rem;color:#475569;">با مراجعه به این آدرس‌ها (با id مناسب) می‌توانید سند، چک، فیش حقوقی یا صورت‌حساب را چاپ کنید:</p>
				<table class="cptt-ops-table">
					<thead><tr><th>نوع</th><th>URL Pattern</th><th>پارامتر</th></tr></thead>
					<tbody>
						<tr><td>🧾 سند حسابداری</td><td><code><?php echo esc_html(admin_url('admin-ajax.php')); ?>?action=cpttf_erp_print_voucher&nonce=NONCE&id=<voucher_id></code></td><td>id سند</td></tr>
						<tr><td>📑 چک</td><td><code>?action=cpttf_erp_print_cheque&nonce=NONCE&id=<cheque_id></code></td><td>id چک</td></tr>
						<tr><td>👤 فیش پرداخت کارشناس</td><td><code>?action=cpttf_erp_print_payslip&nonce=NONCE&expert_id=<user_id>&from=&to=</code></td><td>user_id کارشناس</td></tr>
						<tr><td>📊 صورت‌حساب اشخاص</td><td><code>?action=cpttf_erp_print_statement&nonce=NONCE&party_type=customer|expert&party_id=<id>&from=&to=</code></td><td>party_id</td></tr>
					</tbody>
				</table>
				<p style="font-size:.75rem;color:#94a3b8;margin-top:10px;">دکمه‌های مستقیم چاپ در React app (در صفحات مربوطه) باید به این آدرس‌ها لینک شوند.</p>
			</div>
		</div>

		<script>
		(function(){
			var nonce = '<?php echo esc_js($nonce); ?>';
			var ajax  = '<?php echo esc_js($ajax); ?>';
			function msg(t, ok){ var h = document.getElementById('cptt-ops-msg-host'); h.innerHTML = '<div class="cptt-ops-msg ' + (ok ? 'ok' : 'err') + '">' + t + '</div>'; setTimeout(function(){ h.innerHTML = ''; }, 4000); }
			function call(action, data, cb){
				var fd = new FormData(); fd.append('action', action); fd.append('nonce', nonce);
				Object.keys(data || {}).forEach(function(k){ var v = data[k]; if (typeof v === 'object') v = JSON.stringify(v); fd.append(k, v); });
				fetch(ajax, { method:'POST', body: fd, credentials:'same-origin' }).then(function(r){ return r.json(); }).then(cb).catch(function(e){ cb({success:false, data:{message:String(e)}}); });
			}
			document.querySelectorAll('.cptt-ops-tab').forEach(function(b){
				b.onclick = function(){
					document.querySelectorAll('.cptt-ops-tab').forEach(function(x){ x.classList.remove('active'); });
					b.classList.add('active');
					var t = b.getAttribute('data-tab');
					document.querySelectorAll('[data-pane]').forEach(function(p){ p.style.display = (p.getAttribute('data-pane') === t) ? '' : 'none'; });
				};
			});
			// Recurring save
			var rs = document.getElementById('rec-save');
			if (rs) rs.onclick = function(){
				var item = {
					name: document.getElementById('rec-name').value,
					type: document.getElementById('rec-type').value,
					amount: document.getElementById('rec-amount').value,
					bankAccountId: document.getElementById('rec-bank').value,
					frequency: document.getElementById('rec-freq').value,
					interval: document.getElementById('rec-interval').value,
					startDate: document.getElementById('rec-start').value,
					endDate: document.getElementById('rec-end').value,
					description: document.getElementById('rec-desc').value,
					isActive: 1
				};
				if (!item.name || !item.amount) { msg('❌ نام و مبلغ الزامی است', false); return; }
				call('cpttf_erp_recurring_save', { item: item }, function(r){
					if (r.success) { msg('✅ ذخیره شد', true); setTimeout(function(){ location.reload(); }, 1200); }
					else msg('❌ ' + (r.data && r.data.message ? r.data.message : 'خطا'), false);
				});
			};
			document.querySelectorAll('.rec-run').forEach(function(b){
				b.onclick = function(){
					if (!confirm('این تراکنش الان اجرا و سند ثبت شود؟')) return;
					call('cpttf_erp_recurring_run_now', { id: b.getAttribute('data-id') }, function(r){
						if (r.success) { msg('✅ اجرا شد. اجرای بعدی: ' + (r.data.next_run || '—'), true); setTimeout(function(){ location.reload(); }, 1500); }
						else msg('❌ ' + (r.data && r.data.message ? r.data.message : 'خطا'), false);
					});
				};
			});
			document.querySelectorAll('.rec-del').forEach(function(b){
				b.onclick = function(){
					if (!confirm('حذف شود؟')) return;
					call('cpttf_erp_recurring_delete', { id: b.getAttribute('data-id') }, function(r){
						if (r.success) { location.reload(); } else msg('❌ خطا', false);
					});
				};
			});
			// Chequebook save
			var cs = document.getElementById('cb-save');
			if (cs) cs.onclick = function(){
				var item = {
					name: document.getElementById('cb-name').value,
					bank: document.getElementById('cb-bank').value,
					seriesPrefix: document.getElementById('cb-prefix').value,
					sayyadiSeries: document.getElementById('cb-sayyadi').value,
					startNumber: document.getElementById('cb-start').value,
					endNumber: document.getElementById('cb-end').value,
					isActive: 1
				};
				if (!item.name) { msg('❌ نام الزامی است', false); return; }
				call('cpttf_erp_chequebook_save', { item: item }, function(r){
					if (r.success) { location.reload(); } else msg('❌ خطا', false);
				});
			};
			document.querySelectorAll('.cb-next').forEach(function(b){
				b.onclick = function(){
					call('cpttf_erp_chequebook_next', { id: b.getAttribute('data-id') }, function(r){
						if (r.success) {
							var d = r.data;
							alert('شماره بعدی: ' + d.next_number + (d.is_duplicate ? '\n⚠ هشدار: این شماره قبلاً ثبت شده!' : '') + '\nباقی‌مانده: ' + d.remaining + ' برگ');
						} else msg('❌ ' + (r.data && r.data.message ? r.data.message : 'خطا'), false);
					});
				};
			});
			document.querySelectorAll('.cb-del').forEach(function(b){
				b.onclick = function(){
					if (!confirm('حذف دفترچه؟')) return;
					call('cpttf_erp_chequebook_delete', { id: b.getAttribute('data-id') }, function(r){
						if (r.success) { location.reload(); }
					});
				};
			});
			// Cron run
			var cd = document.getElementById('cron-run-daily');
			if (cd) cd.onclick = function(){
				call('cpttf_erp_cron_run_now', { which: 'daily' }, function(r){
					if (r.success) { msg('✅ اجرای روزانه انجام شد', true); setTimeout(function(){ location.reload(); }, 1500); }
					else msg('❌ ' + (r.data && r.data.message ? r.data.message : 'خطا'), false);
				});
			};
			var ch = document.getElementById('cron-run-hourly');
			if (ch) ch.onclick = function(){
				call('cpttf_erp_cron_run_now', { which: 'hourly' }, function(r){
					if (r.success) { msg('✅ اجرای ساعتی انجام شد', true); setTimeout(function(){ location.reload(); }, 1500); }
					else msg('❌ ' + (r.data && r.data.message ? r.data.message : 'خطا'), false);
				});
			};
		})();
		</script>
		<?php
	}

	/* ═════════════════════════════════════════════════════════════════
	 * ▼▼▼ Phase 7 — Print Templates (returns full HTML for new window) ▼▼▼
	 * ═════════════════════════════════════════════════════════════════ */

	/** Common print stylesheet — Dana font, RTL, A4, header indigo */
	private function print_styles(){
		$base = CPTT_URL . 'assets/fonts/';
		return "
		<style>
			@font-face { font-family:'Dana'; src:url('{$base}Dana/Dana-FaNum-Regular.ttf') format('truetype'); font-weight:400; font-display:block; }
			@font-face { font-family:'Dana'; src:url('{$base}Dana/Dana-FaNum-Medium.ttf') format('truetype'); font-weight:500; font-display:block; }
			@font-face { font-family:'Dana'; src:url('{$base}Dana/Dana-FaNum-Bold.ttf') format('truetype'); font-weight:700; font-display:block; }
			* { box-sizing: border-box; }
			html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
			body { font-family: 'Dana', Tahoma, sans-serif; direction: rtl; color: #1e293b; padding: 20px; margin: 0; background: #fff; }
			.ph-header { display:flex; justify-content:space-between; align-items:center; padding-bottom: 14px; border-bottom: 2px solid #4f46e5; margin-bottom: 18px; }
			.ph-header h1 { font-size: 20px; color: #4f46e5; margin: 0; font-weight: 700; }
			.ph-header .meta { font-size: 11px; color: #64748b; text-align: left; }
			.ph-section { margin-bottom: 16px; }
			.ph-section h2 { font-size: 14px; color: #4338ca; margin: 0 0 8px; padding-bottom: 4px; border-bottom: 1px dashed #cbd5e1; }
			.ph-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
			.ph-field { padding: 6px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px; }
			.ph-field .label { color: #64748b; font-size: 10px; display: block; margin-bottom: 2px; }
			.ph-field .value { font-weight: 700; color: #1e293b; }
			table { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 8px; }
			th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: right; }
			th { background: #eef2ff; color: #312e81; font-weight: 700; }
			tbody tr:nth-child(even) { background: #f8fafc; }
			tfoot td { background: #fef3c7; font-weight: 700; }
			.num { font-feature-settings: 'tnum' 1; font-variant-numeric: tabular-nums; }
			.amt-positive { color: #059669; }
			.amt-negative { color: #dc2626; }
			.ph-footer { margin-top: 28px; padding-top: 12px; border-top: 1px dashed #cbd5e1; font-size: 10px; color: #94a3b8; text-align: center; }
			.ph-signatures { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
			.ph-signatures .ph-sig { text-align: center; font-size: 11px; color: #475569; }
			.ph-signatures .ph-sig .line { border-top: 1px solid #475569; padding-top: 4px; margin-top: 40px; }
			@media print {
				@page { margin: 1.2cm; size: A4; }
				body { padding: 0; }
			}
			.ph-print-bar { position: sticky; top: 0; background: #fff; padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: center; margin-bottom: 14px; }
			.ph-print-bar button { background: #4f46e5; color: #fff; padding: 8px 24px; border-radius: 6px; border: 0; cursor: pointer; font-family: inherit; font-size: 13px; font-weight: 700; }
			.ph-print-bar button:hover { background: #4338ca; }
			@media print { .ph-print-bar { display: none; } }
		</style>
		<script>
			window.addEventListener('load', function(){
				if (document.fonts && document.fonts.load) {
					Promise.all([
						document.fonts.load('400 14px Dana'),
						document.fonts.load('700 14px Dana')
					]).then(function(){ /* ready */ }).catch(function(){});
				}
			});
		</script>
		";
	}

	private function company_header($title){
		$company = (string)get_bloginfo('name');
		$date_fa = $this->fa_today();
		return '<div class="ph-header">' .
			'<div><h1>' . esc_html($title) . '</h1>' .
			'<div style="font-size:11px;color:#64748b;margin-top:4px;">' . esc_html($company) . '</div></div>' .
			'<div class="meta">تاریخ چاپ: ' . esc_html($date_fa) . '</div></div>';
	}

	private function print_bar(){
		return '<div class="ph-print-bar"><button onclick="window.print()">🖨 چاپ این صفحه</button></div>';
	}

	private function signatures(){
		return '<div class="ph-signatures">' .
			'<div class="ph-sig"><div class="line">امضای تنظیم‌کننده</div></div>' .
			'<div class="ph-sig"><div class="line">امضای تأییدکننده</div></div>' .
			'<div class="ph-sig"><div class="line">امضای مدیرعامل</div></div>' .
			'</div>';
	}

	private function fmt_money($n){ return number_format((float)$n) . ' تومان'; }

	public function ajax_print_voucher(){
		$this->check_perm('voucher_view');
		$id = sanitize_text_field((string)($_GET['id'] ?? $_POST['id'] ?? ''));
		if (!$id) wp_die('id missing');
		$rows = $this->read_vouchers_from_table(1, 'id=%s', [$id]);
		if (empty($rows[0])) wp_die('سند پیدا نشد');
		$v = $rows[0];
		$total_d = 0; $total_c = 0;
		foreach ($v['rows'] as $r) { $total_d += $r['debit']; $total_c += $r['credit']; }
		nocache_headers();
		header('Content-Type: text/html; charset=UTF-8');
		echo '<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><title>چاپ سند ' . esc_html($v['voucherCode']) . '</title>' . $this->print_styles() . '</head><body>';
		echo $this->print_bar();
		echo $this->company_header('سند حسابداری شماره ' . $v['voucherCode']);
		echo '<div class="ph-section"><h2>اطلاعات سند</h2><div class="ph-grid">';
		echo '<div class="ph-field"><span class="label">شماره سند</span><span class="value">' . esc_html($v['voucherCode']) . '</span></div>';
		echo '<div class="ph-field"><span class="label">تاریخ</span><span class="value">' . esc_html($v['date']) . '</span></div>';
		echo '<div class="ph-field"><span class="label">نوع</span><span class="value">' . esc_html($v['voucherType']) . '</span></div>';
		echo '<div class="ph-field"><span class="label">وضعیت</span><span class="value">' . esc_html($v['status']) . '</span></div>';
		echo '<div class="ph-field" style="grid-column:span 2;"><span class="label">شرح</span><span class="value">' . esc_html($v['description']) . '</span></div>';
		echo '</div></div>';
		echo '<div class="ph-section"><h2>ردیف‌های سند</h2><table><thead><tr><th>ردیف</th><th>حساب</th><th>شرح</th><th>بدهکار</th><th>بستانکار</th></tr></thead><tbody>';
		$i = 1;
		foreach ($v['rows'] as $r) {
			echo '<tr><td class="num">' . ($i++) . '</td><td>' . esc_html($r['accountName']) . '</td><td>' . esc_html($r['description']) . '</td>';
			echo '<td class="num amt-positive">' . ($r['debit'] > 0 ? $this->fmt_money($r['debit']) : '—') . '</td>';
			echo '<td class="num amt-negative">' . ($r['credit'] > 0 ? $this->fmt_money($r['credit']) : '—') . '</td></tr>';
		}
		echo '</tbody><tfoot><tr><td colspan="3" style="text-align:left;">جمع کل:</td>';
		echo '<td class="num amt-positive">' . $this->fmt_money($total_d) . '</td>';
		echo '<td class="num amt-negative">' . $this->fmt_money($total_c) . '</td></tr></tfoot></table></div>';
		echo $this->signatures();
		echo '<div class="ph-footer">تولید شده توسط سامانه‌ی حسابداری هماهنگ ERP</div>';
		echo '</body></html>';
		exit;
	}

	public function ajax_print_cheque(){
		$this->check_perm('treasury_view');
		$id = sanitize_text_field((string)($_GET['id'] ?? $_POST['id'] ?? ''));
		if (!$id) wp_die('id missing');
		$found = null;
		foreach ($this->get_cheques() as $c) if ((string)$c['id'] === $id) { $found = $c; break; }
		if (!$found) wp_die('چک پیدا نشد');
		nocache_headers();
		header('Content-Type: text/html; charset=UTF-8');
		echo '<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><title>چاپ چک ' . esc_html($found['number']) . '</title>' . $this->print_styles() . '</head><body>';
		echo $this->print_bar();
		echo $this->company_header(($found['kind'] === 'receivable' ? '📥 چک دریافتی' : '📤 چک پرداختی') . ' شماره ' . $found['number']);
		echo '<div class="ph-section"><h2>اطلاعات چک</h2><div class="ph-grid">';
		echo '<div class="ph-field"><span class="label">شماره چک</span><span class="value">' . esc_html($found['number']) . '</span></div>';
		echo '<div class="ph-field"><span class="label">شماره صیادی</span><span class="value">' . esc_html($found['sayyadi'] ?: '—') . '</span></div>';
		echo '<div class="ph-field"><span class="label">بانک</span><span class="value">' . esc_html($found['bank']) . '</span></div>';
		echo '<div class="ph-field"><span class="label">شعبه</span><span class="value">' . esc_html($found['branch'] ?: '—') . '</span></div>';
		echo '<div class="ph-field"><span class="label">تاریخ صدور</span><span class="value">' . esc_html($found['issueDate']) . '</span></div>';
		echo '<div class="ph-field"><span class="label">تاریخ سررسید</span><span class="value">' . esc_html($found['dueDate']) . '</span></div>';
		echo '<div class="ph-field"><span class="label">طرف چک</span><span class="value">' . esc_html($found['partyName'] ?: '—') . '</span></div>';
		echo '<div class="ph-field"><span class="label">وضعیت</span><span class="value">' . esc_html($found['status']) . '</span></div>';
		echo '<div class="ph-field" style="grid-column:span 2;background:#eef2ff;"><span class="label">مبلغ</span><span class="value" style="font-size:18px;color:#4338ca;">' . $this->fmt_money($found['amount']) . '</span></div>';
		if (!empty($found['description'])) echo '<div class="ph-field" style="grid-column:span 2;"><span class="label">توضیحات</span><span class="value">' . esc_html($found['description']) . '</span></div>';
		echo '</div></div>';
		echo $this->signatures();
		echo '<div class="ph-footer">سامانه حسابداری هماهنگ ERP</div>';
		echo '</body></html>';
		exit;
	}

	public function ajax_print_payslip(){
		$this->check_perm('reports_view');
		$expert_id = (int)($_GET['expert_id'] ?? $_POST['expert_id'] ?? 0);
		$from = sanitize_text_field((string)($_GET['from'] ?? $_POST['from'] ?? ''));
		$to   = sanitize_text_field((string)($_GET['to']   ?? $_POST['to']   ?? ''));
		if (!$expert_id) wp_die('expert_id missing');
		$user = get_user_by('id', $expert_id);
		if (!$user) wp_die('کارشناس پیدا نشد');
		// Aggregate from settlement_history
		$rows = [];
		$total = 0;
		foreach ($this->build_settlement_history() as $sh) {
			$sh_eid = (int)str_replace('e', '', (string)($sh['expertId'] ?? ''));
			if ($sh_eid !== $expert_id) continue;
			if ($from && $this->fa_date_cmp((string)$sh['date'], $from) < 0) continue;
			if ($to   && $this->fa_date_cmp((string)$sh['date'], $to)   > 0) continue;
			$rows[] = $sh;
			$total += (float)($sh['amount'] ?? 0);
		}
		nocache_headers();
		header('Content-Type: text/html; charset=UTF-8');
		echo '<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><title>فیش حقوقی ' . esc_html($user->display_name) . '</title>' . $this->print_styles() . '</head><body>';
		echo $this->print_bar();
		echo $this->company_header('فیش پرداخت‌های کارشناس');
		echo '<div class="ph-section"><h2>اطلاعات کارشناس</h2><div class="ph-grid">';
		echo '<div class="ph-field"><span class="label">نام و نام خانوادگی</span><span class="value">' . esc_html($user->display_name) . '</span></div>';
		echo '<div class="ph-field"><span class="label">نام کاربری</span><span class="value">' . esc_html($user->user_login) . '</span></div>';
		if ($from || $to) {
			echo '<div class="ph-field" style="grid-column:span 2;"><span class="label">بازه‌ی گزارش</span><span class="value">' . esc_html($from ?: '—') . ' تا ' . esc_html($to ?: '—') . '</span></div>';
		}
		echo '</div></div>';
		echo '<div class="ph-section"><h2>تاریخچه پرداخت‌ها</h2>';
		if (empty($rows)) {
			echo '<p style="text-align:center;color:#94a3b8;padding:20px;">پرداختی در این بازه ثبت نشده است</p>';
		} else {
			echo '<table><thead><tr><th>ردیف</th><th>تاریخ</th><th>روش پرداخت</th><th>توضیحات</th><th>مبلغ</th></tr></thead><tbody>';
			$i = 1;
			foreach ($rows as $r) {
				echo '<tr><td class="num">' . ($i++) . '</td><td>' . esc_html($r['date']) . '</td><td>' . esc_html($r['paymentMethod']) . '</td><td>' . esc_html($r['description'] ?? '—') . '</td><td class="num amt-positive">' . $this->fmt_money($r['amount']) . '</td></tr>';
			}
			echo '</tbody><tfoot><tr><td colspan="4" style="text-align:left;">جمع کل پرداختی:</td><td class="num amt-positive" style="font-size:14px;">' . $this->fmt_money($total) . '</td></tr></tfoot></table>';
		}
		echo '</div>';
		echo $this->signatures();
		echo '<div class="ph-footer">سامانه حسابداری هماهنگ ERP — فیش رسمی</div>';
		echo '</body></html>';
		exit;
	}

	public function ajax_print_statement(){
		$this->check_perm('reports_view');
		$party_type = sanitize_key((string)($_GET['party_type'] ?? $_POST['party_type'] ?? 'customer'));
		$party_id   = (int)($_GET['party_id'] ?? $_POST['party_id'] ?? 0);
		$from = sanitize_text_field((string)($_GET['from'] ?? $_POST['from'] ?? ''));
		$to   = sanitize_text_field((string)($_GET['to']   ?? $_POST['to']   ?? ''));
		if (!$party_id) wp_die('party_id missing');
		// Reuse party_statement logic: aggregate from voucher rows
		$rows = [];
		$party_name = '—';
		if ($party_type === 'expert') {
			$u = get_user_by('id', $party_id);
			if ($u) $party_name = $u->display_name;
		} else {
			foreach ($this->build_receivables() as $r) if ((int)$r['customerId'] === $party_id) { $party_name = (string)$r['customerName']; break; }
		}
		$balance = 0; $debit_total = 0; $credit_total = 0;
		foreach ($this->get_vouchers() as $v) {
			if (($v['status'] ?? '') !== 'FINALIZED') continue;
			$d = (string)($v['date'] ?? '');
			if ($from && $this->fa_date_cmp($d, $from) < 0) continue;
			if ($to   && $this->fa_date_cmp($d, $to)   > 0) continue;
			foreach ((array)($v['rows'] ?? []) as $r) {
				$pid = $party_type === 'expert' ? (int)($r['expertId'] ?? 0) : (int)($r['customerId'] ?? 0);
				if ($pid !== $party_id) continue;
				$balance += (float)($r['debit'] ?? 0) - (float)($r['credit'] ?? 0);
				$debit_total += (float)($r['debit'] ?? 0);
				$credit_total += (float)($r['credit'] ?? 0);
				$rows[] = [
					'date' => $d,
					'voucherCode' => (string)($v['voucherCode'] ?? ''),
					'description' => (string)($r['description'] ?? $v['description'] ?? ''),
					'debit'   => (float)($r['debit'] ?? 0),
					'credit'  => (float)($r['credit'] ?? 0),
					'balance' => $balance,
				];
			}
		}
		nocache_headers();
		header('Content-Type: text/html; charset=UTF-8');
		echo '<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><title>صورت‌حساب ' . esc_html($party_name) . '</title>' . $this->print_styles() . '</head><body>';
		echo $this->print_bar();
		echo $this->company_header('صورت‌حساب ' . ($party_type === 'expert' ? 'کارشناس' : 'مشتری'));
		echo '<div class="ph-section"><div class="ph-grid">';
		echo '<div class="ph-field"><span class="label">نام طرف حساب</span><span class="value">' . esc_html($party_name) . '</span></div>';
		echo '<div class="ph-field"><span class="label">نوع</span><span class="value">' . ($party_type === 'expert' ? 'کارشناس' : 'مشتری') . '</span></div>';
		if ($from || $to) echo '<div class="ph-field" style="grid-column:span 2;"><span class="label">بازه</span><span class="value">' . esc_html($from ?: '—') . ' تا ' . esc_html($to ?: '—') . '</span></div>';
		echo '</div></div>';
		echo '<div class="ph-section">';
		if (empty($rows)) {
			echo '<p style="text-align:center;color:#94a3b8;padding:20px;">گردشی در این بازه نیست</p>';
		} else {
			echo '<table><thead><tr><th>تاریخ</th><th>شماره سند</th><th>شرح</th><th>بدهکار</th><th>بستانکار</th><th>مانده</th></tr></thead><tbody>';
			foreach ($rows as $r) {
				$bal_color = $r['balance'] >= 0 ? '#4338ca' : '#b45309';
				$bal_label = $r['balance'] >= 0 ? 'بد' : 'بس';
				echo '<tr><td>' . esc_html($r['date']) . '</td><td class="num">' . esc_html($r['voucherCode']) . '</td><td>' . esc_html($r['description']) . '</td>';
				echo '<td class="num amt-positive">' . ($r['debit'] > 0 ? $this->fmt_money($r['debit']) : '—') . '</td>';
				echo '<td class="num amt-negative">' . ($r['credit'] > 0 ? $this->fmt_money($r['credit']) : '—') . '</td>';
				echo '<td class="num" style="color:' . $bal_color . ';font-weight:700;">' . $this->fmt_money(abs($r['balance'])) . ' ' . $bal_label . '</td></tr>';
			}
			echo '</tbody><tfoot><tr><td colspan="3" style="text-align:left;">جمع:</td>';
			echo '<td class="num amt-positive">' . $this->fmt_money($debit_total) . '</td>';
			echo '<td class="num amt-negative">' . $this->fmt_money($credit_total) . '</td>';
			echo '<td class="num" style="color:' . ($balance >= 0 ? '#4338ca' : '#b45309') . ';font-weight:700;">مانده نهایی: ' . $this->fmt_money(abs($balance)) . ' ' . ($balance >= 0 ? 'بد' : 'بس') . '</td></tr></tfoot></table>';
		}
		echo '</div>';
		echo '<div class="ph-footer">سامانه حسابداری هماهنگ ERP — صورت‌حساب رسمی</div>';
		echo '</body></html>';
		exit;
	}

	/* ═════════════════════════════════════════════════════════════════
	 * ▼▼▼ Phase 8 — Integrations & Automations ▼▼▼
	 * ═════════════════════════════════════════════════════════════════ */

	/* ─── Bulk Import (CSV) ─── */

	/**
	 * Generic CSV import. type ∈ {income_expense, vouchers, cheques, payables, coa}.
	 * Mode: preview (validate only, no DB write) or commit (actually save).
	 */
	public function ajax_import_preview(){
		$this->check_perm('settings_manage');
		return $this->_import_run(false);
	}
	public function ajax_import_commit(){
		$this->check_perm('settings_manage');
		return $this->_import_run(true);
	}
	private function _import_run($commit){
		$type = sanitize_key((string)($_POST['type'] ?? ''));
		$csv  = isset($_POST['csv']) ? (string)wp_unslash($_POST['csv']) : '';
		if (!$type || !$csv) wp_send_json_error('type و csv الزامی است', 400);
		// Parse CSV (Persian-safe)
		$lines = preg_split('/\r\n|\n|\r/', trim($csv));
		if (count($lines) < 2) wp_send_json_error('CSV حداقل باید header + ۱ سطر داشته باشد', 400);
		$header = str_getcsv(array_shift($lines));
		$header = array_map('trim', $header);
		$rows = [];
		foreach ($lines as $ln) {
			if (trim($ln) === '') continue;
			$cells = str_getcsv($ln);
			if (count($cells) < count($header)) $cells = array_pad($cells, count($header), '');
			$rows[] = array_combine($header, array_slice($cells, 0, count($header)));
		}
		$results = ['total' => count($rows), 'ok' => 0, 'fail' => 0, 'errors' => []];
		foreach ($rows as $i => $r) {
			$idx = $i + 2; // header is line 1
			$res = $this->_import_one($type, $r, $commit);
			if ($res === true) $results['ok']++;
			else { $results['fail']++; $results['errors'][] = ['line' => $idx, 'msg' => is_string($res) ? $res : 'خطای ناشناخته']; }
		}
		$results['mode'] = $commit ? 'commit' : 'preview';
		wp_send_json_success($results);
	}
	private function _import_one($type, $row, $commit){
		// Normalize Persian digits for numeric fields
		foreach ($row as $k => $v) $row[$k] = $this->to_en_digits((string)$v);
		switch ($type) {
			case 'income_expense':
				$item = [
					'type'   => strtoupper(trim((string)($row['type'] ?? 'INCOME'))) === 'EXPENSE' ? 'EXPENSE' : 'INCOME',
					'title'  => (string)($row['title'] ?? ''),
					'amount' => (float)($row['amount'] ?? 0),
					'date'   => (string)($row['date'] ?? $this->fa_today()),
					'description' => (string)($row['description'] ?? ''),
					'bankAccountId' => (string)($row['bank_account_id'] ?? ''),
					'projectId' => (string)($row['project_id'] ?? ''),
					'category' => (string)($row['category'] ?? ''),
				];
				if (!$item['title']) return 'title خالی';
				if ($item['amount'] <= 0) return 'amount نامعتبر';
				if ($commit) {
					$_POST['item'] = wp_json_encode($item);
					// Fake-call ie_save logic
					$lock = $this->is_date_locked($item['date']);
					if ($lock['locked'] && !current_user_can('manage_options')) return 'دوره قفل: ' . $lock['reason'];
					if (!class_exists('CPTT_Finance')) return 'CPTT_Finance missing';
					$bank_id = 0;
					if (preg_match('/^t(\d+)$/', $item['bankAccountId'], $m)) $bank_id = (int)$m[1];
					if (!$bank_id) $bank_id = CPTT_Finance::default_cash_account_id();
					$pid = 0;
					if (preg_match('/^p(\d+)$/', $item['projectId'], $mp)) $pid = (int)$mp[1];
					$ledger_id = CPTT_Finance::ledger_insert([
						'account_id'=>$bank_id,
						'type'=>$item['type']==='INCOME'?'income':'expense',
						'direction'=>$item['type']==='INCOME'?1:-1,
						'amount'=>$item['amount'],
						'project_id'=>$pid,
						'description'=>$item['title'].($item['description']?' — '.$item['description']:''),
						'date_at'=>(int)current_time('timestamp', true),
						'ref_type'=>'erp_ie_import',
					]);
					$tmap = $this->map_treasury_to_coa($bank_id);
					$rows = $item['type']==='INCOME'
						? [
							['accountId'=>$tmap['id'],'accountName'=>$tmap['name'],'debit'=>$item['amount'],'credit'=>0,'description'=>'import'],
							['accountId'=>$this->map_account('revenue_default'),'accountName'=>$this->coa_name($this->map_account('revenue_default')),'debit'=>0,'credit'=>$item['amount'],'description'=>$item['title']],
						]
						: [
							['accountId'=>$this->map_account('expense_default'),'accountName'=>$this->coa_name($this->map_account('expense_default')),'debit'=>$item['amount'],'credit'=>0,'description'=>$item['title']],
							['accountId'=>$tmap['id'],'accountName'=>$tmap['name'],'debit'=>0,'credit'=>$item['amount'],'description'=>'import'],
						];
					$this->gen_voucher([
						'type'=>$item['type']==='INCOME'?'RV':'PV',
						'description'=>'(Import) '.$item['title'],
						'date'=>$item['date'],
						'source_type'=>'import',
						'ref_type'=>'erp_ie_import',
						'ref_id'=>(int)$ledger_id,
						'rows'=>$rows,
					]);
				}
				return true;
			case 'cheques':
				$c = [
					'kind'      => (string)($row['kind'] ?? 'receivable'),
					'number'    => (string)($row['number'] ?? ''),
					'sayyadi'   => (string)($row['sayyadi'] ?? ''),
					'bank'      => (string)($row['bank'] ?? ''),
					'amount'    => (float)($row['amount'] ?? 0),
					'issueDate' => (string)($row['issue_date'] ?? ''),
					'dueDate'   => (string)($row['due_date'] ?? ''),
					'partyName' => (string)($row['party_name'] ?? ''),
					'status'    => (string)($row['status'] ?? 'received'),
				];
				if (!$c['number'] || !$c['amount'] || !$c['dueDate']) return 'number/amount/due_date الزامی';
				if ($commit) {
					$c['id'] = 'chq_import_' . time() . '_' . wp_generate_password(4, false, false);
					$c['createdAt'] = $this->fa_today();
					$this->insert_cheque_into_table($c);
				}
				return true;
			case 'payables':
				$p = [
					'partyType' => (string)($row['party_type'] ?? 'other'),
					'partyName' => (string)($row['party_name'] ?? ''),
					'amount'    => (float)($row['amount'] ?? 0),
					'issueDate' => (string)($row['issue_date'] ?? $this->fa_today()),
					'dueDate'   => (string)($row['due_date'] ?? $this->fa_today()),
					'description' => (string)($row['description'] ?? ''),
				];
				if (!$p['partyName'] || $p['amount'] <= 0) return 'party_name/amount نامعتبر';
				if ($commit) {
					$p['id'] = 'pay_import_' . time() . '_' . wp_generate_password(4, false, false);
					$p['status'] = 'open';
					$p['paidAmount'] = 0;
					$p['createdAt'] = $this->fa_today();
					$this->insert_payable_into_table($p);
				}
				return true;
			case 'coa':
				$node = [
					'id'       => (string)($row['id'] ?? ('a_import_' . wp_generate_password(6, false, false))),
					'code'     => (string)($row['code'] ?? ''),
					'name'     => (string)($row['name'] ?? ''),
					'type'     => (string)($row['type'] ?? 'detail'),
					'parentId' => (string)($row['parent_id'] ?? ''),
					'balance'  => 0,
				];
				if (!$node['name'] || !$node['code']) return 'name/code الزامی';
				if ($commit) {
					$coa = $this->get_coa();
					$exists = false;
					foreach ($coa as $i => $n) if ((string)$n['id'] === $node['id']) { $coa[$i] = $node; $exists = true; break; }
					if (!$exists) $coa[] = $node;
					update_option(self::OPT_COA, $coa, false);
				}
				return true;
			default:
				return 'نوع پشتیبانی نشده: ' . $type;
		}
	}

	/* ─── Multi-currency rates ─── */

	private function get_rates(){ $v = get_option(self::OPT_CURRENCY_RATES, []); return is_array($v) ? $v : []; }
	private function save_rates($a){ update_option(self::OPT_CURRENCY_RATES, $a, false); }

	public function ajax_rates_list(){
		$this->check_perm('reports_view');
		$rates = $this->get_rates();
		// Group by currency code: { USD: [{date, rate}, ...] }
		$grouped = [];
		foreach ($rates as $key => $rate) {
			if (!preg_match('#^([A-Z]{3})/(.+)$#', $key, $m)) continue;
			$grouped[$m[1]][] = ['date' => $m[2], 'rate' => (float)$rate];
		}
		foreach ($grouped as $k => $list) {
			usort($grouped[$k], function($a, $b){ return strcmp((string)$b['date'], (string)$a['date']); });
		}
		wp_send_json_success(['rates' => $grouped]);
	}

	public function ajax_rates_save(){
		$this->check_perm('settings_manage');
		$cur  = strtoupper(sanitize_text_field((string)($_POST['currency'] ?? '')));
		$date = sanitize_text_field((string)($_POST['date'] ?? $this->fa_today()));
		$rate = (float)($_POST['rate'] ?? 0);
		if (!preg_match('/^[A-Z]{3}$/', $cur) || $rate <= 0) wp_send_json_error('invalid', 400);
		$rates = $this->get_rates();
		$key = $cur . '/' . $date;
		$old = isset($rates[$key]) ? $rates[$key] : null;
		$rates[$key] = $rate;
		$this->save_rates($rates);
		$this->audit_log($old ? 'update' : 'create', 'currency_rate', $key, ['old'=>$old], ['new'=>$rate]);
		wp_send_json_success();
	}

	public function ajax_rates_delete(){
		$this->check_perm('settings_manage');
		$cur  = strtoupper(sanitize_text_field((string)($_POST['currency'] ?? '')));
		$date = sanitize_text_field((string)($_POST['date'] ?? ''));
		$key = $cur . '/' . $date;
		$rates = $this->get_rates();
		if (isset($rates[$key])) {
			unset($rates[$key]);
			$this->save_rates($rates);
			$this->audit_log('delete', 'currency_rate', $key, null, null);
		}
		wp_send_json_success();
	}

	/**
	 * Convert amount from one currency to another using stored rates.
	 * Falls back to most recent rate before $date.
	 */
	public function ajax_currency_convert(){
		$this->check_perm('reports_view');
		$from = strtoupper(sanitize_text_field((string)($_POST['from'] ?? 'TOMAN')));
		$to   = strtoupper(sanitize_text_field((string)($_POST['to']   ?? 'TOMAN')));
		$amount = (float)($_POST['amount'] ?? 0);
		$date = sanitize_text_field((string)($_POST['date'] ?? $this->fa_today()));
		$rate_from = $from === 'TOMAN' ? 1 : $this->lookup_rate($from, $date);
		$rate_to   = $to   === 'TOMAN' ? 1 : $this->lookup_rate($to,   $date);
		if (!$rate_from || !$rate_to) wp_send_json_error('نرخ ارز موجود نیست', 400);
		$toman_value = $amount * $rate_from;
		$converted = $toman_value / $rate_to;
		wp_send_json_success([
			'from' => $from, 'to' => $to, 'amount' => $amount,
			'converted' => round($converted, 2),
			'rate_from' => $rate_from, 'rate_to' => $rate_to,
			'date' => $date,
		]);
	}

	private function lookup_rate($currency, $date){
		$rates = $this->get_rates();
		$best = 0; $best_date = '';
		foreach ($rates as $key => $r) {
			if (!preg_match('#^' . preg_quote($currency, '#') . '/(.+)$#', $key, $m)) continue;
			$d = $m[1];
			if ($this->fa_date_cmp($d, $date) > 0) continue;
			if (strcmp($d, $best_date) > 0) { $best = (float)$r; $best_date = $d; }
		}
		return $best;
	}

	/* ─── Invoice/Quote module ─── */

	public function ajax_invoice_list(){
		$this->check_perm('reports_view');
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_INVOICES;
		$rtbl = $wpdb->prefix . self::TBL_INVOICE_ROWS;
		if (!(int)$wpdb->get_var("SHOW TABLES LIKE '$tbl'")) wp_send_json_success(['rows' => []]);
		$type = sanitize_key((string)($_POST['type'] ?? ''));
		$status = sanitize_key((string)($_POST['status'] ?? ''));
		$where = ['1=1']; $params = [];
		if ($type)   { $where[] = 'type=%s'; $params[] = $type; }
		if ($status) { $where[] = 'status=%s'; $params[] = $status; }
		$sql = "SELECT * FROM $tbl WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 500";
		$rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);
		$out = [];
		foreach ((array)$rows as $r) {
			$lines = $wpdb->get_results($wpdb->prepare("SELECT * FROM $rtbl WHERE invoice_id=%s ORDER BY row_no", $r->id));
			$out[] = [
				'id' => (string)$r->id, 'number' => (string)$r->number, 'type' => (string)$r->type,
				'customerId' => (int)$r->customer_id, 'customerName' => (string)$r->customer_name,
				'projectId' => (int)$r->project_id, 'projectName' => (string)$r->project_name,
				'issueDate' => (string)$r->issue_date, 'dueDate' => (string)$r->due_date,
				'subtotal' => (float)$r->subtotal, 'discount' => (float)$r->discount,
				'taxRate' => (float)$r->tax_rate, 'taxAmount' => (float)$r->tax_amount,
				'total' => (float)$r->total, 'currency' => (string)$r->currency,
				'status' => (string)$r->status, 'notes' => (string)$r->notes,
				'voucherId' => (string)$r->voucher_id, 'createdBy' => (string)$r->created_by,
				'lines' => array_map(function($l){
					return [
						'rowNo' => (int)$l->row_no, 'title' => (string)$l->title,
						'description' => (string)$l->description,
						'quantity' => (float)$l->quantity, 'unitPrice' => (float)$l->unit_price,
						'amount' => (float)$l->amount,
					];
				}, (array)$lines),
			];
		}
		wp_send_json_success(['rows' => $out]);
	}

	public function ajax_invoice_save(){
		$this->check_perm('treasury_payment');
		$raw = isset($_POST['invoice']) ? wp_unslash($_POST['invoice']) : '';
		$inv = json_decode($raw, true);
		if (!is_array($inv) || empty($inv['number'])) wp_send_json_error('شماره فاکتور الزامی', 400);
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_INVOICES;
		$rtbl = $wpdb->prefix . self::TBL_INVOICE_ROWS;
		$id = !empty($inv['id']) ? (string)$inv['id'] : ('inv_' . time() . '_' . wp_generate_password(4, false, false));
		// Compute totals
		$subtotal = 0;
		foreach ((array)($inv['lines'] ?? []) as $l) {
			$qty = (float)($l['quantity'] ?? 1);
			$price = (float)($l['unitPrice'] ?? 0);
			$subtotal += $qty * $price;
		}
		$discount = (float)($inv['discount'] ?? 0);
		$tax_rate = (float)($inv['taxRate'] ?? 0);
		$base = max(0, $subtotal - $discount);
		$tax_amount = round($base * $tax_rate / 100, 2);
		$total = $base + $tax_amount;
		$row = [
			'id'            => $id,
			'number'        => (string)$inv['number'],
			'type'          => in_array(($inv['type'] ?? 'invoice'), ['invoice','quote','proforma'], true) ? $inv['type'] : 'invoice',
			'customer_id'   => (int)($inv['customerId'] ?? 0),
			'customer_name' => $this->safe_text((string)($inv['customerName'] ?? '')),
			'project_id'    => (int)($inv['projectId'] ?? 0),
			'project_name'  => $this->safe_text((string)($inv['projectName'] ?? '')),
			'issue_date'    => (string)($inv['issueDate'] ?? $this->fa_today()),
			'due_date'      => (string)($inv['dueDate'] ?? ''),
			'subtotal'      => round($subtotal, 2),
			'discount'      => $discount,
			'tax_rate'      => $tax_rate,
			'tax_amount'    => $tax_amount,
			'total'         => round($total, 2),
			'currency'      => (string)($inv['currency'] ?? 'TOMAN'),
			'status'        => in_array(($inv['status'] ?? 'draft'), ['draft','sent','paid','cancelled','partial'], true) ? $inv['status'] : 'draft',
			'notes'         => $this->safe_text((string)($inv['notes'] ?? '')),
			'created_by'    => wp_get_current_user()->display_name,
			'created_at'    => (int)current_time('timestamp', true),
		];
		$wpdb->replace($tbl, $row);
		$wpdb->delete($rtbl, ['invoice_id' => $id]);
		foreach ((array)($inv['lines'] ?? []) as $i => $l) {
			$qty = (float)($l['quantity'] ?? 1);
			$price = (float)($l['unitPrice'] ?? 0);
			$wpdb->insert($rtbl, [
				'invoice_id' => $id,
				'row_no'     => $i + 1,
				'title'      => $this->safe_text((string)($l['title'] ?? '')),
				'description'=> $this->safe_text((string)($l['description'] ?? '')),
				'quantity'   => $qty,
				'unit_price' => $price,
				'amount'     => round($qty * $price, 2),
			]);
		}
		$this->audit_log('save', 'invoice', $id, null, $row);
		$this->fire_webhook('invoice.saved', $row);
		wp_send_json_success(['id' => $id, 'total' => $total]);
	}

	public function ajax_invoice_delete(){
		$this->check_perm('treasury_payment');
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_INVOICES;
		$rtbl = $wpdb->prefix . self::TBL_INVOICE_ROWS;
		$wpdb->delete($rtbl, ['invoice_id' => $id]);
		$wpdb->delete($tbl, ['id' => $id]);
		$this->audit_log('delete', 'invoice', $id, null, null);
		wp_send_json_success();
	}

	public function ajax_invoice_status(){
		$this->check_perm('treasury_payment');
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		$status = sanitize_key((string)($_POST['status'] ?? ''));
		if (!in_array($status, ['draft','sent','paid','cancelled','partial'], true)) wp_send_json_error('status نامعتبر', 400);
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_INVOICES;
		$wpdb->update($tbl, ['status' => $status], ['id' => $id]);
		$this->audit_log('status', 'invoice', $id, null, ['status' => $status]);
		$this->fire_webhook('invoice.status', ['id'=>$id, 'status'=>$status]);
		wp_send_json_success();
	}

	/** Convert invoice (status=paid) to a real RV voucher */
	public function ajax_invoice_to_voucher(){
		$this->check_perm('voucher_create');
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_INVOICES;
		$inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%s", $id), ARRAY_A);
		if (!$inv) wp_send_json_error('فاکتور پیدا نشد', 404);
		if (!empty($inv['voucher_id'])) wp_send_json_error('قبلاً سند صادر شده: ' . $inv['voucher_id'], 400);
		$amount = (float)$inv['total'];
		$tmap = $this->map_treasury_to_coa(CPTT_Finance::default_cash_account_id());
		$vid = $this->gen_voucher([
			'type' => 'RV',
			'description' => 'دریافت بابت فاکتور #' . $inv['number'] . ' — ' . $inv['customer_name'],
			'date' => $this->fa_today(),
			'source_type' => 'invoice',
			'ref_type'    => 'invoice',
			'ref_id'      => 0,
			'rows' => [
				['accountId'=>$tmap['id'],'accountName'=>$tmap['name'],'debit'=>$amount,'credit'=>0,'description'=>'دریافت'],
				['accountId'=>$this->map_account('revenue_default'),'accountName'=>$this->coa_name($this->map_account('revenue_default')),'debit'=>0,'credit'=>$amount,'description'=>'درآمد فاکتور '.$inv['number']],
			],
		]);
		if (is_wp_error($vid)) wp_send_json_error($vid->get_error_message(), 500);
		$wpdb->update($tbl, ['voucher_id' => $vid, 'status' => 'paid'], ['id' => $id]);
		$this->audit_log('to_voucher', 'invoice', $id, null, ['voucher_id'=>$vid]);
		wp_send_json_success(['voucher_id' => $vid]);
	}

	public function ajax_print_invoice(){
		$this->check_perm('reports_view');
		$id = sanitize_text_field((string)($_GET['id'] ?? $_POST['id'] ?? ''));
		if (!$id) wp_die('id missing');
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_INVOICES;
		$rtbl = $wpdb->prefix . self::TBL_INVOICE_ROWS;
		$inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%s", $id), ARRAY_A);
		if (!$inv) wp_die('فاکتور پیدا نشد');
		$lines = $wpdb->get_results($wpdb->prepare("SELECT * FROM $rtbl WHERE invoice_id=%s ORDER BY row_no", $id));
		nocache_headers();
		header('Content-Type: text/html; charset=UTF-8');
		$title_word = ($inv['type'] === 'quote') ? 'پیش‌فاکتور' : (($inv['type'] === 'proforma') ? 'پروفرما' : 'فاکتور');
		echo '<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><title>'.esc_html($title_word).' ' . esc_html($inv['number']) . '</title>' . $this->print_styles() . '</head><body>';
		echo $this->print_bar();
		echo $this->company_header($title_word . ' فروش شماره ' . $inv['number']);
		echo '<div class="ph-section"><div class="ph-grid">';
		echo '<div class="ph-field"><span class="label">طرف فاکتور</span><span class="value">' . esc_html($inv['customer_name']) . '</span></div>';
		echo '<div class="ph-field"><span class="label">پروژه</span><span class="value">' . esc_html($inv['project_name'] ?: '—') . '</span></div>';
		echo '<div class="ph-field"><span class="label">تاریخ صدور</span><span class="value">' . esc_html($inv['issue_date']) . '</span></div>';
		echo '<div class="ph-field"><span class="label">سررسید پرداخت</span><span class="value">' . esc_html($inv['due_date'] ?: '—') . '</span></div>';
		echo '</div></div>';
		echo '<div class="ph-section"><h2>اقلام</h2><table><thead><tr><th>ردیف</th><th>شرح</th><th>تعداد</th><th>قیمت واحد</th><th>مبلغ</th></tr></thead><tbody>';
		$i = 1;
		foreach ((array)$lines as $l) {
			echo '<tr><td class="num">' . ($i++) . '</td><td>' . esc_html($l->title) . ($l->description ? '<br><small style="color:#94a3b8;">' . esc_html($l->description) . '</small>' : '') . '</td>';
			echo '<td class="num">' . number_format((float)$l->quantity, 2) . '</td>';
			echo '<td class="num">' . $this->fmt_money($l->unit_price) . '</td>';
			echo '<td class="num">' . $this->fmt_money($l->amount) . '</td></tr>';
		}
		echo '</tbody><tfoot>';
		echo '<tr><td colspan="4" style="text-align:left;">جمع کل اقلام:</td><td class="num">' . $this->fmt_money($inv['subtotal']) . '</td></tr>';
		if ((float)$inv['discount'] > 0) echo '<tr><td colspan="4" style="text-align:left;">تخفیف:</td><td class="num" style="color:#dc2626;">-' . $this->fmt_money($inv['discount']) . '</td></tr>';
		if ((float)$inv['tax_rate'] > 0) echo '<tr><td colspan="4" style="text-align:left;">مالیات (' . $inv['tax_rate'] . '٪):</td><td class="num">' . $this->fmt_money($inv['tax_amount']) . '</td></tr>';
		echo '<tr style="background:#eef2ff;"><td colspan="4" style="text-align:left;font-size:14px;">مبلغ نهایی قابل پرداخت:</td><td class="num" style="font-size:16px;color:#4338ca;">' . $this->fmt_money($inv['total']) . '</td></tr>';
		echo '</tfoot></table></div>';
		if ($inv['notes']) echo '<div class="ph-section"><h2>توضیحات</h2><p>' . esc_html($inv['notes']) . '</p></div>';
		echo $this->signatures();
		echo '<div class="ph-footer">سامانه حسابداری هماهنگ ERP — ' . $title_word . ' رسمی</div>';
		echo '</body></html>';
		exit;
	}

	/* ─── Webhooks (outbound) ─── */

	private function get_webhooks(){ $v = get_option(self::OPT_WEBHOOKS, []); return is_array($v) ? $v : []; }
	private function save_webhooks($a){ update_option(self::OPT_WEBHOOKS, $a, false); }

	public function ajax_webhook_list(){
		$this->check_perm('settings_manage');
		wp_send_json_success(['rows' => $this->get_webhooks()]);
	}

	public function ajax_webhook_save(){
		$this->check_perm('settings_manage');
		if (!current_user_can('manage_options')) wp_send_json_error('فقط مدیر سیستم', 403);
		$raw = isset($_POST['item']) ? wp_unslash($_POST['item']) : '';
		$it = json_decode($raw, true);
		if (!is_array($it) || empty($it['url'])) wp_send_json_error('url الزامی', 400);
		if (!filter_var($it['url'], FILTER_VALIDATE_URL)) wp_send_json_error('url نامعتبر', 400);
		$id = !empty($it['id']) ? (string)$it['id'] : ('wh_' . time() . '_' . wp_generate_password(4, false, false));
		$row = [
			'id'       => $id,
			'name'     => $this->safe_text((string)($it['name'] ?? 'Webhook')),
			'url'      => esc_url_raw((string)$it['url']),
			'events'   => is_array($it['events'] ?? null) ? array_map('sanitize_key', $it['events']) : ['*'],
			'secret'   => sanitize_text_field((string)($it['secret'] ?? '')),
			'isActive' => !empty($it['isActive']) ? 1 : 0,
			'createdAt'=> $this->fa_today(),
		];
		$all = $this->get_webhooks();
		$existing = null;
		foreach ($all as $i => $r) if ((string)$r['id'] === $id) { $existing = $r; $all[$i] = $row; break; }
		if (!$existing) $all[] = $row;
		$this->save_webhooks($all);
		$this->audit_log($existing ? 'update' : 'create', 'webhook', $id, $existing, $row);
		wp_send_json_success(['id' => $id]);
	}

	public function ajax_webhook_delete(){
		$this->check_perm('settings_manage');
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		$all = $this->get_webhooks();
		$all = array_values(array_filter($all, function($r) use ($id){ return (string)$r['id'] !== $id; }));
		$this->save_webhooks($all);
		$this->audit_log('delete', 'webhook', $id, null, null);
		wp_send_json_success();
	}

	public function ajax_webhook_test(){
		$this->check_perm('settings_manage');
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		foreach ($this->get_webhooks() as $w) {
			if ((string)$w['id'] !== $id) continue;
			$res = $this->_deliver_webhook($w, 'test.ping', ['message' => 'این یک تست از سامانه هماهنگ ERP است', 'at' => $this->fa_today()]);
			if (is_wp_error($res)) wp_send_json_error($res->get_error_message(), 502);
			wp_send_json_success(['status' => $res]);
		}
		wp_send_json_error('webhook یافت نشد', 404);
	}

	/** Fire webhook event to all subscribers matching $event */
	private function fire_webhook($event, $payload){
		foreach ($this->get_webhooks() as $w) {
			if (empty($w['isActive'])) continue;
			$evs = (array)($w['events'] ?? ['*']);
			if (!in_array('*', $evs, true) && !in_array($event, $evs, true)) continue;
			// Fire-and-forget: nonblocking POST
			$this->_deliver_webhook($w, $event, $payload, true);
		}
	}

	private function _deliver_webhook($w, $event, $payload, $nonblocking = false){
		$body = wp_json_encode([
			'event'   => $event,
			'data'    => $payload,
			'sentAt'  => current_time('mysql'),
			'site'    => get_bloginfo('name'),
		], JSON_UNESCAPED_UNICODE);
		$headers = ['Content-Type' => 'application/json; charset=UTF-8'];
		if (!empty($w['secret'])) $headers['X-Hamahang-Signature'] = hash_hmac('sha256', $body, (string)$w['secret']);
		$args = [
			'method'    => 'POST',
			'headers'   => $headers,
			'body'      => $body,
			'timeout'   => $nonblocking ? 1 : 10,
			'blocking'  => !$nonblocking,
		];
		$r = wp_remote_request((string)$w['url'], $args);
		if (is_wp_error($r)) return $r;
		return wp_remote_retrieve_response_code($r);
	}

	/* ─── REST API keys ─── */

	private function get_api_keys(){ $v = get_option(self::OPT_API_KEYS, []); return is_array($v) ? $v : []; }
	private function save_api_keys($a){ update_option(self::OPT_API_KEYS, $a, false); }

	public function ajax_apikey_list(){
		$this->check_perm('settings_manage');
		// Return only id, name, scope, last4 (NOT the full key)
		$out = [];
		foreach ($this->get_api_keys() as $k) {
			$out[] = [
				'id'       => $k['id'],
				'name'     => $k['name'],
				'scope'    => $k['scope'] ?? 'read',
				'last4'    => substr((string)$k['key'], -4),
				'createdAt'=> $k['createdAt'] ?? '',
				'lastUsed' => $k['lastUsed'] ?? '',
			];
		}
		wp_send_json_success(['rows' => $out]);
	}

	public function ajax_apikey_create(){
		$this->check_perm('settings_manage');
		if (!current_user_can('manage_options')) wp_send_json_error('فقط مدیر سیستم', 403);
		$name = $this->safe_text((string)($_POST['name'] ?? 'API Key'));
		$scope = in_array(($_POST['scope'] ?? 'read'), ['read','readwrite'], true) ? $_POST['scope'] : 'read';
		$key = 'hk_' . wp_generate_password(32, false, false);
		$id  = 'apikey_' . time() . '_' . wp_generate_password(4, false, false);
		$all = $this->get_api_keys();
		$all[] = [
			'id'        => $id,
			'name'      => $name,
			'scope'     => $scope,
			'key'       => $key, // stored once; UI shows full key on creation only
			'createdAt' => $this->fa_today(),
			'lastUsed'  => '',
		];
		$this->save_api_keys($all);
		$this->audit_log('create', 'api_key', $id, null, ['name'=>$name,'scope'=>$scope]);
		wp_send_json_success(['id' => $id, 'key' => $key]);
	}

	public function ajax_apikey_revoke(){
		$this->check_perm('settings_manage');
		$id = sanitize_text_field((string)($_POST['id'] ?? ''));
		$all = $this->get_api_keys();
		$all = array_values(array_filter($all, function($r) use ($id){ return (string)$r['id'] !== $id; }));
		$this->save_api_keys($all);
		$this->audit_log('revoke', 'api_key', $id, null, null);
		wp_send_json_success();
	}

	/** Validate an incoming API key header (for REST). Returns scope or false. */
	private function validate_api_key($key){
		if (!$key) return false;
		$all = $this->get_api_keys();
		foreach ($all as $i => $k) {
			if (hash_equals((string)$k['key'], (string)$key)) {
				// Update lastUsed
				$all[$i]['lastUsed'] = current_time('mysql');
				$this->save_api_keys($all);
				return (string)($k['scope'] ?? 'read');
			}
		}
		return false;
	}

	/* ─── REST API routes (public read-only via X-API-Key header) ─── */

	public function register_rest_routes(){
		$ns = 'cpttf-erp/v1';
		$auth_cb = function(){
			$key = isset($_SERVER['HTTP_X_API_KEY']) ? (string)$_SERVER['HTTP_X_API_KEY'] : '';
			$scope = $this->validate_api_key($key);
			return $scope !== false;
		};
		register_rest_route($ns, '/health', [
			'methods'  => 'GET',
			'callback' => function(){ return ['ok' => true, 'version' => CPTT_VERSION, 'time' => current_time('mysql')]; },
			'permission_callback' => '__return_true',
		]);
		register_rest_route($ns, '/vouchers', [
			'methods'  => 'GET',
			'callback' => function($req){
				$limit = max(1, min(500, (int)$req->get_param('limit')));
				return ['rows' => $this->read_vouchers_from_table($limit ?: 100)];
			},
			'permission_callback' => $auth_cb,
		]);
		register_rest_route($ns, '/accounts', [
			'methods'  => 'GET',
			'callback' => function(){ return ['rows' => $this->get_coa_with_balances()]; },
			'permission_callback' => $auth_cb,
		]);
		register_rest_route($ns, '/receivables', [
			'methods'  => 'GET',
			'callback' => function(){ return ['rows' => $this->build_receivables()]; },
			'permission_callback' => $auth_cb,
		]);
		register_rest_route($ns, '/cheques', [
			'methods'  => 'GET',
			'callback' => function(){ return ['rows' => $this->get_cheques()]; },
			'permission_callback' => $auth_cb,
		]);
		register_rest_route($ns, '/payables', [
			'methods'  => 'GET',
			'callback' => function(){ return ['rows' => $this->get_payables_recomputed()]; },
			'permission_callback' => $auth_cb,
		]);
		register_rest_route($ns, '/invoices', [
			'methods'  => 'GET',
			'callback' => function(){
				global $wpdb; $tbl = $wpdb->prefix . self::TBL_INVOICES;
				if (!(int)$wpdb->get_var("SHOW TABLES LIKE '$tbl'")) return ['rows' => []];
				return ['rows' => $wpdb->get_results("SELECT id, number, type, customer_name, total, status, issue_date, due_date FROM $tbl ORDER BY created_at DESC LIMIT 500")];
			},
			'permission_callback' => $auth_cb,
		]);
		register_rest_route($ns, '/kpis', [
			'methods'  => 'GET',
			'callback' => function(){
				return [
					'kpis'           => class_exists('CPTT_Finance') ? CPTT_Finance::compute_kpis() : [],
					'financialHealth'=> $this->build_financial_health(),
				];
			},
			'permission_callback' => $auth_cb,
		]);
	}

	/* ─── 2FA (TOTP-like, time-window 6-digit OTP) ─── */

	/**
	 * Light-weight 2FA: when enabled, the user must enter a code from
	 * a deterministic time-rotation OR (fallback) a one-time code emailed
	 * to them. To avoid adding heavy deps, we use a HMAC-based 30s OTP
	 * derived from user-specific secret + WordPress site auth_key.
	 */
	private function generate_otp($user_id, $window = 0){
		$secret = wp_salt('auth') . '|' . $user_id . '|' . (string)(get_option(self::OPT_2FA, [])[$user_id]['secret'] ?? '');
		$step = (int) floor(time() / 30) + $window;
		$h = hash_hmac('sha256', (string)$step, $secret, true);
		$offset = ord($h[strlen($h) - 1]) & 0x0F;
		$bin = (ord($h[$offset]) & 0x7F) << 24
			| (ord($h[$offset+1]) & 0xFF) << 16
			| (ord($h[$offset+2]) & 0xFF) << 8
			| (ord($h[$offset+3]) & 0xFF);
		return str_pad((string)($bin % 1000000), 6, '0', STR_PAD_LEFT);
	}

	public function ajax_2fa_status(){
		$this->check();
		$all = get_option(self::OPT_2FA, []);
		$uid = get_current_user_id();
		$enabled = !empty($all[$uid]['enabled']);
		wp_send_json_success(['enabled' => $enabled]);
	}

	public function ajax_2fa_enable(){
		$this->check();
		if (!current_user_can('manage_options')) wp_send_json_error('۲FA فقط برای مدیر سیستم در حال حاضر', 403);
		$uid = get_current_user_id();
		$all = get_option(self::OPT_2FA, []);
		if (!is_array($all)) $all = [];
		$secret = wp_generate_password(16, false, false);
		$all[$uid] = ['enabled' => false, 'secret' => $secret, 'created_at' => current_time('mysql')];
		update_option(self::OPT_2FA, $all, false);
		// Send a sample OTP via email to verify
		$otp = $this->generate_otp($uid);
		$u = wp_get_current_user();
		if ($u && $u->user_email) {
			wp_mail($u->user_email, 'فعال‌سازی ۲FA — هماهنگ ERP', 'کد یکبار مصرف شما برای فعال‌سازی: ' . $otp . "\n\n(اعتبار: ۳۰ ثانیه)");
		}
		wp_send_json_success(['otp_sent_to' => $u->user_email]);
	}

	public function ajax_2fa_verify(){
		$this->check();
		$uid = get_current_user_id();
		$code = sanitize_text_field((string)($_POST['code'] ?? ''));
		$all = get_option(self::OPT_2FA, []);
		if (!isset($all[$uid])) wp_send_json_error('۲FA راه‌اندازی نشده', 400);
		// Accept current OR previous OR next window (90s tolerance)
		$ok = false;
		foreach ([-1, 0, 1] as $w) {
			if (hash_equals($this->generate_otp($uid, $w), $code)) { $ok = true; break; }
		}
		if (!$ok) wp_send_json_error('کد نامعتبر', 400);
		$all[$uid]['enabled'] = true;
		update_option(self::OPT_2FA, $all, false);
		$this->audit_log('enable', '2fa', (string)$uid, null, null);
		wp_send_json_success();
	}

	public function ajax_2fa_disable(){
		$this->check();
		$uid = get_current_user_id();
		$all = get_option(self::OPT_2FA, []);
		if (isset($all[$uid])) {
			$all[$uid]['enabled'] = false;
			update_option(self::OPT_2FA, $all, false);
			$this->audit_log('disable', '2fa', (string)$uid, null, null);
		}
		wp_send_json_success();
	}

	/* Phase 8: Integrations admin page (CSV import, rates, webhooks, API, 2FA) */
	public function render_integrations_page(){
		if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');
		$nonce = wp_create_nonce(self::NONCE);
		$ajax  = admin_url('admin-ajax.php');
		$webhooks = $this->get_webhooks();
		$rates    = $this->get_rates();
		$keys     = $this->get_api_keys();
		$two_fa   = get_option(self::OPT_2FA, []);
		$uid      = get_current_user_id();
		$two_fa_on= !empty($two_fa[$uid]['enabled']);
		?>
		<style>
			.cptt-int-wrap { max-width: 1200px; margin: 18px 8px; font-family: Tahoma, sans-serif; direction: rtl; }
			.cptt-int-wrap h1 { font-size: 1.4rem; color: #1e293b; margin: 0 0 4px; }
			.cptt-int-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; background: #fff; padding: 6px; border-radius: 12px; border: 1px solid #e2e8f0; }
			.cptt-int-tab { padding: 8px 14px; border-radius: 8px; cursor: pointer; font-size: .8rem; font-weight: 700; color: #475569; background: transparent; border: 0; font-family: inherit; }
			.cptt-int-tab.active { background: #4f46e5; color: #fff; }
			.cptt-int-tab:hover:not(.active) { background: #f1f5f9; }
			.cptt-int-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 18px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
			.cptt-int-card h2 { font-size: 1.05rem; color: #4f46e5; margin: 0 0 12px; padding-bottom: 6px; border-bottom: 1px dashed #cbd5e1; }
			.cptt-int-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; }
			.cptt-int-field label { display: block; font-size: .72rem; font-weight: 700; color: #475569; margin-bottom: 4px; }
			.cptt-int-field input, .cptt-int-field select, .cptt-int-field textarea { width: 100%; padding: 7px 10px; border-radius: 8px; border: 1.5px solid #e2e8f0; font-size: .8rem; font-family: inherit; direction: rtl; text-align: right; }
			.cptt-int-field textarea { font-family: monospace; direction: ltr; text-align: left; min-height: 200px; }
			.cptt-int-btn { padding: 8px 18px; border-radius: 8px; border: 0; background: #4f46e5; color: #fff; font-size: .8rem; font-weight: 700; cursor: pointer; font-family: inherit; }
			.cptt-int-btn:hover { background: #4338ca; }
			.cptt-int-btn.ghost { background: #f1f5f9; color: #334155; }
			.cptt-int-btn.danger { background: #e11d48; color: #fff; }
			.cptt-int-btn.success { background: #059669; color: #fff; }
			.cptt-int-table { width: 100%; border-collapse: collapse; font-size: .78rem; margin-top: 8px; }
			.cptt-int-table th, .cptt-int-table td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; text-align: right; }
			.cptt-int-table th { background: #f8fafc; font-weight: 700; color: #475569; }
			.cptt-int-msg { padding: 10px; border-radius: 8px; font-size: .8rem; margin: 8px 0; }
			.cptt-int-msg.ok { background: #d1fae5; color: #065f46; }
			.cptt-int-msg.err { background: #fee2e2; color: #991b1b; }
			.cptt-int-codeblock { background: #1e293b; color: #e2e8f0; padding: 12px; border-radius: 8px; font-family: monospace; direction: ltr; text-align: left; font-size: .75rem; word-break: break-all; }
			.cptt-int-pill { display:inline-block; padding: 2px 8px; border-radius: 999px; font-size: .65rem; font-weight: 700; }
			.cptt-int-pill.ok { background: #d1fae5; color: #065f46; }
			.cptt-int-pill.off { background: #f1f5f9; color: #64748b; }
		</style>
		<div class="cptt-int-wrap">
			<h1>🔌 یکپارچه‌سازی و اتوماسیون</h1>
			<div id="cptt-int-msg-host"></div>
			<div class="cptt-int-tabs">
				<button class="cptt-int-tab active" data-tab="import">📥 Bulk Import</button>
				<button class="cptt-int-tab" data-tab="rates">💱 نرخ ارز</button>
				<button class="cptt-int-tab" data-tab="webhooks">🪝 Webhooks</button>
				<button class="cptt-int-tab" data-tab="api">🔑 REST API</button>
				<button class="cptt-int-tab" data-tab="2fa">🔒 ۲FA</button>
			</div>

			<!-- ── Import ── -->
			<div class="cptt-int-card" data-pane="import">
				<h2>📥 وارد کردن دسته‌ای (CSV)</h2>
				<div class="cptt-int-grid">
					<div class="cptt-int-field"><label>نوع داده</label><select id="imp-type">
						<option value="income_expense">درآمد/هزینه</option>
						<option value="cheques">چک‌ها</option>
						<option value="payables">بدهی‌ها</option>
						<option value="coa">سرفصل حساب‌ها</option>
					</select></div>
				</div>
				<div class="cptt-int-field" style="margin-top:10px;">
					<label>محتوای CSV (سطر اول = header)</label>
					<textarea id="imp-csv" placeholder="مثال (income_expense): type,title,amount,date,bank_account_id,project_id&#10;INCOME,واریز پیش‌پرداخت,5000000,۱۴۰۴/۰۳/۰۱,t1,p10"></textarea>
				</div>
				<div style="margin-top:10px;display:flex;gap:8px;">
					<button class="cptt-int-btn ghost" id="imp-preview">👁 پیش‌نمایش (تست)</button>
					<button class="cptt-int-btn success" id="imp-commit">💾 ذخیره</button>
				</div>
				<div id="imp-result" style="margin-top:10px;"></div>
				<div style="margin-top:14px;padding:10px;background:#f8fafc;border-radius:8px;font-size:.75rem;color:#64748b;">
					<strong>راهنمای فیلد per نوع:</strong>
					<ul style="margin:6px 0 0 16px;">
						<li><strong>income_expense</strong>: type, title, amount, date, bank_account_id, project_id, description, category</li>
						<li><strong>cheques</strong>: kind, number, sayyadi, bank, amount, issue_date, due_date, party_name, status</li>
						<li><strong>payables</strong>: party_type, party_name, amount, issue_date, due_date, description</li>
						<li><strong>coa</strong>: id, code, name, type, parent_id</li>
					</ul>
				</div>
			</div>

			<!-- ── Currency Rates ── -->
			<div class="cptt-int-card" data-pane="rates" style="display:none;">
				<h2>💱 نرخ ارز روزانه</h2>
				<div class="cptt-int-grid">
					<div class="cptt-int-field"><label>ارز (3 حرف)</label><input type="text" id="rt-cur" placeholder="USD"></div>
					<div class="cptt-int-field"><label>تاریخ (شمسی)</label><input type="text" id="rt-date" value="<?php echo esc_attr($this->fa_today()); ?>"></div>
					<div class="cptt-int-field"><label>نرخ به تومان</label><input type="number" id="rt-rate" placeholder="65000"></div>
					<div><button class="cptt-int-btn success" id="rt-save" style="margin-top:18px;">💾 ذخیره</button></div>
				</div>
				<table class="cptt-int-table" style="margin-top:14px;">
					<thead><tr><th>ارز</th><th>تاریخ</th><th>نرخ</th><th>عمل</th></tr></thead>
					<tbody id="rt-tbody">
					<?php if (empty($rates)): ?>
						<tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:20px;">نرخی ثبت نشده</td></tr>
					<?php else: foreach ($rates as $k => $r): if (!preg_match('#^([A-Z]{3})/(.+)$#', $k, $m)) continue; ?>
						<tr>
							<td><strong><?php echo esc_html($m[1]); ?></strong></td>
							<td><?php echo esc_html($m[2]); ?></td>
							<td class="num"><?php echo number_format((float)$r); ?> تومان</td>
							<td><button class="cptt-int-btn danger rt-del" data-cur="<?php echo esc_attr($m[1]); ?>" data-date="<?php echo esc_attr($m[2]); ?>">حذف</button></td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>

			<!-- ── Webhooks ── -->
			<div class="cptt-int-card" data-pane="webhooks" style="display:none;">
				<h2>🪝 Webhook‌های خروجی</h2>
				<table class="cptt-int-table">
					<thead><tr><th>نام</th><th>URL</th><th>رویدادها</th><th>وضعیت</th><th>عمل</th></tr></thead>
					<tbody>
					<?php if (empty($webhooks)): ?>
						<tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:20px;">webhookی ثبت نشده</td></tr>
					<?php else: foreach ($webhooks as $w): ?>
						<tr>
							<td><strong><?php echo esc_html($w['name']); ?></strong></td>
							<td style="font-family:monospace;font-size:.7rem;direction:ltr;text-align:left;"><?php echo esc_html($w['url']); ?></td>
							<td><?php echo esc_html(implode(', ', (array)$w['events'])); ?></td>
							<td><span class="cptt-int-pill <?php echo !empty($w['isActive']) ? 'ok' : 'off'; ?>"><?php echo !empty($w['isActive']) ? 'فعال' : 'غیرفعال'; ?></span></td>
							<td>
								<button class="cptt-int-btn ghost wh-test" data-id="<?php echo esc_attr($w['id']); ?>">⚡ تست</button>
								<button class="cptt-int-btn danger wh-del" data-id="<?php echo esc_attr($w['id']); ?>">حذف</button>
							</td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
				<h3 style="font-size:.9rem;margin-top:16px;">➕ تعریف Webhook جدید</h3>
				<div class="cptt-int-grid">
					<div class="cptt-int-field"><label>نام</label><input type="text" id="wh-name" placeholder="Slack notification"></div>
					<div class="cptt-int-field" style="grid-column:span 2;"><label>URL</label><input type="text" id="wh-url" placeholder="https://example.com/webhook"></div>
					<div class="cptt-int-field"><label>رویدادها (با کاما — یا * برای همه)</label><input type="text" id="wh-events" value="*" placeholder="voucher.created, invoice.saved"></div>
					<div class="cptt-int-field"><label>Secret (HMAC SHA256)</label><input type="text" id="wh-secret" placeholder="optional"></div>
				</div>
				<button class="cptt-int-btn success" id="wh-save" style="margin-top:10px;">💾 ذخیره</button>
				<div style="margin-top:14px;padding:10px;background:#f8fafc;border-radius:8px;font-size:.75rem;color:#64748b;">
					<strong>رویدادهای موجود:</strong> <code>voucher.created</code>, <code>invoice.saved</code>, <code>invoice.status</code>, <code>*</code> (همه)
				</div>
			</div>

			<!-- ── REST API ── -->
			<div class="cptt-int-card" data-pane="api" style="display:none;">
				<h2>🔑 کلیدهای REST API</h2>
				<table class="cptt-int-table">
					<thead><tr><th>نام</th><th>دسترسی</th><th>۴ کاراکتر آخر</th><th>ساخت</th><th>آخرین استفاده</th><th>عمل</th></tr></thead>
					<tbody>
					<?php if (empty($keys)): ?>
						<tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:20px;">کلیدی ساخته نشده</td></tr>
					<?php else: foreach ($keys as $k): ?>
						<tr>
							<td><strong><?php echo esc_html($k['name']); ?></strong></td>
							<td><?php echo esc_html($k['scope'] ?? 'read'); ?></td>
							<td style="font-family:monospace;">…<?php echo esc_html(substr((string)$k['key'], -4)); ?></td>
							<td><?php echo esc_html($k['createdAt'] ?? ''); ?></td>
							<td><?php echo esc_html($k['lastUsed'] ?? '—'); ?></td>
							<td><button class="cptt-int-btn danger ak-del" data-id="<?php echo esc_attr($k['id']); ?>">باطل کردن</button></td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
				<h3 style="font-size:.9rem;margin-top:16px;">➕ ایجاد کلید جدید</h3>
				<div class="cptt-int-grid">
					<div class="cptt-int-field"><label>نام</label><input type="text" id="ak-name" placeholder="Reporting Tool"></div>
					<div class="cptt-int-field"><label>سطح دسترسی</label><select id="ak-scope"><option value="read">فقط خواندن</option><option value="readwrite">خواندن/نوشتن</option></select></div>
					<div><button class="cptt-int-btn success" id="ak-create" style="margin-top:18px;">🔑 ایجاد</button></div>
				</div>
				<div id="ak-new-result" style="margin-top:10px;"></div>
				<div style="margin-top:14px;padding:10px;background:#f8fafc;border-radius:8px;font-size:.75rem;color:#64748b;">
					<strong>نمونه فراخوانی:</strong>
					<div class="cptt-int-codeblock" style="margin-top:6px;">curl -H "X-API-Key: hk_xxxxxxxx" <?php echo esc_html(home_url('/wp-json/cpttf-erp/v1/vouchers?limit=10')); ?></div>
					<strong style="display:block;margin-top:10px;">Endpoint های موجود:</strong>
					<ul style="margin:6px 0 0 16px;font-family:monospace;direction:ltr;text-align:left;font-size:.7rem;">
						<li>GET /wp-json/cpttf-erp/v1/health</li>
						<li>GET /wp-json/cpttf-erp/v1/vouchers?limit=100</li>
						<li>GET /wp-json/cpttf-erp/v1/accounts</li>
						<li>GET /wp-json/cpttf-erp/v1/receivables</li>
						<li>GET /wp-json/cpttf-erp/v1/cheques</li>
						<li>GET /wp-json/cpttf-erp/v1/payables</li>
						<li>GET /wp-json/cpttf-erp/v1/invoices</li>
						<li>GET /wp-json/cpttf-erp/v1/kpis</li>
					</ul>
				</div>
			</div>

			<!-- ── 2FA ── -->
			<div class="cptt-int-card" data-pane="2fa" style="display:none;">
				<h2>🔒 احراز هویت دو مرحله‌ای (۲FA)</h2>
				<div style="padding:14px;background:#f8fafc;border-radius:10px;margin-bottom:14px;">
					<strong>وضعیت فعلی شما:</strong>
					<span class="cptt-int-pill <?php echo $two_fa_on ? 'ok' : 'off'; ?>" style="margin-right:8px;"><?php echo $two_fa_on ? '🔒 فعال' : '🔓 غیرفعال'; ?></span>
				</div>
				<?php if (!$two_fa_on): ?>
					<p style="color:#64748b;font-size:.85rem;">با کلیک روی «فعال‌سازی»، یک کد ۶ رقمی به ایمیل شما (<strong><?php echo esc_html(wp_get_current_user()->user_email); ?></strong>) ارسال می‌شود. کد را در فیلد زیر وارد کنید.</p>
					<button class="cptt-int-btn" id="fa-enable">📧 فعال‌سازی</button>
					<div id="fa-verify-box" style="display:none;margin-top:12px;">
						<div class="cptt-int-grid">
							<div class="cptt-int-field"><label>کد ۶ رقمی از ایمیل</label><input type="text" id="fa-code" placeholder="123456" maxlength="6"></div>
							<div><button class="cptt-int-btn success" id="fa-verify" style="margin-top:18px;">✅ تأیید</button></div>
						</div>
					</div>
				<?php else: ?>
					<p style="color:#64748b;font-size:.85rem;">۲FA برای حساب شما فعال است. در صورت نیاز می‌توانید آن را غیرفعال کنید.</p>
					<button class="cptt-int-btn danger" id="fa-disable">🔓 غیرفعال‌سازی</button>
				<?php endif; ?>
				<div style="margin-top:14px;padding:10px;background:#fef3c7;border-radius:8px;font-size:.75rem;color:#92400e;">
					<strong>توجه:</strong> این ۲FA سبک‌وزن است و بر اساس کد ایمیل‌شده + الگوریتم HMAC کار می‌کند. برای امنیت بالاتر در محصولات تجاری، از پلاگین‌های اختصاصی ۲FA استفاده کنید.
				</div>
			</div>
		</div>
		<script>
		(function(){
			var nonce = '<?php echo esc_js($nonce); ?>';
			var ajax  = '<?php echo esc_js($ajax); ?>';
			function msg(t, ok){ var h=document.getElementById('cptt-int-msg-host'); h.innerHTML='<div class="cptt-int-msg '+(ok?'ok':'err')+'">'+t+'</div>'; setTimeout(function(){h.innerHTML='';}, 4000); }
			function call(action, data, cb){
				var fd = new FormData(); fd.append('action', action); fd.append('nonce', nonce);
				Object.keys(data||{}).forEach(function(k){ var v=data[k]; if(typeof v==='object') v=JSON.stringify(v); fd.append(k, v); });
				fetch(ajax, {method:'POST', body: fd, credentials:'same-origin'}).then(function(r){ return r.json(); }).then(cb).catch(function(e){ cb({success:false, data:{message:String(e)}}); });
			}
			document.querySelectorAll('.cptt-int-tab').forEach(function(b){
				b.onclick = function(){
					document.querySelectorAll('.cptt-int-tab').forEach(function(x){x.classList.remove('active');});
					b.classList.add('active');
					var t = b.getAttribute('data-tab');
					document.querySelectorAll('[data-pane]').forEach(function(p){ p.style.display = (p.getAttribute('data-pane')===t) ? '' : 'none'; });
				};
			});
			// Import
			function runImport(commit){
				var type = document.getElementById('imp-type').value;
				var csv  = document.getElementById('imp-csv').value;
				if (!csv) { msg('❌ CSV خالی', false); return; }
				call(commit ? 'cpttf_erp_import_commit' : 'cpttf_erp_import_preview', {type: type, csv: csv}, function(r){
					if (!r.success) { document.getElementById('imp-result').innerHTML = '<div class="cptt-int-msg err">❌ '+(r.data&&r.data.message?r.data.message:'خطا')+'</div>'; return; }
					var d = r.data;
					var h = '<div class="cptt-int-msg ok">' + (d.mode==='commit'?'✅ ذخیره شد':'👁 پیش‌نمایش') + ' — کل: '+d.total+' / موفق: '+d.ok+' / خطا: '+d.fail+'</div>';
					if (d.errors && d.errors.length) {
						h += '<table class="cptt-int-table"><thead><tr><th>خط</th><th>پیام</th></tr></thead><tbody>';
						d.errors.forEach(function(e){ h += '<tr><td>'+e.line+'</td><td style="color:#dc2626;">'+e.msg+'</td></tr>'; });
						h += '</tbody></table>';
					}
					document.getElementById('imp-result').innerHTML = h;
				});
			}
			document.getElementById('imp-preview').onclick = function(){ runImport(false); };
			document.getElementById('imp-commit').onclick = function(){ if (!confirm('ذخیره نهایی انجام شود؟')) return; runImport(true); };
			// Rates
			document.getElementById('rt-save').onclick = function(){
				call('cpttf_erp_rates_save', {currency: document.getElementById('rt-cur').value, date: document.getElementById('rt-date').value, rate: document.getElementById('rt-rate').value}, function(r){
					if (r.success) { msg('✅ ذخیره شد', true); setTimeout(function(){ location.reload(); }, 1200); }
					else msg('❌ '+(r.data&&r.data.message?r.data.message:'خطا'), false);
				});
			};
			document.querySelectorAll('.rt-del').forEach(function(b){
				b.onclick = function(){
					if (!confirm('حذف؟')) return;
					call('cpttf_erp_rates_delete', {currency: b.getAttribute('data-cur'), date: b.getAttribute('data-date')}, function(r){
						if (r.success) location.reload();
					});
				};
			});
			// Webhooks
			document.getElementById('wh-save').onclick = function(){
				var events = document.getElementById('wh-events').value.split(',').map(function(x){return x.trim();}).filter(Boolean);
				var item = {
					name: document.getElementById('wh-name').value,
					url:  document.getElementById('wh-url').value,
					events: events,
					secret: document.getElementById('wh-secret').value,
					isActive: 1
				};
				if (!item.url) { msg('❌ url الزامی', false); return; }
				call('cpttf_erp_webhook_save', {item: item}, function(r){
					if (r.success) { msg('✅ ذخیره شد', true); setTimeout(function(){ location.reload(); }, 1200); }
					else msg('❌ '+(r.data&&r.data.message?r.data.message:'خطا'), false);
				});
			};
			document.querySelectorAll('.wh-test').forEach(function(b){
				b.onclick = function(){
					call('cpttf_erp_webhook_test', {id: b.getAttribute('data-id')}, function(r){
						if (r.success) msg('✅ ارسال شد — کد پاسخ: '+r.data.status, true);
						else msg('❌ '+(r.data&&r.data.message?r.data.message:'خطا'), false);
					});
				};
			});
			document.querySelectorAll('.wh-del').forEach(function(b){
				b.onclick = function(){
					if (!confirm('حذف؟')) return;
					call('cpttf_erp_webhook_delete', {id: b.getAttribute('data-id')}, function(r){ if (r.success) location.reload(); });
				};
			});
			// API keys
			document.getElementById('ak-create').onclick = function(){
				call('cpttf_erp_apikey_create', {name: document.getElementById('ak-name').value, scope: document.getElementById('ak-scope').value}, function(r){
					if (!r.success) { msg('❌ '+(r.data&&r.data.message?r.data.message:'خطا'), false); return; }
					document.getElementById('ak-new-result').innerHTML = '<div class="cptt-int-msg ok">✅ کلید ایجاد شد. <strong>این کلید فقط همین یک بار قابل مشاهده است:</strong></div><div class="cptt-int-codeblock">'+r.data.key+'</div>';
					setTimeout(function(){ location.reload(); }, 4000);
				});
			};
			document.querySelectorAll('.ak-del').forEach(function(b){
				b.onclick = function(){
					if (!confirm('این کلید باطل شود؟ پس از این قابل استفاده نخواهد بود.')) return;
					call('cpttf_erp_apikey_revoke', {id: b.getAttribute('data-id')}, function(r){ if (r.success) location.reload(); });
				};
			});
			// 2FA
			var en = document.getElementById('fa-enable');
			if (en) en.onclick = function(){
				call('cpttf_erp_2fa_enable', {}, function(r){
					if (r.success) {
						document.getElementById('fa-verify-box').style.display = 'block';
						msg('✅ کد به ایمیل '+r.data.otp_sent_to+' ارسال شد', true);
					} else msg('❌ '+(r.data&&r.data.message?r.data.message:'خطا'), false);
				});
			};
			var vr = document.getElementById('fa-verify');
			if (vr) vr.onclick = function(){
				call('cpttf_erp_2fa_verify', {code: document.getElementById('fa-code').value}, function(r){
					if (r.success) { msg('✅ ۲FA فعال شد', true); setTimeout(function(){ location.reload(); }, 1500); }
					else msg('❌ '+(r.data&&r.data.message?r.data.message:'کد نامعتبر'), false);
				});
			};
			var dis = document.getElementById('fa-disable');
			if (dis) dis.onclick = function(){
				if (!confirm('۲FA غیرفعال شود؟')) return;
				call('cpttf_erp_2fa_disable', {}, function(r){ if (r.success) location.reload(); });
			};
		})();
		</script>
		<?php
	}

	/* Phase 8: Invoices admin page (list + create + print) */
	public function render_invoices_page(){
		if (!current_user_can('edit_cptt_projects')) wp_die('دسترسی غیرمجاز');
		$nonce = wp_create_nonce(self::NONCE);
		$ajax  = admin_url('admin-ajax.php');
		global $wpdb;
		$tbl = $wpdb->prefix . self::TBL_INVOICES;
		$inv_exists = (int)$wpdb->get_var("SHOW TABLES LIKE '$tbl'");
		$rows = [];
		if ($inv_exists) $rows = $wpdb->get_results("SELECT * FROM $tbl ORDER BY created_at DESC LIMIT 100");
		?>
		<style>
			.cptt-inv-wrap { max-width: 1200px; margin: 18px 8px; font-family: Tahoma, sans-serif; direction: rtl; }
			.cptt-inv-wrap h1 { font-size: 1.4rem; color: #1e293b; margin: 0 0 14px; }
			.cptt-inv-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 18px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
			.cptt-inv-card h2 { font-size: 1.05rem; color: #4f46e5; margin: 0 0 12px; padding-bottom: 6px; border-bottom: 1px dashed #cbd5e1; }
			.cptt-inv-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; }
			.cptt-inv-field label { display: block; font-size: .72rem; font-weight: 700; color: #475569; margin-bottom: 4px; }
			.cptt-inv-field input, .cptt-inv-field select, .cptt-inv-field textarea { width: 100%; padding: 7px 10px; border-radius: 8px; border: 1.5px solid #e2e8f0; font-size: .8rem; font-family: inherit; direction: rtl; text-align: right; }
			.cptt-inv-btn { padding: 8px 18px; border-radius: 8px; border: 0; background: #4f46e5; color: #fff; font-size: .8rem; font-weight: 700; cursor: pointer; font-family: inherit; }
			.cptt-inv-btn:hover { background: #4338ca; }
			.cptt-inv-btn.success { background: #059669; }
			.cptt-inv-btn.ghost { background: #f1f5f9; color: #334155; }
			.cptt-inv-btn.danger { background: #e11d48; color: #fff; }
			.cptt-inv-btn.warning { background: #f59e0b; }
			.cptt-inv-table { width: 100%; border-collapse: collapse; font-size: .78rem; margin-top: 8px; }
			.cptt-inv-table th, .cptt-inv-table td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; text-align: right; }
			.cptt-inv-table th { background: #f8fafc; font-weight: 700; color: #475569; }
			.cptt-inv-pill { display:inline-block; padding: 2px 8px; border-radius: 999px; font-size: .65rem; font-weight: 700; }
			.cptt-inv-pill.draft { background: #f1f5f9; color: #64748b; }
			.cptt-inv-pill.sent  { background: #fef3c7; color: #92400e; }
			.cptt-inv-pill.paid  { background: #d1fae5; color: #065f46; }
			.cptt-inv-pill.cancelled { background: #fee2e2; color: #991b1b; }
			.cptt-inv-msg { padding: 10px; border-radius: 8px; font-size: .8rem; margin: 8px 0; }
			.cptt-inv-msg.ok { background: #d1fae5; color: #065f46; }
			.cptt-inv-msg.err { background: #fee2e2; color: #991b1b; }
			.cptt-line-row { display: grid; grid-template-columns: 3fr 1fr 1fr 1fr auto; gap: 6px; align-items: end; margin-bottom: 6px; }
		</style>
		<div class="cptt-inv-wrap">
			<h1>🧾 فاکتورها و پیش‌فاکتورها</h1>
			<div id="cptt-inv-msg-host"></div>

			<div class="cptt-inv-card">
				<h2>📋 لیست فاکتورها</h2>
				<table class="cptt-inv-table">
					<thead><tr><th>شماره</th><th>نوع</th><th>طرف</th><th>تاریخ</th><th>مبلغ</th><th>وضعیت</th><th>عملیات</th></tr></thead>
					<tbody>
					<?php if (empty($rows)): ?>
						<tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:20px;">فاکتوری ثبت نشده</td></tr>
					<?php else: foreach ($rows as $r): ?>
						<tr>
							<td><strong><?php echo esc_html($r->number); ?></strong></td>
							<td><?php echo esc_html(['invoice'=>'فاکتور','quote'=>'پیش‌فاکتور','proforma'=>'پروفرما'][$r->type] ?? $r->type); ?></td>
							<td><?php echo esc_html($r->customer_name); ?></td>
							<td><?php echo esc_html($r->issue_date); ?></td>
							<td class="num"><?php echo number_format((float)$r->total); ?></td>
							<td><span class="cptt-inv-pill <?php echo esc_attr($r->status); ?>"><?php echo esc_html(['draft'=>'پیش‌نویس','sent'=>'ارسال‌شده','paid'=>'تسویه‌شده','cancelled'=>'باطل','partial'=>'جزئی'][$r->status] ?? $r->status); ?></span></td>
							<td>
								<a href="<?php echo esc_url(admin_url('admin-ajax.php?action=cpttf_erp_print_invoice&nonce=' . $nonce . '&id=' . urlencode($r->id))); ?>" target="_blank" class="cptt-inv-btn ghost" style="text-decoration:none;">🖨 چاپ</a>
								<?php if ($r->status !== 'paid'): ?>
									<button class="cptt-inv-btn success inv-status" data-id="<?php echo esc_attr($r->id); ?>" data-status="paid">💰 تسویه</button>
									<button class="cptt-inv-btn warning inv-tov" data-id="<?php echo esc_attr($r->id); ?>">📥 صدور سند</button>
								<?php endif; ?>
								<button class="cptt-inv-btn danger inv-del" data-id="<?php echo esc_attr($r->id); ?>">حذف</button>
							</td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>

			<div class="cptt-inv-card">
				<h2>➕ صدور فاکتور جدید</h2>
				<div class="cptt-inv-grid">
					<div class="cptt-inv-field"><label>شماره فاکتور</label><input type="text" id="iv-num" placeholder="INV-1404-001"></div>
					<div class="cptt-inv-field"><label>نوع</label><select id="iv-type">
						<option value="invoice">فاکتور</option>
						<option value="quote">پیش‌فاکتور</option>
						<option value="proforma">پروفرما</option>
					</select></div>
					<div class="cptt-inv-field"><label>نام مشتری</label><input type="text" id="iv-cname"></div>
					<div class="cptt-inv-field"><label>نام پروژه</label><input type="text" id="iv-pname"></div>
					<div class="cptt-inv-field"><label>تاریخ صدور</label><input type="text" id="iv-issue" value="<?php echo esc_attr($this->fa_today()); ?>"></div>
					<div class="cptt-inv-field"><label>سررسید</label><input type="text" id="iv-due"></div>
					<div class="cptt-inv-field"><label>تخفیف</label><input type="number" id="iv-disc" value="0"></div>
					<div class="cptt-inv-field"><label>نرخ مالیات (٪)</label><input type="number" id="iv-tax" value="0" step="0.01"></div>
				</div>
				<h3 style="font-size:.9rem;margin-top:14px;">اقلام</h3>
				<div id="iv-lines"></div>
				<button class="cptt-inv-btn ghost" id="iv-add-line" style="margin-top:6px;">➕ افزودن قلم</button>
				<div class="cptt-inv-field" style="margin-top:10px;"><label>توضیحات</label><textarea id="iv-notes" rows="2"></textarea></div>
				<button class="cptt-inv-btn success" id="iv-save" style="margin-top:10px;">💾 ذخیره فاکتور</button>
			</div>
		</div>
		<script>
		(function(){
			var nonce='<?php echo esc_js($nonce); ?>'; var ajax='<?php echo esc_js($ajax); ?>';
			function call(action, data, cb){
				var fd = new FormData(); fd.append('action', action); fd.append('nonce', nonce);
				Object.keys(data||{}).forEach(function(k){ var v=data[k]; if(typeof v==='object') v=JSON.stringify(v); fd.append(k, v); });
				fetch(ajax, {method:'POST', body: fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(cb).catch(function(e){cb({success:false, data:{message:String(e)}});});
			}
			function msg(t, ok){ var h=document.getElementById('cptt-inv-msg-host'); h.innerHTML='<div class="cptt-inv-msg '+(ok?'ok':'err')+'">'+t+'</div>'; setTimeout(function(){h.innerHTML='';}, 4000); }
			function addLine(){
				var div = document.createElement('div');
				div.className = 'cptt-line-row';
				div.innerHTML = '<input class="iv-l-title" placeholder="شرح خدمت/کالا"><input type="number" class="iv-l-qty" value="1" min="0" step="any"><input type="number" class="iv-l-price" placeholder="قیمت واحد"><input type="text" class="iv-l-desc" placeholder="توضیح اختیاری"><button type="button" class="cptt-inv-btn danger" style="padding:6px 10px;" onclick="this.parentElement.remove()">×</button>';
				document.getElementById('iv-lines').appendChild(div);
			}
			document.getElementById('iv-add-line').onclick = addLine;
			addLine();
			document.getElementById('iv-save').onclick = function(){
				var lines = [];
				document.querySelectorAll('#iv-lines .cptt-line-row').forEach(function(r){
					lines.push({
						title: r.querySelector('.iv-l-title').value,
						quantity: r.querySelector('.iv-l-qty').value || 1,
						unitPrice: r.querySelector('.iv-l-price').value || 0,
						description: r.querySelector('.iv-l-desc').value
					});
				});
				var inv = {
					number: document.getElementById('iv-num').value,
					type: document.getElementById('iv-type').value,
					customerName: document.getElementById('iv-cname').value,
					projectName: document.getElementById('iv-pname').value,
					issueDate: document.getElementById('iv-issue').value,
					dueDate: document.getElementById('iv-due').value,
					discount: document.getElementById('iv-disc').value,
					taxRate: document.getElementById('iv-tax').value,
					notes: document.getElementById('iv-notes').value,
					currency: 'TOMAN',
					status: 'draft',
					lines: lines
				};
				if (!inv.number) { msg('❌ شماره فاکتور الزامی', false); return; }
				if (lines.length === 0 || !lines[0].title) { msg('❌ حداقل یک قلم وارد کنید', false); return; }
				call('cpttf_erp_invoice_save', {invoice: inv}, function(r){
					if (r.success) { msg('✅ فاکتور ذخیره شد. مبلغ نهایی: '+new Intl.NumberFormat('fa-IR').format(r.data.total), true); setTimeout(function(){location.reload();}, 1500); }
					else msg('❌ '+(r.data&&r.data.message?r.data.message:'خطا'), false);
				});
			};
			document.querySelectorAll('.inv-status').forEach(function(b){
				b.onclick = function(){
					call('cpttf_erp_invoice_status', {id: b.getAttribute('data-id'), status: b.getAttribute('data-status')}, function(r){
						if (r.success) location.reload();
						else msg('❌ '+(r.data&&r.data.message?r.data.message:'خطا'), false);
					});
				};
			});
			document.querySelectorAll('.inv-tov').forEach(function(b){
				b.onclick = function(){
					if (!confirm('سند RV برای این فاکتور صادر شود؟')) return;
					call('cpttf_erp_invoice_to_voucher', {id: b.getAttribute('data-id')}, function(r){
						if (r.success) { msg('✅ سند ایجاد شد: '+r.data.voucher_id, true); setTimeout(function(){location.reload();}, 1500); }
						else msg('❌ '+(r.data&&r.data.message?r.data.message:'خطا'), false);
					});
				};
			});
			document.querySelectorAll('.inv-del').forEach(function(b){
				b.onclick = function(){
					if (!confirm('حذف فاکتور؟')) return;
					call('cpttf_erp_invoice_delete', {id: b.getAttribute('data-id')}, function(r){ if (r.success) location.reload(); });
				};
			});
		})();
		</script>
		<?php
	}
}
