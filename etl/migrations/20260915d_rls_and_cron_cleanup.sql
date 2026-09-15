-- 15.09.2026 (четверта черга аудиту) — доступи і сміттєві крони.
-- Застосовано на wotghlaehnvxyeacznvv 15.09.2026.

-- ============================================================
-- 1. ТАБЛИЦІ БЕЗ RLS, ВІДКРИТІ ЧЕРЕЗ PostgREST
-- ============================================================
-- Supabase роздає новоствореним таблицям у public гранти anon/authenticated
-- автоматично. Якщо при цьому не увімкнути RLS — таблиця читається ключем,
-- який лежить у JS публічного сайту. Так вийшло з двома таблицями цієї сесії
-- і одним бекапом від 04.09.
--
-- Для всіх трьох правильний режим — RLS без політик (deny all): ETL ходить під
-- service_role, який RLS обходить, а дашборд ці таблиці не читає взагалі.

alter table public.ads_executor_rules enable row level security;
revoke all on public.ads_executor_rules from anon, authenticated;

alter table public._bak_ads_utm_20260915 enable row level security;
revoke all on public._bak_ads_utm_20260915 from anon, authenticated;

alter table public._backup_deals_iphone_relabel_20260904 enable row level security;
revoke all on public._backup_deals_iphone_relabel_20260904 from anon, authenticated;

alter table public._cron_job_backup_verify_pub_20260904 enable row level security;
revoke all on public._cron_job_backup_verify_pub_20260904 from anon, authenticated;

-- ============================================================
-- 2. SECURITY DEFINER VIEW
-- ============================================================
-- View у Postgres за замовчуванням виконується з правами ВЛАСНИКА. v_project_windows
-- читає dashboard_projects і launches — обидві під RLS. Відкритий anon-у DEFINER-view
-- віддавав би їх повністю, в обхід політик. Читають його лише SQL-функції
-- (resolve_ads_project, dashboard_project_pnl), фронт — ніколи.
alter view public.v_project_windows set (security_invoker = true);
revoke all on public.v_project_windows from anon, authenticated;

-- ============================================================
-- 3. СМІТТЄВІ ПЕР-ПУБЛІКАЦІЙНІ КРОНИ verify_pub_*
-- ============================================================
-- Розклад цих джоб — «хв год день місяць *», БЕЗ року, тож одноразова джоба
-- перезапускається ЩОРОКУ. Код (verify-publication-ig v7) чистить її при
-- спрацюванні, але у цих 23 дата вже минула: наступне спрацювання — вересень 2027,
-- і тоді в SMM-групу полетіли б 23 торішні «Час публікації!».
--
--   20 джоб — публікація вже у verified_status='requested' (питання поставлене,
--             далі відповідає людина кнопкою; джоба свою роботу зробила);
--    3 джоби — TG-only публікації, які функція і так пропускає (skipped_not_ig).
--
-- Знайти такі знову:
--   select jobid, jobname, schedule from cron.job where jobname like 'verify\_pub\_%';
select cron.unschedule(jobid) from cron.job where jobname like 'verify\_pub\_%';

-- ============================================================
-- ЩО ЛИШИЛОСЬ І ПОТРЕБУЄ ОКРЕМОГО РІШЕННЯ (не чіпав)
-- ============================================================
-- public._dispatch_debounce            — RLS off, гранти anon/authenticated. Таблиця
--   мертва (один рядок від 11.09, жодної згадки в жодному репо org), але не наша —
--   не чіпав, щоб не зламати невідомого писача.
-- 5 інших SECURITY DEFINER view: projects, v_project_label_bleed,
--   v_dashboard_webhook_health, inventory_variant_qty, v_creative_usages — усі старіші
--   за цю сесію, кожна потребує окремої перевірки, хто її читає.
-- 105 SECURITY DEFINER функцій викликаються anon, 116 — authenticated. Це велика
--   поверхня, яку не можна різати наосліп: потрібен окремий прохід по кожній.
-- 6 matview читаються anon/authenticated — для цього дашборду це навмисно.
-- auth_leaked_password_protection вимкнено — DreamCar HQ не використовує
--   password-auth (лише TG OAuth), тож практичного значення не має.
