#!/usr/bin/env python3
"""
ETL Google Ads API → Supabase dashboard_ads_data (platform='google').

Дзеркало sync_fb_ads.py: ті самі колонки, той самий on_conflict, та сама
логіка витягу UTM — щоб розділ «🔑 Виконавець» (#terms) рахував Google
нарівні з Meta по utm_term.

Мапінг Google → наша схема:
  customer.id            → ad_account_id
  customer.descriptive_name → ad_account_name
  campaign.id/name       → campaign_id/campaign_name
  ad_group.id/name       → adset_id/adset_name   (у Google це ad_group)
  ad_group_ad.ad.id/name → ad_id/ad_name
  metrics.cost_micros/1e6 → spend
  segments.date          → date_start = date_end

UTM беремо з final_urls оголошення (там ?utm_source=google&utm_term=...),
з fallback на tracking_url_template.

ENV:
  GOOGLE_ADS_DEVELOPER_TOKEN   — токен розробника (Google Ads API Center)
  GOOGLE_ADS_CLIENT_ID         — OAuth client id
  GOOGLE_ADS_CLIENT_SECRET     — OAuth client secret
  GOOGLE_ADS_REFRESH_TOKEN     — OAuth refresh token
  GOOGLE_ADS_CUSTOMER_IDS      — через кому, без дефісів (напр. 1234567890)
  GOOGLE_ADS_LOGIN_CUSTOMER_ID — MCC id (опційно, якщо доступ через управляючий акаунт)
  SUPABASE_URL
  SUPABASE_SERVICE_ROLE_KEY
  SYNC_DAYS                    — глибина, дефолт 30
"""
import os
import sys
from datetime import date, timedelta
from urllib.parse import urlparse, parse_qs

import requests

API_VERSION = os.getenv('GOOGLE_ADS_API_VERSION', 'v18')
DEV_TOKEN = os.getenv('GOOGLE_ADS_DEVELOPER_TOKEN', '')
CLIENT_ID = os.getenv('GOOGLE_ADS_CLIENT_ID', '')
CLIENT_SECRET = os.getenv('GOOGLE_ADS_CLIENT_SECRET', '')
REFRESH_TOKEN = os.getenv('GOOGLE_ADS_REFRESH_TOKEN', '')
CUSTOMER_IDS = [c.strip().replace('-', '') for c in os.getenv('GOOGLE_ADS_CUSTOMER_IDS', '').split(',') if c.strip()]
LOGIN_CUSTOMER_ID = os.getenv('GOOGLE_ADS_LOGIN_CUSTOMER_ID', '').replace('-', '')

SB_URL = os.getenv('SUPABASE_URL', 'https://wotghlaehnvxyeacznvv.supabase.co')
SB_KEY = os.getenv('SUPABASE_SERVICE_ROLE_KEY', '')
SYNC_DAYS = int(os.getenv('SYNC_DAYS', '30'))

HEADERS_SB = {
    'apikey': SB_KEY,
    'Authorization': f'Bearer {SB_KEY}',
    'Content-Type': 'application/json',
    'Prefer': 'resolution=merge-duplicates,return=minimal',
}
BATCH = 100


def log(msg):
    print(msg, flush=True)


# ===== OAUTH =====
def get_access_token():
    r = requests.post('https://oauth2.googleapis.com/token', data={
        'client_id': CLIENT_ID,
        'client_secret': CLIENT_SECRET,
        'refresh_token': REFRESH_TOKEN,
        'grant_type': 'refresh_token',
    }, timeout=60)
    if not r.ok:
        log(f'❌ OAuth {r.status_code}: {r.text[:300]}')
        r.raise_for_status()
    return r.json()['access_token']


# ===== UTM =====
def extract_utm(url):
    """?utm_source=google&utm_term=fortunatos → dict. Ігнорує {macros}."""
    out = {'utm_source': None, 'utm_medium': None, 'utm_campaign': None,
           'utm_term': None, 'utm_content': None}
    if not url:
        return out
    try:
        qs = parse_qs(urlparse(url).query)
    except Exception:
        return out
    for k in out:
        v = (qs.get(k) or [None])[0]
        if v and not (v.startswith('{') and v.endswith('}')):
            out[k] = v.strip() or None
    return out


# ===== GOOGLE ADS =====
GAQL = """
SELECT
  customer.id, customer.descriptive_name, customer.currency_code,
  campaign.id, campaign.name,
  ad_group.id, ad_group.name,
  ad_group_ad.ad.id, ad_group_ad.ad.name,
  ad_group_ad.ad.final_urls,
  metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions,
  segments.date
FROM ad_group_ad
WHERE segments.date BETWEEN '{since}' AND '{until}'
  AND metrics.impressions > 0
"""


