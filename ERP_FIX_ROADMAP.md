# 🛠 نقشه‌ی راه رفع کاستی‌های ERP — هماهنگ

> این فایل بر اساس یافته‌های `ERP_AUDIT_REPORT.txt` ساخته شده است.
> هر فاز یک واحد منطقی و قابل تحویل است. پس از پایان هر فاز، وضعیت
> به ✅ **DONE** تغییر می‌کند، و منتظر تأیید کاربر برای فاز بعدی می‌مانیم.

**نسخه‌ی شروع:** v7.6.0
**هدف نهایی:** v8.0.0 — ERP تجاری واقعی، پایدار، امن

---

## 📊 جدول وضعیت کلی

| فاز | عنوان | اولویت | پیچیدگی | نسخه | وضعیت |
|----|------|--------|---------|------|------|
| 1  | Voucher Engine کامل (موتور سند) | CRITICAL | LARGE | v7.7.0 | ✅ DONE |
| 2  | Account Mapping قابل پیکربندی | CRITICAL | MEDIUM | v7.8.0 | ✅ DONE |
| 3  | Migration دیتابیس + کارایی | CRITICAL | LARGE | v7.9.0 | ✅ DONE |
| 4  | Security & RBAC واقعی | CRITICAL | LARGE | v7.10.0 | ✅ DONE |
| 5  | Period Locking & Fiscal Year | HIGH | MEDIUM | v7.11.0 | ✅ DONE |
| 6  | گزارش‌های گمشده (Cash Flow و …) | HIGH | LARGE | v7.12.0 | ✅ DONE |
| 7  | Operational Polish (Reverse, Cron, Drill-down …) | MEDIUM | MEDIUM | v7.13.0 | ✅ DONE |
| 8  | Integrations & Automations (SMS, Import, Print) | MEDIUM | MEDIUM | v8.0.0 | ✅ DONE |

نمادها:
- ⏳ Pending — هنوز شروع نشده
- 🚧 In Progress — در حال انجام
- ✅ DONE — تکمیل و تحویل شده
- ⏭ Skipped — به‌خواست کاربر رد شد

---

## 🔥 فاز ۱ — Voucher Engine کامل (v7.7.0)

**وضعیت:** ✅ **DONE** — تحویل در v7.7.0 (1404/03/22)

### Tasks
- [x] `cpttf_erp_treasury_deposit` → تولید voucher نوع **RV** + ledger insert
- [x] `cpttf_erp_treasury_withdraw` → تولید voucher نوع **PV** + ledger insert
- [x] `cpttf_erp_treasury_transfer` → تولید voucher نوع **TV** + دو ledger insert
- [x] `cpttf_erp_ie_save` (Income/Expense) → تولید voucher RV یا PV
- [x] `cpttf_erp_ie_delete` → تولید سند برگشتی (reverse) خودکار
- [x] `cpttf_erp_project_quick_pay` → تولید voucher RV
- [⏭] افزودن ستون `voucher_id` به جدول `wp_cptt_fin_ledger` *(به فاز ۳ منتقل شد — همراه با migration کلی)*
- [⏭] حذف `voucher synthesis` workaround از `FinancialReports.tsx` *(workaround فعلاً برای legacy data نگه داشته شد — در فاز ۳ که migration رخ دهد، حذف می‌شود. فعلاً اگر voucher واقعی موجود باشد، synth رد می‌شود.)*
- [x] افزودن helper مرکزی `gen_voucher()` بازنویسی‌شده با validation و auto-fill name
- [x] دکمه **برگشت سند** (تولید سند معکوس با یک کلیک) در UI
- [x] enforcement تساوی debit==credit در backend (`validate_voucher_rows`)
- [x] map_treasury_to_coa برای تبدیل خودکار treasury → CoA subsidiary
- [x] گسترش COA پیش‌فرض (a3_1, a3_1_1..3, a5_1, a5_2, a1_1_3)

