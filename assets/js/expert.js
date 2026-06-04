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
  /* ---------------------------------------------------------------
     Push Notification (v5.4.3)
     - استفاده از آی‌دی هر اعلان برای جلوگیری از تکرار
     - عنوان نوتیفیکیشن بر اساس نوع/متن واقعی هر اعلان
     - ذخیره‌ی آی‌دی‌های نمایش‌داده‌شده در localStorage تا بعد از رفرش هم تکرار نشود
  --------------------------------------------------------------- */
  var CPTT_PUSH_STORAGE_KEY = 'cptt_pushed_notif_ids_v1';
  var CPTT_PUSH_MAX_KEEP = 200;

  function loadPushedIds() {
    try {
      var raw = localStorage.getItem(CPTT_PUSH_STORAGE_KEY);
      if (!raw) return {};
      var obj = JSON.parse(raw);
      return (obj && typeof obj === 'object') ? obj : {};
    } catch (e) { return {}; }
  }
  function savePushedIds(obj) {
    try {
      // محدودسازی حجم: اگر بیش از حد بزرگ شد، فقط جدیدترین‌ها نگه داشته شوند
      var keys = Object.keys(obj);
      if (keys.length > CPTT_PUSH_MAX_KEEP) {
        keys.sort(function(a,b){ return (obj[a]||0) - (obj[b]||0); });
        var drop = keys.length - CPTT_PUSH_MAX_KEEP;
        for (var i = 0; i < drop; i++) delete obj[keys[i]];
      }
      localStorage.setItem(CPTT_PUSH_STORAGE_KEY, JSON.stringify(obj));
    } catch (e) {}
  }

  // نگاشت نوع اعلان به عنوان فارسی مناسب push
  var CPTT_NOTIF_TITLES = {
    project_assigned:  '📌 پروژه‌ی جدید به شما واگذار شد',
    project_removed:   '🚫 از پروژه حذف شدید',
    step_completed:    '✅ یک مرحله انجام شد',
    project_note:      '📝 یادداشت جدید در پروژه',
    project_completed: '🎉 پروژه تکمیل شد',
    project_chat:      '💬 پیام جدید در چت پروژه',
    direct_chat:       '📨 پیام مستقیم جدید',
    expert_payout:     '💰 تسویه حساب جدید',
    user_task_done:    '🧩 پاسخ مشتری ثبت شد'
  };
  function titleForNotif(type, message) {
    if (type && CPTT_NOTIF_TITLES[type]) return CPTT_NOTIF_TITLES[type];
    // fallback: چند کلمه‌ی اول پیام
    var m = String(message || '').replace(/\s+/g, ' ').trim();
    if (!m) return '🔔 اعلان جدید';
    return m.length > 50 ? m.slice(0, 50) + '…' : m;
  }

  var cpttPollInFlight = false;
  var cpttFirstPoll = true; // پولینگ اولِ بار: فقط ID ها را seed کن، push نزن

  function pollNotifications() {
    if (!qs('.cptt-notification-bell')) return;
    if (cpttPollInFlight) return; // جلوگیری از تداخل درخواست‌های همزمان
    cpttPollInFlight = true;

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

      var currentUnread = parseInt(data.unread || 0, 10);
      var items = Array.isArray(data.items) ? data.items : [];

      // Push برای هر اعلان خوانده‌نشده‌ی جدید (که قبلا push نشده)
      if (window.Notification && Notification.permission === 'granted' && items.length) {
        var pushed = loadPushedIds();
        var now = Date.now();
        var changed = false;
        // مرتب‌سازی صعودی بر اساس id تا اگر چند تا جدید باشد به ترتیب push شوند
        items.slice().sort(function(a,b){ return (parseInt(a.id,10)||0) - (parseInt(b.id,10)||0); })
        .forEach(function(it){
          var nid = String(it.id || '');
          if (!nid) return;
          if (pushed[nid]) return;
          // در پولینگ اول، فقط آی‌دی‌ها را seed کن تا اعلان‌های قدیمی موجود در دیتابیس
          // بعد از باز کردن صفحه دوباره به‌صورت push نمایش داده نشوند.
          if (cpttFirstPoll) {
            pushed[nid] = now;
            changed = true;
            return;
          }
          if (parseInt(it.is_read, 10) === 1) {
            // قبلا خوانده شده؛ push نمی‌کنیم ولی به دفتر اضافه می‌کنیم تا بعدا تکرار نشود
            pushed[nid] = now;
            changed = true;
            return;
          }
          try {
            var n = new Notification(titleForNotif(it.type, it.message), {
              body: String(it.message || ''),
              dir: 'rtl',
              lang: 'fa',
              tag: 'cptt-notif-' + nid,  // tag یکتا → جلوگیری از تکرار توسط مرورگر
              renotify: false,
              icon: (window.CPTT_EXPERT && CPTT_EXPERT.notif_icon) ? CPTT_EXPERT.notif_icon : undefined
            });
            if (it.link) {
              n.onclick = function(){
                try { window.focus(); window.location.href = it.link; } catch(e){}
                this.close();
              };
            }
          } catch(e) {}
          pushed[nid] = now;
          changed = true;
        });
        if (changed) savePushedIds(pushed);
      }
      cpttFirstPoll = false;

      if (badge) {
        if (currentUnread > 0) { badge.textContent = currentUnread; badge.style.display = 'flex'; }
        else { badge.style.display = 'none'; }
      }
      if (list && data.html) {
        list.innerHTML = data.html;
      }
    }).catch(function(e){ /* silently ignore polling errors */ })
    .finally(function(){ cpttPollInFlight = false; });
  }

  /* =========================================================
     DARK MODE
     ========================================================= */
  function initDarkMode() {
    if (!document.body.classList.contains('cptt-expert-dashboard-page')) {
        return;
    }
    var saved = localStorage.getItem('cptt_dark_mode');
    if (saved === '1') document.body.classList.add('cptt-dark');
    
    qsa('.cptt-dark-toggle-icon').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.body.classList.toggle('cptt-dark');
            var on = document.body.classList.contains('cptt-dark');
            localStorage.setItem('cptt_dark_mode', on ? '1' : '0');
        });
    });
  }



  /* =========================================================
     DASHBOARD THEME MANAGER
     ========================================================= */
  function initThemeManager() {
    if (!document.body.classList.contains('cptt-expert-dashboard-page')) return;
    var allowed = ['classic','skeuo','neumorph','minimal','dark','three-d'];
    function normalize(t){ return allowed.indexOf(t) !== -1 ? t : 'classic'; }
    function applyTheme(t) {
      t = normalize(t);
      allowed.forEach(function(x){ document.body.classList.remove('cptt-theme-' + x); });
      document.body.classList.add('cptt-theme-' + t);
      document.body.setAttribute('data-theme', t);
      var wrap = document.querySelector('.cptt-expertWrap');
      if (wrap) wrap.setAttribute('data-theme', t);
      if (t === 'dark') {
        document.body.classList.add('cptt-dark');
        localStorage.setItem('cptt_dark_mode', '1');
      } else {
        document.body.classList.remove('cptt-dark');
        localStorage.setItem('cptt_dark_mode', '0');
      }
      document.querySelectorAll('.cptt-theme-select').forEach(function(sel){ sel.value = t; });
      localStorage.setItem('cptt_expert_theme', t);
    }
    var initial = normalize((window.CPTT_EXPERT && CPTT_EXPERT.userTheme) || localStorage.getItem('cptt_expert_theme') || (localStorage.getItem('cptt_dark_mode') === '1' ? 'dark' : 'classic'));
    applyTheme(initial);
    document.querySelectorAll('.cptt-theme-select').forEach(function(sel){
      if (sel.dataset.cpttThemeBound) return;
      sel.dataset.cpttThemeBound = '1';
      sel.value = initial;
      sel.addEventListener('change', function(){
        var theme = normalize(sel.value);
        applyTheme(theme);
        var ajax = (window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '';
        var nonce = (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '';
        if (ajax) {
          var fd = new FormData();
          fd.append('action','cptt_expert_save_theme');
          fd.append('nonce', nonce);
          fd.append('theme', theme);
          fetch(ajax, {method:'POST', credentials:'same-origin', body:fd}).catch(function(){});
        }
      });
    });
  }



  /* =========================================================
     GLASS BACKGROUND CONTROLS (local, per expert browser)
     ========================================================= */
  function initGlassBackgroundControls() {
    if (!document.body.classList.contains('cptt-expert-dashboard-page')) return;
    var storageKey = 'cptt_glass_background_css';
    var presets = {
      mesh: "radial-gradient(circle at 16% 8%, rgba(255,255,255,.92) 0 10%, transparent 28%), radial-gradient(circle at 76% 10%, rgba(186,230,253,.72) 0, transparent 34%), radial-gradient(circle at 14% 78%, rgba(125,211,252,.58) 0, transparent 38%), radial-gradient(circle at 86% 82%, rgba(196,181,253,.46) 0, transparent 34%), linear-gradient(135deg,#f8fcff 0%,#dff5ff 34%,#c8ecff 62%,#eee7ff 100%)",
      aurora: "radial-gradient(circle at 18% 14%, rgba(255,255,255,.88) 0 9%, transparent 30%), radial-gradient(circle at 80% 18%, rgba(34,211,238,.48), transparent 36%), radial-gradient(circle at 32% 88%, rgba(147,197,253,.60), transparent 38%), radial-gradient(circle at 84% 75%, rgba(216,180,254,.46), transparent 34%), linear-gradient(135deg,#ffffff 0%,#e0f7ff 42%,#d8eafe 72%,#f4eaff 100%)",
      blue: "radial-gradient(circle at 22% 0%, rgba(255,255,255,.92), transparent 28%), radial-gradient(circle at 80% 22%, rgba(125,211,252,.72), transparent 36%), radial-gradient(circle at 30% 86%, rgba(56,189,248,.42), transparent 40%), linear-gradient(135deg,#f8fbff 0%,#dff3ff 42%,#c7e8ff 100%)"
    };
    function safeCss(value) {
      value = (value || '').trim();
      if (!value) return '';
      if (/javascript:|expression\s*\(|<\/?script/i.test(value)) return '';
      if (/^https?:\/\//i.test(value) || /^data:image\//i.test(value)) {
        return 'linear-gradient(rgba(246,252,255,.18),rgba(226,245,255,.18)), url("' + value.replace(/"/g, '%22') + '") center/cover fixed no-repeat';
      }
      return value;
    }
    function apply(value) {
      var css = safeCss(value) || presets.mesh;
      document.documentElement.style.setProperty('--cptt-glass-bg', css);
      document.body.style.setProperty('--cptt-glass-bg', css);
    }
    apply(localStorage.getItem(storageKey) || presets.mesh);

    var controls = document.querySelector('.cptt-sidebar-controls');
    if (!controls || document.querySelector('.cptt-glass-bg-controls')) return;
    var wrap = document.createElement('div');
    wrap.className = 'cptt-glass-bg-controls';
    wrap.setAttribute('dir', 'rtl');
    wrap.innerHTML = '<button type="button" class="cptt-glass-bg-toggle" title="پس‌زمینه گلس">پس‌زمینه</button>' +
      '<div class="cptt-glass-bg-panel" hidden>' +
      '<strong>پس‌زمینه گلس</strong>' +
      '<select class="cptt-glass-bg-preset" aria-label="پریست پس‌زمینه گلس">' +
      '<option value="mesh">مش روشن</option><option value="aurora">آرورا</option><option value="blue">آبی نرم</option><option value="custom">سفارشی / عکس</option>' +
      '</select>' +
      '<textarea class="cptt-glass-bg-custom" rows="3" placeholder="آدرس عکس یا CSS gradient"></textarea>' +
      '<div class="cptt-glass-bg-actions"><button type="button" class="cptt-glass-bg-apply">اعمال</button><button type="button" class="cptt-glass-bg-reset">بازنشانی</button></div>' +
      '</div>';
    controls.appendChild(wrap);
    var toggle = wrap.querySelector('.cptt-glass-bg-toggle');
    var panel = wrap.querySelector('.cptt-glass-bg-panel');
    var preset = wrap.querySelector('.cptt-glass-bg-preset');
    var custom = wrap.querySelector('.cptt-glass-bg-custom');
    var applyBtn = wrap.querySelector('.cptt-glass-bg-apply');
    var resetBtn = wrap.querySelector('.cptt-glass-bg-reset');
    custom.value = localStorage.getItem(storageKey) || '';
    function refreshVisibility(){ wrap.style.display = document.body.getAttribute('data-theme') === 'glass' ? '' : 'none'; }
    refreshVisibility();
    document.addEventListener('change', function(e){ if (e.target && e.target.classList && e.target.classList.contains('cptt-theme-select')) setTimeout(refreshVisibility, 50); });
    toggle.addEventListener('click', function(){ panel.hidden = !panel.hidden; });
    preset.addEventListener('change', function(){ if (preset.value !== 'custom') { custom.value = presets[preset.value]; apply(custom.value); localStorage.setItem(storageKey, custom.value); } });
    applyBtn.addEventListener('click', function(){ var v = custom.value || presets.mesh; apply(v); localStorage.setItem(storageKey, v); });
    resetBtn.addEventListener('click', function(){ custom.value = presets.mesh; preset.value = 'mesh'; apply(presets.mesh); localStorage.setItem(storageKey, presets.mesh); });
    document.addEventListener('click', function(e){ if (!wrap.contains(e.target)) panel.hidden = true; });
  }


  /* Mobile: date fields should open datepicker without mobile keyboard. */
  document.addEventListener('pointerdown', function(e){
    if (!(window.matchMedia && window.matchMedia('(max-width: 820px)').matches)) return;
    var inp = e.target && e.target.closest ? e.target.closest('.cptt-jalali-datetime') : null;
    if (inp) { inp.setAttribute('readonly','readonly'); inp.setAttribute('inputmode','none'); }
  }, {passive:true});

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
              if (menu) { menu.setAttribute('hidden', ''); document.body.style.overflow = ''; document.body.classList.remove('cptt-mobile-menu-open'); }
              
              var notifModal = qs('.cptt-all-notifs-modal');
              if (notifModal) {
                  notifModal.removeAttribute('hidden');
                  var list = qs('.cptt-all-notifs-list', notifModal);
                  var fd = new FormData();
                  fd.append('action', 'cptt_expert_fetch_all_notifications');
                  fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : (window.CPTT_ADMIN ? CPTT_ADMIN.nonce : ''));
                  fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : (window.CPTT_ADMIN ? CPTT_ADMIN.ajax : ''), { method: 'POST', body: fd })
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
          fab.addEventListener('click', function() {
            menu.removeAttribute('hidden');
            document.body.style.overflow = 'hidden';
            document.body.classList.add('cptt-mobile-menu-open');
          });
          var close = qs('.cptt-mobile-menu__close', menu);
          var backdrop = qs('.cptt-mobile-menu__backdrop', menu);
          function closeMenu() {
            menu.setAttribute('hidden', '');
            var projectModal = document.getElementById('cptt-new-project-modal');
            var keepLocked = projectModal && projectModal.classList && projectModal.classList.contains('is-open');
            document.body.style.overflow = keepLocked ? 'hidden' : '';
            document.body.classList.remove('cptt-mobile-menu-open');
          }
          if (close) close.addEventListener('click', closeMenu);
          if (backdrop) backdrop.addEventListener('click', closeMenu);
          menu.addEventListener('click', function(e) {
            if (e.target.closest('.cptt-mobile-menu__close') || e.target.closest('.cptt-open-experts-modal-btn') || e.target.closest('[data-cptt-open-newproject]') || e.target.closest('a')) {
              setTimeout(closeMenu, 30);
            }
          });
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
        // Save the avatar span if it exists
        var avatarSpan = titleStrong.querySelector('.cptt-step-toggle-avatars');
        if (avatarSpan) avatarSpan.remove();

        var currentTitle = titleStrong.textContent.replace(/^\d+\.\s*/, '');
        titleStrong.textContent = num + '. ' + currentTitle;

        // Restore the avatar span
        if (avatarSpan) titleStrong.appendChild(avatarSpan);
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
    var label = (qs('#cptt-expert-label') || {}).value || '';
    var visible = 0;
    qsa('.cptt-expertCard').forEach(function (card) {
      var ok = true;
      var dataSearch = String(card.getAttribute('data-search') || '').toLowerCase();
      var dataStatus = String(card.getAttribute('data-status') || '');
      var dataSettled = String(card.getAttribute('data-settled') || '');
      var dataClient = String(card.getAttribute('data-client') || '');
      var dataProduct = String(card.getAttribute('data-product') || '');
      var dataCats = String(card.getAttribute('data-cats') || '');
      var dataLabel = String(card.getAttribute('data-label') || '');
      if (search && dataSearch.indexOf(search) === -1) ok = false;
      if (status && dataStatus !== status) ok = false;
      if (settled !== '' && dataSettled !== settled) ok = false;
      if (client && dataClient !== client) ok = false;
      if (product && dataProduct !== product) ok = false;
      if (cat && dataCats.indexOf(',' + cat + ',') === -1) ok = false;
      if (label && dataLabel !== label) ok = false;
      card.hidden = !ok;
      card.style.display = ok ? '' : 'none';
      if (ok) visible++;
    });
    var empty = qs('#cptt-expert-empty');
    if (empty) empty.hidden = visible !== 0;
    document.dispatchEvent(new CustomEvent('cptt:expertFiltersChanged', { detail: { visible: visible } }));
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

  /* مودال تداخل ویرایش همزمان (v5.4.3) */
  function showConflictModal(message) {
    if (document.getElementById('cptt-conflict-modal')) return;
    var overlay = document.createElement('div');
    overlay.id = 'cptt-conflict-modal';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.65);backdrop-filter:blur(4px);direction:rtl;';
    overlay.innerHTML =
      '<div style="background:#fff;border-radius:18px;max-width:460px;width:100%;padding:24px;box-shadow:0 30px 60px rgba(0,0,0,.3);text-align:center;font-family:inherit;">' +
        '<div style="font-size:42px;margin-bottom:8px;">⚠️</div>' +
        '<h3 style="margin:0 0 10px;color:#0f172a;font-size:17px;font-weight:900;">تداخل در ویرایش همزمان</h3>' +
        '<p style="color:#475569;font-size:13px;line-height:1.8;margin:0 0 18px;">' + (message || 'این پروژه در حین کار شما توسط کارشناس دیگری ویرایش و ذخیره شده است.') + '</p>' +
        '<p style="color:#64748b;font-size:12px;line-height:1.7;margin:0 0 18px;">برای جلوگیری از خراب شدن اطلاعات، لطفاً صفحه را بروزرسانی کنید و تغییرات خود را دوباره اعمال نمایید.</p>' +
        '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">' +
          '<button type="button" id="cptt-conflict-refresh" style="flex:1;min-width:140px;padding:12px 18px;border-radius:12px;border:none;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-size:13px;font-weight:900;cursor:pointer;">🔄 بروزرسانی صفحه</button>' +
          '<button type="button" id="cptt-conflict-close" style="flex:1;min-width:140px;padding:12px 18px;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#334155;font-size:13px;font-weight:900;cursor:pointer;">بستن</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);
    var close = function(){ try{ overlay.remove(); }catch(e){} };
    overlay.addEventListener('click', function(e){ if (e.target === overlay) close(); });
    var rBtn = document.getElementById('cptt-conflict-refresh');
    var cBtn = document.getElementById('cptt-conflict-close');
    if (rBtn) rBtn.addEventListener('click', function(){ window.location.reload(); });
    if (cBtn) cBtn.addEventListener('click', close);
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
          if (!json || !json.success) {
            // تشخیص خطای تداخل ویرایش همزمان (HTTP 409 یا پیام مشخص)
            var errMsg = '';
            if (json && json.data) {
              errMsg = (typeof json.data === 'string') ? json.data : (json.data.message || '');
            }
            var isConflict = (res.status === 409) || (errMsg && errMsg.indexOf('کارشناس دیگری ویرایش') !== -1);
            if (isConflict) {
              showConflictModal(errMsg || 'این پروژه توسط کارشناس دیگری ویرایش شده است.');
              if (msg) { msg.style.color = '#dc2626'; msg.textContent = 'برای ادامه، صفحه را بروزرسانی کنید.'; }
              return;
            }
            throw new Error(errMsg || (window.CPTT_EXPERT && CPTT_EXPERT.texts && CPTT_EXPERT.texts.error) || 'خطا در ذخیره اطلاعات');
          }
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
      return '<div class="cptt-chat-bubble ' + cls + '" data-chat-kind="project" data-id="' + escapeHtml(String(message.id || '')) + '" data-owned="' + (isMe ? '1' : '0') + '" data-text="' + escapeHtml(cleanText.trim()) + '"><div class="cptt-chat-bubble__head"><strong>' + head + '</strong><span>' + time + '</span></div><div class="cptt-chat-bubble__body">' + body + '</div></div>';
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
      function close() { modal.hidden = true; document.body.classList.remove('cptt-chat-modal-open'); if (timer) { window.clearInterval(timer); timer = null; } }
      async function open() { if (modal.parentNode !== document.body) document.body.appendChild(modal); modal.hidden = false; document.body.classList.add('cptt-chat-modal-open'); await refreshMessages(form); if (timer) window.clearInterval(timer); timer = window.setInterval(function () { refreshMessages(form); }, 2500); }
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
      return '<div class="cptt-chat-bubble ' + cls + '" data-chat-kind="direct" data-id="' + escapeHtml(String(m.id || '')) + '" data-owned="' + (isMe ? '1' : '0') + '" data-text="' + escapeHtml(cleanText.trim()) + '"><div class="cptt-chat-bubble__head"><strong>' + escapeHtml(m.sender_name || 'کاربر') + '</strong><span>' + time + '</span></div><div class="cptt-chat-bubble__body">' + body + '</div></div>';
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


  function initClientSearchPickers() { /* disabled: search is only for create form and is bound by isolated v5.4.10 code */ }

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
      steps.forEach(function (s) {
        var projectCard = document.querySelector('.cptt-expertCard[data-project-id="' + String(s.project_id) + '"]');
        if (projectCard && (projectCard.hidden || projectCard.style.display === 'none')) return;
        cols[s.status || 'todo'].push(s);
      });
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
            fd.append('action', 'cptt_expert_update_step_status');
            fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
            fd.append('project_id', projectId);
            fd.append('step_id', stepId);
            fd.append('status', newStatus);
            var kr = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', { method: 'POST', credentials: 'same-origin', body: fd });
            var kj = await kr.json().catch(function(){ return null; });
            if (!kj || !kj.success) throw new Error((kj && kj.data) ? kj.data : 'خطا در تغییر وضعیت');
            window.location.reload();
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
      document.addEventListener('cptt:expertFiltersChanged', function(){ if (!kanbanWrap.hidden) buildKanban(); });
    }
  }

  /* =========================================================
     INITIALIZE
     ========================================================= */
  document.addEventListener('DOMContentLoaded', function () {
    if (window.matchMedia && window.matchMedia('(max-width: 820px)').matches) {
      document.body.classList.add('cptt-mobile-perf');
      document.querySelectorAll('.cptt-jalali-datetime').forEach(function(inp){ inp.setAttribute('readonly','readonly'); inp.setAttribute('inputmode','none'); });
      document.addEventListener('focusin', function(e){ if(e.target && e.target.classList && e.target.classList.contains('cptt-jalali-datetime')) e.target.blur(); });
    }
    /* ===== Web Notifications Permission Request ===== */
    if (window.Notification && Notification.permission === 'default') {
      Notification.requestPermission();
    }

    /* ===== Back to Top Button Handler ===== */
    var btt = document.getElementById('cptt-back-to-top');
    if (btt) {
      var bttTicking = false;
      window.addEventListener('scroll', function() {
        if (bttTicking) return;
        bttTicking = true;
        requestAnimationFrame(function(){
          btt.style.display = window.scrollY > 300 ? 'flex' : 'none';
          bttTicking = false;
        });
      }, {passive:true});
      btt.addEventListener('click', function() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    }

    var search = qs('#cptt-expert-search');
    var status = qs('#cptt-expert-status');
    var settled = qs('#cptt-expert-settled');
    var client = qs('#cptt-expert-client');
    var product = qs('#cptt-expert-product');
    var cat = qs('#cptt-expert-cat');
    var label = qs('#cptt-expert-label');
    [search, status, settled, client, product, cat, label].forEach(function (el) {
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
        if (label) label.value = '';
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
    initThemeManager();
    initGlassBackgroundControls();
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
          if (menu) { menu.setAttribute('hidden', ''); document.body.style.overflow = ''; document.body.classList.remove('cptt-mobile-menu-open'); }
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
    var qsa3 = function(s, ctx) { return Array.from((ctx || document).querySelectorAll(s)); };
    function positionDropdown(bellWrap, drop) {
      if (!bellWrap || !drop) return;
      if (window.innerWidth > 820) {
        drop.style.position = ''; drop.style.top = ''; drop.style.left = ''; drop.style.right = ''; drop.style.width = ''; drop.style.maxWidth = '';
        return;
      }
      var btn = bellWrap.querySelector('.cptt-bell-btn') || bellWrap;
      var rect = btn.getBoundingClientRect();
      var w = Math.min(320, Math.max(240, window.innerWidth - 24));
      var left = rect.left;
      if (left + w > window.innerWidth - 12) left = window.innerWidth - w - 12;
      if (left < 12) left = 12;
      drop.style.position = 'fixed';
      drop.style.top = Math.round(rect.bottom + 8) + 'px';
      drop.style.left = Math.round(left) + 'px';
      drop.style.right = 'auto';
      drop.style.width = Math.round(w) + 'px';
      drop.style.maxWidth = 'calc(100vw - 24px)';
      drop.style.zIndex = '2147483646';
    }
    qsa3('.cptt-notification-bell').forEach(function(wrap) {
      var bellBtn = qs3('.cptt-bell-btn', wrap);
      var notifDrop = qs3('.cptt-notifications-dropdown', wrap);
      if (!bellBtn || !notifDrop || bellBtn.dataset.cpttNotifBound) return;
      bellBtn.dataset.cpttNotifBound = '1';
      var header = qs3('.cptt-notifications-header', notifDrop);
      if (header && !qs3('.cptt-notifications-close', header)) {
        var x = document.createElement('button');
        x.type = 'button'; x.className = 'cptt-notifications-close'; x.setAttribute('aria-label','بستن'); x.textContent = '×';
        header.appendChild(x);
        x.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); notifDrop.setAttribute('hidden',''); });
      }
      bellBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        qsa3('.cptt-notifications-dropdown').forEach(function(d){ if (d !== notifDrop) d.setAttribute('hidden',''); });
        if (notifDrop.hasAttribute('hidden')) { notifDrop.removeAttribute('hidden'); positionDropdown(wrap, notifDrop); }
        else notifDrop.setAttribute('hidden', '');
      });
      window.addEventListener('resize', function(){ if (!notifDrop.hasAttribute('hidden')) positionDropdown(wrap, notifDrop); });
      document.addEventListener('click', function(e) {
        if (!notifDrop.contains(e.target) && !bellBtn.contains(e.target)) notifDrop.setAttribute('hidden', '');
      });
    });
    var markRead = qs3('#cptt-mark-all-read');
    if (markRead) {
      markRead.addEventListener('click', async function(e) {
        e.preventDefault(); e.stopPropagation();
        document.querySelectorAll('.cptt-bell-badge').forEach(function(badge){ badge.style.display = 'none'; });
        document.querySelectorAll('.cptt-notification-item:not(.is-read)').forEach(function(item) { item.classList.add('is-read'); });
        var fd = new FormData();
        fd.append('action', 'cptt_expert_mark_notifications_read');
        fd.append('nonce', CPTT_EXPERT.nonce);
        try { await fetch(CPTT_EXPERT.ajax, { method: 'POST', body: fd }); } catch(err) {}
      });
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
        var html = '<div class="cptt-expert-step is-open" draggable="true" data-step-id="'+stepId+'"><button type="button" class="cptt-expert-step__toggle" aria-expanded="true"><span class="cptt-step-reorder-handle" title="تغییر ترتیب">⠿</span><div class="cptt-expert-step__toggleMain"><strong>' + idx + '. مرحله جدید</strong><span>چک‌لیست: 0/0</span></div><div class="cptt-expert-step__toggleSide"><span class="cptt-expert-status cptt-expert-status--todo">انجام‌نشده</span><span class="cptt-expert-step__chevron">⌄</span></div></button><div class="cptt-expert-step__body"><div class="cptt-expert-step__metaGrid"><label><span>عنوان مرحله</span><input type="text" name="steps['+stepId+'][title]" value="مرحله جدید"></label><label><span>وضعیت مرحله</span><select name="steps['+stepId+'][status]"><option value="todo">انجام‌نشده</option><option value="current">در حال انجام</option><option value="done">انجام‌شده</option></select></label><label><span>مهلت مرحله</span><input type="text" class="cptt-jalali-datetime" name="steps['+stepId+'][due_at_local]" value=""></label></div><div class="cptt-expert-step__metaGrid"><label><span>هزینه مرحله</span><input type="text" class="cptt-currency-input" name="steps['+stepId+'][cost]" value="0"></label><label><span>دریافتی مرحله</span><input type="text" class="cptt-currency-input" name="steps['+stepId+'][paid]" value="0"></label></div><div class="cptt-step-expert-wrap"><input type="hidden" class="cptt-step-expert-primary" name="steps['+stepId+'][assigned_expert_id]" value=""><div class="cptt-step-expert-hidden-list"></div><button type="button" class="cptt-step-expert-btn">👥 انتخاب کارشناسان مرحله</button></div><label class="cptt-expert-noteField"><span>توضیحات (اختیاری)</span><textarea name="steps['+stepId+'][desc]" rows="2"></textarea></label><div class="cptt-expert-checklist"><div class="cptt-expert-sectionTitle">چک‌لیست مرحله</div><div class="cptt-expert-checklist-items"></div><button type="button" class="button button-small cptt-expert-add-checkitem" style="margin-top:10px;">+ افزودن آیتم چک‌لیست</button></div><div class="cptt-expert-userTasks"><div class="cptt-expert-sectionTitle">تسک‌های سمت مشتری</div><div class="cptt-expert-usertasks-items"></div><button type="button" class="button button-small cptt-expert-add-usertask" style="margin-top:10px;">+ افزودن تسک مشتری</button></div><div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;"><button type="button" class="button button-small cptt-expert-add-checkitem" style="flex:1;">+ افزودن چک‌لیست</button><button type="button" class="button button-link-delete cptt-expert-remove-step" style="flex:1;color:#b91c1c;">× حذف مرحله</button></div></div></div>';
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
                  fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : (window.CPTT_ADMIN ? CPTT_ADMIN.nonce : ''));
                  fd.append('id', id);
                  fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : (window.CPTT_ADMIN ? CPTT_ADMIN.ajax : ''), { method: 'POST', body: fd, keepalive: true });
              }
          }
      }

      var delBtn = e.target.closest('.cptt-delete-notif-btn');
      if (delBtn) {
          e.preventDefault(); e.stopPropagation();
          if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
          var id = delBtn.getAttribute('data-id');
          if (id) {
              var wrap = delBtn.closest('.cptt-notification-item-wrap');
              // optimistic UI: حذف فوری از DOM
              if (wrap) {
                  wrap.style.transition = 'opacity .15s ease, transform .15s ease';
                  wrap.style.opacity = '0';
                  wrap.style.transform = 'translateX(-12px)';
                  setTimeout(function(){ if (wrap.parentNode) wrap.parentNode.removeChild(wrap); }, 160);
              }
              var fd = new FormData();
              fd.append('action', 'cptt_expert_delete_notification');
              fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : (window.CPTT_ADMIN && CPTT_ADMIN.nonce) ? CPTT_ADMIN.nonce : '');
              fd.append('id', id);
              var ajaxUrl = (window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : (window.CPTT_ADMIN && CPTT_ADMIN.ajax) ? CPTT_ADMIN.ajax : '/wp-admin/admin-ajax.php';
              fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(function(){});
              // کاهش badge اگر اعلان خوانده‌نشده بود
              var anchor = wrap ? wrap.querySelector('.cptt-notification-item') : null;
              if (anchor && !anchor.classList.contains('is-read')) {
                  var badge = document.querySelector('.cptt-bell-badge');
                  if (badge) {
                      var n = parseInt(badge.textContent || '0', 10) - 1;
                      if (n > 0) { badge.textContent = String(n); }
                      else { badge.style.display = 'none'; badge.textContent = '0'; }
                  }
              }
          }
          return;
      }
      if (e.target.hasAttribute('data-cptt-open-all-notifs')) {
          var modal = qs('.cptt-all-notifs-modal');
          if (modal) {
              modal.removeAttribute('hidden');
              var list = qs('.cptt-all-notifs-list', modal);
              var fd = new FormData();
              fd.append('action', 'cptt_expert_fetch_all_notifications');
              fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : (window.CPTT_ADMIN ? CPTT_ADMIN.nonce : ''));
              fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : (window.CPTT_ADMIN ? CPTT_ADMIN.ajax : ''), { method: 'POST', body: fd })
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

/* =========================================================
   MANAGE FORM: DELIVERY CHAIN (Province → City → Address)
   Isolated per-card, no conflicts with create form
   ========================================================= */
