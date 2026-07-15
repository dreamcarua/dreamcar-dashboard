#!/usr/bin/env python3
"""Replicate source ads into ALL active SALES adsets and activate (Meta act 4136058269783354).
READ+WRITE via Ad Copy API POST /{ad_id}/copies. Dedupes by post (effective_object_story_id).
env: FB_ACCESS_TOKEN, AD_ACCOUNT_ID, DRY_RUN(true/false), STATUS_OPTION(ACTIVE/PAUSED),
     SOURCE_NAMES(||-sep, optional override), MAX_CREATES(default 90).
"""
import os, json, time, urllib.parse, urllib.request, urllib.error
from collections import Counter

GRAPH = os.environ.get("GRAPH_VER", "v21.0")
TOKEN = os.environ["FB_ACCESS_TOKEN"]
ACT = os.environ.get("AD_ACCOUNT_ID", "4136058269783354")
DRY = os.environ.get("DRY_RUN", "true").lower() == "true"
STATUS_OPTION = os.environ.get("STATUS_OPTION", "ACTIVE")
MAXC = int(os.environ.get("MAX_CREATES", "90"))
BASE = f"https://graph.facebook.com/{GRAPH}"
DEFAULT_NAMES = ["меган перший пост х6м", "100 при 50 пост х6м – копія", "на своїй пост х6м", "камерамен пост х6м"]
SRC_NAMES = [s for s in os.environ.get("SOURCE_NAMES", "||".join(DEFAULT_NAMES)).split("||") if s.strip()]
SALES_OBJ = {"OUTCOME_SALES", "CONVERSIONS", "PRODUCT_CATALOG_SALES"}
ST_KEEP = ["ACTIVE", "PAUSED", "PENDING_REVIEW", "CAMPAIGN_PAUSED", "ADSET_PAUSED", "IN_PROCESS", "WITH_ISSUES"]


def norm(s):
    return " ".join((s or "").replace("–", "-").replace("—", "-").split()).lower()


def get(path, params=None):
    p = dict(params or {}); p["access_token"] = TOKEN
    url = f"{BASE}/{path}?" + urllib.parse.urlencode(p)
    for a in range(4):
        try:
            with urllib.request.urlopen(urllib.request.Request(url), timeout=120) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            body = e.read().decode()[:300]; print(f"  GET {e.code}: {body}")
            if e.code == 400 and "limit" not in body.lower():
                return {}
            time.sleep(10)
        except Exception as e:
            print("  GET ERR", str(e)[:200]); time.sleep(5)
    return {}


def get_all(path, params=None):
    p = dict(params or {}); p.setdefault("limit", 200)
    out = []; res = get(path, p)
    while res:
        out += res.get("data", [])
        nxt = ((res.get("paging") or {}).get("cursors") or {}).get("after")
        if not nxt or not res.get("data"):
            break
        p2 = dict(p); p2["after"] = nxt; res = get(path, p2)
        if len(out) > 8000:
            break
    return out


def post(path, params):
    p = dict(params); p["access_token"] = TOKEN
    data = urllib.parse.urlencode(p).encode()
    for a in range(3):
        try:
            req = urllib.request.Request(f"{BASE}/{path}", data=data, method="POST")
            with urllib.request.urlopen(req, timeout=120) as r:
                return json.loads(r.read().decode()), None
        except urllib.error.HTTPError as e:
            body = e.read().decode()[:400]
            if "limit" in body.lower() or e.code in (17, 4, 613):
                time.sleep(20); continue
            return None, f"HTTP {e.code}: {body}"
        except Exception as e:
            return None, str(e)[:200]
    return None, "retry_exhausted"


print(f"REPLICATE CREATIVES · act {ACT} · dry_run={DRY} · status_option={STATUS_OPTION}")

# 1) Resolve source ads by name
print("=== RESOLVE SOURCES ===")
ads = get_all(f"act_{ACT}/ads", {"fields": "name,adset_id,effective_status,creative{id,effective_object_story_id},created_time",
                                 "filtering": json.dumps([{"field": "effective_status", "operator": "IN", "value": ST_KEEP}])})
by_norm = {}
for a in ads:
    by_norm.setdefault(norm(a.get("name")), []).append(a)
