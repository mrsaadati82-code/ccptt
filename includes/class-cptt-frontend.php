<?php
if ( ! defined('ABSPATH') ) exit;

class CPTT_Frontend {
	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_shortcode('cptt_my_projects', [$this, 'shortcode_my_projects']);
		add_action('wp_enqueue_scripts', [$this, 'register_assets']);

		// WooCommerce integration (optional)
		add_action('init', [$this, 'maybe_add_wc_endpoint']);
		add_filter('woocommerce_account_menu_items', [$this, 'wc_menu_item']);
		add_action('woocommerce_account_cptt-projects_endpoint', [$this, 'wc_endpoint_content']);
	}

	public function register_assets() {
		wp_register_style('cptt-frontend', CPTT_URL . 'assets/css/frontend.css', [], CPTT_VERSION);
		wp_register_script('cptt-frontend', CPTT_URL . 'assets/js/frontend.js', [], CPTT_VERSION, true);
	}

	public function maybe_add_wc_endpoint() {
		if ( ! function_exists('WC') ) return;
		add_rewrite_endpoint('cptt-projects', EP_ROOT | EP_PAGES);
	}

	public function wc_menu_item($items) {
		if ( ! function_exists('WC') ) return $items;

		$new = [];
		foreach ($items as $key => $label) {
			if ( $key === 'customer-logout' ) {
				$new['cptt-projects'] = 'پروژه‌های من';
			}
			$new[$key] = $label;
		}
		return $new;
	}

	public function wc_endpoint_content() {
		echo do_shortcode('[cptt_my_projects]');
	}

	private function get_user_projects($user_id) {
		return get_posts([
			'post_type' => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => -1,
			'orderby' => 'date',
			'order' => 'DESC',
			'meta_query' => [
				[
					'key' => '_cptt_client_user_id',
					'value' => (int) $user_id,
					'compare' => '='
				]
			],
		]);
	}

	private function status_label($status) {
		$map = [
			'done'    => 'انجام‌شده',
			'current' => 'در حال انجام',
			'todo'    => 'انجام‌نشده',
		];
		return $map[$status] ?? $map['todo'];
	}

	private function get_expert_names($project_id) {
		$names = [];

		// Multi experts (new)
		if (class_exists('CPTT_Core') && method_exists('CPTT_Core', 'get_project_expert_ids')) {
			$ids = CPTT_Core::get_project_expert_ids($project_id);
			foreach ($ids as $id) {
				$u = get_user_by('id', (int)$id);
				if ($u) $names[] = $u->display_name;
			}
			$names = array_values(array_unique(array_filter($names)));
			return $names;
		}

		// Legacy single expert fallback
		$expert_id = (int) get_post_meta($project_id, '_cptt_expert_user_id', true);
		if ($expert_id) {
			$u = get_user_by('id', $expert_id);
			if ($u) $names[] = $u->display_name;
		}

		return $names;
	}

	public function shortcode_my_projects($atts) {
		if ( ! is_user_logged_in() ) {
			return '<div class="cptt-notice">برای مشاهده پروژه‌ها وارد حساب کاربری شوید.</div>';
		}

		wp_enqueue_style('cptt-frontend');
		wp_enqueue_script('cptt-frontend');

		$user_id  = get_current_user_id();
		$projects = $this->get_user_projects($user_id);

		ob_start();
		?>
		<div class="cptt-wrap" dir="rtl">
			<div class="cptt-title">پروژه‌های من</div>

			<?php if ( empty($projects) ): ?>
				<div class="cptt-empty">در حال حاضر پروژه‌ای برای نمایش وجود ندارد.</div>
			<?php else: ?>
				<?php foreach ($projects as $p):

					$steps = get_post_meta($p->ID, '_cptt_steps', true);
					if ( ! is_array($steps) ) $steps = [];

					$total = count($steps);
					$done  = 0;
					$has_current = false;

					foreach ($steps as $s) {
						$st = $s['status'] ?? 'todo';
						if ($st === 'done') $done++;
						if ($st === 'current') $has_current = true;
					}

					// Project progress (done + 0.5 current)
					$units   = $done + ($has_current ? 0.5 : 0);
					$percent = $total ? (int) round(($units / $total) * 100) : 0;

					$last_update = (string) get_post_meta($p->ID, '_cptt_last_update_fa', true);
					$expert_names = $this->get_expert_names($p->ID);

					// Report button only if complete
					$is_complete = (class_exists('CPTT_Report') && method_exists('CPTT_Report', 'is_project_complete'))
						? CPTT_Report::is_project_complete($p->ID)
						: false;

					$report_url = '';
					if ($is_complete) {
						$report_url = wp_nonce_url(
							admin_url('admin-post.php?action=cptt_view_report&project_id=' . $p->ID),
							'cptt_view_report_' . $p->ID
						);
					}
				?>
					<section class="cptt-project">
						<header class="cptt-project__header">
							<div>
								<h3 class="cptt-project__title"><?php echo esc_html(get_the_title($p)); ?></h3>

								<?php if (!empty($expert_names)): ?>
									<div class="cptt-project__meta">کارشناسان: <?php echo esc_html(implode('، ', $expert_names)); ?></div>
								<?php endif; ?>

								<?php if ($last_update): ?>
									<div class="cptt-project__meta">آخرین بروزرسانی: <?php echo esc_html($last_update); ?></div>
								<?php endif; ?>
							</div>

							<div class="cptt-project__progress">
								<div class="cptt-progressbar" aria-label="درصد پیشرفت">
									<div class="cptt-progressbar__fill" data-cptt-width="<?php echo esc_attr($percent); ?>" style="width:0%"></div>
								</div>
								<div class="cptt-progressbar__label"><?php echo esc_html($percent . '%'); ?></div>
							</div>
						</header>

						<?php if ( empty($steps) ): ?>
							<div class="cptt-empty">هنوز مراحلی برای این پروژه ثبت نشده است.</div>
						<?php else: ?>
							<ol class="cptt-stepper" role="list" style="--cptt-progress: <?php echo esc_attr($percent); ?>%;">
								<?php foreach ($steps as $i => $s):
									$title  = $s['title'] ?? ('مرحله ' . ($i+1));
									$desc   = $s['desc'] ?? '';
									$status = $s['status'] ?? 'todo';
									if (!in_array($status, ['todo','current','done'], true)) $status = 'todo';

									$time_fa = (string)($s['updated_at_fa'] ?? '');

									$updated_by_name = '';
									if (!empty($s['updated_by'])) {
										$uu = get_user_by('id', (int)$s['updated_by']);
										if ($uu) $updated_by_name = $uu->display_name;
									}

									// Checklist payload for modal + ring %
									$checklist = isset($s['checklist']) && is_array($s['checklist']) ? $s['checklist'] : [];
									$cl_total = 0;
									$cl_done  = 0;
									$cl_items = [];

									foreach ($checklist as $it) {
										if (!is_array($it)) continue;
										$text = isset($it['text']) ? (string)$it['text'] : '';
										if ($text === '') continue;

										$is_done = !empty($it['done']) ? 1 : 0;
										$url = isset($it['url']) ? (string)$it['url'] : '';
										$done_at_fa = isset($it['done_at_fa']) ? (string)$it['done_at_fa'] : '';

										$done_by_name = '';
										if (!empty($it['done_by'])) {
											$u2 = get_user_by('id', (int)$it['done_by']);
											if ($u2) $done_by_name = $u2->display_name;
										}

										$cl_total++;
										if ($is_done) $cl_done++;

										$cl_items[] = [
											'text' => $text,
											'done' => (bool)$is_done,
											'url'  => $url,
											'done_at_fa' => $done_at_fa,
											'done_by_name' => $done_by_name,
										];
									}

									$step_ring = 0;
									if ($status === 'done') $step_ring = 100;
									elseif ($cl_total > 0) $step_ring = (int) round(($cl_done / $cl_total) * 100);

									$checklist_payload = [
										'total' => $cl_total,
										'done'  => $cl_done,
										'items' => $cl_items,
									];
									$checklist_b64 = base64_encode(wp_json_encode($checklist_payload, JSON_UNESCAPED_UNICODE));

									if ($status === 'done') $icon = '✓';
									elseif ($status === 'current') $icon = ''; // spinner by CSS
									else $icon = '×';

									$badge_text = $this->status_label($status);
								?>
									<li class="<?php echo esc_attr('cptt-step cptt-step--' . $status); ?>">
										<button type="button"
											class="cptt-step__btn"
											data-cptt-step-title="<?php echo esc_attr($title); ?>"
											data-cptt-step-updated="<?php echo esc_attr($time_fa); ?>"
											data-cptt-step-by="<?php echo esc_attr($updated_by_name); ?>"
											data-cptt-step-desc-b64="<?php echo esc_attr( base64_encode( wp_kses_post($desc) ) ); ?>"
											data-cptt-checklist-b64="<?php echo esc_attr($checklist_b64); ?>"
											aria-haspopup="dialog">

											<span class="cptt-tl" aria-hidden="true">
												<span class="cptt-tl__icon" style="--cptt-ring: <?php echo esc_attr($step_ring); ?>;">
													<?php echo esc_html($icon); ?>
												</span>
											</span>

											<span class="cptt-step__badge cptt-step__badge--<?php echo esc_attr($status); ?>">
												<?php echo esc_html($badge_text); ?>
											</span>

											<span class="cptt-step__label"><?php echo esc_html($title); ?></span>

											<?php if ($time_fa): ?>
												<span class="cptt-step__time">
													<?php echo esc_html($time_fa); ?>
													<?php if ($updated_by_name): ?>
														<?php echo esc_html(' — توسط: ' . $updated_by_name); ?>
													<?php endif; ?>
												</span>
											<?php endif; ?>
										</button>
									</li>
								<?php endforeach; ?>
							</ol>

							<?php if ($is_complete && $report_url): ?>
								<div style="margin-top:12px;">
									<a class="button" href="<?php echo esc_url($report_url); ?>" target="_blank" rel="noopener noreferrer">
										مشاهده گزارش
									</a>
								</div>
							<?php endif; ?>

						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			<?php endif; ?>

			<!-- Modal -->
			<div class="cptt-modal" aria-hidden="true">
				<div class="cptt-modal__backdrop" data-cptt-close></div>
				<div class="cptt-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="cptt-modal-title">
					<button type="button" class="cptt-modal__close" data-cptt-close aria-label="بستن">×</button>
					<div class="cptt-modal__title" id="cptt-modal-title"></div>
					<div class="cptt-modal__meta" id="cptt-modal-meta"></div>
					<div class="cptt-modal__content" id="cptt-modal-content"></div>
					<div class="cptt-modal__checklist" id="cptt-modal-checklist"></div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}