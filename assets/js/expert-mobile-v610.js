/* =====================================================================
 * CPTT v6.1.3 — Expert Dashboard UX (round 4) — JS side
 *
 *   - Tag .cptt-expertCard__infoGrid items with semantic classes
 *   - Inject a mobile-only "deadline" tile into stats
 *   - Inject "created date" chip + view columns
 *   - Clean up any legacy `cptt-filter-fixed` artefacts on load/scroll/resize
 *   - Wire up archive / unarchive buttons (AJAX) — default CLOSED
 *   - NEW: Customer messenger dropdown (Eitaa / Bale / Rubika / WhatsApp /
 *          Telegram / SMS / Copy-to-clipboard)
 * ===================================================================== */
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

	/* ─── Jalali short converter ─── */
	function g2j(gy, gm, gd){
		var g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
		var gy2 = (gm > 2) ? (gy + 1) : gy;
		var days = 355666 + (365 * gy) + (~~((gy2 + 3) / 4)) - (~~((gy2 + 99) / 100)) + (~~((gy2 + 399) / 400)) + gd + g_d_m[gm - 1];
		var jy = -1595 + (33 * ~~(days / 12053));
		days %= 12053;
		jy += 4 * ~~(days / 1461);
		days %= 1461;
		if (days > 365){ jy += ~~((days - 1) / 365); days = (days - 1) % 365; }
		var jm = (days < 186) ? 1 + ~~(days / 31) : 7 + ~~((days - 186) / 30);
		var jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));
		return [jy, jm, jd];
	}
	var FA_MONTHS_SHORT = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
	function toFaDigits(n){
		var fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
		return String(n).replace(/[0-9]/g, function(d){ return fa[+d]; });
	}
	function ts2faShort(ts){
		try{
			var d = new Date(ts * 1000);
			var j = g2j(d.getFullYear(), d.getMonth() + 1, d.getDate());
			return toFaDigits(j[2]) + ' ' + FA_MONTHS_SHORT[j[1] - 1] + ' ' + toFaDigits(j[0]);
		}catch(e){ return ''; }
	}

	/* ─── 1) Tag infoGrid items ─── */
	function tagInfoGrid(card){
		var grid = card.querySelector('.cptt-expertCard__infoGrid');
		if (!grid || grid.dataset.cpttTagged) return;
		grid.dataset.cpttTagged = '1';
		var items = grid.children;
		for (var i = 0; i < items.length; i++){
			var it = items[i];
			var label = (it.querySelector('span') || {}).textContent || '';
			label = label.trim();
			if (label.indexOf('مهلت') !== -1)            it.classList.add('cptt-info-deadline');
			else if (label.indexOf('آخرین') !== -1)       it.classList.add('cptt-info-last-update');
			else if (label.indexOf('وضعیت مالی') !== -1)  it.classList.add('cptt-info-settled');
			else if (label.indexOf('هزینه') !== -1)       it.classList.add('cptt-info-finance');
			else if (label.indexOf('روش تحویل') !== -1)   it.classList.add('cptt-info-delivery');
			else if (label.indexOf('استان') !== -1)       it.classList.add('cptt-info-province');
			else if (label.indexOf('آدرس') !== -1)        it.classList.add('cptt-info-address');
		}
	}
	function tagStatsAndInjectMobileDeadline(card){
		var stats = card.querySelector('.cptt-expertCard__stats');
		if (!stats || stats.dataset.cpttTagged) return;
		stats.dataset.cpttTagged = '1';
		var items = stats.children;
		for (var i = 0; i < items.length; i++){
			var label = (items[i].querySelector('span') || {}).textContent || '';
			label = label.trim();
			if (label === 'پیشرفت' || label.indexOf('پیشرفت') !== -1) items[i].classList.add('cptt-stat-progress');
			else if (label.indexOf('چک‌لیست') !== -1)                items[i].classList.add('cptt-stat-checklist');
			else if (label.indexOf('تسک') !== -1)                     items[i].classList.add('cptt-stat-utask');
			else if (label.indexOf('مانده') !== -1)                   items[i].classList.add('cptt-stat-remain');
		}
		var dlFa = card.dataset.deadlineFa || '';
		if (!dlFa){
			var dlItem = card.querySelector('.cptt-expertCard__infoGrid > .cptt-info-deadline strong');
			if (dlItem) dlFa = dlItem.textContent.trim();
		}
		if (!dlFa) dlFa = '—';
		var dlTile = document.createElement('div');
		dlTile.className = 'cptt-stat-deadline-mobile';
		dlTile.style.cssText = 'background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:12px 8px;text-align:center;';
		dlTile.innerHTML =
			'<strong style="display:block;font-weight:950;color:#9a3412;font-size:13px;">' + escHtml(dlFa) + '</strong>' +
			'<span style="display:block;color:#9a3412;font-weight:800;margin-top:4px;font-size:11px;">مهلت پروژه</span>';
		stats.insertBefore(dlTile, stats.firstChild);
	}
	function tagAllCards(){
		var cards = document.querySelectorAll('.cptt-expertCard');
		for (var i = 0; i < cards.length; i++){
			tagInfoGrid(cards[i]);
			tagStatsAndInjectMobileDeadline(cards[i]);
		}
	}

	/* ─── 2) Created date chip on card titles ─── */
	function addCreatedChipToCards(){
		var cards = document.querySelectorAll('.cptt-expertCard');
		for (var i = 0; i < cards.length; i++){
			var card = cards[i];
			if (card.dataset.cpttCrChip) continue;
			var ts = parseInt(card.dataset.createdTs || '0', 10);
			if (!ts) { card.dataset.cpttCrChip = '1'; continue; }
			var fa = ts2faShort(ts);
			if (!fa) { card.dataset.cpttCrChip = '1'; continue; }
			var h3 = card.querySelector('.cptt-expertCard__top h3');
			if (!h3) continue;
			if (h3.querySelector('.cptt-expertCard__createdInline')) { card.dataset.cpttCrChip = '1'; continue; }
			var chip = document.createElement('span');
			chip.className = 'cptt-expertCard__createdInline';
			chip.title = 'تاریخ ایجاد پروژه';
			chip.innerHTML =
				'<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+
				'<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>'+
				'<line x1="16" y1="2" x2="16" y2="6"></line>'+
				'<line x1="8" y1="2" x2="8" y2="6"></line>'+
				'<line x1="3" y1="10" x2="21" y2="10"></line>'+
				'</svg>' + '<span>'+ escHtml(fa) +'</span>';
			h3.appendChild(chip);
			card.dataset.cpttCrChip = '1';
		}
	}
	function getCardCreatedFa(card){
		var ts = parseInt(card.dataset.createdTs || '0', 10);
		if (!ts) return '';
		return ts2faShort(ts);
	}

	/* ─── 3) Inject "created date" into list/timeline/gantt ─── */
	function addCreatedToListView(){
		var table = document.querySelector('#cptt-list-view .cev-list');
		if (!table || table.dataset.cpttCrCol) return;
		var headRow = table.querySelector('thead tr');
		if (!headRow) return;
		var th = document.createElement('th');
		th.textContent = 'ایجاد';
		var headCells = headRow.querySelectorAll('th');
		if (headCells.length >= 2) headRow.insertBefore(th, headCells[headCells.length - 2]);
		else headRow.appendChild(th);
		var rows = table.querySelectorAll('tbody tr');
		for (var i = 0; i < rows.length; i++){
			var row = rows[i];
			var pid = row.dataset.pid || '';
			var card = pid ? document.querySelector('.cptt-expertCard[data-project-id="'+ pid +'"]') : null;
			var fa = card ? getCardCreatedFa(card) : '';
			var td = document.createElement('td');
			td.className = 'cev-list__cr';
			td.textContent = fa || '—';
			var cells = row.querySelectorAll('td');
			if (cells.length >= 2) row.insertBefore(td, cells[cells.length - 2]);
			else row.appendChild(td);
		}
		table.dataset.cpttCrCol = '1';
	}
	function addCreatedToTimeline(){
		var con = document.getElementById('cptt-timeline-view');
		if (!con) return;
		var items = con.querySelectorAll('.cev-tl-item');
		for (var i = 0; i < items.length; i++){
			var it = items[i];
			if (it.dataset.cpttCr) continue;
			var meta = it.querySelector('.cev-tl-meta');
			if (!meta) continue;
			var pid = it.dataset.pid || '';
			var card = pid ? document.querySelector('.cptt-expertCard[data-project-id="'+ pid +'"]') : null;
			var fa = card ? getCardCreatedFa(card) : '';
			if (!fa) { it.dataset.cpttCr = '1'; continue; }
			var span = document.createElement('span');
			span.className = 'cev-tl-cr';
			span.title = 'تاریخ ایجاد پروژه';
			span.innerHTML = '🗓 ' + escHtml(fa);
			meta.appendChild(span);
			it.dataset.cpttCr = '1';
		}
	}
	function addCreatedToGantt(){
		var con = document.getElementById('cptt-gantt-view');
		if (!con) return;
		var rows = con.querySelectorAll('.cev-gantt-row');
		for (var i = 0; i < rows.length; i++){
			var row = rows[i];
			if (row.dataset.cpttCr) continue;
			var pid = row.dataset.pid || '';
			var card = pid ? document.querySelector('.cptt-expertCard[data-project-id="'+ pid +'"]') : null;
			var fa = card ? getCardCreatedFa(card) : '';
			if (!fa) { row.dataset.cpttCr = '1'; continue; }
			var lbl = row.querySelector('.cev-gantt-lbl');
			if (lbl && !lbl.querySelector('.cev-gantt-cr')){
				var sm = document.createElement('div');
				sm.className = 'cev-gantt-cr';
				sm.textContent = 'ایجاد: ' + fa;
				lbl.appendChild(sm);
			}
			row.dataset.cpttCr = '1';
		}
	}

	/* ─── 4) Strip any legacy `cptt-filter-fixed` classes/inline-style ─── */
	function cleanLegacyFixed(){
		var els = document.querySelectorAll('.cptt-filter-fixed, .cptt-filter-bar.cptt-filter-fixed, .cptt-expertFilters.cptt-filter-fixed, .cptt-hubFilters.cptt-filter-fixed');
		for (var i = 0; i < els.length; i++){
			var el = els[i];
			el.classList.remove('cptt-filter-fixed');
			if (el.style){
				el.style.position = '';
				el.style.top = '';
				el.style.left = '';
				el.style.right = '';
				el.style.bottom = '';
				el.style.width = '';
				el.style.maxWidth = '';
				el.style.transform = '';
			}
		}
		var phs = document.querySelectorAll('.cptt-filter-sticky-placeholder');
		for (var j = 0; j < phs.length; j++){
			phs[j].style.display = 'none';
			phs[j].style.height = '0';
		}
	}

	/* ─── 5) Archive toggle — default CLOSED ─── */
	function initArchiveToggle(){
		var sec = document.getElementById('cptt-archive-section');
		var btn = document.getElementById('cptt-archive-toggle');
		var body = document.getElementById('cptt-archive-body');
		if (!sec || !btn || !body) return;
		body.setAttribute('hidden', '');
		sec.classList.remove('is-open');
		sec.setAttribute('aria-expanded', 'false');
		btn.setAttribute('aria-expanded', 'false');
		btn.addEventListener('click', function(){
			var isOpen = !body.hasAttribute('hidden');
			if (isOpen){
				body.setAttribute('hidden', '');
				sec.classList.remove('is-open');
				sec.setAttribute('aria-expanded', 'false');
				btn.setAttribute('aria-expanded', 'false');
			} else {
				body.removeAttribute('hidden');
				sec.classList.add('is-open');
				sec.setAttribute('aria-expanded', 'true');
				btn.setAttribute('aria-expanded', 'true');
			}
		});
	}

	/* ─── 6) Archive / Unarchive buttons (AJAX) ─── */
	function getAjax(){
		if (typeof window.CPTT_EXPERT !== 'undefined' && CPTT_EXPERT && CPTT_EXPERT.ajax) return CPTT_EXPERT;
		if (typeof window.CPTT_PANELS !== 'undefined' && CPTT_PANELS && CPTT_PANELS.ajax) return { ajax: CPTT_PANELS.ajax, nonce: CPTT_PANELS.nonce };
		if (typeof window.ajaxurl !== 'undefined') return { ajax: window.ajaxurl, nonce: '' };
		return null;
	}
	function ajaxPost(action, data, cb){
		var cfg = getAjax();
		if (!cfg){ if (cb) cb(null); return; }
		var fd = new FormData();
		fd.append('action', action);
		if (cfg.nonce) fd.append('nonce', cfg.nonce);
		for (var k in data) if (data.hasOwnProperty(k)) fd.append(k, data[k]);
		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.ajax);
		xhr.onload = function(){
			var r = null;
			try { r = JSON.parse(xhr.responseText); } catch(e){}
			if (cb) cb(r);
		};
		xhr.onerror = function(){ if (cb) cb(null); };
		xhr.send(fd);
	}
	function bindArchiveButtons(){
		document.addEventListener('click', function(e){
			var aBtn = e.target.closest('.cptt-expert-archive-project');
			if (aBtn){
				e.preventDefault(); e.stopPropagation();
				var pid = aBtn.dataset.projectId; if (!pid) return;
				if (!confirm('این پروژه به آرشیو منتقل شود؟\n\nاطلاعات پروژه پاک نمی‌شود؛ فقط از داشبورد اصلی برداشته می‌شود و در بخش «آرشیو پروژه‌ها» قابل دسترس خواهد بود.')) return;
				aBtn.disabled = true;
				ajaxPost('cptt_expert_archive_project', { project_id: pid }, function(r){
					if (r && r.success){
						var card = aBtn.closest('.cptt-expertCard');
						if (card){
							card.style.transition = 'opacity .3s, transform .3s';
							card.style.opacity = '0';
							card.style.transform = 'scale(.96)';
							setTimeout(function(){ location.reload(); }, 320);
						} else { location.reload(); }
					} else {
						aBtn.disabled = false;
						alert((r && r.data) ? ('خطا: ' + r.data) : 'خطا در آرشیو پروژه');
					}
				});
				return;
			}
			var uBtn = e.target.closest('.cptt-expert-unarchive-project');
			if (uBtn){
				e.preventDefault(); e.stopPropagation();
				var upid = uBtn.dataset.projectId; if (!upid) return;
				if (!confirm('این پروژه از آرشیو خارج شود و به داشبورد اصلی برگردد؟')) return;
				uBtn.disabled = true;
				ajaxPost('cptt_expert_unarchive_project', { project_id: upid }, function(r){
					if (r && r.success){ location.reload(); }
					else {
						uBtn.disabled = false;
						alert((r && r.data) ? ('خطا: ' + r.data) : 'خطا در بازگردانی پروژه');
					}
				});
				return;
			}
		});
	}

	/* ─── 7) Customer messenger dropdown ───
	 * Triggered by clicking .cptt-customer-msg-btn next to a <select.cptt-customer-select>.
	 */
	function normalizePhone(p){
		p = String(p || '').replace(/[^0-9]/g, '');
		if (p.length === 10 && p.charAt(0) === '9') p = '0' + p;
		return p;
	}
	function phoneIntl(p){
		// 09XXXXXXXXX → 989XXXXXXXXX
		p = normalizePhone(p);
		if (p.length === 11 && p.charAt(0) === '0' && p.charAt(1) === '9') return '98' + p.substring(1);
		if (p.length === 13 && p.substring(0, 3) === '+98') return p.substring(1);
		if (p.length === 12 && p.substring(0, 2) === '98') return p;
		return p;
	}
	function closeMessengerPopups(){
		var pops = document.querySelectorAll('.cptt-msg-popup');
		for (var i = 0; i < pops.length; i++) pops[i].parentNode && pops[i].parentNode.removeChild(pops[i]);
	}
	function showToast(text){
		var el = document.createElement('div');
		el.className = 'cptt-msg-popup__toast';
		el.textContent = text;
		document.body.appendChild(el);
		setTimeout(function(){
			el.style.transition = 'opacity .25s';
			el.style.opacity = '0';
			setTimeout(function(){ if (el.parentNode) el.parentNode.removeChild(el); }, 280);
		}, 1500);
	}

	function buildMessengers(phone){
		var p = normalizePhone(phone);
		var intl = phoneIntl(phone);
		// v6.1.4 — only: Telegram, WhatsApp, SMS, Call, Copy
		return [
			{
				id:'whatsapp', label:'واتساپ',
				url: intl ? 'https://wa.me/' + intl : '',
				icon:'<svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg"><circle cx="16" cy="16" r="16" fill="#25D366"/><path d="M22.7 18.2c-.3-.2-1.8-.9-2.1-1s-.5-.2-.7.2-.8 1-.9 1.2c-.2.2-.3.2-.6.1-.3-.2-1.3-.5-2.5-1.5-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.6.1-.1.3-.4.4-.5.1-.2.2-.3.3-.5.1-.2 0-.4 0-.5l-.7-1.7c-.2-.4-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.4-.2.3-.9 1-.9 2.3 0 1.4 1 2.7 1.1 2.9.1.2 2 3 4.7 4.1.7.3 1.2.5 1.6.6.7.2 1.3.2 1.8.1.5-.1 1.8-.7 2-1.4.2-.7.2-1.3.2-1.4-.1-.2-.3-.2-.6-.3z" fill="#fff"/></svg>'
			},
			{
				id:'telegram', label:'تلگرام',
				url: intl ? 'https://t.me/+' + intl : (p ? 'tg://resolve?phone=' + p : ''),
				icon:'<svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg"><circle cx="16" cy="16" r="16" fill="#229ED9"/><path d="M23.6 9.4 7.2 15.7c-.7.3-.7.7-.1.9l4.2 1.3 9.7-6.1c.5-.3.9-.1.6.2l-7.8 7-0.3 4.3c.4 0 .6-.2.8-.4l2-1.9 4.1 3c.8.4 1.3.2 1.5-.7l2.7-12.7c.3-1.3-.4-1.9-1-1.6z" fill="#fff"/></svg>'
			},
			{
				id:'sms', label:'پیامک',
				url: p ? 'sms:' + p : '',
				icon:'<svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg"><circle cx="16" cy="16" r="16" fill="#6366f1"/><path d="M9 11h14v8H15l-3 3v-3H9z" fill="#fff"/></svg>'
			},
			{
				id:'call', label:'تماس',
				url: p ? 'tel:' + p : '',
				icon:'<svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg"><circle cx="16" cy="16" r="16" fill="#10b981"/><path d="M21.8 19.4c-.7 0-1.4-.1-2-.3-.2-.1-.5 0-.7.2l-1.3 1.3c-1.7-.9-3.1-2.3-4-4l1.3-1.3c.2-.2.3-.5.2-.7-.2-.7-.3-1.3-.3-2 0-.4-.3-.7-.7-.7H12c-.4 0-.8.2-.8.7 0 5.3 4.3 9.6 9.6 9.6.4 0 .7-.4.7-.8v-2.3c0-.4-.3-.7-.7-.7z" fill="#fff"/></svg>'
			},
			{
				id:'copy', label:'کپی',
				url:'', action:'copy',
				icon:'<svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg"><circle cx="16" cy="16" r="16" fill="#475569"/><path d="M13 9h8v12h-8z" fill="none" stroke="#fff" stroke-width="2"/><path d="M11 13H9v10h8v-2" fill="none" stroke="#fff" stroke-width="2"/></svg>'
			}
		];
	}

	function openMessengerDropdown(btn){
		closeMessengerPopups();
		var field = btn.closest('.cptt-customer-field, .cptt-customer-row, label');
		var sel = field ? field.querySelector('select.cptt-customer-select, select[name="client_user_id"]') : null;
		if (!sel){
			sel = btn.parentNode.querySelector('select.cptt-customer-select, select[name="client_user_id"]');
		}
		if (!sel || !sel.value){
			alert('ابتدا یک مشتری انتخاب کنید.');
			return;
		}
		var opt = sel.options[sel.selectedIndex];
		var phone = opt ? (opt.dataset.phone || '') : '';
		var name  = opt ? (opt.dataset.name  || opt.textContent.replace(/\(.*?\)/g, '').trim()) : '';
		var pop = document.createElement('div');
		pop.className = 'cptt-msg-popup';

		var items = buildMessengers(phone);
		var itemsHtml = items.map(function(m){
			var disabled = (!phone && m.id !== 'copy') ? ' cptt-msg-popup__item--noPhone' : '';
			return '<a href="' + (m.url || '#') + '"' +
				(m.url ? ' target="_blank" rel="noopener noreferrer"' : '') +
				' class="cptt-msg-popup__item' + disabled + '"' +
				' data-msg="' + m.id + '"' +
				(m.alt ? ' data-alt="' + m.alt + '"' : '') +
				'>' + m.icon + '<span>' + m.label + '</span></a>';
		}).join('');

		pop.innerHTML =
			'<div class="cptt-msg-popup__header">' +
				'<div>' +
					'<div class="cptt-msg-popup__title">' + escHtml(name || 'مشتری') + '</div>' +
					(phone ? '<div class="cptt-msg-popup__phone">' + escHtml(phone) + '</div>' : '<div class="cptt-msg-popup__phone" style="color:#ef4444">شماره ثبت نشده</div>') +
				'</div>' +
				'<button type="button" class="cptt-msg-popup__close" aria-label="بستن">×</button>' +
			'</div>' +
			'<div class="cptt-msg-popup__items">' + itemsHtml + '</div>';

		document.body.appendChild(pop);

		// position
		var rect = btn.getBoundingClientRect();
		var popRect = pop.getBoundingClientRect();
		var top = rect.bottom + 8;
		var left = rect.right - popRect.width;
		if (left < 8) left = 8;
		if (top + popRect.height > window.innerHeight - 8) top = rect.top - popRect.height - 8;
		if (top < 8) top = 8;
		pop.style.top = top + 'px';
		pop.style.left = left + 'px';

		pop.querySelector('.cptt-msg-popup__close').onclick = closeMessengerPopups;
		pop.addEventListener('click', function(e){
			var item = e.target.closest('.cptt-msg-popup__item');
			if (!item) return;
			var act = item.dataset.msg;
			if (act === 'copy'){
				e.preventDefault();
				if (!phone){ alert('شماره‌ای برای کپی وجود ندارد.'); return; }
				if (navigator.clipboard && navigator.clipboard.writeText){
					navigator.clipboard.writeText(phone).then(function(){
						showToast('شماره کپی شد: ' + phone);
					}, function(){
						fallbackCopy(phone);
					});
				} else {
					fallbackCopy(phone);
				}
				closeMessengerPopups();
				return;
			}
			if (!item.href || item.href === window.location.href + '#'){
				e.preventDefault();
				alert('شماره موبایل برای این مشتری ثبت نشده است.');
				return;
			}
			closeMessengerPopups();
		});

		// close on outside click
		setTimeout(function(){
			function outside(ev){
				if (!ev.target.closest('.cptt-msg-popup') && !ev.target.closest('.cptt-customer-msg-btn')){
					closeMessengerPopups();
					document.removeEventListener('click', outside, true);
				}
			}
			document.addEventListener('click', outside, true);
		}, 0);
	}

	function fallbackCopy(text){
		try {
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.style.cssText = 'position:fixed;left:-9999px;top:0;';
			document.body.appendChild(ta);
			ta.select();
			document.execCommand('copy');
			document.body.removeChild(ta);
			showToast('شماره کپی شد: ' + text);
		} catch(e){
			alert('شماره: ' + text);
		}
	}

	function bindMessengerButtons(){
		document.addEventListener('click', function(e){
			var btn = e.target.closest('.cptt-customer-msg-btn');
			if (!btn) return;
			e.preventDefault();
			e.stopPropagation();
			openMessengerDropdown(btn);
		});
		window.addEventListener('scroll', closeMessengerPopups, true);
		window.addEventListener('resize', closeMessengerPopups, true);
	}

	/* =====================================================================
	 * 8) Extra finance rows — compact inline UX
	 *
	 *   - A small "+" SVG button is appended into the LAST row's finance grid.
	 *     Last row = the most recent .cptt-ef-row if any, else the main
	 *     .cptt-step-finance-grid of the step.
	 *   - Clicking + creates a new .cptt-ef-row under it and moves the +
	 *     button to that new row.
	 *   - Each .cptt-ef-row has an "×" remove button.
	 * ===================================================================== */

	var ADD_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
	var REMOVE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

	function getStepId(step){
		if (!step) return '';
		return step.getAttribute('data-step-id') || '';
	}

	function findLastFinanceRowOfStep(step){
		// last extra row inside .cptt-step-extra-finance, else the main finance grid
		var box = step.querySelector('.cptt-step-extra-finance');
		if (box){
			var rows = box.querySelectorAll('.cptt-ef-row');
			if (rows.length) return rows[rows.length - 1];
		}
		return step.querySelector('.cptt-step-finance-grid');
	}

	function ensureAddButton(step){
		if (!step) return;
		var stepId = getStepId(step);
		var last = findLastFinanceRowOfStep(step);
		if (!last) return;

		// v6.1.7 — idempotent check: if + button is ALREADY on the correct
		// last row, do nothing. Saves us from useless DOM churn.
		var existingAddOnLast = last.querySelector(':scope > .cptt-ef-btn--add, :scope > label.cptt-ef-add-cell, :scope > .cptt-ef-actions .cptt-ef-btn--add');
		var totalAdds = step.querySelectorAll('.cptt-ef-btn--add').length;
		if (existingAddOnLast && totalAdds === 1) return;

		// remove existing add buttons in this step
		var existingAdds = step.querySelectorAll('.cptt-ef-btn--add, label.cptt-ef-add-cell');
		for (var i = 0; i < existingAdds.length; i++){
			if (existingAdds[i].parentNode) existingAdds[i].parentNode.removeChild(existingAdds[i]);
		}
		// remove `--has-add` class from any grid that lost its button
		var prevGrids = step.querySelectorAll('.cptt-step-finance-grid');
		for (var k = 0; k < prevGrids.length; k++){
			prevGrids[k].classList.remove('cptt-finance-grid--has-add');
		}

		if (last.classList.contains('cptt-step-finance-grid')){
			// Add a small + cell as the last column inside the main finance grid
			last.classList.add('cptt-finance-grid--has-add');
			var lbl = document.createElement('label');
			lbl.className = 'cptt-ef-add-cell';
			lbl.innerHTML = '<span>افزودن</span><button type="button" class="cptt-ef-btn--add" title="افزودن ردیف مالی" aria-label="افزودن ردیف مالی" data-step-id="'+ escAttr(stepId) +'">'+ ADD_SVG +'</button>';
			last.appendChild(lbl);
		} else if (last.classList.contains('cptt-ef-row')){
			// Append + button alongside the × inside a small actions wrapper.
			var rmBtn = last.querySelector('.cptt-ef-btn--remove');
			// If actions wrapper exists, reuse; else create one
			var actions = last.querySelector('.cptt-ef-actions');
			if (!actions){
				actions = document.createElement('div');
				actions.className = 'cptt-ef-actions';
				if (rmBtn && rmBtn.parentNode){
					rmBtn.parentNode.insertBefore(actions, rmBtn);
					actions.appendChild(rmBtn);
				} else {
					last.appendChild(actions);
				}
			}
			var addBtn = document.createElement('button');
			addBtn.type = 'button';
			addBtn.className = 'cptt-ef-btn--add';
			addBtn.title = 'افزودن ردیف مالی';
			addBtn.setAttribute('aria-label', 'افزودن ردیف مالی');
			addBtn.setAttribute('data-step-id', stepId);
			addBtn.innerHTML = ADD_SVG;
			actions.appendChild(addBtn);
			last.classList.add('cptt-ef-row--has-actions');
		}
	}

	function escAttr(s){ return String(s||'').replace(/"/g, '&quot;'); }

	function injectAddButtons(){
		var steps = document.querySelectorAll('.cptt-expert-step');
		for (var i = 0; i < steps.length; i++) ensureAddButton(steps[i]);
	}

	function appendExtraFinanceRow(step){
		if (!step) return;
		var stepId = getStepId(step);
		var box = step.querySelector('.cptt-step-extra-finance');
		if (!box){
			// create container right after the main finance grid
			var grid = step.querySelector('.cptt-step-finance-grid');
			box = document.createElement('div');
			box.className = 'cptt-step-extra-finance';
			box.setAttribute('data-step-id', stepId);
			if (grid && grid.parentNode) grid.parentNode.insertBefore(box, grid.nextSibling);
			else step.appendChild(box);
		}
		var idx = 'new_' + Date.now() + '_' + box.querySelectorAll('.cptt-ef-row').length;
		var safeStep = stepId.replace(/"/g, '');
		var row = document.createElement('div');
		row.className = 'cptt-ef-row';
		row.setAttribute('data-step-id', stepId);
		row.innerHTML =
			'<label class="cptt-ef-cell cptt-ef-cell--title"><span>عنوان</span>' +
				'<input type="text" name="steps['+safeStep+'][extra_finance]['+idx+'][title]" placeholder="مثلاً: هزینه چاپ">' +
			'</label>' +
			'<label class="cptt-ef-cell cptt-ef-cell--cost"><span>هزینه</span>' +
				'<input type="text" class="cptt-currency-input" name="steps['+safeStep+'][extra_finance]['+idx+'][cost]" value="0">' +
			'</label>' +
			'<label class="cptt-ef-cell cptt-ef-cell--paid"><span>دریافتی</span>' +
				'<input type="text" class="cptt-currency-input" name="steps['+safeStep+'][extra_finance]['+idx+'][paid]" value="0">' +
			'</label>' +
			'<button type="button" class="cptt-ef-btn--remove" title="حذف این ردیف مالی" aria-label="حذف">'+ REMOVE_SVG +'</button>';
		box.appendChild(row);
		// re-place the + button at the bottom row
		ensureAddButton(step);
		// focus title field
		var firstInp = row.querySelector('input[type="text"]');
		if (firstInp) firstInp.focus();
	}

	function bindExtraFinanceButtons(){
		document.addEventListener('click', function(e){
			var addBtn = e.target.closest('.cptt-ef-btn--add');
			if (addBtn){
				e.preventDefault();
				e.stopPropagation();
				var step = addBtn.closest('.cptt-expert-step');
				if (step) appendExtraFinanceRow(step);
				return;
			}
			var rmBtn = e.target.closest('.cptt-ef-btn--remove');
			if (rmBtn){
				e.preventDefault();
				e.stopPropagation();
				var row = rmBtn.closest('.cptt-ef-row');
				var step = rmBtn.closest('.cptt-expert-step');
				if (row){
					row.style.transition = 'opacity .2s, transform .2s';
					row.style.opacity = '0';
					row.style.transform = 'scale(.96)';
					setTimeout(function(){
						if (row.parentNode) row.parentNode.removeChild(row);
						if (step) ensureAddButton(step);
					}, 200);
				}
				return;
			}
		});

		// v6.1.7 — NO observer, NO repeated polling. Re-inject only on
		// the events that actually create/expose new finance grids.
		// Idempotent guard inside ensureAddButton() makes repeated calls
		// cheap.
		setTimeout(injectAddButtons, 400);
		var injectDeb = null;
		function injectDebounced(){
			if (injectDeb) clearTimeout(injectDeb);
			injectDeb = setTimeout(function(){
				try { injectAddButtons(); } catch(e){}
				injectDeb = null;
			}, 300);
		}
		document.addEventListener('click', function(e){
			if (e.target.closest('.cptt-expert-toggleProject, .cptt-expert-add-step')){
				injectDebounced();
			}
		});
	}

	/* ─── 9) Mobile sticky toolbar (true fixed-on-scroll) ─── */
	function initMobileStickyToolbar(){
		var bar = document.getElementById('cptt-views-toolbar');
		if (!bar) return;
		// placeholder
		var ph = bar.nextElementSibling;
		if (!ph || !ph.classList || !ph.classList.contains('cev-toolbar-placeholder')){
			ph = document.createElement('div');
			ph.className = 'cev-toolbar-placeholder';
			if (bar.parentNode) bar.parentNode.insertBefore(ph, bar.nextSibling);
		}

		function isMobile(){
			return !!(window.matchMedia && window.matchMedia('(max-width: 820px)').matches);
		}
		function getAdminBarH(){
			return document.body.classList.contains('admin-bar') ? 46 : 0;
		}
		function update(){
			if (!isMobile()){
				bar.classList.remove('is-stuck-mobile');
				ph.style.display = 'none';
				ph.style.height = '0';
				return;
			}
			var threshold = getAdminBarH();
			var stuck = bar.classList.contains('is-stuck-mobile');
			if (stuck){
				// check if we scrolled back above the placeholder
				var pRect = ph.getBoundingClientRect();
				if (pRect.top > threshold){
					bar.classList.remove('is-stuck-mobile');
					ph.style.display = 'none';
					ph.style.height = '0';
				}
				return;
			}
			var rect = bar.getBoundingClientRect();
			if (rect.top <= threshold){
				ph.style.display = 'block';
				ph.style.height = Math.max(0, Math.round(rect.height)) + 'px';
				bar.classList.add('is-stuck-mobile');
			}
		}
		var ticking = false;
		window.addEventListener('scroll', function(){
			if (ticking) return;
			ticking = true;
			requestAnimationFrame(function(){ update(); ticking = false; });
		}, { passive: true });
		window.addEventListener('resize', function(){
			if (bar.classList.contains('is-stuck-mobile')){
				bar.classList.remove('is-stuck-mobile');
				ph.style.display = 'none';
				ph.style.height = '0';
			}
			setTimeout(update, 60);
		}, { passive: true });
		setTimeout(update, 200);
	}

	/* =====================================================================
	 * v6.3.2 — Apply the dashboard filter/search ALSO to archived cards.
	 *
	 * The main filter (in expert-views.js) only walks `.cptt-expertCard`.
	 * Archived cards live in `.cptt-archive-card` blocks. Here we mirror
	 * the same filter logic onto those cards, AND auto-expand the
	 * "آرشیو پروژه‌ها" section when at least one archived match is found
	 * (so the user actually sees the result).
	 * ===================================================================== */
	function initArchiveFilterMirror(){
		var ids = ['cptt-expert-search','cptt-expert-status','cptt-expert-settled',
		           'cptt-expert-client','cptt-expert-product','cptt-expert-cat','cptt-expert-label'];

		function applyArchiveFilter(){
			var search = lower((document.getElementById('cptt-expert-search') || {}).value || '');
			var status = (document.getElementById('cptt-expert-status') || {}).value || '';
			var settled = (document.getElementById('cptt-expert-settled') || {}).value || '';
			var client = (document.getElementById('cptt-expert-client') || {}).value || '';
			var product = (document.getElementById('cptt-expert-product') || {}).value || '';
			var cat = (document.getElementById('cptt-expert-cat') || {}).value || '';
			var label = (document.getElementById('cptt-expert-label') || {}).value || '';

			var hasFilter = !!(search || status || settled || client || product || cat || label);
			var archCards = document.querySelectorAll('.cptt-archive-card');
			var matches = 0;
			for (var i = 0; i < archCards.length; i++){
				var c = archCards[i];
				var ok = true;
				if (search && (c.getAttribute('data-search') || '').indexOf(search) === -1) ok = false;
				if (ok && status && (c.getAttribute('data-status') || '') !== status) ok = false;
				if (ok && settled !== '' && (c.getAttribute('data-settled') || '') !== settled) ok = false;
				if (ok && client && (c.getAttribute('data-client') || '') !== client) ok = false;
				if (ok && product && (c.getAttribute('data-product') || '') !== product) ok = false;
				if (ok && cat && (c.getAttribute('data-cats') || '').indexOf(',' + cat + ',') === -1) ok = false;
				if (ok && label && (c.getAttribute('data-label') || '') !== label) ok = false;
				c.style.display = ok ? '' : 'none';
				if (ok) matches++;
			}
			// Auto-open archive section if filtering with matches
			var sec = document.getElementById('cptt-archive-section');
			var btn = document.getElementById('cptt-archive-toggle');
			var body = document.getElementById('cptt-archive-body');
			if (sec && btn && body){
				if (hasFilter && matches > 0){
					body.removeAttribute('hidden');
					sec.classList.add('is-open');
					btn.setAttribute('aria-expanded', 'true');
					// Update count badge to show "X از Y"
					var totalCnt = archCards.length;
					var badge = btn.querySelector('.cptt-archive-toggle__count');
					if (badge) {
						if (matches !== totalCnt){
							badge.textContent = toFa(matches) + ' / ' + toFa(totalCnt);
							badge.dataset.cpttFiltering = '1';
						}
					}
				} else {
					// Restore original badge count when no filter
					if (!hasFilter){
						var badge2 = btn.querySelector('.cptt-archive-toggle__count');
						if (badge2 && badge2.dataset.cpttFiltering === '1'){
							badge2.textContent = toFa(archCards.length);
							badge2.dataset.cpttFiltering = '0';
						}
					}
				}
			}
		}
		function lower(s){
			try { return String(s||'').toLowerCase(); } catch(_) { return ''; }
		}
		function toFa(n){
			var fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
			return String(n).replace(/[0-9]/g, function(d){ return fa[+d]; });
		}

		// Hook into each input/select with debounce so we don't fight the
		// main filter (which also re-renders the views).
		var deb = null;
		function schedule(){
			if (deb) clearTimeout(deb);
			deb = setTimeout(applyArchiveFilter, 120);
		}
		ids.forEach(function(id){
			var el = document.getElementById(id);
			if (!el) return;
			el.addEventListener('input',  schedule);
			el.addEventListener('change', schedule);
		});
		var resetBtn = document.getElementById('cptt-expert-reset');
		if (resetBtn) resetBtn.addEventListener('click', schedule);
		// Fire once on load (in case persisted filters are restored)
		setTimeout(applyArchiveFilter, 800);
	}

	/* =====================================================================
	 * v6.1.10 — Lazy-load the "manage project" form on first click.
	 *
	 * Previously every dashboard card pre-rendered the 385-line form +
	 * steps + notes + chat + summary. For an admin with 30-50 projects
	 * this meant ~20k lines of HTML and dozens of DB reads per page load,
	 * causing severe lag.
	 *
	 * Now the card just has an empty <div class="cptt-expertCard__details
	 * cptt-lazy-details" data-loaded="0"> placeholder. The first time the
	 * user clicks "مدیریت پروژه" on that card, we fetch the form HTML
	 * via AJAX `cptt_expert_load_manage_form` and inject it. Subsequent
	 * clicks just show/hide the cached content.
	 * ===================================================================== */
	function initLazyManageForm(){
		document.addEventListener('click', function(e){
			var btn = e.target.closest('.cptt-expert-toggleProject');
			if (!btn) return;
			var card = btn.closest('.cptt-expertCard');
			if (!card) return;
			var details = card.querySelector('.cptt-expertCard__details.cptt-lazy-details');
			if (!details) return;
			if (details.dataset.loaded === '1') return;        // already loaded → let expert.js toggle normally
			if (details.dataset.loading === '1') return;       // request already in-flight
			var pid = details.dataset.projectId || '';
			if (!pid) return;

			// Let expert.js do its normal "open card" flow — it will reveal
			// the placeholder ("در حال بارگذاری اطلاعات پروژه..."). We just
			// fetch the real content in parallel and inject it when it arrives.
			details.dataset.loading = '1';

			ajaxPost('cptt_expert_load_manage_form', { project_id: pid }, function(r){
				details.dataset.loading = '0';
				if (r && r.success && r.data && r.data.html){
					details.innerHTML = r.data.html;
					details.dataset.loaded = '1';
					try {
						card.dispatchEvent(new CustomEvent('cptt:manage-form-loaded', { bubbles: true, detail: { projectId: pid } }));
					} catch(err){}
					// Re-trigger the various expert.js initializers that rely
					// on the toggle click. Easiest: click the button again
					// (it's now a no-op for show/hide because we'll re-set it).
					setTimeout(function(){
						// Ensure card stays open with new content visible
						details.hidden = false;
						card.classList.add('is-expanded');
						btn.textContent = 'بستن مدیریت';
						// v6.2.1 — CRITICAL: re-bind the save form's submit
						// handler. Without this, the "save" button submits
						// the form to the page URL (browser default).
						try { if (typeof window.bindSaveForms === 'function') window.bindSaveForms(); } catch(_){}
						// Best-effort: poke known global helpers if exposed.
						try { if (typeof window.layoutAllFinance === 'function') window.layoutAllFinance(); } catch(_){}
						try { if (typeof window.enhanceManageFinancialFields === 'function') window.enhanceManageFinancialFields(card); } catch(_){}
						try { if (typeof window.hardFloatingSave === 'function') window.hardFloatingSave(); } catch(_){}
						try { if (typeof window.bindStepAccordions === 'function') window.bindStepAccordions(card); } catch(_){}
						try { if (typeof window.bindProjectToggles === 'function') window.bindProjectToggles(); } catch(_){}
						// Dispatch generic event so other modules can re-init.
						try {
							document.dispatchEvent(new Event('cptt:relayout'));
						} catch(_){}
						// Re-run our own injectors for the new step rows.
						try { injectAddButtons(); } catch(_){}
					}, 30);
				} else {
					details.innerHTML = '<div style="padding:24px;text-align:center;color:#dc2626;font-size:13px;">خطا در بارگذاری فرم مدیریت پروژه</div>';
				}
			});
		});
	}

	/* ─── Observer with debounce + idempotent guards ─── */
	function observe(){
		var targets = [
			document.getElementById('cptt-expert-grid'),
			document.getElementById('cptt-list-view'),
			document.getElementById('cptt-timeline-view'),
			document.getElementById('cptt-gantt-view'),
			document.getElementById('cptt-calendar-view'),
			document.getElementById('cptt-view-container')
		];
		if (!('MutationObserver' in window)) return;
		// v6.1.7 — debounce heavy work so we don't run 5 functions on every
		// single DOM mutation (which lagged the page when expert-views.js
		// re-rendered the views).
		var debTimer = null;
		var refresh = function(){
			if (debTimer) clearTimeout(debTimer);
			debTimer = setTimeout(function(){
				try {
					tagAllCards();
					addCreatedChipToCards();
					addCreatedToListView();
					addCreatedToTimeline();
					addCreatedToGantt();
				} catch(e) { /* swallow */ }
				debTimer = null;
			}, 250);
		};
		var mo = new MutationObserver(refresh);
		for (var i = 0; i < targets.length; i++){
			if (targets[i]) mo.observe(targets[i], { childList: true, subtree: false });
			// NOTE: subtree:false → only direct children → far fewer fires
		}
	}

	ready(function(){
		// v6.1.7 — run cleanLegacyFixed ONCE, not on every scroll/resize
		cleanLegacyFixed();
		setTimeout(cleanLegacyFixed, 400);
		setTimeout(cleanLegacyFixed, 1500);

		tagAllCards();
		addCreatedChipToCards();
		setTimeout(function(){
			addCreatedToListView();
			addCreatedToTimeline();
			addCreatedToGantt();
		}, 600);
		observe();
		initArchiveToggle();
		bindArchiveButtons();
		bindMessengerButtons();
		bindExtraFinanceButtons();
		initMobileStickyToolbar();
		initLazyManageForm();
		initArchiveFilterMirror();

		document.addEventListener('click', function(e){
			if (e.target.closest('.cev-view-btn') || e.target.closest('.cev-tl-mode-btn')){
				setTimeout(function(){
					tagAllCards();
					addCreatedChipToCards();
					addCreatedToListView();
					addCreatedToTimeline();
					addCreatedToGantt();
				}, 350);
			}
		});
		var sortSel = document.getElementById('cptt-sort-select');
		if (sortSel) sortSel.addEventListener('change', function(){
			setTimeout(function(){
				addCreatedToListView();
				addCreatedToTimeline();
				addCreatedToGantt();
			}, 350);
		});
	});
})();
