/* ============================================================
   DreamCar · /pricing-analysis/ · live P&L → entry-token аналіз
   Source: dashboard_project_pnl_cached → fallback dashboard_project_pnl
   Role-gate: CEO / CFO / COO (як /finance/)
   ============================================================ */
(function(){
'use strict';

// ---- Entry-price mapping (хардкод як просили) ----
// name → entry-ціна (₴). Підтримуємо кілька варіантів імен з launches.
const ENTRY_PRICES = {
  'VOLVO XC90': 199,
  'MERCEDES GLE COUPE': 199,
  'BMW 330E HYBRID': 199,
  'Ford Mustang Convertible 2017 (#19)': 199,
  'Ford Mustang': 199,
  'FORD MUSTANG': 199,
  'AUDI E-TRON 2026': 249,
  'AUDI E-TRON': 249,
  'BMW X5 Hybrid #17': 249,
  'BMW X5 HYBRID': 249,
  'BMW X5': 249,
  'AUDI Q7': 199, // ?
  'Мото': 99,
  'МОТО': 99,
  'MOTO': 99,
  'MOTORCYCLE': 99,
  'iPhone 17 PRO MAX': 49,
  'IPHONE 17 PRO MAX': 49,
  'IPHONE': 49,
};

// fuzzy: підбираємо запис по name за case-insensitive contains.
function resolveEntryPrice(projectName){
  if (!projectName) return null;
  const n = String(projectName).trim();
  if (ENTRY_PRICES[n] != null) return ENTRY_PRICES[n];
  const up = n.toUpperCase();
  if (ENTRY_PRICES[up] != null) return ENTRY_PRICES[up];
  // contains-search
  for (const k of Object.keys(ENTRY_PRICES)){
    if (up.includes(k.toUpperCase()) || k.toUpperCase().includes(up)){
      return ENTRY_PRICES[k];
    }
  }
  // евристика по типу проекту
  if (/IPHONE|АЙФОН|ГАДЖЕТ/i.test(n)) return 49;
  if (/МОТО|МОТОЦИКЛ|MOTO/i.test(n)) return 99;
  if (/X5|E-TRON|ETRON/i.test(n)) return 249;
  if (/AUDI|BMW|MERCEDES|VOLVO|FORD|MUSTANG/i.test(n)) return 199;
  return null;
}

// ---- Supabase ----
let sb = null;
async function getSb(){
  if (sb) return sb;
  if (window.supabase && window.supabase.auth) { sb = window.supabase; return sb; }
  await new Promise(res => {
    let tries = 0;
    const it = setInterval(() => {
      if (window.supabase && window.supabase.auth){ clearInterval(it); res(); }
      else if (++tries > 50){ clearInterval(it); res(); }
    }, 100);
  });
  sb = window.supabase;
  return sb;
}

// ---- Role gate (skopiyovano z /finance/) ----
const ALLOWED_ROLES = ['ceo','cfo','coo'];
const EMAIL_ALIAS = {
  'vg@abrisart.com': 'ceo',
  'dreamcarua@gmail.com': 'ceo',
  '1avrybak@gmail.com': 'cfo',
  'smth.mario@gmail.com': 'coo',
};
async function checkAccess(){
  try{
    await getSb();
    if (!sb || !sb.auth) return { ok:false, reason:'no_supabase' };
    const { data: { user } } = await sb.auth.getUser();
    if (!user) return { ok:false, reason:'no_user' };
    const { data: u } = await sb
      .from('users')
      .select('id,name,role,email,is_active,auth_id_aliases')
      .or(`auth_id.eq.${user.id},auth_id_aliases.cs.{${user.id}}`)
      .maybeSingle();
    if (u && u.is_active && ALLOWED_ROLES.includes(String(u.role))) return { ok:true, user:u };
    const email = (user.email||'').toLowerCase();
    if (email){
      const { data: u2 } = await sb.from('users').select('id,name,role,is_active').ilike('email', email).maybeSingle();
      if (u2 && u2.is_active && ALLOWED_ROLES.includes(String(u2.role))) return { ok:true, user:u2 };
    }
    if (email && EMAIL_ALIAS[email]) return { ok:true, user:{ name:'alias', email, role:EMAIL_ALIAS[email] } };
    return { ok:false, reason:'role_denied', email:user.email, name:u?.name, role:u?.role };
  }catch(e){ console.error('[pricing access]', e); return { ok:false, reason:'error', error:String(e?.message||e) }; }
}
function showDenied(info){
  document.body.innerHTML = `
    <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:'Manrope',sans-serif;background:#0a0a0a;color:#e5e7eb;padding:40px">
      <div style="max-width:520px;text-align:center;background:#141414;border:1px solid #2a2a2a;border-radius:14px;padding:40px 32px">
        <div style="font-size:48px;margin-bottom:12px">🔒</div>
        <h1 style="font-family:'Oswald';letter-spacing:.06em;color:#E30613;margin:0 0 10px;font-size:22px">ДОСТУП ОБМЕЖЕНО</h1>
        <p style="color:#a1a1aa;margin:8px 0 16px;font-size:14px;line-height:1.55">
          Розділ <b>/pricing-analysis/</b> доступний лише для:<br>
          <span style="color:#fbbf24">CEO · CFO · COO</span>
        </p>
        <div style="background:#0a0a0a;border:1px solid #2a2a2a;border-radius:8px;padding:14px;font-family:'JetBrains Mono',monospace;font-size:12px;color:#71717a;text-align:left">
          <div>Користувач: <span style="color:#a3a3a3">${(info.name||info.email||'—')}</span></div>
          <div>Роль: <span style="color:${info.role?'#fbbf24':'#71717a'}">${(info.role||'—')}</span></div>
          <div>Причина: <span style="color:#ef4444">${info.reason||'unknown'}</span></div>
        </div>
        <a href="/" style="display:inline-block;margin-top:20px;padding:10px 22px;background:#E30613;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px">← На головну дашборду</a>
      </div>
    </div>`;
}

// ---- Format helpers ----
const fmtUAH = (n) => {
  if (n == null || isNaN(n)) return '—';
  const v = Math.round(Number(n));
  return new Intl.NumberFormat('uk-UA').format(v) + ' ₴';
};
const fmtShort = (n) => {
  if (n == null || isNaN(n)) return '—';
  const v = Number(n);
  if (Math.abs(v) >= 1_000_000) return (v/1_000_000).toFixed(1)+'M ₴';
  if (Math.abs(v) >= 1_000) return (v/1_000).toFixed(0)+'k ₴';
  return Math.round(v) + ' ₴';
};
const fmtPct = (n) => (n == null || isNaN(n)) ? '—' : (Number(n).toFixed(0) + '%');
const fmtInt = (n) => (n == null || isNaN(n)) ? '—' : new Intl.NumberFormat('uk-UA').format(Math.round(Number(n)));
const esc = (s) => String(s||'').replace(/[<>&"']/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'}[c]));

function daysBetween(a, b){
  if (!a) return null;
  const s = new Date(a);
  const e = new Date(b || new Date());
  if (isNaN(s) || isNaN(e)) return null;
  return Math.max(1, Math.round((e - s) / 86400000));
}

// ---- Data fetching ----
async function fetchPnl(){
  await getSb();
  try{
    const { data: c, error: ec } = await sb.rpc('dashboard_project_pnl_cached');
    if (!ec && c?.[0]?.data){
      return { rows: Array.isArray(c[0].data) ? c[0].data : [], refreshedAt: c[0].refreshed_at, source: 'mv' };
    }
    console.warn('[pricing] cached failed', ec?.message);
  }catch(e){ console.warn('[pricing] cached err', e); }
  const { data, error } = await sb.rpc('dashboard_project_pnl');
  if (error){ console.error('[pricing] live rpc err', error); return { rows: [], refreshedAt: null, source: 'error' }; }
  return { rows: data || [], refreshedAt: new Date().toISOString(), source: 'live' };
}

// ---- Project rows enrichment ----
function enrich(rows){
  return rows
    .filter(r => Number(r.revenue_total||0) > 0) // тільки з виручкою
    .map(r => {
      const days = daysBetween(r.starts_on, r.ends_on);
      const entry = resolveEntryPrice(r.name);
      const netProfit = Number(r.true_net_profit ?? r.net_profit ?? 0);
      const totalCost = Number(r.total_cost || 0);
      const revenue = Number(r.revenue_total || 0);
      const adSpend = Number(r.ad_spend || 0);
      const paid = Number(r.paid_count || 0);
      const margin = revenue > 0 ? (netProfit / revenue) * 100 : null;
      const roi = totalCost > 0 ? (netProfit / totalCost) * 100 : null;
      const cac = paid > 0 ? adSpend / paid : null;
      const aov = paid > 0 ? revenue / paid : null;
      const netDay = (days && days > 0) ? netProfit / days : null;
      return {
        name: r.name || '—',
        status: r.status,
        starts_on: r.starts_on,
        ends_on: r.ends_on,
        entry,
        days,
        revenue,
        adSpend,
        netProfit,
        totalCost,
        margin,
        roi,
        cac,
        aov,
        paid,
        netDay,
        color: r.color,
      };
    });
}

// ---- KPI ----
function renderKpi(projects){
  const n = projects.length;
  const avg = (key) => {
    const vals = projects.map(p => Number(p[key])).filter(v => !isNaN(v) && isFinite(v));
    return vals.length ? vals.reduce((s,v)=>s+v,0) / vals.length : null;
  };
  document.getElementById('k-avgnetday').innerHTML = avg('netDay') == null ? '—' : fmtShort(avg('netDay'));
  document.getElementById('s-avgnetday').textContent = `по ${n} проєктах`;
  document.getElementById('k-avgmargin').innerHTML = fmtPct(avg('margin'));
  document.getElementById('k-avgroi').innerHTML = fmtPct(avg('roi'));
  document.getElementById('k-count').textContent = String(n);
}

// ---- Table ----
let _sortKey = 'netday', _sortDir = -1; // -1 desc, +1 asc
let _projects = [];

function sortRows(rows){
  const k = _sortKey;
  return [...rows].sort((a,b)=>{
    let va, vb;
    switch(k){
      case 'name':     va=a.name||''; vb=b.name||''; return va.localeCompare(vb,'uk') * _sortDir;
      case 'entry':    va=a.entry??-1; vb=b.entry??-1; break;
      case 'days':     va=a.days??0; vb=b.days??0; break;
      case 'revenue':  va=a.revenue; vb=b.revenue; break;
      case 'adspend':  va=a.adSpend; vb=b.adSpend; break;
      case 'netprofit':va=a.netProfit; vb=b.netProfit; break;
      case 'netday':   va=a.netDay??-Infinity; vb=b.netDay??-Infinity; break;
      case 'margin':   va=a.margin??-Infinity; vb=b.margin??-Infinity; break;
      case 'roi':      va=a.roi??-Infinity; vb=b.roi??-Infinity; break;
      case 'cac':      va=a.cac??Infinity; vb=b.cac??Infinity; break;
      case 'aov':      va=a.aov??-Infinity; vb=b.aov??-Infinity; break;
      case 'paid':     va=a.paid; vb=b.paid; break;
      default:         va=0; vb=0;
    }
    return (va - vb) * _sortDir;
  });
}

function renderTable(){
  const rows = sortRows(_projects);
  const tbody = document.querySelector('#tblProjects tbody');
  if (!rows.length){
    tbody.innerHTML = '<tr><td colspan="12" class="empty">Немає даних. Перевір mv_dashboard_project_pnl.</td></tr>';
    return;
  }
  const cls = (entry) => entry === 199 ? 'e199' : entry === 249 ? 'e249' : entry === 99 ? 'e99' : entry === 49 ? 'e49' : 'eunknown';
  const rowCls = (entry) => entry === 199 ? 'row-e199' : entry === 249 ? 'row-e249' : entry === 99 ? 'row-e99' : entry === 49 ? 'row-e49' : '';
  tbody.innerHTML = rows.map(p => `
    <tr class="${rowCls(p.entry)}">
      <td>
        <div><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:${p.color||'#888'};margin-right:8px;vertical-align:middle"></span>${esc(p.name)}</div>
        <div style="font-size:10.5px;color:#6B7280;font-family:'JetBrains Mono',monospace;margin-top:2px">${esc(p.status||'')} · ${p.starts_on?p.starts_on.slice(0,10):'?'} → ${p.ends_on?p.ends_on.slice(0,10):'?'}</div>
      </td>
      <td><span class="entry-pill ${cls(p.entry)}">${p.entry != null ? p.entry : '?'}</span></td>
      <td>${p.days || '—'}</td>
      <td>${fmtShort(p.revenue)}</td>
      <td>${fmtShort(p.adSpend)}</td>
      <td class="${p.netProfit<0?'neg':'pos'}">${fmtShort(p.netProfit)}</td>
      <td class="${(p.netDay||0)<0?'neg':'pos'}"><b>${fmtShort(p.netDay)}</b></td>
      <td class="${(p.margin||0)<0?'neg':'pos'}">${fmtPct(p.margin)}</td>
      <td class="${(p.roi||0)<0?'neg':'pos'}">${fmtPct(p.roi)}</td>
      <td>${fmtShort(p.cac)}</td>
      <td>${fmtShort(p.aov)}</td>
      <td>${fmtInt(p.paid)}</td>
    </tr>
  `).join('');
}

function bindSort(){
  document.querySelectorAll('#tblProjects thead th.sortable').forEach(th => {
    th.addEventListener('click', () => {
      const k = th.dataset.sort;
      if (_sortKey === k) _sortDir *= -1; else { _sortKey = k; _sortDir = (k === 'name' ? 1 : -1); }
      document.querySelectorAll('#tblProjects thead th').forEach(x => x.classList.remove('sorted'));
      th.classList.add('sorted');
      renderTable();
    });
  });
}

// ---- Compare 199 vs 249 ----
function avgOf(arr, key){
  const vals = arr.map(x => Number(x[key])).filter(v => !isNaN(v) && isFinite(v));
  return vals.length ? vals.reduce((s,v)=>s+v,0) / vals.length : null;
}
function renderCompare(){
  const p199 = _projects.filter(p => p.entry === 199);
  const p249 = _projects.filter(p => p.entry === 249);
  const fill = (prefix, arr) => {
    document.getElementById(prefix+'-margin').innerHTML = fmtPct(avgOf(arr,'margin'));
    document.getElementById(prefix+'-roi').innerHTML = fmtPct(avgOf(arr,'roi'));
    document.getElementById(prefix+'-netday').innerHTML = fmtShort(avgOf(arr,'netDay'));
    document.getElementById(prefix+'-cac').innerHTML = fmtShort(avgOf(arr,'cac'));
    document.getElementById(prefix+'-aov').innerHTML = fmtShort(avgOf(arr,'aov'));
    document.getElementById(prefix+'-cnt').textContent = arr.length;
  };
  fill('c199', p199);
  fill('c249', p249);

  // verdict
  const big = document.getElementById('verdictBig');
  const txt = document.getElementById('verdictText');
  const card199 = document.getElementById('card199');
  const card249 = document.getElementById('card249');
  if (!p199.length && !p249.length){
    big.textContent = 'Недостатньо даних'; txt.textContent = 'Немає проєктів з мапнутою entry-ціною 199 або 249 ₴.';
    return;
  }
  if (!p249.length){
    big.textContent = '199 ₴ · default';
    txt.textContent = `Немає проєктів з 249 ₴ для порівняння. 199 ₴ — підтверджений default на ${p199.length} проєктах.`;
    card199.classList.add('win'); card249.classList.remove('win','lose');
    return;
  }
  if (!p199.length){
    big.textContent = '249 ₴ · поки немає 199 для порівняння'; txt.textContent='';
    return;
  }
  const m199 = avgOf(p199,'margin'), m249 = avgOf(p249,'margin');
  const r199 = avgOf(p199,'roi'),    r249 = avgOf(p249,'roi');
  const winner = (m199||0) > (m249||0) ? 199 : 249;
  const dM = ((m199||0) - (m249||0));
  const dR = ((r199||0) - (r249||0));
  if (winner === 199){
    card199.classList.add('win'); card199.classList.remove('lose');
    card249.classList.add('lose'); card249.classList.remove('win');
    big.textContent = `199 ₴ виграє: +${Math.round(dM)} пп маржі · +${Math.round(dR)} пп ROI`;
    txt.innerHTML = `Avg маржа 199: <b>${fmtPct(m199)}</b> vs 249: <b>${fmtPct(m249)}</b>. Avg ROI 199: <b>${fmtPct(r199)}</b> vs 249: <b>${fmtPct(r249)}</b>. На premium-сегменті 199 ₴ — підтверджений default.`;
  } else {
    card249.classList.add('win'); card249.classList.remove('lose');
    card199.classList.add('lose'); card199.classList.remove('win');
    big.textContent = `249 ₴ виграє: +${Math.round(-dM)} пп маржі · +${Math.round(-dR)} пп ROI`;
    txt.innerHTML = `Avg маржа 249: <b>${fmtPct(m249)}</b> vs 199: <b>${fmtPct(m199)}</b>. ⚠️ Несподіваний результат — варто додатково перевірити вибірку.`;
  }
}

// ---- Flash cards (49/99) ----
function renderFlash(){
  const p49 = _projects.filter(p => p.entry === 49);
  const p99 = _projects.filter(p => p.entry === 99);
  const card = (arr) => {
    if (!arr.length) return '<div class="muted" style="padding:10px 0;font-size:12px">Поки немає проєктів цієї цінової категорії.</div>';
    return arr.map(p => `
      <div class="fc-metric"><span class="k">Проєкт</span><span class="v" style="font-family:'Manrope',sans-serif;font-size:12px">${esc(p.name)}</span></div>
      <div class="fc-metric"><span class="k">Тривалість</span><span class="v">${p.days||'?'} дн</span></div>
      <div class="fc-metric"><span class="k">Revenue</span><span class="v">${fmtShort(p.revenue)}</span></div>
      <div class="fc-metric"><span class="k">Net Profit</span><span class="v ${p.netProfit<0?'':'hi'}">${fmtShort(p.netProfit)}</span></div>
      <div class="fc-metric"><span class="k">NET / день</span><span class="v hi">${fmtShort(p.netDay)}</span></div>
      <div class="fc-metric"><span class="k">Маржа · ROI</span><span class="v">${fmtPct(p.margin)} · ${fmtPct(p.roi)}</span></div>
      <div class="fc-metric"><span class="k">CAC · AOV</span><span class="v">${fmtShort(p.cac)} · ${fmtShort(p.aov)}</span></div>
    `).join('<hr style="border:none;border-top:1px solid var(--line);margin:8px 0">');
  };
  document.getElementById('flash49').innerHTML = card(p49);
  document.getElementById('flash99').innerHTML = card(p99);
}

// ---- Chart: NET/day за entry-price ----
let _chFlash = null;
function renderChart(){
  const ctx = document.getElementById('chFlash');
  if (!ctx) return;
  if (_chFlash){ _chFlash.destroy(); _chFlash = null; }
  // групуємо по entry-ціні, avg NET/day
  const buckets = { 49: [], 99: [], 199: [], 249: [] };
  _projects.forEach(p => {
    if (p.entry != null && buckets[p.entry] && p.netDay != null) buckets[p.entry].push(p.netDay);
  });
  const labels = ['49 ₴ (iPhone/гаджет)','99 ₴ (мото)','199 ₴ (premium авто)','249 ₴ (експеримент)'];
  const keys = [49,99,199,249];
  const data = keys.map(k => {
    const arr = buckets[k];
    return arr.length ? arr.reduce((s,v)=>s+v,0)/arr.length : 0;
  });
  const colors = ['#a78bfa','#60a5fa','#10B981','#E30613'];
  _chFlash = new Chart(ctx, {
    type: 'bar',
    data: { labels, datasets: [{ label:'Avg NET / день, ₴', data, backgroundColor: colors, borderRadius: 5, barPercentage: 0.65 }] },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor:'rgba(10,10,10,0.95)', borderColor:'#E30613', borderWidth:1,
          titleColor:'#fff', bodyColor:'#ddd', padding:12,
          callbacks: { label: (c) => fmtUAH(c.raw) + ' / день · ' + buckets[keys[c.dataIndex]].length + ' проєкт(ів)' }
        }
      },
      scales: {
        x: { grid:{ color:'rgba(255,255,255,0.04)' }, ticks:{ color:'#ddd', font:{ family:'Manrope', size:11 } } },
        y: { grid:{ color:'rgba(255,255,255,0.04)' }, ticks:{ color:'#6B7280', font:{ family:'JetBrains Mono', size:10 }, callback: v => fmtShort(v) } }
      }
    }
  });
}

// ---- Main ----
let _booted = false;
async function boot(){
  if (_booted) return;
  _booted = true;
  try {
    await getSb();
    const access = await checkAccess();
    if (!access.ok){
      console.warn('[pricing] denied', access);
      showDenied(access);
      return;
    }
    document.getElementById('loaderStatus').innerHTML = '<span class="loading-bar">Тягну mv_dashboard_project_pnl…</span>';
    const { rows, refreshedAt, source } = await fetchPnl();
    _projects = enrich(rows);
    document.getElementById('loaderStatus').style.display = 'none';
    document.getElementById('lastUpd').textContent = `оновлено: ${refreshedAt ? new Date(refreshedAt).toLocaleString('uk-UA',{timeZone:'Europe/Kyiv'}) : '—'} · джерело: ${source}`;

    bindSort();
    renderKpi(_projects);
    renderTable();
    renderCompare();
    renderFlash();
    renderChart();

    console.log('[pricing] projects loaded:', _projects.length, '· buckets:', {
      e49: _projects.filter(p=>p.entry===49).length,
      e99: _projects.filter(p=>p.entry===99).length,
      e199: _projects.filter(p=>p.entry===199).length,
      e249: _projects.filter(p=>p.entry===249).length,
      unknown: _projects.filter(p=>p.entry==null).length,
    });
  } catch(e){
    console.error('[pricing] boot fail', e);
    const ls = document.getElementById('loaderStatus');
    if (ls) ls.innerHTML = `<span style="color:#fca5a5;font-family:'JetBrains Mono',monospace;font-size:12px">Помилка завантаження: ${esc(e.message||e)}</span>`;
  }
}

window.addEventListener('dc-auth-ok', boot);
setTimeout(() => { if (!_booted) boot(); }, 2500);

})();
