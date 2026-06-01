#!/usr/bin/env python3
"""
ETL MySQL → Supabase для DreamCar Dashboard.

READ-ONLY у MySQL (тільки SELECT), upsert у Supabase dashboard_* таблиці.

Modes:
  --mode=initial      — повна копія (з нуля, всі 205k+ deals)
  --mode=incremental  — тільки нові/оновлені після last_sync (default)
  --limit=N           — обмежити кількість для тесту

Env vars:
  MYSQL_HOST, MYSQL_PORT, MYSQL_DB, MYSQL_USER, MYSQL_PASS
  SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY
"""
import os, sys, json, argparse
from datetime import datetime, timezone
import pymysql
import requests

# ===== CONFIG =====
MYSQL_HOST = os.getenv('MYSQL_HOST', 'fincheck.mysql.network')
MYSQL_PORT = int(os.getenv('MYSQL_PORT', 10145))
MYSQL_DB   = os.getenv('MYSQL_DB',   'dreamcar_utm')
MYSQL_USER = os.getenv('MYSQL_USER', 'dreamcar_utm')
MYSQL_PASS = os.getenv('MYSQL_PASS', '')

SB_URL = os.getenv('SUPABASE_URL', 'https://wotghlaehnvxyeacznvv.supabase.co')
SB_KEY = os.getenv('SUPABASE_SERVICE_ROLE_KEY', '')

BATCH = 500
HEADERS = {
    'apikey': SB_KEY,
    'Authorization': f'Bearer {SB_KEY}',
    'Content-Type': 'application/json',
    'Prefer': 'resolution=merge-duplicates,return=minimal',
}

# ===== STATUS MAPPING =====
STATUS_MAP = {
    'lead': 'new',
    'paid': 'pay',
    'failed': 'fail',
    'pending': 'pending',
}

def log(msg):
    ts = datetime.now(timezone.utc).strftime('%H:%M:%S')
    print(f'[{ts}] {msg}', flush=True)


def mysql_conn():
    return pymysql.connect(
        host=MYSQL_HOST, port=MYSQL_PORT,
        user=MYSQL_USER, password=MYSQL_PASS,
        database=MYSQL_DB, connect_timeout=15,
        cursorclass=pymysql.cursors.DictCursor,
        charset='utf8mb4',
    )


def supabase_upsert(table, rows, on_conflict=None):
    """Upsert batch into Supabase via PostgREST."""
    if not rows:
        return 0
    url = f'{SB_URL}/rest/v1/{table}'
    params = {}
    if on_conflict:
        params['on_conflict'] = on_conflict
    # Convert datetime/Decimal to JSON-friendly
    clean = []
    for r in rows:
        c = {}
        for k, v in r.items():
            if isinstance(v, datetime):
                c[k] = v.isoformat()
            elif hasattr(v, 'isoformat'):
                c[k] = v.isoformat()
            elif isinstance(v, (bytes, bytearray)):
                c[k] = v.decode('utf-8', errors='replace')
            else:
                # Decimal → float
                try:
                    if str(type(v)) == "<class 'decimal.Decimal'>":
                        c[k] = float(v)
                        continue
                except Exception:
                    pass
                c[k] = v
        clean.append(c)
    r = requests.post(url, headers=HEADERS, params=params, json=clean, timeout=60)
    if r.status_code not in (200, 201, 204):
        raise RuntimeError(f'Supabase upsert failed ({r.status_code}): {r.text[:500]}')
    return len(clean)


def get_last_sync(key):
    url = f'{SB_URL}/rest/v1/dashboard_settings'
    r = requests.get(url, headers=HEADERS, params={'key': f'eq.{key}', 'select': 'value'}, timeout=10)
    if r.status_code == 200 and r.json():
        return r.json()[0].get('value')
    return None


def set_last_sync(key, value):
    url = f'{SB_URL}/rest/v1/dashboard_settings'
    body = [{'key': key, 'value': value, 'description': f'ETL last_sync for {key}'}]
    r = requests.post(url, headers={**HEADERS, 'Prefer': 'resolution=merge-duplicates'},
                      params={'on_conflict': 'key'}, json=body, timeout=10)
    if r.status_code not in (200, 201, 204):
        log(f'WARN: failed to save last_sync: {r.status_code} {r.text[:200]}')


