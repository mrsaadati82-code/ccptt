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
      function close() { modal.hidden = true; document.body.classList.remove('cptt-chat-modal-open'); if (timer) { window.clearInterval(timer); timer = null; } }
      async function open() { if (modal.parentNode !== document.body) document.body.appendChild(modal); modal.hidden = false; document.body.classList.add('cptt-chat-modal-open'); await refreshMessages(form); if (timer) window.clearInterval(timer); timer = window.setInterval(function () { refreshMessages(form); }, 8000); }
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
