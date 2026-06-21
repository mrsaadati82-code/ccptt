# هماهنگ — افزونه‌ی مدیریت پروژه و تیم (CPTT)

نسخه: **8.0.1**

## 🔧 تغییرات نسخه‌ی 8.0.1 — حل مشکل M-۱: ذخیره‌ی source های React

این یک patch کوچک است که زیرساخت لازم برای فاز ۹ را آماده می‌کند.

### مشکل قبلی
source های React (TypeScript) در `/tmp/mali` ذخیره می‌شدند که بین جلسات پاک می‌شد. این یعنی نمی‌توان به پنل React چیز جدیدی اضافه کرد یا تغییر داد.

### راه‌حل
ساخت پوشه‌ی جدید `ui-src/` در ریشه‌ی پلاگین که شامل:

```
ui-src/
├── package.json           # dependency ها
├── package-lock.json
├── tsconfig.json          # تنظیمات TypeScript
├── vite.config.ts         # build config با singlefile plugin
├── index.html
├── scripts/
│   └── extract-bundle.py  # استخراج build شده به assets/
├── README.md              # راهنمای کامل
└── src/
    ├── main.tsx
    ├── index.css          # ۲۲۰ خط (Tailwind + FilterBar + anti-theme overrides)
    ├── utils/
    │   ├── cn.ts
    │   ├── jalali.ts        # تقویم شمسی (self-contained)
    │   ├── forceStyle.ts    # anti-WordPress-theme CSS forcing
    │   ├── wpBridge.ts      # bridge کامل با ۱۲۴ endpoint
    │   └── exporter.ts      # CSV/Excel/Print با فونت Dana
    ├── context/             # (در فاز ۹ بازنویسی می‌شود)
    └── components/          # (در فاز ۹ بازنویسی می‌شود)
```

### چه چیزی تغییر نکرد
- پنل React موجود (`assets/finance-ui/app.js` با ۴۴۶ KB) **دست‌نخورده است**
- هیچ قابلیتی حذف نشد
- هیچ عملکردی تغییر نکرد
- پلاگین مثل قبل کار می‌کند

### چه چیزی اضافه شد
- زیرساخت کامل برای rebuild پنل React در فاز ۹
- ۸۱۵ خط کد util (۵ فایل کلیدی)
- script استخراج خودکار bundle
- README کامل با توضیح وضعیت و راهنمای build

### فایل‌های تغییر یافته
- ✨ پوشه‌ی جدید `ui-src/` با ۱۰ فایل اصلی
- 🔁 `client-project-tracker.php` — bump به 8.0.1
- 🔁 `README.md` — این بخش
- 🔁 `ERP_MASTER_STATUS.txt` — مشکل M-۱ به DONE تغییر کرد

### قدم بعدی (فاز ۹)
حالا که زیرساخت ذخیره‌ی sources آماده است، در فاز ۹:
- بازنویسی `AppContext.tsx` با ۱۲۴ endpoint
- بازنویسی App.tsx + Sidebar + Navbar
- بازنویسی ۲۴ component (همه بهبودهای فاز ۱-۸ حفظ می‌شود)
- اضافه‌کردن ۶ تب جدید (Mapping, FY Close, Reports, Ops, Integrations, Invoices)

---

## 🎊 تغییرات نسخه‌ی 8.0.0 — فاز ۸: Integrations & Automations

این نسخه پایان **فاز ۸** و **پایان کامل Roadmap** است. ۸ ماژول جدید + ۲۸ Endpoint جدید + ۲ صفحه‌ی admin کامل.

### ۱) Bulk Import (CSV)
- پشتیبانی از ۴ نوع داده: درآمد/هزینه، چک‌ها، بدهی‌ها، سرفصل حساب‌ها
- ۲ حالت: **preview** (validate بدون save) + **commit** (ذخیره نهایی)
- گزارش per-line موفق/ناموفق با پیام خطای واضح
- نرمال‌سازی اعداد فارسی → انگلیسی خودکار
- چک period lock در commit
- در income_expense: voucher RV/PV خودکار + ledger
- 2 endpoint: `import_preview`, `import_commit`

### ۲) Multi-currency نرخ روزانه
- ذخیره نرخ ارز per (currency, jalali_date)
- جستجو با fallback به نزدیک‌ترین نرخ قبل
- `currency_convert(from, to, amount, date)` با محاسبه دقیق از طریق TOMAN
- 4 endpoint: `rates_list/save/delete`, `currency_convert`

### ۳) Invoice/Quote Module
- ۳ نوع: فاکتور، پیش‌فاکتور (quote)، پروفرما
- جداول جدید: `wp_cptt_erp_invoices` + `_rows`
- محاسبه‌ی خودکار: subtotal، discount، tax_amount، total
- ۵ status: draft / sent / paid / cancelled / partial
- **تبدیل به سند RV** (با یک کلیک پس از تسویه)
- **چاپ رسمی** با template Dana
- صفحه‌ی admin مستقل با لیست + فرم خط‌خط (lines)
- 6 endpoint: `invoice_list/save/delete/status/to_voucher/print`

### ۴) Webhooks خروجی
- ذخیره URL + events + secret (HMAC-SHA256)
- fire-and-forget روی event های کلیدی (voucher.created, invoice.saved, invoice.status)
- header `X-Hamahang-Signature` با امضای HMAC
- دکمه «⚡ تست» برای validation اتصال
- 4 endpoint: `webhook_list/save/delete/test`

### ۵) REST API عمومی (read-only)
- namespace: `cpttf-erp/v1`
- احراز با header `X-API-Key`
- ۸ endpoint: `health`, `vouchers`, `accounts`, `receivables`, `cheques`, `payables`, `invoices`, `kpis`
- مدیریت کلیدها در UI: ایجاد، list (با last 4)، باطل کردن
- last_used tracking
- نمایش full key فقط همان ۱ بار در ایجاد
- 3 endpoint: `apikey_list/create/revoke`

### ۶) ۲FA سبک‌وزن
- TOTP-like با کد ۶ رقمی هر ۳۰ ثانیه
- ارسال OTP به ایمیل کاربر در فعال‌سازی
- tolerance ±30s در verify
- audit log فعال‌سازی/غیرفعال‌سازی
- 4 endpoint: `2fa_status/enable/verify/disable`

### ۷) Standard Iran COA کامل
سرفصل استاندارد جامعه‌ی حسابداران رسمی (~۵۰ حساب) که در فاز ۲ اضافه شد، حالا کاملاً قابل extend از طریق Bulk Import CSV.

### ۸) ۲ صفحه‌ی Admin کامل
- **🔌 یکپارچه‌سازی**: ۵ تب (Import / Rates / Webhooks / API / 2FA)
- **🧾 فاکتورها**: لیست + فرم صدور با line editor + چاپ

### ۲ جدول جدید
- `wp_cptt_erp_invoices` (header)
- `wp_cptt_erp_invoice_rows` (line items)

### ۴ Option جدید
- `OPT_CURRENCY_RATES` — نرخ‌های ارز
- `OPT_WEBHOOKS` — webhook subscriptions
- `OPT_API_KEYS` — REST API keys
- `OPT_2FA` — ۲FA opt-in flags

### معیارهای پذیرش فاز ۸ ✅
1. ✅ سررسید چک ۷ روز قبل → SMS/Bale به مدیر مالی (در فاز ۷)
2. ✅ Import یک CSV درآمد/هزینه با voucher خودکار
3. ✅ صدور پیش‌فاکتور → تبدیل به فاکتور → چاپ → ثبت voucher
4. ✅ Webhook به Slack/Discord روی voucher.created
5. ✅ REST API با curl + X-API-Key
6. ✅ ۲FA با کد ایمیل برای admin

### آمار کل پایان Roadmap (v7.6.0 → v8.0.0)
- **۸ فاز کامل ✅**
- **+5,790 خط PHP** (از ~۲٫۳۵۰ خط v7.6 به ~۸٫۱۵۰ خط)
- **+115 endpoint جدید** (از ۵۲ به ~۱۶۷)
- **+9 جدول SQL جدید**
- **+15+ admin page** برای مدیریت
- **+4 print template** رسمی
- **+2 WP-Cron jobs**
- **+8 REST API routes**

### آنچه به Frontend React منتقل می‌شود (فاز Future)
صفحات admin این روادمپ به‌صورت **standalone PHP page** ساخته شدند تا React app موجود (با تمام بهبودهای فاز ۱-۷.۶) دست‌نخورده باقی بماند. در Future Phase می‌توان sources React را persist کرد و این صفحات را به sidebar یکپارچه نمود.

---

## ⚙ تغییرات نسخه‌ی 7.13.0 — فاز ۷: Operational Polish

این نسخه پایان **فاز ۷** از Roadmap است. ۱۳ Endpoint جدید، ۴ template چاپ، ۲ WP-Cron job، ۱ admin page یکپارچه برای عملیات.

### ۱) صفحه‌ی جدید «⚙ عملیات و اتوماسیون»
- زیر منوی CPTT-Finance — برای همه نقش‌های ERP
- ۴ تب: تکرارشونده / دفترچه چک / WP-Cron / چاپ سند

### ۲) تراکنش‌های تکرارشونده (Recurring Transactions)
- 4 frequency: روزانه/هفتگی/ماهانه/سالانه + interval قابل تنظیم
- تاریخ شروع، پایان (اختیاری)، اجرای بعدی، اجرای آخر، شمارش اجراها
- وضعیت فعال/غیرفعال
- اجرای دستی فوری برای تست
- اجرای خودکار توسط WP-Cron ساعتی
- در هر اجرا: ledger entry + voucher RV/PV با ref_type=erp_recurring
- 4 endpoint: `recurring_list/save/delete/run_now`

### ۳) دفترچه چک (Cheque-Book Numbering)
- تعریف دفترچه با: نام، بانک، سری حروف، سری صیادی، شماره شروع/پایان
- محاسبه‌ی شماره بعدی خودکار
- چک duplicate (هشدار اگر شماره با چک موجود تکراری باشد)
- نمایش باقی‌مانده برگ‌ها با رنگ‌بندی (قرمز < ۵)
- 4 endpoint: `chequebook_list/save/delete/next`

### ۴) WP-Cron (اتوماسیون)
دو cron job ثبت می‌شود (روی activate + admin_init):
- **روزانه** (`cpttf_erp_daily_cron`):
  - بازمحاسبه‌ی overdue chequeها، اقساط، payables
  - ارسال reminder Bale به مدیر مالی برای چک‌های ۷/۳/۱ روز مانده
  - ارسال reminder برای اقساط overdue
  - حذف audit_log بالای ۱ سال
- **ساعتی** (`cpttf_erp_hourly_cron`):
  - اجرای recurring transactionsهای فعال که nextRun ≤ امروز
  - update last_run + runs_count + next_run

- لاگ هر اجرا در `OPT_CRON_LOG` ذخیره می‌شود
- صفحه‌ی Cron نشان می‌دهد: زمان اجرای بعدی + گزارش آخرین اجرا
- دکمه «اجرای دستی» برای CEO/admin
- deactivation hook ⇒ unschedule

### ۵) KPI Drill-down
- endpoint جدید `kpi_drilldown` برای کلیک روی KPI dashboard
- پشتیبانی از ۷ metric: revenue, expense, profit, cash_in, cash_out, receivables, payables
- بازه‌ی تاریخ اختیاری
- برگرداندن: total + لیست تراکنش‌ها با voucher_id برای لینک

### ۶) Voucher Batch Entry
- endpoint `voucher_batch` — ثبت چند سند در یک call
- validation per voucher (debit==credit + period lock)
- return: success count + failed count + per-voucher result
- مفید برای import CSV

### ۷) Unfinalize Voucher (CEO only)
- endpoint `voucher_unfinalize` — بازکردن سند FINALIZED به DRAFT
- فقط CEO + manage_options
- چک period lock (نمی‌توان سند در دوره بسته را unfinalize کرد)
- پاک کردن approver fields
- audit log کامل

### ۸) ۴ Template چاپ رسمی
endpoint های جدید که HTML کامل با فونت Dana، header شرکت، signatures و print-bar برمی‌گردانند:

| Endpoint | پارامتر | محتوا |
|----------|---------|-------|
| `print_voucher` | `id` | جزئیات سند + ردیف‌ها + جمع‌بندی + ۳ امضا |
| `print_cheque` | `id` | اطلاعات کامل چک با مبلغ بزرگ |
| `print_payslip` | `expert_id, from, to` | فیش پرداخت کارشناس با تاریخچه |
| `print_statement` | `party_type, party_id, from, to` | صورت‌حساب اشخاص با running balance |

- فونت Dana محلی، RTL، A4، CSS print-only
- print-bar شناور (در حالت print مخفی)
- header شرکت + تاریخ چاپ خودکار
- signatures section: تنظیم‌کننده / تأییدکننده / مدیرعامل

### ۹) Helper جدید
- `fa_add_days($jalali, $days)` — افزودن روز به تاریخ شمسی
- `next_run_after($rec)` — محاسبه‌ی اجرای بعدی recurring
- `execute_recurring_item($rec)` — اجرای واقعی recurring (ledger + voucher)
- `send_due_reminders()` — ارسال یادآور Bale
- `print_styles()`, `company_header()`, `print_bar()`, `signatures()`, `fmt_money()` — print helpers

### معیارهای پذیرش فاز ۷ ✅
1. ✅ Cron روزانه اجرا و overdue ها به‌روز می‌شوند
2. ✅ Cron ساعتی recurringها را اجرا می‌کند
3. ✅ Drill-down روی هر KPI با لیست تراکنش‌ها
4. ✅ Batch entry با success/failed report
5. ✅ Print voucher با header شرکت + signatures + Dana
6. ✅ Cheque-book numbering با duplicate guard
7. ✅ Unfinalize با مجوز CEO + audit log
8. ✅ deactivation hook برای cleanup cron

### فایل‌های تغییر یافته
- `includes/class-cptt-finance-erp.php`:
  - +۷۰۰ خط Phase 7 logic (recurring, chequebook, cron, drilldown, batch, unfinalize)
  - +۴۰۰ خط 4 print templates
  - +۲۵۰ خط admin page «عملیات و اتوماسیون»
  - +۱۳ endpoint جدید
  - +۳ option جدید: OPT_RECURRING, OPT_CHEQUEBOOKS, OPT_CRON_LOG
- `client-project-tracker.php`:
  - schedule cron در activation
  - deactivation hook برای unschedule

---

## 📊 تغییرات نسخه‌ی 7.12.0 — فاز ۶: گزارش‌های پیشرفته

این نسخه پایان **فاز ۶** از Roadmap است. ۷ گزارش جدید استاندارد ERP اضافه شد، همگی در یک صفحه‌ی مستقل با تب‌بندی.

### ۱) صفحه‌ی جدید «📊 گزارش‌های پیشرفته»
- زیر منوی CPTT-Finance → «📊 گزارش‌های پیشرفته»
- دسترسی: `edit_cptt_projects` (همه‌ی نقش‌های ERP)
- ۷ تب با interface استاندارد و RTL کامل

### ۲) صورت جریان وجوه نقد (Cash Flow Statement)
- روش غیرمستقیم، ۳ سکشن استاندارد:
  - **عملیاتی**: تغییرات دارایی‌های جاری + بدهی‌ها + درآمد/هزینه
  - **سرمایه‌گذاری**: حساب‌های گروه ۲ (دارایی‌های غیرجاری)
  - **تأمین مالی**: حساب‌های گروه ۴ (سرمایه/سود انباشته)
- محاسبه‌ی مانده ابتدا/انتها/تغییر خالص بر اساس حساب‌های نقد (a1_1_*)
- نمایش جزئیات هر تراکنش با حساب طرف و توضیح

### ۳) مقایسه دوره‌ای (Period-over-Period)
- دو بازه‌ی تاریخ A و B
- مقایسه‌ی درآمد / هزینه / سود
- محاسبه‌ی delta و درصد تغییر با رنگ‌بندی
- مفید برای مقایسه «فروردین ۱۴۰۴ vs فروردین ۱۴۰۳»

### ۴) بودجه vs واقعی
- per cost center
- نمایش بودجه تعریف‌شده، واقعی مصرف‌شده، اختلاف، درصد مصرف
- progress bar رنگی (سبز < 80٪، زرد 80-100٪، قرمز > 100٪)
- وضعیت: سالم / هشدار / بیش از بودجه / بدون بودجه

### ۵) Aging مطالبات با پارامترهای دلخواه
- تاریخ پایه قابل تنظیم (پیش‌فرض امروز)
- بازه‌های قابل تنظیم با کاما (مثلاً `30,60,90,180,99999`)
- محاسبه آخرین پرداخت هر مشتری از ledger
- بازه‌بندی هر مشتری و جمع هر بازه

### ۶) Aging چک‌ها
- بازه‌بندی بر اساس روزها تا سررسید (منفی = گذشته)
- ۵ بازه پیش‌فرض: گذشته (-30)، تا یک‌ماه آینده، 30/60/60+
- تفکیک دریافتی و پرداختی

### ۷) WIP Report (Work-In-Progress)
- per پروژه: فاکتورشده / تکمیل‌شده / وصول‌شده
- محاسبه over_billed / under_billed / matched
- درصد تکمیل و درصد وصول با progress bar

### ۸) Bank Reconciliation MVP
- انتخاب حساب بانکی + بازه تاریخ
- نمایش تمام حرکات ledger با checkbox تطبیق
- ذخیره‌ی تطبیق‌شده‌ها در `OPT_BANK_RECON` per account
- خلاصه: جمع ورود/خروج، تطبیق‌شده، تطبیق‌نشده
- دکمه «انتخاب همه» / «انتخاب هیچ‌کدام»

### ۹) ۸ Endpoint جدید
- `cpttf_erp_cash_flow` (reports_view)
- `cpttf_erp_period_compare` (reports_view)
- `cpttf_erp_budget_actual` (reports_view)
- `cpttf_erp_aging_custom` (reports_view)
- `cpttf_erp_aging_cheques` (reports_view)
- `cpttf_erp_wip_report` (reports_view)
- `cpttf_erp_bank_recon` (reports_view)
- `cpttf_erp_bank_recon_save` (treasury_payment)

### ۱۰) Helper جدید
- `fa_to_gregorian_ts($jalali)` — تبدیل تاریخ شمسی به Unix timestamp Gregorian
- `compute_pl_range($from, $to)` — محاسبه سود/زیان بازه‌ای (استفاده در مقایسه)

### معیارهای پذیرش فاز ۶ ✅
1. ✅ Cash Flow با مانده‌ی صحیح ابتدا و انتها
2. ✅ مقایسه دوره‌ای با درصد تغییر
3. ✅ Budget vs Actual با progress bar رنگی
4. ✅ Aging قابل تنظیم با بازه‌های دلخواه
5. ✅ Aging چک‌های در جریان وصول و سررسید
6. ✅ WIP per پروژه با over/under billing
7. ✅ Bank Reconciliation با ذخیره‌ی تطبیق

### فایل‌های تغییر یافته
- `includes/class-cptt-finance-erp.php`:
  - +۸ endpoint جدید (~۴۰۰ خط)
  - +۲ helper جدید
  - +constant `OPT_BANK_RECON`
  - +صفحه‌ی admin جدید `render_reports_page()` با ۷ تب (~۳۸۰ خط)
- `client-project-tracker.php` — bump به 7.12.0

### نکته
صفحه‌ی گزارش‌های پیشرفته، مانند صفحات قبلی فاز ۲ و ۵، standalone PHP page است (بدون نیاز به rebuild bundle React).

---

## 🗓 تغییرات نسخه‌ی 7.11.0 — فاز ۵: Period Locking & Fiscal Year Closing

این نسخه پایان **فاز ۵** از Roadmap است. قفل دوره از حالت «نمایشی» به **اجبار واقعی در backend** ارتقا یافت + بستن سال مالی به‌صورت خودکار سند اختتامیه و افتتاحیه تولید می‌کند.

### ۱) Period Locking واقعی (Backend Enforcement)
- تابع `is_date_locked($jalali_date)`:
  - چک تمام سال‌های مالی `CLOSED` → اگر تاریخ داخل بازه باشد → locked
  - چک تمام `cpttf_erp_locks` با `isLocked=true`
  - پشتیبانی از قفل با start/end خالی (یعنی کل FY)
  - پشتیبانی از قفل با start فقط، end فقط، یا هر دو
- تابع `reject_if_locked($date, $context)`:
  - اگر date locked است → ۴۲۳ JSON با پیام واضح فارسی
  - super-admin (manage_options) escape hatch دارد
- enforcement در ۱۲ endpoint نوشتنی:
  - `voucher_save` (با date کاربر)
  - `voucher_delete` (با date سند موجود)
  - `ie_save` (با date کاربر)
  - `treasury_transfer/deposit/withdraw` (با امروز)
  - `settle_steps/manual` (با امروز)
  - `cheque_save` (با issueDate)
  - `cheque_status` (با امروز)
  - `installment_pay` (با امروز)
  - `payable_pay` (با امروز)
  - `project_quick_pay` (با امروز)

### ۲) صفحه‌ی جدید «🗓 بستن سال مالی»
- منوی جدید: CPTT-Finance → «🗓 بستن سال مالی» (فقط CEO/admin)
- نمایش لیست سال‌های مالی با وضعیت + قفل
- ۳ عملیات اصلی per FY:
  1. **پیش‌نمایش بستن** — سود/زیان دوره + جزئیات حساب‌های موقت + مانده‌های دائمی
  2. **اجرای بستن** — سند CV خودکار + قفل دوره + (اختیاری) سند OV برای سال بعدی
  3. **بازکردن دوباره** — برای CEO + audit log

### ۳) سند اختتامیه (CV) خودکار
- تابع `fy_pl_summary($fy)`:
  - aggregate همه‌ی vouchers FINALIZED در بازه‌ی FY
  - حساب‌های گروه ۵ (درآمد) → balance بستانکار
  - حساب‌های گروه ۶ (هزینه) → balance بدهکار
  - skip CV/OV قبلی (جلوگیری از حلقه)
  - return: revenue, expense, profit, rev_by_account, exp_by_account
