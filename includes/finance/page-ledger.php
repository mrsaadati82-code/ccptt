<?php
if (!defined('ABSPATH')) exit;

function cpttf_render_ledger(){
	global $wpdb;
	$cur = CPTT_Finance::currency_label();
	$accounts = CPTT_Finance::get_accounts(false);
	$acc_id   = isset($_GET['acc']) ? (int)$_GET['acc'] : 0;
	$from     = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
	$to       = isset($_GET['to'])   ? sanitize_text_field($_GET['to'])   : '';
	$type     = isset($_GET['type']) ? sanitize_key($_GET['type']) : '';

	$where = ['1=1']; $params = [];
	if ($acc_id) { $where[] = 'account_id=%d'; $params[] = $acc_id; }
	if ($type)   { $where[] = 'type=%s'; $params[] = $type; }
	if ($from)   { $where[] = 'date_at >= %d'; $params[] = CPTT_Finance::parse_jalali_local($from); }
	if ($to)     { $where[] = 'date_at <= %d'; $params[] = CPTT_Finance::parse_jalali_local($to . ' 23:59'); }

	$sql = "SELECT * FROM " . CPTT_Finance::tbl_ledger() . " WHERE " . implode(' AND ', $where) . " ORDER BY date_at DESC LIMIT 1000";
	$rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);

	// Totals (matching filter)
	$tot_in = 0; $tot_out = 0;
	foreach ($rows as $r) {
		if ((int)$r->direction > 0) $tot_in  += (float)$r->amount;
		else                         $tot_out += (float)$r->amount;
	}

	// Account info & current balance
	$acc = $acc_id ? CPTT_Finance::get_account($acc_id) : null;
	$bal = $acc_id ? CPTT_Finance::account_balance($acc_id) : 0;
	?>
	<div class="cpttf-grid cpttf-grid--kpis cpttf-grid--3">
		<?php if ($acc): ?>
		<div class="cpttf-kpi" style="--c:<?php echo esc_attr($acc->color); ?>">
			<div class="cpttf-kpi__icon"><?php echo esc_html($acc->icon); ?></div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label"><?php echo esc_html($acc->name); ?></span>
				<strong class="cpttf-kpi__value"><?php echo CPTT_Finance::fmt($bal); ?> <small><?php echo esc_html($cur); ?></small></strong>
				<small class="cpttf-kpi__sub">موجودی فعلی</small></div>
		</div>
		<?php else: ?>
		<div class="cpttf-kpi" style="--c:#06b6d4">
			<div class="cpttf-kpi__icon">📑</div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label">حالت نمایش</span>
				<strong class="cpttf-kpi__value" data-plain="1">همه حساب‌ها</strong>
				<small class="cpttf-kpi__sub">لجر سراسری</small></div>
		</div>
		<?php endif; ?>
		<div class="cpttf-kpi" style="--c:#16a34a">
			<div class="cpttf-kpi__icon">⬇️</div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label">جمع واریز</span>
				<strong class="cpttf-kpi__value"><?php echo CPTT_Finance::fmt($tot_in); ?> <small><?php echo esc_html($cur); ?></small></strong>
				<small class="cpttf-kpi__sub">در محدوده فیلتر</small></div>
		</div>
		<div class="cpttf-kpi" style="--c:#ef4444">
			<div class="cpttf-kpi__icon">⬆️</div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label">جمع برداشت</span>
				<strong class="cpttf-kpi__value"><?php echo CPTT_Finance::fmt($tot_out); ?> <small><?php echo esc_html($cur); ?></small></strong>
				<small class="cpttf-kpi__sub">در محدوده فیلتر</small></div>
		</div>
	</div>

	<section class="cpttf-card">
		<header class="cpttf-card__head"><h2>📑 فیلتر گردش حساب</h2></header>
		<div class="cpttf-card__body">
			<form class="cpttf-filters" method="get">
				<input type="hidden" name="page" value="cptt-finance-ledger">
				<label><span>حساب</span>
					<select name="acc"><option value="0">همه حساب‌ها</option>
						<?php foreach ($accounts as $a): ?><option value="<?php echo (int)$a->id; ?>" <?php selected($acc_id, $a->id); ?>><?php echo esc_html($a->icon . ' ' . $a->name); ?></option><?php endforeach; ?>
					</select>
				</label>
				<label><span>نوع تراکنش</span>
					<select name="type"><option value="">همه</option>
						<option value="income"        <?php selected($type,'income');        ?>>💵 درآمد</option>
						<option value="expense"       <?php selected($type,'expense');       ?>>📤 هزینه</option>
						<option value="transfer_in"   <?php selected($type,'transfer_in');   ?>>↓ انتقال ورودی</option>
						<option value="transfer_out"  <?php selected($type,'transfer_out');  ?>>↑ انتقال خروجی</option>
						<option value="expert_payout" <?php selected($type,'expert_payout'); ?>>👤 پرداخت کارشناس</option>
					</select>
				</label>
				<label><span>از تاریخ</span><input type="text" class="cpttf-jdate" name="from" value="<?php echo esc_attr($from); ?>" placeholder="۱۴۰۳/۰۱/۰۱"></label>
				<label><span>تا تاریخ</span><input type="text" class="cpttf-jdate" name="to"   value="<?php echo esc_attr($to); ?>"   placeholder="۱۴۰۳/۱۲/۲۹"></label>
				<button class="cpttf-btn cpttf-btn--primary">اعمال فیلتر</button>
				<a class="cpttf-btn cpttf-btn--ghost" href="<?php echo esc_url(admin_url('admin.php?page=cptt-finance-ledger')); ?>">↺ پاک‌کردن</a>
			</form>
		</div>
	</section>

	<section class="cpttf-card">
		<header class="cpttf-card__head"><h2>📋 لیست تراکنش‌ها (<?php echo CPTT_Finance::fmt(count($rows)); ?> ردیف)</h2></header>
		<div class="cpttf-card__body cpttf-table-wrap">
			<table class="cpttf-table">
				<thead><tr>
					<th>تاریخ</th>
					<th>نوع</th>
					<th>حساب</th>
					<th>توضیح</th>
					<th>پروژه/کارشناس</th>
					<th>واریز</th>
					<th>برداشت</th>
					<?php if ($acc_id): ?><th>مانده</th><?php endif; ?>
				</tr></thead>
				<tbody>
				<?php
				if (empty($rows)) {
					$col = $acc_id ? 8 : 7;
					echo '<tr><td colspan="'. $col .'" class="cpttf-empty">هیچ تراکنشی در این محدوده وجود ندارد.</td></tr>';
				} else {
					// running balance per account (only when single-account view)
					$bal_map = [];
					if ($acc_id && $acc) {
						$asc = array_reverse($rows);
						$rb = (float) CPTT_Finance::account_balance_at($acc_id, 0); // baseline = initial+older sums
						foreach ($asc as $r2) {
							$rb += (int)$r2->direction * (float)$r2->amount;
							$bal_map[$r2->id] = $rb;
						}
					}
					foreach ($rows as $r):
						$is_in = (int)$r->direction > 0;
						$acc_row = CPTT_Finance::get_account($r->account_id);
						$proj_title = '';
						if ($r->project_id) {
							$pt = get_the_title((int)$r->project_id);
							if ($pt) $proj_title = $pt;
						}
						$ex_name = '';
						if ($r->expert_id) {
							$eu = get_user_by('id', (int)$r->expert_id);
							if ($eu) $ex_name = $eu->display_name;
						}
				?>
					<tr>
						<td><?php echo esc_html($r->date_fa); ?></td>
						<td><?php echo esc_html(cpttf_type_label($r->type)); ?></td>
						<td><?php echo $acc_row ? esc_html($acc_row->icon . ' ' . $acc_row->name) : '—'; ?></td>
						<td><?php echo esc_html(wp_trim_words($r->description, 12, '...')); ?></td>
						<td>
							<?php if ($proj_title): ?><div style="font-size:11.5px;">📁 <?php echo esc_html($proj_title); ?></div><?php endif; ?>
							<?php if ($ex_name): ?><div style="font-size:11.5px;color:#64748b;">👤 <?php echo esc_html($ex_name); ?></div><?php endif; ?>
							<?php if (!$proj_title && !$ex_name) echo '—'; ?>
						</td>
						<td class="cpttf-text-success"><?php echo $is_in ? '<b>' . CPTT_Finance::fmt($r->amount) . '</b>' : '—'; ?></td>
						<td class="cpttf-text-danger"><?php echo !$is_in ? '<b>' . CPTT_Finance::fmt($r->amount) . '</b>' : '—'; ?></td>
						<?php if ($acc_id): ?><td><b><?php echo CPTT_Finance::fmt($bal_map[$r->id] ?? 0); ?></b></td><?php endif; ?>
					</tr>
				<?php endforeach; } ?>
				</tbody>
			</table>
		</div>
	</section>
	<?php
}