(function() {
  'use strict';

  // Full provinces & cities map (same as create form)
  var PC = {
    "تهران": ["تهران","ورامین","شهریار","قدس","ملارد","پاکدشت","اسلامشهر","رباط کریم","فیروزکوه","دماوند","بومهن","پردیس","نسیم‌شهر","چهاردانگه","باغستان"],
    "البرز": ["کرج","فردیس","نظرآباد","هشتگرد","طالقان","چهارباغ","اشتهارد","ماهدشت","گرمدره"],
    "اصفهان": ["اصفهان","کاشان","خمینی‌شهر","نجف‌آباد","شهرضا","فلاورجان","مبارکه","زرین‌شهر","آران و بیدگل","اردستان","سمیرم","گلپایگان","تیران","شاهین‌شهر","دهاقان","نطنز","فریدن","برخوار","لنجان"],
    "فارس": ["شیراز","مرودشت","جهرم","کازرون","فسا","داراب","لارستان","آباده","اقلید","ممسنی","نی‌ریز","فیروزآباد","سپیدان"],
    "خراسان رضوی": ["مشهد","نیشابور","سبزوار","تربت حیدریه","قوچان","گناباد","کاشمر","تایباد","درگز","تربت جام","فریمان","خواف","چناران","جوین"],
    "آذربایجان شرقی": ["تبریز","مراغه","میانه","اهر","مرند","سراب","هریس","بناب","ملکان","عجبشیر","بستان‌آباد","کلیبر","شبستر","اسکو"],
    "آذربایجان غربی": ["ارومیه","خوی","مهاباد","بوکان","سلماس","میاندوآب","نقده","پیرانشهر","شاهین‌دژ","سردشت","اشنویه","تکاب","ماکو","چالدران"],
    "کرمان": ["کرمان","رفسنجان","جیرفت","بم","زرند","سیرجان","بافت","شهربابک","انار","راور","کوهبنان","قلعه‌گنج","عنبرآباد","منوجان"],
    "خوزستان": ["اهواز","آبادان","دزفول","خرمشهر","ماهشهر","شوشتر","ایذه","بهبهان","اندیمشک","مسجد سلیمان","امیدیه","رامهرمز","دشت‌آزادگان","حمیدیه","کارون"],
    "مازندران": ["ساری","آمل","بابل","قائمشهر","نوشهر","چالوس","تنکابن","رامسر","بهشهر","نکا","جویبار","بابلسر","محمودآباد","فریدونکنار","گلوگاه"],
    "گیلان": ["رشت","انزلی","لاهیجان","لنگرود","صومعه‌سرا","تالش","آستارا","رودبار","فومن","رودسر","آستانه اشرفیه","شفت","ماسال","سیاهکل","رضوانشهر"],
    "هرمزگان": ["بندرعباس","میناب","قشم","بندرلنگه","حاجی‌آباد","رودان","جاسک","بشاگرد","سیریک","خمیر","پارسیان","بستک"],
    "سیستان و بلوچستان": ["زاهدان","زابل","چابهار","ایرانشهر","سراوان","نیکشهر","خاش","سرباز","دلگان","هیرمند","قصرقند"],
    "کرمانشاه": ["کرمانشاه","اسلام‌آباد","هرسین","کنگاور","سنقر","جوانرود","پاوه","دالاهو","قصرشیرین","گیلانغرب","روانسر"],
    "گلستان": ["گرگان","گنبد کاووس","علی‌آباد","مینودشت","بندرگز","رامیان","آق‌قلا","ترکمن","کردکوی","گالیکش","کلاله","آزادشهر"],
    "لرستان": ["خرم‌آباد","بروجرد","کوهدشت","دورود","الیگودرز","ازنا","نورآباد","پلدختر","سلسله"],
    "همدان": ["همدان","ملایر","تویسرکان","نهاوند","بهار","رزن","اسدآباد","کبودرآهنگ","فامنین"],
    "قم": ["قم","کهک","دستجرد","جعفریه"],
    "قزوین": ["قزوین","البرز","بویین‌زهرا","تاکستان","آبیک","اقبالیه","محمدیه","شال","آوج"],
    "زنجان": ["زنجان","ابهر","خرمدره","ایجرود","طارم","ماهنشان","سلطانیه"],
    "اردبیل": ["اردبیل","مشکین‌شهر","پارس‌آباد","خلخال","بیله‌سوار","نمین","نیر","گرمی","سرعین"],
    "بوشهر": ["بوشهر","برازجان","گناوه","دیلم","خورموج","عسلویه","جم","دشتی","تنگستان","دشتستان"],
    "مرکزی": ["اراک","ساوه","محلات","خمین","دلیجان","تفرش","آشتیان","کمیجان","شازند","زرندیه"],
    "ایلام": ["ایلام","مهران","دره‌شهر","آبدانان","دهلران","ایوان","سیروان","ملکشاهی","بدره"],
    "کهگیلویه و بویراحمد": ["یاسوج","گچساران","دوگنبدان","دنا","بهمئی","لنده","چرام","باشت"],
    "خراسان شمالی": ["بجنورد","شیروان","اسفراین","قوچان","مانه و سملقان","جاجرم","فاروج","گرمه","راز و جرگلان"],
    "خراسان جنوبی": ["بیرجند","قاین","طبس","فردوس","بشرویه","درمیان","سربیشه","خوسف","نهبندان"],
    "سمنان": ["سمنان","گرمسار","شاهرود","دامغان","مهدی‌شهر","سرخه","آرادان","میامی"],
    "چهارمحال و بختیاری": ["شهرکرد","بروجن","فارسان","لردگان","سامان","کوهرنگ","کیار","اردل"],
    "کردستان": ["سنندج","سقز","مریوان","بانه","قروه","کامیاران","دیواندره","بیجار","سروآباد","دهگلان"],
    "یزد": ["یزد","میبد","اردکان","بافق","ابرکوه","تفت","خاتم","مهریز","بهاباد"]
  };

  function initManageDelivery(form) {
    var deliverySelect = form.querySelector('.cptt-manage-delivery-method');
    var provinceWrap = form.querySelector('.cptt-manage-province-wrap');
    var provinceSelect = form.querySelector('.cptt-manage-province');
    var cityWrap = form.querySelector('.cptt-manage-city-wrap');
    var citySelect = form.querySelector('.cptt-manage-city');
    var addressWrap = form.querySelector('.cptt-manage-address-wrap');

    if (!deliverySelect) return;

    // Populate cities based on saved province
    function populateCities(prov, selectedCity) {
      if (!citySelect) return;
      citySelect.innerHTML = '<option value="">— انتخاب شهر —</option>';
      if (prov && PC[prov]) {
        PC[prov].forEach(function(city) {
          var opt = document.createElement('option');
          opt.value = city;
          opt.textContent = city;
          if (city === selectedCity) opt.selected = true;
          citySelect.appendChild(opt);
        });
      }
    }

    // Populate provinces
    if (provinceSelect) {
      var currentProv = provinceSelect.value;
      provinceSelect.innerHTML = '<option value="">— انتخاب استان —</option>';
      Object.keys(PC).sort().forEach(function(prov) {
        var opt = document.createElement('option');
        opt.value = prov;
        opt.textContent = prov;
        if (prov === currentProv) opt.selected = true;
        provinceSelect.appendChild(opt);
      });

      // If province already selected, populate cities
      if (currentProv && PC[currentProv]) {
        var savedCity = citySelect ? (citySelect.querySelector('option[selected]') || {value:''}).value : '';
        populateCities(currentProv, savedCity);
      }
    }

    // Province change → load cities
    if (provinceSelect) {
      provinceSelect.addEventListener('change', function() {
        var prov = provinceSelect.value;
        if (prov) {
          populateCities(prov, '');
          if (cityWrap) cityWrap.style.display = 'block';
          if (addressWrap) addressWrap.style.display = 'none';
        } else {
          if (cityWrap) cityWrap.style.display = 'none';
          if (addressWrap) addressWrap.style.display = 'none';
        }
      });
    }

    // City change → show address
    if (citySelect) {
      citySelect.addEventListener('change', function() {
        if (citySelect.value) {
          if (addressWrap) addressWrap.style.display = 'block';
        } else {
          if (addressWrap) addressWrap.style.display = 'none';
        }
      });
    }

    // Delivery method change
    deliverySelect.addEventListener('change', function() {
      if (deliverySelect.value === 'shipping') {
        if (provinceWrap) provinceWrap.style.display = 'block';
        if (provinceSelect && provinceSelect.value) {
          if (cityWrap) cityWrap.style.display = 'block';
        }
      } else {
        if (provinceWrap) provinceWrap.style.display = 'none';
        if (cityWrap) cityWrap.style.display = 'none';
        if (addressWrap) addressWrap.style.display = 'none';
      }
    });

    // Initialize visibility on load
    if (deliverySelect.value === 'shipping') {
      if (provinceWrap) provinceWrap.style.display = 'block';
      if (provinceSelect && provinceSelect.value && cityWrap) cityWrap.style.display = 'block';
      if (citySelect && citySelect.value && addressWrap) addressWrap.style.display = 'block';
    }
  }

  /* =========================================================
     NEW CUSTOMER MODAL - works for BOTH create & manage forms
     ========================================================= */
  function initNewCustomerModals() {
    // For create form: inject trigger option into client selects
    document.querySelectorAll('.cptt-expert-create-form select[name="client_user_id"]').forEach(function(sel) {
      if (!sel.querySelector('option[value="new_customer_trigger"]')) {
        var opt = document.createElement('option');
        opt.value = 'new_customer_trigger';
        opt.textContent = '+ ثبت مشتری جدید —';
        opt.style.fontWeight = 'bold';
        opt.style.color = '#6366f1';
        sel.insertBefore(opt, sel.firstChild);
      }
      sel.addEventListener('change', function() {
        if (sel.value === 'new_customer_trigger') {
          openNewCustomerModal(sel);
          sel.value = '';
        }
      });
    });

    // For manage forms: inject trigger option into client selects
    document.querySelectorAll('.cptt-expert-project-form select[name="client_user_id"]').forEach(function(sel) {
      if (!sel.querySelector('option[value="new_customer_trigger"]')) {
        var opt = document.createElement('option');
        opt.value = 'new_customer_trigger';
        opt.textContent = '+ ثبت مشتری جدید —';
        opt.style.fontWeight = 'bold';
        opt.style.color = '#6366f1';
        sel.insertBefore(opt, sel.firstChild);
      }
      sel.addEventListener('change', function() {
        if (sel.value === 'new_customer_trigger') {
          openNewCustomerModal(sel);
          sel.value = '';
        }
      });
    });
  }

  var _activeClientSelect = null;

  function ensureNewCustomerModal() {
    var modals = Array.prototype.slice.call(document.querySelectorAll('#cptt-new-customer-modal'));
    var modal = modals[0] || null;
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'cptt-new-customer-modal';
      modal.className = 'cptt-new-customer-modal-overlay';
      modal.style.display = 'none';
      modal.innerHTML = '<div class="cptt-new-customer-modal-dialog" role="dialog" aria-modal="true">'
        + '<div class="cptt-ncm-header"><h3>👤 ثبت مشتری جدید</h3><button type="button" id="cptt-cust-close" onclick="window.cpttQuickCustomerClose&&window.cpttQuickCustomerClose(event)">×</button></div>'
        + '<div class="cptt-ncm-body"><div class="cptt-ncm-field"><label for="cptt-cust-firstname">نام</label><input type="text" id="cptt-cust-firstname" autocomplete="given-name"></div>'
        + '<div class="cptt-ncm-field"><label for="cptt-cust-lastname">نام خانوادگی</label><input type="text" id="cptt-cust-lastname" autocomplete="family-name"></div>'
        + '<div class="cptt-ncm-field"><label for="cptt-cust-phone">شماره موبایل</label><input type="tel" id="cptt-cust-phone" autocomplete="tel"></div></div>'
        + '<div id="cptt-cust-msg"></div><div class="cptt-ncm-footer"><button type="button" id="cptt-cust-submit" onclick="window.cpttQuickCustomerSubmit&&window.cpttQuickCustomerSubmit(event)" class="cptt-btn cptt-btn--primary">✔ ثبت مشتری</button></div></div>';
    }
    // Remove duplicated copies to avoid getElementById / event target confusion.
    modals.slice(1).forEach(function(m){ if (m && m.parentNode) m.parentNode.removeChild(m); });
    if (modal.parentNode !== document.body) document.body.appendChild(modal);
    return modal;
  }

  function openNewCustomerModal(triggerSelect) {
    _activeClientSelect = triggerSelect;
    var modal = ensureNewCustomerModal();
    if (modal) {
      // If this modal was rendered inside the create-project modal, move it to <body>
      // before blurring the parent; otherwise the customer popup itself becomes blurred.
      if (modal.parentNode !== document.body) document.body.appendChild(modal);
      modal.style.display = 'flex';
      modal.style.zIndex = '2147483647';
      modal.removeAttribute('aria-hidden');
      // Blur ONLY the create-project popup behind the customer modal.
      var parentModal = document.getElementById('cptt-new-project-modal');
      if (parentModal) {
        parentModal.classList.add('cptt-modal-blurred-behind');
        parentModal.style.filter = 'blur(5px)';
      }
      var sb = document.getElementById('cptt-cust-submit');
      if (sb) sb.disabled = false;
      var msg = document.getElementById('cptt-cust-msg');
      if (msg) msg.textContent = '';
      var fnInput = document.getElementById('cptt-cust-firstname');
      if (fnInput) setTimeout(function(){ fnInput.focus(); }, 30);
    }
  }

  function closeNewCustomerModal() {
    var custModal = ensureNewCustomerModal();
    if (custModal) {
      custModal.style.display = 'none';
      custModal.setAttribute('aria-hidden', 'true');
    }
    var parentModal = document.getElementById('cptt-new-project-modal');
    if (parentModal) {
      parentModal.classList.remove('cptt-modal-blurred-behind');
      parentModal.style.filter = '';
      parentModal.style.pointerEvents = '';
    }
    _activeClientSelect = null;
  }

  window.cpttQuickCustomerClose = window.cpttQuickCustomerClose || function(ev){ if(ev){ev.preventDefault();ev.stopPropagation();} closeNewCustomerModal(); };
  window.cpttQuickCustomerSubmit = window.cpttQuickCustomerSubmit || function(ev){
    if(ev){ ev.preventDefault(); ev.stopPropagation(); }
    var btn = document.getElementById('cptt-cust-submit');
    if (btn) btn.click();
  };

  function bindNewCustomerSubmit() {
    // Bind once globally. The modal may be injected later with the create-project form,
    // so never return just because it is not in DOM yet.
    if (document.documentElement.dataset.cpttNewCustomerDelegated === '1') return;
    document.documentElement.dataset.cpttNewCustomerDelegated = '1';

    document.addEventListener('click', function(e) {
      var closeBtn = e.target.closest && e.target.closest('#cptt-cust-close');
      if (closeBtn) {
        e.preventDefault();
        e.stopPropagation();
        closeNewCustomerModal();
        return;
      }

      var custModal = document.getElementById('cptt-new-customer-modal');
      if (custModal && e.target === custModal) {
        e.preventDefault();
        closeNewCustomerModal();
        return;
      }

      var submitBtn = e.target.closest && e.target.closest('#cptt-cust-submit');
      if (!submitBtn) return;
      e.preventDefault();
      e.stopPropagation();
      if (submitBtn.disabled) return;

      var firstName = (document.getElementById('cptt-cust-firstname') || {}).value;
      var lastName = (document.getElementById('cptt-cust-lastname') || {}).value;
      var phone = (document.getElementById('cptt-cust-phone') || {}).value;
      var msg = document.getElementById('cptt-cust-msg');
      if (firstName) firstName = firstName.trim();
      if (lastName) lastName = lastName.trim();
      if (phone) phone = phone.trim();

      if (!firstName || !lastName || !phone) {
        if (msg) { msg.textContent = 'وارد کردن نام، نام خانوادگی و شماره موبایل الزامی است.'; msg.style.color = '#ef4444'; }
        return;
      }
      if (msg) { msg.textContent = 'در حال ثبت...'; msg.style.color = '#475569'; }
      submitBtn.disabled = true;

      var ajax = (window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '';
      var nonce = (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '';
      var fd = new FormData();
      fd.append('action', 'cptt_expert_create_customer');
      fd.append('nonce', nonce);
      fd.append('first_name', firstName);
      fd.append('last_name', lastName);
      fd.append('phone', phone);

      fetch(ajax, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          submitBtn.disabled = false;
          if (res && res.success) {
            if (msg) { msg.textContent = (res.data && res.data.message) ? res.data.message : 'مشتری با موفقیت ثبت شد!'; msg.style.color = (res.data && res.data.existing) ? '#b45309' : '#047857'; }
            document.querySelectorAll('select[name="client_user_id"]').forEach(function(sel) {
              var existing = sel.querySelector('option[value="' + res.data.ID + '"]');
              if (!existing) {
                var opt = document.createElement('option');
                opt.value = res.data.ID;
                opt.textContent = res.data.display_name;
                opt.dataset.search = [res.data.display_name || '', phone || ''].join(' ');
                sel.appendChild(opt);
              }
            });
            if (_activeClientSelect) {
              _activeClientSelect.value = String(res.data.ID);
              _activeClientSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
            setTimeout(function() {
              closeNewCustomerModal();
              ['cptt-cust-firstname','cptt-cust-lastname','cptt-cust-phone'].forEach(function(id){ var el=document.getElementById(id); if(el) el.value=''; });
              if (msg) msg.textContent = '';
            }, 650);
          } else {
            if (msg) { msg.textContent = (res && res.data) ? res.data : 'خطا در ثبت مشتری'; msg.style.color = '#ef4444'; }
          }
        })
        .catch(function() {
          submitBtn.disabled = false;
          if (msg) { msg.textContent = 'خطای شبکه'; msg.style.color = '#ef4444'; }
        });
    }, true);
  }

  /* =========================================================
     MANAGE FORM: FINANCE SUMMARY (live update)
     ========================================================= */
  function updateManageFinanceSummary(form) {
    var costEl = form.querySelector('.cptt-manage-fin-cost');
    var paidEl = form.querySelector('.cptt-manage-fin-paid');
    var remainEl = form.querySelector('.cptt-manage-fin-remain');
    if (!costEl || !paidEl || !remainEl) return;

    var totalCost = 0, totalPaid = 0;
    form.querySelectorAll('.cptt-currency-input[name*="[cost]"]').forEach(function(inp) {
      totalCost += parseFloat(inp.value.replace(/,/g,'')) || 0;
    });
    form.querySelectorAll('.cptt-currency-input[name*="[paid]"]').forEach(function(inp) {
      totalPaid += parseFloat(inp.value.replace(/,/g,'')) || 0;
    });

    var remain = totalCost - totalPaid;
    costEl.textContent = totalCost.toLocaleString('en');
    paidEl.textContent = totalPaid.toLocaleString('en');
    remainEl.textContent = remain.toLocaleString('en');
    remainEl.style.color = remain > 0 ? '#dc2626' : '#059669';
  }

  /* =========================================================
     DOMContentLoaded INIT
     ========================================================= */
  document.addEventListener('DOMContentLoaded', function() {

    // Init delivery for all manage forms already in DOM
    document.querySelectorAll('.cptt-expert-project-form').forEach(function(form) {
      initManageDelivery(form);
    });

    // Init new customer modal trigger for existing forms
    initNewCustomerModals();
    initClientSearchPickers();
    bindNewCustomerSubmit();

    // Watch for dynamically opened project cards (delegation)
    var expertGrid = document.getElementById('cptt-expert-grid');
    if (expertGrid && !(window.matchMedia && window.matchMedia('(max-width: 820px)').matches)) {
      var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
          m.addedNodes.forEach(function(node) {
            if (node.nodeType !== 1) return;
            node.querySelectorAll && node.querySelectorAll('.cptt-expert-project-form').forEach(function(form) {
              initManageDelivery(form);
            });
            // Re-init new customer triggers
            if (node.querySelector && node.querySelector('select[name="client_user_id"]')) {
              initNewCustomerModals();
              bindNewCustomerSubmit();
              initClientSearchPickers();
            }
          });
        });
      });
      observer.observe(expertGrid, { childList: true, subtree: true });
    }

    // Live finance summary update on step cost/paid change
    document.addEventListener('input', function(e) {
      if (e.target.classList.contains('cptt-currency-input')) {
        var form = e.target.closest('.cptt-expert-project-form');
        if (form) updateManageFinanceSummary(form);
      }
    });

    // Finance update after project form save
    document.addEventListener('cptt:projectSaved', function(e) {
      if (e.detail && e.detail.projectId) {
        var card = document.querySelector('.cptt-expertCard[data-project-id="' + e.detail.projectId + '"]');
        if (card) {
          var form = card.querySelector('.cptt-expert-project-form');
          if (form) updateManageFinanceSummary(form);
        }
      }
    });

  });

})();

/* =========================================================
   PUBLIC HUB: Expert Badge Modal + Project Detail Modal
   + Filters + روزهای هفته فارسی
   ========================================================= */
(function () {
  'use strict';

  /** UTF-8 safe base64 decode - fixes Persian/Arabic garbled text */
  function b64DecodeUtf8(b64) {
    try {
      var bin = atob(b64);
      var bytes = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
      if (window.TextDecoder) return new TextDecoder('utf-8').decode(bytes);
      // fallback for very old browsers
      return decodeURIComponent(escape(bin));
    } catch(e) { return ''; }
  }

  var PERSIAN_DAYS = ['یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنج‌شنبه','جمعه','شنبه'];

  /* ---------- Jalali helpers (mini) ---------- */
  function j2g(jy,jm,jd){
    jy=parseInt(jy,10)+1595;
    var days=-355668+(365*jy)+Math.floor(jy/33)*8+Math.floor(((jy%33)+3)/4)+parseInt(jd,10);
    days+=(jm<7)?((jm-1)*31):(((jm-7)*30)+186);
    var gy=400*Math.floor(days/146097);days%=146097;
    if(days>36524){gy+=100*Math.floor(--days/36524);days%=36524;if(days>=365)days++;}
    gy+=4*Math.floor(days/1461);days%=1461;
    if(days>365){gy+=Math.floor((days-1)/365);days=(days-1)%365;}
    var gd=days+1;
    var sal=[0,31,((gy%4===0&&gy%100!==0)||(gy%400===0))?29:28,31,30,31,30,31,31,30,31,30,31];
    var gm=1;for(;gm<=12;gm++){if(gd<=sal[gm])break;gd-=sal[gm];}
    return [gy,gm,gd];
  }

  function toEn(s){
    var fa='۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',en='01234567890123456789';
    return String(s||'').replace(/[۰-۹٠-٩]/g,function(c){return en[fa.indexOf(c)]||c;});
  }

  /** Extract day-of-week from a jalali datetime string like "۱۴۰۳/۰۲/۱۵ ۱۴:۳۰" */
  function jalaliDayOfWeek(faDateStr) {
    if (!faDateStr) return '';
    var s = toEn(String(faDateStr));
    var m = s.match(/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/);
    if (!m) return '';
    var jy=parseInt(m[1],10), jm=parseInt(m[2],10), jd=parseInt(m[3],10);
    var g = j2g(jy,jm,jd);
    var date = new Date(g[0], g[1]-1, g[2]);
    return PERSIAN_DAYS[date.getDay()] || '';
  }

  /** Append day-of-week to all date elements with class cptt-show-day */
  function appendDaysOfWeek() {
    document.querySelectorAll('.cptt-show-day').forEach(function(el) {
      var txt = el.getAttribute('data-date') || el.textContent;
      var day = jalaliDayOfWeek(txt);
      if (day && !el.querySelector('.cptt-dow')) {
        var span = document.createElement('span');
        span.className = 'cptt-dow';
        span.textContent = ' (' + day + ')';
        span.style.cssText = 'font-size:11px;opacity:0.7;font-weight:500;';
        el.appendChild(span);
      }
    });
  }

  /* =========================================================
     HUB MODAL for Project Details
     ========================================================= */
  function initHubProjectModal() {
    var modal = document.getElementById('cptt-hub-modal');
    if (!modal) return;

    var backdrop = modal.querySelector('.cptt-hubModal__backdrop');
    var closeBtn = modal.querySelector('.cptt-hubModal__close');
    var titleEl  = modal.querySelector('#cptt-hub-modal-title');
    var metaEl   = modal.querySelector('#cptt-hub-modal-meta');
    var bodyEl   = modal.querySelector('#cptt-hub-modal-body');

    function openModal(project) {
      if (titleEl) titleEl.textContent = project.title || '';

      // Meta chips
      var meta = [];
      if (project.customer) meta.push('👤 مشتری: ' + project.customer);
      if (project.customer_phone) meta.push('📞 موبایل: ' + project.customer_phone);
      if (project.customer_email) meta.push('✉️ ایمیل: ' + project.customer_email);
      if (project.experts && project.experts.length) meta.push('🧑‍💼 کارشناسان: ' + project.experts.join('، '));
      if (project.deadline) {
        var dlDay = jalaliDayOfWeek(project.deadline);
        meta.push('📅 مهلت: ' + project.deadline + (dlDay?' ('+dlDay+')':''));
      }
      if (project.product) meta.push('📦 محصول: ' + project.product);
      if (project.categories && project.categories.length) meta.push('🏷 دسته‌بندی: ' + project.categories.join('، '));
      if (metaEl) metaEl.innerHTML = meta.map(function(m){ return '<span class="cptt-hubModal__metaItem">' + escH(m) + '</span>'; }).join('');

      var html = '';

      // پیشرفت کلی
      var progress = project.progress || {};
      var pct = progress.percent || 0;
      html += '<div class="cptt-hubModal__progress">';
      html += '<div class="cptt-hubModal__progressBar"><div class="cptt-hubModal__progressFill" style="width:'+escH(String(pct))+'%"></div></div>';
      html += '<div class="cptt-hubModal__progressLabel">'+escH(String(pct))+'% پیشرفت — '+escH(progress.done||0)+'/'+escH(progress.total||0)+' مرحله</div>';
      html += '</div>';

      // خلاصه مالی (اگر full_details)
      if (project.full_details && project.financial) {
        var fin = project.financial;
        if (fin.cost > 0) {
          html += '<div class="cptt-hubModal__finRow">';
          html += '<div class="cptt-hubModal__finBox"><span>جمع هزینه</span><strong>'+escH(Number(fin.cost).toLocaleString('en'))+'</strong></div>';
          html += '<div class="cptt-hubModal__finBox"><span>دریافتی</span><strong style="color:#059669">'+escH(Number(fin.paid).toLocaleString('en'))+'</strong></div>';
          html += '<div class="cptt-hubModal__finBox"><span>مانده</span><strong style="color:'+(fin.remain>0?'#dc2626':'#059669')+'">'+escH(Number(fin.remain).toLocaleString('en'))+'</strong></div>';
          if (project.settled) html += '<div class="cptt-hubModal__finBox"><span>وضعیت</span><strong style="color:#059669">تسویه شده</strong></div>';
          html += '</div>';
        }
      }

      // اطلاعات تکمیلی ارسال/تحویل
      if (project.full_details && (project.delivery_method_label || project.delivery_province || project.delivery_city || project.delivery_address)) {
        html += '<div class="cptt-hubModal__stepsTitle">اطلاعات تحویل / ارسال</div>';
        html += '<div class="cptt-hubModal__detailsGrid">';
        if (project.delivery_method_label) html += '<div><span>روش</span><strong>'+escH(project.delivery_method_label)+'</strong></div>';
        if (project.delivery_province) html += '<div><span>استان</span><strong>'+escH(project.delivery_province)+'</strong></div>';
        if (project.delivery_city) html += '<div><span>شهر</span><strong>'+escH(project.delivery_city)+'</strong></div>';
        if (project.delivery_address) html += '<div style="grid-column:1/-1"><span>آدرس</span><strong>'+escH(project.delivery_address)+'</strong></div>';
        html += '</div>';
      }

      // یادداشت‌های پروژه
      if (project.full_details && project.notes && project.notes.length) {
        html += '<div class="cptt-hubModal__stepsTitle">یادداشت‌ها و گزارش‌ها</div>';
        html += '<div class="cptt-hubModal__notes">';
        project.notes.forEach(function(n){
          html += '<div class="cptt-hubModal__note">';
          html += '<div><strong>'+escH(n.author || n.name || 'کارشناس')+'</strong><span>'+escH(n.time_fa || n.time || '')+'</span></div>';
          html += '<p>'+escH(n.content || '')+'</p>';
          html += '</div>';
        });
        html += '</div>';
      }

      // مراحل
      var steps = (project.steps && Array.isArray(project.steps)) ? project.steps : [];
      if (steps.length) {
        html += '<div class="cptt-hubModal__stepsTitle">مراحل پروژه</div>';
        html += '<div class="cptt-hubModal__steps">';
        steps.forEach(function(s) {
          var st = s.status || 'todo';
          var stLabel = st==='done'?'انجام‌شده':st==='current'?'در حال انجام':'انجام‌نشده';
          var updDay = jalaliDayOfWeek(s.updated_at_fa || '');
          var dueDay = jalaliDayOfWeek(s.due_fa || '');

          html += '<div class="cptt-hubModal__step cptt-hubModal__step--'+escH(st)+'">';
          html += '<div class="cptt-hubModal__stepHead">';
          html += '<strong>'+escH(s.index||'')+'&nbsp;'+escH(s.title||'');
          if (s.experts && s.experts.length) {
              html += '<span class="cptt-step-toggle-avatars cptt-hubModal__stepExperts">';
              s.experts.forEach(function(ex){
                  html += '<img src="'+escH(ex.avatar)+'" title="'+escH(ex.name)+'" alt="'+escH(ex.name)+'">';
              });
              html += '</span>';
          }
          html += '</strong>';
          html += '<span class="cptt-expertStatusBadge cptt-expertStatusBadge--'+escH(st)+'">'+escH(stLabel)+'</span>';
          html += '</div>';

          if (s.due_fa)
            html += '<div class="cptt-hubModal__stepMeta">📅 مهلت: '+escH(s.due_fa+(dueDay?' ('+dueDay+')':''))+'</div>';
          if (s.updated_at_fa)
            html += '<div class="cptt-hubModal__stepMeta">🕐 آخرین بروزرسانی: '+escH(s.updated_at_fa+(updDay?' ('+updDay+')':''))+'</div>';
          if (s.desc)
            html += '<div class="cptt-hubModal__stepDesc">'+escH(s.desc)+'</div>';

          // چک‌لیست
          if (s.checklist_total > 0) {
            html += '<div class="cptt-hubModal__stepChecklist">';
            html += '<span>✅ چک‌لیست: '+escH(String(s.checklist_done))+'/'+escH(String(s.checklist_total))+'</span>';
            if (s.checklist_items && s.checklist_items.length) {
              html += '<ul class="cptt-hubModal__checkItems">';
              s.checklist_items.forEach(function(ci) {
                html += '<li class="'+(ci.done?'is-done':'')+'">';
                html += escH(ci.text||'');
                if (ci.done && ci.url) html += ' <a href="'+escH(ci.url)+'" target="_blank" rel="noopener">مشاهده نتیجه</a>';
                html += '</li>';
              });
              html += '</ul>';
            }
            html += '</div>';
          }

          // تسک مشتری
          if (s.user_tasks_total > 0) {
            html += '<div class="cptt-hubModal__stepChecklist" style="margin-top:10px;">';
            html += '<span>📋 تسک‌های سمت مشتری: ' + escH(String(s.user_tasks_done)) + '/' + escH(String(s.user_tasks_total)) + '</span>';
            if (s.user_tasks_items && s.user_tasks_items.length) {
              html += '<ul class="cptt-hubModal__checkItems" style="margin-top:6px; list-style:circle; padding-right:15px;">';
              s.user_tasks_items.forEach(function(ut) {
                var taskStatus = ut.done ? '<span style="color:#059669; font-weight:bold;">[تکمیل شده]</span>' : '<span style="color:#f59e0b; font-weight:bold;">[در انتظار پاسخ]</span>';
                html += '<li style="margin-bottom:6px;">';
                html += '<strong style="color:#0f172a;">' + escH(ut.title) + '</strong> ' + taskStatus;
                if (ut.desc) html += '<div style="font-size:11px; color:#64748b; margin-top:2px;">' + escH(ut.desc) + '</div>';
                if (ut.due_fa) html += '<div style="font-size:11px; color:#dc2626; margin-top:2px;">📅 مهلت تسک: ' + escH(ut.due_fa) + '</div>';
                if (ut.done && ut.response) html += '<div style="font-size:12px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:6px; margin-top:4px; color:#047857;">💬 پاسخ مشتری: ' + escH(ut.response) + '</div>';
                html += '</li>';
              });
              html += '</ul>';
            }
            html += '</div>';
          }

          if (project.full_details && (s.cost > 0 || s.paid > 0)) {
            var stepRemain = s.cost - s.paid;
            html += '<div style="font-size:11px; color:#475569; margin-top:8px; padding-top:6px; border-top:1px dashed #cbd5e1; display:flex; gap:12px; flex-wrap:wrap;">';
            html += '<span>💰 هزینه مرحله: <b>' + Number(s.cost).toLocaleString('en') + '</b> ریال</span>';
            html += '<span>💳 دریافتی: <b>' + Number(s.paid).toLocaleString('en') + '</b> ریال</span>';
            html += '<span>⏳ مانده: <b style="color:' + (stepRemain > 0 ? '#dc2626' : '#059669') + '">' + Number(stepRemain).toLocaleString('en') + '</b> ریال</span>';
            html += '</div>';
          }

          html += '</div>';
        });
        html += '</div>';
      } else {
        html += '<div class="cptt-empty">جزئیات مراحل در دسترس نیست.</div>';
      }

      if (bodyEl) bodyEl.innerHTML = html;
      modal.removeAttribute('hidden');
      document.body.style.overflow = 'hidden';
    }

    function closeModal() {
      modal.setAttribute('hidden', '');
      document.body.style.overflow = '';
    }

    if (backdrop) backdrop.addEventListener('click', closeModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeModal(); });

    // Delegate clicks for project open buttons
    document.addEventListener('click', function(e) {
      var btn = e.target.closest('.cptt-publicProject__open');
      if (!btn) return;
      var b64 = btn.getAttribute('data-project');
      if (!b64) return;
      try {
        var project = JSON.parse(b64DecodeUtf8(b64));
        openModal(project);
      } catch(ex) { console.error('CPTT hub modal parse error', ex); }
    });
  }

  /* =========================================================
     HUB MODAL for Expert Profile
     ========================================================= */
  function initHubExpertModal() {
    // Create expert modal if not exists
    var existingModal = document.getElementById('cptt-expert-profile-modal');
    if (!existingModal) {
      var m = document.createElement('div');
      m.id = 'cptt-expert-profile-modal';
      m.className = 'cptt-hubModal cptt-expertProfileModal';
      m.setAttribute('hidden', '');
      m.innerHTML = '<div class="cptt-hubModal__backdrop"></div>' +
        '<div class="cptt-hubModal__dialog" role="dialog" aria-modal="true">' +
          '<button type="button" class="cptt-hubModal__close" aria-label="بستن">×</button>' +
          '<div class="cptt-expertProfileModal__inner" id="cptt-expert-profile-content"></div>' +
        '</div>';
      document.body.appendChild(m);
      existingModal = m;
    }

    var modal = existingModal;
    var backdrop = modal.querySelector('.cptt-hubModal__backdrop');
    var closeBtn = modal.querySelector('.cptt-hubModal__close');
    var content  = modal.querySelector('#cptt-expert-profile-content');

    function openExpertModal(expert) {
      var html = '<div class="cptt-expertProfile">';
      
      // بخش بالا: آواتار + اسم + سمت
      html += '<div class="cptt-expertProfile__header">';
      html += '<div class="cptt-expertProfile__avatar">';
      var avatarSrc = expert.avatar || expert.avatar_url || '';
      if (avatarSrc) {
        html += '<img src="'+escH(avatarSrc)+'" alt="'+escH(expert.name||'')+'" loading="lazy">';
      } else {
        html += '<div class="cptt-expertProfile__avatarDefault">'+escH((expert.name||'?').charAt(0))+'</div>';
      }
      html += '</div>';
      html += '<div class="cptt-expertProfile__headerInfo">';
      html += '<h2 class="cptt-expertProfile__name">'+escH(expert.name||'کارشناس')+'</h2>';
      var title = expert.title || '';
      if (title) html += '<div class="cptt-expertProfile__title">'+escH(title)+'</div>';
      html += '</div></div>';

      // بیوگرافی
      var bio = expert.bio || '';
      if (bio) {
        html += '<div class="cptt-expertProfile__section">';
        html += '<div class="cptt-expertProfile__sectionTitle">درباره من</div>';
        html += '<div class="cptt-expertProfile__bio">'+escH(bio)+'</div>';
        html += '</div>';
      }

      // آمار پروژه‌ها
      var hasStats = (expert.active_projects !== undefined || expert.completed_projects !== undefined || expert.done_steps !== undefined);
      if (hasStats) {
        html += '<div class="cptt-expertProfile__section">';
        html += '<div class="cptt-expertProfile__sectionTitle">آمار پروژه‌ها</div>';
        html += '<div class="cptt-expertProfile__stats">';
        if (expert.active_projects !== undefined)
          html += '<div class="cptt-expertProfile__statBox"><strong>'+escH(String(expert.active_projects))+'</strong><span>پروژه فعال</span></div>';
        if (expert.completed_projects !== undefined)
          html += '<div class="cptt-expertProfile__statBox"><strong>'+escH(String(expert.completed_projects))+'</strong><span>پروژه تکمیل‌شده</span></div>';
        if (expert.done_steps !== undefined)
          html += '<div class="cptt-expertProfile__statBox"><strong>'+escH(String(expert.done_steps))+'</strong><span>مرحله انجام‌شده</span></div>';
        html += '</div></div>';
      }

      // تخصص‌ها
      var skills = expert.specialties || expert.skills || [];
      if (skills && skills.length) {
        html += '<div class="cptt-expertProfile__section">';
        html += '<div class="cptt-expertProfile__sectionTitle">تخصص‌ها</div>';
        html += '<div class="cptt-expertProfile__skills">';
        skills.forEach(function(sk){
          if (sk) html += '<span class="cptt-expertProfile__skill">'+escH(String(sk))+'</span>';
        });
        html += '</div></div>';
      }

      html += '</div>'; // end cptt-expertProfile

      if (content) content.innerHTML = html;
      modal.removeAttribute('hidden');
      document.body.style.overflow = 'hidden';
    }

    function closeModal() {
      modal.setAttribute('hidden','');
      document.body.style.overflow = '';
    }

    if (backdrop) backdrop.addEventListener('click', closeModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);

    // Delegate for expert badge clicks
    document.addEventListener('click', function(e) {
      var btn = e.target.closest('.cptt-expertBadge');
      if (!btn) return;
      var b64 = btn.getAttribute('data-expert');
      if (!b64) return;
      try {
        var expert = JSON.parse(b64DecodeUtf8(b64));
        openExpertModal(expert);
      } catch(ex) { console.error('CPTT expert modal parse error', ex); }
    });
  }

  /* =========================================================
     HUB FILTERS (search, expert, product, cat, deadline)
     ========================================================= */
  function initHubFilters() {
    var grid   = document.getElementById('cptt-hub-grid');
    var empty  = document.getElementById('cptt-hub-empty');
    var count  = document.getElementById('cptt-hub-count');
    var search = document.getElementById('cptt-hub-search');
    var selExp = document.getElementById('cptt-hub-expert');
    var selProd= document.getElementById('cptt-hub-product');
    var selCat = document.getElementById('cptt-hub-cat');
    var selDl  = document.getElementById('cptt-hub-deadline');
    var selLbl = document.getElementById('cptt-hub-label');
    var reset  = document.getElementById('cptt-hub-reset');

    if (!grid) return;

    function filter() {
      var cards = Array.prototype.slice.call(grid.querySelectorAll('.cptt-publicProject'));
      var q   = search ? search.value.toLowerCase() : '';
      var exp = selExp  ? selExp.value  : '';
      var prod= selProd ? selProd.value : '';
      var cat = selCat  ? selCat.value  : '';
      var dl  = selDl   ? selDl.value   : '';
      var lbl = selLbl  ? selLbl.value  : '';
      var vis = 0;

      cards.forEach(function(c) {
        var show = true;
        if (q && !(c.getAttribute('data-search')||'').toLowerCase().includes(q)) show = false;
        if (show && exp  && !(c.getAttribute('data-experts')||'').includes(','+exp+',')) show = false;
        if (show && prod && c.getAttribute('data-product') !== prod) show = false;
        if (show && cat  && !(c.getAttribute('data-cats')||'').includes(','+cat+',')) show = false;
        if (show && dl   && c.getAttribute('data-deadline') !== dl) show = false;
        if (show && lbl  && c.getAttribute('data-label') !== lbl) show = false;
        c.style.display = show ? '' : 'none';
        if (show) vis++;
      });

      if (count) count.textContent = vis;
      if (empty) empty[vis === 0 ? 'removeAttribute' : 'setAttribute']('hidden','');
    }

    [search, selExp, selProd, selCat, selDl, selLbl].forEach(function(el){
      if (el) el.addEventListener('input', filter);
    });

    if (reset) {
      reset.addEventListener('click', function() {
        [search, selExp, selProd, selCat, selDl, selLbl].forEach(function(el){ if(el) el.value=''; });
        filter();
      });
    }
  }

  /* =========================================================
     روزهای هفته در کارت‌های پروژه داشبورد کارشناس
     ========================================================= */
  function injectDaysOfWeekInCards() {
    // Expert dashboard cards
    document.querySelectorAll('.cptt-expertCard__infoGrid [data-date], .cptt-expertCard__meta[data-date]').forEach(function(el){
      var txt = el.getAttribute('data-date') || el.textContent;
      var day = jalaliDayOfWeek(txt);
      if (day && !el.querySelector('.cptt-dow')) {
        var sp = document.createElement('span');
        sp.className = 'cptt-dow';
        sp.textContent = ' (' + day + ')';
        el.appendChild(sp);
      }
    });

    // Public hub project cards
    document.querySelectorAll('.cptt-project__meta[data-date], .cptt-publicProject__meta[data-date]').forEach(function(el){
      var txt = el.getAttribute('data-date') || el.textContent;
      var day = jalaliDayOfWeek(txt);
      if (day && !el.querySelector('.cptt-dow')) {
        var sp = document.createElement('span');
        sp.className = 'cptt-dow';
        sp.textContent = ' (' + day + ')';
        el.appendChild(sp);
      }
    });
  }

  /* =========================================================
     STEP ASSIGNED EXPERT MODAL
     ========================================================= */
  function initStepExpertModal() {
    if (!document.getElementById('cptt-step-expert-modal')) {
      var m = document.createElement('div');
      m.id = 'cptt-step-expert-modal';
      m.setAttribute('hidden','');
      m.innerHTML =
        '<div class="cptt-stepExpertModal__backdrop"></div>' +
        '<div class="cptt-stepExpertModal__dialog">' +
          '<button type="button" class="cptt-stepExpertModal__close" aria-label="بستن">×</button>' +
          '<div class="cptt-stepExpertModal__title">انتخاب کارشناسان مسئول مرحله</div>' +
          '<div class="cptt-stepExpertModal__list" id="cptt-sep-list"></div>' +
        '</div>';
      document.body.appendChild(m);
      var bd = m.querySelector('.cptt-stepExpertModal__backdrop');
      var cl = m.querySelector('.cptt-stepExpertModal__close');
      function closeModal(){ m.setAttribute('hidden',''); document.body.style.overflow=''; m._hiddenInput=null; m._stepEl=null; m._experts=[]; }
      if (bd) bd.addEventListener('click', closeModal);
      if (cl) cl.addEventListener('click', closeModal);
    }

    document.addEventListener('click', function(e) {
      var btn = e.target.closest('.cptt-step-expert-btn');
      if (!btn) return;

      var stepEl = btn.closest('[data-step-id]');
      if (!stepEl) return;
      var stepId = stepEl.getAttribute('data-step-id');

      // hidden input با نام صحیح
      var hiddenInput = stepEl.querySelector('input[name*="[assigned_expert_id]"]');
      var currentVals = Array.prototype.map.call(stepEl.querySelectorAll('.cptt-step-expert-hidden-list input[type="hidden"]'), function(inp){ return String(inp.value); });
      if (!currentVals.length && hiddenInput && hiddenInput.value) currentVals = [String(hiddenInput.value)];

      // گرفتن لیست کارشناسان از article card
      var card = btn.closest('[data-project-experts]');
      var experts = [];
      if (card) {
        var b64 = card.getAttribute('data-project-experts');
        if (b64) {
          try { experts = JSON.parse(b64DecodeUtf8(b64)); } catch(e) {}
        }
      }

      // fallback: از checkboxهای کارشناس در همان فرم
      if (!experts.length) {
        var form = btn.closest('form');
        if (form) {
          form.querySelectorAll('input[name="expert_user_ids[]"]').forEach(function(cb) {
            var lbl = cb.closest('label');
            var nm = lbl ? (lbl.querySelector('span')||{textContent:cb.value}).textContent.trim() : cb.value;
            experts.push({ id: String(cb.value), name: nm });
          });
        }
      }

      if (!experts.length) {
        alert('هیچ کارشناسی برای این پروژه تعیین نشده است.');
        return;
      }

      var modal = document.getElementById('cptt-step-expert-modal');
      var listEl = document.getElementById('cptt-sep-list');

      var html = '<div class="cptt-sep-option cptt-sep-option--hint">یک یا چند کارشناس را انتخاب کنید. برای پاک کردن انتخاب، همه را بردارید.</div>';
      experts.forEach(function(ex) {
        var chk = (currentVals.indexOf(String(ex.id)) !== -1) ? ' checked' : '';
        var avatar = ex.avatar ? '<img class="cptt-sep-avatar" src="'+escH(ex.avatar)+'" alt="">' : '<span class="cptt-sep-avatar cptt-sep-avatar--empty">👤</span>';
        html += '<div class="cptt-sep-option">' +
          '<label><input type="checkbox" name="sep_choice[]" value="'+escH(String(ex.id))+'"'+chk+'> ' +
          avatar + '<span>'+escH(ex.name||String(ex.id))+'</span></label>' +
          '</div>';
      });

      html += '<div class="cptt-sep-actions"><button type="button" class="cptt-btn cptt-btn--primary cptt-sep-confirm">✔ تأیید انتخاب</button></div>';

      if (listEl) listEl.innerHTML = html;
      modal._hiddenInput = hiddenInput;
      modal._stepEl = stepEl;
      modal._stepId = stepId;
      modal._experts = experts;
      modal.removeAttribute('hidden');
      document.body.style.overflow = 'hidden';
    });

    // تأیید انتخاب
    document.addEventListener('click', function(e) {
      if (!e.target.classList.contains('cptt-sep-confirm')) return;
      var modal = document.getElementById('cptt-step-expert-modal');
      if (!modal) return;
      var checked = Array.prototype.slice.call(modal.querySelectorAll('input[name="sep_choice[]"]:checked'));
      var vals = checked.map(function(ch){ return String(ch.value); });
      var val = vals.length ? vals[0] : '';
      var experts = modal._experts || [];

      // ذخیره در hidden inputها
      if (modal._hiddenInput) { modal._hiddenInput.value = val; }
      var stepId = modal._stepEl ? (modal._stepEl.getAttribute('data-step-id') || '') : '';
      if (modal._stepEl) {
        var list = modal._stepEl.querySelector('.cptt-step-expert-hidden-list');
        if (list) {
          list.innerHTML = vals.map(function(v){ return '<input type="hidden" name="steps[' + escH(stepId) + '][assigned_expert_ids][]" value="' + escH(v) + '">'; }).join('');
        }
      }

      // آپدیت متن دکمه + آواتارها کنار عنوان
      if (modal._stepEl) {
        var dispBtn = modal._stepEl.querySelector('.cptt-step-expert-btn');
        if (dispBtn) {
          if (vals.length) {
            var names = vals.map(function(v){ var found = experts.filter(function(ex){ return String(ex.id)===String(v); }); return found.length ? found[0].name : v; });
            dispBtn.textContent = '👥 ' + names.join('، ');
            dispBtn.classList.add('has-expert');
          } else {
            dispBtn.textContent = '👥 انتخاب کارشناسان مرحله';
            dispBtn.classList.remove('has-expert');
          }
        }
        var titleStrong = modal._stepEl.querySelector('.cptt-expert-step__toggleMain strong');
        if (titleStrong) {
          var oldAv = titleStrong.querySelector('.cptt-step-toggle-avatars'); if (oldAv) oldAv.remove();
          if (vals.length) {
            var avWrap = document.createElement('span'); avWrap.className = 'cptt-step-toggle-avatars';
            vals.forEach(function(v){ var found = experts.filter(function(ex){ return String(ex.id)===String(v); })[0]; if(found && found.avatar){ var img=document.createElement('img'); img.src=found.avatar; img.alt=''; avWrap.appendChild(img); } });
            if (avWrap.children.length) titleStrong.appendChild(avWrap);
          }
        }
      }

      // ✅ v5.4.17: ذخیره فوری انتخاب کارشناسان مرحله در سرور (auto-save)
      // تا حتی اگر کاربر کل پروژه را Save نکند، آواتار کنار عنوان بعد رفرش بماند.
      try {
        var card = modal._stepEl ? modal._stepEl.closest('.cptt-expertCard') : null;
        var projectId = card ? card.getAttribute('data-project-id') : '';
        if (projectId && stepId && window.CPTT_EXPERT && CPTT_EXPERT.ajax) {
          var fd = new FormData();
          fd.append('action', 'cptt_expert_save_step_experts');
          fd.append('nonce', CPTT_EXPERT.nonce || '');
          fd.append('project_id', projectId);
          fd.append('step_id', stepId);
          if (vals.length) {
            vals.forEach(function(v){ fd.append('expert_ids[]', v); });
          } else {
            fd.append('expert_ids[]', '');
          }
          fetch(CPTT_EXPERT.ajax, { method:'POST', credentials:'same-origin', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(j){
              if (!j || !j.success) return;

              // Update the hidden last_update input in the form to prevent concurrent edit conflicts
              if (j.data && j.data.last_update) {
                var form = card ? card.querySelector('.cptt-expert-project-form') : null;
                if (form) {
                  var lastUpInput = form.querySelector('input[name="loaded_last_update"]');
                  if (lastUpInput) lastUpInput.value = j.data.last_update;
                }
              }

              // در صورت نیاز آواتار را با پاسخ سرور هم به‌روزرسانی کن
              var avs = (j.data && j.data.avatars) ? j.data.avatars : null;
              if (!avs || !avs.length || !modal._stepEl) return;
              var titleStrong2 = modal._stepEl.querySelector('.cptt-expert-step__toggleMain strong');
              if (!titleStrong2) return;
              var oldAv2 = titleStrong2.querySelector('.cptt-step-toggle-avatars'); if (oldAv2) oldAv2.remove();
              var wrap2 = document.createElement('span'); wrap2.className = 'cptt-step-toggle-avatars';
              avs.forEach(function(a){ if (a && a.avatar) { var img = document.createElement('img'); img.src = a.avatar; img.alt = a.name || ''; img.title = a.name || ''; wrap2.appendChild(img); } });
              if (wrap2.children.length) titleStrong2.appendChild(wrap2);
            })
            .catch(function(){ /* silent */ });
        }
      } catch(e) {}

      modal.setAttribute('hidden','');
      document.body.style.overflow='';
    });
  }

  function escH(s) {
    return String(s||'').replace(/[&<>"']/g,function(c){
      return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[c];
    });
  }

  /* =========================================================
     DOMContentLoaded
     ========================================================= */
  document.addEventListener('DOMContentLoaded', function() {
    initHubProjectModal();
    initHubExpertModal();
    initHubFilters();
    initStepExpertModal();
    injectDaysOfWeekInCards();
    appendDaysOfWeek();

    // Re-run on card expand (MutationObserver for dynamic content)
    var grid = document.getElementById('cptt-expert-grid');
    if (grid && !(window.matchMedia && window.matchMedia('(max-width: 820px)').matches)) {
      var obs = new MutationObserver(function(muts) {
        muts.forEach(function(m) {
          if (m.type === 'attributes' && m.attributeName === 'hidden') {
            injectDaysOfWeekInCards();
          }
          if (m.type === 'childList') injectDaysOfWeekInCards();
        });
      });
      obs.observe(grid, { attributes: true, childList: true, subtree: true });
    }
  });

})();

