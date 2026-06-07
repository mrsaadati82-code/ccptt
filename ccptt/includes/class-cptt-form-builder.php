<?php
/**
 * HAM Bale Order Flow Builder — v6.0.0
 *
 * قابلیت‌های جدید:
 *  - دکمه‌ی اختصاصی در بات بله با عنوان و جایگاه قابل تنظیم
 *  - لینک اختصاصی /start?form=ID برای هر فرم (deep link بله)
 *  - شرطی‌سازی بصری (Conditional Logic) با ویرایشگر گرافیکی
 *  - پیام نهایی شرطی بر اساس پاسخ‌ها
 *  - پرش خودکار از فیلدهایی که اطلاعاتشان در پروفایل کاربر موجود است
 *  - ایمپورت/اکسپورت فرم به فرمت JSON
 *  - مدیریت دکمه‌های پنل مشتری در بات بله
 */
if (!defined('ABSPATH')) exit;

class CPTT_Form_Builder {
    private static $instance = null;
    const OPT_ACTIVE   = 'cptt_active_order_form_id';
    const OPT_DISABLED = 'cptt_order_form_disabled';
    const OPT_PANEL_BUTTONS = 'cptt_bale_panel_buttons';

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('init',                  [$this, 'register_cpt']);
        add_action('admin_menu',            [$this, 'menu']);
        add_action('wp_ajax_cptt_form_save',        [$this, 'ajax_save_form']);
        add_action('wp_ajax_cptt_form_delete',      [$this, 'ajax_delete_form']);
        add_action('wp_ajax_cptt_form_activate',    [$this, 'ajax_activate_form']);
        add_action('wp_ajax_cptt_form_deactivate',  [$this, 'ajax_deactivate_form']);
        add_action('wp_ajax_cptt_form_export',      [$this, 'ajax_export_form']);
        add_action('wp_ajax_cptt_form_import',      [$this, 'ajax_import_form']);
        add_action('wp_ajax_cptt_panel_buttons_save', [$this, 'ajax_save_panel_buttons']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
    }

    /* =====================================================================
     * REGISTER CPT
     * ================================================================== */
    public function register_cpt() {
        if (post_type_exists('cptt_order_form')) return;
        register_post_type('cptt_order_form', [
            'public'             => false,
            'show_ui'            => false,
            'show_in_menu'       => false,
            'show_in_admin_bar'  => false,
            'supports'           => ['title'],
            'show_in_rest'       => false,
            'publicly_queryable' => false,
            'exclude_from_search'=> true,
        ]);
    }

    /* =====================================================================
     * INSTALL DEFAULTS
     * ================================================================== */
    public static function install_defaults() {
        $q = new WP_Query(['post_type'=>'cptt_order_form','posts_per_page'=>1,'fields'=>'ids']);
        if ($q->have_posts()) return;
        $id = wp_insert_post(['post_type'=>'cptt_order_form','post_status'=>'publish','post_title'=>'فرم سفارش بله — پیش‌فرض حرفه‌ای']);
        if ($id && !is_wp_error($id)) {
            update_post_meta($id, '_cptt_form_fields', self::template_service());
            update_option(self::OPT_ACTIVE, (int)$id, false);
            delete_option(self::OPT_DISABLED);
        }
    }

    /* =====================================================================
     * FIELD TYPES
     * ================================================================== */
    public static function available_types() {
        return [
            'intro'    => ['label'=>'✨ پیام/راهنما',    'desc'=>'نمایش متن بدون دریافت پاسخ',           'icon'=>'✨', 'can_branch'=>false],
            'text'     => ['label'=>'📝 پاسخ کوتاه',    'desc'=>'نام، عنوان، کد، توضیح کوتاه',          'icon'=>'📝', 'can_branch'=>false],
            'textarea' => ['label'=>'📄 توضیح کامل',    'desc'=>'پاسخ طولانی در یک پیام',               'icon'=>'📄', 'can_branch'=>false],
            'number'   => ['label'=>'🔢 عدد/تعداد',     'desc'=>'تعداد، بودجه، متراژ و...',             'icon'=>'🔢', 'can_branch'=>false],
            'phone'    => ['label'=>'📞 شماره تماس',    'desc'=>'دریافت شماره معتبر',                   'icon'=>'📞', 'can_branch'=>false],
            'email'    => ['label'=>'📧 ایمیل',          'desc'=>'دریافت ایمیل اختیاری/اجباری',          'icon'=>'📧', 'can_branch'=>false],
            'buttons'  => ['label'=>'🧊 دکمه انتخابی',  'desc'=>'انتخاب یک گزینه — قابل شرطی‌سازی',   'icon'=>'🧊', 'can_branch'=>true],
            'multi'    => ['label'=>'☑️ چندانتخابی',    'desc'=>'انتخاب چند گزینه با دکمه',             'icon'=>'☑️', 'can_branch'=>false],
            'date'     => ['label'=>'📅 تاریخ/مهلت',    'desc'=>'دریافت تاریخ به‌صورت متن',             'icon'=>'📅', 'can_branch'=>false],
            'address'  => ['label'=>'📍 آدرس',           'desc'=>'آدرس کامل یا لوکیشن متنی',            'icon'=>'📍', 'can_branch'=>false],
            'file'     => ['label'=>'📎 فایل/تصویر',    'desc'=>'یک یا چند فایل/عکس',                  'icon'=>'📎', 'can_branch'=>false],
            'payment'  => ['label'=>'💳 پرداخت',         'desc'=>'نمایش کارت‌به‌کارت/درگاه و رسید',    'icon'=>'💳', 'can_branch'=>false],
            'confirm'  => ['label'=>'✅ تأیید نهایی',   'desc'=>'پیش‌نمایش و تأیید قبل از ثبت',        'icon'=>'✅', 'can_branch'=>false],
        ];
    }

    /* =====================================================================
     * AUTO-FILL FIELD MAPPING — فیلدهایی که از پروفایل پر می‌شوند
     * ================================================================== */
    public static function autofill_map() {
        return [
            'phone'   => ['meta_keys'=>['billing_phone','cptt_user_phone','mobile','phone'], 'label'=>'شماره موبایل'],
            'email'   => ['meta_keys'=>['user_email'], 'user_prop'=>'user_email',           'label'=>'ایمیل'],
            'address' => ['meta_keys'=>['billing_address_1','shipping_address_1'],          'label'=>'آدرس'],
        ];
    }

    /**
     * بررسی و برگرداندن مقدار autofill برای یک فیلد و یک کاربر
     * @return string|null  مقدار یافت‌شده یا null اگر نبود
     */
    public static function get_autofill_value($field, $user) {
        if (!$user || !($user instanceof WP_User)) return null;
        $type = $field['type'] ?? 'text';
        $map  = self::autofill_map();
        if (!isset($map[$type])) return null;
        $entry = $map[$type];
        // user_prop مثل user_email
        if (!empty($entry['user_prop'])) {
            $val = (string)($user->{$entry['user_prop']} ?? '');
            if ($val !== '') return $val;
        }
        // meta_keys
        foreach ($entry['meta_keys'] as $key) {
            $val = (string)get_user_meta($user->ID, $key, true);
            if ($val !== '') return $val;
        }
        // نام کاربر برای فیلدهای text با label حاوی "نام"
        if ($type === 'text') {
            $label = mb_strtolower((string)($field['label'] ?? ''));
            if (mb_strpos($label, 'نام') !== false) {
                $name = trim((string)$user->display_name);
                if ($name === '') $name = trim($user->first_name . ' ' . $user->last_name);
                if ($name !== '') return $name;
            }
        }
        return null;
    }

