#!/usr/bin/env python3
"""
Dashboard v3 patch:
- New "Projects" route showing all 7 projects with full-lifetime stats
- Rebuilt Analytics page with best-in-class KPIs, funnel, top channels, hourly trend
- Default state: project="усі проекти" + period="сьогодні" (instead of 30d)

Run:
    cd ~/DreamCar.AI/dashboard-dreamcar
    git pull
    python3 apply_dashboard_v3.py
    git add docs/index.html
    git commit -m "feat(dashboard): v3 - Projects page + Analytics rebuild + default today"
    git push
"""
import re, sys, pathlib

p = pathlib.Path(__file__).parent / 'docs' / 'index.html'
if not p.exists():
    print('docs/index.html not found'); sys.exit(1)

s = p.read_text()
orig_len = len(s)

# ---- Patch 1: default preset to 'today' (both places) ----
if "applyPreset('30d')" in s:
    s = s.replace("applyPreset('30d')", "applyPreset('today')")
    print('  ok patch 1: default preset -> today')
else:
    print('  ok patch 1: already today (or anchor missing)')

# ---- Patch 2: add Projects nav item in sidebar ----
old_nav = '<div class="nav-item" data-route="overview"><span class="icon">📊</span>Огляд</div>'
new_nav = old_nav + '\n      <div class="nav-item" data-route="projects"><span class="icon">🏎️</span>Проекти</div>'
if 'data-route="projects"' in s:
    print('  ok patch 2 already applied (nav item)')
elif old_nav not in s:
    print('FAIL patch 2: anchor not found'); sys.exit(1)
else:
    s = s.replace(old_nav, new_nav, 1)
    print('  ok patch 2 applied (Projects nav item)')

# ---- Patch 3: add SECTION_TITLES + register Projects route ----
old_sections = "const SECTIONS = {"
if 'projects: renderProjects' in s or "'projects':" in s:
    print('  ok patch 3 already applied (route registered)')
elif old_sections not in s:
    print('FAIL patch 3: anchor not found'); sys.exit(1)
else:
    s = s.replace(old_sections, "const SECTIONS = {\n  'projects': renderProjectsOverview,", 1)
    print('  ok patch 3 applied')

# Add to SECTION_TITLES
old_titles = "'sources': 'Джерела',"
new_titles = "'projects': '🏎️ Проекти', 'sources': 'Джерела',"
if "'projects': '🏎️ Проекти'" in s:
    pass
elif old_titles not in s:
    print('FAIL patch 3b: titles anchor not found'); sys.exit(1)
else:
    s = s.replace(old_titles, new_titles, 1)
    print('  ok patch 3b applied (title)')

# Add to UTM_SECTIONS so filter bar shows on Projects page too
old_utm_sec = "const UTM_SECTIONS = new Set(['overview','analytics',"
new_utm_sec = "const UTM_SECTIONS = new Set(['overview','analytics','projects',"
if "'projects'" not in s[s.find('UTM_SECTIONS'):s.find('UTM_SECTIONS')+200]:
    if old_utm_sec in s:
        s = s.replace(old_utm_sec, new_utm_sec, 1)
        print('  ok patch 3c applied (UTM_SECTIONS)')

