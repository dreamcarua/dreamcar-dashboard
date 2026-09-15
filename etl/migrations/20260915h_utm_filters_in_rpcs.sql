-- 20260915h_utm_filters_in_rpcs.sql
-- 15.09.2026
--
-- Проблема: UTM-фільтри (utm_source/medium/campaign/term/content) застосовувались
-- ЛИШЕ клієнтськими фільтрами над сирими рядками (Огляд, Таблиця). Усі серверні
-- агрегації (Аналітика, Джерела, Канали, Контент, Терміни, Комбінації) UTM ігнорували
-- і показували невідфільтровані числа як відфільтровані. Тимчасово це закривав банер.
-- Тепер UTM приймають самі RPC — банер більше не потрібен.
--
-- Другий баг, знайдений тут же: dashboard_traffic_type_summary лишилась на СТАРОМУ
-- визначенні платного трафіку (mv_paid_signatures + хардкод імен 'vira','artem'…).
-- Це четверте визначення, яке пропустив прохід 20260915g. Екран «Тип трафіка»
-- суперечив усім іншим екранам. Приводимо до канону: is_paid_placement(utm_medium).
--
-- Сигнатури розширюються 5 хвостовими параметрами з DEFAULT NULL, тож усі наявні
-- виклики (зокрема *_cached обгортки та pg_cron) працюють без змін.

-- ---------------------------------------------------------------------------
-- helper: порівняння UTM з урахуванням того, що фронт показує порожнє як '(none)'
-- ---------------------------------------------------------------------------
-- ВАЖЛИВО: БЕЗ `SET search_path`. SQL-функція з SET-клаузою НЕ інлайниться планувальником,
-- тож на 6 000 рядків × 5 UTM-предикатів це 30 000 реальних викликів функції:
-- замір показав kpi_summary 18.8 -> 140 ms, kpi_with_delta 59 -> 504 ms.
-- Без SET вона інлайниться у WHERE і коштує нуль. Функція IMMUTABLE, не SECURITY DEFINER,
-- і не звертається до жодного об'єкта — підміна search_path їй нічого не дає.
CREATE OR REPLACE FUNCTION public.utm_eq(col text, p text)
RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE
AS $$
  SELECT p IS NULL OR COALESCE(NULLIF(col, ''), '(none)') = p;
$$;

-- Той самий діагноз для is_paid_placement (створена у 20260915g з SET search_path):
-- вона викликається на КОЖНОМУ рядку у 7 RPC і теж не інлайнилась.
-- traffic_type_summary: 64.8 -> 23.8 ms після зняття SET.
CREATE OR REPLACE FUNCTION public.is_paid_placement(p_utm_medium text)
RETURNS boolean LANGUAGE sql IMMUTABLE PARALLEL SAFE
AS $function$
  select coalesce(p_utm_medium, '') ~* '^(facebook|instagram|fb|ig|messenger|audience_network)_';
$function$;
COMMENT ON FUNCTION public.utm_eq(text, text) IS
  'UTM-предикат для RPC: NULL = фільтр вимкнено; ''(none)'' матчить NULL/порожнє (як у ключах агрегації).';

-- ---------------------------------------------------------------------------
-- 1. dashboard_kpi_summary
-- ---------------------------------------------------------------------------
DROP FUNCTION IF EXISTS public.dashboard_kpi_summary(timestamptz, timestamptz, text[], text, text, text, text);
CREATE FUNCTION public.dashboard_kpi_summary(
  p_from timestamptz, p_to timestamptz,
  p_project_values text[] DEFAULT NULL, p_customer_type text DEFAULT NULL,
  p_tariff text DEFAULT NULL, p_pay_provider text DEFAULT NULL, p_traffic_type text DEFAULT NULL,
  p_utm_source text DEFAULT NULL, p_utm_medium text DEFAULT NULL, p_utm_campaign text DEFAULT NULL,
  p_utm_term text DEFAULT NULL, p_utm_content text DEFAULT NULL)
RETURNS TABLE(total bigint, paid bigint, fail bigint, pending bigint, new_deals bigint,
              revenue numeric, unique_buyers bigint, paid_rate numeric)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path TO 'public', 'pg_temp'
