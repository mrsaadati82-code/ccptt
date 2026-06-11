/**
 * CPTT Reminders — v6.3.0
 * Polls server every N seconds, pops on-screen reminder cards, optionally
 * fires browser notifications, persists per-user prefs & per-reminder state.
 */
(function(){
	'use strict';

	function ready(fn){
		if (document.readyState !== 'loading') fn();
		else document.addEventListener('DOMContentLoaded', fn);
	}
	function escHtml(s){
		return String(s||'').replace(/[&<>"']/g, function(c){
			return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
		});
	}

	var CFG = window.CPTT_REMINDERS || null;
	if (!CFG) return; // not on the dashboard or feature disabled

	var SESSION_SHOWN_KEY = 'cptt_rmd_session_shown_v1';
	function sessionShownGet(){
		try { return JSON.parse(sessionStorage.getItem(SESSION_SHOWN_KEY) || '{}'); }
		catch(e){ return {}; }
	}
	function sessionShownSet(o){
		try { sessionStorage.setItem(SESSION_SHOWN_KEY, JSON.stringify(o)); } catch(e){}
	}

	/* ─── small AJAX helper ─── */
	function ajax(action, data, cb){
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', CFG.nonce);
		for (var k in data) if (Object.prototype.hasOwnProperty.call(data, k)){
			var v = data[k];
			if (typeof v === 'object') v = JSON.stringify(v);
			fd.append(k, v);
		}
		var xhr = new XMLHttpRequest();
		xhr.open('POST', CFG.ajax);
		xhr.onload = function(){
			var r = null;
			try { r = JSON.parse(xhr.responseText); } catch(e){}
			if (cb) cb(r);
		};
		xhr.onerror = function(){ if (cb) cb(null); };
		xhr.send(fd);
	}

	/* ─── ensure stack container ─── */
	function getStack(){
		var s = document.getElementById('cptt-reminders-stack');
		if (!s){
			s = document.createElement('div');
			s.id = 'cptt-reminders-stack';
			document.body.appendChild(s);
		}
		return s;
	}

	/* ─── browser push (optional) ─── */
	var notifPermission = null;
	function maybeAskPermission(){
		if (!('Notification' in window)) return;
		if (Notification.permission === 'default' && CFG.prefs && CFG.prefs.browser_notif === '1'){
			try { Notification.requestPermission().then(function(p){ notifPermission = p; }); }
			catch(e){ try { Notification.requestPermission(function(p){ notifPermission = p; }); } catch(_){} }
		} else {
			notifPermission = Notification.permission;
		}
	}
	function sendBrowserNotif(item){
		if (!('Notification' in window)) return;
		if (Notification.permission !== 'granted') return;
		if (!CFG.prefs || CFG.prefs.browser_notif !== '1') return;
		try {
			var n = new Notification('⏰ یادآوری: ' + (item.title || ''), {
				body: (item.expired ? '⚠ ' : '') + (item.lead_label || '') + '\n' +
				      (item.type === 'step' ? 'پروژه: ' + (item.project_title || '') : ''),
				tag: 'cptt-rmd-' + item.key,
				icon: '/wp-content/plugins/client-project-tracker/assets/images/icon-192.png',
			});
			n.onclick = function(){ try { window.focus(); n.close(); } catch(e){} };
		} catch(e){}
	}

	/* ─── render a card ─── */
	function renderCard(item){
		var stack = getStack();
		// avoid duplicates
		if (stack.querySelector('[data-key="' + cssEsc(item.key) + '"]')) return;
		var card = document.createElement('div');
		card.className = 'cptt-rmd-card' + (item.expired ? ' cptt-rmd-card--expired' : '');
		card.setAttribute('data-key', item.key);
		var iconSvg = item.expired
			? '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>'
			: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
		var subTxt = item.type === 'step'
			? 'مرحله از پروژه «' + escHtml(item.project_title || '') + '»'
			: 'پروژه';
		var metaTxt = item.expired
			? '⚠ ' + escHtml(item.lead_label)
			: '⏳ ' + escHtml(item.lead_label) + (item.deadline_fa ? ' — <b>' + escHtml(item.deadline_fa) + '</b>' : '');

		card.innerHTML =
			'<div class="cptt-rmd-card__head">' +
				'<div class="cptt-rmd-card__icon">' + iconSvg + '</div>' +
				'<div class="cptt-rmd-card__body">' +
					'<span class="cptt-rmd-card__title">' + escHtml(item.title) + '</span>' +
					'<span class="cptt-rmd-card__sub">' + subTxt + '</span>' +
				'</div>' +
				'<button type="button" class="cptt-rmd-card__close" title="بستن (یکبار)" aria-label="بستن">×</button>' +
			'</div>' +
			'<div class="cptt-rmd-card__meta">' + metaTxt + '</div>' +
			'<div class="cptt-rmd-card__actions">' +
				'<button type="button" class="cptt-rmd-btn--open" data-pid="' + escHtml(item.project_id) + '">رفتن به پروژه</button>' +
				'<button type="button" class="cptt-rmd-btn--snooze">⏰ یادآوری بعداً</button>' +
				'<button type="button" class="cptt-rmd-btn--dismiss">دیگر نشان نده</button>' +
			'</div>';
		stack.appendChild(card);

		function remove(){
			card.classList.add('is-removing');
			setTimeout(function(){
				if (card.parentNode) card.parentNode.removeChild(card);
				updateBellBadge();
			}, 220);
		}
		card.querySelector('.cptt-rmd-card__close').addEventListener('click', function(){
			remove();
			// session-only: don't show again this session
			var s = sessionShownGet();
			s[item.key] = Date.now();
			sessionShownSet(s);
		});
		card.querySelector('.cptt-rmd-btn--snooze').addEventListener('click', function(){
			ajax('cptt_reminders_action', { key: item.key, op: 'snooze' });
			remove();
		});
		card.querySelector('.cptt-rmd-btn--dismiss').addEventListener('click', function(){
			if (!confirm('این یادآوری به‌طور دائم حذف شود و دیگر نمایش داده نشود؟')) return;
			ajax('cptt_reminders_action', { key: item.key, op: 'dismiss' });
			remove();
		});
		var openBtn = card.querySelector('.cptt-rmd-btn--open');
		if (openBtn){
			openBtn.addEventListener('click', function(){
				var pid = openBtn.getAttribute('data-pid');
				var target = document.querySelector('.cptt-expertCard[data-project-id="' + cssEsc(pid) + '"]');
				if (target){
					target.scrollIntoView({ behavior: 'smooth', block: 'start' });
					var toggle = target.querySelector('.cptt-expert-toggleProject');
					if (toggle && !target.classList.contains('is-expanded')) toggle.click();
				} else {
					// fallback: just close
				}
				remove();
			});
		}

		// Mark on the server as shown (for "once" / "interval" frequency)
		ajax('cptt_reminders_action', { key: item.key, op: 'shown' });
		// Browser notification
		sendBrowserNotif(item);
		// Bump the bell badge
		updateBellBadge();
	}

	function updateBellBadge(){
		var btn = document.getElementById('cptt-reminders-btn');
		if (!btn) return;
		var stack = document.getElementById('cptt-reminders-stack');
		var n = stack ? stack.querySelectorAll('.cptt-rmd-card').length : 0;
		var badge = btn.querySelector('.cptt-rmd-badge');
		if (n > 0){
			if (!badge){
				badge = document.createElement('span');
				badge.className = 'cptt-rmd-badge';
				btn.appendChild(badge);
			}
			badge.textContent = n > 9 ? '9+' : String(n);
		} else if (badge && badge.parentNode){
			badge.parentNode.removeChild(badge);
		}
	}
	function cssEsc(s){
		return String(s||'').replace(/"/g, '\\"');
	}

	/* ─── client-side gating for "every_login" frequency ─── */
	function clientShouldShow(item){
		if (!CFG.prefs) return true;
		if (CFG.prefs.frequency === 'every_login'){
			var s = sessionShownGet();
			if (s[item.key]) return false;
		}
		return true;
	}

	/* ─── polling ─── */
	function poll(){
		if (!CFG.prefs || CFG.prefs.enabled !== '1') return;
		ajax('cptt_reminders_check', {}, function(r){
			if (!r || !r.success || !r.data || !Array.isArray(r.data.reminders)) return;
			r.data.reminders.forEach(function(item){
				if (!clientShouldShow(item)) return;
				renderCard(item);
				if (CFG.prefs.frequency === 'every_login'){
					var s = sessionShownGet();
					s[item.key] = Date.now();
					sessionShownSet(s);
				}
			});
		});
	}

	/* ─── settings popup ─── */
	function openSettings(){
		closeSettings();
		var overlay = document.createElement('div');
		overlay.id = 'cptt-rmd-settings-overlay';
		overlay.innerHTML =
			'<div class="cptt-rmd-settings" role="dialog" aria-modal="true">' +
				'<div class="cptt-rmd-settings__head">' +
					'<div class="cptt-rmd-settings__title">' +
						'<svg viewBox="0 0 24 24" width="18" height="18"><path d="M12 6v6l4 2" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2.2"/></svg>' +
						'<span>تنظیمات یادآوری مهلت</span>' +
					'</div>' +
					'<button class="cptt-rmd-settings__close" aria-label="بستن">×</button>' +
				'</div>' +
				'<div class="cptt-rmd-settings__body">' +
					// enabled
					'<div class="cptt-rmd-fld cptt-rmd-fld--switch"><label>یادآوری مهلت‌ها فعال باشد</label>' +
						'<label class="cptt-rmd-switch"><input type="checkbox" id="rmd-enabled"><span class="cptt-rmd-switch__track"></span><span class="cptt-rmd-switch__thumb"></span></label>' +
					'</div>' +
					// leads
					'<div class="cptt-rmd-fld">' +
						'<label>چند زمان قبل از مهلت یادآوری شود</label>' +
						'<small>روی هر گزینه کلیک کنید تا انتخاب/لغو شود — ترکیب چند زمان امکان‌پذیر است.</small>' +
						'<div class="cptt-rmd-lead-chips" id="rmd-leads">' +
							leadChip(60*86400, '۲ ماه') +    // intentionally extreme to keep chip layout consistent
							leadChip( 7*86400, '۱ هفته') +
							leadChip( 3*86400, '۳ روز') +
							leadChip( 1*86400, '۱ روز') +
							leadChip( 6*3600,  '۶ ساعت') +
							leadChip( 1*3600,  '۱ ساعت') +
							leadChip( 30*60,   '۳۰ دقیقه') +
							leadChip( 10*60,   '۱۰ دقیقه') +
							leadChip( 5*60,    '۵ دقیقه') +
						'</div>' +
					'</div>' +
					// frequency
					'<div class="cptt-rmd-fld">' +
						'<label>چند بار یادآوری شود</label>' +
						'<select id="rmd-freq">' +
							'<option value="once">فقط یک بار</option>' +
							'<option value="every_login">هر بار ورود (تا انجام شدن)</option>' +
							'<option value="until_done">هر بار تا زمان انجام شدن</option>' +
							'<option value="interval">با فاصله‌ی زمانی مشخص</option>' +
						'</select>' +
					'</div>' +
					// interval
					'<div class="cptt-rmd-fld" id="rmd-fld-interval" style="display:none;">' +
						'<label>فاصله بین یادآوری‌ها (دقیقه)</label>' +
						'<input type="number" id="rmd-interval" min="1" max="1440" value="30">' +
					'</div>' +
					// snooze
					'<div class="cptt-rmd-fld">' +
						'<label>دکمه «یادآوری بعداً»: چند دقیقه دیگر یادآوری شود؟</label>' +
						'<input type="number" id="rmd-snooze" min="1" max="1440" value="15">' +
					'</div>' +
					// browser notif
					'<div class="cptt-rmd-fld cptt-rmd-fld--switch"><label>ارسال نوتیفیکیشن مرورگر (در صورت اجازه)</label>' +
						'<label class="cptt-rmd-switch"><input type="checkbox" id="rmd-bnotif"><span class="cptt-rmd-switch__track"></span><span class="cptt-rmd-switch__thumb"></span></label>' +
					'</div>' +
				'</div>' +
				'<div class="cptt-rmd-settings__foot">' +
					'<button type="button" class="cptt-rmd-test">🔔 تست نمایش</button>' +
					'<button type="button" class="cptt-rmd-save">ذخیره</button>' +
				'</div>' +
			'</div>';
		document.body.appendChild(overlay);

		// fill from current prefs
		var p = CFG.prefs || {};
		var enabled = (p.enabled === '1');
		var bnotif  = (p.browser_notif === '1');
		var freq    = p.frequency || 'until_done';
		var leadsArr = Array.isArray(p.leads) ? p.leads.map(Number) : [86400, 3600, 600];

		overlay.querySelector('#rmd-enabled').checked = enabled;
		overlay.querySelector('#rmd-bnotif').checked  = bnotif;
		overlay.querySelector('#rmd-freq').value      = freq;
		overlay.querySelector('#rmd-interval').value  = p.interval_min || 30;
		overlay.querySelector('#rmd-snooze').value    = p.snooze_min || 15;
		toggleIntervalRow();

		Array.prototype.forEach.call(overlay.querySelectorAll('.cptt-rmd-lead-chip'), function(chip){
			var v = parseInt(chip.getAttribute('data-val'), 10);
			if (leadsArr.indexOf(v) !== -1) chip.classList.add('is-active');
			chip.addEventListener('click', function(){ chip.classList.toggle('is-active'); });
		});

		overlay.querySelector('#rmd-freq').addEventListener('change', toggleIntervalRow);
		function toggleIntervalRow(){
			overlay.querySelector('#rmd-fld-interval').style.display =
				(overlay.querySelector('#rmd-freq').value === 'interval') ? '' : 'none';
		}

		overlay.querySelector('.cptt-rmd-settings__close').addEventListener('click', closeSettings);
		overlay.addEventListener('click', function(e){ if (e.target === overlay) closeSettings(); });

		overlay.querySelector('.cptt-rmd-save').addEventListener('click', function(){
			var leads = [];
			Array.prototype.forEach.call(overlay.querySelectorAll('.cptt-rmd-lead-chip.is-active'), function(c){
				leads.push(parseInt(c.getAttribute('data-val'), 10));
			});
			var prefs = {
				enabled:       overlay.querySelector('#rmd-enabled').checked ? 1 : 0,
				browser_notif: overlay.querySelector('#rmd-bnotif').checked ? 1 : 0,
				frequency:     overlay.querySelector('#rmd-freq').value,
				interval_min:  parseInt(overlay.querySelector('#rmd-interval').value, 10) || 30,
				snooze_min:    parseInt(overlay.querySelector('#rmd-snooze').value, 10) || 15,
				leads:         leads.length ? leads : [86400, 3600, 600],
			};
			var saveBtn = overlay.querySelector('.cptt-rmd-save');
			saveBtn.disabled = true; saveBtn.textContent = 'در حال ذخیره...';
			ajax('cptt_reminders_save_prefs', { prefs: prefs }, function(r){
				if (r && r.success && r.data && r.data.prefs){
					CFG.prefs = r.data.prefs;
					if (prefs.browser_notif) maybeAskPermission();
					closeSettings();
					// poll right away so changes feel instant
					setTimeout(poll, 200);
				} else {
					alert((r && r.data) ? r.data : 'خطا در ذخیره تنظیمات');
					saveBtn.disabled = false; saveBtn.textContent = 'ذخیره';
				}
			});
		});

		overlay.querySelector('.cptt-rmd-test').addEventListener('click', function(){
			renderCard({
				key:           'test:' + Date.now(),
				type:          'project',
				project_id:    0,
				project_title: 'پروژه نمونه',
				title:         'این یک یادآوری آزمایشی است',
				deadline_ts:   Math.floor(Date.now()/1000) + 600,
				deadline_fa:   '۱۰ دقیقه دیگر',
				diff_seconds:  600,
				lead_label:    'تا ۱۰ دقیقه دیگر',
				expired:       false,
			});
		});
	}
	function closeSettings(){
		var o = document.getElementById('cptt-rmd-settings-overlay');
		if (o && o.parentNode) o.parentNode.removeChild(o);
	}
	function leadChip(secs, label){
		return '<button type="button" class="cptt-rmd-lead-chip" data-val="' + secs + '">' + label + '</button>';
	}

	/* ─── inject the bell button next to the notification settings gear ─── */
	function injectBellButton(){
		// Place inside the notifications header, beside #cptt-notif-settings-btn
		var anchor = document.getElementById('cptt-notif-settings-btn');
		if (!anchor) return;
		if (document.getElementById('cptt-reminders-btn')) return;
		var btn = document.createElement('button');
		btn.id = 'cptt-reminders-btn';
		btn.type = 'button';
		btn.title = 'تنظیمات یادآوری مهلت';
		btn.setAttribute('aria-label', 'تنظیمات یادآوری مهلت');
		btn.innerHTML =
			'<svg viewBox="0 0 24 24" width="17" height="17"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2.2"/><polyline points="12 7 12 12 15 14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>';
		// Place AFTER the gear so order is: ✓ خواندن همه | gear | bell
		anchor.parentNode.insertBefore(btn, anchor.nextSibling);
		btn.addEventListener('click', function(e){
			e.preventDefault();
			e.stopPropagation();
			openSettings();
		});
	}

	/* =====================================================================
	 * v6.3.1 — Sidebar scroll-lock helper.
	 *
	 * The CSS scroll-lock used to be on `.cptt-expertSidebar` directly.
	 * That broke the notifications dropdown (which is `position: absolute`
	 * inside the sidebar) because the dropdown got clipped and forced a
	 * horizontal scrollbar on the sidebar.
	 *
	 * Solution: wrap the inner sidebar content in a sibling scroller
	 * `.cptt-sidebar-scroll-wrap`, but keep the notifications-bell
	 * + its dropdown OUTSIDE the wrap (so the dropdown can overflow
	 * freely without making the wrap scroll horizontally).
	 * ===================================================================== */
	function applySidebarScrollLock(){
		// Mobile? Don't touch.
		if (window.matchMedia && window.matchMedia('(max-width: 900px)').matches) return;
		var sidebar = document.querySelector('.cptt-expertSidebar');
		if (!sidebar) return;
		if (sidebar.dataset.scrollLockApplied === '1') return;
		sidebar.dataset.scrollLockApplied = '1';

		// Find the notification bell — it must stay OUTSIDE the scroller
		// because its `.cptt-notifications-dropdown` is absolutely-positioned
		// and would otherwise be clipped by overflow-y:auto.
		var bell = sidebar.querySelector('.cptt-notification-bell, .cptt-sidebar-controls');
		// Build the wrapper.
		var wrap = document.createElement('div');
		wrap.className = 'cptt-sidebar-scroll-wrap';

		// Move every direct child of sidebar into the wrap, EXCEPT the bell.
		var kids = Array.prototype.slice.call(sidebar.children);
		kids.forEach(function(child){
			if (bell && (child === bell || child.contains(bell))) return; // keep bell at sidebar level
			wrap.appendChild(child);
		});
		sidebar.appendChild(wrap);

		// Make sure the bell area sits ABOVE the scroller and doesn't get covered.
		if (bell){
			bell.style.position = bell.style.position || 'relative';
			bell.style.zIndex = '5';
		}
	}

	ready(function(){
		injectBellButton();
		// inject is timing-sensitive (the notifications dropdown is built by PHP
		// inline); make sure it eventually attaches even if first try misses.
		setTimeout(injectBellButton, 500);
		setTimeout(injectBellButton, 1500);

		applySidebarScrollLock();
		// re-apply on resize (in case mobile→desktop)
		var rT = null;
		window.addEventListener('resize', function(){
			if (rT) clearTimeout(rT);
			rT = setTimeout(applySidebarScrollLock, 200);
		}, { passive: true });

		maybeAskPermission();

		// First poll after 4s (let the page settle), then on interval.
		setTimeout(poll, 4000);
		var ivl = Math.max(20, parseInt(CFG.poll_secs || 60, 10)) * 1000;
		setInterval(poll, ivl);
	});
})();