sources = []
for nm in SRC_NAMES:
    cands = list(by_norm.get(norm(nm), []))
    cands.sort(key=lambda x: x.get("created_time", ""), reverse=True)
    cands.sort(key=lambda x: x.get("effective_status") != "ACTIVE")
    if cands:
        s = cands[0]; sources.append(s)
        post_id = (s.get("creative") or {}).get("effective_object_story_id")
        print(f"  OK  '{nm}' -> ad {s['id']} [{s.get('effective_status')}] post={post_id} (cands={len(cands)})")
    else:
        print(f"  MISS '{nm}' -> NOT FOUND")
if not sources:
    print("NO SOURCES FOUND — abort"); print("DONE_REPLICATE"); raise SystemExit(0)

# 2) Targets = active adsets of active SALES campaigns (excludes engagement)
print("=== TARGET ADSETS (active sales) ===")
camps = get_all(f"act_{ACT}/campaigns", {"fields": "name,objective,effective_status",
                                         "filtering": json.dumps([{"field": "effective_status", "operator": "IN", "value": ["ACTIVE"]}])})
tcamps = [c for c in camps if c.get("objective") in SALES_OBJ]
skipped = [f"{c.get('name','?')}({c.get('objective')})" for c in camps if c.get("objective") not in SALES_OBJ]
targets = []
for c in tcamps:
    asets = get_all(f"{c['id']}/adsets", {"fields": "name,effective_status",
                                          "filtering": json.dumps([{"field": "effective_status", "operator": "IN", "value": ["ACTIVE"]}])})
    for a in asets:
        targets.append((a["id"], a.get("name", "?"), c.get("name", "?")))
        print(f"  {c.get('name','?')[:26]:26} | {a.get('name','?')[:34]:34} | adset {a['id']}")
print(f"  sales_active_campaigns={len(tcamps)}  target_adsets={len(targets)}  skipped_nonsales={skipped}")
if not targets:
    print("NO TARGETS — abort"); print("DONE_REPLICATE"); raise SystemExit(0)

# 3) Dedupe: existing posts per adset
present = {}
for (aid, _, _) in targets:
    ex = get_all(f"{aid}/ads", {"fields": "creative{effective_object_story_id}",
                                "filtering": json.dumps([{"field": "effective_status", "operator": "IN", "value": ["ACTIVE", "PENDING_REVIEW", "PAUSED", "IN_PROCESS"]}])})
    present[aid] = set(filter(None, [(e.get("creative") or {}).get("effective_object_story_id") for e in ex]))

# 4) Plan
plan = []
for s in sources:
    spost = (s.get("creative") or {}).get("effective_object_story_id")
    for (aid, an, cn) in targets:
        if spost and spost in present.get(aid, set()):
            print(f"  skip(dup) '{(s.get('name') or '')[:22]}' already in {an[:28]}")
            continue
        plan.append((s["id"], s.get("name"), aid, an, cn))
print(f"=== PLAN: {len(plan)} copies (dry_run={DRY}) ===")
for (sid, sn, aid, an, cn) in plan:
    print(f"  copy '{(sn or '')[:24]}' -> [{cn[:18]}] {an[:30]} ({aid})")
if len(plan) > MAXC:
    print(f"ABORT: plan {len(plan)} > MAX_CREATES {MAXC} (safety cap)"); print("DONE_REPLICATE"); raise SystemExit(0)
if DRY:
    print("DRY_RUN — no writes performed."); print("DONE_REPLICATE"); raise SystemExit(0)

# 5) Execute
print("=== EXECUTE COPIES ===")
created = []
for (sid, sn, aid, an, cn) in plan:
    res, err = post(f"{sid}/copies", {"adset_id": aid, "status_option": STATUS_OPTION})
    if err:
        print(f"  FAIL '{(sn or '')[:20]}' -> {an[:26]}: {err}")
    else:
        nid = res.get("copied_ad_id") or res.get("ad_id") or res.get("id")
        created.append(nid); print(f"  OK   {nid} '{(sn or '')[:20]}' -> {an[:26]}")
    time.sleep(1)
print(f"created={len(created)} / planned={len(plan)}")

# 6) Verify effective_status
print("=== VERIFY ===")
cnt = Counter()
for nid in created:
    if not nid:
        continue
    r = get(f"{nid}", {"fields": "effective_status,name"})
    cnt[r.get("effective_status", "?")] += 1
    time.sleep(0.3)
print("  status_breakdown:", dict(cnt))
print("  new_ad_ids:", ",".join([c for c in created if c]))
print("DONE_REPLICATE")
