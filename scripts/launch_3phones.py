#!/usr/bin/env python3
"""Launch '3 phones' (3 телефона) project on Meta act 4136058269783354.
Cleans 4 target adsets to ONLY the 2 new ads, replicates the 2 ads into them,
sets budgets (~35k/day total), pauses sibling adsets, activates 5 campaigns.
Hardcoded IDs -> no account-wide changes. DRY_RUN=true = plan only.
env: FB_ACCESS_TOKEN, DRY_RUN, STATUS_OPTION(ACTIVE).
"""
import os, json, time, urllib.parse, urllib.request, urllib.error
from collections import Counter

GRAPH = os.environ.get("GRAPH_VER", "v21.0")
TOKEN = os.environ["FB_ACCESS_TOKEN"]
ACT = os.environ.get("AD_ACCOUNT_ID", "4136058269783354")
DRY = os.environ.get("DRY_RUN", "true").lower() == "true"
STATUS = os.environ.get("STATUS_OPTION", "ACTIVE").upper()
BASE = f"https://graph.facebook.com/{GRAPH}"

# 2 new source ads (currently in DC|01 Ядро) -> reuse their creative_id
SOURCES = [
    ("перший пост 3 телефона", "1788281335956822"),
    ("другий пост 3 телефона", "1016292777893952"),
]
# target adsets to run the 2 ads (cleaned first). 1 delivery adset per campaign.
TARGET_ADSETS = [
    ("120249883647100624", "DC|02 Retarget"),
    ("120250907722260624", "DC|02b Baza upsell"),
    ("120249883739070624", "DC|03 Cold"),
    ("120250383367390624", "DC|05 FB Stories"),
]
# sibling adsets to pause so only the chosen adset delivers
SIBLING_ADSETS = [
    "120250384146330624", "120250384145220624", "120250384142630624", "120250907727750624",  # DC|03
    "120249980894940624", "120249980891620624", "120249980888590624",                          # DC|05
]
# CBO campaign budgets (kopiyky) — total ~35k/day
CAMP_BUDGET = {
    "120249698602830624": 2400000,  # DC|01 Ядро 24k
    "120249698608790624": 500000,   # DC|03 Prospecting 5k
    "120250907716920624": 300000,   # DC|02b База 3k
    "120249698605960624": 200000,   # DC|02 Retargeting 2k
}
ADSET_BUDGET = {"120250383367390624": 100000}  # DC|05 ABO: FB Stories 1k
ACTIVATE_CAMPAIGNS = [
    "120249698602830624", "120249698605960624", "120250907716920624",
    "120249698608790624", "120249980882600624",
]
VERIFY_ADSETS = TARGET_ADSETS + [("120249708049950624", "DC|01 Broad A+A")]


def get(path, params=None):
    p = dict(params or {}); p["access_token"] = TOKEN
    u = f"{BASE}/{path}?" + urllib.parse.urlencode(p)
    for _ in range(4):
        try:
            with urllib.request.urlopen(urllib.request.Request(u), timeout=120) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            b = e.read().decode()[:200]; print("  GET", e.code, b[:120])
            if "limit" in b.lower() or e.code in (17, 4, 613): time.sleep(12); continue
            return {}
        except Exception as e:
            print("  GET ERR", str(e)[:120]); time.sleep(3)
    return {}


def get_all(path, params=None):
    p = dict(params or {}); p.setdefault("limit", 200)
    out = []; res = get(path, p)
    while res:
        out += res.get("data", [])
        nxt = ((res.get("paging") or {}).get("cursors") or {}).get("after")
        if not nxt or not res.get("data"): break
        p2 = dict(p); p2["after"] = nxt; res = get(path, p2)
    return out


def post(path, params):
    p = dict(params); p["access_token"] = TOKEN
    data = urllib.parse.urlencode(p).encode()
    for _ in range(4):
        try:
            with urllib.request.urlopen(urllib.request.Request(f"{BASE}/{path}", data=data, method="POST"), timeout=120) as r:
                return json.loads(r.read().decode()), None
        except urllib.error.HTTPError as e:
            b = e.read().decode()[:300]
            if "limit" in b.lower() or e.code in (17, 4, 613): time.sleep(18); continue
            return None, f"HTTP {e.code}: {b}"
        except Exception as e:
            return None, str(e)[:200]
    return None, "retry_exhausted"