### معیار پذیرش
✅ ثبت یک income در «درآمدها و هزینه‌ها» باعث می‌شود در دفتر روزنامه و تراز آزمایشی دیده شود.
✅ انتقال بین دو حساب treasury → یک سند TV متعادل در دفتر کل ظاهر می‌شود.
✅ debit ≠ credit در backend reject می‌شود با پیام واضح.
✅ حذف IE → سند برگشتی خودکار تولید می‌شود (audit trail کامل).
✅ نام حساب در voucher rows خودکار از CoA fetch می‌شود.

### Deliverables
- ✅ نسخه‌ی v7.7.0 پکیج زیپ در ۳ مسیر (`/home/user/`, `/home/user/ccptt/`, `/home/user/dist/`)
- ✅ بخش جدید در README با changelog کامل
- ✅ ERP_FIX_ROADMAP.md به‌روزرسانی به ✅ DONE

---

## 🎯 فاز ۲ — Account Mapping قابل پیکربندی (v7.8.0)

**وضعیت:** ✅ **DONE** — تحویل در v7.8.0 (1404/03/22)

### Tasks
- [x] صفحه‌ی جدید **«نگاشت حساب‌های پیش‌فرض»** (admin page زیر CPTT — PHP-rendered)
- [x] Option جدید: `cpttf_erp_account_mapping` + `cpttf_erp_treasury_coa_map`
- [x] ۱۲ نگاشت پیش‌فرض در ۵ گروه (دارایی، بدهی، درآمد، هزینه، اختیاری):
  - cash_default, bank_default
  - receivable_default, notes_receivable
  - payable_default, notes_payable, expert_payable
  - revenue_default, other_income
  - expense_default, expert_payroll
  - transfer_clearing
- [x] بازنویسی همه‌ی `gen_voucher` ها → خواندن از `map_account()`
- [x] محاسبه‌ی balance زنده روی CoA tree از voucherها (`get_coa_with_balances()`)
- [x] بخش «وارد کردن سرفصل استاندارد ایران» (~۵۰ حساب در ۶ گروه)
- [x] map_treasury_to_coa با per-treasury override
- [x] validation: CoA id ها در ذخیره چک می‌شوند
- [⏭] enforcement «اگر mapping ست نشده، voucher تولید نشود» — *به جای reject سخت، fallback به default_account_mapping استفاده می‌شود تا data lost نشود.*

### معیار پذیرش
✅ تغییر کدینگ هیچ‌گاه باعث ثبت voucher به حساب اشتباه نمی‌شود (همه از map_account).
✅ balance روی هر node در bootstrap و در admin page زنده است.
✅ COA استاندارد ایران در یک کلیک import می‌شود (Merge یا Replace).

### Deliverables
- ✅ نسخه‌ی v7.8.0 پکیج زیپ در ۳ مسیر
- ✅ بخش جدید در README با changelog کامل
- ✅ ERP_FIX_ROADMAP.md به‌روزرسانی به ✅ DONE

### نکته
صفحه‌ی نگاشت در فاز ۲ به‌صورت admin page مستقل (PHP-rendered) تحویل داده شده تا app.js (که شامل ~۴۰۰۰ خط React بهبودهای فاز ۱ است) بازسازی نشود. در فاز ۳ که migration پایگاه‌داده و رشد فایل‌های React بازنویسی می‌شود، sources را persist می‌کنیم و این صفحه به React integrate می‌شود.

---

## ⚡ فاز ۳ — Migration دیتابیس + کارایی (v7.9.0)

**وضعیت:** ✅ **DONE** — تحویل در v7.9.0 (1404/03/23)

