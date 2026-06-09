#!/usr/bin/env python3
"""
ETL SendPulse CRM → Supabase dashboard_deals.

09.06.2026 #202: Phase 2 ETL replacing Make.com middleman flow.
Old: SendPulse → Make.com → MySQL → sync_mysql_to_supabase.py → Supabase
New: SendPulse REST API → THIS SCRIPT → Supabase (direct)

READ-ONLY у SendPulse (тільки GET /crm/v1/deals).
Incremental sync через updated_at marker у dashboard_settings.

Env vars (GH Actions secrets):
  SP_USER_ID, SP_USER_SECRET — SendPulse API credentials з https://login.sendpulse.com/settings/#api
  SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY
"""
import os, sys, json, argparse, time
from datetime import datetime, timezone, timedelta
try:
    from zoneinfo import ZoneInfo
except ImportError:
    from backports.zoneinfo import ZoneInfo
import requests

KYIV_TZ = ZoneInfo('Europe/Kyiv')
UTC_TZ = ZoneInfo('UTC')

SP_USER_ID = os.getenv('SP_USER_ID', '')
SP_USER_SECRET = os.getenv('SP_USER_SECRET', '')
SP_API = 'https://api.sendpulse.com'

SB_URL = os.getenv('SUPABASE_URL', 'https://wotghlaehnvxyeacznvv.supabase.co')
SB_KEY = os.getenv('SUPABASE_SERVICE_ROLE_KEY', '')

BATCH = 100  # SendPulse default page size
SB_HEADERS = {
    'apikey': SB_KEY,
    'Authorization': f'Bearer {SB_KEY}',
    'Content-Type': 'application/json',
    'Prefer': 'resolution=merge-duplicates,return=minimal',
}


def log(msg):
    ts = datetime.now(timezone.utc).strftime('%H:%M:%S')
    print(f'[{ts}] {msg}', flush=True)


def sp_access_token():
    """OAuth client_credentials grant — SendPulse REST API auth."""
    r = requests.post(f'{SP_API}/oauth/access_token', json={
        'grant_type': 'client_credentials',
        'client_id': SP_USER_ID,
        'client_secret': SP_USER_SECRET,
    }, timeout=15)
    r.raise_for_status()
    return r.json()['access_token']


def sp_fetch_deals(token, updated_since_iso=None, limit=BATCH, offset=0):
    """GET /crm/v1/deals — incremental з filter[updated_after]."""
    params = {'limit': limit, 'offset': offset, 'order_by[updated_at]': 'asc'}
    if updated_since_iso:
        params['filter[updated_at][from]'] = updated_since_iso
    r = requests.get(
        f'{SP_API}/crm/v1/deals',
        headers={'Authorization': f'Bearer {token}'},
        params=params, timeout=30
    )
    r.raise_for_status()
    return r.json().get('data', [])


def normalize_project(deal_name: str) -> str | None:
    """Парсимо DCI-{prefix}- → правильний project. Той самий патерн що у БД trigger."""
    if not deal_name: return None
    dn = deal_name.lower()
    if 'dci-iphone-' in dn: return 'IPHONE 17 PRO MAX'
    if 'dci-moto-' in dn:   return 'MOTORCYCLE'
    if 'dci-hummer-' in dn: return 'HUMMER H2'
    if 'dci-audi-' in dn:   return 'AUDI E-TRON'
    return None


