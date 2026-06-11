<?php
if (!defined('ABSPATH')) exit;

function cpttf_render_categories(){
	$in  = CPTT_Finance::get_categories('income');
	$out = CPTT_Finance::get_categories('expense');
	?>
	<section class="cpttf-card">
		<header class="cpttf-card__head cpttf-card__head--actions">
			<h2>🏷 دسته‌بندی‌های مالی</h2>
			<div class="cpttf-card__actions">
				<button class="cpttf-btn cpttf-btn--primary" data-cpttf-cat="new" data-type="income">+ دسته درآمد</button>
				<button class="cpttf-btn cpttf-btn--danger"  data-cpttf-cat="new" data-type="expense">+ دسته هزینه</button>
			</div>
		</header>
		<div class="cpttf-card__body cpttf-grid cpttf-grid--2">
			<div>
				<h3 class="cpttf-section-title cpttf-text-success">💵 درآمد</h3>
				<ul class="cpttf-cat-list">
					<?php foreach ($in as $c): ?>
						<li style="--c:<?php echo esc_attr($c->color); ?>">
							<span class="cpttf-cat__icon"><?php echo esc_html($c->icon); ?></span>
							<strong><?php echo esc_html($c->name); ?></strong>
							<div class="cpttf-cat__actions">
								<button class="cpttf-icon-btn" data-cpttf-cat="edit" data-id="<?php echo (int)$c->id; ?>">✏️</button>
								<button class="cpttf-icon-btn cpttf-icon-btn--danger" data-cpttf-cat="delete" data-id="<?php echo (int)$c->id; ?>">🗑</button>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<div>
				<h3 class="cpttf-section-title cpttf-text-danger">📤 هزینه</h3>
				<ul class="cpttf-cat-list">
					<?php foreach ($out as $c): ?>
						<li style="--c:<?php echo esc_attr($c->color); ?>">
							<span class="cpttf-cat__icon"><?php echo esc_html($c->icon); ?></span>
							<strong><?php echo esc_html($c->name); ?></strong>
							<div class="cpttf-cat__actions">
								<button class="cpttf-icon-btn" data-cpttf-cat="edit" data-id="<?php echo (int)$c->id; ?>">✏️</button>
								<button class="cpttf-icon-btn cpttf-icon-btn--danger" data-cpttf-cat="delete" data-id="<?php echo (int)$c->id; ?>">🗑</button>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
	</section>

	<div class="cpttf-modal" id="cpttf-cat-modal" hidden>
		<div class="cpttf-modal__backdrop" data-cpttf-close></div>
		<div class="cpttf-modal__dialog">
			<header class="cpttf-modal__head"><h3 id="cpttf-cat-modal-title">دسته جدید</h3><button class="cpttf-modal__close" data-cpttf-close>×</button></header>
			<form id="cpttf-cat-form" class="cpttf-modal__body">
				<input type="hidden" name="id" value="0">
				<div class="cpttf-form-grid">
					<label><span>نوع</span>
						<select name="type">
							<option value="income">درآمد</option>
							<option value="expense">هزینه</option>
						</select>
					</label>
					<label><span>نام دسته</span><input type="text" name="name" required></label>
					<label><span>آیکن</span><input type="text" name="icon" maxlength="6" value="🏷"></label>
					<label><span>رنگ</span><input type="color" name="color" value="#64748b"></label>
				</div>
			</form>
			<footer class="cpttf-modal__foot">
				<button class="cpttf-btn cpttf-btn--ghost" data-cpttf-close>انصراف</button>
				<button class="cpttf-btn cpttf-btn--primary" data-cpttf-submit="cat">ذخیره</button>
			</footer>
		</div>
	</div>
	<?php
}
