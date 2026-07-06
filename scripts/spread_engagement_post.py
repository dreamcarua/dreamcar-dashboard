#!/usr/bin/env python3
"""
DC|06 Engagement: розкидати існуючий пост-ad по адсетах кампанії.
Бере creative_id з еталонного ада (AD_ID) і створює ad у кожному ADSET_IDS
з тим самим креативом -> коменти копляться в одному треді.

env:
  FB_ACCESS_TOKEN  - обов'язково (secret)
  AD_ID            - еталонний ad, з якого беремо creative
  ADSET_IDS        - csv адсетів, куди розкидати
  ACTIVATE         - true|false (default true) - створювати одразу ACTIVE
  AD_ACCOUNT_ID    - default 4136058269783354
  PAGE_ID          - default 1676843282640684 (для ensure promoted_object)

Граблі (з attach_engagement_post.py):
- POST_ENGAGEMENT адсет без promoted_object не приймає ads (subcode 1885154) -> ensure+fix;
- reuse creative_id, НЕ створювати новий креатив (CTA вже вшитий).
Ідемпотентність: якщо в адсеті вже є ad з цим creative_id - скіп.
"""
import os, json, urllib.parse, urllib.request, urllib.error, sys

GRAPH = os.environ.get("GRAPH_VER", "v21.0")
TOKEN = os.environ["FB_ACCESS_TOKEN"]
ACT = os.environ.get("AD_ACCOUNT_ID", "4136058269783354")
PAGE_ID = os.environ.get("PAGE_ID", "1676843282640684")
AD_ID = os.environ["AD_ID"].strip()
ADSET_IDS = [x.strip() for x in os.environ.get("ADSET_IDS", "").split(",") if x.strip()]
ACTIVATE = os.environ.get("ACTIVATE", "true").lower() == "true"
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


def ensure_promoted_object(adset_id):
    info = api(adset_id, params={"fields": "id,name,promoted_object,optimization_goal,effective_status"})
    if not info.get("promoted_object"):
        print(f"  fix promoted_object -> {adset_id}")
        api(adset_id, data={"promoted_object": json.dumps({"page_id": PAGE_ID})}, method="POST")
    return info.get("name", adset_id)


def adset_has_creative(adset_id, creative_id):
    try:
        res = api(f"{adset_id}/ads", params={"fields": "id,name,creative{id}", "limit": "50"})
        for ad in res.get("data", []):
            if (ad.get("creative") or {}).get("id") == creative_id:
                return ad["id"]
    except Exception as e:
        print(f"  warn: ads check failed {adset_id}: {e}")
    return None


def main():
    et = api(AD_ID, params={"fields": "id,name,adset_id,creative{id,name}"})
    creative_id = et["creative"]["id"]
    ad_name = et.get("name", "post")
    print(f"ETALON: ad={AD_ID} '{ad_name}' adset={et.get('adset_id')} creative={creative_id}")
    status = "ACTIVE" if ACTIVATE else "PAUSED"
    results = []
    for i, asid in enumerate(ADSET_IDS, 1):
        try:
            as_name = ensure_promoted_object(asid)
            dup = adset_has_creative(asid, creative_id)
            if dup:
                print(f"SKIP {asid} '{as_name}' - вже є ad {dup} з цим креативом")
                results.append({"adset": asid, "ad": dup, "skipped": True})
                continue
            res = api(f"act_{ACT}/ads", data={
                "name": f"{ad_name} · {as_name.split(' ')[0]}",
                "adset_id": asid,
                "creative": json.dumps({"creative_id": creative_id}),
                "status": status,
            }, method="POST")
            print(f"AD OK {asid} '{as_name}' -> {res.get('id')} [{status}]")
            results.append({"adset": asid, "ad": res.get("id"), "status": status})
        except Exception as e:
            print(f"AD FAIL {asid}: {e}")
            results.append({"adset": asid, "error": str(e)})
    print("SUMMARY:", json.dumps(results, ensure_ascii=False))
    if any("error" in r for r in results):
        sys.exit(1)


if __name__ == "__main__":
    main()
