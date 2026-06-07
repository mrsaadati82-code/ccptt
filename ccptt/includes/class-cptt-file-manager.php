<?php
/**
 * CPTT File Manager v1.0
 * مدیریت فایل‌های پروژه — آپلود، حذف، دسته‌بندی، پیش‌نمایش
 */
if (!defined('ABSPATH')) exit;

class CPTT_File_Manager {
    private static $instance = null;

    public static function instance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_cptt_fm_upload',      [$this, 'ajax_upload']);
        add_action('wp_ajax_cptt_fm_delete',      [$this, 'ajax_delete']);
        add_action('wp_ajax_cptt_fm_rename',      [$this, 'ajax_rename']);
        add_action('wp_ajax_cptt_fm_set_category',[$this, 'ajax_set_category']);
        add_action('wp_ajax_cptt_fm_list',        [$this, 'ajax_list']);
    }

    /* ── Settings ── */
    public static function get_settings() {
        $opt = get_option('cptt_file_manager_settings', []);
        return array_merge([
            'max_file_mb'   => 20,
            'allowed_types' => 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar,mp4,mp3,txt',
            'categories'    => "عمومی\nطراحی\nمحتوا\nفنی\nمالی\nتحویل",
        ], is_array($opt) ? $opt : []);
    }

    public static function get_categories() {
        $s = self::get_settings();
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $s['categories'])));
        return array_values($lines ?: ['عمومی']);
    }

    /* ── DB helpers ── */
    public static function get_project_files($project_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT f.*, u.display_name as uploader_name FROM {$wpdb->prefix}cptt_project_files f
             LEFT JOIN {$wpdb->users} u ON u.ID = f.uploaded_by
             WHERE f.project_id = %d ORDER BY f.uploaded_at DESC",
            (int)$project_id
        ));
    }

    public static function get_file($file_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cptt_project_files WHERE id = %d",
            (int)$file_id
        ));
    }

    private static function can_manage_project($project_id) {
        if (current_user_can('manage_options')) return true;
        $uid = get_current_user_id();
        if (!$uid) return false;
        // admin یا کارشناس پروژه
        if (class_exists('CPTT_Core')) {
            $ids = CPTT_Core::get_project_expert_ids($project_id);
            if (in_array($uid, $ids, true)) return true;
        }
        return false;
    }

    private static function can_view_project($project_id) {
        if (self::can_manage_project($project_id)) return true;
        // مشتری پروژه
        $client = (int)get_post_meta($project_id, '_cptt_client_user_id', true);
        return $client && $client === get_current_user_id();
    }

    /* ── AJAX: list ── */
    public function ajax_list() {
        check_ajax_referer('cptt_expert_nonce', 'nonce');
        $pid = absint($_POST['project_id'] ?? 0);
        if (!$pid || !self::can_view_project($pid)) wp_send_json_error('دسترسی ندارید.', 403);

        $files  = self::get_project_files($pid);
        $result = [];
        foreach ($files as $f) {
            $result[] = $this->format_file($f);
        }
        wp_send_json_success(['files' => $result, 'categories' => self::get_categories()]);
    }

    /* ── AJAX: upload ── */
    public function ajax_upload() {
        check_ajax_referer('cptt_expert_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error('لاگین لازم است.', 401);

        $pid = absint($_POST['project_id'] ?? 0);
        if (!$pid || !self::can_view_project($pid)) wp_send_json_error('دسترسی ندارید.', 403);

        if (empty($_FILES['file'])) wp_send_json_error('فایلی ارسال نشده.', 400);

        $s         = self::get_settings();
        $max_bytes = (int)$s['max_file_mb'] * 1024 * 1024;
        $allowed   = array_map('trim', explode(',', strtolower($s['allowed_types'])));

        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) wp_send_json_error('خطا در آپلود: ' . $file['error'], 400);
        if ($file['size'] > $max_bytes) wp_send_json_error('حجم فایل بیشتر از حد مجاز (' . $s['max_file_mb'] . ' MB) است.', 400);

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) wp_send_json_error('نوع فایل مجاز نیست: ' . $ext, 400);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // آپلود به media library
        $upload = wp_handle_upload($file, ['test_form' => false, 'mimes' => $this->get_allowed_mimes($allowed)]);
        if (isset($upload['error'])) wp_send_json_error($upload['error'], 500);

        // ثبت در attachment
        $att_id = wp_insert_attachment([
            'post_mime_type' => $upload['type'],
            'post_title'     => sanitize_file_name(pathinfo($file['name'], PATHINFO_FILENAME)),
            'post_status'    => 'inherit',
            'post_parent'    => $pid,
        ], $upload['file']);
        if (is_wp_error($att_id)) wp_send_json_error($att_id->get_error_message(), 500);
        wp_update_attachment_metadata($att_id, wp_generate_attachment_metadata($att_id, $upload['file']));

        $category = sanitize_text_field($_POST['category'] ?? 'عمومی');
        $note     = sanitize_textarea_field($_POST['note'] ?? '');
        $uid      = get_current_user_id();

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'cptt_project_files', [
            'project_id'    => $pid,
            'attachment_id' => $att_id,
            'file_url'      => $upload['url'],
            'file_name'     => sanitize_file_name($file['name']),
            'file_size'     => $file['size'],
            'file_type'     => $upload['type'],
            'category'      => $category,
            'uploaded_by'   => $uid,
            'uploaded_at'   => current_time('mysql'),
            'note'          => $note,
        ], ['%d','%d','%s','%s','%d','%s','%s','%d','%s','%s']);

        $file_id = (int)$wpdb->insert_id;
        $row     = self::get_file($file_id);

        // اعلان به کارشناسان و مدیر
        $uploader = wp_get_current_user();
        $title    = get_the_title($pid);
        $msg      = "📎 فایل جدید در پروژه «{$title}» آپلود شد توسط {$uploader->display_name}: {$file['name']}";
        if (class_exists('CPTT_Expert')) {
            CPTT_Expert::instance()->notify_project_experts($pid, $uid, 'file_upload', $msg, CPTT_Expert::dashboard_url() . "#project-{$pid}");
        }
        if (class_exists('CPTT_Core')) CPTT_Core::activity_log('project', $pid, 'file_upload', "آپلود فایل: {$file['name']} توسط {$uploader->display_name}");

        wp_send_json_success(['file' => $this->format_file($row), 'msg' => 'فایل با موفقیت آپلود شد.']);
    }

    /* ── AJAX: delete ── */
    public function ajax_delete() {
        check_ajax_referer('cptt_expert_nonce', 'nonce');
        $file_id = absint($_POST['file_id'] ?? 0);
        $row     = self::get_file($file_id);
        if (!$row) wp_send_json_error('فایل یافت نشد.', 404);
        if (!self::can_manage_project($row->project_id) && (int)$row->uploaded_by !== get_current_user_id()) {
            wp_send_json_error('دسترسی ندارید.', 403);
        }
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'cptt_project_files', ['id' => $file_id], ['%d']);
        if ($row->attachment_id) wp_delete_attachment($row->attachment_id, true);
        if (class_exists('CPTT_Core')) CPTT_Core::activity_log('project', $row->project_id, 'file_delete', "حذف فایل: {$row->file_name}");
        wp_send_json_success(['msg' => 'فایل حذف شد.']);
    }

    /* ── AJAX: rename ── */
    public function ajax_rename() {
        check_ajax_referer('cptt_expert_nonce', 'nonce');
        $file_id  = absint($_POST['file_id'] ?? 0);
        $new_name = sanitize_text_field($_POST['name'] ?? '');
        if (!$file_id || !$new_name) wp_send_json_error('اطلاعات ناقص.', 400);
        $row = self::get_file($file_id);
        if (!$row) wp_send_json_error('فایل یافت نشد.', 404);
        if (!self::can_manage_project($row->project_id)) wp_send_json_error('دسترسی ندارید.', 403);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'cptt_project_files', ['file_name' => $new_name], ['id' => $file_id], ['%s'], ['%d']);
        wp_send_json_success(['msg' => 'نام فایل تغییر کرد.', 'name' => $new_name]);
    }

    /* ── AJAX: set category ── */
    public function ajax_set_category() {
        check_ajax_referer('cptt_expert_nonce', 'nonce');
        $file_id  = absint($_POST['file_id'] ?? 0);
        $category = sanitize_text_field($_POST['category'] ?? 'عمومی');
        $row      = self::get_file($file_id);
        if (!$row) wp_send_json_error('فایل یافت نشد.', 404);
        if (!self::can_manage_project($row->project_id)) wp_send_json_error('دسترسی ندارید.', 403);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'cptt_project_files', ['category' => $category], ['id' => $file_id], ['%s'], ['%d']);
        wp_send_json_success(['msg' => 'دسته‌بندی بروزرسانی شد.']);
    }

    /* ── Helpers ── */
    private function format_file($row) {
        if (!$row) return null;
        $size_str = $this->format_size((int)$row->file_size);
        $ext      = strtolower(pathinfo($row->file_name, PATHINFO_EXTENSION));
        $is_image = in_array($ext, ['jpg','jpeg','png','gif','webp','svg'], true);
        $thumb    = ($is_image && $row->attachment_id) ? wp_get_attachment_image_url((int)$row->attachment_id, 'thumbnail') : '';
        $date_fa  = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime(strtotime($row->uploaded_at)) : $row->uploaded_at;
        return [
            'id'           => (int)$row->id,
            'project_id'   => (int)$row->project_id,
            'attachment_id'=> (int)$row->attachment_id,
            'file_url'     => (string)$row->file_url,
            'file_name'    => (string)$row->file_name,
            'file_size'    => (int)$row->file_size,
            'file_size_str'=> $size_str,
            'file_type'    => (string)$row->file_type,
            'ext'          => $ext,
            'is_image'     => $is_image,
            'thumb'        => $thumb,
            'category'     => (string)$row->category,
            'uploaded_by'  => (int)$row->uploaded_by,
            'uploader_name'=> isset($row->uploader_name) ? (string)$row->uploader_name : '',
            'uploaded_at'  => (string)$row->uploaded_at,
            'uploaded_at_fa'=> $date_fa,
            'note'         => (string)($row->note ?? ''),
            'can_delete'   => self::can_manage_project($row->project_id) || (int)$row->uploaded_by === get_current_user_id(),
        ];
    }

    private function format_size($bytes) {
        if ($bytes < 1024)       return $bytes . ' B';
        if ($bytes < 1048576)    return round($bytes/1024, 1) . ' KB';
        if ($bytes < 1073741824) return round($bytes/1048576, 1) . ' MB';
        return round($bytes/1073741824, 2) . ' GB';
    }

    private function get_allowed_mimes($exts) {
        $all   = wp_get_mime_types();
        $result= [];
        foreach ($all as $pattern => $mime) {
            foreach (explode('|', $pattern) as $e) {
                if (in_array(strtolower($e), $exts, true)) { $result[$pattern] = $mime; break; }
            }
        }
        return $result ?: $all;
    }
}
