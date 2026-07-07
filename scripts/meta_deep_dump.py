#!/usr/bin/env python3
"""
Deep dump рекламного акаунту DreamCar (act 4136058269783354) для дослідження структури.
Тягне ВСЕ read-only: структуру (campaigns/adsets/ads/audiences з таргетингами),
інсайти YTD денно, 90д по рівнях, breakdowns (age×gender, placement, hourly, region, device).
Результат: out/*.json -> GitHub Actions artifact.
env: FB_ACCESS_TOKEN (secret), AD_ACCOUNT_ID (default 4136058269783354)
"""
import os, json, time, urllib.parse, urllib.request, urllib.error, datetime

GRAPH = os.environ.get("GRAPH_VER", "v21.0")
TOKEN = os.environ["FB_ACCESS_TOKEN"]
ACT = os.environ.get("AD_ACCOUNT_ID", "4136058269783354")
BASE = f"https://graph.facebook.com/{GRAPH}"
TODAY = datetime.date.today()
R90 = {"since": str(TODAY - datetime.timedelta(days=90)), "until": str(TODAY)}
YTD = {"since": "2026-01-01", "until": str(TODAY)}
os.makedirs("out", exist_ok=True)

INS_FIELDS = "spend,impressions,clicks,ctr,cpm,cpc,reach,frequency,actions,action_values,date_start,date_stop"


def get(url):
    for attempt in range(3):
        try:
            with urllib.request.urlopen(urllib.request.Request(url), timeout=120) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            body = e.read().decode()[:300]
            print(f"  HTTP {e.code} (attempt {attempt+1}): {body}")
            if e.code in (4, 17, 32, 613) or "limit" in body.lower():
                time.sleep(30)
            else:
                time.sleep(5)
        except Exception as e:
            print(f"  ERR (attempt {attempt+1}): {e}")
            time.sleep(5)
    return None


def paged(path, params):
    params = dict(params)
    params["access_token"] = TOKEN
    url = f"{BASE}/{path}?" + urllib.parse.urlencode(params)
    rows = []
    while url:
        res = get(url)
        if not res:
            break
        rows.extend(res.get("data", []))
        url = res.get("paging", {}).get("next")
        time.sleep(0.4)
    return rows


def dump(name, rows):
    with open(f"out/{name}.json", "w") as f:
        json.dump(rows, f, ensure_ascii=False)
    print(f"{name}: {len(rows)} rows")


def insights(name, level, trange, increment=None, breakdowns=None, extra_fields=""):
    p = {
        "level": level,
        "time_range": json.dumps(trange),
        "fields": INS_FIELDS + (f",{level}_id,{level}_name" if level != "account" else "") + extra_fields,
        "limit": "300",
    }
    if increment:
        p["time_increment"] = increment
    if breakdowns:
        p["breakdowns"] = breakdowns
    dump(name, paged(f"act_{ACT}/insights", p))


# ===== 1. Структура =====
dump("campaigns", paged(f"act_{ACT}/campaigns", {
    "fields": "id,name,objective,status,effective_status,daily_budget,lifetime_budget,bid_strategy,buying_type,special_ad_categories,created_time,start_time,stop_time,updated_time",
    "limit": "200"}))
dump("adsets", paged(f"act_{ACT}/adsets", {
    "fields": "id,name,campaign_id,status,effective_status,daily_budget,lifetime_budget,optimization_goal,billing_event,bid_strategy,bid_amount,destination_type,promoted_object,attribution_spec,targeting,created_time,start_time,end_time",
    "limit": "150"}))
dump("ads", paged(f"act_{ACT}/ads", {
    "fields": "id,name,adset_id,campaign_id,status,effective_status,created_time,creative{id,object_story_id,url_tags,video_id}",
    "limit": "200"}))
dump("audiences", paged(f"act_{ACT}/customaudiences", {
    "fields": "id,name,subtype,approximate_count_lower_bound,approximate_count_upper_bound,delivery_status,lookalike_spec,retention_days,rule_aggregation,time_updated",
    "limit": "200"}))

# ===== 2. Інсайти: тренди =====
insights("ins_account_daily_ytd", "account", YTD, increment="1")
insights("ins_campaign_daily_90", "campaign", R90, increment="1")
insights("ins_adset_90", "adset", R90)
insights("ins_ad_90", "ad", R90)

# ===== 3. Breakdowns (90д, account) =====
insights("br_age_gender_90", "account", R90, breakdowns="age,gender")
insights("br_platform_90", "account", R90, breakdowns="publisher_platform,platform_position")
insights("br_hourly_90", "account", R90, breakdowns="hourly_stats_aggregated_by_audience_time_zone")
insights("br_region_90", "account", R90, breakdowns="region")
insights("br_device_90", "account", R90, breakdowns="device_platform")
# campaign-level placement (де яка кампанія їде)
insights("br_campaign_platform_90", "campaign", R90, breakdowns="publisher_platform,platform_position")
# age×gender у розрізі кампаній — для структурних висновків
insights("br_campaign_age_90", "campaign", R90, breakdowns="age")

# ===== 4. v2 (07.07.2026): втома крео + девайси + комплаєнс =====
# ad×day — крива втоми креативів (день життя ада vs ROAS/CTR)
insights("ins_ad_daily_90", "ad", R90, increment="1")
# iOS vs Android
insights("br_impression_device_90", "account", R90, breakdowns="impression_device")
# тексти проблемних адів (DISAPPROVED/WITH_ISSUES) для compliance-переписування
dump("problem_ads_creatives", paged(f"act_{ACT}/ads", {
    "fields": "id,name,campaign_id,adset_id,effective_status,creative{id,body,title,object_story_spec}",
    "filtering": json.dumps([{"field": "ad.effective_status", "operator": "IN", "value": ["DISAPPROVED", "WITH_ISSUES"]}]),
    "limit": "100"}))

print("DONE")
