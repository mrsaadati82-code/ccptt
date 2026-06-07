/**
 * CPTT Expert Views v6.0 — بازنویسی کامل
 * Card / List / Calendar(Jalali) / Gantt / Timeline
 * + Sort + Filter persistence
 */
(function () {
  'use strict';

  /* ─── utils ─── */
  function qs(s, c)  { return (c||document).querySelector(s); }
  function qsa(s, c) { return Array.from((c||document).querySelectorAll(s)); }
  function esc(s) {
    return String(s||'').replace(/[&<>"']/g,function(c){
      return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  /* UTF-8 safe base64 decode - برای متن فارسی */
  function b64decode(str) {
    if (!str) return '';
    try {
      return decodeURIComponent(atob(str).split('').map(function(c){
        return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
      }).join(''));
    } catch(e) {
      try { return atob(str); } catch(e2) { return ''; }
    }
  }

  var LS_VIEW    = 'cptt_ev_view';
  var LS_SORT    = 'cptt_ev_sort';
  var LS_FILTERS = 'cptt_ev_filters';

  /* ─── Jalali ─── */
  var JM = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  var JD = ['ش','ی','د','س','چ','پ','ج'];

  function g2j(gy,gm,gd){
    var g=[0,31,59,90,120,151,181,212,243,273,304,334];
    var gy2=gm>2?gy+1:gy;
    var days=355666+365*gy+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)+gd+g[gm-1];
    var jy=-1595+33*Math.floor(days/12053); days%=12053;
    jy+=4*Math.floor(days/1461); days%=1461;
    if(days>365){jy+=Math.floor((days-1)/365);days=(days-1)%365;}
    var jm,jd;
    if(days<186){jm=1+Math.floor(days/31);jd=1+(days%31);}
    else{jm=7+Math.floor((days-186)/30);jd=1+((days-186)%30);}
    return[jy,jm,jd];
  }
  function j2g(jy,jm,jd){
    jy+=1595;
    var days=-355668+(365*jy)+(Math.floor(jy/33)*8)+Math.floor((jy%33+3)/4)+jd;
    if(jm<7)days+=(jm-1)*31;else days+=((jm-7)*30)+186;
    var gy=400*Math.floor(days/146097); days%=146097;
    if(days>36524){gy+=100*Math.floor(--days/36524);days%=36524;if(days>=365)days++;}
    gy+=4*Math.floor(days/1461); days%=1461;
    if(days>365){gy+=Math.floor((days-1)/365);days=(days-1)%365;}
    var gd=days+1;
    var sal=[0,31,(((gy%4===0&&gy%100!==0)||gy%400===0)?29:28),31,30,31,30,31,31,30,31,30,31];
    var gm=0; for(var i=1;i<=12;i++){if(gd<=sal[i]){gm=i;break;}gd-=sal[i];}
    return[gy,gm,gd];
  }
  function ts2j(ts){ if(!ts)return null; var d=new Date(ts*1000); return g2j(d.getFullYear(),d.getMonth()+1,d.getDate()); }
  function jLabel(ts){ var j=ts2j(ts); return j?j[2]+' '+JM[j[1]-1]:''; }

  /* ─── status ─── */
  var SL={'todo':'انجام‌نشده','current':'در جریان','done':'تکمیل‌شده','active':'فعال','completed':'تکمیل‌شده','in_progress':'در جریان'};
  var SC={'todo':'#94a3b8','current':'#f59e0b','done':'#22c55e','active':'#6366f1','completed':'#22c55e','in_progress':'#f59e0b'};
  function sl(s){return SL[s]||s;}
  function sc(s){return SC[s]||'#94a3b8';}

  /* ─── collect cards from DOM ─── */
  function getCards(){
    return qsa('.cptt-expertCard').map(function(el){
      var stepsB64=el.dataset.steps||'';
      var steps=[];
      if(stepsB64){try{steps=JSON.parse(b64decode(stepsB64))||[];}catch(e){}}
      // برای data-title، از h3 استفاده می‌کنیم اگه dataset خالی بود
      var title = el.dataset.title || '';
      if(!title){
        var h3=qs('h3',el);
        if(h3) title = h3.textContent.replace(/#\d+/g,'').trim();
      }
      return {
        el:          el,
        id:          el.dataset.projectId||'',
        title:       title,
        status:      el.dataset.status||'todo',
        progress:    parseInt(el.dataset.progress||'0',10),
        dlTs:        parseInt(el.dataset.deadlineTs||'0',10),
        crTs:        parseInt(el.dataset.createdTs||'0',10),
        luTs:        parseInt(el.dataset.lastUpdateTs||'0',10),
        cost:        parseInt(el.dataset.cost||'0',10),
        dlFa:        el.dataset.deadlineFa||'',
        luFa:        el.dataset.lastUpdateFa||'',
        customer:    el.dataset.customerName||'',
        settled:     el.dataset.settled==='1',
        steps:       steps,
        hidden:      el.hidden||(el.style.display==='none'),
      };
    });
  }

  /* ─── sort ─── */
  function sortCards(cards,mode){
    var a=cards.slice();
    switch(mode){
      case 'date_asc':     return a.sort(function(x,y){return x.crTs-y.crTs;});
      case 'date_desc':    return a.sort(function(x,y){return y.crTs-x.crTs;});
      case 'dl_asc':       return a.sort(function(x,y){
        if(!x.dlTs&&!y.dlTs)return 0; if(!x.dlTs)return 1; if(!y.dlTs)return -1; return x.dlTs-y.dlTs;});
      case 'dl_desc':      return a.sort(function(x,y){return y.dlTs-x.dlTs;});
      case 'prog_desc':    return a.sort(function(x,y){return (y.progress||0)-(x.progress||0);});
      case 'prog_asc':     return a.sort(function(x,y){return (x.progress||0)-(y.progress||0);});
      case 'title_asc':    return a.sort(function(x,y){return x.title.localeCompare(y.title,'fa');});
      case 'update_desc':  return a.sort(function(x,y){return y.luTs-x.luTs;});
      case 'cost_desc':    return a.sort(function(x,y){return y.cost-x.cost;});
      default:             return a.sort(function(x,y){return y.crTs-x.crTs;});
    }
  }

  /* ─── open project card ─── */
  var _prevView='card'; // view قبل از باز کردن پروژه

  function openProjectCard(pid){
    // ذخیره view فعلی برای برگشت
    _prevView=currentView;
    // برو به card view
    setViewMode('card');
    setTimeout(function(){
      var card=document.querySelector('.cptt-expertCard[data-project-id="'+pid+'"]');
      if(!card)return;
      card.scrollIntoView({behavior:'smooth',block:'start'});
      var details=qs('.cptt-expertCard__details',card);
      var isOpen=details&&!details.hidden&&card.classList.contains('is-expanded');
      if(!isOpen){
        var toggleBtn=qs('.cptt-expert-toggleProject',card);
        if(toggleBtn){
          toggleBtn.click();
          // وقتی بسته شد، برگرد به view قبلی
          setTimeout(function(){
            var btn2=toggleBtn; // reference
            btn2.addEventListener('click',function onClose(){
              btn2.removeEventListener('click',onClose);
              if(_prevView&&_prevView!=='card'){
                setTimeout(function(){ setViewMode(_prevView); },200);
              }
            });
          },50);
        }
      }
    },120);
  }

  /* ─── current visible cards (after filter) ─── */
  function getVisibleCards(){
    return getCards().filter(function(c){return !c.hidden;});
  }

  /* ═══════════════════════════════════════════════════
     LIST VIEW
  ═══════════════════════════════════════════════════ */
  function renderList(cards){
    var con=qs('#cptt-list-view'); if(!con)return;
    if(!cards.length){con.innerHTML='<div class="cev-empty">پروژه‌ای یافت نشد.</div>';return;}
    var rows=cards.map(function(c){
      var color=sc(c.status);
      return '<tr class="cev-list__row" data-pid="'+esc(c.id)+'">'
        +'<td class="cev-list__name"><span class="cev-list__badge" style="background:'+color+'22;color:'+color+'">'+sl(c.status)+'</span>'+esc(c.title)+'</td>'
        +'<td>'+esc(c.customer)+'</td>'
        +'<td><div class="cev-prog"><div class="cev-prog__bar" style="width:'+c.progress+'%;background:'+color+'"></div><span>'+c.progress+'%</span></div></td>'
        +'<td class="cev-list__dl">'+(c.dlFa||'—')+'</td>'
        +'<td class="cev-list__lu">'+(c.luFa||'—')+'</td>'
        +'<td><span class="cev-settle cev-settle--'+(c.settled?'yes':'no')+'">'+(c.settled?'✅ تسویه':'⏳ باز')+'</span></td>'
        +'<td><button class="cev-open-btn" data-pid="'+esc(c.id)+'">مدیریت ↗</button></td>'
        +'</tr>';
    }).join('');
    con.innerHTML='<div class="cev-list-wrap"><table class="cev-list"><thead><tr>'
      +'<th>عنوان پروژه</th><th>مشتری</th><th>پیشرفت</th><th>مهلت</th><th>آخرین بروزرسانی</th><th>وضعیت مالی</th><th></th>'
      +'</tr></thead><tbody>'+rows+'</tbody></table></div>';
    qsa('[data-pid]',con).forEach(function(el){
      el.addEventListener('click',function(e){e.stopPropagation();openProjectCard(el.dataset.pid||el.closest('[data-pid]').dataset.pid);});
    });
  }

  /* ═══════════════════════════════════════════════════
     CALENDAR VIEW (Jalali)
  ═══════════════════════════════════════════════════ */
  function renderCalendar(cards){
    var con=qs('#cptt-calendar-view'); if(!con)return;
    var now=Math.floor(Date.now()/1000);
    var nj=ts2j(now)||[1403,1,1];
    var state={y:nj[0],m:nj[1],mode:'dl'}; // mode: 'dl'=مهلت، 'cr'=ایجاد

    function buildItems(mode){
      // آیتم‌ها: پروژه‌ها و مراحلشان
      var items=[];
      cards.forEach(function(c){
        var ts=mode==='dl'?c.dlTs:c.crTs;
        if(ts) items.push({pid:c.id,label:c.title,ts:ts,color:sc(c.status),type:'project'});
        // مراحل
        if(c.steps&&c.steps.length){
          c.steps.forEach(function(s){
            var sts=mode==='dl'?(s.due_at||0):(0);
            if(sts) items.push({pid:c.id,label:s.title||'مرحله',ts:sts,color:sc(s.status||'todo'),type:'step',pTitle:c.title});
          });
        }
      });
      return items;
    }

    function draw(){
      var y=state.y,m=state.m,mode=state.mode;
      var daysInM=m<=6?31:(m<=11?30:29);
      var greg=j2g(y,m,1);
      var dow=new Date(greg[0],greg[1]-1,greg[2]).getDay();
      var offset=(dow+1)%7;
      var items=buildItems(mode);
      var pbd={};
      items.forEach(function(it){
        var j=ts2j(it.ts);
        if(!j||j[0]!==y||j[1]!==m)return;
        (pbd[j[2]]||(pbd[j[2]]=[])).push(it);
      });
      var todayKey=(nj[0]===y&&nj[1]===m)?nj[2]:-1;
      var modeLabel=mode==='dl'?'🗓 بر اساس مهلت':'📅 بر اساس ایجاد';
      var html='<div class="cev-cal-topbar">'
        +'<button class="cev-cal-btn" id="cev-cal-prev">&#8594; قبل</button>'
        +'<span class="cev-cal-title">'+JM[m-1]+' '+y+'</span>'
        +'<button class="cev-cal-btn" id="cev-cal-next">بعد &#8592;</button>'
        +'<button class="cev-cal-mode-btn" id="cev-cal-mode">'+modeLabel+'</button>'
        +'</div>'
        +'<div class="cev-cal-grid">';
      JD.forEach(function(d){html+='<div class="cev-cal-dh">'+d+'</div>';});
      for(var i=0;i<offset;i++)html+='<div class="cev-cal-cell cev-cal-cell--empty"></div>';
      for(var d2=1;d2<=daysInM;d2++){
        var ps=pbd[d2]||[];
        var hasPrj=ps.some(function(p){return p.type==='project';});
        var hasStp=ps.some(function(p){return p.type==='step';});
        var cls='cev-cal-cell'+(d2===todayKey?' cev-cal-cell--today':'')+(ps.length?' cev-cal-cell--has':'');
        html+='<div class="'+cls+'"><span class="cev-cal-num">'+d2+'</span>';
        ps.slice(0,3).forEach(function(p){
          var icon=p.type==='step'?'▸ ':'● ';
          var title=(p.type==='step'?p.pTitle+' / ':'')+p.label;
          html+='<div class="cev-cal-ev cev-cal-ev--'+p.type+'" data-pid="'+esc(p.pid)+'" '
            +'style="border-right:3px solid '+p.color+';background:'+p.color+'18;color:'+p.color+'" '
            +'title="'+esc(title)+'">'+icon+esc(p.label.substr(0,14))+'</div>';
        });
        if(ps.length>3)html+='<div class="cev-cal-more">+'+(ps.length-3)+' بیشتر</div>';
        html+='</div>';
      }
      html+='</div>';
      con.innerHTML=html;
      qs('#cev-cal-prev',con).onclick=function(){state.m--;if(state.m<1){state.m=12;state.y--;}draw();};
      qs('#cev-cal-next',con).onclick=function(){state.m++;if(state.m>12){state.m=1;state.y++;}draw();};
      qs('#cev-cal-mode',con).onclick=function(){state.mode=state.mode==='dl'?'cr':'dl';draw();};
      qsa('.cev-cal-ev',con).forEach(function(ev){
        ev.addEventListener('click',function(e){e.stopPropagation();openProjectCard(ev.dataset.pid);});
      });
    }
    draw();
  }

  /* ═══════════════════════════════════════════════════
     GANTT VIEW
  ═══════════════════════════════════════════════════ */
  function renderGantt(cards){
    var con=qs('#cptt-gantt-view'); if(!con)return;
    if(!cards.length){con.innerHTML='<div class="cev-empty">پروژه‌ای یافت نشد.</div>';return;}

    var now=Math.floor(Date.now()/1000);
    // بازه پیش‌فرض: ۳ ماه قبل تا ۶ ماه بعد
    var RANGES=[
      {label:'۱ ماه', days:30},
      {label:'۳ ماه', days:90},
      {label:'۶ ماه', days:180},
      {label:'۱ سال', days:365},
    ];
    var state={rangeIdx:1, offset:0}; // offset: تعداد range به جلو/عقب

    function build(){
      var days=RANGES[state.rangeIdx].days;
      var half=Math.floor(days/3);
      var rStart=now-half*86400+(state.offset*days*86400);
      var rEnd=rStart+days*86400;
      return{rStart:rStart,rEnd:rEnd,days:days};
    }

    function buildHeaders(rStart,rEnd){
      // تولید header ماه‌ها با عرض متناسب
      var total=rEnd-rStart;
      var html='';
      var j=ts2j(rStart)||[1403,1,1];
      var y=j[0],m=j[1];
      while(true){
        var mStart=new Date(j2g(y,m,1)[0],j2g(y,m,1)[1]-1,j2g(y,m,1)[2]).getTime()/1000;
        var dIM=m<=6?31:(m<=11?30:29);
        var mEnd=mStart+dIM*86400;
        var overlapStart=Math.max(mStart,rStart);
        var overlapEnd=Math.min(mEnd,rEnd);
        if(overlapStart>=rEnd)break;
        if(overlapEnd>overlapStart){
          var pct=((overlapEnd-overlapStart)/total)*100;
          html+='<div class="cev-gantt-mh" style="flex:0 0 '+pct+'%;min-width:0">'+JM[m-1]+' '+y+'</div>';
        }
        m++; if(m>12){m=1;y++;}
        if(y>j[0]+2)break; // safety
      }
      return html;
    }

    function draw(){
      var r=build();
      var rStart=r.rStart,rEnd=r.rEnd,total=r.days*86400;
      function pct(ts){return Math.max(0,Math.min(100,((ts-rStart)/total)*100));}
      var nowPct=pct(now);

      // range controls
      var rangeHtml='<div class="cev-gantt-controls">';
      rangeHtml+='<button class="cev-gantt-nav" id="cev-gantt-prev">&#8594; قبل</button>';
      RANGES.forEach(function(r2,i){
        rangeHtml+='<button class="cev-gantt-range-btn'+(i===state.rangeIdx?' cev-active':'')+'" data-ri="'+i+'">'+r2.label+'</button>';
      });
      rangeHtml+='<button class="cev-gantt-nav" id="cev-gantt-next">بعد &#8592;</button>';
      rangeHtml+='</div>';

      // header
      var headerHtml='<div class="cev-gantt-header">'
        +'<div class="cev-gantt-lbl cev-gantt-lbl--head">پروژه / مرحله</div>'
        +'<div class="cev-gantt-months">'+buildHeaders(rStart,rEnd)+'</div>'
        +'</div>';

      // rows: پروژه + مراحلش
      var rowsHtml='';
      cards.forEach(function(c){
        var s=c.crTs||0, e=c.dlTs||0;
        var inRange=(s<rEnd)&&(e>rStart||!e)&&(s>0||e>0);
        // fallback اگه هیچ ts ندارد
        if(!s&&!e) return;
        if(!s) s=rStart; if(!e) e=rEnd;
        if(s>rEnd) return; if(e<rStart) return;

        var bL=pct(s), bW=Math.max(1,pct(e)-bL);
        var color=sc(c.status);
        var progW=bW*(c.progress/100);

        rowsHtml+='<div class="cev-gantt-row cev-gantt-row--project" data-pid="'+esc(c.id)+'">'
          +'<div class="cev-gantt-lbl" title="'+esc(c.title)+'"><span class="cev-gantt-lbl-icon">📁</span>'+esc(c.title.substr(0,20))+'</div>'
          +'<div class="cev-gantt-track">'
          +(now>=rStart&&now<=rEnd?'<div class="cev-gantt-today" style="left:'+nowPct+'%"></div>':'')
          +'<div class="cev-gantt-bar cev-gantt-bar--project" style="left:'+bL+'%;width:'+bW+'%;background:'+color+'20;border:1.5px solid '+color+'">'
          +'<div class="cev-gantt-fill" style="width:'+c.progress+'%;background:'+color+'70"></div>'
          +'<span class="cev-gantt-bar-lbl">'+c.progress+'%</span>'
          +'</div>'
          +'</div>'
          +'</div>';

        // مراحل
        if(c.steps&&c.steps.length){
          c.steps.forEach(function(st){
            var ss=c.crTs||rStart, se=st.due_at||0;
            if(!se) return;
            if(ss>rEnd||se<rStart) return;
            var bL2=pct(ss), bW2=Math.max(1,pct(se)-bL2);
            var stColor=sc(st.status||'todo');
            rowsHtml+='<div class="cev-gantt-row cev-gantt-row--step" data-pid="'+esc(c.id)+'">'
              +'<div class="cev-gantt-lbl cev-gantt-lbl--step" title="'+esc(st.title||'')+'"><span class="cev-gantt-lbl-icon">▸</span>'+esc((st.title||'مرحله').substr(0,18))+'</div>'
              +'<div class="cev-gantt-track">'
              +(now>=rStart&&now<=rEnd?'<div class="cev-gantt-today" style="left:'+nowPct+'%"></div>':'')
              +'<div class="cev-gantt-bar cev-gantt-bar--step" style="left:'+bL2+'%;width:'+bW2+'%;background:'+stColor+'14;border:1px dashed '+stColor+'">'
              +'<span class="cev-gantt-bar-lbl" style="font-size:10px;color:'+stColor+'">'+esc((st.title||'').substr(0,10))+'</span>'
              +'</div>'
              +'</div>'
              +'</div>';
          });
        }
      });

      con.innerHTML='<div class="cev-gantt-outer">'
        +rangeHtml
        +'<div class="cev-gantt-wrap">'
        +headerHtml
        +(rowsHtml||'<div class="cev-empty" style="padding:32px">پروژه‌ای در این بازه زمانی یافت نشد.</div>')
        +'</div>'
        +'</div>';

      // events
      qs('#cev-gantt-prev',con).onclick=function(){state.offset--;draw();};
      qs('#cev-gantt-next',con).onclick=function(){state.offset++;draw();};
      qsa('.cev-gantt-range-btn',con).forEach(function(btn){
        btn.onclick=function(){state.rangeIdx=parseInt(btn.dataset.ri);state.offset=0;draw();};
      });
      qsa('.cev-gantt-row',con).forEach(function(r){
        r.addEventListener('click',function(){openProjectCard(r.dataset.pid);});
      });
    }
    draw();
  }

  /* ═══════════════════════════════════════════════════
     TIMELINE VIEW
  ═══════════════════════════════════════════════════ */
  function renderTimeline(cards){
    var con=qs('#cptt-timeline-view'); if(!con)return;
    if(!cards.length){con.innerHTML='<div class="cev-empty">پروژه‌ای یافت نشد.</div>';return;}
    var state={mode:'lu'}; // lu=آخرین بروزرسانی، dl=مهلت، cr=ایجاد

    function getTsForCard(c,mode){
      if(mode==='dl') return c.dlTs||0;
      if(mode==='cr') return c.crTs||0;
      return c.luTs||c.crTs||0;
    }
    function buildItems(mode){
      var items=[];
      cards.forEach(function(c){
        var ts=getTsForCard(c,mode);
        if(ts) items.push({pid:c.id,label:c.title,ts:ts,color:sc(c.status),
          status:c.status,customer:c.customer,progress:c.progress,dlFa:c.dlFa,
          luFa:c.luFa,type:'project',steps:c.steps||[]});
        // مراحل با مهلت
        (c.steps||[]).forEach(function(s){
          var sts=mode==='dl'?(s.due_at||0):(mode==='lu'?(s.due_at||0):0);
          if(!sts) return;
          items.push({pid:c.id,label:s.title||'مرحله',ts:sts,color:sc(s.status||'todo'),
            status:s.status||'todo',customer:'',progress:0,dlFa:'',type:'step',pTitle:c.title});
        });
      });
      items.sort(function(a,b){return b.ts-a.ts;});
      return items;
    }

    function draw(){
      var mode=state.mode;
      var modeOptions=[
        {v:'lu',l:'🕐 آخرین بروزرسانی'},{v:'dl',l:'⏰ مهلت'},{v:'cr',l:'📅 تاریخ ایجاد'}
      ];
      var modeHtml='<div class="cev-tl-controls">';
      modeOptions.forEach(function(o){
        modeHtml+='<button class="cev-tl-mode-btn'+(mode===o.v?' cev-active':'')+'" data-mode="'+o.v+'">'+o.l+'</button>';
      });
      modeHtml+='</div>';

      var items=buildItems(mode);
      var html='<div class="cev-tl">';
      var lastM='';
      items.forEach(function(it){
        var j=ts2j(it.ts);
        var mk=j?(j[0]+'-'+j[1]):'x';
        if(mk!==lastM){
          lastM=mk;
          html+='<div class="cev-tl-sep">'+(j?JM[j[1]-1]+' '+j[0]:'نامشخص')+'</div>';
        }
        var color=it.color, dt=j?jLabel(it.ts):'';
        html+='<div class="cev-tl-item cev-tl-item--'+it.type+'" data-pid="'+esc(it.pid)+'">'
          +'<div class="cev-tl-dot" style="background:'+color+'"></div>'
          +'<div class="cev-tl-card">'
          +(it.type==='step'?'<div class="cev-tl-step-parent">📁 '+esc(it.pTitle||'')+'</div>':'')
          +'<div class="cev-tl-title">'+(it.type==='step'?'▸ ':'')+esc(it.label)+'</div>'
          +'<div class="cev-tl-meta">'
          +'<span class="cev-badge" style="background:'+color+'18;color:'+color+'">'+sl(it.status)+'</span>'
          +(it.customer?'<span>👤 '+esc(it.customer)+'</span>':'')
          +(dt?'<span>📅 '+dt+'</span>':'')
          +(it.progress?'<span>📊 '+it.progress+'%</span>':'')
          +(it.dlFa&&it.type==='project'?'<span>⏰ '+esc(it.dlFa)+'</span>':'')
          +'</div>'
          +(it.type==='project'?'<div class="cev-tl-prog"><div style="width:'+it.progress+'%;background:'+color+'"></div></div>':'')
          +'<button class="cev-open-btn" data-pid="'+esc(it.pid)+'">مدیریت پروژه ↗</button>'
          +'</div>'
          +'</div>';
      });
      if(!items.length) html+='<div class="cev-empty">آیتمی برای نمایش یافت نشد.</div>';
      html+='</div>';
      con.innerHTML=modeHtml+html;

      qsa('.cev-tl-mode-btn',con).forEach(function(btn){
        btn.onclick=function(){state.mode=btn.dataset.mode;draw();};
      });
      qsa('[data-pid]',con).forEach(function(el){
        el.addEventListener('click',function(e){
          e.stopPropagation();
          var pid=el.dataset.pid||el.closest('[data-pid]').dataset.pid;
          if(pid) openProjectCard(pid);
        });
      });
    }
    draw();
  }

  /* ═══════════════════════════════════════════════════
     VIEW MANAGER
  ═══════════════════════════════════════════════════ */
  var currentView='card';
  var currentSort='date_desc';

  /* hide/show helper - مقاوم در برابر CSS specificity */
  function showEl(el){if(!el)return; el.removeAttribute('hidden'); el.style.setProperty('display','',  ''); el.classList.remove('cev-hidden');}
  function hideEl(el){if(!el)return; el.setAttribute('hidden','');   el.style.setProperty('display','none','important'); el.classList.add   ('cev-hidden');}

  function setViewMode(view){
    currentView=view;
    localStorage.setItem(LS_VIEW,view);
    var grid  =qs('#cptt-expert-grid');
    var listV =qs('#cptt-list-view');
    var calV  =qs('#cptt-calendar-view');
    var ganttV=qs('#cptt-gantt-view');
    var tlV   =qs('#cptt-timeline-view');
    var all   =[grid,listV,calV,ganttV,tlV];

    // پنهان کردن همه با style مستقیم
    all.forEach(function(el){ hideEl(el); });

    if(view==='card'){
      showEl(grid);
      applySortToGrid();
    } else {
      var cards=sortCards(getVisibleCards(),currentSort);
      if(view==='list'     ){ showEl(listV);  renderList(cards);}
      if(view==='calendar' ){ showEl(calV);   renderCalendar(cards);}
      if(view==='gantt'    ){ showEl(ganttV); renderGantt(cards);}
      if(view==='timeline' ){ showEl(tlV);    renderTimeline(cards);}
    }

    qsa('.cev-view-btn').forEach(function(btn){
      btn.classList.toggle('cev-active',btn.dataset.view===view);
    });
  }

  function applySortToGrid(){
    var grid=qs('#cptt-expert-grid');
    if(!grid)return;
    var cards=sortCards(getCards(),currentSort);
    cards.forEach(function(c){grid.appendChild(c.el);});
  }

  function refresh(){
    if(currentView==='card') applySortToGrid();
    else setViewMode(currentView);
  }

  /* ═══════════════════════════════════════════════════
     FILTER PERSISTENCE
  ═══════════════════════════════════════════════════ */
  function saveFilters(){
    var state={};
    ['cptt-expert-search','cptt-expert-status','cptt-expert-settled',
     'cptt-expert-client','cptt-expert-product','cptt-expert-cat','cptt-expert-label'].forEach(function(id){
      var el=qs('#'+id);
      if(el)state[id]=el.value||'';
    });
    try{localStorage.setItem(LS_FILTERS,JSON.stringify(state));}catch(e){}
  }

  function restoreFilters(){
    var raw='';
    try{raw=localStorage.getItem(LS_FILTERS)||'';}catch(e){}
    if(!raw)return;
    var state=null;
    try{state=JSON.parse(raw);}catch(e){}
    if(!state)return;
    Object.keys(state).forEach(function(id){
      var el=qs('#'+id);
      if(el&&state[id]!==''){el.value=state[id];el.dispatchEvent(new Event('change',{bubbles:true}));}
    });
  }

  function clearFilterStorage(){
    try{localStorage.removeItem(LS_FILTERS);}catch(e){}
  }

  /* ═══════════════════════════════════════════════════
     INIT
  ═══════════════════════════════════════════════════ */
  function buildToolbar(){
    // پیدا کردن wrapper
    var wrap=qs('#cptt-views-toolbar');
    if(!wrap)return;

    // پیدا کردن select sort که از PHP رندر شده
    var sortSel=qs('#cptt-sort-select',wrap);
    if(sortSel){
      sortSel.value=currentSort;
      sortSel.addEventListener('change',function(){
        currentSort=sortSel.value;
        localStorage.setItem(LS_SORT,currentSort);
        refresh();
      });
    }

    // view buttons (از PHP رندر شده)
    qsa('.cev-view-btn',wrap).forEach(function(btn){
      btn.addEventListener('click',function(){
        setViewMode(btn.dataset.view);
      });
    });
  }

  function hookFilters(){
    var ids=['cptt-expert-search','cptt-expert-status','cptt-expert-settled',
             'cptt-expert-client','cptt-expert-product','cptt-expert-cat','cptt-expert-label'];
    ids.forEach(function(id){
      var el=qs('#'+id);
      if(!el)return;
      el.addEventListener('input',function(){saveFilters();refresh();});
      el.addEventListener('change',function(){saveFilters();refresh();});
    });
    var resetBtn=qs('#cptt-expert-reset');
    if(resetBtn){
      resetBtn.addEventListener('click',function(){
        clearFilterStorage();
        refresh();
      });
    }
    // هر بار که updateVisibility اصلی صدا شد، view هم refresh کن
    document.addEventListener('cptt:expertFiltersChanged',function(){
      if(currentView!=='card') refresh();
    });
  }

  function init(){
    // restore preferences
    try{currentView=localStorage.getItem(LS_VIEW)||'card';}catch(e){}
    try{currentSort=localStorage.getItem(LS_SORT)||'date_desc';}catch(e){}

    // اگر toolbar هنوز در DOM نیست، صبر کن
    if(!qs('#cptt-views-toolbar')){
      var t=setInterval(function(){
        if(qs('#cptt-views-toolbar')){clearInterval(t);run();}
      },200);
    } else {
      run();
    }
  }

  /* ─── Filter Bar Accordion ─── */
  function initFilterAccordion(){
    var bar    = qs('#cptt-filter-bar');
    var toggle = qs('#cptt-filter-bar-toggle');
    var body   = qs('#cptt-filter-bar-body');
    var badge  = qs('#cptt-filter-active-badge');
    if(!bar||!toggle||!body) return;

    function open(){
      body.removeAttribute('hidden');
      bar.classList.add('is-open');
      toggle.setAttribute('aria-expanded','true');
      bar.setAttribute('aria-expanded','true');
      try{ localStorage.setItem('cptt_filter_open','1'); }catch(e){}
    }
    function close(){
      body.setAttribute('hidden','');
      bar.classList.remove('is-open');
      toggle.setAttribute('aria-expanded','false');
      bar.setAttribute('aria-expanded','false');
      try{ localStorage.setItem('cptt_filter_open','0'); }catch(e){}
    }

    toggle.addEventListener('click', function(){
      body.hasAttribute('hidden') ? open() : close();
    });
    toggle.addEventListener('keydown',function(e){
      if(e.key==='Enter'||e.key===' '){ e.preventDefault(); toggle.click(); }
    });

    // اگه قبلاً باز بود، باز بمون
    try{
      if(localStorage.getItem('cptt_filter_open')==='1') open();
    }catch(e){}

    // نمایش badge فیلتر فعال
    function updateBadge(){
      if(!badge) return;
      var active=0;
      ['cptt-expert-search','cptt-expert-status','cptt-expert-settled',
       'cptt-expert-client','cptt-expert-product','cptt-expert-cat','cptt-expert-label'].forEach(function(id){
        var el=qs('#'+id);
        if(el&&el.value&&el.value!=='') active++;
      });
      if(active>0){
        badge.textContent= active+' فیلتر فعال';
        badge.removeAttribute('hidden');
      } else {
        badge.setAttribute('hidden','');
      }
    }
    document.addEventListener('cptt:expertFiltersChanged', updateBadge);
    updateBadge();
  }

  function run(){
    buildToolbar();
    hookFilters();
    initFilterAccordion();
    setTimeout(function(){
      restoreFilters();
      var sortSel=qs('#cptt-sort-select');
      if(sortSel)sortSel.value=currentSort;
      setViewMode(currentView);
    },400);
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',init);
  } else {
    init();
  }

})();