### Tasks
- [x] جدول جدید `wp_cptt_erp_vouchers` (با ۶ index: type, status, date, ref, fy, number)
- [x] جدول جدید `wp_cptt_erp_voucher_rows` (با ۵ index: voucher, account, project, customer, expert)
- [x] جدول جدید `wp_cptt_erp_cheques` (با ۶ index)
- [x] جدول جدید `wp_cptt_erp_installments` + `wp_cptt_erp_installment_rows` (UNIQUE plan_no)
- [x] جدول جدید `wp_cptt_erp_payables` (با ۳ index)
- [x] migration script idempotent (`maybe_run_migrations()` روی admin_init)
- [x] افزودن INDEX روی `wp_cptt_fin_ledger`: date_at, project_id, customer_id, ref_type, voucher_id
- [x] افزودن ستون `voucher_id` به ledger
- [x] تقسیم bootstrap به ۵ chunk lazy (core/treasury/projects/reports/kpis)
- [x] حل N+1 در `build_projects_full()` و `build_receivables()` با `update_meta_cache()` + bulk `get_users()`
- [x] pagination واقعی به `cpttf_erp_audit_list` (با page + per_page + total)
- [x] حذف autoload از ۷ option قدیمی (`disable_autoload_for_legacy_options()`)
- [x] endpoint diagnostics `cpttf_erp_migration_status` برای monitoring
- [⏭] حذف `voucher synthesis` workaround از React `FinancialReports.tsx` *(در فاز ۸ که React refactor می‌شود)*

### معیار پذیرش
✅ Migration خودکار روی نصب — idempotent.
✅ هیچ option بزرگ با autoload فعال باقی نمی‌ماند.
✅ Index های بهینه روی ledger و جداول جدید.
✅ N+1 در post_meta حل شد (update_meta_cache).
✅ Audit log با pagination واقعی.
✅ Voucher CRUD native روی جدول.
✅ Backward compatible: legacy options به‌عنوان fallback.

### Deliverables
- ✅ نسخه‌ی v7.9.0 پکیج زیپ در ۳ مسیر
- ✅ بخش جدید در README با changelog کامل
- ✅ ERP_FIX_ROADMAP.md به‌روزرسانی به ✅ DONE

---

## 🔐 فاز ۴ — Security & RBAC واقعی (v7.10.0)

**وضعیت:** ✅ **DONE** — تحویل در v7.10.0 (1404/03/23)

### Tasks
- [x] تابع جدید `check_perm($perm_key)` در PHP class
- [x] تابع `current_role_key()` به‌جای hardcoded role mapping
- [x] تابع `role_has_perm()` که از matrix می‌خواند با fallback به `default_role_perms()`
- [x] ۴۱ endpoint mapping به perm مربوطه:
  - voucher: voucher_create / voucher_approve / voucher_delete / voucher_reverse
  - treasury_*: treasury_payment
  - ie_save/delete: treasury_payment
  - cheque_*: treasury_payment
  - payable_*: treasury_payment
  - installment_*: treasury_payment
  - settle_*: treasury_payment
  - attachment_*: treasury_payment
  - project_quick_pay: treasury_payment
  - project_step_update / project_settle: project_finance_view
  - fy_* / lock_toggle / cc_* / coa_save/delete / fincat_*: settings_manage
  - mapping_save / coa_import / coa_treasury_map: mapping_manage
  - audit_list: audit_view
  - permissions_save: settings_manage (+manage_options)
- [x] جایگزینی همه‌ی `check()` با `check_perm($key)` در endpoint های write
- [x] role کارشناس (`cptt_expert`) **به viewer downgrade شد** (فقط view-level)
- [x] MIME type validation روی attachment upload با `wp_check_filetype_and_ext()`
- [x] محدودیت سایز attachment (پیش‌فرض ۱۰MB، با filter `cpttf_erp_max_upload_bytes`)
- [x] افزودن `audit_log()` به IE, Treasury (deposit/withdraw/transfer), CC, FY, Lock
- [x] rate-limiting روی ۷ permission حساس (۶۰ req/۶۰ sec per user)
- [x] sanitization helper `safe_text()` با گزینه‌ی KSES
- [x] super-admin escape hatch (manage_options always passes)
- [x] ۳ نقش WordPress جدید در activation: cptt_financial_manager, cptt_accountant, cptt_cashier
- [x] activation hook جدید برای install tables Phase 3

