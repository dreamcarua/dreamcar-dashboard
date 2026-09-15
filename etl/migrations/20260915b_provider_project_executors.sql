-- 15.09.2026 (друга черга аудиту, запит Вадима: «Платіжка — заповнюй / витрати по
-- проєкту — виправляй / кілька виконавців на акаунт — дороби»).
-- Застосовано на wotghlaehnvxyeacznvv 15.09.2026.

-- ============================================================
-- 1. ПЛАТІЖКА: справжнє джерело — checkout_events.meta->>'gateway'
-- ============================================================
-- crm_deals.pay_provider у MySQL заповнювався лише колбеками WayForPay і Platon
-- (обидва зникли: WFP з 13.08, Platon з 02.09). Але наш власний трекер чекауту
-- пише шлюз у checkout_events.meta: {"gateway": "...", "order_id": "DCI-..."}.
-- order_id == order_reference == перша частина dashboard_deals.raw_payload->>'deal_name'.

create index if not exists idx_checkout_events_order_id
  on checkout_events ((meta->>'order_id')) where meta ? 'gateway';

-- Бекфіл історії (не чіпає вже проставлені значення — ручні мітки операторів головніші).
with ce as (
  select distinct on (meta->>'order_id') meta->>'order_id' ref, meta->>'gateway' gw
  from checkout_events
  where meta ? 'gateway' and meta->>'gateway' is not null and meta->>'order_id' is not null
  order by 1, ts desc
)
update dashboard_deals d
set pay_provider = ce.gw
from ce
where ce.ref = split_part(d.raw_payload->>'deal_name',' - ',1)
  and d.pay_provider is null
  and d.created_at >= '2026-03-01';

-- Надалі — тригер на кожну нову/оновлену угоду.
create or replace function tg_deals_stamp_pay_provider() returns trigger language plpgsql as $$
declare v_ref text; v_gw text;
begin
  if NEW.pay_provider is not null then return NEW; end if;
  v_ref := split_part(NEW.raw_payload->>'deal_name', ' - ', 1);
  if v_ref is null or v_ref = '' then return NEW; end if;
  select ce.meta->>'gateway' into v_gw
  from checkout_events ce
  where ce.meta ? 'gateway' and ce.meta->>'order_id' = v_ref
    and ce.meta->>'gateway' is not null
  order by ce.ts desc limit 1;
  if v_gw is not null then NEW.pay_provider := v_gw; end if;
  return NEW;
end $$;

drop trigger if exists tg_dashboard_deals_pay_provider on dashboard_deals;
create trigger tg_dashboard_deals_pay_provider
before insert or update on dashboard_deals
for each row execute function tg_deals_stamp_pay_provider();

-- Подія чекауту може прийти ПІСЛЯ угоди — добираємо щогодини (pg_cron 'reconcile-pay-provider', 17 * * * *).
create or replace function reconcile_pay_provider(p_since interval default interval '3 days')
returns integer language plpgsql as $$
declare n integer;
begin
  with ce as (
    select distinct on (meta->>'order_id') meta->>'order_id' ref, meta->>'gateway' gw
    from checkout_events
    where ts >= now() - p_since and meta ? 'gateway' and meta->>'gateway' is not null
    order by 1, ts desc
  )
  update dashboard_deals d set pay_provider = ce.gw
  from ce
  where ce.ref = split_part(d.raw_payload->>'deal_name',' - ',1)
    and d.pay_provider is null and d.created_at >= now() - p_since - interval '2 days';
  get diagnostics n = row_count; return n;
end $$;

-- select cron.schedule('reconcile-pay-provider', '17 * * * *',
--   $$select reconcile_pay_provider(interval '3 days')$$);

-- ============================================================
-- 2. ВИТРАТИ ПО ПРОЄКТУ: dashboard_ads_data.project
-- ============================================================
-- Раніше spend фільтрувався ЛИШЕ по датах: при виборі розіграшу виручка звужувалась,
-- а витрати ні → ROI/ROAS/CPA завищені по витратах при будь-якому перетині циклів.

create or replace view v_project_windows as
select p.code, p.name, p.date_start, p.date_end,
       coalesce(p.deal_project_values, array[upper(p.name)]) as project_values,
       'dashboard_projects'::text as src