def fetch_customer(token, customer_id, since, until):
    url = f'https://googleads.googleapis.com/{API_VERSION}/customers/{customer_id}/googleAds:searchStream'
    headers = {
        'Authorization': f'Bearer {token}',
        'developer-token': DEV_TOKEN,
        'Content-Type': 'application/json',
    }
    if LOGIN_CUSTOMER_ID:
        headers['login-customer-id'] = LOGIN_CUSTOMER_ID
    body = {'query': GAQL.format(since=since, until=until)}
    r = requests.post(url, headers=headers, json=body, timeout=180)
    if not r.ok:
        log(f'  ❌ google {r.status_code}: {r.text[:400]}')
        r.raise_for_status()
    out = []
    for batch in r.json():           # searchStream → список батчів
        out.extend(batch.get('results', []))
    return out


def to_row(res):
    cust = res.get('customer', {})
    camp = res.get('campaign', {})
    grp = res.get('adGroup', {})
    ad = (res.get('adGroupAd', {}) or {}).get('ad', {})
    m = res.get('metrics', {})
    seg = res.get('segments', {})

    finals = ad.get('finalUrls') or []
    utm = extract_utm(finals[0] if finals else None)
    d = seg.get('date')

    return {
        'platform': 'google',
        'ad_account_id': str(cust.get('id', '')),
        'ad_account_name': cust.get('descriptiveName') or 'Google Ads',
        'campaign_id': str(camp.get('id', '')),
        'campaign_name': camp.get('name'),
        'adset_id': str(grp.get('id', '')),          # ad_group
        'adset_name': grp.get('name'),
        'ad_id': str(ad.get('id', '')),
        'ad_name': ad.get('name') or camp.get('name'),
        'date_start': d,
        'date_end': d,
        'spend': round(int(m.get('costMicros', 0)) / 1_000_000, 2),
        'impressions': int(m.get('impressions', 0)),
        'clicks': int(m.get('clicks', 0)),
        'conversions': float(m.get('conversions', 0) or 0),
        'currency': cust.get('currencyCode') or 'UAH',
        'raw_data': res,
        **utm,
    }


# ===== SUPABASE =====
def upsert_ads(rows):
    if not rows:
        log('  ⚠ no rows to upsert')
        return 0
    url = (f'{SB_URL}/rest/v1/dashboard_ads_data'
           '?on_conflict=ad_account_id,campaign_id,adset_id,ad_id,date_start,date_end')
    total = 0
    for i in range(0, len(rows), BATCH):
        chunk = rows[i:i + BATCH]
        r = requests.post(url, headers=HEADERS_SB, json=chunk, timeout=120)
        if not r.ok:
            log(f'  ❌ supabase {r.status_code}: {r.text[:300]}')
            r.raise_for_status()
        total += len(chunk)
    return total


def main():
    missing = [k for k, v in {
        'GOOGLE_ADS_DEVELOPER_TOKEN': DEV_TOKEN,
        'GOOGLE_ADS_CLIENT_ID': CLIENT_ID,
        'GOOGLE_ADS_CLIENT_SECRET': CLIENT_SECRET,
        'GOOGLE_ADS_REFRESH_TOKEN': REFRESH_TOKEN,
        'SUPABASE_SERVICE_ROLE_KEY': SB_KEY,
    }.items() if not v]
    if missing:
        log('❌ Missing env: ' + ', '.join(missing))
        sys.exit(1)
    if not CUSTOMER_IDS:
        log('❌ GOOGLE_ADS_CUSTOMER_IDS порожній')
        sys.exit(1)

    until = date.today()
    since = until - timedelta(days=SYNC_DAYS)
    log(f'📅 {since} → {until} · accounts: {", ".join(CUSTOMER_IDS)}')

    token = get_access_token()
    grand = 0
    for cid in CUSTOMER_IDS:
        log(f'▶ customer {cid}')
        results = fetch_customer(token, cid, since.isoformat(), until.isoformat())
        rows = [to_row(x) for x in results]
        rows = [r for r in rows if r['ad_id']]
        n = upsert_ads(rows)
        spend = round(sum(r['spend'] for r in rows), 2)
        terms = sorted({r['utm_term'] for r in rows if r['utm_term']})
        log(f'  ✅ {n} rows · spend {spend} · utm_term: {", ".join(terms) or "—"}')
        grand += n

    log(f'🏁 total upserted: {grand}')


if __name__ == '__main__':
    main()
