<?php
if ( ! defined('ABSPATH') ) exit;

class CPTT_Report {

	private static $instance = null;

	public static function instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action('admin_post_cptt_view_report', [$this, 'render_report_page']);
	}

	public static function is_project_complete($project_id) {
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps) || empty($steps)) return false;

		foreach ($steps as $s) {
			if (($s['status'] ?? 'todo') !== 'done') return false;
		}
		return true;
	}

	public static function user_can_access($project_id, $user_id) {
		$user_id = (int)$user_id;
		if (!$user_id) return false;

		if (user_can($user_id, 'manage_options')) return true;

		$client_id = (int)get_post_meta($project_id, '_cptt_client_user_id', true);
		if ($client_id && $client_id === $user_id) return true;

		if (class_exists('CPTT_Core') && method_exists('CPTT_Core', 'get_project_expert_ids')) {
			$experts = CPTT_Core::get_project_expert_ids($project_id);
			if (in_array($user_id, $experts, true)) return true;
		}

		return false;
	}

	private function user_name($id) {
		$u = $id ? get_user_by('id', (int)$id) : null;
		return $u ? $u->display_name : '';
	}

	private function branding() {
		// اگر قبلاً CPTT_Settings را اضافه کرده‌ای استفاده می‌کنیم، وگرنه fallback
		if (class_exists('CPTT_Settings') && method_exists('CPTT_Settings', 'get')) {
			return CPTT_Settings::get();
		}

		return [
			'brand_name'    => get_bloginfo('name'),
			'site_url'      => home_url('/'),
			'primary_color' => '#22c55e',
			'footer_text'   => 'این گزارش به صورت خودکار تولید شده است.',
			'logo_id'       => 0,
			'sign_id'       => 0,
			'stamp_id'      => 0,
			'manager_name'  => '',
			'manager_title' => '',
		];
	}

	private function att_url($id) {
		$id = (int)$id;
		if (!$id) return '';
		$url = wp_get_attachment_image_url($id, 'large');
		return $url ? $url : '';
	}

	public function render_report_page() {
		if (!is_user_logged_in()) wp_die('برای مشاهده گزارش باید وارد شوید.');

		$project_id = isset($_GET['project_id']) ? absint($_GET['project_id']) : 0;
		if (!$project_id || get_post_type($project_id) !== 'cptt_project') wp_die('پروژه معتبر نیست.');

		check_admin_referer('cptt_view_report_' . $project_id);

		if (!self::user_can_access($project_id, get_current_user_id())) wp_die('شما به این گزارش دسترسی ندارید.');
		if (!self::is_project_complete($project_id)) wp_die('گزارش فقط پس از تکمیل پروژه قابل مشاهده است.');

		$brand = $this->branding();
		$primary = $brand['primary_color'] ?: '#22c55e';

		$title = get_the_title($project_id);
		$created_fa = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime((int) current_time('timestamp', true)) : date('Y-m-d H:i');

		$client_id = (int)get_post_meta($project_id, '_cptt_client_user_id', true);
		$client_name = $client_id ? $this->user_name($client_id) : '';

		$expert_names = [];
		if (class_exists('CPTT_Core') && method_exists('CPTT_Core', 'get_project_expert_ids')) {
			$ids = CPTT_Core::get_project_expert_ids($project_id);
			foreach ($ids as $id) {
				$n = $this->user_name($id);
				if ($n) $expert_names[] = $n;
			}
		}

		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];

		$last_update = (string)get_post_meta($project_id, '_cptt_last_update_fa', true);

		$logo = $this->att_url($brand['logo_id'] ?? 0);
		$sign = $this->att_url($brand['sign_id'] ?? 0);
		$stamp = $this->att_url($brand['stamp_id'] ?? 0);

		// خروجی HTML مستقل (بدون قالب وردپرس) برای چاپ تمیز
		header('Content-Type: text/html; charset=utf-8');

		?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html('گزارش پروژه - ' . $title); ?></title>

	<style>
		:root{
			--primary: <?php echo esc_html($primary); ?>;
			--text: #0f172a;
			--muted: #64748b;
			--border: #e5e7eb;
			--bg: #f8fafc;
		}
		*{ box-sizing:border-box; }
		body{
			margin:0;
			background: var(--bg);
			color: var(--text);
			font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif;
			line-height: 1.9;
		}
		.wrap{
			max-width: 980px;
			margin: 24px auto;
			padding: 0 14px;
		}
		.topbar{
			position: relative;
			display:flex;
			align-items:center;
			justify-content: center;
			gap: 12px;
			margin-bottom: 14px;
		}
		.brand{
			display:flex;
			align-items:center;
			justify-content:center;
			gap: 10px;
			width: 100%;
		}

			.brandCenter{
			justify-content:center;
		}
		.brand img{
			max-height: 44px;
			max-width: 160px;
			object-fit: contain;
			background: #fff;
			border: 1px solid var(--border);
			border-radius: 10px;
			padding: 6px;
		}
		.brandTitle{
			font-weight: 900;
			font-size: 16px;
		}
		.brandUrl{
			color: var(--muted);
			font-size: 12px;
		}

		.actions{
			position: absolute;
			left: 0;
			top: 0;

			display:flex;
			gap: 8px;
			flex-wrap: wrap;
		}
		.btn{
			border: 1px solid var(--border);
			background: #fff;
			padding: 10px 12px;
			border-radius: 12px;
			cursor: pointer;
			font-weight: 800;
			text-decoration:none;
			color: var(--text);
		}
		.btnPrimary{
			border-color: rgba(34,197,94,0.25);
			background: rgba(34,197,94,0.12);
		}
		/* ===== Header text blocks ===== */
		.autoText{
		margin-top: 6px;
		color: var(--muted);
		font-size: 13px;
		}

		.siteBlock{
		margin-top: 6px;
		text-align: center;
		}

		.siteBlock .brandTitle{
		font-size: 14px;
		font-weight: 950;
		}

		.siteBlock .brandUrl{
		font-size: 12px;
		color: var(--muted);
		}

		/* ===== PDF Hint ===== */
		.pdfHint{
		margin: 10px 0 0;
		padding: 10px 12px;
		background: #fff7ed;
		border: 1px solid #fed7aa;
		border-radius: 14px;
		color: #9a3412;
		font-weight: 800;
		font-size: 13px;
		}

		/* ===== Step layout (new order) ===== */
		.stepTitle{
		font-weight: 950;
		font-size: 15px;
		margin: 0; /* قبلاً margin داشت */
		}

		.stepDesc{
		margin-top: 6px;
		color: #111827;
		font-size: 13px;
		}

		.stepMeta{
		margin-top: 10px;
		padding-top: 8px;
		border-top: 1px dashed #eef2f7;
		}

		/* ===== Mobile: actions sticky at bottom ===== */
		@media (max-width: 640px){
		.wrap{
			margin: 14px auto;
			padding-bottom: 92px; /* جا برای نوار دکمه‌ها */
		}

		.actions{
			position: fixed;
			left: 0;
			right: 0;
			bottom: 0;
			top: auto;

			padding: 10px 14px;
			background: #fff;
			border-top: 1px solid var(--border);
			box-shadow: 0 -10px 25px rgba(15,23,42,.08);

			flex-wrap: nowrap;
			z-index: 999;
		}

		.actions .btn{
			flex: 1;
			text-align: center;
		}
		}

		/* ===== Final confirmation box (signBox) responsive + print safe ===== */
		.signBox > div{
		flex: 1 1 280px;
		}

		.signBox img{
		max-width: 100%;
		height: auto;
		}

		@media (max-width: 520px){
		.signBox{
			flex-direction: column;
			align-items: stretch;
		}

		.signBox img{
			max-height: 90px;
		}
		}

		@media print{
		.pdfHint{ display:none !important; }

		.signBox{
			flex-wrap: nowrap;
			align-items: flex-start;
		}

		.signBox > div{
			width: 50%;
		}

		.signBox img{
			max-height: 95px;
		}

		.signCard{
			break-inside: avoid;
			page-break-inside: avoid;
		}
		}
		.card{
			background:#fff;
			border: 1px solid var(--border);
			border-radius: 16px;
			padding: 14px;
			box-shadow: 0 14px 36px rgba(15,23,42,.06);
			margin-bottom: 14px;
		}

		.h1{
			font-size: 18px;
			font-weight: 950;
			margin: 0 0 6px;
		}
		.meta{
			color: var(--muted);
			font-size: 13px;
		}

		.grid2{
			display:grid;
			grid-template-columns: 1fr 1fr;
			gap: 12px;
			margin-top: 10px;
		}
		@media (max-width: 720px){
			.grid2{ grid-template-columns: 1fr; }
		}

		.kv{
			border: 1px solid #f1f5f9;
			background: #fbfdff;
			border-radius: 14px;
			padding: 10px 12px;
		}
		.kv b{ font-weight: 900; }

		.step{
			border: 1px solid #eef2f7;
			border-radius: 16px;
			padding: 12px;
			margin-top: 12px;
		}
		.stepHead{
			display:flex;
			align-items:center;
			justify-content: space-between;
			gap: 10px;
			flex-wrap: wrap;
		}
		.badge{
			display:inline-flex;
			padding: 4px 10px;
			border-radius: 999px;
			font-size: 12px;
			font-weight: 900;
			border: 1px solid transparent;
		}
		.badgeDone{ background: rgba(34,197,94,0.12); color:#065f46; border-color: rgba(34,197,94,0.25); }
		.badgeCur{ background: rgba(245,158,11,0.12); color:#92400e; border-color: rgba(245,158,11,0.25); }
		.badgeTodo{ background: rgba(148,163,184,0.18); color:#475569; border-color: rgba(148,163,184,0.25); }

		.stepTitle{ font-weight: 950; font-size: 15px; margin: 8px 0 6px; }
		.small{ color: var(--muted); font-size: 12px; }

		.hr{ height:1px; background:#eef2f7; margin: 10px 0; }

		.cl{
			margin: 0;
			padding: 0 18px 0 0;
		}
		.cl li{ margin: 6px 0; }
		.done{ text-decoration: line-through; color:#065f46; }

		.cl a{
			color: #2563eb;
			text-decoration: underline;
			margin-right: 8px;
			font-weight: 800;
		}

		.signBox{
			display:flex;
			align-items:flex-start;
			justify-content: space-between;
			gap: 12px;
			flex-wrap: wrap;
		}
		.signBox img{
			max-height: 120px;
			max-width: 260px;
			object-fit: contain;
		}
		.signMeta{
			color: var(--muted);
			font-size: 12px;
			margin-top: 8px;
		}

		.footer{
			color: var(--muted);
			font-size: 12px;
			text-align: center;
			padding: 8px 0 20px;
		}

		/* ===== Print ===== */
		@page { size: A4; margin: 12mm; }
		@media print{
			body{ background:#fff; }
			.wrap{ max-width: none; margin:0; padding:0; }
			.actions{ display:none !important; }
			.card{ box-shadow:none; }
			a{ color:#000; text-decoration: none; }
			.step{ break-inside: avoid; page-break-inside: avoid; }
		}
	</style>
</head>
<body>
	<div class="wrap">
		<div class="topbar">
		<div class="brand brandCenter">
			<?php if ($logo): ?>
			<img src="<?php echo esc_url($logo); ?>" alt="logo">
			<?php else: ?>
			<div class="brandTitle"><?php echo esc_html($brand['brand_name'] ?? get_bloginfo('name')); ?></div>
			<?php endif; ?>
		</div>

		<div class="actions" role="group" aria-label="عملیات گزارش">
			<button class="btn btnPrimary" type="button" onclick="window.print()">چاپ گزارش</button>

			<button class="btn" type="button" onclick="cpttSaveAsPdf()">ذخیره PDF</button>

			<a class="btn" href="<?php echo esc_url( wp_get_referer() ?: home_url('/') ); ?>">بازگشت</a>
		</div>
		</div>

		<div class="pdfHint" id="cpttPdfHint" style="display:none;">
		برای ذخیره PDF، در پنجره چاپ مقصد (Destination) را روی «Save as PDF» قرار دهید.
		</div>

		<script>
		function cpttSaveAsPdf(){
		var el = document.getElementById('cpttPdfHint');
		if (el) el.style.display = 'block';
		window.print();
		}
		</script>

		<div class="card">
			<div class="h1">گزارش نهایی پروژه</div>

			<div class="autoText">
			<?php echo esc_html($brand['footer_text'] ?? 'این گزارش به صورت خودکار تولید شده است.'); ?>
			</div>

			<div class="siteBlock">
			<div class="brandTitle"><?php echo esc_html($brand['brand_name'] ?? get_bloginfo('name')); ?></div>
			<div class="brandUrl"><?php echo esc_html($brand['site_url'] ?? home_url('/')); ?></div>
			</div>

			<div class="meta">تاریخ تولید گزارش: <?php echo esc_html($created_fa); ?></div>

			<div class="grid2">
				<div class="kv">
					<div><b>عنوان پروژه:</b> <?php echo esc_html($title); ?></div>
					<div class="small" style="margin-top:6px;"><b>مشتری:</b> <?php echo esc_html($client_name ?: '—'); ?></div>
				</div>
				<div class="kv">
					<div><b>کارشناسان:</b> <?php echo esc_html($expert_names ? implode('، ', $expert_names) : '—'); ?></div>
					<div class="small" style="margin-top:6px;"><b>آخرین بروزرسانی:</b> <?php echo esc_html($last_update ?: '—'); ?></div>
				</div>
			</div>
		</div>

		<div class="card">
			<div class="h1" style="font-size:16px;">روند پروژه</div>

			<?php foreach ($steps as $idx => $s):
				$st = $s['status'] ?? 'todo';
				$badgeClass = $st === 'done' ? 'badgeDone' : ($st === 'current' ? 'badgeCur' : 'badgeTodo');
				$badgeText  = $st === 'done' ? 'انجام‌شده' : ($st === 'current' ? 'در حال انجام' : 'انجام‌نشده');

				$updated_at_fa = (string)($s['updated_at_fa'] ?? '');
				$step_due_fa = (string)($s['due_at_fa'] ?? '');
				$updated_by = !empty($s['updated_by']) ? $this->user_name((int)$s['updated_by']) : '';
				$desc = trim(wp_strip_all_tags((string)($s['desc'] ?? '')));

				$cl = isset($s['checklist']) && is_array($s['checklist']) ? $s['checklist'] : [];
				$uts = isset($s['user_tasks']) && is_array($s['user_tasks']) ? $s['user_tasks'] : [];
			?>
				<div class="step">
					<div class="stepHead">
						<div class="stepTitle">
						<?php echo esc_html(($idx+1) . '. ' . ($s['title'] ?? '')); ?>
						</div>

						<span class="badge <?php echo esc_attr($badgeClass); ?>">
						<?php echo esc_html($badgeText); ?>
						</span>
					</div>

					<?php if ($step_due_fa): ?>
						<div class="small"><b>مهلت مرحله:</b> <?php echo esc_html($step_due_fa); ?></div>
					<?php endif; ?>

					<?php if ($desc !== ''): ?>
						<div class="stepDesc"><?php echo esc_html($desc); ?></div>
					<?php endif; ?>

					<?php if (!empty($cl)): ?>
						<div class="hr"></div>
						<div style="font-weight:900;">چک‌لیست</div>

						<ul class="cl">
						<?php foreach ($cl as $it):
							$text = (string)($it['text'] ?? '');
							if ($text === '') continue;

							$done = !empty($it['done']);
							$done_at = (string)($it['done_at_fa'] ?? '');
							$done_by = !empty($it['done_by']) ? $this->user_name((int)$it['done_by']) : '';
							$url = trim((string)($it['url'] ?? ''));
						?>
							<li class="<?php echo $done ? 'done' : ''; ?>">
							<?php echo esc_html($text); ?>

							<?php if ($done && $url && (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0)): ?>
								<a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer">مشاهده نتیجه</a>
							<?php endif; ?>

							<?php if ($done_at || $done_by): ?>
								<div class="small">
								<?php echo esc_html($done_at ?: ''); ?>
								<?php echo $done_by ? esc_html(' — توسط: ' . $done_by) : ''; ?>
								</div>
							<?php endif; ?>
							</li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if (!empty($uts)): ?>
						<div class="hr"></div>
						<div style="font-weight:900;">تسک‌های مشتری</div>
						<ul class="cl">
						<?php foreach ($uts as $ut):
							$ut_title = (string)($ut['title'] ?? '');
							if ($ut_title === '') continue;
							$ut_done = !empty($ut['done']);
							$ut_resp = (string)($ut['response'] ?? '');
							$ut_url = trim((string)($ut['response_url'] ?? ''));
							$ut_file_url = trim((string)($ut['response_file_url'] ?? ''));
							$ut_files = isset($ut['response_files']) && is_array($ut['response_files']) ? $ut['response_files'] : [];
							$ut_completed = (string)($ut['completed_at_fa'] ?? '');
						?>
							<li class="<?php echo $ut_done ? 'done' : ''; ?>">
								<?php echo esc_html($ut_title); ?>
								<div class="small"><?php echo esc_html($ut_done ? 'تکمیل شده' : 'در انتظار مشتری'); ?><?php echo $ut_completed ? esc_html(' — ' . $ut_completed) : ''; ?></div>
								<?php if ($ut_resp): ?><div class="small"><?php echo esc_html($ut_resp); ?></div><?php endif; ?>
								<?php if ($ut_url && (strpos($ut_url, 'http://') === 0 || strpos($ut_url, 'https://') === 0)): ?><a href="<?php echo esc_url($ut_url); ?>" target="_blank" rel="noopener noreferrer">لینک ارسالی مشتری</a><?php endif; ?>
								<?php if ($ut_file_url && (strpos($ut_file_url, 'http://') === 0 || strpos($ut_file_url, 'https://') === 0)): ?><a href="<?php echo esc_url($ut_file_url); ?>" target="_blank" rel="noopener noreferrer">فایل ارسالی مشتری</a><?php endif; ?>
								<?php if (!empty($ut_files)): foreach ($ut_files as $rf): if (empty($rf['url'])) continue; ?><a href="<?php echo esc_url($rf['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(!empty($rf['name']) ? ('فایل: ' . $rf['name']) : 'فایل ارسالی مشتری'); ?></a><?php endforeach; endif; ?>
							</li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ($updated_at_fa || $updated_by): ?>
						<div class="stepMeta small">
						<?php echo $updated_at_fa ? esc_html('تاریخ و زمان: ' . $updated_at_fa) : ''; ?>
						<?php echo $updated_by ? esc_html(' — کارشناس: ' . $updated_by) : ''; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ($sign || $stamp || !empty($brand['manager_name']) || !empty($brand['manager_title'])): ?>
		<div class="card signCard">
			<div class="h1" style="font-size:16px;">تأیید نهایی</div>
			<div class="signBox">
				<div>
					<?php if ($sign): ?><img src="<?php echo esc_url($sign); ?>" alt="sign"><?php endif; ?>
					<div class="signMeta">
						<?php if (!empty($brand['manager_name'])): ?><div><?php echo esc_html($brand['manager_name']); ?></div><?php endif; ?>
						<?php if (!empty($brand['manager_title'])): ?><div><?php echo esc_html($brand['manager_title']); ?></div><?php endif; ?>
					</div>
				</div>
				<div>
					<?php if ($stamp): ?><img src="<?php echo esc_url($stamp); ?>" alt="stamp"><?php endif; ?>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<div class="footer">
			<?php echo esc_html($brand['footer_text'] ?? ''); ?>
		</div>
	</div>
</body>
</html>
		<?php
		exit;
	}
}