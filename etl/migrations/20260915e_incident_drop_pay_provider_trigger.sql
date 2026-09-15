-- 15.09.2026 — ІНЦИДЕНТ: тригер із міграції 20260915b поклав ETL MySQL на 2 години.
-- Застосовано на wotghlaehnvxyeacznvv 15.09.2026 ~17:35 UTC.
--
-- Хронологія:
--   17:13 UTC  застосовано 20260915b (тригер tg_dashboard_deals_pay_provider)
--   16:30 UTC  прогін ETL MySQL падає: 57014 canceling statement due to statement timeout
--              на upsert 27 рядків у dashboard_deals
--   17:27 UTC  алерт «Revenue завис (2h нуль)» з порадою вимкнути всю рекламу
--   17:33 UTC  тригер прибрано, ETL запущено вручну — дані наздогнали
--
-- Причина: тригер на КОЖЕН рядок шукав шлюз у checkout_events запитом із
--   ORDER BY ce.ts DESC LIMIT 1
-- Планувальник через цей ORDER BY брав індекс ce_ts (по даті) замість партіального
-- idx_checkout_events_order_id і сканував назад до першого збігу. Для order_id без
-- збігу — скан усіх ~165k рядків:
--   з ORDER BY, збіг є      → 0,14 мс
--   з ORDER BY, збігу немає → 8 977 мс   <-- це і вбивало upsert
--   без ORDER BY            → 0,14 мс (Index Scan using idx_checkout_events_order_id)
--
-- Рішення: НЕ лагодити тригер, а прибрати його з гарячого шляху ETL зовсім.
-- Поле заповнює той самий set-based reconcile, лише частіше: 43 мс на прогін.

drop trigger if exists tg_dashboard_deals_pay_provider on dashboard_deals;
drop function if exists tg_deals_stamp_pay_provider();

select cron.unschedule('reconcile-pay-provider');
select cron.schedule('reconcile-pay-provider', '*/10 * * * *',
  $$select reconcile_pay_provider(interval '1 day')$$);

comment on function reconcile_pay_provider(interval) is
 '15.09.2026: ЄДИНИЙ спосіб заповнювати dashboard_deals.pay_provider. Per-row тригер на '
 'dashboard_deals робити НЕ МОЖНА: 15.09 такий тригер поклав ETL MySQL на 2 години — '
 'пошук у checkout_events з ORDER BY ts DESC LIMIT 1 для order_id без збігу сканував '
 '165k рядків (8977 мс на рядок) і валив upsert по statement timeout.';

-- Перевірка, що план правильний (має бути Index Scan using idx_checkout_events_order_id):
-- explain analyze
-- select ce.meta->>'gateway' from checkout_events ce
-- where ce.meta ? 'gateway' and ce.meta->>'order_id' = '<order_id, якого немає>' limit 1;
