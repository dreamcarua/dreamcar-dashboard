-- 02.11.2026 · concept_id in ad_features (owner 02.11: «делай следующие шаги»; ad-director docs/research-notes-2026-11/01).
-- Meta groups near-identical creatives into one delivery entity (Andromeda), so learning and the 50-ads limit
-- are judged per concept, not per ad. One concept = one idea/shot; its ratio or placement versions share the id.
alter table ad_features add column if not exists concept_id text;
create index if not exists ad_features_concept_idx on ad_features(project, concept_id);

-- Backfill: the ad slug without the layer and actor parts and without format suffixes (-pac, -45, -916, -11).
with names as (
  select distinct on (ad_id) ad_id, split_part(ad_name, ' · ', 1) as slug
  from ad_daily_real where project = 'dreamcar' order by ad_id, day desc
)
update ad_features f
set concept_id = regexp_replace(n.slug, '-(pac|45|916|11)$', '')
from names n
where f.ad_id = n.ad_id and f.project = 'dreamcar' and f.concept_id is null;

comment on column ad_features.concept_id is 'One idea or shot; versions of it in other ratios or placements share the id. Set by creative-director at hand-over; backfilled from the ad slug.';