- در `ajax_fy_close_execute()`:
  - debit هر حساب درآمدی به مبلغ مانده‌اش (zero-out)
  - credit هر حساب هزینه‌ای به مبلغ مانده‌اش (zero-out)
  - net (profit) به `retained_earnings` (پیش‌فرض a4_3) منتقل
  - اگر زیان → debit به سود انباشته؛ اگر سود → credit
- نوع سند: CV با date = endDate فیسکال یئر
- status: FINALIZED، isAutoGenerated: true

### ۴) سند افتتاحیه (OV) خودکار
- تابع `fy_permanent_balances($fy)`:
  - aggregate همه‌ی vouchers FINALIZED تا endDate
  - حساب‌های گروه ۱-۴ (دارایی، بدهی، حقوق صاحبان سهام) → balance running
  - filter حساب‌های با مانده ۰
- در closing با next_fy_id:
  - هر حساب دائمی با مانده‌اش به‌عنوان debit یا credit
  - اگر sum debit ≠ sum credit (تفاوت گرد کردن) → plug به retained_earnings
  - نوع سند: OV با date = startDate سال بعد

### ۵) ۵ Endpoint جدید
- `cpttf_erp_period_check` — کوئری: آیا این تاریخ قفل است؟ (برای UI)
- `cpttf_erp_fy_close_preview` — preview سود/زیان قبل از بستن
- `cpttf_erp_fy_close_execute` — اجرای واقعی بستن (CEO only)
- `cpttf_erp_fy_reopen` — بازکردن دوباره FY (CEO only)
- `cpttf_erp_lock_clear` — حذف تمام قفل‌های یک FY (CEO only)

### ۶) ۲ Account Mapping جدید
- `retained_earnings` — سود (زیان) انباشته (پیش‌فرض a4_3)
- `profit_loss_summary` — حساب کنترلی جمع‌بندی سود/زیان

### ۷) Backward Compatibility
- super-admin (administrator) همیشه می‌تواند روی دوره‌ی بسته تراکنش ثبت کند (escape hatch)
- سند CV/OV قابل reverse هستند (با دکمه «↻ برگشت سند» در صفحه Vouchers)
- reopen FY سند CV/OV را حذف نمی‌کند — audit trail سالم می‌ماند

### معیارهای پذیرش فاز ۵ ✅
1. ✅ ثبت سند با تاریخ در دوره‌ی قفل → پیام خطای ۴۲۳ واضح
2. ✅ بستن سال مالی، سند CV متعادل تولید می‌کند
3. ✅ سال جدید با مانده‌ی صحیح حساب‌های دائمی شروع می‌شود (سند OV)
4. ✅ CEO می‌تواند FY بسته را reopen کند
5. ✅ همه‌ی عملیات در audit_log ثبت می‌شوند
6. ✅ super-admin escape hatch دارد

### فایل‌های تغییر یافته
- `includes/class-cptt-finance-erp.php`:
  - +۳۰۰ خط helpers: `fa_date_cmp`, `to_en_digits`, `is_date_locked`, `reject_if_locked`, `fy_for_date`, `fy_pl_summary`, `fy_permanent_balances`
  - +۵ endpoint: `ajax_period_check`, `ajax_fy_close_preview`, `ajax_fy_close_execute`, `ajax_fy_reopen`, `ajax_lock_clear`
  - +reject_if_locked در ۱۲ endpoint نوشتنی
  - +۲ purpose در default_account_mapping (retained_earnings, profit_loss_summary)
  - +صفحه‌ی admin جدید `render_fyclose_page()` با CSS+JS داخلی
- `client-project-tracker.php` — bump به 7.11.0

### نکته
صفحه‌ی «🗓 بستن سال مالی» مانند صفحه‌ی Account Mapping به‌صورت standalone PHP rendered است (نیازی به rebuild bundle ندارد).

---

## 🔐 تغییرات نسخه‌ی 7.10.0 — فاز ۴: Security & RBAC واقعی

این نسخه پایان **فاز ۴** از Roadmap است. permission matrix که قبلاً فقط در UI کار می‌کرد، حالا در backend هم enforce می‌شود. کاربر کارشناس دیگر دسترسی کامل به ERP ندارد.

### ۱) سیستم RBAC جدید (Backend Enforcement)
- تابع جدید `current_role_key()`: نقش ERP کاربر را از WordPress role می‌خواند
  - `administrator` → ceo
  - `cptt_financial_manager` → financial_manager
  - `cptt_accountant` → accountant
  - `cptt_cashier` → cashier
  - `cptt_expert` → **viewer** (تنها مشاهده) — قبلاً به‌عنوان accountant کامل دسترسی داشت!
  - سایر → viewer
- تابع `role_has_perm($perm)`: چک می‌کند نقش فعلی این permission را دارد یا نه
  - manage_options (super-admin) همیشه pass می‌شود (escape hatch)
  - خواندن از matrix ذخیره‌شده در `OPT_PERMS`، در صورت نبود به `default_role_perms()` fallback
- تابع `check_perm($perm)`: nonce + base capability + specific perm را چک می‌کند

### ۲) ۱۲ Permission Key
- voucher_view, voucher_create, voucher_approve, voucher_delete, voucher_reverse
- reports_view, treasury_view, treasury_payment, project_finance_view
- audit_view, settings_manage, mapping_manage

### ۳) ۴۱ Endpoint به perm-aware تبدیل شد
هر endpoint حالا فقط با permission مناسب فراخوانی می‌شود (نه فقط `edit_cptt_projects`):
- ۴ Voucher endpoint
- ۸ Settings endpoint (coa, cc, fy, fincat, lock)
- ۵ Treasury endpoint
- ۲ IE endpoint
- ۲ Settlement endpoint
- ۳ Cheque endpoint
- ۳ Installment endpoint
- ۳ Payable endpoint
- ۲ Attachment endpoint
- ۳ Project endpoint
- ۳ Mapping endpoint (mapping_manage)
- ۲ Audit/Permissions endpoint

### ۴) Rate-Limiting
- روی endpointهای حساس write: حداکثر ۶۰ request در ۶۰ ثانیه per (user, perm)
- استفاده از transient API وردپرس
- پیام خطای ۴۲۹ با متن فارسی

### ۵) MIME Type Validation واقعی روی Upload
- تابع جدید `validate_uploaded_file()`:
  - inspect actual file contents با `wp_check_filetype_and_ext()` (نه فقط extension)
  - فایل با extension `.jpg` ولی محتوای .php → reject
  - حداکثر سایز قابل تنظیم با filter `cpttf_erp_max_upload_bytes` (پیش‌فرض ۱۰ MB)
  - whitelist: PDF, JPG/JPEG, PNG, ZIP

### ۶) KSES Sanitization Helper
- تابع جدید `safe_text($v, $allow_basic = false)`:
  - default: `wp_strip_all_tags()` + truncate ۵۰۰۰ char
  - با `allow_basic=true`: KSES با whitelist محدود (br, p, b, strong, i, em, u, span, a)
  - برای استفاده در فاز ۵ به بعد روی description ها

### ۷) Audit Log به همه‌ی CRUD ها اضافه شد
عملیات‌هایی که قبلاً audit نمی‌شدند، حالا می‌شوند:
- `income_expense` create/delete (با reverse_voucher_id)
- `treasury_deposit/withdraw/transfer` create
- `cost_center` create/update/delete
- `fiscal_year` create/close
- `period_lock` toggle
- موارد قبلی (voucher, cheque, payable, installment, attachment, permissions, mapping, coa) دست‌نخورده

### ۸) نقش‌های ERP در Activation
- روی `register_activation_hook` سه نقش جدید WordPress ساخته می‌شوند:
  - `cptt_financial_manager` — مدیر مالی (ERP)
  - `cptt_accountant` — حسابدار (ERP)
  - `cptt_cashier` — تحویل‌دار صندوق (ERP)
- هر کدام capability پایه `edit_cptt_projects` را دارند (برای ورود به ERP)
- نقش `cptt_expert` موجود بدون تغییر باقی می‌ماند، اما در ERP دسترسی محدود `viewer` می‌گیرد

### ۹) Activation Hook Phase 3 جدید
- روی activation: install tables + ensure_ledger_has_voucher_id + migrate_options
- روی نصب اولیه، migration در همان لحظه‌ی activate رخ می‌دهد (نه در admin_init)

### معیارهای پذیرش فاز ۴ ✅
1. ✅ Cashier نمی‌تواند voucher create کند حتی با direct AJAX call
2. ✅ کارشناس نمی‌تواند permissions را عوض کند (only viewer)
3. ✅ آپلود فایل .php با extension تقلبی .jpg → reject با پیام واضح
4. ✅ هر CRUD در audit_log ثبت می‌شود
5. ✅ Rate-limit برای جلوگیری از abuse و brute-force
6. ✅ Super-admin (manage_options) همیشه escape hatch دارد
7. ✅ ۳ نقش جدید WordPress در فعال‌سازی ساخته می‌شوند

### فایل‌های تغییر یافته
- `includes/class-cptt-finance-erp.php`:
  - +۱۶۰ خط (helpers: `current_role_key`, `default_role_perms`, `role_has_perm`, `check_perm`, `rate_limit_check`, `validate_uploaded_file`, `safe_text`)
  - ۴۱ endpoint با `check()` → `check_perm($perm)`
  - ۵ audit_log جدید
  - ۳ جا hardcoded role mapping با `current_role_key()` جایگزین شد
  - `ajax_attachment_upload` با MIME validation سفت‌شده
- `client-project-tracker.php`:
  - bump به 7.10.0
  - activation hook جدید: install ERP tables + add roles

### مهاجرت ایمن
- کاربر کارشناس که قبلاً وارد ERP می‌شد، حالا نقش `viewer` می‌گیرد (read-only)
- اگر می‌خواهید کارشناس خاصی دسترسی editing داشته باشد، نقش او را در WP به `cptt_financial_manager` یا `cptt_accountant` تغییر دهید
- permissions matrix در صفحه «سطوح دسترسی» همان UI قبلی است؛ الان واقعاً اثر دارد

---

## ⚡ تغییرات نسخه‌ی 7.9.0 — فاز ۳: Migration دیتابیس + کارایی

این نسخه پایان **فاز ۳** از Roadmap است. تمام داده‌های ERP که در `wp_options` array-style ذخیره می‌شدند (و بزرگ‌ترین scalability bottleneck بودند) به **جداول اختصاصی** منتقل شدند.

### ۱) ۶ جدول جدید + Migration خودکار
- `wp_cptt_erp_vouchers` — هدر سند (با ۶ index: type, status, date, ref, fy, number)
- `wp_cptt_erp_voucher_rows` — ردیف‌های سند (با ۵ index: voucher, account, project, customer, expert)
- `wp_cptt_erp_cheques` — چک‌ها (با ۶ index: kind, status, due, party, sayyadi, bank)
- `wp_cptt_erp_payables` — بدهی‌ها (با ۳ index: party, status, due)
- `wp_cptt_erp_installments` — برنامه‌های قسط (با ۳ index)
- `wp_cptt_erp_installment_rows` — قسط‌های جداگانه (با UNIQUE plan_no + ۲ index)

**Migration Runner**: روی `admin_init` خودکار اجرا می‌شود (idempotent). هر بار کد بالاتر از `DB_VERSION` ذخیره‌شده باشد:
- `install_all_tables()` — `dbDelta` همه‌ی جداول
- `ensure_ledger_has_voucher_id()` — اضافه‌کردن ستون `voucher_id` به `wp_cptt_fin_ledger` + ۴ index جدید (date_at, project_id, customer_id, ref_type)
- `migrate_options_to_tables()` — انتقال داده‌های موجود از options به جداول
- `disable_autoload_for_legacy_options()` — حذف autoload از ۷ option بزرگ

### ۲) Voucher Engine روی Table
- `gen_voucher()` حالا مستقیماً به `wp_cptt_erp_vouchers` + `_rows` می‌نویسد
- `ajax_voucher_save/approve/delete` همگی روی جدول کار می‌کنند
- `read_vouchers_from_table()` با single JOIN: همه‌ی vouchers + rows در ۲ کوئری (نه N+1)
- legacy `get_vouchers_legacy()` به‌عنوان fallback نگه داشته شده

### ۳) Cheques/Payables/Installments روی Table
- خواندن و نوشتن همگی روی جداول
- legacy option fallback برای backward compatibility
- saveOnce strategy: callers لیست کامل می‌فرستند، table با TRUNCATE+rebuild هم‌گاه می‌شود

### ۴) Index های بهینه روی `wp_cptt_fin_ledger`
- `date_at_idx` — برای فیلتر بازه‌ی تاریخ
- `project_id_idx` — برای گزارش پروژه
- `customer_id_idx` — برای صورت‌حساب مشتری
- `ref_type_idx` — برای group-by منبع
- `voucher_id_idx` — برای back-ref به سند (ستون جدید)

### ۵) N+1 Killer در `build_*` methods
- `build_receivables()`:
  - `update_meta_cache('post', $pids)` — یک کوئری برای همه meta
  - `get_users(include=$cids)` — یک کوئری برای همه‌ی customer ها
  - `static $roles_cache` — حذف فراخوانی تکراری
- `build_projects_full()`:
  - `update_meta_cache('post', $pids)` — یک کوئری به‌جای N×3
  - `no_found_rows=true` — سرعت بیشتر

### ۶) Chunked Bootstrap (۵ endpoint جدید)
به‌جای یک `full_bootstrap` سنگین، می‌توان بخش‌بخش بارگذاری کرد:
- `cpttf_erp_bootstrap_core` — companies, branches, fy, accounts, role, mapping
- `cpttf_erp_bootstrap_treasury` — treasuryAccounts, cheques, payables, installments
- `cpttf_erp_bootstrap_projects` — projectsFull, receivables, experts, steps
- `cpttf_erp_bootstrap_reports` — vouchers, settlements, ie, categories, locks
- `cpttf_erp_bootstrap_kpis` — kpis, monthlySeries, topCustomers, breakdowns, notifs

⚠ نکته: React app موجود همچنان `full_bootstrap` را صدا می‌زند (backward compatible). فاز ۸/۹ یک React refactor می‌کند تا از chunkها استفاده کند.

### ۷) Audit Log Pagination
- `cpttf_erp_audit_list` حالا با `page` و `per_page` (پیش‌فرض ۵۰) کار می‌کند
- return شامل `total`, `total_pages`, `page`, `per_page`
- پیش از این `LIMIT 500` ثابت بود

### ۸) Diagnostics Endpoint
- `cpttf_erp_migration_status` — برمی‌گرداند:
  - `db_version` فعلی
  - `target_version` کد
  - تعداد ردیف هر جدول
  - تعداد item های هر option قدیمی
  - برای troubleshooting و monitoring

### معیارهای پذیرش فاز ۳ ✅
1. ✅ Migration خودکار روی نصب بدون مداخله‌ی کاربر
2. ✅ هیچ option ای بزرگ‌تر از ۱۰ KB autoload نمی‌شود
3. ✅ جداول با مناسب‌ترین index ها ساخته می‌شوند
4. ✅ N+1 در build_receivables و build_projects_full حل شده
5. ✅ Audit log با pagination واقعی کار می‌کند
6. ✅ Voucher CRUD به‌صورت native روی جدول کار می‌کند
7. ✅ Backward compatible: legacy options به‌عنوان fallback نگه داشته شده

### فایل‌های تغییر یافته
- `includes/class-cptt-finance-erp.php` — ۷۶۵+ خط جدید:
  - constants: `OPT_DB_VERSION`, `DB_VERSION`, ۶ `TBL_*`
  - methods: `maybe_run_migrations()`, `install_all_tables()`, `ensure_ledger_has_voucher_id()`, `migrate_options_to_tables()`, `disable_autoload_for_legacy_options()`, `insert_*_into_table()` x4, `read_vouchers_from_table()`, `get_vouchers_legacy()`, ۵ `ajax_bootstrap_*`, `ajax_migration_status`
  - بازنویسی: `get_vouchers()`, `get_cheques()`, `save_cheques()`, `get_payables()`, `save_payables()`, `get_installment_plans()`, `save_installment_plans()`, `ajax_voucher_save/approve/delete`, `gen_voucher()`
- `client-project-tracker.php` — bump به 7.9.0

### پس از نصب اولین بار
- وارد ادمین وردپرس شوید → migration خودکار اجرا می‌شود
- در URL `?page=cptt-finance-erp&action=migration_status` (یا با ajax tool) چک کنید همه جدول‌ها ساخته شده‌اند
- تعداد ردیف هر جدول باید با تعداد item قدیمی option مطابق باشد

---

## 🎯 تغییرات نسخه‌ی 7.8.0

## 🎯 تغییرات نسخه‌ی 7.8.0 — فاز ۲: Account Mapping قابل پیکربندی

این نسخه پایان **فاز ۲** از Roadmap است. هیچ account ID دیگر در voucher engine hardcode نیست. کاربر CEO می‌تواند نگاشت پیش‌فرض را در یک صفحه ساده تنظیم کند.

### ۱) صفحه‌ی جدید «🧭 نگاشت حساب‌های پیش‌فرض»
- منوی جدید زیر CPTT → «🧭 نگاشت حساب‌ها» (فقط CEO/admin)
- ۱۲ نگاشت قابل تنظیم در ۵ گروه (دارایی، بدهی، درآمد، هزینه، اختیاری):
  - `cash_default` — صندوق پیش‌فرض (برای CASH treasury)
  - `bank_default` — بانک پیش‌فرض (برای BANK treasury)
  - `receivable_default` — بدهکاران تجاری
  - `notes_receivable` — اسناد دریافتنی (چک‌های دریافتی)
  - `payable_default` — بستانکاران تجاری
  - `notes_payable` — اسناد پرداختنی (چک‌های پرداختی)
  - `expert_payable` — بدهی به کارشناسان
  - `revenue_default` — درآمد ارائه خدمات
  - `other_income` — سایر درآمدها
  - `expense_default` — هزینه‌های اداری
  - `expert_payroll` — دستمزد کارشناس
  - `transfer_clearing` — حساب کلیرینگ (اختیاری)
- هر فیلد یک Select از همه‌ی حساب‌های CoA با indent بر اساس type
- نمایش hint توضیحی زیر هر فیلد

### ۲) نگاشت per-treasury → CoA subsidiary
- هر صندوق/بانک می‌تواند به یک حساب CoA خاص نگاشت شود (override بر pre-default)
- اگر تعیین نشد، fallback به `cash_default` یا `bank_default` بر اساس نوع
- ذخیره در `OPT_TREASURY_COA_MAP`

### ۳) وارد کردن سرفصل استاندارد ایران
- دکمه‌ی «➕ افزودن (Merge)»: حساب‌های جدید استاندارد اضافه می‌شوند، موجودها حفظ
- دکمه‌ی «⚠ جایگزینی (Replace)»: کل کدینگ پاک و سرفصل ۴-سطحی استاندارد جایگزین می‌شود
- سرفصل استاندارد شامل ~۵۰ حساب در ۶ گروه اصلی (دارایی جاری/غیرجاری، بدهی، حقوق صاحبان سهام، درآمد، هزینه) با subaccount های رایج

### ۴) محاسبه‌ی مانده‌ی زنده روی CoA tree
- تابع جدید `get_coa_with_balances()` در PHP
- aggregate از voucherها با parent chain rollup (انتخاب حساب کل، جمع زیرحساب‌ها)
- فقط vouchers با status `FINALIZED/MANAGER_APPROVED/ACCOUNTANT_APPROVED` لحاظ می‌شوند
- مانده زنده در bootstrap به‌جای زدن ۰، عدد واقعی فرستاده می‌شود
- صفحه‌ی «نگاشت حساب‌ها» جدول مانده‌ی زنده‌ی سرفصل‌های کل/گروه را نشان می‌دهد
- صفحه‌ی «کدینگ حساب‌ها» در React app هم خودکار از این داده استفاده می‌کند

### ۵) Voucher Engine بدون hardcode
همه‌ی `gen_voucher()` calls حالا از `map_account('purpose')` استفاده می‌کنند:
- treasury_deposit, withdraw, transfer
- ie_save (income/expense)
- project_quick_pay
- auto_voucher_expert_payout, manual_payment
- cheque save/status
- installment_pay
- payable_pay
- map_treasury_to_coa هم از نگاشت می‌خواند

### ۶) Endpoint های جدید Backend
- `cpttf_erp_mapping_get` — برگرداندن نگاشت فعلی + accountها + treasury + purposes
- `cpttf_erp_mapping_save` — ذخیره با validation (CoA id باید موجود باشد)
- `cpttf_erp_coa_balances` — برگرداندن `{coa_id: {debit, credit, balance}}`
- `cpttf_erp_coa_import_standard` — import با mode merge/replace
- `cpttf_erp_coa_treasury_map_save` — ذخیره‌ی per-treasury map (sub-API)

### ۷) Bootstrap Payload Extend
- `accountMapping` — نگاشت فعلی
- `treasuryCoaMap` — نگاشت per-treasury
- `mappingPurposes` — labels فارسی برای purpose keys
- `accounts` حالا با balance زنده می‌آید (بدون تغییر API)

### معیارهای پذیرش فاز ۲ ✅
1. ✅ تغییر کدینگ هیچ‌گاه باعث ثبت voucher به حساب اشتباه نمی‌شود (همه از map_account)
2. ✅ balance روی هر node در ChartOfAccounts زنده است (از voucherها)
3. ✅ COA استاندارد ایران در یک کلیک import می‌شود (Merge یا Replace)
4. ✅ CEO می‌تواند نگاشت‌ها را در UI تنظیم کند
5. ✅ per-treasury override برای حساب‌های خاص

### فایل‌های تغییر یافته
- `includes/class-cptt-finance-erp.php` — ۶۰۰+ خط جدید:
  - constants: `OPT_ACCOUNT_MAPPING`, `OPT_TREASURY_COA_MAP`
  - methods: `default_account_mapping()`, `get_account_mapping()`, `map_account()`, `get_coa_with_balances()`, `standard_iran_coa()`, `account_mapping_purpose_labels()`, `get_treasury_coa_map()`
  - endpoints: ۵ endpoint جدید
  - admin page: `render_mapping_page()` با CSS + JS داخلی