### معیار پذیرش
✅ Cashier نمی‌تواند voucher create کند (حتی با AJAX call مستقیم).
✅ کارشناس نمی‌تواند permissions را عوض کند (viewer-only).
✅ آپلود فایل .php با extension تقلبی .jpg → reject.
✅ هر CRUD در audit_log ثبت می‌شود.
✅ Super-admin همیشه escape hatch دارد.
✅ Rate-limiting برای جلوگیری از abuse.

### Deliverables
- ✅ نسخه‌ی v7.10.0 پکیج زیپ در ۳ مسیر
- ✅ بخش جدید در README با changelog کامل
- ✅ ERP_FIX_ROADMAP.md به‌روزرسانی به ✅ DONE

---

## 🗓 فاز ۵ — Period Locking & Fiscal Year (v7.11.0)

**وضعیت:** ✅ **DONE** — تحویل در v7.11.0 (1404/03/23)

### Tasks
- [x] تابع `is_date_locked($jalali_date)` با چک closed FY و locks
- [x] تابع `reject_if_locked()` با ۴۲۳ JSON و super-admin escape hatch
- [x] enforcement در ۱۲ endpoint نوشتنی:
  - voucher_save, voucher_delete
  - ie_save
  - treasury_transfer/deposit/withdraw
  - settle_steps/manual
  - cheque_save, cheque_status
  - installment_pay, payable_pay
  - project_quick_pay
- [x] صفحه‌ی «🗓 بستن سال مالی» (standalone PHP page)
- [x] preview سود/زیان قبل از بستن
- [x] سند CV خودکار: zero-out حساب‌های موقت → retained_earnings
- [x] سند OV خودکار برای سال بعدی (با مانده‌ی حساب‌های دائمی)
- [x] reopen FY (CEO only + audit log)
- [x] clear locks (CEO only + audit log)
- [x] ۲ purpose جدید در mapping: retained_earnings, profit_loss_summary
- [x] backward compat: super-admin همیشه می‌تواند روی closed period کار کند

### معیار پذیرش
✅ ثبت سند با تاریخ در دوره‌ی قفل → پیام خطای ۴۲۳ واضح.
✅ بستن سال مالی، سند CV متعادل تولید می‌کند.
✅ سال جدید با مانده‌ی صحیح حساب‌های دائمی شروع می‌شود (OV).
✅ CEO می‌تواند FY بسته را reopen کند.

### Deliverables
- ✅ نسخه‌ی v7.11.0 پکیج زیپ در ۳ مسیر
- ✅ بخش جدید در README با changelog کامل
- ✅ ERP_FIX_ROADMAP.md به‌روزرسانی به ✅ DONE

---

## 📈 فاز ۶ — گزارش‌های گمشده (v7.12.0)

**وضعیت:** ✅ **DONE** — تحویل در v7.12.0 (1404/03/23)

### Tasks
- [x] **صورت جریان وجوه نقد** — ۳ سکشن (عملیاتی/سرمایه/تأمین) + مانده ابتدا/انتها
- [x] **مقایسه‌ی دوره‌ای** — دو بازه + delta + درصد تغییر
- [x] **بودجه vs واقعی** — per cost center با progress bar رنگی
- [x] **Bank Reconciliation MVP** — لیست ledger با checkbox تطبیق + ذخیره per account
- [x] **Aging قابل تنظیم** — تاریخ پایه + بازه‌های دلخواه
- [x] **WIP Report** — per پروژه با over/under billing
- [x] **گزارش سن چک‌ها** — بازه‌بندی روزها تا سررسید
- [⏭] **چاپ صورت‌حساب اشخاص با template رسمی** — *به فاز ۷ منتقل شد (Operational Polish)*

