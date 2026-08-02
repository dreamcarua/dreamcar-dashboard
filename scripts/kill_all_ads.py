#!/usr/bin/env python3
"""SALES CUTOFF: pause ALL active campaigns in Meta act 4136058269783354.
Cloud backup so advertising stops even if the desktop app is closed. READ+WRITE.
env: FB_ACCESS_TOKEN (secret), AD_ACCOUNT_ID (default 4136058269783354).
"""
import os, json, time, urllib.parse, urllib.request, urllib.error

GRAPH = os.environ.get("GRAPH_VER", "v21.0")
TOKEN = os.environ["FB_ACCESS_TOKEN"]
ACT = os.environ.get("AD_ACCOUNT_ID", "4136058269783354")
BASE = f"https://graph.facebook.com/{GRAPH}"
ACTIVE_FILTER = json.dumps([{"field": "effective_status", "operator": "IN", "value": ["ACTIVE"]}])


def get(path, params=None):
    p = dict(params or {}); p["access_token"] = TOKEN
    u = f"{BASE}/{path}?" + urllib.parse.urlencode(p)
    for a in range(4):
        try:
            with urllib.request.urlopen(urllib.request.Request(u), timeout=120) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            b = e.read().decode()[:200]; print("  GET", e.code, b)
            if "limit" in b.lower() or e.code in (17, 4, 613):
                time.sleep(10); continue
            return {}
        except Exception as e:
            print("  GET ERR", str(e)[:150]); time.sleep(3)
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
            b = e.read().decode()[:300]
            if "limit" in b.lower() or e.code in (17, 4, 613):
                time.sleep(15); continue
            return None, f"HTTP {e.code}: {b}"
        except Exception as e:
            return None, str(e)[:150]
    return None, "retry_exhausted"


print("KILL ALL ADS (sales cutoff) · act", ACT)
camps = get_all(f"act_{ACT}/campaigns", {"fields": "name,effective_status", "filtering": ACTIVE_FILTER})
print("active_campaigns:", len(camps))
paused = 0
for c in camps:
    res, err = post(f"{c['id']}", {"status": "PAUSED"})
    if err:
        print("  FAIL", c["id"], (c.get("name", "") or "")[:34], err)
    else:
        paused += 1; print("  PAUSED", c["id"], (c.get("name", "") or "")[:34])
    time.sleep(0.5)
print(f"paused={paused}/{len(camps)}")
# verify nothing remains active
left = get_all(f"act_{ACT}/campaigns", {"fields": "name", "filtering": ACTIVE_FILTER})
print("STILL_ACTIVE:", len(left))
for c in left:
    print("  still_active:", c["id"], (c.get("name", "") or "")[:34])
print("DONE_KILL_ALL")
