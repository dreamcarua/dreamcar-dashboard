/* DreamCar Dashboard — main app (READ-ONLY)
 * Тільки SELECT з dashboard_* tables. Жодних writes у production.
 */
(function () {
  'use strict';

  const SB_URL = 'https://wotghlaehnvxyeacznvv.supabase.co';
  // anon key (public — RLS захищає, тільки authenticated можуть бачити)
  // Завантажується через window.HQ_CONFIG (auth-guard підвантажує)
  let sb = null;

  // ===== UTILS =====
  const fmt = {
    money: (v, cur = 'UAH') => {
      if (v == null) return '—';
      try {
        return new Intl.NumberFormat('uk-UA', {
          style: 'currency', currency: cur, maximumFractionDigits: 0
        }).format(Number(v));
      } catch (e) {
        return Number(v).toFixed(0) + ' ' + cur;
      }
    },
    num: (v) => v == null ? '—' : new Intl.NumberFormat('uk-UA').format(v),
    pct: (v) => v == null ? '—' : (Number(v) * 100).toFixed(1) + '%',
    date: (v) => {
      if (!v) return '—';
      const d = new Date(v);
      return d.toLocaleDateString('uk-UA', { day: '2-digit', month: '2-digit', year: 'numeric' })
        + ' ' + d.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' });
    },
  };

  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => Array.from(document.querySelectorAll(sel));

  // ===== STATE =====
  const state = {
    deals: [],
    webhooks: [],
    filters: {
      from: defaultDate(-30),
      to: defaultDate(0),
      project: '',
      utm_source: '',
      status: '',
    },
    charts: {},
  };

  function defaultDate(daysOffset) {
    const d = new Date();
    d.setDate(d.getDate() + daysOffset);
    return d.toISOString().slice(0, 10);
  }

  // ===== SUPABASE INIT =====
  async function initSupabase() {
    // auth-guard вже завантажив HQ_CONFIG + window.supabase
    if (window.supabase && window.supabase.auth) {
      sb = window.supabase;
      return sb;
    }
    // Fallback: завантажуємо самостійно
    if (!window.HQ_CONFIG) {
      await new Promise((res, rej) => {
        const s = document.createElement('script');
        s.src = 'https://team.dreamcar.ua/hq/config.js';
        s.onload = res; s.onerror = rej;
        document.head.appendChild(s);
      });
    }
    const mod = await import('https://esm.sh/@supabase/supabase-js@2');
    sb = mod.createClient(
      window.HQ_CONFIG.SUPABASE_URL,
      window.HQ_CONFIG.SUPABASE_ANON_KEY,
      { auth: { persistSession: true, autoRefreshToken: true } }
    );
    window.supabase = sb;
    return sb;
  }

  // ===== DATA FETCHING (READ-ONLY) =====
  async function fetchDeals() {
    const { from, to, project, utm_source, status } = state.filters;
    let q = sb.from('dashboard_deals')
      .select('id, sendpulse_deal_id, status, amount, currency, project, utm_source, utm_medium, utm_campaign, customer_email, customer_phone, tariff, created_at, paid_at')
      .gte('created_at', from + 'T00:00:00Z')
      .lte('created_at', to + 'T23:59:59Z')
      .order('created_at', { ascending: false })
      .limit(500);

    if (project) q = q.eq('project', project);
    if (utm_source) q = q.eq('utm_source', utm_source);
    if (status) q = q.eq('status', status);

    const { data, error } = await q;
    if (error) { console.error('[deals] error:', error); return []; }
    return data || [];
  }

  async function fetchProjectsList() {
    // 06.06.2026 FIX: тягнемо живі унікальні project-и з dashboard_deals (90 днів) +
    // merge з settings — щоб нові проекти не зникали з dropdown.
    const fromSettings = await sb.from('dashboard_settings').select('value').eq('key', 'projects').single();
    const settingsList = (fromSettings?.data?.value) || [];
    try {
      const ninetyDaysAgo = new Date(Date.now() - 90*24*3600*1000).toISOString();
      const { data: liveDeals } = await sb.from('dashboard_deals')
        .select('project')
        .gte('created_at', ninetyDaysAgo)
        .not('project', 'is', null)
        .limit(5000);
      const liveSet = new Set((liveDeals || []).map(d => d.project).filter(Boolean));
      // Merge: live distinct values first (sorted by frequency desc), then settings extras
      const merged = Array.from(new Set([...liveSet, ...settingsList]));
      return merged.sort();
    } catch(e) {
      console.warn('[projects] fallback to settings:', e);
      return settingsList;
    }
  }

  async function fetchWebhookHealth() {
    // Останні 50 webhooks для health бейджа
    const { data } = await sb.from('dashboard_webhooks')
      .select('source, status_code, error_message, created_at')
      .order('created_at', { ascending: false })
      .limit(50);
    return data || [];
  }

  // ===== AGGREGATIONS (client-side) =====
  function computeKpis(deals) {
    const total = deals.length;
    const paid = deals.filter(d => d.status === 'pay');
    const failed = deals.filter(d => d.status === 'fail');
    const sumPaid = paid.reduce((s, d) => s + (Number(d.amount) || 0), 0);
    const avgDeal = paid.length ? sumPaid / paid.length : 0;
    const conversion = total ? paid.length / total : 0;
    return { total, paid: paid.length, failed: failed.length, sumPaid, avgDeal, conversion };
  }

  function groupByDay(deals) {
    const m = new Map();
    deals.forEach(d => {
      const day = (d.created_at || '').slice(0, 10);
      if (!day) return;
      if (!m.has(day)) m.set(day, { total: 0, paid: 0, sum: 0 });
      const g = m.get(day);
      g.total++;
      if (d.status === 'pay') {
        g.paid++;
        g.sum += Number(d.amount) || 0;
      }
    });
    const days = [...m.keys()].sort();
    return {
      labels: days,
      total: days.map(d => m.get(d).total),
      paid: days.map(d => m.get(d).paid),
      sum: days.map(d => m.get(d).sum),
    };
  }

  function groupByUtm(deals, field = 'utm_source') {
    const m = new Map();
    deals.forEach(d => {
      const v = d[field] || '(none)';
      if (!m.has(v)) m.set(v, { count: 0, sum: 0 });
      const g = m.get(v);
      g.count++;
      if (d.status === 'pay') g.sum += Number(d.amount) || 0;
    });
    return [...m.entries()].sort((a, b) => b[1].count - a[1].count).slice(0, 10);
  }

  // ===== RENDERERS =====
  function renderKpis(k) {
    const cards = [
      { label: 'Угоди (всього)', value: fmt.num(k.total), id: 'kpi-total' },
      { label: 'Оплачені', value: fmt.num(k.paid), id: 'kpi-paid' },
      { label: 'Сума оплачених', value: fmt.money(k.sumPaid), id: 'kpi-sum' },
      { label: 'Середній чек', value: fmt.money(k.avgDeal), id: 'kpi-avg' },
      { label: 'Конверсія', value: fmt.pct(k.conversion), id: 'kpi-conv' },
      { label: 'Відмови', value: fmt.num(k.failed), id: 'kpi-fail' },
    ];
    $('#kpis').innerHTML = cards.map(c => `
      <div class="kpi-card" id="${c.id}">
        <div class="kpi-label">${c.label}</div>
        <div class="kpi-value">${c.value}</div>
      </div>
    `).join('');
  }

  function renderHealth(webhooks) {
    if (!webhooks.length) {
      $('#health').innerHTML = `<div class="health-badge"><span class="dot"></span> Немає webhook логів</div>`;
      return;
    }
    const lastByDay = webhooks.filter(w => Date.now() - new Date(w.created_at).getTime() < 24*60*60*1000);
    const okCount = lastByDay.filter(w => w.status_code === 200).length;
    const failCount = lastByDay.filter(w => w.status_code && w.status_code >= 400).length;
    const last = webhooks[0];
    const lastAgo = Math.round((Date.now() - new Date(last.created_at).getTime()) / 60000);
    const lastClass = lastAgo > 60 ? 'warn' : (lastAgo > 240 ? 'fail' : '');
    const failClass = failCount > 0 ? 'fail' : '';
    $('#health').innerHTML = `
      <div class="health-badge ${lastClass}"><span class="dot"></span> Останній webhook: ${lastAgo} хв тому</div>
      <div class="health-badge"><span class="dot"></span> 24h: ${okCount} OK</div>
      <div class="health-badge ${failClass}"><span class="dot"></span> 24h: ${failCount} помилок</div>
    `;
  }

  function renderDealsTable(deals) {
    const top = deals.slice(0, 25);
    if (!top.length) {
      $('#deals-table').innerHTML = '<div class="empty-state">Угод не знайдено за вибраний період</div>';
      return;
    }
    $('#deals-table').innerHTML = `
      <table class="deals-table">
        <thead>
          <tr>
            <th>Дата</th>
            <th>ID</th>
            <th>Статус</th>
            <th>Сума</th>
            <th>Проєкт</th>
            <th>UTM Source</th>
            <th>UTM Campaign</th>
            <th>Email</th>
          </tr>
        </thead>
        <tbody>
          ${top.map(d => `
            <tr>
              <td>${fmt.date(d.created_at)}</td>
              <td><code>${escapeHtml(d.sendpulse_deal_id || d.id.slice(0, 8))}</code></td>
              <td><span class="status-pill ${d.status}">${d.status || '—'}</span></td>
              <td>${fmt.money(d.amount, d.currency || 'UAH')}</td>
              <td>${escapeHtml(d.project || '—')}</td>
              <td>${escapeHtml(d.utm_source || '—')}</td>
              <td>${escapeHtml(d.utm_campaign || '—')}</td>
              <td>${escapeHtml(d.customer_email || '—')}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
    $('#deals-count').textContent = `показано ${top.length} з ${deals.length}`;
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function renderChartTimeline(deals) {
    const g = groupByDay(deals);
    const ctx = $('#chart-timeline').getContext('2d');
    if (state.charts.timeline) state.charts.timeline.destroy();
    state.charts.timeline = new Chart(ctx, {
      type: 'line',
      data: {
        labels: g.labels,
        datasets: [
          { label: 'Всього угод', data: g.total, borderColor: '#3B82F6', backgroundColor: 'rgba(59,130,246,0.1)', tension: 0.3 },
          { label: 'Оплачені', data: g.paid, borderColor: '#10B981', backgroundColor: 'rgba(16,185,129,0.1)', tension: 0.3 },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { labels: { color: '#DDD', font: { family: 'JetBrains Mono', size: 11 } } } },
        scales: {
          x: { ticks: { color: '#888', font: { size: 10 } }, grid: { color: '#222' } },
          y: { ticks: { color: '#888', font: { size: 10 } }, grid: { color: '#222' }, beginAtZero: true },
        },
      },
    });
  }

  function renderChartBySource(deals) {
    const data = groupByUtm(deals, 'utm_source');
    const ctx = $('#chart-source').getContext('2d');
    if (state.charts.source) state.charts.source.destroy();
    state.charts.source = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: data.map(([k]) => k),
        datasets: [{
          data: data.map(([_, v]) => v.count),
          backgroundColor: ['#E30613','#FF1A2B','#3B82F6','#10B981','#F59E0B','#8B5CF6','#EC4899','#06B6D4','#84CC16','#888'],
          borderColor: '#0A0A0A', borderWidth: 2,
        }],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'right', labels: { color: '#DDD', font: { family: 'JetBrains Mono', size: 11 }, padding: 10 } } },
      },
    });
  }

  // ===== POPULATE FILTERS =====
  function populateFilterOptions(deals) {
    const sources = new Set(deals.map(d => d.utm_source).filter(Boolean));
    const projects = new Set(deals.map(d => d.project).filter(Boolean));
    const fillSelect = (id, vals, current) => {
      const el = $(id);
      el.innerHTML = '<option value="">— Всі —</option>' + [...vals].sort().map(v =>
        `<option value="${escapeHtml(v)}" ${v === current ? 'selected' : ''}>${escapeHtml(v)}</option>`
      ).join('');
    };
    fillSelect('#f-source', sources, state.filters.utm_source);
    fillSelect('#f-project', projects, state.filters.project);
  }

  // ===== MAIN =====
  async function loadAndRender() {
    $('#kpis').innerHTML = '<div class="loading-state">Завантаження…</div>';
    $('#deals-table').innerHTML = '<div class="loading-state">Завантаження угод…</div>';
    try {
      const [deals, webhooks] = await Promise.all([fetchDeals(), fetchWebhookHealth()]);
      state.deals = deals;
      state.webhooks = webhooks;
      const k = computeKpis(deals);
      renderKpis(k);
      renderHealth(webhooks);
      renderDealsTable(deals);
      renderChartTimeline(deals);
      renderChartBySource(deals);
      populateFilterOptions(deals);
    } catch (e) {
      console.error('[load] error:', e);
      $('#deals-table').innerHTML = `<div class="empty-state">Помилка завантаження: ${escapeHtml(e.message || String(e))}</div>`;
    }
  }

  function bindFilters() {
    $('#f-from').value = state.filters.from;
    $('#f-to').value = state.filters.to;
    $$('.filter-bar input, .filter-bar select').forEach(el => {
      el.addEventListener('change', () => {
        state.filters.from = $('#f-from').value;
        state.filters.to = $('#f-to').value;
        state.filters.project = $('#f-project').value;
        state.filters.utm_source = $('#f-source').value;
        state.filters.status = $('#f-status').value;
        loadAndRender();
      });
    });
    $('#f-refresh').addEventListener('click', loadAndRender);
  }

  async function boot() {
    try {
      await initSupabase();
      bindFilters();
      await loadAndRender();
    } catch (e) {
      console.error('[boot] error:', e);
    }
  }

  // Запускаємось після auth-guard (event dc-auth-ok)
  window.addEventListener('dc-auth-ok', boot);
  // Або якщо вже є сесія
  if (window.supabase) {
    boot();
  }
})();
