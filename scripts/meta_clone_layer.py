#!/usr/bin/env python3
"""Clone one ad layer (CBO campaign + its ad set) into a NEW campaign with another optimization goal.

Why: Meta does not allow changing optimization_goal of a published ad set (subcode 3260011), and a CBO
campaign must keep one goal for all its ad sets (subcode 1885760). So a goal change = new campaign.

Copies: campaign objective, daily budget, bid strategy, special categories; ad set targeting, promoted object,
destination, incremental attribution; ads = creative_id reuse (UTM live in the creative, so they carry over).
Ads come from CREATIVES_FROM_ADSET (default: the source ad set), filtered by AD_NAME_CONTAINS and status ACTIVE
unless INCLUDE_PAUSED=true. LABEL_FROM -> LABEL_TO rewrites the layer label inside ad names.

env: FB_ACCESS_TOKEN, AD_ACCOUNT_ID, SOURCE_CAMPAIGN_ID, NEW_GOAL (VALUE), NEW_CAMPAIGN_NAME, NEW_ADSET_NAME,
     CREATIVES_FROM_ADSET, AD_NAME_CONTAINS, INCLUDE_PAUSED, LABEL_FROM, LABEL_TO,
     ACTIVATE (true: new campaign ACTIVE), PAUSE_SOURCE (true: source campaign PAUSED after the new one is live),
     DRY_RUN (true: plan only). Prints a JSON summary on the last line.
"""
import json, os, sys, time, urllib.error, urllib.parse, urllib.request

GRAPH = os.environ.get("GRAPH_VER", "v21.0")
TOKEN = os.environ["FB_ACCESS_TOKEN"]
ACT = "act_" + os.environ.get("AD_ACCOUNT_ID", "4136058269783354").replace("act_", "")
BASE = f"https://graph.facebook.com/{GRAPH}"
E = os.environ.get
DRY = E("DRY_RUN", "true").lower() == "true"


def call(method, path, params=None):
    p = dict(params or {})
    p["access_token"] = TOKEN
    data = None
    url = f"{BASE}/{path}"
    if method == "GET":
        url += "?" + urllib.parse.urlencode(p)
    else:
        data = urllib.parse.urlencode(p).encode()
    for attempt in range(4):
        try:
            req = urllib.request.Request(url, data=data, method=method)
            with urllib.request.urlopen(req, timeout=120) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            body = e.read().decode()[:600]
            if any(k in body for k in ('"code":17', '"code":4,', '"code":613', '"is_transient":true')) and attempt < 3:
                time.sleep(20)
                continue
            raise SystemExit(f"{method} {path} -> HTTP {e.code}: {body}")
    raise SystemExit(f"{method} {path} failed after retries")


def clean_targeting(t):
    out = {}
    for k, v in (t or {}).items():
        if k.startswith("effective_") or k in ("page_types",):
            continue
        if k in ("custom_audiences", "excluded_custom_audiences"):
            v = [{"id": str(a["id"])} for a in v]
        out[k] = v
    geo = out.get("geo_locations") or {}
    lt = set(geo.get("location_types") or [])
    geo["location_types"] = sorted(lt | {"home", "recent"})  # rule: without it publishing fails (#1870194)
    ig = out.get("instagram_positions")
    if ig and "explore_home" in ig and "explore" not in ig:
        out["instagram_positions"] = list(ig) + ["explore"]  # Meta 2490392: explore_home needs explore
    out["geo_locations"] = geo
    return out


