<?php
if (!defined('ABSPATH')) exit;

function cpttf_render_treasury(){
	global $wpdb;
	$cur = CPTT_Finance::currency_label();
	$accounts = CPTT_Finance::get_accounts(false);

	$total_balance = 0;
	foreach ($accounts as $a) if ((int)$a->status === 1) $total_balance += CPTT_Finance::account_balance($a->id);

	$view_id = isset($_GET['acc']) ? (int)$_GET['acc'] : 0;
	$ledger  = [];
	if ($view_id) {
		$ledger = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . CPTT_Finance::tbl_ledger() . " WHERE account_id=%d ORDER BY date_at DESC LIMIT 300", $view_id));
	}
	?>
	<div class="cpttf-grid cpttf-grid--kpis cpttf-grid--3">
		<div class="cpttf-kpi" style="--c:#16a34a">
			<div class="cpttf-kpi__icon">🏦</div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label">جمع موجودی</span>
				<strong class="cpttf-kpi__value"><?php echo CPTT_Finance::fmt($total_balance); ?> <small><?php echo esc_html($cur); ?></small></strong>
				<small class="cpttf-kpi__sub">کل صندوق‌ها و بانک‌ها</small></div>
		</div>
		<div class="cpttf-kpi" style="--c:#6366f1">
			<div class="cpttf-kpi__icon">📂</div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label">تعداد حساب‌ها</span>
				<strong class="cpttf-kpi__value" data-plain="1"><?php echo CPTT_Finance::fmt(count($accounts)); ?></strong>
				<small class="cpttf-kpi__sub">شامل غیرفعال‌ها</small></div>
		</div>
		<div class="cpttf-kpi" style="--c:#06b6d4">
			<div class="cpttf-kpi__icon">⚡</div>
			<div class="cpttf-kpi__body"><span class="cpttf-kpi__label">عملیات سریع</span>
				<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;">
					<button class="cpttf-btn cpttf-btn--sm cpttf-btn--primary" data-cpttf-account="new">+ حساب جدید</button>
					<button class="cpttf-btn cpttf-btn--sm" data-cpttf-transfer>🔁 انتقال</button>
				</div>
			</div>
		</div>
	</div>

	<section class="cpttf-card">
		<header class="cpttf-card__head"><h2>🏦 حساب‌های مالی</h2></header>
		<div class="cpttf-card__body">
			<div class="cpttf-acc-grid">
				<?php foreach ($accounts as $a):
					$bal = CPTT_Finance::account_balance($a->id);
					$is_active = (int)$a->status === 1;
				?>
					<div class="cpttf-acc-card <?php echo $is_active?'':'is-disabled'; ?>" style="--c:<?php echo esc_attr($a->color); ?>">
						<header>
							<span class="cpttf-acc-card__icon"><?php echo esc_html($a->icon); ?></span>
							<div>
								<strong><?php echo esc_html($a->name); ?></strong>
								<small><?php echo $a->type==='bank' ? esc_html($a->bank_name ?: 'بانک') : 'صندوق نقدی'; ?></small>
							</div>
							<div class="cpttf-acc-card__actions">
								<button class="cpttf-icon-btn" data-cpttf-account="edit" data-id="<?php echo (int)$a->id; ?>" title="ویرایش">✏️</button>
								<button class="cpttf-icon-btn cpttf-icon-btn--danger" data-cpttf-account="delete" data-id="<?php echo (int)$a->id; ?>" title="حذف">🗑</button>
							</div>
						</header>
						<div class="cpttf-acc-card__amount">
							<small>موجودی</small>
							<b><?php echo CPTT_Finance::fmt($bal); ?> <small><?php echo esc_html($cur); ?></small></b>
						</div>
						<?php if ($a->type === 'bank'): ?>
						<div class="cpttf-acc-card__meta">
							<?php if ($a->account_number): ?><div>شماره حساب: <code><?php echo esc_html($a->account_number); ?></code></div><?php endif; ?>
							<?php if ($a->card_number):    ?><div>شماره کارت: <code><?php echo esc_html($a->card_number); ?></code></div><?php endif; ?>
							<?php if ($a->iban):           ?><div>شبا: <code><?php echo esc_html($a->iban); ?></code></div><?php endif; ?>
						</div>
						<?php endif; ?>
						<footer>
							<a class="cpttf-btn cpttf-btn--sm cpttf-btn--ghost" href="<?php echo esc_url(admin_url('admin.php?page=cptt-finance-ledger&acc=' . (int)$a->id)); ?>">📒 گردش حساب</a>
						</footer>
					</div>
				<?php endforeach; ?>

				<button class="cpttf-acc-card cpttf-acc-card--add" data-cpttf-account="new">
					<span class="cpttf-acc-card__icon">＋</span>
					<strong>افزودن حساب جدید</strong>
					<small>صندوق نقدی یا حساب بانکی</small>
				</button>
			</div>
		</div>
	</section>

	<?php if ($view_id && $ledger): ?>
	<section class="cpttf-card">
		<header class="cpttf-card__head"><h2>📒 گردش حساب</h2></header>
		<div class="cpttf-card__body cpttf-table-wrap">
			<table class="cpttf-table">
				<thead><tr><th>تاریخ</th><th>نوع</th><th>توضیح</th><th>برداشت</th><th>واریز</th><th>مانده</th></tr></thead>
				<tbody>
				<?php
				$acc = CPTT_Finance::get_account($view_id);
				$running = (float)$acc->initial_balance;
				// reverse loop chronologically to compute running balance
				$sorted = array_reverse($ledger);
				$lines = [];
				foreach ($sorted as $r) {
					$running += (int)$r->direction * (float)$r->amount;
					$lines[] = $r;
				}
				// display newest first with running balance already computed
				$pos = count($lines) - 1;
				$bal_map = [];
				$rb = (float)$acc->initial_balance;
				foreach ($sorted as $r) { $rb += (int)$r->direction * (float)$r->amount; $bal_map[$r->id] = $rb; }
				foreach ($ledger as $r):
					$is_in = (int)$r->direction > 0;
				?>
					<tr>
						<td><?php echo esc_html($r->date_fa); ?></td>
						<td><?php echo esc_html(cpttf_type_label($r->type)); ?></td>
						<td><?php echo esc_html(wp_trim_words($r->description, 14, '...')); ?></td>
						<td><?php echo $is_in ? '—' : CPTT_Finance::fmt($r->amount); ?></td>
						<td><?php echo $is_in ? CPTT_Finance::fmt($r->amount) : '—'; ?></td>
						<td><b><?php echo CPTT_Finance::fmt($bal_map[$r->id] ?? 0); ?></b></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
	<?php endif; ?>

	<?php
	$_pre_transfer = (isset($_GET['new']) && $_GET['new'] === 'transfer');
	?>
	<!-- Account modal -->
	<div class="cpttf-modal" id="cpttf-acc-modal" hidden>
		<div class="cpttf-modal__backdrop" data-cpttf-close></div>
		<div class="cpttf-modal__dialog">
			<header class="cpttf-modal__head"><h3 id="cpttf-acc-modal-title">حساب جدید</h3><button class="cpttf-modal__close" data-cpttf-close>×</button></header>
			<form id="cpttf-acc-form" class="cpttf-modal__body">
				<input type="hidden" name="id" value="0">
				<input type="hidden" name="status" value="1">
				<div class="cpttf-form-grid">
					<label><span>نوع</span>
						<select name="type" id="cpttf-acc-type">
							<option value="cash">💵 صندوق نقدی</option>
							<option value="bank">🏦 حساب بانکی</option>
						</select>
					</label>
					<label><span>نام حساب</span><input type="text" name="name" required placeholder="مثلاً صندوق دفتر، ملت ۶۰۳۷"></label>
					<label><span>موجودی اولیه (<?php echo esc_html($cur); ?>)</span><input type="text" name="initial_balance" class="cpttf-money" value="0"></label>
					<label><span>آیکن</span><input type="text" name="icon" value="🏦" maxlength="6"></label>
					<label><span>رنگ</span><input type="color" name="color" value="#6366f1"></label>
					<div class="cpttf-bank-fields" hidden>
						<label><span>نام بانک</span><input type="text" name="bank_name"></label>
						<label><span>شماره حساب</span><input type="text" name="account_number" dir="ltr"></label>
						<label><span>شماره کارت</span><input type="text" name="card_number" dir="ltr"></label>
						<label><span>شبا (IBAN)</span><input type="text" name="iban" dir="ltr"></label>
					</div>
				</div>
			</form>
			<footer class="cpttf-modal__foot">
				<button class="cpttf-btn cpttf-btn--ghost" data-cpttf-close>انصراف</button>
				<button class="cpttf-btn cpttf-btn--primary" data-cpttf-submit="account">ذخیره</button>
			</footer>
		</div>
	</div>

	<!-- Transfer modal -->
	<div class="cpttf-modal" id="cpttf-tr-modal" hidden>
		<div class="cpttf-modal__backdrop" data-cpttf-close></div>
		<div class="cpttf-modal__dialog">
			<header class="cpttf-modal__head"><h3>🔁 انتقال بین حساب‌ها</h3><button class="cpttf-modal__close" data-cpttf-close>×</button></header>
			<form id="cpttf-tr-form" class="cpttf-modal__body">
				<div class="cpttf-form-grid">
					<label><span>از حساب</span>
						<select name="from_id" required>
							<?php foreach ($accounts as $a): if((int)$a->status !== 1) continue; ?><option value="<?php echo (int)$a->id; ?>"><?php echo esc_html($a->icon.' '.$a->name); ?></option><?php endforeach; ?>
						</select>
					</label>
					<label><span>به حساب</span>
						<select name="to_id" required>
							<?php foreach ($accounts as $a): if((int)$a->status !== 1) continue; ?><option value="<?php echo (int)$a->id; ?>"><?php echo esc_html($a->icon.' '.$a->name); ?></option><?php endforeach; ?>
						</select>
					</label>
					<label><span>مبلغ (<?php echo esc_html($cur); ?>)</span><input type="text" name="amount" class="cpttf-money" required></label>
					<label><span>تاریخ</span><input type="text" name="date_local" placeholder="۱۴۰۳/۰۱/۰۱ ۱۲:۰۰"></label>
					<label class="cpttf-full"><span>توضیح</span><input type="text" name="description"></label>
				</div>
			</form>
			<footer class="cpttf-modal__foot">
				<button class="cpttf-btn cpttf-btn--ghost" data-cpttf-close>انصراف</button>
				<button class="cpttf-btn cpttf-btn--primary" data-cpttf-submit="transfer">انتقال</button>
			</footer>
		</div>
	</div>

	<?php if ($_pre_transfer): ?>
	<script>document.addEventListener('DOMContentLoaded',function(){ var b=document.querySelector('[data-cpttf-transfer]'); if(b) b.click(); });</script>
	<?php endif; ?>
	<?php
}

function cpttf_type_label($t){
	switch($t){
		case 'income': return '💵 درآمد';
		case 'expense': return '📤 هزینه';
		case 'transfer_in': return '↓ انتقال ورودی';
		case 'transfer_out': return '↑ انتقال خروجی';
		case 'expert_payout': return '👤 پرداخت کارشناس';
		case 'manual': return '✏️ دستی';
	}
	return $t;
}