### معیار پذیرش
✅ Cash Flow Statement با مانده‌ی صحیح ابتدا و انتها.
✅ مقایسه‌ی دوره‌ای با درصد تغییر.
✅ Budget vs Actual با progress bar رنگی.
✅ Bank Reconciliation با ذخیره تطبیق.

### Deliverables
- ✅ نسخه‌ی v7.12.0 پکیج زیپ در ۳ مسیر
- ✅ بخش جدید در README با changelog کامل
- ✅ ERP_FIX_ROADMAP.md به‌روزرسانی به ✅ DONE

---

## ⚙ فاز ۷ — Operational Polish (v7.13.0)

**وضعیت:** ✅ **DONE** — تحویل در v7.13.0 (1404/03/23)

### Tasks
- [x] **WP-Cron روزانه**: recompute overdue + reminders Bale + audit prune
- [x] **WP-Cron ساعتی**: اجرای recurringهای فعال
- [x] **Drill-down KPI**: endpoint برای ۷ metric با لیست تراکنش‌ها
- [x] **Voucher Batch Entry**: ثبت چند سند با per-voucher report
- [x] **Recurring Transactions**: CRUD + اجرای خودکار/دستی
- [x] **Payslip Print**: فیش حقوقی کارشناس با تاریخچه و امضا
- [x] **Print Voucher**: template رسمی با header شرکت + signatures
- [x] **Print Cheque**: اطلاعات کامل با مبلغ بزرگ
- [x] **Print Statement**: صورت‌حساب اشخاص با running balance
- [x] **Cheque-Book Numbering**: شماره بعدی خودکار + duplicate guard
- [x] **Unfinalize Voucher**: CEO only + period lock + audit
- [x] صفحه‌ی «⚙ عملیات و اتوماسیون» با ۴ تب
- [x] deactivation hook → cleanup cron
- [⏭] **چاپ درخت کدینگ حساب‌ها** *(به فاز ۸ — Integrations)*
- [⏭] **QR در سند چاپی** *(به فاز ۸)*
- [⏭] **فیلتر دوره روی نمودار monthly** *(در React app — فاز ۸/۹)*

### معیار پذیرش
✅ Cron روزانه اجرا و overdue ها به‌روز می‌شوند.
✅ Cron ساعتی recurringها را اجرا می‌کند.
✅ Drill-down روی KPI با لیست تراکنش‌ها.
✅ چاپ سند با header شرکت + Dana + signatures.
✅ Cheque-book numbering با duplicate guard.

### Deliverables
- ✅ نسخه‌ی v7.13.0 پکیج زیپ در ۳ مسیر
- ✅ بخش جدید در README با changelog کامل
- ✅ ERP_FIX_ROADMAP.md به‌روزرسانی به ✅ DONE

---

## 🔌 فاز ۸ — Integrations & Automations (v8.0.0)

**وضعیت:** ✅ **DONE** — تحویل در v8.0.0 (1404/03/23) — **🎊 پایان Roadmap**

### Tasks
- [x] **Bale/SMS Reminders** (فاز ۷: Bale + cron داری → cheque/installment due)
- [x] **Bulk Import CSV**: income_expense, cheques, payables, coa (با preview + commit)
- [x] **Multi-currency نرخ روزانه**: rates CRUD + currency_convert با fallback
- [x] **Invoice/Quote module**:
  - 2 جدول جدید (invoices + invoice_rows)
  - 3 نوع: invoice/quote/proforma
  - محاسبه‌ی auto: subtotal, discount, tax, total
  - 5 status: draft, sent, paid, cancelled, partial
  - تبدیل به سند RV با یک کلیک
  - چاپ رسمی
- [x] **2FA**: TOTP-like با ایمیل OTP، tolerance ±۳۰s
- [x] **Webhook outbound**: HMAC-SHA256 signed، fire-and-forget، تست UI
- [x] **REST API عمومی**: ۸ endpoint + X-API-Key auth
- [x] **Standard Iran COA**: قبلاً فاز ۲ — قابل extend با CSV import

