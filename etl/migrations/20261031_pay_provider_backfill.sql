-- 31.10.2026 (Вадим: «в ок» — бекфіл pay_provider). Застосовано на wotghlaehnvxyeacznvv 31.10.2026.
-- Причина діри: reconcile_pay_provider (pg_cron reconcile-pay-provider, */10) бере лише 3 дні,
-- тригер tg_dashboard_deals_pay_provider у БД відсутній, тож угоди 08.09–04.10 лишились без провайдера.
-- Джерело — checkout_events.meta->>'gateway' (див. 20260915b_provider_project_executors.sql).
-- Результат: 11 566 угод заповнено; оплачених без провайдера за тиждень 4 947 → 334 (решта без події чекауту).
-- Відкат: update dashboard_deals d set pay_provider = null from _bak_pay_provider_backfill_20261031 b where b.id = d.id;
create table if not exists public._bak_pay_provider_backfill_20261031 as
with ce as (
  select distinct on (meta->>'order_id') meta->>'order_id' ref, meta->>'gateway' gw
  from checkout_events where meta ? 'gateway' and meta->>'gateway' is not null and meta->>'order_id' is not null
  order by 1, ts desc)
select d.id, ce.gw from dashboard_deals d join ce on ce.ref = split_part(d.raw_payload->>'deal_name',' - ',1)
where d.pay_provider is null and d.created_at >= '2026-03-01';
alter table public._bak_pay_provider_backfill_20261031 enable row level security;
update public.dashboard_deals d set pay_provider = b.gw
from public._bak_pay_provider_backfill_20261031 b where b.id = d.id and d.pay_provider is null;