- `client-project-tracker.php` — bump به 7.8.0

### نکته‌ی فاز ۳
صفحه‌ی نگاشت در فاز ۲ به‌صورت admin page مستقل (PHP-rendered) تحویل داده شده تا app.js بازسازی نشود. در فاز ۳ که migration پایگاه‌داده انجام می‌شود، React component مشابه به sidebar اضافه می‌شود.

---

## 🔥 تغییرات نسخه‌ی 7.7.0

## 🔥 تغییرات نسخه‌ی 7.7.0 — فاز ۱: Voucher Engine کامل

این نسخه پایان **فاز ۱** از Roadmap (ERP_FIX_ROADMAP.md) است. تمام gapهای موتور حسابداری که در ERP_AUDIT_REPORT گزارش شدند، رفع شد.

### رفع gap های Voucher Engine
رویدادهایی که قبلاً voucher تولید نمی‌کردند و حالا تولید می‌کنند:

| رویداد | قبل | حالا |
|--------|-----|------|
| Customer Quick Pay | ❌ فقط ledger | ✅ RV voucher + ledger |
| Income (IncomeExpense save) | ❌ فقط ledger | ✅ RV voucher + ledger |
| Expense (IncomeExpense save) | ❌ فقط ledger | ✅ PV voucher + ledger |
| Treasury Deposit | ❌ فقط ledger | ✅ RV voucher + ledger |
| Treasury Withdraw | ❌ فقط ledger | ✅ PV voucher + ledger |
| Treasury Transfer | ❌ فقط ۲ ledger | ✅ TV voucher + ۲ ledger |
| IncomeExpense Delete | ❌ بدون reverse | ✅ تولید سند برگشتی خودکار |

### ابزارهای جدید Backend
- **`map_treasury_to_coa($treasury_id)`**: نگاشت خودکار حساب treasury (CASH→a1_1_2, BANK→a1_1_1) به subsidiary استاندارد در CoA. نام به صورت «بانک‌های ریالی — ملت ۱۲۳» تولید می‌شود.
- **`validate_voucher_rows($rows)`**: اعتبارسنجی backend (debit==credit ±۱ ریال tolerance، عدم منفی، عدم همزمانی debit/credit در یک ردیف، حداقل یک ردیف). در `ajax_voucher_save` و `gen_voucher` اعمال می‌شود.
- **`gen_reverse_voucher($voucher_id, $note)`**: تولید سند برگشتی با debit/credit عوض‌شده، status `FINALIZED`، با لینک به مرجع.
- **`coa_name($account_id)`**: lookup خودکار نام حساب از CoA (در voucher rows دیگر نام نباید hardcoded باشد).

### Endpoint جدید
- `cpttf_erp_voucher_reverse` — تولید سند برگشتی برای هر voucher موجود

### بهبود `gen_voucher()`
- نام حساب از CoA به‌صورت خودکار خوانده می‌شود (نه hardcoded)
- پشتیبانی از پارامتر `date` (اختیاری، پیش‌فرض امروز شمسی)
- پارامتر `strict` (default true) — اگر validation شکست بخورد، `WP_Error` برمی‌گرداند
- نرمال‌سازی types: float برای amount، int برای IDها، sanitize_text_field برای description

### COA پیش‌فرض گسترش یافت
حساب‌های جدید برای پشتیبانی از Phase 1:
- `a1_1_3` — اسناد دریافتنی (چک‌های دریافتی)
- `a3_1` — حساب‌ها و اسناد پرداختنی (general)
- `a3_1_1` — بستانکاران تجاری (پیمانکاران/تامین‌کنندگان)
- `a3_1_2` — اسناد پرداختنی (چک‌های پرداختی)
- `a3_1_3` — بدهی به کارشناسان
- `a5_1` — درآمد ارائه خدمات
- `a5_2` — سایر درآمدها

### دکمه «↻ برگشت سند» در UI
- در صفحه‌ی Vouchers، اسناد `FINALIZED` دکمه‌ی جدید «برگشت سند» دارند
- با prompt برای توضیح اختیاری
- تولید سند معکوس متعادل با ref به مرجع
- پیشنهاد: به‌جای حذف سند، از این دکمه استفاده شود (audit trail سالم می‌ماند)

### معیارهای پذیرش فاز ۱ ✅
1. ✅ ثبت یک income → در دفتر روزنامه‌ی واقعی دیده می‌شود (بدون نیاز به synth)
2. ✅ Transfer بین دو حساب → یک سند TV متعادل در دفتر کل ظاهر می‌شود
3. ✅ debit ≠ credit در backend reject می‌شود با پیام واضح
4. ✅ حذف IE → سند برگشتی خودکار تولید می‌شود (audit trail کامل)
5. ✅ نام حساب در voucher rows خودکار از CoA fetch می‌شود

### فایل‌های تغییر یافته
- `includes/class-cptt-finance-erp.php` — ۲۶۰+ خط جدید (helpers + ۸ endpoint بازنویسی)
- `assets/finance-ui/app.js` + `app.css` — rebuild
- `src/utils/wpBridge.ts` — `reverseVoucher` endpoint
- `src/context/AppContext.tsx` — `reverseVoucher` در public API
- `src/components/Vouchers.tsx` — دکمه «برگشت سند»

### Phase 2 → آغاز بعدی
Account Mapping قابل پیکربندی (جایگزینی hardcoded a3_1_1, a5_1 و … با تنظیمات قابل ویرایش در UI).

---

## 🎯 تغییرات نسخه‌ی 7.6.0

## 🎯 تغییرات نسخه‌ی 7.6.0 (UX فیلترها، تب تسویه‌شده، گزارش‌های دقیق)

### 1️⃣ کامپوننت یکپارچه FilterBar (همه صفحات لیست)
- کامپوننت جدید `FilterBar` + `CompactField` در `UI.tsx`
- CSS جدید `.cptt-filterbar` با grid responsive:
  - موبایل: ۲ ستون
  - تبلت (sm): ۳ ستون / (md): ۴ ستون
  - دسکتاپ (lg): ۶ ستون / (xl): ۸ ستون
- فیلدهای داخل filterbar فشرده‌ترند: padding 0.45rem 0.7rem، font 0.72rem، height 2.25rem
- بدین ترتیب همه فیلترها در ۱ تا حداکثر ۲ ردیف جای می‌گیرند
- صفحات بازنویسی‌شده:
  - **اسناد حسابداری** (Vouchers) — ۸ فیلتر در یک نگاه
  - **درآمدها و هزینه‌ها** (IncomeExpense) — ۴ فیلتر
  - **مطالبات مشتریان** (Receivables) — ۳ فیلتر
  - **صورت‌حساب اشخاص** (PartyStatement) — ۶ فیلتر
  - **خزانه‌داری** (Treasury) — ۲ + ۲ filter بار
  - **گردش حساب یکپارچه** (Ledger) — ۳ فیلتر
  - **چک‌ها** (Cheques) — ۷ فیلتر
  - **بدهی‌ها** (Payables) — ۶ فیلتر
  - **حساب پروژه‌ها** (ProjectAccounting) — ۴ فیلتر
  - **دفاتر و گزارشات مالی** (FinancialReports) — ۶ فیلتر

### 2️⃣ تب «تسویه‌شده» در تسویه کارشناسان
- ۳ تب جدید در صفحه تسویه کارشناسان:
  - **منتظر تسویه** — کارشناسان با `pendingBalance > 0`
  - **تسویه‌شده** — کارشناسان با `pendingBalance == 0 && paidBalance > 0` با نمایش جزئیات: مراحل تسویه‌شده و رکوردهای پرداخت
  - **تاریخچه تسویه‌ها** — جدول کامل با فیلتر (جستجو، کارشناس، بازه تاریخ) + Pagination + جمع کل
- KPI چهارم اضافه شد: «رکوردهای تسویه»

### 3️⃣ دکمه «انتقال بین حساب‌ها» در منو فعال شد
- در `App.tsx` تب `transfers` با prop `initialMode="transfer"` به کامپوننت Treasury هدایت شد
- prop جدید در Treasury: وقتی `initialMode === 'transfer'`، خودکار Modal «انتقال وجه بین حساب‌ها» باز می‌شود با حساب اولین تنظیم‌شده به‌عنوان مبدا

### 4️⃣ گزارشات مالی — اصلاح ریشه‌ای
**مشکل قبلی:** lookup حساب‌ها فقط از حساب‌هایی که در voucherها ظاهر شده بودند انجام می‌شد → اگر voucher ثبت نشده بود، dropdown خالی بود.
- `LedgerReport` بازنویسی شد:
  - تمام درخت حساب‌ها (`accounts`) flat می‌شود
  - بر اساس `mode='general'` فقط حساب‌های کل/گروه؛ `mode='subsidiary'` فقط معین/تفصیلی
  - label با فرمت `کد — نام`
  - aggregation با parent chain: انتخاب یک حساب کل، جمع زیرحساب‌هایش را نشان می‌دهد
  - نمایش حساب حتی اگر گردشی نباشد ("در دوره گردشی ثبت نشده")
- **Voucher synthesis** برای داده‌های legacy:
  - اگر یک `IncomeExpense` یا `SettlementHistory` voucher متناظر در `vouchers` ندارد، یک voucher موقت (synthetic) ساخته می‌شود
  - این تضمین می‌کند داده‌های نقدی شما همیشه در دفتر روزنامه، دفتر کل، تراز، صورت سود و زیان دیده شوند
  - voucher synthetic با `refType` و `refId` track می‌شود تا duplicate نشود

### فایل‌های تغییر یافته
- ✨ `index.css`: ۳۰+ خط جدید برای FilterBar/CompactField + compact input
- 🔁 `components/UI.tsx`: `FilterBar`، `CompactField` اضافه
- 🔁 ۱۰ صفحه‌ی لیست: همه به FilterBar مهاجرت کردند
- 🔁 `components/ExpertSettlement.tsx`: ۳ تب با تاریخچه فیلتردار
- 🔁 `components/Treasury.tsx`: prop `initialMode`
- 🔁 `components/FinancialReports.tsx`: LedgerReport بازنویسی + voucher synthesis
- 🔁 `App.tsx`: routing tab `transfers` با `initialMode='transfer'`
- 🔁 bump version 7.5.0 → 7.6.0

---

## 🔧 تغییرات نسخه‌ی 7.5.0

## 🔧 تغییرات نسخه‌ی 7.5.0 (راه‌حل ریشه‌ای override قالب/وردپرس)

### مشکل اصلی نسخه‌های قبلی
استایل‌های CSS عمومی قالب (روی `input`, `select`, `textarea`, `button`) با `!important` خودشان روی کلاس‌های ما غلبه می‌کردند. حتی selectorهای ID + class + `!important` نسخه ۷.۴ هم در بعضی تم‌ها کنار زده می‌شدند.

### راه‌حل قطعی این نسخه (Nuclear Anti-Theme Style Patcher)
- ابزار جدید `utils/forceStyle.ts`: تابع `applyImportant(el, styles)` که با `element.style.setProperty(prop, val, 'important')` استایل را به‌صورت inline + important اعمال می‌کند — **بالاترین قدرت ممکن در CSS** و هیچ stylesheet خارجی نمی‌تواند آن را override کند.
- Hook جدید `useForceStyle()`: روی هر ref ورودی، استایل را در `useLayoutEffect` + focus/blur/input event اعمال می‌کند.
- در `App.tsx` یک `MutationObserver` سراسری اضافه شد که هر تغییری در DOM داخل `#cpttf-erp-root-wrap` رخ دهد، تمام `.cptt-input`، `.cptt-select`، `.cptt-textarea`، `.cptt-dp-trigger` را force-style می‌کند. حتی اگر تم/پلاگین `class` یا `style` آن‌ها را عوض کند، بلافاصله بازنویسی می‌شود.
- بازنویسی کامل `components/UI.tsx`: کامپوننت‌های `Input`, `NumberInput`, `Select`, `Textarea`, `SearchBar`, `JalaliDatePicker` همگی از `useForceStyle` استفاده می‌کنند.

### اصلاح NumberInput (تداخل suffix با عدد)
- ورودی عددی الان **راست‌چین** است (مثل سایر ورودی‌ها) با direction RTL.
- suffix («تومان»، «قسط»، …) در سمت چپ pin شده با `position: absolute`.
- `padding-left: 4.5rem` (فضای سخاوتمندانه) تا عدد هرگز زیر suffix نرود.
- suffix از فونت Dana + background سفید + border-radius دارد تا تمیز جدا شود.
- placeholder هم راست‌چین.

### فونت چاپ/PDF/فاکتور (راه‌حل قطعی)
- در نسخه قبل از `document.fonts.ready` استفاده می‌کردیم — اما این promise قبل از دانلود واقعی @font-face جدید resolve می‌شد.
- الان در print window: `Promise.all([fonts.load("400 14px Dana"), fonts.load("500 14px Dana"), fonts.load("700 14px Dana"), fonts.load("400 14px Vazirmatn"), fonts.load("700 14px Vazirmatn")])` فراخوانی می‌شود تا فونت واقعاً دانلود و parse شود، سپس `setTimeout(print, 300)`.
- `font-display: block` (نه swap) تا تا قبل از آماده شدن فونت، چاپ شروع نشود.
- استفاده از فونت‌های محلی پلاگین `assets/fonts/Dana/Dana-FaNum-*.ttf` (آدرس مطلق از `CPTTF_ERP.assets.fontsBase`).
- لیست خانواده فونت در همه CSS: `'Dana','Vazirmatn',Tahoma,sans-serif` (با fallback مناسب).

### فایل‌های جدید/تغییر یافته
- ✨ جدید: `src/utils/forceStyle.ts`
- 🔁 بازنویسی: `src/components/UI.tsx` (همه فرم کنترل‌ها با useForceStyle)
- 🔁 بازنویسی: `src/utils/exporter.ts` (explicit fonts.load قبل از print)
- 🔁 بازنویسی: `src/App.tsx` (MutationObserver سراسری)
- 🔁 بازنویسی: `src/index.css` (override block با ۴۶+ rule)
- ✨ جدید: `assets/finance-ui/fonts.css` (فونت محلی)
- 🔁 `includes/class-cptt-finance-erp.php`:
  - enqueue `cpttf-erp-fonts` با priority بالا
  - اضافه‌شدن `assets.fontsBase` به bootstrap
- 🔁 bump version 7.4.0 → 7.5.0

---

## 🎨 تغییرات نسخه‌ی 7.4.0

## 🎨 تغییرات نسخه‌ی 7.4.0 (اصلاحات UI/UX و قابلیت اعلان‌ها)

### رعایت کامل قوانین
- ❌ هیچ بازطراحی کلی UI انجام نشد
- ❌ هیچ صفحه‌ای حذف نشد / Sidebar دست‌نخورده ماند
- ❌ هیچ Dependency جدید UI نصب نشد
- ✅ همه اصلاحات بر اساس ۵ موردِ گزارش‌شده توسط کاربر روی استایل‌های موجود اعمال شد

### 1️⃣ اصلاح کامل استایل ورودی‌ها (Input)
- گوشه‌های نرم (border-radius: 0.9rem) به جای مستطیل نوک‌تیز
- متن داخل اینپوت‌ها راست‌چین (`direction: rtl; text-align: right`)
- فیلدهای عددی به‌صورت `cptt-input-num` با `text-align: left + direction: ltr` و `tabular-nums`
- فاصله بصری بین عدد و suffix «تومان» با gradient mask روی `cptt-input-suffix`
- فوکوس indigo با ring نرم + hover ظریف

### 2️⃣ بازطراحی استایل جدول‌ها
- wrapper جدید `cptt-table-wrap` با border + shadow ملایم + rounded 1.1rem
- header با gradient (`#f8fafc → #f1f5f9`) و فونت bold و فاصله مناسب
- zebra striping روی ردیف‌های زوج (`#fbfcfd`)
- hover روی ردیف‌ها برای خوانایی بهتر
- spacing و padding یکدست در همه جداول ERP

### 3️⃣ اصلاح کامل اعلان‌ها (Bell Notification)
- دکمه «خواندم» الان درست کار می‌کند با state busy + toast
- آیکن **حذف** (Trash2) برای هر اعلان اضافه شد با تایید کاربر
- endpoint جدید PHP: `cpttf_erp_notif_delete` با `OPT_NOTIFS_DEL` در user_meta
- `build_notifications()` اعلان‌های حذف‌شده را برای کاربر فیلتر می‌کند
- آیکن‌های Check / CheckCheck برای خوانایی بهتر
- dropdown با کلاس جدید `cptt-dropdown` (rounded-xl + shadow)

### 4️⃣ بهبود استایل Dropdown‌ها
- کلاس‌های جدید: `cptt-dropdown`, `cptt-dropdown-header`, `cptt-dropdown-item`
- وضعیت `is-active` با background indigo ملایم
- shadow ظریف + rounded-xl + انیمیشن باز شدن نرم

### 5️⃣ فونت داخلی برای چاپ / PDF / فاکتور / Excel
- بازنویسی `exporter.ts` با ثابت `FONT_CSS` شامل @font-face Dana
- `printReport()` با CSS کامل (A4, header indigo, footer dashed) و `document.fonts.ready.then(() => window.print())`
- `exportPdf()` = `printReport()` (Browser print-to-PDF با فونت آماده)
- `exportExcel()` HTML با فونت Dana inline
- نام شرکت از `window.CPTTF_ERP?.company?.name` خوانده می‌شود

### 6️⃣ تقویم شمسی (Jalali DatePicker)
- استایل جدید `cptt-dp-trigger`, `cptt-dp-panel`, `cptt-dp-grid`, `cptt-dp-cell.is-today / .is-selected`
- روز جاری با حاشیه indigo، روز انتخاب‌شده با background indigo
- نمایش ماه و سال در header قابل کلیک

### فایل‌های تغییر یافته
- `assets/finance-ui/app.css` + `app.js` (rebuild از sources)
- `includes/class-cptt-finance-erp.php` — اضافه شدن `ajax_notif_delete`, `OPT_NOTIFS_DEL` و فیلتر در `build_notifications()`
- `client-project-tracker.php` — bump به 7.4.0

---

## 💼 تغییرات نسخه‌ی 7.3.0 (فاز ۲ توسعه — چک، اقساط، پرداختنی‌ها، اعلان، پیوست)

### 📋 رعایت کامل قوانین
- ❌ هیچ بازطراحی UI انجام نشد
- ❌ هیچ صفحه فعلی حذف نشد
- ❌ Sidebar / Dashboard / Layout دست‌نخورده باقی ماند
- ❌ هیچ کتابخانه UI جدید نصب نشد
- ✅ همه قابلیت‌های جدید با UI Kit موجود (KPI, Modal, Tabs, SearchBar, Pagination, Field, Select, NumberInput, JalaliDatePicker, Toast) ساخته شدند

### 1️⃣ مدیریت چک‌ها
- منوی جدید: **خزانه‌داری → چک‌ها**
- KPI: تعداد دریافتی، تعداد پرداختی، در جریان وصول، سررسید نشده، برگشتی
- ۲ تب: چک‌های دریافتی / چک‌های پرداختی
- فیلترها: وضعیت، بانک، شخص، پروژه، بازه تاریخ سررسید
- ۶ وضعیت دریافتی + ۴ وضعیت پرداختی
- فرم ثبت کامل: شماره، صیادی، بانک، شعبه، مبلغ، تاریخ صدور/سررسید، شخص، پروژه، حساب خزانه، توضیحات، پیوست
- اتوماسیون: ثبت چک → سند JV خودکار / وصول → سند RV + بروزرسانی خزانه / پرداخت → سند PV + برداشت از خزانه

### 2️⃣ اقساط
- منوی جدید: **مطالبات مشتریان → اقساط**
- KPI: اقساط فعال، معوق، سررسید‌شده، وصول‌شده، باقی‌مانده
- فرم برنامه‌ساز: مشتری، پروژه، مبلغ کل، تعداد اقساط، فاصله روز، تاریخ اولین قسط
- تولید خودکار جدول اقساط با محاسبه سررسید‌ها
- وضعیت‌ها: پرداخت‌نشده / پرداخت‌شده / معوق (محاسبه خودکار)
- عملیات دریافت هر قسط با ثبت RV + بروزرسانی خزانه

### 3️⃣ حساب‌های پرداختنی (بدهی‌ها)
- منوی جدید: **خزانه‌داری → بدهی‌ها و پرداختنی‌ها**
- ۴ نوع شخص: کارشناس، پیمانکار، تامین‌کننده، سایر
- KPI: بدهی کل، سررسید گذشته، پرداخت‌شده، تعداد پرونده‌ها
- فیلترها: نوع، وضعیت، پروژه، بازه تاریخ
- فرم: شخص، نوع، مبلغ، تاریخ، سررسید، پروژه، توضیحات
- عملیات: پرداخت کامل / بخشی → ثبت PV + برداشت از خزانه + بروزرسانی صورت‌حساب

### 4️⃣ بودجه و کنترل هزینه (توسعه مراکز هزینه)
- KPI های جدید: بودجه کل، هزینه واقعی، مانده، درصد مصرف
- کارت هر مرکز هزینه: بودجه، مصرف‌شده، مانده، Progress Bar
- هشدار زرد (مصرف > 80٪) و قرمز (> 100٪)
- محاسبه مصرف بر اساس IE با costCenterId

### 5️⃣ سودآوری پروژه (توسعه حساب پروژه‌ها)
- کارت جدید «تحلیل سودآوری» داخل Accordion هر پروژه:
  - درآمد کل، هزینه مستقیم (کارشناسان)، هزینه غیرمستقیم (IE با projectId)
  - سود ناخالص، سود خالص، درصد سود
  - نمودار دوتایی درآمد vs هزینه

