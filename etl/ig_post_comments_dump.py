#!/usr/bin/env python3
"""Dump one IG post (by shortcode) with insights and ALL comments + replies.

Env: FB_ACCESS_TOKEN, IG_USER_ID, SHORTCODE. Output: out/post_dump.json
Used for giveaway participant lists (one-off, workflow_dispatch).
"""
import json, os, sys, time, urllib.parse, urllib.request

G = "https://graph.facebook.com/v21.0"
TOKEN = os.environ["FB_ACCESS_TOKEN"]
IG = os.environ["IG_USER_ID"]
SC = os.environ["SHORTCODE"].strip()


def get(url, params=None):
    if params is not None:
        params = dict(params, access_token=TOKEN)
        url = f"{url}?{urllib.parse.urlencode(params)}"
    for attempt in range(5):
        try:
            with urllib.request.urlopen(url, timeout=60) as r:
                return json.loads(r.read())
        except urllib.error.HTTPError as e:
            body = e.read().decode()[:500]
            if e.code in (500, 502, 503) or "rate" in body.lower():
                time.sleep(5 * (attempt + 1)); continue
            raise RuntimeError(f"HTTP {e.code}: {body}")
    raise RuntimeError("retries exhausted")


def paged(url, params):
    out, page = [], get(url, params)
    while True:
        out.extend(page.get("data", []))
        nxt = page.get("paging", {}).get("next")
        if not nxt:
            return out
        page = get(nxt)


def main():
    media_fields = "id,shortcode,caption,timestamp,media_type,media_product_type,permalink,like_count,comments_count,thumbnail_url,media_url"
    media = None
    url, params = f"{G}/{IG}/media", {"fields": media_fields, "limit": 50}
    for _ in range(40):
        page = get(url, params)
        for m in page.get("data", []):
            if m.get("shortcode") == SC or SC in (m.get("permalink") or ""):
                media = m; break
        if media or not page.get("paging", {}).get("next"):
            break
        url, params = page["paging"]["next"], None
    if not media:
        sys.exit(f"shortcode {SC} not found")
    mid = media["id"]
    print("media", mid, media.get("media_product_type"), "comments_count", media.get("comments_count"))

    insights = {}
    for metric in ["reach", "views", "impressions", "likes", "comments", "shares", "saved",
                   "total_interactions", "profile_visits", "follows", "profile_activity"]:
        try:
            d = get(f"{G}/{mid}/insights", {"metric": metric})
            for row in d.get("data", []):
                v = row.get("values", [{}])[0].get("value") if row.get("values") else row.get("total_value", {}).get("value")
                insights[row["name"]] = v
        except Exception as e:
            insights[f"_err_{metric}"] = str(e)[:160]

    cf = "id,text,timestamp,username,like_count,hidden,from{id,username},parent_id"
    comments = paged(f"{G}/{mid}/comments", {"fields": cf, "limit": 50})
    print("top-level fetched", len(comments))
    replies = []
    for c in comments:
        rs = paged(f"{G}/{c['id']}/replies", {"fields": cf, "limit": 50})
        for r in rs:
            r["parent_id"] = c["id"]
        replies.extend(rs)
    print("replies fetched", len(replies))

    os.makedirs("out", exist_ok=True)
    with open("out/post_dump.json", "w") as f:
        json.dump({"fetched_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                   "media": media, "insights": insights,
                   "comments": comments, "replies": replies}, f, ensure_ascii=False)


if __name__ == "__main__":
    main()