AS $function$
  SELECT
    COUNT(*)::bigint, COUNT(*) FILTER (WHERE status='pay')::bigint,
    COUNT(*) FILTER (WHERE status='fail')::bigint, COUNT(*) FILTER (WHERE status='pending')::bigint,
    COUNT(*) FILTER (WHERE status='new')::bigint,
    COALESCE(SUM(amount) FILTER (WHERE status='pay'), 0)::numeric,
    COUNT(DISTINCT COALESCE(NULLIF(customer_email,''), NULLIF(customer_phone,''))) FILTER (WHERE status='pay')::bigint,
    CASE WHEN COUNT(*)=0 THEN 0 ELSE ROUND((COUNT(*) FILTER (WHERE status='pay') * 100.0 / COUNT(*))::numeric, 2) END
  FROM dashboard_deals
  WHERE created_at >= p_from AND created_at <= p_to
    AND (p_project_values IS NULL OR project = ANY(p_project_values))
    AND (p_customer_type IS NULL OR customer_type = p_customer_type)
    AND (p_tariff IS NULL OR tariff = p_tariff)
    AND (p_pay_provider IS NULL OR pay_provider = p_pay_provider)
    AND (
      p_traffic_type IS NULL
      OR (p_traffic_type = 'paid'    AND is_paid_placement(utm_medium) = true)
      OR (p_traffic_type = 'organic' AND is_paid_placement(utm_medium) = false)
    )
    AND utm_eq(utm_source,   p_utm_source)
    AND utm_eq(utm_medium,   p_utm_medium)
    AND utm_eq(utm_campaign, p_utm_campaign)
    AND utm_eq(utm_term,     p_utm_term)
    AND utm_eq(utm_content,  p_utm_content);
$function$;

-- ---------------------------------------------------------------------------
-- 2. dashboard_kpi_with_delta
-- ---------------------------------------------------------------------------
DROP FUNCTION IF EXISTS public.dashboard_kpi_with_delta(timestamptz, timestamptz, text[], text, text, text, text);
CREATE FUNCTION public.dashboard_kpi_with_delta(
  p_from timestamptz, p_to timestamptz,
  p_project_values text[] DEFAULT NULL, p_customer_type text DEFAULT NULL,
  p_tariff text DEFAULT NULL, p_pay_provider text DEFAULT NULL, p_traffic_type text DEFAULT NULL,
  p_utm_source text DEFAULT NULL, p_utm_medium text DEFAULT NULL, p_utm_campaign text DEFAULT NULL,
  p_utm_term text DEFAULT NULL, p_utm_content text DEFAULT NULL)
RETURNS TABLE(period_label text, total bigint, paid bigint, revenue numeric, buyers bigint,
              paid_rate numeric, aov numeric, prev_total bigint, prev_paid bigint,
              prev_revenue numeric, prev_buyers bigint, total_delta numeric, paid_delta numeric,
              revenue_delta numeric, buyers_delta numeric)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path TO 'public', 'pg_temp'
