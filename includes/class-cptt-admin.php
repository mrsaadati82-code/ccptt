<?php
if ( ! defined('ABSPATH') ) exit;

class CPTT_Admin {
	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action('add_meta_boxes', [$this, 'add_metaboxes']);

		add_action('save_post_cptt_project', [$this, 'save_project_meta'], 10, 2);
		add_action('save_post_cptt_template', [$this, 'save_template_meta'], 10, 2);
		add_action('save_post_cptt_checklist_tpl', [$this, 'save_checklist_tpl_meta'], 10, 2);

		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

		add_filter('manage_cptt_project_posts_columns', [$this, 'columns']);
		add_action('manage_cptt_project_posts_custom_column', [$this, 'column_content'], 10, 2);

		add_action('wp_ajax_cptt_get_template_steps', [$this, 'ajax_get_template_steps']);
		add_action('wp_ajax_cptt_get_checklist_tpl', [$this, 'ajax_get_checklist_tpl']);
	}

	public function enqueue_admin_assets($hook) {
		global $post_type;
		if ( ! in_array($post_type, ['cptt_project','cptt_template','cptt_checklist_tpl'], true) ) return;

		wp_enqueue_style('cptt-admin', CPTT_URL . 'assets/css/admin.css', [], CPTT_VERSION);

		wp_enqueue_script('jquery-ui-sortable');
		wp_enqueue_script('cptt-admin', CPTT_URL . 'assets/js/admin.js', ['jquery', 'jquery-ui-sortable'], CPTT_VERSION, true);

		wp_localize_script('cptt-admin', 'CPTT_ADMIN', [
			'ajax'  => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('cptt_admin_nonce'),
		]);
	}

	public function add_metaboxes() {
		add_meta_box(
			'cptt_project_details',
			'اطلاعات پروژه',
			[$this, 'render_details_metabox'],
			'cptt_project',
			'normal',
			'high'
		);

		add_meta_box(
			'cptt_project_steps',
			'مراحل پروژه (Stepper)',
			[$this, 'render_steps_metabox_project'],
			'cptt_project',
			'normal',
			'high'
		);

		add_meta_box(
			'cptt_template_steps',
			'مراحل تمپلیت',
			[$this, 'render_steps_metabox_template'],
			'cptt_template',
			'normal',
			'high'
		);

		add_meta_box(
			'cptt_checklist_tpl_items',
			'آیتم‌های تمپلیت چک‌لیست',
			[$this, 'render_checklist_tpl_metabox'],
			'cptt_checklist_tpl',
			'normal',
			'high'
		);
	}

	/* =========================
	   DETAILS: Multi experts (checkbox list)
	   Fix: checked state always correct + not wiped accidentally
	   ========================= */
	public function render_details_metabox($post) {
		wp_nonce_field('cptt_save_project', 'cptt_nonce');

		$client_id = (int) get_post_meta($post->ID, '_cptt_client_user_id', true);

		$expert_ids = get_post_meta($post->ID, '_cptt_expert_user_ids', true);
		if (!is_array($expert_ids)) $expert_ids = [];
		if (empty($expert_ids)) {
			$legacy = (int) get_post_meta($post->ID, '_cptt_expert_user_id', true);
			if ($legacy) $expert_ids = [$legacy];
		}

		// normalize as string set (prevents int/string mismatch)
		$expert_ids = array_values(array_filter(array_map('strval', $expert_ids)));
		$selectedSet = array_fill_keys($expert_ids, true);

		$users = get_users(['fields' => ['ID','display_name','user_email']]);

		$experts = get_users([
			'role__in' => ['cptt_expert', 'administrator'],
			'fields' => ['ID','display_name','user_email']
		]);

		// ensure selected experts are shown even if role changed
		$shown = [];
		foreach ($experts as $u) $shown[(string)$u->ID] = true;

		foreach ($expert_ids as $sid) {
			if (!isset($shown[$sid])) {
				$u = get_user_by('id', (int)$sid);
				if ($u) {
					$experts[] = (object)[
						'ID' => $u->ID,
						'display_name' => $u->display_name,
						'user_email' => $u->user_email,
					];
					$shown[$sid] = true;
				}
			}
		}

		// sort by display_name
		usort($experts, function($a, $b){
			return strcmp($a->display_name, $b->display_name);
		});
		?>
		<div class="cptt-admin-box">
			<p class="cptt-row">
				<label class="cptt-label" for="cptt_client_user_id">مشتری</label>
				<select id="cptt_client_user_id" name="cptt_client_user_id" class="cptt-select">
					<option value="">— انتخاب کنید —</option>
					<?php foreach ($users as $u): ?>
						<option value="<?php echo esc_attr($u->ID); ?>" <?php selected($client_id, $u->ID); ?>>
							<?php echo esc_html($u->display_name . ' (' . $u->user_email . ')'); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span class="cptt-help">این کاربر در فرانت «پروژه‌های من» را می‌بیند.</span>
			</p>

			<input type="hidden" name="cptt_experts_present" value="1" />

			<div class="cptt-row cptt-row--experts">
				<div class="cptt-label">کارشناسان</div>
				<div class="cptt-expertsList">
					<?php foreach ($experts as $u): ?>
						<?php $checked = isset($selectedSet[(string)$u->ID]); ?>
						<label class="cptt-expertOpt <?php echo $checked ? 'is-checked' : ''; ?>">
							<input type="checkbox" name="cptt_expert_user_ids[]" value="<?php echo esc_attr($u->ID); ?>"
								<?php checked($checked); ?> />
							<span class="cptt-expertText">
								<span class="cptt-expertName"><?php echo esc_html($u->display_name); ?></span>
								<small class="cptt-expertEmail"><?php echo esc_html($u->user_email); ?></small>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
				<span class="cptt-help">برای انتخاب چند کارشناس تیک بزنید. گزینه‌های انتخاب‌شده هایلایت می‌شوند.</span>
			</div>
		</div>
		<?php
	}

	/* =========================
	   STEPS metabox
	   ========================= */
	public function render_steps_metabox_project($post) {
		$steps = get_post_meta($post->ID, '_cptt_steps', true);
		if ( ! is_array($steps) ) $steps = [];

		$templates = get_posts([
			'post_type' => 'cptt_template',
			'numberposts' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
		]);

		$check_tpls = get_posts([
			'post_type' => 'cptt_checklist_tpl',
			'numberposts' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
		]);

		$this->render_steps_editor($post, $steps, false, $templates, $check_tpls);
	}

	public function render_steps_metabox_template($post) {
		wp_nonce_field('cptt_save_template', 'cptt_template_nonce');

		$steps = get_post_meta($post->ID, '_cptt_template_steps', true);
		if ( ! is_array($steps) ) $steps = [];

		$check_tpls = get_posts([
			'post_type' => 'cptt_checklist_tpl',
			'numberposts' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
		]);

		$this->render_steps_editor($post, $steps, true, [], $check_tpls);
	}

	private function render_steps_editor($post, $steps, $is_template, $templates, $check_tpls) {
		?>
		<div class="cptt-admin-steps" data-is-template="<?php echo $is_template ? '1' : '0'; ?>">

			<?php if (!$is_template): ?>
				<div class="cptt-toolbar">
					<div class="cptt-toolbar__left">
						<label for="cptt_template_select"><strong>بارگذاری تمپلیت مراحل:</strong></label>
						<select id="cptt_template_select">
							<option value="">— انتخاب تمپلیت —</option>
							<?php foreach ($templates as $t): ?>
								<option value="<?php echo esc_attr($t->ID); ?>"><?php echo esc_html(get_the_title($t)); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button" id="cptt_apply_template_btn">اعمال تمپلیت</button>
						<span class="cptt-help">اعمال تمپلیت، مراحل فعلی را جایگزین می‌کند.</span>
					</div>
				</div>
			<?php endif; ?>

			<div id="cptt-steps-rows">
				<?php foreach ($steps as $i => $step):
					$step_id = isset($step['id']) ? (string)$step['id'] : (function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : ('st_' . wp_rand(1000,9999)));
					$title   = isset($step['title']) ? $step['title'] : '';
					$status  = isset($step['status']) ? $step['status'] : 'todo';
					$desc    = isset($step['desc']) ? $step['desc'] : '';

					$updated = isset($step['updated_at_fa']) ? (string)$step['updated_at_fa'] : '—';
					$updated_by_name = '—';
					if (!empty($step['updated_by'])) {
						$u = get_user_by('id', (int)$step['updated_by']);
						if ($u) $updated_by_name = $u->display_name;
					}

					$checklist = isset($step['checklist']) && is_array($step['checklist']) ? $step['checklist'] : [];
				?>
					<div class="cptt-step-row" data-step-id="<?php echo esc_attr($step_id); ?>">

						<div class="cptt-stepCard">
							<div class="cptt-stepCard__head">
								<div class="cptt-stepCard__drag cptt-drag-handle" title="جابجایی">⋮⋮</div>

								<div class="cptt-stepCard__title">
									<label class="cptt-fieldLabel">عنوان مرحله</label>
									<input type="hidden" name="cptt_steps[<?php echo esc_attr($i); ?>][id]" value="<?php echo esc_attr($step_id); ?>" />
									<input type="text" name="cptt_steps[<?php echo esc_attr($i); ?>][title]" value="<?php echo esc_attr($title); ?>" placeholder="عنوان مرحله" />
								</div>

								<div class="cptt-stepCard__status">
									<label class="cptt-fieldLabel">وضعیت</label>
									<select name="cptt_steps[<?php echo esc_attr($i); ?>][status]">
										<option value="todo" <?php selected($status, 'todo'); ?>>انجام‌نشده</option>
										<option value="current" <?php selected($status, 'current'); ?>>در حال انجام</option>
										<option value="done" <?php selected($status, 'done'); ?>>انجام‌شده</option>
									</select>
								</div>

								<?php if (!$is_template): ?>
								<div class="cptt-stepCard__updated">
									<label class="cptt-fieldLabel">آخرین تغییر</label>
									<div class="cptt-updBox">
										<div><?php echo esc_html($updated); ?></div>
										<small>توسط: <?php echo esc_html($updated_by_name); ?></small>
									</div>
								</div>
								<?php endif; ?>

								<div class="cptt-stepCard__delete">
									<label class="cptt-fieldLabel">&nbsp;</label>
									<button type="button" class="button cptt-remove-step">×</button>
								</div>
							</div>

							<div class="cptt-stepCard__body">
								<div class="cptt-stepCard__desc">
									<label class="cptt-fieldLabel">توضیحات پاپ‌آپ</label>
									<textarea name="cptt_steps[<?php echo esc_attr($i); ?>][desc]" rows="3" placeholder="توضیحات..."><?php echo esc_textarea($desc); ?></textarea>
								</div>

								<div class="cptt-stepCard__checklist">
									<div class="cptt-checklist-head">
										<div class="cptt-checklist-title">چک‌لیست (متن + لینک نتیجه)</div>
										<div class="cptt-checklist-toolbar">
											<select class="cptt-checktpl-select">
												<option value="">— تمپلیت چک‌لیست —</option>
												<?php foreach ($check_tpls as $ct): ?>
													<option value="<?php echo esc_attr($ct->ID); ?>"><?php echo esc_html(get_the_title($ct)); ?></option>
												<?php endforeach; ?>
											</select>
											<button type="button" class="button cptt-apply-checktpl">اعمال</button>
											<button type="button" class="button button-primary cptt-add-checkitem">+ آیتم</button>
										</div>
									</div>

									<div class="cptt-checkitems" data-step-index="<?php echo esc_attr($i); ?>">
										<?php foreach ($checklist as $j => $it):
											$cid = isset($it['id']) ? (string)$it['id'] : (function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : ('chk_' . wp_rand(1000,9999)));
											$text = isset($it['text']) ? $it['text'] : '';
											$url  = isset($it['url']) ? $it['url'] : '';
											$done = !empty($it['done']) ? 1 : 0;

											$done_at_fa = !empty($it['done_at_fa']) ? (string)$it['done_at_fa'] : '';
											$done_by_name = '';
											if (!empty($it['done_by'])) {
												$uu = get_user_by('id', (int)$it['done_by']);
												if ($uu) $done_by_name = $uu->display_name;
											}
										?>
											<div class="cptt-checkitem-row" data-check-id="<?php echo esc_attr($cid); ?>">
												<span class="cptt-checkitem-handle" title="جابجایی">⋮</span>

												<input type="hidden" name="cptt_steps[<?php echo esc_attr($i); ?>][checklist][<?php echo esc_attr($j); ?>][id]" value="<?php echo esc_attr($cid); ?>" />

												<label class="cptt-checkitem-done">
													<input type="checkbox"
														name="cptt_steps[<?php echo esc_attr($i); ?>][checklist][<?php echo esc_attr($j); ?>][done]"
														value="1" <?php checked($done, 1); ?> />
													انجام شد
													<?php if ($done_at_fa): ?>
														<small>
															<?php echo esc_html($done_at_fa); ?>
															<?php if ($done_by_name): ?>
																<?php echo esc_html(' — توسط: ' . $done_by_name); ?>
															<?php endif; ?>
														</small>
													<?php endif; ?>
												</label>

												<input type="text"
													name="cptt_steps[<?php echo esc_attr($i); ?>][checklist][<?php echo esc_attr($j); ?>][text]"
													value="<?php echo esc_attr($text); ?>"
													placeholder="متن آیتم..." />

												<input type="url"
													name="cptt_steps[<?php echo esc_attr($i); ?>][checklist][<?php echo esc_attr($j); ?>][url]"
													value="<?php echo esc_attr($url); ?>"
													placeholder="لینک نتیجه (اختیاری)..." />

												<button type="button" class="button cptt-remove-checkitem">×</button>
											</div>
										<?php endforeach; ?>
									</div>

									<p class="cptt-help" style="margin-top:8px;">
										اگر همه آیتم‌های چک‌لیست تیک بخورند، مرحله خودکار «انجام‌شده» می‌شود.
									</p>
								</div>
							</div>
						</div><!-- card -->
					</div><!-- row -->
				<?php endforeach; ?>
			</div>

			<button type="button" class="button button-primary" id="cptt-add-step">+ افزودن مرحله</button>

			<script type="text/template" id="cptt-step-template">
				<div class="cptt-step-row" data-step-id="{{uuid}}">
					<div class="cptt-stepCard">
						<div class="cptt-stepCard__head">
							<div class="cptt-stepCard__drag cptt-drag-handle" title="جابجایی">⋮⋮</div>

							<div class="cptt-stepCard__title">
								<label class="cptt-fieldLabel">عنوان مرحله</label>
								<input type="hidden" name="cptt_steps[{{i}}][id]" value="{{uuid}}" />
								<input type="text" name="cptt_steps[{{i}}][title]" value="" placeholder="عنوان مرحله" />
							</div>

							<div class="cptt-stepCard__status">
								<label class="cptt-fieldLabel">وضعیت</label>
								<select name="cptt_steps[{{i}}][status]">
									<option value="todo">انجام‌نشده</option>
									<option value="current">در حال انجام</option>
									<option value="done">انجام‌شده</option>
								</select>
							</div>

							<?php if (!$is_template): ?>
							<div class="cptt-stepCard__updated">
								<label class="cptt-fieldLabel">آخرین تغییر</label>
								<div class="cptt-updBox">
									<div>—</div>
									<small>توسط: —</small>
								</div>
							</div>
							<?php endif; ?>

							<div class="cptt-stepCard__delete">
								<label class="cptt-fieldLabel">&nbsp;</label>
								<button type="button" class="button cptt-remove-step">×</button>
							</div>
						</div>

						<div class="cptt-stepCard__body">
							<div class="cptt-stepCard__desc">
								<label class="cptt-fieldLabel">توضیحات پاپ‌آپ</label>
								<textarea name="cptt_steps[{{i}}][desc]" rows="3" placeholder="توضیحات..."></textarea>
							</div>

							<div class="cptt-stepCard__checklist">
								<div class="cptt-checklist-head">
									<div class="cptt-checklist-title">چک‌لیست (متن + لینک نتیجه)</div>
									<div class="cptt-checklist-toolbar">
										<select class="cptt-checktpl-select">
											<option value="">— تمپلیت چک‌لیست —</option>
											<?php foreach ($check_tpls as $ct): ?>
												<option value="<?php echo esc_attr($ct->ID); ?>"><?php echo esc_html(get_the_title($ct)); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="button" class="button cptt-apply-checktpl">اعمال</button>
										<button type="button" class="button button-primary cptt-add-checkitem">+ آیتم</button>
									</div>
								</div>

								<div class="cptt-checkitems" data-step-index="{{i}}"></div>

								<p class="cptt-help" style="margin-top:8px;">
									اگر همه آیتم‌های چک‌لیست تیک بخورند، مرحله خودکار «انجام‌شده» می‌شود.
								</p>
							</div>
						</div>
					</div>
				</div>
			</script>

			<script type="text/template" id="cptt-checkitem-template">
				<div class="cptt-checkitem-row" data-check-id="{{cid}}">
					<span class="cptt-checkitem-handle" title="جابجایی">⋮</span>
					<input type="hidden" name="cptt_steps[{{i}}][checklist][{{j}}][id]" value="{{cid}}" />
					<label class="cptt-checkitem-done">
						<input type="checkbox" name="cptt_steps[{{i}}][checklist][{{j}}][done]" value="1" />
						انجام شد
					</label>
					<input type="text" name="cptt_steps[{{i}}][checklist][{{j}}][text]" value="" placeholder="متن آیتم..." />
					<input type="url" name="cptt_steps[{{i}}][checklist][{{j}}][url]" value="" placeholder="لینک نتیجه (اختیاری)..." />
					<button type="button" class="button cptt-remove-checkitem">×</button>
				</div>
			</script>
		</div>
		<?php
	}

	/* =========================
	   Checklist Template metabox (same as before)
	   ========================= */
	public function render_checklist_tpl_metabox($post) {
		wp_nonce_field('cptt_save_checktpl', 'cptt_checktpl_nonce');

		$items = get_post_meta($post->ID, '_cptt_checklist_items', true);
		if (!is_array($items)) $items = [];
		?>
		<div class="cptt-checktpl">
			<p class="cptt-help">این تمپلیت را می‌توانید داخل هر مرحله اعمال کنید.</p>

			<div id="cptt-checktpl-rows">
				<?php foreach ($items as $i => $text): ?>
					<div class="cptt-checktpl-row">
						<input type="text" name="cptt_checktpl_items[<?php echo esc_attr($i); ?>]" value="<?php echo esc_attr($text); ?>" placeholder="متن آیتم..." />
						<button type="button" class="button cptt-remove-checktpl-row">×</button>
					</div>
				<?php endforeach; ?>
			</div>

			<button type="button" class="button button-primary" id="cptt-add-checktpl-row">+ افزودن آیتم</button>

			<script type="text/template" id="cptt-checktpl-row-template">
				<div class="cptt-checktpl-row">
					<input type="text" name="cptt_checktpl_items[{{i}}]" value="" placeholder="متن آیتم..." />
					<button type="button" class="button cptt-remove-checktpl-row">×</button>
				</div>
			</script>
		</div>
		<?php
	}

	/* =========================
	   Saving logic (keep your current logic)
	   NOTE: we keep your existing methods in your current file version.
	   To avoid breaking features, we do NOT re-implement full save logic here.
	   This file assumes your previous save logic exists in your installed version.
	   ========================= */

	/* IMPORTANT:
	   If you replaced class-cptt-admin.php previously (with full save logic),
	   you MUST keep those save methods.
	   For safety, I'm including the same save/ajax/normalize methods from your last working version below.
	*/

	/* ===== Normalize + Save + AJAX + Columns ===== */

	private function normalize_checklist($arr) {
		if (!is_array($arr)) return [];
		$out = [];
		foreach ($arr as $it) {
			$id = isset($it['id']) ? sanitize_text_field($it['id']) : '';
			if ($id === '') $id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : ('chk_' . wp_rand(1000,9999));

			$text = isset($it['text']) ? sanitize_text_field($it['text']) : '';
			$url  = isset($it['url']) ? esc_url_raw($it['url']) : '';
			$done = !empty($it['done']) ? 1 : 0;
			if ($text === '') continue;

			$out[] = ['id'=>$id,'text'=>$text,'url'=>$url,'done'=>$done];
		}
		return $out;
	}

	private function normalize_steps($steps) {
		if (!is_array($steps)) return [];
		$out = [];
		$current_found = false;

		foreach ($steps as $s) {
			$id = isset($s['id']) ? sanitize_text_field($s['id']) : '';
			if ($id === '') $id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : ('st_' . wp_rand(1000,9999));

			$title  = isset($s['title']) ? sanitize_text_field($s['title']) : '';
			$desc   = isset($s['desc']) ? wp_kses_post($s['desc']) : '';
			$status = isset($s['status']) ? sanitize_key($s['status']) : 'todo';
			if (!in_array($status, ['todo','current','done'], true)) $status = 'todo';

			$checklist = isset($s['checklist']) ? $this->normalize_checklist($s['checklist']) : [];

			if ($title === '' && $desc === '' && empty($checklist)) continue;

			if ($status === 'current') {
				if ($current_found) $status = 'todo';
				$current_found = true;
			}

			$out[] = [
				'id'=>$id,'title'=>$title,'desc'=>$desc,'status'=>$status,'checklist'=>$checklist
			];
		}

		if (!$current_found) {
			foreach ($out as $i => $s) {
				if ($s['status'] === 'todo') { $out[$i]['status'] = 'current'; break; }
			}
		}

		return $out;
	}

	private function apply_step_status_from_checklist($steps) {
		foreach ($steps as &$s) {
			$cl = isset($s['checklist']) && is_array($s['checklist']) ? $s['checklist'] : [];
			$total = 0; $done = 0;
			foreach ($cl as $it) {
				$text = (string)($it['text'] ?? '');
				if ($text === '') continue;
				$total++;
				if (!empty($it['done'])) $done++;
			}
			if ($total > 0) {
				if ($done >= $total) $s['status'] = 'done';
				else if (($s['status'] ?? '') === 'done') $s['status'] = 'current';
			}
		}
		unset($s);
		return $steps;
	}

	private function apply_status_timestamps($new_steps, $old_steps) {
		$now = (int) current_time('timestamp', true);
		$uid = (int) get_current_user_id();
		$any_status_changed = false;

		if (!is_array($old_steps)) $old_steps = [];
		$old_by_id = [];
		foreach ($old_steps as $os) if (is_array($os) && !empty($os['id'])) $old_by_id[(string)$os['id']] = $os;

		foreach ($new_steps as &$s) {
			$oid = (string)$s['id'];
			$old = $old_by_id[$oid] ?? null;
			$old_status = (is_array($old) && isset($old['status'])) ? (string)$old['status'] : null;

			if ($old_status !== null && $old_status === $s['status']) {
				if (isset($old['updated_at'])) $s['updated_at'] = (int)$old['updated_at'];
				if (isset($old['updated_at_fa'])) $s['updated_at_fa'] = (string)$old['updated_at_fa'];
				if (isset($old['updated_by'])) $s['updated_by'] = (int)$old['updated_by'];
				continue;
			}

			$s['updated_at'] = $now;
			$s['updated_at_fa'] = CPTT_Core::jalali_datetime($now);
			$s['updated_by'] = $uid;
			if ($old_status !== null && $old_status !== $s['status']) $any_status_changed = true;
		}
		unset($s);

		return [$new_steps, $any_status_changed];
	}

	private function apply_checklist_done_timestamps($new_steps, $old_steps) {
		$now = (int) current_time('timestamp', true);
		$uid = (int) get_current_user_id();
		if (!is_array($old_steps)) $old_steps = [];

		$old_by_step = [];
		foreach ($old_steps as $os) if (is_array($os) && !empty($os['id'])) $old_by_step[(string)$os['id']] = $os;

		foreach ($new_steps as &$st) {
			$sid = (string)($st['id'] ?? '');
			$old = $old_by_step[$sid] ?? null;

			$old_items = [];
			if (is_array($old) && !empty($old['checklist']) && is_array($old['checklist'])) {
				foreach ($old['checklist'] as $oi) if (is_array($oi) && !empty($oi['id'])) $old_items[(string)$oi['id']] = $oi;
			}

			if (empty($st['checklist']) || !is_array($st['checklist'])) continue;

			foreach ($st['checklist'] as &$it) {
				$cid = (string)($it['id'] ?? '');
				$ndone = !empty($it['done']) ? 1 : 0;

				$oi = ($cid && isset($old_items[$cid])) ? $old_items[$cid] : null;
				$odone = (!empty($oi) && !empty($oi['done'])) ? 1 : 0;

				if ($ndone === 1) {
					if ($odone === 1 && !empty($oi['done_at'])) {
						$it['done_at'] = (int)$oi['done_at'];
						$it['done_at_fa'] = !empty($oi['done_at_fa']) ? (string)$oi['done_at_fa'] : CPTT_Core::jalali_datetime((int)$oi['done_at']);
						if (!empty($oi['done_by'])) $it['done_by'] = (int)$oi['done_by'];
					} else {
						$it['done_at'] = $now;
						$it['done_at_fa'] = CPTT_Core::jalali_datetime($now);
						$it['done_by'] = $uid;
					}
				} else {
					unset($it['done_at'], $it['done_at_fa'], $it['done_by']);
				}
			}
			unset($it);
		}
		unset($st);

		return $new_steps;
	}

	private function checklist_changed($new_steps, $old_steps) {
		if (!is_array($old_steps)) $old_steps = [];
		$old_by_id = [];
		foreach ($old_steps as $os) if (is_array($os) && !empty($os['id'])) $old_by_id[(string)$os['id']] = $os;

		foreach ($new_steps as $ns) {
			$id = (string)($ns['id'] ?? '');
			$old = $old_by_id[$id] ?? null;

			$ncl = isset($ns['checklist']) && is_array($ns['checklist']) ? $ns['checklist'] : [];
			$ocl = (is_array($old) && isset($old['checklist']) && is_array($old['checklist'])) ? $old['checklist'] : [];

			$omap = [];
			foreach ($ocl as $oi) {
				if (!is_array($oi) || empty($oi['id'])) continue;
				$omap[(string)$oi['id']] = [
					'text' => (string)($oi['text'] ?? ''),
					'url'  => (string)($oi['url'] ?? ''),
					'done' => !empty($oi['done']) ? 1 : 0,
				];
			}

			foreach ($ncl as $ni) {
				if (!is_array($ni) || empty($ni['id'])) return true;
				$key = (string)$ni['id'];
				$nt = (string)($ni['text'] ?? '');
				$nu = (string)($ni['url'] ?? '');
				$nd = !empty($ni['done']) ? 1 : 0;

				if (!isset($omap[$key])) return true;
				if ($omap[$key]['text'] !== $nt) return true;
				if ($omap[$key]['url'] !== $nu) return true;
				if ((int)$omap[$key]['done'] !== (int)$nd) return true;
				unset($omap[$key]);
			}

			if (!empty($omap)) return true;
		}
		return false;
	}

	public function save_project_meta($post_id, $post) {
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
		if ( ! isset($_POST['cptt_nonce']) || ! wp_verify_nonce($_POST['cptt_nonce'], 'cptt_save_project') ) return;
		if ( ! current_user_can('edit_post', $post_id) ) return;

		$client_id = isset($_POST['cptt_client_user_id']) ? absint($_POST['cptt_client_user_id']) : 0;
		update_post_meta($post_id, '_cptt_client_user_id', $client_id);

		if (isset($_POST['cptt_experts_present'])) {
			$expert_ids = isset($_POST['cptt_expert_user_ids']) && is_array($_POST['cptt_expert_user_ids'])
				? array_map('absint', $_POST['cptt_expert_user_ids'])
				: [];
			$expert_ids = array_values(array_filter(array_unique($expert_ids)));

			update_post_meta($post_id, '_cptt_expert_user_ids', $expert_ids);
			update_post_meta($post_id, '_cptt_expert_user_id', !empty($expert_ids) ? (int)$expert_ids[0] : 0);
			update_post_meta($post_id, '_cptt_experts_csv', ',' . implode(',', $expert_ids) . ',');
		}

		$old_steps = get_post_meta($post_id, '_cptt_steps', true);

		$steps = isset($_POST['cptt_steps']) && is_array($_POST['cptt_steps']) ? $_POST['cptt_steps'] : [];
		$steps = $this->normalize_steps($steps);

		$steps = $this->apply_checklist_done_timestamps($steps, $old_steps);
		$steps = $this->apply_step_status_from_checklist($steps);
		[$steps, $any_status_changed] = $this->apply_status_timestamps($steps, $old_steps);

		$any_check_changed = $this->checklist_changed($steps, $old_steps);

		update_post_meta($post_id, '_cptt_steps', $steps);

		if ($any_status_changed || $any_check_changed) {
			$now = (int) current_time('timestamp', true);
			update_post_meta($post_id, '_cptt_last_update', $now);
			update_post_meta($post_id, '_cptt_last_update_fa', CPTT_Core::jalali_datetime($now));
		}
	}

	public function save_template_meta($post_id, $post) {
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
		if ( ! isset($_POST['cptt_template_nonce']) || ! wp_verify_nonce($_POST['cptt_template_nonce'], 'cptt_save_template') ) return;
		if ( ! current_user_can('edit_post', $post_id) ) return;

		$steps = isset($_POST['cptt_steps']) && is_array($_POST['cptt_steps']) ? $_POST['cptt_steps'] : [];
		$steps = $this->normalize_steps($steps);

		foreach ($steps as &$s) {
			unset($s['updated_at'], $s['updated_at_fa'], $s['updated_by']);
			$s['status'] = 'todo';
			if (isset($s['checklist']) && is_array($s['checklist'])) {
				foreach ($s['checklist'] as &$ci) {
					$ci['done'] = 0;
					unset($ci['done_at'], $ci['done_at_fa'], $ci['done_by']);
				}
				unset($ci);
			}
		}
		unset($s);

		update_post_meta($post_id, '_cptt_template_steps', $steps);
	}

	public function save_checklist_tpl_meta($post_id, $post) {
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
		if ( ! isset($_POST['cptt_checktpl_nonce']) || ! wp_verify_nonce($_POST['cptt_checktpl_nonce'], 'cptt_save_checktpl') ) return;
		if ( ! current_user_can('edit_post', $post_id) ) return;

		$items = isset($_POST['cptt_checktpl_items']) && is_array($_POST['cptt_checktpl_items']) ? $_POST['cptt_checktpl_items'] : [];
		$out = [];
		foreach ($items as $t) {
			$t = sanitize_text_field($t);
			if ($t !== '') $out[] = $t;
		}
		update_post_meta($post_id, '_cptt_checklist_items', $out);
	}

	public function ajax_get_template_steps() {
		if ( ! current_user_can('edit_cptt_projects') ) wp_send_json_error('no_access', 403);
		check_ajax_referer('cptt_admin_nonce', 'nonce');

		$id = isset($_GET['template_id']) ? absint($_GET['template_id']) : 0;
		if (!$id || get_post_type($id) !== 'cptt_template') wp_send_json_error('invalid', 400);

		$steps = get_post_meta($id, '_cptt_template_steps', true);
		if (!is_array($steps)) $steps = [];

		foreach ($steps as &$s) {
			unset($s['updated_at'], $s['updated_at_fa'], $s['updated_by']);
			$s['status'] = 'todo';
			if (isset($s['checklist']) && is_array($s['checklist'])) {
				foreach ($s['checklist'] as &$ci) {
					$ci['done'] = 0;
					unset($ci['done_at'], $ci['done_at_fa'], $ci['done_by']);
				}
				unset($ci);
			}
		}
		unset($s);

		wp_send_json_success($steps);
	}

	public function ajax_get_checklist_tpl() {
		if ( ! current_user_can('edit_cptt_projects') ) wp_send_json_error('no_access', 403);
		check_ajax_referer('cptt_admin_nonce', 'nonce');

		$id = isset($_GET['checktpl_id']) ? absint($_GET['checktpl_id']) : 0;
		if (!$id || get_post_type($id) !== 'cptt_checklist_tpl') wp_send_json_error('invalid', 400);

		$items = get_post_meta($id, '_cptt_checklist_items', true);
		if (!is_array($items)) $items = [];

		$out = [];
		foreach ($items as $t) {
			$t = sanitize_text_field($t);
			if ($t !== '') $out[] = $t;
		}

		wp_send_json_success($out);
	}

	public function columns($cols) {
		$new = [];
		$new['cb'] = $cols['cb'];
		$new['title'] = 'عنوان پروژه';
		$new['cptt_client'] = 'مشتری';
		$new['cptt_expert'] = 'کارشناسان';
		$new['cptt_progress'] = 'پیشرفت';
		$new['cptt_last_update'] = 'آخرین بروزرسانی';
		$new['date'] = $cols['date'];
		return $new;
	}

	public function column_content($col, $post_id) {
		if ( $col === 'cptt_client' ) {
			$uid = (int) get_post_meta($post_id, '_cptt_client_user_id', true);
			$u = $uid ? get_user_by('id', $uid) : null;
			echo $u ? esc_html($u->display_name) : '—';
		}

		if ( $col === 'cptt_expert' ) {
			$ids = get_post_meta($post_id, '_cptt_expert_user_ids', true);
			if (!is_array($ids)) $ids = [];
			if (empty($ids)) {
				$legacy = (int) get_post_meta($post_id, '_cptt_expert_user_id', true);
				if ($legacy) $ids = [$legacy];
			}
			$names = [];
			foreach ($ids as $id) {
				$u = get_user_by('id', (int)$id);
				if ($u) $names[] = $u->display_name;
			}
			echo $names ? esc_html(implode('، ', $names)) : '—';
		}

		if ( $col === 'cptt_progress' ) {
			$steps = get_post_meta($post_id, '_cptt_steps', true);
			if ( ! is_array($steps) || empty($steps) ) { echo '—'; return; }
			$total = count($steps);
			$done = 0;
			foreach ($steps as $s) if ( ($s['status'] ?? '') === 'done' ) $done++;
			$percent = $total ? round(($done / $total) * 100) : 0;
			echo esc_html($percent . '% (' . $done . '/' . $total . ')');
		}

		if ( $col === 'cptt_last_update' ) {
			$fa = (string) get_post_meta($post_id, '_cptt_last_update_fa', true);
			echo $fa ? esc_html($fa) : '—';
		}
	}
}