/* =========================================================
   EDIT PROFILE MODAL - داشبورد کارشناس
   ========================================================= */
(function() {
  'use strict';

  document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('cptt-edit-profile-modal');
    if (!modal) return;

    var backdrop = document.getElementById('cptt-edit-profile-backdrop');
    var closeBtn = modal.querySelector('.cptt-editProfileModal__close');
    var form = document.getElementById('cptt-edit-profile-form');
    var msgEl = document.getElementById('cptt-ep-msg');

    function openModal() {
      modal.removeAttribute('hidden');
      document.body.style.overflow = 'hidden';
    }
    function closeModal() {
      modal.setAttribute('hidden', '');
      document.body.style.overflow = '';
    }

    if (backdrop) backdrop.addEventListener('click', closeModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeModal(); });

    // دکمه ویرایش پروفایل در sidebar
    document.querySelectorAll('[href*="cptt_edit_profile=1"], .cptt-open-edit-profile').forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        openModal();
      });
    });

    // Avatar upload handled by separate IIFE below

    // ارسال فرم
    if (form) {
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (msgEl) { msgEl.textContent = 'در حال ذخیره...'; msgEl.style.color = '#6366f1'; }

        var fd = new FormData(form);
        fd.append('action', 'cptt_expert_save_profile');
        fd.append('nonce', window.CPTT_EXPERT ? CPTT_EXPERT.nonce : '');

        fetch(window.CPTT_EXPERT ? CPTT_EXPERT.ajax : '', { method: 'POST', body: fd })
          .then(function(r){ return r.json(); })
          .then(function(res) {
            if (res.success) {
              if (msgEl) { msgEl.textContent = '✓ ' + (res.data.message || 'ذخیره شد!'); msgEl.style.color = '#059669'; }
              setTimeout(function() { closeModal(); }, 1500);
            } else {
              if (msgEl) { msgEl.textContent = '✗ ' + (res.data || 'خطا در ذخیره'); msgEl.style.color = '#dc2626'; }
            }
          })
          .catch(function() {
            if (msgEl) { msgEl.textContent = '✗ خطای شبکه'; msgEl.style.color = '#dc2626'; }
          });
      });
    }
  });
})();

/* =========================================================
   REDESIGNED Expert Profile Modal (Public Hub)
   + Avatar Upload via File Input with Crop
   ========================================================= */
(function() {
  'use strict';
  function escH(s) { return String(s||'').replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[c];}); }

  // Override the existing expert modal renderer
  var oldModal = document.getElementById('cptt-expert-profile-modal');
  if (oldModal) {
    // Rebind badge clicks to use new renderer
    // document.removeEventListener('click', _cpttOldExpertHandler);
  }

  function b64DecodeUtf8(b64) {
    try {
      var bin = atob(b64);
      var bytes = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
      return window.TextDecoder ? new TextDecoder('utf-8').decode(bytes) : decodeURIComponent(escape(bin));
    } catch(e) { return ''; }
  }

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.cptt-expertBadge');
    if (!btn) return;
    e.stopPropagation();
    var b64 = btn.getAttribute('data-expert');
    if (!b64) return;
    try {
      var expert = JSON.parse(b64DecodeUtf8(b64));
      openRedesignedExpertModal(expert);
    } catch(ex) { console.error(ex); }
  }, true); // capture phase to override old handler

  function openRedesignedExpertModal(expert) {
    var modal = document.getElementById('cptt-expert-profile-modal');
    if (!modal) return;
    var content = modal.querySelector('#cptt-expert-profile-content');
    if (!content) return;

    var avatarSrc = expert.avatar || '';
    var name = expert.name || 'کارشناس';
    var title = expert.title || '';
    var bio = expert.bio || '';
    var skills = expert.specialties || [];

    var html = '';
    // Hero section with gradient
    html += '<div class="cptt-expertProfile__hero">';
    html += '<div class="cptt-expertProfile__avatarFloat">';
    if (avatarSrc) {
      html += '<img src="'+escH(avatarSrc)+'" alt="'+escH(name)+'">';
    } else {
      html += '<div class="cptt-expertProfile__avatarDefault2">'+escH(name.charAt(0))+'</div>';
    }
    html += '</div>';
    html += '<h2 class="cptt-expertProfile__name">'+escH(name)+'</h2>';
    if (title) html += '<div class="cptt-expertProfile__title">'+escH(title)+'</div>';
    html += '</div>';

    // Body
    html += '<div class="cptt-expertProfile__body">';

    // Stats
    html += '<div class="cptt-expertProfile__statsRow">';
    html += '<div class="cptt-expertProfile__statCard"><strong>'+escH(String(expert.active_projects||0))+'</strong><span>پروژه فعال</span></div>';
    html += '<div class="cptt-expertProfile__statCard"><strong>'+escH(String(expert.completed_projects||0))+'</strong><span>تکمیل شده</span></div>';
    html += '</div>';

    // Bio
    if (bio) {
      html += '<div class="cptt-expertProfile__bioText">'+escH(bio)+'</div>';
    }

    // Skills
    if (skills.length) {
      html += '<div class="cptt-expertProfile__skillsWrap">';
      skills.forEach(function(sk) {
        if (sk) html += '<span class="cptt-expertProfile__skillTag">'+escH(sk)+'</span>';
      });
      html += '</div>';
    }

    html += '</div>';

    content.innerHTML = html;
    modal.removeAttribute('hidden');
    document.body.style.overflow = 'hidden';
  }
})();

/* =========================================================
   AVATAR UPLOAD v3 - Bulletproof crop with inline styles
   ========================================================= */
(function(){
  'use strict';
  document.addEventListener('DOMContentLoaded', function(){
    var btn = document.getElementById('cptt-ep-avatar-btn');
    if (!btn) return;

    var fi = document.createElement('input');
    fi.type = 'file'; fi.accept = 'image/*';
    fi.style.cssText = 'position:fixed;left:-9999px;opacity:0;';
    document.body.appendChild(fi);

    btn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); fi.value=''; fi.click(); });

    fi.addEventListener('change', function(){
      if (!fi.files || !fi.files[0]) return;
      var rd = new FileReader();
      rd.onload = function(ev){ startCrop(ev.target.result); };
      rd.readAsDataURL(fi.files[0]);
    });

    var _m=null, _img=null, _s=1, _px=0, _py=0, _drag=false, _dx=0, _dy=0, _lp=0, RING=220, OUT=400;

    function startCrop(src){
      endCrop();
      var d = document.createElement('div');
      d.id='cptt-avm';
      // All styles inline - no CSS conflicts possible
      d.style.cssText='position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;padding:12px;';
      d.innerHTML =
        '<div style="position:absolute;inset:0;background:rgba(15,23,42,.75);backdrop-filter:blur(6px);" id="cptt-avbg"></div>'+
        '<div style="position:relative;background:#fff;border-radius:20px;padding:20px;width:320px;max-width:92vw;box-shadow:0 30px 60px rgba(0,0,0,.3);text-align:center;direction:rtl;" id="cptt-avbox">'+
          '<div style="font-size:15px;font-weight:900;color:#0f172a;margin:0 0 4px;">تنظیم عکس پروفایل</div>'+
          '<div style="font-size:11px;color:#94a3b8;margin:0 0 12px;">با انگشت یا موس جابجا کنید · اسکرول یا پینچ برای زوم</div>'+
          '<div id="cptt-avring" style="width:'+RING+'px;height:'+RING+'px;margin:0 auto 12px;border-radius:50%;overflow:hidden;border:3px solid #c7d2fe;position:relative;background:#f1f5f9;touch-action:none;cursor:grab;">'+
            '<img id="cptt-avimg" src="'+src+'" draggable="false" style="position:absolute;display:block;pointer-events:none;user-select:none;max-width:none !important;max-height:none !important;min-width:0 !important;min-height:0 !important;width:auto;height:auto;">'+
          '</div>'+
          '<input type="range" id="cptt-avzoom" min="20" max="500" value="100" style="width:90%;margin:0 auto 14px;display:block;accent-color:#6366f1;">'+
          '<div style="display:flex;gap:8px;">'+
            '<button type="button" id="cptt-avok" style="flex:1;padding:10px 0;border-radius:12px;border:none;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-size:14px;font-weight:800;cursor:pointer;min-height:44px;">ثبت عکس</button>'+
            '<button type="button" id="cptt-avno" style="flex:1;padding:10px 0;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#334155;font-size:14px;font-weight:800;cursor:pointer;min-height:44px;">انصراف</button>'+
          '</div>'+
        '</div>';
      document.body.appendChild(d);
      _m = d;
      _img = document.getElementById('cptt-avimg');
      var ring = document.getElementById('cptt-avring');
      var zoom = document.getElementById('cptt-avzoom');
      _s=100; _px=0; _py=0;

      _img.onload = function(){
        var fit = RING / Math.min(_img.naturalWidth, _img.naturalHeight);
        _s = Math.max(20, Math.round(fit*100));
        zoom.value = _s;
        _px=0; _py=0;
        paint();
      };

      zoom.oninput = function(){ _s = +this.value; paint(); };

      ring.addEventListener('wheel', function(e){
        e.preventDefault();
        _s += (e.deltaY<0?8:-8);
        _s = Math.max(20,Math.min(500,_s));
        zoom.value = _s;
        paint();
      }, {passive:false});

      ring.addEventListener('mousedown', function(e){ _drag=true; _dx=e.clientX-_px; _dy=e.clientY-_py; e.preventDefault(); });
      var mmv = function(e){ if(!_drag) return; _px=e.clientX-_dx; _py=e.clientY-_dy; paint(); };
      var mup = function(){ _drag=false; };
      document.addEventListener('mousemove', mmv);
      document.addEventListener('mouseup', mup);

      ring.addEventListener('touchstart', function(e){
        if(e.touches.length===2){
          _lp=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY);
          e.preventDefault();
        } else if(e.touches.length===1){
          _drag=true; _dx=e.touches[0].clientX-_px; _dy=e.touches[0].clientY-_py; e.preventDefault();
        }
      }, {passive:false});
      ring.addEventListener('touchmove', function(e){
        if(e.touches.length===2){
          var nd=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY);
          _s+=(nd-_lp)*0.4; _s=Math.max(20,Math.min(500,_s)); zoom.value=Math.round(_s); _lp=nd; paint(); e.preventDefault();
        } else if(_drag&&e.touches.length===1){
          _px=e.touches[0].clientX-_dx; _py=e.touches[0].clientY-_dy; paint(); e.preventDefault();
        }
      }, {passive:false});
      ring.addEventListener('touchend', function(){ _drag=false; _lp=0; });

      document.getElementById('cptt-avno').onclick = endCrop;
      document.getElementById('cptt-avbg').onclick = endCrop;

      document.getElementById('cptt-avok').onclick = function(){
        var b = this; b.disabled=true; b.textContent='آپلود...';
        // Create canvas matching ring view exactly
        var c = document.createElement('canvas');
        c.width=OUT; c.height=OUT;
        var ctx = c.getContext('2d');
        var sc = _s/100;
        var iw = _img.naturalWidth*sc;
        var ih = _img.naturalHeight*sc;
        var half = RING/2;
        // Image position in ring: centered at (half+_px, half+_py) with size (iw, ih)
        // imgLeft = half - iw/2 + _px, imgTop = half - ih/2 + _py
        var ratio = OUT/RING;
        var cx = (half - iw/2 + _px)*ratio;
        var cy = (half - ih/2 + _py)*ratio;
        ctx.drawImage(_img, cx, cy, iw*ratio, ih*ratio);

        c.toBlob(function(blob){
          if(!blob){ b.disabled=false; b.textContent='ثبت عکس'; return; }
          var fd = new FormData();
          fd.append('action','cptt_expert_upload_avatar');
          fd.append('nonce', window.CPTT_EXPERT?CPTT_EXPERT.nonce:'');
          fd.append('avatar_file', blob, 'avatar.jpg');
          fetch(window.CPTT_EXPERT?CPTT_EXPERT.ajax:'',{method:'POST',credentials:'same-origin',body:fd})
          .then(function(r){return r.json();})
          .then(function(j){
            if(j.success&&j.data){
              var p=document.getElementById('cptt-ep-avatar-preview');
              if(p) p.innerHTML='<img src="'+j.data.url+'" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
              var h=document.getElementById('cptt-ep-avatar-id');
              if(h) h.value=j.data.id;
              endCrop();
            } else {
              alert(j.data||'خطا'); b.disabled=false; b.textContent='ثبت عکس';
            }
          }).catch(function(){ alert('خطای شبکه'); b.disabled=false; b.textContent='ثبت عکس'; });
        },'image/jpeg',0.92);
      };

      // Clean up on close
      _m._cleanup = function(){ document.removeEventListener('mousemove',mmv); document.removeEventListener('mouseup',mup); };
    }

    function paint(){
      if(!_img) return;
      var sc=_s/100;
      var w=_img.naturalWidth*sc;
      var h=_img.naturalHeight*sc;
      var half=RING/2;
      /* استفاده از setProperty با important تا قواعد img{max-width:100%;height:auto} تم وردپرس override شود
         این باگ باعث میشد عکس‌های غیرمربعی هنگام زوم کشیده شوند. */
      _img.style.setProperty('width', w+'px', 'important');
      _img.style.setProperty('height', h+'px', 'important');
      _img.style.setProperty('max-width', 'none', 'important');
      _img.style.setProperty('max-height', 'none', 'important');
      _img.style.setProperty('min-width', '0', 'important');
      _img.style.setProperty('min-height', '0', 'important');
      _img.style.setProperty('left', (half-w/2+_px)+'px', 'important');
      _img.style.setProperty('top', (half-h/2+_py)+'px', 'important');
      _img.style.setProperty('position', 'absolute', 'important');
    }

    function endCrop(){
      var m=document.getElementById('cptt-avm');
      if(m){ if(m._cleanup) m._cleanup(); m.remove(); }
      _m=null; _img=null; _drag=false; fi.value='';
    }
  });
})();