def map_sp_to_supabase(sp_deal: dict) -> dict | None:
    """Map SendPulse deal record → dashboard_deals row."""
    deal_id = sp_deal.get('id') or sp_deal.get('deal_id')
    if not deal_id:
        return None
    # SendPulse status → наш enum
    sp_status = (sp_deal.get('status_name') or sp_deal.get('step_name') or '').lower()
    status_map = {
        'успішні': 'pay', 'успешные': 'pay', 'paid': 'pay',
        'нові': 'pending', 'новые': 'pending', 'new': 'pending',
        'провалені': 'fail', 'провальные': 'fail', 'failed': 'fail',
    }
    status = status_map.get(sp_status, 'pending')

    name = sp_deal.get('name') or sp_deal.get('deal_name', '')
    raw_project = sp_deal.get('Проект_deal') or sp_deal.get('project', '')
    project = normalize_project(name) or raw_project or None

    # Timestamps — SendPulse повертає у форматі ISO (UTC)
    created_at = sp_deal.get('created_at') or sp_deal.get('createdAt_deal')
    updated_at = sp_deal.get('updated_at') or sp_deal.get('updatedAt_deal')

    return {
        'sendpulse_deal_id': str(deal_id),
        'status': status,
        'amount': float(sp_deal.get('price', sp_deal.get('amount', 0)) or 0),
        'currency': sp_deal.get('currency', 'UAH'),
        'project': project,
        'utm_source':   sp_deal.get('utm_source_deal') or sp_deal.get('utm_source'),
        'utm_medium':   sp_deal.get('utm_medium_deal') or sp_deal.get('utm_medium'),
        'utm_campaign': sp_deal.get('utm_campaign_deal') or sp_deal.get('utm_campaign'),
        'utm_term':     sp_deal.get('utm_term_deal') or sp_deal.get('utm_term'),
        'utm_content':  sp_deal.get('utm_content_deal') or sp_deal.get('utm_content'),
        'customer_email': sp_deal.get('email') or sp_deal.get('contact_email'),
        'customer_phone': sp_deal.get('phone') or sp_deal.get('contact_phone'),
        'raw_payload': sp_deal,  # для майбутніх migrations + audit
        'created_at': created_at,
        'paid_at': sp_deal.get('paid_at') if status == 'pay' else None,
    }


def sb_upsert(table, rows):
    if not rows: return 0
    r = requests.post(
        f'{SB_URL}/rest/v1/{table}?on_conflict=sendpulse_deal_id',
        headers=SB_HEADERS, json=rows, timeout=60
    )
    if r.status_code not in (200, 201, 204):
        raise RuntimeError(f'Supabase upsert failed ({r.status_code}): {r.text[:500]}')
    return len(rows)


def get_last_sync(key='sendpulse_deals_last_updated_at'):
    r = requests.get(
        f'{SB_URL}/rest/v1/dashboard_settings',
        headers=SB_HEADERS,
        params={'key': f'eq.{key}', 'select': 'value'}, timeout=10
    )
    if r.status_code == 200 and r.json():
        return r.json()[0].get('value')
    return None


def set_last_sync(value, key='sendpulse_deals_last_updated_at'):
    requests.post(
        f'{SB_URL}/rest/v1/dashboard_settings?on_conflict=key',
        headers={**SB_HEADERS, 'Prefer': 'resolution=merge-duplicates'},
        json=[{'key': key, 'value': value}], timeout=10
    )


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--mode', choices=['incremental','initial'], default='incremental')
    ap.add_argument('--limit', type=int, default=10000)
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()

    if not SP_USER_ID or not SP_USER_SECRET:
        log('ERROR: SP_USER_ID / SP_USER_SECRET not set'); sys.exit(1)
    if not SB_KEY:
        log('ERROR: SUPABASE_SERVICE_ROLE_KEY not set'); sys.exit(1)

    log('Auth SendPulse...')
    token = sp_access_token()

    since = None
    if args.mode == 'incremental':
        since = get_last_sync()
        log(f'Incremental from updated_at >= {since or "(initial: 30 days back)"}')
        if not since:
            since = (datetime.now(UTC_TZ) - timedelta(days=30)).isoformat()

    offset = 0
    total_upserted = 0
    last_updated_seen = since
    while offset < args.limit:
        log(f'Fetch offset={offset}...')
        deals = sp_fetch_deals(token, updated_since_iso=since, limit=BATCH, offset=offset)
        if not deals:
            log(f'No more deals at offset={offset}'); break
        rows = []
        for d in deals:
            r = map_sp_to_supabase(d)
            if r: rows.append(r)
            if r and r.get('created_at'):
                if not last_updated_seen or r['created_at'] > last_updated_seen:
                    last_updated_seen = r['created_at']
        if args.dry_run:
            log(f'[DRY] would upsert {len(rows)} rows; sample: {json.dumps(rows[0] if rows else {}, ensure_ascii=False)[:200]}')
        else:
            n = sb_upsert('dashboard_deals', rows)
            total_upserted += n
            log(f'Upserted {n} (total {total_upserted})')
        offset += BATCH
        time.sleep(0.5)  # SendPulse rate limit гуманний

    if last_updated_seen and not args.dry_run:
        set_last_sync(last_updated_seen)
        log(f'Marker updated → {last_updated_seen}')

    log(f'DONE. total_upserted={total_upserted}')


if __name__ == '__main__':
    main()
