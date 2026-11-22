-- 22.11.2026 (ad-director improve run, measurement audit 1.5b): ad_daily_real missed URL-encoded UTM.
-- utm_content of a deal is «ad set name_ad name»; part of the links carry it form-encoded ('+' for a space),
-- and names that contain '+' («Advantage+») arrive decoded as a space. The join was lower() only.
-- Week 16-22.11.2026: deals by UTM 1 812 payments 793 902 UAH, view 1 711 / 749 968 (-5.5 %);
-- 92 payments 41 243 UAH were lost to the encoding, 9 / 2 691 have no same-day ad row (not fixed here).
-- Only change: both keys normalised the same way ('+' -> space, runs of spaces -> one space, lower).
-- tools/promo_hourly.py already decodes '+' (line 285). ad-director docs/traps.md#ad-daily-real-plus-encoded-utm.
-- Rollback: apply 20261116_ad_daily_real_promo_kind.sql again.
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
            regexp_replace(replace(lower((dashboard_ads_data.adset_name || '_'::text) || dashboard_ads_data.ad_name), '+'::text, ' '::text), '\s+'::text, ' '::text, 'g'::text) AS utm_key
           FROM dashboard_ads_data
          WHERE dashboard_ads_data.ad_account_id = '4136058269783354'::text
          GROUP BY dashboard_ads_data.date_start, dashboard_ads_data.ad_account_id, dashboard_ads_data.campaign_id, dashboard_ads_data.campaign_name, dashboard_ads_data.adset_id, dashboard_ads_data.adset_name, dashboard_ads_data.ad_id, dashboard_ads_data.ad_name
        ), pay AS (
         SELECT (dashboard_deals.paid_at AT TIME ZONE 'Europe/Kyiv'::text)::date AS day,
            regexp_replace(replace(lower(dashboard_deals.utm_content), '+'::text, ' '::text), '\s+'::text, ' '::text, 'g'::text) AS utm_key,
            sum(dashboard_deals.amount) AS revenue,
            count(*)::integer AS payments,
            count(*) FILTER (WHERE dashboard_deals.customer_type = 'new'::text)::integer AS new_buyers,
            sum(dashboard_deals.amount) FILTER (WHERE dashboard_deals.customer_type = 'new'::text) AS revenue_new
           FROM dashboard_deals
          WHERE dashboard_deals.status = 'pay'::text AND dashboard_deals.amount > 0::numeric AND dashboard_deals.utm_medium ~ '^(facebook|instagram|messenger|audience_network|threads)_'::text
          GROUP BY ((dashboard_deals.paid_at AT TIME ZONE 'Europe/Kyiv'::text)::date), (regexp_replace(replace(lower(dashboard_deals.utm_content), '+'::text, ' '::text), '\s+'::text, ' '::text, 'g'::text))
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
