-- 04.09.2026 · розділення червневого й липневого iPhone-циклів + детектор перетікання міток
-- Застосовано через Supabase apply_migration 04.09.2026. Після міграції виконано:
--   update dashboard_deals set project='IPHONE 17 PRO MAX 2'
--    where project='IPHONE 17 PRO MAX'
--      and substring(coalesce(raw_payload->>'deal_name',''),'DCI-[^-]+-[^-]*-[^-]*-(\d{8})') between '20260702' and '20260705';
--   -> 905 pay (167 860 ₴) + 191 pending переміщено; refresh materialized view mv_dashboard_projects_stats.
-- Відкат: public._backup_deals_iphone_relabel_20260904 (1277 рядків, project_before).

create table if not exists public._backup_deals_iphone_relabel_20260904 as
select id, project as project_before, status, amount, paid_at,
       raw_payload->>'deal_name' as deal_name, now() as backed_up_at
from public.dashboard_deals
where project = 'IPHONE 17 PRO MAX'
  and substring(coalesce(raw_payload->>'deal_name',''), 'DCI-[^-]+-[^-]*-[^-]*-(\d{8})') >= '20260702';

create or replace function public.tg_dashboard_deals_normalize_project()
 returns trigger
 language plpgsql
as $function$
declare
  dn text := coalesce(NEW.raw_payload->>'deal_name', NEW.raw_payload->>'name', NEW.raw_payload->>'model', '');
  dt date := coalesce(
      to_date(nullif(substring(coalesce(NEW.raw_payload->>'deal_name',''), 'DCI-[^-]+-[^-]*-[^-]*-(\d{8})'), ''), 'YYYYMMDD'),
      NEW.paid_at::date, NEW.created_at::date, current_date);
begin
  if dn ilike '%DCI-iphone-%' then
    -- префікс DCI-iphone- обслуговував червневий цикл (06.06) і, через невимкнені старі лінки,
    -- ще й липневий (02-05.07). Розводимо по даті угоди; поза цими вікнами мітку джерела не чіпаємо.
    if dt >= date '2026-07-02' and dt <= date '2026-07-05' then
      NEW.project := 'IPHONE 17 PRO MAX 2';
    elsif dt < date '2026-07-02' then
      NEW.project := 'IPHONE 17 PRO MAX';
    end if;
  elsif dn ilike '%DCI-promax-%' then
    NEW.project := 'IPHONE 17 PRO MAX 2';
  elsif dn ilike '%DCI-moto-%' then
    NEW.project := 'MOTORCYCLE';
  elsif dn ilike '%DCI-hummer-%' then
    NEW.project := 'HUMMER H2';
  elsif dn ilike '%DCI-audi-%' then
    NEW.project := 'AUDI E-TRON';
  elsif dn ilike '%DCI-bmwx6m-%' then
    NEW.project := 'BMW X6M';
  end if;
  return NEW;
end;
$function$;

create or replace view public.v_project_label_bleed as
select d.project                       as deal_label,
       owner_p.name                    as label_project,
       win_p.name                      as window_project,
       count(*)                        as deals,
       round(sum(d.amount))            as revenue,
       min(d.paid_at::date)            as first_paid,
       max(d.paid_at::date)            as last_paid
from public.dashboard_deals d
join public.dashboard_projects owner_p on d.project = any(owner_p.deal_project_values)
join public.dashboard_projects win_p
  on d.paid_at::date between win_p.date_start and win_p.date_end
 and win_p.id <> owner_p.id
where d.status = 'pay'
group by 1,2,3
having count(*) >= 50;

comment on view public.v_project_label_bleed is
 'Оплати, що несуть мітку одного проєкту, але сплачені у вікні іншого. Поріг 50 угод = ознака невимкнених старих лінків, а не пізніх доплат. Перевіряти після старту кожного циклу.';