    /* =====================================================================
     * TEMPLATES
     * ================================================================== */
    public static function template_service() {
        $f = self::fid();
        return [
            ['id'=>self::fid(),'type'=>'intro','label'=>'شروع سفارش','message'=>'سلام 👋 برای ثبت سفارش، چند سؤال کوتاه از شما می‌پرسیم.'],
            ['id'=>$f=self::fid(),'type'=>'buttons','label'=>'آیا سایت دارید؟','required'=>1,'options'=>"سایت دارم\nسایت ندارم",'layout'=>'grid',
                'branches'=>[
                    ['option'=>'سایت دارم',  'goto_label'=>'بهبود_سایت',  'final_message'=>"✅ بر اساس پاسخ‌های شما، پیشنهاد ما: ارتقاء سئو و بهبود تجربه کاربری سایت فعلی شماست."],
                    ['option'=>'سایت ندارم', 'goto_label'=>'راه_اندازی',  'final_message'=>"✅ بر اساس پاسخ‌های شما، پیشنهاد ما: طراحی سایت حرفه‌ای از صفر متناسب با کسب‌وکار شماست."],
                ]
            ],
            ['id'=>self::fid(),'type'=>'textarea','label'=>'توضیحات سفارش','required'=>1,'placeholder'=>'نیازتان را کامل توضیح دهید...','section_label'=>'بهبود_سایت'],
            ['id'=>self::fid(),'type'=>'number','label'=>'بودجه تقریبی (تومان)','required'=>0,'section_label'=>'بهبود_سایت'],
            ['id'=>self::fid(),'type'=>'text','label'=>'آدرس سایت فعلی','required'=>1,'section_label'=>'بهبود_سایت'],
            ['id'=>self::fid(),'type'=>'textarea','label'=>'نوع کسب‌وکار','required'=>1,'section_label'=>'راه_اندازی'],
            ['id'=>self::fid(),'type'=>'number','label'=>'بودجه طراحی (تومان)','required'=>0,'section_label'=>'راه_اندازی'],
            ['id'=>self::fid(),'type'=>'file','label'=>'نمونه کار یا فایل مرتبط','required'=>0,'multiple'=>1,'max_files'=>5],
            ['id'=>self::fid(),'type'=>'confirm','label'=>'تأیید نهایی سفارش','message'=>'اطلاعات را بررسی کنید و سفارش را ثبت کنید.'],
        ];
    }
    public static function template_product() {
        return [
            ['id'=>self::fid(),'type'=>'buttons','label'=>'نوع درخواست محصول','required'=>1,'options'=>"خرید محصول\nاستعلام قیمت\nسفارش عمده\nسفارشی‌سازی"],
            ['id'=>self::fid(),'type'=>'text','label'=>'نام محصول/مدل','required'=>1],
            ['id'=>self::fid(),'type'=>'number','label'=>'تعداد','required'=>1],
            ['id'=>self::fid(),'type'=>'address','label'=>'آدرس ارسال','required'=>0],
            ['id'=>self::fid(),'type'=>'phone','label'=>'شماره تماس','required'=>1],
            ['id'=>self::fid(),'type'=>'payment','label'=>'پرداخت سفارش','required'=>0,'amount'=>'','allow_later'=>1],
            ['id'=>self::fid(),'type'=>'confirm','label'=>'ثبت سفارش','message'=>'در صورت تأیید سفارش ثبت می‌شود.'],
        ];
    }
    public static function template_support() {
        return [
            ['id'=>self::fid(),'type'=>'buttons','label'=>'نوع درخواست','required'=>1,'options'=>"گزارش مشکل\nدرخواست تغییر\nارسال فایل\nپیگیری سفارش"],
            ['id'=>self::fid(),'type'=>'textarea','label'=>'شرح درخواست','required'=>1],
            ['id'=>self::fid(),'type'=>'multi','label'=>'اولویت‌ها','required'=>0,'options'=>"فوری\nنیاز به تماس\nنیاز به فاکتور\nنیاز به ارسال فایل"],
            ['id'=>self::fid(),'type'=>'file','label'=>'تصویر/فایل مرتبط','required'=>0,'multiple'=>1,'max_files'=>10],
            ['id'=>self::fid(),'type'=>'confirm','label'=>'تأیید درخواست','message'=>'درخواست شما برای تیم ارسال می‌شود.'],
        ];
    }
    private static function fid() { return 'f_' . wp_generate_password(7, false, false); }

    /* =====================================================================
     * DATA ACCESS
     * ================================================================== */
    public static function get_forms() {
        return get_posts(['post_type'=>'cptt_order_form','post_status'=>'any','numberposts'=>-1,'orderby'=>'date','order'=>'DESC']);
    }
    public static function get_form($id) {
        $p = get_post((int)$id);
        if (!$p || $p->post_type !== 'cptt_order_form') return null;
        $f = get_post_meta($p->ID, '_cptt_form_fields', true);
        return [
            'id'               => (int)$p->ID,
            'title'            => $p->post_title,
            'fields'           => is_array($f) ? $f : [],
            'target_product_id'=> (int)get_post_meta($p->ID, '_cptt_form_target_product_id', true),
            'target_cat_id'    => (int)get_post_meta($p->ID, '_cptt_form_target_cat_id', true),
            'bale_button'      => (array)(get_post_meta($p->ID, '_cptt_form_bale_button', true) ?: []),
            'final_message'    => (string)get_post_meta($p->ID, '_cptt_form_final_message', true),
            'autofill'         => (int)get_post_meta($p->ID, '_cptt_form_autofill', true),
            'submit_message'   => (string)get_post_meta($p->ID, '_cptt_form_submit_message', true),
        ];
    }
    public static function get_active_form() {
        if (get_option(self::OPT_DISABLED, 0)) return null;
        $id = (int)get_option(self::OPT_ACTIVE, 0);
        $f  = $id ? self::get_form($id) : null;
        if ($f && !empty($f['fields'])) return $f;
        foreach (self::get_forms() as $p) {
            $f = self::get_form($p->ID);
            if ($f && !empty($f['fields'])) return $f;
        }
        return null;
    }
    public static function get_form_for_product($product_id) {
        if (get_option(self::OPT_DISABLED, 0)) return null;
        $product_id = (int)$product_id;
        $cats = $product_id ? wp_get_post_terms($product_id, 'product_cat', ['fields'=>'ids']) : [];
        foreach (self::get_forms() as $p) {
            $f = self::get_form($p->ID);
            if (!$f) continue;
            if (!empty($f['target_product_id']) && (int)$f['target_product_id'] === $product_id) return $f;
            if (!empty($f['target_cat_id']) && in_array((int)$f['target_cat_id'], array_map('intval',(array)$cats), true)) return $f;
        }
        return self::get_active_form();
    }

