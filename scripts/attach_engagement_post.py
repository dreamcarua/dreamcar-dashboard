#!/usr/bin/env python3
"""
Прикріплює існуючий IG-пост як оголошення у задані ад-сети (PAUSED).
Використовує системний токен FB_ACCESS_TOKEN (scope: ads_management, instagram_basic, pages).
ДЛЯ engagement-кампанії DC|06: один і той самий пост у всі групи -> коменти копляться в одному треді.
"""
import os, json, urllib.parse, urllib.request, urllib.error, sys

GRAPH = os.environ.get("GRAPH_VER", "v21.0")
TOKEN = os.environ["FB_ACCESS_TOKEN"]
ACT = os.environ.get("AD_ACCOUNT_ID", "4136058269783354")
IG_USER = os.environ.get("IG_USER_ID", "17841403783002317")
PAGE_ID = os.environ.get("PAGE_ID", "1676843282640684")
SHORTCODE = os.environ.get("SHORTCODE", "DaLlUIlMWD9").strip()
IG_MEDIA_ID = os.environ.get("IG_MEDIA_ID", "").strip()
ADSETS = [x.strip() for x in os.environ.get("ADSET_IDS", "").split(",") if x.strip()]
CREATIVE_ID_IN = os.environ.get("CREATIVE_ID", "").strip()
DRY = os.environ.get("DRY_RUN", "true").lower() == "true"
BASE = f"https://graph.facebook.com/{GRAPH}"


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


def resolve_media():
    if IG_MEDIA_ID:
        print("Using provided IG_MEDIA_ID:", IG_MEDIA_ID)
        return IG_MEDIA_ID
    after = None
    scanned = 0
    while True:
        params = {"fields": "id,permalink,media_type,caption,timestamp", "limit": "50"}
        if after:
            params["after"] = after
        res = api(f"{IG_USER}/media", params=params)
        for m in res.get("data", []):
            scanned += 1
            if SHORTCODE in (m.get("permalink") or ""):
                print("MATCH:", json.dumps(m, ensure_ascii=False))
                return m["id"]
        after = res.get("paging", {}).get("cursors", {}).get("after")
        if not after:
            break
    raise RuntimeError(f"Shortcode {SHORTCODE} not found after scanning {scanned} media")


def create_creative(media_id):
    if CREATIVE_ID_IN:
        print("Using provided CREATIVE_ID:", CREATIVE_ID_IN)
        return CREATIVE_ID_IN
    name = f"DC|06 engagement {SHORTCODE}"
    attempts = [
        {"name": name, "object_story_spec": json.dumps({"page_id": PAGE_ID, "instagram_user_id": IG_USER}), "source_instagram_media_id": media_id},
        {"name": name, "instagram_user_id": IG_USER, "source_instagram_media_id": media_id},
        {"name": name, "object_story_spec": json.dumps({"page_id": PAGE_ID, "instagram_actor_id": IG_USER}), "source_instagram_media_id": media_id},
    ]
    last = None
    for a in attempts:
        try:
            res = api(f"act_{ACT}/adcreatives", data=a, method="POST")
            print("Creative OK:", res, "| fields:", list(a.keys()))
            return res["id"]
        except Exception as e:
            print("creative attempt failed:", e)
            last = e
    raise last


def main():
    print(f"== attach_engagement_post | graph={GRAPH} act={ACT} dry={DRY} ==")
    print("ad sets:", ADSETS)
    media_id = resolve_media()
    print("IG media id:", media_id)
    if DRY:
        print("DRY_RUN: stop before creating creative/ads.")
        return
    creative_id = create_creative(media_id)
    print("creative id:", creative_id)
    results = []
    for asid in ADSETS:
        try:
            res = api(f"act_{ACT}/ads", data={
                "name": f"engagement · {SHORTCODE} · {asid[-5:]}",
                "adset_id": asid,
                "creative": json.dumps({"creative_id": creative_id}),
                "status": "PAUSED",
            }, method="POST")
            print(f"AD OK adset {asid} -> {res}")
            results.append((asid, res.get("id")))
        except Exception as e:
            print(f"AD FAIL adset {asid}: {e}")
            results.append((asid, f"ERROR {e}"))
    print("SUMMARY:", json.dumps(results, ensure_ascii=False))
    if any(isinstance(r[1], str) and r[1].startswith("ERROR") for r in results):
        sys.exit(1)


if __name__ == "__main__":
    main()
