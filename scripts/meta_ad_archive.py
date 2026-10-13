#!/usr/bin/env python3
"""Archive ads into Supabase public.ad_archive, then (optionally) delete them. Archive always comes first.

WHY (owner 13.10.2026): before deleting switched-off ads, keep what each ad was and how it did, so later
conclusions are made on full data. Daily stats already stay in dashboard_ads_data (the ETL never deletes);
this keeps the rest: creative (text, title, media, link, CTA, post id), lifetime platform stats, real revenue
from CRM payments by UTM (utm_content = "<adset name>_<ad name>", compared case-insensitively).

SELECTION (MODE):
  ids        - AD_IDS "id||id..." exactly as given.
  candidates - every ad of the account whose OWN status is PAUSED (or effective DISAPPROVED) and that was
               created before CREATED_BEFORE (ISO, e.g. cycle start). Ads with own status ACTIVE inside a
               switched-off ad set or campaign are kept: they are the content of a structure that may be
               switched on again.
SAFETY:
  * an effectively ACTIVE ad is never archived-for-delete nor deleted;
  * DELETE=false (default) archives only; with DELETE=true an ad is deleted only after its archive row was
    written and read back;
  * PROTECT_CAMPAIGNS: comma list of campaign ids whose ads are never deleted.
env: FB_ACCESS_TOKEN, SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY, AD_ACCOUNT_ID, MODE, AD_IDS, CREATED_BEFORE,
     DELETE, PROTECT_CAMPAIGNS, PROJECT (default dreamcar), LIMIT (default 2000)
"""
import os, re, json, time, urllib.parse, urllib.request, urllib.error
from datetime import datetime

GRAPH = os.environ.get("GRAPH_VER", "v21.0")
TOKEN = os.environ["FB_ACCESS_TOKEN"]
SB_URL = os.environ.get("SUPABASE_URL", "https://wotghlaehnvxyeacznvv.supabase.co").rstrip("/")
SB_KEY = os.environ["SUPABASE_SERVICE_ROLE_KEY"]
ACT = os.environ.get("AD_ACCOUNT_ID", "4136058269783354")
MODE = os.environ.get("MODE", "ids")
RAW = os.environ.get("AD_IDS", "")
BEFORE = os.environ.get("CREATED_BEFORE", "").strip()
DELETE = os.environ.get("DELETE", "false").lower() == "true"
PROTECT = {x.strip() for x in os.environ.get("PROTECT_CAMPAIGNS", "").split(",") if x.strip()}
PROJECT = os.environ.get("PROJECT", "dreamcar")
LIMIT = int(os.environ.get("LIMIT", "2000"))
BASE = f"https://graph.facebook.com/{GRAPH}"

CREATIVE_FIELDS = ("id,name,title,body,object_type,call_to_action_type,link_url,object_url,image_url,thumbnail_url,"
                   "video_id,instagram_permalink_url,effective_instagram_media_id,effective_object_story_id,"
                   "object_story_spec,asset_feed_spec,url_tags")
AD_FIELDS = (f"id,name,status,effective_status,created_time,adset{{id,name}},campaign{{id,name}},"
             f"creative{{{CREATIVE_FIELDS}}}")
INSIGHT_FIELDS = ("spend,impressions,reach,frequency,clicks,inline_link_clicks,ctr,cpm,cpc,actions,action_values,"
                  "purchase_roas,date_start,date_stop")


def http(url, method="GET", data=None, headers=None, tries=4):
    for i in range(tries):
        try:
            req = urllib.request.Request(url, data=data, method=method, headers=headers or {})
            with urllib.request.urlopen(req, timeout=90) as r:
                body = r.read().decode()
                return (json.loads(body) if body else {}), None
        except urllib.error.HTTPError as e:
            body = e.read().decode()[:400]
            if "limit" in body.lower() or e.code in (429, 500, 502, 503):
                time.sleep(10 * (i + 1)); continue
            return None, f"HTTP {e.code}: {body}"
        except Exception as e:
            time.sleep(4); err = str(e)[:200]
    return None, "retry_exhausted"


def fb(node, params=None, method="GET"):
    p = dict(params or {}); p["access_token"] = TOKEN
    return http(f"{BASE}/{node}?" + urllib.parse.urlencode(p), method=method)


def sb(path, method="GET", payload=None, prefer=None):
    h = {"apikey": SB_KEY, "Authorization": f"Bearer {SB_KEY}", "Content-Type": "application/json"}
    if prefer: h["Prefer"] = prefer
    data = json.dumps(payload, ensure_ascii=False).encode() if payload is not None else None
    return http(f"{SB_URL}/rest/v1/{path}", method=method, data=data, headers=h)