    /**
     * پیدا کردن فرم از طریق form_key (برای deep link بله)
     */
    public static function get_form_by_key($key) {
        $key = sanitize_key($key);
        if (!$key) return null;
        // اگر عددی بود، مستقیم بر اساس ID
        if (ctype_digit($key)) return self::get_form((int)$key);
        // بررسی meta
        $posts = get_posts(['post_type'=>'cptt_order_form','post_status'=>'publish','meta_query'=>[['key'=>'_cptt_form_key','value'=>$key,'compare'=>'=']],'numberposts'=>1]);
        if (!empty($posts)) return self::get_form($posts[0]->ID);
        return null;
    }

    /* =====================================================================
     * PANEL BUTTONS
     * ================================================================== */
    public static function get_panel_buttons() {
        // دکمه‌های ثابت سیستم
        $defaults = [
            ['id'=>'cust_new_order',  'label'=>'📋 فرم سفارش پیش‌فرض بله', 'enabled'=>true, 'row_idx'=>0,'col_idx'=>0,'is_form'=>false],
            ['id'=>'cust_products',   'label'=>'🛍 محصولات',                'enabled'=>true, 'row_idx'=>0,'col_idx'=>1,'is_form'=>false],
            ['id'=>'cust_projects',   'label'=>'📁 پروژه‌های من',           'enabled'=>true, 'row_idx'=>1,'col_idx'=>0,'is_form'=>false],
            ['id'=>'cust_tasks',      'label'=>'📝 تسک‌های در انتظار',     'enabled'=>true, 'row_idx'=>2,'col_idx'=>0,'is_form'=>false],
            ['id'=>'cust_invoices',   'label'=>'📄 پیش‌فاکتورها',          'enabled'=>true, 'row_idx'=>2,'col_idx'=>1,'is_form'=>false],
            ['id'=>'cust_orders',     'label'=>'📦 سفارش‌های من',          'enabled'=>true, 'row_idx'=>3,'col_idx'=>0,'is_form'=>false],
            ['id'=>'cust_payments',   'label'=>'💳 پرداخت بدهی',           'enabled'=>true, 'row_idx'=>3,'col_idx'=>1,'is_form'=>false],
            ['id'=>'cust_requests',   'label'=>'📋 درخواست‌های من',        'enabled'=>true, 'row_idx'=>4,'col_idx'=>0,'is_form'=>false],
        ];

        $saved = get_option(self::OPT_PANEL_BUTTONS, []);
        if (!is_array($saved)) $saved = [];
        $saved_by_id = [];
        foreach ($saved as $sv) { if (!empty($sv['id'])) $saved_by_id[(string)$sv['id']] = $sv; }

        $out = [];

        // ─── دکمه‌های ثابت (با override از saved) ───
        foreach ($defaults as $def) {
            $sv = $saved_by_id[$def['id']] ?? null;
            $out[$def['id']] = [
                'id'      => $def['id'],
                'label'   => $sv ? sanitize_text_field((string)($sv['label'] ?? $def['label'])) : $def['label'],
                'enabled' => $sv ? (bool)($sv['enabled'] ?? $def['enabled']) : $def['enabled'],
                'row_idx' => $sv ? (int)($sv['row_idx'] ?? $def['row_idx']) : $def['row_idx'],
                'col_idx' => $sv ? (int)($sv['col_idx'] ?? $def['col_idx']) : $def['col_idx'],
                'is_form' => false,
            ];
        }

        // ─── دکمه‌های فرم: مستقیم از postmeta (منبع واحد) ───
        if (function_exists('get_posts')) {
            foreach (self::get_forms() as $_fp) {
                $_fbtn = get_post_meta((int)$_fp->ID, '_cptt_form_bale_button', true);
                if (!is_array($_fbtn) || empty($_fbtn['label'])) continue;
                $fid = 'form_start_' . (int)$_fp->ID;
                // override از saved اگر وجود داشت
                $sv  = $saved_by_id[$fid] ?? null;
                $out[$fid] = [
                    'id'      => $fid,
                    'label'   => $sv ? sanitize_text_field((string)($sv['label'] ?? $_fbtn['label'])) : sanitize_text_field((string)$_fbtn['label']),
                    'enabled' => $sv ? (bool)($sv['enabled'] ?? !empty($_fbtn['enabled'])) : !empty($_fbtn['enabled']),
                    'row_idx' => $sv ? (int)($sv['row_idx'] ?? ($_fbtn['row'] ?? 5)) : (int)($_fbtn['row'] ?? 5),
                    'col_idx' => $sv ? (int)($sv['col_idx'] ?? ($_fbtn['position'] ?? 0)) : (int)($_fbtn['position'] ?? 0),
                    'is_form' => true,
                    'form_id' => (int)$_fp->ID,
                ];
            }
        }

        usort($out, function($a,$b){
            return $a['row_idx'] === $b['row_idx'] ? $a['col_idx'] <=> $b['col_idx'] : $a['row_idx'] <=> $b['row_idx'];
        });
        return array_values($out);
    }

    /* =====================================================================
     * MENU & ASSETS
     * ================================================================== */
    public function menu() {
        add_submenu_page(
            'edit.php?post_type=cptt_project',
            'مدیریت دکمه‌ها و فرم‌های بله',
            '🤖 دکمه‌ها و فرم‌های بله',
            'manage_options',
            'cptt-form-builder',
            [$this, 'page']
        );
    }

    public function admin_assets($hook) {
        if (strpos((string)$hook, 'cptt-form-builder') === false) return;
        wp_enqueue_style('cptt-form-builder', CPTT_URL . 'assets/css/form-builder.css', [], CPTT_VERSION);
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script('cptt-form-builder', CPTT_URL . 'assets/js/form-builder.js', ['jquery','jquery-ui-sortable'], CPTT_VERSION, true);

        $forms_data = [];
        foreach (self::get_forms() as $p) {
            $bale_btn = get_post_meta($p->ID, '_cptt_form_bale_button', true) ?: [];
            $key      = (string)get_post_meta($p->ID, '_cptt_form_key', true);
            $forms_data[$p->ID] = [
                'id'         => (int)$p->ID,
                'title'      => $p->post_title,
                'bale_key'   => $key ?: ((string)$p->ID),
                'deep_link'  => 'https://ble.ir/start?startapp=form_' . ($key ?: $p->ID),
                'bale_button'=> (array)$bale_btn,
            ];
        }

        $bale_settings = get_option('cptt_bale_settings', []);
        $bot_username  = '';
        if (!empty($bale_settings['token'])) {
            $cached = get_transient('cptt_bot_username');
            if ($cached) { $bot_username = (string)$cached; }
            else {
                $res = wp_remote_get('https://tapi.bale.ai/bot' . trim($bale_settings['token']) . '/getMe', ['timeout'=>8,'sslverify'=>false]);
                if (!is_wp_error($res)) {
                    $body = json_decode(wp_remote_retrieve_body($res), true);
                    if (!empty($body['ok']) && !empty($body['result']['username'])) {
                        $bot_username = (string)$body['result']['username'];
                        set_transient('cptt_bot_username', $bot_username, HOUR_IN_SECONDS);
                    }
                }
            }
        }
        wp_localize_script('cptt-form-builder', 'CPTT_FB', [
            'ajax'          => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('cptt_form_builder'),
            'bot_username'  => $bot_username,
            'types'         => self::available_types(),
            'autofill_types'=> array_keys(self::autofill_map()),
            'templates'     => [
                'service' => self::template_service(),
                'product' => self::template_product(),
                'support' => self::template_support(),
            ],
            'panel_buttons' => self::get_panel_buttons(),
            'forms_data'    => $forms_data,
        ]);
    }