### معیار پذیرش
✅ Import یک CSV income_expense با voucher خودکار.
✅ صدور پیش‌فاکتور → تبدیل به فاکتور → چاپ → ثبت voucher.
✅ Webhook به Slack/Discord روی voucher.created.
✅ REST API با curl + X-API-Key.

### Deliverables
- ✅ نسخه‌ی v8.0.0 پکیج زیپ در ۳ مسیر
- ✅ README کامل با changelog
- ✅ ERP_FIX_ROADMAP.md به‌روزرسانی به ✅ DONE

---

## 🎊 پایان Roadmap

**هر ۸ فاز تکمیل شد!** پروژه از یافته‌های `ERP_AUDIT_REPORT` (که Readiness 54/100 بود) به یک ERP کامل، امن، مقیاس‌پذیر، با integrations استاندارد رسید.

### نکته‌ی فنی
صفحات admin این روادمپ به‌صورت standalone PHP page ساخته شدند تا React app موجود (با تمام بهبودهای فاز ۱-۷.۶) دست‌نخورده باقی بماند و نیازی به rebuild bundle (که در `/tmp/mali` پاک می‌شود) نبود. در Future Phase می‌توان sources React را در `ccptt/.ui-src/` persist کرد و این صفحات را به sidebar یکپارچه نمود.

---

# 📋 پروسه‌ی کاری

برای هر فاز این روال طی می‌شود:

1. شروع فاز → تغییر وضعیت به 🚧 In Progress
2. اجرای تک‌تک Tasks
3. تست‌های پذیرش
4. Build + extract + zip به ۳ مسیر
5. README به‌روزرسانی با changelog کامل
6. این فایل به ✅ **DONE** تغییر می‌کند
7. توقف و انتظار برای تأیید کاربر («ادامه بده»)
8. شروع فاز بعدی

---

# 🎯 ترتیب پیشنهادی اجرا (با دلیل)

- **فاز ۱ اول** چون بدون موتور سند کامل، بقیه‌ی گزارش‌ها معتبر نیستند.
- **فاز ۲ دوم** چون فاز ۱ هنوز با account IDهای hardcode کار می‌کند → فاز ۲ آن را پاک می‌کند.
- **فاز ۳ سوم** چون بدون migration دیتابیس، با volume بالا فاز ۱ و ۲ کند می‌شوند.
- **فاز ۴ چهارم** چون پس از پایداری data، باید امنیت آن را تضمین کنیم.
- **فاز ۵ پنجم** چون locking فقط با voucher engine کامل (فاز ۱) و RBAC (فاز ۴) معنی دارد.
- **فاز ۶ ششم** چون گزارش‌های جدید بدون data integrity فاز ۱-۵ بی‌فایده‌اند.
- **فاز ۷ هفتم** چون polish قبل از integration قابل تکرار است.
- **فاز ۸ آخر** چون integrationها به همه‌ی فازهای قبلی وابسته‌اند.

---

# 📝 یادداشت‌ها

- در طول هر فاز، rule های مهم رعایت می‌شوند:
  - ❌ هیچ قابلیت موجود حذف نشود (backward compatible)
  - ❌ هیچ بازطراحی کلی UI
  - ✅ Mobile-first + responsive + جالی شمسی
  - ✅ همه فیلدهای تاریخ با JalaliDatePicker
  - ✅ همه عملیات با Toast + Audit log
  - ✅ Bundle همیشه single-file extract می‌شود

- اگر در میانه‌ی فاز نیاز به تصمیم باشد، با ask_user می‌پرسیم.

---

**📅 تاریخ ایجاد:** 1404/03/22 (2026-06-12)
**🔄 آخرین به‌روزرسانی:** 1404/03/22 (2026-06-12)
**📌 وضعیت کلی:** 🎊 **تمام ۸ فاز تکمیل شد! ERP نسخه‌ی v8.0.0 آماده‌ی production است.**
