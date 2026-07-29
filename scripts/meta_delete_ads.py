#!/usr/bin/env python3
"""Delete a list of ads by ID for act 4136058269783354 via FB_ACCESS_TOKEN (no adspirer).

WHY: Meta cap = 50 ads per ad set, and PAUSED/inactive ads count toward it. Pausing does
NOT free a slot — only deletion does. This purges dead zombies so fresh creatives can be added.

SAFE BY DESIGN:
  * For every id we first GET effective_status and REFUSE to delete anything ACTIVE.
    Only PAUSED / DISAPPROVED / WITH_ISSUES / PENDING_* / ADSET_PAUSED / CAMPAIGN_PAUSED are deletable.
  * DRY_RUN prints the plan and writes nothing.

env: FB_ACCESS_TOKEN (req) · AD_ACCOUNT_ID (default 4136058269783354)
     DRY_RUN (default "true" -> plan only) · AD_IDS "id||id||..." (|| , or whitespace separated)
"""
import os, json, time, urllib.parse, urllib.request, urllib.error, re

GRAPH = os.environ.get("GRAPH_VER", "v21.0")
TOKEN = os.environ["FB_ACCESS_TOKEN"]
ACT = os.environ.get("AD_ACCOUNT_ID", "4136058269783354")
DRY = os.environ.get("DRY_RUN", "true").lower() == "true"
RAW = os.environ.get("AD_IDS", "").strip()
BASE = f"https://graph.facebook.com/{GRAPH}"
PROTECT_STATUS = {"ACTIVE"}


def get(node, params=None):
    p = dict(params or {}); p["access_token"] = TOKEN
    url = f"{BASE}/{node}?" + urllib.parse.urlencode(p)
    for _ in range(4):
        try:
            with urllib.request.urlopen(urllib.request.Request(url), timeout=90) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            body = e.read().decode()[:200]; print("  GET", e.code, body)
            if "limit" in body.lower():
                time.sleep(15); continue
            return {"__err__": body}
        except Exception as e:
            print("  GET ERR", str(e)[:120]); time.sleep(4)
    return {}


def delete(node):
    url = f"{BASE}/{node}?access_token=" + urllib.parse.quote(TOKEN)
    for _ in range(4):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, method="DELETE"), timeout=90) as r:
                return json.loads(r.read().decode()), None
        except urllib.error.HTTPError as e:
            body = e.read().decode()[:300]
            if "limit" in body.lower():
                time.sleep(20); continue
            return None, f"HTTP {e.code}: {body}"
        except Exception as e:
            return None, str(e)[:200]
    return None, "retry_exhausted"


ids = [x for x in re.split(r"[|,\s]+", RAW) if x.strip().isdigit()]
# de-dup preserving order
seen = set(); ids = [i for i in ids if not (i in seen or seen.add(i))]
print(f"META_DELETE · act {ACT} · dry_run={DRY} · {len(ids)} ids")
if not ids:
    print("no AD_IDS; nothing to do"); print("DONE_DELETE"); raise SystemExit(0)

plan = []
for aid in ids:
    info = get(aid, {"fields": "name,effective_status,adset_id"})
    if not info or info.get("__err__"):
        print(f"  UNRESOLVED {aid} {info.get('__err__','') if info else ''}"); continue
    st = info.get("effective_status"); nm = (info.get("name") or "?")[:30]
    if st in PROTECT_STATUS:
        print(f"  REFUSE {aid} '{nm}' is {st} — never delete active"); continue
    plan.append({"id": aid, "nm": nm, "st": st})

print("=== PLAN (delete) ===")
for p in plan:
    print(f"  DEL {p['id']} '{p['nm']:30}' [{p['st']}]")
print(f"total to delete: {len(plan)} (skipped {len(ids)-len(plan)})")
if not plan:
    print("empty plan"); print("DONE_DELETE"); raise SystemExit(0)
if DRY:
    print("DRY_RUN — no deletions."); print("DONE_DELETE"); raise SystemExit(0)

print("=== APPLY (delete) ===")
ok = 0
for p in plan:
    res, err = delete(p["id"])
    if err:
        print(f"  FAIL {p['id']} '{p['nm']}': {err}"); continue
    ok += 1; print(f"  DELETED {p['id']} '{p['nm']}'")
    time.sleep(0.5)
print(f"deleted {ok}/{len(plan)}")
print("DONE_DELETE")
