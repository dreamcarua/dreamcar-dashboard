#!/usr/bin/env python3
"""Meta budget scaler / pause / activate for act 4136058269783354 via FB_ACCESS_TOKEN (no adspirer).

SAFE BY DESIGN:
  * percent ops READ the current campaign daily_budget (minor units) and multiply by a factor —
    an absolute amount is never written, so a currency-unit mistake (x100) is impossible.
  * per-run change capped at MAX_PCT (default 30). Resulting daily budget capped at CAP_UAH.
  * budget scaling only touches ACTIVE campaigns; ABO/lifetime campaigns are skipped (reported).
  * campaigns whose name contains a PROTECTED token (default "НОВИХ") cannot be paused or cut.
  * after a real budget write, re-reads status and re-activates if Meta force-paused it, then verifies.

env:
  FB_ACCESS_TOKEN (required)  · AD_ACCOUNT_ID (default 4136058269783354)
  DRY_RUN  (default "true" -> plan only)  · MAX_PCT (default "30")
  CAP_UAH  (default "60000")  · PROTECTED (default "НОВИХ", ||-separated)
  TARGETS  "<id_or_nameSubstr>::<op>||..."   op = +25  |  -20  |  PAUSE  |  ACTIVATE

AD-LEVEL: якщо цифровий ID не знайдено серед кампаній, шукаємо серед ads акаунту.
Для ads дозволені ЛИШЕ PAUSE/ACTIVATE (бюджету в ad немає). Захист PROTECTED діє так само.
"""
import os, json, time, urllib.parse, urllib.request, urllib.error

GRAPH = os.environ.get("GRAPH_VER", "v21.0")
TOKEN = os.environ["FB_ACCESS_TOKEN"]
ACT = os.environ.get("AD_ACCOUNT_ID", "4136058269783354")
DRY = os.environ.get("DRY_RUN", "true").lower() == "true"
MAX_PCT = float(os.environ.get("MAX_PCT", "30"))
CAP_MINOR = int(float(os.environ.get("CAP_UAH", "60000")) * 100)
PROTECTED = [s.strip().lower() for s in os.environ.get("PROTECTED", "НОВИХ").split("||") if s.strip()]
TARGETS = os.environ.get("TARGETS", "").strip()
BASE = f"https://graph.facebook.com/{GRAPH}"


def get(path, params=None):
    p = dict(params or {}); p["access_token"] = TOKEN
    url = f"{BASE}/{path}?" + urllib.parse.urlencode(p)
    for _ in range(4):
        try:
            with urllib.request.urlopen(urllib.request.Request(url), timeout=90) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            body = e.read().decode()[:200]; print("  GET", e.code, body)
            if e.code in (17, 4, 613) or "limit" in body.lower():
                time.sleep(15); continue
            return {}
        except Exception as e:
            print("  GET ERR", str(e)[:120]); time.sleep(4)
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
    for _ in range(4):
        try:
            with urllib.request.urlopen(urllib.request.Request(f"{BASE}/{path}", data=data, method="POST"), timeout=90) as r:
                return json.loads(r.read().decode()), None
        except urllib.error.HTTPError as e:
            body = e.read().decode()[:300]
            if e.code in (17, 4, 613) or "limit" in body.lower():
                time.sleep(20); continue
            return None, f"HTTP {e.code}: {body}"
        except Exception as e:
            return None, str(e)[:200]
    return None, "retry_exhausted"


def uah(minor):
    try:
        return f"{int(minor)/100:,.0f}"
    except Exception:
        return "?"


print(f"META_SCALE · act {ACT} · dry_run={DRY} · max_pct={MAX_PCT} · cap={uah(CAP_MINOR)}UAH · protected={PROTECTED}")
if not TARGETS:
    print("no TARGETS given; nothing to do"); print("DONE_SCALE"); raise SystemExit(0)

camps = get_all(f"act_{ACT}/campaigns", {"fields": "name,objective,effective_status,daily_budget,lifetime_budget"})
by_id = {c["id"]: c for c in camps}
print(f"loaded {len(camps)} campaigns")


_ads_cache = None


def load_ads():
    """Ліниво тягне ads акаунту — лише коли цифровий ID не знайшовся серед кампаній."""
    global _ads_cache
    if _ads_cache is None:
        _ads_cache = get_all(f"act_{ACT}/ads", {"fields": "name,effective_status", "limit": 500})
        print(f"loaded {len(_ads_cache)} ads (ad-level fallback)")
    return _ads_cache


