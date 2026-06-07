#!/usr/bin/env python3
"""
ETL Facebook Marketing API → Supabase dashboard_ads_data.

Replaces Make.com scenario "FB Ads → MySQL ads_data".

Tig per ad-level insights with UTM extraction from link URLs.

Env vars:
  FB_ACCESS_TOKEN          — System User long-lived token (Business Manager)
  FB_AD_ACCOUNT_IDS        — comma-separated: act_123,act_456 (or 123 without prefix)
  SUPABASE_URL
  SUPABASE_SERVICE_ROLE_KEY

CLI:
  --mode=incremental  (default) — yesterday + today
  --mode=initial                — last 30 days
  --mode=range --since=YYYY-MM-DD --until=YYYY-MM-DD
"""
import os, sys, json, argparse, time
from datetime import datetime, timedelta, timezone
from urllib.parse import urlparse, parse_qs
import requests

# ===== CONFIG =====
FB_TOKEN = os.getenv('FB_ACCESS_TOKEN', '')
FB_ACCOUNTS = [a.strip() for a in os.getenv('FB_AD_ACCOUNT_IDS', '').split(',') if a.strip()]
FB_API_VERSION = os.getenv('FB_API_VERSION', 'v21.0')

SB_URL = os.getenv('SUPABASE_URL', 'https://wotghlaehnvxyeacznvv.supabase.co')
SB_KEY = os.getenv('SUPABASE_SERVICE_ROLE_KEY', '')

HEADERS_SB = {
    'apikey': SB_KEY,
    'Authorization': f'Bearer {SB_KEY}',
    'Content-Type': 'application/json',
    'Prefer': 'resolution=merge-duplicates,return=minimal',
}

BATCH = 100

# ===== HELPERS =====
def log(msg):
    ts = datetime.now(timezone.utc).strftime('%H:%M:%S')
    print(f'[{ts}] {msg}', flush=True)


def normalize_account(acct):
    """Ensure 'act_' prefix."""
    if not acct.startswith('act_'):
        return f'act_{acct}'
    return acct


def extract_utm_from_url(url):
    """Parse UTM params from a destination URL."""
    if not url:
        return {}
    try:
        q = parse_qs(urlparse(url).query)
        return {
            'utm_source':   (q.get('utm_source')   or [None])[0],
            'utm_medium':   (q.get('utm_medium')   or [None])[0],
            'utm_campaign': (q.get('utm_campaign') or [None])[0],
            'utm_term':     (q.get('utm_term')     or [None])[0],
            'utm_content':  (q.get('utm_content')  or [None])[0],
        }
    except Exception:
        return {}


# ===== FB API =====
def fb_get(path, params=None):
    """GET with retry on rate-limit."""
    url = f'https://graph.facebook.com/{FB_API_VERSION}/{path}'
    params = params or {}
    params['access_token'] = FB_TOKEN

    for attempt in range(5):
        r = requests.get(url, params=params, timeout=60)
        if r.status_code == 200:
            return r.json()
        # FB throttling
        if r.status_code in (4, 17, 32) or 'usage' in r.text.lower():
            wait = 2 ** attempt * 5
            log(f'  ⏳ FB rate limit, sleep {wait}s...')
            time.sleep(wait)
            continue
        log(f'  ❌ FB {r.status_code}: {r.text[:300]}')
        r.raise_for_status()
    raise RuntimeError(f'FB API failed after 5 attempts: {path}')


def get_account_info(acct):
    """Fetch ad account name + currency."""
    data = fb_get(acct, {'fields': 'name,currency'})
    return data.get('name'), data.get('currency', 'USD')


