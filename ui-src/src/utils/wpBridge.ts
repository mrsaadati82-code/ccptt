declare global {
  interface Window {
    CPTTF_ERP?: {
      env: 'wp' | 'standalone'; version: string; ajax: string; nonce: string;
      user: { id: number; name: string; role: string };
      company: { name: string }; currency: string;
      counts: Record<string, number>; urls: Record<string, string>;
      assets?: { fontsBase: string; fontCss: string };
    };
  }
}

export const isWP = (): boolean =>
  typeof window !== 'undefined' && !!window.CPTTF_ERP && window.CPTTF_ERP.env === 'wp';
export const wpUser = () => window.CPTTF_ERP?.user;
export const wpCompany = () => window.CPTTF_ERP?.company;

export async function wpCall<T = any>(action: string, data: Record<string, any> = {}): Promise<T> {
  if (!window.CPTTF_ERP) throw new Error('CPTTF_ERP not bootstrapped');
  const fd = new FormData();
  fd.append('action', action);
  fd.append('nonce', window.CPTTF_ERP.nonce);
  for (const k of Object.keys(data)) {
    const v = data[k];
    if (v === undefined || v === null) continue;
    if (v instanceof File || v instanceof Blob) fd.append(k, v);
    else if (typeof v === 'object') fd.append(k, JSON.stringify(v));
    else fd.append(k, String(v));
  }
  const res = await fetch(window.CPTTF_ERP.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
  const json = await res.json();
  if (!json || json.success !== true) {
    const msg = (json && (json.data?.message || json.data)) || `AJAX ${action} failed`;
    throw new Error(typeof msg === 'string' ? msg : JSON.stringify(msg));
  }
  return json.data as T;
}

export interface WPBootstrap {
  companies: any[]; branches: any[]; currencies: any[]; fiscalYears: any[];
  costCenters: any[]; accounts: any[]; vouchers: any[]; experts: any[];
  projectSteps: any[]; settlementHistory: any[]; incomesExpenses: any[];
  treasuryAccounts: any[]; financialLocks: any[];
  categories: { income: string[]; expense: string[] };
  financeCategories: any[]; receivables: any[]; projectsFull: any[];
  kpis: any; monthlySeries: any[]; topCustomers: any[];
  categoryBreakdownExpense: any[]; categoryBreakdownIncome: any[];
  user: { id: number; name: string; role: string };
  todayJalali?: string; partyAccounts?: any[]; rolePermissions?: any;
  cheques?: any[]; installmentPlans?: any[]; payables?: any[];
  notifications?: any[]; financialHealth?: any;
  accountMapping?: any; treasuryCoaMap?: any; mappingPurposes?: any;
}

export async function wpBootstrapAll(): Promise<WPBootstrap> {
  return await wpCall<WPBootstrap>('cpttf_erp_full_bootstrap');
}

export const wpApi: any = {
  // Voucher CRUD
  saveVoucher:      (v: any)               => wpCall('cpttf_erp_voucher_save', { voucher: v }),
  approveVoucher:   (id: string, role: string) => wpCall('cpttf_erp_voucher_approve', { id, role }),
  deleteVoucher:    (id: string)           => wpCall('cpttf_erp_voucher_delete', { id }),
  reverseVoucher:   (id: string, note: string = '') => wpCall('cpttf_erp_voucher_reverse', { id, note }),
  unfinalizeVoucher:(id: string, note: string = '') => wpCall('cpttf_erp_voucher_unfinalize', { id, note }),
  vouchersList:     (params: any)          => wpCall('cpttf_erp_vouchers_list', params),
  voucherBatch:     (vouchers: any[])      => wpCall('cpttf_erp_voucher_batch', { vouchers }),

  // CoA
  saveCoaNode:      (node: any)            => wpCall('cpttf_erp_coa_save', { node }),
  deleteCoaNode:    (id: string)           => wpCall('cpttf_erp_coa_delete', { id }),
  coaBalances:      ()                     => wpCall('cpttf_erp_coa_balances'),
  importStandardCoa:(mode: 'merge'|'replace'='merge') => wpCall('cpttf_erp_coa_import_standard', { mode }),

  // Account Mapping (Phase 2)
  getAccountMapping:  ()                   => wpCall('cpttf_erp_mapping_get'),
  saveAccountMapping: (mapping: any, treasuryMap?: any) =>
    wpCall('cpttf_erp_mapping_save', { mapping, treasury_map: treasuryMap || {} }),

  // Treasury
  saveTreasury:     (acc: any)             => wpCall('cpttf_erp_treasury_save', { account: acc }),
  deleteTreasury:   (id: string)           => wpCall('cpttf_erp_treasury_delete', { id }),
  transfer:         (fromId: string, toId: string, amount: number, description: string) =>
    wpCall('cpttf_erp_treasury_transfer', { from_id: fromId, to_id: toId, amount, description }),
  deposit:          (accountId: string, amount: number, description: string) =>
    wpCall('cpttf_erp_treasury_deposit', { account_id: accountId, amount, description }),
  withdraw:         (accountId: string, amount: number, description: string) =>
    wpCall('cpttf_erp_treasury_withdraw', { account_id: accountId, amount, description }),

  // Settlements
  settleSteps:      (expertId: string, stepIds: string[], paymentMethodId: string, description: string) =>
    wpCall('cpttf_erp_settle_steps', { expert_id: expertId, step_ids: stepIds, payment_method_id: paymentMethodId, description }),
  payExpertManual:  (expertId: string, amount: number, paymentMethodId: string, description: string) =>
    wpCall('cpttf_erp_settle_manual', { expert_id: expertId, amount, payment_method_id: paymentMethodId, description }),

  // Income/Expense
  saveIncomeExpense:  (item: any)          => wpCall('cpttf_erp_ie_save', { item }),
  deleteIncomeExpense:(id: string)         => wpCall('cpttf_erp_ie_delete', { id }),

  // Cost Centers
  saveCostCenter:   (cc: any)              => wpCall('cpttf_erp_cc_save', { center: cc }),
  deleteCostCenter: (id: string)           => wpCall('cpttf_erp_cc_delete', { id }),

  // Finance Categories
  saveFinanceCategory:   (c: any)          => wpCall('cpttf_erp_fincat_save', { category: c }),
  deleteFinanceCategory: (id: string)      => wpCall('cpttf_erp_fincat_delete', { id }),

  // Fiscal Year (Phase 5)
  createFiscalYear: (name: string, start: string, end: string) =>
    wpCall('cpttf_erp_fy_create', { name, start_date: start, end_date: end }),
  closeFiscalYear:  (id: string)           => wpCall('cpttf_erp_fy_close', { id }),
  fyClosePreview:   (id: string)           => wpCall('cpttf_erp_fy_close_preview', { id }),
  fyCloseExecute:   (id: string, nextFyId: string = '') =>
    wpCall('cpttf_erp_fy_close_execute', { id, next_fy_id: nextFyId }),
  fyReopen:         (id: string)           => wpCall('cpttf_erp_fy_reopen', { id }),
  lockClear:        (fyId: string)         => wpCall('cpttf_erp_lock_clear', { fiscal_year_id: fyId }),
  toggleLock:       (startDate: string, endDate: string) =>
    wpCall('cpttf_erp_lock_toggle', { start_date: startDate, end_date: endDate }),
  periodCheck:      (date: string)         => wpCall('cpttf_erp_period_check', { date }),

  // Project Accounting
  updateProjectStepFinance: (projectId: number, stepId: string, fields: any) =>
    wpCall('cpttf_erp_project_step_update', { project_id: projectId, step_id: stepId, fields }),
  projectQuickPay:  (projectId: number, amount: number, paymentMethodId: string, note: string) =>
    wpCall('cpttf_erp_project_quick_pay', { project_id: projectId, amount, payment_method_id: paymentMethodId, note }),
  projectSettle:    (projectId: number, settled: boolean) =>
    wpCall('cpttf_erp_project_settle', { project_id: projectId, settled: settled ? 1 : 0 }),

  // Reports (Phase 6)
  partyStatement: (partyType: string, partyId: number, from: string, to: string, projectId: string) =>
    wpCall('cpttf_erp_party_statement', { party_type: partyType, party_id: partyId, date_from: from, date_to: to, project_id: projectId }),
  cashFlow:         (from: string, to: string) => wpCall('cpttf_erp_cash_flow', { from, to }),
  periodCompare:    (aFrom: string, aTo: string, bFrom: string, bTo: string) =>
    wpCall('cpttf_erp_period_compare', { a_from: aFrom, a_to: aTo, b_from: bFrom, b_to: bTo }),
  budgetActual:     (from: string, to: string) => wpCall('cpttf_erp_budget_actual', { from, to }),
  agingCustom:      (baseDate: string, buckets: string) =>
    wpCall('cpttf_erp_aging_custom', { base_date: baseDate, buckets }),
  agingCheques:     (baseDate: string)     => wpCall('cpttf_erp_aging_cheques', { base_date: baseDate }),
  wipReport:        ()                     => wpCall('cpttf_erp_wip_report'),
  bankRecon:        (accountId: string, from: string, to: string) =>
    wpCall('cpttf_erp_bank_recon', { account_id: accountId, from, to }),
  bankReconSave:    (accountId: string, ids: string) =>
    wpCall('cpttf_erp_bank_recon_save', { account_id: accountId, ids }),
  kpiDrilldown:     (metric: string, from: string='', to: string='') =>
    wpCall('cpttf_erp_kpi_drilldown', { metric, from, to }),
  auditList:        (params: any)          => wpCall('cpttf_erp_audit_list', params),

  // Permissions
  savePermissions:  (matrix: any)          => wpCall('cpttf_erp_permissions_save', { matrix }),

  // Export
  exportReport:     (kind: string, params: any) => wpCall('cpttf_erp_report_export', { kind, params }),

  // Cheques
  cheques:          ()                     => wpCall('cpttf_erp_cheques_list'),
  saveCheque:       (c: any)               => wpCall('cpttf_erp_cheque_save', { cheque: c }),
  deleteCheque:     (id: string)           => wpCall('cpttf_erp_cheque_delete', { id }),
  changeChequeStatus: (id: string, status: string, accountId?: string, note?: string) =>
    wpCall('cpttf_erp_cheque_status', { id, status, account_id: accountId || '', note: note || '' }),

  // Installments
  installments:     ()                     => wpCall('cpttf_erp_installments_list'),
  createInstallmentPlan: (plan: any)       => wpCall('cpttf_erp_installment_plan_create', { plan }),
  payInstallment:   (planId: string, installmentNo: number, amount: number, accountId: string, note: string) =>
    wpCall('cpttf_erp_installment_pay', { plan_id: planId, installment_no: installmentNo, amount, account_id: accountId, note }),
  deleteInstallmentPlan: (planId: string)  => wpCall('cpttf_erp_installment_delete', { plan_id: planId }),

  // Payables
  payables:         ()                     => wpCall('cpttf_erp_payables_list'),
  savePayable:      (p: any)               => wpCall('cpttf_erp_payable_save', { payable: p }),
  payPayable:       (id: string, amount: number, accountId: string, note: string) =>
    wpCall('cpttf_erp_payable_pay', { id, amount, account_id: accountId, note }),
  deletePayable:    (id: string)           => wpCall('cpttf_erp_payable_delete', { id }),

  // Notifications
  notifications:    ()                     => wpCall('cpttf_erp_notifications_list'),
  markNotifRead:    (id: string)           => wpCall('cpttf_erp_notif_read', { id }),
  markAllNotifRead: ()                     => wpCall('cpttf_erp_notif_read_all'),
  deleteNotif:      (id: string)           => wpCall('cpttf_erp_notif_delete', { id }),

  // Attachments
  uploadAttachment: (file: File, entityType: string, entityId: string) =>
    wpCall('cpttf_erp_attachment_upload', { file, entity_type: entityType, entity_id: entityId }),
  listAttachments:  (entityType: string, entityId: string) =>
    wpCall('cpttf_erp_attachments_list', { entity_type: entityType, entity_id: entityId }),
  deleteAttachment: (id: string)           => wpCall('cpttf_erp_attachment_delete', { id }),

  // Recurring (Phase 7)
  recurringList:    ()                     => wpCall('cpttf_erp_recurring_list'),
  recurringSave:    (item: any)            => wpCall('cpttf_erp_recurring_save', { item }),
  recurringDelete:  (id: string)           => wpCall('cpttf_erp_recurring_delete', { id }),
  recurringRunNow:  (id: string)           => wpCall('cpttf_erp_recurring_run_now', { id }),

  // Chequebooks (Phase 7)
  chequebookList:   ()                     => wpCall('cpttf_erp_chequebook_list'),
  chequebookSave:   (item: any)            => wpCall('cpttf_erp_chequebook_save', { item }),
  chequebookDelete: (id: string)           => wpCall('cpttf_erp_chequebook_delete', { id }),
  chequebookNext:   (id: string)           => wpCall('cpttf_erp_chequebook_next', { id }),

  // Cron (Phase 7)
  cronRunNow:       (which: 'daily'|'hourly') => wpCall('cpttf_erp_cron_run_now', { which }),

  // Multi-currency (Phase 8)
  ratesList:        ()                     => wpCall('cpttf_erp_rates_list'),
  ratesSave:        (currency: string, date: string, rate: number) =>
    wpCall('cpttf_erp_rates_save', { currency, date, rate }),
  ratesDelete:      (currency: string, date: string) =>
    wpCall('cpttf_erp_rates_delete', { currency, date }),
  currencyConvert:  (from: string, to: string, amount: number, date: string='') =>
    wpCall('cpttf_erp_currency_convert', { from, to, amount, date }),

  // Invoices (Phase 8)
  invoiceList:      (type: string='', status: string='') =>
    wpCall('cpttf_erp_invoice_list', { type, status }),
  invoiceSave:      (invoice: any)         => wpCall('cpttf_erp_invoice_save', { invoice }),
  invoiceDelete:    (id: string)           => wpCall('cpttf_erp_invoice_delete', { id }),
  invoiceStatus:    (id: string, status: string) =>
    wpCall('cpttf_erp_invoice_status', { id, status }),
  invoiceToVoucher: (id: string)           => wpCall('cpttf_erp_invoice_to_voucher', { id }),

  // Webhooks (Phase 8)
  webhookList:      ()                     => wpCall('cpttf_erp_webhook_list'),
  webhookSave:      (item: any)            => wpCall('cpttf_erp_webhook_save', { item }),
  webhookDelete:    (id: string)           => wpCall('cpttf_erp_webhook_delete', { id }),
  webhookTest:      (id: string)           => wpCall('cpttf_erp_webhook_test', { id }),

  // Import (Phase 8)
  importPreview:    (type: string, csv: string) =>
    wpCall('cpttf_erp_import_preview', { type, csv }),
  importCommit:     (type: string, csv: string) =>
    wpCall('cpttf_erp_import_commit', { type, csv }),

  // Print URL builders (returns admin-ajax URL strings, opened in new window)
  printUrl: (action: string, params: Record<string, string|number>): string => {
    const base = window.CPTTF_ERP?.ajax || '';
    const nonce = window.CPTTF_ERP?.nonce || '';
    const qs = new URLSearchParams({ action, nonce, ...Object.fromEntries(Object.entries(params).map(([k,v]) => [k, String(v)])) }).toString();
    return base + '?' + qs;
  },

  reload: () => wpBootstrapAll(),
};

export default wpApi;
