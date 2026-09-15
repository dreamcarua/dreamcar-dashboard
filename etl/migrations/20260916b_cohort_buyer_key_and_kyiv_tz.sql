-- 20260916b_cohort_buyer_key_and_kyiv_tz.sql
-- 16.09.2026
--
-- Дві вади у mv_dashboard_cohort_retention, знайдені звіркою екрана Cohort із SQL
-- (UI: 432/758/1863/2607 · прямий SQL: 476/774/1847/2611):
--
-- 1. Ключ покупця — ЛИШЕ email. Покупці, що заплатили без email (тільки телефон),
--    із когорт зникали. Це той самий розкол, який 15.09 вже усунули у
--    dashboard_kpi_summary, kpi_with_delta, extended_kpi і traffic_type_summary —
--    когорти лишились на старому ключі.
-- 2. date_trunc('month', paid_at) без AT TIME ZONE. paid_at — timestamptz, тож
--    date_trunc бере таймзону сесії (UTC у крона рефрешу). Оплата о 01:30 Києва
--    1 вересня — це 22:30 UTC 31 серпня, і покупець потрапляв у СЕРПНЕВУ когорту.
--    Саме тому липень у UI був БІЛЬШИЙ за прямий SQL — зсув гнав людей в обидва боки.
--
-- Індекс і крон (jobid 27, `0 4 * * *`) не чіпаємо: matview перестворюється, індекс
-- відтворюємо тут же, команда крона посилається на ім'я і лишається валідною.

DROP MATERIALIZED VIEW IF EXISTS public.mv_dashboard_cohort_retention;

CREATE MATERIALIZED VIEW public.mv_dashboard_cohort_retention AS
WITH paid AS (
  SELECT
    COALESCE(NULLIF(d.customer_email, ''), NULLIF(d.customer_phone, '')) AS buyer_key,
    d.paid_at,
    (date_trunc('month', d.paid_at AT TIME ZONE 'Europe/Kyiv'))::date AS pay_month
  FROM dashboard_deals d
  WHERE d.status = 'pay'
    AND d.paid_at IS NOT NULL
    AND COALESCE(NULLIF(d.customer_email, ''), NULLIF(d.customer_phone, '')) IS NOT NULL
),
first_pay AS (
  SELECT buyer_key,
         (date_trunc('month', MIN(paid_at) AT TIME ZONE 'Europe/Kyiv'))::date AS cohort_month
  FROM paid GROUP BY buyer_key
),
cohort_sizes AS (
  SELECT cohort_month, COUNT(DISTINCT buyer_key) AS cohort_size
  FROM first_pay GROUP BY cohort_month
)
SELECT
  fp.cohort_month,
  (((EXTRACT(year FROM p.pay_month) - EXTRACT(year FROM fp.cohort_month)) * 12)
   + (EXTRACT(month FROM p.pay_month) - EXTRACT(month FROM fp.cohort_month)))::integer AS month_offset,
  COUNT(DISTINCT p.buyer_key) AS retained_buyers,
  cs.cohort_size
FROM first_pay fp
JOIN paid p USING (buyer_key)
JOIN cohort_sizes cs USING (cohort_month)
GROUP BY fp.cohort_month,
  (((EXTRACT(year FROM p.pay_month) - EXTRACT(year FROM fp.cohort_month)) * 12)
   + (EXTRACT(month FROM p.pay_month) - EXTRACT(month FROM fp.cohort_month)))::integer,
  cs.cohort_size;

CREATE UNIQUE INDEX mv_dashboard_cohort_retention_cohort_month_month_offset_idx
  ON public.mv_dashboard_cohort_retention USING btree (cohort_month, month_offset);

REVOKE ALL ON public.mv_dashboard_cohort_retention FROM PUBLIC, anon;
GRANT SELECT ON public.mv_dashboard_cohort_retention TO authenticated, service_role;

-- Після накату: когорти 2026-09..2026-04 = 476/774/1847/2611/2529/1711 — збіг із
-- прямим SQL по dashboard_deals точний. Крон jobid 27 лишився активним.
