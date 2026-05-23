(function () {
  'use strict';

  function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, function (m) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
    });
  }

  /* =========================================================
     NOTIFICATION POLLING
     ========================================================= */
  function pollNotifications() {
    if (!qs('.cptt-notification-bell')) return;
    var fd = new FormData();
    fd.append('action', 'cptt_expert_fetch_notifications');
    fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
    fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', {
      method: 'POST', credentials: 'same-origin', body: fd
    }).then(function(r){ return r.json(); }).then(function(json){
      if (!json || !json.success) return;
      var data = json.data || {};
      var badge = qs('.cptt-bell-badge');
      var list = qs('.cptt-notifications-list');
      if (badge) {
        if (data.unread > 0) { badge.textContent = data.unread; badge.style.display = 'flex'; }
        else { badge.style.display = 'none'; }
      }
      if (list && data.html) {
        list.innerHTML = data.html;
        bindNotificationItems();
      }
    }).catch(function(e){ console.error(e); });
  }

  function bindNotificationItems() {
    qsa('.cptt-notification-item').forEach(function(el){
      if (el.dataset.bound) return;
      el.dataset.bound = '1';
      el.addEventListener('click', function(e){
        if (el.classList.contains('is-read')) return;
        el.classList.add('is-read');
      });
    });
  }

  /* =========================================================
     DARK MODE
     ========================================================= */
  function initDarkMode() {
    var saved = localStorage.getItem('cptt_dark_mode');
    if (saved === '1') document.body.classList.add('cptt-dark');
    var container = qs('.cptt-expertSidebar');
    if (!container) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'cptt-btn cptt-btn--secondary cptt-dark-toggle';
    btn.style.cssText = 'width:100%;margin-top:8px;';
    btn.textContent = document.body.classList.contains('cptt-dark') ? '☀️ حالت روشن' : '🌙 حالت تاریک';
    btn.addEventListener('click', function(){
      document.body.classList.toggle('cptt-dark');
      var on = document.body.classList.contains('cptt-dark');
      localStorage.setItem('cptt_dark_mode', on ? '1' : '0');
      btn.textContent = on ? '☀️ حالت روشن' : '🌙 حالت تاریک';
    });
    container.appendChild(btn);
  }

  /* =========================================================
     JALALI DATE PICKER (FIXED)
     ========================================================= */
  function initJalaliPicker() {
    function toFa(str) { return String(str || '').replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
    function toEn(str) { var fa = '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', en = '01234567890123456789'; return String(str || '').replace(/[۰-۹٠-٩]/g, function (ch) { return en[fa.indexOf(ch)] || ch; }); }
    function g2j(gy, gm, gd) {
      var gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
      var gy2 = (gm > 2) ? gy + 1 : gy;
      var days = 355666 + (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + gdm[gm - 1];
      var jy = -1595 + 33 * Math.floor(days / 12053); days %= 12053;
      jy += 4 * Math.floor(days / 1461); days %= 1461;
      if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
      var jm, jd;
      if (days < 186) { jm = 1 + Math.floor(days / 31); jd = 1 + (days % 31); }
      else { jm = 7 + Math.floor((days - 186) / 30); jd = 1 + ((days - 186) % 30); }
      return [jy, jm, jd];
    }
    function j2g(jy, jm, jd) {
      jy = parseInt(jy, 10) + 1595;
      var days = -355668 + (365 * jy) + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4) + parseInt(jd, 10);
      days += (jm < 7) ? ((jm - 1) * 31) : (((jm - 7) * 30) + 186);
      var gy = 400 * Math.floor(days / 146097); days %= 146097;
      if (days > 36524) { gy += 100 * Math.floor(--days / 36524); days %= 36524; if (days >= 365) days++; }
      gy += 4 * Math.floor(days / 1461); days %= 1461;
      if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
      var gd = days + 1;
      var sal = [0,31,(((gy % 4 === 0) && (gy % 100 !== 0)) || (gy % 400 === 0)) ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
      var gm = 1; for (; gm <= 12; gm++) { if (gd <= sal[gm]) break; gd -= sal[gm]; }
      return [gy, gm, gd];
    }
    function monthLen(jy, jm) { return jm <= 6 ? 31 : (jm <= 11 ? 30 : 30); }
    var monthNames = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    var activeInput = null, viewJ = null;
    var cal = document.createElement('div');
    cal.className = 'cptt-jdp';
    cal.dir = 'rtl';
    cal.style.display = 'none';
    document.body.appendChild(cal);
    function draw() {
      if (!activeInput || !viewJ) return;
      var ml = monthLen(viewJ.jy, viewJ.jm);
      var g = j2g(viewJ.jy, viewJ.jm, 1);
      var first = new Date(g[0], g[1] - 1, g[2]).getDay();
      var start = (first + 1) % 7;
      var now = new Date(); var tj = g2j(now.getFullYear(), now.getMonth() + 1, now.getDate());
      var html = '<div class="cptt-jdp__head"><button type="button" data-nav="prev">‹</button><strong>' + monthNames[viewJ.jm - 1] + ' ' + toFa(viewJ.jy) + '</strong><button type="button" data-nav="next">›</button></div>';
      html += '<div class="cptt-jdp__week"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div><div class="cptt-jdp__days">';
      for (var i = 0; i < start; i++) html += '<span></span>';
      for (var d = 1; d <= ml; d++) {
        var cls = '';
        if (viewJ.jd === d) cls += ' is-selected';
        if (tj[0] === viewJ.jy && tj[1] === viewJ.jm && tj[2] === d) cls += ' is-today';
        html += '<button type="button" class="' + cls.trim() + '" data-day="' + d + '">' + toFa(d) + '</button>';
      }
      html += '</div><div class="cptt-jdp__time"><input type="number" min="0" max="23" value="' + String(viewJ.hh || 12).padStart(2, '0') + '"><span>:</span><input type="number" min="0" max="59" value="' + String(viewJ.ii || 0).padStart(2, '0') + '"></div><div class="cptt-jdp__foot"><button type="button" data-today="1">امروز</button><button type="button" data-close="1">بستن</button></div>';
      cal.innerHTML = html;
    }
    function open(input) {
      activeInput = input;
      var m = String(input.value || '').match(/(\d{4})\/(\d{1,2})\/(\d{1,2})(?:\s+(\d{1,2}):(\d{1,2}))?/);
      if (m) { viewJ = { jy: +toEn(m[1]), jm: +toEn(m[2]), jd: +toEn(m[3]), hh: m[4] ? +toEn(m[4]) : 12, ii: m[5] ? +toEn(m[5]) : 0 }; }
      else { var n = new Date(); var j = g2j(n.getFullYear(), n.getMonth() + 1, n.getDate()); viewJ = { jy: j[0], jm: j[1], jd: j[2], hh: n.getHours(), ii: n.getMinutes() }; }
      draw();
      var r = input.getBoundingClientRect();
      cal.style.top = (window.scrollY + r.bottom + 6) + 'px';
      cal.style.left = (window.scrollX + r.left) + 'px';
      cal.style.display = 'block';
    }
    function setDate(day) {
      var inputs = cal.querySelectorAll('.cptt-jdp__time input');
      var hh = Math.max(0, Math.min(23, parseInt(inputs[0].value || '0', 10)));
      var ii = Math.max(0, Math.min(59, parseInt(inputs[1].value || '0', 10)));
      viewJ.jd = day; viewJ.hh = hh; viewJ.ii = ii;
      activeInput.value = toFa(viewJ.jy + '/' + String(viewJ.jm).padStart(2, '0') + '/' + String(day).padStart(2, '0') + ' ' + String(hh).padStart(2, '0') + ':' + String(ii).padStart(2, '0'));
      cal.style.display = 'none';
    }
    document.addEventListener('click', function (e) {
      var input = e.target.closest('.cptt-jalali-datetime');
      if (input) { open(input); return; }
      if (!cal.contains(e.target)) { cal.style.display = 'none'; }
    });
    cal.addEventListener('click', function (e) {
      // FIX: e.target may be a text node inside button (e.g., ‹ ›)
      var target = (e.target.nodeType === 3) ? e.target.parentElement : e.target;
      var nav = target.closest ? target.closest('[data-nav]') : null;
      if (nav) { 
        viewJ.jm += nav.getAttribute('data-nav') === 'next' ? 1 : -1; 
        if (viewJ.jm > 12) { viewJ.jm = 1; viewJ.jy++; } 
        if (viewJ.jm < 1) { viewJ.jm = 12; viewJ.jy--; } 
        draw(); return; 
      }
      var dayEl = target.closest ? target.closest('[data-day]') : null;
      if (dayEl) { setDate(parseInt(dayEl.dataset.day, 10)); return; }
      if (target.closest && target.closest('[data-close]')) { cal.style.display = 'none'; return; }
      if (target.closest && target.closest('[data-today]')) { 
        var n = new Date(); var j = g2j(n.getFullYear(), n.getMonth() + 1, n.getDate()); 
        viewJ = { jy: j[0], jm: j[1], jd: j[2], hh: n.getHours(), ii: n.getMinutes() }; 
        setDate(j[2]); return; 
      }
    });
  }

  /* =========================================================
     1. FILTERS - main project grid
     ========================================================= */
  function updateVisibility() {
    var search = (qs('#cptt-expert-search') || {}).value ? qs('#cptt-expert-search').value.toLowerCase().trim() : '';
    var status = (qs('#cptt-expert-status') || {}).value || '';
    var settled = (qs('#cptt-expert-settled') || {}).value || '';
    var client = (qs('#cptt-expert-client') || {}).value || '';
    var product = (qs('#cptt-expert-product') || {}).value || '';
    var cat = (qs('#cptt-expert-cat') || {}).value || '';
    var visible = 0;
    qsa('.cptt-expertCard').forEach(function (card) {
      var ok = true;
      var dataSearch = String(card.getAttribute('data-search') || '').toLowerCase();
      var dataStatus = String(card.getAttribute('data-status') || '');
      var dataSettled = String(card.getAttribute('data-settled') || '');
      var dataClient = String(card.getAttribute('data-client') || '');
      var dataProduct = String(card.getAttribute('data-product') || '');
      var dataCats = String(card.getAttribute('data-cats') || '');
      if (search && dataSearch.indexOf(search) === -1) ok = false;
      if (status && dataStatus !== status) ok = false;
      if (settled !== '' && dataSettled !== settled) ok = false;
      if (client && dataClient !== client) ok = false;
      if (product && dataProduct !== product) ok = false;
      if (cat && dataCats.indexOf(',' + cat + ',') === -1) ok = false;
      card.hidden = !ok;
      card.style.display = ok ? '' : 'none';
      if (ok) visible++;
    });
    var empty = qs('#cptt-expert-empty');
    if (empty) empty.hidden = visible !== 0;
  }

  /* =========================================================
     2. PROJECT MANAGEMENT TOGGLE
     ========================================================= */
  function bindProjectToggles() {
    qsa('.cptt-expert-toggleProject').forEach(function (btn) {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        var card = btn.closest('.cptt-expertCard');
        if (!card) return;
        var details = qs('.cptt-expertCard__details', card);
        if (!details) return;
        var isOpen = !details.hidden;
        qsa('.cptt-expertCard').forEach(function (c) {
          var d = qs('.cptt-expertCard__details', c);
          var b = qs('.cptt-expert-toggleProject', c);
          if (d) d.hidden = true;
          c.classList.remove('is-expanded');
          if (b) b.textContent = 'مدیریت پروژه';
        });
        if (!isOpen) {
          details.hidden = false;
          card.classList.add('is-expanded');
          btn.textContent = 'بستن مدیریت';
          setTimeout(function () { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
          bindStepAccordions(card);
        }
      });
    });
  }

  /* =========================================================
     3. STEPS ACCORDION
     ========================================================= */
  function bindStepAccordions(scope) {
    qsa('.cptt-expert-step', scope || document).forEach(function (step) {
      var toggle = qs('.cptt-expert-step__toggle', step);
      var body = qs('.cptt-expert-step__body', step);
      if (!toggle || !body) return;
      if (toggle.dataset.bound) return;
      toggle.dataset.bound = '1';
      var isExpanded = toggle.getAttribute('aria-expanded') === 'true';
      if (isExpanded) { step.classList.add('is-open'); body.hidden = false; }
      else { step.classList.remove('is-open'); body.hidden = true; }
      toggle.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        var stepsContainer = step.parentElement;
        var willOpen = body.hidden;
        if (stepsContainer) {
          qsa('.cptt-expert-step', stepsContainer).forEach(function (other) {
            if (other === step) return;
            var ob = qs('.cptt-expert-step__body', other);
            var ot = qs('.cptt-expert-step__toggle', other);
            if (ob) ob.hidden = true;
            if (ot) ot.setAttribute('aria-expanded', 'false');
            other.classList.remove('is-open');
          });
        }
        if (willOpen) { body.hidden = false; step.classList.add('is-open'); toggle.setAttribute('aria-expanded', 'true'); }
        else { body.hidden = true; step.classList.remove('is-open'); toggle.setAttribute('aria-expanded', 'false'); }
      });
    });
  }

  /* =========================================================
     4. SUMMARY APPLY after save
     ========================================================= */
  function applySummary(card, data) {
    if (!card || !data || !data.progress) return;
    var bar = qs('.cptt-expertCard__progress span', card);
    if (bar) bar.style.width = Math.max(0, Math.min(100, parseInt(data.progress.percent || 0, 10))) + '%';
    var badge = qs('.cptt-expertStatusBadge', card);
    if (badge) {
      badge.textContent = data.progress.label || 'در حال انجام';
      badge.className = 'cptt-expertStatusBadge cptt-expertStatusBadge--' + (data.progress.status || 'in_progress');
    }
    var last = qs('.cptt-expert-last-update', card);
    if (last) last.textContent = data.last_update_fa || '—';
    var stat = qsa('.cptt-expertCard__stats strong', card);
    if (stat[0]) stat[0].textContent = (data.progress.percent || 0) + '%';
  }

  /* =========================================================
     5. CREATE PROJECT FORM
     ========================================================= */
  function bindCreateForm() {
    qsa('.cptt-expert-create-form').forEach(function (form) {
      if (form.dataset.bound) return;
      form.dataset.bound = '1';
      form.addEventListener('submit', async function (e) {
        e.preventDefault();
        var msg = qs('.cptt-expert-formMsg', form);
        var btn = qs('button[type="submit"]', form);
        if (msg) msg.textContent = '';
        if (btn) { btn.disabled = true; btn.textContent = 'در حال ایجاد...'; }
        try {
          var fd = new FormData(form);
          fd.append('action', 'cptt_expert_create_project');
          fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
          var res = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', {
            method: 'POST', credentials: 'same-origin', body: fd
          });
          var json = await res.json();
          if (!json || !json.success) throw new Error((json && json.data) ? json.data : 'خطا در ایجاد پروژه');
          if (msg) msg.textContent = 'پروژه با موفقیت ایجاد شد.';
          window.setTimeout(function () { window.location.href = (json.data && json.data.redirect) ? json.data.redirect : window.location.href; }, 500);
        } catch (err) {
          if (msg) msg.textContent = err.message || 'خطا در ایجاد پروژه';
        } finally {
          if (btn) { btn.disabled = false; btn.textContent = 'ایجاد پروژه'; }
        }
      });
    });
  }

  /* =========================================================
     6. SAVE PROJECT FORM
     ========================================================= */
  function bindSaveForms() {
    qsa('.cptt-expert-project-form').forEach(function (form) {
      if (form.dataset.bound) return;
      form.dataset.bound = '1';
      form.addEventListener('submit', async function (e) {
        e.preventDefault();
        var msg = qs('.cptt-expert-formMsg', form);
        var btn = qs('button[type="submit"]', form);
        if (msg) { msg.textContent = ''; msg.style.color = ''; }
        if (btn) { btn.disabled = true; btn.textContent = (window.CPTT_EXPERT && CPTT_EXPERT.texts && CPTT_EXPERT.texts.saving) || 'در حال ذخیره...'; }
        try {
          var fd = new FormData(form);
          fd.append('action', 'cptt_expert_save_project');
          fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
          var res = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', {
            method: 'POST', credentials: 'same-origin', body: fd
          });
          var json = await res.json();
          if (!json || !json.success) throw new Error((window.CPTT_EXPERT && CPTT_EXPERT.texts && CPTT_EXPERT.texts.error) || 'خطا در ذخیره اطلاعات');
          if (msg) { msg.style.color = '#047857'; msg.textContent = (window.CPTT_EXPERT && CPTT_EXPERT.texts && CPTT_EXPERT.texts.saved) || 'تغییرات با موفقیت ذخیره شد.'; }
          var card = form.closest('.cptt-expertCard');
          applySummary(card, json.data || {});
          var textarea = qs('textarea[name="note"]', form);
          if (textarea) textarea.value = '';
          window.setTimeout(function () { window.location.reload(); }, 700);
        } catch (err) {
          if (msg) { msg.style.color = '#dc2626'; msg.textContent = err.message || 'خطا در ذخیره اطلاعات'; }
        } finally {
          if (btn) { btn.disabled = false; btn.textContent = 'ذخیره تغییرات'; }
        }
      });
    });
  }

  /* =========================================================
     7. CHAT BETWEEN EXPERTS (PROJECT) — BUBBLES + FILE VIEW
     ========================================================= */
  function renderMessages(items, container, myUserId) {
    if (!Array.isArray(items) || !items.length) {
      container.innerHTML = '<div class="cptt-expert-emptyMini">پیامی ثبت نشده است.</div>';
      return;
    }
    var html = items.map(function (message) {
      var isMe = parseInt(message.sender_id, 10) === parseInt(myUserId || 0, 10);
      var head = escapeHtml((message.sender_name || 'کاربر') + (message.recipient_name && message.recipient_name !== 'همه' ? ' → ' + message.recipient_name : ''));
      var time = escapeHtml(message.time_fa || '');
      var body = escapeHtml(message.content || '').replace(/\n/g, '<br>');
      // Convert file links to view buttons
      body = body.replace(/<a href="([^"]+)"[^>]*class="cptt-chat-file-link"[^>]*>[^<]*<\/a>/g, '<a href="$1" target="_blank" class="cptt-chat-file-btn">👁 مشاهده فایل</a>');
      var cls = isMe ? 'cptt-chat-bubble--me' : 'cptt-chat-bubble--other';
      return '<div class="cptt-chat-bubble ' + cls + '"><div class="cptt-chat-bubble__head"><strong>' + head + '</strong><span>' + time + '</span></div><div class="cptt-chat-bubble__body">' + body + '</div></div>';
    }).join('');
    container.innerHTML = html;
    container.scrollTop = container.scrollHeight;
  }

  function bindMessageForms() {
    qsa('.cptt-expert-message-form').forEach(function (form) {
      if (form.dataset.bound) return;
      form.dataset.bound = '1';
      form.addEventListener('submit', async function (e) {
        e.preventDefault();
        var msg = qs('.cptt-expert-formMsg', form);
        var btn = qs('button[type="submit"]', form);
        if (msg) msg.textContent = '';
        if (btn) { btn.disabled = true; btn.textContent = 'در حال ارسال...'; }
        try {
          var fd = new FormData(form);
          fd.append('action', 'cptt_expert_send_message');
          fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
          var res = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', { method: 'POST', credentials: 'same-origin', body: fd });
          var json = await res.json();
          if (!json || !json.success) throw new Error((json && json.data) ? json.data : 'خطا در ارسال پیام');
          if (msg) msg.textContent = 'پیام ارسال شد.';
          var ta = qs('textarea[name="content"]', form); if (ta) ta.value = '';
          var wrap = form.parentElement.querySelector('.cptt-expert-messagesWrap');
          var myId = (window.wp_user_id || 0); // try to infer; fallback works with CSS
          if (wrap) renderMessages((json.data && json.data.messages) || [], wrap, myId);
        } catch (err) {
          if (msg) msg.textContent = err.message || 'خطا در ارسال پیام';
        } finally {
          if (btn) { btn.disabled = false; btn.textContent = 'ارسال پیام'; }
        }
      });
    });
  }

  async function refreshMessages(form, myUserId) {
    if (!form) return;
    var projectId = form.getAttribute('data-project-id') || '';
    var wrap = form.parentElement.querySelector('.cptt-expert-messagesWrap');
    if (!projectId || !wrap) return;
    var fd = new FormData();
    fd.append('action', 'cptt_expert_fetch_messages');
    fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
    fd.append('project_id', projectId);
    var res = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', { method: 'POST', credentials: 'same-origin', body: fd });
    var json = await res.json();
    if (json && json.success && wrap) renderMessages((json.data && json.data.messages) || [], wrap, myUserId);
  }

  function bindChatModals() {
    qsa('.cptt-expert-chatModal').forEach(function (modal) {
      if (modal.dataset.bound) return;
      modal.dataset.bound = '1';
      var card = modal.closest('.cptt-expertCard');
      var openBtn = card ? qs('.cptt-expert-chat-launch', card) : null;
      var closeBtn = qs('.cptt-expert-chatModal__close', modal);
      var backdrop = qs('.cptt-expert-chatModal__backdrop', modal);
      var form = qs('.cptt-expert-message-form', modal);
      var timer = null;
      function close() { modal.hidden = true; if (timer) { window.clearInterval(timer); timer = null; } }
      async function open() { modal.hidden = false; await refreshMessages(form); if (timer) window.clearInterval(timer); timer = window.setInterval(function () { refreshMessages(form); }, 8000); }
      if (openBtn) openBtn.addEventListener('click', open);
      if (closeBtn) closeBtn.addEventListener('click', close);
      if (backdrop) backdrop.addEventListener('click', close);
    });
  }

  /* =========================================================
     8. DIRECT CHAT — BUBBLES + FILE VIEW
     ========================================================= */
  function renderDirectMessages(items, myUserId) {
    var wrap = qs('#direct-chat-messages-container');
    if (!wrap) return;
    if (!Array.isArray(items) || !items.length) {
      wrap.innerHTML = '<div class="cptt-expert-emptyMini">پیامی وجود ندارد.</div>';
      return;
    }
    var html = items.map(function (m) {
      var isMe = parseInt(m.sender_id, 10) === parseInt(myUserId || 0, 10);
      var time = escapeHtml(m.time_fa || '');
      var body = escapeHtml(m.content || '').replace(/\n/g, '<br>');
      body = body.replace(/<a href="([^"]+)"[^>]*class="cptt-chat-file-link"[^>]*>[^<]*<\/a>/g, '<a href="$1" target="_blank" class="cptt-chat-file-btn">👁 مشاهده فایل</a>');
      var cls = isMe ? 'cptt-chat-bubble--me' : 'cptt-chat-bubble--other';
      return '<div class="cptt-chat-bubble ' + cls + '"><div class="cptt-chat-bubble__head"><strong>' + escapeHtml(m.sender_name || 'کاربر') + '</strong><span>' + time + '</span></div><div class="cptt-chat-bubble__body">' + body + '</div></div>';
    }).join('');
    wrap.innerHTML = html;
    wrap.scrollTop = wrap.scrollHeight;
  }

  /* =========================================================
     9. NEW PROJECT MODAL
     ========================================================= */
  function bindNewProjectModal() {
    var modal = qs('#cptt-new-project-modal');
    if (!modal) return;
    var openers = qsa('.cptt-newProjectCta, [data-cptt-open-newproject]');
    var closeBtns = qsa('.cptt-newProjectModal__close, [data-cptt-close-newproject]', modal);
    var backdrop = qs('.cptt-newProjectModal__backdrop', modal);
    function open() { modal.classList.add('is-open'); document.body.style.overflow = 'hidden'; }
    function close() { modal.classList.remove('is-open'); document.body.style.overflow = ''; }
    openers.forEach(function (btn) {
      if (btn.dataset.npmBound) return;
      btn.dataset.npmBound = '1';
      btn.addEventListener('click', function (e) { e.preventDefault(); open(); });
    });
    closeBtns.forEach(function (b) {
      if (b.dataset.npmBound) return;
      b.dataset.npmBound = '1';
      b.addEventListener('click', function (e) { e.preventDefault(); close(); });
    });
    if (backdrop) backdrop.addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('is-open')) close(); });
  }

  /* =========================================================
     10. DELETE PROJECT / DELETE STEP
     ========================================================= */
  function bindDeleteActions() {
    document.addEventListener('click', async function (e) {
      var btn = e.target.closest('.cptt-expert-delete-project');
      if (btn) {
        e.preventDefault();
        if (!confirm('آیا از حذف این پروژه اطمینان دارید؟ این عمل غیرقابل بازگشت است.')) return;
        var projectId = btn.getAttribute('data-project-id');
        try {
          var fd = new FormData();
          fd.append('action', 'cptt_expert_delete_project');
          fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
          fd.append('project_id', projectId);
          var res = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', { method: 'POST', credentials: 'same-origin', body: fd });
          var json = await res.json();
          if (json && json.success) {
            var card = btn.closest('.cptt-expertCard');
            if (card) { card.style.opacity = '0'; setTimeout(function(){ card.remove(); updateVisibility(); }, 300); }
          } else {
            alert((json && json.data) ? json.data : 'خطا در حذف پروژه');
          }
        } catch (err) { alert('خطا در ارتباط'); }
        return;
      }
      var sbtn = e.target.closest('.cptt-expert-remove-step');
      if (sbtn) {
        var stepEl = sbtn.closest('.cptt-expert-step');
        if (!stepEl) return;
        var stepId = stepEl.getAttribute('data-step-id');
        var form = sbtn.closest('.cptt-expert-project-form');
        var projectId = form ? form.getAttribute('data-project-id') : '';
        if (projectId && stepId && confirm('این مرحله حذف شود؟')) {
          try {
            var fd2 = new FormData();
            fd2.append('action', 'cptt_expert_delete_step');
            fd2.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
            fd2.append('project_id', projectId);
            fd2.append('step_id', stepId);
            var res2 = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', { method: 'POST', credentials: 'same-origin', body: fd2 });
            var json2 = await res2.json();
            if (json2 && json2.success) { stepEl.remove(); }
            else { alert((json2 && json2.data) ? json2.data : 'خطا در حذف مرحله'); }
          } catch (err) { alert('خطا در ارتباط'); }
        } else {
          stepEl.remove();
        }
      }
    });
  }

  /* =========================================================
     11. KANBAN BOARD
     ========================================================= */
  function initKanban() {
    var dataEl = qs('#cptt-kanban-data');
    if (!dataEl) return;
    var steps = [];
    try {
      var raw = dataEl.getAttribute('data-kanban');
      var bin = atob(raw);
      var bytes = Uint8Array.from(bin, function (c) { return c.charCodeAt(0); });
      var txt = window.TextDecoder ? new TextDecoder('utf-8').decode(bytes) : decodeURIComponent(escape(bin));
      steps = JSON.parse(txt);
    } catch (e) { console.error(e); return; }
    if (!Array.isArray(steps)) return;

    var grid = qs('#cptt-expert-grid');
    var kanbanWrap = qs('#cptt-kanban-board');
    if (!grid || !kanbanWrap) return;

    function buildKanban() {
      var cols = { todo: [], current: [], done: [] };
      steps.forEach(function (s) { cols[s.status || 'todo'].push(s); });
      var html = '<div class="cptt-kanban">' +
        '<div class="cptt-kanban__col" data-status="todo"><div class="cptt-kanban__head">🔵 انجام‌نشده</div><div class="cptt-kanban__dropzone">' + renderCol(cols.todo) + '</div></div>' +
        '<div class="cptt-kanban__col" data-status="current"><div class="cptt-kanban__head">🟡 در حال انجام</div><div class="cptt-kanban__dropzone">' + renderCol(cols.current) + '</div></div>' +
        '<div class="cptt-kanban__col" data-status="done"><div class="cptt-kanban__head">🟢 انجام‌شده</div><div class="cptt-kanban__dropzone">' + renderCol(cols.done) + '</div></div>' +
        '</div>';
      kanbanWrap.innerHTML = html;
      initDnD();
    }
    function renderCol(items) {
      return items.map(function (s) {
        return '<div class="cptt-kanban__card" draggable="true" data-step-id="' + escapeHtml(s.step_id) + '" data-project-id="' + escapeHtml(s.project_id) + '">' +
          '<div class="cptt-kanban__cardTitle">' + escapeHtml(s.title || 'بدون عنوان') + '</div>' +
          '<div class="cptt-kanban__cardMeta">' + escapeHtml(s.project_title || '') + '</div>' +
          '</div>';
      }).join('');
    }
    function initDnD() {
      var dragCard = null;
      qsa('.cptt-kanban__card').forEach(function (card) {
        card.addEventListener('dragstart', function (e) { dragCard = card; e.dataTransfer.effectAllowed = 'move'; card.classList.add('is-dragging'); });
        card.addEventListener('dragend', function () { card.classList.remove('is-dragging'); dragCard = null; });
      });
      qsa('.cptt-kanban__dropzone').forEach(function (zone) {
        zone.addEventListener('dragover', function (e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; });
        zone.addEventListener('drop', async function (e) {
          e.preventDefault();
          if (!dragCard) return;
          zone.appendChild(dragCard);
          var newStatus = zone.closest('.cptt-kanban__col').getAttribute('data-status');
          var stepId = dragCard.getAttribute('data-step-id');
          var projectId = dragCard.getAttribute('data-project-id');
          // update data model
          steps.forEach(function (s) { if (s.step_id === stepId) s.status = newStatus; });
          // persist via quick AJAX
          try {
            var fd = new FormData();
            fd.append('action', 'cptt_expert_save_project');
            fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
            fd.append('project_id', projectId);
            fd.append('steps[' + stepId + '][status]', newStatus);
            await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', { method: 'POST', credentials: 'same-origin', body: fd });
          } catch (err) { console.error(err); }
        });
      });
    }
    buildKanban();

    var toggle = qs('#cptt-kanban-toggle');
    if (toggle) {
      toggle.addEventListener('click', function () {
        var show = kanbanWrap.hidden;
        kanbanWrap.hidden = !show;
        grid.hidden = show;
        toggle.textContent = show ? '📋 نمایش لیست' : '📌 نمایش Kanban';
        if (show) buildKanban();
      });
    }
  }

  /* =========================================================
     12. HUB (public showcase)
     ========================================================= */
  function decodeB64Json(b64) {
    try {
      var bin = atob(String(b64 || ''));
      var bytes = Uint8Array.from(bin, function (c) { return c.charCodeAt(0); });
      var txt = window.TextDecoder ? new TextDecoder('utf-8').decode(bytes) : decodeURIComponent(escape(bin));
      return JSON.parse(txt);
    } catch (e) { return null; }
  }

  function hubModal() { return qs('#cptt-hub-modal'); }
  function closeHubModal() { var modal = hubModal(); if (!modal) return; modal.hidden = true; }
  function openHubModal(title, metaHtml, bodyHtml) {
    var modal = hubModal();
    if (!modal) return;
    var titleEl = qs('#cptt-hub-modal-title', modal);
    var metaEl = qs('#cptt-hub-modal-meta', modal);
    var bodyEl = qs('#cptt-hub-modal-body', modal);
    if (titleEl) titleEl.textContent = title || '';
    if (metaEl) metaEl.innerHTML = metaHtml || '';
    if (bodyEl) bodyEl.innerHTML = bodyHtml || '';
    modal.hidden = false;
  }

  function renderHubProject(project) {
    if (!project) return;
    var chips = [];
    if (project.start_fa) chips.push('<span class="cptt-hubMetaChip">تاریخ شروع: ' + escapeHtml(project.start_fa) + '</span>');
    if (project.last_update) chips.push('<span class="cptt-hubMetaChip">آخرین بروزرسانی: ' + escapeHtml(project.last_update) + '</span>');
    if (project.deadline) chips.push('<span class="cptt-hubMetaChip">مهلت: ' + escapeHtml(project.deadline) + '</span>');
    if (Array.isArray(project.experts) && project.experts.length) chips.push('<span class="cptt-hubMetaChip">کارشناسان: ' + escapeHtml(project.experts.join('، ')) + '</span>');
    if (project.full_details && project.customer) chips.push('<span class="cptt-hubMetaChip">مشتری: ' + escapeHtml(project.customer) + '</span>');
    var body = '<div class="cptt-hubModal__stats">' +
      '<div><strong>' + escapeHtml(String((project.progress && project.progress.percent) || 0)) + '%</strong><span>پیشرفت پروژه</span></div>' +
      '<div><strong>' + escapeHtml(String((project.progress && project.progress.done) || 0)) + '/' + escapeHtml(String((project.progress && project.progress.total) || 0)) + '</strong><span>مراحل تکمیل‌شده</span></div>' +
      '<div><strong>' + escapeHtml(String(project.checklist_done || 0)) + '/' + escapeHtml(String(project.checklist_total || 0)) + '</strong><span>چک‌لیست</span></div>' +
      '<div><strong>' + escapeHtml(String(project.user_tasks_done || 0)) + '/' + escapeHtml(String(project.user_tasks_total || 0)) + '</strong><span>تسک مشتری</span></div>' +
      '</div>';
    if (project.full_details) {
      body += '<div class="cptt-hubModal__detailsGrid">' +
        '<div><span>وضعیت پروژه</span><strong>' + escapeHtml((project.progress && project.progress.label) || '—') + '</strong></div>' +
        '<div><span>تعداد مراحل</span><strong>' + escapeHtml(String((project.progress && project.progress.total) || 0)) + '</strong></div>' +
        '<div><span>محصول</span><strong>' + escapeHtml(project.product || '—') + '</strong></div>' +
        '<div><span>دسته‌بندی</span><strong>' + escapeHtml(Array.isArray(project.categories) && project.categories.length ? project.categories.join('، ') : '—') + '</strong></div>' +
        '<div><span>وضعیت مالی</span><strong>' + escapeHtml(project.settled ? 'تسویه شده' : 'تسویه نشده') + '</strong></div>' +
        '<div><span>هزینه کل</span><strong>' + escapeHtml(String((project.financial && project.financial.cost) || 0)) + '</strong></div>' +
        '<div><span>دریافتی</span><strong>' + escapeHtml(String((project.financial && project.financial.paid) || 0)) + '</strong></div>' +
        '<div><span>مانده</span><strong>' + escapeHtml(String((project.financial && project.financial.remain) || 0)) + '</strong></div>' +
        '</div>';
    }
    if (Array.isArray(project.steps) && project.steps.length) {
      body += '<div class="cptt-hubSteps">';
      project.steps.forEach(function (step) {
        if (!step) return;
        body += '<section class="cptt-hubStep cptt-hubStep--' + escapeHtml(step.status || 'todo') + '">';
        body += '<div class="cptt-hubStep__head"><strong>' + escapeHtml((step.index || '') + '. ' + (step.title || '')) + '</strong><span class="cptt-step__badge cptt-step__badge--' + escapeHtml(step.status || 'todo') + '">' + escapeHtml(step.label || '') + '</span></div>';
        if (step.due_fa) body += '<div class="cptt-hubStep__meta">مهلت مرحله: ' + escapeHtml(step.due_fa) + '</div>';
        if (step.desc) body += '<div class="cptt-hubStep__desc">' + escapeHtml(step.desc) + '</div>';
        body += '<div class="cptt-hubStep__summary">' +
          '<span>چک‌لیست: ' + escapeHtml(String(step.checklist_done || 0)) + '/' + escapeHtml(String(step.checklist_total || 0)) + '</span>' +
          '<span>تسک مشتری: ' + escapeHtml(String(step.user_tasks_done || 0)) + '/' + escapeHtml(String(step.user_tasks_total || 0)) + '</span>' +
          '</div>';
        if (Array.isArray(step.checklist_items) && step.checklist_items.length) {
          body += '<ul class="cptt-hubChecklist">';
          step.checklist_items.forEach(function (item) {
            if (!item) return;
            body += '<li class="' + (item.done ? 'is-done' : '') + '"><span>' + escapeHtml(item.text || '') + '</span>';
            if (item.done && item.url) body += '<a href="' + escapeHtml(item.url) + '" target="_blank" rel="noopener noreferrer">لینک</a>';
            body += '</li>';
          });
          body += '</ul>';
        }
        body += '</section>';
      });
      body += '</div>';
    } else { body += '<div class="cptt-empty">برای این پروژه مرحله‌ای ثبت نشده است.</div>'; }
    openHubModal(project.title || '', chips.join(''), body);
  }

  function renderHubExpert(expert) {
    if (!expert) return;
    var chips = [];
    if (expert.title) chips.push('<span class="cptt-hubMetaChip">' + escapeHtml(expert.title) + '</span>');
    chips.push('<span class="cptt-hubMetaChip">پروژه فعال: ' + escapeHtml(String(expert.active_projects || 0)) + '</span>');
    chips.push('<span class="cptt-hubMetaChip">پروژه تکمیل‌شده: ' + escapeHtml(String(expert.completed_projects || 0)) + '</span>');
    var body = '<div class="cptt-expertModalProfile">';
    if (expert.avatar) body += '<img src="' + escapeHtml(expert.avatar) + '" alt="' + escapeHtml(expert.name || '') + '">';
    body += '<div class="cptt-expertModalProfile__info"><h3>' + escapeHtml(expert.name || '') + '</h3>';
    if (expert.bio) body += '<p>' + escapeHtml(expert.bio) + '</p>';
    body += '</div></div>';
    if (Array.isArray(expert.specialties) && expert.specialties.length) {
      body += '<div class="cptt-hubSectionTitle">تخصص‌ها</div><div class="cptt-expertModalTags">';
      expert.specialties.forEach(function (tag) { body += '<span>' + escapeHtml(tag) + '</span>'; });
      body += '</div>';
    }
    openHubModal(expert.name || '', chips.join(''), body);
  }

  function bindHubModals() {
    qsa('.cptt-publicProject__open').forEach(function (btn) {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () { renderHubProject(decodeB64Json(btn.getAttribute('data-project'))); });
    });
    qsa('.cptt-expertBadge').forEach(function (btn) {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () { renderHubExpert(decodeB64Json(btn.getAttribute('data-expert'))); });
    });
    var modal = hubModal();
    if (!modal || modal.dataset.bound) return;
    modal.dataset.bound = '1';
    var closeBtn = qs('.cptt-hubModal__close', modal);
    var backdrop = qs('.cptt-hubModal__backdrop', modal);
    if (closeBtn) closeBtn.addEventListener('click', closeHubModal);
    if (backdrop) backdrop.addEventListener('click', closeHubModal);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeHubModal(); });
  }

  function updateHubVisibility() {
    var search = ((qs('#cptt-hub-search') || {}).value || '').toLowerCase().trim();
    var expert = ((qs('#cptt-hub-expert') || {}).value || '');
    var product = ((qs('#cptt-hub-product') || {}).value || '');
    var cat = ((qs('#cptt-hub-cat') || {}).value || '');
    var deadline = ((qs('#cptt-hub-deadline') || {}).value || '');
    var visible = 0;
    qsa('.cptt-publicProject').forEach(function (card) {
      var ok = true;
      var dataSearch = String(card.getAttribute('data-search') || '').toLowerCase();
      var dataExperts = String(card.getAttribute('data-experts') || '');
      var dataProduct = String(card.getAttribute('data-product') || '');
      var dataCats = String(card.getAttribute('data-cats') || '');
      var dataDeadline = String(card.getAttribute('data-deadline') || '');
      if (search && dataSearch.indexOf(search) === -1) ok = false;
      if (expert && dataExperts.indexOf(',' + expert + ',') === -1) ok = false;
      if (product && dataProduct !== product) ok = false;
      if (cat && dataCats.indexOf(',' + cat + ',') === -1) ok = false;
      if (deadline && dataDeadline !== deadline) ok = false;
      card.hidden = !ok;
      card.style.display = ok ? '' : 'none';
      if (ok) visible++;
    });
    var count = qs('#cptt-hub-count');
    if (count) count.textContent = String(visible);
    var empty = qs('#cptt-hub-empty');
    if (empty) empty.hidden = visible !== 0;
  }

  function bindHubFilters() {
    var fields = ['#cptt-hub-search', '#cptt-hub-expert', '#cptt-hub-product', '#cptt-hub-cat', '#cptt-hub-deadline'];
    fields.forEach(function (sel) {
      var el = qs(sel);
      if (!el) return;
      el.addEventListener('input', updateHubVisibility);
      el.addEventListener('change', updateHubVisibility);
    });
    var reset = qs('#cptt-hub-reset');
    if (reset && !reset.dataset.bound) {
      reset.dataset.bound = '1';
      reset.addEventListener('click', function () {
        fields.forEach(function (sel) { var el = qs(sel); if (el) el.value = ''; });
        updateHubVisibility();
      });
    }
  }

  /* =========================================================
     13. INITIALIZE
     ========================================================= */
  document.addEventListener('DOMContentLoaded', function () {
    var search = qs('#cptt-expert-search');
    var status = qs('#cptt-expert-status');
    var settled = qs('#cptt-expert-settled');
    var client = qs('#cptt-expert-client');
    var product = qs('#cptt-expert-product');
    var cat = qs('#cptt-expert-cat');
    [search, status, settled, client, product, cat].forEach(function (el) {
      if (!el) return;
      el.addEventListener('input', updateVisibility);
      el.addEventListener('change', updateVisibility);
    });
    var reset = qs('#cptt-expert-reset');
    if (reset) {
      reset.addEventListener('click', function () {
        if (search) search.value = '';
        if (status) status.value = '';
        if (settled) settled.value = '';
        if (client) client.value = '';
        if (product) product.value = '';
        if (cat) cat.value = '';
        updateVisibility();
      });
    }
    bindProjectToggles();
    bindStepAccordions();
    bindCreateForm();
    bindSaveForms();
    bindMessageForms();
    bindChatModals();
    bindNewProjectModal();
    bindHubModals();
    bindHubFilters();
    bindDeleteActions();
    bindNotificationItems();
    initJalaliPicker();
    initDarkMode();
    initKanban();
    updateVisibility();
    updateHubVisibility();
    setInterval(pollNotifications, 30000);
  });

  /* =========================================================
     14. MOBILE + DIRECT CHAT (second DOMContentLoaded block merged)
     ========================================================= */
  document.addEventListener('DOMContentLoaded', function() {
    var qs2 = function(s, ctx) { return (ctx || document).querySelector(s); };
    var qsa2 = function(s, ctx) { return Array.from((ctx || document).querySelectorAll(s)); };

    var openExpertsBtn = qs2('.cptt-open-experts-modal-btn');
    var expertsModal = qs2('.cptt-experts-mobile-modal');
    if (openExpertsBtn && expertsModal) {
      openExpertsBtn.addEventListener('click', function() { expertsModal.removeAttribute('hidden'); });
      var closeMod = qs2('.cptt-experts-mobile-modal__close', expertsModal);
      var backMod = qs2('.cptt-experts-mobile-modal__backdrop', expertsModal);
      if (closeMod) closeMod.addEventListener('click', function() { expertsModal.setAttribute('hidden', ''); });
      if (backMod) backMod.addEventListener('click', function() { expertsModal.setAttribute('hidden', ''); });
    }

    var directChatModal = qs2('.cptt-direct-chat-modal');
    if (directChatModal) {
      var closeDc = qs2('.cptt-direct-chat-modal__close', directChatModal);
      var backDc = qs2('.cptt-direct-chat-modal__backdrop', directChatModal);
      if (closeDc) closeDc.addEventListener('click', function() { directChatModal.setAttribute('hidden', ''); });
      if (backDc) backDc.addEventListener('click', function() { directChatModal.setAttribute('hidden', ''); });

      qsa2('.cptt-expert-list-item').forEach(function(item) {
        item.addEventListener('click', async function() {
          var expertId = this.getAttribute('data-expert-id');
          if (expertsModal) expertsModal.setAttribute('hidden', '');
          qs2('#direct-chat-receiver-id', directChatModal).value = expertId;
          qs2('#direct-chat-messages-container', directChatModal).innerHTML = '<p>در حال بارگذاری...</p>';
          qs2('.cptt-direct-chat-form', directChatModal).reset();
          qs2('#direct-chat-file-name', directChatModal).textContent = '';
          directChatModal.removeAttribute('hidden');
          try {
            var fd = new FormData();
            fd.append('action', 'cptt_expert_get_expert_info');
            fd.append('nonce', CPTT_EXPERT.nonce);
            fd.append('expert_id', expertId);
            var res = await fetch(CPTT_EXPERT.ajax, { method: 'POST', body: fd });
            var json = await res.json();
            if (json.success) {
              qs2('#direct-chat-avatar', directChatModal).src = json.data.avatar;
              qs2('#direct-chat-name', directChatModal).textContent = json.data.name;
              qs2('#direct-chat-stats', directChatModal).textContent = json.data.stats;
            }
            var fd2 = new FormData();
            fd2.append('action', 'cptt_expert_fetch_direct_messages');
            fd2.append('nonce', CPTT_EXPERT.nonce);
            fd2.append('receiver_id', expertId);
            var res2 = await fetch(CPTT_EXPERT.ajax, { method: 'POST', body: fd2 });
            var json2 = await res2.json();
            if (json2.success) renderDirectMessages(json2.data, window.wp_user_id || 0);
          } catch(e) { console.error(e); }
        });
      });

      var dcForm = qs2('.cptt-direct-chat-form', directChatModal);
      if (dcForm) {
        var fileInput = qs2('#direct-chat-file', dcForm);
        if (fileInput) {
          fileInput.addEventListener('change', function() {
            qs2('#direct-chat-file-name', dcForm).textContent = this.files.length > 0 ? this.files[0].name : '';
          });
        }
        dcForm.addEventListener('submit', async function(e) {
          e.preventDefault();
          var msg = qs2('#direct-chat-form-msg', dcForm);
          var btn = qs2('button[type="submit"]', dcForm);
          msg.textContent = '';
          btn.disabled = true; btn.textContent = 'در حال ارسال...';
          try {
            var fd = new FormData(dcForm);
            fd.append('action', 'cptt_expert_send_direct_message');
            fd.append('nonce', CPTT_EXPERT.nonce);
            var res = await fetch(CPTT_EXPERT.ajax, { method: 'POST', body: fd });
            var json = await res.json();
            if (json.success) {
              renderDirectMessages(json.data, window.wp_user_id || 0);
              dcForm.reset();
              qs2('#direct-chat-file-name', dcForm).textContent = '';
            } else { msg.textContent = json.data || 'خطا در ارسال'; }
          } catch(e) { msg.textContent = 'خطا در ارتباط'; }
          btn.disabled = false; btn.textContent = 'ارسال';
        });
      }
    }
  });

  // Currency formatter
  document.addEventListener('keyup', function(e) {
    if (e.target && e.target.classList.contains('cptt-currency-input')) {
      var val = e.target.value.replace(/[^\d]/g, '');
      if (val) { e.target.value = parseInt(val, 10).toLocaleString('en-US'); }
      else { e.target.value = ''; }
    }
  });

  document.addEventListener('DOMContentLoaded', function() {
    var qs3 = function(s, ctx) { return (ctx || document).querySelector(s); };
    var catSelect = qs3('#cptt-create-cat-select');
    var prodWrap = qs3('#cptt-create-product-wrap');
    var prodSelect = qs3('#cptt-create-product-select');
    if (catSelect && prodWrap && prodSelect) {
      catSelect.addEventListener('change', function() {
        var cid = this.value;
        if (!cid) { prodWrap.style.display = 'none'; prodSelect.value = ''; }
        else {
          prodWrap.style.display = '';
          Array.from(prodSelect.options).forEach(function(opt) {
            if (!opt.value) return;
            var cats = opt.getAttribute('data-cats') || '';
            var catArr = cats.split(',');
            opt.style.display = (catArr.indexOf(cid) > -1) ? '' : 'none';
          });
          prodSelect.value = '';
        }
      });
    }
  });

  document.addEventListener('DOMContentLoaded', function() {
    var qs3 = function(s, ctx) { return (ctx || document).querySelector(s); };
    var bellBtn = qs3('.cptt-bell-btn');
    var notifDrop = qs3('.cptt-notifications-dropdown');
    if (bellBtn && notifDrop) {
      bellBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (notifDrop.hasAttribute('hidden')) notifDrop.removeAttribute('hidden');
        else notifDrop.setAttribute('hidden', '');
      });
      document.addEventListener('click', function(e) {
        if (!notifDrop.contains(e.target) && !bellBtn.contains(e.target)) notifDrop.setAttribute('hidden', '');
      });
      var markRead = qs3('#cptt-mark-all-read');
      if (markRead) {
        markRead.addEventListener('click', async function(e) {
          e.preventDefault(); e.stopPropagation();
          var badge = qs3('.cptt-bell-badge');
          if (badge) badge.style.display = 'none';
          document.querySelectorAll('.cptt-notification-item:not(.is-read)').forEach(function(item) { item.classList.add('is-read'); });
          var fd = new FormData();
          fd.append('action', 'cptt_expert_mark_notifications_read');
          fd.append('nonce', CPTT_EXPERT.nonce);
          try { await fetch(CPTT_EXPERT.ajax, { method: 'POST', body: fd }); } catch(err) {}
        });
      }
    }
  });

  document.addEventListener('DOMContentLoaded', function() {
    function randId(prefix) { return prefix + '_' + Math.floor(Math.random()*10000); }
    document.addEventListener('click', function(e) {
      if (e.target.classList.contains('cptt-expert-add-step')) {
        var stepId = randId('step');
        var idx = document.querySelectorAll('.cptt-expert-step').length + 1;
        var html = '<div class="cptt-expert-step is-open" data-step-id="'+stepId+'"><button type="button" class="cptt-expert-step__toggle" aria-expanded="true"><div class="cptt-expert-step__toggleMain"><strong>' + idx + '. مرحله جدید</strong><span>چک‌لیست: 0/0</span></div><div class="cptt-expert-step__toggleSide"><span class="cptt-expert-status cptt-expert-status--todo">انجام‌نشده</span><span class="cptt-expert-step__chevron">⌄</span></div></button><div class="cptt-expert-step__body"><div class="cptt-expert-step__metaGrid"><label><span>عنوان مرحله</span><input type="text" name="steps['+stepId+'][title]" value="مرحله جدید"></label><label><span>وضعیت مرحله</span><select name="steps['+stepId+'][status]"><option value="todo">انجام‌نشده</option><option value="current">در حال انجام</option><option value="done">انجام‌شده</option></select></label><label><span>مهلت مرحله</span><input type="text" class="cptt-jalali-datetime" name="steps['+stepId+'][due_at_local]" value=""></label></div><div class="cptt-expert-step__metaGrid"><label><span>توضیحات (اختیاری)</span><textarea name="steps['+stepId+'][desc]" rows="2"></textarea></label></div><div class="cptt-expert-step__metaGrid"><label><span>هزینه مرحله</span><input type="text" class="cptt-currency-input" name="steps['+stepId+'][cost]" value="0"></label><label><span>دریافتی مرحله</span><input type="text" class="cptt-currency-input" name="steps['+stepId+'][paid]" value="0"></label></div><div class="cptt-expert-checklist"><div class="cptt-expert-sectionTitle">چک‌لیست مرحله</div><div class="cptt-expert-checklist-items"></div><button type="button" class="button button-small cptt-expert-add-checkitem" style="margin-top:10px;">+ افزودن آیتم چک‌لیست</button></div><div class="cptt-expert-userTasks"><div class="cptt-expert-sectionTitle">تسک‌های سمت مشتری</div><div class="cptt-expert-usertasks-items"></div><button type="button" class="button button-small cptt-expert-add-usertask" style="margin-top:10px;">+ افزودن تسک مشتری</button></div><div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;"><button type="button" class="button button-small cptt-expert-add-checkitem" style="flex:1;">+ افزودن چک‌لیست</button><button type="button" class="button button-link-delete cptt-expert-remove-step" style="flex:1;color:#b91c1c;">× حذف مرحله</button></div></div></div>';
        var wrap = document.querySelector('.cptt-expert-stepsWrap') || document.querySelector('.cptt-expert-steps');
        if (wrap) {
          wrap.insertAdjacentHTML('beforeend', html);
          bindStepAccordions(wrap);
        }
      }
      if (e.target.classList.contains('cptt-expert-add-checkitem')) {
        var btn = e.target;
        var step = btn.closest('.cptt-expert-step');
        if (!step) return;
        var stepId = step.getAttribute('data-step-id');
        var checkId = randId('chk');
        var html = '<div class="cptt-expert-checklistRow"><label class="cptt-expert-checkItem"><input type="checkbox" name="steps['+stepId+'][checklist]['+checkId+'][done]" value="1"><span>انجام شد</span></label><input type="text" name="steps['+stepId+'][checklist]['+checkId+'][text]" value="" placeholder="متن آیتم"><input type="url" name="steps['+stepId+'][checklist]['+checkId+'][url]" value="" placeholder="لینک نتیجه (اختیاری)"><button type="button" class="button button-small cptt-expert-remove-checkitem">×</button></div>';
        var itemsWrap = step.querySelector('.cptt-expert-checklist-items') || (btn.previousElementSibling && btn.previousElementSibling.classList.contains('cptt-expert-checklist-items') ? btn.previousElementSibling : null);
        if (!itemsWrap) { itemsWrap = document.createElement('div'); itemsWrap.className='cptt-expert-checklist-items'; btn.parentNode.insertBefore(itemsWrap, btn); }
        itemsWrap.insertAdjacentHTML('beforeend', html);
      }
      if (e.target.classList.contains('cptt-expert-add-usertask')) {
        var btn = e.target;
        var step = btn.closest('.cptt-expert-step');
        if (!step) return;
        var stepId = step.getAttribute('data-step-id');
        var taskId = randId('ut');
        var html = '<div class="cptt-expert-userTaskRow"><div class="cptt-expert-userTaskRow__fields"><input type="text" name="steps['+stepId+'][user_tasks]['+taskId+'][title]" value="" placeholder="عنوان تسک"><textarea name="steps['+stepId+'][user_tasks]['+taskId+'][desc]" rows="2" placeholder="توضیحات تسک"></textarea><input type="text" class="cptt-jalali-datetime" name="steps['+stepId+'][user_tasks]['+taskId+'][due_at_local]" value="" placeholder="مهلت"><button type="button" class="button button-small cptt-expert-remove-usertask">×</button></div></div>';
        var itemsWrap = step.querySelector('.cptt-expert-usertasks-items') || (btn.previousElementSibling && btn.previousElementSibling.classList.contains('cptt-expert-usertasks-items') ? btn.previousElementSibling : null);
        if (!itemsWrap) { itemsWrap = document.createElement('div'); itemsWrap.className='cptt-expert-usertasks-items'; btn.parentNode.insertBefore(itemsWrap, btn); }
        itemsWrap.insertAdjacentHTML('beforeend', html);
      }
      if (e.target.classList.contains('cptt-expert-remove-step')) {
        e.target.closest('.cptt-expert-step').remove();
      }
      if (e.target.classList.contains('cptt-expert-remove-checkitem')) {
        e.target.closest('.cptt-expert-checklistRow').remove();
      }
      if (e.target.classList.contains('cptt-expert-remove-usertask')) {
        e.target.closest('.cptt-expert-userTaskRow').remove();
      }
    });
  });
})();