### 6️⃣ سن مطالبات (Aging Report)
- منوی جدید: **مطالبات مشتریان → سن مطالبات**
- KPI با ۴ بازه: ۰-۳۰ سبز، ۳۰-۶۰ زرد، ۶۰-۹۰ نارنجی، +۹۰ قرمز
- جدول: مشتری | مانده | سن بدهی | آخرین پرداخت | وضعیت
- Export کامل (Excel/CSV/PDF/Print)

### 7️⃣ پیوست فایل
- کامپوننت جدید `AttachmentsBox` قابل استفاده در:
  - اسناد حسابداری (Vouchers)
  - چک‌ها
  - بدهی‌ها
- آپلود PDF / JPG / PNG / ZIP (حداکثر ۱۰MB)
- نمایش آیکون فایل، گالری، Modal پیش‌نمایش (تصویر + iframe برای PDF)
- ذخیره در WP media library

### 8️⃣ مرکز اعلان مالی
- آیکون 🔔 در **Navbar** با Badge تعداد خوانده‌نشده
- اعلان‌های خودکار:
  - سررسید چک (۷ روز آینده + گذشته)
  - قسط معوق
  - بدهی سررسید‌شده
- Dropdown با لیست + دکمه «خواندم» و «همه را خوانده»
- لینک به صفحه مرتبط

### 9️⃣ داشبورد مالی پیشرفته
- کارت جدید «وضعیت سلامت مالی» با ۵ شاخص:
  - نسبت وصول مطالبات
  - میانگین زمان وصول
  - سود خالص ماه جاری
  - درصد مصرف بودجه
  - بدهی‌های سررسید گذشته

### 🔟 API داخلی کامل
همه قابلیت‌های جدید دارای:
- Endpoint اختصاصی (۱۹ endpoint جدید)
- nonce validation
- capability check
- toast notification
- audit log یکپارچه
- voucher engine (RV/PV/JV خودکار)
- ledger entries در `wp_cptt_fin_ledger`

### 📦 فایل‌های نصب
- `/home/user/client-project-tracker-7.3.0.zip`

## 🚀 تغییرات نسخه‌ی 7.2.0 (فاز ۱ توسعه — موتور سند + Audit + اشخاص + Permissions)

### 📋 قوانین رعایت‌شده
- ❌ هیچ بازطراحی UI انجام نشد
- ❌ هیچ صفحه فعلی حذف نشد
- ❌ Sidebar / Dashboard / Layout دست‌نخورده باقی ماند
- ✅ تمام توسعه‌ها از UI Kit موجود (KPI, Modal, Tabs, SearchBar, Pagination, Field, Select, NumberInput, JalaliDatePicker, Toast) استفاده می‌کنند

### 1️⃣ موتور سند حسابداری خودکار (Accounting Engine)
- **انواع سند**: JV (روزنامه)، RV (دریافت)، PV (پرداخت)، TV (انتقال)، CV (اختتامیه)، OV (افتتاحیه)
- **شماره‌گذاری استاندارد**: `RV-1405-000001`, `PV-1405-000001`, `JV-1405-000001` — جداگانه برای هر نوع و هر سال مالی
- **رویدادهای اتوماتیک**:
  - تسویه کارشناس → PV (سند پرداخت)
  - پرداخت دستی کارشناس → PV
  - دریافت پول از مشتری → آماده RV (مقدمه‌چینی شده)
  - واریز/برداشت/انتقال → آماده RV/PV/TV
- **فیلدهای کامل سند**: voucher_code, voucher_type, voucher_date, fiscal_year_id, status, created_by + ردیف‌های دوبل‌انتری با project_id, customer_id, expert_id

### 2️⃣ توسعه صفحه اسناد حسابداری
- **فیلترهای جدید**: بازه تاریخ شمسی، نوع سند، وضعیت، شماره سند، پروژه، شخص، ثبت‌کننده
- **ستون‌های جدید در جدول**: نوع سند، شخص مرتبط، پروژه، ثبت‌کننده، تاریخ
- **جزئیات سند در Accordion** با Header کامل + Rows + Footer (جمع بدهکار/بستانکار)

### 3️⃣ حساب تفصیلی اشخاص
- **گروه جدید** در کدینگ حساب‌ها: «تفصیلی اشخاص» با Tab مجزا
- زیرمجموعه: مشتریان + کارشناسان
- **ایجاد خودکار** هنگام شناسایی مشتری/کارشناس جدید
- نمایش: کد، نام، نوع، مانده بدهکار، مانده بستانکار، وضعیت

### 4️⃣ صورت‌حساب اشخاص (Party Statement)
- منوی جدید زیر مطالبات: «صورت‌حساب اشخاص»
- **فیلترها**: نوع شخص، شخص، پروژه، بازه زمانی
- **KPI**: تعداد اشخاص، مانده بدهکار، مانده بستانکار، گردش دوره
- **جدول کامل**: تاریخ | سند | شرح | بدهکار | بستانکار | مانده
- نمایش «مانده ابتدای دوره»
- **عملیات**: چاپ، PDF، Excel، CSV
- لینک مستقیم از Receivables: دکمه «صورت‌حساب» در هر گروه مشتری

### 5️⃣ Audit Trail (لاگ حسابرسی)
- زیرمنوی جدید زیر سال مالی: «لاگ حسابرسی»
- ثبت خودکار: ویرایش، حذف، تأیید، پرداخت، انتقال
- ذخیره: کاربر، زمان، نوع عملیات، رکورد، **before/after به JSON**
- **فیلترها**: کاربر، عملیات، تاریخ، موجودیت
- **Modal جزئیات**: نمایش before/after با pretty-print
- جدول دیتابیس جدید: `wp_cptt_erp_audit`

### 6️⃣ توسعه خزانه‌داری
- **فیلترهای جدید روی حساب‌ها**: نوع حساب (بانکی/نقدی)، ارز، فعال/غیرفعال
- **ستون‌های جدید جدول گردش**: شماره سند، مرجع، پروژه، شخص، ثبت‌کننده
- جستجو روی: شرح + شماره سند + پروژه + شخص

### 7️⃣ توسعه داشبورد مالی
- **Tooltip ⓘ** روی KPI ها (mouse hover برای توضیح)
- **نمودار درآمد/هزینه**: انتخاب بازه ماهانه / فصلی / سالانه
- **ستون نوع سند** در تراکنش‌های اخیر (badge RV/PV/...)

### 8️⃣ توسعه گزارشات مالی
- **فیلترهای مشترک** برای همه گزارشات: بازه زمانی، پروژه، مرکز هزینه، شخص، وضعیت سند
- **Export Buttons** (Excel / CSV / PDF / Print) در Header هر گزارش

### 9️⃣ سال مالی — بستن و افتتاح خودکار
- پیام راهنمای واضح در صفحه: «هنگام بستن سال، سند اختتامیه (CV) ایجاد می‌شود، حساب‌های موقت صفر می‌شوند، سود/زیان به سرمایه منتقل می‌شود، سپس سند افتتاحیه (OV) برای سال بعد ایجاد می‌گردد»
- آماده برای endpoint سرور (با fy_close موجود)

### 🔟 سیستم Permissions
- صفحه جدید «سطوح دسترسی» با ماتریس کامل
- **۱۰ مجوز جزئی**: مشاهده اسناد، ثبت سند، تأیید سند، حذف سند، مشاهده گزارشات، مشاهده خزانه، پرداخت، مشاهده اطلاعات مالی پروژه، مشاهده لاگ حسابرسی، مدیریت تنظیمات
- ۴ نقش: مدیرعامل، مدیر مالی، حسابدار، صندوق‌دار
- ذخیره در WP option + سرور-side enforcement
- استفاده در React با `can('voucher_create')` و ...

### 🔧 endpointهای سرور جدید
- `cpttf_erp_vouchers_list` — لیست اسناد با فیلتر
- `cpttf_erp_party_statement` — صورت‌حساب شخص با مانده ابتدای دوره
- `cpttf_erp_audit_list` — لاگ حسابرسی با فیلتر
- `cpttf_erp_permissions_save` — ذخیره ماتریس مجوزها

### 📦 فایل‌های نصب
- `/home/user/client-project-tracker-7.2.0.zip`

## 🎨 تغییرات نسخه‌ی 7.1.0 (بازنویسی کامل UI + تقویم شمسی + رفع باگ‌ها)

### 🐛 رفع باگ‌های گزارش‌شده

**۱) رفع باگ تغییر تب در زیرمنوهای دفاتر و گزارشات**
- علت: `FinancialReports` فقط `initialTab` می‌گرفت و وقتی currentTab تغییر می‌کرد، component re-mount نمی‌شد
- رفع: در `App.tsx` با `key={currentTab}` کامپوننت forced re-mount می‌شود
- علاوه بر این: tab switching داخلی هم اضافه شد (در تب fileancialReports یک Tabs component با ۶ گزینه است که local هم سوییچ می‌کند)

**۲) بازنویسی کامل UI Kit (رفع استایل خراب)**
- `index.css` با reset کم‌تهاجمی (فقط input focus rings و number spinners reset می‌شوند)
- کلاس‌های `cptt-*` به‌صورت pure CSS (نه @apply) نوشته شدند که با Tailwind v4 ۱۰۰٪ سازگارند
- تمام input/select/textarea/button با استایل یکپارچه و focus ring indigo
- modal با backdrop-blur، animation zoom، scroll اتوماتیک
- table classes (`cptt-table`) با hover effect

### 🗓 تقویم شمسی واقعی همه‌جا
- یک `JalaliDatePicker` کامل native ساخته شد در `components/UI.tsx`:
  - تبدیل دقیق Gregorian↔Jalali بدون وابستگی خارجی
  - ۳ view: روز، ماه، سال
  - دکمه‌های «امروز» و «پاک کردن»
  - rtl + Dana font + ارقام فارسی خودکار
  - hover/active states زیبا
- استفاده در:
  - ثبت تراکنش جدید (IncomeExpense)
  - ثبت سند جدید (Vouchers)
  - سال مالی جدید (FiscalYear)
  - قفل دوره (FiscalYear)
- تابع‌های utils/jalali.ts: `toFa`, `toEn`, `formatJalali`, `todayJalali`,
  `gregorianToJalali`, `jalaliToGregorian`, `daysInJalaliMonth`, `isLeapJalali`
- تمام تاریخ‌های نمایش‌داده‌شده با `toFa()` به ارقام فارسی تبدیل می‌شوند

### 🗑 حذف کامل mock data
- تمام آرایه‌های mock در AppContext پاک شدند
- تمام ارجاع‌های به «بانک ملی»، «شرکت فناوری هماهنگ»، «۷۵۰۰۰۰۰۰۰» و … حذف شدند
- داده‌ها فقط از سرور می‌آیند (در حالت WP) یا empty (در standalone)
- mock fiscal year ها، سندهای فیک، کارشناسان نمونه — همه پاک شدند

### 📃 امکانات کاربر-پسند جدید
- **Tabs component** هماهنگ: استفاده در Categories, CostCenters, FinancialReports
- **Pagination** در تمام جدول‌های بزرگ
- **SearchBar** هماهنگ
- **KPI cards** با hover-shadow
- **Empty state** زیبا با آیکون
- **Toast notifications** پایین-چپ با animation
- **Modal** با animation slide-in + zoom

### 🔗 یکپارچگی کامل با هسته پلاگین
- AppContext.formatMoney متمرکز و respect activeCurrency
- todayJalali در Context: همه‌جا قابل دسترس برای datepicker defaults
- تمام عملیات write با toast feedback (موفقیت/خطا)
- تمام اسکیما با هسته پلاگین سازگار:
  - تسویه کارشناس → `_cptt_steps` + hooks
  - دریافت پول → توزیع FIFO + ledger + Bale
  - ادیت مالی مرحله → `_cptt_steps` مستقیم
  - toggle تسویه پروژه → `_cptt_is_settled`

### 📄 فایل مستندات FEATURES.txt
یک فایل کامل ۵۰۰+ خطی در root پلاگین (`FEATURES.txt`) که حاوی:
- شرح تفصیلی ۱۳ ماژول
- لیست تمام عملیات هر بخش
- اتوماسیون و یکپارچگی
- ۲۸ endpoint سرور
- ⚠️ کاستی‌ها و نیازهای توسعه (در ۹ گروه: حسابداری استاندارد، گزارش‌گیری،
  تراکنش‌ها، یکپارچگی هسته، مالیات، کاربران، UX، اتوماسیون، موبایل، مالی پیشرفته)

این فایل را می‌توانید به یک هوش مصنوعی بدهید تا پیشنهادات توسعه ارائه دهد.

## 🎨 تغییرات نسخه‌ی 7.0.0 (UI Kit حرفه‌ای + Toastها + عملیات واقعی کامل)

### 🎨 UI Kit کامل و reset جامع
یک **سیستم طراحی یکپارچه** (`#cpttf-erp-root-wrap` scoped) ساخته شد تا تمام فیلدها، دکمه‌ها، select ها، textarea ها و modal ها در سراسر پنل از یک منبع استفاده کنند:
- **Reset CSS قوی**: همه‌ی فیلدهای native (input/select/textarea/button) با scope به `#cpttf-erp-root-wrap` reset می‌شوند تا استایل وردپرس کاملاً برداشته شود
- **کلاس‌های `cptt-*`**:
  - `.cptt-input` — فیلد input یکپارچه با hover/focus state
  - `.cptt-select` — select با chevron سفارشی SVG و padding مناسب
  - `.cptt-textarea` — textarea با resize عمودی و focus ring indigo
  - `.cptt-label` — لیبل bold یکپارچه
  - `.cptt-btn-primary/success/danger/warning/ghost/outline` — ۶ نوع دکمه با hover-shadow و active-scale
  - `.cptt-btn-sm` — اندازه‌ی کوچک‌تر
  - `.cptt-pill-success/danger/warning/info/neutral` — badge های pill
  - `.cptt-card` — کارت یکپارچه با shadow و border
  - `.cptt-modal-overlay/content` — modal با backdrop-blur و animation
- **کامپوننت‌های مشترک** در `components/UI.tsx`:
  - `<Input>`, `<NumberInput>` (با فرمت ۱۲۳,۴۵۶ خودکار)، `<Select>`, `<Textarea>`, `<Field>` (لیبل+hint+error)
  - `<Button>`, `<IconButton>` با ۶ variant
  - `<Modal>` با header sticky و footer
  - `<PageHeader>`, `<Empty>`, `<Pagination>`, `<Tabs>`, `<SearchBar>`, `<KPI>`
  - `<ToastHost>` و API ساده `toast.success/error/info(msg)`
- تمام input/select/textareaهای کامپوننت‌های قدیمی با regex به `cptt-input`/`cptt-select` migrate شدند

### 🔔 سیستم Toast
- هر عملیات موفق (ثبت سند، تسویه کارشناس، دریافت پول، ساخت دسته، انتقال، ...) **یک toast سبز** در گوشه پایین‌چپ نمایش می‌دهد
- هر خطا یک toast قرمز با پیام دقیق نمایش می‌دهد
- در AppContext: تمام wrapperهای mutation اکنون پس از موفقیت یا خطا toast می‌زنند

### 🔗 عملیات واقعی کامل (نه نمایشی)

#### دکمه «دریافت پول از مشتری» (Quick Pay)
در پنل **حساب پروژه‌ها** و **مطالبات مشتریان**:
- روی هر پرونده یک دکمه‌ی سبز `💰` می‌بینید (وقتی پروژه مانده دارد)
- باز شدن مودال با: مبلغ، حساب دریافت، توضیحات
- اتوماسیون کامل:
  - مبلغ روی مراحل پروژه به‌صورت FIFO توزیع می‌شود (همان منطق `ajax_quick_pay` کلاسیک)
  - یک ledger entry در `wp_cptt_fin_ledger` با direction +1 ثبت می‌شود
  - **نوتیفیکیشن بله به مشتری** ارسال می‌شود (اگر CPTT_Bale فعال باشد)
  - `CPTT_Core::activity_log` ثبت می‌کند
  - داشبورد BI، Receivables، ProjectAccounting، Treasury، Ledger همگی refresh می‌شوند

#### دکمه «علامت تسویه‌شده/بازگشت»
روی هر پروژه می‌توانید مستقیماً وضعیت `_cptt_is_settled` را toggle کنید (همان رفتار checkbox کلاسیک)

#### ادیت inline مالی مرحله
روی هر ردیف مرحله در ProjectAccounting، یک دکمه‌ی «ویرایش» اضافه شد که modal ادیت با ۴ فیلد:
- هزینه (فاکتور مشتری)
- پرداخت‌شده از مشتری
- سهم کارشناس
- پرداختی به کارشناس

با ذخیره، `wp_cptt_fin_erp_project_step_update` صدا می‌شود و فیلد `_cptt_steps` آپدیت می‌شود.

### 🔁 اتوماسیون با هسته‌ی پلاگین
- وقتی کارشناس از داشبورد خود تسویه می‌زند (یا ادمین از پنل کلاسیک)، در ERP بازتاب پیدا می‌کند
- وقتی فیلد مالی پروژه در پیشخوان وردپرس edit شود، با refresh پنل نمایش می‌آید
- وقتی از پنل ERP عملیاتی انجام می‌دهید، هوک‌های موجود (`cptt_after_expert_payout`, `cptt_after_manual_expert_payment`) trigger می‌شوند پس بله و activity_log درست کار می‌کنند

### 📦 endpointهای جدید
- `cpttf_erp_project_quick_pay` — دریافت سریع از مشتری روی پروژه + توزیع FIFO + ledger + Bale + activity log
- `cpttf_erp_project_settle` — toggle تسویه‌شده پروژه

### 🛠 بهبودهای فنی
- فایل `.bak` Phase 1 پاک شد
- در صورت تنهایی `treasury` خالی، Treasury حالا empty-state حرفه‌ای نمایش می‌دهد بجای کرش
- focus ring indigo یکپارچه روی همه‌ی فیلدها
- placeholderها رنگ یکسان slate-400

## 🚀 تغییرات نسخه‌ی 6.9.0 (بازنویسی داشبورد + رفع کامل mock + Pagination + Accordion)

### 🗑 حذف کامل اطلاعات فیک
تمام داده‌های فیک باقی‌مانده در کامپوننت‌ها حذف شدند:
- **DashboardBI**: آرایه‌ی `monthlyData` با ۶ ماه داده‌ی نمونه و `totalReceivables = 750000000` فیک حذف شدند. اکنون از سرور `monthlySeries`, `topCustomers`, `categoryBreakdownExpense/Income`, `kpis` می‌خواند.
- **Treasury**: `runningBalance = balance * 0.5` (موجودی افتتاحیه فیک)، تاریخ فیک `۱۴۰۵/۰۱/۰۱` و entry افتتاحیه ساختگی حذف شدند. اکنون از ledger واقعی + تاریخچه تسویه‌ها استفاده می‌کند.
- **FinancialReports**: `openingBalance = acc.balance * 0.4` فیک حذف شد و به‌جای آن از فرمول‌ی واقعی (current balance منهای net delta) محاسبه می‌شود.
- فایل `.bak` بدون استفاده از Phase 1 پاک شد.

### 🐛 رفع باگ صفحه سفید روی کلیک پروژه
علت: `Treasury` و `ProjectAccounting` با fallback `treasuryAccounts[0]` و دسترسی به `selectedAccount.balance` کرش می‌کردند وقتی state حالت اولیه `selectedAccId = 't1'` بود اما `t1` در داده‌ی واقعی نبود.
**رفع شد** با:
- `useEffect` که اولین حساب موجود را خودکار انتخاب می‌کند
- `Number(st?.cost || 0)` و null-safety در همه‌ی فیلدهای step
- بررسی `Array.isArray(p.steps)` قبل از iteration
- empty-state حرفه‌ای وقتی هیچ حسابی موجود نیست

### 🎨 استایل کامل با UI Kit جدید
تمام مدال‌ها، فرم‌ها، دکمه‌ها و فیلدها در `Treasury` بازنویسی شدند با کامپوننت‌های ماژولار Tailwind:
- `Modal`, `Field`, `Select`, `AmountInput`, `TextInput`, `FormButtons` — همگی با glass+shadow+rounded-2xl یکپارچه با UI
- بدون هیچ HTML/CSS وردپرس
- جداول با header sticky-friendly و hover effects هماهنگ

### 🎯 تکمیل قابلیت‌های داشبورد مالی پلاگین در پنل
موارد جدیدی که از داشبورد قدیمی به پنل ERP منتقل شدند:
- ✅ نمودار میله‌ای ۶ ماه اخیر (درآمد vs هزینه) با tooltip
- ✅ دو دونات «ترکیب درآمدها» و «ترکیب هزینه‌ها» با SVG واقعی و legend
- ✅ نمودار افقی «برترین مشتریان بر اساس درآمد» با progress bar
- ✅ ۸ KPI کارت: درآمد دریافتی، مجموع فاکتورها، مطالبات باز، تسویه کارشناسان، سود ناخالص، موجودی خزانه، پروژه‌های باز/تسویه
- ✅ «آخرین تراکنش‌های مالی» (۸ مورد آخر)
- ✅ «آخرین تسویه‌های کارشناسان» (۵ مورد آخر)
- ✅ هشدار قرمز «مطالبات وصول‌نشده» در پایین داشبورد

### 📃 صفحه‌بندی (Pagination) برای صفحات شلوغ
- **ProjectAccounting**: ۱۰ پروژه در هر صفحه + sort by date/cost/remain/profit
- **Receivables**: ۸ مشتری در هر صفحه + فیلتر min remain
- **Ledger**: ۲۰ تراکنش در هر صفحه
- **Treasury** (turnover hesab): ۱۵ تراکنش در هر صفحه

### 🔍 فیلتر و جستجوی پیشرفته
- DashboardBI: جستجو در تراکنش‌ها + فیلتر بازه‌ی زمانی (همه/۳۰ روز/۳ ماه/۶ ماه/یک سال)
- ProjectAccounting: جستجوی متنی + 3 فیلتر وضعیت + sort چندگانه + toggle مخفی‌سازی پروژه‌های صفر
- Receivables: جستجو + 3 فیلتر وضعیت + dropdown «حداقل مانده»
- Ledger: جستجو + فیلتر حساب + فیلتر نوع (ورودی/خروجی)
- Treasury: جستجو در گردش حساب انتخاب‌شده

