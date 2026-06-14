/* ============================================================
   DreamCar Dashboard — P2 extras
   ============================================================
   #18 Saved Views/Bookmarks  (localStorage filter presets)
   #19 Light theme toggle      (CSS override)
   #20 Notifications tray       (in-app stack)
   #15 Drill-down click handler (bar/doughnut → table)
   ============================================================ */
(function () {
  if (window.__dashboardExtrasLoaded) return;
  window.__dashboardExtrasLoaded = true;

  /* ====== CSS for light theme + notifications tray ====== */
  var css = document.createElement('style');
  css.id = 'dashboard-extras-css';
  css.textContent = [
    /* #20 Notifications tray */
    '.dc-notif-tray { position: fixed; top: 70px; right: 20px; z-index: 9000; display: flex; flex-direction: column; gap: 8px; max-width: 360px; pointer-events: none; }',
    '.dc-notif { background: var(--bg-2, #141414); border: 1px solid var(--border, #2a2a2a); border-left: 4px solid var(--red, #E30613); border-radius: 6px; padding: 10px 14px; font-size: 13px; color: #fff; box-shadow: 0 4px 16px rgba(0,0,0,0.4); pointer-events: auto; animation: dcSlide .25s ease; cursor: pointer; }',
    '.dc-notif.success { border-left-color: #10B981; }',
    '.dc-notif.warn { border-left-color: #F59E0B; }',
    '.dc-notif.info { border-left-color: #3B82F6; }',
    '@keyframes dcSlide { from { transform: translateX(40px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }',
    '.dc-notif-time { font-family: "JetBrains Mono", monospace; font-size: 10px; color: var(--ash, #aaa); display: block; margin-top: 4px; }',
    /* #19 Light theme */
    'html.light-theme body { background: #FAFAFA !important; color: #111 !important; }',
    'html.light-theme .topbar { background: #FFFFFF !important; border-bottom: 1px solid #E5E5E5 !important; }',
    'html.light-theme .sidebar { background: #FFFFFF !important; border-right: 1px solid #E5E5E5 !important; }',
    'html.light-theme .card, html.light-theme .kpi { background: #FFFFFF !important; border-color: #E5E5E5 !important; color: #111 !important; }',
    'html.light-theme .kpi-value, html.light-theme .topbar-title { color: #111 !important; }',
    'html.light-theme .filter-bar { background: #F5F5F5 !important; border-bottom: 1px solid #E5E5E5; }',
    'html.light-theme .filter-bar input, html.light-theme .filter-bar select { background: #FFF !important; color: #111 !important; border-color: #D5D5D5 !important; }',
    'html.light-theme .nav-item, html.light-theme .nav-item:hover { color: #333 !important; }',
    'html.light-theme .nav-item.active { background: rgba(227,6,19,0.12) !important; color: var(--red, #E30613) !important; }',
    'html.light-theme table { color: #111 !important; }',
    'html.light-theme thead th { background: #F5F5F5 !important; color: #555 !important; border-bottom: 1px solid #E5E5E5; }',
    'html.light-theme tbody td { border-bottom: 1px solid #F0F0F0 !important; }',
    'html.light-theme tbody tr:hover td { background: #FAFAFA !important; }',
    /* #18 Saved views button у topbar */
    '.dc-views-menu { position: absolute; top: 50px; right: 20px; background: var(--bg-2, #141414); border: 1px solid var(--red, #E30613); border-radius: 8px; padding: 8px; min-width: 240px; z-index: 8500; display: none; box-shadow: 0 8px 24px rgba(0,0,0,0.5); }',
    '.dc-views-menu.show { display: block; }',
    '.dc-view-item { display: flex; align-items: center; gap: 8px; padding: 6px 10px; cursor: pointer; border-radius: 5px; font-size: 12px; color: #fff; }',
    '.dc-view-item:hover { background: rgba(227,6,19,0.12); }',
    '.dc-view-item .name { flex: 1; }',
    '.dc-view-item .dc-view-del { color: #DC2626; font-size: 14px; padding: 0 4px; }',
    '.dc-view-item.empty { color: var(--ash, #aaa); font-style: italic; padding: 12px 10px; }',
    'html.light-theme .dc-views-menu { background: #FFF !important; border-color: #D5D5D5 !important; }',
    'html.light-theme .dc-view-item { color: #111 !important; }',
  ].join('\n');
  document.head.appendChild(css);

  /* ====== #20 Notifications tray ====== */
  var tray;
  function ensureTray() {
    if (tray) return tray;
    tray = document.createElement('div');
    tray.className = 'dc-notif-tray';
    document.body.appendChild(tray);
    return tray;
  }
  window.dcNotify = function (msg, type, ttl) {
    var t = ensureTray();
    var el = document.createElement('div');
    el.className = 'dc-notif ' + (type || 'info');
    var time = new Date().toTimeString().slice(0, 5);
    el.innerHTML = '<div>' + msg + '</div><span class="dc-notif-time">' + time + '</span>';
    el.onclick = function () { el.remove(); };
    t.appendChild(el);
    setTimeout(function () { el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 250); }, ttl || 5000);
  };

  /* ====== #19 Light theme toggle ====== */
  function applyTheme(t) {
    if (t === 'light') document.documentElement.classList.add('light-theme');
    else document.documentElement.classList.remove('light-theme');
    localStorage.setItem('dc-theme', t || 'dark');
  }
  applyTheme(localStorage.getItem('dc-theme') || 'dark');

  function injectThemeButton() {
    var actions = document.getElementById('topbar-actions');
    if (!actions || actions.querySelector('#dc-theme-btn')) return;
    var btn = document.createElement('button');
    btn.className = 'tb-btn';
    btn.id = 'dc-theme-btn';
    btn.title = 'Theme toggle';
    btn.textContent = localStorage.getItem('dc-theme') === 'light' ? '🌙' : '☀️';
    btn.onclick = function (ev) {
      // 08.06.2026 P0 defensive fix: stopPropagation щоб не conflict-ити з document click handler
      // або з any pending renderRoute calls. Theme toggle — pure CSS, не повинен викликати data refetch.
      if (ev) { ev.stopPropagation(); ev.preventDefault(); }
      var newT = localStorage.getItem('dc-theme') === 'light' ? 'dark' : 'light';
      applyTheme(newT);
      btn.textContent = newT === 'light' ? '🌙' : '☀️';
      return false;
    };
    actions.insertBefore(btn, actions.firstChild);
  }

  /* ====== #18 Saved views ====== */
  function getViews() {
    try { return JSON.parse(localStorage.getItem('dc-saved-views') || '[]'); }
    catch { return []; }
  }
  function setViews(views) {
    localStorage.setItem('dc-saved-views', JSON.stringify(views));
  }
  function snapshotFilters() {
    return window.filters ? JSON.parse(JSON.stringify(window.filters)) : {};
  }
  function applyFiltersSnapshot(snap) {
    if (!window.filters || !snap) return;
    Object.keys(snap).forEach(function (k) { window.filters[k] = snap[k]; });
    // Sync UI inputs
    if (snap.from) { var cf = document.getElementById('cd-from'); if (cf) cf.value = snap.from; }
    if (snap.to) { var ct = document.getElementById('cd-to'); if (ct) ct.value = snap.to; }
    var pairs = [
      ['f-date-range', 'preset'], ['f-project', 'project'], ['f-model', 'model'],
      ['f-status', 'status'], ['f-customer-type', 'customer_type'],
      ['f-tariff', 'tariff'], ['f-pay-provider', 'pay_provider'],
      ['f-funnel-type', 'funnel_type'], ['f-traffic-type', 'traffic_type'],
      ['f-source-filter', 'source_filter']
    ];
    pairs.forEach(function (p) {
      var el = document.getElementById(p[0]);
      if (el && snap[p[1]] != null) el.value = snap[p[1]] || '';
    });
    if (window.renderRoute) window.renderRoute();
  }
  function renderViewsMenu() {
    var menu = document.getElementById('dc-views-menu');
    if (!menu) return;
    var views = getViews();
    var items = views.length
      ? views.map(function (v, i) {
          return '<div class="dc-view-item" data-i="' + i + '"><span class="name">' + (v.name || '(no name)') + '</span><span class="dc-view-del" data-del="' + i + '">🗑</span></div>';
        }).join('') + '<div style="border-top:1px solid var(--border);margin-top:8px;padding-top:8px;"><div class="dc-view-item" data-act="save"><span class="name">💾 Зберегти поточний як новий</span></div></div>'
      : '<div class="dc-view-item empty">Збережіть поточний набір фільтрів</div><div class="dc-view-item" data-act="save"><span class="name">💾 Зберегти</span></div>';
    menu.innerHTML = items;
    menu.querySelectorAll('[data-i]').forEach(function (el) {
      el.onclick = function (e) {
        if (e.target.dataset.del != null) {
          var v = getViews(); v.splice(parseInt(e.target.dataset.del), 1); setViews(v);
          renderViewsMenu(); return;
        }
        var i = parseInt(el.dataset.i);
        var v = getViews()[i];
        if (v) applyFiltersSnapshot(v.snap);
        menu.classList.remove('show');
      };
    });
    var saveEl = menu.querySelector('[data-act="save"]');
    if (saveEl) saveEl.onclick = function () {
      var name = prompt('Назва збереженого набору:', 'View ' + (getViews().length + 1));
      if (!name) return;
      var v = getViews();
      v.push({ name: name, snap: snapshotFilters(), saved_at: new Date().toISOString() });
      setViews(v);
      renderViewsMenu();
      window.dcNotify && dcNotify('💾 Збережено: ' + name, 'success');
    };
  }
  function injectViewsButton() {
    var actions = document.getElementById('topbar-actions');
    if (!actions || actions.querySelector('#dc-views-btn')) return;
    var btn = document.createElement('button');
    btn.className = 'tb-btn';
    btn.id = 'dc-views-btn';
    btn.title = 'Saved views';
    btn.textContent = '⭐';
    actions.insertBefore(btn, actions.firstChild);
    var menu = document.createElement('div');
    menu.className = 'dc-views-menu';
    menu.id = 'dc-views-menu';
    document.body.appendChild(menu);
    btn.onclick = function (e) {
      e.stopPropagation();
      renderViewsMenu();
      menu.classList.toggle('show');
    };
    document.addEventListener('click', function (e) {
      if (!menu.contains(e.target) && e.target !== btn) menu.classList.remove('show');
    });
  }

  /* ====== #392.3 Active Launch Pulse — sticky pill row під topbar ======
     Показує всі активні запуски (status='active' у public.launches) як клікабельні
     pill-картки. Клік → вибрати проект у filter-bar (drill-down).
     Render = 1 SELECT раз на bootstrap + auto-refresh кожні 5 хв. */

  // Inject CSS для Pulse
  var pulseCss = document.createElement('style');
  pulseCss.id = 'dc-pulse-css';
  pulseCss.textContent = [
    '.dc-pulse { display: flex; gap: 8px; padding: 8px 16px; background: linear-gradient(90deg,#0f0f0f 0%, #141414 60%, #0f0f0f 100%); border-bottom: 1px solid #2a2a2a; overflow-x: auto; white-space: nowrap; position: sticky; top: 56px; z-index: 35; align-items: center; scrollbar-width: thin; }',
    '.dc-pulse-label { font-family: "JetBrains Mono", monospace; font-size: 10px; letter-spacing: .14em; color: #888; text-transform: uppercase; margin-right: 4px; }',
    '.dc-pulse-card { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 999px; font-size: 12px; color: #fff; cursor: pointer; transition: all .15s; }',
    '.dc-pulse-card:hover { border-color: #E30613; transform: translateY(-1px); background: #1f1f1f; }',
    '.dc-pulse-card.active { border-color: #10B981; box-shadow: 0 0 0 1px #10B981; }',
    '.dc-pulse-card .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }',
    '.dc-pulse-card .dot.active { background: #10B981; animation: dcPulse 1.6s ease-in-out infinite; }',
    '.dc-pulse-card .dot.completed { background: #888; }',
    '.dc-pulse-card .dot.measure { background: #FBBF24; }',
    '@keyframes dcPulse { 0%,100% { box-shadow: 0 0 0 0 rgba(16,185,129,.6);} 50% { box-shadow: 0 0 0 6px rgba(16,185,129,0);} }',
    '.dc-pulse-card .nm { font-weight: 700; letter-spacing: .02em; }',
    '.dc-pulse-card .meta { color: #888; font-family: "JetBrains Mono", monospace; font-size: 10px; }',
    '.dc-pulse-empty { color: #888; font-size: 12px; font-style: italic; }',
    'html.light-theme .dc-pulse { background: #FAFAFA !important; border-bottom-color: #E5E5E5 !important; }',
    'html.light-theme .dc-pulse-card { background: #FFF !important; color: #111 !important; border-color: #D5D5D5 !important; }',
    '@media (max-width: 768px) { .dc-pulse { padding: 6px 12px; top: 52px; } .dc-pulse-label { display: none; } }',
  ].join('\n');
  document.head.appendChild(pulseCss);

  // Compute days until end_on (Kyiv)
  function daysLeft(endIso) {
    if (!endIso) return null;
    var todayY = new Date().toLocaleDateString('sv-SE', { timeZone: 'Europe/Kyiv' });
    var today = new Date(todayY + 'T00:00:00+03:00').getTime();
    var end = new Date(endIso + 'T23:59:59+03:00').getTime();
    return Math.round((end - today) / 86400000);
  }

  async function fetchActiveLaunches() {
    if (!window.supabase || !window.supabase.from) return [];
    try {
      var sb = window.supabase;
      var r = await sb.from('launches')
        .select('id,name,code,status,starts_on,ends_on,is_active,budget_plan,budget_actual,deal_aliases')
        .eq('is_active', true)
        .in('status', ['active', 'measure'])
        .order('starts_on', { ascending: false })
        .limit(6);
      if (r.error) { console.warn('[dc-pulse] launches fetch error:', r.error.message); return []; }
      return r.data || [];
    } catch (e) { console.warn('[dc-pulse]', e); return []; }
  }

  function renderPulse(launches) {
    var bar = document.getElementById('dc-pulse-bar');
    if (!bar) return;
    if (!launches.length) {
      bar.innerHTML = '<span class="dc-pulse-label">Запуски</span><span class="dc-pulse-empty">Немає активних запусків</span>';
      return;
    }
    var currentProject = (window.filters && window.filters.project) || null;
    var html = '<span class="dc-pulse-label">Активні запуски</span>';
    launches.forEach(function (l) {
      var d = daysLeft(l.ends_on);
      var meta = d == null ? '' : (d < 0 ? 'завершено' : d === 0 ? 'фінал сьогодні' : 'D-' + d);
      var st = (l.status || '').toLowerCase();
      var aliases = Array.isArray(l.deal_aliases) ? l.deal_aliases.map(function(a){return String(a).toUpperCase()}) : [];
      var isActive = currentProject && (aliases.indexOf(String(currentProject).toUpperCase()) !== -1 || (l.code && l.code.toUpperCase() === String(currentProject).toUpperCase()));
      html += '<div class="dc-pulse-card' + (isActive ? ' active' : '') + '" data-id="' + l.id + '" data-code="' + (l.code || '') + '" data-alias="' + (aliases[0] || l.code || l.name) + '" title="' + (l.name || '') + ' · ' + meta + '">' +
        '<span class="dot ' + (st === 'active' ? 'active' : st === 'measure' ? 'measure' : 'completed') + '"></span>' +
        '<span class="nm">' + ((l.name || '').slice(0, 20)) + '</span>' +
        '<span class="meta">' + meta + '</span>' +
      '</div>';
    });
    bar.innerHTML = html;
    bar.querySelectorAll('.dc-pulse-card').forEach(function (card) {
      card.addEventListener('click', function () {
        var alias = card.dataset.alias;
        var projSelect = document.getElementById('f-project') || document.getElementById('f-model');
        if (projSelect) {
          for (var i = 0; i < projSelect.options.length; i++) {
            var opt = projSelect.options[i];
            if (opt.value && opt.value.toUpperCase() === alias.toUpperCase()) {
              projSelect.value = opt.value;
              projSelect.dispatchEvent(new Event('change', { bubbles: true }));
              break;
            }
          }
        }
      });
    });
  }

  function injectPulseBar() {
    if (document.getElementById('dc-pulse-bar')) return;
    var filterBar = document.getElementById('filter-bar');
    var topbar = document.querySelector('.topbar');
    var bar = document.createElement('div');
    bar.id = 'dc-pulse-bar';
    bar.className = 'dc-pulse';
    bar.innerHTML = '<span class="dc-pulse-label">Завантаження…</span>';
    if (filterBar && filterBar.parentNode) {
      filterBar.parentNode.insertBefore(bar, filterBar);
    } else if (topbar && topbar.parentNode) {
      topbar.parentNode.insertBefore(bar, topbar.nextSibling);
    } else {
      document.body.insertBefore(bar, document.body.firstChild);
    }
  }

  async function refreshPulse() {
    injectPulseBar();
    var launches = await fetchActiveLaunches();
    renderPulse(launches);
  }

  // Refresh при зміні filter (project) щоб active card підсвічувалась
  document.addEventListener('change', function (e) {
    if (e.target && (e.target.id === 'f-project' || e.target.id === 'f-model')) {
      setTimeout(refreshPulse, 100);
    }
  });

  /* ====== #392.3 Cross-page filter state propagation ======
     SMM/Retention/Tasks→Dashboard: коли юзер тиц на /upsell-ab/ або /meta-analytics/,
     передаємо вибраний період і проект через sessionStorage (single-origin) +
     URL hash для linkable share. */
  function persistFilters() {
    if (!window.filters) return;
    try {
      sessionStorage.setItem('dc-active-filters', JSON.stringify({
        project: window.filters.project, from: window.filters.from, to: window.filters.to,
        preset: window.filters.preset, ts: Date.now()
      }));
    } catch {}
  }
  document.addEventListener('change', function (e) {
    if (e.target && /^f-/.test(e.target.id || '')) setTimeout(persistFilters, 200);
  });
  document.addEventListener('click', function (e) {
    if (e.target && e.target.id && /apply|reset/.test(e.target.id)) setTimeout(persistFilters, 200);
  });

  /* ====== Init ====== */
  function init() {
    injectThemeButton();
    injectViewsButton();
    ensureTray();
    refreshPulse();
    // 14.06.2026 #392.5 — Auto-refresh Pulse кожні 5 хв з visibility/unload cleanup (prev — leak inf).
    function startPulseTimer() {
      if (window.__dcPulseTimer) return;
      window.__dcPulseTimer = setInterval(refreshPulse, 300000);
    }
    function stopPulseTimer() {
      if (window.__dcPulseTimer) { clearInterval(window.__dcPulseTimer); window.__dcPulseTimer = null; }
    }
    startPulseTimer();
    if (!window.__dcPulseLifecycleBound) {
      window.__dcPulseLifecycleBound = true;
      document.addEventListener('visibilitychange', function () {
        if (document.hidden) stopPulseTimer();
        else { refreshPulse(); startPulseTimer(); }
      });
      window.addEventListener('beforeunload', stopPulseTimer);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
  setTimeout(init, 600);
  setTimeout(init, 2000);
})();
