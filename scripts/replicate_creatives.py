#!/usr/bin/env python3
"""Replicate source page-posts into ALL active SALES adsets as ACTIVE ads (Meta act 4136058269783354).
Sources resolved by NAME (env SOURCE_NAMES, ||-sep) via Graph name-CONTAIN filter, else DEFAULT_SOURCES.
Builds ONE clean creative per source (object_story_id); if that is rejected (e.g. dynamic asset_feed_spec,
subcode 1815017/3858504), FALLS BACK to reusing the source ad's own creative_id. Then attaches as a new
ad in every active sales adset (dedupe by post). Avoids naive /copies pitfalls.
env: FB_ACCESS_TOKEN, AD_ACCOUNT_ID, DRY_RUN, STATUS_OPTION, MAX_CREATES, SOURCE_NAMES(name||name...).
"""
import os, json, time, urllib.parse, urllib.request, urllib.error
from collections import Counter

GRAPH = os.environ.get("GRAPH_VER", "v21.0")
TOKEN = os.environ["FB_ACCESS_TOKEN"]
ACT = os.environ.get("AD_ACCOUNT_ID", "4136058269783354")
DRY = os.environ.get("DRY_RUN", "true").lower() == "true"
STATUS = os.environ.get("STATUS_OPTION", "ACTIVE").upper()
MAXC = int(os.environ.get("MAX_CREATES", "120"))
BASE = f"https://graph.facebook.com/{GRAPH}"
DEFAULT_SOURCES = [
    ("меган перший пост х6м", "1676843282640684_3812050758938051", "120251106548460624"),
    ("100 при 50 пост х6м – копія", "1676843282640684_1037561262352778", "120251106548450624"),
    ("на своїй пост х6м", "1676843282640684_862903833255150", "120251106548440624"),
    ("камерамен пост х6м", "1676843282640684_1587690539640623", "120251106548430624"),
]
SALES_OBJ = {"OUTCOME_SALES", "CONVERSIONS", "PRODUCT_CATALOG_SALES"}


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


def resolve_by_name(names):
    out = []
    for nm in names:
        res = get(f"act_{ACT}/ads", {"fields": "id,name,created_time,creative{id,effective_object_story_id}",
                                     "filtering": json.dumps([{"field": "name", "operator": "CONTAIN", "value": nm}]),
                                     "limit": 100})
        cands = res.get("data", [])
        exact = [c for c in cands if norm(c.get("name")) == norm(nm)]
        pool = exact or cands
        pool.sort(key=lambda x: x.get("created_time", ""), reverse=True)
        if pool:
            c = pool[0]; cr = c.get("creative") or {}
            pid = cr.get("effective_object_story_id") or ("CID:" + cr.get("id")) if cr.get("id") else None
            if pid:
                out.append((nm, pid, c["id"])); print(f"  RESOLVE OK '{nm[:26]}' -> ad {c['id']} key={pid} (cands={len(cands)})")
            else:
                print(f"  RESOLVE no-key '{nm[:26]}' (ad {c.get('id')})")
        else:
            print(f"  RESOLVE MISS '{nm[:26]}'")
    return out


names_env = os.environ.get("SOURCE_NAMES", "").strip()
print(f"REPLICATE v5 · act {ACT} · dry_run={DRY} · status={STATUS}")
if names_env:
    NAMES = [n for n in names_env.split("||") if n.strip()]
    print("=== RESOLVE SOURCES BY NAME ===")
    SOURCES = resolve_by_name(NAMES)
else:
    SOURCES = DEFAULT_SOURCES
print("=== SOURCES ===")
for nm, pid, adid in SOURCES:
    print(f"  {nm[:30]:30} key={pid} ad={adid}")
if not SOURCES:
    print("NO SOURCES RESOLVED — abort"); print("DONE_REPLICATE"); raise SystemExit(0)

print("=== TARGET ADSETS (active sales) ===")
camps = get_all(f"act_{ACT}/campaigns", {"fields": "name,objective,effective_status",
                                         "filtering": json.dumps([{"field": "effective_status", "operator": "IN", "value": ["ACTIVE"]}])})