AS $function$
  -- Мінімальний поріг попереднього періоду для показу delta (щоб уникнути "162300%")
  WITH agg AS (
    SELECT
      CASE WHEN created_at >= p_from THEN 'cur' ELSE 'prev' END AS bucket,
      COUNT(*) AS cnt,
      COUNT(*) FILTER (WHERE status='pay') AS cnt_paid,
      COALESCE(SUM(amount) FILTER (WHERE status='pay'), 0) AS rev,
      COUNT(DISTINCT COALESCE(NULLIF(customer_email,''), NULLIF(customer_phone,''))) FILTER (WHERE status='pay') AS buyers_distinct
    FROM dashboard_deals
    WHERE created_at >= (p_from - (p_to - p_from)) AND created_at <= p_to
      AND (p_project_values IS NULL OR project = ANY(p_project_values))
      AND (p_customer_type IS NULL OR customer_type = p_customer_type)
      AND (p_tariff IS NULL OR tariff = p_tariff)
      AND (p_pay_provider IS NULL OR pay_provider = p_pay_provider)
      AND (
        p_traffic_type IS NULL
        OR (p_traffic_type = 'paid'    AND is_paid_placement(utm_medium) = true)
        OR (p_traffic_type = 'organic' AND is_paid_placement(utm_medium) = false)
      )
      AND utm_eq(utm_source,   p_utm_source)
      AND utm_eq(utm_medium,   p_utm_medium)
      AND utm_eq(utm_campaign, p_utm_campaign)
      AND utm_eq(utm_term,     p_utm_term)
      AND utm_eq(utm_content,  p_utm_content)
    GROUP BY 1
  ),
  cur AS (SELECT cnt, cnt_paid, rev, buyers_distinct FROM agg WHERE bucket='cur'),
  prv AS (SELECT cnt, cnt_paid, rev, buyers_distinct FROM agg WHERE bucket='prev')
  SELECT
    'current'::text,
    COALESCE((SELECT cnt FROM cur), 0)::bigint,
    COALESCE((SELECT cnt_paid FROM cur), 0)::bigint,
    COALESCE((SELECT rev FROM cur), 0)::numeric,
    COALESCE((SELECT buyers_distinct FROM cur), 0)::bigint,
    CASE WHEN COALESCE((SELECT cnt FROM cur), 0) = 0 THEN 0::numeric
         ELSE ROUND((COALESCE((SELECT cnt_paid FROM cur), 0) * 100.0 / (SELECT cnt FROM cur))::numeric, 2) END,
    CASE WHEN COALESCE((SELECT cnt_paid FROM cur), 0) = 0 THEN 0::numeric
         ELSE ROUND(((SELECT rev FROM cur) / (SELECT cnt_paid FROM cur))::numeric, 2) END,
    COALESCE((SELECT cnt FROM prv), 0)::bigint,
    COALESCE((SELECT cnt_paid FROM prv), 0)::bigint,
    COALESCE((SELECT rev FROM prv), 0)::numeric,
    COALESCE((SELECT buyers_distinct FROM prv), 0)::bigint,
    CASE WHEN COALESCE((SELECT cnt FROM prv), 0) < 5 THEN NULL
         ELSE ROUND(((COALESCE((SELECT cnt FROM cur), 0) - (SELECT cnt FROM prv)) * 100.0 / (SELECT cnt FROM prv))::numeric, 1) END,
    CASE WHEN COALESCE((SELECT cnt_paid FROM prv), 0) < 5 THEN NULL
         ELSE ROUND(((COALESCE((SELECT cnt_paid FROM cur), 0) - (SELECT cnt_paid FROM prv)) * 100.0 / (SELECT cnt_paid FROM prv))::numeric, 1) END,
    CASE WHEN COALESCE((SELECT rev FROM prv), 0) = 0 THEN NULL
         ELSE ROUND(((COALESCE((SELECT rev FROM cur), 0) - (SELECT rev FROM prv)) * 100.0 / (SELECT rev FROM prv))::numeric, 1) END,
    CASE WHEN COALESCE((SELECT buyers_distinct FROM prv), 0) < 5 THEN NULL
         ELSE ROUND(((COALESCE((SELECT buyers_distinct FROM cur), 0) - (SELECT buyers_distinct FROM prv)) * 100.0 / (SELECT buyers_distinct FROM prv))::numeric, 1) END;
$function$;

-- ---------------------------------------------------------------------------
-- 3. dashboard_extended_kpi
-- ---------------------------------------------------------------------------
DROP FUNCTION IF EXISTS public.dashboard_extended_kpi(timestamptz, timestamptz, text[], text, text, text, text);
CREATE FUNCTION public.dashboard_extended_kpi(
  p_from timestamptz, p_to timestamptz,
  p_project_values text[] DEFAULT NULL, p_customer_type text DEFAULT NULL,
  p_tariff text DEFAULT NULL, p_pay_provider text DEFAULT NULL, p_traffic_type text DEFAULT NULL,
  p_utm_source text DEFAULT NULL, p_utm_medium text DEFAULT NULL, p_utm_campaign text DEFAULT NULL,
  p_utm_term text DEFAULT NULL, p_utm_content text DEFAULT NULL)
RETURNS TABLE(median_minutes numeric, p90_minutes numeric, median_amount numeric,
              repeat_buyers bigint, total_buyers bigint, uah_revenue numeric,
              usd_revenue numeric, eur_revenue numeric)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path TO 'public', 'pg_catalog'
