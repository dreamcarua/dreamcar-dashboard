-- 15.09.2026 — виправлення, знайдені аудитом дашборду (запит Вадима «фільтри, дати і тп»).
-- Застосовано на wotghlaehnvxyeacznvv 15.09.2026. Файл — для історії й повторюваності.

-- 1. Розіграш #21 не мав ні code, ні deal_aliases → auto-merge у loadProjects()
--    підставляв UUID-префікс як code і матчив угоди по старому alias 'Q7'.
--    Наслідок: активний розіграш показував 0 оплат при 4 789 у БД.
update launches
set code = 'audi_q7_prestige',
    deal_aliases = array['AUDI Q7 PRESTIGE']
where id = '424f1dbc-01ab-47b5-bef0-b3ad3c273e32'
  and (code is null or deal_aliases is null);

-- 2. Бекап рядків, зіпсованих account-level fallback у sync_fb_ads.py
--    (utm_source='facebook' + utm_medium='cpc' — підпис саме цього fallback).
create table if not exists _bak_ads_utm_20260915 as
select * from dashboard_ads_data
where date_start >= '2026-06-01' and utm_source = 'facebook' and utm_medium = 'cpc';

-- 3. Повернення реальної атрибуції: останнє відоме значення по тому ж ad_id.
--    80 рядків / 28 402 ₴ за 08–15.09 повернуто з 'artem' на 'fortunatos'.
with truth as (
  select distinct on (ad_id) ad_id, utm_source, utm_medium, utm_campaign, utm_content, utm_term
  from dashboard_ads_data
  where date_start >= '2026-06-01'
    and not (utm_source = 'facebook' and utm_medium = 'cpc')
    and utm_term is not null
  order by ad_id, date_start desc
)
update dashboard_ads_data a
set utm_source   = t.utm_source,
    utm_medium   = t.utm_medium,
    utm_campaign = coalesce(a.utm_campaign, t.utm_campaign),
    utm_content  = coalesce(a.utm_content,  t.utm_content),
    utm_term     = t.utm_term
from truth t
where a.ad_id = t.ad_id
  and a.date_start >= '2026-06-01'
  and a.utm_source = 'facebook' and a.utm_medium = 'cpc'
  and t.utm_term is distinct from a.utm_term;

-- Детектор повторення: один ad_id з кількома виконавцями у межах циклу.
-- select ad_id, count(distinct utm_term), string_agg(distinct utm_term, ',')
-- from dashboard_ads_data where date_start >= '2026-09-01'
-- group by 1 having count(distinct utm_term) > 1;
