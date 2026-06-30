#!/usr/bin/env python3
"""
Engagement-кампанія DC|06: один IG-пост у всі групи -> коменти копляться в одному треді.
MODE=rebuild: створює ad sets через Graph (promoted_object=Page + ON_POST + POST_ENGAGEMENT),
чіпляє пост (CTA-кнопка на t.me/DreamCar_CLUB), архівує старі биті групи.

Передумови/граблі:
- токен у LIVE-додатку DC new (1897152837652670), інакше subcode 1885183;
- IMAGE-пост як ad потребує CTA+link, інакше subcode 2446383;
- ad set engagement потребує promoted_object на момент створення, інакше subcode 1885154;
- geo-таргет потребує location_types:["home","recent"], інакше subcode 1870227.

GROUP_NUMS (env) — фільтр груп за номером, напр. "4,5". Порожньо = всі.
CREATIVE_ID (env) — переused існуючий креатив (щоб не плодити).
"""
import os, json, urllib.parse, urllib.request, urllib.error, sys

GRAPH = os.environ.get("GRAPH_VER", "v21.0")
TOKEN = os.environ["FB_ACCESS_TOKEN"]
ACT = os.environ.get("AD_ACCOUNT_ID", "4136058269783354")
IG_USER = os.environ.get("IG_USER_ID", "17841403783002317")
PAGE_ID = os.environ.get("PAGE_ID", "1676843282640684")
CAMPAIGN_ID = os.environ.get("CAMPAIGN_ID", "120250690345200624")
SHORTCODE = os.environ.get("SHORTCODE", "DaLlUIlMWD9").strip()
IG_MEDIA_ID = os.environ.get("IG_MEDIA_ID", "").strip()
CREATIVE_ID_IN = os.environ.get("CREATIVE_ID", "1336439131947353").strip()
LINK_URL = os.environ.get("LINK_URL", "https://t.me/DreamCar_CLUB").strip()
CTA_TYPE = os.environ.get("CTA_TYPE", "LEARN_MORE").strip()
DAILY_BUDGET = os.environ.get("DAILY_BUDGET", "20000").strip()
MODE = os.environ.get("MODE", "rebuild").strip().lower()
GROUP_NUMS = [x.strip() for x in os.environ.get("GROUP_NUMS", "4,5").split(",") if x.strip()]
ATTACH_ADSETS = [x.strip() for x in os.environ.get("ADSET_IDS", "").split(",") if x.strip()]
OLD_ADSETS = [x.strip() for x in os.environ.get("OLD_ADSETS", "120250690349300624,120250690350550624,120250690351790624,120250690353260624,120250690352530624").split(",") if x.strip()]
DRY = os.environ.get("DRY_RUN", "true").lower() == "true"
BASE = f"https://graph.facebook.com/{GRAPH}"

GROUPS = [
    ("1 · Тепла база · IG-engagers + Engaged Page 90д", {"custom_audiences": [{"id": "120239206735980624"}, {"id": "120239206222220624"}], "age_min": 18, "age_max": 65}),
    ("2 · Підписники dreamcar (followers)", {"custom_audiences": [{"id": "120237307584540624"}], "age_min": 18, "age_max": 65}),
    ("3 · LAL 1% покупців сайту", {"custom_audiences": [{"id": "120249981511060624"}], "age_min": 18, "age_max": 65}),
    ("4 · Broad UA 18-45", {"age_min": 18, "age_max": 45}),
    ("5 · Чол 25-54 (ядро, hard cap)", {"genders": [1], "age_min": 25, "age_max": 54, "targeting_automation": {"advantage_audience": 0}}),
]


def api(path, params=None, data=None, method="GET"):
    params = dict(params or {})
    if method == "GET":
        params["access_token"] = TOKEN
        url = f"{BASE}/{path}?" + urllib.parse.urlencode(params)
        req = urllib.request.Request(url, method="GET")
    else:
        body = dict(data or {})
        body["access_token"] = TOKEN
        req = urllib.request.Request(f"{BASE}/{path}", data=urllib.parse.urlencode(body).encode(), method="POST")
    try:
        with urllib.request.urlopen(req) as r:
            return json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        raise RuntimeError(f"HTTP {e.code} {method} {path}: {e.read().decode()}")


def whoami():
    try:
        d = api("debug_token", params={"input_token": TOKEN}).get("data", {})
        print("TOKEN_APP_ID:", d.get("app_id"), "| app:", d.get("application"))
    except Exception as e:
        print("debug_token failed:", e)


def resolve_media():
    if IG_MEDIA_ID:
        return IG_MEDIA_ID
    after = None
    while True:
        params = {"fields": "id,permalink,media_type", "limit": "50"}
        if after:
            params["after"] = after
        res = api(f"{IG_USER}/media", params=params)
        for m in res.get("data", []):
            if SHORTCODE in (m.get("permalink") or ""):
                print("MATCH:", m.get("id"), m.get("permalink"))
                return m["id"]
        after = res.get("paging", {}).get("cursors", {}).get("after")
        if not after:
            break
    raise RuntimeError(f"Shortcode {SHORTCODE} not found")