AS $function$
  WITH base AS (
    SELECT COALESCE(NULLIF(customer_email,''), NULLIF(customer_phone,'')) AS buyer_key,
           amount, currency, (paid_at - created_at) AS lag
    FROM dashboard_deals
    WHERE created_at >= p_from AND created_at <= p_to
      AND status='pay' AND paid_at IS NOT NULL
      AND (p_project_values IS NULL OR project = ANY(p_project_values))
      AND (p_customer_type IS NULL OR customer_type = p_customer_type)
      AND (p_tariff IS NULL OR tariff = p_tariff)
      AND (p_pay_provider IS NULL OR pay_provider = p_pay_provider)
      AND (p_traffic_type IS NULL
           OR (p_traffic_type='paid' AND public.is_paid_placement(utm_medium)=true)
           OR (p_traffic_type='organic' AND public.is_paid_placement(utm_medium)=false))
      AND public.utm_eq(utm_source,   p_utm_source)
      AND public.utm_eq(utm_medium,   p_utm_medium)
      AND public.utm_eq(utm_campaign, p_utm_campaign)
      AND public.utm_eq(utm_term,     p_utm_term)
      AND public.utm_eq(utm_content,  p_utm_content)
  ),
  by_email AS (
    -- 15.09.2026: ключ покупця уніфіковано з dashboard_kpi_summary і dashboard_kpi_with_delta:
    -- email, а якщо його немає — телефон.
    SELECT buyer_key, COUNT(*) AS purchases FROM base
    WHERE buyer_key IS NOT NULL
    GROUP BY buyer_key
  )
  SELECT
    ROUND(EXTRACT(EPOCH FROM percentile_cont(0.5) WITHIN GROUP (ORDER BY lag))::numeric / 60, 1),
    ROUND(EXTRACT(EPOCH FROM percentile_cont(0.9) WITHIN GROUP (ORDER BY lag))::numeric / 60, 1),
    ROUND(percentile_cont(0.5) WITHIN GROUP (ORDER BY amount)::numeric, 2),
    (SELECT COUNT(*) FROM by_email WHERE purchases > 1),
    (SELECT COUNT(*) FROM by_email),
    ROUND(COALESCE(SUM(amount) FILTER (WHERE currency IS NULL OR currency='UAH' OR currency=''), 0)::numeric, 2),
    ROUND(COALESCE(SUM(amount) FILTER (WHERE currency='USD'), 0)::numeric, 2),
    ROUND(COALESCE(SUM(amount) FILTER (WHERE currency='EUR'), 0)::numeric, 2)
  FROM base;
$function$;

-- ---------------------------------------------------------------------------
-- 4. dashboard_daily_series
-- ---------------------------------------------------------------------------
DROP FUNCTION IF EXISTS public.dashboard_daily_series(timestamptz, timestamptz, text[], text, text, text, text);
CREATE FUNCTION public.dashboard_daily_series(
  p_from timestamptz, p_to timestamptz,
  p_project_values text[] DEFAULT NULL, p_customer_type text DEFAULT NULL,
  p_tariff text DEFAULT NULL, p_pay_provider text DEFAULT NULL, p_traffic_type text DEFAULT NULL,
  p_utm_source text DEFAULT NULL, p_utm_medium text DEFAULT NULL, p_utm_campaign text DEFAULT NULL,
  p_utm_term text DEFAULT NULL, p_utm_content text DEFAULT NULL)
RETURNS TABLE(day date, leads bigint, paid bigint, revenue numeric)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path TO 'public', 'pg_temp'
AS $function$
  SELECT created_at::date, COUNT(*)::bigint,
    COUNT(*) FILTER (WHERE status='pay')::bigint,
    COALESCE(SUM(amount) FILTER (WHERE status='pay'), 0)::numeric
  FROM dashboard_deals
  WHERE created_at >= p_from AND created_at <= p_to
    AND (p_project_values IS NULL OR project = ANY(p_project_values))
    AND (p_customer_type IS NULL OR customer_type = p_customer_type)
    AND (p_tariff IS NULL OR tariff = p_tariff)
    AND (p_pay_provider IS NULL OR pay_provider = p_pay_provider)
    AND (p_traffic_type IS NULL
         OR (p_traffic_type='paid' AND is_paid_placement(utm_medium)=true)
         OR (p_traffic_type='organic' AND is_paid_placement(utm_medium)=false))
    AND utm_eq(utm_source,   p_utm_source)
    AND utm_eq(utm_medium,   p_utm_medium)
    AND utm_eq(utm_campaign, p_utm_campaign)
    AND utm_eq(utm_term,     p_utm_term)
    AND utm_eq(utm_content,  p_utm_content)
  GROUP BY 1 ORDER BY 1;
$function$;

