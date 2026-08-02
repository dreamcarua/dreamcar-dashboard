-- Tech-request #6 (02.08.2026) — фільтр «Нові (перша покупка)» нічого не фільтрував.
--
-- ЩО БУЛО ЗНАЙДЕНО
-- `dashboard_agg_deals_with_traffic` ОГОЛОШУВАЛА параметри p_customer_type / p_tariff /
-- p_pay_provider, але НЕ використовувала їх у WHERE — вона читає з MV
-- `mv_dashboard_utm_agg`, у якого цих вимірів немає. Фронт справно передавав фільтр,
-- база його мовчки ковтала. Мертвий фільтр у розділах:
-- Джерела / Тип трафіка / Кампанії / Оголошення / Виконавець.
--
-- ЯК ВИПРАВЛЕНО
-- Дві взаємовиключні гілки в одній функції:
--   1) фільтрів немає → старий швидкий MV-шлях (~50 мс) — поведінка не змінилась;
--   2) фільтри є      → точний підрахунок по dashboard_deals (~900 мс на всю історію).
-- Перемикання дає планувальнику One-Time Filter на параметрах, тож «зайва» гілка
-- взагалі не виконується.
--
-- ЗВІРКА (30 днів, customer_type='new'):
--   dashboard_agg_deals_with_traffic → 1580 лідів
--   dashboard_kpi_summary            → 1580 лідів   ✅ збіг
--
-- Застосовано на wotghlaehnvxyeacznvv через Supabase MCP 02.08.2026.

CREATE OR REPLACE FUNCTION public.dashboard_agg_deals_with_traffic(
  p_field text,
  p_from timestamp with time zone,
  p_to timestamp with time zone,
  p_project_values text[] DEFAULT NULL::text[],
  p_customer_type text DEFAULT NULL::text,
  p_tariff text DEFAULT NULL::text,
  p_pay_provider text DEFAULT NULL::text,
  p_traffic_type text DEFAULT NULL::text)
RETURNS TABLE(key text, traffic_type text, leads bigint, paid bigint, fail bigint, pending bigint, sum_amount numeric, buyers bigint)
LANGUAGE sql
STABLE SECURITY DEFINER
SET search_path TO 'public', 'pg_temp'
AS $function$
  WITH mv_path AS (
    SELECT
      m.key,
      MODE() WITHIN GROUP (ORDER BY m.tt) AS traffic_type,
      SUM(m.leads)::bigint      AS leads,
      SUM(m.paid)::bigint       AS paid,
      SUM(m.fail)::bigint       AS fail,
      SUM(m.pending)::bigint    AS pending,
      SUM(m.sum_amount)::numeric AS sum_amount,
      0::bigint                 AS buyers
    FROM mv_dashboard_utm_agg m
    WHERE p_customer_type IS NULL AND p_tariff IS NULL AND p_pay_provider IS NULL
      AND m.field = p_field
      -- #224: AT TIME ZONE 'Europe/Kyiv' щоб ::date вирівнювалось з MV `day`
      AND m.day >= (p_from AT TIME ZONE 'Europe/Kyiv')::date
      AND m.day <= (p_to   AT TIME ZONE 'Europe/Kyiv')::date
      AND (p_project_values IS NULL OR m.project = ANY(p_project_values))
      AND (p_traffic_type IS NULL OR m.tt = p_traffic_type)
    GROUP BY m.key
  ),
  raw_rows AS (
    SELECT
      COALESCE(NULLIF(
        CASE p_field
          WHEN 'utm_source'   THEN d.utm_source
          WHEN 'utm_medium'   THEN d.utm_medium
          WHEN 'utm_campaign' THEN d.utm_campaign
          WHEN 'utm_term'     THEN d.utm_term
          WHEN 'utm_content'  THEN d.utm_content
          WHEN 'project'      THEN d.project
        END, ''), '(none)') AS key,
      -- та сама класифікація трафіку, що й у mv_dashboard_utm_agg
      CASE
        WHEN lower(COALESCE(d.utm_term, '')) = ANY (ARRAY['vira','vera','artem','artyom','arthem']) THEN 'organic'
        WHEN lower(COALESCE(d.utm_source, '')) = 'telegram'
          OR lower(COALESCE(d.utm_medium, '')) = ANY (ARRAY['telegram_bot','tg_bot','tma','bot','post','stories','channel','tg_channel']) THEN 'organic'
        WHEN EXISTS (
          SELECT 1 FROM mv_paid_signatures ps
          WHERE ps.v = ANY (ARRAY[d.utm_campaign, d.utm_content, d.utm_term])
        ) THEN 'paid'
        ELSE 'organic'
      END AS tt,
      d.status,
      d.amount
    FROM dashboard_deals d
    WHERE NOT (p_customer_type IS NULL AND p_tariff IS NULL AND p_pay_provider IS NULL)
      AND d.created_at >= p_from AND d.created_at <= p_to
      AND (p_project_values IS NULL OR d.project = ANY(p_project_values))
      AND (p_customer_type IS NULL OR d.customer_type = p_customer_type)
      AND (p_tariff        IS NULL OR d.tariff       = p_tariff)
      AND (p_pay_provider  IS NULL OR d.pay_provider = p_pay_provider)
  ),
  raw_path AS (
    SELECT
      r.key,
      MODE() WITHIN GROUP (ORDER BY r.tt) AS traffic_type,
      COUNT(*)::bigint                                            AS leads,
      COUNT(*) FILTER (WHERE r.status = 'pay')::bigint            AS paid,
      COUNT(*) FILTER (WHERE r.status = 'fail')::bigint           AS fail,
      COUNT(*) FILTER (WHERE r.status = 'pending')::bigint        AS pending,
      COALESCE(SUM(r.amount) FILTER (WHERE r.status = 'pay'), 0)::numeric AS sum_amount,
      0::bigint                                                   AS buyers
    FROM raw_rows r
    WHERE (p_traffic_type IS NULL OR r.tt = p_traffic_type)
    GROUP BY r.key
  )
  SELECT u.key, u.traffic_type, u.leads, u.paid, u.fail, u.pending, u.sum_amount, u.buyers
  FROM (SELECT * FROM mv_path UNION ALL SELECT * FROM raw_path) u
  ORDER BY u.leads DESC
  LIMIT 500;
