#!/usr/bin/env python3
"""Replicate source page-posts into ALL active SALES adsets as ACTIVE ads (Meta act 4136058269783354).
Creates ONE clean creative per source post (object_story_id, standard-enhancements OPT_OUT) and
attaches it as a new ad in every active sales adset (dedupe by post). Avoids /copies (subcode 3858504).
env: FB_ACCESS_TOKEN, AD_ACCOUNT_ID, DRY_RUN, STATUS_OPTION(ACTIVE/PAUSED), MAX_CREATES, SOURCES(name::postid||...).
"""
import os, json, time, urllib.parse, urllib.request, urllib.error
from collections import Counter

GRAPH = os.environ.get("GRAPH_VER", "v21.0")
TOKEN = os.environ["FB_ACCESS_TOKEN"]
ACT = os.environ.get("AD_ACCOUNT_ID", "4136058269783354")
DRY = os.environ.get("DRY_RUN", "true").lower() == "true"
STATUS = os.environ.get("STATUS_OPTION", "ACTIVE").upper()
MAXC = int(os.environ.get("MAX_CREATES", "90"))
BASE = f"https://graph.facebook.com/{GRAPH}"
DEFAULT_SOURCES = [
    ("меган перший пост х6м", "1676843282640684_3812050758938051"),
    ("100 при 50 пост х6м – копія", "1676843282640684_1037561262352778"),
    ("на своїй пост х6м", "1676843282640684_862903833255150"),
    ("камерамен пост х6м", "1676843282640684_1587690539640623"),
]
_env = os.environ.get("SOURCES", "").strip()
if _env:
    SOURCES = []
    for part in _env.split("||"):
        if "::" in part:
            nm, pid = part.split("::", 1); SOURCES.append((nm.strip(), pid.strip()))
else:
    SOURCES = DEFAULT_SOURCES
SALES_OBJ = {"OUTCOME_SALES", "CONVERSIONS", "PRODUCT_CATALOG_SALES"}
DOF = json.dumps({"creative_features_spec": {"standard_enhancements": {"enroll_status": "OPT_OUT"}}})


def get(path, params=None):
    p = dict(params or {}); p["access_token"] = TOKEN
    url = f"{BASE}/{path}?" + urllib.parse.urlencode(p)
    for a in range(4):
        try:
            with urllib.request.urlopen(urllib.request.Request(url), timeout=120) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            body = e.read().decode()[:200]; print(f"  GET {e.code}: {body}")
            if "limit" in body.lower() or e.code in (17, 4, 613):
                time.sleep(15); continue
            return {}
        except Exception as e:
            print("  GET ERR", str(e)[:150]); time.sleep(4)
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
    return out


def post(path, params):
    p = dict(params); p["access_token"] = TOKEN
    data = urllib.parse.urlencode(p).encode()
    for a in range(4):
        try:
            with urllib.request.urlopen(urllib.request.Request(f"{BASE}/{path}", data=data, method="POST"), timeout=120) as r:
                return json.loads(r.read().decode()), None
        except urllib.error.HTTPError as e:
            body = e.read().decode()[:400]
            if "limit" in body.lower() or e.code in (17, 4, 613):
                time.sleep(20); continue
            return None, f"HTTP {e.code}: {body}"
        except Exception as e:
            return None, str(e)[:200]
    return None, "retry_exhausted"


print(f"REPLICATE (object_story_id) · act {ACT} · dry_run={DRY} · status={STATUS}")
print("=== SOURCES ===")
for nm, pid in SOURCES:
    print(f"  {nm[:34]:34} post={pid}")

print("=== TARGET ADSETS (active sales) ===")
camps = get_all(f"act_{ACT}/campaigns", {"fields": "name,objective,effective_status",
                                         "filtering": json.dumps([{"field": "effective_status", "operator": "IN", "value": ["ACTIVE"]}])})
tcamps = [c for c in camps if c.get("objective") in SALES_OBJ]
skip_c = [f"{c.get('name','?')}({c.get('objective')})" for c in camps if c.get("objective") not in SALES_OBJ]
targets = []
for c in tcamps:
    for a in get_all(f"{c['id']}/adsets", {"fields": "name,effective_status",
                                           "filtering": json.dumps([{"field": "effective_status", "operator": "IN", "value": ["ACTIVE"]}])}):
        targets.append((a["id"], a.get("name", "?"), c.get("name", "?")))
print(f"  sales_active_campaigns={len(tcamps)} target_adsets={len(targets)} skipped_nonsales={skip_c}")
for (aid, an, cn) in targets:
    print(f"  {cn[:24]:24} | {an[:32]:32} | {aid}")
if not targets:
    print("NO TARGETS"); print("DONE_REPLICATE"); raise SystemExit(0)

present = {}
for (aid, _, _) in targets:
    ex = get_all(f"{aid}/ads", {"fields": "creative{effective_object_story_id}",
                                "filtering": json.dumps([{"field": "effective_status", "operator": "IN", "value": ["ACTIVE", "PENDING_REVIEW", "PAUSED", "IN_PROCESS"]}])})
    present[aid] = set(filter(None, [(e.get("creative") or {}).get("effective_object_story_id") for e in ex]))

plan = []
for (nm, pid) in SOURCES:
    for (aid, an, cn) in targets:
        if pid in present.get(aid, set()):
            print(f"  skip(dup) '{nm[:20]}' in {an[:26]}"); continue
        plan.append((nm, pid, aid, an, cn))
print(f"=== PLAN: {len(plan)} ads (dry_run={DRY}) ===")
for (nm, pid, aid, an, cn) in plan:
    print(f"  '{nm[:20]}' -> [{cn[:16]}] {an[:28]} ({aid})")
if len(plan) > MAXC:
    print(f"ABORT plan>{MAXC}"); print("DONE_REPLICATE"); raise SystemExit(0)
if DRY:
    print("DRY_RUN — no writes."); print("DONE_REPLICATE"); raise SystemExit(0)

print("=== CREATE CREATIVES (1 per source) ===")
cre = {}
for (nm, pid) in SOURCES:
    res, err = post(f"act_{ACT}/adcreatives", {"name": f"[repl] {nm}", "object_story_id": pid, "degrees_of_freedom_spec": DOF})
    if err:
        print(f"  CRE FAIL '{nm[:24]}': {err}")
    else:
        cre[pid] = res.get("id"); print(f"  CRE OK {res.get('id')} <- '{nm[:24]}'")
    time.sleep(1)

print("=== CREATE ADS ===")
created = []
for (nm, pid, aid, an, cn) in plan:
    cid = cre.get(pid)
    if not cid:
        print(f"  SKIP no-creative '{nm[:18]}' {an[:22]}"); continue
    res, err = post(f"act_{ACT}/ads", {"name": nm, "adset_id": aid, "creative": json.dumps({"creative_id": cid}), "status": STATUS})
    if err:
        print(f"  AD FAIL '{nm[:16]}'->{an[:22]}: {err}")
    else:
        created.append(res.get("id")); print(f"  AD OK {res.get('id')} '{nm[:16]}'->{an[:22]}")
    time.sleep(1)
print(f"created={len(created)}/{len(plan)}")

print("=== VERIFY ===")
cnt = Counter()
for nid in created:
    if not nid:
        continue
    r = get(f"{nid}", {"fields": "effective_status"}); cnt[r.get("effective_status", "?")] += 1; time.sleep(0.3)
print("  status:", dict(cnt))
print("  new_ad_ids:", ",".join([c for c in created if c]))
print("DONE_REPLICATE")
