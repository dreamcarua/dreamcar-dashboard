-- 20260916d_close_internal_matviews.sql
-- 16.09.2026
--
-- Продовження 20260916a, на рівень вище: закривши `anon`, лишається питання, що
-- бачить `authenticated`. А `authenticated` — це БУДЬ-ЯКИЙ, хто зайшов через Google:
-- вхід відкритий, handle_new_user заводить невідомі пошти як member/is_active=false,
-- і таких у базі вже троє. Роль-гейт живе у JS сторінки, а PostgREST його не знає.
--
-- Цим кроком закриваємо ЛИШЕ ті матв'ю, які не читає жодна сторінка — до них
-- ходять тільки SECURITY DEFINER функції, тобто правами власника, і відкликання
-- `authenticated` на них нічого не ламає:
--   mv_dashboard_utm_agg        <- dashboard_agg_deals_with_traffic_core (definer)
--   mv_dashboard_projects_stats <- dashboard_projects_with_stats (definer),
--                                  refresh_dashboard_projects_stats (definer)
--
-- НЕ чіпаємо тут (потрібне рішення власника, які ролі бачать гроші):
--   mv_finance_daily_pnl, mv_dashboard_project_pnl — читають сторінки /finance/
--     і /pricing-analysis/ напряму; треба RPC-обгортка з роль-чеком;
--   mv_dashboard_filter_options — читає головний дашборд, там лише довідник значень;
--   mv_upsell_daily — має ДВІ перевантажені upsell_daily(), одна з них
--     SECURITY INVOKER, тож відкликання зламало б /upsell-ab/.

REVOKE ALL ON public.mv_dashboard_utm_agg FROM anon, authenticated;
REVOKE ALL ON public.mv_dashboard_projects_stats FROM anon, authenticated;

-- Перевірено після накату: «Проекти» (225 758 лідів, 77 151 760 грн лайфтайм)
-- і «Джерела» (14 значень, 6 010 лідів) працюють — обидва йдуть через definer-RPC.
