(function () {
  function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function updateVisibility() {
    const search = (qs('#cptt-expert-search') || {}).value ? qs('#cptt-expert-search').value.toLowerCase().trim() : '';
    const status = (qs('#cptt-expert-status') || {}).value || '';
    const settled = (qs('#cptt-expert-settled') || {}).value || '';
    const client = (qs('#cptt-expert-client') || {}).value || '';
    const product = (qs('#cptt-expert-product') || {}).value || '';
    const cat = (qs('#cptt-expert-cat') || {}).value || '';
    let visible = 0;
    qsa('.cptt-expertCard').forEach(card => {
      let ok = true;
      const dataSearch = String(card.getAttribute('data-search') || '').toLowerCase();
      const dataStatus = String(card.getAttribute('data-status') || '');
      const dataSettled = String(card.getAttribute('data-settled') || '');
      const dataClient = String(card.getAttribute('data-client') || '');
      const dataProduct = String(card.getAttribute('data-product') || '');
      const dataCats = String(card.getAttribute('data-cats') || '');
      if (search && dataSearch.indexOf(search) === -1) ok = false;
      if (status && dataStatus !== status) ok = false;
      if (settled !== '' && dataSettled !== settled) ok = false;
      if (client && dataClient !== client) ok = false;
      if (product && dataProduct !== product) ok = false;
      if (cat && dataCats.indexOf(',' + cat + ',') === -1) ok = false;
      card.hidden = !ok;
      if (ok) visible++;
    });
    const empty = qs('#cptt-expert-empty');
    if (empty) empty.hidden = visible !== 0;
  }

  function bindProjectToggles() {
    qsa('.cptt-expert-toggleProject').forEach(btn => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => {
        const card = btn.closest('.cptt-expertCard');
        if (!card) return;
        const details = qs('.cptt-expertCard__details', card);
        if (!details) return;
        const isOpen = !details.hidden;
        qsa('.cptt-expertCard').forEach(c => {
          const d = qs('.cptt-expertCard__details', c);
          const b = qs('.cptt-expert-toggleProject', c);
          if (d) d.hidden = true;
          c.classList.remove('is-expanded');
          if (b) b.textContent = 'مدیریت پروژه';
        });
        if (!isOpen) {
          details.hidden = false;
          card.classList.add('is-expanded');
          btn.textContent = 'بستن مدیریت';
        }
      });
    });
  }

  function bindStepAccordions(scope) {
    qsa('.cptt-expert-step', scope || document).forEach(step => {
      const toggle = qs('.cptt-expert-step__toggle', step);
      const body = qs('.cptt-expert-step__body', step);
      if (!toggle || !body || toggle.dataset.bound) return;
      toggle.dataset.bound = '1';
      toggle.addEventListener('click', () => {
        const parent = step.parentElement;
        if (parent) {
          qsa('.cptt-expert-step', parent).forEach(other => {
            if (other === step) return;
            const ob = qs('.cptt-expert-step__body', other);
            const ot = qs('.cptt-expert-step__toggle', other);
            if (ob) ob.hidden = true;
            if (ot) ot.setAttribute('aria-expanded', 'false');
          });
        }
        body.hidden = !body.hidden;
        toggle.setAttribute('aria-expanded', body.hidden ? 'false' : 'true');
      });
    });
  }

  function applySummary(card, data) {
    if (!card || !data || !data.progress) return;
    const bar = qs('.cptt-expertCard__progress span', card);
    if (bar) bar.style.width = Math.max(0, Math.min(100, parseInt(data.progress.percent || 0, 10))) + '%';
    const badge = qs('.cptt-expertStatusBadge', card);
    if (badge) {
      badge.textContent = data.progress.label || 'در حال انجام';
      badge.className = 'cptt-expertStatusBadge cptt-expertStatusBadge--' + (data.progress.status || 'in_progress');
    }
    const last = qs('.cptt-expert-last-update', card);
    if (last) last.textContent = data.last_update_fa || '—';
    const stat = qsa('.cptt-expertCard__stats strong', card);
    if (stat[0]) stat[0].textContent = (data.progress.percent || 0) + '%';
  }


  function bindCreateForm() {
    qsa('.cptt-expert-create-form').forEach(form => {
      if (form.dataset.bound) return;
      form.dataset.bound = '1';
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = qs('.cptt-expert-formMsg', form);
        const btn = qs('button[type="submit"]', form);
        if (msg) msg.textContent = '';
        if (btn) { btn.disabled = true; btn.textContent = 'در حال ایجاد...'; }
        try {
          const fd = new FormData(form);
          fd.append('action', 'cptt_expert_create_project');
          fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
          const res = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', {
            method: 'POST', credentials: 'same-origin', body: fd
          });
          const json = await res.json();
          if (!json || !json.success) throw new Error((json && json.data) ? json.data : 'خطا در ایجاد پروژه');
          if (msg) msg.textContent = 'پروژه با موفقیت ایجاد شد.';
          window.setTimeout(function(){ window.location.href = (json.data && json.data.redirect) ? json.data.redirect : window.location.href; }, 500);
        } catch(err) {
          if (msg) msg.textContent = err.message || 'خطا در ایجاد پروژه';
        } finally {
          if (btn) { btn.disabled = false; btn.textContent = 'ایجاد پروژه'; }
        }
      });
    });
  }

  function bindSaveForms() {
    qsa('.cptt-expert-project-form').forEach(form => {
      if (form.dataset.bound) return;
      form.dataset.bound = '1';
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = qs('.cptt-expert-formMsg', form);
        const btn = qs('button[type="submit"]', form);
        if (msg) msg.textContent = '';
        if (btn) {
          btn.disabled = true;
          btn.textContent = (window.CPTT_EXPERT && CPTT_EXPERT.texts && CPTT_EXPERT.texts.saving) || 'در حال ذخیره...';
        }
        try {
          const fd = new FormData(form);
          fd.append('action', 'cptt_expert_save_project');
          fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
          const res = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
          });
          const json = await res.json();
          if (!json || !json.success) throw new Error((window.CPTT_EXPERT && CPTT_EXPERT.texts && CPTT_EXPERT.texts.error) || 'خطا در ذخیره اطلاعات');
          if (msg) msg.textContent = (window.CPTT_EXPERT && CPTT_EXPERT.texts && CPTT_EXPERT.texts.saved) || 'تغییرات با موفقیت ذخیره شد.';
          const card = form.closest('.cptt-expertCard');
          applySummary(card, json.data || {});
          const textarea = qs('textarea[name="note"]', form);
          if (textarea) textarea.value = '';
          window.setTimeout(function () { window.location.reload(); }, 600);
        } catch (err) {
          if (msg) msg.textContent = err.message || 'خطا در ذخیره اطلاعات';
        } finally {
          if (btn) {
            btn.disabled = false;
            btn.textContent = 'ذخیره تغییرات';
          }
        }
      });
    });
  }


  function bindMessageForms() {
    qsa('.cptt-expert-message-form').forEach(form => {
      if (form.dataset.bound) return;
      form.dataset.bound = '1';
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = qs('.cptt-expert-formMsg', form);
        const btn = qs('button[type="submit"]', form);
        if (msg) msg.textContent = '';
        if (btn) { btn.disabled = true; btn.textContent = 'در حال ارسال...'; }
        try {
          const fd = new FormData(form);
          fd.append('action', 'cptt_expert_send_message');
          fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
          const res = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', { method:'POST', credentials:'same-origin', body:fd });
          const json = await res.json();
          if (!json || !json.success) throw new Error((json && json.data) ? json.data : 'خطا در ارسال پیام');
          if (msg) msg.textContent = 'پیام ارسال شد.';
          const ta = qs('textarea[name="content"]', form); if (ta) ta.value = '';
          const wrap = form.parentElement.querySelector('.cptt-expert-messagesWrap');
          if (wrap) wrap.innerHTML = renderMessages((json.data && json.data.messages) || []);
        } catch(err) {
          if (msg) msg.textContent = err.message || 'خطا در ارسال پیام';
        } finally {
          if (btn) { btn.disabled = false; btn.textContent = 'ارسال پیام'; }
        }
      });
    });
  }


  function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, function (m) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]; });
  }

  function renderMessages(items) {
    if (!Array.isArray(items) || !items.length) return '<div class="cptt-expert-emptyMini">پیامی ثبت نشده است.</div>';
    return items.map(function (message) {
      const head = escapeHtml(((message.sender_name || 'کاربر') + ' → ' + (message.recipient_name || 'همه')));
      const time = escapeHtml(message.time_fa || '');
      const body = escapeHtml(message.content || '').replace(/\n/g, '<br>');
      return '<div class="cptt-expert-noteItem"><div class="cptt-expert-noteItem__head"><strong>' + head + '</strong><span>' + time + '</span></div><div class="cptt-expert-noteItem__body">' + body + '</div></div>';
    }).join('');
  }

  async function refreshMessages(form) {
    if (!form) return;
    const projectId = form.getAttribute('data-project-id') || '';
    const wrap = form.parentElement.querySelector('.cptt-expert-messagesWrap');
    if (!projectId || !wrap) return;
    const fd = new FormData();
    fd.append('action', 'cptt_expert_fetch_messages');
    fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
    fd.append('project_id', projectId);
    const res = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', { method:'POST', credentials:'same-origin', body:fd });
    const json = await res.json();
    if (json && json.success && wrap) wrap.innerHTML = renderMessages((json.data && json.data.messages) || []);
  }

  function bindChatModals() {
    qsa('.cptt-expert-chatModal').forEach(modal => {
      if (modal.dataset.bound) return;
      modal.dataset.bound = '1';
      const card = modal.closest('.cptt-expertCard');
      const openBtn = card ? qs('.cptt-expert-chat-launch', card) : null;
      const closeBtn = qs('.cptt-expert-chatModal__close', modal);
      const backdrop = qs('.cptt-expert-chatModal__backdrop', modal);
      const form = qs('.cptt-expert-message-form', modal);
      let timer = null;
      function close() {
        modal.hidden = true;
        if (timer) { window.clearInterval(timer); timer = null; }
      }
      async function open() {
        modal.hidden = false;
        await refreshMessages(form);
        if (timer) window.clearInterval(timer);
        timer = window.setInterval(function(){ refreshMessages(form); }, 6000);
      }
      if (openBtn) openBtn.addEventListener('click', open);
      if (closeBtn) closeBtn.addEventListener('click', close);
      if (backdrop) backdrop.addEventListener('click', close);
    });
  }

  function initJalaliPicker() {
    function toFa(str){ return String(str||'').replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]); }
    function toEn(str){ const fa='۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', en='01234567890123456789'; return String(str||'').replace(/[۰-۹٠-٩]/g, ch => en[fa.indexOf(ch)] || ch); }
    function g2j(gy, gm, gd){ const gdm=[0,31,59,90,120,151,181,212,243,273,304,334]; const gy2=(gm>2)?gy+1:gy; let days=355666+(365*gy)+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)+gd+gdm[gm-1]; let jy=-1595+33*Math.floor(days/12053); days%=12053; jy+=4*Math.floor(days/1461); days%=1461; if(days>365){ jy+=Math.floor((days-1)/365); days=(days-1)%365; } let jm,jd; if(days<186){ jm=1+Math.floor(days/31); jd=1+(days%31); } else { jm=7+Math.floor((days-186)/30); jd=1+((days-186)%30); } return [jy,jm,jd]; }
    function j2g(jy,jm,jd){ jy=parseInt(jy,10)+1595; let days=-355668+(365*jy)+Math.floor(jy/33)*8+Math.floor(((jy%33)+3)/4)+parseInt(jd,10); days+=(jm<7)?((jm-1)*31):(((jm-7)*30)+186); let gy=400*Math.floor(days/146097); days%=146097; if(days>36524){ gy+=100*Math.floor(--days/36524); days%=36524; if(days>=365) days++; } gy+=4*Math.floor(days/1461); days%=1461; if(days>365){ gy+=Math.floor((days-1)/365); days=(days-1)%365; } let gd=days+1; const sal=[0,31,(((gy%4===0)&&(gy%100!==0))||(gy%400===0))?29:28,31,30,31,30,31,31,30,31,30,31]; let gm=1; for(;gm<=12;gm++){ if(gd<=sal[gm]) break; gd-=sal[gm]; } return [gy,gm,gd]; }
    function monthLen(jy,jm){ return jm<=6?31:(jm<=11?30:30); }
    const monthNames=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    let activeInput=null, viewJ=null;
    const cal=document.createElement('div');
    cal.className='cptt-jdp';
    cal.dir='rtl';
    cal.style.display='none';
    document.body.appendChild(cal);
    function draw(){
      if(!activeInput || !viewJ) return;
      const ml=monthLen(viewJ.jy, viewJ.jm);
      const g=j2g(viewJ.jy, viewJ.jm, 1);
      const first=new Date(g[0], g[1]-1, g[2]).getDay();
      const start=(first+1)%7;
      const now=new Date(); const tj=g2j(now.getFullYear(), now.getMonth()+1, now.getDate());
      let html='<div class="cptt-jdp__head"><button type="button" data-nav="prev">‹</button><strong>'+monthNames[viewJ.jm-1]+' '+toFa(viewJ.jy)+'</strong><button type="button" data-nav="next">›</button></div>';
      html+='<div class="cptt-jdp__week"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div><div class="cptt-jdp__days">';
      for(let i=0;i<start;i++) html+='<span></span>';
      for(let d=1; d<=ml; d++){
        let cls='';
        if(viewJ.jd===d) cls+=' is-selected';
        if(tj[0]===viewJ.jy && tj[1]===viewJ.jm && tj[2]===d) cls+=' is-today';
        html+='<button type="button" class="'+cls.trim()+'" data-day="'+d+'">'+toFa(d)+'</button>';
      }
      html+='</div><div class="cptt-jdp__time"><input type="number" min="0" max="23" value="'+String(viewJ.hh||12).padStart(2,'0')+'"><span>:</span><input type="number" min="0" max="59" value="'+String(viewJ.ii||0).padStart(2,'0')+'"></div><div class="cptt-jdp__foot"><button type="button" data-today="1">امروز</button><button type="button" data-close="1">بستن</button></div>';
      cal.innerHTML=html;
    }
    function open(input){
      activeInput=input;
      const m=String(input.value||'').match(/(\d{4})\/(\d{1,2})\/(\d{1,2})(?:\s+(\d{1,2}):(\d{1,2}))?/);
      if(m){ viewJ={jy:+toEn(m[1]), jm:+toEn(m[2]), jd:+toEn(m[3]), hh:m[4]?+toEn(m[4]):12, ii:m[5]?+toEn(m[5]):0}; }
      else { const n=new Date(); const j=g2j(n.getFullYear(),n.getMonth()+1,n.getDate()); viewJ={jy:j[0],jm:j[1],jd:j[2],hh:n.getHours(),ii:n.getMinutes()}; }
      draw();
      const r=input.getBoundingClientRect();
      cal.style.top=(window.scrollY + r.bottom + 6)+'px';
      cal.style.left=(window.scrollX + r.left)+'px';
      cal.style.display='block';
    }
    function setDate(day){
      const inputs=cal.querySelectorAll('.cptt-jdp__time input');
      const hh=Math.max(0,Math.min(23,parseInt(inputs[0].value||'0',10)));
      const ii=Math.max(0,Math.min(59,parseInt(inputs[1].value||'0',10)));
      viewJ.jd=day; viewJ.hh=hh; viewJ.ii=ii;
      activeInput.value = toFa(viewJ.jy+'/'+String(viewJ.jm).padStart(2,'0')+'/'+String(day).padStart(2,'0')+' '+String(hh).padStart(2,'0')+':'+String(ii).padStart(2,'0'));
      cal.style.display='none';
    }
    document.addEventListener('click', function(e){
      const input=e.target.closest('.cptt-jalali-datetime');
      if(input){ open(input); return; }
      if(!cal.contains(e.target)){ cal.style.display='none'; }
    });
    cal.addEventListener('click', function(e){
      const nav=e.target.closest('[data-nav]'); if(nav){ viewJ.jm += nav.dataset.nav==='next'?1:-1; if(viewJ.jm>12){viewJ.jm=1;viewJ.jy++;} if(viewJ.jm<1){viewJ.jm=12;viewJ.jy--;} draw(); return; }
      const day=e.target.closest('[data-day]'); if(day){ setDate(parseInt(day.dataset.day,10)); return; }
      if(e.target.closest('[data-close]')) { cal.style.display='none'; return; }
      if(e.target.closest('[data-today]')) { const n=new Date(); const j=g2j(n.getFullYear(),n.getMonth()+1,n.getDate()); viewJ={jy:j[0],jm:j[1],jd:j[2],hh:n.getHours(),ii:n.getMinutes()}; draw(); return; }
    });
  }


  function decodeB64Json(b64) {
    try {
      const bin = atob(String(b64 || ''));
      const bytes = Uint8Array.from(bin, c => c.charCodeAt(0));
      const txt = window.TextDecoder ? new TextDecoder('utf-8').decode(bytes) : decodeURIComponent(escape(bin));
      return JSON.parse(txt);
    } catch (e) { return null; }
  }

  function hubModal() {
    return qs('#cptt-hub-modal');
  }

  function closeHubModal() {
    const modal = hubModal();
    if (!modal) return;
    modal.hidden = true;
  }

  function openHubModal(title, metaHtml, bodyHtml) {
    const modal = hubModal();
    if (!modal) return;
    const titleEl = qs('#cptt-hub-modal-title', modal);
    const metaEl = qs('#cptt-hub-modal-meta', modal);
    const bodyEl = qs('#cptt-hub-modal-body', modal);
    if (titleEl) titleEl.textContent = title || '';
    if (metaEl) metaEl.innerHTML = metaHtml || '';
    if (bodyEl) bodyEl.innerHTML = bodyHtml || '';
    modal.hidden = false;
  }

  function renderHubProject(project) {
    if (!project) return;
    const chips = [];
    if (project.start_fa) chips.push('<span class="cptt-hubMetaChip">تاریخ شروع: ' + escapeHtml(project.start_fa) + '</span>');
    if (project.last_update) chips.push('<span class="cptt-hubMetaChip">آخرین بروزرسانی: ' + escapeHtml(project.last_update) + '</span>');
    if (project.deadline) chips.push('<span class="cptt-hubMetaChip">مهلت: ' + escapeHtml(project.deadline) + '</span>');
    if (Array.isArray(project.experts) && project.experts.length) chips.push('<span class="cptt-hubMetaChip">کارشناسان: ' + escapeHtml(project.experts.join('، ')) + '</span>');
    if (project.full_details && project.customer) chips.push('<span class="cptt-hubMetaChip">مشتری: ' + escapeHtml(project.customer) + '</span>');

    let body = '<div class="cptt-hubModal__stats">'
      + '<div><strong>' + escapeHtml(String((project.progress && project.progress.percent) || 0)) + '%</strong><span>پیشرفت پروژه</span></div>'
      + '<div><strong>' + escapeHtml(String((project.progress && project.progress.done) || 0)) + '/' + escapeHtml(String((project.progress && project.progress.total) || 0)) + '</strong><span>مراحل تکمیل‌شده</span></div>'
      + '<div><strong>' + escapeHtml(String(project.checklist_done || 0)) + '/' + escapeHtml(String(project.checklist_total || 0)) + '</strong><span>چک‌لیست</span></div>'
      + '<div><strong>' + escapeHtml(String(project.user_tasks_done || 0)) + '/' + escapeHtml(String(project.user_tasks_total || 0)) + '</strong><span>تسک مشتری</span></div>'
      + '</div>';

    if (project.full_details) {
      body += '<div class="cptt-hubModal__detailsGrid">'
        + '<div><span>وضعیت پروژه</span><strong>' + escapeHtml((project.progress && project.progress.label) || '—') + '</strong></div>'
        + '<div><span>تعداد مراحل</span><strong>' + escapeHtml(String((project.progress && project.progress.total) || 0)) + '</strong></div>'
        + '<div><span>محصول</span><strong>' + escapeHtml(project.product || '—') + '</strong></div>'
        + '<div><span>دسته‌بندی</span><strong>' + escapeHtml(Array.isArray(project.categories) && project.categories.length ? project.categories.join('، ') : '—') + '</strong></div>'
        + '<div><span>وضعیت مالی</span><strong>' + escapeHtml(project.settled ? 'تسویه شده' : 'تسویه نشده') + '</strong></div>'
        + '<div><span>هزینه کل</span><strong>' + escapeHtml(String((project.financial && project.financial.cost) || 0)) + '</strong></div>'
        + '<div><span>دریافتی</span><strong>' + escapeHtml(String((project.financial && project.financial.paid) || 0)) + '</strong></div>'
        + '<div><span>مانده</span><strong>' + escapeHtml(String((project.financial && project.financial.remain) || 0)) + '</strong></div>'
        + '</div>';
    }

    if (Array.isArray(project.steps) && project.steps.length) {
      body += '<div class="cptt-hubSteps">';
      project.steps.forEach(function (step) {
        if (!step) return;
        body += '<section class="cptt-hubStep cptt-hubStep--' + escapeHtml(step.status || 'todo') + '">';
        body += '<div class="cptt-hubStep__head"><strong>' + escapeHtml((step.index || '') + '. ' + (step.title || '')) + '</strong><span class="cptt-step__badge cptt-step__badge--' + escapeHtml(step.status || 'todo') + '">' + escapeHtml(step.label || '') + '</span></div>';
        if (step.due_fa) body += '<div class="cptt-hubStep__meta">مهلت مرحله: ' + escapeHtml(step.due_fa) + '</div>';
        if (step.desc) body += '<div class="cptt-hubStep__desc">' + escapeHtml(step.desc) + '</div>';
        body += '<div class="cptt-hubStep__summary">'
          + '<span>چک‌لیست: ' + escapeHtml(String(step.checklist_done || 0)) + '/' + escapeHtml(String(step.checklist_total || 0)) + '</span>'
          + '<span>تسک مشتری: ' + escapeHtml(String(step.user_tasks_done || 0)) + '/' + escapeHtml(String(step.user_tasks_total || 0)) + '</span>'
          + '</div>';
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
    } else {
      body += '<div class="cptt-empty">برای این پروژه مرحله‌ای ثبت نشده است.</div>';
    }

    openHubModal(project.title || '', chips.join(''), body);
  }

  function renderHubExpert(expert) {
    if (!expert) return;
    const chips = [];
    if (expert.title) chips.push('<span class="cptt-hubMetaChip">' + escapeHtml(expert.title) + '</span>');
    chips.push('<span class="cptt-hubMetaChip">پروژه فعال: ' + escapeHtml(String(expert.active_projects || 0)) + '</span>');
    chips.push('<span class="cptt-hubMetaChip">پروژه تکمیل‌شده: ' + escapeHtml(String(expert.completed_projects || 0)) + '</span>');
    let body = '<div class="cptt-expertModalProfile">';
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
    qsa('.cptt-publicProject__open').forEach(btn => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        renderHubProject(decodeB64Json(btn.getAttribute('data-project')));
      });
    });
    qsa('.cptt-expertBadge').forEach(btn => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        renderHubExpert(decodeB64Json(btn.getAttribute('data-expert')));
      });
    });
    const modal = hubModal();
    if (!modal || modal.dataset.bound) return;
    modal.dataset.bound = '1';
    const closeBtn = qs('.cptt-hubModal__close', modal);
    const backdrop = qs('.cptt-hubModal__backdrop', modal);
    if (closeBtn) closeBtn.addEventListener('click', closeHubModal);
    if (backdrop) backdrop.addEventListener('click', closeHubModal);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeHubModal();
    });
  }

  function updateHubVisibility() {
    const search = ((qs('#cptt-hub-search') || {}).value || '').toLowerCase().trim();
    const expert = ((qs('#cptt-hub-expert') || {}).value || '');
    const product = ((qs('#cptt-hub-product') || {}).value || '');
    const cat = ((qs('#cptt-hub-cat') || {}).value || '');
    const deadline = ((qs('#cptt-hub-deadline') || {}).value || '');
    let visible = 0;
    qsa('.cptt-publicProject').forEach(card => {
      let ok = true;
      const dataSearch = String(card.getAttribute('data-search') || '').toLowerCase();
      const dataExperts = String(card.getAttribute('data-experts') || '');
      const dataProduct = String(card.getAttribute('data-product') || '');
      const dataCats = String(card.getAttribute('data-cats') || '');
      const dataDeadline = String(card.getAttribute('data-deadline') || '');
      if (search && dataSearch.indexOf(search) === -1) ok = false;
      if (expert && dataExperts.indexOf(',' + expert + ',') === -1) ok = false;
      if (product && dataProduct !== product) ok = false;
      if (cat && dataCats.indexOf(',' + cat + ',') === -1) ok = false;
      if (deadline && dataDeadline !== deadline) ok = false;
      card.hidden = !ok;
      card.style.display = ok ? '' : 'none';
      if (ok) visible++;
    });
    const count = qs('#cptt-hub-count');
    if (count) count.textContent = String(visible);
    const empty = qs('#cptt-hub-empty');
    if (empty) empty.hidden = visible !== 0;
  }

  function bindHubFilters() {
    const fields = ['#cptt-hub-search', '#cptt-hub-expert', '#cptt-hub-product', '#cptt-hub-cat', '#cptt-hub-deadline'];
    fields.forEach(sel => {
      const el = qs(sel);
      if (!el) return;
      el.addEventListener('input', updateHubVisibility);
      el.addEventListener('change', updateHubVisibility);
    });
    const reset = qs('#cptt-hub-reset');
    if (reset && !reset.dataset.bound) {
      reset.dataset.bound = '1';
      reset.addEventListener('click', function () {
        fields.forEach(sel => {
          const el = qs(sel);
          if (!el) return;
          if (el.tagName === 'SELECT') el.value = '';
          else el.value = '';
        });
        updateHubVisibility();
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    const search = qs('#cptt-expert-search');
    const status = qs('#cptt-expert-status');
    const settled = qs('#cptt-expert-settled');
    const client = qs('#cptt-expert-client');
    const product = qs('#cptt-expert-product');
    const cat = qs('#cptt-expert-cat');
    [search, status, settled, client, product, cat].forEach(el => {
      if (!el) return;
      el.addEventListener('input', updateVisibility);
      el.addEventListener('change', updateVisibility);
    });
    const reset = qs('#cptt-expert-reset');
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
    bindHubModals();
    bindHubFilters();
    initJalaliPicker();
    updateVisibility();
    updateHubVisibility();
  });
})();
