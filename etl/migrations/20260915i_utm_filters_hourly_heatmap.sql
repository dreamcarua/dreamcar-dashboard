-- 20260915i: hourly heatmap теж приймає UTM.
-- Фронт передавав у неї лише p_from/p_to/p_project_values, тож на відфільтрованому
-- екрані Аналітики теплокарта показувала весь трафік. Тепер RPC вміє UTM, а фронт
-- передає повний _rpcParams().
DROP FUNCTION IF EXISTS public.dashboard_hourly_heatmap(timestamptz, timestamptz, text[], text, text, text, text);
CREATE FUNCTION public.dashboard_hourly_heatmap(
  p_from timestamptz, p_to timestamptz,
  p_project_values text[] DEFAULT NULL, p_customer_type text DEFAULT NULL,
  p_tariff text DEFAULT NULL, p_pay_provider text DEFAULT NULL, p_traffic_type text DEFAULT NULL,
  p_utm_source text DEFAULT NULL, p_utm_medium text DEFAULT NULL, p_utm_campaign text DEFAULT NULL,
  p_utm_term text DEFAULT NULL, p_utm_content text DEFAULT NULL)
RETURNS TABLE(dow integer, hour integer, leads bigint, paid bigint, revenue numeric)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path TO 'public', 'pg_catalog'
AS $function$
  SELECT
    EXTRACT(ISODOW FROM created_at AT TIME ZONE 'Europe/Kyiv')::int,
    EXTRACT(HOUR FROM created_at AT TIME ZONE 'Europe/Kyiv')::int,
    COUNT(*)::bigint,
    COUNT(*) FILTER (WHERE status='pay')::bigint,
    COALESCE(SUM(amount) FILTER (WHERE status='pay'),0)::numeric
  FROM dashboard_deals
  WHERE created_at >= p_from AND created_at <= p_to
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
  GROUP BY 1, 2;
$function$;

GRANT EXECUTE ON FUNCTION public.dashboard_hourly_heatmap(timestamptz, timestamptz, text[], text, text, text, text, text, text, text, text, text) TO anon, authenticated, service_role;
