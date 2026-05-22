<?php
if ( ! defined('ABSPATH') ) exit;

class CPTT_Admin {
	private static $instance = null;
	public static function instance() {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}
	private function __construct() {
		add_action('admin_menu', [$this, 'reorder_menu'], 999);
		add_action('add_meta_boxes', [$this, 'add_metaboxes']);
		add_action('admin_menu', [$this, 'dashboard_menu']);
		add_action('save_post_cptt_project', [$this, 'save_project_meta'], 10, 2);
		add_action('save_post_cptt_template', [$this, 'save_template_meta'], 10, 2);
		add_action('save_post_cptt_checklist_tpl', [$this, 'save_checklist_tpl_meta'], 10, 2);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
		add_filter('manage_cptt_project_posts_columns', [$this, 'columns']);
		add_action('manage_cptt_project_posts_custom_column', [$this, 'column_content'], 10, 2);
		add_action('wp_ajax_cptt_get_template_steps', [$this, 'ajax_get_template_steps']);
		add_action('wp_ajax_cptt_get_checklist_tpl', [$this, 'ajax_get_checklist_tpl']);
		add_action('wp_ajax_cptt_quick_pay', [$this, 'ajax_quick_pay']);
	}
	public function reorder_menu() {
		global $submenu;
		$parent = 'edit.php?post_type=cptt_project';
		if (empty($submenu[$parent])) return;
		$items = [];
		foreach ($submenu[$parent] as $item) { $items[$item[2]] = $item; }
		$ordered = [];
		if (isset($items['cptt-project-dashboard'])) { $ordered[] = $items['cptt-project-dashboard']; unset($items['cptt-project-dashboard']); }
		if (isset($items[$parent])) { $ordered[] = $items[$parent]; unset($items[$parent]); }
		if (isset($items['post-new.php?post_type=cptt_project'])) { $ordered[] = $items['post-new.php?post_type=cptt_project']; unset($items['post-new.php?post_type=cptt_project']); }
		if (isset($items['edit.php?post_type=cptt_template'])) { $ordered[] = $items['edit.php?post_type=cptt_template']; unset($items['edit.php?post_type=cptt_template']); }
		if (isset($items['edit.php?post_type=cptt_checklist_tpl'])) { $ordered[] = $items['edit.php?post_type=cptt_checklist_tpl']; unset($items['edit.php?post_type=cptt_checklist_tpl']); }
		if (isset($items['cptt-accounting'])) { $ordered[] = $items['cptt-accounting']; unset($items['cptt-accounting']); }
		if (isset($items['cptt-sms-settings'])) { $ordered[] = $items['cptt-sms-settings']; unset($items['cptt-sms-settings']); }
		if (isset($items['cptt-branding'])) { $ordered[] = $items['cptt-branding']; unset($items['cptt-branding']); }
		foreach ($items as $item) { $ordered[] = $item; }
		$submenu[$parent] = $ordered;
	}
	public function enqueue_admin_assets($hook) {
		global $post_type;
		$is_dashboard = ($hook === 'cptt_project_page_cptt-project-dashboard' || $hook === 'cptt_project_page_cptt-accounting');
		if ( ! $is_dashboard && ! in_array($post_type, ['cptt_project','cptt_template','cptt_checklist_tpl'], true) ) return;
		wp_enqueue_style('cptt-admin', CPTT_URL . 'assets/css/admin.css', [], CPTT_VERSION);
		wp_enqueue_script('jquery-ui-sortable');
		wp_enqueue_script('cptt-admin', CPTT_URL . 'assets/js/admin.js', ['jquery','jquery-ui-sortable'], CPTT_VERSION, true);
		wp_localize_script('cptt-admin', 'CPTT_ADMIN', ['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('cptt_admin_nonce')]);
	}
	public function dashboard_menu() {
		add_submenu_page('edit.php?post_type=cptt_project','داشبورد پروژه‌ها','داشبورد پروژه‌ها','edit_cptt_projects','cptt-project-dashboard',[$this,'render_dashboard_page']);
		add_submenu_page('edit.php?post_type=cptt_project','حساب و کتاب','حساب و کتاب','edit_cptt_projects','cptt-accounting',[$this,'render_accounting_page']);
	}
	public function add_metaboxes() {
		add_meta_box('cptt_project_details','اطلاعات پروژه',[$this,'render_details_metabox'],'cptt_project','normal','high');
		add_meta_box('cptt_project_steps','مراحل پروژه (Stepper)',[$this,'render_steps_metabox_project'],'cptt_project','normal','high');
		add_meta_box('cptt_template_steps','مراحل تمپلیت',[$this,'render_steps_metabox_template'],'cptt_template','normal','high');
		add_meta_box('cptt_checklist_tpl_items','آیتم‌های تمپلیت چک‌لیست',[$this,'render_checklist_tpl_metabox'],'cptt_checklist_tpl','normal','high');
		add_meta_box('cptt_project_notes','یادداشت‌های کارشناسان',[$this,'render_notes_metabox'],'cptt_project','side','default');
		add_meta_box('cptt_project_accounting','حساب و کتاب پروژه',[$this,'render_accounting_metabox'],'cptt_project','side','default');
	}
	private function product_select_html($name,$selected=0,$class='cptt-select') {
		$selected=(int)$selected;
		if (!post_type_exists('product')) return '<select class="'.esc_attr($class).'" disabled><option>ووکامرس/محصولی یافت نشد</option></select>';
		$products=get_posts(['post_type'=>'product','post_status'=>['publish','draft','private'],'numberposts'=>500,'orderby'=>'title','order'=>'ASC']);
		$html='<select id="'.esc_attr(str_replace(['[',']'],'',$name)).'" name="'.esc_attr($name).'" class="'.esc_attr($class).'">';
		$html.='<option value="">— بدون محصول —</option>';
		foreach ($products as $product) {
			$terms=get_the_terms($product->ID,'product_cat'); $cat_ids=[];
			if (!is_wp_error($terms)&&!empty($terms)) foreach($terms as $t)$cat_ids[]=(int)$t->term_id;
			$data_cats=!empty($cat_ids)?' data-cats="'.esc_attr(implode(',',$cat_ids)).'"':'';
			$html.=sprintf('<option value="%d"%s%s>%s</option>',(int)$product->ID,selected($selected,(int)$product->ID,false),$data_cats,esc_html(get_the_title($product)));
		}
		$html.='</select>'; return $html;
	}
	private function product_title($product_id) {
		$product_id=(int)$product_id; if(!$product_id)return '—';
		$title=get_the_title($product_id); return $title?:('#'.$product_id);
	}
	private function project_progress_data($project_id) {
		$steps=get_post_meta($project_id,'_cptt_steps',true);
		if (!is_array($steps)||empty($steps)) return ['percent'=>0,'done'=>0,'total'=>0,'status'=>'in_progress','label'=>'در حال انجام'];
		$total=count($steps); $done=0;
		foreach ($steps as $s) if (($s['status']??'')==='done') $done++;
		$percent=$total?(int)round(($done/$total)*100):0;
		$status=($total>0&&$done>=$total)?'completed':'in_progress';
		return ['percent'=>$percent,'done'=>$done,'total'=>$total,'status'=>$status,'label'=>$status==='completed'?'تکمیل شده':'در حال انجام'];
	}
	private function project_financial_data($project_id) {
		$steps=get_post_meta($project_id,'_cptt_steps',true);
		if (!is_array($steps)) $steps=[];
		$tc=0; $tp=0;
		foreach ($steps as $s){ $tc+=(float)($s['cost']??0); $tp+=(float)($s['paid']??0); }
		$tr=$tc-$tp;
		$percent=$tc>0?round(($tp/$tc)*100,1):0;
		return ['cost'=>$tc,'paid'=>$tp,'remain'=>$tr,'percent'=>$percent];
	}
	public function render_dashboard_page() {
		if (!current_user_can('edit_cptt_projects')) return;
		$query_args=['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>-1,'orderby'=>'date','order'=>'DESC'];
		$user=wp_get_current_user();
		if (!in_array('administrator',(array)$user->roles,true)&&in_array('cptt_expert',(array)$user->roles,true)) {
			$query_args['meta_query']=[['key'=>'_cptt_experts_csv','value'=>','.get_current_user_id().',','compare'=>'LIKE']];
		}
		$projects=get_posts($query_args);
		$clients=[]; $experts_map=[]; $products_map=[]; $cards=[];
		$wc_cats_map=[]; $today_start=strtotime('today midnight'); $today_end=strtotime('tomorrow midnight')-1; $now=current_time('timestamp',true);
		$insight_today=[]; $insight_overdue=[]; $insight_follow=[];
		foreach ($projects as $p) {
			$client_id=(int)get_post_meta($p->ID,'_cptt_client_user_id',true);
			$client=$client_id?get_user_by('id',$client_id):null;
			if ($client) $clients[$client_id]=$client->display_name;
			$expert_ids=class_exists('CPTT_Core')?CPTT_Core::get_project_expert_ids($p->ID):[];
			$expert_names=[];
			foreach ($expert_ids as $eid) {
				$u=get_user_by('id',(int)$eid);
				if ($u) { $experts_map[(int)$eid]=$u->display_name; $expert_names[]=$u->display_name; }
			}
			$product_id=(int)get_post_meta($p->ID,'_cptt_product_id',true);
			if (!$product_id) $product_id=(int)get_post_meta($p->ID,'_cptt_wc_product_id',true);
			if ($product_id) $products_map[$product_id]=$this->product_title($product_id);
			$cat_ids=get_post_meta($p->ID,'_cptt_wc_cat_ids',true);
			$cat_names=[];
			if (is_array($cat_ids)) {
				foreach ($cat_ids as $cid) {
					$term=get_term((int)$cid,'product_cat');
					if ($term&&!is_wp_error($term)) { $cat_names[]=$term->name; $wc_cats_map[(int)$cid]=$term->name; }
				}
			}
			$progress=$this->project_progress_data($p->ID);
			$settled=(int)get_post_meta($p->ID,'_cptt_is_settled',true);
			$deadline=(string)get_post_meta($p->ID,'_cptt_deadline_at_fa',true);
			$last_update=(string)get_post_meta($p->ID,'_cptt_last_update_fa',true);
			$cards[]=['post'=>$p,'client_id'=>$client_id,'client_name'=>$client?$client->display_name:'—','expert_ids'=>$expert_ids,'expert_names'=>$expert_names,'product_id'=>$product_id,'product_title'=>$product_id?$this->product_title($product_id):'—','term_ids'=>$cat_ids?:[],'term_names'=>$cat_names,'progress'=>$progress,'settled'=>$settled,'deadline'=>$deadline,'last_update'=>$last_update];
			$steps=get_post_meta($p->ID,'_cptt_steps',true);
			if (is_array($steps)) {
				$assigned=in_array('administrator',(array)$user->roles,true)||in_array(get_current_user_id(),$expert_ids,true);
				foreach ($steps as $s) {
					if (($s['status']??'todo')==='done') continue;
					if (!$assigned) continue;
					$due=(int)($s['due_at']??0);
					$item=['project_title'=>get_the_title($p),'project_id'=>$p->ID,'step_title'=>($s['title']??''),'due_fa'=>($s['due_at_fa']??'')];
					if ($due&&$due>=$today_start&&$due<=$today_end) $insight_today[]=$item;
					elseif ($due&&$due<$now) $insight_overdue[]=$item;
					else $insight_follow[]=$item;
				}
			}
		}
		asort($clients); asort($experts_map); asort($products_map); asort($wc_cats_map);
		?>
		<div class="wrap cptt-dashboard" dir="rtl">
			<div class="cptt-dashboard__hero">
				<div><h1>داشبورد پروژه‌ها</h1><p>نمای اختصاصی پروژه‌ها با فیلتر سریع، بدون بارگذاری مجدد صفحه.</p></div>
				<a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=cptt_project')); ?>">+ پروژه جدید</a>
			</div>
			<div class="cptt-dashboard__insights">
				<div class="cptt-insight">
					<div class="cptt-insight__title">کارهای امروز</div>
					<div class="cptt-insight__count"><?php echo count($insight_today); ?></div>
					<?php if ($insight_today): ?>
					<ul class="cptt-insight__list">
						<?php foreach (array_slice($insight_today,0,3) as $it): ?>
						<li><a href="<?php echo esc_url(get_edit_post_link($it['project_id'])); ?>"><?php echo esc_html($it['project_title']); ?></a> — <?php echo esc_html($it['step_title']); ?></li>
						<?php endforeach; ?>
					</ul><?php endif; ?>
				</div>
				<div class="cptt-insight cptt-insight--warn">
					<div class="cptt-insight__title">تاخیر</div>
					<div class="cptt-insight__count"><?php echo count($insight_overdue); ?></div>
					<?php if ($insight_overdue): ?>
					<ul class="cptt-insight__list">
						<?php foreach (array_slice($insight_overdue,0,3) as $it): ?>
						<li><a href="<?php echo esc_url(get_edit_post_link($it['project_id'])); ?>"><?php echo esc_html($it['project_title']); ?></a> — <?php echo esc_html($it['step_title']); ?><?php if ($it['due_fa']){ ?> <small>(<?php echo esc_html($it['due_fa']); ?>)</small><?php } ?></li>
						<?php endforeach; ?>
					</ul><?php endif; ?>
				</div>
				<div class="cptt-insight">
					<div class="cptt-insight__title">قابل پیگیری</div>
					<div class="cptt-insight__count"><?php echo count($insight_follow); ?></div>
					<?php if ($insight_follow): ?>
					<ul class="cptt-insight__list">
						<?php foreach (array_slice($insight_follow,0,3) as $it): ?>
						<li><a href="<?php echo esc_url(get_edit_post_link($it['project_id'])); ?>"><?php echo esc_html($it['project_title']); ?></a> — <?php echo esc_html($it['step_title']); ?></li>
						<?php endforeach; ?>
					</ul><?php endif; ?>
				</div>
			</div>
			<div class="cptt-dashboard__filters">
				<label>جستجو<input type="search" id="cptt-dash-search" placeholder="عنوان، مشتری، کارشناس، محصول..."></label>
				<label>کارشناس<select id="cptt-dash-expert"><option value="">همه</option><?php foreach ($experts_map as $id=>$name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<label>وضعیت<select id="cptt-dash-status"><option value="">همه</option><option value="completed">تکمیل شده</option><option value="in_progress">در حال انجام</option></select></label>
				<label>مشتری<select id="cptt-dash-client"><option value="">همه</option><?php foreach ($clients as $id=>$name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<label>تسویه<select id="cptt-dash-settled"><option value="">همه</option><option value="1">تسویه شده</option><option value="0">تسویه نشده</option></select></label>
				<label>دسته‌بندی<select id="cptt-dash-cat"><option value="">همه</option><?php foreach ($wc_cats_map as $id=>$name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<label>محصول<select id="cptt-dash-product"><option value="">همه</option><?php foreach ($products_map as $id=>$name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<button type="button" class="button" id="cptt-dash-reset">پاک کردن فیلترها</button>
			</div>
			<div class="cptt-dashboard__summary">
				<span><b id="cptt-dash-count"><?php echo count($cards); ?></b> پروژه نمایش داده می‌شود</span>
			</div>
			<div class="cptt-dashboard__grid" id="cptt-dash-grid">
				<?php foreach ($cards as $c):
					$p=$c['post'];
					$search=strtolower(get_the_title($p).' '.$c['client_name'].' '.implode(' ',$c['expert_names']).' '.$c['product_title'].' '.implode(' ',$c['term_names']));
				?>
					<article class="cptt-project-card"
						data-search="<?php echo esc_attr($search); ?>"
						data-client="<?php echo esc_attr($c['client_id']); ?>"
						data-experts=",<?php echo esc_attr(implode(',',array_map('intval',$c['expert_ids']))); ?>,"
						data-status="<?php echo esc_attr($c['progress']['status']); ?>"
						data-settled="<?php echo esc_attr($c['settled']); ?>"
						data-cats=",<?php echo esc_attr(implode(',',array_map('intval',(array)$c['term_ids']))); ?>,"
						data-product="<?php echo esc_attr($c['product_id']); ?>">
						<div class="cptt-project-card__top">
							<h2><?php echo esc_html(get_the_title($p)); ?></h2>
							<span class="cptt-chip cptt-chip--<?php echo esc_attr($c['progress']['status']); ?>"><?php echo esc_html($c['progress']['label']); ?></span>
						</div>
						<div class="cptt-project-card__bar"><span style="width:<?php echo esc_attr($c['progress']['percent']); ?>%"></span></div>
						<div class="cptt-project-card__percent"><?php echo esc_html($c['progress']['percent'].'% ('.$c['progress']['done'].'/'.$c['progress']['total'].')'); ?></div>
						<div class="cptt-project-card__meta">
							<div><b>مشتری:</b> <?php echo esc_html($c['client_name']); ?></div>
							<div><b>کارشناسان:</b> <?php echo esc_html($c['expert_names']?implode('، ',$c['expert_names']):'—'); ?></div>
							<div><b>محصول:</b> <?php echo esc_html($c['product_title']); ?></div>
							<div><b>دسته‌بندی:</b> <?php echo esc_html($c['term_names']?implode('، ',$c['term_names']):'—'); ?></div>
							<div><b>تسویه:</b> <?php echo $c['settled']?'تسویه شده':'تسویه نشده'; ?></div>
							<?php if ($c['deadline']): ?><div><b>مهلت:</b> <?php echo esc_html($c['deadline']); ?></div><?php endif; ?>
							<?php if ($c['last_update']): ?><div><b>آخرین بروزرسانی:</b> <?php echo esc_html($c['last_update']); ?></div><?php endif; ?>
						</div>
						<div class="cptt-project-card__actions">
							<a class="button button-primary" href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>">ویرایش پروژه</a>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
			<div class="cptt-dashboard__empty" id="cptt-dash-empty" style="display:none;">هیچ پروژه‌ای با این فیلترها پیدا نشد.</div>
		</div>
		<?php
	}
	public function render_accounting_page() {
		if (!current_user_can('edit_cptt_projects')) return;
		$query_args=['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>-1,'orderby'=>'date','order'=>'DESC'];
		$user=wp_get_current_user();
		if (!in_array('administrator',(array)$user->roles,true)&&in_array('cptt_expert',(array)$user->roles,true)) {
			$query_args['meta_query']=[['key'=>'_cptt_experts_csv','value'=>','.get_current_user_id().',','compare'=>'LIKE']];
		}
		$projects=get_posts($query_args);
		$total_cost_all=0; $total_paid_all=0; $total_projects=count($projects); $settled_count=0; $unsettled_count=0;
		$rows=[]; $clients_map=[]; $experts_map=[];
		foreach ($projects as $p) {
			$fin=$this->project_financial_data($p->ID);
			$total_cost_all+=$fin['cost']; $total_paid_all+=$fin['paid'];
			if ((int)get_post_meta($p->ID,'_cptt_is_settled',true)) $settled_count++; else $unsettled_count++;
			$client_id=(int)get_post_meta($p->ID,'_cptt_client_user_id',true);
			$client=$client_id?get_user_by('id',$client_id):null;
			if ($client) $clients_map[$client_id]=$client->display_name;
			$expert_ids=class_exists('CPTT_Core')?CPTT_Core::get_project_expert_ids($p->ID):[];
			$expert_names=[];
			foreach ($expert_ids as $eid) {
				$u=get_user_by('id',$eid);
				if ($u) { $experts_map[(int)$eid]=$u->display_name; $expert_names[]=$u->display_name; }
			}
			$progress=$this->project_progress_data($p->ID);
			$rows[]=['post'=>$p,'client'=>($client?$client->display_name:'—'),'client_id'=>$client_id,'expert_ids'=>$expert_ids,'experts'=>implode('، ',$expert_names),'fin'=>$fin,'progress'=>$progress,'settled'=>(int)get_post_meta($p->ID,'_cptt_is_settled',true)];
		}
		$total_remain_all=$total_cost_all-$total_paid_all;
		$clients_map=[]; // rebuild for unique
		foreach ($rows as $r) { if ($r['client_id']) $clients_map[$r['client_id']]=$r['client']; }
		asort($clients_map); asort($experts_map);
		?>
		<div class="wrap cptt-dashboard cptt-accounting" dir="rtl">
			<div class="cptt-dashboard__hero">
				<div><h1>حساب و کتاب</h1><p>مدیریت مالی پروژه‌ها، تسویه حساب و ثبت دریافتی.</p></div>
				<a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=cptt_project')); ?>">+ پروژه جدید</a>
			</div>

			<div class="cptt-acct-kpi-grid">
				<div class="cptt-acct-kpi">
					<div class="cptt-acct-kpi__icon" style="background:linear-gradient(135deg,#dbeafe,#eff6ff);color:#1d4ed8;">📁</div>
					<div class="cptt-acct-kpi__body">
						<div class="cptt-acct-kpi__label">تعداد پروژه‌ها</div>
						<div class="cptt-acct-kpi__value"><?php echo number_format($total_projects); ?></div>
					</div>
				</div>
				<div class="cptt-acct-kpi">
					<div class="cptt-acct-kpi__icon" style="background:linear-gradient(135deg,#fef3c7,#fffbeb);color:#b45309;">💰</div>
					<div class="cptt-acct-kpi__body">
						<div class="cptt-acct-kpi__label">کل هزینه</div>
						<div class="cptt-acct-kpi__value"><?php echo number_format($total_cost_all); ?></div>
						<small>تومان</small>
					</div>
				</div>
				<div class="cptt-acct-kpi">
					<div class="cptt-acct-kpi__icon" style="background:linear-gradient(135deg,#dcfce7,#f0fdf4);color:#15803d;">💳</div>
					<div class="cptt-acct-kpi__body">
						<div class="cptt-acct-kpi__label">کل دریافتی</div>
						<div class="cptt-acct-kpi__value"><?php echo number_format($total_paid_all); ?></div>
						<small>تومان</small>
					</div>
				</div>
				<div class="cptt-acct-kpi <?php echo $total_remain_all>0?'is-remain':'is-done'; ?>">
					<div class="cptt-acct-kpi__icon" style="background:linear-gradient(135deg,#fee2e2,#fef2f2);color:#b91c1c;">📊</div>
					<div class="cptt-acct-kpi__body">
						<div class="cptt-acct-kpi__label">مانده کل</div>
						<div class="cptt-acct-kpi__value"><?php echo number_format($total_remain_all); ?></div>
						<small>تومان</small>
					</div>
				</div>
				<div class="cptt-acct-kpi">
					<div class="cptt-acct-kpi__icon" style="background:linear-gradient(135deg,#dcfce7,#f0fdf4);color:#15803d;">✅</div>
					<div class="cptt-acct-kpi__body">
						<div class="cptt-acct-kpi__label">تسویه شده</div>
						<div class="cptt-acct-kpi__value"><?php echo number_format($settled_count); ?></div>
						<small>پروژه</small>
					</div>
				</div>
				<div class="cptt-acct-kpi">
					<div class="cptt-acct-kpi__icon" style="background:linear-gradient(135deg,#fee2e2,#fef2f2);color:#b91c1c;">⏳</div>
					<div class="cptt-acct-kpi__body">
						<div class="cptt-acct-kpi__label">تسویه نشده</div>
						<div class="cptt-acct-kpi__value"><?php echo number_format($unsettled_count); ?></div>
						<small>پروژه</small>
					</div>
				</div>
			</div>

			<div class="cptt-acct-filters">
				<label>جستجو<input type="search" id="cptt-acct-search" placeholder="عنوان پروژه، مشتری..."></label>
				<label>مشتری<select id="cptt-acct-client"><option value="">همه</option><?php foreach ($clients_map as $id=>$name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<label>وضعیت مالی<select id="cptt-acct-settled"><option value="">همه</option><option value="1">تسویه شده</option><option value="0">تسویه نشده</option></select></label>
				<label>وضعیت پروژه<select id="cptt-acct-status"><option value="">همه</option><option value="completed">تکمیل شده</option><option value="in_progress">در حال انجام</option></select></label>
				<button type="button" class="button" id="cptt-acct-reset">پاک کردن</button>
			</div>

			<div class="cptt-acct-table-wrap">
				<table class="cptt-acct-table" id="cptt-acct-table">
					<thead>
						<tr>
							<th style="width:28%">پروژه</th>
							<th>مشتری</th>
							<th>پیشرفت</th>
							<th>وضعیت</th>
							<th style="text-align:left">کل هزینه</th>
							<th style="text-align:left">دریافتی</th>
							<th style="text-align:left">مانده</th>
							<th>روند</th>
							<th style="width:110px">عملیات</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($rows as $r):
						$p=$r['post']; $f=$r['fin']; $prog=$r['progress'];
						$barColor=$f['percent']>=100?'#22c55e':($f['percent']>=50?'#f59e0b':'#ef4444');
						$search=strtolower(get_the_title($p).' '.$r['client']);
					?>
						<tr class="cptt-acct-row"
							data-search="<?php echo esc_attr($search); ?>"
							data-client="<?php echo esc_attr($r['client_id']); ?>"
							data-settled="<?php echo esc_attr($r['settled']); ?>"
							data-status="<?php echo esc_attr($prog['status']); ?>">
							<td>
								<a class="cptt-acct-title" href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>"><?php echo esc_html(get_the_title($p)); ?></a>
								<div class="cptt-acct-meta"><?php echo esc_html($r['experts']); ?></div>
							</td>
							<td><?php echo esc_html($r['client']); ?></td>
							<td>
								<div class="cptt-acct-minibar"><span style="width:<?php echo (int)$prog['percent']; ?>%;"></span></div>
								<small><?php echo (int)$prog['percent']; ?>%</small>
							</td>
							<td><?php echo $r['settled']?'<span class="cptt-chip cptt-chip--completed">تسویه</span>':'<span class="cptt-chip cptt-chip--in_progress">تسویه نشده</span>'; ?></td>
							<td style="text-align:left; font-weight:800;"><?php echo number_format($f['cost']); ?></td>
							<td style="text-align:left; color:#15803d; font-weight:800;"><?php echo number_format($f['paid']); ?></td>
							<td style="text-align:left; color:<?php echo $f['remain']>0?'#b91c1c':'#15803d'; ?>; font-weight:900;"><?php echo number_format($f['remain']); ?></td>
							<td>
								<div class="cptt-acct-bar-wrap">
									<div class="cptt-acct-bar" style="--bar-color:<?php echo esc_attr($barColor); ?>"><span style="width:<?php echo min(100,(float)$f['percent']); ?>%;"></span></div>
									<small><?php echo (float)$f['percent']; ?>%</small>
								</div>
							</td>
							<td>
								<a class="button" href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>" style="padding:4px 10px; font-size:12px;">ویرایش</a>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if (empty($rows)): ?>
					<tr><td colspan="9" style="text-align:center; padding:28px;">پروژه‌ای یافت نشد.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="cptt-acct-empty" id="cptt-acct-empty" style="display:none;">
				<div>هیچ پروژه‌ای با این فیلترها پیدا نشد.</div>
			</div>
		</div>
		<?php
	}
	public function ajax_quick_pay() {
		if (!current_user_can('edit_cptt_projects')) wp_send_json_error('no_access',403);
		check_ajax_referer('cptt_admin_nonce','nonce');
		$project_id=isset($_POST['project_id'])?absint($_POST['project_id']):0;
		$amount=isset($_POST['amount'])?(float)$_POST['amount']:0;
		if (!$project_id||$amount<=0) wp_send_json_error('invalid',400);
		if (get_post_type($project_id)!=='cptt_project') wp_send_json_error('invalid_project',400);
		$steps=get_post_meta($project_id,'_cptt_steps',true);
		if (!is_array($steps)) $steps=[];
		$added=false;
		foreach ($steps as &$s) {
			$cost=(float)($s['cost']??0); $paid=(float)($s['paid']??0);
			if ($cost>0&&$paid<$cost) {
				$need=$cost-$paid;
				$pay=min($amount,$need);
				$s['paid']=$paid+$pay;
				$amount-=$pay;
				$added=true;
				if ($amount<=0) break;
			}
		}
		unset($s);
		if ($added) {
			update_post_meta($project_id,'_cptt_steps',$steps);
			$fin=$this->project_financial_data($project_id);
			wp_send_json_success(['fin'=>$fin]);
		}
		wp_send_json_error('no_applicable_step',400);
	}
	public function render_details_metabox($post) {
		wp_nonce_field('cptt_save_project','cptt_nonce');
		$client_id=(int)get_post_meta($post->ID,'_cptt_client_user_id',true);
		$product_id=(int)get_post_meta($post->ID,'_cptt_product_id',true);
		if (!$product_id) $product_id=(int)get_post_meta($post->ID,'_cptt_wc_product_id',true);
		$is_settled=(int)get_post_meta($post->ID,'_cptt_is_settled',true);
		$expert_ids=get_post_meta($post->ID,'_cptt_expert_user_ids',true);
		if (!is_array($expert_ids)) $expert_ids=[];
		if (empty($expert_ids)) { $legacy=(int)get_post_meta($post->ID,'_cptt_expert_user_id',true); if ($legacy) $expert_ids=[$legacy]; }
		$expert_ids=array_values(array_filter(array_map('strval',$expert_ids)));
		$selectedSet=array_fill_keys($expert_ids,true);
		$users=get_users(['fields'=>['ID','display_name','user_email']]);
		$experts=get_users(['role__in'=>['cptt_expert','administrator'],'fields'=>['ID','display_name','user_email']]);
		$shown=[]; foreach ($experts as $u) $shown[(string)$u->ID]=true;
		foreach ($expert_ids as $sid) {
			if (!isset($shown[$sid])) {
				$u=get_user_by('id',(int)$sid);
				if ($u) { $experts[]=(object)['ID'=>$u->ID,'display_name'=>$u->display_name,'user_email'=>$u->user_email]; $shown[$sid]=true; }
			}
		}
		usort($experts,function($a,$b){return strcmp($a->display_name,$b->display_name);});
		$deadline_at=(int)get_post_meta($post->ID,'_cptt_deadline_at',true);
		$deadline_local=$deadline_at?$this->datetime_local_value($deadline_at):'';
		$wc_cats=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
		if (is_wp_error($wc_cats)) $wc_cats=[];
		$selected_cats=get_post_meta($post->ID,'_cptt_wc_cat_ids',true);
		if (!is_array($selected_cats)) $selected_cats=[];
		$selected_cats=array_map('intval',$selected_cats);
		$wc_order_id=(int)get_post_meta($post->ID,'_cptt_wc_order_id',true);
		$auto_settled=false;
		if ($wc_order_id && function_exists('wc_get_order')) {
			$order=wc_get_order($wc_order_id);
			if ($order && $order->is_paid()) { $auto_settled=true; $is_settled=1; }
		}
		?>
		<div class="cptt-admin-box">
			<p class="cptt-row">
				<label class="cptt-label" for="cptt_client_user_id">مشتری</label>
				<select id="cptt_client_user_id" name="cptt_client_user_id" class="cptt-select">
					<option value="">— انتخاب کنید —</option>
					<?php foreach ($users as $u): ?><option value="<?php echo esc_attr($u->ID); ?>" <?php selected($client_id,$u->ID); ?>><?php echo esc_html($u->display_name.' ('.$u->user_email.')'); ?></option><?php endforeach; ?>
				</select>
				<span class="cptt-help">این کاربر در فرانت «پروژه‌های من» را می‌بیند.</span>
			</p>
			<p class="cptt-row">
				<label class="cptt-label" for="cptt_wc_cats">دسته‌بندی (ووکامرس)</label>
				<select id="cptt_wc_cats" name="cptt_wc_cats[]" multiple class="cptt-select" style="height:auto;min-height:90px;">
					<?php foreach ($wc_cats as $cat): ?><option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected(in_array((int)$cat->term_id,$selected_cats,true)); ?>><?php echo esc_html($cat->name); ?></option><?php endforeach; ?>
				</select>
				<span class="cptt-help">دسته‌بندی ووکامرس به‌عنوان برچسب پروژه استفاده می‌شود. انتخاب دسته‌بندی، لیست محصولات را محدود می‌کند.</span>
			</p>
			<p class="cptt-row">
				<label class="cptt-label" for="cptt_product_id">محصول مرتبط</label>
				<span><?php echo $this->product_select_html('cptt_product_id',$product_id); ?><span class="cptt-help">اگر پروژه از سفارش آنلاین ساخته شده باشد، محصول به صورت خودکار انتخاب می‌شود.</span></span>
			</p>
			<p class="cptt-row">
				<label class="cptt-label">وضعیت تسویه</label>
				<?php if ($auto_settled): ?>
				<label style="display:inline-flex;align-items:center;gap:8px;color:#065f46;"><input type="checkbox" checked disabled /> تسویه شده (سفارش آنلاین)<input type="hidden" name="cptt_is_settled" value="1" /></label>
				<?php else: ?>
				<label style="display:inline-flex;align-items:center;gap:8px;"><input type="checkbox" id="cptt_is_settled" name="cptt_is_settled" value="1" <?php checked($is_settled,1); ?> /> تسویه شده</label>
				<?php endif; ?>
			</p>
			<input type="hidden" name="cptt_experts_present" value="1" />
			<div class="cptt-row cptt-row--experts">
				<div class="cptt-label">کارشناسان</div>
				<div class="cptt-expertsList">
					<?php foreach ($experts as $u):
						$checked=isset($selectedSet[(string)$u->ID]);
					?><label class="cptt-expertOpt <?php echo $checked?'is-checked':''; ?>"><input type="checkbox" name="cptt_expert_user_ids[]" value="<?php echo esc_attr($u->ID); ?>" <?php checked($checked); ?> /><span class="cptt-expertText"><span class="cptt-expertName"><?php echo esc_html($u->display_name); ?></span><small class="cptt-expertEmail"><?php echo esc_html($u->user_email); ?></small></span></label><?php endforeach; ?>
				</div>
				<span class="cptt-help">برای انتخاب چند کارشناس تیک بزنید.</span>
			</div>
			<p class="cptt-row">
				<label class="cptt-label" for="cptt_deadline_local">مهلت پروژه</label>
				<span><input type="text" class="cptt-jalali-datetime" id="cptt_deadline_local" name="cptt_deadline_local" value="<?php echo esc_attr($deadline_local); ?>" placeholder="۱۴۰۳/۰۱/۳۱ ۱۴:۳۰" /><span class="cptt-help">اگر پروژه تا این زمان تکمیل نشود، پیامک هشدار برای مسئول پروژه ارسال می‌شود.</span></span>
			</p>
		</div>
		<?php
	}
	public function render_notes_metabox($post) {
		$notes=get_post_meta($post->ID,'_cptt_project_notes',true);
		if (!is_array($notes)) $notes=[];
		?>
		<div class="cptt-notes">
			<div class="cptt-notes__list">
				<?php if (empty($notes)): ?><div class="cptt-notes__empty">هنوز یادداشتی ثبت نشده.</div><?php endif; ?>
				<?php foreach ($notes as $note):
					$u=get_user_by('id',(int)($note['user_id']??0));
					$name=$u?$u->display_name:'کارشناس';
					$time=!empty($note['time'])?CPTT_Core::jalali_datetime((int)$note['time']):'';
				?>
				<div class="cptt-note">
					<div class="cptt-note__head"><strong><?php echo esc_html($name); ?></strong><span><?php echo esc_html($time); ?></span></div>
					<div class="cptt-note__body"><?php echo nl2br(esc_html($note['content']??'')); ?></div>
				</div>
				<?php endforeach; ?>
			</div>
			<div class="cptt-notes__add">
				<label class="cptt-label">یادداشت جدید</label>
				<textarea name="cptt_new_note" rows="3" placeholder="متن یادداشت داخلی برای کارشناسان..."></textarea>
			</div>
		</div>
		<?php
	}
	public function render_accounting_metabox($post) {
		$fin=$this->project_financial_data($post->ID);
		$steps=get_post_meta($post->ID,'_cptt_steps',true);
		if (!is_array($steps)) $steps=[];
		?>
		<div class="cptt-acct-box">
			<div class="cptt-acct-grid cptt-acct-grid--3">
				<div class="cptt-acct-card"><div class="cptt-acct-label">کل هزینه</div><div class="cptt-acct-value"><?php echo number_format($fin['cost']); ?></div></div>
				<div class="cptt-acct-card"><div class="cptt-acct-label">کل دریافتی</div><div class="cptt-acct-value" style="color:#15803d;"><?php echo number_format($fin['paid']); ?></div></div>
				<div class="cptt-acct-card <?php echo $fin['remain']>0?'is-remain':'is-done'; ?>"><div class="cptt-acct-label">مانده</div><div class="cptt-acct-value"><?php echo number_format($fin['remain']); ?></div></div>
			</div>
			<?php if ($fin['cost']>0): ?>
			<div class="cptt-acct-bar-wrap" style="margin-top:10px;">
				<div class="cptt-acct-bar" style="--bar-color:<?php echo $fin['percent']>=100?'#22c55e':($fin['percent']>=50?'#f59e0b':'#ef4444'); ?>"><span style="width:<?php echo min(100,(float)$fin['percent']); ?>%;"></span></div>
				<small><?php echo (float)$fin['percent']; ?>% تسویه</small>
			</div>
			<?php endif; ?>
			<?php if (!empty($steps)): ?>
			<div class="cptt-acct-steps" style="margin-top:12px;">
				<div class="cptt-acct-label" style="margin-bottom:8px;">جزئیات مراحل</div>
				<?php foreach ($steps as $i=>$s):
					$sc=(float)($s['cost']??0); $sp=(float)($s['paid']??0); $sr=$sc-$sp;
				?>
				<div class="cptt-acct-step-mini">
					<div class="cptt-acct-step-mini__title"><?php echo esc_html(($i+1).'. '.($s['title']??'بدون عنوان')); ?></div>
					<div class="cptt-acct-step-mini__nums">
						<span>هزینه: <?php echo number_format($sc); ?></span>
						<span>دریافتی: <?php echo number_format($sp); ?></span>
						<span class="<?php echo $sr>0?'is-remain':'is-done'; ?>">مانده: <?php echo number_format($sr); ?></span>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<?php if ($fin['remain']>0): ?><p class="cptt-help">این پروژه تسویه نشده است.</p><?php else: ?><p class="cptt-help" style="color:#15803d;">وضعیت مالی این پروژه تسویه شده است.</p><?php endif; ?>
		</div>
		<?php
	}
	public function render_steps_metabox_project($post) {
		$steps=get_post_meta($post->ID,'_cptt_steps',true);
		if (!is_array($steps)) $steps=[];
		$templates=get_posts(['post_type'=>'cptt_template','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
		$check_tpls=get_posts(['post_type'=>'cptt_checklist_tpl','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
		$this->render_steps_editor($post,$steps,false,$templates,$check_tpls);
	}
	public function render_steps_metabox_template($post) {
		wp_nonce_field('cptt_save_template','cptt_template_nonce');
		$steps=get_post_meta($post->ID,'_cptt_template_steps',true);
		if (!is_array($steps)) $steps=[];
		$check_tpls=get_posts(['post_type'=>'cptt_checklist_tpl','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
		$this->render_steps_editor($post,$steps,true,[],$check_tpls);
	}
	private function render_steps_editor($post,$steps,$is_template,$templates,$check_tpls) {
		?>
		<div class="cptt-admin-steps" data-is-template="<?php echo $is_template?'1':'0'; ?>">
			<?php if (!$is_template): ?>
			<div class="cptt-toolbar">
				<div class="cptt-toolbar__left">
					<label for="cptt_template_select"><strong>بارگذاری تمپلیت مراحل:</strong></label>
					<select id="cptt_template_select"><option value="">— انتخاب تمپلیت —</option><?php foreach ($templates as $t): ?><option value="<?php echo esc_attr($t->ID); ?>"><?php echo esc_html(get_the_title($t)); ?></option><?php endforeach; ?></select>
					<button type="button" class="button" id="cptt_apply_template_btn">اعمال تمپلیت</button>
					<span class="cptt-help">اعمال تمپلیت، مراحل فعلی را جایگزین می‌کند.</span>
				</div>
			</div>
			<?php endif; ?>
			<div id="cptt-steps-rows">
				<?php foreach ($steps as $i=>$step):
					$step_id=isset($step['id'])?(string)$step['id']:(function_exists('wp_generate_uuid4')?wp_generate_uuid4():('st_'.wp_rand(1000,9999)));
					$title=isset($step['title'])?$step['title']:'';
					$status=isset($step['status'])?$step['status']:'todo';
					$desc=isset($step['desc'])?$step['desc']:'';
					$due_local=!empty($step['due_at'])?$this->datetime_local_value((int)$step['due_at']):'';
					$updated=isset($step['updated_at_fa'])?(string)$step['updated_at_fa']:'—';
					$updated_by_name='—';
					if (!empty($step['updated_by'])) { $u=get_user_by('id',(int)$step['updated_by']); if($u) $updated_by_name=$u->display_name; }
					$checklist=isset($step['checklist'])&&is_array($step['checklist'])?$step['checklist']:[];
					$user_tasks=isset($step['user_tasks'])&&is_array($step['user_tasks'])?$step['user_tasks']:[];
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
									<option value="todo" <?php selected($status,'todo'); ?>>انجام‌نشده</option>
									<option value="current" <?php selected($status,'current'); ?>>در حال انجام</option>
									<option value="done" <?php selected($status,'done'); ?>>انجام‌شده</option>
								</select>
							</div>
							<?php if (!$is_template): ?>
							<div class="cptt-stepCard__updated">
								<label class="cptt-fieldLabel">آخرین تغییر</label>
								<div class="cptt-updBox"><div><?php echo esc_html($updated); ?></div><small>توسط: <?php echo esc_html($updated_by_name); ?></small></div>
							</div>
							<?php endif; ?>
							<div class="cptt-stepCard__delete"><label class="cptt-fieldLabel">&nbsp;</label><button type="button" class="button cptt-remove-step">×</button></div>
						</div>
						<div class="cptt-stepCard__body">
							<div class="cptt-stepCard__desc">
								<label class="cptt-fieldLabel">توضیحات پاپ‌آپ</label>
								<textarea name="cptt_steps[<?php echo esc_attr($i); ?>][desc]" rows="3" placeholder="توضیحات..."><?php echo esc_textarea($desc); ?></textarea>
								<label class="cptt-fieldLabel" style="margin-top:10px;">مهلت مرحله</label>
								<input type="text" class="cptt-jalali-datetime" name="cptt_steps[<?php echo esc_attr($i); ?>][due_at_local]" value="<?php echo esc_attr($due_local); ?>" placeholder="۱۴۰۳/۰۱/۳۱ ۱۴:۳۰" />
								<span class="cptt-help">تاریخ و ساعت شمسی به زمان تهران.</span>
							</div>
							<div class="cptt-stepCard__checklist">
								<div class="cptt-checklist-head">
									<div class="cptt-checklist-title">چک‌لیست (متن + لینک نتیجه)</div>
									<div class="cptt-checklist-toolbar">
										<select class="cptt-checktpl-select"><option value="">— تمپلیت چک‌لیست —</option><?php foreach ($check_tpls as $ct): ?><option value="<?php echo esc_attr($ct->ID); ?>"><?php echo esc_html(get_the_title($ct)); ?></option><?php endforeach; ?></select>
										<button type="button" class="button cptt-apply-checktpl">اعمال</button>
										<button type="button" class="button button-primary cptt-add-checkitem">+ آیتم</button>
									</div>
								</div>
								<div class="cptt-checkitems" data-step-index="<?php echo esc_attr($i); ?>">
									<?php foreach ($checklist as $j=>$it):
										$cid=isset($it['id'])?(string)$it['id']:(function_exists('wp_generate_uuid4')?wp_generate_uuid4():('chk_'.wp_rand(1000,9999)));
										$text=isset($it['text'])?$it['text']:'';
										$url=isset($it['url'])?$it['url']:'';
										$done=!empty($it['done'])?1:0;
										$done_at_fa=!empty($it['done_at_fa'])?(string)$it['done_at_fa']:'';
										$done_by_name='';
										if (!empty($it['done_by'])) { $uu=get_user_by('id',(int)$it['done_by']); if($uu)$done_by_name=$uu->display_name; }
									?>
										<div class="cptt-checkitem-row" data-check-id="<?php echo esc_attr($cid); ?>">
											<span class="cptt-checkitem-handle" title="جابجایی">⋮</span>
											<input type="hidden" name="cptt_steps[<?php echo esc_attr($i); ?>][checklist][<?php echo esc_attr($j); ?>][id]" value="<?php echo esc_attr($cid); ?>" />
											<label class="cptt-checkitem-done"><input type="checkbox" name="cptt_steps[<?php echo esc_attr($i); ?>][checklist][<?php echo esc_attr($j); ?>][done]" value="1" <?php checked($done,1); ?> /> انجام شد<?php if ($done_at_fa): ?><small><?php echo esc_html($done_at_fa); ?><?php if ($done_by_name): ?><?php echo esc_html(' — توسط: '.$done_by_name); ?><?php endif; ?></small><?php endif; ?></label>
											<input type="text" name="cptt_steps[<?php echo esc_attr($i); ?>][checklist][<?php echo esc_attr($j); ?>][text]" value="<?php echo esc_attr($text); ?>" placeholder="متن آیتم..." />
											<input type="url" name="cptt_steps[<?php echo esc_attr($i); ?>][checklist][<?php echo esc_attr($j); ?>][url]" value="<?php echo esc_attr($url); ?>" placeholder="لینک نتیجه (اختیاری)..." />
											<button type="button" class="button cptt-remove-checkitem">×</button>
										</div>
									<?php endforeach; ?>
								</div>
								<p class="cptt-help" style="margin-top:8px;">اگر همه آیتم‌های چک‌لیست تیک بخورند، مرحله خودکار «انجام‌شده» می‌شود.</p>
							</div>
							<?php $this->render_user_tasks_editor($i,$user_tasks); ?>
							<?php if (!$is_template): ?>
							<div class="cptt-stepCard__billing">
								<div class="cptt-fieldLabel">حساب و کتاب مرحله</div>
								<div class="cptt-billing-row">
									<label>هزینه (تومان) <input type="number" name="cptt_steps[<?php echo esc_attr($i); ?>][cost]" value="<?php echo esc_attr($step['cost']??0); ?>" class="cptt-step-cost" step="any" /></label>
									<label>دریافتی (تومان) <input type="number" name="cptt_steps[<?php echo esc_attr($i); ?>][paid]" value="<?php echo esc_attr($step['paid']??0); ?>" class="cptt-step-paid" step="any" /></label>
									<span class="cptt-step-remain">مانده: <?php echo number_format(floatval($step['cost']??0)-floatval($step['paid']??0)); ?></span>
								</div>
							</div>
							<?php endif; ?>
						</div>
					</div>
				</div>
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
								<select name="cptt_steps[{{i}}][status]"><option value="todo">انجام‌نشده</option><option value="current">در حال انجام</option><option value="done">انجام‌شده</option></select>
							</div>
							<?php if (!$is_template): ?>
							<div class="cptt-stepCard__updated"><label class="cptt-fieldLabel">آخرین تغییر</label><div class="cptt-updBox"><div>—</div><small>توسط: —</small></div></div>
							<?php endif; ?>
							<div class="cptt-stepCard__delete"><label class="cptt-fieldLabel">&nbsp;</label><button type="button" class="button cptt-remove-step">×</button></div>
						</div>
						<div class="cptt-stepCard__body">
							<div class="cptt-stepCard__desc">
								<label class="cptt-fieldLabel">توضیحات پاپ‌آپ</label>
								<textarea name="cptt_steps[{{i}}][desc]" rows="3" placeholder="توضیحات..."></textarea>
								<label class="cptt-fieldLabel" style="margin-top:10px;">مهلت مرحله</label>
								<input type="text" class="cptt-jalali-datetime" name="cptt_steps[{{i}}][due_at_local]" value="" placeholder="۱۴۰۳/۰۱/۳۱ ۱۴:۳۰" />
								<span class="cptt-help">تاریخ و ساعت شمسی به زمان تهران.</span>
							</div>
							<div class="cptt-stepCard__checklist">
								<div class="cptt-checklist-head">
									<div class="cptt-checklist-title">چک‌لیست (متن + لینک نتیجه)</div>
									<div class="cptt-checklist-toolbar">
										<select class="cptt-checktpl-select"><option value="">— تمپلیت چک‌لیست —</option><?php foreach ($check_tpls as $ct): ?><option value="<?php echo esc_attr($ct->ID); ?>"><?php echo esc_html(get_the_title($ct)); ?></option><?php endforeach; ?></select>
										<button type="button" class="button cptt-apply-checktpl">اعمال</button>
										<button type="button" class="button button-primary cptt-add-checkitem">+ آیتم</button>
									</div>
								</div>
								<div class="cptt-checkitems" data-step-index="{{i}}"></div>
								<p class="cptt-help" style="margin-top:8px;">اگر همه آیتم‌های چک‌لیست تیک بخورند، مرحله خودکار «انجام‌شده» می‌شود.</p>
							</div>
							<div class="cptt-stepCard__userTasks">
								<div class="cptt-userTasks-head">
									<div class="cptt-userTasks-title">تسک‌های سمت مشتری</div>
									<button type="button" class="button button-primary cptt-add-usertask">+ تسک مشتری</button>
								</div>
								<div class="cptt-usertasks" data-step-index="{{i}}"></div>
								<p class="cptt-help">برای دریافت اطلاعات از مشتری، تسک تعریف کنید. مشتری از پنل خود پاسخ را ثبت می‌کند.</p>
							</div>
							<?php if (!$is_template): ?>
							<div class="cptt-stepCard__billing">
								<div class="cptt-fieldLabel">حساب و کتاب مرحله</div>
								<div class="cptt-billing-row">
									<label>هزینه (تومان) <input type="number" name="cptt_steps[{{i}}][cost]" value="0" class="cptt-step-cost" step="any" /></label>
									<label>دریافتی (تومان) <input type="number" name="cptt_steps[{{i}}][paid]" value="0" class="cptt-step-paid" step="any" /></label>
									<span class="cptt-step-remain">مانده: ۰</span>
								</div>
							</div>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</script>
			<script type="text/template" id="cptt-checkitem-template">
				<div class="cptt-checkitem-row" data-check-id="{{cid}}">
					<span class="cptt-checkitem-handle" title="جابجایی">⋮</span>
					<input type="hidden" name="cptt_steps[{{i}}][checklist][{{j}}][id]" value="{{cid}}" />
					<label class="cptt-checkitem-done"><input type="checkbox" name="cptt_steps[{{i}}][checklist][{{j}}][done]" value="1" /> انجام شد</label>
					<input type="text" name="cptt_steps[{{i}}][checklist][{{j}}][text]" value="" placeholder="متن آیتم..." />
					<input type="url" name="cptt_steps[{{i}}][checklist][{{j}}][url]" value="" placeholder="لینک نتیجه (اختیاری)..." />
					<button type="button" class="button cptt-remove-checkitem">×</button>
				</div>
			</script>
			<script type="text/template" id="cptt-usertask-template">
				<div class="cptt-usertask-row" data-task-id="{{tid}}">
					<input type="hidden" name="cptt_steps[{{i}}][user_tasks][{{k}}][id]" value="{{tid}}" />
					<input type="text" name="cptt_steps[{{i}}][user_tasks][{{k}}][title]" value="" placeholder="عنوان تسک برای مشتری..." />
					<textarea name="cptt_steps[{{i}}][user_tasks][{{k}}][desc]" rows="2" placeholder="توضیح یا اطلاعات موردنیاز..."></textarea>
					<label class="cptt-usertask-date">مهلت: <input type="text" class="cptt-jalali-datetime" name="cptt_steps[{{i}}][user_tasks][{{k}}][due_at_local]" value="" placeholder="۱۴۰۳/۰۱/۳۱ ۱۴:۳۰" /></label>
					<label class="cptt-usertask-remind"><input type="checkbox" name="cptt_steps[{{i}}][user_tasks][{{k}}][sms_remind]" value="1" checked /> یادآوری پیامکی</label>
					<button type="button" class="button cptt-remove-usertask">×</button>
				</div>
			</script>
		</div>
		<?php
	}
	private function render_user_tasks_editor($step_index,$tasks) {
		if (!is_array($tasks)) $tasks=[];
		?>
		<div class="cptt-stepCard__userTasks">
			<div class="cptt-userTasks-head"><div class="cptt-userTasks-title">تسک‌های سمت مشتری</div><button type="button" class="button button-primary cptt-add-usertask">+ تسک مشتری</button></div>
			<div class="cptt-usertasks" data-step-index="<?php echo esc_attr($step_index); ?>">
				<?php foreach ($tasks as $k=>$task):
					$tid=isset($task['id'])?(string)$task['id']:(function_exists('wp_generate_uuid4')?wp_generate_uuid4():('ut_'.wp_rand(1000,9999)));
					$title=isset($task['title'])?(string)$task['title']:'';
					$desc=isset($task['desc'])?(string)$task['desc']:'';
					$due_local=!empty($task['due_at'])?$this->datetime_local_value((int)$task['due_at']):'';
					$sms_remind=!isset($task['sms_remind'])||!empty($task['sms_remind']);
					$done=!empty($task['done']);
					$response=isset($task['response'])?(string)$task['response']:'';
					$response_url=isset($task['response_url'])?(string)$task['response_url']:'';
					$completed_at_fa=isset($task['completed_at_fa'])?(string)$task['completed_at_fa']:'';
				?>
				<div class="cptt-usertask-row <?php echo $done?'is-done':''; ?>" data-task-id="<?php echo esc_attr($tid); ?>">
					<input type="hidden" name="cptt_steps[<?php echo esc_attr($step_index); ?>][user_tasks][<?php echo esc_attr($k); ?>][id]" value="<?php echo esc_attr($tid); ?>" />
					<input type="text" name="cptt_steps[<?php echo esc_attr($step_index); ?>][user_tasks][<?php echo esc_attr($k); ?>][title]" value="<?php echo esc_attr($title); ?>" placeholder="عنوان تسک برای مشتری..." />
					<textarea name="cptt_steps[<?php echo esc_attr($step_index); ?>][user_tasks][<?php echo esc_attr($k); ?>][desc]" rows="2" placeholder="توضیح یا اطلاعات موردنیاز..."><?php echo esc_textarea($desc); ?></textarea>
					<label class="cptt-usertask-date">مهلت: <input type="text" class="cptt-jalali-datetime" name="cptt_steps[<?php echo esc_attr($step_index); ?>][user_tasks][<?php echo esc_attr($k); ?>][due_at_local]" value="<?php echo esc_attr($due_local); ?>" placeholder="۱۴۰۳/۰۱/۳۱ ۱۴:۳۰" /></label>
					<label class="cptt-usertask-remind"><input type="checkbox" name="cptt_steps[<?php echo esc_attr($step_index); ?>][user_tasks][<?php echo esc_attr($k); ?>][sms_remind]" value="1" <?php checked($sms_remind); ?> /> یادآوری پیامکی</label>
					<?php if ($done): ?>
					<div class="cptt-usertask-response">
						<strong>تکمیل‌شده توسط مشتری</strong>
						<?php if ($completed_at_fa): ?><small><?php echo esc_html($completed_at_fa); ?></small><?php endif; ?>
						<?php if ($response): ?><div><?php echo nl2br(esc_html($response)); ?></div><?php endif; ?>
						<?php if ($response_url): ?><a href="<?php echo esc_url($response_url); ?>" target="_blank" rel="noopener noreferrer">لینک ارسالی مشتری</a><?php endif; ?>
						<?php if (!empty($task['response_file_url'])): ?><a href="<?php echo esc_url($task['response_file_url']); ?>" target="_blank" rel="noopener noreferrer">فایل ارسالی مشتری</a><?php endif; ?>
						<?php if (!empty($task['response_files'])&&is_array($task['response_files'])): foreach ($task['response_files'] as $rf): if (empty($rf['url'])) continue; ?><a href="<?php echo esc_url($rf['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(!empty($rf['name'])?('فایل: '.$rf['name']):'فایل ارسالی مشتری'); ?></a><?php endforeach; endif; ?>
					</div>
					<?php endif; ?>
					<button type="button" class="button cptt-remove-usertask">×</button>
				</div>
				<?php endforeach; ?>
			</div>
			<p class="cptt-help">برای دریافت اطلاعات از مشتری، تسک تعریف کنید. مشتری از پنل خود پاسخ را ثبت می‌کند.</p>
		</div>
		<?php
	}
	public function render_checklist_tpl_metabox($post) {
		wp_nonce_field('cptt_save_checktpl','cptt_checktpl_nonce');
		$items=get_post_meta($post->ID,'_cptt_checklist_items',true);
		if (!is_array($items)) $items=[];
		?>
		<div class="cptt-checktpl">
			<p class="cptt-help">این تمپلیت را می‌توانید داخل هر مرحله اعمال کنید.</p>
			<div id="cptt-checktpl-rows">
				<?php foreach ($items as $i=>$text): ?>
				<div class="cptt-checktpl-row"><input type="text" name="cptt_checktpl_items[<?php echo esc_attr($i); ?>]" value="<?php echo esc_attr($text); ?>" placeholder="متن آیتم..." /><button type="button" class="button cptt-remove-checktpl-row">×</button></div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button button-primary" id="cptt-add-checktpl-row">+ افزودن آیتم</button>
			<script type="text/template" id="cptt-checktpl-row-template"><div class="cptt-checktpl-row"><input type="text" name="cptt_checktpl_items[{{i}}]" value="" placeholder="متن آیتم..." /><button type="button" class="button cptt-remove-checktpl-row">×</button></div></script>
		</div>
		<?php
	}
	private function datetime_local_value($timestamp) {
		$timestamp=(int)$timestamp; if (!$timestamp) return '';
		return class_exists('CPTT_Core')?CPTT_Core::jalali_datetime($timestamp):date('Y/m/d H:i',$timestamp);
	}
	private function parse_datetime_local($value) {
		$value=trim((string)$value); if ($value==='') return 0;
		if (class_exists('CPTT_Core')&&method_exists('CPTT_Core','parse_jalali_datetime')) return (int)CPTT_Core::parse_jalali_datetime($value);
		return 0;
	}
	private function normalize_checklist($arr) {
		if (!is_array($arr)) return [];
		$out=[];
		foreach ($arr as $it) {
			$id=isset($it['id'])?sanitize_text_field($it['id']):'';
			if ($id==='') $id=function_exists('wp_generate_uuid4')?wp_generate_uuid4():('chk_'.wp_rand(1000,9999));
			$text=isset($it['text'])?sanitize_text_field($it['text']):'';
			$url=isset($it['url'])?esc_url_raw($it['url']):'';
			$done=!empty($it['done'])?1:0;
			if ($text==='') continue;
			$out[]=['id'=>$id,'text'=>$text,'url'=>$url,'done'=>$done];
		}
		return $out;
	}
	private function normalize_user_tasks($arr) {
		if (!is_array($arr)) return [];
		$out=[];
		foreach ($arr as $task) {
			if (!is_array($task)) continue;
			$id=isset($task['id'])?sanitize_text_field($task['id']):'';
			if ($id==='') $id=function_exists('wp_generate_uuid4')?wp_generate_uuid4():('ut_'.wp_rand(1000,9999));
			$title=isset($task['title'])?sanitize_text_field($task['title']):'';
			$desc=isset($task['desc'])?wp_kses_post($task['desc']):'';
			$due_at=isset($task['due_at_local'])?$this->parse_datetime_local($task['due_at_local']):0;
			$sms_remind=!empty($task['sms_remind'])?1:0;
			if ($title===''&&$desc==='') continue;
			$row=['id'=>$id,'title'=>$title,'desc'=>$desc,'due_at'=>$due_at,'sms_remind'=>$sms_remind,'done'=>0];
			if ($due_at&&class_exists('CPTT_Core')) $row['due_at_fa']=CPTT_Core::jalali_datetime($due_at);
			$out[]=$row;
		}
		return $out;
	}
	private function preserve_user_task_state($new_steps,$old_steps) {
		if (!is_array($old_steps)) $old_steps=[];
		$old_by_step=[];
		foreach ($old_steps as $os) if (is_array($os)&&!empty($os['id'])) $old_by_step[(string)$os['id']]=$os;
		foreach ($new_steps as &$step) {
			$sid=(string)($step['id']??'');
			$old_step=($sid&&isset($old_by_step[$sid]))?$old_by_step[$sid]:null;
			if (is_array($old_step)&&!empty($old_step['deadline_sms_sent'])&&!empty($old_step['due_at'])&&!empty($step['due_at'])&&(int)$old_step['due_at']===(int)$step['due_at']) $step['deadline_sms_sent']=$old_step['deadline_sms_sent'];
			$old_tasks=[];
			if (is_array($old_step)&&!empty($old_step['user_tasks'])&&is_array($old_step['user_tasks'])) { foreach ($old_step['user_tasks'] as $ot) { if (is_array($ot)&&!empty($ot['id'])) $old_tasks[(string)$ot['id']]=$ot; } }
			if (empty($step['user_tasks'])||!is_array($step['user_tasks'])) continue;
			foreach ($step['user_tasks'] as &$task) {
				$tid=(string)($task['id']??'');
				$old=($tid&&isset($old_tasks[$tid]))?$old_tasks[$tid]:null;
				if (!$old||!is_array($old)) continue;
				foreach (['done','response','response_url','response_file_url','response_file_name','response_file_type','response_files','completed_at','completed_at_fa','completed_by','last_reminder_at','last_reminder_at_fa'] as $key) { if (array_key_exists($key,$old)) $task[$key]=$old[$key]; }
			}
			unset($task);
		}
		unset($step);
		return $new_steps;
	}
	private function normalize_steps($steps) {
		if (!is_array($steps)) return [];
		$out=[]; $current_found=false;
		foreach ($steps as $s) {
			$id=isset($s['id'])?sanitize_text_field($s['id']):'';
			if ($id==='') $id=function_exists('wp_generate_uuid4')?wp_generate_uuid4():('st_'.wp_rand(1000,9999));
			$title=isset($s['title'])?sanitize_text_field($s['title']):'';
			$desc=isset($s['desc'])?wp_kses_post($s['desc']):'';
			$status=isset($s['status'])?sanitize_key($s['status']):'todo';
			if (!in_array($status,['todo','current','done'],true)) $status='todo';
			$due_at=isset($s['due_at_local'])?$this->parse_datetime_local($s['due_at_local']):0;
			$checklist=isset($s['checklist'])?$this->normalize_checklist($s['checklist']):[];
			$user_tasks=isset($s['user_tasks'])?$this->normalize_user_tasks($s['user_tasks']):[];
			$cost=isset($s['cost'])?(float)$s['cost']:0;
			$paid=isset($s['paid'])?(float)$s['paid']:0;
			if ($title===''&&$desc===''&&empty($checklist)&&empty($user_tasks)) continue;
			if ($status==='current') { if ($current_found) $status='todo'; $current_found=true; }
			$row=['id'=>$id,'title'=>$title,'desc'=>$desc,'status'=>$status,'checklist'=>$checklist,'user_tasks'=>$user_tasks,'cost'=>$cost,'paid'=>$paid];
			if ($due_at) { $row['due_at']=$due_at; $row['due_at_fa']=class_exists('CPTT_Core')?CPTT_Core::jalali_datetime($due_at):date('Y/m/d H:i',$due_at); }
			$out[]=$row;
		}
		if (!$current_found) { foreach ($out as $i=>$s) { if ($s['status']==='todo') { $out[$i]['status']='current'; break; } } }
		return $out;
	}
	private function apply_step_status_from_checklist($steps) {
		foreach ($steps as &$s) {
			$cl=isset($s['checklist'])&&is_array($s['checklist'])?$s['checklist']:[];
			$total=0; $done=0;
			foreach ($cl as $it) {
				$text=(string)($it['text']??''); if ($text==='') continue;
				$total++; if (!empty($it['done'])) $done++;
			}
			if ($total>0) { if ($done>=$total) $s['status']='done'; else if (($s['status']??'')==='done') $s['status']='current'; }
		}
		unset($s);
		return $steps;
	}
	private function apply_status_timestamps($new_steps,$old_steps) {
		$now=(int)current_time('timestamp',true); $uid=(int)get_current_user_id(); $any_status_changed=false;
		if (!is_array($old_steps)) $old_steps=[];
		$old_by_id=[];
		foreach ($old_steps as $os) if (is_array($os)&&!empty($os['id'])) $old_by_id[(string)$os['id']]=$os;
		foreach ($new_steps as &$s) {
			$oid=(string)$s['id']; $old=$old_by_id[$oid]??null; $old_status=(is_array($old)&&isset($old['status']))?(string)$old['status']:null;
			if ($old_status!==null&&$old_status===$s['status']) { if (isset($old['updated_at'])) $s['updated_at']=(int)$old['updated_at']; if (isset($old['updated_at_fa'])) $s['updated_at_fa']=(string)$old['updated_at_fa']; if (isset($old['updated_by'])) $s['updated_by']=(int)$old['updated_by']; continue; }
			$s['updated_at']=$now; $s['updated_at_fa']=CPTT_Core::jalali_datetime($now); $s['updated_by']=$uid;
			if ($old_status!==null&&$old_status!==$s['status']) $any_status_changed=true;
		}
		unset($s);
		return [$new_steps,$any_status_changed];
	}
	private function apply_checklist_done_timestamps($new_steps,$old_steps) {
		$now=(int)current_time('timestamp',true); $uid=(int)get_current_user_id();
		if (!is_array($old_steps)) $old_steps=[];
		$old_by_step=[];
		foreach ($old_steps as $os) if (is_array($os)&&!empty($os['id'])) $old_by_step[(string)$os['id']]=$os;
		foreach ($new_steps as &$st) {
			$sid=(string)($st['id']??''); $old=$old_by_step[$sid]??null;
			$old_items=[];
			if (is_array($old)&&!empty($old['checklist'])&&is_array($old['checklist'])) { foreach ($old['checklist'] as $oi) if (is_array($oi)&&!empty($oi['id'])) $old_items[(string)$oi['id']]=$oi; }
			if (empty($st['checklist'])||!is_array($st['checklist'])) continue;
			foreach ($st['checklist'] as &$it) {
				$cid=(string)($it['id']??''); $ndone=!empty($it['done'])?1:0;
				$oi=($cid&&isset($old_items[$cid]))?$old_items[$cid]:null; $odone=(!empty($oi)&&!empty($oi['done']))?1:0;
				if ($ndone===1) {
					if ($odone===1&&!empty($oi['done_at'])) { $it['done_at']=(int)$oi['done_at']; $it['done_at_fa']=!empty($oi['done_at_fa'])?(string)$oi['done_at_fa']:CPTT_Core::jalali_datetime((int)$oi['done_at']); if (!empty($oi['done_by'])) $it['done_by']=(int)$oi['done_by']; }
					else { $it['done_at']=$now; $it['done_at_fa']=CPTT_Core::jalali_datetime($now); $it['done_by']=$uid; }
				} else { unset($it['done_at'],$it['done_at_fa'],$it['done_by']); }
			}
			unset($it);
		}
		unset($st);
		return $new_steps;
	}
	private function checklist_changed($new_steps,$old_steps) {
		if (!is_array($old_steps)) $old_steps=[];
		$old_by_id=[]; foreach ($old_steps as $os) if (is_array($os)&&!empty($os['id'])) $old_by_id[(string)$os['id']]=$os;
		foreach ($new_steps as $ns) {
			$id=(string)($ns['id']??''); $old=$old_by_id[$id]??null;
			$ncl=isset($ns['checklist'])&&is_array($ns['checklist'])?$ns['checklist']:[];
			$ocl=(is_array($old)&&isset($old['checklist'])&&is_array($old['checklist']))?$old['checklist']:[];
			$omap=[];
			foreach ($ocl as $oi) { if (!is_array($oi)||empty($oi['id'])) continue; $omap[(string)$oi['id']]=['text'=>(string)($oi['text']??''),'url'=>(string)($oi['url']??''),'done'=>!empty($oi['done'])?1:0]; }
			foreach ($ncl as $ni) {
				if (!is_array($ni)||empty($ni['id'])) return true;
				$key=(string)$ni['id']; $nt=(string)($ni['text']??''); $nu=(string)($ni['url']??''); $nd=!empty($ni['done'])?1:0;
				if (!isset($omap[$key])) return true; if ($omap[$key]['text']!==$nt) return true; if ($omap[$key]['url']!==$nu) return true; if ((int)$omap[$key]['done']!==(int)$nd) return true; unset($omap[$key]);
			}
			if (!empty($omap)) return true;
		}
		return false;
	}
	public function save_project_meta($post_id,$post) {
		if (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE) return;
		if (!isset($_POST['cptt_nonce'])||!wp_verify_nonce($_POST['cptt_nonce'],'cptt_save_project')) return;
		if (!current_user_can('edit_post',$post_id)) return;
		$client_id=isset($_POST['cptt_client_user_id'])?absint($_POST['cptt_client_user_id']):0;
		update_post_meta($post_id,'_cptt_client_user_id',$client_id);
		$product_id=isset($_POST['cptt_product_id'])?absint($_POST['cptt_product_id']):0;
		update_post_meta($post_id,'_cptt_product_id',$product_id);
		update_post_meta($post_id,'_cptt_is_settled',!empty($_POST['cptt_is_settled'])?1:0);
		if (isset($_POST['cptt_experts_present'])) {
			$expert_ids=isset($_POST['cptt_expert_user_ids'])&&is_array($_POST['cptt_expert_user_ids'])?array_map('absint',$_POST['cptt_expert_user_ids']):[];
			$expert_ids=array_values(array_filter(array_unique($expert_ids)));
			update_post_meta($post_id,'_cptt_expert_user_ids',$expert_ids);
			update_post_meta($post_id,'_cptt_expert_user_id',!empty($expert_ids)?(int)$expert_ids[0]:0);
			update_post_meta($post_id,'_cptt_experts_csv',','.implode(',',$expert_ids).',');
		}
		$old_deadline=(int)get_post_meta($post_id,'_cptt_deadline_at',true);
		$deadline_at=isset($_POST['cptt_deadline_local'])?$this->parse_datetime_local($_POST['cptt_deadline_local']):0;
		if ($deadline_at) { update_post_meta($post_id,'_cptt_deadline_at',$deadline_at); update_post_meta($post_id,'_cptt_deadline_at_fa',CPTT_Core::jalali_datetime($deadline_at)); }
		else { delete_post_meta($post_id,'_cptt_deadline_at'); delete_post_meta($post_id,'_cptt_deadline_at_fa'); }
		if ($old_deadline!==$deadline_at) delete_post_meta($post_id,'_cptt_deadline_sms_sent');
		if (isset($_POST['cptt_wc_cats'])&&is_array($_POST['cptt_wc_cats'])) {
			$cat_ids=array_map('intval',$_POST['cptt_wc_cats']);
			$cat_ids=array_values(array_filter(array_unique($cat_ids)));
			update_post_meta($post_id,'_cptt_wc_cat_ids',$cat_ids);
			update_post_meta($post_id,'_cptt_wc_cats_csv',','.implode(',',$cat_ids).',');
		} else { delete_post_meta($post_id,'_cptt_wc_cat_ids'); delete_post_meta($post_id,'_cptt_wc_cats_csv'); }
		$new_note=isset($_POST['cptt_new_note'])?trim(sanitize_textarea_field($_POST['cptt_new_note'])):'';
		if ($new_note!=='') {
			$notes=get_post_meta($post_id,'_cptt_project_notes',true);
			if (!is_array($notes)) $notes=[];
			$notes[]=['user_id'=>get_current_user_id(),'time'=>current_time('timestamp',true),'content'=>$new_note];
			update_post_meta($post_id,'_cptt_project_notes',$notes);
		}
		$old_steps=get_post_meta($post_id,'_cptt_steps',true);
		$steps=isset($_POST['cptt_steps'])&&is_array($_POST['cptt_steps'])?$_POST['cptt_steps']:[];
		$steps=$this->normalize_steps($steps);
		$steps=$this->preserve_user_task_state($steps,$old_steps);
		$steps=$this->apply_checklist_done_timestamps($steps,$old_steps);
		$steps=$this->apply_step_status_from_checklist($steps);
		[$steps,$any_status_changed]=$this->apply_status_timestamps($steps,$old_steps);
		$any_check_changed=$this->checklist_changed($steps,$old_steps);
		update_post_meta($post_id,'_cptt_steps',$steps);
		if ($any_status_changed||$any_check_changed) {
			$now=(int)current_time('timestamp',true);
			update_post_meta($post_id,'_cptt_last_update',$now);
			update_post_meta($post_id,'_cptt_last_update_fa',CPTT_Core::jalali_datetime($now));
		}
		if (class_exists('CPTT_SMS')&&method_exists('CPTT_SMS','maybe_notify_project_completed')) CPTT_SMS::maybe_notify_project_completed($post_id);
	}
	public function save_template_meta($post_id,$post) {
		if (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE) return;
		if (!isset($_POST['cptt_template_nonce'])||!wp_verify_nonce($_POST['cptt_template_nonce'],'cptt_save_template')) return;
		if (!current_user_can('edit_post',$post_id)) return;
		$steps=isset($_POST['cptt_steps'])&&is_array($_POST['cptt_steps'])?$_POST['cptt_steps']:[];
		$steps=$this->normalize_steps($steps);
		foreach ($steps as &$s) {
			unset($s['updated_at'],$s['updated_at_fa'],$s['updated_by']);
			$s['status']='todo';
			if (isset($s['checklist'])&&is_array($s['checklist'])) { foreach ($s['checklist'] as &$ci) { $ci['done']=0; unset($ci['done_at'],$ci['done_at_fa'],$ci['done_by']); } unset($ci); }
			if (isset($s['user_tasks'])&&is_array($s['user_tasks'])) { foreach ($s['user_tasks'] as &$ut) { $ut['done']=0; unset($ut['response'],$ut['response_url'],$ut['response_file_url'],$ut['response_file_name'],$ut['response_file_type'],$ut['response_files'],$ut['completed_at'],$ut['completed_at_fa'],$ut['completed_by'],$ut['last_reminder_at'],$ut['last_reminder_at_fa']); } unset($ut); }
		}
		unset($s);
		update_post_meta($post_id,'_cptt_template_steps',$steps);
	}
	public function save_checklist_tpl_meta($post_id,$post) {
		if (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE) return;
		if (!isset($_POST['cptt_checktpl_nonce'])||!wp_verify_nonce($_POST['cptt_checktpl_nonce'],'cptt_save_checktpl')) return;
		if (!current_user_can('edit_post',$post_id)) return;
		$items=isset($_POST['cptt_checktpl_items'])&&is_array($_POST['cptt_checktpl_items'])?$_POST['cptt_checktpl_items']:[];
		$out=[];
		foreach ($items as $t) { $t=sanitize_text_field($t); if ($t!=='') $out[]=$t; }
		update_post_meta($post_id,'_cptt_checklist_items',$out);
	}
	public function ajax_get_template_steps() {
		if (!current_user_can('edit_cptt_projects')) wp_send_json_error('no_access',403);
		check_ajax_referer('cptt_admin_nonce','nonce');
		$id=isset($_GET['template_id'])?absint($_GET['template_id']):0;
		if (!$id||get_post_type($id)!=='cptt_template') wp_send_json_error('invalid',400);
		$steps=get_post_meta($id,'_cptt_template_steps',true);
		if (!is_array($steps)) $steps=[];
		foreach ($steps as &$s) {
			unset($s['updated_at'],$s['updated_at_fa'],$s['updated_by']);
			$s['status']='todo';
			$s['due_at_local']=!empty($s['due_at'])?$this->datetime_local_value((int)$s['due_at']):'';
			if (isset($s['checklist'])&&is_array($s['checklist'])) { foreach ($s['checklist'] as &$ci) { $ci['done']=0; unset($ci['done_at'],$ci['done_at_fa'],$ci['done_by']); } unset($ci); }
			if (isset($s['user_tasks'])&&is_array($s['user_tasks'])) { foreach ($s['user_tasks'] as &$ut) { $ut['done']=0; $ut['due_at_local']=!empty($ut['due_at'])?$this->datetime_local_value((int)$ut['due_at']):''; unset($ut['response'],$ut['response_url'],$ut['response_file_url'],$ut['response_file_name'],$ut['response_file_type'],$ut['response_files'],$ut['completed_at'],$ut['completed_at_fa'],$ut['completed_by'],$ut['last_reminder_at'],$ut['last_reminder_at_fa']); } unset($ut); }
		}
		unset($s);
		wp_send_json_success($steps);
	}
	public function ajax_get_checklist_tpl() {
		if (!current_user_can('edit_cptt_projects')) wp_send_json_error('no_access',403);
		check_ajax_referer('cptt_admin_nonce','nonce');
		$id=isset($_GET['checktpl_id'])?absint($_GET['checktpl_id']):0;
		if (!$id||get_post_type($id)!=='cptt_checklist_tpl') wp_send_json_error('invalid',400);
		$items=get_post_meta($id,'_cptt_checklist_items',true);
		if (!is_array($items)) $items=[];
		$out=[];
		foreach ($items as $t) { $t=sanitize_text_field($t); if ($t!=='') $out[]=$t; }
		wp_send_json_success($out);
	}
	public function columns($cols) {
		$new=[];
		$new['cb']=$cols['cb'];
		$new['title']='عنوان پروژه';
		$new['cptt_client']='مشتری';
		$new['cptt_expert']='کارشناسان';
		$new['cptt_cats']='دسته‌بندی';
		$new['cptt_product']='محصول';
		$new['cptt_settled']='تسویه';
		$new['cptt_progress']='پیشرفت';
		$new['cptt_last_update']='آخرین بروزرسانی';
		$new['date']=$cols['date'];
		return $new;
	}
	public function column_content($col,$post_id) {
		if ($col==='cptt_client') { $uid=(int)get_post_meta($post_id,'_cptt_client_user_id',true); $u=$uid?get_user_by('id',$uid):null; echo $u?esc_html($u->display_name):'—'; }
		if ($col==='cptt_expert') {
			$ids=get_post_meta($post_id,'_cptt_expert_user_ids',true);
			if (!is_array($ids)) $ids=[];
			if (empty($ids)) { $legacy=(int)get_post_meta($post_id,'_cptt_expert_user_id',true); if ($legacy) $ids=[$legacy]; }
			$names=[]; foreach ($ids as $id) { $u=get_user_by('id',(int)$id); if ($u) $names[]=$u->display_name; }
			echo $names?esc_html(implode('، ',$names)):'—';
		}
		if ($col==='cptt_cats') {
			$cat_ids=get_post_meta($post_id,'_cptt_wc_cat_ids',true);
			if (!is_array($cat_ids)||empty($cat_ids)) { echo '—'; return; }
			$names=[]; foreach ($cat_ids as $cid) { $term=get_term((int)$cid,'product_cat'); if ($term&&!is_wp_error($term)) $names[]=$term->name; }
			echo $names?esc_html(implode('، ',$names)):'—';
		}
		if ($col==='cptt_product') { $pid=(int)get_post_meta($post_id,'_cptt_product_id',true); if (!$pid) $pid=(int)get_post_meta($post_id,'_cptt_wc_product_id',true); echo $pid?esc_html($this->product_title($pid)):'—'; }
		if ($col==='cptt_settled') { echo get_post_meta($post_id,'_cptt_is_settled',true)?'تسویه شده':'—'; }
		if ($col==='cptt_progress') {
			$steps=get_post_meta($post_id,'_cptt_steps',true);
			if (!is_array($steps)||empty($steps)) { echo '—'; return; }
			$total=count($steps); $done=0; foreach ($steps as $s) if (($s['status']??'')==='done') $done++;
			$percent=$total?round(($done/$total)*100):0;
			echo esc_html($percent.'% ('.$done.'/'.$total.')');
		}
		if ($col==='cptt_last_update') { $fa=(string)get_post_meta($post_id,'_cptt_last_update_fa',true); echo $fa?esc_html($fa):'—'; }
	}
}