print(f"LAUNCH 3phones · act {ACT} · dry_run={DRY} · status={STATUS}")

print("=== STEP 1: pause old ads in 4 target adsets ===")
pause_ids = []
for (aid, an) in TARGET_ADSETS:
    ads = get_all(f"{aid}/ads", {"fields": "id,status"})
    tp = [a["id"] for a in ads if a.get("status") != "PAUSED"]
    print(f"  {an} ({aid}): total={len(ads)} to_pause={len(tp)}")
    pause_ids += tp
print(f"  TOTAL ads to pause: {len(pause_ids)}")
if not DRY:
    done = 0
    for adid in pause_ids:
        _, err = post(adid, {"status": "PAUSED"})
        if err: print("   pause FAIL", adid, err[:80])
        else: done += 1
        time.sleep(0.25)
    print(f"  paused {done}/{len(pause_ids)}")

print("=== STEP 2: pause sibling adsets ===")
if not DRY:
    for aid in SIBLING_ADSETS:
        _, err = post(aid, {"status": "PAUSED"})
        print(f"  sib {aid}: {'ok' if not err else err[:80]}")
else:
    print(f"  would pause {len(SIBLING_ADSETS)} sibling adsets")

print("=== STEP 3: create 2 ads x 4 adsets ===")
created = []; fails = Counter()
if not DRY:
    for (aid, an) in TARGET_ADSETS:
        for (nm, cid) in SOURCES:
            res, err = post(f"act_{ACT}/ads", {"name": f"{nm} · {an}", "adset_id": aid,
                                               "creative": json.dumps({"creative_id": cid}), "status": STATUS})
            if err: fails[err[:60]] += 1; print(f"  AD FAIL '{nm}'->{an}: {err[:140]}")
            else: created.append(res.get("id")); print(f"  AD OK {res.get('id')} '{nm}'->{an}")
            time.sleep(0.8)
    print(f"  created={len(created)}/8 fails={dict(fails)}")
else:
    print("  would create 8 ads (2 x 4 adsets)")

print("=== STEP 4: budgets (kopiyky) ===")
if not DRY:
    for cid, bud in CAMP_BUDGET.items():
        _, err = post(cid, {"daily_budget": bud}); print(f"  camp {cid} -> {bud}: {'ok' if not err else err[:80]}")
    for aid, bud in ADSET_BUDGET.items():
        _, err = post(aid, {"daily_budget": bud}); print(f"  adset {aid} -> {bud}: {'ok' if not err else err[:80]}")
else:
    print("  camp:", CAMP_BUDGET, " adset:", ADSET_BUDGET)

print("=== STEP 5: activate campaigns + target adsets ===")
if not DRY:
    for aid, _an in TARGET_ADSETS:
        _, err = post(aid, {"status": "ACTIVE"}); print(f"  adset {aid}: {'ok' if not err else err[:80]}")
    for cid in ACTIVATE_CAMPAIGNS:
        _, err = post(cid, {"status": "ACTIVE"}); print(f"  camp {cid}: {'ok' if not err else err[:80]}")
else:
    print(f"  would activate {len(TARGET_ADSETS)} adsets + {len(ACTIVATE_CAMPAIGNS)} campaigns")

print("=== STEP 6: verify ===")
for cid in ACTIVATE_CAMPAIGNS:
    r = get(cid, {"fields": "name,effective_status,daily_budget"})
    print(f"  CAMP {str(r.get('name','?'))[:24]:24} {r.get('effective_status','?')} bud={r.get('daily_budget','-')}")
for aid, an in VERIFY_ADSETS:
    r = get(aid, {"fields": "effective_status,daily_budget"})
    ads = get_all(f"{aid}/ads", {"fields": "status"})
    act = [a for a in ads if a.get("status") == "ACTIVE"]
    print(f"  ADSET {an[:20]:20} {r.get('effective_status','?')} bud={r.get('daily_budget','-')} active_ads={len(act)} total={len(ads)}")
print("DONE_LAUNCH_3PHONES")
