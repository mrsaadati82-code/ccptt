/**
 * CPTT Expert Panels v2.0 — بازنویسی کامل
 * File Manager + Client Requests
 * Guaranteed: CPTT_PANELS always defined via inline script
 */
(function(){
'use strict';

/* ─── wait for jQuery ─── */
function ready(fn){
  if(typeof jQuery !== 'undefined'){
    jQuery(fn);
  } else {
    document.addEventListener('DOMContentLoaded', function(){
      if(typeof jQuery !== 'undefined') jQuery(fn);
      else fn();
    });
  }
}

/* ─── CPTT_PANELS check ─── */
if(typeof window.CPTT_PANELS === 'undefined'){
  console.warn('CPTT_PANELS not defined - expert panels disabled');
  return;
}

var P = window.CPTT_PANELS;

/* ─── utils ─── */
function esc(s){
  return String(s||'').replace(/[&<>"']/g,function(c){
    return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
  });
}
function fileSizeStr(b){
  b=parseInt(b)||0;
  if(b<1024) return b+' B';
  if(b<1048576) return (b/1024).toFixed(1)+' KB';
  return (b/1048576).toFixed(1)+' MB';
}
function ajax(action, data, cb){
  var fd = new FormData();
  fd.append('action', action);
  fd.append('nonce', P.nonce);
  for(var k in data) if(data.hasOwnProperty(k)) fd.append(k, data[k]);
  var xhr = new XMLHttpRequest();
  xhr.open('POST', P.ajax);
  xhr.onload = function(){
    var r;
    try{ r = JSON.parse(xhr.responseText); }catch(e){ r = null; }
    cb(r);
  };
  xhr.onerror = function(){ cb(null); };
  xhr.send(fd);
}

/* ═══════════════════════════════════════════════════
   MODAL — تضمین شده که روی صفحه قرار می‌گیره
═══════════════════════════════════════════════════ */
function Modal(id, title, bodyHTML){
  // حذف قبلی
  var old = document.getElementById(id);
  if(old) old.parentNode.removeChild(old);

  var wrap = document.createElement('div');
  wrap.id = id;

  // inline style مطمئن
  var wrapStyle = [
    'position:fixed','top:0','left:0','right:0','bottom:0',
    'z-index:2147483647','display:flex','align-items:center',
    'justify-content:center','padding:16px','box-sizing:border-box',
    'font-family:inherit'
  ].join(';');
  wrap.setAttribute('style', wrapStyle);

  var backdropStyle = [
    'position:absolute','inset:0',
    'background:rgba(0,0,0,0.55)',
    'backdrop-filter:blur(4px)'
  ].join(';');

  var dialogStyle = [
    'position:relative','z-index:1',
    'width:100%','max-width:860px','max-height:90vh',
    'display:flex','flex-direction:column',
    'border-radius:20px','overflow:hidden',
    'box-shadow:0 20px 60px rgba(0,0,0,0.35)',
    'background:var(--th-card-bg,#fff)',
    'animation:cptt-panel-in 0.2s ease'
  ].join(';');

  var headStyle = [
    'display:flex','align-items:center','justify-content:space-between',
    'padding:14px 20px','flex-shrink:0',
    'background:linear-gradient(135deg,#6366f1,#4f46e5)',
    'color:#fff'
  ].join(';');

  var bodyStyle = [
    'flex:1','overflow-y:auto','overflow-x:hidden',
    'padding:18px 20px',
    'background:var(--th-card-bg,#fff)',
    'color:var(--th-text,#0f172a)'
  ].join(';');

  wrap.innerHTML =
    '<div style="'+backdropStyle+'" class="cptt-modal-backdrop"></div>'+
    '<div style="'+dialogStyle+'" class="cptt-modal-dialog">'+
      '<div style="'+headStyle+'" class="cptt-modal-head">'+
        '<span style="font-size:15px;font-weight:900;">'+esc(title)+'</span>'+
        '<button type="button" class="cptt-modal-close" style="'+
          'width:30px;height:30px;border-radius:50%;border:1.5px solid rgba(255,255,255,.4);'+
          'background:rgba(255,255,255,.15);color:#fff;font-size:20px;line-height:1;'+
          'cursor:pointer;display:flex;align-items:center;justify-content:center;'+
          'transition:background .15s;" aria-label="بستن">×</button>'+
      '</div>'+
      '<div style="'+bodyStyle+'" class="cptt-modal-body">'+bodyHTML+'</div>'+
    '</div>';

  document.body.appendChild(wrap);

  function close(){
    if(wrap.parentNode) wrap.parentNode.removeChild(wrap);
    document.removeEventListener('keydown', onKey);
  }
  function onKey(e){ if(e.key==='Escape') close(); }

  wrap.querySelector('.cptt-modal-close').addEventListener('click', close);
  wrap.querySelector('.cptt-modal-backdrop').addEventListener('click', close);
  document.addEventListener('keydown', onKey);

  return { el: wrap, close: close };
}

/* ═══════════════════════════════════════════════════
   FILE MANAGER
═══════════════════════════════════════════════════ */
var FILE_ICONS = {
  jpg:'🖼️', jpeg:'🖼️', png:'🖼️', gif:'🖼️', webp:'🖼️', svg:'🖼️',
  pdf:'📄', doc:'📃', docx:'📃', xls:'📊', xlsx:'📊', ppt:'📑', pptx:'📑', txt:'📝',
  mp4:'🎬', avi:'🎬', mov:'🎬', mp3:'🎵', wav:'🎵', ogg:'🎵',
  zip:'📦', rar:'📦', '7z':'📦'
};
function fileIcon(ext){ return FILE_ICONS[ext] || '📎'; }

function renderFileCard(f, cats, isFirst){
  var catOpts = (cats||[]).map(function(ct){
    return '<option value="'+esc(ct)+'"'+(ct===f.category?' selected':'')+'>'+esc(ct)+'</option>';
  }).join('');

  var cardStyle = [
    'border-radius:14px','overflow:hidden','border:1.5px solid',
    'border-color:var(--th-card-border,rgba(100,116,139,.15))',
    'background:var(--th-card-bg,#fff)',
    'box-shadow:0 2px 8px rgba(0,0,0,.06)',
    'transition:box-shadow .18s,transform .15s'
  ].join(';');

  var topStyle = [
    'display:flex','align-items:flex-start','gap:10px','padding:12px'
  ].join(';');

  var thumb = f.is_image && f.thumb
    ? '<div style="width:52px;height:52px;border-radius:10px;overflow:hidden;flex-shrink:0;"><img src="'+esc(f.thumb)+'" style="width:100%;height:100%;object-fit:cover;" alt=""></div>'
    : '<div style="width:52px;height:52px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:26px;background:rgba(99,102,241,.07);">'+fileIcon(f.ext)+'</div>';

  var actStyle = [
    'display:flex','align-items:center','gap:6px','flex-wrap:wrap',
    'padding:8px 12px','border-top:1px solid rgba(99,102,241,.1)',
    'background:rgba(99,102,241,.03)'
  ].join(';');

  var btnStyle = 'display:inline-flex;align-items:center;gap:4px;padding:5px 11px;border-radius:8px;font-size:12px;font-weight:700;border:1.5px solid rgba(100,116,139,.2);background:rgba(255,255,255,.9);color:inherit;cursor:pointer;font-family:inherit;white-space:nowrap;';
  var delBtnStyle = 'display:inline-flex;align-items:center;gap:4px;padding:5px 11px;border-radius:8px;font-size:12px;font-weight:700;border:1.5px solid rgba(239,68,68,.25);background:rgba(239,68,68,.06);color:#b91c1c;cursor:pointer;font-family:inherit;';

  var selStyle = 'padding:4px 8px;border-radius:8px;border:1.5px solid rgba(100,116,139,.2);background:rgba(255,255,255,.9);font-size:12px;font-family:inherit;color:inherit;';

  return '<div class="cptt-fm-card" data-fid="'+f.id+'" style="'+cardStyle+'">'+
    '<div style="'+topStyle+'">'+
      thumb+
      '<div style="flex:1;min-width:0;">'+
        '<div style="font-size:13px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:5px;" title="'+esc(f.file_name)+'">'+esc(f.file_name)+'</div>'+
        '<div style="display:flex;flex-wrap:wrap;gap:6px;font-size:11.5px;color:var(--th-muted,#64748b);">'+
          '<span style="padding:2px 8px;border-radius:99px;background:rgba(99,102,241,.1);color:#4338ca;font-weight:800;">'+esc(f.category)+'</span>'+
          '<span>'+esc(f.file_size_str||fileSizeStr(f.file_size))+'</span>'+
          '<span title="'+esc(f.uploaded_at)+'">'+esc(f.uploaded_at_fa||f.uploaded_at)+'</span>'+
          '<span>👤 '+esc(f.uploader_name)+'</span>'+
        '</div>'+
        (f.note ? '<div style="font-size:11.5px;font-style:italic;color:var(--th-muted,#64748b);margin-top:4px;">'+esc(f.note)+'</div>' : '')+
      '</div>'+
    '</div>'+
    '<div style="'+actStyle+'">'+
      '<a href="'+esc(f.file_url)+'" target="_blank" style="'+btnStyle+'" download>⬇ دانلود</a>'+
      (f.is_image ? '<button class="fm-preview" data-url="'+esc(f.file_url)+'" style="'+btnStyle+'">👁 پیش‌نمایش</button>' : '')+
      '<select class="fm-cat" data-fid="'+f.id+'" style="'+selStyle+'">'+catOpts+'</select>'+
      '<button class="fm-rename" data-fid="'+f.id+'" data-name="'+esc(f.file_name)+'" style="'+btnStyle+'">✏️ نام</button>'+
      (f.can_delete ? '<button class="fm-del" data-fid="'+f.id+'" style="'+delBtnStyle+'">🗑 حذف</button>' : '')+
    '</div>'+
  '</div>';
}

function openFileManager(pid){
  var cats = P.fm_cats||[];
  var catOpts = cats.map(function(c){ return '<option>'+esc(c)+'</option>'; }).join('');
  var maxMb = (P.fm_settings&&P.fm_settings.max_file_mb)||20;

  var bodyHTML =
    '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:10px;border-radius:12px;background:rgba(99,102,241,.05);border:1px solid rgba(99,102,241,.12);margin-bottom:14px;">'+
      '<input type="search" id="fm-search" placeholder="جستجوی فایل..." style="flex:1;min-width:120px;padding:7px 12px;border-radius:10px;border:1.5px solid rgba(100,116,139,.2);background:var(--th-card-bg,#fff);font:inherit;font-size:12.5px;color:inherit;">'+
      '<select id="fm-cat-filter" style="padding:7px 10px;border-radius:10px;border:1.5px solid rgba(100,116,139,.2);background:var(--th-card-bg,#fff);font:inherit;font-size:12.5px;color:inherit;">'+
        '<option value="">همه دسته‌ها</option>'+
        cats.map(function(c){ return '<option>'+esc(c)+'</option>'; }).join('')+
      '</select>'+
      '<label style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:10px;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer;">'+
        '⬆ آپلود'+
        '<input type="file" id="fm-file-input" multiple style="display:none;">'+
      '</label>'+
      '<select id="fm-upload-cat" style="padding:7px 10px;border-radius:10px;border:1.5px solid rgba(100,116,139,.2);background:var(--th-card-bg,#fff);font:inherit;font-size:12.5px;color:inherit;">'+catOpts+'</select>'+
    '</div>'+
    '<div id="fm-progress" style="display:none;height:5px;border-radius:99px;background:rgba(99,102,241,.15);margin-bottom:10px;overflow:hidden;">'+
      '<div id="fm-progress-bar" style="height:100%;border-radius:99px;background:linear-gradient(90deg,#6366f1,#4f46e5);width:0%;transition:width .2s;"></div>'+
    '</div>'+
    '<div id="fm-msg" style="min-height:10px;margin-bottom:8px;font-size:13px;font-weight:700;"></div>'+
    '<div id="fm-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;">'+
      '<div style="text-align:center;padding:40px;color:var(--th-muted,#94a3b8);">در حال بارگذاری...</div>'+
    '</div>';

  var modal = Modal('cptt-fm-'+pid, '📁 مدیریت فایل‌های پروژه', bodyHTML);
  var grid = modal.el.querySelector('#fm-grid');
  var search = modal.el.querySelector('#fm-search');
  var catFilter = modal.el.querySelector('#fm-cat-filter');
  var fileInput = modal.el.querySelector('#fm-file-input');
  var uploadCat = modal.el.querySelector('#fm-upload-cat');
  var progWrap = modal.el.querySelector('#fm-progress');
  var progBar = modal.el.querySelector('#fm-progress-bar');
  var msgEl = modal.el.querySelector('#fm-msg');

  var allFiles = [];

  function showMsg(txt, ok){
    msgEl.textContent = txt;
    msgEl.style.color = ok ? '#15803d' : '#b91c1c';
    msgEl.style.background = ok ? 'rgba(34,197,94,.08)' : 'rgba(239,68,68,.08)';
    msgEl.style.padding = '6px 12px';
    msgEl.style.borderRadius = '8px';
    setTimeout(function(){ msgEl.textContent=''; msgEl.style.cssText='min-height:10px;margin-bottom:8px;font-size:13px;font-weight:700;'; },3000);
  }

  function render(){
    var q = (search.value||'').toLowerCase();
    var cat = catFilter.value;
    var vis = allFiles.filter(function(f){
      return (!q || f.file_name.toLowerCase().indexOf(q)>-1) && (!cat || f.category===cat);
    });
    if(!vis.length){
      grid.innerHTML = '<div style="text-align:center;padding:40px;color:var(--th-muted,#94a3b8);">'+(allFiles.length ? 'فیلتری یافت نشد.' : 'فایلی آپلود نشده.')+'</div>';
      return;
    }
    grid.innerHTML = vis.map(function(f){ return renderFileCard(f, cats); }).join('');
    bindGridEvents();
  }

  function bindGridEvents(){
    grid.querySelectorAll('.fm-preview').forEach(function(btn){
      btn.onclick = function(){ openImagePreview(btn.dataset.url); };
    });
    grid.querySelectorAll('.fm-cat').forEach(function(sel){
      sel.onchange = function(){
        ajax('cptt_fm_set_category',{project_id:pid,file_id:sel.dataset.fid,category:sel.value},function(r){
          if(r&&r.success){ var f=allFiles.find(function(x){return String(x.id)===sel.dataset.fid;}); if(f) f.category=sel.value; showMsg('✅ دسته‌بندی تغییر کرد.',true); }
          else showMsg('❌ خطا',false);
        });
      };
    });
    grid.querySelectorAll('.fm-rename').forEach(function(btn){
      btn.onclick = function(){
        var n = prompt('نام جدید فایل:', btn.dataset.name);
        if(!n||n===btn.dataset.name) return;
        ajax('cptt_fm_rename',{project_id:pid,file_id:btn.dataset.fid,name:n},function(r){
          if(r&&r.success){ var f=allFiles.find(function(x){return String(x.id)===btn.dataset.fid;}); if(f){ f.file_name=n; render(); } showMsg('✅ نام تغییر کرد.',true); }
          else showMsg('❌ خطا',false);
        });
      };
    });
    grid.querySelectorAll('.fm-del').forEach(function(btn){
      btn.onclick = function(){
        if(!confirm('این فایل حذف شود؟')) return;
        ajax('cptt_fm_delete',{project_id:pid,file_id:btn.dataset.fid},function(r){
          if(r&&r.success){ allFiles=allFiles.filter(function(x){return String(x.id)!==btn.dataset.fid;}); render(); showMsg('✅ حذف شد.',true); }
          else showMsg('❌ خطا',false);
        });
      };
    });
  }

  // load files
  ajax('cptt_fm_list',{project_id:pid},function(r){
    if(r&&r.success){ allFiles=r.data.files||[]; render(); }
    else grid.innerHTML='<div style="text-align:center;padding:40px;color:#ef4444;">خطا در بارگذاری.</div>';
  });

  search.addEventListener('input', render);
  catFilter.addEventListener('change', render);

  // upload
  fileInput.addEventListener('change', function(){
    var cat = uploadCat.value||'عمومی';
    var files = Array.from(fileInput.files);
    if(!files.length) return;
    var i = 0;
    function uploadNext(){
      if(i>=files.length){ fileInput.value=''; progWrap.style.display='none'; return; }
      var file = files[i++];
      var fd = new FormData();
      fd.append('action','cptt_fm_upload');
      fd.append('nonce',P.nonce);
      fd.append('project_id',pid);
      fd.append('category',cat);
      fd.append('file',file);
      progWrap.style.display='block'; progBar.style.width='0%';
      var xhr = new XMLHttpRequest();
      xhr.open('POST',P.ajax);
      xhr.upload.onprogress=function(e){ if(e.lengthComputable) progBar.style.width=(e.loaded/e.total*100)+'%'; };
      xhr.onload=function(){
        var r; try{r=JSON.parse(xhr.responseText);}catch(e){}
        progWrap.style.display='none';
        if(r&&r.success){ allFiles.unshift(r.data.file); render(); showMsg('✅ '+esc(file.name)+' آپلود شد.',true); }
        else showMsg('❌ خطا: '+(r&&r.data?r.data:'نامشخص'),false);
        uploadNext();
      };
      xhr.onerror=function(){ showMsg('❌ خطای شبکه',false); uploadNext(); };
      xhr.send(fd);
    }
    uploadNext();
  });
}

function openImagePreview(url){
  var ov = document.createElement('div');
  ov.setAttribute('style','position:fixed;inset:0;z-index:2147483647;background:rgba(0,0,0,.88);display:flex;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;');
  ov.innerHTML = '<div style="position:relative;max-width:90vw;max-height:90vh;">'+
    '<button style="position:absolute;top:-14px;right:-14px;width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.3);color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;" id="prev-close">×</button>'+
    '<img src="'+esc(url)+'" style="max-width:80vw;max-height:82vh;border-radius:12px;object-fit:contain;display:block;">'+
    '<a href="'+esc(url)+'" download target="_blank" style="display:block;text-align:center;margin-top:10px;color:#a5b4fc;font-size:13px;font-weight:700;text-decoration:none;">⬇ دانلود</a>'+
  '</div>';
  document.body.appendChild(ov);
  ov.querySelector('#prev-close').onclick = function(){ document.body.removeChild(ov); };
  ov.onclick = function(e){ if(e.target===ov) document.body.removeChild(ov); };
}

/* ═══════════════════════════════════════════════════
   CLIENT REQUESTS
═══════════════════════════════════════════════════ */
var PRIORITY_LABELS = {low:'🔵 کم',normal:'🟡 معمولی',high:'🟠 بالا',urgent:'🔴 فوری'};

function renderReqCard(r, isExpert){
  var types = P.req_types||{};
  var statuses = P.req_statuses||{};
  var ty = types[r.type]||{icon:'💬',label:r.type,color:'#94a3b8'};
  var st = statuses[r.status]||{icon:'⏳',label:r.status,color:'#94a3b8'};

  var cardStyle = 'border-radius:14px;padding:14px 16px;border:1.5px solid rgba(100,116,139,.14);background:var(--th-card-bg,#fff);box-shadow:0 2px 8px rgba(0,0,0,.05);display:flex;flex-direction:column;gap:8px;';

  var badge = function(icon,label,color){
    return '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:11.5px;font-weight:800;background:'+color+'18;color:'+color+';">'+icon+' '+esc(label)+'</span>';
  };

  var respondForm = '';
  if(isExpert){
    var stOpts = Object.keys(statuses).map(function(k){
      return '<option value="'+esc(k)+'"'+(k===r.status?' selected':'')+'>'+statuses[k].icon+' '+esc(statuses[k].label)+'</option>';
    }).join('');
    respondForm = '<div class="req-respond" data-rid="'+r.id+'" style="display:flex;flex-direction:column;gap:6px;padding-top:8px;border-top:1px dashed rgba(99,102,241,.15);">'+
      '<select class="req-status-sel" style="padding:6px 10px;border-radius:8px;border:1.5px solid rgba(100,116,139,.2);background:var(--th-card-bg,#fff);font:inherit;font-size:12.5px;color:inherit;">'+stOpts+'</select>'+
      '<textarea class="req-resp-txt" rows="2" placeholder="پاسخ به مشتری..." style="padding:8px 12px;border-radius:8px;border:1.5px solid rgba(100,116,139,.2);background:var(--th-card-bg,#fff);font:inherit;font-size:12.5px;resize:vertical;color:inherit;">'+esc(r.response||'')+'</textarea>'+
      '<button class="req-resp-btn" style="padding:7px 16px;border-radius:8px;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border:none;font-size:12.5px;font-weight:800;cursor:pointer;font-family:inherit;align-self:flex-start;">💬 ثبت پاسخ</button>'+
    '</div>';
  }

  return '<div class="req-card" data-rid="'+r.id+'" style="'+cardStyle+'">'+
    '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">'+
      badge(ty.icon,ty.label,ty.color)+
      badge(st.icon,st.status_label||st.label,st.color)+
      '<span style="font-size:11.5px;color:var(--th-muted,#64748b);">'+(PRIORITY_LABELS[r.priority]||r.priority)+'</span>'+
      (isExpert||r.can_delete ? '<button class="req-del" data-rid="'+r.id+'" style="margin-right:auto;padding:3px 10px;border-radius:8px;border:1.5px solid rgba(239,68,68,.25);background:rgba(239,68,68,.06);color:#b91c1c;font-size:12px;cursor:pointer;font-family:inherit;">🗑</button>' : '')+
    '</div>'+
    '<div style="font-size:14px;font-weight:900;color:var(--th-text,#0f172a);">'+esc(r.title)+'</div>'+
    (r.client_name&&isExpert ? '<div style="font-size:12px;color:var(--th-muted,#64748b);">👤 '+esc(r.client_name)+'</div>' : '')+
    (r.description ? '<div style="font-size:13px;line-height:1.7;color:var(--th-text,#0f172a);">'+esc(r.description)+'</div>' : '')+
    '<div style="font-size:11.5px;color:var(--th-muted,#64748b);">📅 '+esc(r.created_fa||r.created_at)+'</div>'+
    (r.attachment_url ? '<a href="'+esc(r.attachment_url)+'" target="_blank" style="font-size:12.5px;color:#6366f1;font-weight:700;text-decoration:none;">📎 پیوست</a>' : '')+
    (r.response ? '<div style="padding:8px 12px;border-radius:10px;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);font-size:12.5px;"><b>پاسخ:</b> '+esc(r.response)+'</div>' : '')+
    respondForm+
  '</div>';
}

function openRequests(pid, isExpert){
  var types = P.req_types||{};
  var statuses = P.req_statuses||{};

  var newFormHTML = !isExpert ? (
    '<div id="req-new-wrap" style="display:none;border-radius:14px;padding:14px;background:rgba(99,102,241,.05);border:1.5px solid rgba(99,102,241,.18);margin-bottom:14px;">'+
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">'+
        '<label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:800;color:var(--th-muted,#64748b);">نوع<select name="type" style="padding:7px 10px;border-radius:8px;border:1.5px solid rgba(100,116,139,.2);background:var(--th-card-bg,#fff);font:inherit;font-size:12.5px;color:inherit;">'+
          Object.keys(types).map(function(k){ return '<option value="'+k+'">'+types[k].icon+' '+esc(types[k].label)+'</option>'; }).join('')+
        '</select></label>'+
        '<label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:800;color:var(--th-muted,#64748b);">اولویت<select name="priority" style="padding:7px 10px;border-radius:8px;border:1.5px solid rgba(100,116,139,.2);background:var(--th-card-bg,#fff);font:inherit;font-size:12.5px;color:inherit;"><option value="low">🔵 کم</option><option value="normal" selected>🟡 معمولی</option><option value="high">🟠 بالا</option><option value="urgent">🔴 فوری</option></select></label>'+
      '</div>'+
      '<input type="text" name="title" placeholder="عنوان درخواست *" style="width:100%;box-sizing:border-box;padding:8px 12px;border-radius:8px;border:1.5px solid rgba(100,116,139,.2);background:var(--th-card-bg,#fff);font:inherit;font-size:12.5px;margin-bottom:8px;color:inherit;">'+
      '<textarea name="description" rows="3" placeholder="توضیحات..." style="width:100%;box-sizing:border-box;padding:8px 12px;border-radius:8px;border:1.5px solid rgba(100,116,139,.2);background:var(--th-card-bg,#fff);font:inherit;font-size:12.5px;resize:vertical;margin-bottom:8px;color:inherit;"></textarea>'+
      '<label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:800;color:var(--th-muted,#64748b);margin-bottom:8px;">پیوست (اختیاری)<input type="file" name="attachment" style="font:inherit;font-size:12px;"></label>'+
      '<button id="req-submit-btn" style="padding:9px 20px;border-radius:10px;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border:none;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;">📤 ثبت درخواست</button>'+
    '</div>'
  ) : '';

  var stOpts = '<option value="">همه وضعیت‌ها</option>'+Object.keys(statuses).map(function(k){ return '<option value="'+k+'">'+esc(statuses[k].icon+' '+statuses[k].label)+'</option>'; }).join('');

  var bodyHTML =
    '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">'+
      '<input id="req-search" type="search" placeholder="جستجو..." style="flex:1;min-width:100px;padding:7px 12px;border-radius:10px;border:1.5px solid rgba(100,116,139,.2);background:var(--th-card-bg,#fff);font:inherit;font-size:12.5px;color:inherit;">'+
      '<select id="req-status-filter" style="padding:7px 10px;border-radius:10px;border:1.5px solid rgba(100,116,139,.2);background:var(--th-card-bg,#fff);font:inherit;font-size:12.5px;color:inherit;">'+stOpts+'</select>'+
      (!isExpert ? '<button id="req-new-toggle" style="padding:7px 16px;border-radius:10px;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border:none;font-size:12.5px;font-weight:800;cursor:pointer;font-family:inherit;">+ درخواست جدید</button>' : '')+
    '</div>'+
    newFormHTML+
    '<div id="req-msg" style="min-height:10px;margin-bottom:8px;font-size:13px;font-weight:700;"></div>'+
    '<div id="req-list"><div style="text-align:center;padding:40px;color:var(--th-muted,#94a3b8);">در حال بارگذاری...</div></div>';

  var title = isExpert ? '📋 درخواست‌های مشتری پروژه' : '📋 درخواست‌های من';
  var modal = Modal('cptt-req-'+pid, title, bodyHTML);

  var list = modal.el.querySelector('#req-list');
  var searchEl = modal.el.querySelector('#req-search');
  var stFilter = modal.el.querySelector('#req-status-filter');
  var msgEl = modal.el.querySelector('#req-msg');
  var allReqs = [];

  function showMsg(t,ok){
    msgEl.textContent=t;
    msgEl.style.cssText='min-height:10px;margin-bottom:8px;font-size:13px;font-weight:700;padding:6px 12px;border-radius:8px;color:'+(ok?'#15803d':'#b91c1c')+';background:'+(ok?'rgba(34,197,94,.08)':'rgba(239,68,68,.08)')+';';
    setTimeout(function(){ msgEl.style.cssText='min-height:10px;margin-bottom:8px;font-size:13px;font-weight:700;'; },3000);
  }

  function renderList(){
    var q=(searchEl.value||'').toLowerCase(), st=stFilter.value;
    var vis=allReqs.filter(function(r){ return (!q||(r.title||'').toLowerCase().indexOf(q)>-1)&&(!st||r.status===st); });
    if(!vis.length){ list.innerHTML='<div style="text-align:center;padding:40px;color:var(--th-muted,#94a3b8);">'+(allReqs.length?'فیلتری یافت نشد.':'درخواستی وجود ندارد.')+'</div>'; return; }
    list.innerHTML='<div style="display:flex;flex-direction:column;gap:10px;">'+vis.map(function(r){ return renderReqCard(r,isExpert); }).join('')+'</div>';
    bindReqEvents();
  }

  function bindReqEvents(){
    list.querySelectorAll('.req-del').forEach(function(btn){
      btn.onclick=function(){
        if(!confirm('درخواست حذف شود؟')) return;
        ajax('cptt_req_delete',{req_id:btn.dataset.rid},function(r){
          if(r&&r.success){ allReqs=allReqs.filter(function(x){return String(x.id)!==btn.dataset.rid;}); renderList(); showMsg('✅ حذف شد.',true); }
          else showMsg('❌ خطا',false);
        });
      };
    });
    list.querySelectorAll('.req-resp-btn').forEach(function(btn){
      btn.onclick=function(){
        var form=btn.closest('.req-respond');
        var rid=form.dataset.rid;
        var st=form.querySelector('.req-status-sel').value;
        var resp=form.querySelector('.req-resp-txt').value;
        ajax('cptt_req_respond',{req_id:rid,status:st,response:resp},function(r){
          if(r&&r.success){
            var req=allReqs.find(function(x){return String(x.id)===String(rid);});
            if(req){ req.status=st; req.response=resp; }
            renderList(); showMsg('✅ پاسخ ثبت شد.',true);
          } else showMsg('❌ خطا',false);
        });
      };
    });
  }

  // load
  ajax('cptt_req_list',{project_id:pid},function(r){
    if(r&&r.success){ allReqs=r.data.requests||[]; renderList(); }
    else list.innerHTML='<div style="text-align:center;padding:40px;color:#ef4444;">خطا در بارگذاری.</div>';
  });

  searchEl.addEventListener('input', renderList);
  stFilter.addEventListener('change', renderList);

  // new request toggle
  var newToggle = modal.el.querySelector('#req-new-toggle');
  var newWrap = modal.el.querySelector('#req-new-wrap');
  if(newToggle && newWrap){
    newToggle.onclick = function(){
      newWrap.style.display = newWrap.style.display==='none' ? 'block' : 'none';
    };
    // submit
    var submitBtn = modal.el.querySelector('#req-submit-btn');
    if(submitBtn){
      submitBtn.onclick = function(){
        var title_val = newWrap.querySelector('[name=title]').value.trim();
        if(!title_val){ alert('عنوان درخواست الزامی است.'); return; }
        var fd = new FormData();
        fd.append('action','cptt_req_submit');
        fd.append('nonce',P.fnonce);
        fd.append('project_id',pid);
        fd.append('title',title_val);
        fd.append('description',newWrap.querySelector('[name=description]').value||'');
        fd.append('type',newWrap.querySelector('[name=type]').value||'other');
        fd.append('priority',newWrap.querySelector('[name=priority]').value||'normal');
        var att = newWrap.querySelector('[name=attachment]');
        if(att&&att.files&&att.files[0]) fd.append('attachment',att.files[0]);
        var xhr=new XMLHttpRequest(); xhr.open('POST',P.ajax);
        xhr.onload=function(){
          var r; try{r=JSON.parse(xhr.responseText);}catch(e){}
          if(r&&r.success){ showMsg('✅ '+r.data.msg,true); newWrap.style.display='none';
            ajax('cptt_req_list',{project_id:pid},function(r2){ if(r2&&r2.success){ allReqs=r2.data.requests||[]; renderList(); } });
          } else showMsg('❌ '+(r&&r.data?r.data:'خطا'),false);
        };
        xhr.send(fd);
      };
    }
  }
}

/* ═══════════════════════════════════════════════════
   BIND BUTTONS
═══════════════════════════════════════════════════ */
ready(function($){
  // file manager
  $(document).on('click','.cptt-btn--file-manager',function(e){
    e.preventDefault(); e.stopPropagation();
    var pid=$(this).data('project-id')||(this).getAttribute('data-project-id');
    if(pid) openFileManager(String(pid));
    else alert('شناسه پروژه یافت نشد.');
  });

  // requests - expert
  $(document).on('click','.cptt-btn--requests',function(e){
    e.preventDefault(); e.stopPropagation();
    var pid=$(this).data('project-id')||(this).getAttribute('data-project-id');
    if(pid) openRequests(String(pid), true);
    else alert('شناسه پروژه یافت نشد.');
  });

  // requests - client (frontend)
  $(document).on('click','.cptt-client-requests-btn',function(e){
    e.preventDefault(); e.stopPropagation();
    var pid=$(this).data('project-id')||(this).getAttribute('data-project-id');
    if(pid) openRequests(String(pid), false);
    else alert('شناسه پروژه یافت نشد.');
  });

  // confirm: اگه data-project-id نداشت، از closest card بگیر
  $(document).on('click','.cptt-btn--file-manager,.cptt-btn--requests,.cptt-client-requests-btn',function(e){
    // already handled above
  });
});

})();