def candidates():
    out, after, n = [], None, 0
    while True:
        p = {"fields": "id,status,effective_status,created_time", "limit": 200}
        if after: p["after"] = after
        res, err = fb(f"act_{ACT}/ads", p)
        if err: raise SystemExit(f"LIST ERR {err}")
        for a in res.get("data", []):
            n += 1
            if BEFORE and a.get("created_time", "") >= BEFORE:
                continue
            if a.get("status") == "PAUSED" or a.get("effective_status") == "DISAPPROVED":
                out.append(a["id"])
        paging = res.get("paging") or {}
        after = (paging.get("cursors") or {}).get("after")
        if not paging.get("next"): break
    print(f"listed {n} ads of the account; candidates {len(out)}")
    return out[:LIMIT]


def cycle_of(name):
    m = re.search(r"(?:старт|пост)(\d{2})-", name or "")
    return int(m.group(1)) if m else None


def real_revenue(adset_name, ad_name):
    # real ROAS rule of the project: only payments with an ad placement mark in utm_medium count
    key = f"{adset_name}_{ad_name}".replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_")
    q = ("dashboard_deals?select=amount&status=eq.pay&amount=gt.0"
         "&utm_medium=match." + urllib.parse.quote("^(facebook|instagram|messenger|audience_network|threads)_", safe="")
         + "&utm_content=ilike." + urllib.parse.quote(key, safe=""))
    res, err = sb(q)
    if err or res is None: return None, None
    return round(sum(float(r.get("amount") or 0) for r in res), 2), len(res)


ids = [x for x in re.split(r"[|,\s]+", RAW) if x.strip().isdigit()] if MODE == "ids" else candidates()
print(f"AD_ARCHIVE · act {ACT} · mode={MODE} · before={BEFORE or '-'} · delete={DELETE} · {len(ids)} ids")
archived = deleted = skipped = failed = 0
for aid in ids:
    ad, err = fb(aid, {"fields": AD_FIELDS})
    if err or not ad:
        print(f"  UNRESOLVED {aid} {err}"); failed += 1; continue
    est = ad.get("effective_status")
    camp = (ad.get("campaign") or {})
    if est == "ACTIVE":
        print(f"  SKIP ACTIVE {aid} '{ad.get('name','')[:40]}'"); skipped += 1; continue
    ins, _ = fb(f"{aid}/insights", {"fields": INSIGHT_FIELDS, "date_preset": "maximum"})
    life = ((ins or {}).get("data") or [{}])[0]
    adset = ad.get("adset") or {}
    rev, pays = real_revenue(adset.get("name", ""), ad.get("name", ""))
    cr = ad.get("creative") or {}
    row = {
        "ad_id": aid, "project": PROJECT, "ad_account_id": ACT, "ad_name": ad.get("name"),
        "adset_id": adset.get("id"), "adset_name": adset.get("name"),
        "campaign_id": camp.get("id"), "campaign_name": camp.get("name"),
        "created_time": ad.get("created_time"), "last_status": f"{ad.get('status')}/{est}",
        "cycle_no": cycle_of(ad.get("name")), "creative_id": cr.get("id"), "creative": cr, "lifetime": life,
        "real_revenue": rev, "real_payments": pays,
    }
    _, err = sb("ad_archive?on_conflict=ad_id", "POST", [row], prefer="resolution=merge-duplicates,return=minimal")
    if err:
        print(f"  ARCHIVE FAIL {aid}: {err}"); failed += 1; continue
    back, err = sb(f"ad_archive?select=ad_id&ad_id=eq.{aid}")
    if err or not back:
        print(f"  ARCHIVE NOT READ BACK {aid}"); failed += 1; continue
    archived += 1
    spend = life.get("spend", "0")
    print(f"  ARCHIVED {aid} '{(ad.get('name') or '')[:40]}' spend={spend} real={rev} pays={pays}")
    if not DELETE:
        continue
    if camp.get("id") in PROTECT:
        print(f"    keep (protected campaign {camp.get('id')})"); continue
    res, err = fb(aid, method="DELETE")
    if err:
        print(f"    DELETE FAIL {aid}: {err}"); failed += 1; continue
    deleted += 1
    sb(f"ad_archive?ad_id=eq.{aid}", "PATCH", {"deleted_at": datetime.utcnow().isoformat() + "Z"}, prefer="return=minimal")
    time.sleep(0.4)
print(f"archived {archived} · deleted {deleted} · skipped active {skipped} · failed {failed}")
print("DONE_AD_ARCHIVE")