def resolve(tok):
    tok = tok.strip()
    if tok.isdigit():
        c = by_id.get(tok)
        if c:
            return c
        for a in load_ads():
            if a.get("id") == tok:
                a = dict(a)
                a["_level"] = "ad"
                return a
        return None
    hits = [c for c in camps if tok.lower() in (c.get("name", "") or "").lower()]
    if len(hits) == 1:
        return hits[0]
    if len(hits) > 1:
        print(f"  AMBIGUOUS '{tok}' -> {[h.get('name') for h in hits]}")
    return None


plan = []
for part in TARGETS.split("||"):
    if "::" not in part:
        continue
    tok, op = part.split("::", 1); tok, op = tok.strip(), op.strip()
    c = resolve(tok)
    if not c:
        print(f"  UNRESOLVED '{tok}'"); continue
    nm = c.get("name", "?"); cid = c["id"]; st = c.get("effective_status")
    lvl = c.get("_level", "campaign")
    prot = any(pt in nm.lower() for pt in PROTECTED)
    opu = op.upper()
    if opu in ("PAUSE", "ACTIVATE"):
        if opu == "PAUSE" and prot:
            print(f"  REFUSE pause protected '{nm}'"); continue
        plan.append({"cid": cid, "nm": nm, "kind": "status", "lvl": lvl,
                     "to": "PAUSED" if opu == "PAUSE" else "ACTIVE", "st": st})
        continue
    if lvl == "ad":
        print(f"  REFUSE budget op on AD '{nm}' — ads have no budget; use PAUSE/ACTIVATE"); continue
    try:
        pct = float(op.replace("%", "").replace("+", ""))
        if op.strip().startswith("-"):
            pct = -abs(pct)
    except Exception:
        print(f"  BAD op '{op}' for '{nm}'"); continue
    if pct < 0 and prot:
        print(f"  REFUSE cut protected '{nm}'"); continue
    if abs(pct) > MAX_PCT:
        print(f"  REFUSE |{pct:.0f}%| > {MAX_PCT:.0f}% for '{nm}'"); continue
    if st != "ACTIVE":
        print(f"  SKIP '{nm}' not ACTIVE ({st}) — budget scale only on active"); continue
    db = c.get("daily_budget")
    if not db:
        print(f"  SKIP '{nm}' — no campaign daily_budget (ABO/lifetime); set at adset level"); continue
    cur = int(db); new = int(round(cur * (1 + pct / 100.0)))
    if new > CAP_MINOR:
        print(f"  REFUSE new {uah(new)}UAH > cap {uah(CAP_MINOR)}UAH for '{nm}'"); continue
    if new < 10000:
        print(f"  REFUSE new {uah(new)}UAH too low for '{nm}'"); continue
    plan.append({"cid": cid, "nm": nm, "kind": "budget", "cur": cur, "new": new, "pct": pct, "st": st})

print("=== PLAN ===")
for p in plan:
    if p["kind"] == "budget":
        print(f"  BUDGET '{p['nm'][:34]:34}' {uah(p['cur'])} -> {uah(p['new'])} UAH ({p['pct']:+.0f}%) [{p['st']}]")
    else:
        print(f"  STATUS[{p.get('lvl','campaign')[:3]}] '{p['nm'][:34]:34}' -> {p['to']} [{p['st']}]")
if not plan:
    print("empty plan"); print("DONE_SCALE"); raise SystemExit(0)
if DRY:
    print("DRY_RUN — no writes."); print("DONE_SCALE"); raise SystemExit(0)

print("=== APPLY ===")
for p in plan:
    if p["kind"] == "budget":
        res, err = post(f"{p['cid']}", {"daily_budget": p["new"]})
        if err:
            print(f"  FAIL budget '{p['nm'][:20]}': {err}"); continue
        chk = get(f"{p['cid']}", {"fields": "effective_status,configured_status,daily_budget"})
        cur_st = chk.get("configured_status") or chk.get("effective_status")
        if cur_st and cur_st != "ACTIVE":
            post(f"{p['cid']}", {"status": "ACTIVE"}); time.sleep(1)
        fin = get(f"{p['cid']}", {"fields": "effective_status,daily_budget"})
        print(f"  OK budget '{p['nm'][:20]}' -> {uah(fin.get('daily_budget'))} UAH · status {fin.get('effective_status')}")
    else:
        res, err = post(f"{p['cid']}", {"status": p["to"]})
        if err:
            print(f"  FAIL status '{p['nm'][:20]}': {err}"); continue
        fin = get(f"{p['cid']}", {"fields": "effective_status"})
        print(f"  OK status '{p['nm'][:20]}' -> {fin.get('effective_status')}")
    time.sleep(1)
print("DONE_SCALE")