def _extract_url_from_creative(creative):
    """Спробувати 8 стратегій витягнути URL з ad creative."""
    if not creative:
        return None, None
    # Strategy 1: creative.link_url / template_url
    url = creative.get('link_url') or creative.get('template_url')
    if url: return url, 'link_url'
    spec = creative.get('object_story_spec') or {}
    # Strategy 2: link_data.link (link ads)
    link_data = spec.get('link_data') or {}
    url = link_data.get('link')
    if url: return url, 'link_data.link'
    # Strategy 3: link_data.child_attachments[].link (carousel)
    for child in (link_data.get('child_attachments') or []):
        if child.get('link'):
            return child['link'], 'link_data.child_attachments'
    # Strategy 4: link_data.call_to_action.value.link
    cta_link = ((link_data.get('call_to_action') or {}).get('value') or {}).get('link')
    if cta_link: return cta_link, 'link_data.cta'
    # Strategy 5: video_data.call_to_action.value.link
    vd = spec.get('video_data') or {}
    cta = ((vd.get('call_to_action') or {}).get('value') or {}).get('link')
    if cta: return cta, 'video_data.cta'
    # Strategy 6: photo_data.url або call_to_action
    pd = spec.get('photo_data') or {}
    if pd.get('url'): return pd['url'], 'photo_data.url'
    pd_cta = ((pd.get('call_to_action') or {}).get('value') or {}).get('link')
    if pd_cta: return pd_cta, 'photo_data.cta'
    # Strategy 7: text_data або page_welcome
    td = spec.get('template_data') or {}
    if td.get('link'): return td['link'], 'template_data.link'
    # Strategy 8: asset_feed_spec.link_urls[0].website_url (динамічні)
    afs = creative.get('asset_feed_spec') or {}
    link_urls = afs.get('link_urls') or []
    if link_urls and link_urls[0].get('website_url'):
        return link_urls[0]['website_url'], 'asset_feed_spec'
    return None, None


def _fetch_post_url(post_id):
    """Для shared post (effective_object_story_id) — окремий fetch URL."""
    if not post_id:
        return None
    try:
        data = fb_get(post_id, {'fields': 'permalink_url,call_to_action,attachments{target{url}}'})
        # CTA link
        cta = (data.get('call_to_action') or {}).get('value') or {}
        if cta.get('link'):
            return cta['link']
        # Attachments target URL
        for att in (data.get('attachments', {}).get('data', []) or []):
            target_url = ((att.get('target') or {}).get('url'))
            if target_url and 'utm_' in target_url:
                return target_url
        return data.get('permalink_url')
    except Exception as e:
        log(f'  ⚠ post {post_id} fetch failed: {e}')
        return None


def get_ad_link_urls(acct):
    """
    Fetch link URLs per ad (for UTM extraction).
    Returns: { ad_id: link_url }
    """
    links = {}
    no_url_count = 0
    unresolved_post_ids = []  # для другого pass через effective_object_story_id
    params = {
        'fields': 'id,name,creative{object_story_spec,asset_feed_spec,effective_object_story_id,link_url,template_url}',
        'limit': 200,
    }
    next_url = None
    while True:
        if next_url:
            r = requests.get(next_url, timeout=60)
            data = r.json()
        else:
            data = fb_get(f'{acct}/ads', params)
        for ad in data.get('data', []):
            creative = ad.get('creative') or {}
            url, strategy = _extract_url_from_creative(creative)
            if url:
                links[ad['id']] = url
            else:
                # Fallback: ad refers to existing post — спробувати дотягнути URL з посту
                eosi = creative.get('effective_object_story_id')
                if eosi:
                    unresolved_post_ids.append((ad['id'], eosi, ad.get('name')))
                else:
                    no_url_count += 1
        paging = data.get('paging') or {}
        next_url = paging.get('next')
        if not next_url:
            break

    # 2nd pass: для unresolved ads — fetch URL через post_id
    if unresolved_post_ids:
        log(f'  ↻ resolving {len(unresolved_post_ids)} posts for missing URLs...')
        for ad_id, post_id, ad_name in unresolved_post_ids[:200]:  # cap до 200 викликів
            url = _fetch_post_url(post_id)
            if url:
                links[ad_id] = url
            else:
                no_url_count += 1

    log(f'  ✓ resolved {len(links)} URLs, {no_url_count} ads without URL')
    return links


def _date(s):
    return datetime.strptime(s, '%Y-%m-%d').date()


def _chunks(since, until, max_days=80):
    """
    FB Marketing API ad-level insights max window with time_increment=1 is 90 days.
    Split larger range into chunks of `max_days`.
    Returns list of (since, until) tuples (strings).
    """
    s = _date(since)
    u = _date(until)
    out = []
    cur = s
    while cur <= u:
        nxt = min(cur + timedelta(days=max_days - 1), u)
        out.append((cur.isoformat(), nxt.isoformat()))
        cur = nxt + timedelta(days=1)
    return out