/* v5.4.10 isolated client search button - create project only */
(function(){
  function bindClientSearch(){
    document.querySelectorAll('.cptt-expert-create-form select[name="client_user_id"], .cptt-expert-project-form select[name="client_user_id"]').forEach(function(sel){
      if (sel.dataset.cpttRobustClientSearch) return;
      sel.dataset.cpttRobustClientSearch = '1';
      var wrap = document.createElement('div');
      wrap.className = 'cptt-clientSearchCreate';
      var searchRow = document.createElement('div');
      searchRow.className = 'cptt-clientSearchCreate__search';
      searchRow.hidden = true;
      var inp = document.createElement('input');
      inp.type = 'search'; inp.className = 'cptt-clientSearchCreate__input'; inp.placeholder = 'جستجوی نام، نام خانوادگی یا شماره تماس...';
      var results = document.createElement('div');
      results.className = 'cptt-clientSearchCreate__results';
      results.hidden = true;
      var fieldRow = document.createElement('div');
      fieldRow.className = 'cptt-clientSearchCreate__field';
      var btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'cptt-clientSearchCreate__btn'; btn.textContent = '🔎'; btn.setAttribute('aria-label','جستجوی مشتری');
      sel.parentNode.insertBefore(wrap, sel);
      searchRow.appendChild(inp); searchRow.appendChild(results); fieldRow.appendChild(sel); fieldRow.appendChild(btn); wrap.appendChild(searchRow); wrap.appendChild(fieldRow);
      btn.addEventListener('click', function(){ searchRow.hidden = !searchRow.hidden; if (!searchRow.hidden) setTimeout(function(){ inp.focus(); }, 30); });
      function renderResults(){
        var q = String(inp.value || '').toLowerCase().trim();
        var matches = [];
        Array.prototype.forEach.call(sel.options, function(opt){
          if (!opt.value || opt.value === 'new_customer_trigger') { opt.hidden = false; return; }
          var h = String((opt.getAttribute('data-search') || '') + ' ' + opt.textContent).toLowerCase();
          var ok = !!q && h.indexOf(q) !== -1;
          opt.hidden = !!q && !ok;
          if (ok && matches.length < 12) matches.push(opt);
        });
        if (!q) { results.hidden = true; results.innerHTML = ''; return; }
        if (!matches.length) { results.hidden = false; results.innerHTML = '<div class="cptt-clientSearchCreate__empty">نتیجه‌ای یافت نشد</div>'; return; }
        results.hidden = false;
        results.innerHTML = matches.map(function(opt){ return '<button type="button" data-value="' + String(opt.value).replace(/"/g,'&quot;') + '">' + opt.textContent + '</button>'; }).join('');
      }
      inp.addEventListener('input', renderResults);
      results.addEventListener('click', function(e){
        var b = e.target.closest('button[data-value]'); if (!b) return;
        sel.value = b.getAttribute('data-value');
        sel.dispatchEvent(new Event('change', {bubbles:true}));
        inp.value = b.textContent;
        results.hidden = true;
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindClientSearch); else bindClientSearch();
  document.addEventListener('click', function(e){ if(e.target.closest('[data-cptt-open-newproject], .cptt-newProjectCta, .cptt-expert-toggleProject')) setTimeout(bindClientSearch, 100); });
})();

/* v5.4.24 Daily Mood Tracker - smooth snapping UI */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  ready(function(){
    var modal = document.getElementById('cptt-mood-modal');
    if(!modal || modal.dataset.v5424 === '1') return;
    modal.dataset.v5424 = '1';
    var card = modal.querySelector('.cptt-moodCard');
    var range = document.getElementById('cptt-mood-range');
    var text = document.getElementById('cptt-mood-text');
    var emoji = document.getElementById('cptt-mood-emoji');
    var note = document.getElementById('cptt-mood-note');
    var submit = document.getElementById('cptt-mood-submit');
    var noteToggle = document.getElementById('cptt-mood-note-toggle');
    var msg = document.getElementById('cptt-mood-msg');
    var mouth = document.getElementById('cptt-mood-mouth');
    var eyeL = document.getElementById('cptt-mood-eye-l');
    var eyeR = document.getElementById('cptt-mood-eye-r');
    var wrap = modal.querySelector('.cptt-moodSliderWrap');
    var states = {
      1: { cls:'is-bad', label:'اصلاً خوب نیستم', mouth:'M78 112 Q110 82 142 112', eyeR:15, eyeY:62, emoji: modal.dataset.em1 || '😟', pct:0 },
      2: { cls:'is-mid', label:'بد نیستم/معمولی', mouth:'M82 105 Q110 105 138 105', eyeR:16, eyeY:58, emoji: modal.dataset.em2 || '😐', pct:50 },
      3: { cls:'is-good', label:'عالی و پرانرژی', mouth:'M76 92 Q110 128 146 92', eyeR:24, eyeY:56, emoji: modal.dataset.em3 || '😄', pct:100 }
    };
    var currentMood = 2;
    var raf = 0;

    var visual = document.createElement('div');
    visual.className = 'cptt-moodSnapSlider';
    visual.innerHTML = '<div class="cptt-moodSnapSlider__track"><span class="cptt-moodSnapSlider__fill"></span><i class="cptt-moodSnapSlider__thumb"></i></div>';
    if (wrap && range) wrap.insertBefore(visual, range.nextSibling);
    var fill = visual.querySelector('.cptt-moodSnapSlider__fill');
    var thumb = visual.querySelector('.cptt-moodSnapSlider__thumb');

    function nearestMoodFromClientX(x){
      var rect = visual.getBoundingClientRect();
      var ratio = rect.width ? Math.max(0, Math.min(1, (x - rect.left) / rect.width)) : .5;
      if (ratio < .25) return 1;
      if (ratio > .75) return 3;
      return 2;
    }
    function setVisual(v){
      var pct = states[v].pct;
      if(fill) fill.style.width = pct + '%';
      if(thumb) thumb.style.transform = 'translate(-50%,-50%)';
      if(thumb) thumb.style.left = pct + '%';
      if(range) range.value = String(v);
    }
    function animateSwap(el, value){
      if(!el || el.textContent === value) return;
      el.classList.remove('is-animating');
      el.textContent = value;
      requestAnimationFrame(function(){ el.classList.add('is-animating'); });
    }
    function applyMood(v){
      v = Math.max(1, Math.min(3, parseInt(v || 2, 10)));
      if(!states[v]) v = 2;
      if (v === currentMood && card.dataset.mood === String(v)) { setVisual(v); return; }
      currentMood = v;
      if(raf) cancelAnimationFrame(raf);
      raf = requestAnimationFrame(function(){
        card.dataset.mood = String(v);
        card.classList.remove('is-bad','is-mid','is-good');
        card.classList.add(states[v].cls);
        animateSwap(text, states[v].label);
        animateSwap(emoji, states[v].emoji);
        if(mouth) mouth.setAttribute('d', states[v].mouth);
        if(eyeL && eyeR){
          eyeL.setAttribute('r', states[v].eyeR); eyeR.setAttribute('r', states[v].eyeR);
          eyeL.setAttribute('cy', states[v].eyeY); eyeR.setAttribute('cy', states[v].eyeY);
        }
        setVisual(v);
      });
    }
    function choose(v){ applyMood(v); }
    function handlePoint(ev){
      var x = ev.touches && ev.touches[0] ? ev.touches[0].clientX : ev.clientX;
      choose(nearestMoodFromClientX(x));
    }
    var dragging = false;
    visual.addEventListener('pointerdown', function(e){ dragging = true; visual.setPointerCapture && visual.setPointerCapture(e.pointerId); handlePoint(e); });
    visual.addEventListener('pointermove', function(e){ if(dragging) handlePoint(e); });
    visual.addEventListener('pointerup', function(e){ dragging = false; handlePoint(e); });
    visual.addEventListener('pointercancel', function(){ dragging = false; });
    visual.addEventListener('click', handlePoint);
    if(range) {
      range.addEventListener('input', function(){ choose(Math.round(parseFloat(range.value || '2'))); });
      range.addEventListener('change', function(){ choose(Math.round(parseFloat(range.value || '2'))); });
    }
    function hide(){ modal.classList.add('is-closing'); setTimeout(function(){ modal.remove(); document.body.classList.remove('cptt-mood-open'); }, 260); }
    function save(){
      if(submit) submit.disabled = true;
      if(msg) { msg.textContent = 'در حال ثبت...'; msg.style.color = 'currentColor'; }
      var fd = new FormData();
      fd.append('action','cptt_expert_save_mood');
      fd.append('nonce',(window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
      fd.append('mood', String(currentMood));
      fd.append('note', note ? note.value : '');
      fd.append('closed', '0');
      fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', {method:'POST', credentials:'same-origin', body:fd})
        .then(function(r){return r.json();})
        .then(function(res){ if(res && res.success){ hide(); } else { if(submit) submit.disabled=false; if(msg){msg.textContent=(res&&res.data)?res.data:'خطا در ثبت'; msg.style.color='#b91c1c';} } })
        .catch(function(){ if(submit) submit.disabled=false; if(msg){msg.textContent='خطای شبکه'; msg.style.color='#b91c1c';} });
    }
    document.body.classList.add('cptt-mood-open');
    applyMood(2);
    if(noteToggle && note) noteToggle.addEventListener('click', function(){
      var isHidden = note.hasAttribute('hidden');
      if(isHidden){ note.removeAttribute('hidden'); setTimeout(function(){note.focus();},30); }
      else { note.setAttribute('hidden',''); }
    });
    if(submit) submit.addEventListener('click', save);
  });
})();


/* =========================================================
   HAM v5.5.8 — finance helpers, floating save hard-fix, badges
   ========================================================= */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function num(v){ return parseFloat(String(v||'').replace(/,/g,'')) || 0; }
  function fmt(n){ return (Math.round((parseFloat(n)||0)*100)/100).toLocaleString('en'); }
  function qsa(s,c){ return Array.prototype.slice.call((c||document).querySelectorAll(s)); }

  function forceBadgesWhite(){ qsa('.cptt-bell-badge').forEach(function(b){ b.style.setProperty('color','#fff','important'); b.style.setProperty('text-shadow','none','important'); }); }

  function updateStepFinance(step){
    if(!step) return;
    var unit = step.querySelector('.cptt-step-unit-price');
    var qty = step.querySelector('.cptt-step-qty');
    var cost = step.querySelector('.cptt-step-cost, input[name*="[cost]"]');
    if(unit && qty && cost){
      var total = num(unit.value) * (parseFloat(qty.value)||1);
      cost.value = fmt(total);
      cost.dispatchEvent(new Event('input',{bubbles:true}));
    }
  }

  function enhanceManageFinancialFields(scope){
    qsa('.cptt-expert-step', scope||document).forEach(function(step){
      var cost = step.querySelector('input[name*="[cost]"]');
      var paid = step.querySelector('input[name*="[paid]"]');
      var qty = step.querySelector('input[name*="[qty]"]');
      var unit = step.querySelector('input[name*="[unit_price]"]');
      if(cost) cost.classList.add('cptt-step-cost','cptt-currency-input');
      if(paid) paid.classList.add('cptt-step-paid','cptt-currency-input');
      if(qty) qty.classList.add('cptt-step-qty');
      if(unit) unit.classList.add('cptt-step-unit-price','cptt-currency-input');
      var grids = qsa('.cptt-expert-step__metaGrid', step);
      var finGrid = grids.length > 1 ? grids[1] : (cost ? cost.closest('.cptt-expert-step__metaGrid') : null);
      if(finGrid && cost && !step.querySelector('.cptt-step-unit-price')){
        var stepId = step.getAttribute('data-step-id') || ('step_'+Date.now());
        var unitLbl = document.createElement('label');
        unitLbl.innerHTML = '<span>مبلغ فی (ریال)</span><input type="text" class="cptt-currency-input cptt-step-unit-price" name="steps['+stepId+'][unit_price]" value="0">';
        var qtyLbl = document.createElement('label');
        qtyLbl.innerHTML = '<span>تعداد</span><input type="number" step="any" min="0.01" class="cptt-step-qty" name="steps['+stepId+'][qty]" value="1">';
        finGrid.insertBefore(unitLbl, finGrid.firstChild);
        finGrid.insertBefore(qtyLbl, cost.closest('label'));
        cost.classList.add('cptt-step-cost');
        var title = cost.closest('label') && cost.closest('label').querySelector('span'); if(title) title.textContent = 'جمع مرحله (ریال)';
      }
    });
  }

  function updateManageSummary(form){
    if(!form) return;
    var totalCost=0,totalPaid=0;
    qsa('.cptt-step-cost, .cptt-currency-input[name*="[cost]"]', form).forEach(function(i){ totalCost += num(i.value); });
    qsa('.cptt-step-paid, .cptt-currency-input[name*="[paid]"]', form).forEach(function(i){ totalPaid += num(i.value); });
    var c=form.querySelector('.cptt-manage-fin-cost'), p=form.querySelector('.cptt-manage-fin-paid'), r=form.querySelector('.cptt-manage-fin-remain');
    if(c) c.textContent = fmt(totalCost); if(p) p.textContent = fmt(totalPaid); if(r){ r.textContent=fmt(totalCost-totalPaid); r.style.color=(totalCost-totalPaid)>0?'#dc2626':'#059669'; }
  }

  function addSettleButtons(){
    var createSummary = document.querySelector('#cptt-create-total-price');
    if(createSummary && !document.querySelector('#cptt-create-settle-all')){
      var btn=document.createElement('button'); btn.type='button'; btn.id='cptt-create-settle-all'; btn.className='cptt-btn cptt-btn--settle'; btn.textContent='تسویه کل پروژه';
      (createSummary.closest('.cptt-createProjectGrid') || createSummary.parentNode).appendChild(btn);
      btn.addEventListener('click', function(){ qsa('.cptt-create-finance-row').forEach(function(row){ var cost=row.querySelector('.cptt-create-step-cost'), paid=row.querySelector('.cptt-create-step-paid'); if(cost&&paid){ paid.value=cost.value; paid.dispatchEvent(new Event('input',{bubbles:true})); }}); });
    }
    qsa('.cptt-expert-project-form').forEach(function(form){
      if(form.querySelector('.cptt-manage-settle-all')) return;
      var footer=form.querySelector('.cptt-expert-formActions') || form.querySelector('.cptt-expert-formFooter'); if(!footer) return;
      var btn=document.createElement('button'); btn.type='button'; btn.className='cptt-btn cptt-btn--settle cptt-manage-settle-all'; btn.textContent='تسویه کل پروژه';
      footer.insertBefore(btn, footer.firstChild);
      btn.addEventListener('click', function(){ enhanceManageFinancialFields(form); qsa('.cptt-expert-step', form).forEach(function(step){ var cost=step.querySelector('.cptt-step-cost, input[name*="[cost]"]'), paid=step.querySelector('.cptt-step-paid, input[name*="[paid]"]'); if(cost&&paid){ paid.value=cost.value; paid.dispatchEvent(new Event('input',{bubbles:true})); }}); updateManageSummary(form); });
    });
  }

  function hardFloatingSave(){
    var activeCard=document.querySelector('.cptt-expertCard.is-expanded');
    var activeForm=activeCard ? activeCard.querySelector('.cptt-expert-project-form') : null;
    var bodyBtn=document.querySelector('body > .cptt-expert-save-floating');
    var cardBtn=activeCard ? activeCard.querySelector('.cptt-expert-save-floating') : null;
    var btn=cardBtn || bodyBtn;
    if(!activeCard || !activeForm || !btn){ if(bodyBtn) bodyBtn.style.display='none'; return; }
    if(!activeForm.id) activeForm.id='cptt-manage-form-'+(activeCard.getAttribute('data-project-id')||Date.now());
    btn.setAttribute('form', activeForm.id);
    btn.style.display='inline-flex';
    btn.style.zIndex='999995';
    if(btn.parentNode !== document.body) document.body.appendChild(btn);
  }

  ready(function(){
    forceBadgesWhite(); setTimeout(forceBadgesWhite,500); setTimeout(forceBadgesWhite,1500);
    enhanceManageFinancialFields(document); addSettleButtons(); hardFloatingSave();
    document.addEventListener('click', function(e){
      if(e.target.closest('.cptt-expert-toggleProject')) setTimeout(function(){ enhanceManageFinancialFields(document); addSettleButtons(); hardFloatingSave(); },80);
      if(e.target.closest('.cptt-expert-add-step')) setTimeout(function(){ enhanceManageFinancialFields(document); addSettleButtons(); },80);
    });
    document.addEventListener('input', function(e){
      if(e.target.matches('.cptt-step-unit-price,.cptt-step-qty')) updateStepFinance(e.target.closest('.cptt-expert-step'));
      var form=e.target.closest('.cptt-expert-project-form'); if(form && e.target.matches('.cptt-currency-input,.cptt-step-paid,.cptt-step-cost')) updateManageSummary(form);
      if(e.target.classList && e.target.classList.contains('cptt-bell-badge')) forceBadgesWhite();
    });
    window.addEventListener('resize', hardFloatingSave);
    window.addEventListener('scroll', function(){ var b=document.querySelector('body > .cptt-expert-save-floating'); if(b) b.style.zIndex='999995'; }, {passive:true});
  });
})();

/* =========================================================
   HAM v5.5.9 — finance layout + modal-aware floating save
   ========================================================= */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function qsa(s,c){ return Array.prototype.slice.call((c||document).querySelectorAll(s)); }
  function num(v){ return parseFloat(String(v||'').replace(/,/g,'')) || 0; }
  function fmt(n){ return (Math.round((parseFloat(n)||0)*100)/100).toLocaleString('en'); }
  function visible(el){ return el && !el.hidden && el.offsetParent !== null; }
  function anyModalOpen(){ return qsa('.cptt-expert-chatModal,.cptt-direct-chat-modal,.cptt-newProjectModal,.cptt-editProfileModal,.cptt-stepExpertModal,.cptt-debtors-modal,.cptt-all-notifs-modal,.cptt-mobile-menu,.cptt-experts-mobile-modal,.cptt-hubModal,#cptt-new-customer-modal').some(function(m){ return !m.hasAttribute('hidden') && getComputedStyle(m).display !== 'none' && m.id !== ''; }); }
  function ensureFinanceGrid(step){
    if(!step) return;
    var unit = step.querySelector('.cptt-step-unit-price');
    var qty = step.querySelector('.cptt-step-qty');
    var cost = step.querySelector('.cptt-step-cost, input[name*="[cost]"]');
    var paid = step.querySelector('.cptt-step-paid, input[name*="[paid]"]');
    if(!cost || !paid) return;
    cost.classList.add('cptt-step-cost'); paid.classList.add('cptt-step-paid');
    if(unit) unit.classList.add('cptt-step-unit-price'); if(qty) qty.classList.add('cptt-step-qty');
    var grid = step.querySelector('.cptt-step-finance-grid');
    if(!grid){
      grid = document.createElement('div'); grid.className='cptt-step-finance-grid';
      var firstGrid = step.querySelector('.cptt-expert-step__metaGrid');
      if(firstGrid) firstGrid.insertAdjacentElement('afterend', grid); else step.insertBefore(grid, step.firstChild);
    }
    if(!unit){ var sid=step.getAttribute('data-step-id')||Date.now(); var l=document.createElement('label'); l.innerHTML='<span>مبلغ فی</span><input type="text" class="cptt-currency-input cptt-step-unit-price" name="steps['+sid+'][unit_price]" value="0">'; unit=l.querySelector('input'); grid.appendChild(l); }
    if(!qty){ var sid2=step.getAttribute('data-step-id')||Date.now(); var q=document.createElement('label'); q.className='cptt-step-finance-qty'; q.innerHTML='<span>تعداد</span><input type="number" step="any" min="0.01" class="cptt-step-qty" name="steps['+sid2+'][qty]" value="1">'; qty=q.querySelector('input'); grid.appendChild(q); }
    [unit,qty,cost,paid].forEach(function(inp){ var lab=inp && inp.closest('label'); if(lab && lab.parentNode!==grid) grid.appendChild(lab); });
    if(qty && qty.closest('label')) qty.closest('label').classList.add('cptt-step-finance-qty');
    if(cost && cost.closest('label')) { cost.closest('label').classList.add('cptt-step-finance-cost'); var sp=cost.closest('label').querySelector('span'); if(sp) sp.textContent='جمع کل'; }
    if(paid && paid.closest('label')) { paid.closest('label').classList.add('cptt-step-finance-paid'); var sp2=paid.closest('label').querySelector('span'); if(sp2) sp2.textContent='پرداختی'; }
    if(unit && unit.closest('label')) { var sp3=unit.closest('label').querySelector('span'); if(sp3) sp3.textContent='مبلغ فی'; }
    if(!grid.querySelector('.cptt-step-finance-remain')){
      var r=document.createElement('label'); r.className='cptt-step-finance-remain'; r.innerHTML='<span>مانده</span><input type="text" readonly class="cptt-step-remain" value="0">'; grid.appendChild(r);
    }
    updateRemain(step);
  }
  function updateRemain(step){
    var unit=step.querySelector('.cptt-step-unit-price'), qty=step.querySelector('.cptt-step-qty'), cost=step.querySelector('.cptt-step-cost'), paid=step.querySelector('.cptt-step-paid'), rem=step.querySelector('.cptt-step-remain');
    if(unit&&qty&&cost){ var total=num(unit.value)*(parseFloat(qty.value)||1); if(document.activeElement!==cost) cost.value=fmt(total); }
    if(cost&&paid&&rem){ var remain=num(cost.value)-num(paid.value); rem.value=fmt(Math.max(0,remain)); rem.closest('label').style.display = remain>0 ? '' : 'none'; }
  }
  function layoutAllFinance(){ qsa('.cptt-expert-step').forEach(ensureFinanceGrid); }
  function moveSettleButtonsToProjectInfo(){
    qsa('.cptt-expert-project-form').forEach(function(form){
      var btn=form.querySelector('.cptt-manage-settle-all'); var meta=form.querySelector('.cptt-expert-projectMeta .cptt-createProjectGrid, .cptt-expert-projectMeta');
      if(btn && meta && btn.parentNode!==meta){ btn.classList.add('cptt-settle-in-meta'); meta.appendChild(btn); }
    });
  }
  function modalAwareSave(){
    var btn=document.querySelector('body > .cptt-expert-save-floating'); if(!btn) return;
    btn.style.zIndex='999995';
    btn.style.display = anyModalOpen() ? 'none' : 'inline-flex';
  }
  ready(function(){
    layoutAllFinance(); moveSettleButtonsToProjectInfo(); modalAwareSave();
    document.addEventListener('click', function(e){ if(e.target.closest('.cptt-expert-toggleProject,.cptt-expert-add-step')) setTimeout(function(){layoutAllFinance(); moveSettleButtonsToProjectInfo(); modalAwareSave();},100); });
    document.addEventListener('input', function(e){ if(e.target.matches('.cptt-step-unit-price,.cptt-step-qty,.cptt-step-cost,.cptt-step-paid')) updateRemain(e.target.closest('.cptt-expert-step')); });
    document.addEventListener('click', function(){ setTimeout(modalAwareSave, 80); });
  });
})();

/* =========================================================
   HAM v5.5.10 — safe sticky filters fallback + mobile FAB hard size
   ========================================================= */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function qsa(s,c){ return Array.prototype.slice.call((c||document).querySelectorAll(s)); }
  function setupStickyFilter(el){
    if(!el || el.dataset.cpttStickyBound) return;
    el.dataset.cpttStickyBound='1';
    var ph=document.createElement('div');
    ph.className='cptt-filter-sticky-placeholder';
    ph.style.display='none';
    el.parentNode.insertBefore(ph, el);
    function update(){
      if(!document.body.contains(el)) return;
      if(el.classList.contains('cptt-filter-fixed')){
        var prect=ph.getBoundingClientRect();
        if(prect.top > 10){
          el.classList.remove('cptt-filter-fixed');
          el.style.width=''; el.style.left=''; el.style.right=''; el.style.top='';
          ph.style.display='none'; ph.style.height='0px';
          return;
        }
        var wrect=ph.getBoundingClientRect();
        el.style.width=Math.round(wrect.width)+'px';
        el.style.left=Math.round(wrect.left)+'px';
        el.style.right='auto';
        return;
      }
      var rect=el.getBoundingClientRect();
      if(rect.top <= 10 && window.scrollY > 80){
        ph.style.height=Math.round(rect.height)+'px';
        ph.style.display='block';
        el.classList.add('cptt-filter-fixed');
        var prect=ph.getBoundingClientRect();
        el.style.width=Math.round(prect.width)+'px';
        el.style.left=Math.round(prect.left)+'px';
        el.style.right='auto';
        el.style.top='10px';
      }
    }
    var stickyTicking = false;
    window.addEventListener('scroll', function(){
      if (stickyTicking) return;
      stickyTicking = true;
      requestAnimationFrame(function(){ update(); stickyTicking = false; });
    }, {passive:true});
    window.addEventListener('resize', function(){ if(el.classList.contains('cptt-filter-fixed')){ el.classList.remove('cptt-filter-fixed'); el.style.cssText=''; ph.style.display='none'; } setTimeout(update,80); }, {passive:true});
    setTimeout(update,100);
  }
  ready(function(){ if (!(window.matchMedia && window.matchMedia('(max-width: 820px)').matches)) qsa('.cptt-expertFilters,.cptt-hubFilters').forEach(setupStickyFilter); });
})();

/* =========================================================
   HAM v5.5.12 — admin pages embedded as dashboard content (no iframe)
   ========================================================= */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  ready(function(){
    var panel=document.getElementById('cptt-admin-bridge-panel');
    var content=document.getElementById('cptt-admin-bridge-content');
    var title=document.getElementById('cptt-admin-bridge-title');
    var close=document.getElementById('cptt-admin-bridge-close');
    var main=document.querySelector('.cptt-expertMain');
    if(!panel || !content || !main) return;
    function setActive(btn){ document.querySelectorAll('.cptt-admin-bridge__item').forEach(function(x){x.classList.remove('is-active');}); if(btn) btn.classList.add('is-active'); }
    function showHome(){
      panel.hidden=true;
      main.classList.remove('cptt-admin-bridge-mode');
      content.innerHTML='<div class="cptt-admin-bridge-loading">در حال بارگذاری...</div>';
      setActive(document.querySelector('.cptt-admin-bridge__item[data-admin-page="home"]'));
      try{ window.scrollTo({top:0, behavior:'smooth'}); }catch(e){}
    }
    function loadPage(page, btn, extra){
      extra = extra || {};
      panel.dataset.page = page;
      if(page === 'home'){ showHome(); return; }
      setActive(btn);
      main.classList.add('cptt-admin-bridge-mode');
      panel.hidden=false;
      if(title) title.textContent = btn ? btn.textContent.trim() : 'مدیریت افزونه';
      content.innerHTML='<div class="cptt-admin-bridge-loading">در حال بارگذاری...</div>';
      var fd=new FormData();
      fd.append('action','cptt_expert_admin_bridge_page');
      fd.append('nonce',(window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
      fd.append('page',page);
      if(extra.subtab) fd.append('subtab', extra.subtab);
      if(extra.form_id) fd.append('form_id', extra.form_id);
      fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', {method:'POST', credentials:'same-origin', body:fd})
        .then(function(r){return r.json();})
        .then(function(res){
          if(res && res.success){ if(title) title.textContent=res.data.title || (btn?btn.textContent.trim():''); content.innerHTML=res.data.html || ''; document.dispatchEvent(new CustomEvent('cptt:adminBridgeLoaded',{detail:{container:content,page:page}})); }
          else content.innerHTML='<div class="cptt-notice">خطا در بارگذاری صفحه.</div>'; document.dispatchEvent(new CustomEvent('cptt:adminBridgeLoaded',{detail:{container:content,page:page}}));
          panel.scrollIntoView({behavior:'smooth', block:'start'});
        })
        .catch(function(){ content.innerHTML='<div class="cptt-notice">خطای ارتباط با سرور.</div>'; });
    }
    document.querySelectorAll('.cptt-admin-bridge__item[data-admin-page]').forEach(function(btn){
      btn.addEventListener('click', function(){ loadPage(btn.getAttribute('data-admin-page') || 'home', btn); });
    });

    content.addEventListener('submit', function(e){
      var form=e.target;
      if(!form || !form.matches('#cptt-settings-form,#cptt-pay-form')) return;
      e.preventDefault();
      var page = form.matches('#cptt-pay-form') ? 'payments' : 'settings';
      var btn=form.querySelector('[type="submit"]');
      if(btn){ btn.disabled=true; btn.dataset.oldText=btn.textContent; btn.textContent='در حال ذخیره...'; }
      var fd=new FormData(form);
      fd.append('action','cptt_expert_admin_bridge_page');
      fd.append('nonce',(window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
      fd.append('page', page);
      fd.append('bridge_save','1');
      fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', {method:'POST', credentials:'same-origin', body:fd})
        .then(function(r){return r.json();})
        .then(function(res){
          if(res && res.success){ content.innerHTML=res.data.html || ''; document.dispatchEvent(new CustomEvent('cptt:adminBridgeLoaded',{detail:{container:content,page:page}})); if(title) title.textContent=res.data.title || title.textContent; }
          else alert('خطا در ذخیره');
        })
        .catch(function(){ alert('خطای ارتباط با سرور'); })
        .finally(function(){ if(btn){ btn.disabled=false; btn.textContent=btn.dataset.oldText||'ذخیره'; } });
    });
    content.addEventListener('click', function(e){
      var a=e.target.closest('a');
      if(!a) return;
      var href=a.getAttribute('href')||'';
      if(href.indexOf('page=cptt-settings')!==-1){
        e.preventDefault();
        var u=new URL(href, window.location.href);
        loadPage('settings', document.querySelector('.cptt-admin-bridge__item[data-admin-page="settings"]'), {subtab:u.searchParams.get('tab')||'style'});
      } else if(href.indexOf('page=cptt-form-builder')!==-1){
        e.preventDefault();
        var uf=new URL(href, window.location.href);
        loadPage('form_builder', document.querySelector('.cptt-admin-bridge__item[data-admin-page="form_builder"]'), {form_id:uf.searchParams.get('form_id')||0});
      }
    });
    if(close) close.addEventListener('click', showHome);
  });
})();

/* =========================================================
   HAM v5.5.20 — embedded scripts, accounting modal pagination, fluid bottom nav
   ========================================================= */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function qsa(s,c){ return Array.prototype.slice.call((c||document).querySelectorAll(s)); }
  function execInlineScripts(container){
    if(!container) return;
    qsa('script', container).forEach(function(old){
      var sc=document.createElement('script');
      Array.prototype.slice.call(old.attributes).forEach(function(a){ sc.setAttribute(a.name,a.value); });
      sc.text = old.textContent || '';
      old.parentNode.replaceChild(sc, old);
    });
  }
  window.cpttExecEmbeddedScripts = execInlineScripts;

  function initAccountingModalPagination(scope){
    scope = scope || document;
    var table = scope.querySelector('#cptt-acct-table');
    if(!table || table.dataset.modalized) return;
    table.dataset.modalized='1';
    var wrap = table.closest('.cptt-acct-table-wrap'); if(!wrap) return;
    var btn = document.createElement('button');
    btn.type='button'; btn.className='cptt-btn cptt-acct-projects-open'; btn.textContent='📋 مشاهده لیست پروژه‌ها';
    wrap.parentNode.insertBefore(btn, wrap);
    var modal=document.createElement('div'); modal.className='cptt-acct-projects-modal'; modal.hidden=true;
    modal.innerHTML='<div class="cptt-acct-projects-modal__backdrop"></div><div class="cptt-acct-projects-modal__dialog"><div class="cptt-acct-projects-modal__head"><strong>لیست پروژه‌ها</strong><button type="button" class="cptt-acct-projects-modal__close">×</button></div><div class="cptt-acct-projects-modal__body"></div><div class="cptt-acct-projects-pager"><button type="button" data-dir="prev">قبلی</button><span></span><button type="button" data-dir="next">بعدی</button></div></div>';
    wrap.parentNode.insertBefore(modal, btn.nextSibling);
    modal.querySelector('.cptt-acct-projects-modal__body').appendChild(wrap);
    var rows=qsa('tbody tr.cptt-acct-row', table), page=1, per=10;
    function visibleRows(){ return rows.filter(function(r){ return r.dataset.filteredOut !== '1' && r.style.display !== 'none'; }); }
    function render(){ var vr=visibleRows(), pages=Math.max(1,Math.ceil(vr.length/per)); if(page>pages)page=pages; rows.forEach(function(r){r.style.display='none';}); vr.slice((page-1)*per,page*per).forEach(function(r){r.style.display='';}); modal.querySelector('.cptt-acct-projects-pager span').textContent='صفحه '+page+' از '+pages+' — '+vr.length+' پروژه'; }
    btn.addEventListener('click', function(){ modal.hidden=false; document.body.style.overflow='hidden'; page=1; render(); });
    function close(){ modal.hidden=true; document.body.style.overflow=''; }
    modal.querySelector('.cptt-acct-projects-modal__close').addEventListener('click', close);
    modal.querySelector('.cptt-acct-projects-modal__backdrop').addEventListener('click', close);
    modal.querySelector('[data-dir="prev"]').addEventListener('click', function(){ if(page>1){page--;render();} });
    modal.querySelector('[data-dir="next"]').addEventListener('click', function(){ page++;render(); });
    document.addEventListener('input', function(e){ if(e.target.closest('.cptt-acct-filters')) setTimeout(function(){ page=1; render(); },80); });
    document.addEventListener('change', function(e){ if(e.target.closest('.cptt-acct-filters')) setTimeout(function(){ page=1; render(); },80); });
  }
  window.cpttInitAccountingModalPagination = initAccountingModalPagination;

  function initFluidBottomNav(){
    if(!document.body.classList.contains('cptt-expert-dashboard-page') || document.querySelector('.cptt-fluid-nav')) return;
    var nav=document.createElement('nav'); nav.className='cptt-fluid-nav'; nav.dir='rtl';
    nav.innerHTML='<div class="cptt-fluid-nav__blob"></div>'+[
      ['home','خانه','M3 10.5 12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-6H8v6H3a1 1 0 0 1-1-1z'],
      ['projects','پروژه‌ها','M4 6h6l2 2h8v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z'],
      ['chats','چت‌ها','M21 12a8 8 0 0 1-8 8H7l-4 3 1.5-5A8 8 0 1 1 21 12z'],
      ['profile','پروفایل','M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0 2c-5 0-8 2.5-8 5v1h16v-1c0-2.5-3-5-8-5z'],
      ['save','ذخیره','M5 13l4 4L19 7']
    ].map(function(i){return '<button type="button" class="cptt-fluid-nav__item" data-tab="'+i[0]+'" title="'+i[1]+'"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="'+i[2]+'"></path></svg></span><small>'+i[1]+'</small></button>';}).join('');
    document.body.appendChild(nav);
    var items=qsa('.cptt-fluid-nav__item',nav), blob=nav.querySelector('.cptt-fluid-nav__blob');
    function setActive(btn){ items.forEach(function(b){b.classList.remove('is-active');}); btn.classList.add('is-active'); var r=btn.getBoundingClientRect(), nr=nav.getBoundingClientRect(); blob.style.transform='translateX('+Math.round(r.left-nr.left+r.width/2-28)+'px)'; }
    items.forEach(function(btn){ btn.addEventListener('click', function(){ var t=btn.dataset.tab; setActive(btn); if(t==='home') window.scrollTo({top:0,behavior:'smooth'}); if(t==='projects'){ var g=document.getElementById('cptt-expert-grid'); if(g) g.scrollIntoView({behavior:'smooth'}); } if(t==='chats'){ var b=document.querySelector('.cptt-open-experts-modal-btn'); if(b) b.click(); } if(t==='profile'){ var p=document.querySelector('.cptt-sideBox--profile'); if(p) p.scrollIntoView({behavior:'smooth'}); } if(t==='save'){ var s=document.querySelector('body > .cptt-expert-save-floating,.cptt-expertCard.is-expanded .cptt-expert-save-floating'); if(s && getComputedStyle(s).display!=='none') s.click(); } }); });
    setActive(items[0]);
    function syncSave(){ var open=!!document.querySelector('.cptt-expertCard.is-expanded'); nav.classList.toggle('has-save', open); }
    document.addEventListener('click', function(e){ if(e.target.closest('.cptt-expert-toggleProject')) setTimeout(syncSave,120); }); syncSave(); window.addEventListener('resize', function(){ var a=nav.querySelector('.is-active')||items[0]; setActive(a); }, {passive:true});
  }

  ready(function(){
    window.ajaxurl = window.ajaxurl || ((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '/wp-admin/admin-ajax.php');
    initAccountingModalPagination(document);
    initFluidBottomNav();
  });
  document.addEventListener('cptt:adminBridgeLoaded', function(e){ execInlineScripts(e.detail && e.detail.container); initAccountingModalPagination(e.detail && e.detail.container); });
})();

/* =========================================================
   HAM v5.5.21 — mobile gooey nav, accounting table modal UX, save sync
   ========================================================= */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function qsa(s,c){ return Array.prototype.slice.call((c||document).querySelectorAll(s)); }
  function isMobile(){ return window.matchMedia && window.matchMedia('(max-width: 820px)').matches; }

  function syncFloatingSaveVisibility(){
    var expanded = !!document.querySelector('.cptt-expertCard.is-expanded');
    qsa('body > .cptt-expert-save-floating,.cptt-expertCard .cptt-expert-save-floating').forEach(function(btn){
      if (isMobile()) btn.style.display = 'none';
      else btn.style.display = expanded ? 'inline-flex' : 'none';
      btn.style.zIndex = '999995';
    });
    document.body.classList.toggle('cptt-project-manage-open', expanded);
  }

  function repairAccountingTableModal(scope){
    scope = scope || document;
    var table = scope.querySelector('#cptt-acct-table');
    if(!table || table.dataset.hamModalRepaired) return;
    table.dataset.hamModalRepaired='1';
    var wrap = table.closest('.cptt-acct-table-wrap'); if(!wrap) return;
    var oldBtn = scope.querySelector('.cptt-acct-projects-open'); if(oldBtn) oldBtn.remove();
    var oldModal = scope.querySelector('.cptt-acct-projects-modal');
    var originalParent = wrap.parentNode;
    var placeholder = document.createElement('div'); placeholder.className='cptt-acct-table-placeholder';
    originalParent.insertBefore(placeholder, wrap);
    if (oldModal && oldModal.querySelector('.cptt-acct-projects-modal__body') && wrap.parentNode !== originalParent) {
      originalParent.insertBefore(wrap, placeholder.nextSibling);
    }
    var modal = oldModal || document.createElement('div');
    if(!oldModal){
      modal.className='cptt-acct-projects-modal'; modal.hidden=true;
      modal.innerHTML='<div class="cptt-acct-projects-modal__backdrop"></div><div class="cptt-acct-projects-modal__dialog"><div class="cptt-acct-projects-modal__head"><strong>لیست پروژه‌ها</strong><button type="button" class="cptt-acct-projects-modal__close">×</button></div><div class="cptt-acct-projects-modal__body"></div><div class="cptt-acct-projects-pager"><button type="button" data-dir="prev">قبلی</button><span></span><button type="button" data-dir="next">بعدی</button></div></div>';
      originalParent.insertBefore(modal, placeholder.nextSibling);
    }
    var body = modal.querySelector('.cptt-acct-projects-modal__body');
    var rows = qsa('tbody tr.cptt-acct-row', table), page=1, per=10;
    function filteredRows(){ return rows.filter(function(r){ return r.dataset.filteredOut !== '1'; }); }
    function render(){
      var inModal = !modal.hidden;
      var visible = filteredRows();
      var pages = Math.max(1, Math.ceil(visible.length/per)); if(page>pages) page=pages;
      rows.forEach(function(r){ r.style.display = inModal ? 'none' : ''; });
      if(inModal) visible.slice((page-1)*per,page*per).forEach(function(r){ r.style.display=''; });
      var sp=modal.querySelector('.cptt-acct-projects-pager span'); if(sp) sp.textContent='صفحه '+page+' از '+pages+' — '+visible.length+' پروژه';
    }
    function open(){
      page=1; body.appendChild(wrap); modal.hidden=false; document.body.style.overflow='hidden'; render();
    }
    function close(){
      modal.hidden=true; document.body.style.overflow=''; originalParent.insertBefore(wrap, placeholder.nextSibling); rows.forEach(function(r){ r.style.display=''; });
    }
    wrap.classList.add('cptt-acct-table-clickable');
    wrap.addEventListener('click', function(e){
      if(!modal.hidden) return;
      if(e.target.closest('a,button,input,select,textarea')) return;
      open();
    });
    modal.querySelector('.cptt-acct-projects-modal__close').onclick=close;
    modal.querySelector('.cptt-acct-projects-modal__backdrop').onclick=close;
    modal.querySelector('[data-dir="prev"]').onclick=function(){ if(page>1){page--;render();} };
    modal.querySelector('[data-dir="next"]').onclick=function(){ page++;render(); };
    document.addEventListener('input', function(e){ if(e.target.closest('.cptt-acct-filters')) setTimeout(function(){ page=1; render(); },80); });
    document.addEventListener('change', function(e){ if(e.target.closest('.cptt-acct-filters')) setTimeout(function(){ page=1; render(); },80); });
  }

  function initGooeyMobileNav(){
    if(!document.body.classList.contains('cptt-expert-dashboard-page')) return;
    // Remove previous bad nav if it exists.
    qsa('.cptt-fluid-nav').forEach(function(n){ n.remove(); });
    if(document.querySelector('.ham-gooey-nav')) return;
    var defs=document.createElement('div');
    defs.className='ham-gooey-svg-defs';
    defs.innerHTML='<svg width="0" height="0" aria-hidden="true" focusable="false"><defs><filter id="ham-gooey"><feGaussianBlur in="SourceGraphic" stdDeviation="10" result="blur"/><feColorMatrix in="blur" mode="matrix" values="1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 20 -9" result="goo"/><feComposite in="SourceGraphic" in2="goo" operator="atop"/></filter></defs></svg>';
    document.body.appendChild(defs);
    var nav=document.createElement('nav'); nav.className='ham-gooey-nav'; nav.dir='rtl';
    var items=[
      ['projects','پروژه‌ها','M4 6h6l2 2h8v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z'],
      ['new','ایجاد','M12 5v14M5 12h14'],
      ['save','ذخیره','M5 13l4 4L19 7'],
      ['hub','ویترین','M4 20h16M6 20V8l6-4 6 4v12M9 20v-6h6v6'],
      ['chat','چت','M21 12a8 8 0 0 1-8 8H7l-4 3 1.5-5A8 8 0 1 1 21 12z']
    ];
    nav.innerHTML='<div class="ham-gooey-nav__goo"><span class="ham-gooey-nav__bubble"></span><span class="ham-gooey-nav__dent"></span></div><div class="ham-gooey-nav__items">'+items.map(function(i){return '<button type="button" class="ham-gooey-nav__item ham-gooey-nav__item--'+i[0]+'" data-tab="'+i[0]+'" aria-label="'+i[1]+'"><span class="ham-gooey-nav__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="'+i[2]+'"></path></svg></span><small>'+i[1]+'</small></button>';}).join('')+'</div>';
    document.body.appendChild(nav);
    var btns=qsa('.ham-gooey-nav__item',nav), bubble=nav.querySelector('.ham-gooey-nav__bubble'), dent=nav.querySelector('.ham-gooey-nav__dent');
    function setActive(btn){
      btns.forEach(function(b){b.classList.remove('is-active');}); btn.classList.add('is-active');
      var nr=nav.getBoundingClientRect(), br=btn.getBoundingClientRect();
      var x=br.left-nr.left+br.width/2;
      bubble.style.transform='translate3d('+(x-31)+'px,-28px,0) scale(1)';
      dent.style.transform='translate3d('+(x-45)+'px,-8px,0)';
    }
    function syncSave(){ nav.classList.toggle('has-save', !!document.querySelector('.cptt-expertCard.is-expanded')); }
    btns.forEach(function(btn){ btn.addEventListener('click', function(){
      var t=btn.dataset.tab; setActive(btn);
      if(t==='projects'){ var g=document.getElementById('cptt-expert-grid'); if(g) g.scrollIntoView({behavior:'smooth', block:'start'}); }
      if(t==='new'){ var n=document.querySelector('[data-cptt-open-newproject],.cptt-newProjectCta'); if(n) n.click(); }
      if(t==='hub'){ if(window.CPTT_EXPERT && CPTT_EXPERT.publicHubUrl) window.location.href=CPTT_EXPERT.publicHubUrl; }
      if(t==='chat'){ var c=document.querySelector('.cptt-open-experts-modal-btn'); if(c) c.click(); }
      if(t==='save'){ var s=document.querySelector('body > .cptt-expert-save-floating,.cptt-expertCard.is-expanded .cptt-expert-save-floating'); if(s) s.click(); }
    }); });
    setActive(nav.querySelector('.ham-gooey-nav__item--projects'));
    document.addEventListener('click', function(e){ if(e.target.closest('.cptt-expert-toggleProject')) setTimeout(syncSave,120); });
    window.addEventListener('resize', function(){ var a=nav.querySelector('.is-active')||btns[0]; setActive(a); }, {passive:true});
    syncSave();
  }
  ready(function(){ repairAccountingTableModal(document); initGooeyMobileNav(); syncFloatingSaveVisibility(); });
  document.addEventListener('click', function(e){ if(e.target.closest('.cptt-expert-toggleProject')) setTimeout(syncFloatingSaveVisibility,160); });
  document.addEventListener('cptt:adminBridgeLoaded', function(e){ repairAccountingTableModal(e.detail && e.detail.container); });
})();

