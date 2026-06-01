/* ===========================================================
   DreamCar Dashboard Auth Guard v1 (copy з HQ, не міняти без sync)
   Захищає сторінку через Supabase Auth — якщо немає сесії, показує overlay
   з CTA «Увійти через /hq». Поки немає сесії — контент схований.

   Підключення (один тег):
     <script src="/assets/js/auth-guard.js" defer></script>

   ВАЖЛИВО: window.HQ_CONFIG треба завантажити ПЕРЕД цим скриптом —
   або скрипт сам підвантажить /hq/config.js з team.dreamcar.ua.
   =========================================================== */
(function () {
  if (window.__dcAuthGuard) return;
  window.__dcAuthGuard = true;

  const STYLES = `
    #dc-auth-overlay {
      position: fixed; inset: 0; z-index: 99999;
      background: rgba(10,10,10,0.96);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      display: flex; align-items: center; justify-content: center;
      flex-direction: column; gap: 24px;
      font-family: 'Manrope', sans-serif; color: #fff;
      padding: 24px;
    }
    #dc-auth-overlay .dc-auth-card {
      background: #141414; border: 1px solid #2A2A2A;
      border-radius: 12px; padding: 36px 32px;
      max-width: 420px; width: 100%; text-align: center;
      box-shadow: 0 30px 80px -20px rgba(227,6,19,0.18);
    }
    #dc-auth-overlay h2 {
      font-family: 'Oswald','Bebas Neue',sans-serif;
      font-size: 28px; letter-spacing: 0.02em;
      margin: 0 0 8px; color: #fff;
    }
    #dc-auth-overlay h2 .red { color: #E30613; }
    #dc-auth-overlay p {
      color: #BBB; font-size: 14px; line-height: 1.5;
      margin: 0 0 24px;
    }
    #dc-auth-overlay .dc-auth-spinner {
      width: 28px; height: 28px;
      border: 3px solid #2A2A2A; border-top-color: #E30613;
      border-radius: 50%;
      animation: dc-auth-spin 0.8s linear infinite;
      margin: 0 auto 16px;
    }
    @keyframes dc-auth-spin { to { transform: rotate(360deg); } }
    #dc-auth-overlay .dc-auth-btn {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 12px 24px;
      font-family: 'Archivo Black', sans-serif;
      font-size: 13px; letter-spacing: 0.08em; text-transform: uppercase;
      background: #E30613; color: #fff;
      border: none; border-radius: 6px;
      text-decoration: none; cursor: pointer;
      transition: background 120ms, transform 120ms;
    }
    #dc-auth-overlay .dc-auth-btn:hover { background: #B8050F; transform: translateY(-1px); }
    #dc-auth-overlay .dc-auth-status {
      font-family: 'JetBrains Mono', monospace;
      font-size: 11px; color: #888;
      letter-spacing: 0.12em; text-transform: uppercase;
      margin-top: 16px;
    }
    body.dc-auth-locked { overflow: hidden; }
    body.dc-auth-locked > *:not(#dc-auth-overlay):not(script) {
      filter: blur(6px); pointer-events: none; user-select: none;
    }
  `;

  function injectStyles() {
    const s = document.createElement('style');
    s.id = 'dc-auth-guard-styles';
    s.textContent = STYLES;
    document.head.appendChild(s);
  }

  function buildOverlay(state) {
    const ov = document.createElement('div');
    ov.id = 'dc-auth-overlay';
    const next = encodeURIComponent(location.href);
    if (state === 'checking') {
      ov.innerHTML = `
        <div class="dc-auth-card">
          <div class="dc-auth-spinner"></div>
          <h2>DREAM<span class="red">CAR</span></h2>
          <p>Перевіряю доступ…</p>
        </div>
      `;
    } else if (state === 'denied') {
      ov.innerHTML = `
        <div class="dc-auth-card">
          <h2>DREAM<span class="red">CAR</span> · ДАШБОРД</h2>
          <p>Ця сторінка потребує авторизації команди DreamCar.</p>
          <button class="dc-auth-btn" id="dc-auth-google-btn" style="background:#fff;color:#1f1f1f;justify-content:center;gap:10px;cursor:pointer;width:100%;">
            <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
            Увійти через Google
          </button>
          <div style="margin-top:14px;font-size:11px;color:#888;">Або відкрий <a href="https://team.dreamcar.ua/hq/" style="color:#E30613;text-decoration:none;">team.dreamcar.ua/hq/</a> якщо щось не так</div>
          <div class="dc-auth-status">Доступ обмежено · команда DreamCar</div>
        </div>
      `;
      // Hook Google login button after DOM ready
      setTimeout(() => {
        const btn = document.getElementById('dc-auth-google-btn');
        if (btn) btn.onclick = async () => {
          btn.disabled = true;
          btn.textContent = 'Перенаправляю на Google...';
          try {
            const sb = window.supabase || await ensureSupabase();
            await sb.auth.signInWithOAuth({
              provider: 'google',
              options: { redirectTo: location.origin + location.pathname }
            });
          } catch (e) {
            btn.disabled = false;
            btn.textContent = 'Помилка — спробуй ще раз';
            console.error('[dc-auth] OAuth failed:', e);
          }
        };
      }, 100);
    } else if (state === 'error') {
      ov.innerHTML = `
        <div class="dc-auth-card">
          <h2>DREAM<span class="red">CAR</span></h2>
          <p>Помилка перевірки доступу. Спробуй оновити сторінку через 5 секунд.</p>
          <a class="dc-auth-btn" href="${location.href}">↻ Оновити</a>
        </div>
      `;
    }
    document.body.appendChild(ov);
    document.body.classList.add('dc-auth-locked');
  }

  function removeOverlay() {
    const ov = document.getElementById('dc-auth-overlay');
    if (ov) ov.remove();
    document.body.classList.remove('dc-auth-locked');
  }

  function loadScript(src) {
    return new Promise((res, rej) => {
      const s = document.createElement('script');
      s.src = src; s.async = true;
      s.onload = () => res();
      s.onerror = (e) => rej(e);
      document.head.appendChild(s);
    });
  }

  async function ensureSupabase() {
    if (window.supabase && window.supabase.auth) return window.supabase;
    if (!window.HQ_CONFIG) {
      await loadScript('https://team.dreamcar.ua/hq/config.js').catch(()=>{});
    }
    if (!window.HQ_CONFIG || !window.HQ_CONFIG.SUPABASE_URL) {
      throw new Error('HQ_CONFIG missing');
    }
    const mod = await import('https://esm.sh/@supabase/supabase-js@2');
    window.supabase = mod.createClient(
      window.HQ_CONFIG.SUPABASE_URL,
      window.HQ_CONFIG.SUPABASE_ANON_KEY,
      { auth: { persistSession: true, autoRefreshToken: true, detectSessionInUrl: true } }
    );
    return window.supabase;
  }

  async function check() {
    injectStyles();
    buildOverlay('checking');
    try {
      const sb = await Promise.race([
        ensureSupabase(),
        new Promise((_, rej) => setTimeout(() => rej(new Error('SDK timeout')), 8000))
      ]);
      const { data: { session } } = await Promise.race([
        sb.auth.getSession(),
        new Promise((_, rej) => setTimeout(() => rej(new Error('Session timeout')), 6000))
      ]);
      if (session && session.user) {
        removeOverlay();
        window.dispatchEvent(new CustomEvent('dc-auth-ok', { detail: { user: session.user } }));
        console.log('[dc-auth] ✓ session:', session.user.email);
      } else {
        removeOverlay();
        buildOverlay('denied');
      }
    } catch (e) {
      console.error('[dc-auth] error:', e);
      removeOverlay();
      buildOverlay('denied');
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', check);
  } else {
    check();
  }
})();
