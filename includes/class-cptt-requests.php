<?php
/**
 * CPTT Client Requests v1.0
 * درخواست‌های مشتری برای تغییر/اصلاح در پروژه
 */
if (!defined('ABSPATH')) exit;

class CPTT_Requests {
    private static $instance = null;

    public static function instance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_cptt_req_submit',  [$this, 'ajax_submit']);
        add_action('wp_ajax_cptt_req_list',    [$this, 'ajax_list']);
        add_action('wp_ajax_cptt_req_respond', [$this, 'ajax_respond']);
        add_action('wp_ajax_cptt_req_delete',  [$this, 'ajax_delete']);
    }

    /* ── Types ── */
    public static function types() {
        return [
            'change'    => ['label'=>'تغییر در پروژه','icon'=>'✏️','color'=>'#6366f1'],
            'fix'       => ['label'=>'اصلاح/خطا',     'icon'=>'🔧','color'=>'#f59e0b'],
            'feature'   => ['label'=>'درخواست ویژگی', 'icon'=>'✨','color'=>'#22c55e'],
            'content'   => ['label'=>'تغییر محتوا',   'icon'=>'📝','color'=>'#06b6d4'],
            'deadline'  => ['label'=>'تغییر مهلت',    'icon'=>'📅','color'=>'#8b5cf6'],
            'cancel'    => ['label'=>'لغو مرحله',      'icon'=>'🚫','color'=>'#ef4444'],
            'other'     => ['label'=>'سایر',           'icon'=>'💬','color'=>'#94a3b8'],
        ];
    }

    public static function statuses() {
        return [
            'pending'   => ['label'=>'در انتظار بررسی','color'=>'#f59e0b','icon'=>'⏳'],
            'approved'  => ['label'=>'تأیید شده',      'color'=>'#22c55e','icon'=>'✅'],
            'rejected'  => ['label'=>'رد شده',         'color'=>'#ef4444','icon'=>'❌'],
            'reviewing' => ['label'=>'در حال بررسی',   'color'=>'#6366f1','icon'=>'🔍'],
            'done'      => ['label'=>'انجام شده',       'color'=>'#10b981','icon'=>'🎉'],
        ];
    }

    /* ── DB ── */
    public static function get_requests($project_id = 0, $client_id = 0, $limit = 100) {
        global $wpdb;
        $where = ['1=1'];
        $vals  = [];
        if ($project_id) { $where[] = 'r.project_id = %d'; $vals[] = $project_id; }
        if ($client_id)  { $where[] = 'r.client_id = %d';  $vals[] = $client_id; }
        $where_sql = implode(' AND ', $where);
        $sql = "SELECT r.*, u.display_name as client_name, p.post_title as project_title
                FROM {$wpdb->prefix}cptt_requests r
                LEFT JOIN {$wpdb->users} u ON u.ID = r.client_id
                LEFT JOIN {$wpdb->posts} p ON p.ID = r.project_id
                WHERE {$where_sql} ORDER BY r.created_at DESC LIMIT %d";
        $vals[] = $limit;
        return $vals ? $wpdb->get_results($wpdb->prepare($sql, $vals)) : $wpdb->get_results($sql);
    }

    public static function get_request($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cptt_requests WHERE id = %d", (int)$id
        ));
    }

    /* ── AJAX: submit (client) ── */
    public function ajax_submit() {
        check_ajax_referer('cptt_frontend_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error('لاگین لازم است.', 401);

        $pid   = absint($_POST['project_id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? '');
        $desc  = sanitize_textarea_field($_POST['description'] ?? '');
        $type  = sanitize_key($_POST['type'] ?? 'other');
        $prio  = sanitize_key($_POST['priority'] ?? 'normal');
        $uid   = get_current_user_id();

        if (!$pid || !$title) wp_send_json_error('اطلاعات ناقص.', 400);
        // بررسی که این پروژه متعلق به این مشتری است
        $client_id = (int)get_post_meta($pid, '_cptt_client_user_id', true);
        if ($client_id !== $uid && !current_user_can('manage_options')) {
            wp_send_json_error('این پروژه متعلق به شما نیست.', 403);
        }

        $att_url = '';
        if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $up = wp_handle_upload($_FILES['attachment'], ['test_form' => false]);
            if ($up && !isset($up['error'])) $att_url = $up['url'];
        }

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'cptt_requests', [
            'project_id'  => $pid,
            'client_id'   => $uid,
            'title'       => $title,
            'description' => $desc,
            'type'        => in_array($type, array_keys(self::types()), true) ? $type : 'other',
            'priority'    => in_array($prio, ['low','normal','high','urgent'], true) ? $prio : 'normal',
            'status'      => 'pending',
            'attachment_url' => $att_url,
            'created_at'  => current_time('mysql'),
        ], ['%d','%d','%s','%s','%s','%s','%s','%s','%s']);

        $req_id  = (int)$wpdb->insert_id;
        $project_title = get_the_title($pid);
        $user    = wp_get_current_user();
        $types   = self::types();
        $t_label = $types[$type]['label'] ?? 'درخواست';
        $msg     = "📋 درخواست جدید از مشتری «{$user->display_name}»\n\nنوع: {$t_label}\nپروژه: {$project_title}\nعنوان: {$title}";

        // اعلان کارشناسان
        if (class_exists('CPTT_Expert')) {
            CPTT_Expert::instance()->notify_project_experts($pid, 0, 'client_request', $msg,
                CPTT_Expert::dashboard_url() . "#project-{$pid}"
            );
        }
        // اعلان مدیر از طریق بله
        if (class_exists('CPTT_Bale')) {
            $settings = CPTT_Bale::get_settings();
            if (!empty($settings['admin_id'])) {
                CPTT_Bale::send_message($settings['admin_id'], $msg . "\n\n🆔 شماره درخواست: #{$req_id}");
            }
        }
        if (class_exists('CPTT_Core')) CPTT_Core::activity_log('project', $pid, 'client_request', "درخواست جدید: {$title}");

        wp_send_json_success(['id' => $req_id, 'msg' => 'درخواست شما با موفقیت ثبت شد. کارشناسان در اسرع وقت بررسی می‌کنند.']);
    }

    /* ── AJAX: list ── */
    public function ajax_list() {
        check_ajax_referer('cptt_expert_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error('دسترسی ندارید.', 401);

        $pid = absint($_POST['project_id'] ?? 0);
        if (!$pid) wp_send_json_error('پروژه مشخص نشده.', 400);

        // کارشناس یا ادمین می‌تونه همه رو ببینه
        $is_expert = current_user_can('manage_options') || (class_exists('CPTT_Core') && in_array(get_current_user_id(), CPTT_Core::get_project_expert_ids($pid), true));
        $client_id = $is_expert ? 0 : get_current_user_id();

        $rows   = self::get_requests($pid, $client_id);
        $types  = self::types();
        $stats  = self::statuses();
        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'id'          => (int)$r->id,
                'project_id'  => (int)$r->project_id,
                'client_id'   => (int)$r->client_id,
                'client_name' => (string)($r->client_name ?? ''),
                'title'       => (string)$r->title,
                'description' => (string)$r->description,
                'type'        => (string)$r->type,
                'type_label'  => $types[$r->type]['label'] ?? $r->type,
                'type_icon'   => $types[$r->type]['icon'] ?? '💬',
                'type_color'  => $types[$r->type]['color'] ?? '#94a3b8',
                'priority'    => (string)$r->priority,
                'status'      => (string)$r->status,
                'status_label'=> $stats[$r->status]['label'] ?? $r->status,
                'status_color'=> $stats[$r->status]['color'] ?? '#94a3b8',
                'status_icon' => $stats[$r->status]['icon'] ?? '⏳',
                'attachment_url'=> (string)$r->attachment_url,
                'response'    => (string)($r->response ?? ''),
                'responded_at'=> (string)($r->responded_at ?? ''),
                'created_at'  => (string)$r->created_at,
                'created_fa'  => class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime(strtotime($r->created_at)) : $r->created_at,
                'can_respond' => $is_expert,
                'can_delete'  => $is_expert || (int)$r->client_id === get_current_user_id(),
            ];
        }
        wp_send_json_success(['requests' => $result, 'types' => $types, 'statuses' => $stats, 'is_expert' => $is_expert]);
    }

    /* ── AJAX: respond (expert/admin) ── */
    public function ajax_respond() {
        check_ajax_referer('cptt_expert_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error('دسترسی ندارید.', 401);

        $req_id   = absint($_POST['req_id'] ?? 0);
        $status   = sanitize_key($_POST['status'] ?? 'reviewing');
        $response = sanitize_textarea_field($_POST['response'] ?? '');
        $req      = self::get_request($req_id);
        if (!$req) wp_send_json_error('درخواست یافت نشد.', 404);

        $is_expert = current_user_can('manage_options') || (class_exists('CPTT_Core') && in_array(get_current_user_id(), CPTT_Core::get_project_expert_ids($req->project_id), true));
        if (!$is_expert) wp_send_json_error('دسترسی ندارید.', 403);

        $valid_statuses = array_keys(self::statuses());
        if (!in_array($status, $valid_statuses, true)) $status = 'reviewing';

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'cptt_requests', [
            'status'       => $status,
            'response'     => $response,
            'responded_by' => get_current_user_id(),
            'responded_at' => current_time('mysql'),
        ], ['id' => $req_id], ['%s','%s','%d','%s'], ['%d']);

        // اعلان به مشتری
        $statuses = self::statuses();
        $st_label = $statuses[$status]['label'] ?? $status;
        $project_title = get_the_title($req->project_id);
        $msg = "📋 درخواست شما در پروژه «{$project_title}» — {$st_label}\n\nعنوان: {$req->title}" .
               ($response ? "\n\n💬 پاسخ: {$response}" : '');

        if (class_exists('CPTT_Expert')) {
            CPTT_Expert::instance()->insert_notification(
                $req->client_id, 'request_update', $msg, $req->project_id, ''
            );
        }
        if (class_exists('CPTT_Bale')) {
            $bale_chat = get_user_meta($req->client_id, '_cptt_bale_chat_id', true);
            if ($bale_chat) CPTT_Bale::send_message($bale_chat, $msg);
        }
        if (class_exists('CPTT_Core')) CPTT_Core::activity_log('project', $req->project_id, 'request_update', "پاسخ به درخواست #{$req_id}: {$st_label}");

        wp_send_json_success(['msg' => 'پاسخ ثبت شد.', 'status' => $status, 'status_label' => $st_label]);
    }

    /* ── AJAX: delete ── */
    public function ajax_delete() {
        check_ajax_referer('cptt_expert_nonce', 'nonce');
        $req_id = absint($_POST['req_id'] ?? 0);
        $req    = self::get_request($req_id);
        if (!$req) wp_send_json_error('درخواست یافت نشد.', 404);
        $uid = get_current_user_id();
        $is_owner  = (int)$req->client_id === $uid;
        $is_expert = current_user_can('manage_options') || (class_exists('CPTT_Core') && in_array($uid, CPTT_Core::get_project_expert_ids($req->project_id), true));
        if (!$is_owner && !$is_expert) wp_send_json_error('دسترسی ندارید.', 403);
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'cptt_requests', ['id' => $req_id], ['%d']);
        wp_send_json_success(['msg' => 'درخواست حذف شد.']);
    }
}