# ===== TRANSFORM =====
def map_deal(row):
    deal_type = row.get('deal_type') or 'lead'
    status = STATUS_MAP.get(deal_type, 'pending')
    if row.get('is_paid'): status = 'pay'
    elif row.get('is_failed'): status = 'fail'
    elif row.get('is_pending'): status = 'pending'

    return {
        'sendpulse_deal_id': str(row['deal_id']) if row.get('deal_id') else None,
        'status': status,
        'amount': float(row['amount_uah']) if row.get('amount_uah') else (
            float(row['amount']) if row.get('amount') else None),
        'currency': row.get('deal_currency') or 'UAH',
        'project': row.get('deal_project'),
        'utm_source': row.get('utm_source'),
        'utm_medium': row.get('utm_medium'),
        'utm_campaign': row.get('utm_campaign'),
        'utm_term': row.get('utm_term'),
        'utm_content': row.get('utm_content'),
        'customer_email': row.get('email'),
        'customer_phone': row.get('phone'),
        'customer_type': row.get('customer_type'),
        'tariff': row.get('tariff'),
        'pay_provider': row.get('pay_provider'),
        'wc_order_id': int(row['wc_order_id']) if row.get('wc_order_id') else None,
        'raw_payload': {k: (str(v) if not isinstance(v, (int, float, str, bool, type(None))) else v) for k, v in row.items()},
        'created_at': row['created_at'].isoformat() if row.get('created_at') else None,
        'updated_at': row['updated_at'].isoformat() if row.get('updated_at') else None,
        'paid_at': row['deal_updated_at'].isoformat() if (status == 'pay' and row.get('deal_updated_at')) else None,
        'failed_at': row['deal_updated_at'].isoformat() if (status == 'fail' and row.get('deal_updated_at')) else None,
    }


def map_webhook(row):
    return {
        'source': 'sendpulse' if row.get('webhook_type') == 'crm' else 'make_com',
        'event_type': row.get('event_type'),
        'method': 'POST',
        'payload': {'raw': (row.get('raw_data') or '')[:5000], 'processed': row.get('processed_data')},
        'response': None,
        'status_code': 200 if row.get('success') else 500,
        'error_message': row.get('error_message'),
        'processing_ms': int(float(row['processing_time']) * 1000) if row.get('processing_time') else None,
        'created_at': row['created_at'].isoformat() if row.get('created_at') else None,
    }


def map_ad(row):
    spend = float(row['spend']) if row.get('spend') else 0
    clicks = int(row['clicks']) if row.get('clicks') else 0
    return {
        'ad_account_id': row.get('account_id'),
        'ad_account_name': row.get('account_name'),
        'campaign_id': row.get('campaign_id'),
        'campaign_name': row.get('campaign_name'),
        'adset_id': row.get('adset_id'),
        'adset_name': row.get('adset_name'),
        'ad_id': row.get('ad_id'),
        'ad_name': row.get('ad_name'),
        'date_start': row['date_start'].isoformat() if row.get('date_start') else None,
        'date_end': row['date_stop'].isoformat() if row.get('date_stop') else None,
        'spend': spend,
        'impressions': int(row['impressions']) if row.get('impressions') else 0,
        'clicks': clicks,
        'cpc': (spend / clicks) if clicks > 0 else None,
        'cpm': float(row['cpm']) if row.get('cpm') else None,
        'ctr': float(row['ctr']) if row.get('ctr') else None,
        'currency': row.get('account_currency') or 'UAH',
        'utm_source': row.get('utm_source'),
        'utm_medium': row.get('utm_medium'),
        'utm_campaign': row.get('utm_campaign'),
        'utm_term': row.get('utm_term'),
        'utm_content': row.get('utm_content'),
        'raw_data': {k: (str(v) if not isinstance(v, (int, float, str, bool, type(None))) else v) for k, v in row.items()},
    }


# ===== SYNC FUNCTIONS =====
def sync_deals(mode, limit=None):
    conn = mysql_conn()
    cur = conn.cursor()
    where = ''
    params = []
    if mode == 'incremental':
        last = get_last_sync('etl_deals_last_sync')
        if last:
            where = 'WHERE updated_at > %s'
            params.append(last)
            log(f'[deals] incremental since {last}')
        else:
            log('[deals] no last_sync, treating as initial')

    cur.execute(f'SELECT COUNT(*) as c FROM crm_deals {where}', params)
    total = cur.fetchone()['c']
    log(f'[deals] {total} rows to sync')
    if limit:
        total = min(total, limit)
        log(f'[deals] LIMIT {limit} for test')

    cur.execute(f'SELECT MAX(updated_at) as m FROM crm_deals {where}', params)
    max_updated = cur.fetchone()['m']

    offset = 0
    pushed = 0
    while offset < total:
        cap = min(BATCH, total - offset)
        cur.execute(f'SELECT * FROM crm_deals {where} ORDER BY updated_at ASC LIMIT %s OFFSET %s', params + [cap, offset])
        rows = cur.fetchall()
        if not rows: break
        mapped = [m for m in (map_deal(r) for r in rows) if m['sendpulse_deal_id']]
        n = supabase_upsert('dashboard_deals', mapped, on_conflict='sendpulse_deal_id')
        pushed += n
        offset += len(rows)
        log(f'[deals] {offset}/{total} (pushed {pushed})')
    conn.close()
    if max_updated:
        set_last_sync('etl_deals_last_sync', max_updated.isoformat())
    log(f'[deals] ✓ done, {pushed} pushed')
    return pushed