def _fetch_insights_chunk(acct, since, until):
    all_rows = []
    params = {
        'level': 'ad',
        'fields': ','.join([
            'account_id', 'account_name', 'account_currency',
            'campaign_id', 'campaign_name',
            'adset_id', 'adset_name',
            'ad_id', 'ad_name',
            'spend', 'impressions', 'clicks', 'reach',
            'ctr', 'cpc', 'cpm',
            'actions', 'action_values',
        ]),
        'time_range': json.dumps({'since': since, 'until': until}),
        'time_increment': 1,
        'limit': 500,
    }
    next_url = None
    while True:
        if next_url:
            r = requests.get(next_url, timeout=120)
            data = r.json()
        else:
            data = fb_get(f'{acct}/insights', params)
        all_rows.extend(data.get('data', []))
        paging = data.get('paging') or {}
        next_url = paging.get('next')
        if not next_url:
            break
    return all_rows


def get_insights(acct, since, until):
    """
    Pull ad-level insights for date range.
    Auto-chunks ranges > 80 days (FB API limit with time_increment=1).
    """
    chunks = _chunks(since, until, max_days=80)
    if len(chunks) > 1:
        log(f'   Chunking {since}..{until} into {len(chunks)} pieces (FB API 90-day limit)')
    all_rows = []
    for i, (cs, cu) in enumerate(chunks, 1):
        if len(chunks) > 1:
            log(f'   [{i}/{len(chunks)}] {cs}..{cu}')
        rows = _fetch_insights_chunk(acct, cs, cu)
        all_rows.extend(rows)
        if len(chunks) > 1:
            log(f'       +{len(rows)} rows (total {len(all_rows)})')
    return all_rows


# ===== TRANSFORM =====
def count_actions(actions, target_types):
    """Sum actions of specific types (e.g., lead, purchase)."""
    if not actions:
        return 0
    total = 0
    for a in actions:
        t = a.get('action_type', '')
        if t in target_types:
            total += float(a.get('value', 0) or 0)
    return total


def transform_row(row, link_map, acct_name, acct_currency, executor_map=None):
    """FB insight row → dashboard_ads_data row."""
    ad_id = row.get('ad_id') or ''
    link_url = link_map.get(ad_id)
    utm = extract_utm_from_url(link_url)
    # 07.06.2026: fallback — якщо FB не повертає URL з utm_term, мапимо за ad_account_id
    # (бо більшість акаунтів веде один виконавець, налаштування FB Ads URL Parameters ще не зроблені)
    if not utm.get('utm_term') and executor_map:
        acct = row.get('account_id') or ''
        if acct in executor_map:
            utm['utm_term'] = executor_map[acct]
            utm['utm_source'] = utm.get('utm_source') or 'facebook'
            utm['utm_medium'] = utm.get('utm_medium') or 'cpc'

    # Conversions = leads + completed registrations + purchases
    conv = count_actions(row.get('actions'), [
        'lead',
        'complete_registration',
        'purchase',
        'offsite_conversion.fb_pixel_lead',
        'offsite_conversion.fb_pixel_purchase',
        'offsite_conversion.fb_pixel_complete_registration',
    ])

    return {
        'ad_account_id':   row.get('account_id') or '',
        'ad_account_name': row.get('account_name') or acct_name,
        'campaign_id':     row.get('campaign_id'),
        'campaign_name':   row.get('campaign_name'),
        'adset_id':        row.get('adset_id'),
        'adset_name':      row.get('adset_name'),
        'ad_id':           ad_id,
        'ad_name':         row.get('ad_name'),
        'date_start':      row.get('date_start'),
        'date_end':        row.get('date_stop') or row.get('date_start'),
        'spend':           float(row.get('spend', 0) or 0),
        'impressions':     int(row.get('impressions', 0) or 0),
        'clicks':          int(row.get('clicks', 0) or 0),
        'conversions':     conv,
        'cpc':             float(row.get('cpc', 0) or 0),
        'cpm':             float(row.get('cpm', 0) or 0),
        'ctr':             float(row.get('ctr', 0) or 0),
        'currency':        row.get('account_currency') or acct_currency,
        'utm_source':      utm.get('utm_source'),
        'utm_medium':      utm.get('utm_medium'),
        'utm_campaign':    utm.get('utm_campaign'),
        'utm_term':        utm.get('utm_term'),
        'utm_content':     utm.get('utm_content'),
        'raw_data':        row,
    }