/* =========================================================
   HAM v5.5.22 — hard fixes: desktop save visibility, simple mobile nav,
   accounting table restore, PWA install prompt/splash
   ========================================================= */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function qsa(s,c){ return Array.prototype.slice.call((c||document).querySelectorAll(s)); }
  function isMobile(){ return window.matchMedia && window.matchMedia('(max-width: 820px)').matches; }

  function updateSaveState(){
    var openCard = document.querySelector('.cptt-expertCard.is-expanded');
    var open = !!openCard;
    document.body.classList.toggle('cptt-has-expanded-project', open);
    var btn = document.querySelector('body > .cptt-expert-save-floating') || (openCard && openCard.querySelector('.cptt-expert-save-floating'));
    if(btn){
      if(open){
        var form = openCard.querySelector('.cptt-expert-project-form');
        if(form){ if(!form.id) form.id='cptt-form-'+(openCard.dataset.projectId||Date.now()); btn.setAttribute('form', form.id); }
        if(btn.parentNode !== document.body) document.body.appendChild(btn);
      }
      btn.style.setProperty('display', (!isMobile() && open) ? 'inline-flex' : 'none', 'important');
      btn.style.setProperty('z-index','999995','important');
    }
    var nav=document.querySelector('.ham-simple-nav'); if(nav) nav.classList.toggle('has-save', open);
  }
  window.cpttUpdateSaveState = updateSaveState;

  function removeBadNavs(){ qsa('.ham-gooey-nav,.ham-gooey-svg-defs,.cptt-fluid-nav').forEach(function(n){n.remove();}); }
  function initSimpleNav(){
    if(!document.body.classList.contains('cptt-expert-dashboard-page')) return;
    removeBadNavs();
    if(document.querySelector('.ham-simple-nav')) { updateSaveState(); return; }
    var nav=document.createElement('nav'); nav.className='ham-simple-nav'; nav.dir='rtl';
    function icon(path){ return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="'+path+'"></path></svg>'; }
    nav.innerHTML='<div class="ham-simple-nav__rail">'+
      '<button type="button" class="ham-simple-nav__item" data-nav="projects" aria-label="پروژه‌ها">'+icon('M4 6h6l2 2h8v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z')+'</button>'+
      '<button type="button" class="ham-simple-nav__item" data-nav="new" aria-label="ایجاد پروژه">'+icon('M12 5v14M5 12h14')+'</button>'+
      '<span class="ham-simple-nav__gap"></span>'+
      '<button type="button" class="ham-simple-nav__item" data-nav="hub" aria-label="ویترین">'+icon('M4 20h16M6 20V8l6-4 6 4v12M9 20v-6h6v6')+'</button>'+
      '<button type="button" class="ham-simple-nav__item" data-nav="chat" aria-label="چت">'+icon('M21 12a8 8 0 0 1-8 8H7l-4 3 1.5-5A8 8 0 1 1 21 12z')+'</button>'+
      '<button type="button" class="ham-simple-nav__save" data-nav="save" aria-label="ذخیره تغییرات">'+icon('M5 13l4 4L19 7')+'</button>'+
      '</div>';
    document.body.appendChild(nav);
    nav.addEventListener('click', function(e){
      var b=e.target.closest('[data-nav]'); if(!b) return;
      var t=b.dataset.nav;
      if(t==='projects'){ var g=document.getElementById('cptt-expert-grid'); if(g) g.scrollIntoView({behavior:'smooth',block:'start'}); }
      if(t==='new'){ var n=document.querySelector('[data-cptt-open-newproject],.cptt-newProjectCta'); if(n) n.click(); }
      if(t==='hub'){ if(window.CPTT_EXPERT && CPTT_EXPERT.publicHubUrl) window.location.href=CPTT_EXPERT.publicHubUrl; }
      if(t==='chat'){ var c=document.querySelector('.cptt-open-experts-modal-btn'); if(c) c.click(); }
      if(t==='save'){ var s=document.querySelector('body > .cptt-expert-save-floating,.cptt-expertCard.is-expanded .cptt-expert-save-floating'); if(s) s.click(); }
    });
    updateSaveState();
  }

  function restoreAccountingTable(scope){
    scope=scope||document;
    var table=scope.querySelector('#cptt-acct-table') || document.querySelector('#cptt-acct-table');
    if(!table) return;
    var wrap=table.closest('.cptt-acct-table-wrap'); if(!wrap) return;
    var acct=wrap.closest('.cptt-accounting') || scope.querySelector('.cptt-accounting') || document.querySelector('.cptt-accounting');
    if(acct && wrap.closest('.cptt-acct-projects-modal__body')){
      var empty=acct.querySelector('#cptt-acct-empty');
      acct.insertBefore(wrap, empty || acct.querySelector('.cptt-acct-projects-modal') || null);
    }
    qsa('.cptt-acct-projects-open', acct||document).forEach(function(b){b.remove();});
    qsa('tbody tr.cptt-acct-row', table).forEach(function(r){ r.style.display=''; });
    wrap.classList.add('cptt-acct-table-clickable');
    if(wrap.dataset.hamClickModal) return; wrap.dataset.hamClickModal='1';
    var modal=(acct||document).querySelector('.cptt-acct-projects-modal');
    if(!modal){
      modal=document.createElement('div'); modal.className='cptt-acct-projects-modal'; modal.hidden=true;
      modal.innerHTML='<div class="cptt-acct-projects-modal__backdrop"></div><div class="cptt-acct-projects-modal__dialog"><div class="cptt-acct-projects-modal__head"><strong>لیست پروژه‌ها</strong><button type="button" class="cptt-acct-projects-modal__close">×</button></div><div class="cptt-acct-projects-modal__body"></div><div class="cptt-acct-projects-pager"><button type="button" data-dir="prev">قبلی</button><span></span><button type="button" data-dir="next">بعدی</button></div></div>';
      (acct||wrap.parentNode).appendChild(modal);
    }
    var originalParent=wrap.parentNode, next=wrap.nextSibling, page=1, per=10, rows=qsa('tbody tr.cptt-acct-row', table);
    function filtered(){ return rows.filter(function(r){ return r.dataset.filteredOut !== '1'; }); }
    function render(){ var fs=filtered(), pages=Math.max(1,Math.ceil(fs.length/per)); if(page>pages)page=pages; rows.forEach(function(r){r.style.display='none';}); fs.slice((page-1)*per,page*per).forEach(function(r){r.style.display='';}); var sp=modal.querySelector('.cptt-acct-projects-pager span'); if(sp) sp.textContent='صفحه '+page+' از '+pages+' — '+fs.length+' پروژه'; }
    function open(){ page=1; modal.querySelector('.cptt-acct-projects-modal__body').appendChild(wrap); modal.hidden=false; document.body.style.overflow='hidden'; render(); }
    function close(){ modal.hidden=true; document.body.style.overflow=''; originalParent.insertBefore(wrap,next); rows.forEach(function(r){r.style.display='';}); }
    wrap.addEventListener('click', function(e){ if(!modal.hidden) return; if(e.target.closest('a,button,input,select,textarea')) return; open(); });
    modal.querySelector('.cptt-acct-projects-modal__close').onclick=close; modal.querySelector('.cptt-acct-projects-modal__backdrop').onclick=close;
    modal.querySelector('[data-dir="prev"]').onclick=function(){ if(page>1){page--;render();} };
    modal.querySelector('[data-dir="next"]').onclick=function(){ page++;render(); };
  }

  function initPwaPrompt(){
    if(!isMobile() || localStorage.getItem('ham_pwa_prompt_dismissed')==='1' || window.matchMedia('(display-mode: standalone)').matches) return;
    var deferred=null;
    window.addEventListener('beforeinstallprompt', function(e){ e.preventDefault(); deferred=e; show(); });
    function show(){
      if(document.querySelector('.ham-pwa-install')) return;
      var p=document.createElement('div'); p.className='ham-pwa-install';
      p.innerHTML='<div class="ham-pwa-install__card"><div class="ham-pwa-install__logo">هما</div><div><strong>نصب اپلیکیشن هماهنگ</strong><p>برای دسترسی سریع‌تر و تجربه روان‌تر، داشبورد را به صفحه اصلی اضافه کنید.</p></div><div class="ham-pwa-install__actions"><button type="button" class="ham-pwa-install__later">بعداً</button><button type="button" class="ham-pwa-install__install">نصب</button></div></div>';
      document.body.appendChild(p);
      p.querySelector('.ham-pwa-install__later').onclick=function(){localStorage.setItem('ham_pwa_prompt_dismissed','1');p.remove();};
      p.querySelector('.ham-pwa-install__install').onclick=function(){ if(deferred){deferred.prompt(); deferred.userChoice.finally(function(){p.remove();deferred=null;});} else p.remove(); };
    }
    setTimeout(function(){ if(!deferred) show(); }, 2500);
  }
  function initSplash(){
    if(sessionStorage.getItem('ham_splash_done')==='1') return;
    var s=document.createElement('div'); s.className='ham-pwa-splash'; s.innerHTML='<div class="ham-pwa-splash__mark">هما</div><strong>هماهنگ</strong><span>در حال آماده‌سازی داشبورد...</span>';
    document.body.appendChild(s); sessionStorage.setItem('ham_splash_done','1');
    setTimeout(function(){s.classList.add('is-hide'); setTimeout(function(){s.remove();},420);},900);
  }
  ready(function(){ initSimpleNav(); restoreAccountingTable(document); initPwaPrompt(); initSplash(); updateSaveState(); });
  document.addEventListener('click', function(e){ if(e.target.closest('.cptt-expert-toggleProject')) setTimeout(updateSaveState,180); });
  document.addEventListener('cptt:adminBridgeLoaded', function(e){ restoreAccountingTable(e.detail && e.detail.container); });
})();

/* =========================================================
   HAM v5.6.0 — mobile notch nav, robust accounting table modal,
   real PWA install flow, standalone splash, offline banner
   ========================================================= */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function qs(sel, ctx){ return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx){ return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function isMobile(){ return !!(window.matchMedia && window.matchMedia('(max-width: 820px)').matches); }
  function isStandalone(){
    try {
      return !!((window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true || String(document.referrer||'').indexOf('android-app://') === 0);
    } catch(e){ return false; }
  }
  function isIOS(){
    var ua = navigator.userAgent || '';
    return /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }
  function fmtPercent(v){ return Math.max(0, Math.min(100, Math.round(v))); }

  function removeLegacyMobileUi(){
    qsa('.ham-simple-nav,.ham-gooey-nav,.ham-gooey-svg-defs,.cptt-fluid-nav,.ham-pwa-install,.ham-pwa-splash').forEach(function(el){ el.remove(); });
  }

  function triggerSave(){
    var btn = document.querySelector('body > .cptt-expert-save-floating, .cptt-expertCard.is-expanded .cptt-expert-save-floating');
    if (btn && getComputedStyle(btn).display !== 'none') btn.click();
  }

  function openAllNotifications(){
    var modal = qs('.cptt-all-notifs-modal');
    if (!modal) return;
    modal.removeAttribute('hidden');
    var list = qs('.cptt-all-notifs-list', modal);
    if (!list || !(window.CPTT_EXPERT && CPTT_EXPERT.ajax)) return;
    list.innerHTML = 'در حال بارگذاری...';
    var fd = new FormData();
    fd.append('action', 'cptt_expert_fetch_all_notifications');
    fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
    fetch(CPTT_EXPERT.ajax, { method:'POST', credentials:'same-origin', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(res){ if (res && res.success) list.innerHTML = (res.data && res.data.html) ? res.data.html : ''; })
      .catch(function(){ list.innerHTML = '<div class="cptt-expert-emptyMini">خطا در بارگذاری اعلان‌ها</div>'; });
  }

  function openProfilePanel(){
    var btn = qs('.cptt-open-edit-profile,[href*="cptt_edit_profile=1"]');
    if (btn) { btn.click(); return; }
    var box = qs('.cptt-sideBox--profile');
    if (box) box.scrollIntoView({ behavior:'smooth', block:'start' });
  }

  function syncNotchNav(){
    if (!document.body.classList.contains('cptt-expert-dashboard-page')) return;
    var nav = qs('.ham-notch-nav');
    if (!nav) return;
    if (window.cpttUpdateSaveState) {
      try { window.cpttUpdateSaveState(); } catch(e){}
    }
    var hasExpanded = !!qs('.cptt-expertCard.is-expanded');
    nav.classList.toggle('has-save', hasExpanded);
  }

  function createIcon(type){
    var map = {
      projects: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="4" width="6.5" height="6.5" rx="1.6"></rect><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.6"></rect><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.6"></rect><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.6"></rect></svg>',
      chat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 18.5H4.9A1.9 1.9 0 0 1 3 16.6V7.9A1.9 1.9 0 0 1 4.9 6h14.2A1.9 1.9 0 0 1 21 7.9v8.7a1.9 1.9 0 0 1-1.9 1.9H12l-5 3z"></path><path d="M8 11h8"></path><path d="M8 14.5h5"></path></svg>',
      notifications: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4.25a4.25 4.25 0 0 1 4.25 4.25v1.18c0 .9.28 1.77.8 2.5l1 1.43a1.35 1.35 0 0 1-1.1 2.14H7.05a1.35 1.35 0 0 1-1.1-2.14l1-1.43a4.32 4.32 0 0 0 .8-2.5V8.5A4.25 4.25 0 0 1 12 4.25z"></path><path d="M10.2 18.1a2 2 0 0 0 3.6 0"></path></svg>',
      profile: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 12.2a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"></path><path d="M4.8 19.3a7.2 7.2 0 0 1 14.4 0"></path></svg>',
      save: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4.75h9.1l2.9 2.9v11.6H6z"></path><path d="M9 4.75v5.1h5.4v-5.1"></path><path d="M9 19.25v-5.4h6v5.4"></path></svg>'
    };
    return map[type] || '';
  }

  function initNotchMobileNav(){
    if (!document.body.classList.contains('cptt-expert-dashboard-page') || !isMobile()) return;
    removeLegacyMobileUi();
    if (qs('.ham-notch-nav')) { syncNotchNav(); return; }
    var nav = document.createElement('nav');
    nav.className = 'ham-notch-nav';
    nav.setAttribute('dir', 'rtl');
    nav.innerHTML = '' +
      '<div class="ham-notch-nav__bar">' +
        '<button type="button" class="ham-notch-nav__item is-active" data-nav="projects" aria-label="پروژه‌ها">' + createIcon('projects') + '</button>' +
        '<button type="button" class="ham-notch-nav__item" data-nav="chat" aria-label="گفتگوها">' + createIcon('chat') + '</button>' +
        '<span class="ham-notch-nav__gap" aria-hidden="true"></span>' +
        '<button type="button" class="ham-notch-nav__item" data-nav="notifications" aria-label="اعلان‌ها">' + createIcon('notifications') + '</button>' +
        '<button type="button" class="ham-notch-nav__item" data-nav="profile" aria-label="پروفایل">' + createIcon('profile') + '</button>' +
      '</div>' +
      '<button type="button" class="ham-notch-nav__save" data-nav="save" aria-label="ذخیره تغییرات">' + createIcon('save') + '</button>';
    document.body.appendChild(nav);

    function setActive(btn){
      qsa('.ham-notch-nav__item', nav).forEach(function(item){ item.classList.remove('is-active'); });
      if (btn) btn.classList.add('is-active');
    }

    nav.addEventListener('click', function(e){
      var btn = e.target.closest('[data-nav]');
      if (!btn) return;
      var action = btn.getAttribute('data-nav');
      if (action === 'projects') {
        setActive(qs('[data-nav="projects"]', nav));
        var grid = qs('#cptt-expert-grid');
        if (grid) grid.scrollIntoView({ behavior:'smooth', block:'start' });
      }
      if (action === 'chat') {
        setActive(qs('[data-nav="chat"]', nav));
        var chat = qs('.cptt-open-experts-modal-btn');
        if (chat) chat.click();
      }
      if (action === 'notifications') {
        setActive(qs('[data-nav="notifications"]', nav));
        openAllNotifications();
      }
      if (action === 'profile') {
        setActive(qs('[data-nav="profile"]', nav));
        openProfilePanel();
      }
      if (action === 'save') {
        triggerSave();
      }
    });

    syncNotchNav();
  }

  function stabilizeAccountingTable(scope){
    scope = scope && scope.nodeType === 1 ? scope : document;
    var acct = scope.querySelector('.cptt-accounting') || (scope.closest ? scope.closest('.cptt-accounting') : null) || document.querySelector('.cptt-accounting');
    if (!acct) return;
    qsa('.cptt-acct-projects-open,.cptt-acct-table-placeholder,.cptt-acct-projects-modal', acct).forEach(function(el){ el.remove(); });
    var wrap = acct.querySelector('.cptt-acct-table-wrap');
    if (!wrap) return;

    if (!wrap.dataset.hamStableClone) {
      var cleanWrap = wrap.cloneNode(true);
      wrap.parentNode.replaceChild(cleanWrap, wrap);
      wrap = cleanWrap;
      wrap.dataset.hamStableClone = '1';
    }
    if (wrap.dataset.hamAccountingReady === '1') return;
    wrap.dataset.hamAccountingReady = '1';
    wrap.classList.add('cptt-acct-table-clickable');

    var table = wrap.querySelector('#cptt-acct-table');
    if (!table) return;

    var modal = document.createElement('div');
    modal.className = 'ham-acct-modal';
    modal.hidden = true;
    modal.innerHTML = '' +
      '<div class="ham-acct-modal__backdrop"></div>' +
      '<div class="ham-acct-modal__dialog">' +
        '<div class="ham-acct-modal__head"><strong>لیست پروژه‌ها</strong><button type="button" class="ham-acct-modal__close" aria-label="بستن">×</button></div>' +
        '<div class="ham-acct-modal__body"></div>' +
        '<div class="ham-acct-modal__pager"><button type="button" data-dir="prev">قبلی</button><span></span><button type="button" data-dir="next">بعدی</button></div>' +
      '</div>';
    acct.appendChild(modal);

    var modalBody = qs('.ham-acct-modal__body', modal);
    var pagerText = qs('.ham-acct-modal__pager span', modal);
    var prevBtn = qs('[data-dir="prev"]', modal);
    var nextBtn = qs('[data-dir="next"]', modal);
    var page = 1;
    var perPage = 10;
    var state = { rows: [], table: null };

    function sourceRows(){
      return qsa('tbody tr.cptt-acct-row', table).filter(function(row){
        return row.style.display !== 'none' && getComputedStyle(row).display !== 'none';
      });
    }

    function rebuildModalTable(){
      var cloneTable = table.cloneNode(true);
      var cloneBody = qs('tbody', cloneTable);
      if (!cloneBody) return;
      cloneBody.innerHTML = '';
      var visibleRows = sourceRows();
      if (!visibleRows.length) {
        cloneBody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:26px;">پروژه‌ای برای نمایش وجود ندارد.</td></tr>';
        state.rows = [];
      } else {
        visibleRows.forEach(function(row){ cloneBody.appendChild(row.cloneNode(true)); });
        state.rows = qsa('tbody tr.cptt-acct-row', cloneTable);
      }
      state.table = cloneTable;
      modalBody.innerHTML = '';
      modalBody.appendChild(cloneTable);
    }

    function renderModalPage(){
      var totalRows = state.rows.length;
      var totalPages = Math.max(1, Math.ceil(totalRows / perPage));
      if (page > totalPages) page = totalPages;
      state.rows.forEach(function(row, index){
        row.style.display = (index >= (page - 1) * perPage && index < page * perPage) ? '' : 'none';
      });
      pagerText.textContent = 'صفحه ' + page + ' از ' + totalPages + (totalRows ? (' — ' + totalRows + ' پروژه') : '');
      prevBtn.disabled = page <= 1;
      nextBtn.disabled = page >= totalPages;
    }

    function openModal(){
      page = 1;
      rebuildModalTable();
      modal.hidden = false;
      document.body.style.overflow = 'hidden';
      renderModalPage();
    }

    function closeModal(){
      modal.hidden = true;
      document.body.style.overflow = '';
      modalBody.innerHTML = '';
    }

    wrap.addEventListener('click', function(e){
      if (e.target.closest('a,button,input,select,textarea')) return;
      openModal();
    });
    qs('.ham-acct-modal__close', modal).addEventListener('click', closeModal);
    qs('.ham-acct-modal__backdrop', modal).addEventListener('click', closeModal);
    prevBtn.addEventListener('click', function(){ if (page > 1) { page--; renderModalPage(); } });
    nextBtn.addEventListener('click', function(){ page++; renderModalPage(); });
  }

  function showPwaCard(kind, deferredPrompt){
    if (!document.body.classList.contains('cptt-expert-dashboard-page') || isStandalone() || !isMobile()) return;
    if (localStorage.getItem('ham_pwa_install_dismissed_v560') === '1') return;
    var existing = qs('.ham-pwa-card');
    if (existing) existing.remove();
    var card = document.createElement('div');
    card.className = 'ham-pwa-card ham-pwa-card--' + kind;
    if (kind === 'prompt') {
      card.innerHTML = '<div class="ham-pwa-card__inner"><div class="ham-pwa-card__logo">هما</div><div class="ham-pwa-card__text"><strong>نصب اپلیکیشن هماهنگ</strong><p>برای دسترسی سریع‌تر و تجربه بهتر، داشبورد را به صفحه اصلی گوشی اضافه کن.</p></div><div class="ham-pwa-card__actions"><button type="button" class="ham-pwa-card__later">بعداً</button><button type="button" class="ham-pwa-card__install">نصب</button></div></div>';
    } else {
      card.innerHTML = '<div class="ham-pwa-card__inner"><div class="ham-pwa-card__logo">هما</div><div class="ham-pwa-card__text"><strong>نصب در آیفون / آیپد</strong><p>از دکمه <b>Share</b> مرورگر Safari گزینه <b>Add to Home Screen</b> را بزن تا اپلیکیشن نصب شود.</p></div><div class="ham-pwa-card__actions"><button type="button" class="ham-pwa-card__later">بستن</button><button type="button" class="ham-pwa-card__guide">متوجه شدم</button></div></div>';
    }
    document.body.appendChild(card);
    var later = qs('.ham-pwa-card__later', card);
    if (later) later.onclick = function(){ localStorage.setItem('ham_pwa_install_dismissed_v560', '1'); card.remove(); };
    var installBtn = qs('.ham-pwa-card__install', card);
    if (installBtn) {
      installBtn.onclick = function(){
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        Promise.resolve(deferredPrompt.userChoice).finally(function(){ card.remove(); });
      };
    }
    var guideBtn = qs('.ham-pwa-card__guide', card);
    if (guideBtn) guideBtn.onclick = function(){ card.remove(); };
  }

  function initPwaInstallFlow(){
    if (!document.body.classList.contains('cptt-expert-dashboard-page') || isStandalone() || !isMobile()) return;
    var deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', function(e){
      e.preventDefault();
      deferredPrompt = e;
      showPwaCard('prompt', deferredPrompt);
    });
    window.addEventListener('appinstalled', function(){
      localStorage.removeItem('ham_pwa_install_dismissed_v560');
      qsa('.ham-pwa-card').forEach(function(el){ el.remove(); });
    });
    if (isIOS()) {
      setTimeout(function(){ if (!deferredPrompt && !isStandalone()) showPwaCard('ios'); }, 1200);
    }
  }

  function initStandaloneSplash(){
    if (!document.body.classList.contains('cptt-expert-dashboard-page') || !isStandalone()) return;
    qsa('.ham-pwa-splash,.ham-pwa-install,.ham-pwa-card').forEach(function(el){ el.remove(); });
    var splash = document.createElement('div');
    splash.className = 'ham-standalone-splash';
    splash.innerHTML = '<div class="ham-standalone-splash__box"><strong>به اپلیکیشن هماهنگ خوش آمدی</strong><span>در حال آماده‌سازی داشبورد...</span><div class="ham-standalone-splash__progress"><i></i></div><b>0%</b></div>';
    document.body.appendChild(splash);
    var bar = qs('i', splash), pct = qs('b', splash);
    var start = null;
    var duration = 1250;
    function tick(ts){
      if (!start) start = ts;
      var progress = Math.min(1, (ts - start) / duration);
      var value = fmtPercent(progress * 100);
      if (bar) bar.style.width = value + '%';
      if (pct) pct.textContent = value + '%';
      if (progress < 1) requestAnimationFrame(tick);
      else {
        splash.classList.add('is-hide');
        setTimeout(function(){ splash.remove(); }, 360);
      }
    }
    requestAnimationFrame(tick);
  }

  function initStandaloneOfflineBanner(){
    if (!document.body.classList.contains('cptt-expert-dashboard-page') || !isStandalone()) return;
    var banner = qs('.ham-offline-banner');
    if (!banner) {
      banner = document.createElement('div');
      banner.className = 'ham-offline-banner';
      banner.textContent = 'آفلاین - فقط مشاهده';
      document.body.appendChild(banner);
    }
    function sync(){ banner.classList.toggle('is-show', !navigator.onLine); }
    window.addEventListener('online', sync);
    window.addEventListener('offline', sync);
    sync();
  }

  ready(function(){
    removeLegacyMobileUi();
    initNotchMobileNav();
    initPwaInstallFlow();
    initStandaloneSplash();
    initStandaloneOfflineBanner();
    stabilizeAccountingTable(document);
    syncNotchNav();
  });

  document.addEventListener('click', function(e){
    if (e.target.closest('.cptt-expert-toggleProject,.cptt-expert-add-step,.cptt-expert-remove-step,.cptt-expert-save-floating')) {
      setTimeout(syncNotchNav, 120);
    }
  });
  window.addEventListener('resize', function(){ setTimeout(function(){ initNotchMobileNav(); syncNotchNav(); }, 80); }, { passive:true });
  document.addEventListener('cptt:adminBridgeLoaded', function(e){
    stabilizeAccountingTable(e.detail && e.detail.container ? e.detail.container : document);
  });
})();

/* =========================================================
   HAM v5.6.1 — hide save outside visible project form + edge-to-edge nav
   ========================================================= */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function qs(sel, ctx){ return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx){ return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function isMobile(){ return !!(window.matchMedia && window.matchMedia('(max-width: 820px)').matches); }
  function isVisible(el){ return !!(el && !el.hidden && getComputedStyle(el).display !== 'none' && el.offsetParent !== null); }

  function getVisibleExpandedCard(){
    var cards = qsa('.cptt-expertCard.is-expanded');
    for (var i = 0; i < cards.length; i++) {
      if (isVisible(cards[i])) return cards[i];
    }
    return null;
  }

  function submitVisibleProjectForm(){
    var card = getVisibleExpandedCard();
    if (!card) return;
    var form = qs('.cptt-expert-project-form', card);
    if (!form) return;
    if (typeof form.requestSubmit === 'function') { form.requestSubmit(); return; }
    var ev = document.createEvent('Event');
    ev.initEvent('submit', true, true);
    form.dispatchEvent(ev);
  }

  function updateSaveStateV561(){
    var card = getVisibleExpandedCard();
    var inBridge = !!qs('.cptt-expertMain.cptt-admin-bridge-mode');
    var showDesktopSave = !!card && !inBridge && !isMobile();
    document.body.classList.toggle('cptt-has-expanded-project', !!card && !inBridge);
    qsa('body > .cptt-expert-save-floating, .cptt-expertCard .cptt-expert-save-floating').forEach(function(btn){
      if (card) {
        var form = qs('.cptt-expert-project-form', card);
        if (form) {
          if (!form.id) form.id = 'cptt-form-' + (card.getAttribute('data-project-id') || Date.now());
          btn.setAttribute('form', form.id);
        }
      }
      if (btn.parentNode !== document.body) document.body.appendChild(btn);
      btn.style.setProperty('display', showDesktopSave ? 'inline-flex' : 'none', 'important');
      btn.style.setProperty('z-index', '999995', 'important');
    });
    var nav = qs('.ham-edge-nav');
    if (nav) nav.classList.toggle('has-save', !!card && !inBridge);
  }
  window.cpttUpdateSaveState = updateSaveStateV561;

  function removeAllMobileNavs(){
    qsa('.ham-notch-nav,.ham-simple-nav,.ham-gooey-nav,.ham-gooey-svg-defs,.cptt-fluid-nav').forEach(function(el){ el.remove(); });
  }

  function navIcon(name){
    var icons = {
      projects: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7.5h16"></path><path d="M4 12h16"></path><path d="M4 16.5h10"></path></svg>',
      chat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5.5 18.5 4 21l4.2-1.1c1.1.4 2.3.6 3.6.6 4.8 0 8.7-3.2 8.7-7.2S16.6 6 11.8 6 3 9.2 3 13.2c0 2 .9 3.8 2.5 5.3Z"></path><path d="M8.5 12.5h6"></path><path d="M8.5 15.5h3.5"></path></svg>',
      notifications: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.5 18.5h5"></path><path d="M6.7 15.8c.8-1 1.2-2.4 1.2-3.8V10a4.1 4.1 0 1 1 8.2 0v2c0 1.4.4 2.8 1.2 3.8l.4.5H6.3l.4-.5Z"></path></svg>',
      profile: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 12.5a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"></path><path d="M5 19.5a7 7 0 0 1 14 0"></path></svg>',
      save: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4.5h11l3 3v12H5z"></path><path d="M9 4.5v5h6v-5"></path><path d="M9 19.5V14h6v5"></path></svg>'
    };
    return icons[name] || '';
  }

  function initEdgeNav(){
    if (!document.body.classList.contains('cptt-expert-dashboard-page') || !isMobile()) return;
    removeAllMobileNavs();
    if (qs('.ham-edge-nav')) { updateSaveStateV561(); return; }
    var nav = document.createElement('nav');
    nav.className = 'ham-edge-nav';
    nav.setAttribute('dir', 'rtl');
    nav.innerHTML = '' +
      '<div class="ham-edge-nav__bar">' +
        '<button type="button" class="ham-edge-nav__item is-active" data-nav="projects" aria-label="پروژه‌ها">' + navIcon('projects') + '</button>' +
        '<button type="button" class="ham-edge-nav__item" data-nav="chat" aria-label="گفتگوها">' + navIcon('chat') + '</button>' +
        '<span class="ham-edge-nav__spacer" aria-hidden="true"></span>' +
        '<button type="button" class="ham-edge-nav__item" data-nav="notifications" aria-label="اعلان‌ها">' + navIcon('notifications') + '</button>' +
        '<button type="button" class="ham-edge-nav__item" data-nav="profile" aria-label="پروفایل">' + navIcon('profile') + '</button>' +
      '</div>' +
      '<button type="button" class="ham-edge-nav__save" data-nav="save" aria-label="ذخیره تغییرات">' + navIcon('save') + '</button>';
    document.body.appendChild(nav);

    function setActive(key){
      qsa('.ham-edge-nav__item', nav).forEach(function(item){
        item.classList.toggle('is-active', item.getAttribute('data-nav') === key);
      });
    }

    nav.addEventListener('click', function(e){
      var btn = e.target.closest('[data-nav]');
      if (!btn) return;
      var action = btn.getAttribute('data-nav');
      if (action !== 'save') setActive(action);
      if (action === 'projects') {
        var grid = qs('#cptt-expert-grid');
        if (grid) grid.scrollIntoView({ behavior:'smooth', block:'start' });
      }
      if (action === 'chat') {
        var chatBtn = qs('.cptt-open-experts-modal-btn');
        if (chatBtn) chatBtn.click();
      }
      if (action === 'notifications') {
        var notifBtn = qs('[data-cptt-open-all-notifs], .cptt-mobile-bell-btn');
        if (notifBtn && notifBtn.click) notifBtn.click();
        else {
          var modal = qs('.cptt-all-notifs-modal');
          if (modal) modal.removeAttribute('hidden');
        }
      }
      if (action === 'profile') {
        var profBtn = qs('.cptt-open-edit-profile,[href*="cptt_edit_profile=1"]');
        if (profBtn && profBtn.click) profBtn.click();
        else {
          var profileBox = qs('.cptt-sideBox--profile');
          if (profileBox) profileBox.scrollIntoView({ behavior:'smooth', block:'start' });
        }
      }
      if (action === 'save') submitVisibleProjectForm();
    });

    updateSaveStateV561();
  }

  function observeSaveState(){
    var main = qs('.cptt-expertMain');
    var grid = qs('#cptt-expert-grid');
    var cfg = { attributes:true, subtree:true, attributeFilter:['class','style','hidden'] };
    if (main && !main.dataset.cpttSaveObs) {
      main.dataset.cpttSaveObs = '1';
      new MutationObserver(function(){ window.requestAnimationFrame(updateSaveStateV561); }).observe(main, cfg);
    }
    if (grid && !grid.dataset.cpttSaveObs) {
      grid.dataset.cpttSaveObs = '1';
      new MutationObserver(function(){ window.requestAnimationFrame(updateSaveStateV561); }).observe(grid, cfg);
    }
  }

  ready(function(){
    initEdgeNav();
    observeSaveState();
    updateSaveStateV561();
  });

  document.addEventListener('click', function(e){
    if (e.target.closest('.cptt-expert-toggleProject,.cptt-admin-bridge__item,#cptt-admin-bridge-close,.cptt-expert-add-step,.cptt-expert-remove-step,.cptt-expert-save-floating,.cptt-newProjectCta,[data-cptt-open-newproject]')) {
      setTimeout(updateSaveStateV561, 140);
    }
  });
  document.addEventListener('cptt:adminBridgeLoaded', function(){ setTimeout(updateSaveStateV561, 80); });
  window.addEventListener('resize', function(){ setTimeout(function(){ initEdgeNav(); updateSaveStateV561(); }, 80); }, { passive:true });
})();

