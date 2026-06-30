#!/usr/bin/env python3
"""
Engagement-кампанія DC|06: один IG-пост у всі групи -> коменти копляться в одному треді.
MODE=rebuild (default): створює 5 ad sets через Graph (promoted_object=Page + destination ON_POST +
POST_ENGAGEMENT), чіпляє пост (з CTA-кнопкою на t.me/DreamCar_CLUB), архівує старі биті групи.
MODE=attach: лише чіпляє пост у вже задані ADSET_IDS.

Передумови: токен у LIVE-додатку DC new (1897152837652670); IMAGE-пост як ad потребує CTA+link (subcode 2446383);
ad set engagement потребує promoted_object на момент створення (subcode 1885154).
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
CREATIVE_ID_IN = os.environ.get("CREATIVE_ID", "").strip()
LINK_URL = os.environ.get("LINK_URL", "https://t.me/DreamCar_CLUB").strip()
CTA_TYPE = os.environ.get("CTA_TYPE", "LEARN_MORE").strip()
DAILY_BUDGET = os.environ.get("DAILY_BUDGET", "20000").strip()
MODE = os.environ.get("MODE", "rebuild").strip().lower()
ATTACH_ADSETS = [x.strip() for x in os.environ.get("ADSET_IDS", "").split(",") if x.strip()]
OLD_ADSETS = [x.strip() for x in os.environ.get("OLD_ADSETS", "120250690349300624,120250690350550624,120250690351790624,120250690353260624,120250690352530624").split(",") if x.strip()]
DRY = os.environ.get("DRY_RUN", "true").lower() == "true"
BASE = f"https://graph.facebook.com/{GRAPH}"

# 5 груп: назва + специфіка таргета (поверх базового UA + feed/reels)
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
        print("TOKEN_APP_ID:", d.get("app_id"), "| app:", d.get("application"), "| type:", d.get("type"))
    except Exception as e:
        print("debug_token failed:", e)


def resolve_media():
    if IG_MEDIA_ID:
        print("Using provided IG_MEDIA_ID:", IG_MEDIA_ID)
        return IG_MEDIA_ID
    after = None
    while True:
        params = {"fields": "id,permalink,media_type,caption", "limit": "50"}
        if after:
            params["after"] = after
        res = api(f"{IG_USER}/media", params=params)
        for m in res.get("data", []):
            if SHORTCODE in (m.get("permalink") or ""):
                print("MATCH:", m.get("id"), m.get("permalink"), m.get("media_type"))
                return m["id"]
        after = res.get("paging", {}).get("cursors", {}).get("after")
        if not after:
            break
    raise RuntimeError(f"Shortcode {SHORTCODE} not found")


def base_targeting(extra):
    t = {
        "geo_locations": {"countries": ["UA"]},
        "publisher_platforms": ["facebook", "instagram"],
        "facebook_positions": ["feed", "facebook_reels"],
        "instagram_positions": ["stream", "reels", "explore"],
    }
    t.update(extra)
    return t


def create_adset(name, extra):
    data = {
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
    }
    res = api(f"act_{ACT}/adsets", data=data, method="POST")
    print(f"ADSET OK '{name}' -> {res.get('id')}")
    return res["id"]


def create_creative(media_id):
    if CREATIVE_ID_IN:
        print("Using provided CREATIVE_ID:", CREATIVE_ID_IN)
        return CREATIVE_ID_IN
    name = f"DC|06 engagement {SHORTCODE}"
    cta = json.dumps({"type": CTA_TYPE, "value": {"link": LINK_URL}})
    attempts = [
        {"name": name, "instagram_user_id": IG_USER, "source_instagram_media_id": media_id, "call_to_action": cta},
        {"name": name, "instagram_user_id": IG_USER, "source_instagram_media_id": media_id},
    ]
    last = None
    for a in attempts:
        try:
            res = api(f"act_{ACT}/adcreatives", data=a, method="POST")
            print("CREATIVE OK:", res.get("id"), "| fields:", list(a.keys()))
            return res["id"]
        except Exception as e:
            print("creative attempt failed:", e)
            last = e
    raise last


def create_ad(adset_id, creative_id, tag):
    res = api(f"act_{ACT}/ads", data={
        "name": f"engagement · {SHORTCODE} · {tag}",
        "adset_id": adset_id,
        "creative": json.dumps({"creative_id": creative_id}),
        "status": "PAUSED",
    }, method="POST")
    print(f"AD OK adset {adset_id} -> {res.get('id')}")
    return res.get("id")


def archive(adset_id):
    try:
        api(adset_id, data={"status": "ARCHIVED"}, method="POST")
        print("ARCHIVED old adset", adset_id)
    except Exception as e:
        print("archive failed", adset_id, e)


def main():
    print(f"== DC|06 engagement | mode={MODE} graph={GRAPH} dry={DRY} link={LINK_URL} ==")
    whoami()
    media_id = resolve_media()
    if DRY:
        print("DRY_RUN: stop.")
        return
    creative_id = create_creative(media_id)
    print("creative id:", creative_id)

    results = []
    if MODE == "rebuild":
        new_ids = []
        for name, extra in GROUPS:
            asid = create_adset(name, extra)
            new_ids.append(asid)
            adid = create_ad(asid, creative_id, name.split(" ")[0])
            results.append((name, asid, adid))
        for old in OLD_ADSETS:
            archive(old)
    else:
        for asid in ATTACH_ADSETS:
            try:
                adid = create_ad(asid, creative_id, asid[-5:])
                results.append((asid, adid))
            except Exception as e:
                print("AD FAIL", asid, e)
                results.append((asid, f"ERROR {e}"))

    print("SUMMARY:", json.dumps(results, ensure_ascii=False))
    if any((isinstance(r[-1], str) and str(r[-1]).startswith("ERROR")) or r[-1] is None for r in results):
        sys.exit(1)


if __name__ == "__main__":
    main()
