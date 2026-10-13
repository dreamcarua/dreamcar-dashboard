-- 13.10.2026 · Learning loop of the ad director (owner: «ты должен УЧИТЬСЯ!»).
-- Facts per ad per day with real revenue, creative features, a registry of learnings, and two scoring functions.
-- Read-only for modes except ad_features and ad_learnings (written by modes, reviewed weekly).
-- Rollback: drop function ad_tag_effects(text,int,text); drop function ad_scorecard(text,int,numeric);
--           drop view ad_daily_real; drop table ad_learnings; drop table ad_features;

-- 1) Facts: one row per ad per day (Kyiv day). Real revenue = CRM payments with an ad placement mark in utm_medium,
--    matched by utm_content = "<adset name>_<ad name>" (case-insensitive), counted on the payment day.
create or replace view public.ad_daily_real as
with ads as (
  select date_start as day, ad_account_id, campaign_id, campaign_name, adset_id, adset_name, ad_id, ad_name,
         sum(spend)::numeric spend, sum(impressions)::bigint impressions, sum(clicks)::bigint clicks,
         lower(adset_name || '_' || ad_name) as utm_key
  from public.dashboard_ads_data
  where ad_account_id = '4136058269783354'
  group by 1,2,3,4,5,6,7,8
), pay as (
  select (paid_at at time zone 'Europe/Kyiv')::date as day, lower(utm_content) as utm_key,
         sum(amount)::numeric revenue, count(*)::int payments,
         count(*) filter (where customer_type = 'new')::int new_buyers,
         sum(amount) filter (where customer_type = 'new')::numeric revenue_new
  from public.dashboard_deals
  where status = 'pay' and amount > 0
    and utm_medium ~ '^(facebook|instagram|messenger|audience_network|threads)_'
  group by 1,2
), promo as (
  select d::date as day, string_agg(distinct kind, ',') kinds
  from public.ad_events e, generate_series((e.starts_at at time zone 'Europe/Kyiv')::date,
                                           (e.ends_at at time zone 'Europe/Kyiv')::date, interval '1 day') d
  where e.project = 'dreamcar' and e.status <> 'cancelled' and e.kind in ('offer','x2','final','launch')
  group by 1
)
select 'dreamcar'::text as project, a.day, a.ad_account_id, a.campaign_id, a.campaign_name, a.adset_id, a.adset_name,
       a.ad_id, a.ad_name, a.spend, a.impressions, a.clicks,
       coalesce(p.revenue, 0) revenue, coalesce(p.payments, 0) payments,
       coalesce(p.new_buyers, 0) new_buyers, coalesce(p.revenue_new, 0) revenue_new,
       coalesce(pr.kinds, 'normal') as day_type
from ads a
left join pay p on p.day = a.day and p.utm_key = a.utm_key
left join promo pr on pr.day = a.day;

comment on view public.ad_daily_real is 'Per ad per Kyiv day: spend, impressions, clicks (Meta) + real revenue, payments, new buyers (CRM by UTM, ad placement marks) + day_type from ad_events. Learning loop 13.10.2026.';

-- 2) Creative features: what an ad IS, so results can be learnt per element, not per ad id.
create table if not exists public.ad_features (
  ad_id text primary key,
  project text not null default 'dreamcar',
  creative_id text,
  cycle_no int,
  layer text,                 -- profile layer key (core, base, ...)
  source text,                -- creative_director | team_banner | team_video | organic_post | organic_reel | offer_post | other
  format text,                -- image | carousel | video | reel | multi_format
  angle text,                 -- aspiration | price | proof | urgency | mechanic | offer | lifestyle | detail | other
  hook text,                  -- first line / first 2 seconds in a few words
  offer_kind text,            -- none | offer | x2 | final | launch
  shows_price boolean,
  shows_car boolean,
  people boolean,
  text_density text,          -- none | low | high
  tags jsonb not null default '{}'::jsonb,   -- any further element tags
  tagged_by text,             -- mode or session that tagged it
  tagged_at timestamptz not null default now()
);
alter table public.ad_features enable row level security;

-- 3) Learnings: what the system believes, with evidence; decisions cite them by id.
create table if not exists public.ad_learnings (
  id bigserial primary key,
  project text not null default 'dreamcar',
  statement text not null,              -- one sentence, testable
  scope jsonb not null default '{}'::jsonb,   -- {layer, day_type, format, angle, phase, audience}
  metric text not null default 'real_roas',
  effect numeric,                        -- e.g. ratio vs comparison (1.35 = +35 %)
  low numeric, high numeric,             -- 80 % band of the effect
  n_ads int, spend numeric, payments int,
  window_from date, window_to date,
  evidence jsonb,                        -- query or numbers behind it
  status text not null default 'candidate' check (status in ('candidate','confirmed','refuted','retired')),
  confirmations int not null default 0,  -- independent windows / cycles that agreed
  source_rows bigint[],                  -- ad_journal ids whose outcomes fed it
  used_in text,                          -- which rule or brief uses it
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  reviewed_by text
);
alter table public.ad_learnings enable row level security;

-- 4) Scorecard: each ad of the window with raw and shrunk real ROAS (empirical Bayes toward its ad set),
--    an 80 % band from the payment count, a fatigue signal (CTR of the last 3 days vs the 7 days before).
--    p_prior_spend: how much spend the prior is worth (default 1500 = the kill-switch spend).
create or replace function public.ad_scorecard(p_project text default 'dreamcar', p_days int default 7,
                                                p_prior_spend numeric default 1500)
