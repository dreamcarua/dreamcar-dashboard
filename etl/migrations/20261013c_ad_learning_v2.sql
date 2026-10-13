-- 13.10.2026 · Learning loop v2 after the independent review (see ad-director docs/decisions.md 13.10.2026).
-- ad_scorecard: day type filter, today excluded, prior from the ad set WITHOUT the ad (leave-one-out),
--   band on the log scale with order-value spread: rel_se = sqrt((1+cv^2)/(payments+k0)), k0 = prior spend × payments per UAH of the set.
-- ad_tag_effects: each ad-day is compared with its own ad set on the same day without itself (expected revenue),
--   ratio = Σrevenue / Σexpected; n_ads, top ad share, leave-one-ad-out min/max, band; metric revenue or revenue_new.
-- New names (v2) instead of replacing: dropping needs a human confirmation in this setup. v1 functions stay, superseded.
-- Rollback: drop function ad_scorecard_v2(text,int,numeric,text,numeric); drop function ad_tag_effects_v2(text,int,text,text,text,numeric);

create or replace function public.ad_scorecard_v2(p_project text default 'dreamcar', p_days int default 7,
    p_prior_spend numeric default 1500, p_day_type text default 'any', p_cv numeric default 1.2)
returns table (ad_id text, ad_name text, adset_id text, adset_name text, spend numeric, revenue numeric,
               payments int, new_buyers int, real_roas numeric, set_roas_without_ad numeric, shrunk_roas numeric,
               low80 numeric, high80 numeric, ctr_3d numeric, ctr_prev7 numeric, ctr_change numeric,
               promo_spend_share numeric, days_live int)
language sql stable as $$
  with today as (select (now() at time zone 'Europe/Kyiv')::date d),
  w as (
    select r.* from public.ad_daily_real r, today
    where r.project = p_project and r.day > today.d - p_days and r.day < today.d
      and (p_day_type = 'any' or (p_day_type = 'normal' and r.day_type = 'normal')
           or (p_day_type = 'promo' and r.day_type <> 'normal'))
  ), per_ad as (
    select w.ad_id, max(w.ad_name) ad_name, max(w.adset_id) adset_id, max(w.adset_name) adset_name,
           sum(w.spend) spend, sum(w.revenue) revenue, sum(w.payments)::int payments, sum(w.new_buyers)::int new_buyers,
           sum(w.spend) filter (where w.day_type <> 'normal') promo_spend, count(distinct w.day)::int days_live
    from w group by w.ad_id having sum(w.spend) > 0
  ), per_set as (
    select w.adset_id, sum(w.spend) s, sum(w.revenue) r, sum(w.payments) n from w group by w.adset_id
  ), prior as (
    select a.*,
           coalesce((s.r - a.revenue) / nullif(s.s - a.spend, 0), s.r / nullif(s.s, 0), 0) loo_roas,
           coalesce((s.n - a.payments) / nullif(s.s - a.spend, 0), s.n / nullif(s.s, 0), 0) loo_rate
    from per_ad a join per_set s on s.adset_id = a.adset_id
  ), ctr as (
    select d.ad_id,
      sum(d.clicks) filter (where d.day >= today.d - 3)::numeric / nullif(sum(d.impressions) filter (where d.day >= today.d - 3), 0) c3,
      sum(d.clicks) filter (where d.day < today.d - 3)::numeric / nullif(sum(d.impressions) filter (where d.day < today.d - 3), 0) c7
    from public.ad_daily_real d, today where d.project = p_project and d.day >= today.d - 10 and d.day < today.d
    group by d.ad_id
  ), est as (
    select p.*, (p.revenue + p_prior_spend * p.loo_roas) / (p.spend + p_prior_spend) shrunk,
           sqrt((1 + p_cv * p_cv) / greatest(p.payments + p_prior_spend * p.loo_rate, 0.5)) rel_se
    from prior p
  )
  select e.ad_id, e.ad_name, e.adset_id, e.adset_name, round(e.spend, 2), round(e.revenue, 2), e.payments, e.new_buyers,
         round(e.revenue / nullif(e.spend, 0), 2), round(e.loo_roas, 2), round(e.shrunk, 2),
         round(e.shrunk * exp(-1.28 * e.rel_se), 2), round(e.shrunk * exp(1.28 * e.rel_se), 2),
         round(c.c3 * 100, 2), round(c.c7 * 100, 2), round(c.c3 / nullif(c.c7, 0) - 1, 2),
         round(coalesce(e.promo_spend, 0) / nullif(e.spend, 0), 2), e.days_live
  from est e left join ctr c on c.ad_id = e.ad_id
  order by e.shrunk desc;
$$;

create or replace function public.ad_tag_effects_v2(p_project text default 'dreamcar', p_days int default 30,
    p_feature text default 'angle', p_day_type text default 'normal', p_metric text default 'revenue',
    p_cv numeric default 1.2)
returns table (feature_value text, n_ads int, spend numeric, revenue numeric, expected numeric, payments int,
               ratio numeric, low80 numeric, high80 numeric, loo_min numeric, loo_max numeric, top_ad_share numeric)
language sql stable as $$
  with today as (select (now() at time zone 'Europe/Kyiv')::date d),
  base as (
    select r.ad_id, r.adset_id, r.day, r.spend, r.payments,
           case when p_metric = 'revenue_new' then r.revenue_new else r.revenue end rev,
           case p_feature
             when 'angle' then f.angle when 'format' then f.format when 'source' then f.source
             when 'offer_kind' then f.offer_kind when 'text_density' then f.text_density
             when 'shows_price' then f.shows_price::text when 'people' then f.people::text
             else f.tags ->> p_feature end as v
    from public.ad_daily_real r join public.ad_features f on f.ad_id = r.ad_id, today
    where r.project = p_project and r.day > today.d - p_days and r.day < today.d and r.spend > 0
      and (p_day_type = 'any' or (p_day_type = 'normal' and r.day_type = 'normal')
           or (p_day_type = 'promo' and r.day_type <> 'normal'))
  ), setday as (
    select adset_id, day, sum(spend) s, sum(rev) r from base group by 1, 2
  ), rows_ as (
    select b.*, b.spend * (sd.r - b.rev) / nullif(sd.s - b.spend, 0) expected
    from base b join setday sd using (adset_id, day)
  ), per_ad as (
    select coalesce(v, '∅') v, ad_id, sum(spend) spend, sum(rev) rev, sum(expected) e, sum(payments) n
    from rows_ where expected is not null group by 1, 2
  ), per_v as (
    select v, count(*)::int n_ads, sum(spend) spend, sum(rev) rev, sum(e) e, sum(n)::int n, max(spend) top
    from per_ad group by v
  ), loo as (
    select a.v, min((p.rev - a.rev) / nullif(p.e - a.e, 0)) lmin, max((p.rev - a.rev) / nullif(p.e - a.e, 0)) lmax
    from per_ad a join per_v p using (v) where p.n_ads > 1 group by a.v
  )
  select p.v, p.n_ads, round(p.spend, 0), round(p.rev, 0), round(p.e, 0), p.n,
         round(p.rev / nullif(p.e, 0), 2),
         round(p.rev / nullif(p.e, 0) * exp(-1.28 * sqrt((1 + p_cv * p_cv) / greatest(p.n, 0.5))), 2),
         round(p.rev / nullif(p.e, 0) * exp(1.28 * sqrt((1 + p_cv * p_cv) / greatest(p.n, 0.5))), 2),
         round(l.lmin, 2), round(l.lmax, 2), round(p.top / nullif(p.spend, 0), 2)
  from per_v p left join loo l using (v)
  order by p.spend desc;
$$;
