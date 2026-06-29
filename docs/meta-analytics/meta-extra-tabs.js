/* meta-extra-tabs.js — 4 додаткові таби для /meta-analytics/ (воронка, втома, формати, AI).
   Самостійний: інжектить власні кнопки/контейнери, читає meta-extra.json (окремо від data.json).
   Не залежить від внутрішнього RENDER сторінки; падіння файлу = таби показують "збираються дані". */
(function () {
  'use strict';
  var C = { red:'#E30613', red2:'#FF6A7A', gold:'#FBBF24', green:'#10B981', blue:'#60A5FA', grey:'#888' };
  var EX = null, charts = {};
  var TABS = [
    { id:'xfunnel',  label:'🔻 Воронка',  fn:renderFunnel },
    { id:'xfatigue', label:'🔥 Втома',    fn:renderFatigue },
    { id:'xformats', label:'🎨 Формати',  fn:renderFormats },
    { id:'xai',      label:'🤖 AI-пріоритети', fn:renderAI }
  ];
  var f0 = function (n) { return (n==null||isNaN(n)) ? '—' : Math.round(n).toLocaleString('uk-UA'); };
  var f2 = function (n) { return (n==null||isNaN(n)) ? '—' : Number(n).toFixed(2); };
  function roasPill(v){ var c=v>=5?'pg':v>=3?'py':'pr'; return '<span class="pill '+c+'">'+f2(v)+'</span>'; }
  function delta(v, invert){ if(v==null) return ''; var good = invert ? v<0 : v>=0; var col = good?C.green:C.red2;
    return ' <span style="font-size:11px;color:'+col+'">'+(v>=0?'▲':'▼')+Math.abs(v).toFixed(0)+'%</span>'; }
  function mk(id, cfg){ var el=document.getElementById(id); if(!el||!window.Chart) return; if(charts[id]) charts[id].destroy(); charts[id]=new Chart(el,cfg); }
  var gx = { grid:{ color:'#1c1c1c' } };

  function waiting(msg){ return '<div class="note" style="margin-top:6px">'+(msg||'Дані ще збираються — перший запуск ETL <b>meta-extra-sync</b> наповнить ці таби (оновлюється щодня о 08:40 Київ).')+'</div>'; }

  /* ---- 1. ВОРОНКА ---- */
  function renderFunnel(el){
    if(!EX || !EX.funnel){ el.innerHTML = waiting(); return; }
    var f = EX.funnel, d = EX.deltas || {};
    var win = EX.window ? (EX.window.since.slice(5)+'→'+EX.window.until.slice(5)+' ('+EX.window.days+'д)') : '';
    var h = '<div class="note">Воронка та ефективність доставки по акаунту · вікно <b class="hl">'+win+'</b>. Дельта — до попереднього такого ж періоду.</div>'
      + '<div class="grid k4">'
      + kpi('CPM', f0(f.cpm)+' ₴', delta(d.cpm,true))
      + kpi('CPC (link)', f0(f.cpc_link)+' ₴', '')
      + kpi('Link CTR', f.link_ctr+'%', delta(d.link_ctr))
      + kpi('Частота', f2(f.frequency), (f.frequency>4?' <span style="color:'+C.red2+';font-size:11px">висока</span>':''))
      + '</div>'
      + '<div class="grid k4" style="margin-top:13px">'
      + kpi('AOV (сер. чек)', f0(f.aov)+' ₴', '')
      + kpi('CVR (клік→покуп)', f.cvr+'%', '')
      + kpi('CPA', f2(f.cpa)+' ₴', delta(d.cpa,true))
      + kpi('ROAS pixel / реал', f2(f.roas)+' / '+f2(f.real_roas), delta(d.roas))
      + '</div>'
      + '<div class="chart-card" style="margin-top:13px"><h3 style="margin:2px 0 8px">Покази → Цільові кліки → Покупки</h3><div class="chart-box"><canvas id="exFunnel"></canvas></div>'
      + '<div class="legend"><span>'+f0(f.impressions)+' показів</span><span>'+f0(f.link_clicks)+' кліків (Link CTR '+f.link_ctr+'%)</span><span>'+f0(f.purchases)+' покупок (CVR '+f.cvr+'%)</span></div></div>';
    el.innerHTML = h;
    mk('exFunnel', { type:'bar',
      data:{ labels:['Покази','Цільові кліки','Покупки'], datasets:[{ label:'к-сть',
        data:[f.impressions, f.link_clicks, f.purchases], backgroundColor:[C.blue,C.gold,C.green], borderRadius:5 }]},
      options:{ maintainAspectRatio:false, indexAxis:'y',
        scales:{ x:{ type:'logarithmic', grid:gx.grid } },
        plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label:function(c){ return c.parsed.x.toLocaleString('uk-UA'); } } } } } });
  }

  /* ---- 2. ВТОМА ДОСТАВКИ ---- */
  function renderFatigue(el){
    if(!EX || !EX.campaigns || !EX.campaigns.length){ el.innerHTML = waiting(); return; }
    var cs = EX.campaigns;
    var h = '<div class="note">Сигнал вигорання (best practice): <b class="hl">частота↑ + CTR↓ + CPM/CPC↑</b>. Поріг частоти — <b>4</b>. Дані з Meta API за вікно.</div>'
      + '<div class="card scroll"><table><thead><tr><th>Кампанія</th><th>Частота</th><th>CPM ₴</th><th>CPC ₴</th><th>CTR</th><th>Reach</th><th>Покупки</th><th>ROAS</th><th>Стан</th></tr></thead><tbody>';
    cs.forEach(function(c){
      var fr = c.fatigue ? '<span class="pill pr">'+f2(c.frequency)+'</span>' : (c.frequency>=3?'<span class="pill py">'+f2(c.frequency)+'</span>':'<span class="pill pg">'+f2(c.frequency)+'</span>');
      var st = c.fatigue ? '<span class="pill pr">втома</span>' : (c.roas<1?'<span class="pill pr">зламана</span>':'<span class="pill pg">ок</span>');
      h += '<tr><td>'+c.name+'</td><td>'+fr+'</td><td>'+f0(c.cpm)+'</td><td>'+f2(c.cpc)+'</td><td>'+f2(c.ctr)+'%</td><td>'+f0(c.reach)+'</td><td>'+f0(c.purchases)+'</td><td>'+roasPill(c.roas)+'</td><td>'+st+'</td></tr>';
    });
    h += '</tbody></table></div><div class="chart-card" style="margin-top:13px"><h3 style="margin:2px 0 8px">Частота по кампаніях (поріг 4)</h3><div class="chart-box"><canvas id="exFreq"></canvas></div></div>';
    el.innerHTML = h;
    var top = cs.slice(0,10);
    mk('exFreq', { data:{ labels:top.map(function(c){ return c.name.length>16?c.name.slice(0,16)+'…':c.name; }),
      datasets:[
        { type:'bar', label:'Частота', data:top.map(function(c){ return c.frequency; }),
          backgroundColor:top.map(function(c){ return c.frequency>4?C.red:(c.frequency>=3?C.gold:C.green); }), borderRadius:4 },
        { type:'line', label:'Поріг 4', data:top.map(function(){ return 4; }), borderColor:C.gold, borderDash:[6,4], pointRadius:0, borderWidth:1 }
      ]},
      options:{ maintainAspectRatio:false, plugins:{ legend:{ labels:{ boxWidth:12 } } },
        scales:{ y:{ grid:gx.grid, min:0 }, x:{ grid:gx.grid, ticks:{ font:{ size:9 } } } } } });
  }

  /* ---- 3. КЛАСТЕРИ ФОРМАТІВ ---- */
  function renderFormats(el){
    if(!EX || !EX.clusters || !EX.clusters.length){ el.innerHTML = waiting(); return; }
    var cl = EX.clusters, vs = EX.video_vs_static || {};
    var h = '<div class="note">Кластери креативів за патерном назв (відео / KTM-мото / iPhone / Mustang / Картинки / тариф). Допомагає бачити, який <b>контент і формат</b> несе ROAS.</div>';
    if(vs.video){
      h += '<div class="grid k4" style="margin-bottom:13px">'
        + kpi('Відео ROAS', f2(vs.video.roas), '')
        + kpi('Відео % бюджету', vs.video.pct+'%', (vs.video.pct<20?' <span style="color:'+C.gold+';font-size:11px">недовклад</span>':''))
        + kpi('Статика ROAS', f2(vs.static.roas), '')
        + kpi('Статика % бюджету', vs.static.pct+'%', '')
        + '</div>';
    }
    h += '<div class="card scroll"><table><thead><tr><th>Кластер</th><th>Витрати ₴</th><th>% бюдж.</th><th>ROAS</th><th>Покупки</th><th>CTR</th><th>CPA ₴</th></tr></thead><tbody>';
    cl.forEach(function(c){
      h += '<tr><td>'+c.cluster+'</td><td>'+f0(c.spend)+'</td><td>'+c.spend_pct+'%</td><td>'+roasPill(c.roas)+'</td><td>'+f0(c.purchases)+'</td><td>'+f2(c.ctr)+'%</td><td>'+f0(c.cpa)+'</td></tr>';
    });
    h += '</tbody></table></div><div class="chart-card" style="margin-top:13px"><h3 style="margin:2px 0 8px">ROAS × частка бюджету по кластерах</h3><div class="chart-box"><canvas id="exClus"></canvas></div></div>';
    el.innerHTML = h;
    mk('exClus', { type:'bar',
      data:{ labels:cl.map(function(c){ return c.cluster; }), datasets:[{ label:'ROAS',
        data:cl.map(function(c){ return c.roas; }),
        backgroundColor:cl.map(function(c){ return c.roas>=5?C.green:(c.roas>=3?C.gold:C.red); }), borderRadius:4 }]},
      options:{ maintainAspectRatio:false, indexAxis:'y',
        plugins:{ legend:{ display:false } }, scales:{ x:{ grid:gx.grid, min:0, title:{ display:true, text:'Pixel ROAS' } }, y:{ grid:gx.grid, ticks:{ font:{ size:10 } } } } } });
  }

  /* ---- 4. AI-ПРІОРИТЕТИ ---- */
  function renderAI(el){
    if(!EX || !EX.ai){ el.innerHTML = waiting(); return; }
    var ai = EX.ai;
    var h = '';
    if(ai.summary){ h += '<div class="note" style="border-color:rgba(96,165,250,.35)"><b class="hl">🤖 Синтез AI.</b> '+ai.summary+'</div>'; }
    if(ai.priorities && ai.priorities.length){
      h += '<div class="card scroll" style="margin-top:11px"><h3 style="margin:2px 0 8px">Матриця пріоритетів (impact × зусилля)</h3><table><thead><tr><th>#</th><th>Дія</th><th>Чому</th><th>Impact</th><th>Зусилля</th><th>Очік.</th></tr></thead><tbody>';
      ai.priorities.forEach(function(p){
        var ip = p.impact==='Високий'?'pg':(p.impact==='Середній'?'py':'pr');
        h += '<tr><td>'+p.id+'</td><td>'+p.action+'</td><td style="color:#aaa;font-size:12px">'+p.why+'</td><td><span class="pill '+ip+'">'+p.impact+'</span></td><td>'+p.effort+'</td><td style="color:'+C.green+'">'+p.expect+'</td></tr>';
      });
      h += '</tbody></table></div>';
    }
    h += '<h3 style="margin:16px 0 8px">🚨 Авто-алерти</h3>';
    if(ai.alerts && ai.alerts.length){
      ai.alerts.forEach(function(a){
        var sev = a.sev==='cri'?'cri':(a.sev==='mod'?'mod':'inf');
        var mark = a.sev==='cri'?'🔴':(a.sev==='mod'?'🟡':'ℹ️');
        h += '<div class="rec '+sev+'">'+mark+' '+a.text+'</div>';
      });
    } else { h += '<div class="rec inf">Критичних алертів немає — показники в нормі. 👌</div>'; }
    el.innerHTML = h;
  }

  function kpi(lab, val, extra){
    return '<div class="card kpi"><div class="lab">'+lab+'</div><div class="val">'+val+(extra||'')+'</div></div>';
  }

  /* ---- bootstrap: інжект кнопок + контейнерів, кліки ---- */
  function activate(tab){
    document.querySelectorAll('.tb').forEach(function(x){ x.classList.remove('on'); });
    document.querySelectorAll('.tabc').forEach(function(x){ x.classList.remove('on'); });
    document.getElementById('btn-'+tab.id).classList.add('on');
    var c = document.getElementById('tab-'+tab.id);
    c.classList.add('on');
    tab.fn(c);
    try { window.scrollTo({ top:0, behavior:'smooth' }); } catch(e){}
  }

  function boot(){
    var tabsBar = document.querySelector('.tabs');
    var wrap = document.querySelector('.wrap');
    var foot = document.getElementById('foot');
    if(!tabsBar || !wrap){ return; }
    TABS.forEach(function(t){
      var b = document.createElement('button');
      b.className = 'tb'; b.id = 'btn-'+t.id; b.textContent = t.label;
      b.addEventListener('click', function(){ activate(t); });
      tabsBar.appendChild(b);
      var d = document.createElement('div');
      d.className = 'tabc'; d.id = 'tab-'+t.id;
      if(foot) wrap.insertBefore(d, foot); else wrap.appendChild(d);
    });
    fetch('meta-extra.json?_=' + Date.now())
      .then(function(r){ if(!r.ok) throw new Error(r.status); return r.json(); })
      .then(function(j){ EX = j;
        var on = document.querySelector('.tabc.on');
        if(on && /^tab-x/.test(on.id)){ var t = TABS.find(function(x){ return 'tab-'+x.id===on.id; }); if(t) t.fn(on); }
      })
      .catch(function(){ /* файлу ще нема — таби покажуть "збираються дані" */ });
  }

  if(document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', boot); }
  else { boot(); }
})();