returns table (ad_id text, ad_name text, adset_id text, adset_name text, spend numeric, revenue numeric,
               payments int, new_buyers int, real_roas numeric, adset_roas numeric, shrunk_roas numeric,
               low80 numeric, high80 numeric, ctr_3d numeric, ctr_prev7 numeric, ctr_change numeric,
               promo_spend_share numeric, days_live int)
language sql stable as $$
  with w as (
    select * from public.ad_daily_real
    where project = p_project and day > (now() at time zone 'Europe/Kyiv')::date - p_days
  ), per_ad as (
    select ad_id, max(ad_name) ad_name, max(adset_id) adset_id, max(adset_name) adset_name,
           sum(spend) spend, sum(revenue) revenue, sum(payments)::int payments, sum(new_buyers)::int new_buyers,
           sum(spend) filter (where day_type <> 'normal') promo_spend, count(distinct day)::int days_live
    from w group by ad_id having sum(spend) > 0
  ), per_set as (
    select adset_id, sum(revenue) / nullif(sum(spend), 0) set_roas from w group by adset_id
  ), ctr as (
    select d.ad_id,
      sum(d.clicks) filter (where d.day > (now() at time zone 'Europe/Kyiv')::date - 3)::numeric
        / nullif(sum(d.impressions) filter (where d.day > (now() at time zone 'Europe/Kyiv')::date - 3), 0) c3,
      sum(d.clicks) filter (where d.day <= (now() at time zone 'Europe/Kyiv')::date - 3
                              and d.day > (now() at time zone 'Europe/Kyiv')::date - 10)::numeric
        / nullif(sum(d.impressions) filter (where d.day <= (now() at time zone 'Europe/Kyiv')::date - 3
                                               and d.day > (now() at time zone 'Europe/Kyiv')::date - 10), 0) c7
    from public.ad_daily_real d where d.project = p_project
      and d.day > (now() at time zone 'Europe/Kyiv')::date - 10 group by d.ad_id
  )
  select a.ad_id, a.ad_name, a.adset_id, a.adset_name, round(a.spend, 2), round(a.revenue, 2), a.payments, a.new_buyers,
         round(a.revenue / nullif(a.spend, 0), 2),
         round(s.set_roas, 2),
         round((a.revenue + p_prior_spend * coalesce(s.set_roas, 0)) / (a.spend + p_prior_spend), 2) as shrunk,
         round(greatest(0, (a.revenue + p_prior_spend * coalesce(s.set_roas, 0)) / (a.spend + p_prior_spend)
                * (1 - 1.28 / sqrt((a.payments + 1)::numeric))), 2),
         round((a.revenue + p_prior_spend * coalesce(s.set_roas, 0)) / (a.spend + p_prior_spend)
                * (1 + 1.28 / sqrt((a.payments + 1)::numeric)), 2),
         round(c.c3 * 100, 2), round(c.c7 * 100, 2), round(c.c3 / nullif(c.c7, 0) - 1, 2),
         round(coalesce(a.promo_spend, 0) / nullif(a.spend, 0), 2), a.days_live
  from per_ad a left join per_set s on s.adset_id = a.adset_id left join ctr c on c.ad_id = a.ad_id
  order by shrunk desc;
$$;

-- 5) Element effects: real ROAS per value of a feature (angle, format, source, ...) against the rest of the same
--    ad sets, normal days only by default, with ad counts so thin evidence is visible.
create or replace function public.ad_tag_effects(p_project text default 'dreamcar', p_days int default 30,
                                                  p_feature text default 'angle', p_day_type text default 'normal')
returns table (feature_value text, n_ads int, spend numeric, revenue numeric, payments int, real_roas numeric,
               rest_roas numeric, ratio numeric)
language sql stable as $$
  with d as (
    select r.*, case p_feature
        when 'angle' then f.angle when 'format' then f.format when 'source' then f.source
        when 'offer_kind' then f.offer_kind when 'text_density' then f.text_density
        when 'shows_price' then f.shows_price::text when 'people' then f.people::text
        else f.tags ->> p_feature end as v
    from public.ad_daily_real r join public.ad_features f on f.ad_id = r.ad_id
    where r.project = p_project and r.day > (now() at time zone 'Europe/Kyiv')::date - p_days
      and (p_day_type = 'any' or (p_day_type = 'normal' and r.day_type = 'normal')
           or (p_day_type = 'promo' and r.day_type <> 'normal'))
  ), g as (
    select coalesce(v, '∅') v, count(distinct ad_id)::int n_ads, sum(spend) spend, sum(revenue) revenue,
           sum(payments)::int payments from d group by 1
  ), t as (select sum(spend) s, sum(revenue) r from d)
  select g.v, g.n_ads, round(g.spend, 0), round(g.revenue, 0), g.payments, round(g.revenue / nullif(g.spend, 0), 2),
         round((t.r - g.revenue) / nullif(t.s - g.spend, 0), 2),
         round((g.revenue / nullif(g.spend, 0)) / nullif((t.r - g.revenue) / nullif(t.s - g.spend, 0), 0), 2)
  from g, t order by g.spend desc;
$$;