tcamps = [c for c in camps if c.get("objective") in SALES_OBJ]
targets = []
for c in tcamps:
    for a in get_all(f"{c['id']}/adsets", {"fields": "name,effective_status",
                                           "filtering": json.dumps([{"field": "effective_status", "operator": "IN", "value": ["ACTIVE"]}])}):
        targets.append((a["id"], a.get("name", "?"), c.get("name", "?")))
print(f"  sales_active_campaigns={len(tcamps)} target_adsets={len(targets)}")
if not targets:
    print("NO TARGETS"); print("DONE_REPLICATE"); raise SystemExit(0)

present = {}
for (aid, _, _) in targets:
    ex = get_all(f"{aid}/ads", {"fields": "creative{effective_object_story_id}",
                                "filtering": json.dumps([{"field": "effective_status", "operator": "IN", "value": ["ACTIVE", "PENDING_REVIEW", "PAUSED", "IN_PROCESS"]}])})
    present[aid] = set(filter(None, [(e.get("creative") or {}).get("effective_object_story_id") for e in ex]))

plan = []
for (nm, pid, adid) in SOURCES:
    dedupe_key = pid if not pid.startswith("CID:") else None
    for (aid, an, cn) in targets:
        if dedupe_key and dedupe_key in present.get(aid, set()):
            continue
        plan.append((nm, pid, adid, aid, an, cn))
print(f"=== PLAN: {len(plan)} ads (dry_run={DRY}) ===")
for (nm, pid, adid, aid, an, cn) in plan:
    print(f"  '{nm[:20]}' -> [{cn[:16]}] {an[:28]} ({aid})")
if len(plan) > MAXC:
    print(f"ABORT plan>{MAXC}"); print("DONE_REPLICATE"); raise SystemExit(0)
if DRY:
    print("DRY_RUN — no writes."); print("DONE_REPLICATE"); raise SystemExit(0)

print("=== BUILD CREATIVES ===")
cre = {}
for (nm, pid, adid) in SOURCES:
    if pid.startswith("CID:"):
        cre[pid] = pid[4:]; print(f"  CRE reuse-source {cre[pid]} <- '{nm[:22]}' (no post)")
        continue
    res, err = post(f"act_{ACT}/adcreatives", {"name": f"[repl] {nm}"[:90], "object_story_id": pid})
    if not err and res.get("id"):
        cre[pid] = res.get("id"); print(f"  CRE OK(plain) {res.get('id')} <- '{nm[:22]}'")
    else:
        print(f"  CRE plain-fail '{nm[:22]}': {err}")
        scid = (get(f"{adid}", {"fields": "creative{id}"}).get("creative") or {}).get("id")
        if scid:
            cre[pid] = scid; print(f"  CRE FALLBACK reuse source creative {scid} <- '{nm[:22]}'")
        else:
            print(f"  CRE NONE for '{nm[:22]}'")
    time.sleep(1)

print("=== CREATE ADS ===")
created = []
fails = Counter()
for (nm, pid, adid, aid, an, cn) in plan:
    cid = cre.get(pid)
    if not cid:
        continue
    res, err = post(f"act_{ACT}/ads", {"name": nm, "adset_id": aid, "creative": json.dumps({"creative_id": cid}), "status": STATUS})
    if err:
        fails[err[:50]] += 1; print(f"  AD FAIL '{nm[:14]}'->{an[:20]}: {err}")
    else:
        created.append(res.get("id")); print(f"  AD OK {res.get('id')} '{nm[:14]}'->{an[:20]}")
    time.sleep(1)
print(f"created={len(created)}/{len(plan)} fails={dict(fails)}")

print("=== VERIFY ===")
cnt = Counter()
for nid in created:
    if not nid:
        continue
    r = get(f"{nid}", {"fields": "effective_status"}); cnt[r.get("effective_status", "?")] += 1; time.sleep(0.3)
print("  status:", dict(cnt))
print("  new_ad_ids:", ",".join([c for c in created if c]))
print("DONE_REPLICATE")
