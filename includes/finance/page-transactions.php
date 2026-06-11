<?php
if (!defined('ABSPATH')) exit;

function cpttf_render_transactions(){
	global $wpdb;
	$cur = CPTT_Finance::currency_label();
	$accounts = CPTT_Finance::get_accounts(true);
	$cats_in  = CPTT_Finance::get_categories('income');
	$cats_out = CPTT_Finance::get_categories('expense');

	// Filters
	$f_type    = isset($_GET['ftype'])   ? sanitize_key($_GET['ftype']) : '';
	$f_account = isset($_GET['facc'])    ? (int)$_GET['facc'] : 0;
	$f_cat     = isset($_GET['fcat'])    ? (int)$_GET['fcat'] : 0;
	$f_from    = isset($_GET['ffrom'])   ? sanitize_text_field($_GET['ffrom']) : '';
	$f_to      = isset($_GET['fto'])     ? sanitize_text_field($_GET['fto'])   : '';

	$where = ["type IN ('income','expense')"]; $params = [];
	if ($f_type === 'income' || $f_type === 'expense') { $where[] = 'type=%s'; $params[] = $f_type; }
	if ($f_account) { $where[] = 'account_id=%d'; $params[] = $f_account; }
	if ($f_cat)     { $where[] = 'category_id=%d'; $params[] = $f_cat; }
	if ($f_from)    { $where[] = 'date_at >= %d'; $params[] = CPTT_Finance::parse_jalali_local($f_from); }
	if ($f_to)      { $where[] = 'date_at <= %d'; $params[] = CPTT_Finance::parse_jalali_local($f_to . ' 23:59'); }
	$sql = "SELECT * FROM " . CPTT_Finance::tbl_ledger() . " WHERE " . implode(' AND ', $where) . " ORDER BY date_at DESC LIMIT 500";
	$rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);

	// Totals (matching filter)
	$sum_in = 0; $sum_out = 0;
	foreach ($rows as $r) { if ($r->type === 'income') $sum_in += (float)$r->amount; elseif ($r->type === 'expense') $sum_out += (float)$r->amount; }

	$new_mode = isset($_GET['new']) ? sanitize_key($_GET['new']) : '';
	$pre_project = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
	?>
	<div class="cpttf-grid cpttf-grid--kpis cpttf-grid--3">
		<div class="cpttf-kpi" style="--c:#16a34a">
			<div class="cpttf-kpi__icon">💵</div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label">جمع درآمد</span>
				<strong class="cpttf-kpi__value"><?php echo CPTT_Finance::fmt($sum_in); ?> <small><?php echo esc_html($cur); ?></small></strong>
				<small class="cpttf-kpi__sub">در محدوده فیلتر</small></div>
		</div>
		<div class="cpttf-kpi" style="--c:#ef4444">
			<div class="cpttf-kpi__icon">📤</div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label">جمع هزینه</span>
				<strong class="cpttf-kpi__value"><?php echo CPTT_Finance::fmt($sum_out); ?> <small><?php echo esc_html($cur); ?></small></strong>
				<small class="cpttf-kpi__sub">در محدوده فیلتر</small></div>
		</div>
		<div class="cpttf-kpi" style="--c:#06b6d4">
			<div class="cpttf-kpi__icon">📊</div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label">خالص</span>
				<strong class="cpttf-kpi__value"><?php echo CPTT_Finance::fmt($sum_in - $sum_out); ?> <small><?php echo esc_html($cur); ?></small></strong>
				<small class="cpttf-kpi__sub">درآمد − هزینه</small></div>
		</div>
	</div>

	<section class="cpttf-card">
		<header class="cpttf-card__head cpttf-card__head--actions">
			<h2>💸 درآمد و هزینه</h2>
			<div class="cpttf-card__actions">
				<button class="cpttf-btn cpttf-btn--primary" data-cpttf-tx="income">+ ثبت درآمد</button>
				<button class="cpttf-btn cpttf-btn--danger" data-cpttf-tx="expense">+ ثبت هزینه</button>
			</div>
		</header>
		<div class="cpttf-card__body">
			<form class="cpttf-filters" method="get">
				<input type="hidden" name="page" value="cptt-finance-transactions">
				<label><span>نوع</span>
					<select name="ftype"><option value="">همه</option>
						<option value="income"  <?php selected($f_type,'income');  ?>>درآمد</option>
						<option value="expense" <?php selected($f_type,'expense'); ?>>هزینه</option>
					</select>
				</label>
				<label><span>حساب</span>
					<select name="facc"><option value="0">همه</option>
						<?php foreach ($accounts as $a): ?><option value="<?php echo (int)$a->id; ?>" <?php selected($f_account, $a->id); ?>><?php echo esc_html($a->icon . ' ' . $a->name); ?></option><?php endforeach; ?>
					</select>
				</label>
				<label><span>دسته</span>
					<select name="fcat"><option value="0">همه</option>
						<?php foreach (array_merge($cats_in, $cats_out) as $c): ?><option value="<?php echo (int)$c->id; ?>" <?php selected($f_cat, $c->id); ?>><?php echo esc_html($c->icon . ' ' . $c->name); ?></option><?php endforeach; ?>
					</select>
				</label>
				<label><span>از تاریخ</span><input type="text" name="ffrom" value="<?php echo esc_attr($f_from); ?>" placeholder="۱۴۰۳/۰۱/۰۱"></label>
				<label><span>تا تاریخ</span><input type="text" name="fto"   value="<?php echo esc_attr($f_to); ?>"   placeholder="۱۴۰۳/۱۲/۲۹"></label>
				<button class="cpttf-btn">اعمال</button>
				<a class="cpttf-btn cpttf-btn--ghost" href="<?php echo esc_url(admin_url('admin.php?page=cptt-finance-transactions')); ?>">↺ پاک‌کردن</a>
			</form>

			<div class="cpttf-table-wrap">
				<table class="cpttf-table">
					<thead><tr><th>تاریخ</th><th>نوع</th><th>حساب</th><th>دسته</th><th>توضیح</th><th>مبلغ</th><th></th></tr></thead>
					<tbody>
					<?php if (empty($rows)): ?>
						<tr><td colspan="7" class="cpttf-empty">هیچ تراکنشی در این محدوده ثبت نشده.</td></tr>
					<?php else: foreach ($rows as $r):
						$acc = CPTT_Finance::get_account($r->account_id);
						$cat = $r->category_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM " . CPTT_Finance::tbl_categories() . " WHERE id=%d", $r->category_id)) : null;
					?>
						<tr>
							<td><?php echo esc_html($r->date_fa); ?></td>
							<td>
								<?php if ($r->type === 'income'): ?>
									<span class="cpttf-chip cpttf-chip--in">💵 درآمد</span>
								<?php else: ?>
									<span class="cpttf-chip cpttf-chip--out">📤 هزینه</span>
								<?php endif; ?>
							</td>
							<td><?php echo $acc ? esc_html($acc->icon . ' ' . $acc->name) : '—'; ?></td>
							<td><?php echo $cat ? '<span class="cpttf-tag" style="background:'.esc_attr($cat->color).'22;color:'.esc_attr($cat->color).'">'.esc_html($cat->icon . ' ' . $cat->name).'</span>' : '—'; ?></td>
							<td><?php echo esc_html(wp_trim_words($r->description, 12, '...')); ?></td>
							<td class="<?php echo $r->type==='income'?'cpttf-text-success':'cpttf-text-danger'; ?>"><b><?php echo CPTT_Finance::fmt($r->amount); ?></b></td>
							<td><button class="cpttf-btn cpttf-btn--sm cpttf-btn--danger" data-cpttf-tx-del="<?php echo (int)$r->id; ?>">حذف</button></td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>

	<?php // ─── Modal template ─── ?>
	<div class="cpttf-modal" id="cpttf-tx-modal" hidden>
		<div class="cpttf-modal__backdrop" data-cpttf-close></div>
		<div class="cpttf-modal__dialog">
			<header class="cpttf-modal__head">
				<h3 id="cpttf-tx-modal-title">ثبت تراکنش</h3>
				<button class="cpttf-modal__close" data-cpttf-close>×</button>
			</header>
			<form id="cpttf-tx-form" class="cpttf-modal__body">
				<input type="hidden" name="tx_type" id="cpttf-tx-type" value="income">
				<input type="hidden" name="id" value="0">
				<input type="hidden" name="project_id" value="<?php echo (int)$pre_project; ?>">
				<div class="cpttf-form-grid">
					<label><span>تاریخ</span><input type="text" name="date_local" placeholder="۱۴۰۳/۰۱/۰۱ ۱۲:۰۰"></label>
					<label><span>حساب</span>
						<select name="account_id" required>
							<?php foreach ($accounts as $a): ?><option value="<?php echo (int)$a->id; ?>"><?php echo esc_html($a->icon . ' ' . $a->name); ?></option><?php endforeach; ?>
						</select>
					</label>
					<label><span>مبلغ (<?php echo esc_html($cur); ?>)</span><input type="text" inputmode="numeric" name="amount" class="cpttf-money" required></label>
					<label><span>دسته</span>
						<select name="category_id" id="cpttf-tx-cat">
							<option value="0">— بدون دسته —</option>
							<optgroup label="درآمد" data-tx="income">
								<?php foreach ($cats_in as $c): ?><option value="<?php echo (int)$c->id; ?>"><?php echo esc_html($c->icon . ' ' . $c->name); ?></option><?php endforeach; ?>
							</optgroup>
							<optgroup label="هزینه" data-tx="expense">
								<?php foreach ($cats_out as $c): ?><option value="<?php echo (int)$c->id; ?>"><?php echo esc_html($c->icon . ' ' . $c->name); ?></option><?php endforeach; ?>
							</optgroup>
						</select>
					</label>
					<label class="cpttf-full"><span>توضیحات</span><textarea name="description" rows="2"></textarea></label>
				</div>
			</form>
			<footer class="cpttf-modal__foot">
				<button class="cpttf-btn cpttf-btn--ghost" data-cpttf-close>انصراف</button>
				<button class="cpttf-btn cpttf-btn--primary" data-cpttf-submit="tx">ذخیره</button>
			</footer>
		</div>
	</div>

	<?php if ($new_mode === 'income' || $new_mode === 'expense'): ?>
	<script>document.addEventListener('DOMContentLoaded', function(){ window.cpttfOpenTx && cpttfOpenTx(<?php echo wp_json_encode($new_mode); ?>); });</script>
	<?php endif; ?>
	<?php
}