def main():
    src_c = E("SOURCE_CAMPAIGN_ID")
    goal = E("NEW_GOAL", "VALUE")
    camp = call("GET", src_c, {"fields": "name,objective,daily_budget,bid_strategy,special_ad_categories,buying_type,status"})
    sets = call("GET", f"{src_c}/adsets", {"fields": "id,name,targeting,promoted_object,destination_type,billing_event,optimization_goal,is_incremental_attribution_enabled,attribution_spec,status", "limit": 50})["data"]
    live_sets = [s for s in sets if s.get("status") != "DELETED"]
    if len(live_sets) != 1:
        raise SystemExit(f"expected one ad set in {src_c}, found {len(live_sets)}")
    s = live_sets[0]
    if s.get("optimization_goal") == goal:
        raise SystemExit(f"source ad set already optimizes for {goal}")
    from_set = E("CREATIVES_FROM_ADSET") or s["id"]
    flt = (E("AD_NAME_CONTAINS") or "").strip()
    inc_paused = E("INCLUDE_PAUSED", "false").lower() == "true"
    ads = call("GET", f"{from_set}/ads", {"fields": "id,name,status,effective_status,creative{id}", "limit": 200})["data"]
    picked = [a for a in ads if (flt in a["name"]) and (inc_paused or a.get("status") == "ACTIVE")]
    lf, lt = E("LABEL_FROM") or "", E("LABEL_TO") or ""
    plan_ads = [{"name": a["name"].replace(lf, lt) if lf else a["name"], "creative_id": a["creative"]["id"], "source_ad": a["id"]} for a in picked]
    new_c_name = E("NEW_CAMPAIGN_NAME") or (camp["name"] + " · " + goal)
    new_s_name = E("NEW_ADSET_NAME") or (s["name"] + " · " + goal)
    summary = {"dry_run": DRY, "source_campaign": src_c, "source_adset": s["id"], "source_goal": s.get("optimization_goal"),
               "new_campaign_name": new_c_name, "new_adset_name": new_s_name, "goal": goal,
               "daily_budget": camp.get("daily_budget"), "ads": plan_ads}
    if not plan_ads:
        raise SystemExit("no ads picked: check AD_NAME_CONTAINS / CREATIVES_FROM_ADSET")
    if DRY:
        print(json.dumps(summary, ensure_ascii=False))
        return

    cp = {"name": new_c_name, "objective": camp["objective"], "status": "PAUSED", "buying_type": camp.get("buying_type", "AUCTION"),
          "special_ad_categories": json.dumps(camp.get("special_ad_categories") or [])}
    if camp.get("daily_budget"):
        cp["daily_budget"] = camp["daily_budget"]
    if camp.get("bid_strategy"):
        cp["bid_strategy"] = camp["bid_strategy"]
    reuse = (E("REUSE_CAMPAIGN_ID") or "").strip()  # a campaign this script made in a failed run, still empty
    if not reuse:  # same name, no ad sets = left by a failed run of this script: reuse instead of a second orphan
        same = call("GET", f"{ACT}/campaigns", {"fields": "id,name", "limit": 50,
                    "filtering": json.dumps([{"field": "name", "operator": "EQUAL", "value": new_c_name}])}).get("data", [])
        for c in same:
            if not call("GET", f"{c['id']}/adsets", {"fields": "id", "limit": 5})["data"]:
                reuse = c["id"]
                summary["reused_empty_campaign"] = reuse
                break
    if reuse:
        if call("GET", f"{reuse}/adsets", {"fields": "id", "limit": 5})["data"]:
            raise SystemExit(f"REUSE_CAMPAIGN_ID {reuse} is not empty")
        new_c = reuse
    else:
        new_c = call("POST", f"{ACT}/campaigns", cp)["id"]
    summary["new_campaign"] = new_c
    sp = {"name": new_s_name, "campaign_id": new_c, "optimization_goal": goal, "billing_event": s.get("billing_event", "IMPRESSIONS"),
          "promoted_object": json.dumps(s["promoted_object"]), "targeting": json.dumps(clean_targeting(s["targeting"])),
          "status": "ACTIVE"}
    if s.get("destination_type"):
        sp["destination_type"] = s["destination_type"]
    if s.get("is_incremental_attribution_enabled"):
        sp["is_incremental_attribution_enabled"] = "true"
    elif s.get("attribution_spec"):
        sp["attribution_spec"] = json.dumps(s["attribution_spec"])
    new_s = call("POST", f"{ACT}/adsets", sp)["id"]
    summary["new_adset"] = new_s
    made = []
    for a in plan_ads:
        r = call("POST", f"{ACT}/ads", {"name": a["name"], "adset_id": new_s, "creative": json.dumps({"creative_id": a["creative_id"]}), "status": "ACTIVE"})
        made.append({"id": r["id"], "name": a["name"], "creative_id": a["creative_id"]})
        time.sleep(1)
    summary["new_ads"] = made
    if E("ACTIVATE", "false").lower() == "true":
        call("POST", new_c, {"status": "ACTIVE"})
        time.sleep(5)
        st = call("GET", new_c, {"fields": "status,effective_status"})
        summary["new_campaign_state"] = st
        if E("PAUSE_SOURCE", "false").lower() == "true" and st.get("status") == "ACTIVE":
            call("POST", src_c, {"status": "PAUSED"})
            summary["source_paused"] = call("GET", src_c, {"fields": "status,effective_status"})
    summary["new_adset_state"] = call("GET", new_s, {"fields": "status,effective_status,optimization_goal"})
    print(json.dumps(summary, ensure_ascii=False))


if __name__ == "__main__":
    main()