def base_targeting(extra):
    t = {
        "geo_locations": {"countries": ["UA"], "location_types": ["home", "recent"]},
        "publisher_platforms": ["facebook", "instagram"],
        "facebook_positions": ["feed", "facebook_reels"],
        "instagram_positions": ["stream", "reels", "explore"],
    }
    t.update(extra)
    return t


def create_adset(name, extra):
    res = api(f"act_{ACT}/adsets", data={
        "name": name,
        "campaign_id": CAMPAIGN_ID,
        "status": "PAUSED",
        "billing_event": "IMPRESSIONS",
        "optimization_goal": "POST_ENGAGEMENT",
        "destination_type": "ON_POST",
        "promoted_object": json.dumps({"page_id": PAGE_ID}),
        "daily_budget": DAILY_BUDGET,
        "bid_strategy": "LOWEST_COST_WITHOUT_CAP",
        "targeting": json.dumps(base_targeting(extra)),
    }, method="POST")
    print(f"ADSET OK '{name}' -> {res.get('id')}")
    return res["id"]


def create_creative(media_id):
    if CREATIVE_ID_IN:
        print("Using CREATIVE_ID:", CREATIVE_ID_IN)
        return CREATIVE_ID_IN
    name = f"DC|06 engagement {SHORTCODE}"
    cta = json.dumps({"type": CTA_TYPE, "value": {"link": LINK_URL}})
    for a in [
        {"name": name, "instagram_user_id": IG_USER, "source_instagram_media_id": media_id, "call_to_action": cta},
        {"name": name, "instagram_user_id": IG_USER, "source_instagram_media_id": media_id},
    ]:
        try:
            res = api(f"act_{ACT}/adcreatives", data=a, method="POST")
            print("CREATIVE OK:", res.get("id"))
            return res["id"]
        except Exception as e:
            print("creative attempt failed:", e)
    raise RuntimeError("creative failed")


def create_ad(adset_id, creative_id, tag):
    res = api(f"act_{ACT}/ads", data={
        "name": f"engagement · {SHORTCODE} · {tag}",
        "adset_id": adset_id,
        "creative": json.dumps({"creative_id": creative_id}),
        "status": "PAUSED",
    }, method="POST")
    print(f"AD OK adset {adset_id} -> {res.get('id')}")
    return res.get("id")


def ensure_promoted_object(adset_id):
    """P0 FIX 30.06.2026: Проверить что ad set имеет promoted_object для POST_ENGAGEMENT"""
    try:
        info = api(adset_id, params={"fields": "id,promoted_object,optimization_goal"})
        po = info.get("promoted_object")
        if not po:
            print(f"⚠️  ADSET {adset_id} missing promoted_object (goal={info.get('optimization_goal')}), attempting UPDATE...")
            api(adset_id, data={"promoted_object": json.dumps({"page_id": PAGE_ID})}, method="POST")
            print(f"✅ UPDATED adset {adset_id} with promoted_object")
        return True
    except Exception as e:
        print(f"❌ ensure_promoted_object failed for {adset_id}: {e}")
        return False


def archive(adset_id):
    try:
        api(adset_id, data={"status": "ARCHIVED"}, method="POST")
        print("ARCHIVED old adset", adset_id)
    except Exception as e:
        print("archive failed", adset_id, e)


def main():
    print(f"== DC|06 engagement | mode={MODE} groups={GROUP_NUMS or 'all'} dry={DRY} link={LINK_URL} ==")
    whoami()
    media_id = resolve_media()
    if DRY:
        print("DRY_RUN: stop.")
        return
    creative_id = create_creative(media_id)
    results = []
    if MODE == "rebuild":
        for name, extra in GROUPS:
            num = name.split(" ")[0]
            if GROUP_NUMS and num not in GROUP_NUMS:
                continue
            try:
                asid = create_adset(name, extra)
                adid = create_ad(asid, creative_id, num)
                results.append((name, asid, adid))
            except Exception as e:
                print("GROUP FAIL", name, e)
                results.append((name, "ERROR", str(e)))
        for old in OLD_ADSETS:
            archive(old)
    else:
        for asid in ATTACH_ADSETS:
            try:
                # P0 FIX 30.06.2026: Проверить что ad set имеет promoted_object перед attach
                if not ensure_promoted_object(asid):
                    results.append((asid, f"ERROR: Failed to ensure promoted_object"))
                    continue
                results.append((asid, create_ad(asid, creative_id, asid[-5:])))
            except Exception as e:
                print("AD FAIL", asid, e)
                results.append((asid, f"ERROR {e}"))
    print("SUMMARY:", json.dumps(results, ensure_ascii=False))
    if any("ERROR" in str(r[-1]) or r[-1] is None for r in results):
        sys.exit(1)


if __name__ == "__main__":
    main()