/* =========================================================
   HAM v5.6.2 — brand-new curved bottom nav from scratch
   ========================================================= */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function qs(sel, ctx){ return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx){ return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function isMobile(){ return !!(window.matchMedia && window.matchMedia('(max-width: 820px)').matches); }
  function isVisible(el){ return !!(el && !el.hidden && getComputedStyle(el).display !== 'none' && el.offsetParent !== null); }
  function inAdminBridge(){ var main = qs('.cptt-expertMain'); return !!(main && main.classList.contains('cptt-admin-bridge-mode')); }

  function visibleExpandedCard(){
    if (inAdminBridge()) return null;
    var cards = qsa('.cptt-expertCard.is-expanded');
    for (var i = 0; i < cards.length; i++) {
      if (isVisible(cards[i])) return cards[i];
    }
    return null;
  }

  function submitVisibleManageForm(){
    var card = visibleExpandedCard();
    if (!card) return;
    var form = qs('.cptt-expert-project-form', card);
    if (!form) return;
    if (!form.id) form.id = 'cptt-mobile-save-form-' + (card.getAttribute('data-project-id') || Date.now());
    if (typeof form.requestSubmit === 'function') { form.requestSubmit(); return; }
    var ev = document.createEvent('Event');
    ev.initEvent('submit', true, true);
    form.dispatchEvent(ev);
  }

  function updateSaveVisibilityV562(){
    var card = visibleExpandedCard();
    var open = !!card;
    document.body.classList.toggle('cptt-has-expanded-project', open);
    qsa('body > .cptt-expert-save-floating, .cptt-expertCard .cptt-expert-save-floating').forEach(function(btn){
      if (card) {
        var form = qs('.cptt-expert-project-form', card);
        if (form) {
          if (!form.id) form.id = 'cptt-save-form-' + (card.getAttribute('data-project-id') || Date.now());
          btn.setAttribute('form', form.id);
        }
      }
      if (btn.parentNode !== document.body) document.body.appendChild(btn);
      btn.style.setProperty('display', (!isMobile() && open) ? 'inline-flex' : 'none', 'important');
      btn.style.setProperty('z-index', '999995', 'important');
    });
    var nav = qs('.ham-curved-nav');
    if (nav) nav.classList.toggle('has-save', open);
  }
  window.cpttUpdateSaveState = updateSaveVisibilityV562;

  function removeOlderBottomNavs(){
    qsa('.ham-curved-nav,.ham-edge-nav,.ham-notch-nav,.ham-simple-nav,.ham-gooey-nav,.ham-gooey-svg-defs,.cptt-fluid-nav').forEach(function(el){ el.remove(); });
  }

  function icon(name){
    var icons = {
      projects: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="4" width="7" height="7" rx="1.8"></rect><rect x="13.5" y="4" width="7" height="7" rx="1.8"></rect><rect x="3.5" y="14" width="7" height="7" rx="1.8"></rect><rect x="13.5" y="14" width="7" height="7" rx="1.8"></rect></svg>',
      chats: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17.5H4.8A1.8 1.8 0 0 1 3 15.7V7.8A1.8 1.8 0 0 1 4.8 6h10.4A1.8 1.8 0 0 1 17 7.8v7.9A1.8 1.8 0 0 1 15.2 17.5H11l-4 3z"></path><path d="M8 10.5h6"></path><path d="M8 13.5h4"></path><path d="M18 11.5h1.2A1.8 1.8 0 0 1 21 13.3v4.4l-2.7-1.9h-1.8"></path></svg>',
      showcase: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5a4 4 0 0 1 4-4h2"></path><path d="M14 15.5h2a4 4 0 0 1 4 4"></path><circle cx="9" cy="9" r="3"></circle><circle cx="15" cy="9" r="3"></circle></svg>',
      create: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>',
      save: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4.5h11.5L19.5 8v11.5H5z"></path><path d="M8.5 4.5v5h6v-5"></path><path d="M8.5 19.5V14h7v5"></path></svg>'
    };
    return icons[name] || '';
  }

  function openChats(){
    var btn = qs('.cptt-open-experts-modal-btn');
    if (btn) btn.click();
  }

  function openShowcase(){
    if (window.CPTT_EXPERT && CPTT_EXPERT.publicHubUrl) window.location.href = CPTT_EXPERT.publicHubUrl;
  }

  function openCreateProject(){
    var btn = qs('[data-cptt-open-newproject], .cptt-newProjectCta');
    if (btn) btn.click();
  }

  function goProjects(){
    var grid = qs('#cptt-expert-grid');
    if (grid) grid.scrollIntoView({ behavior:'smooth', block:'start' });
  }

  function initCurvedBottomNav(){
    if (!document.body.classList.contains('cptt-expert-dashboard-page') || !isMobile()) return;
    removeOlderBottomNavs();
    if (qs('.ham-curved-nav')) { updateSaveVisibilityV562(); return; }

    var nav = document.createElement('nav');
    nav.className = 'ham-curved-nav';
    nav.setAttribute('dir', 'rtl');
    nav.innerHTML = '' +
      '<div class="ham-curved-nav__shell">' +
        '<svg class="ham-curved-nav__bg" viewBox="0 0 100 74" preserveAspectRatio="none" aria-hidden="true">' +
          '<path d="M0 22C0 9.8 7.8 0 20 0H36C42.3 0 43.5 26 50 26C56.5 26 57.7 0 64 0H80C92.2 0 100 9.8 100 22V74H0Z"></path>' +
        '</svg>' +
        '<div class="ham-curved-nav__items">' +
          '<button type="button" class="ham-curved-nav__item is-active" data-nav="projects" aria-label="پروژه‌ها">' + icon('projects') + '</button>' +
          '<button type="button" class="ham-curved-nav__item" data-nav="chats" aria-label="چت‌ها">' + icon('chats') + '</button>' +
          '<span class="ham-curved-nav__void" aria-hidden="true"></span>' +
          '<button type="button" class="ham-curved-nav__item" data-nav="showcase" aria-label="ویترین کارشناسان">' + icon('showcase') + '</button>' +
          '<button type="button" class="ham-curved-nav__item" data-nav="create" aria-label="ایجاد پروژه">' + icon('create') + '</button>' +
        '</div>' +
        '<button type="button" class="ham-curved-nav__save" data-nav="save" aria-label="ذخیره">' + icon('save') + '</button>' +
      '</div>';
    document.body.appendChild(nav);

    function setActive(key){
      qsa('.ham-curved-nav__item', nav).forEach(function(item){
        item.classList.toggle('is-active', item.getAttribute('data-nav') === key);
      });
    }

    nav.addEventListener('click', function(e){
      var btn = e.target.closest('[data-nav]');
      if (!btn) return;
      var action = btn.getAttribute('data-nav');
      if (action === 'projects') { setActive('projects'); goProjects(); }
      if (action === 'chats') { setActive('chats'); openChats(); }
      if (action === 'showcase') { setActive('showcase'); openShowcase(); }
      if (action === 'create') { setActive('create'); openCreateProject(); }
      if (action === 'save') { submitVisibleManageForm(); }
    });

    updateSaveVisibilityV562();
  }

  function observeUiChanges(){
    var main = qs('.cptt-expertMain');
    var grid = qs('#cptt-expert-grid');
    var config = { subtree:true, attributes:true, childList:true, attributeFilter:['class','hidden','style'] };
    if (main && !main.dataset.cpttNavObserver) {
      main.dataset.cpttNavObserver = '1';
      new MutationObserver(function(){ window.requestAnimationFrame(updateSaveVisibilityV562); }).observe(main, config);
    }
    if (grid && !grid.dataset.cpttNavObserver) {
      grid.dataset.cpttNavObserver = '1';
      new MutationObserver(function(){ window.requestAnimationFrame(updateSaveVisibilityV562); }).observe(grid, config);
    }
  }

  ready(function(){
    initCurvedBottomNav();
    observeUiChanges();
    updateSaveVisibilityV562();
  });

  document.addEventListener('click', function(e){
    if (e.target.closest('.cptt-expert-toggleProject,.cptt-admin-bridge__item,#cptt-admin-bridge-close,.cptt-expert-add-step,.cptt-expert-remove-step,.cptt-expert-save-floating,.cptt-newProjectCta,[data-cptt-open-newproject],.cptt-expert-delete-project')) {
      setTimeout(updateSaveVisibilityV562, 120);
    }
  });
  document.addEventListener('cptt:adminBridgeLoaded', function(){ setTimeout(updateSaveVisibilityV562, 80); });
  window.addEventListener('resize', function(){ setTimeout(function(){ initCurvedBottomNav(); updateSaveVisibilityV562(); }, 80); }, { passive:true });
})();

/* =========================================================
   HAM v5.6.3 — nav polish, reliable PWA prompt, finance hydration,
   inline accounting accordion
   ========================================================= */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function qs(sel, ctx){ return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx){ return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function isMobile(){ return !!(window.matchMedia && window.matchMedia('(max-width: 820px)').matches); }
  function isVisible(el){ return !!(el && !el.hidden && getComputedStyle(el).display !== 'none' && el.offsetParent !== null); }
  function isStandalone(){
    try { return !!((window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true || String(document.referrer||'').indexOf('android-app://') === 0); }
    catch(e){ return false; }
  }
  function isIOS(){
    var ua = navigator.userAgent || '';
    return /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }
  function num(v){ return parseFloat(String(v||'').replace(/,/g,'')) || 0; }
  function fmt(v){ return (Math.round((parseFloat(v)||0) * 100) / 100).toLocaleString('en-US'); }

  function visibleExpandedCardV563(){
    var main = qs('.cptt-expertMain');
    if (main && main.classList.contains('cptt-admin-bridge-mode')) return null;
    var cards = qsa('.cptt-expertCard.is-expanded');
    for (var i = 0; i < cards.length; i++) if (isVisible(cards[i])) return cards[i];
    return null;
  }

  function submitVisibleManageFormV563(){
    var card = visibleExpandedCardV563();
    if (!card) return;
    var form = qs('.cptt-expert-project-form', card);
    if (!form) return;
    if (!form.id) form.id = 'cptt-curved-save-form-' + (card.getAttribute('data-project-id') || Date.now());
    if (typeof form.requestSubmit === 'function') { form.requestSubmit(); return; }
    var ev = document.createEvent('Event');
    ev.initEvent('submit', true, true);
    form.dispatchEvent(ev);
  }

  function updateSaveStateV563(){
    var card = visibleExpandedCardV563();
    var open = !!card;
    document.body.classList.toggle('cptt-has-expanded-project', open);
    qsa('body > .cptt-expert-save-floating, .cptt-expertCard .cptt-expert-save-floating').forEach(function(btn){
      if (card) {
        var form = qs('.cptt-expert-project-form', card);
        if (form) {
          if (!form.id) form.id = 'cptt-save-form-' + (card.getAttribute('data-project-id') || Date.now());
          btn.setAttribute('form', form.id);
        }
      }
      if (btn.parentNode !== document.body) document.body.appendChild(btn);
      btn.style.setProperty('display', (!isMobile() && open) ? 'inline-flex' : 'none', 'important');
      btn.style.setProperty('z-index', '999995', 'important');
    });
    var nav = qs('.ham-curved-nav');
    if (nav) nav.classList.toggle('has-save', open);
  }
  window.cpttUpdateSaveState = updateSaveStateV563;

  function removeOlderNavsV563(){ qsa('.ham-curved-nav,.ham-edge-nav,.ham-notch-nav,.ham-simple-nav,.ham-gooey-nav,.ham-gooey-svg-defs,.cptt-fluid-nav').forEach(function(el){ el.remove(); }); }

  function navSvg(name){
    var map = {
      projects: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.6" y="4" width="7.2" height="7.2" rx="1.9"></rect><rect x="13.2" y="4" width="7.2" height="7.2" rx="1.9"></rect><rect x="3.6" y="13.6" width="7.2" height="7.2" rx="1.9"></rect><rect x="13.2" y="13.6" width="7.2" height="7.2" rx="1.9"></rect></svg>',
      chats: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.05" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17.8H4.8A1.8 1.8 0 0 1 3 16V7.8A1.8 1.8 0 0 1 4.8 6h10.6A1.8 1.8 0 0 1 17.2 7.8V16A1.8 1.8 0 0 1 15.4 17.8H11L7 20.7z"></path><path d="M8 10.5h6.2"></path><path d="M8 13.8h4.2"></path><path d="M17.2 10.4h1.3A1.5 1.5 0 0 1 20 11.9v4.2l-2.6-1.8h-.2"></path></svg>',
      showcase: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.05" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8.5" cy="9" r="2.8"></circle><circle cx="15.5" cy="9" r="2.8"></circle><path d="M3.8 18.5a5.1 5.1 0 0 1 9.4-2"></path><path d="M10.8 16.5a4.8 4.8 0 0 1 9.4 2"></path></svg>',
      create: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>',
      save: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4.5h11.3L19.5 8v11.5H5z"></path><path d="M8.7 4.5v5h6v-5"></path><path d="M8.7 19.5V14h6.6v5"></path></svg>'
    };
    return map[name] || '';
  }

  function goProjectsV563(){ var grid = qs('#cptt-expert-grid'); if (grid) grid.scrollIntoView({ behavior:'smooth', block:'start' }); }
  function openChatsV563(){ var btn = qs('.cptt-open-experts-modal-btn'); if (btn) btn.click(); }
  function openShowcaseV563(){ if (window.CPTT_EXPERT && CPTT_EXPERT.publicHubUrl) window.location.href = CPTT_EXPERT.publicHubUrl; }
  function openCreateV563(){ var btn = qs('[data-cptt-open-newproject], .cptt-newProjectCta'); if (btn) btn.click(); }

  function initCurvedNavV563(){
    if (!document.body.classList.contains('cptt-expert-dashboard-page') || !isMobile()) return;
    removeOlderNavsV563();
    if (qs('.ham-curved-nav')) { updateSaveStateV563(); return; }
    var nav = document.createElement('nav');
    nav.className = 'ham-curved-nav';
    nav.setAttribute('dir', 'rtl');
    nav.innerHTML = '' +
      '<div class="ham-curved-nav__shell">' +
        '<svg class="ham-curved-nav__bg" viewBox="0 0 100 74" preserveAspectRatio="none" aria-hidden="true">' +
          '<path d="M0 22C0 9.8 7.8 0 20 0H36C42.3 0 43.6 27 50 27S57.7 0 64 0H80C92.2 0 100 9.8 100 22V74H0Z"></path>' +
        '</svg>' +
        '<div class="ham-curved-nav__items">' +
          '<button type="button" class="ham-curved-nav__item is-active" data-nav="projects" aria-label="پروژه‌ها">' + navSvg('projects') + '</button>' +
          '<button type="button" class="ham-curved-nav__item" data-nav="chats" aria-label="چت‌ها">' + navSvg('chats') + '</button>' +
          '<span class="ham-curved-nav__void" aria-hidden="true"></span>' +
          '<button type="button" class="ham-curved-nav__item" data-nav="showcase" aria-label="ویترین کارشناسان">' + navSvg('showcase') + '</button>' +
          '<button type="button" class="ham-curved-nav__item" data-nav="create" aria-label="ایجاد پروژه">' + navSvg('create') + '</button>' +
        '</div>' +
        '<button type="button" class="ham-curved-nav__save" data-nav="save" aria-label="ذخیره">' + navSvg('save') + '</button>' +
      '</div>';
    document.body.appendChild(nav);
    function setActive(key){ qsa('.ham-curved-nav__item', nav).forEach(function(item){ item.classList.toggle('is-active', item.getAttribute('data-nav') === key); }); }
    nav.addEventListener('click', function(e){
      var btn = e.target.closest('[data-nav]');
      if (!btn) return;
      var action = btn.getAttribute('data-nav');
      if (action === 'projects') { setActive('projects'); goProjectsV563(); }
      if (action === 'chats') { setActive('chats'); openChatsV563(); }
      if (action === 'showcase') { setActive('showcase'); openShowcaseV563(); }
      if (action === 'create') { setActive('create'); openCreateV563(); }
      if (action === 'save') submitVisibleManageFormV563();
    });
    updateSaveStateV563();
  }

  function financeHydrateStep(step){
    if (!step) return;
    var qty = qs('.cptt-step-qty', step) || qs('input[name*="[qty]"]', step);
    var unit = qs('.cptt-step-unit-price', step) || qs('input[name*="[unit_price]"]', step);
    var cost = qs('.cptt-step-cost', step) || qs('input[name*="[cost]"]', step);
    if (!cost) return;
    var q = qty ? parseFloat(qty.value || '1') : 1;
    if (!isFinite(q) || q <= 0) q = 1;
    if (qty && (!qty.value || parseFloat(qty.value) <= 0)) qty.value = '1';
    var costNum = num(cost.value);
    if (unit) {
      var unitNum = num(unit.value);
      if ((unit.value === '' || unitNum === 0) && costNum > 0) {
        unit.value = fmt(costNum / q);
      }
    }
  }

  function hydrateAllFinanceRows(){ qsa('.cptt-expert-step').forEach(financeHydrateStep); }

  function inlineAccountingAccordion(scope){
    scope = scope && scope.nodeType === 1 ? scope : document;
    var acct = scope.querySelector('.cptt-accounting') || document.querySelector('.cptt-accounting');
    if (!acct) return;
    qsa('.ham-acct-modal,.cptt-acct-projects-modal,.cptt-acct-projects-open,.cptt-acct-table-placeholder', acct).forEach(function(el){ el.remove(); });
    var wrap = acct.querySelector('.cptt-acct-table-wrap');
    if (!wrap) return;
    wrap.classList.remove('cptt-acct-table-clickable');
    if (!wrap.dataset.hamInlineClone) {
      var clean = wrap.cloneNode(true);
      wrap.parentNode.replaceChild(clean, wrap);
      wrap = clean;
      wrap.dataset.hamInlineClone = '1';
    }
    if (wrap.dataset.hamInlineAccordionReady === '1') return;
    wrap.dataset.hamInlineAccordionReady = '1';

    var rowsCount = qsa('tbody tr.cptt-acct-row', wrap).length;
    var box = document.createElement('section');
    box.className = 'ham-acct-inline';
    box.innerHTML = '<button type="button" class="ham-acct-inline__toggle" aria-expanded="false"><span><b>لیست پروژه‌ها</b><small>' + rowsCount + ' پروژه</small></span><i>⌄</i></button><div class="ham-acct-inline__body" hidden></div>';
    wrap.parentNode.insertBefore(box, wrap);
    qs('.ham-acct-inline__body', box).appendChild(wrap);

    var toggle = qs('.ham-acct-inline__toggle', box);
    var body = qs('.ham-acct-inline__body', box);
    toggle.addEventListener('click', function(){
      var open = !body.hasAttribute('hidden');
      if (open) {
        body.setAttribute('hidden', '');
        box.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
      } else {
        body.removeAttribute('hidden');
        box.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
      }
    });
  }

  function initPwaPromptV563(){
    if (!document.body.classList.contains('cptt-expert-dashboard-page') || !isMobile() || isStandalone()) return;
    var storageKey = 'ham_pwa_prompt_v563_dismissed';
    if (sessionStorage.getItem('ham_pwa_prompt_shown_v563') === '1') return;
    var deferred = null;
    var card = null;

    function removeCard(){ if (card) { card.remove(); card = null; } }
    function dismissForever(){ localStorage.setItem(storageKey, '1'); removeCard(); }
    function showCard(mode){
      if (localStorage.getItem(storageKey) === '1') return;
      if (card) card.remove();
      card = document.createElement('div');
      card.className = 'ham-pwa-card-v563 ham-pwa-card-v563--' + mode;
      var title = 'نصب اپلیکیشن هماهنگ';
      var text = 'برای تجربه بهتر، داشبورد را مثل یک اپ روی گوشی نصب کن.';
      var action = '<button type="button" class="ham-pwa-card-v563__install">نصب</button>';
      if (mode === 'ios') {
        title = 'نصب اپلیکیشن در آیفون';
        text = 'در Safari روی Share بزن و بعد Add to Home Screen را انتخاب کن.';
        action = '<button type="button" class="ham-pwa-card-v563__guide">متوجه شدم</button>';
      }
      if (mode === 'manual') {
        title = 'افزودن به صفحه اصلی';
        text = 'اگر دکمه نصب مرورگر فعال نیست، از منوی مرورگر گزینه Add to Home screen یا Install app را بزن.';
        action = '<button type="button" class="ham-pwa-card-v563__guide">باشه</button>';
      }
      card.innerHTML = '<div class="ham-pwa-card-v563__inner"><div class="ham-pwa-card-v563__logo">هما</div><div class="ham-pwa-card-v563__text"><strong>' + title + '</strong><p>' + text + '</p></div><div class="ham-pwa-card-v563__actions"><button type="button" class="ham-pwa-card-v563__later">بعداً</button>' + action + '</div></div>';
      document.body.appendChild(card);
      sessionStorage.setItem('ham_pwa_prompt_shown_v563', '1');
      var later = qs('.ham-pwa-card-v563__later', card);
      if (later) later.onclick = dismissForever;
      var installBtn = qs('.ham-pwa-card-v563__install', card);
      if (installBtn) {
        installBtn.onclick = function(){
          if (!deferred) {
            removeCard();
            showCard(isIOS() ? 'ios' : 'manual');
            return;
          }
          deferred.prompt();
          Promise.resolve(deferred.userChoice).finally(function(){ removeCard(); deferred = null; });
        };
      }
      var guide = qs('.ham-pwa-card-v563__guide', card);
      if (guide) guide.onclick = removeCard;
    }

    window.addEventListener('beforeinstallprompt', function(e){
      e.preventDefault();
      deferred = e;
      showCard('ready');
    });
    window.addEventListener('appinstalled', function(){
      localStorage.removeItem(storageKey);
      removeCard();
    });

    setTimeout(function(){
      if (localStorage.getItem(storageKey) === '1' || isStandalone()) return;
      if (deferred) showCard('ready');
      else showCard(isIOS() ? 'ios' : 'manual');
    }, 1800);
  }

  ready(function(){
    hydrateAllFinanceRows();
    initCurvedNavV563();
    inlineAccountingAccordion(document);
    initPwaPromptV563();
    updateSaveStateV563();
  });

  document.addEventListener('input', function(e){
    var step = e.target && e.target.closest ? e.target.closest('.cptt-expert-step') : null;
    if (step && (e.target.matches('.cptt-step-paid,.cptt-step-cost,.cptt-step-unit-price,.cptt-step-qty') || /\[(cost|paid|unit_price|qty)\]$/.test(e.target.name || ''))) {
      financeHydrateStep(step);
    }
  }, true);

  document.addEventListener('click', function(e){
    if (e.target.closest('.cptt-expert-toggleProject,.cptt-expert-add-step,.cptt-expert-remove-step,.cptt-newProjectCta,[data-cptt-open-newproject],.cptt-admin-bridge__item,#cptt-admin-bridge-close')) {
      setTimeout(function(){ hydrateAllFinanceRows(); initCurvedNavV563(); inlineAccountingAccordion(document); updateSaveStateV563(); }, 120);
    }
  });
  document.addEventListener('cptt:adminBridgeLoaded', function(e){
    setTimeout(function(){ inlineAccountingAccordion(e.detail && e.detail.container ? e.detail.container : document); updateSaveStateV563(); }, 80);
  });
  window.addEventListener('resize', function(){ setTimeout(function(){ initCurvedNavV563(); updateSaveStateV563(); }, 80); }, { passive:true });
})();

/* =========================================================
   HAM v5.6.4 — captured Android PWA install + site-logo splash
   ========================================================= */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function qs(sel, ctx){ return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx){ return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function isMobile(){ return !!(window.matchMedia && window.matchMedia('(max-width: 820px)').matches); }
  function isStandalone(){
    try { return !!((window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true || String(document.referrer||'').indexOf('android-app://') === 0); }
    catch(e){ return false; }
  }
  function isIOS(){
    var ua = navigator.userAgent || '';
    return /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }
  function isAndroid(){ return /android/i.test(navigator.userAgent || ''); }

  function removeLegacyPwaUi(){ qsa('.ham-pwa-card,.ham-pwa-card-v563,.ham-pwa-install,.ham-pwa-splash').forEach(function(el){ el.remove(); }); }

  function installMessage(mode){
    if (mode === 'ios') return 'در Safari روی Share بزن و Add to Home Screen را انتخاب کن.';
    if (mode === 'manual') return 'اگر دکمه نصب مرورگر دیده نمی‌شود، از منوی مرورگر گزینه Install app یا Add to Home screen را بزن.';
    return 'برای نصب اپلیکیشن هماهنگ روی گوشی، دکمه نصب را بزن.';
  }

  function initReliablePwaPrompt(){
    if (!document.body.classList.contains('cptt-expert-dashboard-page') || !isMobile() || isStandalone()) return;
    removeLegacyPwaUi();
    var dismissedKey = 'ham_pwa_session_dismissed_v564';
    if (sessionStorage.getItem(dismissedKey) === '1') return;
    var card = null;

    function currentDeferred(){
      return window.CPTT_PWA_CAPTURE && window.CPTT_PWA_CAPTURE.deferred ? window.CPTT_PWA_CAPTURE.deferred : null;
    }

    function currentMode(){
      if (currentDeferred()) return 'ready';
      if (isIOS()) return 'ios';
      return 'manual';
    }

    function removeCard(){ if (card) { card.remove(); card = null; } }
    function dismiss(){ sessionStorage.setItem(dismissedKey, '1'); removeCard(); }

    function renderCard(){
      if (sessionStorage.getItem(dismissedKey) === '1' || isStandalone()) return;
      var mode = currentMode();
      if (!card) {
        card = document.createElement('div');
        card.className = 'ham-pwa-install-v564';
        document.body.appendChild(card);
      }
      var btnText = 'نصب';
      card.innerHTML = '<div class="ham-pwa-install-v564__card"><div class="ham-pwa-install-v564__logo">هما</div><div class="ham-pwa-install-v564__text"><strong>نصب اپلیکیشن هماهنگ</strong><p>' + installMessage(mode) + '</p></div><div class="ham-pwa-install-v564__actions"><button type="button" class="ham-pwa-install-v564__later">بعداً</button><button type="button" class="ham-pwa-install-v564__install">' + btnText + '</button></div></div>';
      var later = qs('.ham-pwa-install-v564__later', card);
      var installBtn = qs('.ham-pwa-install-v564__install', card);
      if (later) later.onclick = dismiss;
      if (installBtn) {
        installBtn.onclick = function(){
          var deferred = currentDeferred();
          if (deferred) {
            deferred.prompt();
            Promise.resolve(deferred.userChoice).finally(function(){ removeCard(); });
            return;
          }
          var p = qs('.ham-pwa-install-v564__text p', card);
          if (!p) return;
          if (isIOS()) p.textContent = installMessage('ios');
          else if (isAndroid()) p.textContent = 'از منوی مرورگر Chrome گزینه Install app یا Add to Home screen را بزن. اگر همین صفحه را دوباره باز کنی، به‌محض آماده شدن مرورگر دکمه نصب مستقیم فعال می‌شود.';
          else p.textContent = installMessage('manual');
        };
      }
    }

    window.addEventListener('cptt:pwa-ready', function(){ renderCard(); });
    window.addEventListener('cptt:pwa-installed', function(){ removeCard(); sessionStorage.removeItem(dismissedKey); });

    setTimeout(renderCard, 3500);
    setTimeout(renderCard, 7000);
  }

  function initLogoSplash(){
    if (!document.body.classList.contains('cptt-expert-dashboard-page') || !isStandalone()) return;
    qsa('.ham-standalone-splash').forEach(function(el){ el.remove(); });
    var cfg = window.CPTT_PWA_CONFIG || {};
    var logo = cfg.logo || '';
    var splash = document.createElement('div');
    splash.className = 'ham-standalone-splash';
    splash.innerHTML = '<div class="ham-standalone-splash__box">' +
      (logo ? '<div class="ham-standalone-splash__logoWrap"><img src="' + logo + '" alt="logo" class="ham-standalone-splash__logo"></div>' : '') +
      '<strong>به اپلیکیشن هماهنگ خوش آمدی</strong>' +
      '<span>در حال آماده‌سازی داشبورد...</span>' +
      '<div class="ham-standalone-splash__progress"><i></i></div><b>0%</b></div>';
    document.body.appendChild(splash);
    var bar = qs('i', splash), pct = qs('b', splash);
    var start = null, duration = 1250;
    function tick(ts){
      if (!start) start = ts;
      var progress = Math.min(1, (ts - start) / duration);
      var value = Math.max(0, Math.min(100, Math.round(progress * 100)));
      if (bar) bar.style.width = value + '%';
      if (pct) pct.textContent = value + '%';
      if (progress < 1) requestAnimationFrame(tick);
      else {
        splash.classList.add('is-hide');
        setTimeout(function(){ splash.remove(); }, 360);
      }
    }
    requestAnimationFrame(tick);
  }

  ready(function(){
    initReliablePwaPrompt();
    initLogoSplash();
  });
})();

/* =========================================================
   HAM v5.6.4b — suppress legacy PWA popups
   ========================================================= */
(function(){
  try {
    localStorage.setItem('ham_pwa_prompt_dismissed', '1');
    localStorage.setItem('ham_pwa_install_dismissed_v560', '1');
    sessionStorage.setItem('ham_pwa_prompt_shown_v563', '1');
  } catch(e) {}
})();

/* =========================================================
   HAM v5.6.6 — universal splash, offline banner, faster live chat
   ========================================================= */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function qs(sel, ctx){ return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx){ return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function isDashboard(){ return document.body.classList.contains('cptt-expert-dashboard-page'); }

  function initUniversalSplash(){
    if (!isDashboard()) return;
    qsa('.ham-pwa-splash,.ham-standalone-splash,.ham-universal-splash').forEach(function(el){ el.remove(); });
    var cfg = window.CPTT_PWA_CONFIG || {};
    var logo = cfg.logo || '';
    var splash = document.createElement('div');
    splash.className = 'ham-universal-splash';
    splash.innerHTML = '<div class="ham-universal-splash__box">' +
      (logo ? '<div class="ham-universal-splash__logoWrap"><img src="' + logo + '" alt="logo" class="ham-universal-splash__logo"></div>' : '<div class="ham-universal-splash__fallback">هما</div>') +
      '<strong>به اپلیکیشن هماهنگ خوش آمدی</strong>' +
      '<span>در حال آماده‌سازی داشبورد...</span>' +
      '<div class="ham-universal-splash__progress"><i></i></div><b>0%</b></div>';
    document.body.appendChild(splash);
    var bar = qs('i', splash), pct = qs('b', splash);
    var start = null, duration = 1050;
    function tick(ts){
      if (!start) start = ts;
      var progress = Math.min(1, (ts - start) / duration);
      var value = Math.max(0, Math.min(100, Math.round(progress * 100)));
      if (bar) bar.style.width = value + '%';
      if (pct) pct.textContent = value + '%';
      if (progress < 1) requestAnimationFrame(tick);
      else {
        splash.classList.add('is-hide');
        setTimeout(function(){ splash.remove(); }, 340);
      }
    }
    requestAnimationFrame(tick);
  }

  function initOfflineObserveBanner(){
    if (!isDashboard()) return;
    var banner = qs('.ham-offline-observe-banner');
    if (!banner) {
      banner = document.createElement('div');
      banner.className = 'ham-offline-observe-banner';
      banner.textContent = 'آفلاین - فقط مشاهده';
      document.body.appendChild(banner);
    }
    function sync(){ banner.classList.toggle('is-show', !navigator.onLine); }
    window.addEventListener('online', sync);
    window.addEventListener('offline', sync);
    sync();
  }

  function speedUpProjectChatPolling(){
    document.addEventListener('click', function(e){
      var openBtn = e.target.closest('.cptt-expert-chat-launch');
      if (!openBtn) return;
      setTimeout(function(){
        var modal = openBtn.closest('.cptt-expertCard').querySelector('.cptt-expert-chatModal');
        if (!modal) return;
        if (modal.dataset.hamFastPolling === '1') return;
        modal.dataset.hamFastPolling = '1';
        var form = qs('.cptt-expert-message-form', modal);
        if (!form) return;
        var iv = null;
        function refresh(){
          if (modal.hidden) return;
          try {
            var fd = new FormData();
            fd.append('action', 'cptt_expert_fetch_messages');
            fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
            fd.append('project_id', form.getAttribute('data-project-id') || '');
            fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', { method:'POST', credentials:'same-origin', body:fd })
              .then(function(r){ return r.json(); })
              .then(function(json){
                if (!(json && json.success)) return;
                var wrap = form.parentElement.querySelector('.cptt-expert-messagesWrap');
                var myId = (window.CPTT_EXPERT && CPTT_EXPERT.wpUserId) ? CPTT_EXPERT.wpUserId : 0;
                if (typeof renderMessages === 'function' && wrap) renderMessages((json.data && json.data.messages) || [], wrap, myId);
              }).catch(function(){});
          } catch(err){}
        }
        modal.addEventListener('DOMAttrModified', function(){});
        iv = setInterval(function(){ if (modal.hidden) return; refresh(); }, 2500);
        modal.addEventListener('click', function(ev){ if (ev.target.closest('.cptt-expert-chatModal__close,.cptt-expert-chatModal__backdrop')) { if (iv) { clearInterval(iv); iv = null; modal.dataset.hamFastPolling = ''; } } });
      }, 140);
    });
  }

  ready(function(){
    initUniversalSplash();
    initOfflineObserveBanner();
    speedUpProjectChatPolling();
  });
})();

