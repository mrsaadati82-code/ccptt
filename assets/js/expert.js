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
      }
    }).catch(function(e){ /* silently ignore polling errors */ });
  }

  /* =========================================================
     DARK MODE
     ========================================================= */
  function initDarkMode() {
    var saved = localStorage.getItem('cptt_dark_mode');
    if (saved === '1') document.body.classList.add('cptt-dark');
    
    qsa('.cptt-dark-toggle-icon').forEach(function(btn) {
        if (document.body.classList.contains('cptt-dark')) {
            btn.innerHTML = '☀️';
            if (btn.textContent.indexOf('حالت') > -1) btn.innerHTML = '☀️ حالت روشن';
        }
        btn.addEventListener('click', function() {
            document.body.classList.toggle('cptt-dark');
            var on = document.body.classList.contains('cptt-dark');
            localStorage.setItem('cptt_dark_mode', on ? '1' : '0');
            qsa('.cptt-dark-toggle-icon').forEach(function(b) {
                b.innerHTML = on ? '☀️' : '🌙';
                if (b.textContent.indexOf('حالت') > -1) b.innerHTML = on ? '☀️ حالت روشن' : '🌙 حالت تاریک';
            });
        });
    });
  }

  /* =========================================================
     JALALI DATE PICKER
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
      e.stopPropagation();
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
     UTILITIES & UI
     ========================================================= */

  function initRealtimeClock() {
      function update() {
          var now = new Date();
          var faTime = now.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
          qsa('.cptt-realtime-clock').forEach(function(el) { el.textContent = faTime; });
      }
      setInterval(update, 10000);
      update();
  }

  function initMobileUI() {
      var mobileBell = qs('.cptt-mobile-bell-btn');
      if (mobileBell) {
          mobileBell.addEventListener('click', function(e) {
              e.preventDefault();
              var menu = qs('.cptt-mobile-menu');
              if (menu) { menu.setAttribute('hidden', ''); document.body.style.overflow = ''; }
              
              var notifModal = qs('.cptt-all-notifs-modal');
              if (notifModal) {
                  notifModal.removeAttribute('hidden');
                  var list = qs('.cptt-all-notifs-list', notifModal);
                  var fd = new FormData();
                  fd.append('action', 'cptt_expert_fetch_all_notifications');
                  fd.append('nonce', window.CPTT_EXPERT ? CPTT_EXPERT.nonce : window.CPTT_ADMIN.nonce);
                  fetch(window.CPTT_EXPERT ? CPTT_EXPERT.ajax : window.CPTT_ADMIN.ajax, { method: 'POST', body: fd })
                  .then(r => r.json()).then(data => {
                      if (data.success && list) {
                          list.innerHTML = data.data.html;
                      }
                  });
              }
          });
      }

      var fab = qs('.cptt-mobile-fab');
      var menu = qs('.cptt-mobile-menu');
      if (fab && menu) {
          fab.addEventListener('click', function() { menu.removeAttribute('hidden'); document.body.style.overflow = 'hidden'; });
          var close = qs('.cptt-mobile-menu__close', menu);
          var backdrop = qs('.cptt-mobile-menu__backdrop', menu);
          function closeMenu() { menu.setAttribute('hidden', ''); document.body.style.overflow = ''; }
          if (close) close.addEventListener('click', closeMenu);
          if (backdrop) backdrop.addEventListener('click', closeMenu);
      }
      
      var filterBtn = qs('#cptt-mobile-filter-btn');
      var filterWrap = qs('#cptt-expert-filters-wrap');
      if (filterBtn && filterWrap) {
          filterBtn.addEventListener('click', function() {
              filterWrap.classList.toggle('is-open');
          });
      }
  }

  /* =========================================================
     STAGE NUMBERING & REORDERING
     ========================================================= */
  function refreshStepNumbers(container) {
    if (!container) return;
    var steps = qsa('.cptt-expert-step', container);
    steps.forEach(function (step, index) {
      var num = index + 1;
      var titleStrong = qs('.cptt-expert-step__toggleMain strong', step);
      if (titleStrong) {
        var currentTitle = titleStrong.textContent.replace(/^\d+\.\s*/, '');
        titleStrong.textContent = num + '. ' + currentTitle;
      }
    });
  }

  function initStepReordering() {
    document.addEventListener('dragstart', function(e) {
      var handle = e.target.closest('.cptt-step-reorder-handle');
      if (!handle) return;
      var step = handle.closest('.cptt-expert-step');
      if (step) {
        step.classList.add('is-dragging-step');
        e.dataTransfer.setData('text/plain', '');
      } else {
          e.preventDefault();
      }
    });
    document.addEventListener('dragend', function(e) {
      var step = e.target.closest('.cptt-expert-step');
      if (step) step.classList.remove('is-dragging-step');
    });
    document.addEventListener('dragover', function(e) {
      e.preventDefault();
      var container = e.target.closest('.cptt-expert-steps');
      if (!container) return;
      var dragging = qs('.is-dragging-step');
      if (!dragging) return;
      var afterElement = getDragAfterElement(container, e.clientY);
      if (afterElement == null) {
        container.appendChild(dragging);
      } else {
        container.insertBefore(dragging, afterElement);
      }
    });
    document.addEventListener('drop', function(e) {
      var container = e.target.closest('.cptt-expert-steps');
      if (container) refreshStepNumbers(container);
    });

    function getDragAfterElement(container, y) {
      var draggableElements = qsa('.cptt-expert-step:not(.is-dragging-step)', container);
      return draggableElements.reduce(function(closest, child) {
        var box = child.getBoundingClientRect();
        var offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
          return { offset: offset, element: child };
        } else {
          return closest;
        }
      }, { offset: Number.NEGATIVE_INFINITY }).element;
    }
  }

  /* =========================================================
     HASH ACTION HANDLER
     ========================================================= */
  function parseHashAction() {
      var hash = window.location.hash;
      if (!hash) return;
      
      var pid = '';
      var openChat = false;
      var openDirect = false;
      var expertId = '';

      if (hash.startsWith('#project-')) {
          pid = hash.replace('#project-', '').split('#')[0];
          if (hash.indexOf('#chat-') > -1) openChat = true;
      } else if (hash.startsWith('#chat-')) {
          pid = hash.replace('#chat-', '');
          openChat = true;
      } else if (hash.startsWith('#directchat-')) {
          expertId = hash.replace('#directchat-', '');
          openDirect = true;
      }

      if (pid) {
          var card = null;
          qsa('.cptt-expertCard').forEach(function(c) {
             var f = qs('form[data-project-id="'+pid+'"]', c);
             if (f || c.getAttribute('data-project-id') === pid) card = c;
          });
          
          if (card) {
              var btn = qs('.cptt-expert-toggleProject', card);
              if (btn) {
                  setTimeout(function(){ 
                      btn.click(); 
                      if (openChat) {
                          setTimeout(function() {
                              var chatBtn = qs('.cptt-expert-chat-launch', card);
                              if (chatBtn) chatBtn.click();
                          }, 600);
                      }
                  }, 500);
              }
          }
      } else if (openDirect && expertId) {
          var expertItem = qs('.cptt-expert-list-item[data-expert-id="'+expertId+'"]');
          if (expertItem) setTimeout(function(){ expertItem.click(); }, 500);
      }
  }

  /* =========================================================
     FILTERS
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
          refreshStepNumbers(qs('.cptt-expert-steps', card));
        }
      });
    });
  }

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
        if (e.target.closest('.cptt-step-reorder-handle')) return;
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

  function bindCreateForm() {
    qsa('.cptt-expert-create-form').forEach(function (form) {
      if (form.dataset.bound) return;
      form.dataset.bound = '1';
      bindChatEnhancements(form);
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

  function bindSaveForms() {
    qsa('.cptt-expert-project-form').forEach(function (form) {
      if (form.dataset.bound) return;
      form.dataset.bound = '1';
      bindChatEnhancements(form);
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

  function renderMessages(items, container, myUserId) {
    if (!Array.isArray(items) || !items.length) {
      container.innerHTML = '<div class="cptt-expert-emptyMini">پیامی ثبت نشده است.</div>';
      return;
    }
    var html = items.map(function (message) {
      var isMe = parseInt(message.sender_id, 10) === parseInt(myUserId || 0, 10);
      var head = escapeHtml((message.sender_name || 'کاربر') + (message.recipient_name && message.recipient_name !== 'همه' ? ' → ' + message.recipient_name : ''));
      var time = escapeHtml(message.time_fa || '');
      
      var rawBody = message.content || '';
      var linkMatch = rawBody.match(/href=(?:&quot;|"|')?([^"'>\s&]+)(?:&quot;|"|')?[^>]*class=(?:&quot;|"|')?cptt-chat-file-link/i);
      var fileUrl = linkMatch ? linkMatch[1] : '';
      var cleanText = rawBody.replace(/<a[^>]*cptt-chat-file-link.*?<\/a>/gi, '').replace(/&lt;a[^&]*cptt-chat-file-link.*?&lt;\/a&gt;/gi, '');
      
      var body = escapeHtml(cleanText.trim()).replace(/\n/g, '<br>');
      if (fileUrl) {
          body += '<br><a href="' + escapeHtml(fileUrl) + '" target="_blank" class="cptt-chat-file-btn">👁 مشاهده فایل ضمیمه</a>';
      }

      var cls = isMe ? 'cptt-chat-bubble--me' : 'cptt-chat-bubble--other';
      return '<div class="cptt-chat-bubble ' + cls + '"><div class="cptt-chat-bubble__head"><strong>' + head + '</strong><span>' + time + '</span></div><div class="cptt-chat-bubble__body">' + body + '</div></div>';
    }).join('');
    container.innerHTML = html;
    container.scrollTop = container.scrollHeight;
  }

  const emojis = ['👍','✅','😊','🙏','👏','❤️','💡','🚀','👀','⚠️'];
  
  function bindChatEnhancements(form) {
    if (form.dataset.enhanced) return;
    form.dataset.enhanced = '1';
    
    var ta = form.querySelector('textarea');
    if (!ta) return;
    
    var emojiWrap = document.createElement('div');
    emojiWrap.className = 'cptt-emoji-picker';
    emojis.forEach(function(emoji) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cptt-emoji-btn';
      btn.textContent = emoji;
      btn.addEventListener('click', function() {
        ta.value += emoji;
        ta.focus();
      });
      emojiWrap.appendChild(btn);
    });
    ta.parentNode.insertBefore(emojiWrap, ta);
    
    var fileInput = form.querySelector('input[type="file"]');
    if (fileInput) {
      var previewWrap = document.createElement('div');
      previewWrap.className = 'cptt-chat-file-preview-wrap';
      ta.parentNode.insertBefore(previewWrap, ta.nextSibling);
      
      fileInput.addEventListener('change', function() {
        if (this.files && this.files.length > 0) {
          previewWrap.innerHTML = '<div class="cptt-chat-file-preview">' + escapeHtml(this.files[0].name) + ' <button type="button" title="حذف">×</button></div>';
          previewWrap.querySelector('button').addEventListener('click', function() {
            fileInput.value = '';
            previewWrap.innerHTML = '';
          });
        } else {
          previewWrap.innerHTML = '';
        }
      });
      
      form.addEventListener('cptt-chat-sent', function() {
        fileInput.value = '';
        previewWrap.innerHTML = '';
      });
    }
  }

  function bindMessageForms() {
    qsa('.cptt-expert-message-form').forEach(function (form) {
      if (form.dataset.bound) return;
      form.dataset.bound = '1';
      bindChatEnhancements(form);
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
          var ta = qs('textarea[name="content"]', form); if (ta) ta.value = ''; form.dispatchEvent(new Event('cptt-chat-sent'));
          var wrap = form.parentElement.querySelector('.cptt-expert-messagesWrap');
          var myId = (window.CPTT_EXPERT && CPTT_EXPERT.wpUserId) ? CPTT_EXPERT.wpUserId : 0;
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
      
      var rawBody = m.content || '';
      var linkMatch = rawBody.match(/href=(?:&quot;|"|')?([^"'>\s&]+)(?:&quot;|"|')?[^>]*class=(?:&quot;|"|')?cptt-chat-file-link/i);
      var fileUrl = linkMatch ? linkMatch[1] : '';
      var cleanText = rawBody.replace(/<a[^>]*cptt-chat-file-link.*?<\/a>/gi, '').replace(/&lt;a[^&]*cptt-chat-file-link.*?&lt;\/a&gt;/gi, '');
      
      var body = escapeHtml(cleanText.trim()).replace(/\n/g, '<br>');
      if (fileUrl) {
          body += '<br><a href="' + escapeHtml(fileUrl) + '" target="_blank" class="cptt-chat-file-btn">👁 مشاهده فایل ضمیمه</a>';
      }

      var cls = isMe ? 'cptt-chat-bubble--me' : 'cptt-chat-bubble--other';
      return '<div class="cptt-chat-bubble ' + cls + '"><div class="cptt-chat-bubble__head"><strong>' + escapeHtml(m.sender_name || 'کاربر') + '</strong><span>' + time + '</span></div><div class="cptt-chat-bubble__body">' + body + '</div></div>';
    }).join('');
    wrap.innerHTML = html;
    wrap.scrollTop = wrap.scrollHeight;
  }

  function bindNewProjectModal() {
    var modal = qs('#cptt-new-project-modal');
    if (!modal) return;
    var openers = qsa('.cptt-newProjectCta, [data-cptt-open-newproject]');
    var closeBtns = qsa('.cptt-newProjectModal__close, [data-cptt-close-newproject]', modal);
    var backdrop = qs('.cptt-newProjectModal__backdrop', modal);
    function open() { modal.classList.add('is-open'); document.body.style.overflow = 'hidden'; modal.removeAttribute('aria-hidden'); }
    function close() { modal.classList.remove('is-open'); document.body.style.overflow = ''; modal.setAttribute('aria-hidden', 'true'); }
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
        var container = stepEl.parentElement;
        var projectId = form ? form.getAttribute('data-project-id') : '';
        if (projectId && stepId && stepId.indexOf('step_') === -1 && confirm('این مرحله حذف شود؟')) {
          try {
            var fd2 = new FormData();
            fd2.append('action', 'cptt_expert_delete_step');
            fd2.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
            fd2.append('project_id', projectId);
            fd2.append('step_id', stepId);
            var res2 = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', { method: 'POST', credentials: 'same-origin', body: fd2 });
            var json2 = await res2.json();
            if (json2 && json2.success) { stepEl.remove(); if (container) refreshStepNumbers(container); }
            else { alert((json2 && json2.data) ? json2.data : 'خطا در حذف مرحله'); }
          } catch (err) { alert('خطا در ارتباط'); }
        } else {
          stepEl.remove();
          if (container) refreshStepNumbers(container);
        }
      }
    });
  }

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
          steps.forEach(function (s) { if (s.step_id === stepId) s.status = newStatus; });
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
     INITIALIZE
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
    bindDeleteActions();
    initStepReordering();
    
    initJalaliPicker();
    initRealtimeClock();
    initMobileUI();
    parseHashAction();
    initDarkMode();
    initKanban();
    updateVisibility();
    setInterval(pollNotifications, 30000);
  });

  document.addEventListener('DOMContentLoaded', function() {
    var qs2 = function(s, ctx) { return (ctx || document).querySelector(s); };
    var qsa2 = function(s, ctx) { return Array.from((ctx || document).querySelectorAll(s)); };

    var openExpertsBtn = qs2('.cptt-open-experts-modal-btn');
    var expertsModal = qs2('.cptt-experts-mobile-modal');
    if (openExpertsBtn && expertsModal) {
      qsa2('.cptt-open-experts-modal-btn').forEach(btn => btn.addEventListener('click', function() { 
          expertsModal.removeAttribute('hidden'); 
          var menu = document.querySelector('.cptt-mobile-menu');
          if (menu) { menu.setAttribute('hidden', ''); document.body.style.overflow = ''; }
      }));

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
            if (json2.success) renderDirectMessages(json2.data, (window.CPTT_EXPERT && CPTT_EXPERT.wpUserId) ? CPTT_EXPERT.wpUserId : 0);
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
        bindChatEnhancements(dcForm);
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
              renderDirectMessages(json.data, (window.CPTT_EXPERT && CPTT_EXPERT.wpUserId) ? CPTT_EXPERT.wpUserId : 0);
              dcForm.reset(); dcForm.dispatchEvent(new Event('cptt-chat-sent'));
              qs2('#direct-chat-file-name', dcForm).textContent = '';
            } else { msg.textContent = json.data || 'خطا در ارسال'; }
          } catch(e) { msg.textContent = 'خطا در ارتباط'; }
          btn.disabled = false; btn.textContent = 'ارسال';
        });
      }
    }
  });

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
        var card = e.target.closest('.cptt-expertCard');
        var container = qs('.cptt-expert-steps', card);
        if (!container) return;
        var stepId = randId('step');
        var idx = container.querySelectorAll('.cptt-expert-step').length + 1;
        var html = '<div class="cptt-expert-step is-open" draggable="true" data-step-id="'+stepId+'"><button type="button" class="cptt-expert-step__toggle" aria-expanded="true"><span class="cptt-step-reorder-handle" title="تغییر ترتیب">⠿</span><div class="cptt-expert-step__toggleMain"><strong>' + idx + '. مرحله جدید</strong><span>چک‌لیست: 0/0</span></div><div class="cptt-expert-step__toggleSide"><span class="cptt-expert-status cptt-expert-status--todo">انجام‌نشده</span><span class="cptt-expert-step__chevron">⌄</span></div></button><div class="cptt-expert-step__body"><div class="cptt-expert-step__metaGrid"><label><span>عنوان مرحله</span><input type="text" name="steps['+stepId+'][title]" value="مرحله جدید"></label><label><span>وضعیت مرحله</span><select name="steps['+stepId+'][status]"><option value="todo">انجام‌نشده</option><option value="current">در حال انجام</option><option value="done">انجام‌شده</option></select></label><label><span>مهلت مرحله</span><input type="text" class="cptt-jalali-datetime" name="steps['+stepId+'][due_at_local]" value=""></label></div><div class="cptt-expert-step__metaGrid"><label><span>هزینه مرحله</span><input type="text" class="cptt-currency-input" name="steps['+stepId+'][cost]" value="0"></label><label><span>دریافتی مرحله</span><input type="text" class="cptt-currency-input" name="steps['+stepId+'][paid]" value="0"></label></div><label class="cptt-expert-noteField"><span>توضیحات (اختیاری)</span><textarea name="steps['+stepId+'][desc]" rows="2"></textarea></label><div class="cptt-expert-checklist"><div class="cptt-expert-sectionTitle">چک‌لیست مرحله</div><div class="cptt-expert-checklist-items"></div><button type="button" class="button button-small cptt-expert-add-checkitem" style="margin-top:10px;">+ افزودن آیتم چک‌لیست</button></div><div class="cptt-expert-userTasks"><div class="cptt-expert-sectionTitle">تسک‌های سمت مشتری</div><div class="cptt-expert-usertasks-items"></div><button type="button" class="button button-small cptt-expert-add-usertask" style="margin-top:10px;">+ افزودن تسک مشتری</button></div><div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;"><button type="button" class="button button-small cptt-expert-add-checkitem" style="flex:1;">+ افزودن چک‌لیست</button><button type="button" class="button button-link-delete cptt-expert-remove-step" style="flex:1;color:#b91c1c;">× حذف مرحله</button></div></div></div>';
        container.insertAdjacentHTML('beforeend', html);
        bindStepAccordions(container);
        refreshStepNumbers(container);
      }
      if (e.target.classList.contains('cptt-expert-add-checkitem')) {
        var btn = e.target;
        var step = btn.closest('.cptt-expert-step');
        if (!step) return;
        var stepId = step.getAttribute('data-step-id');
        var checkId = randId('chk');
        var html = '<div class="cptt-expert-checkRow"><label class="cptt-expert-checkItem"><input type="checkbox" name="steps['+stepId+'][checklist]['+checkId+'][done]" value="1"><span>انجام شد</span></label><input type="text" name="steps['+stepId+'][checklist]['+checkId+'][text]" value="" placeholder="متن آیتم"><input type="url" name="steps['+stepId+'][checklist]['+checkId+'][url]" value="" placeholder="لینک نتیجه (اختیاری)"><button type="button" class="button button-small cptt-expert-remove-checkitem">×</button></div>';
        var chkWrap = step.querySelector('.cptt-expert-checklist-items');
        if (!chkWrap) {
            var itemsDiv = document.createElement('div');
            itemsDiv.className = 'cptt-expert-checklist-items';
            var sectionTitle = step.querySelector('.cptt-expert-checklist .cptt-expert-sectionTitle');
            if (sectionTitle) sectionTitle.parentNode.insertBefore(itemsDiv, btn);
            chkWrap = itemsDiv;
        }
        chkWrap.insertAdjacentHTML('beforeend', html);
      }
      if (e.target.classList.contains('cptt-expert-add-usertask')) {
        var btn = e.target;
        var step = btn.closest('.cptt-expert-step');
        if (!step) return;
        var stepId = step.getAttribute('data-step-id');
        var taskId = randId('ut');
        var html = '<div class="cptt-expert-userTask"><div class="cptt-expert-userTask__fields"><input type="text" name="steps['+stepId+'][user_tasks]['+taskId+'][title]" value="" placeholder="عنوان تسک"><textarea name="steps['+stepId+'][user_tasks]['+taskId+'][desc]" rows="2" placeholder="توضیحات تسک"></textarea><input type="text" class="cptt-jalali-datetime" name="steps['+stepId+'][user_tasks]['+taskId+'][due_at_local]" value="" placeholder="مهلت"><button type="button" class="button button-small cptt-expert-remove-usertask">×</button></div></div>';
        var itemsWrap = step.querySelector('.cptt-expert-usertasks-items');
        if (!itemsWrap) {
            itemsWrap = document.createElement('div');
            itemsWrap.className = 'cptt-expert-usertasks-items';
            btn.parentNode.insertBefore(itemsWrap, btn);
        }
        itemsWrap.insertAdjacentHTML('beforeend', html);
      }
      if (e.target.classList.contains('cptt-expert-remove-checkitem')) e.target.closest('.cptt-expert-checkRow').remove();
      if (e.target.classList.contains('cptt-expert-remove-usertask')) e.target.closest('.cptt-expert-userTask').remove();
    });
  });

  document.addEventListener('click', function(e) {
      var notifLink = e.target.closest('.cptt-notification-item');
      if (notifLink) {
          if (!notifLink.classList.contains('is-read')) {
              notifLink.classList.add('is-read');
              var id = notifLink.getAttribute('data-id');
              if (id) {
                  var fd = new FormData();
                  fd.append('action', 'cptt_expert_mark_single_notification_read');
                  fd.append('nonce', window.CPTT_EXPERT ? CPTT_EXPERT.nonce : window.CPTT_ADMIN.nonce);
                  fd.append('id', id);
                  fetch(window.CPTT_EXPERT ? CPTT_EXPERT.ajax : window.CPTT_ADMIN.ajax, { method: 'POST', body: fd, keepalive: true });
              }
          }
      }

      if (e.target.classList.contains('cptt-delete-notif-btn')) {
          e.preventDefault(); e.stopPropagation();
          var id = e.target.getAttribute('data-id');
          if (id) {
              var fd = new FormData();
              fd.append('action', 'cptt_expert_delete_notification');
              fd.append('nonce', window.CPTT_EXPERT ? CPTT_EXPERT.nonce : window.CPTT_ADMIN.nonce);
              fd.append('id', id);
              fetch(window.CPTT_EXPERT ? CPTT_EXPERT.ajax : window.CPTT_ADMIN.ajax, { method: 'POST', body: fd });
              e.target.closest('.cptt-notification-item-wrap').remove();
          }
      }
      if (e.target.hasAttribute('data-cptt-open-all-notifs')) {
          var modal = qs('.cptt-all-notifs-modal');
          if (modal) {
              modal.removeAttribute('hidden');
              var list = qs('.cptt-all-notifs-list', modal);
              var fd = new FormData();
              fd.append('action', 'cptt_expert_fetch_all_notifications');
              fd.append('nonce', window.CPTT_EXPERT ? CPTT_EXPERT.nonce : window.CPTT_ADMIN.nonce);
              fetch(window.CPTT_EXPERT ? CPTT_EXPERT.ajax : window.CPTT_ADMIN.ajax, { method: 'POST', body: fd })
              .then(r => r.json()).then(data => {
                  if (data.success && list) {
                      list.innerHTML = data.data.html;
                  }
              });
          }
      }
      if (e.target.classList.contains('cptt-all-notifs-modal__close') || e.target.classList.contains('cptt-all-notifs-modal__backdrop')) {
          var modal = qs('.cptt-all-notifs-modal');
          if (modal) modal.setAttribute('hidden', '');
      }
  });

})();
