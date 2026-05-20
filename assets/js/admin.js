jQuery(function ($) {
  const $rows = $('#cptt-steps-rows');
  const stepTpl = $('#cptt-step-template').html() || '';
  const checkItemTpl = $('#cptt-checkitem-template').html() || '';
  const userTaskTpl = $('#cptt-usertask-template').html() || '';

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'cptt_' + Math.random().toString(16).slice(2) + '_' + Date.now();
  }

  function reindexAll() {
    $rows.find('.cptt-step-row').each(function (i) {
      // reindex step fields
      $(this).find('input, select, textarea').each(function () {
        const $el = $(this);
        const name = $el.attr('name');
        if (!name) return;
        $el.attr('name', name.replace(/cptt_steps\[\d+\]/, 'cptt_steps[' + i + ']'));
      });

      // update checklist container attribute
      $(this).find('.cptt-checkitems').attr('data-step-index', i);

      // reindex checklist items
      $(this).find('.cptt-checkitem-row').each(function (j) {
        $(this).find('input').each(function () {
          const $el = $(this);
          const name = $el.attr('name');
          if (!name) return;
          // cptt_steps[i][checklist][j]
          $el.attr('name', name
            .replace(/cptt_steps\[\d+\]/, 'cptt_steps[' + i + ']')
            .replace(/\[checklist\]\[\d+\]/, '[checklist][' + j + ']')
          );
        });
      });

      // reindex customer-side tasks
      $(this).find('.cptt-usertasks').attr('data-step-index', i);
      $(this).find('.cptt-usertask-row').each(function (k) {
        $(this).find('input, textarea, select').each(function () {
          const $el = $(this);
          const name = $el.attr('name');
          if (!name) return;
          $el.attr('name', name
            .replace(/cptt_steps\[\d+\]/, 'cptt_steps[' + i + ']')
            .replace(/\[user_tasks\]\[\d+\]/, '[user_tasks][' + k + ']')
          );
        });
      });
    });
  }

  // add step
  $('#cptt-add-step').on('click', function () {
    const i = $rows.find('.cptt-step-row').length;
    const id = uuid();
    const html = stepTpl.replaceAll('{{i}}', i).replaceAll('{{uuid}}', id);
    $rows.append(html);
    reindexAll();
  });

  // remove step
  $rows.on('click', '.cptt-remove-step', function () {
    $(this).closest('.cptt-step-row').remove();
    reindexAll();
  });

  // sortable steps
  $rows.sortable({
    items: '.cptt-step-row',
    handle: '.cptt-drag-handle',
    axis: 'y',
    tolerance: 'pointer',
    update: function () { reindexAll(); }
  });

  // toggle checklist
  $rows.on('click', '.cptt-toggle-checklist', function () {
    const $wrap = $(this).closest('.cptt-step-row').find('.cptt-checklist-wrap').first();
    $wrap.toggle();
  });

  // add checklist item
  $rows.on('click', '.cptt-add-checkitem', function () {
    const $step = $(this).closest('.cptt-step-row');
    const i = $step.index(); // temporary; will reindex after append
    const $list = $step.find('.cptt-checkitems').first();
    const j = $list.find('.cptt-checkitem-row').length;
    const cid = uuid();

    const html = checkItemTpl
      .replaceAll('{{i}}', i)
      .replaceAll('{{j}}', j)
      .replaceAll('{{cid}}', cid);

    $list.append(html);

    // make checklist sortable inside this step
    if (!$list.data('sortable')) {
      $list.sortable({
        items: '.cptt-checkitem-row',
        handle: '.cptt-checkitem-handle',
        axis: 'y',
        update: function () { reindexAll(); }
      });
      $list.data('sortable', true);
    }

    reindexAll();
  });

  // remove checklist item
  $rows.on('click', '.cptt-remove-checkitem', function () {
    $(this).closest('.cptt-checkitem-row').remove();
    reindexAll();
  });

  // init sortable for existing checklists
  $rows.find('.cptt-checkitems').each(function () {
    const $list = $(this);
    $list.sortable({
      items: '.cptt-checkitem-row',
      handle: '.cptt-checkitem-handle',
      axis: 'y',
      update: function () { reindexAll(); }
    });
    $list.data('sortable', true);
  });

  // Apply Steps Template (project only)
  $('#cptt_apply_template_btn').on('click', function () {
    const tplId = $('#cptt_template_select').val();
    if (!tplId) return;

    if (!confirm('اعمال تمپلیت، مراحل فعلی را جایگزین می‌کند. ادامه می‌دهید؟')) return;

    $.get(CPTT_ADMIN.ajax, {
      action: 'cptt_get_template_steps',
      nonce: CPTT_ADMIN.nonce,
      template_id: tplId
    }).done(function (res) {
      if (!res || !res.success) return alert('خطا در دریافت تمپلیت');

      const steps = res.data || [];
      $rows.empty();

      steps.forEach(function (s, idx) {
        const id = s.id || uuid();
        const html = stepTpl.replaceAll('{{i}}', idx).replaceAll('{{uuid}}', id);
        const $row = $(html);

        $row.find('input[name^="cptt_steps[' + idx + '][title]"]').val(s.title || '');
        $row.find('select[name^="cptt_steps[' + idx + '][status]"]').val(s.status || 'todo');
        $row.find('textarea[name^="cptt_steps[' + idx + '][desc]"]').val(s.desc || '');
        $row.find('input[name^="cptt_steps[' + idx + '][due_at_local]"]').val(s.due_at_local || '');

        // checklist items
        const cl = Array.isArray(s.checklist) ? s.checklist : [];
        const $list = $row.find('.cptt-checkitems').first();
        cl.forEach(function (it, j) {
          const cid = it.id || uuid();
          const itemHtml = checkItemTpl
            .replaceAll('{{i}}', idx)
            .replaceAll('{{j}}', j)
            .replaceAll('{{cid}}', cid);
          const $itRow = $(itemHtml);
          $itRow.find('input[type="text"]').val(it.text || '');
          $itRow.find('input[type="url"]').val(it.url || '');
          $itRow.find('input[type="checkbox"]').prop('checked', !!it.done);
          $list.append($itRow);
        });

        // customer-side tasks
        const uts = Array.isArray(s.user_tasks) ? s.user_tasks : [];
        const $utList = $row.find('.cptt-usertasks').first();
        uts.forEach(function (ut, k) {
          const tid = ut.id || uuid();
          const taskHtml = userTaskTpl
            .replaceAll('{{i}}', idx)
            .replaceAll('{{k}}', k)
            .replaceAll('{{tid}}', tid);
          const $taskRow = $(taskHtml);
          $taskRow.find('input[type="text"]').val(ut.title || '');
          $taskRow.find('textarea').val(ut.desc || '');
          $taskRow.find('input[name$="[due_at_local]"]').val(ut.due_at_local || '');
          $taskRow.find('input[type="checkbox"]').prop('checked', ut.sms_remind !== 0);
          $utList.append($taskRow);
        });

        $rows.append($row);
      });

      // re-init sortables
      reindexAll();
      $rows.sortable('refresh');
      $rows.find('.cptt-checkitems').each(function () {
        const $list = $(this);
        if ($list.data('sortable')) return;
        $list.sortable({
          items: '.cptt-checkitem-row',
          handle: '.cptt-checkitem-handle',
          axis: 'y',
          update: function () { reindexAll(); }
        });
        $list.data('sortable', true);
      });
    });
  });

  // Apply Checklist Template inside a step
  $rows.on('click', '.cptt-apply-checktpl', function () {
    const $step = $(this).closest('.cptt-step-row');
    const tplId = $step.find('.cptt-checktpl-select').val();
    if (!tplId) return;

    $.get(CPTT_ADMIN.ajax, {
      action: 'cptt_get_checklist_tpl',
      nonce: CPTT_ADMIN.nonce,
      checktpl_id: tplId
    }).done(function (res) {
      if (!res || !res.success) return alert('خطا در دریافت تمپلیت چک‌لیست');

      const items = res.data || [];
      const $list = $step.find('.cptt-checkitems').first();
      $list.empty();

      // add rows
      items.forEach(function (text, j) {
        const idx = $step.index();
        const cid = uuid();
        const html = checkItemTpl
          .replaceAll('{{i}}', idx)
          .replaceAll('{{j}}', j)
          .replaceAll('{{cid}}', cid);
        const $itRow = $(html);
        $itRow.find('input[type="text"]').val(text || '');
        $itRow.find('input[type="checkbox"]').prop('checked', false);
        $list.append($itRow);
      });

      reindexAll();
    });
  });

  // add customer-side task
  $rows.on('click', '.cptt-add-usertask', function () {
    if (!userTaskTpl) return;
    const $step = $(this).closest('.cptt-step-row');
    const i = $step.index();
    const $list = $step.find('.cptt-usertasks').first();
    const k = $list.find('.cptt-usertask-row').length;
    const tid = uuid();
    const html = userTaskTpl
      .replaceAll('{{i}}', i)
      .replaceAll('{{k}}', k)
      .replaceAll('{{tid}}', tid);
    $list.append(html);
    reindexAll();
  });

  // remove customer-side task
  $rows.on('click', '.cptt-remove-usertask', function () {
    $(this).closest('.cptt-usertask-row').remove();
    reindexAll();
  });

  // Checklist template editor page
  $('#cptt-add-checktpl-row').on('click', function () {
    const $c = $('#cptt-checktpl-rows');
    const tpl = $('#cptt-checktpl-row-template').html() || '';
    const i = $c.find('.cptt-checktpl-row').length;
    $c.append(tpl.replaceAll('{{i}}', i));
  });

  $(document).on('click', '.cptt-remove-checktpl-row', function () {
    $(this).closest('.cptt-checktpl-row').remove();
  });

  $(document).on('change', '.cptt-expertOpt input[type="checkbox"]', function(){
    $(this).closest('.cptt-expertOpt').toggleClass('is-checked', this.checked);
  });

  /* ===== Jalali datetime picker (manual typing + calendar) ===== */
  function cpttToEn(str){
    const fa='۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', en='01234567890123456789';
    return String(str||'').replace(/[۰-۹٠-٩]/g, ch => en[fa.indexOf(ch)] || ch);
  }
  function cpttToFa(str){ return String(str||'').replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]); }
  function g2j(gy, gm, gd){
    const gdm=[0,31,59,90,120,151,181,212,243,273,304,334];
    const gy2=(gm>2)?gy+1:gy;
    let days=355666+(365*gy)+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)+gd+gdm[gm-1];
    let jy=-1595+33*Math.floor(days/12053); days%=12053; jy+=4*Math.floor(days/1461); days%=1461;
    if(days>365){ jy+=Math.floor((days-1)/365); days=(days-1)%365; }
    let jm,jd;
    if(days<186){ jm=1+Math.floor(days/31); jd=1+(days%31); }
    else { jm=7+Math.floor((days-186)/30); jd=1+((days-186)%30); }
    return [jy,jm,jd];
  }
  function j2g(jy,jm,jd){
    jy = parseInt(jy,10) + 1595;
    let days = -355668 + (365*jy) + Math.floor(jy/33)*8 + Math.floor(((jy%33)+3)/4) + parseInt(jd,10);
    days += (jm < 7) ? ((jm-1)*31) : (((jm-7)*30)+186);
    let gy = 400*Math.floor(days/146097); days%=146097;
    if(days>36524){ gy += 100*Math.floor(--days/36524); days%=36524; if(days>=365) days++; }
    gy += 4*Math.floor(days/1461); days%=1461;
    if(days>365){ gy += Math.floor((days-1)/365); days=(days-1)%365; }
    let gd=days+1;
    const sal=[0,31,(((gy%4===0)&&(gy%100!==0))||(gy%400===0))?29:28,31,30,31,30,31,31,30,31,30,31];
    let gm=1; for(;gm<=12;gm++){ if(gd<=sal[gm]) break; gd-=sal[gm]; }
    return [gy,gm,gd];
  }
  function parseJalaliVal(v){
    v = cpttToEn(v).trim();
    const m = v.match(/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})(?:\s+(\d{1,2}):(\d{1,2}))?/);
    if(!m) return null;
    return {jy:+m[1], jm:+m[2], jd:+m[3], hh:m[4]?+m[4]:12, ii:m[5]?+m[5]:0};
  }
  function monthLen(jy,jm){ return jm<=6?31:(jm<=11?30:30); }
  const monthNames=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  let activeDateInput=null, viewJ=null;
  const $cal = $('<div class="cptt-jdp" dir="rtl" style="display:none"></div>').appendTo('body');

  function drawCal(){
    if(!activeDateInput || !viewJ) return;
    const ml=monthLen(viewJ.jy, viewJ.jm);
    const g=j2g(viewJ.jy, viewJ.jm, 1);
    const first=new Date(g[0], g[1]-1, g[2]).getDay(); // 0 Sun ... 6 Sat
    const start=(first+1)%7; // Sat=0
    let html='<div class="cptt-jdp__head"><button type="button" data-nav="prev">‹</button><strong>'+monthNames[viewJ.jm-1]+' '+cpttToFa(viewJ.jy)+'</strong><button type="button" data-nav="next">›</button></div>';
    html+='<div class="cptt-jdp__week"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div><div class="cptt-jdp__days">';
    for(let i=0;i<start;i++) html+='<span></span>';
    for(let d=1; d<=ml; d++) html+='<button type="button" data-day="'+d+'">'+cpttToFa(d)+'</button>';
    html+='</div><div class="cptt-jdp__time"><input type="number" min="0" max="23" value="'+String(viewJ.hh||12).padStart(2,'0')+'"><span>:</span><input type="number" min="0" max="59" value="'+String(viewJ.ii||0).padStart(2,'0')+'"></div><div class="cptt-jdp__foot"><button type="button" data-today="1">امروز</button><button type="button" data-close="1">بستن</button></div>';
    $cal.html(html);
  }
  function openCal(input){
    activeDateInput=input;
    const parsed=parseJalaliVal($(input).val());
    if(parsed) viewJ=parsed; else { const n=new Date(); const j=g2j(n.getFullYear(), n.getMonth()+1, n.getDate()); viewJ={jy:j[0],jm:j[1],jd:j[2],hh:n.getHours(),ii:n.getMinutes()}; }
    drawCal();
    const off=$(input).offset();
    $cal.css({top:off.top+$(input).outerHeight()+6,left:off.left,display:'block'});
  }
  function setInputDate(day){
    const h=Math.max(0,Math.min(23,parseInt($cal.find('.cptt-jdp__time input').eq(0).val()||'0',10)));
    const m=Math.max(0,Math.min(59,parseInt($cal.find('.cptt-jdp__time input').eq(1).val()||'0',10)));
    const val=viewJ.jy+'/'+String(viewJ.jm).padStart(2,'0')+'/'+String(day).padStart(2,'0')+' '+String(h).padStart(2,'0')+':'+String(m).padStart(2,'0');
    $(activeDateInput).val(cpttToFa(val)).trigger('change');
    $cal.hide();
  }
  $(document).on('focus click', '.cptt-jalali-datetime', function(){ openCal(this); });
  $cal.on('click','[data-nav]',function(){ const dir=$(this).data('nav'); viewJ.jm += dir==='next'?1:-1; if(viewJ.jm>12){viewJ.jm=1;viewJ.jy++;} if(viewJ.jm<1){viewJ.jm=12;viewJ.jy--;} drawCal(); });
  $cal.on('click','[data-day]',function(){ setInputDate(parseInt($(this).data('day'),10)); });
  $cal.on('click','[data-today]',function(){ const n=new Date(); const j=g2j(n.getFullYear(),n.getMonth()+1,n.getDate()); viewJ={jy:j[0],jm:j[1],jd:j[2],hh:n.getHours(),ii:n.getMinutes()}; drawCal(); });
  $cal.on('click','[data-close]',function(){ $cal.hide(); });
  $(document).on('mousedown', function(e){ if(!$(e.target).closest('.cptt-jdp,.cptt-jalali-datetime').length) $cal.hide(); });

  /* ===== Projects dashboard filters (no reload) ===== */
  function filterDashboard(){
    const q=($('#cptt-dash-search').val()||'').toLowerCase().trim();
    const expert=$('#cptt-dash-expert').val()||'';
    const status=$('#cptt-dash-status').val()||'';
    const client=$('#cptt-dash-client').val()||'';
    const settled=$('#cptt-dash-settled').val();
    const cat=$('#cptt-dash-cat').val()||'';
    const product=$('#cptt-dash-product').val()||'';
    let count=0;
    $('.cptt-project-card').each(function(){
      const $c=$(this);
      let ok=true;
      if(q && ($c.data('search')||'').indexOf(q)===-1) ok=false;
      if(expert && String($c.attr('data-experts')||'').indexOf(','+expert+',')===-1) ok=false;
      if(status && $c.attr('data-status')!==status) ok=false;
      if(client && $c.attr('data-client')!==client) ok=false;
      if(settled!=='' && settled!=null && $c.attr('data-settled')!==settled) ok=false;
      if(cat && String($c.attr('data-cats')||'').indexOf(','+cat+',')===-1) ok=false;
      if(product && $c.attr('data-product')!==product) ok=false;
      $c.toggle(ok);
      if(ok) count++;
    });
    $('#cptt-dash-count').text(count);
    $('#cptt-dash-empty').toggle(count===0);
  }
  $(document).on('input change', '#cptt-dash-search,#cptt-dash-expert,#cptt-dash-status,#cptt-dash-client,#cptt-dash-settled,#cptt-dash-cat,#cptt-dash-product', filterDashboard);
  $(document).on('click', '#cptt-dash-reset', function(){ $('.cptt-dashboard__filters input,.cptt-dashboard__filters select').val(''); filterDashboard(); });

});