# ---- Patch 4: insert renderProjectsOverview before renderOverview ----
projects_overview = '''/* ---------------- PROJECTS OVERVIEW ---------------- */
async function renderProjectsOverview() {
  const c = $('#content');
  c.innerHTML = `<div class="section-head"><h1>🏎️ Проекти</h1></div>${loadingHTML()}`;
  try {
    const { data, error } = await sb.rpc('dashboard_projects_with_stats');
    if (error) throw error;
    const rows = (data || []).map(r => ({
      code: r.code, name: r.name, car_model: r.car_model,
      date_start: r.date_start, date_end: r.date_end,
      status: r.status, color: r.color,
      leads: Number(r.leads), paid: Number(r.paid),
      fail: Number(r.fail), pending: Number(r.pending),
      revenue: Number(r.revenue), buyers: Number(r.buyers),
      conv_rate: Number(r.conv_rate), avg_check: Number(r.avg_check)
    }));

    const totals = rows.reduce((a,r) => ({
      leads: a.leads + r.leads, paid: a.paid + r.paid,
      revenue: a.revenue + r.revenue, buyers: a.buyers + r.buyers
    }), { leads: 0, paid: 0, revenue: 0, buyers: 0 });
    const avgConv = totals.leads > 0 ? (totals.paid / totals.leads * 100) : 0;
    const avgAov = totals.paid > 0 ? (totals.revenue / totals.paid) : 0;
    const bestProj = rows.reduce((best, r) => r.revenue > (best?.revenue || 0) ? r : best, null);
    const bestConv = rows.reduce((best, r) => r.conv_rate > (best?.conv_rate || 0) ? r : best, null);

    c.innerHTML = `
      <div class="section-head">
        <div><h1>🏎️ Проекти</h1><div class="subtitle">${rows.length} проектів · full-lifetime stats</div></div>
      </div>

      <div class="kpi-grid">
        ${kpi('Усього лідів', fmtNum(totals.leads), 'across всіх проектів', 'blue')}
        ${kpi('Усього оплат', fmtNum(totals.paid), fmtPct(avgConv) + ' конв.', 'green')}
        ${kpi('Виручка lifetime', fmtMoney(totals.revenue), 'UAH', 'red')}
        ${kpi('Сер. чек', fmtMoney(avgAov), 'AOV', 'amber')}
        ${kpi('Унік. покупців', fmtNum(totals.buyers), '', 'purple')}
        ${kpi('Топ за виручкою', bestProj ? bestProj.name : '—', bestProj ? fmtMoney(bestProj.revenue) : '', 'red')}
        ${kpi('Топ за конверсією', bestConv ? bestConv.name : '—', bestConv ? fmtPct(bestConv.conv_rate) : '', 'green')}
      </div>

      <div class="grid-2">
        <div class="card">
          <div class="card-head"><h3>Revenue по проектах</h3></div>
          <div class="chart-container"><canvas id="ch-proj-rev"></canvas></div>
        </div>
        <div class="card">
          <div class="card-head"><h3>Conv % по проектах</h3></div>
          <div class="chart-container"><canvas id="ch-proj-conv"></canvas></div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h3>Усі проекти — деталі</h3></div>
        <div id="t-projects"></div>
      </div>
    `;

    const reversedRows = [...rows].reverse();
    barChart('ch-proj-rev',
      reversedRows.map(r => r.name),
      [{ label: 'Виручка UAH', data: reversedRows.map(r => r.revenue), backgroundColor: reversedRows.map(r => r.color || '#E30613') }],
      { legend: false }
    );
    barChart('ch-proj-conv',
      reversedRows.map(r => r.name),
      [{ label: 'Конв %', data: reversedRows.map(r => r.conv_rate), backgroundColor: '#10B981' }],
      { legend: false }
    );

    buildTable('#t-projects', {
      rows, pageSize: 50, sortKey: 'date_start', sortDir: 'desc',
      csvFile: 'projects.csv',
      cols: [
        { key: 'name', label: 'Проект', fmt: (v, r) => `<strong style="color:${r.color||'#fff'}">${escapeHtml(v)}</strong>` },
        { key: 'date_start', label: 'Старт', fmt: v => `<span class="mono">${v}</span>` },
        { key: 'date_end', label: 'Фініш', fmt: v => `<span class="mono">${v}</span>` },
        { key: 'status', label: 'Статус', fmt: v => `<span class="pill ${v==='active'?'pay':v==='archived'?'pending':'fail'}">${escapeHtml(v)}</span>` },
        { key: 'leads', label: 'Ліди', class: 'num', fmt: fmtNum },
        { key: 'paid', label: 'Оплати', class: 'num', fmt: fmtNum },
        { key: 'revenue', label: 'Виручка', class: 'num', fmt: fmtMoney },
        { key: 'buyers', label: 'Покупців', class: 'num', fmt: fmtNum },
        { key: 'conv_rate', label: 'Конв %', class: 'num', fmt: fmtPct },
        { key: 'avg_check', label: 'AOV', class: 'num', fmt: fmtMoney }
      ]
    });
  } catch (e) { c.innerHTML = errorHTML(e); }
}

'''

anchor_overview = "async function renderOverview()"
if 'async function renderProjectsOverview' in s:
    print('  ok patch 4 already applied (renderProjectsOverview)')
elif anchor_overview not in s:
    print('FAIL patch 4: anchor not found'); sys.exit(1)
else:
    s = s.replace(anchor_overview, projects_overview + anchor_overview, 1)
    print('  ok patch 4 applied (renderProjectsOverview inserted)')

