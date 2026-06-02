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


def get_ad_link_urls(acct):
    """
    Fetch link URLs per ad (for UTM extraction).
    Returns: { ad_id: link_url }
    """
    links = {}
    params = {
        'fields': 'id,creative{object_story_spec,asset_feed_spec,effective_object_story_id,link_url,template_url}',
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
            # Strategy 1: creative.link_url
            url = creative.get('link_url') or creative.get('template_url')
            # Strategy 2: object_story_spec.link_data.link
            if not url:
                spec = creative.get('object_story_spec') or {}
                link_data = spec.get('link_data') or {}
                url = link_data.get('link')
            # Strategy 3: video_data.call_to_action.value.link
            if not url:
                spec = creative.get('object_story_spec') or {}
                vd = spec.get('video_data') or {}
                cta = vd.get('call_to_action') or {}
                url = (cta.get('value') or {}).get('link')
            if url:
                links[ad['id']] = url
        # Paginate
        paging = data.get('paging') or {}
        next_url = paging.get('next')
        if not next_url:
            break
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


def transform_row(row, link_map, acct_name, acct_currency):
    """FB insight row → dashboard_ads_data row."""
    ad_id = row.get('ad_id') or ''
    link_url = link_map.get(ad_id)
    utm = extract_utm_from_url(link_url)

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

        rows = [transform_row(r, link_map, name, currency) for r in raw_rows]
        upserted = upsert_ads(rows)
        total += upserted

    update_sync_meta(total, since, until)
    log(f'\n✅ DONE — upserted {total} rows total across {len(FB_ACCOUNTS)} account(s)')


if __name__ == '__main__':
    main()