-- ---------------------------------------------------------------------------
-- 5. dashboard_hourly_series
-- ---------------------------------------------------------------------------
DROP FUNCTION IF EXISTS public.dashboard_hourly_series(timestamptz, timestamptz, text[], text, text, text, text);
CREATE FUNCTION public.dashboard_hourly_series(
  p_from timestamptz, p_to timestamptz,
  p_project_values text[] DEFAULT NULL, p_customer_type text DEFAULT NULL,
  p_tariff text DEFAULT NULL, p_pay_provider text DEFAULT NULL, p_traffic_type text DEFAULT NULL,
  p_utm_source text DEFAULT NULL, p_utm_medium text DEFAULT NULL, p_utm_campaign text DEFAULT NULL,
  p_utm_term text DEFAULT NULL, p_utm_content text DEFAULT NULL)
RETURNS TABLE(hour timestamptz, leads bigint, paid bigint, revenue numeric)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path TO 'public', 'pg_temp'
AS $function$
  SELECT DATE_TRUNC('hour', created_at), COUNT(*)::bigint,
    COUNT(*) FILTER (WHERE status='pay')::bigint,
    COALESCE(SUM(amount) FILTER (WHERE status='pay'), 0)::numeric
  FROM dashboard_deals
  WHERE created_at >= p_from AND created_at <= p_to
    AND (p_project_values IS NULL OR project = ANY(p_project_values))
    AND (p_customer_type IS NULL OR customer_type = p_customer_type)
    AND (p_tariff IS NULL OR tariff = p_tariff)
    AND (p_pay_provider IS NULL OR pay_provider = p_pay_provider)
    AND (p_traffic_type IS NULL
         OR (p_traffic_type='paid' AND is_paid_placement(utm_medium)=true)
         OR (p_traffic_type='organic' AND is_paid_placement(utm_medium)=false))
    AND utm_eq(utm_source,   p_utm_source)
    AND utm_eq(utm_medium,   p_utm_medium)
    AND utm_eq(utm_campaign, p_utm_campaign)
    AND utm_eq(utm_term,     p_utm_term)
    AND utm_eq(utm_content,  p_utm_content)
  GROUP BY 1 ORDER BY 1;
$function$;

-- ---------------------------------------------------------------------------
-- 6. dashboard_traffic_type_summary
--    + переведення на канонічне визначення платного трафіку (див. 20260915g)
-- ---------------------------------------------------------------------------
DROP FUNCTION IF EXISTS public.dashboard_traffic_type_summary(timestamptz, timestamptz, text[], text, text, text, text);
CREATE FUNCTION public.dashboard_traffic_type_summary(
  p_from timestamptz, p_to timestamptz,
  p_project_values text[] DEFAULT NULL, p_customer_type text DEFAULT NULL,
  p_tariff text DEFAULT NULL, p_pay_provider text DEFAULT NULL, p_traffic_type text DEFAULT NULL,
  p_utm_source text DEFAULT NULL, p_utm_medium text DEFAULT NULL, p_utm_campaign text DEFAULT NULL,
  p_utm_term text DEFAULT NULL, p_utm_content text DEFAULT NULL)
RETURNS TABLE(traffic_type text, leads bigint, paid bigint, fail bigint, pending bigint,
              revenue numeric, buyers bigint, conv_rate numeric, avg_check numeric)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path TO 'public', 'pg_temp'
