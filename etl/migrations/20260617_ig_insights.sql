-- Instagram organic insights — таблиці для etl/sync_ig_insights.py
-- Застосувати через Supabase MCP apply_migration або supabase db push.
-- Патерн нейму: dashboard_* (як dashboard_ads_data).

-- 1) Денний знімок акаунта (тренд підписників / охоплення / залученості)
create table if not exists public.dashboard_ig_account_daily (
  ig_user_id          text        not null,
  username            text,
  date                date        not null,
  followers_count     integer,
  media_count         integer,
  follows_count       integer,
  reach               integer,
  profile_views       integer,
  website_clicks      integer,
  accounts_engaged    integer,
  total_interactions  integer,
  raw_data            jsonb,
  updated_at          timestamptz not null default now(),
  primary key (ig_user_id, date)
);

-- 2) Метрики по кожному посту / reels / story (органіка)
create table if not exists public.dashboard_ig_media (
  media_id            text        primary key,
  ig_user_id          text        not null,
  caption             text,
  media_type          text,        -- IMAGE / VIDEO / CAROUSEL_ALBUM
  media_product_type  text,        -- FEED / REELS / STORY
  permalink           text,
  published_at        timestamptz,
  like_count          integer,
  comments_count      integer,
  reach               integer,
  saved               integer,
  shares              integer,
  views               integer,
  total_interactions  integer,
  engagement_rate     numeric,     -- (interactions / reach) * 100
  raw_data            jsonb,
  synced_at           timestamptz not null default now()
);

create index if not exists idx_ig_media_user_published
  on public.dashboard_ig_media (ig_user_id, published_at desc);
create index if not exists idx_ig_account_daily_date
  on public.dashboard_ig_account_daily (date desc);

-- RLS: вмикаємо (service-role ETL обходить; читання дашборду — додати select-політику за потреби)
alter table public.dashboard_ig_account_daily enable row level security;
alter table public.dashboard_ig_media         enable row level security;
