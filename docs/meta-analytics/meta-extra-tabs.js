/* meta-extra-tabs.js v2 — 4 аналітичні таби + фільтри (період/платформа/стать/тип/формат/мін-конв).
   Самостійний, fail-safe. Читає meta-extra.json (periods + daily + thresholds). Back-compat зі старою плоскою структурою. */
(function () {
  'use strict';
  var C = { red:'#E30613', red2:'#FF6A7A', gold:'#FBBF24', green:'#10B981', blue:'#60A5FA', grey:'#888' };
  var EX = null, charts = {};
  var F = { period:'last_30d', from:'', to:'', platform:'all', gender:'all', camptype:'all', format:'all', minConv:5 };
  var TABS = [
    { id:'xfunnel',  label:'🔻 Воронка',  fn:renderFunnel },
    { id:'xfatigue', label:'🔥 Втома',    fn:renderFatigue },
    { id:'xformats', label:'🎨 Формати',  fn:renderFormats },
    { id:'xai',      label:'🤖 AI-пріоритети', fn:renderAI }
  ];
  var f0 = function (n){ return (n==null||isNaN(n)) ? '—' : Math.round(n).toLocaleString('uk-UA'); };
  var f2 = function (n){ return (n==null||isNaN(n)) ? '—' : Number(n).toFixed(2); };
  function roasPill(v){ var c=v>=5?'pg':v>=3?'py':'pr'; return '<span class="pill '+c+'">'+f2(v)+'</span>'; }
  function delta(v, invert){ if(v==null) return ''; var good=invert?v<0:v>=0; var col=good?C.green:C.red2;
    return ' <span style="font-size:11px;color:'+col+'">'+(v>=0?'▲':'▼')+Math.abs(v).toFixed(0)+'%</span>'; }
  function mk(id,cfg){ var el=document.getElementById(id); if(!el||!window.Chart) return; if(charts[id]) charts[id].destroy(); charts[id]=new Chart(el,cfg); }
  function kpi(lab,val,extra){ return '<div class="card kpi"><div class="lab">'+lab+'</div><div class="val">'+val+(extra||'')+'</div></div>'; }
  function note(t){ return '<div class="note" style="margin-top:6px">'+t+'</div>'; }
  function waiting(){ return note('Дані ще збираються — перший прогін ETL <b>meta-extra-sync</b> наповнить таби (щодня 08:40 Київ).'); }
  var gx = { grid:{ color:'#1c1c1c' } };
  function thr(){ return (EX && EX.thresholds) || { min_conv:5, soft_conv:3, min_impr:1500 }; }

  /* ---------- доступ до даних з урахуванням фільтрів ---------- */
  function periodObj(){
    if(!EX) return null;
    if(EX.periods){
      if(F.period==='custom') return customBundle();
      return EX.periods[F.period] || EX.periods[EX.default_period] || null;
    }
    // back-compat: стара плоска структура
    return { funnel:EX.funnel, deltas:EX.deltas, campaigns:EX.campaigns, clusters:EX.clusters,
             video_vs_static:EX.video_vs_static, by_platform:[], by_gender:[], ai:EX.ai, window:EX.window };
  }
  function customBundle(){
    var d=(EX.daily||[]).filter(function(x){ return (!F.from||x.date>=F.from)&&(!F.to||x.date<=F.to); });
    var s={spend:0,impressions:0,clicks:0,link_clicks:0,purchases:0,revenue:0};
    d.forEach(function(x){ s.spend+=x.spend; s.impressions+=x.impressions; s.clicks+=x.clicks; s.link_clicks+=x.link_clicks; s.purchases+=x.purchases; s.revenue+=x.revenue; });
    var f={ spend:Math.round(s.spend), impressions:s.impressions, reach:null, frequency:null,
      clicks:s.clicks, link_clicks:s.link_clicks, purchases:s.purchases, revenue:Math.round(s.revenue),
      ctr:s.impressions?+(s.clicks/s.impressions*100).toFixed(2):0,
      link_ctr:s.impressions?+(s.link_clicks/s.impressions*100).toFixed(2):0,
      cpc_link:s.link_clicks?+(s.spend/s.link_clicks).toFixed(2):0,
      cpm:s.impressions?+(s.spend/s.impressions*1000).toFixed(2):0,
      cvr:s.link_clicks?+(s.purchases/s.link_clicks*100).toFixed(1):0,
      cpa:s.purchases?+(s.spend/s.purchases).toFixed(1):0,
      aov:s.purchases?Math.round(s.revenue/s.purchases):0,
      roas:s.spend?+(s.revenue/s.spend).toFixed(2):0,
      real_roas:s.spend?+(s.revenue/s.spend*0.7).toFixed(2):0 };
    var base=EX.periods[EX.default_period]||{};
    return { funnel:f, deltas:{}, campaigns:base.campaigns, clusters:base.clusters,
             video_vs_static:base.video_vs_static, by_platform:base.by_platform, by_gender:base.by_gender,
             ai:base.ai, window:{since:F.from,until:F.to}, custom:true, days:d.length };
  }
  // ефективна воронка з урахуванням платформа/стать
  function effFunnel(b){
    if(F.platform!=='all'){ var p=(b.by_platform||[]).find(function(x){return x.key===F.platform;}); if(p) return {f:p,seg:'платформа '+F.platform}; }
    if(F.gender!=='all'){ var g=(b.by_gender||[]).find(function(x){return x.key===F.gender;}); if(g) return {f:g,seg:'стать '+(F.gender==='male'?'чол':'жін')}; }
    return {f:b.funnel,seg:null};
  }
  function periodLabel(){
    var m={today:'Сьогодні',yesterday:'Вчора',last_7d:'7 днів',last_30d:'30 днів',this_month:'Цей місяць',this_year:'Цей рік',custom:'Свій діапазон'};
    return m[F.period]||F.period;
  }

  /* ---------- 1. ВОРОНКА ---------- */
  function renderFunnel(el){
    var b=periodObj(); if(!b||!b.funnel){ el.innerHTML=waiting(); return; }
    var ef=effFunnel(b), f=ef.f, d=(ef.seg||b.custom)?{}:(b.deltas||{});
    var win=b.window?((b.window.since||'').slice(5)+'→'+(b.window.until||'').slice(5)):'';
    var h=note('Воронка · <b class="hl">'+periodLabel()+'</b> '+win+(ef.seg?(' · розріз: <b class="hl">'+ef.seg+'</b>'):'')+(b.custom?' · <span style="color:'+C.gold+'">reach/частота недоступні для свого діапазону</span>':'')+'. Дельта — до попереднього періоду.')
      + '<div class="grid k4">'
      + kpi('CPM', f0(f.cpm)+' ₴', delta(d.cpm,true))
      + kpi('CPC (link)', f0(f.cpc_link)+' ₴','')
      + kpi('Link CTR', (f.link_ctr!=null?f.link_ctr+'%':'—'), delta(d.link_ctr))
      + kpi('Частота', (f.frequency!=null?f2(f.frequency):'—'), (f.frequency>4?' <span style="color:'+C.red2+';font-size:11px">висока</span>':''))
      + '</div><div class="grid k4" style="margin-top:13px">'
      + kpi('AOV', f0(f.aov)+' ₴','')
      + kpi('CVR', (f.cvr!=null?f.cvr+'%':'—'),'')
      + kpi('CPA', f2(f.cpa)+' ₴', delta(d.cpa,true))
      + kpi('ROAS pixel / реал', f2(f.roas)+' / '+f2(f.real_roas), delta(d.roas))
      + '</div>'
      + '<div class="chart-card" style="margin-top:13px"><h3 style="margin:2px 0 8px">Покази → Цільові кліки → Покупки</h3><div class="chart-box"><canvas id="exFunnel"></canvas></div>'
      + '<div class="legend"><span>'+f0(f.impressions)+' показів</span><span>'+f0(f.link_clicks)+' кліків ('+(f.link_ctr!=null?f.link_ctr+'%':'—')+')</span><span>'+f0(f.purchases)+' покупок ('+(f.cvr!=null?f.cvr+'%':'—')+')</span></div></div>';
    el.innerHTML=h;
    mk('exFunnel',{ type:'bar', data:{ labels:['Покази','Цільові кліки','Покупки'], datasets:[{ data:[f.impressions,f.link_clicks,f.purchases], backgroundColor:[C.blue,C.gold,C.green], borderRadius:5 }]},
      options:{ maintainAspectRatio:false, indexAxis:'y', scales:{ x:{ type:'logarithmic', grid:gx.grid } },
        plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label:function(c){ return c.parsed.x.toLocaleString('uk-UA'); } } } } } });
  }

  /* ---------- 2. ВТОМА ---------- */
  function renderFatigue(el){
    var b=periodObj(); var cs=b&&b.campaigns; if(!cs||!cs.length){ el.innerHTML=waiting(); return; }
    var mc=thr().min_conv;
    if(F.camptype!=='all') cs=cs.filter(function(c){return c.role===F.camptype;});
    var h=note('Сигнал вигорання: <b class="hl">частота↑ + CTR↓ + CPM/CPC↑</b>, поріг частоти <b>4</b>. Період: <b class="hl">'+periodLabel()+'</b>'+(F.camptype!=='all'?' · тип: '+F.camptype:'')+(b.custom?' · кампанії показано за 30 днів (свій діапазон рахує лише воронку)':'')+'.')
      + '<div class="card scroll"><table><thead><tr><th>Кампанія</th><th>Частота</th><th>CPM ₴</th><th>CPC ₴</th><th>CTR</th><th>Reach</th><th>Покупки</th><th>ROAS</th><th>Стан</th></tr></thead><tbody>';
    if(!cs.length) h+='<tr><td colspan="9" class="empty">Немає кампаній цього типу.</td></tr>';
    cs.forEach(function(c){
      var fr=c.fatigue?'<span class="pill pr">'+f2(c.frequency)+'</span>':(c.frequency>=3?'<span class="pill py">'+f2(c.frequency)+'</span>':'<span class="pill pg">'+f2(c.frequency)+'</span>');
      var st=c.fatigue?'<span class="pill pr">втома</span>':(c.roas<1?'<span class="pill pr">зламана</span>':(c.purchases<mc?'<span class="pill py">мала вибірка</span>':'<span class="pill pg">ок</span>'));
      h+='<tr><td>'+c.name+'</td><td>'+fr+'</td><td>'+f0(c.cpm)+'</td><td>'+f2(c.cpc)+'</td><td>'+f2(c.ctr)+'%</td><td>'+f0(c.reach)+'</td><td>'+f0(c.purchases)+'</td><td>'+roasPill(c.roas)+'</td><td>'+st+'</td></tr>';
    });
    h+='</tbody></table></div><div class="chart-card" style="margin-top:13px"><h3 style="margin:2px 0 8px">Частота по кампаніях (поріг 4)</h3><div class="chart-box"><canvas id="exFreq"></canvas></div></div>';
    el.innerHTML=h;
    var top=cs.slice(0,10);
    mk('exFreq',{ data:{ labels:top.map(function(c){return c.name.length>16?c.name.slice(0,16)+'…':c.name;}),
      datasets:[{ type:'bar', label:'Частота', data:top.map(function(c){return c.frequency;}),
          backgroundColor:top.map(function(c){return c.frequency>4?C.red:(c.frequency>=3?C.gold:C.green);}), borderRadius:4 },
        { type:'line', label:'Поріг 4', data:top.map(function(){return 4;}), borderColor:C.gold, borderDash:[6,4], pointRadius:0, borderWidth:1 }]},
      options:{ maintainAspectRatio:false, plugins:{ legend:{ labels:{ boxWidth:12 } } }, scales:{ y:{ grid:gx.grid, min:0 }, x:{ grid:gx.grid, ticks:{ font:{ size:9 } } } } } });
  }

  /* ---------- 3. ФОРМАТИ (з нормалізацією min-conv) ---------- */
  function renderFormats(el){
    var b=periodObj(); var cl=b&&b.clusters; if(!cl||!cl.length){ el.innerHTML=waiting(); return; }
    var mc=thr().min_conv, vs=b.video_vs_static||{};
    if(F.format==='video') cl=cl.filter(function(c){return c.is_video;});
    else if(F.format==='static') cl=cl.filter(function(c){return !c.is_video;});
    var ranked=cl.filter(function(c){return c.purchases>=mc;});
    var low=cl.filter(function(c){return c.purchases<mc;});
    var h=note('Кластери креативів · <b class="hl">'+periodLabel()+'</b>'+(F.format!=='all'?' · '+(F.format==='video'?'лише відео':'лише статика'):'')+'. <b>Нормалізація:</b> у рейтинг ROAS — лише кластери з <b>≥'+mc+' покупок</b> (решта → «мала вибірка», ROAS орієнтовний).'+(b.custom?' Свій діапазон рахує воронку; кластери — за 30 днів.':''));
    if(vs.video){
      h+='<div class="grid k4" style="margin-bottom:13px">'
        + kpi('Відео ROAS', f2(vs.video.roas),'')
        + kpi('Відео % бюджету', vs.video.pct+'%', (vs.video.pct<20?' <span style="color:'+C.gold+';font-size:11px">недовклад</span>':''))
        + kpi('Статика ROAS', f2(vs.static.roas),'')
        + kpi('Статика % бюджету', vs.static.pct+'%','')+'</div>';
    }
    h+='<div class="card scroll"><table><thead><tr><th>Кластер</th><th>Витрати ₴</th><th>% бюдж.</th><th>ROAS</th><th>Покупки</th><th>CTR</th><th>CPA ₴</th></tr></thead><tbody>';
    ranked.forEach(function(c){ h+='<tr><td>'+c.cluster+'</td><td>'+f0(c.spend)+'</td><td>'+c.spend_pct+'%</td><td>'+roasPill(c.roas)+'</td><td>'+f0(c.purchases)+'</td><td>'+f2(c.ctr)+'%</td><td>'+f0(c.cpa)+'</td></tr>'; });
    low.forEach(function(c){ h+='<tr style="opacity:.55"><td>'+c.cluster+' <span class="pill py">мала вибірка</span></td><td>'+f0(c.spend)+'</td><td>'+c.spend_pct+'%</td><td>'+f2(c.roas)+'</td><td>'+f0(c.purchases)+'</td><td>'+f2(c.ctr)+'%</td><td>'+f0(c.cpa)+'</td></tr>'; });
    h+='</tbody></table></div><div class="chart-card" style="margin-top:13px"><h3 style="margin:2px 0 8px">ROAS по кластерах <span style="color:#888;font-weight:400;font-size:12px">(лише ≥'+mc+' покупок)</span></h3><div class="chart-box"><canvas id="exClus"></canvas></div></div>';
    el.innerHTML=h;
    mk('exClus',{ type:'bar', data:{ labels:ranked.map(function(c){return c.cluster;}), datasets:[{ label:'ROAS', data:ranked.map(function(c){return c.roas;}),
        backgroundColor:ranked.map(function(c){return c.roas>=5?C.green:(c.roas>=3?C.gold:C.red);}), borderRadius:4 }]},
      options:{ maintainAspectRatio:false, indexAxis:'y', plugins:{ legend:{ display:false } }, scales:{ x:{ grid:gx.grid, min:0, title:{ display:true, text:'Pixel ROAS' } }, y:{ grid:gx.grid, ticks:{ font:{ size:10 } } } } } });
  }

  /* ---------- 4. AI ---------- */
  function renderAI(el){
    var b=periodObj(); var ai=b&&b.ai; if(!ai){ el.innerHTML=waiting(); return; }
    var h=note('🤖 <b class="hl">Синтез AI</b> · період <b class="hl">'+periodLabel()+'</b>. '+(ai.summary||''));
    if(ai.priorities&&ai.priorities.length){
      h+='<div class="card scroll" style="margin-top:11px"><h3 style="margin:2px 0 8px">Матриця пріоритетів (impact × зусилля)</h3><table><thead><tr><th>#</th><th>Дія</th><th>Чому</th><th>Impact</th><th>Зусилля</th><th>Очік.</th></tr></thead><tbody>';
      ai.priorities.forEach(function(p){ var ip=p.impact==='Високий'?'pg':(p.impact==='Середній'?'py':'pr');
        h+='<tr><td>'+p.id+'</td><td>'+p.action+'</td><td style="color:#aaa;font-size:12px">'+p.why+'</td><td><span class="pill '+ip+'">'+p.impact+'</span></td><td>'+p.effort+'</td><td style="color:'+C.green+'">'+p.expect+'</td></tr>'; });
      h+='</tbody></table></div>';
    }
    h+='<h3 style="margin:16px 0 8px">🚨 Авто-алерти</h3>';
    if(ai.alerts&&ai.alerts.length){ ai.alerts.forEach(function(a){ var sev=a.sev==='cri'?'cri':(a.sev==='mod'?'mod':'inf'); var mk2=a.sev==='cri'?'🔴':(a.sev==='mod'?'🟡':'ℹ️'); h+='<div class="rec '+sev+'">'+mk2+' '+a.text+'</div>'; }); }
    else h+='<div class="rec inf">Критичних алертів немає. 👌</div>';
    el.innerHTML=h;
  }

  /* ---------- фільтр-бар ---------- */
  var grp=null;
  function sel(id,label,opts,val){
    var o=opts.map(function(o){ return '<option value="'+o[0]+'"'+(o[0]===val?' selected':'')+'>'+o[1]+'</option>'; }).join('');
    return '<label for="'+id+'" style="margin-left:8px">'+label+'</label><select id="'+id+'" style="font-size:12.5px;padding:6px 9px">'+o+'</select>';
  }
  function buildBar(){
    grp=document.createElement('span'); grp.id='ex-filters';
    grp.style.cssText='display:none;align-items:center;gap:8px;flex-wrap:wrap;border-left:1px solid #2a2a2a;margin-left:6px;padding-left:10px';
    grp.innerHTML=
      sel('exPeriod','Період',[['today','Сьогодні'],['yesterday','Вчора'],['last_7d','7 днів'],['last_30d','30 днів'],['this_month','Місяць'],['this_year','Рік'],['custom','Свій…']],F.period)
      +'<span id="exCustom" style="display:none;gap:6px;align-items:center"><input type="date" id="exFrom" style="background:#141414;border:1px solid #2a2a2a;color:#fff;border-radius:7px;padding:5px 7px;font-size:12px"><input type="date" id="exTo" style="background:#141414;border:1px solid #2a2a2a;color:#fff;border-radius:7px;padding:5px 7px;font-size:12px"></span>'
      +sel('exPlat','Платф.',[['all','Усі'],['facebook','Facebook'],['instagram','Instagram']],F.platform)
      +sel('exGen','Стать',[['all','Усі'],['male','Чол'],['female','Жін']],F.gender)
      +sel('exType','Тип',[['all','Усі'],['core','Broad'],['acquisition','Нові'],['retarget','Ретаргет'],['prospecting','Prospecting']],F.camptype)
      +sel('exFmt','Формат',[['all','Усі'],['video','Відео'],['static','Статика']],F.format)
      +sel('exMin','Мін.конв.',[['1','1'],['3','3'],['5','5'],['10','10']],String(F.minConv));
    var bar=document.querySelector('.filters'); if(bar) bar.appendChild(grp);
    function onChange(){
      F.period=val('exPeriod'); F.platform=val('exPlat'); F.gender=val('exGen');
      F.camptype=val('exType'); F.format=val('exFmt'); F.minConv=+val('exMin');
      document.getElementById('exCustom').style.display = F.period==='custom'?'inline-flex':'none';
      if(F.period==='custom'){ F.from=val('exFrom'); F.to=val('exTo'); if(!F.from||!F.to) return; }
      ensureTab(); rerender();
    }
    function val(id){ var e=document.getElementById(id); return e?e.value:''; }
    grp.addEventListener('change', onChange); // одна делегація — події спливають із дочірніх select/input
  }
  function showBar(on){ if(grp) grp.style.display = on?'inline-flex':'none'; }

  /* ---------- таб-логіка ---------- */
  function curTabEl(){ var on=document.querySelector('.tabc.on'); return (on&&/^tab-x/.test(on.id))?on:null; }
  function rerender(){ var el=curTabEl(); if(!el) return; var t=TABS.find(function(x){return 'tab-'+x.id===el.id;}); if(t) t.fn(el); }
  function ensureTab(){ if(!curTabEl()) activate(TABS[0]); }
  function activate(tab){
    document.querySelectorAll('.tb').forEach(function(x){ x.classList.remove('on'); });
    document.querySelectorAll('.tabc').forEach(function(x){ x.classList.remove('on'); });
    document.getElementById('btn-'+tab.id).classList.add('on');
    document.getElementById('tab-'+tab.id).classList.add('on');
    showBar(true); tab.fn(document.getElementById('tab-'+tab.id));
    try{ window.scrollTo({ top:0, behavior:'smooth' }); }catch(e){}
  }

  function boot(){
    var tabsBar=document.querySelector('.tabs'), wrap=document.querySelector('.wrap'), foot=document.getElementById('foot');
    if(!tabsBar||!wrap) return;
    TABS.forEach(function(t){
      var b=document.createElement('button'); b.className='tb'; b.id='btn-'+t.id; b.textContent=t.label;
      b.addEventListener('click', function(){ activate(t); }); tabsBar.appendChild(b);
      var d=document.createElement('div'); d.className='tabc'; d.id='tab-'+t.id;
      if(foot) wrap.insertBefore(d,foot); else wrap.appendChild(d);
    });
    // приховувати фільтр-бар на оригінальних табах
    document.querySelectorAll('.tabs .tb').forEach(function(btn){
      if(btn.dataset && btn.dataset.tab){ btn.addEventListener('click', function(){ showBar(false); }); }
    });
    buildBar();
    fetch('meta-extra.json?_='+Date.now()).then(function(r){ if(!r.ok) throw 0; return r.json(); })
      .then(function(j){ EX=j; if(EX.default_period) F.period=EX.default_period; var s=document.getElementById('exPeriod'); if(s) s.value=F.period; rerender(); })
      .catch(function(){});
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',boot); else boot();
})();