AS $function$
  WITH base AS (
    SELECT
      -- 15.09.2026: канон — placement-мітка Meta у utm_medium (is_paid_placement).
      -- Раніше тут жило ЧЕТВЕРТЕ визначення платного трафіку: хардкод імен
      -- ('vira','artem'…) + mv_paid_signatures. Через нього екран «Тип трафіка»
      -- суперечив Аналітиці, Кампаніям і Огляду на тих самих датах.
      CASE WHEN is_paid_placement(utm_medium) THEN 'paid' ELSE 'organic' END AS tt,
      status, amount,
      COALESCE(NULLIF(customer_email,''), NULLIF(customer_phone,'')) AS buyer_key
    FROM dashboard_deals
    WHERE created_at >= p_from AND created_at <= p_to
      AND (p_project_values IS NULL OR project = ANY(p_project_values))
      AND (p_customer_type IS NULL OR customer_type = p_customer_type)
      AND (p_tariff IS NULL OR tariff = p_tariff)
      AND (p_pay_provider IS NULL OR pay_provider = p_pay_provider)
      AND utm_eq(utm_source,   p_utm_source)
      AND utm_eq(utm_medium,   p_utm_medium)
      AND utm_eq(utm_campaign, p_utm_campaign)
      AND utm_eq(utm_term,     p_utm_term)
      AND utm_eq(utm_content,  p_utm_content)
  )
  SELECT
    tt, COUNT(*)::bigint,
    COUNT(*) FILTER (WHERE status='pay')::bigint,
    COUNT(*) FILTER (WHERE status='fail')::bigint,
    COUNT(*) FILTER (WHERE status='pending')::bigint,
    COALESCE(SUM(amount) FILTER (WHERE status='pay'), 0)::numeric,
    -- 15.09.2026: ключ покупця уніфіковано (email → телефон), як у решті KPI.
    COUNT(DISTINCT buyer_key) FILTER (WHERE status='pay' AND buyer_key IS NOT NULL)::bigint,
    CASE WHEN COUNT(*)=0 THEN 0 ELSE ROUND((COUNT(*) FILTER (WHERE status='pay') * 100.0 / COUNT(*))::numeric, 2) END,
    CASE WHEN COUNT(*) FILTER (WHERE status='pay')=0 THEN 0
         ELSE ROUND((COALESCE(SUM(amount) FILTER (WHERE status='pay'),0) / COUNT(*) FILTER (WHERE status='pay'))::numeric, 2) END
  FROM base
  WHERE p_traffic_type IS NULL OR tt = p_traffic_type
  GROUP BY tt;
$function$;

-- ---------------------------------------------------------------------------
-- 7. dashboard_agg_deals_with_traffic_core
--    UTM-фільтр вимикає MV-шлях (mv_dashboard_utm_agg агрегована по ОДНОМУ полю
--    і не має решти UTM у рядку) — так само, як це вже робили customer_type/tariff/
--    pay_provider.
-- ---------------------------------------------------------------------------
DROP FUNCTION IF EXISTS public.dashboard_agg_deals_with_traffic_core(text, timestamptz, timestamptz, text[], text, text, text, text);
CREATE FUNCTION public.dashboard_agg_deals_with_traffic_core(
  p_field text, p_from timestamptz, p_to timestamptz,
  p_project_values text[] DEFAULT NULL, p_customer_type text DEFAULT NULL,
  p_tariff text DEFAULT NULL, p_pay_provider text DEFAULT NULL, p_traffic_type text DEFAULT NULL,
  p_utm_source text DEFAULT NULL, p_utm_medium text DEFAULT NULL, p_utm_campaign text DEFAULT NULL,
  p_utm_term text DEFAULT NULL, p_utm_content text DEFAULT NULL)
