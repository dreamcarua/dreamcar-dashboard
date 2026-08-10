-- =====================================================================
-- 021 — Фінанси: «Додаткові надходження» (крипта / ФОП / інше)
-- Запит Вадима, 10.08.2026.
-- Правила (узгоджено):
--   crypto — net = сума × (1 − власний_% запису)      (свій % на кожен запис)
--   fop    — net = сума × (1 − Σ активних percent_rates) («звичайна схема»)
--   other  — net = сума (без вирахувань)
-- Чисті суми додаються в загальний P&L (Огляд) на фронті.
-- RLS як у finance-таблиць: read=team, write=cfo+.
-- =====================================================================

create table if not exists public.additional_income (
  id            uuid primary key default gen_random_uuid(),
  occurred_on   date not null default current_date,
  kind          text not null check (kind in ('crypto','fop','other')),
  amount_uah    numeric not null check (amount_uah >= 0),   -- ГРОСС (до вирахувань), у гривні-еквіваленті
  deduction_pct numeric check (deduction_pct is null or (deduction_pct >= 0 and deduction_pct <= 100)), -- лише crypto: власний %
  source        text,
  note          text,
  created_by    uuid references public.users(id) on delete set null,
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now(),
  deleted_at    timestamptz
);
create index if not exists additional_income_date_idx on public.additional_income (occurred_on) where deleted_at is null;

-- Σ активних системних відсотків на дату (для fop «звичайна схема»)
create or replace function public.fin_system_rate_at(d date)
returns numeric language sql stable set search_path to 'public' as
$$
  select coalesce(sum(rate_pct), 0)
    from public.percent_rates
   where applies_to = 'all'
     and valid_from <= d
     and (valid_to is null or valid_to >= d)
$$;

-- Агрегація дод. надходжень за період: totals + by_kind + daily(net) + rows(net на рядок)
create or replace function public.dashboard_additional_income(p_from date, p_to date)
returns jsonb language plpgsql security definer set search_path to 'public' as
$$
declare v_res jsonb;
begin
  with base as (
    select ai.id, ai.occurred_on, ai.kind, ai.amount_uah, ai.deduction_pct, ai.source, ai.note,
      case ai.kind
        when 'crypto' then coalesce(ai.deduction_pct, 0)
        when 'fop'    then public.fin_system_rate_at(ai.occurred_on)
        else 0
      end as eff_pct
    from public.additional_income ai
    where ai.deleted_at is null
      and ai.occurred_on between p_from and p_to
  ),
  calc as (
    select *, round(amount_uah * (1 - eff_pct/100.0), 2) as net_uah,
           round(amount_uah * (eff_pct/100.0), 2) as deduction_uah
    from base
  )
  select jsonb_build_object(
    'gross',     coalesce(round(sum(amount_uah),2), 0),
    'deduction', coalesce(round(sum(deduction_uah),2), 0),
    'net',       coalesce(round(sum(net_uah),2), 0),
    'count',     count(*),
    'by_kind', coalesce((
      select jsonb_object_agg(k, obj) from (
        select kind as k, jsonb_build_object('gross', round(sum(amount_uah),2), 'net', round(sum(net_uah),2), 'count', count(*)) as obj
        from calc group by kind
      ) s), '{}'::jsonb),
    'daily', coalesce((
      select jsonb_agg(jsonb_build_object('day', d, 'net', n) order by d)
        from (select occurred_on as d, sum(net_uah) as n from calc group by occurred_on) s), '[]'::jsonb),
    'rows', coalesce((
      select jsonb_agg(jsonb_build_object(
        'id', id, 'occurred_on', occurred_on, 'kind', kind, 'amount_uah', amount_uah,
        'deduction_pct', deduction_pct, 'eff_pct', eff_pct, 'net_uah', net_uah,
        'source', source, 'note', note) order by occurred_on desc, id)
        from calc), '[]'::jsonb)
  ) into v_res from calc;
  return coalesce(v_res, jsonb_build_object('gross',0,'deduction',0,'net',0,'count',0,'by_kind','{}'::jsonb,'daily','[]'::jsonb,'rows','[]'::jsonb));
end;
$$;
grant execute on function public.fin_system_rate_at(date) to authenticated, anon;
grant execute on function public.dashboard_additional_income(date,date) to authenticated, anon;

-- RLS: read=team, write=cfo+ (як project_costs/percent_rates)
alter table public.additional_income enable row level security;
drop policy if exists "additional_income: team read" on public.additional_income;
drop policy if exists "additional_income: cfo+ write" on public.additional_income;
create policy "additional_income: team read" on public.additional_income
  for select using (public.current_user_has_role(array['ceo','coo','cfo','lead','member','designer']::user_role[]));
create policy "additional_income: cfo+ write" on public.additional_income
  for all using (public.current_user_has_role(array['ceo','coo','cfo']::user_role[]))
          with check (public.current_user_has_role(array['ceo','coo','cfo']::user_role[]));
grant select, insert, update, delete on public.additional_income to authenticated;
