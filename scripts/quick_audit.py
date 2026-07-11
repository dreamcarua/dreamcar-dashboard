#!/usr/bin/env python3
"""Quick fresh Meta audit (READ-ONLY): yesterday vs today.
Account + campaign + adset: spend, purchase_roas, purchases, frequency.
Prints clean tables to stdout so a GitHub Action log can be read directly.
env: FB_ACCESS_TOKEN (secret), AD_ACCOUNT_ID (default 4136058269783354).
"""
import os, json, urllib.parse, urllib.request, urllib.error

GRAPH = os.environ.get("GRAPH_VER", "v21.0")
TOKEN = os.environ["FB_ACCESS_TOKEN"]
ACT = os.environ.get("AD_ACCOUNT_ID", "4136058269783354")
BASE = f"https://graph.facebook.com/{GRAPH}/act_{ACT}/insights"
PURCH = ("omni_purchase", "purchase", "offsite_conversion.fb_pixel_purchase")


def get(params):
    url = BASE + "?" + urllib.parse.urlencode(params)
    for a in range(3):
        try:
            with urllib.request.urlopen(urllib.request.Request(url), timeout=120) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            print("  HTTP", e.code, e.read().decode()[:200])
        except Exception as e:
            print("  ERR", str(e)[:200])
    return {"data": []}


def _val(arr, keys):
    for x in (arr or []):
        if x.get("action_type") in keys:
            try:
                return float(x.get("value", 0))
            except Exception:
                return 0.0
    return 0.0


def roas(row):
    pr = row.get("purchase_roas") or []
    if pr:
        try:
            return float(pr[0].get("value", 0))
        except Exception:
            pass
    sp = float(row.get("spend", 0) or 0)
    return (_val(row.get("action_values"), PURCH) / sp) if sp else 0.0


def dump(level, preset):
    fields = "spend,purchase_roas,action_values,actions,impressions"
    if level != "account":
        fields += f",{level}_name"
    if level == "adset":
        fields += ",frequency"
    p = {"level": level, "fields": fields, "date_preset": preset, "limit": 200, "access_token": TOKEN}
    data = [r for r in get(p).get("data", []) if float(r.get("spend", 0) or 0) > 0]
    data.sort(key=lambda r: -float(r.get("spend", 0) or 0))
    print(f"\n=== {level.upper()} · {preset} ===")
    tot_s = tot_v = 0.0
    for r in data[:25]:
        nm = "ACCOUNT" if level == "account" else (r.get(f"{level}_name", "?") or "?")
        sp = float(r.get("spend", 0) or 0)
        pu = int(_val(r.get("actions"), PURCH))
        ro = roas(r)
        tot_s += sp
        tot_v += sp * ro
        extra = f" | freq {float(r.get('frequency', 0) or 0):.2f}" if level == "adset" else ""
        print(f"  {nm[:40]:40} | spend {sp:8.0f} | ROAS {ro:6.2f} | purch {pu:4d}{extra}")
    if level != "account" and tot_s:
        print(f"  {'-- blended --':40} | spend {tot_s:8.0f} | ROAS {tot_v / tot_s:6.2f}")


print("FRESH META AUDIT (read-only) · act", ACT)
for preset in ["yesterday", "today"]:
    dump("account", preset)
    dump("campaign", preset)
dump("adset", "today")
print("\nDONE_QUICK_AUDIT")
