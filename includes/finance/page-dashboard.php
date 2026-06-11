<?php
if (!defined('ABSPATH')) exit;

function cpttf_render_dashboard(){
	$k = CPTT_Finance::compute_kpis();
	$series = CPTT_Finance::monthly_series();
	$top_customers = CPTT_Finance::top_customers_series(6);
	$exp_breakdown = CPTT_Finance::category_breakdown('expense');
	$inc_breakdown = CPTT_Finance::category_breakdown('income');
	$cur = CPTT_Finance::currency_label();
	?>
	<!-- v6.5.0 — Promo strip linking to the new full ERP UI -->
	<section class="cpttf-card" style="border:0; background:linear-gradient(135deg,#4f46e5,#7c3aed); color:#fff;">
		<div class="cpttf-card__body" style="display:flex; gap:14px; align-items:center; flex-wrap:wrap; background:transparent;">
			<div style="font-size:28px;">⚡</div>
			<div style="flex:1; min-width:240px;">
				<strong style="display:block; font-size:15px; color:#fff;">پنل حسابداری ERP حرفه‌ای آماده است</strong>
				<small style="color:rgba(255,255,255,.85); font-size:12px;">رابط کامل دوبل‌انتری، اسناد چندارزه، گزارش‌های مالی، مراکز هزینه، سال مالی و …</small>
			</div>
			<a href="<?php echo esc_url(admin_url('admin.php?page=cptt-finance-erp')); ?>" class="cpttf-btn" style="background:#fff; color:#4f46e5; border-color:transparent; font-weight:900;">باز کردن پنل ERP →</a>
		</div>
	</section>

	<div class="cpttf-grid cpttf-grid--kpis">
		<?php
		$kpis = [
			['label'=>'درآمد کل دریافتی', 'value'=>$k['total_revenue'],     'icon'=>'💵','color'=>'#16a34a','sub'=>'پولی که از مشتری گرفته‌اید'],
			['label'=>'مطالبات (بدهی مشتریان)','value'=>$k['receivables'],  'icon'=>'⏳','color'=>'#f59e0b','sub'=>$k['debt_projects'].' پروژه بدهکار'],
			['label'=>'تسویه به کارشناسان', 'value'=>$k['expert_payouts'],  'icon'=>'👤','color'=>'#6366f1','sub'=>'پرداختی به اعضای تیم'],
			['label'=>'سود ناخالص', 'value'=>$k['gross_profit'],            'icon'=>'📈','color'=>'#06b6d4','sub'=>'درآمد − پرداخت به کارشناس'],
			['label'=>'کل صورتحساب صادر شده', 'value'=>$k['total_invoiced'],'icon'=>'🧾','color'=>'#a855f7','sub'=>'مجموع cost مراحل'],
			['label'=>'پروژه‌ها', 'value'=>$k['projects_total'],            'icon'=>'📁','color'=>'#0ea5e9','sub'=>$k['settled_projects'].' تسویه شده · '.$k['unsettled_projects'].' باز','plain'=>true],
		];
		foreach ($kpis as $kp): ?>
			<div class="cpttf-kpi" style="--c:<?php echo esc_attr($kp['color']); ?>">
				<div class="cpttf-kpi__icon"><?php echo esc_html($kp['icon']); ?></div>
				<div class="cpttf-kpi__body">
					<span class="cpttf-kpi__label"><?php echo esc_html($kp['label']); ?></span>
					<strong class="cpttf-kpi__value">
						<?php echo CPTT_Finance::fmt($kp['value']); ?>
						<?php if (empty($kp['plain'])): ?><small><?php echo esc_html($cur); ?></small><?php endif; ?>
					</strong>
					<small class="cpttf-kpi__sub"><?php echo esc_html($kp['sub']); ?></small>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<!-- Row 1: monthly bar chart full-width -->
	<section class="cpttf-card">
		<header class="cpttf-card__head">
			<h2>📈 درآمد و هزینه ۶ ماه اخیر</h2>
			<small>ترکیب لجر مالی + داده‌های پروژه‌ها</small>
		</header>
		<div class="cpttf-card__body">
			<canvas id="cpttf-chart-monthly" height="240"
				data-monthly='<?php echo esc_attr(wp_json_encode($series)); ?>'
				data-currency='<?php echo esc_attr($cur); ?>'></canvas>
		</div>
	</section>

	<!-- Row 2: two donuts side by side -->
	<div class="cpttf-grid cpttf-grid--2">
		<section class="cpttf-card">
			<header class="cpttf-card__head"><h2>💰 ترکیب درآمد دریافتی</h2></header>
			<div class="cpttf-card__body">
				<?php
				$pie_main = [
					['name'=>'دریافت‌شده از مشتری', 'value'=>$k['total_revenue'], 'color'=>'#16a34a'],
					['name'=>'مطالبات باقی‌مانده',   'value'=>$k['receivables'],    'color'=>'#f59e0b'],
				];
				$total_pm = array_sum(array_map(function($r){return (float)$r['value'];}, $pie_main));
				if ($total_pm > 0): ?>
					<canvas id="cpttf-chart-revenue-pie" height="220"
						data-pie='<?php echo esc_attr(wp_json_encode($pie_main)); ?>'></canvas>
				<?php else: ?>
					<p class="cpttf-empty">داده‌ای برای نمایش وجود ندارد.</p>
				<?php endif; ?>
			</div>
		</section>

		<section class="cpttf-card">
			<header class="cpttf-card__head"><h2>💼 توزیع سود</h2></header>
			<div class="cpttf-card__body">
				<?php
				$pie_profit = [
					['name'=>'سود ناخالص شما',       'value'=>max(0,$k['gross_profit']),     'color'=>'#06b6d4'],
					['name'=>'پرداخت‌شده به کارشناس', 'value'=>$k['expert_payouts'], 'color'=>'#6366f1'],
				];
				$total_pp = array_sum(array_map(function($r){return (float)$r['value'];}, $pie_profit));
				if ($total_pp > 0): ?>
					<canvas id="cpttf-chart-profit-pie" height="220"
						data-pie='<?php echo esc_attr(wp_json_encode($pie_profit)); ?>'></canvas>
				<?php else: ?>
					<p class="cpttf-empty">داده‌ای برای نمایش وجود ندارد.</p>
				<?php endif; ?>
			</div>
		</section>
	</div>

	<!-- Row 3: top customers + expense categories -->
	<div class="cpttf-grid cpttf-grid--2">
		<section class="cpttf-card">
			<header class="cpttf-card__head"><h2>🥇 پرفروش‌ترین مشتریان</h2></header>
			<div class="cpttf-card__body">
				<?php if (empty($top_customers)): ?>
					<p class="cpttf-empty">هنوز پرداختی ثبت نشده است.</p>
				<?php else: ?>
					<canvas id="cpttf-chart-top-customers" height="220"
						data-hbar='<?php echo esc_attr(wp_json_encode($top_customers)); ?>'
						data-currency='<?php echo esc_attr($cur); ?>'></canvas>
				<?php endif; ?>
			</div>
		</section>

		<section class="cpttf-card">
			<header class="cpttf-card__head"><h2>📤 ترکیب هزینه‌ها بر اساس دسته</h2></header>
			<div class="cpttf-card__body">
				<?php if (empty($exp_breakdown)): ?>
					<p class="cpttf-empty">هنوز هزینه‌ای ثبت نشده است. <a href="<?php echo esc_url(admin_url('admin.php?page=cptt-finance-transactions&new=expense')); ?>">+ افزودن</a></p>
				<?php else: ?>
					<canvas id="cpttf-chart-expense-cat" height="220"
						data-pie='<?php echo esc_attr(wp_json_encode(array_map(function($r){ return ['name'=>$r['name'],'value'=>$r['value'],'color'=>$r['color']]; }, $exp_breakdown))); ?>'></canvas>
				<?php endif; ?>
			</div>
		</section>
	</div>

	<!-- Row 4: accounts + shortcuts + income breakdown -->
	<div class="cpttf-grid cpttf-grid--2">
		<section class="cpttf-card">
			<header class="cpttf-card__head">
				<h2>🏦 موجودی حساب‌ها</h2>
			</header>
			<div class="cpttf-card__body">
				<?php
				$accs = CPTT_Finance::get_accounts(true);
				if (empty($accs)) {
					echo '<p class="cpttf-empty">هنوز هیچ حسابی ثبت نشده است.</p>';
				} else {
					echo '<ul class="cpttf-acc-list">';
					foreach ($accs as $a) {
						$bal = CPTT_Finance::account_balance($a->id);
						$cls = $bal < 0 ? 'is-negative' : '';
						echo '<li class="cpttf-acc-list__item '. $cls .'" style="--c:'. esc_attr($a->color) .'">';
						echo '<span class="cpttf-acc-list__icon">'. esc_html($a->icon) .'</span>';
						echo '<div class="cpttf-acc-list__body"><strong>'. esc_html($a->name) .'</strong>';
						echo '<small>'. ($a->type === 'bank' ? esc_html($a->bank_name ?: 'بانک') : 'صندوق نقدی') .'</small></div>';
						echo '<b class="cpttf-acc-list__bal">'. CPTT_Finance::fmt($bal) .' <small>'. esc_html($cur) .'</small></b>';
						echo '</li>';
					}
					echo '</ul>';
				}
				?>
			</div>
		</section>

		<section class="cpttf-card">
			<header class="cpttf-card__head">
				<h2>🚀 میانبرها</h2>
			</header>
			<div class="cpttf-card__body cpttf-shortcuts">
				<a class="cpttf-shortcut" href="<?php echo esc_url(admin_url('admin.php?page=cptt-finance-transactions&new=income')); ?>"><span>💵</span><b>ثبت درآمد جدید</b></a>
				<a class="cpttf-shortcut" href="<?php echo esc_url(admin_url('admin.php?page=cptt-finance-transactions&new=expense')); ?>"><span>📤</span><b>ثبت هزینه</b></a>
				<a class="cpttf-shortcut" href="<?php echo esc_url(admin_url('admin.php?page=cptt-finance-treasury')); ?>"><span>🔁</span><b>انتقال بین حساب‌ها</b></a>
				<a class="cpttf-shortcut" href="<?php echo esc_url(admin_url('admin.php?page=cptt-finance-receivables')); ?>"><span>👥</span><b>لیست بدهکاران</b></a>
				<a class="cpttf-shortcut" href="<?php echo esc_url(admin_url('admin.php?page=cptt-finance-payouts')); ?>"><span>💼</span><b>تسویه کارشناسان</b></a>
				<a class="cpttf-shortcut" href="<?php echo esc_url(admin_url('admin.php?page=cptt-finance-classic')); ?>"><span>📒</span><b>حساب پروژه‌ها</b></a>
			</div>
		</section>
	</div>
	<?php
}
