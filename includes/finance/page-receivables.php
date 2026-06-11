<?php
if (!defined('ABSPATH')) exit;

function cpttf_render_receivables(){
	$cur = CPTT_Finance::currency_label();
	// Build debtor map from project_card_data-like aggregation
	$debtors = [];
	$projects = get_posts(['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>-1]);
	$grand_total = 0; $proj_with_debt = 0;
	foreach ($projects as $p) {
		$steps = get_post_meta($p->ID, '_cptt_steps', true);
		if (!is_array($steps)) continue;
		$cost = 0; $paid = 0;
		foreach ($steps as $st) {
			$cost += (float)($st['cost'] ?? 0);
			$paid += (float)($st['paid'] ?? 0);
			if (!empty($st['extra_finance']) && is_array($st['extra_finance'])) {
				foreach ($st['extra_finance'] as $ef) {
					$cost += (float)($ef['cost'] ?? 0);
					$paid += (float)($ef['paid'] ?? 0);
				}
			}
		}
		$remain = max(0, $cost - $paid);
		if ($remain <= 0) continue;
		$proj_with_debt++;
		$grand_total += $remain;
		$cid = (int) get_post_meta($p->ID, '_cptt_client_user_id', true);
		$cu  = $cid ? get_user_by('id', $cid) : null;
		// v6.4.1 — warn (in the name itself) if the project's "client" actually
		// belongs to the expert role — points to mis-assigned data.
		$cname = $cu ? $cu->display_name : 'مشتری نامشخص';
		if ($cu) {
			$roles = (array) $cu->roles;
			if (in_array('cptt_expert', $roles, true) && !in_array('customer', $roles, true)) {
				$cname .= ' ⚠ (نقش کارشناس)';
			}
		}
		$cphone = $cu ? (string) get_user_meta($cid, 'billing_phone', true) : '';
		if ($cphone === '' && $cu) $cphone = (string) get_user_meta($cid, 'cptt_phone', true);
		if ($cphone === '' && $cu) $cphone = (string) get_user_meta($cid, 'mobile', true);
		if (!isset($debtors[$cid])) $debtors[$cid] = [
			'id'=>$cid,'name'=>$cname,'phone'=>$cphone,
			'total_cost'=>0,'total_paid'=>0,'remain'=>0,'projects'=>[],
		];
		$debtors[$cid]['total_cost'] += $cost;
		$debtors[$cid]['total_paid'] += $paid;
		$debtors[$cid]['remain']     += $remain;
		$debtors[$cid]['projects'][] = [
			'id'=>$p->ID,'title'=>get_the_title($p),'cost'=>$cost,'paid'=>$paid,'remain'=>$remain,
		];
	}
	uasort($debtors, function($a,$b){ return ($b['remain'] <=> $a['remain']); });
	?>
	<div class="cpttf-grid cpttf-grid--kpis cpttf-grid--3">
		<div class="cpttf-kpi" style="--c:#ef4444">
			<div class="cpttf-kpi__icon">⏳</div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label">کل مطالبات</span>
				<strong class="cpttf-kpi__value"><?php echo CPTT_Finance::fmt($grand_total); ?> <small><?php echo esc_html($cur); ?></small></strong>
				<small class="cpttf-kpi__sub">از همه‌ی مشتریان</small></div>
		</div>
		<div class="cpttf-kpi" style="--c:#f59e0b">
			<div class="cpttf-kpi__icon">👥</div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label">مشتریان بدهکار</span>
				<strong class="cpttf-kpi__value" data-plain="1"><?php echo CPTT_Finance::fmt(count($debtors)); ?></strong>
				<small class="cpttf-kpi__sub">نفر</small></div>
		</div>
		<div class="cpttf-kpi" style="--c:#6366f1">
			<div class="cpttf-kpi__icon">📁</div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label">پروژه‌های بدهکار</span>
				<strong class="cpttf-kpi__value" data-plain="1"><?php echo CPTT_Finance::fmt($proj_with_debt); ?></strong>
				<small class="cpttf-kpi__sub">نیاز به وصول</small></div>
		</div>
	</div>

	<section class="cpttf-card">
		<header class="cpttf-card__head cpttf-card__head--with-search">
			<h2>👥 لیست بدهکاران</h2>
			<input type="search" id="cpttf-recv-search" class="cpttf-input" placeholder="🔍 جستجو در نام یا تلفن مشتری">
		</header>
		<div class="cpttf-card__body">
			<?php if (empty($debtors)): ?>
				<p class="cpttf-empty">🎉 هیچ بدهکاری ندارید — همه چیز تسویه است.</p>
			<?php else: ?>
				<ul class="cpttf-debtor-list" id="cpttf-debtor-list">
					<?php foreach ($debtors as $d):
						$search_str = mb_strtolower(($d['name'] ?: '') . ' ' . $d['phone']);
					?>
						<li class="cpttf-debtor" data-search="<?php echo esc_attr($search_str); ?>">
							<button type="button" class="cpttf-debtor__head">
								<div class="cpttf-debtor__avatar"><?php echo esc_html(mb_substr($d['name'], 0, 1)); ?></div>
								<div class="cpttf-debtor__title">
									<strong><?php echo esc_html($d['name']); ?></strong>
									<small><?php echo esc_html($d['phone'] ?: 'بدون شماره'); ?> · <?php echo count($d['projects']); ?> پروژه</small>
								</div>
								<div class="cpttf-debtor__amount">
									<b><?php echo CPTT_Finance::fmt($d['remain']); ?></b>
									<small><?php echo esc_html($cur); ?></small>
								</div>
								<svg class="cpttf-debtor__chevron" width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="5 8 10 13 15 8"/></svg>
							</button>
							<div class="cpttf-debtor__projects" hidden>
								<table class="cpttf-table">
									<thead><tr><th>پروژه</th><th>مبلغ کل</th><th>پرداختی</th><th>مانده</th><th></th></tr></thead>
									<tbody>
									<?php foreach ($d['projects'] as $pr): ?>
										<tr>
											<td><a href="<?php echo esc_url(get_edit_post_link($pr['id'])); ?>" target="_blank"><?php echo esc_html($pr['title']); ?></a></td>
											<td><?php echo CPTT_Finance::fmt($pr['cost']); ?></td>
											<td><?php echo CPTT_Finance::fmt($pr['paid']); ?></td>
											<td class="cpttf-text-danger"><b><?php echo CPTT_Finance::fmt($pr['remain']); ?></b></td>
											<td><a class="cpttf-btn cpttf-btn--sm" href="<?php echo esc_url(admin_url('admin.php?page=cptt-finance-transactions&new=income&project_id=' . $pr['id'])); ?>">💵 ثبت پرداخت</a></td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</section>
	<?php
}