# ---- Patch 5: rebuild renderAnalytics with best-in-class layout ----
new_analytics = '''async function renderAnalytics() {
  const c = $('#content');
  c.innerHTML = `<div class="section-head"><h1>📈 Аналітика</h1></div>${loadingHTML()}`;
  try {
    const params = _rpcParams();
    const isToday = (filters.from === filters.to);
    const [kpiDelta, daily, hourly, mediums, campaigns, sources, trafficSplit] = await Promise.all([
      sb.rpc('dashboard_kpi_with_delta', params).then(r => r.error ? null : (r.data||[])[0]),
      dailySeriesRPC(),
      isToday ? sb.rpc('dashboard_hourly_series', params).then(r => r.error ? [] : (r.data||[])) : Promise.resolve([]),
      aggViaRPC('utm_medium'),
      aggViaRPC('utm_campaign'),
      aggViaRPC('utm_source'),
      trafficTypeRPC()
    ]);

    const k = kpiDelta || {};
    const dlt = v => v == null ? '' : (v >= 0 ? `▲ +${v}%` : `▼ ${v}%`);
    const dltClass = v => v == null ? '' : (v >= 0 ? 'pay' : 'fail');

    const topMediums = mediums.slice(0, 5);
    const topCampaigns = campaigns.filter(c => c.key !== '(none)' && c.key !== '').slice(0, 10);
    const topSources = sources.filter(s => s.key !== '(none)' && s.key !== '').slice(0, 10);

    const seriesData = isToday ? hourly.map(h => ({ x: h.hour, leads: Number(h.leads), paid: Number(h.paid), revenue: Number(h.revenue) }))
                               : daily.map(d => ({ x: d.day, leads: d.leads, paid: d.paid, revenue: d.revenue }));
    const xLabels = seriesData.map(d => isToday ? new Date(d.x).getHours() + ':00' : d.x);

    c.innerHTML = `
      <div class="section-head">
        <div><h1>📈 Аналітика</h1><div class="subtitle">${filters.from} → ${filters.to} · ${isToday ? 'погодинна' : 'щоденна'} деталізація</div></div>
      </div>

      <div class="kpi-grid">
        <div class="kpi-card blue">
          <div class="kpi-label">Ліди</div>
          <div class="kpi-value">${fmtNum(Number(k.total||0))}</div>
          <div class="kpi-meta"><span class="pill ${dltClass(Number(k.total_delta))}">${dlt(Number(k.total_delta))}</span> vs попередній період</div>
        </div>
        <div class="kpi-card green">
          <div class="kpi-label">Оплати</div>
          <div class="kpi-value">${fmtNum(Number(k.paid||0))}</div>
          <div class="kpi-meta">${fmtPct(Number(k.paid_rate||0))} конверсія · <span class="pill ${dltClass(Number(k.paid_delta))}">${dlt(Number(k.paid_delta))}</span></div>
        </div>
        <div class="kpi-card red">
          <div class="kpi-label">Виручка UAH</div>
          <div class="kpi-value">${fmtMoney(Number(k.revenue||0))}</div>
          <div class="kpi-meta"><span class="pill ${dltClass(Number(k.revenue_delta))}">${dlt(Number(k.revenue_delta))}</span> vs попередній</div>
        </div>
        <div class="kpi-card amber">
          <div class="kpi-label">Сер. чек (AOV)</div>
          <div class="kpi-value">${fmtMoney(Number(k.aov||0))}</div>
          <div class="kpi-meta">${fmtNum(Number(k.buyers||0))} унік. покупців</div>
        </div>
      </div>

      <div class="grid-2">
        <div class="card">
          <div class="card-head"><h3>${isToday ? 'Сьогодні погодинно' : 'Динаміка по днях'}</h3></div>
          <div class="chart-container"><canvas id="ch-trend"></canvas></div>
        </div>
        <div class="card">
          <div class="card-head"><h3>🔻 Воронка</h3></div>
          <div class="chart-container short"><canvas id="ch-funnel"></canvas></div>
        </div>
      </div>

      <div class="grid-2">
        <div class="card">
          <div class="card-head"><h3>💸 Платний vs 🌱 Органічний</h3></div>
          <div class="chart-container short"><canvas id="ch-tt"></canvas></div>
        </div>
        <div class="card">
          <div class="card-head"><h3>🏆 Топ-5 каналів (utm_medium)</h3></div>
          <div class="chart-container short"><canvas id="ch-medium"></canvas></div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h3>🎯 Топ-10 кампаній</h3><span class="meta">${campaigns.length} усього</span></div>
        <div id="t-campaigns"></div>
      </div>

      <div class="card">
        <div class="card-head"><h3>📍 Топ-10 джерел</h3></div>
        <div id="t-sources"></div>
      </div>
    `;

    if (seriesData.length) {
      lineChart('ch-trend', xLabels, [
        { label: 'Ліди', data: seriesData.map(d => d.leads), borderColor: '#3B82F6', backgroundColor: 'rgba(59,130,246,0.10)', tension: 0.3, fill: true },
        { label: 'Оплачені', data: seriesData.map(d => d.paid), borderColor: '#10B981', backgroundColor: 'rgba(16,185,129,0.15)', tension: 0.3, fill: true },
        { label: 'Revenue', data: seriesData.map(d => d.revenue), borderColor: '#E30613', backgroundColor: 'transparent', tension: 0.3, yAxisID: 'y1' }
      ], { scales: { y1: { position: 'right', grid: { drawOnChartArea: false }, ticks: { color: '#E30613' } } } });
    }

    const total = Number(k.total||0), paid = Number(k.paid||0);
    barChart('ch-funnel',
      ['Усі ліди', 'Оплачені'],
      [{ label: 'К-сть', data: [total, paid], backgroundColor: ['#3B82F6','#10B981'] }],
      { legend: false }
    );

    doughnutChart('ch-tt',
      ['💸 Платний','🌱 Органічний'],
      [trafficSplit.paid.leads, trafficSplit.organic.leads],
      ['#F59E0B','#10B981']
    );

    if (topMediums.length) {
      barChart('ch-medium',
        topMediums.map(m => m.key.length > 15 ? m.key.slice(0,12)+'...' : m.key),
        [{ label: 'Виручка', data: topMediums.map(m => m.sum), backgroundColor: '#E30613' }],
        { legend: false }
      );
    }

    buildTable('#t-campaigns', {
      rows: topCampaigns, pageSize: 10, sortKey: 'sum',
      csvFile: 'top-campaigns.csv',
      cols: [
        { key: 'key', label: 'Кампанія', fmt: v => `<span class="mono" style="font-size:12px">${escapeHtml(v)}</span>` },
        { key: 'leads', label: 'Ліди', class: 'num', fmt: fmtNum },
        { key: 'paid', label: 'Оплати', class: 'num', fmt: fmtNum },
        { key: 'sum', label: 'Виручка', class: 'num', fmt: fmtMoney },
        { key: 'conv', label: 'Конв %', class: 'num', fmt: fmtPct }
      ]
    });

    buildTable('#t-sources', {
      rows: topSources, pageSize: 10, sortKey: 'sum',
      csvFile: 'top-sources.csv',
      cols: [
        { key: 'key', label: 'Джерело', fmt: v => `<span class="mono" style="font-size:12px">${escapeHtml(v)}</span>` },
        { key: 'traffic_type', label: 'Тип', fmt: v => `<span class="pill ${v.includes('Платний')?'pending':'pay'}">${escapeHtml(v)}</span>` },
        { key: 'leads', label: 'Ліди', class: 'num', fmt: fmtNum },
        { key: 'paid', label: 'Оплати', class: 'num', fmt: fmtNum },
        { key: 'sum', label: 'Виручка', class: 'num', fmt: fmtMoney },
        { key: 'conv', label: 'Конв %', class: 'num', fmt: fmtPct }
      ]
    });
  } catch (e) { c.innerHTML = errorHTML(e); }
}'''

if 'dashboard_kpi_with_delta' in s and "isToday ? 'погодинна'" in s:
    print('  ok patch 5 already applied (renderAnalytics)')
else:
    pat5 = re.compile(r"async function renderAnalytics\(\) \{[\s\S]*?\n\}\n", re.MULTILINE)
    m = pat5.search(s)
    if not m:
        print('FAIL patch 5: regex no match'); sys.exit(1)
    s = s[:m.start()] + new_analytics + "\n" + s[m.end():]
    print('  ok patch 5 applied (renderAnalytics rebuilt)')

p.write_text(s)
print(f'\nDONE. {orig_len} -> {len(s)} bytes (+{len(s)-orig_len})')
print('\nChanges in v3:')
print('  + Default state: project=усі, period=today')
print('  + New "Projects" route — full-lifetime stats всіх 7 проектів')
print('  + Analytics rebuilt: KPI with delta, hourly trend (today), funnel, traffic split, top channels/campaigns/sources')
print('\nNext:')
print('  git add docs/index.html')
print('  git commit -m "feat(dashboard): v3 - Projects page + Analytics rebuild"')
print('  git push')
