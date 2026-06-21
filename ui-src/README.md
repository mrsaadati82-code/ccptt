# React Source — ui-src/

این پوشه شامل سورس‌های React/TypeScript پنل ERP است.

این کار از نسخه‌ی ۸.۰.۱ به بعد در حال پیاده‌سازی است (حل مشکل M-۱).

---

## وضعیت فعلی (v8.0.1)

| فایل | وضعیت | توضیح |
|------|--------|--------|
| `package.json` | ✅ آماده | لیست dependency ها |
| `vite.config.ts` | ✅ آماده | همراه با vite-plugin-singlefile |
| `tsconfig.json` | ✅ آماده | strict TypeScript |
| `index.html` | ✅ آماده | entry HTML |
| `src/main.tsx` | ✅ آماده | mount React app |
| `src/index.css` | ✅ آماده | Tailwind + FilterBar + anti-theme override |
| `src/utils/cn.ts` | ✅ آماده | className utility |
| `src/utils/jalali.ts` | ✅ آماده | تقویم شمسی self-contained |
| `src/utils/forceStyle.ts` | ✅ آماده | anti-WordPress-theme CSS forcing |
| `src/utils/wpBridge.ts` | ✅ آماده | API bridge با ۱۲۴ endpoint کامل |
| `src/utils/exporter.ts` | ✅ آماده | CSV/Excel/Print با فونت Dana |
| `src/context/AppContext.tsx` | ⏳ آینده | در فاز ۹ بازنویسی می‌شود |
| `src/App.tsx` | ⏳ آینده | routing + MutationObserver |
| `src/components/UI.tsx` | ⏳ آینده | کامپوننت‌های مشترک (FilterBar, Input, ...) |
| `src/components/Sidebar.tsx` | ⏳ آینده | منو + ۶ تب جدید فاز ۹/۱۰ |
| `src/components/Navbar.tsx` | ⏳ آینده | bell + dropdown |
| `src/components/*.tsx` (۲۴ صفحه) | ⏳ آینده | همه صفحات ERP |

---

## نحوه‌ی Build

نیاز است Node.js نصب شده باشد. سپس:

```bash
cd ui-src/
npm install
npm run build
```

نتیجه در `dist/index.html` ساخته می‌شود (single-file با singlefile plugin).

سپس script استخراج (در `scripts/extract-bundle.py`) را اجرا کنید تا
به `assets/finance-ui/app.css` و `app.js` تبدیل شود.

```bash
python3 scripts/extract-bundle.py
```

---

## مهم برای فاز ۹

**bundle فعلی `assets/finance-ui/app.js` که شامل تمام بهبودهای فاز ۱ تا ۷.۵ است
دست‌نخورده باقی می‌ماند** تا فاز ۹ کامل شود.

تنها زمانی که AppContext + همه‌ی component ها در این پوشه بازنویسی شوند،
دستور rebuild اجرا و bundle به‌روز خواهد شد.

---

## چه چیزی در sources upstream نبود (و در فاز ۹ بازسازی می‌شود)

upstream repo (`mali`) فقط ۱۳ کامپوننت و AppContext ساده دارد.
نسخه‌ی build شده‌ی فعلی پلاگین شامل ۲۷ کامپوننت + تمام بهبودهای زیر است
که در فاز ۱ تا ۷ از طریق این agent اضافه شدند ولی sources آن‌ها
از دست رفت:

### کامپوننت‌های جدید (۱۴ مورد)
- AgingReport, AttachmentsBox, AuditLog, Categories, Cheques, ExportButtons,
  ExpertSettlement (rewritten with 3 tabs), Installments, PartyStatement,
  Payables, Permissions, ProjectAccounting, Receivables, UI (shared kit)

### بهبودهای موجود در همه‌ی صفحات
- FilterBar + CompactField (فاز v7.6) - filter compact responsive
- useForceStyle hook (فاز v7.5) - anti-WP-theme
- MutationObserver سراسری در App.tsx (فاز v7.5)
- exporter.ts بازنویسی شده با font.load() قبل از print (فاز v7.5)
- reverseVoucher button و دکمه دار در Vouchers (فاز v7.7)
- Tabs جدید در ExpertSettlement (pending/done/history) (فاز v7.6)
- Treasury با initialMode='transfer' (فاز v7.6)
- ChartOfAccounts با balance زنده (فاز v7.8)
- FinancialReports.LedgerReport با parent chain rollup (فاز v7.6)
- voucher synthesis fallback (فاز v7.6 - حالا قابل حذف چون Phase 1 fix رفت)

### تب‌های جدید پیشنهادی فاز ۱۰
این صفحات الان فقط در منوی وردپرس به‌صورت admin page هستند:
- 🧭 نگاشت حساب‌ها (Phase 2)
- 🗓 بستن سال مالی (Phase 5)
- 📊 گزارش‌های پیشرفته (Phase 6 - ۷ subreport)
- ⚙ عملیات و اتوماسیون (Phase 7)
- 🔌 یکپارچه‌سازی (Phase 8)
- 🧾 فاکتورها (Phase 8)

در فاز ۱۰ این‌ها به sidebar React اضافه می‌شوند.