### 🧱 معماری
- `formatMoney` و `currencySymbol` در Context = source of truth واحد
- `kpis`, `monthlySeries`, `topCustomers`, `categoryBreakdownExpense/Income` از سرور در bootstrap می‌آیند
- `ajax_full_bootstrap` در PHP حالا این ۴ مورد را علاوه بر داده‌های قبلی برمی‌گرداند

## 🎯 تغییرات نسخه‌ی 6.8.0 (ادغام کامل + رفع باگ ارز + گردش حساب یکپارچه)

### 🐛 رفع باگ‌های گزارش‌شده
- **ارز در پنل ERP کار می‌کرد ولی کامپوننت‌های جدید (Receivables, ProjectAccounting, Categories, Ledger) آن را اعمال نمی‌کردند** → یک helper متمرکز `formatMoney()` به Context اضافه شد که `activeCurrency` را می‌خواند. تمام کامپوننت‌های قدیمی و جدید حالا با تغییر ارز در Navbar به‌صورت زنده تبدیل می‌شوند (TOMAN، RIAL، USD، EUR).
- **«۹ پروژه با ۰ هزینه و مجموع 52 میلیون»**: این به‌خاطر `extra_finance` (فاکتورهای تکمیلی) بود که در پلاگین وجود دارد و در ستون اصلی cost دیده نمی‌شدند. حالا:
  - KPI «مجموع فاکتورها» یک hint اضافی نشان می‌دهد: «شامل X تومان فاکتور تکمیلی»
  - جدول مراحل، در ستون پرداخت‌شده، یک خط `+ X تومان تکمیلی` برای مرحله‌هایی که `extra_finance` دارند نشان می‌دهد
  - فیلتر جدید «مخفی کردن پروژه‌های صفر» اضافه شد تا کاربر فقط پروژه‌های فعال را ببیند

### 🆕 ماژول جدید: گردش حساب (Ledger)
ماژول کامل **گردش حساب یکپارچه** که تراکنش‌ها را از سه منبع جمع‌آوری می‌کند:
- ثبت‌های درآمد/هزینه (از `wp_cptt_fin_ledger`)
- تسویه‌های کارشناسان (از `wp_cptt_ledger`)
- انتقال‌های خزانه

ویژگی‌ها:
- KPI: مجموع ورودی، خروجی، مانده‌ی خالص، تعداد رکورد
- فیلتر بر اساس حساب، نوع (ورودی/خروجی)، جستجوی متنی
- نمایش جدولی با footer که جمع کل را با فیلتر فعال نشان می‌دهد
- مسیر: **خزانه‌داری → گردش حساب (یکپارچه)**

### 🧱 بهبود معماری
- `formatMoney` و `currencySymbol` به Context منتقل شدند تا منبع حقیقت واحد داشته باشیم
- ProjectAccounting حالا فیلد‌های `base_cost`, `base_paid`, `extra_cost`, `extra_paid` را دریافت می‌کند تا breakdown دقیق نشان دهد
- مجموع `totalExtraCost` و `totalExtraPaid` در سطح پروژه برای ساخت KPI

### 🔁 یکپارچگی و اتوماسیون
- وقتی در «درآمد/هزینه» چیزی ثبت می‌کنید، **بلافاصله در گردش حساب** و **داشبورد BI** و **خزانه‌داری** نمایش داده می‌شود (همه از one source of truth می‌خوانند)
- وقتی در «تسویه کارشناسان» تسویه می‌زنید، **در گردش حساب**، **در حساب پروژه‌ها** (به‌عنوان `expert_paid`)، و **در تاریخچه تسویه‌ها** همگام می‌شود
- وقتی در «دسته‌بندی‌ها» دسته‌ای می‌سازید، **در فرم درآمد/هزینه** بلافاصله در dropdown قابل انتخاب می‌شود

## 🛠 تغییرات نسخه‌ی 6.7.1 (رفع باگ خراب شدن UI پنل ERP)

### 🐛 رفع باگ‌های 6.7.0
- **علت اصلی**: استایل پلاگین (`assets/css/finance.css`) به‌خاطر شرط `strpos($hook, 'cptt-finance') === false` در صفحه‌ی `cptt-finance-erp` هم enqueue می‌شد و با کلاس‌های Tailwind v4 تداخل می‌کرد → رفع شد با early-return روی این page.
- **`position: fixed; inset: 0`** روی `#cpttf-erp-root-wrap` با layout `flex h-screen` داخل React تداخل داشت → برداشته شد. حالا root mount در flow عادی است با `min-height: 100vh`.
- **`ob_clean()` در `render_page`** خروجی legitim WP را می‌توانست خراب کند → حذف شد.
- **CSS سراسری `html, body { margin:0 !important; padding:0 !important }`** و گزینه‌های `.notice/.error/.updated` (که با کلاس‌های utility Tailwind مثل `.notice-success-bg` و … تداخل بالقوه داشتند) **اسکوپ شدند** فقط به `body.cpttf-erp-chromeless > .notice` و `#wpbody-content > .notice`.

### 🆕 ایزولاسیون قوی‌تر assetها
- یک متد جدید `dequeue_3rd_party_assets()` در صفحه ERP اضافه شد که با priority 9999:
  - **همه‌ی style‌های ثبت‌شده توسط پلاگین‌های دیگر را dequeue می‌کند** (allowlist فقط `cpttf-erp-*`, `admin-bar`, `common`)
  - **همه‌ی script‌های ثبت‌شده توسط پلاگین‌های دیگر را dequeue می‌کند** (allowlist + `jquery`)
- نتیجه: فقط Tailwind/React بسته‌ی ما در پنل لود می‌شود، هیچ CSS تداخلی از پلاگین‌های دیگر باقی نمی‌ماند.

### حذف نشدنی‌ها
- تمام عملکرد و endpointهای v6.7.0 دست‌نخورده باقی ماندند (`receivables`, `financeCategories`, `projectsFull`, و …).
- React bundle بدون تغییر است (نیازی به rebuild نبود — فقط مشکلات سرور بود).

## ✨ تغییرات نسخه‌ی 6.7.0 (🚀 پنل ERP خودکفا + Fullscreen کامل)

### 🖥 حالت Fullscreen واقعی
صفحه‌ی پنل ERP اکنون **به‌طور کامل** chrome وردپرس را حذف می‌کند:
- ❌ نوار ابزار بالای وردپرس (admin bar) مخفی
- ❌ منوی کناری پیشخوان مخفی
- ❌ نوتیفیکیشن‌ها و خطاهای پلاگین‌های دیگر مخفی (`admin_notices`, `all_admin_notices` کلاً remove می‌شوند)
- ❌ خطاهای PHP در خروجی نمایش داده نمی‌شوند (با `display_errors = 0` لوکال این صفحه)
- ❌ Footer وردپرس، Screen Options، Contextual Help مخفی
- ❌ Output buffer هر noise قبلی را قبل از render پاک می‌کند
- ✅ پنل ERP در `position: fixed` با `inset: 0` و `z-index: 99999` کل viewport را پوشش می‌دهد
- نتیجه: تجربه‌ای کاملاً مشابه یک SaaS مستقل، بدون هیچ ردپایی از وردپرس

### 🔙 دکمه «رفتن به پیشخوان»
به انتهای منوی پنل ERP یک دکمه‌ی emerald-colored اضافه شد که کاربر را به `/wp-admin/` برمی‌گرداند.

### 🧹 حذف Welcome Alert
کادر «به پیش‌نمایش پلتفرم جامع حسابداری هماهنگ ERP خوش آمدید!» در داشبورد به‌طور کامل حذف شد.

### 🗑 پاکسازی mock data
- تمام آرایه‌های اولیه‌ی mock در `AppContext.tsx` با `[]` جایگزین شدند
- شرکت‌های فیک («شرکت فناوری هماهنگ پارس»، «آژانس توسعه نرم‌افزار نوین» و …) حذف
- شعبه‌ها، حساب‌های فیک، کارشناسان مثال (علیرضا رضایی، سارا احمدی، …)، سندهای فیک، تراکنش‌های نمونه — **همگی حذف شدند**
- در محیط WP، هر چه نمایش داده می‌شود از داده‌ی واقعی پلاگین می‌آید؛ در محیط standalone هم آرایه‌ها خالی‌اند

### ➕ سه ماژول جدید مالی (تلفیق کامل با پنل ERP)
حالا تمام امکانات مالی پلاگین در همین پنل قرار دارند و دیگر نیازی به رفتن به منوی Finance قدیمی نیست:

#### 1) **مطالبات مشتریان** (`Receivables`)
- KPI: مانده‌ی کل دریافتنی، تعداد پرونده‌های باز، مجموع فاکتورها، نرخ وصول
- فیلتر: همه / با مانده باز / تسویه‌شده
- جستجو بر اساس نام مشتری یا پروژه
- گروه‌بندی خودکار بر اساس مشتری + هشدار نقش mis-assigned
- لینک مستقیم به ویرایش پروژه در پیشخوان

#### 2) **دسته‌بندی‌های مالی** (`Categories`)
- مدیریت کامل دسته‌های `income` و `expense`
- 12 رنگ palette + 23 آیکون آماده
- ذخیره‌ی واقعی در `wp_cptt_fin_categories`
- تب‌بندی درآمد/هزینه با شمارش پویا

#### 3) **حساب پروژه‌ها** (`ProjectAccounting`)
- KPI: تعداد پروژه‌ها، مجموع فاکتورها، دریافتی، دستمزد کارشناسان، سود ناخالص
- تجمیع چندسطحی: هر پروژه → مراحل → جزئیات مالی هر مرحله
- جدول مراحل با: هزینه، پرداخت‌شده، مانده مشتری، سهم کارشناس، پرداختی کارشناس، وضعیت
- لینک مستقیم به ویرایش پروژه در پیشخوان

### 🧱 معماری جدید
- داده‌های جدید در bootstrap: `receivables`, `financeCategories`, `projectsFull`
- Endpoints جدید سرور:
  - `cpttf_erp_fincat_save` / `cpttf_erp_fincat_delete` — CRUD دسته‌بندی
  - `cpttf_erp_project_step_update` — ویرایش inline فیلدهای مالی مرحله
- Sidebar بازطراحی شد: حذف WordPress Dev (غیر مفید در محیط WP)، اضافه کردن سه ماژول جدید
- Bundle size بدون تغییر معنادار (419KB JS، 63KB CSS)

### 🔒 ایزولاسیون از خطاهای پلاگین‌های دیگر
- `remove_all_actions('admin_notices')` و سه نوع دیگر notice روی این صفحه فقط
- Output buffer + regex برای پاک کردن HTML notice که قبل از hook ما print شده
- این یعنی اگر پلاگین‌های دیگری روی سایت خطا دارند، پنل ERP تمیز و بدون مزاحمت نمایش داده می‌شود

## ✨ تغییرات نسخه‌ی 6.6.0 (🔗 اتصال داده زنده پنل ERP)

### 🎯 Phase 2 — Live Data Bridge
پنل حسابداری ERP که در نسخه ۶.۵.۰ به‌صورت بصری اضافه شد، اکنون با **داده‌های واقعی پلاگین** ادغام کامل شد. تمام داشبوردها، جدول‌ها و عملیات مالی روی داده‌های زنده‌ی WordPress کار می‌کنند و هیچ mock data ای دیده نمی‌شود.

#### چه چیزی زنده شد
- 💼 **کارشناسان**: لیست واقعی کاربران با نقش `cptt_expert` + جمع مطالبات و تسویه‌های انجام‌شده از `_cptt_steps`
- 🧾 **مراحل قابل تسویه**: مراحل پروژه‌های واقعی که `exp_to_expert > expert_paid` در پنل تسویه نمایش داده می‌شوند
- 🏦 **خزانه‌داری**: حساب‌های بانکی و صندوق‌ها از `wp_cptt_fin_accounts` با موجودی واقعی (محاسبه‌شده از ledger)
- 💸 **درآمد/هزینه**: ثبت‌های مالی از `wp_cptt_fin_ledger`
- 📜 **تاریخچه تسویه**: از جدول `wp_cptt_ledger` برای `expert_payout` و `expert_manual_payout`
- 📊 **KPIها و شمارش‌ها**: واقعی از `CPTT_Finance::compute_kpis()`
- 👤 **نقش کاربر**: از role وردپرس (administrator → ceo، cptt_expert → accountant)

#### عملیات (CRUD) که با پلاگین واقعی sync می‌شوند
- ✅ **تسویه چندمرحله‌ای کارشناس** → روی `_cptt_steps` اعمال + هوک `cptt_after_expert_payout` (مثل مسیر کلاسیک)
- ✅ **پرداخت دستی به کارشناس** → CPTT_Core::ledger_add + هوک `cptt_after_manual_expert_payment` + ارسال بله
- ✅ **ثبت درآمد/هزینه** → wp_cptt_fin_ledger
- ✅ **ساخت/ویرایش/حذف حساب خزانه** → wp_cptt_fin_accounts
- ✅ **انتقال بین حساب‌ها** → ledger دوتایی transfer_in/transfer_out با linked_id
- ✅ **واریز/برداشت دستی** → ledger insert
- ✅ **اسناد حسابداری** (Vouchers) — ذخیره در options با شماره‌گذاری خودکار + گردش کار تأیید (DRAFT → ACCOUNTANT_APPROVED → MANAGER_APPROVED → FINALIZED)
- ✅ **کدینگ حساب‌ها** (Chart of Accounts) — درختی چهارسطحی در options
- ✅ **مراکز هزینه/درآمد** — در options
- ✅ **سال‌های مالی** — ایجاد و بستن
- ✅ **قفل دوره مالی** — toggle

#### معماری Bridge
- `assets/finance-ui/app.js` و `app.css` بازساخت شدند: `AppContext.tsx` اکنون با `isWP()` تشخیص می‌دهد در محیط وردپرس قرار دارد و یک‌بار از `cpttf_erp_full_bootstrap` همه‌ی state را hydrate می‌کند
- splash loader تا hydration کامل، سپس UI کامل با داده‌های واقعی
- هر mutation در React → optimistic update + async wpApi.* → reload از سرور برای consistency
- اگر AJAX fail شود، automatic fallback به mock data (No-WP mode هنوز کار می‌کند برای توسعه)

#### Endpoints جدید (WP AJAX)
همگی با nonce: `cptt_finance_erp_nonce` و capability `edit_cptt_projects`
- **READ**: `cpttf_erp_full_bootstrap` (همه چیز یک‌جا)، `cpttf_erp_kpis`، `cpttf_erp_accounts`، `cpttf_erp_ledger`
- **VOUCHERS**: `cpttf_erp_voucher_save`، `_approve`، `_delete`
- **COA**: `cpttf_erp_coa_save`، `_delete`
- **TREASURY**: `cpttf_erp_treasury_save`، `_delete`، `_transfer`، `_deposit`، `_withdraw`
- **SETTLEMENTS**: `cpttf_erp_settle_steps`، `cpttf_erp_settle_manual`
- **INCOME/EXPENSE**: `cpttf_erp_ie_save`، `_delete`
- **COST CENTERS**: `cpttf_erp_cc_save`، `_delete`
- **FISCAL YEARS**: `cpttf_erp_fy_create`، `cpttf_erp_fy_close`
- **LOCKS**: `cpttf_erp_lock_toggle`

#### پیشرفت‌های اضافه
- 🔄 رفتار تسویه از پنل ERP **دقیقا همان hookها و notif بله را تریگر می‌کند** که مسیر کلاسیک تریگر می‌کند، پس automation موجود (Bale, activity_log, ledger) بدون تغییر کار می‌کند
- 🏷 دسته‌بندی‌های `incomes/expenses` به‌صورت پویا از `CPTT_Finance::get_categories()` در پنل ERP دیده می‌شوند
- 🔐 نام شرکت پیش‌فرض از `bloginfo('name')`
- 📐 backward compatibility: تمام صفحات و عملکردهای مالی قبلی (دشبورد مالی، مطالبات، تسویه کلاسیک، خزانه‌داری، حساب پروژه‌ها کلاسیک، گردش حساب) بدون تغییر کار می‌کنند

## ✨ تغییرات نسخه‌ی 6.5.0 (⚡ پنل حسابداری ERP حرفه‌ای)

