-- 20260916e_money_rpc_role_gate.sql
-- 16.09.2026 — рішення Вадима: гроші на рівні БД бачать ті самі ролі, що й у
-- гейті головного дашборду — ceo / coo / lead. Самореєстрацію через Google лишаємо
-- відкритою, покладаємось на ролі.
--
-- Проблема: після 20260916a роль `anon` закрита, але `authenticated` — це БУДЬ-ХТО,
-- хто зайшов через Google. handle_new_user заводить невідому пошту як
-- member/is_active=false (таких у базі троє з 13), і така людина в обхід UI читала
-- повну виручку через dashboard_kpi_summary та інші RPC.
--
-- Гейт ставимо ОДНІЄЮ умовою у WHERE кожної з 7 функцій. Службові виклики
-- (pg_cron без JWT, ETL і Edge з service_role) проходять — інакше зламались би
-- *_cached обгортки й крони.
--
-- Функції /finance/, /kasa/ і /pricing-analysis/ цим НЕ зачеплені: у них свій
-- список ролей (ceo/coo/cfo), і Артем-CFO працює саме там. На головний дашборд
-- CFO і так не пускав JS-гейт із червня — поведінка не змінилась.

-- ---------------------------------------------------------------------------
-- 1. Сам гейт
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.dashboard_money_visible(
  p_roles public.user_role[] DEFAULT ARRAY['ceo','coo','lead']::public.user_role[])
RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path TO 'public', 'pg_temp'
AS $function$
  SELECT CASE coalesce(
           nullif(current_setting('request.jwt.claims', true), '')::json ->> 'role', '')
    WHEN ''             THEN true    -- postgres / pg_cron: JWT немає
    WHEN 'service_role' THEN true    -- серверні ключі: ETL, Edge, крони
    WHEN 'anon'         THEN false
    ELSE public.current_user_has_role(p_roles)
  END;
$function$;

COMMENT ON FUNCTION public.dashboard_money_visible(public.user_role[]) IS
  'Гейт грошових RPC: службові виклики проходять, anon ні, залогінений — лише з дозволеною роллю (і is_active).';

REVOKE ALL ON FUNCTION public.dashboard_money_visible(public.user_role[]) FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.dashboard_money_visible(public.user_role[]) TO authenticated, service_role;

-- ---------------------------------------------------------------------------
-- 2. Вшиваємо гейт у 7 грошових RPC.
--    Якір `AND (p_project_values IS NULL` є рівно один раз у кожній з них
--    (перевірено запитом перед накатом). Блок ідемпотентний.
-- ---------------------------------------------------------------------------
DO $$
DECLARE r record; src text; newdef text;
BEGIN
  FOR r IN
    SELECT p.oid, p.proname, pg_get_functiondef(p.oid) AS def
    FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
    WHERE n.nspname = 'public'
      AND p.proname IN ('dashboard_kpi_summary','dashboard_kpi_with_delta','dashboard_extended_kpi',
                        'dashboard_daily_series','dashboard_hourly_series','dashboard_hourly_heatmap',
                        'dashboard_traffic_type_summary')
  LOOP
    src := r.def;
    IF position('dashboard_money_visible' in src) > 0 THEN
      CONTINUE;
    END IF;
    newdef := replace(src,
      'AND (p_project_values IS NULL',
      'AND public.dashboard_money_visible()' || chr(10) ||
      '      AND (p_project_values IS NULL');
    IF newdef = src THEN
      RAISE EXCEPTION 'Якір не знайдено у %', r.proname;
    END IF;
    EXECUTE newdef;
  END LOOP;
END $$;

-- ---------------------------------------------------------------------------
-- 3. dashboard_agg_deals_with_traffic: гейт у неї був, але гілка ELSE давала
--    повний доступ будь-якому залогіненому. Звужуємо до дозволених ролей.
--    Роль buyer лишається як була — свій зріз utm_term і лише свої значення.
--    (Повне тіло функції — у міграції; тут лише опис зміни.)
-- ---------------------------------------------------------------------------
-- див. накат: гілка `else` замінена на
--   elsif public.dashboard_money_visible() then <повний доступ>
--   else return; end if;

-- ---------------------------------------------------------------------------
-- 4. dashboard_rpc_cache тримає СИРІ результати грошових RPC у jsonb, а політика
--    rpc_cache_read дозволяла читати його будь-кому залогіненому — обхід усього
--    гейту вище. *_cached обгортки — SECURITY DEFINER, RLS їм не заважає.
-- ---------------------------------------------------------------------------
DROP POLICY IF EXISTS rpc_cache_read ON public.dashboard_rpc_cache;
REVOKE ALL ON public.dashboard_rpc_cache FROM anon, authenticated;

-- ---------------------------------------------------------------------------
-- Перевірено після накату (підстановкою request.jwt.claims):
--   ceo    -> 3 144 / 1 114 592,20 грн · agg 10 рядків
--   coo    -> 3 144 / 1 114 592,20 грн
--   lead   -> 3 144 / 1 114 592,20 грн (обидва)
--   member -> 0 / 0 · agg 0
--   buyer  -> 0 / 0 (свій зріз лишився у власній гілці)
--   cfo    -> 0 / 0 (на головний дашборд його й так не пускав JS-гейт)
--   anon   -> 0 / 0 · agg 0
--   service_role і postgres -> повні дані
-- Живий дашборд під vg@abrisart.com: Огляд і Аналітика показують ті самі числа.
