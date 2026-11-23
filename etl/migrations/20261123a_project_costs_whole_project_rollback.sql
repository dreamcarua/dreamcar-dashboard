-- Відкат 20261123a. Спершу перевести витрати «на весь проєкт» в одну дату (старт проєкту = вже spent_at).
update public.project_costs set whole_project = false where whole_project;
do $$
declare v text; n text;
begin
  v := pg_get_viewdef('public.mv_finance_daily_pnl'::regclass);
  n := replace(replace(replace(v, 'SELECT pc_1.d,', 'SELECT pc_1.spent_at AS d,'),
       'FROM project_costs_daily pc_1', 'FROM project_costs pc_1'), 'GROUP BY pc_1.d', 'GROUP BY pc_1.spent_at');
  execute 'drop materialized view public.mv_finance_daily_pnl';
  execute 'create materialized view public.mv_finance_daily_pnl as ' || n;
  execute 'create unique index mv_finance_daily_pnl_day_pk on public.mv_finance_daily_pnl using btree (day)';
  execute 'create index mv_finance_daily_pnl_day_brin on public.mv_finance_daily_pnl using brin (day)';
  execute 'revoke all on public.mv_finance_daily_pnl from public, anon, authenticated';
  execute 'grant all on public.mv_finance_daily_pnl to service_role';
  v := pg_get_functiondef('public.dashboard_period_pnl(date,date,text,uuid[])'::regprocedure);
  execute replace(v, 'LEFT JOIN project_costs_daily pc ON pc.d BETWEEN', 'LEFT JOIN project_costs pc ON pc.spent_at BETWEEN');
end $$;
drop view if exists public.project_costs_daily;
alter table public.project_costs drop column if exists whole_project;