from dashboard_projects p
union all
select l.code, l.name, l.starts_on, l.ends_on,
       coalesce(l.deal_aliases, array[upper(l.name)]),
       'launches'
from launches l
where l.is_active and l.status in ('active','measure','completed','archived')
  and l.starts_on is not null
  and coalesce(l.code,'') not in (select code from dashboard_projects);

create or replace function resolve_ads_project(d date)
returns text language sql stable as $$
  select w.project_values[1]
  from v_project_windows w
  where d >= w.date_start
    and d <= coalesce(w.date_end, current_date)
    and coalesce(w.date_end, current_date) < date '2030-01-01'   -- lifetime-проєкти не «поточні»
  order by w.date_start desc
  limit 1;
$$;

alter table dashboard_ads_data add column if not exists project text;

create or replace function tg_ads_stamp_project() returns trigger language plpgsql as $$
begin
  if NEW.project is null and NEW.date_start is not null then
    NEW.project := resolve_ads_project(NEW.date_start);
  end if;
  return NEW;
end $$;

drop trigger if exists tg_dashboard_ads_stamp_project on dashboard_ads_data;
create trigger tg_dashboard_ads_stamp_project
before insert or update on dashboard_ads_data
for each row execute function tg_ads_stamp_project();

update dashboard_ads_data set project = resolve_ads_project(date_start) where project is null;

-- launches.iphone2 (03–04.07) — той самий липневий цикл, що dashboard_projects.iphone_17_jul2026.
-- Без alias резолвер віддавав 'IPHONE2', якого немає серед значень dashboard_deals.project.
update launches set deal_aliases = array['IPHONE 17 PRO MAX 2']
where code = 'iphone2' and deal_aliases is null;

-- ============================================================
-- 3. ВИКОНАВЦІ: акаунт ≠ один медіабаєр
-- ============================================================
-- ads_account_to_executor мапить акаунт на ОДНОГО виконавця. У CLUB UAH працюють
-- двоє (artem + fortunatos), тож при збої url_tags весь акаунт штампувався одним іменем.

alter table ads_account_to_executor add column if not exists is_exclusive boolean not null default true;
update ads_account_to_executor set is_exclusive = false
where ad_account_id in ('1057590556523878','1469553690525881','1320925609223169');

create table if not exists ads_executor_rules (
  id                bigserial primary key,
  priority          int     not null default 100,   -- менше = перевіряється раніше
  ad_account_id     text,                           -- null = будь-який акаунт
  campaign_pattern  text,                           -- ILIKE; null = будь-яка
  adset_pattern     text,
  ad_name_pattern   text,
  executor_utm_term text    not null,
  note              text,
  is_active         boolean not null default true,
  created_at        timestamptz default now()
);
comment on table ads_executor_rules is
 '15.09.2026: виконавець за патерном назви кампанії/адсету. Застосовується ПІСЛЯ url_tags і carry-forward, ПЕРЕД account-level дефолтом.';

insert into ads_executor_rules (priority, ad_account_id, campaign_pattern, executor_utm_term, note)
select 10, '1057590556523878', 'Fortunatos |%', 'fortunatos',
       'Конвенція назв кампаній: <Виконавець> | DC | <Модель> | <Темп> | <дата>'
where not exists (select 1 from ads_executor_rules where campaign_pattern = 'Fortunatos |%');

-- Перекваліфікація рядків, які лишились із підписом account-level fallback
-- (utm_campaign IS NULL + facebook/cpc — саме те, що ставив старий код).
update dashboard_ads_data a
set utm_term = r.executor_utm_term
from ads_executor_rules r
where r.is_active
  and (r.ad_account_id is null or r.ad_account_id = a.ad_account_id)
  and (r.campaign_pattern is null or a.campaign_name ilike r.campaign_pattern)
  and a.utm_campaign is null and a.utm_source = 'facebook' and a.utm_medium = 'cpc'
  and a.utm_term is distinct from r.executor_utm_term
  and a.date_start >= '2026-06-01';

refresh materialized view mv_dashboard_filter_options;