$function$;


-- People Merge ігнорував Тип клієнта / Тариф / Провайдер / Тип трафіка.
-- Додаємо (усі DEFAULT NULL — старі виклики працюють без змін).
-- Витрати реклами не показуються при розрізі по типу клієнта: spend не ділиться
-- на new/returning, тож ROI/CPA були б брехливими.
CREATE OR REPLACE FUNCTION public.dashboard_agg_by_person(
  p_from timestamp with time zone,
  p_to timestamp with time zone,
  p_project_values text[] DEFAULT NULL::text[],
  p_customer_type text DEFAULT NULL::text,
  p_tariff text DEFAULT NULL::text,
  p_pay_provider text DEFAULT NULL::text,
  p_traffic_type text DEFAULT NULL::text)
RETURNS TABLE(person text, leads bigint, paid bigint, fail bigint, pending bigint, revenue numeric, spend numeric, roi_pct numeric, cpa numeric, cpl numeric)
LANGUAGE sql
STABLE SECURITY DEFINER
SET search_path TO 'public', 'pg_catalog'
AS $function$
  WITH crm_agg AS (
    SELECT
      COALESCE(NULLIF(d.utm_term, ''), '(none)') AS person,
      COUNT(*) AS leads,
      COUNT(*) FILTER (WHERE status='pay') AS paid,
      COUNT(*) FILTER (WHERE status='fail') AS fail,
      COUNT(*) FILTER (WHERE status='pending') AS pending,
      COALESCE(SUM(amount) FILTER (WHERE status='pay'), 0) AS revenue
    FROM dashboard_deals d
    WHERE created_at >= p_from AND created_at <= p_to
      AND (p_project_values IS NULL OR project = ANY(p_project_values))
      AND (p_customer_type IS NULL OR customer_type = p_customer_type)
      AND (p_tariff        IS NULL OR tariff       = p_tariff)
      AND (p_pay_provider  IS NULL OR pay_provider = p_pay_provider)
      AND (
        p_traffic_type IS NULL
        OR (p_traffic_type = 'paid'    AND is_paid_deal(utm_campaign, utm_content, utm_term) = true)
        OR (p_traffic_type = 'organic' AND is_paid_deal(utm_campaign, utm_content, utm_term) = false)
      )
    GROUP BY 1
  ),
  ads_agg AS (
    SELECT
      COALESCE(NULLIF(a.utm_term, ''), '(none)') AS person,
      COALESCE(SUM(spend), 0) AS spend
    FROM dashboard_ads_data a
    WHERE date_start::timestamptz >= p_from AND date_start::timestamptz <= p_to
      AND p_customer_type IS NULL
    GROUP BY 1
  )
  SELECT
    COALESCE(c.person, a.person) AS person,
    COALESCE(c.leads, 0)::bigint,
    COALESCE(c.paid, 0)::bigint,
    COALESCE(c.fail, 0)::bigint,
    COALESCE(c.pending, 0)::bigint,
    COALESCE(c.revenue, 0)::numeric,
    COALESCE(a.spend, 0)::numeric,
    CASE WHEN COALESCE(a.spend, 0) > 0 THEN ROUND(((COALESCE(c.revenue,0) - a.spend) * 100.0 / a.spend)::numeric, 1) ELSE NULL END,
    CASE WHEN COALESCE(c.paid, 0) > 0 AND COALESCE(a.spend,0) > 0 THEN ROUND((a.spend / c.paid)::numeric, 2) ELSE NULL END,
    CASE WHEN COALESCE(c.leads, 0) > 0 AND COALESCE(a.spend,0) > 0 THEN ROUND((a.spend / c.leads)::numeric, 2) ELSE NULL END
  FROM crm_agg c
  FULL OUTER JOIN ads_agg a USING (person)
  ORDER BY revenue DESC NULLS LAST;
$function$;
