# هماهنگ — افزونه‌ی مدیریت پروژه و تیم (CPTT)

نسخه: **7.1.0**

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
