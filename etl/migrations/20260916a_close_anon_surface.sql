-- 20260916a_close_anon_surface.sql
-- 16.09.2026
--
-- Знахідка: логін-гейт на дашбордах — лише візуальний. Ролі `anon` (публічний ключ,
-- який за визначенням лежить у фронтенді) вистачало, щоб прочитати гроші в обхід гейту.
-- Перевірено живим запитом з публічним ключем без сесії:
--   rpc/dashboard_kpi_summary      -> 200, revenue 1 966 121,20 грн, 3 691 покупців
--   mv_finance_daily_pnl           -> 200, щоденний P&L
--   mv_dashboard_project_pnl       -> 200, P&L по кожному проєкту
--   mv_dashboard_utm_agg           -> 200, уся UTM-агрегація з виручкою
--   v_project_label_bleed          -> 200, угоди + виручка
--   tg_listening_chats             -> 200, chat_id Telegram
--   experiments, retention_message_history, projects, v_creative_usages,
--   inventory_variant_qty, mv_upsell_daily, mv_dashboard_projects_stats,
--   mv_dashboard_filter_options, _dispatch_debounce -> 200
-- Закритими вже були: dashboard_deals, dashboard_ads_data, users, kasa_transactions,
-- app_secrets, v_dashboard_webhook_health, dashboard_agg_deals_with_traffic (RLS-обгортка).
--
-- Чому це безпечно зняти: за 24 години логів edge_logs роль `anon` зробила 92 запити,
-- з них 82 — /auth/v1/token (сам логін), решта ~10 — цей аудит. Уся прод-робота йде
-- або ключем sb_secret_* (23 447 запитів: ETL, Edge, крони), або JWT `authenticated`
-- (5 200: дашборди після логіну), або legacy service_role (2 090). Даних роллю `anon`
-- у проді не читає ніхто. Оплати (checkout_events) пишуться Edge-функцією sb_secret.

-- ---------------------------------------------------------------------------
-- 1. Таблиці, в'ю і матв'ю: знімаємо явний грант anon=r
-- ---------------------------------------------------------------------------
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM anon;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM anon;

-- ---------------------------------------------------------------------------
-- 2. Функції: у них ДВА джерела доступу — явний anon=X і успадкований PUBLIC (=X).
--    Знімати треба обидва, інакше anon лишається через PUBLIC.
--    Функції з розширень (pg_net, pgcrypto, uuid-ossp...) не чіпаємо — у них PUBLIC
--    є частиною контракту розширення.
--    Там, де доступ authenticated/service_role тримався ЛИШЕ на PUBLIC, спершу
--    видаємо явний грант, щоб не зламати статус-кво.
-- ---------------------------------------------------------------------------
DO $$
DECLARE r record; acl text;
BEGIN
  FOR r IN
    SELECT p.oid::regprocedure AS sig, p.proacl
    FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
    WHERE n.nspname = 'public'
      AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = p.oid AND d.deptype = 'e')
  LOOP
    acl := coalesce(array_to_string(r.proacl, ','), '');
    IF r.proacl IS NULL OR acl LIKE '%=X/%' THEN
      IF acl NOT LIKE '%authenticated=X%' THEN
        EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO authenticated', r.sig);
      END IF;
      IF acl NOT LIKE '%service_role=X%' THEN
        EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO service_role', r.sig);
      END IF;
    END IF;
    EXECUTE format('REVOKE EXECUTE ON FUNCTION %s FROM PUBLIC, anon', r.sig);
  END LOOP;
END $$;

-- ---------------------------------------------------------------------------
-- 3. Щоб це не відросло: Supabase має ALTER DEFAULT PRIVILEGES, який видає права
--    anon на КОЖЕН новий об'єкт у public. Саме через це `_core` знову відкрилась
--    після DROP+CREATE у 20260915h. Знімаємо anon з дефолтів.
-- ---------------------------------------------------------------------------
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON TABLES FROM anon;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON SEQUENCES FROM anon;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE EXECUTE ON FUNCTIONS FROM anon;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;

-- ---------------------------------------------------------------------------
-- 4. _dispatch_debounce — єдина таблиця у public без RLS (лінтер ERROR).
--    Не наша, службова для воркфлоу компресії; читає її service_role, який RLS обходить.
-- ---------------------------------------------------------------------------
ALTER TABLE public._dispatch_debounce ENABLE ROW LEVEL SECURITY;

-- ---------------------------------------------------------------------------
-- 5. П'ять в'ю з SECURITY DEFINER (лінтер ERROR): читають базові таблиці правами
--    власника, тобто в обхід RLS. Жодна не використовується у коді жодного дашборду
--    (перевірено grep по репо). Переводимо на security_invoker.
-- ---------------------------------------------------------------------------
ALTER VIEW public.projects                   SET (security_invoker = true);
ALTER VIEW public.v_project_label_bleed      SET (security_invoker = true);
ALTER VIEW public.v_dashboard_webhook_health SET (security_invoker = true);
ALTER VIEW public.inventory_variant_qty      SET (security_invoker = true);
ALTER VIEW public.v_creative_usages          SET (security_invoker = true);

-- ---------------------------------------------------------------------------
-- Перевірено після накату:
--   anon: 0 таблиць з SELECT, 0 функцій з EXECUTE у public
--   authenticated: 106 таблиць, 301 функція — як і було
--   повторна проба публічним ключем: усі 11 об'єктів -> 401 permission denied
--   усі 6 дашбордів під логіном працюють: index, finance, kasa, pricing-analysis,
--   upsell-ab, meta-analytics, marketing-critic + team.dreamcar.ua/hq
-- ---------------------------------------------------------------------------