RETURNS TABLE(key text, traffic_type text, leads bigint, paid bigint, fail bigint,
              pending bigint, sum_amount numeric, buyers bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path TO 'public', 'pg_temp'
AS $function$
  WITH flags AS (
    SELECT (p_customer_type IS NULL AND p_tariff IS NULL AND p_pay_provider IS NULL
            AND p_utm_source IS NULL AND p_utm_medium IS NULL AND p_utm_campaign IS NULL
            AND p_utm_term IS NULL AND p_utm_content IS NULL) AS can_use_mv
  ),
  mv_path AS (
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
    WHERE (SELECT can_use_mv FROM flags)
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
      -- 15.09.2026 (рішення Вадима): канонічне визначення — placement-мітка Meta у utm_medium.
      CASE WHEN is_paid_placement(d.utm_medium) THEN 'paid' ELSE 'organic' END AS tt,
      d.status,
      d.amount
    FROM dashboard_deals d
    WHERE NOT (SELECT can_use_mv FROM flags)
      AND d.created_at >= p_from AND d.created_at <= p_to
      AND (p_project_values IS NULL OR d.project = ANY(p_project_values))
      AND (p_customer_type IS NULL OR d.customer_type = p_customer_type)
      AND (p_tariff        IS NULL OR d.tariff       = p_tariff)
      AND (p_pay_provider  IS NULL OR d.pay_provider = p_pay_provider)
      AND utm_eq(d.utm_source,   p_utm_source)
      AND utm_eq(d.utm_medium,   p_utm_medium)
      AND utm_eq(d.utm_campaign, p_utm_campaign)
      AND utm_eq(d.utm_term,     p_utm_term)
      AND utm_eq(d.utm_content,  p_utm_content)
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

-- ---------------------------------------------------------------------------
-- 8. dashboard_agg_deals_with_traffic (RLS-обгортка)
-- ---------------------------------------------------------------------------
DROP FUNCTION IF EXISTS public.dashboard_agg_deals_with_traffic(text, timestamptz, timestamptz, text[], text, text, text, text);
CREATE FUNCTION public.dashboard_agg_deals_with_traffic(
  p_field text, p_from timestamptz, p_to timestamptz,
  p_project_values text[] DEFAULT NULL, p_customer_type text DEFAULT NULL,
  p_tariff text DEFAULT NULL, p_pay_provider text DEFAULT NULL, p_traffic_type text DEFAULT NULL,
  p_utm_source text DEFAULT NULL, p_utm_medium text DEFAULT NULL, p_utm_campaign text DEFAULT NULL,
  p_utm_term text DEFAULT NULL, p_utm_content text DEFAULT NULL)
RETURNS TABLE(key text, traffic_type text, leads bigint, paid bigint, fail bigint,
              pending bigint, sum_amount numeric, buyers bigint)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path TO 'public'
AS $function$
declare v record;
begin
  select * into v from public._dash_viewer_ctx();
  -- postgres/cron/service_role — повний доступ; anon — нічого
  if v.jwt_role is null or v.jwt_role = 'service_role' then
    return query select * from public.dashboard_agg_deals_with_traffic_core(
      p_field,p_from,p_to,p_project_values,p_customer_type,p_tariff,p_pay_provider,p_traffic_type,
      p_utm_source,p_utm_medium,p_utm_campaign,p_utm_term,p_utm_content);
  elsif v.jwt_role = 'anon' or v.u_role is null then
    return;
  elsif v.u_role = 'buyer' then
    -- buyer: лише розріз utm_term і лише СВОЇ значення
    if p_field is distinct from 'utm_term' then return; end if;
    return query
      select c.* from public.dashboard_agg_deals_with_traffic_core(
        p_field,p_from,p_to,p_project_values,p_customer_type,p_tariff,p_pay_provider,p_traffic_type,
        p_utm_source,p_utm_medium,p_utm_campaign,p_utm_term,p_utm_content) c
      where c.key = any(coalesce(v.u_terms, '{}'));
  else
    return query select * from public.dashboard_agg_deals_with_traffic_core(
      p_field,p_from,p_to,p_project_values,p_customer_type,p_tariff,p_pay_provider,p_traffic_type,
      p_utm_source,p_utm_medium,p_utm_campaign,p_utm_term,p_utm_content);
  end if;
end $function$;

-- ---------------------------------------------------------------------------
-- Гранти: відтворюємо рівно ті, що були до DROP.
-- _core лишається закритим для anon/authenticated (доступ лише через обгортку).
-- ---------------------------------------------------------------------------
-- УВАГА: Supabase має ALTER DEFAULT PRIVILEGES у схемі public, який автоматично видає
-- EXECUTE ролям anon/authenticated на КОЖНУ нову функцію. REVOKE FROM PUBLIC цього не знімає —
-- треба явно перелічити ролі, інакше _core стає викликаваною в обхід RLS-обгортки.
REVOKE ALL ON FUNCTION public.dashboard_agg_deals_with_traffic_core(text, timestamptz, timestamptz, text[], text, text, text, text, text, text, text, text, text) FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.dashboard_agg_deals_with_traffic_core(text, timestamptz, timestamptz, text[], text, text, text, text, text, text, text, text, text) TO service_role;

GRANT EXECUTE ON FUNCTION public.dashboard_agg_deals_with_traffic(text, timestamptz, timestamptz, text[], text, text, text, text, text, text, text, text, text) TO anon, authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.dashboard_kpi_summary(timestamptz, timestamptz, text[], text, text, text, text, text, text, text, text, text) TO anon, authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.dashboard_kpi_with_delta(timestamptz, timestamptz, text[], text, text, text, text, text, text, text, text, text) TO anon, authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.dashboard_extended_kpi(timestamptz, timestamptz, text[], text, text, text, text, text, text, text, text, text) TO anon, authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.dashboard_daily_series(timestamptz, timestamptz, text[], text, text, text, text, text, text, text, text, text) TO anon, authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.dashboard_hourly_series(timestamptz, timestamptz, text[], text, text, text, text, text, text, text, text, text) TO anon, authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.dashboard_traffic_type_summary(timestamptz, timestamptz, text[], text, text, text, text, text, text, text, text, text) TO anon, authenticated, service_role;

