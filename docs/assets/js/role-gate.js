/* ===========================================================
   DreamCar Dashboard Role Gate v1
   Обмеження UI за роллю users.role.

   Роль `buyer` (медіабаєр) → бачить ЛИШЕ розділ #terms («Виконавець»),
   і в ньому лише свої рядки (row-level ріже RLS: buyer_read_own_deals
   → utm_term = ANY(users.utm_terms)).

   ВАЖЛИВО: це UI-зручність, НЕ захист. Реальний захист — RLS у Postgres.
   Навіть якщо хтось обійде цей скрипт через DevTools — дані не віддадуться.

   Підключення (ПІСЛЯ auth-guard.js):
     <script src="/assets/js/role-gate.js" defer></script>
   =========================================================== */
(function () {
  if (window.__dcRoleGate) return;
  window.__dcRoleGate = true;

  const BUYER_ROUTE = 'terms';

  async function loadMe() {
    const sb = window.supabase;
    if (!sb || !sb.auth) return null;
    const { data: { user } } = await sb.auth.getUser();
    if (!user) return null;
    // auth_id → users; якщо не знайшли, пробуємо alias
    let { data } = await sb.from('users')
      .select('role, utm_terms, name')
      .eq('auth_id', user.id).eq('is_active', true).maybeSingle();
    // 14.08.2026 (аудит): fallback на alias був лише в коментарі, коду не було.
    // Наслідок — байєр, залогінений alias-акаунтом, не розпізнавався (me=null),
    // gate мовчки виходив і показував ВСІ розділи замість лише «Виконавець».
    if (!data) {
      const r2 = await sb.from('users')
        .select('role, utm_terms, name')
        .contains('auth_id_aliases', [user.id]).eq('is_active', true).maybeSingle();
      data = r2.data || null;
    }
    return data || null;
  }

  function lockToTerms() {
    const hideEl = (el) => { if (el) el.style.display = 'none'; };
    // 1) внутрішні розділи — лишаємо тільки «Виконавець»
    document.querySelectorAll('.nav-item[data-route]').forEach(el => {
      if (el.getAttribute('data-route') !== BUYER_ROUTE) hideEl(el);
    });
    // 2) зовнішні розділи (Фінанси, Каса, Цінова, Upsell, Meta) — прибираємо всі
    document.querySelectorAll('.nav-item[href]').forEach(hideEl);

    // 3) форсимо маршрут; будь-яка спроба піти в інший розділ повертає на #terms
    const force = () => {
      const h = (location.hash || '').replace('#', '').split('?')[0].trim();
      if (h !== BUYER_ROUTE) {
        location.hash = '#' + BUYER_ROUTE;
      }
    };
    force();
    window.addEventListener('hashchange', force);
    document.body.setAttribute('data-dc-role', 'buyer');
    console.log('[role-gate] buyer → locked to #' + BUYER_ROUTE);
  }

  function isHome() {
    const p = location.pathname.replace(/\/+$/, '');
    return p === '' || p === '/index.html';
  }

  async function run() {
    try {
      const me = await loadMe();
      if (!me) return;
      if (me.role === 'buyer') {
        // Прямий захід за URL (/finance/, /kasa/, …) → повертаємо на дозволений розділ.
        // Дані там і так ріже RLS, але не показуємо порожні чужі сторінки.
        if (!isHome()) { location.replace('/#' + BUYER_ROUTE); return; }
        lockToTerms();
        // Порожній utm_terms = fail-closed: RLS не віддасть жодного рядка.
        if (!me.utm_terms || !me.utm_terms.length) {
          console.warn('[role-gate] buyer без utm_terms — даних не буде (fail-closed)');
        }
      }
    } catch (e) {
      console.warn('[role-gate] skip:', e);
    }
  }

  window.addEventListener('dc-auth-ok', () => setTimeout(run, 200));
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(run, 600));
  } else {
    setTimeout(run, 600);
  }
})();
