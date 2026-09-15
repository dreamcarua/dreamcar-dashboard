-- 20260916f_close_pnl_matviews.sql
-- 16.09.2026
--
-- Дві P&L-матв'ю віддавались будь-якому залогіненому (а вхід через Google відкритий).
-- Перевірено grep по репо: жодна сторінка не читає їх НАПРЯМУ — імена трапляються
-- лише у коментарях і підписах UI. Реальні читачі — SECURITY DEFINER RPC
-- (dashboard_period_pnl, dashboard_project_pnl, dashboard_project_pnl_cached,
-- dashboard_finance_overview) і серверні Edge-функції звітів із ключем sb_secret.
-- Тож знімаємо `authenticated` — сторінки не зачеплені.
REVOKE ALL ON public.mv_finance_daily_pnl FROM anon, authenticated;
REVOKE ALL ON public.mv_dashboard_project_pnl FROM anon, authenticated;

-- Перевірено після накату під vg@abrisart.com:
--   /finance/          — Net Profit 479,5k грн, Revenue 1,97M грн, 4 897 оплат
--   /pricing-analysis/ — 13 проєктів, середній NET 135k грн/день