# ===== SUPABASE =====
def upsert_ads(rows):
    """Bulk upsert with batching."""
    if not rows:
        log('  ⚠ no rows to upsert')
        return 0
    total = 0
    url = f'{SB_URL}/rest/v1/dashboard_ads_data?on_conflict=ad_account_id,campaign_id,adset_id,ad_id,date_start,date_end'
    for i in range(0, len(rows), BATCH):
        chunk = rows[i:i + BATCH]
        r = requests.post(url, headers=HEADERS_SB, json=chunk, timeout=120)
        if not r.ok:
            log(f'  ❌ supabase {r.status_code}: {r.text[:300]}')
            r.raise_for_status()
        total += len(chunk)
        log(f'  ✓ upserted {total}/{len(rows)}')
    return total


def update_sync_meta(rows_count, since, until):
    """Update dashboard_settings with last sync timestamp."""
    url = f'{SB_URL}/rest/v1/dashboard_settings?on_conflict=key'
    now = datetime.now(timezone.utc).isoformat()
    payload = [
        {'key': 'etl_fbads_last_sync', 'value': now, 'updated_at': now},
        {'key': 'etl_fbads_last_count', 'value': str(rows_count), 'updated_at': now},
        {'key': 'etl_fbads_last_range', 'value': f'{since}..{until}', 'updated_at': now},
    ]
    requests.post(url, headers=HEADERS_SB, json=payload, timeout=30)


# ===== MAIN =====
def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--mode', default='incremental',
                        choices=['incremental', 'initial', 'range'])
    parser.add_argument('--since', help='YYYY-MM-DD (for --mode=range)')
    parser.add_argument('--until', help='YYYY-MM-DD (for --mode=range)')
    args = parser.parse_args()

    if not FB_TOKEN:
        log('❌ FB_ACCESS_TOKEN не задано')
        sys.exit(1)
    if not FB_ACCOUNTS:
        log('❌ FB_AD_ACCOUNT_IDS не задано')
        sys.exit(1)
    if not SB_KEY:
        log('❌ SUPABASE_SERVICE_ROLE_KEY не задано')
        sys.exit(1)

    today = datetime.now(timezone.utc).date()
    if args.mode == 'initial':
        since = (today - timedelta(days=30)).isoformat()
        until = today.isoformat()
    elif args.mode == 'range':
        if not args.since or not args.until:
            log('❌ --mode=range потребує --since і --until')
            sys.exit(1)
        since, until = args.since, args.until
    else:  # incremental
        since = (today - timedelta(days=1)).isoformat()
        until = today.isoformat()

    log(f'🚀 FB Ads ETL — mode={args.mode}, range {since}..{until}')
    log(f'   {len(FB_ACCOUNTS)} ad account(s): {", ".join(FB_ACCOUNTS)}')

    # 07.06.2026: завантажуємо mapping ad_account_id → utm_term (виконавець)
    # для fallback коли FB API не повертає URL з utm_term у creative.
    executor_map = {}
    try:
        r = requests.get(f'{SB_URL}/rest/v1/ads_account_to_executor?select=ad_account_id,executor_utm_term',
                         headers=HEADERS_SB, timeout=30)
        if r.ok:
            for row in r.json():
                executor_map[row['ad_account_id']] = row['executor_utm_term']
            log(f'   📋 executor_map loaded: {len(executor_map)} entries')
    except Exception as e:
        log(f'   ⚠ executor_map load failed: {e}')

    total = 0
    for raw_acct in FB_ACCOUNTS:
        acct = normalize_account(raw_acct)
        log(f'\n📊 {acct}')
        try:
            name, currency = get_account_info(acct)
            log(f'   Name: {name}, Currency: {currency}')
        except Exception as e:
            log(f'   ❌ fetch account info: {e}')
            continue

        log(f'   Fetching ad link URLs...')
        try:
            link_map = get_ad_link_urls(acct)
            log(f'   {len(link_map)} ads with link URLs')
        except Exception as e:
            log(f'   ⚠ link URLs failed: {e}')
            link_map = {}

        log(f'   Fetching insights...')
        try:
            raw_rows = get_insights(acct, since, until)
            log(f'   {len(raw_rows)} insight rows')
        except Exception as e:
            log(f'   ❌ insights failed: {e}')
            continue

        rows = [transform_row(r, link_map, name, currency, executor_map) for r in raw_rows]
        upserted = upsert_ads(rows)
        total += upserted

    update_sync_meta(total, since, until)
    log(f'\n✅ DONE — upserted {total} rows total across {len(FB_ACCOUNTS)} account(s)')


if __name__ == '__main__':
    main()
