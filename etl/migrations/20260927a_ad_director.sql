-- Ad director (pilot DreamCar), phase 0: event calendar, decision journal, alert dedupe.
-- Owner decision 27.09.2026 (dreamcar-memory decisions.md). Service role only: RLS on, no policies.

create table if not exists public.ad_events (
  id          bigserial primary key,
  project     text not null default 'dreamcar',
  cycle       text,                                   -- номер проєкту, напр. '21'
  starts_at   timestamptz not null,
  ends_at     timestamptz,
  kind        text not null check (kind in ('launch','offer','mailing','live','x2','final','handover','promo','shoot','other')),
  title       text not null,
  offer       text,
  layers      text[] default '{}',                    -- шари реклами, куди йде подія
  mailing     boolean,                                -- чи є розсилка по базі (null = невідомо)
  status      text not null default 'planned' check (status in ('planned','ready','live','done','cancelled')),
  notes       text,
  source      text,                                   -- звідки запис (рішення, людина)
  created_by  text not null default 'claude',
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now()
);
create index if not exists ad_events_starts_idx on public.ad_events (project, starts_at);

create table if not exists public.ad_journal (
  id           bigserial primary key,
  at           timestamptz not null default now(),
  project      text not null default 'dreamcar',
  actor        text not null,                         -- director | executor | vadym | watchdog
  action       text not null,                         -- pause_ad | budget | activate | proposal | note ...
  status       text not null default 'done' check (status in ('proposed','approved','rejected','done','failed','rolled_back')),
  entity_type  text,                                  -- campaign | adset | ad | account
  entity_id    text,
  entity_name  text,
  before       jsonb,
  after        jsonb,
  reason       text,                                  -- причина з цифрами
  metrics      jsonb,
  hypothesis   text,                                  -- що очікуємо
  review_at    timestamptz,                           -- коли перевірити результат
  outcome      text,
  outcome_at   timestamptz,
  rollback     jsonb                                  -- як відкотити
);
create index if not exists ad_journal_at_idx on public.ad_journal (project, at desc);

create table if not exists public.ad_alerts (
  key          text primary key,                      -- стабільний ключ дедупу: kind:entity
  project      text not null default 'dreamcar',
  kind         text not null,
  entity_id    text,
  entity_name  text,
  message      text,
  first_seen   timestamptz not null default now(),
  last_seen    timestamptz not null default now(),
  last_sent    timestamptz,
  sent_count   int not null default 0,
  resolved_at  timestamptz
);

create or replace function public.ad_touch_updated_at() returns trigger
language plpgsql set search_path = public as $$
begin new.updated_at := now(); return new; end $$;

drop trigger if exists ad_events_touch on public.ad_events;
create trigger ad_events_touch before update on public.ad_events
  for each row execute function public.ad_touch_updated_at();

alter table public.ad_events  enable row level security;
alter table public.ad_journal enable row level security;
alter table public.ad_alerts  enable row level security;
revoke all on public.ad_events, public.ad_journal, public.ad_alerts from anon, authenticated;

-- Дані календаря вносяться окремо (не в цей публічний репозиторій: подарунки не можна показувати до анонсу).
