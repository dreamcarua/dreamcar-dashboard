-- 16.11.2026 (ad-director improve run): ad_daily_real counted promo days as normal.
-- The promo CTE knew kinds offer/x2/final/launch only; kind 'promo' (planner since 30.10.2026) fell through,
-- so 07.11, 10.11, 12.11, 14-15.11 had day_type 'normal' and every "normal days" verdict mixed promo days in
-- (week 09-15.11: view said normal 2.34, true normal days 1.75, promo days 2.59).
-- ad-director docs/traps.md#ad-daily-real-misses-promo-kind. Only change: 'promo' added to the ARRAY.
-- Rollback: the same statement without 'promo'::text.
CREATE OR REPLACE VIEW public.ad_daily_real AS
 WITH ads AS (
         SELECT dashboard_ads_data.date_start AS day,
            dashboard_ads_data.ad_account_id,
            dashboard_ads_data.campaign_id,
            dashboard_ads_data.campaign_name,
            dashboard_ads_data.adset_id,
            dashboard_ads_data.adset_name,
            dashboard_ads_data.ad_id,
            dashboard_ads_data.ad_name,
            sum(dashboard_ads_data.spend) AS spend,
            sum(dashboard_ads_data.impressions)::bigint AS impressions,
            sum(dashboard_ads_data.clicks)::bigint AS clicks,
            lower((dashboard_ads_data.adset_name || '_'::text) || dashboard_ads_data.ad_name) AS utm_key
           FROM dashboard_ads_data
          WHERE dashboard_ads_data.ad_account_id = '4136058269783354'::text
          GROUP BY dashboard_ads_data.date_start, dashboard_ads_data.ad_account_id, dashboard_ads_data.campaign_id, dashboard_ads_data.campaign_name, dashboard_ads_data.adset_id, dashboard_ads_data.adset_name, dashboard_ads_data.ad_id, dashboard_ads_data.ad_name
        ), pay AS (
         SELECT (dashboard_deals.paid_at AT TIME ZONE 'Europe/Kyiv'::text)::date AS day,
            lower(dashboard_deals.utm_content) AS utm_key,
            sum(dashboard_deals.amount) AS revenue,
            count(*)::integer AS payments,
            count(*) FILTER (WHERE dashboard_deals.customer_type = 'new'::text)::integer AS new_buyers,
            sum(dashboard_deals.amount) FILTER (WHERE dashboard_deals.customer_type = 'new'::text) AS revenue_new
           FROM dashboard_deals
          WHERE dashboard_deals.status = 'pay'::text AND dashboard_deals.amount > 0::numeric AND dashboard_deals.utm_medium ~ '^(facebook|instagram|messenger|audience_network|threads)_'::text
          GROUP BY ((dashboard_deals.paid_at AT TIME ZONE 'Europe/Kyiv'::text)::date), (lower(dashboard_deals.utm_content))
        ), promo AS (
         SELECT d.d::date AS day,
            string_agg(DISTINCT e.kind, ','::text) AS kinds
           FROM ad_events e,
            LATERAL generate_series((e.starts_at AT TIME ZONE 'Europe/Kyiv'::text)::date::timestamp with time zone, (e.ends_at AT TIME ZONE 'Europe/Kyiv'::text)::date::timestamp with time zone, '1 day'::interval) d(d)
          WHERE e.project = 'dreamcar'::text AND e.status <> 'cancelled'::text AND (e.kind = ANY (ARRAY['offer'::text, 'x2'::text, 'final'::text, 'launch'::text, 'promo'::text]))
          GROUP BY (d.d::date)
        )
 SELECT 'dreamcar'::text AS project,
    a.day,
    a.ad_account_id,
    a.campaign_id,
    a.campaign_name,
    a.adset_id,
    a.adset_name,
    a.ad_id,
    a.ad_name,
    a.spend,
    a.impressions,
    a.clicks,
    COALESCE(p.revenue, 0::numeric) AS revenue,
    COALESCE(p.payments, 0) AS payments,
    COALESCE(p.new_buyers, 0) AS new_buyers,
    COALESCE(p.revenue_new, 0::numeric) AS revenue_new,
    COALESCE(pr.kinds, 'normal'::text) AS day_type
   FROM ads a
     LEFT JOIN pay p ON p.day = a.day AND p.utm_key = a.utm_key
     LEFT JOIN promo pr ON pr.day = a.day;