### 🎉 ادغام React+Tailwind UI کامل
رابط کاربری حرفه‌ای حسابداری از مخزن [mrsaadati82-code/mali](https://github.com/mrsaadati82-code/mali) به‌صورت کامل با پلاگین تلفیق شد. این یک **پلتفرم ERP پروژه‌محور** کامل با تمام ماژول‌های حسابداری دوبل‌انتری است.

#### ماژول‌های موجود در پنل ERP
- 📊 **داشبورد BI** — KPI ها، نمودارهای تعاملی، خلاصه فعالیت‌ها
- 📒 **کدینگ حساب‌ها** (Chart of Accounts) — درختی، چهار‌سطحی (گروه/کل/معین/تفصیلی)
- 📝 **اسناد حسابداری** (Vouchers) — دوبل‌انتری، گردش کار تأیید چندمرحله‌ای
- 📑 **گزارش‌های مالی** — دفتر روزنامه، دفتر کل، معین، تراز آزمایشی، صورت سود و زیان، ترازنامه
- 💼 **تسویه کارشناسان** — یکپارچه با مراحل پروژه‌های هماهنگ
- 💸 **درآمد و هزینه** — ثبت سریع چندارزه
- 🏦 **خزانه‌داری** — حساب‌های بانکی/نقدی، انتقال
- 🎯 **مراکز هزینه/درآمد** — پروژه/تیم/شعبه
- 📅 **سال مالی** — بستن سال، انتقال مانده
- 🔐 **نقش‌های کاربری** — صندوق‌دار، حسابدار، مدیر مالی، مدیرعامل
- 🌐 **چندشرکتی و چندشعبه‌ای**
- 💱 **چندارزی** (تومان، ریال، دلار، یورو)
- ⌨️ **Command Palette** با `Ctrl+K`
- 🌙 **حالت تاریک/روشن**

#### معماری
- یک کلاس جدید `CPTT_Finance_ERP` که bundle ساخته‌شده (React 19 + Tailwind v4 + TS) را در یک صفحه‌ی مستقل WordPress mount می‌کند
- مسیر دسترسی: **پیشخوان ← 💰 مالی ← ⚡ پنل ERP جدید**
- لینک promo در داشبورد مالی به این پنل
- صفحه‌ی fullscreen با hide کردن chrome وردپرس
- bootstrap data از WP به React تزریق می‌شود (`window.CPTTF_ERP`)
- ۴ AJAX endpoint اولیه برای bridge داده‌های واقعی (kpis, accounts, ledger, bootstrap)

#### وضعیت فعلی (Phase 1)
- **UI کامل** و آماده‌ی استفاده با sample data از خود کامپوننت‌ها
- **bridge داده‌های واقعی** آغاز شده — در فازهای بعدی mock data جای خود را به داده‌های زنده پلاگین می‌دهد
- منوی مالی فعلی، تب‌های قدیمی و صفحه‌ی «حساب پروژه‌ها» همگی دست‌نخورده باقی ماندند

---

## ✨ تغییرات نسخه‌ی 6.4.1 (🔧 یکپارچه‌سازی و بهبود زیرسیستم مالی)

### 🔄 یکپارچه‌سازی «حساب و کتاب» در منوی مالی
- صفحه‌ی «حساب و کتاب» از منوی پروژه‌ها **حذف شد** و به منوی **💰 مالی** منتقل شد با نام **📒 حساب پروژه‌ها**
- نمای کلاسیک حفظ شد ولی داخل shell جدید Finance با تب‌های هماهنگ
- صفحه‌ی جدید **📑 گردش حساب** که سراسری یا فیلتر بر اساس حساب کار می‌کند

### 🐛 رفع باگ‌های گزارش‌شده
1. **دکمه «گردش حساب» در خزانه‌داری** — حالا به صفحه مستقل `cptt-finance-ledger` می‌رود (نه redirect به خود treasury)
2. **نام مشتری vs کارشناس در مطالبات** — اگر کاربر assigned به‌عنوان مشتری نقش `cptt_expert` داشت، یک هشدار `⚠ (نقش کارشناس)` کنار نامش نمایش داده می‌شود تا داده‌ی mis-assigned مشخص شود
3. **داده‌های پروژه‌ها در نمودار** — `monthly_series()` حالا **ترکیبی** از لجر مالی + داده‌های پروژه‌ها (paid → income، expert_paid → expense) را نمایش می‌دهد

### 📊 نمودارهای متعدد در داشبورد مالی
به‌جای یک نمودار، **۵ نمودار** اضافه شد:
- 📈 **میله‌ای ۶ ماه اخیر** — درآمد + هزینه (با ماه‌های شمسی)
- 🍩 **donut ترکیب درآمد** — دریافت‌شده vs مطالبات
- 🍩 **donut توزیع سود** — سود ناخالص شما vs پرداخت به کارشناسان
- 🏆 **horizontal bar پرفروش‌ترین مشتریان** (top 6)
- 🍩 **donut هزینه‌ها بر اساس دسته**

همه با Canvas خالص و responsive.

### 📅 تقویم شمسی برای فیلدهای تاریخ
- یک Jalali datepicker سبک inline که خودکار به این فیلدها متصل می‌شود:
  - `input[name="date_local"]` در modal تراکنش‌ها و انتقال‌ها
  - `input[name="ffrom|fto|from|to"]` در همه‌ی فیلترها
  - هر فیلد با کلاس `.cpttf-jdate`
- نمایش ماه‌های فارسی، اعداد فارسی، هفته از شنبه شروع می‌شود
- دکمه «امروز» و navigation ماه قبل/بعد

### ⚡ helper جدید `account_balance_at($id, $ts)`
برای محاسبه‌ی running balance دقیق در صفحه گردش حساب

---

## ✨ تغییرات نسخه‌ی 6.4.0 (🎉 زیرسیستم مالی مدرن — Phase 1)

### معماری جدید
هیچ قابلیت قبلی حذف نشد. صفحه‌ی «حساب و کتاب» موجود (در زیرمنوی پروژه‌ها) دست‌نخورده باقی ماند. در کنار آن، **یک پلتفرم مالی کامل، ماژولار و مستقل** اضافه شد:

- منوی جداگانه‌ی **💰 مالی** در پیشخوان وردپرس
- هر ماژول صفحه‌ی مستقل + URL مستقل + ناوبری تب‌محور سبک
- طراحی **Mobile First** + **SaaS-style** + کارت‌های KPI + نمودارهای تعاملی
- ۳ جدول جدید DB (`wp_cptt_fin_accounts`, `wp_cptt_fin_categories`, `wp_cptt_fin_ledger`)
- لجر واحد + auto-hooks: تسویه‌های موجود و پرداخت‌های دستی **خودکار** در لجر ثبت می‌شوند

### ماژول‌های فاز ۱

#### 📊 1) داشبورد مالی
- KPI Cards: درآمد دریافتی، مطالبات، تسویه کارشناسان، سود ناخالص، صورتحساب، تعداد پروژه‌ها
- نمودار میله‌ای ۶ ماه اخیر (canvas خالص بدون کتابخانه خارجی)
- میانبرها: ثبت درآمد، ثبت هزینه، انتقال بین حساب‌ها، لیست بدهکاران، تسویه کارشناسان
- موجودی همه‌ی حساب‌ها

#### 👥 2) مطالبات مشتریان
- KPI: کل مطالبات، تعداد مشتریان بدهکار، پروژه‌های بدهکار
- جستجوی سریع
- لیست collapsible: هر مشتری → پروژه‌هایش با مبلغ کل / پرداختی / مانده
- دکمه «ثبت پرداخت» سریع برای هر پروژه

#### 💼 3) تسویه کارشناسان
- KPI: کل مانده تسویه، تعداد کارشناسان منتظر
- لیست collapsible: هر کارشناس → مراحل با مبلغ و مانده
- لینک به صفحه‌ی تسویه‌ی پیشرفته‌ی موجود (برای انجام تسویه واقعی)

#### 💸 4) درآمد و هزینه (مستقل از پروژه)
- ثبت تراکنش با: حساب، مبلغ، دسته، تاریخ شمسی، توضیح، اتصال اختیاری به پروژه
- فیلترهای کامل: نوع، حساب، دسته، بازه تاریخ
- KPI: جمع درآمد، جمع هزینه، خالص (در محدوده فیلتر)
- جدول قابل اسکرول روی موبایل
- modal سازگار با موبایل

#### 🏦 5) خزانه‌داری
- کارت‌های زیبا برای هر حساب (نقدی یا بانکی) با موجودی زنده
- نمایش شماره حساب / کارت / شبا
- ✏️ ویرایش + 🗑 حذف/غیرفعال‌سازی
- **انتقال بین حساب‌ها** با ثبت دو طرفه در لجر
- **گردش حساب** هر حساب با مانده در حال محاسبه

#### 🏷 6) دسته‌بندی‌های مالی
- مدیریت دسته‌ی درآمد و هزینه به‌صورت جداگانه
- آیکن + رنگ سفارشی برای هر دسته
- ۸ دسته‌ی پیش‌فرض seed می‌شوند

### امنیت
- نیاز به `edit_cptt_projects` capability
- nonce در همه‌ی AJAX ها
- جلوگیری از حذف حساب دارای تراکنش (به‌صورت خودکار غیرفعال می‌شود نه حذف)

### سازگاری
- استفاده از `CPTT_Currency` در صورت موجود بودن
- استفاده از `CPTT_Core::jalali_datetime` و `jalali_to_gregorian`
- در WordPress 5.6+ تست شده

---

## ✨ تغییرات نسخه‌ی 6.3.2 (UX سیستم یادآوری + فیلتر آرشیو)

### 🎨 ۱) چیپ‌های انتخاب‌شده در تنظیمات یادآوری
- چیپ‌ها در حالت `is-active` حالا کاملاً نارنجی پررنگ با border تیره‌تر + سایه + علامت `✓` شده‌اند.
- روی hover هم border رنگ گرفت تا feedback بصری بهتر داشته باشد.
- در تم‌های تاریک هم با درخشش نارنجی متمایز هستند.

### 🔘 ۲) سوییچ‌ها (هر دو reminders و notification settings) — بازطراحی
- سایز جدید: `46×26px` با thumb `20×20px` (هماهنگ و منظم)
- فاصله thumb از لبه: `3px` (قبلاً 2px بود و کج می‌نشست)
- در حالت ON: thumb دقیقاً به `left: 23px` می‌رود (= عرض - thumb - padding)
- track با inset shadow طبیعی‌تر شد
- thumb با سایه قوی‌تر برای visibility بهتر
- CSS با `!important` همه inline styles قبلی JS را override می‌کند
- کار می‌کند روی هر دو نوع switch (تنظیمات اعلان و تنظیمات یادآوری)

### 🔍 ۳) فیلتر و جستجو روی پروژه‌های آرشیوشده
- هر کارت آرشیو حالا data attributes کامل دارد: `data-search`, `data-status`, `data-settled`, `data-client`, `data-product`, `data-cats`, `data-label`, `data-customer-name`
- JS جدید `initArchiveFilterMirror()` همان فیلترهای اصلی را روی `.cptt-archive-card` ها هم اعمال می‌کند
- **بخش آرشیو** خودکار باز می‌شود وقتی فیلتر فعال است و حداقل یک نتیجه آرشیوشده وجود دارد
- badge شمارش به `X / Y` تبدیل می‌شود (تعداد منطبق‌ها / کل آرشیو) و در حذف فیلتر برمی‌گردد به حالت اول

### 🔴 ۴) reminderهای مهلت‌گذشته به‌وضوح قرمز
- کل کارت پس‌زمینه‌ی قرمز روشن می‌گیرد (نه فقط نوار کناری)
- border قرمز پررنگ + border-right قرمز تیره‌تر
- title و sub قرمز تیره
- آیکن گرد قرمز
- meta box با پس‌زمینه قرمز روشن
- box-shadow قرمز
- **انیمیشن pulse** سه بار در ۲ ثانیه اول برای جلب توجه
- در تم‌های تاریک هم به‌صورت متمایز و خواناست

### 🔔 ۵) Badge روی دکمه‌ی زنگوله یادآوری
- وقتی یادآوری روی صفحه نمایش داده شد، badge قرمز کوچک با تعداد فعلی روی دکمه ظاهر می‌شود
- با dismiss/snooze هر کارت، badge آپدیت می‌شود

---

## ✨ تغییرات نسخه‌ی 6.3.1 (هات‌فیکس سیستم یادآوری)

### 🐛 ۱) پروژه/مرحله تکمیل‌شده دیگر یادآوری نمی‌دهد
متد جدید `is_project_completed()` در `class-cptt-reminders.php`. اگر همه‌ی مراحل پروژه `status='done'` باشند، چه deadline پروژه و چه deadlineهای مراحل skip می‌شوند.

### 🐛 ۲) Dropdown اعلان داخل سایدبار اسکرول افقی می‌داد
**علت**: scroll-lock که در v6.3.0 با `overflow-y: auto` روی خود `.cptt-expertSidebar` گذاشتم، باعث می‌شد dropdown زنگوله که `position: absolute` بود داخل sidebar clip شود و scroll افقی ایجاد کند.

**رفع**: JS یک wrapper جدید `.cptt-sidebar-scroll-wrap` داخل سایدبار می‌سازد و **همه‌ی محتوای داخلی به‌جز زنگوله** را داخل آن منتقل می‌کند. wrapper اسکرول عمودی دارد ولی `overflow-x: visible`. زنگوله و dropdown آن بیرون از scroller می‌مانند تا هیچ‌گاه clip نشوند.

### ✅ ۳) تنظیمات یادآوری ۱۰۰٪ اختصاصی هر کاربر است
این از ابتدا درست بود ولی برای اطمینان مجدداً تأیید می‌کنم:
- prefs در `user_meta['cptt_reminder_prefs']` ذخیره می‌شود
- state (snoozed / dismissed / lastShown) در `user_meta['cptt_reminder_state']` ذخیره می‌شود
- AJAX endpoints همیشه از `get_current_user_id()` استفاده می‌کنند
- وقتی کاربر A روی «دیگر نشان نده» کلیک می‌کند، فقط برای A اعمال می‌شود — کاربران دیگر اصلاً متوجه نمی‌شوند

---

## ✨ تغییرات نسخه‌ی 6.3.0 (سیستم یادآوری مهلت + Sidebar Scroll Lock)

### 🆕 ۱) قابلیت جدید: سیستم یادآوری (Reminders)
یک سیستم کامل یادآوری برای مهلت پروژه‌ها و مراحل.

#### دکمه و تنظیمات
- یک **آیکن زنگوله نارنجی** کنار آیکن چرخ‌دنده‌ی تنظیمات اعلان (در dropdown زنگوله اصلی) اضافه می‌شود.
- با کلیک روی آن، یک popup تنظیمات باز می‌شود:
  - **فعال/غیرفعال** کل سیستم یادآوری
  - **انتخاب چند بازه‌ی زمان قبل از مهلت**: چیپ‌های `۲ ماه / ۱ هفته / ۳ روز / ۱ روز / ۶ ساعت / ۱ ساعت / ۳۰ دقیقه / ۱۰ دقیقه / ۵ دقیقه`. کاربر می‌تواند چند مورد را همزمان انتخاب کند.
  - **فرکانس نمایش**:
    - فقط یک بار
    - هر بار ورود (تا انجام شدن)
    - هر بار تا زمان انجام شدن
    - با فاصله‌ی زمانی مشخص (که مقدار دقیقه قابل تنظیم است)
  - **«یادآوری بعداً»**: کاربر می‌تواند تنظیم کند بعد از چند دقیقه دوباره یادآوری شود (snooze)
  - **نوتیفیکیشن مرورگر**: علاوه بر popup روی صفحه، به‌صورت browser notification هم ارسال می‌شود
  - **دکمه تست نمایش**: یک یادآوری آزمایشی نمایش می‌دهد

#### نمایش
- وقتی مهلت یک پروژه/مرحله نزدیک می‌شود (در بازه‌ی انتخاب‌شده)، یک کارت popup در گوشه‌ی پایین-راست صفحه نمایش داده می‌شود:
  - عنوان (پروژه یا مرحله)، نام پروژه، تاریخ مهلت، فاصله تا مهلت
  - رنگ نارنجی (هشدار) یا قرمز (مهلت گذشته)
  - **۳ دکمه**: «رفتن به پروژه» / «یادآوری بعداً» / «دیگر نشان نده»
- روی پروژه‌های آرشیوشده و مراحل تکمیل‌شده یادآوری نمی‌شود.

#### رفتار سرور
- هر ۶۰ ثانیه یک poll سبک به سرور (`cptt_reminders_check`).
- سرور فقط مواردی را برمی‌گرداند که الان باید نمایش داده شوند (تصمیم frequency سرور-سمت + state کاربر).
- State (snoozed/dismissed/lastShown) در `user_meta` ذخیره می‌شود.
- پاکسازی خودکار state های قدیمی (>۱۴ روز) برای جلوگیری از بزرگ شدن meta.
- نوتیفیکیشن مرورگر فقط در صورت اجازه‌ی کاربر فعال می‌شود.

#### امنیت و حریم خصوصی
- هر کاربر تنظیمات و state مستقل خود را دارد
- nonce در همه‌ی AJAX ها
- مدیر کل همه پروژه‌ها، کارشناس فقط پروژه‌های خودش

### 🆕 ۲) Sidebar Scroll Lock در دسکتاپ
- وقتی موس روی سایدبار سمت راست داشبورد باشد، **scroll-wheel فقط سایدبار را اسکرول می‌کند** (نه کل صفحه).
- پیاده‌سازی با `max-height: calc(100vh - 70px)` + `overflow-y: auto` + `overscroll-behavior: contain` روی `.cptt-expertSidebar`.
- اسکرول‌بار باریک و زیبا (مطابق با تم).
- در حضور admin bar وردپرس، `max-height` به طور خودکار 32px تنظیم می‌شود.

---

## ✨ تغییرات نسخه‌ی 6.2.1 (هات‌فیکس بعد از 6.2.0)

### 🐛 رفع باگ بحرانی: submit رفت به URL اشتباه
بعد از کلیک «ذخیره تغییرات» در مدیریت پروژه، browser به یک URL طولانی با همه‌ی فیلدها در query string redirect می‌کرد.

**علت**: در v6.1.10 lazy-load فرم مدیریت اضافه شد. تابع `bindSaveForms()` در DOMContentLoaded اجرا می‌شد و submit handler رو فقط روی فرم‌های اولیه bind می‌کرد. ولی الان فرم با AJAX inject می‌شه پس handler ندارد و browser default submit (GET به همان URL) اجرا می‌شه.

**رفع**:
- `bindSaveForms` به `window.bindSaveForms` expose شد.
- در event `cptt:manage-form-loaded` (که بعد از inject فرم dispatch می‌شه) `bindSaveForms()` خودکار صدا زده می‌شه.
- دکمه‌ی floating save (`hardFloatingSave`) هم expose و re-init می‌شه.
- فرم خودش `method="post" action="#" onsubmit="return false;"` گرفت تا حتی اگر JS برای هر دلیلی fail کرد، browser فرم رو submit نکنه.

### 🐛 ReferenceError: enhanceManageFinancialFields is not defined
در v6.1.10 این تابع رو در IIFE اشتباه expose کرده بودم (در IIFE دوم expert.js که این تابع اونجا تعریف نشده).

**رفع**: به IIFE درست (همان جایی که تعریف شده) منتقل شد + برای `hardFloatingSave` هم expose اضافه شد.

### 🎨 آیکن PWA
آیکن‌های `icon-192.png` و `icon-512.png` (که قبلاً 456 بایت placeholder بودند) با آیکن واقعی PWA با لوگوی «ه» روی پس‌زمینه‌ی بنفش جایگزین شدند. خطای `Manifest icon download error` رفع شد.

---

## ✨ تغییرات نسخه‌ی 6.2.0 (🤖 دستیار هوشمند)

### قابلیت جدید: دستیار AI داشبورد کارشناس
یک چت‌بات حرفه‌ای داخل داشبورد اضافه شد که به کارشناسان آموزش می‌دهد و حتی می‌تواند بعضی کارها را برایشان انجام دهد.

#### ✨ ویژگی‌ها
- **دکمه شناور «✨ دستیار»** پایین‌چپ داشبورد. با کلیک، پنل چت باز می‌شود.
- **آگاه از همه‌ی امکانات افزونه**: می‌داند داشبورد چه بخش‌هایی دارد، چه دکمه‌هایی وجود دارد، چگونه پروژه ساخت، فیلتر زد، آرشیو کرد، تسویه کرد و …
- **شخصی‌سازی**: کاربر را با نام صدا می‌زند (`{name} عزیز`).
- **فقط در حوزه‌ی افزونه پاسخ می‌دهد**: اگر سوال عمومی (اخبار، ریاضی، …) بپرسید، محترمانه می‌گوید فقط درباره‌ی هماهنگ کمک می‌کند.
- **انجام عملیات با دستور متنی** (در صورت فعال بودن): مثلاً «یک پروژه به نام طراحی لوگو برای علی بساز». دستیار JSON action صادر می‌کند و سرور آن را اجرا می‌کند. عملیات‌های مجاز:
  - `create_project` — ساخت پروژه با عنوان، مشتری، مهلت، مراحل، یادداشت
  - `add_step` — افزودن مرحله به پروژه
  - `archive_project` — انتقال به آرشیو
  - `mark_step_done` — علامت‌زدن مرحله به‌عنوان انجام‌شده

#### 💰 صرفه‌جویی در توکن
- فقط ۸ پیام آخر گفتگو به مدل ارسال می‌شود (rolling history per user).
- پیام سیستمی کوتاه و فشرده است.
- `max_tokens` پیش‌فرض ۶۰۰ توکن (قابل تنظیم).
- مدل پیش‌فرض روی OpenRouter رایگان است: `google/gemini-2.0-flash-exp:free`.
- بلاک‌های JSON عملیاتی از تاریخچه حذف می‌شوند تا توکن کمتر مصرف شود.

#### 🔌 پشتیبانی از سرویس‌دهنده‌های مختلف
در تب جدید **«🤖 دستیار هوشمند»** در تنظیمات افزونه می‌توانید انتخاب کنید:

| سرویس‌دهنده | مدل پیش‌فرض پیشنهادی | لینک کلید |
|---|---|---|
| **OpenAI** | `gpt-4o-mini` | platform.openai.com/api-keys |
| **Groq** | `llama-3.3-70b-versatile` | console.groq.com/keys |
| **OpenRouter** | `google/gemini-2.0-flash-exp:free` | openrouter.ai/keys |
| **Google AI Studio** | `gemini-2.0-flash` | aistudio.google.com/apikey |
| **سفارشی** | هر endpoint سازگار با OpenAI | — |

#### 🎛 تنظیمات قابل دسترسی
- فعال/غیرفعال کردن دستیار
- انتخاب سرویس‌دهنده + کلید API + مدل + Base URL
- Temperature (خلاقیت پاسخ) + max_tokens
- اجازه انجام عملیات (روشن/خاموش)
- پیام خوش‌آمد قابل ویرایش با placeholder `{name}`
- دکمه «🔌 تست اتصال» برای بررسی صحت کلید

#### 🔒 امنیت
- نیاز به login + nonce برای هر درخواست
- بررسی دسترسی کاربر قبل از هر عملیات
- هیچ اطلاعاتی به مدل ارسال نمی‌شود به جز پیام کاربر + system prompt + ۸ تبادل قبلی همان کاربر

---

## ✨ تغییرات نسخه‌ی 6.1.10 (Scalability — رفع کندی برای مدیر کل با پروژه‌های زیاد)

### 🎯 ریشه‌ی اصلی پیدا شد
کارشناسان با ۹ پروژه روان کار می‌کردند، ولی مدیر کل با ۴۸ پروژه (۱۷ فعال + ۳۱ تکمیل) کند می‌شد. علت:

داشبورد برای **هر کارت پروژه** چندین کار سنگین انجام می‌داد:

| کار | حجم برای ۴۸ پروژه |
|---|---|
| `render_project_manage_form()` (HTML 385 خط) | ~۱۸٬۵۰۰ خط HTML |
| `get_recent_notes()` + `get_recent_messages()` (2 DB read per project) | ~۹۶ DB read اضافی |
| Render فرم mراحل + checklist + user_tasks + finance + step-expert wraps | چندین قسمت سنگین تکرار شده |

برای ۹ پروژه مشکلی نبود، ولی برای ۴۸+ این انفجاری می‌شد.

### 🔧 رفع: Lazy Loading فرم مدیریت پروژه
- **PHP**: کارت‌ها فقط شامل پیش‌نمایش (header + stats + actions) هستند. فرم مدیریت و sidebar (notes/chat/files/requests/summary) **خالی** هستند با کلاس `cptt-lazy-details`.
- **AJAX endpoint جدید**: `wp_ajax_cptt_expert_load_manage_form` — وقتی کاربر روی «مدیریت پروژه» کلیک می‌کند، HTML کامل با AJAX fetch می‌شود (فقط برای آن یک پروژه).
- **JS**: هر کارت فقط بار اول AJAX می‌زند. بعد از اون cache می‌شه و دفعات بعد فقط show/hide.

### 🚀 بهینه‌سازی `project_card_data()`
- پارامتر جدید `$include_heavy = true` اضافه شد.
- وقتی `false` پاس بشه (در render اولیه کارت‌ها)، فیلدهای `notes` و `messages` (هر کدوم یک DB read) **اصلاً اجرا نمی‌شوند**.
- در ajax handler با `true` صدا زده می‌شه — جایی که واقعاً لازم هستن.

### 📊 نتیجه
برای داشبورد با ۴۸ پروژه:
- **HTML اولیه**: از ~۲۰٬۰۰۰ خط → ~۴٬۰۰۰ خط (کاهش ۸۰٪)
- **DB reads در render اول**: ~۲۰۰+ → ~۱۰۰ (کاهش ۵۰٪)
- **بدون افت عملکرد**: فرم وقتی نیاز شد در زیر یک ثانیه AJAX می‌شه

### ✅ بدون آسیب به عملکرد و استایل
- همه‌ی توابع موجود (step accordions, finance grid, sticky save, ...) با dispatch `cptt:manage-form-loaded` و expose `window.bindStepAccordions` / `window.layoutAllFinance` / ... روی HTML تازه‌inject‌شده اجرا می‌شوند.
- استایل‌ها تغییری نکردند.

---

## ✨ تغییرات نسخه‌ی 6.1.9 (FOUT + کندی قطعی)

### 🎯 ریشه‌ی اصلی پیدا شد
از خطای کنسول `elementorFrontendConfig is not defined` معلوم شد که داشبورد کارشناس **همه scripts و styles المنتور + قالب فعال** را load می‌کرد. این باعث می‌شد:

1. **FOUT** (Flash of Unstyled Content): صفحه ابتدا با استایل قالب رندر می‌شد، بعد از ~۱۰ ثانیه به استایل افزونه swap می‌شد.
2. **Elementor's `frontend.min.js` خطا می‌داد** چون این صفحه با المنتور ساخته نشده.
3. **Network سنگین می‌شد** چون Elementor + theme + همه plugins فایل‌هایشان را روی این صفحه‌ی virtual می‌فرستادند.

### 🔧 رفع: `dequeue_third_party_assets_for_dashboard()`
- قبل از `wp_head()` همه styles/scripts که متعلق به افزونه ما یا WP core نیستند **dequeue** می‌شوند.
- Allowlist: `cptt-*`, `ham-*`, `jquery*`, `dashicons`, `admin-bar`, `wp-color-picker`, ...
- Path-based: هر فایلی که در `/client-project-tracker/` یا `/wp-includes/` باشد نگه داشته می‌شود.
- هندلرهای مخصوص Elementor (`elementor-frontend`, `elementor-frontend-modules`, ...) صریحاً dequeue می‌شوند.
- این کار هم در `template_redirect` و هم در `wp_print_styles`/`wp_print_scripts` با priority `9999` اجرا می‌شود تا plugins که late enqueue می‌کنند هم گرفته شوند.

### 🎨 رفع FOUT با inline anti-flash CSS
قبل از `wp_head()` یک `<style id="cptt-anti-fout">` تزریق می‌شود که:
- `body { visibility: hidden }` تا CSS افزونه load شود
- `body.cptt-ready { visibility: visible }` که بعد از `DOMContentLoaded` (یا حداکثر ۱.۵ ثانیه) اضافه می‌شود
- پس‌زمینه و فونت‌های سیستم به‌عنوان fallback تنظیم می‌شوند

نتیجه: کاربر هیچ‌گاه style قالب را نمی‌بیند — یا صفحه‌ی خالی روشن، یا داشبورد کامل با تم خودش.

---

## ✨ تغییرات نسخه‌ی 6.1.8 (Performance — رفع loading دائمی)

با خطاهای کنسولی که فرستادید، ۴ منبع واقعی کندی پیدا شد:

### 1) 🔥 **Service Worker** — مظنون اصلی loading spinner دائمی
SW قدیمی `networkThenCache` داشت که در زمان slow network + cache miss یک `throw error` می‌کرد → `FetchEvent.respondWith` reject می‌شد → مرورگر تا ابد loading spinner نشان می‌داد و کنسول این خطا را می‌داد:
```
The FetchEvent for "..." resulted in a network error response: the promise was rejected.
```
**رفع**: SW به یک **pass-through minimal** تبدیل شد. هیچ `fetch` handler ندارد. PWA همچنان قابل نصب است ولی browser HTTP cache خود مسئول cacheing است. در `activate` همه‌ی cache های قدیمی پاک می‌شود.

### 2) 🐛 `admin.js: $ is not a function`
دو wrapper `jQuery(function($){...})` در `admin.js` بد بسته شده بود → کدهای بعدی خارج از scope `$` بودند. **رفع**: closure درست شد.

### 3) 🐛 `expert.js: initClientSearchPickers is not defined`
این تابع در یک IIFE تعریف بود ولی از IIFE دیگری صدا زده می‌شد. **رفع**: تابع به `window.initClientSearchPickers` expose شد + هر دو caller با `typeof` چک می‌کنند.

### 4) ⚡ فونت‌ها — `font-display: swap`
کنسول گفت: `[Intervention] Slow network is detected. Fallback font will be used`. این warning بی‌خطر است ولی تجربه کاربری بد می‌شد چون متن invisible می‌ماند تا فونت دانلود شود. **رفع**: همه‌ی `@font-face` ها به `font-display: swap` تنظیم شدند → متن سریعاً با فونت سیستم نشان داده می‌شود و وقتی فونت دانلود شد swap می‌شود.

### ⚠️ مهم بعد از نصب
اگر قبلاً SW قدیمی روی مرورگر cached شده، باید یک بار:
- در Chrome: `F12 → Application → Service Workers → Unregister` کنید (یا 30 ثانیه صبر کنید تا SW جدید فعال شود)
- در گوشی: app PWA را uninstall و دوباره install کنید (یا cache مرورگر را clear کنید)

---

## ✨ تغییرات نسخه‌ی 6.1.7 (Performance)

### 🐌 رفع کندی و لگ صفحه‌ی کارشناس
نسخه ۶.۱.۶ هنگ نمی‌کرد ولی کند کار می‌کرد چون:

1. **`cleanLegacyFixed` در هر scroll و resize اجرا می‌شد** — این تابع DOM رو traverse می‌کرد و چندین `querySelectorAll` و style تغییر می‌داد. حالا فقط ۳ بار در زمان load اجرا می‌شه (۰/۴۰۰/۱۵۰۰ms).
2. **MutationObserver روی ۶ تا view با subtree:true** — هر تغییر داخلی ۵ تابع heavy رو fire می‌کرد. حالا با:
   - `subtree: false` (فقط direct children) → ۹۰٪ کمتر trigger
   - debounce 250ms → حداکثر 4 refresh در ثانیه به‌جای صدها
3. **`injectAddButtons` در ۳ تایمر مختلف + هر click اجرا می‌شد** — حالا فقط ۱ بار initial + در click های مهم با debounce 300ms.
4. **`ensureAddButton` بدون idempotent guard** — حتی اگر دکمه + از قبل در جای درست بود، DOM رو دستکاری می‌کرد. حالا چک می‌کنه و اگر هیچ‌چیز نیاز نباشه return می‌کنه.

نتیجه: لگ، loading spinner دائمی، و سنگینی صفحه برطرف شد. عملکرد و استایل دست‌نخورده‌اند.

---

## ✨ تغییرات نسخه‌ی 6.1.6 (هات‌فیکس)

### 🔥 رفع باگ بحرانی: صفحه‌ی کارشناس هنگ می‌کرد (Page Unresponsive)
در نسخه ۶.۱.۵ یک MutationObserver روی `document.body` گذاشته بودم که با تابع `ensureAddButton()` (که خودش DOM را تغییر می‌داد) **یک حلقه‌ی بی‌نهایت** ایجاد می‌کرد. نتیجه: مرورگر crash می‌کرد و فقط splash اولیه load می‌شد بعد hang می‌شد.

**رفع**: MutationObserver عمومی حذف شد. حالا فقط در زمان‌های ایمن، دکمه‌های + دوباره inject می‌شوند:
- در زمان load صفحه (با تأخیر ۲۰۰/۸۰۰/۲۰۰۰ میلی‌ثانیه)
- بعد از کلیک روی «مدیریت پروژه» / «افزودن مرحله» / toggle مرحله

عملکرد همان نسخه ۶.۱.۵ است، فقط بدون infinite loop. ❤️

---

## ✨ تغییرات نسخه‌ی 6.1.5

### 🐞 رفع نهایی دو مورد

#### 1) چسبیدن نوار حالت‌ها در موبایل به بالای صفحه
به جای `position: sticky` (که گاهی به‌خاطر `overflow:hidden`/`transform` در یکی از parent ها در iOS و در برخی تم‌ها درست به بالا نمی‌چسبید)، حالا یک handler JS کوچک با `position: fixed` کنترل می‌کنه:
- وقتی نوار به بالا می‌رسد، کلاس `is-stuck-mobile` می‌گیرد و دقیقاً به بالای viewport (یا زیر admin bar 46px) می‌چسبد.
- یک placeholder ارتفاع نوار را پر می‌کند تا فلوی صفحه نپرد.
- وقتی scroll کنید بالا، نوار به جای اصلی برمی‌گردد.

#### 2) UI پرداختی/دریافتی‌های اضافی - تمیز و فشرده
- جعبه‌ی بزرگ و عنوان "💰 پرداختی/دریافتی‌های اضافی این مرحله" + دکمه‌ی بزرگ «+ افزودن ردیف» **حذف شدند**.
- حالا یک **دکمه‌ی + کوچک SVG** به‌صورت ستون آخر داخل ردیف مالی اصلی هر مرحله (`cptt-step-finance-grid`) قرار می‌گیرد.
- با کلیک +، یک ردیف جدید مالی (عنوان، هزینه، دریافتی، × حذف) **زیر** ردیف فعلی اضافه می‌شود و **دکمه‌ی +** خودکار از ردیف قبلی برداشته شده و به همان ردیف جدید (کنار × حذف) منتقل می‌شود — یعنی + همیشه روی آخرین ردیف مالی هست.
- در ردیف‌های اضافی، دکمه‌های × و + به‌صورت گروهی در کنار هم در یک سلول `cptt-ef-actions` قرار می‌گیرند.
- **دسکتاپ**: همه چیز در یک ردیف.
- **موبایل**: عنوان فول‌ویدث در ردیف ۱، هزینه + دریافتی در ردیف ۲ کنار هم، دکمه‌های × و + در سلول کوچک کنار آنها.

---

## ✨ تغییرات نسخه‌ی 6.1.4

### 🐞 رفع باگ‌ها + قابلیت‌های جدید (دور پنجم)

#### پیام‌رسان‌ها — فقط ۵ مورد
به جای ۷ پیام‌رسان، حالا فقط این‌ها در popup هست:
- **واتساپ** (`https://wa.me/98{phone}`)
- **تلگرام** (`https://t.me/+98{phone}`)
- **پیامک** (`sms:{phone}`) — باز شدن SMS app گوشی
- **تماس** (`tel:{phone}`) — باز شدن dialer گوشی
- **کپی** — کپی شماره به clipboard

ایتا، بله، روبیکا حذف شدند.

#### Sticky نوارها — اصلاح شد
- **دسکتاپ**: نوار داخلی `cptt-expertFilters` (به جای wrapper `cptt-filter-bar`) به بالای صفحه می‌چسبد و در حالت چسبیده زیر admin bar وردپرس قرار می‌گیرد. این یعنی هدر "جستجو و فیلتر" در صفحه فلو می‌ماند اما خود فیلدها وقتی scroll می‌کنید بالای صفحه می‌مانند.
- **موبایل**: نوار `#cptt-views-toolbar` (حالت‌های ویو + مرتب‌سازی) با `top: 0` چسبیده می‌شود، یعنی هیچ فاصله‌ای از بالای viewport ندارد (فقط در حضور admin bar 46px فاصله می‌گیرد).
- legacy sticky handler در `expert.js` کاملاً غیرفعال + پاک‌سازی هم اجرا می‌شود.

#### عنوان طولانی مرحله در موبایل — ریسپانسیو نرم
- هدر مرحله الان همیشه در یک ردیف باقی می‌ماند: `[⠿ drag] [TITLE] [badge] [chevron]`
- آیکن drag دیگر بالای عنوان نمی‌رود (با `align-self: center`).
- badge "انجام‌شده / در حال انجام / انجام‌نشده" در سمت چپ روبروی عنوان می‌ماند (نه پایین).
- فقط اگر عنوان واقعاً طولانی باشد، خودش به خط دوم می‌رود و ارتفاع کمی بیشتر می‌شود.
- پدینگ و فونت موبایل کمی فشرده‌تر شده.

#### تسویه کارشناس در حساب و کتاب — فیکس
- مراحلی که `paid` ندارند ولی `cost > 0` (هزینه دارند) هم در لیست تسویه نشده‌ها نمایش داده می‌شوند.
- وضعیت `done` بودن مرحله یا پروژه دیگر مانع نمایش در لیست نمی‌شود.
- اگر مرحله `assigned_expert_ids` نداشت، **همه** کارشناسان پروژه (نه فقط اولی) به‌عنوان عضو در نظر گرفته می‌شوند.
- مقادیر `extra_finance[]` (پرداختی/دریافتی‌های اضافی) هم به paid/cost افزوده می‌شوند.

#### گزارش عملکرد کارشناس — فقط مراحل خودِ کارشناس
در `class-cptt-analytics.php → collect_expert_stats()`:
- برای هر مرحله، اگر `assigned_expert_ids` شامل user_id جاری نباشد، **هزینه و دریافتی** آن مرحله در آمار کارشناس محاسبه نمی‌شود.
- اگر مرحله‌ای فاقد `assigned_expert_ids` بود (یعنی کارشناس خاصی برایش تعریف نشده)، آن مرحله متعلق به همه‌ی کارشناسان پروژه فرض می‌شود.
- `extra_finance[]` مرحله هم در صورت مالکیت، حساب می‌شود.

#### پرداختی/دریافتی‌های چندتایی برای هر مرحله 💰
بخش جدید **«پرداختی/دریافتی‌های اضافی»** زیر فیلدهای اصلی مالی هر مرحله اضافه شد:
- دکمه **«+ افزودن ردیف»** هر ردیف شامل: **عنوان**، **هزینه (ریال)**، **دریافتی (ریال)**، و دکمه **حذف ×**
- هر ردیف **در دسکتاپ** یک ردیف کامل می‌گیرد (`grid: 2fr 1.2fr 1.2fr 34px`)
- **در موبایل** ردیف به ۲ ردیف منطقی تبدیل می‌شود: ردیف بالا = عنوان فول‌ویدث، ردیف دوم = هزینه + دریافتی کنار هم
- در ذخیره به آرایه `extra_finance[]` می‌رود و در محاسبات حساب و کتاب + گزارش عملکرد لحاظ می‌شود.

---

## ✨ تغییرات نسخه‌ی 6.1.3

### 🐞 رفع باگ‌های دور چهارم + قابلیت‌های جدید

#### Sticky نوارها — نسبت به viewport تقسیم شد
- **دسکتاپ** (`>820px`): فقط **نوار جستجو و فیلتر** sticky می‌شود (با احترام به admin bar وردپرس → `top: 40px`).
- **موبایل** (`≤820px`): فقط **نوار حالت‌های چیدمان و مرتب‌سازی** sticky می‌شود (با احترام به admin bar موبایلی → `top: 54px`).
- نوار دیگر در هر viewport در فلوی عادی صفحه می‌ماند.
- همچنین `overflow: visible` روی `.cptt-expertWrap` / `.cptt-expertMain` تنظیم شد تا `position: sticky` قطعاً کار کند.

#### دکمه پیش‌فاکتور هم‌اندازه با مدیریت پروژه (موبایل و دسکتاپ)
- سلکتورهای with `> *` و grid-column صریح. هر دو الان دقیقاً ۴۰٪–۴۰٪ + دکمه آرشیو ۲۰٪ در یک ردیف هستند.

#### دکمه پیام‌رسان مشتری 💬
- در فیلد «انتخاب مشتری» (هم در فرم ایجاد پروژه جدید و هم در مدیریت پروژه) یک دکمه با آیکن پیام اضافه شد.
- با کلیک، یک popup با ۷ آیکن پیام‌رسان باز می‌شود:
  - **ایتا** → `https://eitaa.com/{phone}`
  - **بله** → `https://ble.ir/{phone}`
  - **روبیکا** → `https://rubika.ir/{phone}`
  - **واتساپ** → `https://wa.me/98{phone-without-leading-0}`
  - **تلگرام** → `tg://resolve?phone=98{phone}` (fallback به `https://t.me/+98{phone}`)
  - **پیامک** → `sms:{phone}` (باز شدن SMS اپ گوشی)
  - **کپی به کلیپ‌بورد** → کپی شماره با toast تأیید
- شماره مشتری از متاهای `billing_phone` / `cptt_phone` / `cptt_user_phone` / `mobile` خوانده می‌شود.
- اگر شماره ثبت نشده باشد، popup با badge قرمز نشان می‌دهد و کلیک‌ها alert می‌دهند.

#### عنوان طولانی مرحله در موبایل
- هدر مرحله به `flex-wrap: wrap` تغییر کرد:
  - عنوان مرحله + آواتار کارشناس‌ها در ردیف اول (با `word-break` و `overflow-wrap: anywhere`).
  - badge وضعیت (انجام‌شده / در حال انجام / انجام‌نشده) + chevron در ردیف دوم.
- آواتارها در موبایل به `24px` کوچک‌تر شد.

#### انیمیشن لودینگ splash
- **فقط در PWA standalone mode** و **فقط یک بار در روز** (per device) نمایش داده می‌شود.
- در مرورگر عادی (غیر PWA) هرگز نمایش داده نمی‌شود.
- کلید localStorage: `ham_splash_day` با مقدار `YYYY-M-D`.
- سه نسخه‌ی splash که در `expert.js` داشت همه به این منطق یکپارچه به‌روزرسانی شدند:
  - `initSplash()` → کاملاً disabled
  - `initStandaloneSplash()` → once-per-day check
  - `initLogoSplash()` → once-per-day check
  - `initUniversalSplash()` → PWA-only + once-per-day check

---

## ✨ تغییرات نسخه‌ی 6.1.2

### 🐞 رفع باگ‌های دور سوم
- **آکاردئون فیلتر بیرون از صفحه باز می‌شد** — **کد قدیمی sticky filter در `expert.js` کاملاً غیرفعال شد** (همان handler v5.5.10 که `position:fixed` روی `.cptt-expertFilters` می‌گذاشت). در نتیجه دیگر دو نسخه از فیلتر همزمان نخواهید دید و آکاردئون فقط در یک نقطه (همان موقعیت inline در فلوی صفحه) باز و بسته می‌شود.
- **نوار حالت‌های ویو + مرتب‌سازی** با `position: sticky` خالص و بومی به نوار بالا چسبیده می‌شود — هم در موبایل و هم در دسکتاپ.
- **همپوشانی با admin bar در دسکتاپ** — `body.admin-bar #cptt-views-toolbar { top: 40px }` (یعنی `32px` نوار وردپرس + `8px` فاصله). برای صفحات کوچک‌تر از `782px` که نوار وردپرس `46px` می‌شود، `top: 54px` تنظیم شده.
- **پاپ‌آپ‌های File-Manager / Requests در تم‌های روشن** — اعمال override نهایی روی همه‌ی متن‌ها (`div`, `span`, `strong`, `b`, `label`, `p`, `small`, `li`)، تینت‌های شفاف نیلی، badge ها، input ها و دکمه‌ها برای تم‌های روشن. دکمه‌های حذف همچنان قرمز می‌مانند.
- **دکمه‌ی آرشیو در یک ردیف** — به جای 50/50 + ردیف دوم، الان همه در یک ردیف به نسبت **`40% / 40% / 20%`** (مدیریت پروژه / پیش‌فاکتور / آرشیو). دکمه‌ی آرشیو فقط آیکن SVG است و متن ندارد. گزارش و حذف به ردیف بعد فول‌ویدث می‌روند.
- **بخش آرشیو پروژه‌ها** بصورت پیش‌فرض **بسته** است و فقط با کلیک کاربر باز می‌شود. (دیگر state در `localStorage` ذخیره نمی‌شود.)

### 📦 سایر تغییرات
- نسخه به `6.1.2`
- فایل `assets/js/expert.js` تغییر کرد (legacy sticky filter غیرفعال شد).

---

## ✨ تغییرات نسخه‌ی 6.1.1

### 🐞 رفع باگ‌های دور دوم + قابلیت‌های جدید
- **آکاردئون فیلتر در موبایل دیگر sticky نیست** — وقتی روی آن می‌زنید، فوراً در همان نقطه باز می‌شود (بدون پرش به بیرون از صفحه). به‌جای آن، **نوار حالت‌های ویو + مرتب‌سازی** در موبایل چسبان می‌شود.
- **در دسکتاپ** اگر نوار بالای پیشخوان (admin bar) باشد، فیلتر چسبان دقیقاً زیر آن قرار می‌گیرد و دیگر همپوشانی ندارد.
- **پاپ‌آپ‌های File-Manager / Requests در تم‌های روشن** — رنگ‌بندی کامل روشن شد (سفید + متن مشکی + دکمه‌های ملایم). تم‌های تاریک دست‌نخورده.
- **stats در موبایل** — کاشی «پیشرفت» مخفی شد و کاشی «مهلت پروژه» در جای آن قرار گرفت (با پس‌زمینه نارنجی متمایز).
- **دکمه‌های مدیریت پروژه / پیش‌فاکتور در موبایل** — با grid‎ ‍`1fr 1fr` دقیقاً ۵۰٪–۵۰٪ هم‌ارتفاع در یک ردیف. سایر دکمه‌ها (گزارش، آرشیو، حذف) در ردیف بعد فول‌ویدث.

### 👑 داشبورد کارشناس برای مدیر کل
- **مدیران کل (`administrator`) همه‌ی پروژه‌ها را می‌بینند** — حتی پروژه‌هایی که عضوش نیستند. کارشناسان عادی همچنان فقط پروژه‌های خودشان را می‌بینند.

### 📦 سیستم آرشیو پروژه‌ها
- دکمه‌ی جدید **📦 آرشیو** در اکشن‌های هر کارت پروژه. با کلیک، پروژه از داشبورد اصلی برداشته می‌شود **(اطلاعات، فایل‌ها، چت‌ها، یادداشت‌ها و … هیچ‌کدام حذف نمی‌شوند)** و به بخش «آرشیو پروژه‌ها» منتقل می‌شود.
- **بخش جدید «آرشیو پروژه‌ها»** در پایین داشبورد به‌صورت قابل‌بازشدن (collapsible) — تعداد کل آرشیوها روی دکمه نمایش داده می‌شود.
- در آرشیو می‌توانید **پیش‌فاکتور / گزارش** را ببینید، پروژه را با دکمه‌ی **📤 خروج از آرشیو** برگردانید یا (اگر دسترسی حذف دارید) کامل حذف کنید.
- ۲ AJAX endpoint جدید: `cptt_expert_archive_project`, `cptt_expert_unarchive_project`.
- متاهای جدید روی پست پروژه: `_cptt_archived` (1)، `_cptt_archived_at` (timestamp)، `_cptt_archived_by` (user_id).

---

## ✨ تغییرات نسخه‌ی 6.1.0

### 🐞 رفع باگ‌ها و بهبود تجربه‌ی موبایلِ داشبورد کارشناس
- **آیکن SVG برای دکمه‌ی «تنظیمات اعلان‌ها»** با ابعاد و کنتراست بیشتر و رفع مشکلِ نمایشی روی برخی سیستم‌ها (به جای آیکن قبلی که در بعضی مرورگرها رندر نمی‌شد).
- **رفع overflowِ آکاردئون «جستجو و فیلتر»** در حالت sticky/fixed موبایل (دیگر بیرون از صفحه باز نمی‌شود).
- **رنگ‌بندی پاپ‌آپ‌های «درخواست‌ها» و «مدیریت فایل»** در تم‌های روشن (Glass / Minimal / Neumorph / Skeuo) اصلاح شد. دیگر در تم روشن، نیمه‌تاریک نشان داده نمی‌شود. تم‌های تاریک دست‌نخورده باقی مانده‌اند.
- **فیلدهای فیلتر در موبایل** هر ردیف **۳ فیلد** (در سرجمع **۲ ردیف**) — به جای ۱ فیلد در هر ردیف. فیلد جستجو در ردیف خودش.
- **infoGrid کارت پروژه در موبایل** هر ردیف **۲ آیتم**، با فاصله‌گذاری و فونتِ فشرده‌تر. در پیش‌نمایش (حالت بسته) فقط **مهلت پروژه** + **تاریخ ایجاد** نمایش داده می‌شود و آیتم‌های وضعیت مالی، هزینه/دریافتی، روش تحویل و آخرین بروزرسانی **مخفی** هستند. با کلیک روی «مدیریت پروژه» همه آیتم‌ها در ۲ ستون باز می‌شوند.
- **خلاصه‌ی سریع (سایدبار)** در موبایل به جای ۴ ردیف، در **۲ ردیف ۲ ستونه** فشرده‌تر شد.
- **حذف دکمه‌ی «جستجو و فیلتر پروژه‌ها»ی تکراری** در موبایل (آکاردئون اصلی به‌خودی‌خود نمایش داده می‌شود).
- **مرتب‌سازی** در موبایل دیگر یک ردیف کامل اشغال نمی‌کند؛ به یک **دراپ‌دانن جمع‌وجور** در ردیف اول هدر (کنار دکمه‌های ویو) تبدیل شد.
- **تاریخ ایجاد پروژه** در همه‌ی ویوها (کارتی، لیستی، تقویم، گانت، تایم‌لاین) به‌صورت کوچک و فشرده اضافه شد.
- **دکمه‌های «مدیریت پروژه» و «پیش‌فاکتور»** در موبایل دقیقاً **۵۰٪–۵۰٪** در یک ردیف، با ارتفاع ثابت ۳۸px و هم‌اندازه. دکمه‌های «گزارش نهایی» و «حذف پروژه» در ردیف بعد فول‌ویدث می‌مانند.

---

## 📜 تغییرات نسخه‌ی 5.5.22

### 📱 اصلاح نهایی منوی موبایل و نصب PWA
- حذف منوی Gooey و جایگزینی با منوی ساده مشابه نمونه با آیکن‌های بدون عنوان و دکمه ذخیره مرکزی
- مخفی شدن کامل دکمه‌های شناور قدیمی موبایل
- اصلاح ماندن دکمه ذخیره دسکتاپ پس از بستن مدیریت
- اصلاح جدول حساب و کتاب: جدول روی صفحه دیده می‌شود و با کلیک مودال صفحه‌بندی باز می‌شود
- افزودن پاپ‌آپ نصب PWA و splash/loading اولیه با نام هماهنگ

## ✨ تغییرات نسخه‌ی 5.5.21

### 🫧 اصلاح ناوبری مایع موبایل و مودال حساب و کتاب
- حذف عملی ناوبری پایین قبلی و جایگزینی با Gooey Fluid Bottom Nav موبایل با SVG filter
- حذف دکمه منوی شناور و اسکرول بالا در موبایل
- افزودن دکمه ذخیره به مرکز منوی پایین فقط هنگام باز بودن مدیریت پروژه
- اصلاح لیست پروژه‌های حساب و کتاب: جدول روی صفحه باقی می‌ماند و با کلیک روی جدول، مودال صفحه‌بندی‌شده باز می‌شود
- رفع باقی ماندن دکمه ذخیره شناور در دسکتاپ پس از بستن مدیریت پروژه

## ✨ تغییرات نسخه‌ی 5.5.20

### 🛡 پایداری داده‌ها، PWA و ناوبری پایین
- افزودن محافظ جلوگیری از overwrite خالی مراحل و بکاپ قبل از ذخیره مراحل
- افزودن manifest و service worker برای PWA داشبورد
- افزودن Bottom Navigation سیال با آیتم‌های خانه، پروژه‌ها، چت‌ها، پروفایل و ذخیره
- انتقال ذخیره تغییرات در موبایل به آیتم ذخیره داخل نوار پایین
- تبدیل جدول پروژه‌های حساب و کتاب به مودال با صفحه‌بندی ۱۰تایی
- اجرای اسکریپت‌های داخلی صفحات مدیریت embedded برای رفع عملکرد دکمه‌ها

## ✨ تغییرات نسخه‌ی 5.5.19

### 📱 اصلاح لرزش کارت‌ها و تقویم موبایل
- حذف contain/transformهای ایجادکننده لرزش کارت‌های پروژه هنگام اسکرول موبایل
- غیرفعال‌سازی fallback ثابت‌سازی فیلترها در موبایل برای جلوگیری از reflow هنگام اسکرول
- فیلدهای تاریخ شمسی در موبایل readonly شدند تا کیبورد باز نشود
- کیبورد فقط روی فیلدهای ساعت/دقیقه داخل خود تقویم باز می‌شود

## ✨ تغییرات نسخه‌ی 5.5.18

### ⚡ بهینه‌سازی عملکرد موبایل
- افزودن حالت سبک موبایل برای کاهش لگ و هنگ بدون تغییر چیدمان
- حذف transitionهای سنگین و hover transformها روی موبایل
- سبک‌سازی blur و shadow روی کارت‌های تکراری برای اسکرول روان‌تر
- throttle شدن برخی پردازش‌های اسکرول در JS
- حفظ ظاهر کلی، رنگ‌ها و عملکردهای داشبورد

## ✨ تغییرات نسخه‌ی 5.5.17

### 🛠 اصلاح نهایی ذخیره صفحات داخلی و مسیر ورود
- جلوگیری از 404 هنگام ذخیره فرم‌های تنظیمات و پرداخت داخل داشبورد مدیر با ارسال AJAX
- ذخیره تنظیمات افزونه داخل همان پنل داخلی بدون خروج به options.php
- مقاوم‌سازی بیشتر `/cptt-login` با رندر مستقیم روی init برای سایت‌هایی که rewrite را 404 می‌کردند

## ✨ تغییرات نسخه‌ی 5.5.14

### 🔐 پایداری صفحه ورود اختصاصی و تکمیل پنل داخلی مدیریت
- مقاوم‌سازی مسیر `/cptt-login` در برابر 404 با parse_request و pre_handle_404
- تکمیل assetها و layout صفحات حساب و کتاب، تنظیمات و فرم‌ساز در داشبورد مدیر
- رفع پیمایش تب‌های تنظیمات و فرم‌ساز داخل همان پنل داخلی بدون خروج از داشبورد

## ✨ تغییرات نسخه‌ی 5.5.13

### 🧩 تکمیل صفحات مدیریت داخلی داشبورد
- بارگذاری assetهای لازم صفحات حساب و کتاب، تنظیمات، پرداخت و فرم‌ساز داخل داشبورد مدیر
- اصلاح نمایش کامل‌تر صفحات تنظیمات و فرم‌ساز در پنل داخلی بدون iframe
- پشتیبانی از تب‌های تنظیمات و فرم‌های فرم‌ساز در همان پنل داخلی
- بهبود layout و هماهنگی تم‌های تاریک/تاریک مدرن برای محتوای مدیریتی جاسازی‌شده

## ✨ تغییرات نسخه‌ی 5.5.12

### 🧭 اصلاح منوی مدیریت مدیر در داشبورد
- حذف iframe و عدم نمایش پوسته داخلی پیشخوان
- نمایش صفحات مدیریتی به صورت محتوای داخلی همان داشبورد
- آیتم «داشبورد» برای بازگشت به داشبورد کارشناس به جای داشبورد پروژه‌های ادمین
- حفظ سایدبار سمت راست هنگام جابه‌جایی بین صفحات مدیریت
- هماهنگ‌سازی استایل محتوای مدیریت با تم انتخابی داشبورد

## ✨ تغییرات نسخه‌ی 5.5.11

### 🤖 ربات بله، پروفایل وردپرس و منوی مدیریت مدیر
- اصلاح شروع ربات بله: ابتدا شماره موبایل گرفته می‌شود، سپس حساب موجود وصل یا ثبت‌نام مشتری آغاز می‌شود
- افزودن خودکار فیلد شماره موبایل به شناسنامه کاربر وردپرس
- افزودن منوی مدیریت افزونه در داشبورد کارشناس فقط برای مدیر سایت با نمایش صفحات مدیریتی داخل همان فضا
- هماهنگ‌سازی ظاهر منوی مدیریت با تم‌های داشبورد
- تنظیم نهایی اندازه FAB موبایل

## ✨ تغییرات نسخه‌ی 5.5.10

### 🧯 اصلاح فوری پایداری داشبورد
- حذف observerهای سنگین که باعث هنگ هنگام باز کردن مدیریت پروژه می‌شدند
- اصلاح قطعی اندازه دکمه منوی شناور موبایل
- افزودن fallback جاوااسکریپتی برای sticky شدن فیلترهای داشبورد و ویترین

## ✨ تغییرات نسخه‌ی 5.5.9

### ✅ اصلاحات نهایی UI داشبورد
- کوچک‌تر شدن FAB موبایل و هم‌ردیف شدن با دکمه‌های شناور
- تیره شدن تقویم شمسی در تم تاریک مدرن
- بازچینی فیلدهای مالی مرحله با ترتیب مبلغ فی، تعداد، جمع کل، پرداختی و مانده
- انتقال دکمه تسویه کل به بخش اطلاعات پروژه
- تبدیل عنوان کد یکتا به کد پیگیری در گزارش‌ها، کارت‌ها و پیام‌ها
- تیره شدن پاپ‌آپ گفتگو با کارشناسان در تم‌های تاریک
- sticky شدن فیلترهای داشبورد و ویترین
- تنظیم z-index دکمه ذخیره شناور زیر مودال‌ها

## ✨ تغییرات نسخه‌ی 5.5.8

### 🧾 اصلاحات مالی، شناور و ریسپانسیو
- اصلاح قطعی رنگ متن حباب ارسالی و badge اعلان‌ها
- بازسازی رفتار دکمه ذخیره شناور با انتقال به body هنگام باز بودن فرم
- افزودن مبلغ فی، تعداد، جمع مرحله و دریافتی در مدیریت پروژه و مرحله جدید بدون نیاز به رفرش
- افزودن دکمه تسویه کل در ایجاد و مدیریت پروژه
- افزودن عنوان صفحه برای داشبورد و ویترین کارشناسان
- افزودن کد پیگیری به گزارش، فاکتور و پیش‌فاکتور
- اصلاح جستجوی مشتری در موبایل، وسط‌چین شدن پروفایل، و sticky شدن فیلترها

## ✨ تغییرات نسخه‌ی 5.5.7

### 🛠 اصلاحات تکمیلی داشبورد کارشناس
- هماهنگ‌سازی هدر اعلان‌ها در تاریک مدرن
- اصلاح جایگاه و z-index دکمه شناور ذخیره در موبایل و تاریک مدرن
- بهبود جایگاه dropdown اعلان‌ها زیر آیکن و افزودن دکمه بستن
- اصلاح چینش دکمه‌های مدیریت پروژه/پیش‌فاکتور در موبایل
- سفید شدن عدد badge اعلان‌ها در همه تم‌ها
- تاریک شدن تنظیمات ایموجی وضعیت در تم‌های تاریک
- اصلاح رنگ متن حباب پیام ارسالی در تم پیش‌فرض

## ✨ تغییرات نسخه‌ی 5.5.6

### 🧩 اصلاحات ریسپانسیو، چت و ویترین
- راست‌چین شدن پیام‌های ارسالی و چپ‌چین شدن پیام‌های دریافتی در چت‌ها
- اصلاح رنگ متن حباب پیام در تم اسکئومورفیسم و مینیمال
- اصلاح پاپ‌آپ چت پروژه و دکمه ذخیره شناور در تاریک مدرن
- اصلاح ناپدید شدن FAB موبایل بعد از انتخاب گزینه منو
- کاهش/حذف اسکرول افقی در موبایل، تبلت و دسکتاپ کوچک
- اصلاح جایگاه dropdown اعلان‌ها در موبایل و فاصله آیتم‌های اعلان
- نمایش جزئیات کامل‌تر پروژه‌ها در ویترین کارشناسان

## ✨ تغییرات نسخه‌ی 5.5.5

### 🎨 اصلاح نهایی تم‌های داشبورد کارشناس
- حذف گزینه گلس مورفیسم از لیست تم‌ها
- اصلاح رنگ بخش‌های باقی‌مانده روشن در تم تاریک و تاریک مدرن
- اصلاح مرکز بودن پاپ‌آپ چت پروژه و ثبت مشتری جدید
- بهبود کارت انتخاب کارشناسان با آواتار وسط‌چین، استروک و حالت انتخاب‌شده بدون مربع چک‌باکس
- جمع‌وجورتر شدن چت با کارشناس و بهبود حباب پیام‌ها در تم‌ها

## ✨ تغییرات نسخه‌ی 5.5.4

### 🎨 اصلاح تم‌های داشبورد کارشناس
- بازنویسی تم گلس مورفیسم با ظاهر روشن VisionOS و حذف گلس از wrapperهای ساختاری
- امکان انتخاب پس‌زمینه گلس از پریست، CSS gradient یا آدرس عکس در داشبورد کارشناس
- اصلاح مرکز بودن پاپ‌آپ چت پروژه و قرار گرفتن سایدبار زیر بلور مودال
- بازگشت دکمه شناور «ذخیره تغییرات» به پایین سمت راست کنار سایدبار و بهبود جایگاه موبایل
- اصلاح رنگ select/dropdown در تم سه‌بعدی
- تغییر نام تم «سه‌بعدی» به «تاریک مدرن» و انتقال به انتهای لیست

## ✨ تغییرات نسخه‌ی 5.4.17

### ✅ آواتار کارشناسان مرحله بعد از رفرش حفظ می‌شود
- پس از انتخاب کارشناس برای یک مرحله، عکس‌های پروفایل کنار عنوان مرحله **بلافاصله ذخیره می‌شوند** (auto-save)
- حتی اگر کاربر دکمه «ذخیره تغییرات» را نزند، انتخاب پابرجاست
- AJAX جدید: `cptt_expert_save_step_experts`

### 💳 بازنویسی کامل سیستم پرداخت (Payment v2)
- **چند روش پرداخت همزمان** (افزودن/حذف/فعال/غیرفعال)
- **درگاه‌های آنلاین:** زرین‌پال، زیبال، آیدی‌پی، نکست‌پی، PayPing، درگاه بانک
- **کارت‌به‌کارت با چند کارت** (هر گیت‌وی کارت‌به‌کارت می‌تواند چند کارت بانکی داشته باشد)
- **صفحه‌ی پرداخت زیبا و یکپارچه** برای مشتری با انتخاب روش
- **آپلود رسید** با پیش‌نمایش تصویری در پنل ادمین
- **تایید/رد رسید** + نوتیف بله به مشتری
- **یکپارچگی کامل با ربات بله** (لینک پرداخت در پیام «پرداخت بدهی»)
- **هیرو + تب‌بندی + سوییچ‌های زیبا** در پنل ادمین

### 📄 فاکتور و پیش‌فاکتور
- توضیحات مرحله از فاکتور حذف شد — فقط عنوان مرحله نمایش داده می‌شود

### 🛒 افزودن چندمحصول به پروژه از داشبورد کارشناس
- آیکن **+** کنار انتخاب محصول
- با کلیک: محصول به بودجه‌بندی اضافه می‌شود + chip در بالای فرم
- selectها reset می‌شوند تا محصول بعدی انتخاب شود

### 🧮 صفحه حساب و کتاب
- دکمه‌های Excel و PDF حذف شدند (فقط دکمه‌ی چاپ گزارش مالی باقی ماند)


افزونه‌ی وردپرسی مدیریت پروژه‌ی مشتری-محور با Stepper، چک‌لیست، تسک سمت مشتری، گزارش نهایی، حساب و کتاب، پنل کارشناس، اتصال به ووکامرس، پنل کامل ربات بله، سیستم ورود/ثبت‌نام با OTP بله، و **سیستم ثبت سفارش مشتری در بله**.

## ✨ تغییرات نسخه‌ی 5.4.7

### 🛒 سیستم کامل ثبت سفارش در ربات بله (Customer Order Flow)
- **دکمه‌ی «🛒 ثبت سفارش جدید»** در منوی مشتری ربات بله.
- **جریان چندمرحله‌ای (Wizard) مینیمال و کاربرپسند:**
  1. انتخاب *نوع سفارش* (حضوری 🏬 / با ارسال 🚚)
  2. *توضیحات* (با دکمه‌ی «⏭ رد کردن»)
  3. *آپلود فایل/عکس* — چندتایی، با دکمه‌ی «✅ ثبت و ادامه»
  4. *آدرس ارسال* — فقط برای سفارش ارسال، با گزینه‌ی «بعداً هماهنگ می‌کنم»
  5. *پیش‌نمایش* و *تأیید نهایی*
- پس از تأیید، پیام تشکر زیبا به مشتری ارسال می‌شود.
- **سفارش به‌صورت یک پست از نوع `cptt_order`** ذخیره می‌شود و در پنل ادمین قابل ویرایش است.
- **ارسال کارت زیبا و مرتب به مدیر سایت و کارشناسان منتخب** شامل:
  - شناسه سفارش، زمان ثبت، وضعیت، نوع
  - مشخصات کامل مشتری: نام، آیدی کاربری، شماره تماس، آیدی بله، ایمیل
  - توضیحات کامل سفارش
  - آدرس (در صورت ارسال)
  - تعداد فایل + ارسال خودکار لینک‌های همه‌ی فایل‌ها در پیام بعدی
- **دکمه‌ی «👥 تخصیص کارشناس»** برای مدیر زیر هر سفارش.
- **دکمه‌ی «➕ ایجاد پروژه از این سفارش»** برای کارشناس — وارد wizard موجود ایجاد پروژه می‌شود با اطلاعات مشتری از پیش پر شده.
- **پس از ایجاد پروژه از سفارش:**
  - سفارش به پروژه لینک می‌شود (`status=project`)
  - فایل‌های اولیه به‌عنوان یادداشت در پروژه ثبت می‌شوند
  - به مشتری نوتیف بله ارسال می‌شود: «🎉 سفارش شما تبدیل به پروژه شد!»
- اگر کارشناس هیچ اقدامی نکند، سفارش در وضعیت `pending` می‌ماند و به پروژه‌ها اضافه نمی‌شود.

### 🔧 تنظیمات
- **انتخاب کارشناسان دریافت‌کننده‌ی سفارش‌ها**: در تنظیمات → تب «ربات بله»، یک باکس checkbox برای انتخاب کارشناسانی که هنگام ثبت سفارش جدید نوتیف می‌گیرند.
- لیست سفارش‌های مشتری در منوی ربات: **«📦 سفارش‌های من»**.
- لیست سفارش‌های سیستم برای مدیر: **«🛒 سفارش‌های دریافت‌شده»**.

### 🧰 پنل ادمین — سفارش‌ها
- منوی جدید «سفارش‌های بله» زیر منوی پروژه‌ها.
- متاباکس «جزئیات سفارش» با نمایش مرتب اطلاعات مشتری، توضیحات، آدرس و گالری فایل‌ها (با پیش‌نمایش تصاویر).
- متاباکس «عملیات سفارش» با امکان تخصیص کارشناس و تغییر وضعیت از داخل ادمین.

---

## ✨ تغییرات نسخه‌ی 5.4.6
### UI / UX داشبورد کارشناس
- 📐 دکمه‌های کارت پروژه در دسکتاپ هم‌اندازه و در یک ردیف.
- 📱 در موبایل «مدیریت پروژه» + «پیش‌فاکتور» کنار هم، «گزارش» فول‌ویدث پایین.
- 🪟 رفع تداخل آیکن منوی شناور موبایل با مودال.
- 🗑 رفع باگ دکمه‌ی حذف اعلان.
### حساب و کتاب
- 👥 دکمه‌ی «لیست بدهکاران» با پاپ‌آپ مینیمال و جستجوی زنده.

## ✨ تغییرات نسخه‌ی 5.4.4
- ربات بله — بازنویسی کامل (wizard، edit-in-place، mute، کرون)
- سیستم ورود اختصاصی با OTP بله

## ساختار
- `client-project-tracker.php` — بوت‌استرپ + activation hooks
- `includes/`
  - `class-cptt-core.php` — CPTها (شامل **`cptt_order`** جدید)، نقش کارشناس، توابع جلالی
  - `class-cptt-admin.php` — متاباکس‌ها، داشبورد، حساب و کتاب، **متاباکس‌های سفارش**
  - `class-cptt-expert.php` — پنل کارشناس، نوتیفیکیشن‌ها، چت
  - `class-cptt-frontend.php` — شورتکد + endpoint ووکامرس
  - `class-cptt-report.php` — گزارش نهایی پروژه
  - `class-cptt-settings.php` — تنظیمات (شامل تب بله + OTP + **انتخاب کارشناسان دریافت‌کننده‌ی سفارش**)
  - `class-cptt-sms.php` — پیامک webhook + کرون
  - `class-cptt-woocommerce.php` — ساخت خودکار پروژه از سفارش
  - `class-cptt-analytics.php` — آمار و گزارش
  - `class-cptt-bale.php` — **ربات بله v5.4.7 (شامل Customer Order Flow کامل)**
  - `class-cptt-auth.php` — سیستم ورود OTP با بله
- `assets/css/`, `assets/js/`, `assets/fonts/`

## نصب
1. فایل زیپ را در `wp-admin → افزونه‌ها → افزودن → بارگذاری` آپلود کنید.
2. فعال‌سازی → از منوی «پروژه‌های مشتری» → «تنظیمات» تب «ربات بله» شروع کنید.
3. توکن ربات بله و آی‌دی ادمین را وارد کنید.
4. در همان تب، **کارشناسانی که باید سفارش‌ها را دریافت کنند** را انتخاب کنید.
5. در صورت نیاز، گزینه‌ی «فعال‌سازی ورود اختصاصی» و «جایگزین wp-login» را روشن کنید.
6. یک‌بار از تنظیمات وردپرس → پیوندهای یکتا → «ذخیره» را بزنید تا rewriteها و CPTهای جدید رفرش شوند.

## تست‌شده با
- WordPress 6.x, PHP 8.x
- ربات بله (`tapi.bale.ai`)