def sync_webhooks(mode, limit=None):
    conn = mysql_conn()
    cur = conn.cursor()
    where = ''
    params = []
    if mode == 'incremental':
        last = get_last_sync('etl_webhooks_last_sync')
        if last:
            where = 'WHERE created_at > %s'
            params.append(last)
    cur.execute(f'SELECT COUNT(*) as c FROM webhook_log {where}', params)
    total = cur.fetchone()['c']
    if limit: total = min(total, limit)
    log(f'[webhooks] {total} rows to sync')

    cur.execute(f'SELECT MAX(created_at) as m FROM webhook_log {where}', params)
    max_created = cur.fetchone()['m']

    offset = 0; pushed = 0
    while offset < total:
        cap = min(BATCH, total - offset)
        cur.execute(f'SELECT id,webhook_type,event_type,LEFT(raw_data,5000) as raw_data,processed_data,deal_id,success,error_message,processing_time,created_at FROM webhook_log {where} ORDER BY created_at ASC LIMIT %s OFFSET %s', params + [cap, offset])
        rows = cur.fetchall()
        if not rows: break
        mapped = [map_webhook(r) for r in rows]
        n = supabase_upsert('dashboard_webhooks', mapped)
        pushed += n
        offset += len(rows)
        log(f'[webhooks] {offset}/{total}')
    conn.close()
    if max_created:
        set_last_sync('etl_webhooks_last_sync', max_created.isoformat())
    log(f'[webhooks] ✓ done, {pushed} pushed')
    return pushed


def sync_ads(mode, limit=None):
    conn = mysql_conn()
    cur = conn.cursor()
    where = ''
    params = []
    if mode == 'incremental':
        last = get_last_sync('etl_ads_last_sync')
        if last:
            where = 'WHERE updated_at > %s'
            params.append(last)
    cur.execute(f'SELECT COUNT(*) as c FROM ads_data {where}', params)
    total = cur.fetchone()['c']
    if limit: total = min(total, limit)
    log(f'[ads] {total} rows to sync')

    cur.execute(f'SELECT MAX(updated_at) as m FROM ads_data {where}', params)
    max_updated = cur.fetchone()['m']

    offset = 0; pushed = 0
    while offset < total:
        cap = min(BATCH, total - offset)
        cur.execute(f'SELECT * FROM ads_data {where} ORDER BY updated_at ASC LIMIT %s OFFSET %s', params + [cap, offset])
        rows = cur.fetchall()
        if not rows: break
        mapped = [m for m in (map_ad(r) for r in rows) if m['ad_account_id'] or m['campaign_id']]
        n = supabase_upsert('dashboard_ads_data', mapped, on_conflict='ad_account_id,campaign_id,adset_id,ad_id,date_start,date_end')
        pushed += n
        offset += len(rows)
        log(f'[ads] {offset}/{total}')
    conn.close()
    if max_updated:
        set_last_sync('etl_ads_last_sync', max_updated.isoformat())
    log(f'[ads] ✓ done, {pushed} pushed')
    return pushed


# ===== MAIN =====
def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--mode', choices=['initial', 'incremental'], default='incremental')
    ap.add_argument('--limit', type=int, default=None)
    ap.add_argument('--only', choices=['deals', 'webhooks', 'ads', 'all'], default='all')
    args = ap.parse_args()

    if not SB_KEY:
        log('ERROR: SUPABASE_SERVICE_ROLE_KEY env not set')
        sys.exit(1)
    if not MYSQL_PASS:
        log('ERROR: MYSQL_PASS env not set')
        sys.exit(1)

    log(f'=== ETL start: mode={args.mode}, only={args.only}, limit={args.limit} ===')
    results = {}
    if args.only in ('deals', 'all'):
        results['deals'] = sync_deals(args.mode, args.limit)
    if args.only in ('webhooks', 'all'):
        results['webhooks'] = sync_webhooks(args.mode, args.limit)
    if args.only in ('ads', 'all'):
        results['ads'] = sync_ads(args.mode, args.limit)
    log(f'=== ETL done: {results} ===')


if __name__ == '__main__':
    main()
