-- 15.09.2026 — «платний трафік» рахувався ЧОТИРМА способами. Рішення Вадима:
-- канон = placement-мітка Meta у utm_medium. Застосовано на wotghlaehnvxyeacznvv 15.09.2026.
--
-- ЧОМУ ЦЕ ВАЖЛИВО. По циклу #21 (AUDI Q7 Prestige, витрати 164 675 ₴):
--   placement-мітки (канон)        433 127 ₴ платної виручки → ROAS  2,63x
--   is_paid_deal (сигнатури)     1 649 361 ₴                 → ROAS 10,02x
--   isPaidSource (utm_source)    1 050 677 ₴                 → ROAS  6,40x
-- По 7 циклах з травня канон дає 2,4–3,5x, тоді як дашборд показував 8–17x.
-- Тобто реклама оцінювалась утричі краще, ніж працює насправді.
--
-- ЧОМУ САМЕ placement. Meta підставляє {{placement}} у utm_medium лише для
-- ОПЛАЧЕНИХ показів: instagram_feed, instagram_stories, instagram_reels,
-- facebook_mobile_feed, facebook_mobile_reels. Органіка таких міток не має —
-- там bare stories, post, account, telegram_bot, tma, newsletter.
-- Старі підходи зараховували до платного органіку з тією ж міткою креативу
-- (сигнатури) або будь-що з utm_source=instagram (тобто і сторіс з акаунту).

-- ============================================================
-- 1. Єдина канонічна функція
-- ============================================================
create or replace function public.is_paid_placement(p_utm_medium text)
returns boolean language sql immutable parallel safe
set search_path to 'public','pg_catalog' as $$
  select coalesce(p_utm_medium, '') ~* '^(facebook|instagram|fb|ig|messenger|audience_network)_';
$$;

comment on function public.is_paid_placement(text) is
 '15.09.2026 (рішення Вадима): КАНОНІЧНЕ визначення платного трафіку DreamCar — '
 'placement-мітка Meta у utm_medium. Замінило is_paid_deal() (пошук utm_campaign/content/term '
 'серед mv_paid_signatures), яке зараховувало до платного органіку з тією ж міткою креативу: '
 'по циклу #21 давало ROAS 10,02x замість реальних 2,63x.';

-- ============================================================
-- 2. Сім функцій, що фільтрували по p_traffic_type
-- ============================================================
-- Заміна в кожній: is_paid_deal(utm_campaign, utm_content, utm_term)
--               -> is_paid_placement(utm_medium)
-- Функції: dashboard_agg_by_person_core7, dashboard_daily_series,
--          dashboard_extended_kpi, dashboard_hourly_heatmap,
--          dashboard_hourly_series, dashboard_kpi_summary, dashboard_kpi_with_delta
--
-- do $outer$
-- declare r record; def text; nd text;
-- begin
--   for r in select p.oid, p.proname from pg_proc p join pg_namespace n on n.oid=p.pronamespace
--            where n.nspname='public' and p.proname in (...)
--   loop
--     def := pg_get_functiondef(r.oid);
--     nd  := replace(def, 'is_paid_deal(utm_campaign, utm_content, utm_term)',
--                         'is_paid_placement(utm_medium)');
--     if nd = def then raise exception 'anchor not found in %', r.proname; end if;
--     execute nd;
--   end loop;
-- end $outer$;
--
-- Контроль (цикл #21): було paid 4 112 угод / 1 649 361 ₴,
--                      стало paid 1 108 угод / 433 127 ₴ — збіг із прямим SQL.
--                      paid + organic = 4 830 = усі оплати, витоку немає.

-- ============================================================
-- 3. dashboard_agg_deals_with_traffic_core — власний CASE
-- ============================================================
-- Там була ТРЕТЯ логіка: спершу гардкод імен виконавців
--   utm_term IN ('vira','vera','artem','artyom','arthem') -> organic
-- (список ламався щоразу, як зʼявлявся новий медіабаєр — fortunatos у ньому немає),
-- потім список organic-медіумів, потім пошук серед mv_paid_signatures.
-- Замінено на: CASE WHEN is_paid_placement(d.utm_medium) THEN 'paid' ELSE 'organic' END

-- ============================================================
-- 4. mv_dashboard_utm_agg — той самий CASE, запечений у matview
-- ============================================================
-- Matview перебудовано з новим CASE, індекси відновлено:
--   CREATE UNIQUE INDEX mv_dashboard_utm_agg_pk ON public.mv_dashboard_utm_agg
--     USING btree (field, key, project, day, tt);
--   CREATE INDEX mv_dashboard_utm_agg_day ON public.mv_dashboard_utm_agg USING brin (day);
-- Крон mv-utm-agg-refresh-15min ('2,32 * * * *') перестворено після перебудови.
-- Контроль: matview і пряме SQL по циклу #21 дають однакові 433 127 / 1 493 072 ₴.

-- ============================================================
-- 5. Фронтенд
-- ============================================================
-- docs/index.html: isPaidSource(utm_source) -> isPaidPlacement(utm_medium),
-- регулярка дзеркалить SQL-функцію. Міняти лише разом із нею.

-- ЩО ЛИШИЛОСЬ: mv_paid_signatures і крон mv-paid-signatures-refresh-15min більше
-- нікому не потрібні — жодна функція на них не посилається. Не видаляв: спершу
-- хай попрацює тиждень на нових числах, потім прибрати разом із кроном.