/* =========================================================
   HAM v5.6.7 — disable all install popups by request
   ========================================================= */
(function(){
  try {
    localStorage.setItem('ham_pwa_prompt_dismissed', '1');
    localStorage.setItem('ham_pwa_install_dismissed_v560', '1');
    sessionStorage.setItem('ham_pwa_prompt_shown_v563', '1');
    sessionStorage.setItem('ham_pwa_session_dismissed_v564', '1');
  } catch(e) {}
})();

/* =========================================================
   HAM v5.6.8 — performant chat upgrade (telegram-like phase 1)
   ========================================================= */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function qs(sel, ctx){ return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx){ return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function escapeHtml(str){ return String(str || '').replace(/[&<>"']/g, function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]; }); }
  function currentAjax(){ return (window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : ''; }
  function currentNonce(){ return (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : ''; }
  function currentUserId(){ return (window.CPTT_EXPERT && CPTT_EXPERT.wpUserId) ? parseInt(CPTT_EXPERT.wpUserId, 10) : 0; }

  function pingPresence(){
    if (!(window.CPTT_EXPERT && CPTT_EXPERT.ajax && CPTT_EXPERT.nonce)) return;
    var fd = new FormData();
    fd.append('action', 'cptt_expert_ping_presence');
    fd.append('nonce', currentNonce());
    fetch(currentAjax(), { method:'POST', credentials:'same-origin', body:fd }).catch(function(){});
  }

  function normalizeMessageBody(rawBody){
    rawBody = rawBody || '';
    var linkMatch = rawBody.match(/href=(?:&quot;|"|')?([^"'>\s&]+)(?:&quot;|"|')?[^>]*class=(?:&quot;|"|')?cptt-chat-file-link/i);
    var fileUrl = linkMatch ? linkMatch[1] : '';
    var cleanText = rawBody.replace(/<a[^>]*cptt-chat-file-link.*?<\/a>/gi, '').replace(/&lt;a[^&]*cptt-chat-file-link.*?&lt;\/a&gt;/gi, '');
    var body = escapeHtml(cleanText.trim()).replace(/\n/g, '<br>');
    if (fileUrl) body += '<br><a href="' + escapeHtml(fileUrl) + '" target="_blank" class="cptt-chat-file-btn">👁 مشاهده فایل ضمیمه</a>';
    return { text: cleanText.trim(), html: body };
  }

  function renderProjectMessagesEnhanced(items, container){
    if (!container) return;
    if (!Array.isArray(items) || !items.length) { container.innerHTML = '<div class="cptt-expert-emptyMini">پیامی ثبت نشده است.</div>'; return; }
    var myId = currentUserId();
    container.innerHTML = items.map(function(message){
      var isMe = parseInt(message.sender_id, 10) === myId;
      var head = escapeHtml((message.sender_name || 'کاربر') + (message.recipient_name && message.recipient_name !== 'همه' ? ' → ' + message.recipient_name : ''));
      var time = escapeHtml(message.time_fa || '');
      var norm = normalizeMessageBody(message.content || '');
      var cls = isMe ? 'cptt-chat-bubble--me' : 'cptt-chat-bubble--other';
      return '<div class="cptt-chat-bubble ' + cls + '" data-chat-kind="project" data-id="' + escapeHtml(String(message.id || '')) + '" data-owned="' + (isMe ? '1' : '0') + '" data-text="' + escapeHtml(norm.text) + '"><div class="cptt-chat-bubble__head"><strong>' + head + '</strong><span>' + time + '</span></div><div class="cptt-chat-bubble__body">' + norm.html + '</div></div>';
    }).join('');
    container.scrollTop = container.scrollHeight;
  }

  function renderDirectMessagesEnhanced(items, container){
    if (!container) return;
    if (!Array.isArray(items) || !items.length) { container.innerHTML = '<div class="cptt-expert-emptyMini">پیامی وجود ندارد.</div>'; return; }
    var myId = currentUserId();
    container.innerHTML = items.map(function(message){
      var isMe = parseInt(message.sender_id, 10) === myId;
      var time = escapeHtml(message.time_fa || '');
      var norm = normalizeMessageBody(message.content || '');
      var cls = isMe ? 'cptt-chat-bubble--me' : 'cptt-chat-bubble--other';
      return '<div class="cptt-chat-bubble ' + cls + '" data-chat-kind="direct" data-id="' + escapeHtml(String(message.id || '')) + '" data-owned="' + (isMe ? '1' : '0') + '" data-text="' + escapeHtml(norm.text) + '"><div class="cptt-chat-bubble__head"><strong>' + escapeHtml(message.sender_name || 'کاربر') + '</strong><span>' + time + '</span></div><div class="cptt-chat-bubble__body">' + norm.html + '</div></div>';
    }).join('');
    container.scrollTop = container.scrollHeight;
  }

  function ensureReplyBox(form){
    if (!form || qs('.cptt-chat-reply-box', form)) return;
    var ta = qs('textarea', form);
    if (!ta) return;
    var box = document.createElement('div');
    box.className = 'cptt-chat-reply-box';
    box.hidden = true;
    box.innerHTML = '<div class="cptt-chat-reply-box__text"></div><button type="button" class="cptt-chat-reply-box__close">×</button>';
    ta.parentNode.insertBefore(box, ta);
    qs('.cptt-chat-reply-box__close', box).onclick = function(){ form.dataset.replyText = ''; box.hidden = true; qs('.cptt-chat-reply-box__text', box).textContent = ''; };
    form.addEventListener('submit', function(){ if (form.dataset.replyText) { ta.value = '↪️ در پاسخ به: ' + form.dataset.replyText + '\n' + ta.value; form.dataset.replyText = ''; box.hidden = true; qs('.cptt-chat-reply-box__text', box).textContent = ''; } }, true);
  }

  function setReplyText(form, text){
    if (!form) return;
    ensureReplyBox(form);
    var box = qs('.cptt-chat-reply-box', form);
    if (!box) return;
    form.dataset.replyText = text || '';
    qs('.cptt-chat-reply-box__text', box).textContent = text || '';
    box.hidden = !(text && text.length);
    var ta = qs('textarea', form);
    if (ta) ta.focus();
  }

  function ensureVoiceButton(form){
    if (!form || form.dataset.voiceReady === '1') return;
    form.dataset.voiceReady = '1';
    var actions = qs('.cptt-expert-formActions', form);
    var fileInput = qs('input[type="file"]', form);
    if (!actions || !fileInput || !window.MediaRecorder) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'cptt-btn cptt-btn--secondary cptt-chat-voice-btn';
    btn.textContent = '🎙 ویس';
    actions.insertBefore(btn, actions.firstChild);
    var recorder = null, chunks = [], stream = null;
    btn.addEventListener('click', async function(){
      try {
        if (recorder && recorder.state === 'recording') {
          recorder.stop();
          btn.textContent = '🎙 ویس';
          return;
        }
        stream = await navigator.mediaDevices.getUserMedia({ audio:true });
        recorder = new MediaRecorder(stream);
        chunks = [];
        recorder.ondataavailable = function(e){ if (e.data && e.data.size) chunks.push(e.data); };
        recorder.onstop = function(){
          var blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
          var ext = (blob.type.indexOf('ogg') > -1) ? 'ogg' : 'webm';
          var file = new File([blob], 'voice-message.' + ext, { type: blob.type || 'audio/webm' });
          try {
            var dt = new DataTransfer();
            dt.items.add(file);
            fileInput.files = dt.files;
            fileInput.dispatchEvent(new Event('change', { bubbles:true }));
          } catch(err) {}
          if (stream) { stream.getTracks().forEach(function(t){ t.stop(); }); stream = null; }
        };
        recorder.start();
        btn.textContent = '⏹ توقف ضبط';
      } catch(err) {
        btn.textContent = '🎙 ویس';
      }
    });
  }

  function ensureChatContextMenu(){
    var menu = qs('#cptt-chat-context-menu');
    if (menu) return menu;
    menu = document.createElement('div');
    menu.id = 'cptt-chat-context-menu';
    menu.className = 'cptt-chat-context-menu';
    menu.hidden = true;
    menu.innerHTML = '<button type="button" data-act="copy">کپی</button><button type="button" data-act="reply">پاسخ</button><button type="button" data-act="forward">فوروارد</button><button type="button" data-act="select">انتخاب</button><button type="button" data-act="delete">حذف</button>';
    document.body.appendChild(menu);
    document.addEventListener('click', function(e){ if (!menu.contains(e.target)) menu.hidden = true; });
    return menu;
  }

  function updateBulkToolbar(modal){
    if (!modal) return;
    var selected = qsa('.cptt-chat-bubble.is-selected', modal);
    var toolbar = qs('.cptt-chat-bulkbar', modal);
    if (!toolbar) {
      toolbar = document.createElement('div');
      toolbar.className = 'cptt-chat-bulkbar';
      toolbar.hidden = true;
      toolbar.innerHTML = '<span class="cptt-chat-bulkbar__count">0</span><div class="cptt-chat-bulkbar__actions"><button type="button" data-bulk="copy">کپی</button><button type="button" data-bulk="forward">فوروارد</button><button type="button" data-bulk="delete">حذف</button><button type="button" data-bulk="cancel">انصراف</button></div>';
      var body = qs('.cptt-expert-chatModal__dialog,.cptt-direct-chat-modal__dialog', modal) || modal;
      body.insertBefore(toolbar, body.firstChild);
      toolbar.addEventListener('click', function(e){
        var act = e.target.getAttribute('data-bulk');
        if (!act) return;
        var items = qsa('.cptt-chat-bubble.is-selected', modal);
        if (act === 'cancel') { items.forEach(function(el){ el.classList.remove('is-selected'); }); updateBulkToolbar(modal); return; }
        var texts = items.map(function(el){ return el.getAttribute('data-text') || ''; }).filter(Boolean).join('\n\n');
        if (act === 'copy' && texts) navigator.clipboard && navigator.clipboard.writeText(texts).catch(function(){});
        if (act === 'forward' && texts) {
          var form = qs('form', modal);
          var ta = form ? qs('textarea', form) : null;
          if (ta) { ta.value = '↪️ فوروارد:\n' + texts; ta.focus(); }
        }
        if (act === 'delete') {
          items.forEach(function(el){
            if (el.getAttribute('data-owned') !== '1') return;
            var kind = el.getAttribute('data-chat-kind');
            var id = el.getAttribute('data-id');
            if (kind === 'project') {
              var form = qs('.cptt-expert-message-form', modal);
              if (!form) return;
              var fd = new FormData();
              fd.append('action', 'cptt_expert_delete_project_message');
              fd.append('nonce', currentNonce());
              fd.append('project_id', form.getAttribute('data-project-id') || '');
              fd.append('message_id', id || '');
              fetch(currentAjax(), { method:'POST', credentials:'same-origin', body:fd }).catch(function(){});
            } else if (kind === 'direct') {
              var fd2 = new FormData();
              fd2.append('action', 'cptt_expert_delete_direct_message');
              fd2.append('nonce', currentNonce());
              fd2.append('message_id', id || '');
              fetch(currentAjax(), { method:'POST', credentials:'same-origin', body:fd2 }).catch(function(){});
            }
            el.remove();
          });
          updateBulkToolbar(modal);
        }
      });
    }
    qs('.cptt-chat-bulkbar__count', toolbar).textContent = String(selected.length);
    toolbar.hidden = !selected.length;
  }

  function bindBubbleContext(modal){
    if (!modal || modal.dataset.contextReady === '1') return;
    modal.dataset.contextReady = '1';
    var menu = ensureChatContextMenu();
    var pressTimer = null;
    function showMenu(bubble, x, y){
      modal._activeBubble = bubble;
      menu.hidden = false;
      menu.style.left = Math.max(10, Math.min(window.innerWidth - 170, x)) + 'px';
      menu.style.top = Math.max(10, Math.min(window.innerHeight - 220, y)) + 'px';
      var owned = bubble.getAttribute('data-owned') === '1';
      qsa('button[data-act="delete"]', menu).forEach(function(btn){ btn.style.display = owned ? '' : 'none'; });
    }
    modal.addEventListener('contextmenu', function(e){
      var bubble = e.target.closest('.cptt-chat-bubble');
      if (!bubble) return;
      e.preventDefault();
      showMenu(bubble, e.clientX, e.clientY);
    });
    modal.addEventListener('touchstart', function(e){
      var bubble = e.target.closest('.cptt-chat-bubble');
      if (!bubble) return;
      var touch = e.touches[0];
      pressTimer = setTimeout(function(){ showMenu(bubble, touch.clientX, touch.clientY); }, 480);
    }, { passive:true });
    modal.addEventListener('touchend', function(){ clearTimeout(pressTimer); });
    menu.addEventListener('click', function(e){
      var act = e.target.getAttribute('data-act');
      if (!act || !modal._activeBubble) return;
      var bubble = modal._activeBubble;
      var text = bubble.getAttribute('data-text') || '';
      var kind = bubble.getAttribute('data-chat-kind');
      var id = bubble.getAttribute('data-id') || '';
      var form = kind === 'project' ? qs('.cptt-expert-message-form', modal) : qs('.cptt-direct-chat-form', modal);
      if (act === 'copy' && text) navigator.clipboard && navigator.clipboard.writeText(text).catch(function(){});
      if (act === 'reply') setReplyText(form, text);
      if (act === 'forward') { var ta = form ? qs('textarea', form) : null; if (ta) { ta.value = '↪️ فوروارد:\n' + text; ta.focus(); } }
      if (act === 'select') { bubble.classList.toggle('is-selected'); updateBulkToolbar(modal); }
      if (act === 'delete' && bubble.getAttribute('data-owned') === '1') {
        if (kind === 'project') {
          var fd = new FormData();
          fd.append('action', 'cptt_expert_delete_project_message');
          fd.append('nonce', currentNonce());
          fd.append('project_id', form ? (form.getAttribute('data-project-id') || '') : '');
          fd.append('message_id', id);
          fetch(currentAjax(), { method:'POST', credentials:'same-origin', body:fd }).catch(function(){});
        } else if (kind === 'direct') {
          var fd2 = new FormData();
          fd2.append('action', 'cptt_expert_delete_direct_message');
          fd2.append('nonce', currentNonce());
          fd2.append('message_id', id);
          fetch(currentAjax(), { method:'POST', credentials:'same-origin', body:fd2 }).catch(function(){});
        }
        bubble.remove();
        updateBulkToolbar(modal);
      }
      menu.hidden = true;
    });
  }

  function enhanceChatModalUi(modal){
    if (!modal || modal.dataset.telegramish === '1') return;
    modal.dataset.telegramish = '1';
    var form = qs('.cptt-expert-message-form,.cptt-direct-chat-form', modal);
    ensureReplyBox(form);
    ensureVoiceButton(form);
    bindBubbleContext(modal);
    updateBulkToolbar(modal);
  }

  function fetchProjectMessagesEnhanced(modal){
    var form = qs('.cptt-expert-message-form', modal);
    if (!form) return;
    var wrap = form.parentElement.querySelector('.cptt-expert-messagesWrap');
    var fd = new FormData();
    fd.append('action', 'cptt_expert_fetch_messages');
    fd.append('nonce', currentNonce());
    fd.append('project_id', form.getAttribute('data-project-id') || '');
    fetch(currentAjax(), { method:'POST', credentials:'same-origin', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(json){ if (json && json.success) { renderProjectMessagesEnhanced((json.data && json.data.messages) || [], wrap); updateBulkToolbar(modal); } })
      .catch(function(){});
  }

  function fetchDirectMessagesEnhanced(modal){
    var recv = qs('#direct-chat-receiver-id', modal);
    var wrap = qs('#direct-chat-messages-container', modal);
    if (!recv || !wrap || !recv.value) return;
    var fd = new FormData();
    fd.append('action', 'cptt_expert_fetch_direct_messages');
    fd.append('nonce', currentNonce());
    fd.append('receiver_id', recv.value);
    fetch(currentAjax(), { method:'POST', credentials:'same-origin', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(json){ if (json && json.success) { renderDirectMessagesEnhanced(json.data || [], wrap); updateBulkToolbar(modal); } })
      .catch(function(){});
    var fd2 = new FormData();
    fd2.append('action', 'cptt_expert_get_expert_info');
    fd2.append('nonce', currentNonce());
    fd2.append('expert_id', recv.value);
    fetch(currentAjax(), { method:'POST', credentials:'same-origin', body:fd2 })
      .then(function(r){ return r.json(); })
      .then(function(json){ if (json && json.success) { var stats = qs('#direct-chat-stats', modal); if (stats) stats.textContent = (json.data.presence_text || '') + ' • ' + (json.data.stats || ''); } })
      .catch(function(){});
  }

  function initChatRealtimeLoops(){
    var projectLoops = new WeakMap();
    qsa('.cptt-expert-chatModal').forEach(function(modal){
      if (modal.dataset.loopReady === '1') return;
      modal.dataset.loopReady = '1';
      var openBtn = modal.closest('.cptt-expertCard') ? qs('.cptt-expert-chat-launch', modal.closest('.cptt-expertCard')) : null;
      function start(){ enhanceChatModalUi(modal); fetchProjectMessagesEnhanced(modal); if (projectLoops.get(modal)) clearInterval(projectLoops.get(modal)); projectLoops.set(modal, setInterval(function(){ if (!modal.hidden) fetchProjectMessagesEnhanced(modal); }, 2200)); }
      function stop(){ if (projectLoops.get(modal)) clearInterval(projectLoops.get(modal)); projectLoops.delete(modal); }
      if (openBtn) openBtn.addEventListener('click', function(){ setTimeout(start, 180); });
      qsa('.cptt-expert-chatModal__close,.cptt-expert-chatModal__backdrop', modal).forEach(function(el){ el.addEventListener('click', stop); });
    });

    var directModal = qs('.cptt-direct-chat-modal');
    if (directModal && !directModal.dataset.loopReady) {
      directModal.dataset.loopReady = '1';
      var directLoop = null;
      document.addEventListener('click', function(e){ if (e.target.closest('.cptt-expert-list-item')) { setTimeout(function(){ enhanceChatModalUi(directModal); fetchDirectMessagesEnhanced(directModal); if (directLoop) clearInterval(directLoop); directLoop = setInterval(function(){ if (!directModal.hidden) fetchDirectMessagesEnhanced(directModal); }, 2200); }, 250); } });
      qsa('.cptt-direct-chat-modal__close,.cptt-direct-chat-modal__backdrop', directModal).forEach(function(el){ el.addEventListener('click', function(){ if (directLoop) clearInterval(directLoop); directLoop = null; }); });
    }
  }

  ready(function(){
    pingPresence();
    setInterval(pingPresence, 60000);
    initChatRealtimeLoops();
  });
})();

/* =========================================================
   HAM v5.6.9 — complete chat replacement from scratch
   ========================================================= */
(function(){
  'use strict';
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function qs(sel, ctx){ return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx){ return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function escapeHtml(str){ return String(str || '').replace(/[&<>"']/g, function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]; }); }
  function ajax(){ return (window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : ''; }
  function nonce(){ return (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : ''; }
  function myId(){ return (window.CPTT_EXPERT && CPTT_EXPERT.wpUserId) ? parseInt(CPTT_EXPERT.wpUserId, 10) : 0; }

  var app = null;
  var state = {
    mode: 'project',
    projectId: 0,
    directId: 0,
    recipientId: 0,
    replyId: '',
    replyText: '',
    pollTimer: null,
    selected: new Set(),
    recorder: null,
    recordStream: null,
    recordChunks: [],
    recordStartedAt: 0,
    recordTimer: null,
    pendingVoiceFile: null,
    _menuBubbleId: ''
  };

  function normBody(rawBody){
    rawBody = rawBody || '';
    var replyId = '', replyText = '';
    var replyMatch = rawBody.match(/^\[\[REPLY:([^|\]]+)\|([\s\S]*?)\]\]\s*/);
    if (replyMatch) {
      replyId = String(replyMatch[1] || '');
      replyText = String(replyMatch[2] || '');
      rawBody = rawBody.replace(replyMatch[0], '');
    }
    var linkMatch = rawBody.match(/href=(?:&quot;|"|')?([^"'>\s&]+)(?:&quot;|"|')?[^>]*class=(?:&quot;|"|')?cptt-chat-file-link/i);
    var fileUrl = linkMatch ? linkMatch[1] : '';
    var cleanText = rawBody.replace(/<a[^>]*cptt-chat-file-link.*?<\/a>/gi, '').replace(/&lt;a[^&]*cptt-chat-file-link.*?&lt;\/a&gt;/gi, '');
    var body = escapeHtml(cleanText.trim()).replace(/\n/g, '<br>');
    var ext = '';
    if (fileUrl) {
      var em = String(fileUrl).toLowerCase().match(/\.([a-z0-9]+)(?:\?|$)/);
      ext = em ? em[1] : '';
      if (['mp3','wav','ogg','oga','m4a','aac','webm'].indexOf(ext) > -1) {
        body += '<div class="ham-chat__audioWrap"><audio controls preload="metadata" class="ham-chat__audio" src="' + escapeHtml(fileUrl) + '"></audio></div>';
      } else {
        body += '<br><a href="' + escapeHtml(fileUrl) + '" target="_blank" class="ham-chat__fileBtn">مشاهده فایل</a>';
      }
    }
    // Detect forward marker [[FWD:name]]
    var fwdFrom = '';
    var fwdMatch = cleanText.match(/^\s*\[\[FWD:([^\]]+)\]\]\s*/);
    if (fwdMatch) {
      fwdFrom = String(fwdMatch[1] || '').trim();
      cleanText = cleanText.replace(fwdMatch[0], '');
      body = escapeHtml(cleanText.trim()).replace(/\n/g, '<br>');
      if (fileUrl) {
        if (['mp3','wav','ogg','oga','m4a','aac','webm'].indexOf(ext) > -1) {
          body += '<div class="ham-chat__audioWrap"><audio controls preload="metadata" class="ham-chat__audio" src="' + escapeHtml(fileUrl) + '"></audio></div>';
        } else {
          body += '<br><a href="' + escapeHtml(fileUrl) + '" target="_blank" class="ham-chat__fileBtn">مشاهده فایل</a>';
        }
      }
    }
    return { text: cleanText.trim(), html: body, replyId: replyId, replyText: replyText, fileUrl: fileUrl, fileExt: ext, fwdFrom: fwdFrom };
  }

  function ensureApp(){
    if (app) return app;
    app = document.createElement('div');
    app.id = 'ham-chat-app';
    app.className = 'ham-chat';
    app.hidden = true;
    app.innerHTML = '' +
      '<div class="ham-chat__backdrop"></div>' +
      '<div class="ham-chat__dialog">' +
        '<div class="ham-chat__header">' +
          '<button type="button" class="ham-chat__close" aria-label="بستن">×</button>' +
          '<div class="ham-chat__peer">' +
            '<div class="ham-chat__avatarWrap"><img class="ham-chat__avatar" alt="avatar"><span class="ham-chat__statusDot"></span></div>' +
            '<div class="ham-chat__peerMeta"><strong class="ham-chat__title"></strong><small class="ham-chat__subtitle"></small></div>' +
          '</div>' +
          '<div class="ham-chat__headActions"><button type="button" class="ham-chat__clearSelect" hidden>لغو انتخاب</button></div>' +
        '</div>' +
        '<div class="ham-chat__bulkbar" hidden><span class="ham-chat__bulkCount">0</span><div class="ham-chat__bulkActions"><button type="button" data-bulk="copy">کپی</button><button type="button" data-bulk="forward">فوروارد</button><button type="button" data-bulk="delete">حذف</button></div></div>' +
        '<div class="ham-chat__recipientRow" hidden><select class="ham-chat__recipient"></select></div>' +
        '<div class="ham-chat__messages"></div>' +
        '<form class="ham-chat__composer">' +
          '<div class="ham-chat__reply" hidden><div class="ham-chat__replyText"></div><button type="button" class="ham-chat__replyClose">×</button></div>' +
          '<div class="ham-chat__recording" hidden><div class="ham-chat__recLeft"><span class="ham-chat__recordDot"></span><b class="ham-chat__recordTime">00:00</b><small class="ham-chat__recordState">در حال ضبط...</small></div><div class="ham-chat__recControls"><button type="button" class="ham-chat__recBtn ham-chat__recBtn--cancel" data-rec="cancel" title="لغو" aria-label="لغو">✕</button><button type="button" class="ham-chat__recBtn ham-chat__recBtn--pause" data-rec="pause" title="توقف موقت" aria-label="توقف موقت">⏸</button><button type="button" class="ham-chat__recBtn ham-chat__recBtn--send" data-rec="send" title="ارسال" aria-label="ارسال">➤</button></div></div>' +
          '<input type="file" class="ham-chat__fileInput" hidden>' +
          '<div class="ham-chat__composeRow">' +
            '<button type="button" class="ham-chat__attach" aria-label="پیوست">📎</button>' +
            '<button type="button" class="ham-chat__voice" aria-label="ویس">🎙</button>' +
            '<textarea class="ham-chat__input" rows="1" placeholder="پیام بنویسید..."></textarea>' +
            '<button type="submit" class="ham-chat__send" aria-label="ارسال">➤</button>' +
          '</div>' +
          '<div class="ham-chat__filePreview" hidden></div>' +
          '<div class="ham-chat__msg"></div>' +
        '</form>' +
      '</div>' +
      '<div class="ham-chat__menu" hidden><button type="button" data-act="copy">کپی</button><button type="button" data-act="reply">پاسخ</button><button type="button" data-act="forward">فوروارد</button><button type="button" data-act="select">انتخاب</button><button type="button" data-act="download">دانلود</button><button type="button" data-act="delete">حذف</button></div>' +
      '<div class="ham-chat__forward" hidden><div class="ham-chat__forwardBox"><div class="ham-chat__forwardHead"><strong>فوروارد به کارشناس</strong><button type="button" class="ham-chat__forwardClose">×</button></div><div class="ham-chat__forwardSearch"><input type="text" class="ham-chat__forwardSearchInput" placeholder="جستجوی کارشناس..."></div><div class="ham-chat__forwardList"></div></div></div>';
    document.body.appendChild(app);
    bindApp();
    return app;
  }

  function stopPolling(){ if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; } }
  function closeChat(){
    stopPolling();
    closeMenu();
    if (state.recordStream) { state.recordStream.getTracks().forEach(function(t){ t.stop(); }); state.recordStream = null; }
    state.recorder = null; state.recordChunks = []; state.replyId=''; state.replyText = ''; state.selected.clear(); state.pendingVoiceFile = null; if(state.recordTimer){clearInterval(state.recordTimer); state.recordTimer=null;} state.recordStartedAt=0;
    if (app) { app.hidden = true; app.classList.remove('is-context-open'); }
    document.body.classList.remove('ham-chat-open');
  }

  function bindApp(){
    qs('.ham-chat__backdrop', app).addEventListener('click', closeChat);
    qs('.ham-chat__close', app).addEventListener('click', closeChat);
    qs('.ham-chat__replyClose', app).addEventListener('click', function(){ state.replyId=''; state.replyText=''; syncReply(); });
    qs('.ham-chat__attach', app).addEventListener('click', function(){ qs('.ham-chat__fileInput', app).click(); });
    qs('.ham-chat__fileInput', app).addEventListener('change', syncFilePreview);
    qs('.ham-chat__voice', app).addEventListener('click', toggleVoiceRecord);
    qs('.ham-chat__recording', app).addEventListener('click', function(e){
      var btn = e.target.closest('.ham-chat__recBtn'); if (!btn) return;
      e.preventDefault(); e.stopPropagation();
      handleRecAction(btn.getAttribute('data-rec'));
    });
    qs('.ham-chat__composer', app).addEventListener('submit', sendCurrentMessage);
    qs('.ham-chat__forwardClose', app).addEventListener('click', closeForwardSheet);
    qs('.ham-chat__clearSelect', app).addEventListener('click', function(){ state.selected.clear(); syncSelection(); });
    qs('.ham-chat__bulkbar', app).addEventListener('click', function(e){
      var act = e.target.getAttribute('data-bulk');
      if (!act) return;
      var selected = currentSelectedBubbles();
      var texts = selected.map(function(el){ return el.getAttribute('data-text') || ''; }).filter(Boolean).join('\n\n');
      if (act === 'copy' && texts && navigator.clipboard) navigator.clipboard.writeText(texts).catch(function(){});
      if (act === 'forward' && texts) { openForwardSheet(texts); }
      if (act === 'delete') { batchDeleteSelected(); }
    });
    /* v5.7.1: context-menu opens for the nearest bubble row, even if user
       clicks/long-presses the empty area beside the bubble (inside messages area) */
    function findBubbleFromEvent(target){
      var bubble = target.closest('.ham-chat__bubble');
      if (bubble) return bubble;
      /* Clicked beside a bubble — find the parent row / nearest bubble */
      var msgArea = target.closest('.ham-chat__messages');
      if (!msgArea) return null;
      return null; /* will be resolved by row-scan in handler */
    }
    function findBubbleRowAt(y){
      var bubbles = qsa('.ham-chat__bubble', qs('.ham-chat__messages', app));
      var best = null, bestDist = Infinity;
      for (var i = 0; i < bubbles.length; i++){
        var r = bubbles[i].getBoundingClientRect();
        var cy = r.top + r.height / 2;
        var d = Math.abs(y - cy);
        if (d < bestDist){ bestDist = d; best = bubbles[i]; }
      }
      /* only match if within 60px vertical */
      return (best && bestDist < 60) ? best : null;
    }

    app.addEventListener('contextmenu', function(e){
      /* Don't open on composer area */
      if (e.target.closest('.ham-chat__composer,.ham-chat__header,.ham-chat__bulkbar,.ham-chat__recipientRow')) return;
      var bubble = e.target.closest('.ham-chat__bubble') || findBubbleRowAt(e.clientY);
      if (!bubble) return;
      e.preventDefault();
      openMenuForBubble(bubble, e.clientX, e.clientY);
    });
    var pressTimer = null, pressStartY = 0;
    app.addEventListener('touchstart', function(e){
      if (e.target.closest('.ham-chat__composer,.ham-chat__header,.ham-chat__bulkbar,.ham-chat__recipientRow,audio,a')) return;
      var bubble = e.target.closest('.ham-chat__bubble') || findBubbleRowAt(e.touches[0].clientY);
      if (!bubble) return;
      var t = e.touches[0];
      pressStartY = t.clientY;
      pressTimer = setTimeout(function(){ openMenuForBubble(bubble, t.clientX, t.clientY); }, 430);
    }, { passive:true });
    app.addEventListener('touchmove', function(e){
      if (pressTimer && e.touches[0] && Math.abs(e.touches[0].clientY - pressStartY) > 10) { clearTimeout(pressTimer); pressTimer = null; }
    }, { passive:true });
    app.addEventListener('touchend', function(){ clearTimeout(pressTimer); pressTimer = null; }, { passive:true });
    qs('.ham-chat__menu', app).addEventListener('click', function(e){
      var act = e.target.getAttribute('data-act');
      var bubble = app._menuBubble;
      if (!act || !bubble) return;
      var text = bubble.getAttribute('data-text') || '';
      if (act === 'copy' && text && navigator.clipboard) navigator.clipboard.writeText(text).catch(function(){});
      if (act === 'reply') { state.replyId = bubble.getAttribute('data-id') || ''; state.replyText = text; syncReply(); }
      if (act === 'forward' && text) { openForwardSheet(text); }
      if (act === 'download' && bubble.getAttribute('data-file-url')) { window.open(bubble.getAttribute('data-file-url'),'_blank'); }
      if (act === 'select') {
        var id = bubble.getAttribute('data-id');
        if (state.selected.has(id)) state.selected.delete(id); else state.selected.add(id);
        syncSelection();
      }
      if (act === 'delete') deleteSingleBubble(bubble);
      closeMenu();
    });

    qs('.ham-chat__messages', app).addEventListener('click', function(e){
      var replyBtn = e.target.closest('.ham-chat__replySnippet');
      if (replyBtn) {
        var rid = String(replyBtn.getAttribute('data-reply-id') || '').replace(/(["\\])/g,'\\$1');
        var target = qs('.ham-chat__bubble[data-id="' + rid + '"]', app);
        if (target) { target.scrollIntoView({ behavior:'smooth', block:'center' }); target.classList.add('is-jump-highlight'); setTimeout(function(){ target.classList.remove('is-jump-highlight'); }, 1200); }
        return;
      }
      var bubble = e.target.closest('.ham-chat__bubble');
      if (!bubble || e.target.closest('audio,a')) return;
      if (app.classList.contains('is-selection-mode')) {
        var bid = bubble.getAttribute('data-id');
        if (state.selected.has(bid)) state.selected.delete(bid); else state.selected.add(bid);
        syncSelection();
      }
    });
    qs('.ham-chat__input', app).addEventListener('input', autoGrow);
  }

  function autoGrow(){
    var ta = qs('.ham-chat__input', app);
    if (!ta) return;
    ta.style.height = 'auto';
    ta.style.height = Math.min(120, ta.scrollHeight) + 'px';
  }

  function syncReply(){
    var row = qs('.ham-chat__reply', app);
    qs('.ham-chat__replyText', app).textContent = state.replyText || '';
    row.hidden = !state.replyText;
    row.style.display = state.replyText ? '' : 'none';
  }

  function syncFilePreview(){
    var input = qs('.ham-chat__fileInput', app);
    var preview = qs('.ham-chat__filePreview', app);
    if (!input.files || !input.files.length) { preview.hidden = true; preview.style.display='none'; preview.textContent = ''; return; }
    preview.hidden = false;
    preview.style.display = 'flex';
    preview.innerHTML = '<span>' + escapeHtml(input.files[0].name) + '</span><button type="button" class="ham-chat__fileRemove">×</button>';
    qs('.ham-chat__fileRemove', preview).onclick = function(){ input.value = ''; syncFilePreview(); };
  }

  function _stopRecordStream(){
    if (state.recordStream) { try { state.recordStream.getTracks().forEach(function(t){ t.stop(); }); } catch(e){} state.recordStream = null; }
  }
  function _hideRecordingUI(){
    var rec = qs('.ham-chat__recording', app);
    if (rec) { rec.hidden = true; rec.style.display='none'; rec.classList.remove('is-paused'); }
    var row = qs('.ham-chat__composeRow', app); if (row) row.classList.remove('is-hidden');
    var btn = qs('.ham-chat__voice', app); if (btn) { btn.classList.remove('is-recording'); btn.textContent = '🎙'; }
    if (state.recordTimer) { clearInterval(state.recordTimer); state.recordTimer = null; }
  }
  function _setRecState(text, paused){
    var s = qs('.ham-chat__recordState', app);
    if (s) s.textContent = text || '';
    var rec = qs('.ham-chat__recording', app);
    if (rec) rec.classList.toggle('is-paused', !!paused);
    var pBtn = qs('.ham-chat__recBtn--pause', app);
    if (pBtn) { pBtn.textContent = paused ? '▶' : '⏸'; pBtn.title = paused ? 'ادامه' : 'توقف موقت'; pBtn.setAttribute('aria-label', pBtn.title); }
  }

  async function _startRecording(){
    var fileInput = qs('.ham-chat__fileInput', app);
    try {
      state.recordStream = await navigator.mediaDevices.getUserMedia({ audio:true });
      state.recordChunks = [];
      state.recorder = new MediaRecorder(state.recordStream);
      state.recorder.ondataavailable = function(e){ if (e.data && e.data.size) state.recordChunks.push(e.data); };
      state.recorder.onstop = function(){
        // Only auto-send if user chose to send
        if (state._recordCancel) {
          state._recordCancel = false;
          state.recordChunks = [];
          state.pendingVoiceFile = null;
          _stopRecordStream();
          _hideRecordingUI();
          state.recordStartedAt = 0; state._recordElapsed = 0;
          return;
        }
        var blob = new Blob(state.recordChunks, { type: (state.recorder && state.recorder.mimeType) || 'audio/webm' });
        var ext = (blob.type || '').indexOf('ogg') > -1 ? 'ogg' : 'webm';
        var file = new File([blob], 'voice-message.' + ext, { type: blob.type || 'audio/webm' });
        /* Store voice file directly in state — DataTransfer is unreliable */
        state.pendingVoiceFile = file;
        _stopRecordStream();
        _hideRecordingUI();
        state.recordStartedAt = 0; state._recordElapsed = 0;
        if (state._recordSendAfterStop) {
          state._recordSendAfterStop = false;
          // trigger send
          var form = qs('.ham-chat__composer', app);
          if (form) form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', {cancelable:true,bubbles:true}));
        } else {
          syncFilePreviewChat();
        }
      };
      state._recordCancel = false;
      state._recordSendAfterStop = false;
      state._recordElapsed = 0;
      state.recorder.start();
      state.recordStartedAt = Date.now();
      if(state.recordTimer) clearInterval(state.recordTimer);
      state.recordTimer = setInterval(function(){
        if (state.recorder && state.recorder.state === 'paused') return;
        var sec = Math.floor(((Date.now()-state.recordStartedAt) + (state._recordElapsed||0))/1000);
        var mm = String(Math.floor(sec/60)).padStart(2,'0');
        var ss = String(sec%60).padStart(2,'0');
        var rt = qs('.ham-chat__recordTime', app); if(rt) rt.textContent = mm + ':' + ss;
      }, 250);
      var rec = qs('.ham-chat__recording', app);
      if (rec) { rec.hidden = false; rec.style.display='flex'; }
      var row = qs('.ham-chat__composeRow', app); if (row) row.classList.add('is-hidden');
      var btn = qs('.ham-chat__voice', app); if (btn) { btn.classList.add('is-recording'); btn.textContent = '●'; }
      _setRecState('در حال ضبط...', false);
    } catch(err) {
      _hideRecordingUI();
    }
  }

  async function toggleVoiceRecord(){
    if (!window.MediaRecorder || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      alert('مرورگر شما از ضبط صدا پشتیبانی نمی‌کند');
      return;
    }
    if (state.recorder && (state.recorder.state === 'recording' || state.recorder.state === 'paused')) {
      // Already recording: don't toggle (controls are in the recording bar)
      return;
    }
    await _startRecording();
  }

  function handleRecAction(action){
    if (!state.recorder) return;
    if (action === 'pause') {
      if (state.recorder.state === 'recording') {
        try { state.recorder.pause(); } catch(e){}
        state._recordElapsed = (state._recordElapsed||0) + (Date.now() - state.recordStartedAt);
        _setRecState('متوقف شد', true);
      } else if (state.recorder.state === 'paused') {
        try { state.recorder.resume(); } catch(e){}
        state.recordStartedAt = Date.now();
        _setRecState('در حال ضبط...', false);
      }
    } else if (action === 'cancel') {
      state._recordCancel = true;
      try { if (state.recorder.state !== 'inactive') state.recorder.stop(); } catch(e){}
    } else if (action === 'send') {
      state._recordSendAfterStop = true;
      try { if (state.recorder.state !== 'inactive') state.recorder.stop(); } catch(e){}
    }
  }


  function openMenuForBubble(bubble, x, y){
    var menu = qs('.ham-chat__menu', app);
    /* Close any existing context first */
    if (app._menuBubble && app._menuBubble !== bubble) {
      app._menuBubble.classList.remove('is-context-focus');
    }
    app._menuBubble = bubble;
    state._menuBubbleId = bubble.getAttribute('data-id') || '';

    /* v5.7.1: allow deleting any message */
    qsa('button[data-act="delete"]', menu).forEach(function(btn){ btn.style.display = ''; });
    qsa('button[data-act="download"]', menu).forEach(function(btn){ btn.style.display = bubble.getAttribute('data-file-url') ? '' : 'none'; });

    /* v5.7.0 — focus effect: highlight bubble, blur rest */
    bubble.classList.add('is-context-focus');
    app.classList.add('is-context-open');

    /* Show menu to measure its size, then position smartly */
    menu.hidden = false;
    menu.style.left = '0px';
    menu.style.top = '0px';
    menu.style.visibility = 'hidden';

    requestAnimationFrame(function(){
      var menuRect = menu.getBoundingClientRect();
      var bubbleRect = bubble.getBoundingClientRect();
      var vw = window.innerWidth, vh = window.innerHeight;
      var mW = menuRect.width || 160, mH = menuRect.height || 200;

      /* Horizontal: center on click X, clamp to viewport */
      var left = Math.max(10, Math.min(vw - mW - 10, x - mW / 2));

      /* Vertical: if bubble is in lower half → show menu ABOVE the bubble (upward)
         if bubble is in upper half → show menu BELOW the bubble (downward) */
      var top;
      var bubbleMidY = bubbleRect.top + bubbleRect.height / 2;
      if (bubbleMidY > vh * 0.5) {
        /* Bubble is low — menu opens upward */
        top = bubbleRect.top - mH - 8;
        if (top < 10) top = 10;
      } else {
        /* Bubble is high — menu opens downward */
        top = bubbleRect.bottom + 8;
        if (top + mH > vh - 10) top = vh - mH - 10;
      }

      menu.style.left = Math.round(left) + 'px';
      menu.style.top = Math.round(top) + 'px';
      menu.style.visibility = '';
    });
  }
  function closeMenu(){
    var menu = qs('.ham-chat__menu', app); menu.hidden = true;
    /* v5.7.0 — remove focus effect */
    if (app._menuBubble) app._menuBubble.classList.remove('is-context-focus');
    app._menuBubble = null;
    state._menuBubbleId = '';
    app.classList.remove('is-context-open');
  }
  document.addEventListener('click', function(e){ if (app && !qs('.ham-chat__menu', app).contains(e.target)) closeMenu(); });

  function currentSelectedBubbles(){
    return qsa('.ham-chat__bubble', qs('.ham-chat__messages', app)).filter(function(el){ return state.selected.has(el.getAttribute('data-id')); });
  }

  function syncSelection(){
    qsa('.ham-chat__bubble', qs('.ham-chat__messages', app)).forEach(function(el){ el.classList.toggle('is-selected', state.selected.has(el.getAttribute('data-id'))); });
    var has = state.selected.size > 0;
    app.classList.toggle('is-selection-mode', has);
    qs('.ham-chat__bulkbar', app).hidden = !has; qs('.ham-chat__bulkbar', app).style.display = has ? 'flex' : 'none';
    qs('.ham-chat__clearSelect', app).hidden = !has;
    qs('.ham-chat__bulkCount', app).textContent = String(state.selected.size);
  }

  function sortItems(items){
    return (items || []).slice().sort(function(a,b){
      /* Use server-provided seq (insertion order) as primary key — guarantees
         correct order even when legacy messages have time=0 */
      var sa = parseInt(a.seq, 10), sb = parseInt(b.seq, 10);
      if (!isNaN(sa) && !isNaN(sb) && sa !== sb) return sa - sb;
      var ta = parseInt(a.time || 0, 10), tb = parseInt(b.time || 0, 10);
      if (ta !== tb) return ta - tb;
      /* direct-chat rows: numeric id = database AUTO_INCREMENT */
      var ia = parseInt(a.id, 10), ib = parseInt(b.id, 10);
      if (!isNaN(ia) && !isNaN(ib) && ia !== ib) return ia - ib;
      return String(a.id || '').localeCompare(String(b.id || ''));
    });
  }

  function buildBubbleHtml(message){
    var isMe = parseInt(message.sender_id, 10) === myId();
    var head = state.mode === 'project'
      ? escapeHtml((message.sender_name || 'کاربر') + (message.recipient_name && message.recipient_name !== 'همه' ? ' → ' + message.recipient_name : ''))
      : escapeHtml(message.sender_name || 'کاربر');
    var time = escapeHtml(message.time_fa || '');
    var norm = normBody(message.content || '');
    var cls = isMe ? 'ham-chat__bubble--me' : 'ham-chat__bubble--other';
    var reply = (norm.replyId && norm.replyText) ? '<button type="button" class="ham-chat__replySnippet" data-reply-id="' + escapeHtml(norm.replyId) + '">' + escapeHtml(norm.replyText) + '</button>' : '';
    var fwdBadge = norm.fwdFrom ? '<div class="ham-chat__fwdBadge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17l5-5-5-5"></path><path d="M20 12H9a4 4 0 0 0-4 4v3"></path></svg>فوروارد از ' + escapeHtml(norm.fwdFrom) + '</div>' : '';
    /* v5.7.1: data-text includes file indicator so reply/forward always has content */
    var replyLabel = norm.text || '';
    if (!replyLabel && norm.fileUrl) {
      var isAudio = norm.fileExt && ['mp3','wav','ogg','oga','m4a','aac','webm'].indexOf(norm.fileExt) > -1;
      replyLabel = isAudio ? '🎤 پیام صوتی' : '📎 فایل پیوست';
    }
    return '<div class="ham-chat__bubble ' + cls + '" data-id="' + escapeHtml(String(message.id || '')) + '" data-owned="' + (isMe ? '1' : '0') + '" data-text="' + escapeHtml(replyLabel) + '" data-file-url="' + escapeHtml(norm.fileUrl || '') + '"><div class="ham-chat__bubbleHead"><strong>' + head + '</strong><span>' + time + '</span></div>' + fwdBadge + reply + '<div class="ham-chat__bubbleBody">' + norm.html + '</div></div>';
  }

  function isNearBottom(wrap){
    if (!wrap) return true;
    return (wrap.scrollHeight - wrap.scrollTop - wrap.clientHeight) < 120;
  }

  function renderItems(items){
    var wrap = qs('.ham-chat__messages', app);
    items = sortItems(items);
    if (!items.length) {
      wrap.innerHTML = '<div class="ham-chat__empty">هنوز پیامی ثبت نشده است.</div>';
      app._renderedIds = new Set();
      syncSelection();
      return;
    }
    var existing = app._renderedIds instanceof Set ? app._renderedIds : null;
    var empty = wrap.querySelector('.ham-chat__empty,.ham-chat__loading');
    if (empty) { wrap.innerHTML = ''; existing = null; }
    if (!existing) {
      // Full render (first time / cleared)
      app._renderedIds = new Set();
      var html = '';
      items.forEach(function(m){
        html += buildBubbleHtml(m);
        app._renderedIds.add(String(m.id || ''));
      });
      wrap.innerHTML = html;
      requestAnimationFrame(function(){ wrap.scrollTop = wrap.scrollHeight; enhanceAudioPlayers(wrap); });
      syncSelection();
      return;
    }
    var stickToBottom = isNearBottom(wrap);
    var appended = false;
    var seenNow = new Set();
    var lastNode = null;
    items.forEach(function(m){
      var id = String(m.id || '');
      seenNow.add(id);
      if (existing.has(id)) return;
      existing.add(id);
      var temp = document.createElement('div');
      temp.innerHTML = buildBubbleHtml(m);
      var node = temp.firstChild;
      wrap.appendChild(node);
      lastNode = node;
      appended = true;
    });
    // Remove bubbles that were deleted server-side
    Array.prototype.slice.call(wrap.querySelectorAll('.ham-chat__bubble')).forEach(function(el){
      var id = el.getAttribute('data-id') || '';
      if (id && !seenNow.has(id)) { existing.delete(id); el.parentNode && el.parentNode.removeChild(el); }
    });
    if (appended) {
      enhanceAudioPlayers(wrap);
      if (stickToBottom) requestAnimationFrame(function(){ wrap.scrollTop = wrap.scrollHeight; });
    }
    syncSelection();
  }

  async function fetchProjectMessages(){
    var fd = new FormData();
    fd.append('action', 'cptt_expert_fetch_messages');
    fd.append('nonce', nonce());
    fd.append('project_id', String(state.projectId));
    var res = await fetch(ajax(), { method:'POST', credentials:'same-origin', body:fd });
    var json = await res.json();
    if (json && json.success) renderItems((json.data && json.data.messages) || []);
  }

  async function fetchDirectMessages(){
    var fd = new FormData();
    fd.append('action', 'cptt_expert_fetch_direct_messages');
    fd.append('nonce', nonce());
    fd.append('receiver_id', String(state.directId));
    var res = await fetch(ajax(), { method:'POST', credentials:'same-origin', body:fd });
    var json = await res.json();
    if (json && json.success) renderItems(json.data || []);
  }

  async function refreshHeaderPresence(){
    if (state.mode !== 'direct' || !state.directId) return;
    var fd = new FormData();
    fd.append('action', 'cptt_expert_get_expert_info');
    fd.append('nonce', nonce());
    fd.append('expert_id', String(state.directId));
    var res = await fetch(ajax(), { method:'POST', credentials:'same-origin', body:fd });
    var json = await res.json();
    if (!(json && json.success)) return;
    var data = json.data || {};
    qs('.ham-chat__subtitle', app).textContent = (data.presence_text || '') + (data.stats ? (' • ' + data.stats) : '');
    var dot = qs('.ham-chat__statusDot', app);
    if (dot) dot.classList.toggle('is-online', !!(data.presence && data.presence.online));
    if (data.avatar) qs('.ham-chat__avatar', app).src = data.avatar;
    if (data.name) qs('.ham-chat__title', app).textContent = data.name;
  }

  function startPolling(){
    stopPolling();
    state.pollTimer = setInterval(function(){
      if (app.hidden) return;
      if (state.mode === 'project') fetchProjectMessages().catch(function(){});
      if (state.mode === 'direct') { fetchDirectMessages().catch(function(){}); refreshHeaderPresence().catch(function(){}); }
    }, 2200);
  }

  function stopPolling(){ if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; } }

  async function sendCurrentMessage(e){
    e.preventDefault();
    var ta = qs('.ham-chat__input', app);
    var fileInput = qs('.ham-chat__fileInput', app);
    var msg = qs('.ham-chat__msg', app);
    var text = (ta.value || '').trim();
    var voiceFile = state.pendingVoiceFile || null;
    var hasFile = voiceFile || (fileInput.files && fileInput.files.length);
    if (!text && !hasFile) return;
    var fd = new FormData();
    var submitBtn = qs('.ham-chat__send', app);
    submitBtn.disabled = true; msg.textContent = 'در حال ارسال...';
    try {
      if (state.replyId) text = '[[REPLY:' + state.replyId + '|' + state.replyText + ']] ' + text;
      /* Determine the actual file to send: voice recording takes priority */
      var actualFile = voiceFile || ((fileInput.files && fileInput.files.length) ? fileInput.files[0] : null);
      if (state.mode === 'project') {
        fd.append('action', 'cptt_expert_send_message');
        fd.append('nonce', nonce());
        fd.append('project_id', String(state.projectId));
        fd.append('recipient_id', String(qs('.ham-chat__recipient', app).value || '0'));
        fd.append('content', text);
        if (actualFile) fd.append('chat_file', actualFile, actualFile.name || 'voice.webm');
        var res = await fetch(ajax(), { method:'POST', credentials:'same-origin', body:fd });
        var json = await res.json();
        if (!(json && json.success)) throw new Error((json && json.data) ? json.data : 'خطا در ارسال پیام');
        renderItems((json.data && json.data.messages) || []);
      } else {
        fd.append('action', 'cptt_expert_send_direct_message');
        fd.append('nonce', nonce());
        fd.append('receiver_id', String(state.directId));
        fd.append('message', text);
        if (actualFile) fd.append('chat_file', actualFile, actualFile.name || 'voice.webm');
        var res2 = await fetch(ajax(), { method:'POST', credentials:'same-origin', body:fd });
        var json2 = await res2.json();
        if (!(json2 && json2.success)) throw new Error((json2 && json2.data) ? json2.data : 'خطا در ارسال پیام');
        renderItems(json2.data || []);
      }
      ta.value = ''; autoGrowChat();
      fileInput.value = ''; state.pendingVoiceFile = null; syncFilePreviewChat();
      state.replyId=''; state.replyText = ''; syncReplyChat();
      msg.textContent = '';
    } catch(err) {
      msg.textContent = err.message || 'خطا در ارسال پیام';
    } finally {
      submitBtn.disabled = false;
    }
  }

  function autoGrowChat(){
    var ta = qs('.ham-chat__input', app); if (!ta) return;
    ta.style.height = 'auto'; ta.style.height = Math.min(120, ta.scrollHeight) + 'px';
  }
  function syncReplyChat(){ var row = qs('.ham-chat__reply', app); qs('.ham-chat__replyText', app).textContent = state.replyText || ''; row.hidden = !state.replyText; row.style.display = state.replyText ? 'flex' : 'none'; }
  function syncFilePreviewChat(){
    var input = qs('.ham-chat__fileInput', app), preview = qs('.ham-chat__filePreview', app);
    var hasInputFile = input.files && input.files.length;
    var hasVoice = !!state.pendingVoiceFile;
    if (!hasInputFile && !hasVoice) { preview.hidden = true; preview.style.display='none'; preview.innerHTML = ''; return; }
    var name = hasVoice ? (state.pendingVoiceFile.name || 'voice-message.webm') : input.files[0].name;
    preview.hidden = false;
    preview.style.display = 'flex';
    preview.innerHTML = '<span>' + (hasVoice ? '🎤 ' : '') + escapeHtml(name) + '</span><button type="button" class="ham-chat__fileRemove">×</button>';
    qs('.ham-chat__fileRemove', preview).onclick = function(){ input.value = ''; state.pendingVoiceFile = null; syncFilePreviewChat(); };
  }

  function batchDeleteSelected(){ currentSelectedBubbles().forEach(deleteSingleBubble); }

  function deleteSingleBubble(bubble){
    if (!bubble || bubble.getAttribute('data-owned') !== '1') return;
    var id = bubble.getAttribute('data-id') || '';
    if (!id) return;
    if (state.mode === 'project') {
      var fd = new FormData();
      fd.append('action', 'cptt_expert_delete_project_message');
      fd.append('nonce', nonce());
      fd.append('project_id', String(state.projectId));
      fd.append('message_id', id);
      fetch(ajax(), { method:'POST', credentials:'same-origin', body:fd }).then(function(r){ return r.json(); }).then(function(json){ if (json && json.success) renderItems((json.data && json.data.messages) || []); }).catch(function(){});
    } else {
      var fd2 = new FormData();
      fd2.append('action', 'cptt_expert_delete_direct_message');
      fd2.append('nonce', nonce());
      fd2.append('message_id', id);
      fetch(ajax(), { method:'POST', credentials:'same-origin', body:fd2 }).then(function(r){ return r.json(); }).then(function(json){ if (json && json.success) renderItems(json.data || []); }).catch(function(){});
    }
  }


  function buildExpertForwardTargets(){
    var targets = [];
    var seen = {};
    // 1) Live directory items (with avatar)
    Array.prototype.slice.call(document.querySelectorAll('.cptt-expert-list-item[data-expert-id]')).forEach(function(item){
      var id = item.getAttribute('data-expert-id'); if (!id || seen[id]) return; seen[id] = 1;
      // skip self
      if (parseInt(id,10) === myId()) return;
      /* Find the expert name — skip spans that are avatar wrappers or mood badges */
      var nameEl = null;
      var allSpans = Array.prototype.slice.call(item.querySelectorAll(':scope > span'));
      for (var si = 0; si < allSpans.length; si++) {
        var sp = allSpans[si];
        if (sp.classList.contains('cptt-moodAvatarWrap') || sp.classList.contains('cptt-moodAvatarBadge')) continue;
        if (sp.querySelector('img')) continue; /* wrapper containing avatar img */
        nameEl = sp; break;
      }
      var name = nameEl ? nameEl.textContent.trim() : '';
      /* Fallback: use data attribute or alt text from img */
      if (!name) {
        var altImg = item.querySelector('img[alt]');
        if (altImg && altImg.getAttribute('alt')) name = altImg.getAttribute('alt').trim();
      }
      if (!name) name = 'کارشناس #' + id;
      var imgEl = item.querySelector('img');
      var avatar = imgEl ? imgEl.getAttribute('src') : '';
      if (!avatar) {
        avatar = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 60 60"><rect width="60" height="60" rx="30" fill="%234f46e5"/><text x="30" y="38" text-anchor="middle" font-size="24" font-weight="900" fill="white" font-family="sans-serif">' + encodeURIComponent((name||'?').charAt(0)) + '</text></svg>';
      }
      var hint = '';
      var sub = item.querySelector('small,.cptt-expert-list-item__role,.cptt-expert-list-item__sub');
      if (sub) hint = sub.textContent.trim();
      targets.push({ id:id, name:name, avatar:avatar, hint:hint });
    });
    // 2) Fallback: experts from project cards if directory not present
    if (!targets.length) {
      Array.prototype.slice.call(document.querySelectorAll('.cptt-expertCard select[name="recipient_id"] option')).forEach(function(opt){
        var id = String(opt.value || '0'); if (id === '0' || seen[id]) return; seen[id] = 1;
        if (parseInt(id,10) === myId()) return;
        var name = (opt.textContent || '').trim();
        targets.push({ id:id, name:name, avatar:'', hint:'' });
      });
    }
    return targets;
  }

  function closeForwardSheet(){ var sheet = qs('.ham-chat__forward', app); if (sheet) sheet.hidden = true; }

  function _renderForwardList(targets, q){
    var list = qs('.ham-chat__forwardList', app); if (!list) return;
    q = (q || '').toLowerCase().trim();
    var filtered = q ? targets.filter(function(t){ return (t.name || '').toLowerCase().indexOf(q) !== -1; }) : targets;
    if (!filtered.length) {
      list.innerHTML = '<div class="ham-chat__forwardEmpty">کارشناسی برای فوروارد یافت نشد.</div>';
      return;
    }
    list.innerHTML = filtered.map(function(t){
      var av = t.avatar ? '<img src="' + escapeHtml(t.avatar) + '" alt="">' : '<img alt="">';
      var meta = '<div class="ham-chat__fwdMeta"><span class="ham-chat__fwdName">' + escapeHtml(t.name) + '</span>' + (t.hint ? '<span class="ham-chat__fwdHint">' + escapeHtml(t.hint) + '</span>' : '') + '</div>';
      return '<button type="button" class="ham-chat__forwardItem" data-id="' + escapeHtml(String(t.id)) + '">' + av + meta + '</button>';
    }).join('');
  }

  function _showForwardToast(text){
    var box = qs('.ham-chat__forwardBox', app); if (!box) return;
    var t = document.createElement('div'); t.className = 'ham-chat__forwardToast'; t.textContent = text || 'پیام ارسال شد';
    box.appendChild(t);
    setTimeout(function(){ if (t.parentNode) t.parentNode.removeChild(t); }, 2400);
  }

  function openForwardSheet(texts){
    var sheet = qs('.ham-chat__forward', app); if (!sheet) return;
    app._forwardPayload = texts || '';
    var targets = buildExpertForwardTargets();
    app._forwardTargets = targets;
    _renderForwardList(targets, '');
    var search = qs('.ham-chat__forwardSearchInput', app);
    if (search) {
      search.value = '';
      search.oninput = function(){ _renderForwardList(app._forwardTargets || [], this.value); };
    }
    sheet.hidden = false;
    var list = qs('.ham-chat__forwardList', app);
    if (list) {
      list.onclick = function(e){
        var item = e.target.closest('.ham-chat__forwardItem');
        if (!item || item.classList.contains('is-sending') || item.classList.contains('is-sent')) return;
        item.classList.add('is-sending');
        sendForwardPayload(item.getAttribute('data-id'), app._forwardPayload || '').then(function(ok){
          item.classList.remove('is-sending');
          if (ok) {
            item.classList.add('is-sent');
            var name = (item.querySelector('.ham-chat__fwdName') || {}).textContent || 'کارشناس';
            _showForwardToast('پیام به ' + name + ' ارسال شد ✓');
          }
        });
      };
    }
  }

  async function sendForwardPayload(id, payload){
    if (!payload || !id || String(id) === '0') return false;
    var senderName = (window.CPTT_EXPERT && CPTT_EXPERT.wpUserName) ? CPTT_EXPERT.wpUserName : 'کاربر';
    var marked = '[[FWD:' + senderName + ']] ' + payload;
    var fd = new FormData();
    fd.append('action', 'cptt_expert_send_direct_message');
    fd.append('nonce', nonce());
    fd.append('receiver_id', String(id || '0'));
    fd.append('message', marked);
    try {
      var res = await fetch(ajax(), { method:'POST', credentials:'same-origin', body:fd });
      var json = await res.json();
      return !!(json && json.success);
    } catch (e) { return false; }
  }

  function openProjectChat(card){
    ensureApp();
    state.mode = 'project';
    state.projectId = parseInt(card.getAttribute('data-project-id') || '0', 10) || 0;
    state.directId = 0;
    state.replyId = '';
    state.replyText = '';
    state.selected.clear();
    qs('.ham-chat__title', app).textContent = (function(){ var h = card.querySelector('h3'); if (!h) return 'گفتگوی پروژه'; var clone = h.cloneNode(true); qsa('.cptt-project-code', clone).forEach(function(el){ el.remove(); }); return clone.textContent.trim(); })();
    qs('.ham-chat__subtitle', app).textContent = 'گفتگوی پروژه';
    qs('.ham-chat__avatar', app).src = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><rect width="64" height="64" rx="22" fill="%234f46e5"/><path d="M18 22h12v12H18zm16 0h12v12H34zM18 38h12v12H18zm16 0h12V26H34z" fill="white"/></svg>';
    qs('.ham-chat__statusDot', app).classList.remove('is-online');
    var recipientRow = qs('.ham-chat__recipientRow', app);
    var recipient = qs('.ham-chat__recipient', app);
    recipient.innerHTML = '<option value="0">همه کارشناسان پروژه</option>';
    var oldForm = card.querySelector('.cptt-expert-chatModal .cptt-expert-message-form');
    if (oldForm) {
      qsa('select[name="recipient_id"] option', oldForm).forEach(function(opt){ if (String(opt.value || '0') !== '0') recipient.appendChild(opt.cloneNode(true)); });
    }
    recipientRow.hidden = false;
    qs('.ham-chat__messages', app).innerHTML = '<div class="ham-chat__loading">در حال بارگذاری گفتگو...</div>';
    qs('.ham-chat__msg', app).textContent = '';
    qs('.ham-chat__input', app).value = ''; autoGrowChat();
    qs('.ham-chat__fileInput', app).value = ''; syncFilePreviewChat(); syncReplyChat(); syncSelection(); closeMenu();
    app.hidden = false; document.body.classList.add('ham-chat-open');
    fetchProjectMessages().catch(function(){});
    startPolling();
  }

  async function openDirectChat(expertId){
    ensureApp();
    state.mode = 'direct';
    state.projectId = 0;
    state.directId = parseInt(expertId || '0', 10) || 0;
    state.replyId = '';
    state.replyText = '';
    state.selected.clear();
    qs('.ham-chat__recipientRow', app).hidden = true;
    qs('.ham-chat__messages', app).innerHTML = '<div class="ham-chat__loading">در حال بارگذاری گفتگو...</div>';
    qs('.ham-chat__msg', app).textContent = '';
    qs('.ham-chat__input', app).value = ''; autoGrowChat();
    qs('.ham-chat__fileInput', app).value = ''; syncFilePreviewChat(); syncReplyChat(); syncSelection(); closeMenu();
    app.hidden = false; document.body.classList.add('ham-chat-open');
    await refreshHeaderPresence().catch(function(){});
    await fetchDirectMessages().catch(function(){});
    startPolling();
  }

  /* ===== Custom Audio Player (Telegram-like) ===== */
  function _fmtTime(s){
    if (!isFinite(s) || s < 0) s = 0;
    var m = Math.floor(s/60), ss = Math.floor(s%60);
    return String(m).padStart(2,'0') + ':' + String(ss).padStart(2,'0');
  }
  function _buildAudioPlayer(audio){
    var url = audio.getAttribute('src') || '';
    var wrap = document.createElement('div');
    wrap.className = 'ham-cap';
    var BARS = 32;
    var barsHtml = '';
    // pseudo-random but stable heights based on URL hash
    var seed = 0; for (var i=0;i<url.length;i++) seed = (seed*31 + url.charCodeAt(i)) >>> 0;
    function rnd(){ seed = (seed * 1664525 + 1013904223) >>> 0; return (seed >>> 8) / 16777216; }
    var heights = [];
    for (var j=0;j<BARS;j++){
      var h = 30 + Math.floor(rnd() * 70); // 30%..100%
      heights.push(h);
      barsHtml += '<span class="ham-cap__bar" style="height:' + h + '%"></span>';
    }
    wrap.innerHTML = ''
      + '<button type="button" class="ham-cap__btn" aria-label="پخش">'
      +   '<svg class="ham-cap__icoPlay" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>'
      +   '<svg class="ham-cap__icoPause" viewBox="0 0 24 24" fill="currentColor" style="display:none"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>'
      + '</button>'
      + '<div class="ham-cap__body">'
      +   '<div class="ham-cap__wave" role="slider" aria-label="موقعیت پخش">' + barsHtml + '<span class="ham-cap__head"></span></div>'
      +   '<div class="ham-cap__meta"><span class="ham-cap__cur">00:00</span><span class="ham-cap__dur">--:--</span></div>'
      + '</div>'
      + '<button type="button" class="ham-cap__speed" aria-label="سرعت پخش">1x</button>';
    var btn = wrap.querySelector('.ham-cap__btn');
    var icoP = wrap.querySelector('.ham-cap__icoPlay');
    var icoPa = wrap.querySelector('.ham-cap__icoPause');
    var wave = wrap.querySelector('.ham-cap__wave');
    var head = wrap.querySelector('.ham-cap__head');
    var curEl = wrap.querySelector('.ham-cap__cur');
    var durEl = wrap.querySelector('.ham-cap__dur');
    var spdBtn = wrap.querySelector('.ham-cap__speed');
    var bars = Array.prototype.slice.call(wrap.querySelectorAll('.ham-cap__bar'));
    var speeds = [1, 1.5, 2, 0.75];
    var spdIdx = 0;

    function updateUI(){
      var d = audio.duration; var c = audio.currentTime;
      if (isFinite(d) && d > 0) {
        wrap.classList.add('is-loaded');
        durEl.textContent = _fmtTime(d);
        var pct = Math.max(0, Math.min(1, c/d));
        var activeCount = Math.round(pct * bars.length);
        bars.forEach(function(b, i){ b.classList.toggle('is-active', i < activeCount); });
        var waveRect = wave.getBoundingClientRect();
        head.style.left = (pct * waveRect.width) + 'px';
      }
      curEl.textContent = _fmtTime(c);
    }
    function setPlayingUI(playing){
      icoP.style.display = playing ? 'none' : '';
      icoPa.style.display = playing ? '' : 'none';
      btn.setAttribute('aria-label', playing ? 'توقف' : 'پخش');
    }
    btn.addEventListener('click', function(e){
      e.preventDefault();
      // Pause other players first
      Array.prototype.slice.call(document.querySelectorAll('audio.ham-chat__audio')).forEach(function(a){ if (a !== audio) try{ a.pause(); }catch(e){} });
      if (audio.paused) audio.play().catch(function(){}); else audio.pause();
    });
    audio.addEventListener('play', function(){ setPlayingUI(true); });
    audio.addEventListener('pause', function(){ setPlayingUI(false); });
    audio.addEventListener('ended', function(){ setPlayingUI(false); audio.currentTime = 0; updateUI(); });
    audio.addEventListener('loadedmetadata', updateUI);
    audio.addEventListener('timeupdate', updateUI);
    audio.addEventListener('durationchange', updateUI);

    function seekFromEvent(e){
      var rect = wave.getBoundingClientRect();
      var x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
      var pct = Math.max(0, Math.min(1, x / rect.width));
      if (isFinite(audio.duration) && audio.duration > 0) {
        audio.currentTime = audio.duration * pct;
        updateUI();
      }
    }
    var dragging = false;
    wave.addEventListener('mousedown', function(e){ dragging = true; seekFromEvent(e); });
    document.addEventListener('mousemove', function(e){ if (dragging) seekFromEvent(e); });
    document.addEventListener('mouseup', function(){ dragging = false; });
    wave.addEventListener('touchstart', function(e){ dragging = true; seekFromEvent(e); }, {passive:true});
    wave.addEventListener('touchmove', function(e){ if (dragging) seekFromEvent(e); }, {passive:true});
    wave.addEventListener('touchend', function(){ dragging = false; });

    spdBtn.addEventListener('click', function(e){
      e.preventDefault();
      spdIdx = (spdIdx + 1) % speeds.length;
      audio.playbackRate = speeds[spdIdx];
      spdBtn.textContent = speeds[spdIdx] + 'x';
    });

    // Force load metadata
    if (audio.readyState >= 1) updateUI();
    else { try { audio.load(); } catch(e){} }

    return wrap;
  }
  function enhanceAudioPlayers(root){
    if (!root) return;
    Array.prototype.slice.call(root.querySelectorAll('.ham-chat__audioWrap')).forEach(function(w){
      if (w.dataset.capReady === '1') return;
      var audio = w.querySelector('audio.ham-chat__audio');
      if (!audio) return;
      w.dataset.capReady = '1';
      var player = _buildAudioPlayer(audio);
      w.appendChild(player);
    });
  }

  function interceptOldChatButtons(){
    document.addEventListener('click', function(e){
      var projectBtn = e.target.closest('.cptt-expert-chat-launch');
      if (projectBtn) {
        e.preventDefault();
        e.stopPropagation();
        if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
        var card = projectBtn.closest('.cptt-expertCard');
        if (card) openProjectChat(card);
        return;
      }
      var expertItem = e.target.closest('.cptt-expert-list-item');
      if (expertItem && expertItem.hasAttribute('data-expert-id')) {
        e.preventDefault();
        e.stopPropagation();
        if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
        var expertsModal = qs('.cptt-experts-mobile-modal');
        if (expertsModal) expertsModal.setAttribute('hidden', '');
        openDirectChat(expertItem.getAttribute('data-expert-id'));
        return;
      }
    }, true);
  }

  ready(function(){
    interceptOldChatButtons();
  });
})();