    /* =====================================================================
     * ADMIN PAGE
     * ================================================================== */
    public function page() {
        if (!current_user_can('manage_options')) return;
        $forms     = self::get_forms();
        $active_id = (int)get_option(self::OPT_ACTIVE, 0);
        $disabled  = (int)get_option(self::OPT_DISABLED, 0);
        $edit_id   = absint($_GET['form_id'] ?? 0);
        $tab       = sanitize_key($_GET['tab'] ?? 'builder');
        $editing   = $edit_id ? self::get_form($edit_id) : (!empty($forms) ? self::get_form($forms[0]->ID) : null);
        $cats      = taxonomy_exists('product_cat') ? get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'number'=>200]) : [];
        $products  = get_posts(['post_type'=>'product','post_status'=>'publish','numberposts'=>200,'orderby'=>'title','order'=>'ASC']);

        $form_key = $editing ? (string)get_post_meta($editing['id'], '_cptt_form_key', true) : '';
        if (!$form_key && $editing) $form_key = (string)$editing['id'];
        $bot_un_page = (string)get_transient('cptt_bot_username');
        $dl_prefix   = $bot_un_page ? ('https://ble.ir/' . ltrim($bot_un_page,'@') . '?start=form_') : 'https://ble.ir/BOT_USERNAME?start=form_';
        $deep_link   = $editing ? ($dl_prefix . $form_key) : '';

        ?>
        <div class="cptt-fb-app" dir="rtl">
            <!-- Hero -->
            <div class="cptt-fb-neoHero">
                <div>
                    <h1>🤖 فرم‌ساز سفارش ربات بله</h1>
                    <p>فرم‌های شرطی پیشرفته بسازید، دکمه‌های اختصاصی تنظیم کنید و تجربه‌ی کاربری بات را کنترل کنید.</p>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                    <button class="cptt-fb-primary" id="cptt-fb-save">💾 ذخیره فرم</button>
                    <label class="cptt-fb-import-label cptt-fb-soft" style="cursor:pointer;padding:12px 18px;">
                        📥 ایمپورت
                        <input type="file" id="cptt-fb-import-file" accept=".json" style="display:none">
                    </label>
                    <button class="cptt-fb-soft" id="cptt-fb-export">📤 اکسپورت</button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="cptt-fb-tabs">
                <a class="cptt-fb-tab <?php echo $tab==='builder'?'is-active':''; ?>" href="<?php echo esc_url(add_query_arg(['tab'=>'builder','form_id'=>$edit_id])); ?>">🛠 فرم‌ساز</a>
                <a class="cptt-fb-tab <?php echo $tab==='panel'?'is-active':''; ?>" href="<?php echo esc_url(add_query_arg(['tab'=>'panel','form_id'=>$edit_id])); ?>">🎛 پنل مشتری</a>
            </div>

            <?php if ($tab === 'panel'): ?>
            <!-- ===================== TAB: PANEL BUTTONS ===================== -->
            <div class="cptt-fb-panel-editor" id="cptt-panel-editor">
                <div class="cptt-fb-neoHero" style="margin-top:18px;">
                    <div>
                        <h2>🎛 مدیریت دکمه‌های پنل مشتری در بله</h2>
                        <p>دکمه‌ها را فعال/غیرفعال کنید، عنوان و جایگاه ردیف و ستون را تعیین کنید. دکمه‌های فرم‌های اختصاصی هم اینجا نمایش داده می‌شوند.</p>
                    </div>
                    <button class="cptt-fb-primary" id="cptt-panel-save">💾 ذخیره پنل</button>
                </div>

                <div class="cptt-panel-legend">
                    <span>📌 <b>ردیف:</b> شماره ردیف در کیبورد (0=اول)</span>
                    <span>📌 <b>ستون:</b> جایگاه در ردیف (0=چپ)</span>
                    <span>📌 <b>تنها:</b> این دکمه به تنهایی در ردیفش باشد</span>
                </div>

                <div class="cptt-panel-grid-header">
                    <span>↕</span><span>فعال</span><span>عنوان دکمه</span>
                    <span>ردیف</span><span>ستون</span><span>تنها</span><span>شناسه</span>
                </div>

                <div id="cptt-panel-grid" class="cptt-panel-grid">
                    <?php
                    // get_panel_buttons شامل دکمه‌های ثابت + دکمه‌های فرم‌های ذخیره‌شده است
                    // دکمه‌های فرم‌های دارای bale_button را مستقیم از postmeta می‌گیریم
                    $all_panel = self::get_panel_buttons();
                    // اضافه فرم‌هایی که در get_panel_buttons نیستند (هنوز ذخیره نشده‌اند)
                    $existing_form_ids = [];
                    foreach ($all_panel as $_pb) {
                        if (!empty($_pb['is_form'])) {
                            preg_match('/form_start_(\d+)/', (string)($_pb['id']??''), $_m);
                            if (!empty($_m[1])) $existing_form_ids[] = (int)$_m[1];
                        }
                    }
                    foreach (self::get_forms() as $_pfp) {
                        if (in_array($_pfp->ID, $existing_form_ids, true)) continue;
                        $_fbt = get_post_meta($_pfp->ID, '_cptt_form_bale_button', true);
                        if (!empty($_fbt['label'])) {
                            $all_panel[] = [
                                'id'      => 'form_start_' . $_pfp->ID,
                                'label'   => (string)$_fbt['label'],
                                'enabled' => !empty($_fbt['enabled']),
                                'row_idx' => (int)($_fbt['row'] ?? 5),
                                'col_idx' => (int)($_fbt['position'] ?? 0),
                                'solo'    => false,
                                'is_form' => true,
                            ];
                        }
                    }
                    foreach ($all_panel as $btn):
                        $is_form = !empty($btn['is_form']);
                    ?>
                    <div class="cptt-panel-item <?php echo $is_form ? 'is-form-btn' : ''; ?>" data-id="<?php echo esc_attr($btn['id']); ?>">
                        <div class="cptt-panel-drag-col">
                            <span class="cptt-fb-drag" title="بکشید">⠿</span>
                            <button class="cptt-panel-row-up" title="ردیف بالاتر">▲</button>
                            <button class="cptt-panel-row-dn" title="ردیف پایین‌تر">▼</button>
                        </div>
                        <label class="cptt-fb-toggle" title="فعال/غیرفعال">
                            <input type="checkbox" class="cptt-panel-enabled" <?php checked(!empty($btn['enabled'])); ?>>
                            <span class="cptt-fb-toggle-slider"></span>
                        </label>
                        <input class="cptt-panel-label" type="text" value="<?php echo esc_attr($btn['label']); ?>" placeholder="عنوان دکمه">
                        <div class="cptt-panel-pos-col">
                            <button class="cptt-panel-row-up" title="ردیف -1">↑</button>
                            <input class="cptt-panel-row" type="number" min="0" max="20" value="<?php echo (int)($btn['row_idx'] ?? 0); ?>" title="شماره ردیف">
                            <button class="cptt-panel-row-dn" title="ردیف +1">↓</button>
                        </div>
                        <div class="cptt-panel-pos-col">
                            <button class="cptt-panel-col-prev" title="ستون -1">←</button>
                            <input class="cptt-panel-col" type="number" min="0" max="5" value="<?php echo (int)($btn['col_idx'] ?? 0); ?>" title="شماره ستون">
                            <button class="cptt-panel-col-next" title="ستون +1">→</button>
                        </div>
                        <label title="تنها در ردیفش باشد">
                            <input type="checkbox" class="cptt-panel-solo" <?php checked(!empty($btn['solo'])); ?>>
                            <span>تنها</span>
                        </label>
                        <?php if ($is_form): ?>
                            <span class="cptt-fb-panel-id-badge form-badge" title="تنظیمات label و فعال‌بودن از فرم‌ساز تعریف می‌شود">📋 فرم #<?php echo (int)($btn['form_id'] ?? 0); ?></span>
                        <?php else: ?>
                            <span class="cptt-fb-panel-id-badge"><?php echo esc_html($btn['id']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="cptt-fb-panel-note">💡 هر تغییر باید ذخیره شود تا در بات بله اعمال گردد. دکمه‌های نارنجی مربوط به فرم‌های اختصاصی شما هستند.</p>
            </div>

            <?php else: ?>
            <!-- ===================== TAB: BUILDER ===================== -->
            <div class="cptt-fb-shell">
                <!-- Sidebar -->
                <aside class="cptt-fb-neoSide">
                    <button class="cptt-fb-primary cptt-fb-wide" id="cptt-fb-new-form">+ فرم جدید</button>
                    <div class="cptt-fb-sideTitle">فرم‌های موجود</div>
                    <?php foreach ($forms as $f): ?>
                    <a class="cptt-fb-formLink <?php echo ($editing && $editing['id']==$f->ID)?'is-active':''; ?>"
                       href="<?php echo esc_url(add_query_arg(['form_id'=>$f->ID,'tab'=>'builder'])); ?>">
                        <?php echo esc_html(get_the_title($f)); ?>
                        <?php if ($active_id==$f->ID && !$disabled): ?><b class="cptt-fb-active-badge">فعال</b><?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </aside>

                <!-- Workspace -->
                <main class="cptt-fb-workspace">
                    <?php if ($editing): ?>
                    <!-- Topbar -->
                    <div class="cptt-fb-topbar">
                        <input id="cptt-fb-form-title" value="<?php echo esc_attr($editing['title']); ?>" placeholder="نام فرم">
                        <input type="hidden" id="cptt-fb-form-id" value="<?php echo (int)$editing['id']; ?>">
                        <button class="cptt-fb-soft" id="cptt-fb-set-active">✅ فعال در بله</button>
                        <button class="cptt-fb-soft" id="cptt-fb-deactivate">⏸ غیرفعال</button>
                        <button class="cptt-fb-danger" id="cptt-fb-delete">🗑 حذف</button>
                    </div>

                    <!-- Deep Link & Bale Button -->
                    <div class="cptt-fb-deeplink-box">
                        <div class="cptt-fb-deeplink-row">
                            <span>🔗 لینک اختصاصی این فرم در بله:</span>
                            <code id="cptt-fb-deeplink"><?php echo esc_html($deep_link); ?></code>
                            <button class="cptt-fb-soft cptt-fb-copy-btn" data-target="cptt-fb-deeplink">📋 کپی</button>
                        </div>
                        <div class="cptt-fb-deeplink-row">
                            <label><span>🔑 کلید یکتا فرم (برای deep link):</span></label>
                            <input type="text" id="cptt-fb-form-key" value="<?php echo esc_attr($form_key); ?>" placeholder="مثلاً: website-order" style="direction:ltr;">
                        </div>
                    </div>

                    <!-- Bale Button Settings -->
                    <div class="cptt-fb-section-box">
                        <div class="cptt-fb-section-title">🎮 دکمه‌ی اختصاصی در پنل مشتری بله</div>
                        <div class="cptt-fb-bale-btn-grid">
                            <label>
                                <span>فعال بودن دکمه</span>
                                <label class="cptt-fb-toggle">
                                    <input type="checkbox" id="cptt-fb-btn-enabled" <?php checked(!empty($editing['bale_button']['enabled'])); ?>>
                                    <span class="cptt-fb-toggle-slider"></span>
                                </label>
                            </label>
                            <label>
                                <span>عنوان دکمه در بله</span>
                                <input type="text" id="cptt-fb-btn-label" value="<?php echo esc_attr($editing['bale_button']['label'] ?? ''); ?>" placeholder="مثلاً: 📋 سفارش سایت">
                            </label>
                            <label>
                                <span>جایگاه دکمه (عدد، کوچک‌تر = بالاتر)</span>
                                <input type="number" id="cptt-fb-btn-position" value="<?php echo esc_attr($editing['bale_button']['position'] ?? 0); ?>" min="0" max="20">
                            </label>
                            <label>
                                <span>ردیف (row) قرارگیری در کیبورد</span>
                                <input type="number" id="cptt-fb-btn-row" value="<?php echo esc_attr($editing['bale_button']['row'] ?? 0); ?>" min="0" max="10">
                            </label>
                        </div>
                        <p class="cptt-fb-hint">💡 اگر چند فرم دکمه‌ی فعال داشته باشند، همه در منوی مشتری نمایش داده می‌شوند.</p>
                    </div>

                    <!-- Targets -->
                    <div class="cptt-fb-section-box cptt-fb-targets">
                        <div class="cptt-fb-section-title">🎯 هدف‌گذاری فرم</div>
                        <label>
                            <span>فرم مخصوص دسته‌بندی محصول</span>
                            <select id="cptt-fb-target-cat">
                                <option value="0">— همه / بدون دسته —</option>
                                <?php foreach ($cats as $cat): ?>
                                <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected((int)($editing['target_cat_id'] ?? 0), (int)$cat->term_id); ?>>
                                    <?php echo esc_html($cat->name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>فرم مخصوص محصول خاص</span>
                            <select id="cptt-fb-target-product">
                                <option value="0">— بدون محصول خاص —</option>
                                <?php foreach ($products as $prod):
                                    $pcats = taxonomy_exists('product_cat') ? wp_get_post_terms($prod->ID, 'product_cat', ['fields'=>'ids']) : [];
                                ?>
                                <option value="<?php echo esc_attr($prod->ID); ?>"
                                        data-cats="<?php echo esc_attr(implode(',', array_map('intval', (array)$pcats))); ?>"
                                        <?php selected((int)($editing['target_product_id'] ?? 0), (int)$prod->ID); ?>>
                                    <?php echo esc_html(get_the_title($prod)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <!-- General Settings -->
                    <div class="cptt-fb-section-box">
                        <div class="cptt-fb-section-title">⚙ تنظیمات عمومی فرم</div>
                        <label class="cptt-fb-toggle-label">
                            <label class="cptt-fb-toggle">
                                <input type="checkbox" id="cptt-fb-autofill" <?php checked(!empty($editing['autofill'])); ?>>
                                <span class="cptt-fb-toggle-slider"></span>
                            </label>
                            <span>پرش خودکار از فیلدهایی که اطلاعاتشان در پروفایل کاربر وجود دارد</span>
                        </label>
                        <label class="full" style="margin-top:12px;">
                            <span>💡 پیام پیشنهادی قبل از تأیید (اختیاری - بر اساس شرط‌ها override می‌شود)</span>
                            <textarea id="cptt-fb-final-message" rows="2" placeholder="پیشنهاد یا راهنمایی نهایی برای کاربر..."><?php echo esc_textarea($editing['final_message'] ?? ''); ?></textarea>
                        </label>
                        <label class="full" style="margin-top:8px;">
                            <span>✅ پیام پس از ثبت موفق — پیام تشکر/تأیید</span>
                            <textarea id="cptt-fb-submit-message" rows="2" placeholder="مثلاً: اطلاعات شما دریافت شد، {name} عزیز! کارشناس ما در اسرع وقت تماس می‌گیرد."><?php echo esc_textarea($editing['submit_message'] ?? ''); ?></textarea>
                        </label>
                        <div class="cptt-fb-vars-hint">
                            <b>📌 متغیرهای قابل استفاده در پیام‌ها:</b>
                            <code>{name}</code> نام کاربر &nbsp;
                            <code>{phone}</code> شماره تماس &nbsp;
                            <code>{form}</code> عنوان فرم &nbsp;
                            <code>{date}</code> تاریخ امروز
                        </div>
                    </div>

                    <!-- Templates -->
                    <div class="cptt-fb-templates">
                        <button data-template="service">📋 تمپلیت خدمات (با شرط)</button>
                        <button data-template="product">📦 تمپلیت محصول</button>
                        <button data-template="support">🆘 تمپلیت پشتیبانی</button>
                    </div>

                    <!-- Builder Grid -->
                    <div class="cptt-fb-builderGrid">
                        <!-- Palette -->
                        <section class="cptt-fb-palette">
                            <h3>➕ افزودن مرحله</h3>
                            <?php foreach (self::available_types() as $k => $t): ?>
                            <button class="cptt-fb-add-field" data-type="<?php echo esc_attr($k); ?>">
                                <i><?php echo esc_html($t['icon']); ?></i>
                                <span><?php echo esc_html($t['label']); ?></span>
                                <small><?php echo esc_html($t['desc']); ?></small>
                                <?php if (!empty($t['can_branch'])): ?><span class="cptt-fb-branch-badge">شرطی</span><?php endif; ?>
                            </button>
                            <?php endforeach; ?>
                        </section>

                        <!-- Canvas -->
                        <section class="cptt-fb-canvas">
                            <div class="cptt-fb-canvasTitle">
                                مراحل فرم — با کشیدن مرتب کنید
                                <span class="cptt-fb-canvas-hint">فیلدهای نارنجی دارای منطق شرطی هستند</span>
                            </div>
                            <ul id="cptt-fb-fields" class="cptt-fb-fields">
                                <?php foreach ($editing['fields'] as $field) $this->render_field_row($field); ?>
                            </ul>
                        </section>
                    </div>

                    <?php else: ?>
                    <div class="cptt-fb-emptyBig">
                        <h2>هنوز فرمی ندارید</h2>
                        <p>یک فرم جدید بسازید یا از تمپلیت‌ها شروع کنید.</p>
                    </div>
                    <?php endif; ?>
                </main>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* =====================================================================
     * RENDER FIELD ROW
     * ================================================================== */
    public function render_field_row($field) {
        $types   = self::available_types();
        $type    = $field['type'] ?? 'text';
        $def     = $types[$type] ?? $types['text'];
        $id      = $field['id'] ?? self::fid();
        $branches= isset($field['branches']) && is_array($field['branches']) ? $field['branches'] : [];
        $has_branch = !empty($branches);
        $section_label = $field['section_label'] ?? '';
        ?>
        <li class="cptt-fb-field <?php echo $has_branch ? 'has-branch' : ''; ?>"
            data-id="<?php echo esc_attr($id); ?>"
            data-type="<?php echo esc_attr($type); ?>"
            data-can-branch="<?php echo !empty($def['can_branch']) ? '1' : '0'; ?>">

            <div class="cptt-fb-fieldHead">
                <span class="cptt-fb-drag" title="بکشید برای مرتب‌کردن">⠿</span>
                <span class="cptt-fb-type-icon"><?php echo esc_html($def['icon']); ?></span>
                <b class="cptt-fb-type-label"><?php echo esc_html($def['label']); ?></b>
                <input class="cptt-fb-field-label" value="<?php echo esc_attr($field['label'] ?? ''); ?>" placeholder="عنوان مرحله">
                <label class="cptt-fb-check-label">
                    <input type="checkbox" class="cptt-fb-field-required" <?php checked(!empty($field['required'])); ?>>
                    اجباری
                </label>
                <?php if (!empty($def['can_branch'])): ?>
                <button class="cptt-fb-branch-toggle" title="منطق شرطی">🔀 شرط</button>
                <?php endif; ?>
                <button class="cptt-fb-field-remove" title="حذف">×</button>
            </div>

            <div class="cptt-fb-fieldBody">
                <?php if ($section_label !== ''): ?>
                <div class="cptt-fb-section-hint">بخش: <b><?php echo esc_html($section_label); ?></b></div>
                <?php endif; ?>
                <input type="hidden" class="cptt-fb-field-section-label" value="<?php echo esc_attr($section_label); ?>">

                <label><span>پیام راهنما در بله</span>
                    <input class="cptt-fb-field-help" value="<?php echo esc_attr($field['help'] ?? ''); ?>">
                </label>

                <?php if (in_array($type, ['text','textarea','number','phone','email','date','address'], true)): ?>
                <label><span>نمونه/Placeholder</span>
                    <input class="cptt-fb-field-placeholder" value="<?php echo esc_attr($field['placeholder'] ?? ''); ?>">
                </label>
                <?php endif; ?>

                <?php if (in_array($type, ['buttons','multi'], true)): ?>
                <label class="full"><span>گزینه‌ها — هر گزینه در یک خط</span>
                    <textarea class="cptt-fb-field-options" rows="4"><?php echo esc_textarea($field['options'] ?? ''); ?></textarea>
                </label>
                <label><span>چیدمان</span>
                    <select class="cptt-fb-field-layout">
                        <option value="grid" <?php selected($field['layout'] ?? 'grid', 'grid'); ?>>شبکه‌ای</option>
                        <option value="list" <?php selected($field['layout'] ?? 'grid', 'list'); ?>>لیستی</option>
                    </select>
                </label>
                <?php endif; ?>

                <?php if ($type === 'file'): ?>
                <label><input type="checkbox" class="cptt-fb-field-multiple" <?php checked(!empty($field['multiple'])); ?>> چند فایل مجاز</label>
                <label><span>حداکثر فایل</span>
                    <input type="number" class="cptt-fb-field-max-files" value="<?php echo esc_attr($field['max_files'] ?? 5); ?>">
                </label>
                <?php endif; ?>

                <?php if ($type === 'payment'): ?>
                <label><span>مبلغ (اختیاری)</span>
                    <input class="cptt-fb-field-amount" value="<?php echo esc_attr($field['amount'] ?? ''); ?>">
                </label>
                <label><input type="checkbox" class="cptt-fb-field-allow-later" <?php checked(!empty($field['allow_later'])); ?>> امکان پرداخت بعداً</label>
                <?php endif; ?>

                <?php if ($type === 'intro' || $type === 'confirm'): ?>
                <label class="full"><span>متن پیام</span>
                    <textarea class="cptt-fb-field-message" rows="3"><?php echo esc_textarea($field['message'] ?? ''); ?></textarea>
                </label>
                <?php endif; ?>
            </div>

            <?php if (!empty($def['can_branch'])): ?>
            <!-- BRANCH EDITOR -->
            <div class="cptt-fb-branch-editor <?php echo $has_branch ? 'is-open' : ''; ?>">
                <div class="cptt-fb-branch-title">
                    🔀 منطق شرطی
                    <span class="cptt-fb-branch-info">برای هر گزینه، مرحله‌ای که بعد از آن نمایش داده شود را تعیین کنید.</span>
                </div>
                <div class="cptt-fb-branches">
                    <?php foreach ($branches as $bi => $branch): ?>
                    <div class="cptt-fb-branch-row">
                        <div class="cptt-fb-branch-left">
                            <label><span>اگر انتخاب کرد:</span>
                                <input class="cptt-fb-branch-option" value="<?php echo esc_attr($branch['option'] ?? ''); ?>" placeholder="عنوان گزینه">
                            </label>
                        </div>
                        <div class="cptt-fb-branch-arrow">→</div>
                        <div class="cptt-fb-branch-right">
                            <label><span>برو به بخش (section_label):</span>
                                <input class="cptt-fb-branch-goto" value="<?php echo esc_attr($branch['goto_label'] ?? ''); ?>" placeholder="بهبود_سایت">
                            </label>
                            <label class="full"><span>پیام نهایی اختصاصی این گزینه:</span>
                                <textarea class="cptt-fb-branch-final" rows="2" placeholder="پیشنهاد/پیام پایانی برای این مسیر..."><?php echo esc_textarea($branch['final_message'] ?? ''); ?></textarea>
                            </label>
                        </div>
                        <button class="cptt-fb-branch-remove" title="حذف شرط">×</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button class="cptt-fb-branch-add">+ افزودن شرط جدید</button>
                <p class="cptt-fb-branch-hint">
                    💡 <b>section_label</b>: در هر مرحله‌ای که می‌خواهید فقط در یک مسیر نمایش داده شود، همین برچسب را تنظیم کنید.
                    مراحل بدون section_label همیشه نمایش داده می‌شوند.
                </p>
            </div>
            <?php endif; ?>
        </li>
        <?php
    }

    /* =====================================================================
     * AJAX: SAVE FORM
     * ================================================================== */
    public function ajax_save_form() {
        check_ajax_referer('cptt_form_builder', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('no_access');

        $id    = absint($_POST['id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? 'فرم سفارش بله');
        $raw   = isset($_POST['fields']) && is_array($_POST['fields']) ? wp_unslash($_POST['fields']) : [];

        $fields = [];
        $valid_types = array_keys(self::available_types());
        foreach ($raw as $f) {
            if (!is_array($f)) continue;
            $type = sanitize_key($f['type'] ?? 'text');
            if (!in_array($type, $valid_types, true)) continue;

            // branches
            $branches = [];
            if (!empty($f['branches']) && is_array($f['branches'])) {
                foreach ($f['branches'] as $br) {
                    if (!is_array($br)) continue;
                    $branches[] = [
                        'option'        => sanitize_text_field($br['option'] ?? ''),
                        'goto_label'    => sanitize_key($br['goto_label'] ?? ''),
                        'final_message' => sanitize_textarea_field($br['final_message'] ?? ''),
                    ];
                }
            }

            $fields[] = [
                'id'            => sanitize_text_field($f['id'] ?? self::fid()),
                'type'          => $type,
                'label'         => sanitize_text_field($f['label'] ?? ''),
                'required'      => !empty($f['required']) ? 1 : 0,
                'placeholder'   => sanitize_text_field($f['placeholder'] ?? ''),
                'help'          => sanitize_text_field($f['help'] ?? ''),
                'options'       => sanitize_textarea_field($f['options'] ?? ''),
                'layout'        => sanitize_key($f['layout'] ?? 'grid'),
                'multiple'      => !empty($f['multiple']) ? 1 : 0,
                'max_files'     => max(1, absint($f['max_files'] ?? 5)),
                'amount'        => sanitize_text_field($f['amount'] ?? ''),
                'allow_later'   => !empty($f['allow_later']) ? 1 : 0,
                'message'       => sanitize_textarea_field($f['message'] ?? ''),
                'section_label' => sanitize_key($f['section_label'] ?? ''),
                'branches'      => $branches,
            ];
        }

        if (!$id) {
            $id = wp_insert_post(['post_type'=>'cptt_order_form','post_status'=>'publish','post_title'=>$title]);
        } else {
            wp_update_post(['ID'=>$id,'post_title'=>$title]);
        }
        if (!$id || is_wp_error($id)) wp_send_json_error('post_error');

        update_post_meta($id, '_cptt_form_fields', $fields);
        update_post_meta($id, '_cptt_form_target_product_id', absint($_POST['target_product_id'] ?? 0));
        update_post_meta($id, '_cptt_form_target_cat_id', absint($_POST['target_cat_id'] ?? 0));
        update_post_meta($id, '_cptt_form_final_message', sanitize_textarea_field($_POST['final_message'] ?? ''));
        update_post_meta($id, '_cptt_form_submit_message', sanitize_textarea_field($_POST['submit_message'] ?? ''));
        update_post_meta($id, '_cptt_form_autofill', !empty($_POST['autofill']) ? 1 : 0);

        // form_key
        $form_key = sanitize_key($_POST['form_key'] ?? '');
        if ($form_key !== '') update_post_meta($id, '_cptt_form_key', $form_key);

        // bale button
        $bale_button = [
            'enabled'  => !empty($_POST['bale_btn_enabled']) ? 1 : 0,
            'label'    => sanitize_text_field($_POST['bale_btn_label'] ?? ''),
            'position' => absint($_POST['bale_btn_position'] ?? 0),
            'row'      => absint($_POST['bale_btn_row'] ?? 0),
        ];
        update_post_meta($id, '_cptt_form_bale_button', $bale_button);

        $fk = $form_key ?: (string)$id;
        $bot_u = (string)get_transient('cptt_bot_username');
        $dl_base = $bot_u ? ('https://ble.ir/' . ltrim($bot_u,'@') . '?start=form_') : ('https://ble.ir/BOT_USERNAME?start=form_');
        wp_send_json_success([
            'id'        => $id,
            'deep_link' => $dl_base . $fk,
            'form_key'  => $fk,
        ]);
    }

    /* =====================================================================
     * AJAX: DELETE / ACTIVATE / DEACTIVATE
     * ================================================================== */
    public function ajax_delete_form() {
        check_ajax_referer('cptt_form_builder', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('no_access');
        $id = absint($_POST['id'] ?? 0);
        if ($id) wp_delete_post($id, true);
        if ((int)get_option(self::OPT_ACTIVE, 0) === $id) delete_option(self::OPT_ACTIVE);
        wp_send_json_success();
    }
    public function ajax_activate_form() {
        check_ajax_referer('cptt_form_builder', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('no_access');
        $id = absint($_POST['id'] ?? 0);
        update_option(self::OPT_ACTIVE, $id, false);
        delete_option(self::OPT_DISABLED);
        wp_send_json_success();
    }
    public function ajax_deactivate_form() {
        check_ajax_referer('cptt_form_builder', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('no_access');
        update_option(self::OPT_DISABLED, 1, false);
        wp_send_json_success();
    }

    /* =====================================================================
     * AJAX: EXPORT / IMPORT
     * ================================================================== */
    public function ajax_export_form() {
        check_ajax_referer('cptt_form_builder', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('no_access');
        $id   = absint($_POST['id'] ?? 0);
        $form = $id ? self::get_form($id) : null;
        if (!$form) wp_send_json_error('not_found');
        $export = [
            '_cptt_export_version' => '6.0',
            'title'                => $form['title'],
            'fields'               => $form['fields'],
            'final_message'        => $form['final_message'],
            'autofill'             => $form['autofill'],
            'bale_button'          => $form['bale_button'],
        ];
        wp_send_json_success(['json' => wp_json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]);
    }

    public function ajax_import_form() {
        check_ajax_referer('cptt_form_builder', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('no_access');

        $json = isset($_POST['json']) ? wp_unslash((string)$_POST['json']) : '';
        $data = json_decode($json, true);
        if (!$data || empty($data['fields']) || !is_array($data['fields'])) {
            wp_send_json_error('فایل JSON نامعتبر است.');
        }

        $title = sanitize_text_field($data['title'] ?? 'فرم وارد‌شده');
        $id    = wp_insert_post(['post_type'=>'cptt_order_form','post_status'=>'publish','post_title'=>$title]);
        if (!$id || is_wp_error($id)) wp_send_json_error('خطا در ایجاد فرم.');

        $valid_types = array_keys(self::available_types());
        $fields = [];
        foreach ($data['fields'] as $f) {
            if (!is_array($f)) continue;
            $type = sanitize_key($f['type'] ?? 'text');
            if (!in_array($type, $valid_types, true)) continue;
            $branches = [];
            if (!empty($f['branches']) && is_array($f['branches'])) {
                foreach ($f['branches'] as $br) {
                    $branches[] = [
                        'option'        => sanitize_text_field($br['option'] ?? ''),
                        'goto_label'    => sanitize_key($br['goto_label'] ?? ''),
                        'final_message' => sanitize_textarea_field($br['final_message'] ?? ''),
                    ];
                }
            }
            $fields[] = [
                'id'           => 'f_' . wp_generate_password(7, false, false),
                'type'         => $type,
                'label'        => sanitize_text_field($f['label'] ?? ''),
                'required'     => !empty($f['required']) ? 1 : 0,
                'placeholder'  => sanitize_text_field($f['placeholder'] ?? ''),
                'help'         => sanitize_text_field($f['help'] ?? ''),
                'options'      => sanitize_textarea_field($f['options'] ?? ''),
                'layout'       => sanitize_key($f['layout'] ?? 'grid'),
                'multiple'     => !empty($f['multiple']) ? 1 : 0,
                'max_files'    => max(1, absint($f['max_files'] ?? 5)),
                'amount'       => sanitize_text_field($f['amount'] ?? ''),
                'allow_later'  => !empty($f['allow_later']) ? 1 : 0,
                'message'      => sanitize_textarea_field($f['message'] ?? ''),
                'section_label'=> sanitize_key($f['section_label'] ?? ''),
                'branches'     => $branches,
            ];
        }
        update_post_meta($id, '_cptt_form_fields', $fields);
        update_post_meta($id, '_cptt_form_final_message', sanitize_textarea_field($data['final_message'] ?? ''));
        update_post_meta($id, '_cptt_form_autofill', !empty($data['autofill']) ? 1 : 0);
        if (!empty($data['bale_button']) && is_array($data['bale_button'])) {
            update_post_meta($id, '_cptt_form_bale_button', [
                'enabled'  => !empty($data['bale_button']['enabled']) ? 1 : 0,
                'label'    => sanitize_text_field($data['bale_button']['label'] ?? ''),
                'position' => absint($data['bale_button']['position'] ?? 0),
                'row'      => absint($data['bale_button']['row'] ?? 0),
            ]);
        }
        wp_send_json_success(['id'=>$id, 'title'=>$title]);
    }

    /* =====================================================================
     * AJAX: SAVE PANEL BUTTONS
     * ================================================================== */
    public function ajax_save_panel_buttons() {
        check_ajax_referer('cptt_form_builder', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('no_access');
        $raw = isset($_POST['buttons']) && is_array($_POST['buttons']) ? wp_unslash($_POST['buttons']) : [];
        $out = [];
        foreach ($raw as $btn) {
            if (!is_array($btn) || empty($btn['id'])) continue;
            $bid = sanitize_key((string)$btn['id']);
            // برای form_start_ فقط row/col/enabled/label ذخیره کن (label و enabled اصلی در postmeta فرم است)
            $out[] = [
                'id'      => $bid,
                'label'   => sanitize_text_field((string)($btn['label'] ?? '')),
                'enabled' => !empty($btn['enabled']),
                'row_idx' => (int)($btn['row_idx'] ?? 0),
                'col_idx' => (int)($btn['col_idx'] ?? 0),
            ];
        }
        update_option(self::OPT_PANEL_BUTTONS, $out, false);
        wp_send_json_success(['count' => count($out)]);
    }
}
