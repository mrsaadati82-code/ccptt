<?php
if (!defined('ABSPATH')) exit;

function cpttf_render_payouts(){
	$cur = CPTT_Finance::currency_label();
	// Aggregate per-expert remaining (paid amounts not yet settled with experts)
	$experts = [];
	$projects = get_posts(['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>-1]);
	foreach ($projects as $p) {
		$steps = get_post_meta($p->ID, '_cptt_steps', true);
		if (!is_array($steps)) continue;
		foreach ($steps as $st) {
			$paid = (float)($st['paid'] ?? 0);
			if ($paid <= 0) continue;
			$assigned_ids = isset($st['assigned_expert_ids']) && is_array($st['assigned_expert_ids']) ? array_values(array_filter(array_unique(array_map('intval', $st['assigned_expert_ids'])))) : [];
			if (empty($assigned_ids) && !empty($st['assigned_expert_id'])) $assigned_ids = [(int)$st['assigned_expert_id']];
			if (empty($assigned_ids)) {
				$eids = class_exists('CPTT_Core') ? CPTT_Core::get_project_expert_ids($p->ID) : [];
				if (!empty($eids)) $assigned_ids = array_map('intval', $eids);
			}
			$per_expert = (isset($st['expert_settlements']) && is_array($st['expert_settlements'])) ? $st['expert_settlements'] : [];
			$pool_paid = 0.0;
			if (!empty($per_expert)) {
				foreach ($assigned_ids as $eid) $pool_paid += (float)($per_expert[(string)$eid]['expert_paid'] ?? 0);
			} else {
				$pool_paid = (float)($st['expert_paid'] ?? 0);
			}
			$pool_remaining = max(0, $paid - $pool_paid);
			foreach ($assigned_ids as $eid) {
				if (!$eid) continue;
				$settled_row = isset($per_expert[(string)$eid]) ? !empty($per_expert[(string)$eid]['step_settled']) : false;
				if ($settled_row) continue;
				if (!isset($experts[$eid])) {
					$u = get_user_by('id', $eid);
					$experts[$eid] = [
						'id'=>$eid,
						'name'=>$u ? $u->display_name : ('#'.$eid),
						'count'=>0,'remain'=>0,'paid_so_far'=>0,
						'steps'=>[],
					];
				}
				$experts[$eid]['count']++;
				$experts[$eid]['remain'] += $pool_remaining;
				$experts[$eid]['steps'][] = [
					'project_id'=>$p->ID,'project_title'=>get_the_title($p),
					'step_title'=>(string)($st['title'] ?? '—'),
					'paid'=>$paid,'remain'=>$pool_remaining,
				];
			}
		}
	}
	uasort($experts, function($a,$b){ return ($b['remain'] <=> $a['remain']); });
	$total_remain = array_sum(array_map(function($e){ return (float)$e['remain']; }, $experts));
	?>
	<div class="cpttf-grid cpttf-grid--kpis cpttf-grid--3">
		<div class="cpttf-kpi" style="--c:#f59e0b">
			<div class="cpttf-kpi__icon">💼</div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label">کل مانده تسویه</span>
				<strong class="cpttf-kpi__value"><?php echo CPTT_Finance::fmt($total_remain); ?> <small><?php echo esc_html($cur); ?></small></strong>
				<small class="cpttf-kpi__sub">قابل پرداخت به کارشناسان</small></div>
		</div>
		<div class="cpttf-kpi" style="--c:#6366f1">
			<div class="cpttf-kpi__icon">👤</div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label">کارشناسان</span>
				<strong class="cpttf-kpi__value" data-plain="1"><?php echo CPTT_Finance::fmt(count($experts)); ?></strong>
				<small class="cpttf-kpi__sub">نفر منتظر تسویه</small></div>
		</div>
		<div class="cpttf-kpi" style="--c:#0ea5e9">
			<div class="cpttf-kpi__icon">⚡</div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label">دسترسی سریع</span>
				<a class="cpttf-btn cpttf-btn--sm" href="<?php echo esc_url(admin_url('edit.php?post_type=cptt_project&page=cptt-accounting')); ?>">صفحه تسویه‌ی پیشرفته →</a></div>
		</div>
	</div>

	<section class="cpttf-card">
		<header class="cpttf-card__head"><h2>👤 کارشناسان منتظر تسویه</h2></header>
		<div class="cpttf-card__body">
			<?php if (empty($experts)): ?>
				<p class="cpttf-empty">✅ همه‌ی تسویه‌ها انجام شده.</p>
			<?php else: ?>
				<ul class="cpttf-debtor-list">
					<?php foreach ($experts as $e):
						$av_id = (int) get_user_meta($e['id'], 'cptt_expert_avatar_id', true);
						$av = $av_id ? wp_get_attachment_image_url($av_id, 'thumbnail') : get_avatar_url($e['id'], ['size'=>64]);
					?>
						<li class="cpttf-debtor">
							<button type="button" class="cpttf-debtor__head">
								<div class="cpttf-debtor__avatar"><img src="<?php echo esc_url($av); ?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;"></div>
								<div class="cpttf-debtor__title">
									<strong><?php echo esc_html($e['name']); ?></strong>
									<small><?php echo (int)$e['count']; ?> مرحله</small>
								</div>
								<div class="cpttf-debtor__amount">
									<b><?php echo CPTT_Finance::fmt($e['remain']); ?></b>
									<small><?php echo esc_html($cur); ?></small>
								</div>
								<svg class="cpttf-debtor__chevron" width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="5 8 10 13 15 8"/></svg>
							</button>
							<div class="cpttf-debtor__projects" hidden>
								<table class="cpttf-table">
									<thead><tr><th>پروژه / مرحله</th><th>دریافتی مرحله</th><th>مانده تسویه</th></tr></thead>
									<tbody>
									<?php foreach ($e['steps'] as $s): ?>
										<tr>
											<td>
												<a href="<?php echo esc_url(get_edit_post_link($s['project_id'])); ?>" target="_blank"><?php echo esc_html($s['project_title']); ?></a>
												<div style="color:#64748b;font-size:11.5px;">مرحله: <?php echo esc_html($s['step_title']); ?></div>
											</td>
											<td><?php echo CPTT_Finance::fmt($s['paid']); ?></td>
											<td class="cpttf-text-danger"><b><?php echo CPTT_Finance::fmt($s['remain']); ?></b></td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
								<p style="margin-top:12px;font-size:12.5px;color:#64748b;">
									برای تسویه‌ی واقعی هر مرحله از <a href="<?php echo esc_url(admin_url('edit.php?post_type=cptt_project&page=cptt-accounting')); ?>" target="_blank">صفحه‌ی «حساب و کتاب»</a> استفاده کنید. این صفحه فقط نمای خلاصه می‌دهد و آینده‌نگر است.
								</p>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</section>
	<?php
}
