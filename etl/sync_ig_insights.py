#!/usr/bin/env python3
"""
ETL Instagram Graph API (органіка) -> Supabase dashboard_ig_* таблиці.

Повна органічна картина IG: тренд підписників, охоплення, залученість акаунта
+ метрики по кожному посту/reels (likes, comments, reach, saved, shares, views, ER).

Той самий патерн що sync_fb_ads.py:
  - System User long-lived токен (той самий FB_ACCESS_TOKEN), але з IG-скоупами:
    instagram_basic, instagram_manage_insights, pages_read_engagement,
    pages_show_list, business_management.
  - Upsert у Supabase через PostgREST (SERVICE_ROLE_KEY, merge-duplicates).

Env vars:
  FB_ACCESS_TOKEN            — System User токен (з IG-скоупами)
  IG_USER_ID                 — ID Instagram Business account (17-значний). Якщо не задано —
                               скрипт спробує знайти через сторінки (--mode=discover показує всі).
  SUPABASE_URL
  SUPABASE_SERVICE_ROLE_KEY
  IG_MEDIA_LOOKBACK_DAYS     — опц., глибина по медіа (дефолт 90)

CLI:
  --mode=incremental  (default) — акаунт за 3 дні + медіа за lookback
  --mode=initial                — акаунт за 30 днів + медіа за lookback
  --mode=discover               — лише вивести знайдені IG Business акаунти (id + username) і вийти
  --mode=range --since=YYYY-MM-DD --until=YYYY-MM-DD
"""
import os, sys, json, argparse, time
from datetime import datetime, timedelta, timezone
import requests

# ===== CONFIG =====
FB_TOKEN = os.getenv('FB_ACCESS_TOKEN', '')
IG_USER_ID = os.getenv('IG_USER_ID', '').strip()
FB_API_VERSION = os.getenv('FB_API_VERSION', 'v21.0')
LOOKBACK = int(os.getenv('IG_MEDIA_LOOKBACK_DAYS', '90'))

SB_URL = os.getenv('SUPABASE_URL', 'https://wotghlaehnvxyeacznvv.supabase.co').rstrip('/')
SB_KEY = os.getenv('SUPABASE_SERVICE_ROLE_KEY', '')

HEADERS_SB = {
    'apikey': SB_KEY,
    'Authorization': f'Bearer {SB_KEY}',
    'Content-Type': 'application/json',
    'Prefer': 'resolution=merge-duplicates,return=minimal',
}
BATCH = 100


def log(msg):
    ts = datetime.now(timezone.utc).strftime('%H:%M:%S')
    print(f'[{ts}] {msg}', flush=True)


def _num(v):
    try:
        return float(v)
    except Exception:
        return 0.0


def _int(v):
    try:
        return int(_num(v))
    except Exception:
        return None


# ===== IG / FB Graph =====
def fb_get(path, params=None):
    url = f'https://graph.facebook.com/{FB_API_VERSION}/{path}'
    params = dict(params or {})
    params['access_token'] = FB_TOKEN
    for attempt in range(5):
        try:
            r = requests.get(url, params=params, timeout=90)
        except Exception as e:
            log(f'  ⚠ net exc: {e}')
            time.sleep(2 * (attempt + 1))
            continue
        if r.status_code == 200:
            return r.json()
        # throttling
        if r.status_code in (4, 17, 32, 613) or 'rate limit' in r.text.lower() or 'usage' in r.text.lower():
            wait = 2 ** attempt * 5
            log(f'  ⏳ rate limit, sleep {wait}s...')
            time.sleep(wait)
            continue
        log(f'  ⚠ FB {r.status_code}: {r.text[:250]}')
        if r.status_code in (400, 403):
            return None
    return None


def discover_ig_accounts():
    """Знайти всі IG Business акаунти, привʼязані до сторінок токена."""
    out = []
    data = fb_get('me/accounts', {'fields': 'name,instagram_business_account{id,username,followers_count}', 'limit': 100})
    for page in (data or {}).get('data', []):
        iba = page.get('instagram_business_account')
        if iba:
            out.append({'page': page.get('name'), 'ig_user_id': iba.get('id'),
                        'username': iba.get('username'), 'followers': iba.get('followers_count')})
    return out


def resolve_ig_user_id():
    if IG_USER_ID:
        return IG_USER_ID
    accts = discover_ig_accounts()
    if len(accts) == 1:
        log(f'  ℹ IG_USER_ID не задано, знайдено один акаунт: {accts[0]}')
        return accts[0]['ig_user_id']
    if accts:
        log('  ⚠ IG_USER_ID не задано, знайдено кілька акаунтів — задай IG_USER_ID secret:')
        for a in accts:
            log(f'      {a["ig_user_id"]}  @{a.get("username")}  ({a.get("page")})')
    return None


# ===== ACCOUNT-LEVEL =====
def account_snapshot(ig_id):
    """Поточний знімок: підписники, к-сть медіа, підписки, username."""
    d = fb_get(ig_id, {'fields': 'username,followers_count,media_count,follows_count'}) or {}
    return {
        'username': d.get('username'),
        'followers_count': _int(d.get('followers_count')),
        'media_count': _int(d.get('media_count')),
        'follows_count': _int(d.get('follows_count')),
    }


def _series(ig_id, metric, since, until):
    """Часовий ряд денної метрики -> {date: value}. Стійко до зміни метрик API."""
    params = {'metric': metric, 'period': 'day', 'since': since, 'until': until}
    d = fb_get(f'{ig_id}/insights', params)
    out = {}
    if not d or 'data' not in d:
        return out
    for m in d['data']:
        for v in m.get('values', []):
            et = (v.get('end_time') or '')[:10]
            if et:
                out[et] = _int(v.get('value'))
    return out


def _total_value(ig_id, metric, since, until):
    """Сумарне значення метрики за вікно (metric_type=total_value)."""
    params = {'metric': metric, 'period': 'day', 'metric_type': 'total_value',
              'since': since, 'until': until}
    d = fb_get(f'{ig_id}/insights', params)
    out = {}
    if not d or 'data' not in d:
        return out
    for m in d['data']:
        tv = (m.get('total_value') or {}).get('value')
        out[m.get('name')] = _int(tv)
    return out


def build_account_rows(ig_id, snap, since, until):
    """Один рядок на дату. reach — часовий ряд; інші тотали — атрибутуються на until."""
    reach = _series(ig_id, 'reach', since, until)
    totals = {}
    for metric in ('accounts_engaged', 'total_interactions', 'profile_views', 'website_clicks'):
        try:
            totals.update(_total_value(ig_id, metric, since, until))
        except Exception as e:
            log(f'  ⚠ total_value {metric}: {e}')
    rows = []
    dates = set(reach.keys())
    until_d = until
    dates.add(until_d)
    for d in sorted(dates):
        row = {'ig_user_id': ig_id, 'date': d, 'reach': reach.get(d)}
        if d == until_d:
            row.update({
                'username': snap.get('username'),
                'followers_count': snap.get('followers_count'),
                'media_count': snap.get('media_count'),
                'follows_count': snap.get('follows_count'),
                'profile_views': totals.get('profile_views'),
                'website_clicks': totals.get('website_clicks'),
                'accounts_engaged': totals.get('accounts_engaged'),
                'total_interactions': totals.get('total_interactions'),
                'raw_data': {'snapshot': snap, 'totals': totals},
            })
        rows.append(row)
    return rows


# ===== MEDIA-LEVEL =====
MEDIA_FIELDS = ('id,caption,media_type,media_product_type,permalink,timestamp,'
                'like_count,comments_count')
MEDIA_METRICS = ('reach', 'saved', 'shares', 'total_interactions', 'views', 'likes', 'comments')


def fetch_media(ig_id, since_ts):
    """Список медіа новіше за since_ts (datetime). Пагінація."""
    out = []
    params = {'fields': MEDIA_FIELDS, 'limit': 50}
    data = fb_get(f'{ig_id}/media', params)
    stop = False
    while data and 'data' in data and not stop:
        for m in data['data']:
            ts = m.get('timestamp')
            try:
                tsd = datetime.strptime(ts[:19], '%Y-%m-%dT%H:%M:%S').replace(tzinfo=timezone.utc)
            except Exception:
                tsd = None
            if tsd and tsd < since_ts:
                stop = True
                break
            out.append(m)
        if stop:
            break
        nxt = (data.get('paging') or {}).get('next')
        if not nxt or len(out) > 500:
            break
        try:
            data = requests.get(nxt, timeout=90).json()
        except Exception:
            break
    return out


def media_insights(media_id):
    """Метрики поста. Стійко: пробуємо повний набір, на 400 звужуємо."""
    for metrics in (MEDIA_METRICS, ('reach', 'total_interactions', 'saved', 'shares'), ('reach',)):
        d = fb_get(f'{media_id}/insights', {'metric': ','.join(metrics)})
        if d and 'data' in d:
            res = {}
            for m in d['data']:
                res[m.get('name')] = _int((m.get('values') or [{}])[0].get('value'))
            return res
    return {}


def build_media_rows(ig_id, media_list):
    rows = []
    for m in media_list:
        ins = media_insights(m['id'])
        time.sleep(0.15)
        reach = ins.get('reach')
        inter = ins.get('total_interactions')
        if inter is None:
            inter = (_int(m.get('like_count')) or 0) + (_int(m.get('comments_count')) or 0) \
                    + (ins.get('saved') or 0) + (ins.get('shares') or 0)
        er = round(inter / reach * 100, 2) if (reach and inter is not None) else None
        rows.append({
            'media_id': m['id'],
            'ig_user_id': ig_id,
            'caption': (m.get('caption') or '')[:2000],
            'media_type': m.get('media_type'),
            'media_product_type': m.get('media_product_type'),
            'permalink': m.get('permalink'),
            'published_at': m.get('timestamp'),
            'like_count': _int(m.get('like_count')),
            'comments_count': _int(m.get('comments_count')),
            'reach': reach,
            'saved': ins.get('saved'),
            'shares': ins.get('shares'),
            'views': ins.get('views'),
            'total_interactions': inter,
            'engagement_rate': er,
            'raw_data': {'media': m, 'insights': ins},
        })
    return rows


# ===== SUPABASE =====
def upsert(table, rows, on_conflict):
    if not rows:
        log(f'  ⚠ {table}: нема рядків')
        return 0
    # PostgREST bulk insert вимагає однаковий набір ключів у всіх обʼєктах
    allkeys = set()
    for r in rows:
        allkeys.update(r.keys())
    for r in rows:
        for k in allkeys:
            r.setdefault(k, None)
    url = f'{SB_URL}/rest/v1/{table}?on_conflict={on_conflict}'
    total = 0
    for i in range(0, len(rows), BATCH):
        chunk = rows[i:i + BATCH]
        r = requests.post(url, headers=HEADERS_SB, json=chunk, timeout=120)
        if not r.ok:
            log(f'  ❌ supabase {table} {r.status_code}: {r.text[:300]}')
            r.raise_for_status()
        total += len(chunk)
    log(f'  ✓ {table}: upserted {total}')
    return total


def update_sync_meta(acct_rows, media_rows, since, until):
    url = f'{SB_URL}/rest/v1/dashboard_settings?on_conflict=key'
    now = datetime.now(timezone.utc).isoformat()
    payload = [
        {'key': 'etl_ig_last_sync', 'value': now, 'updated_at': now},
        {'key': 'etl_ig_last_count', 'value': f'{acct_rows}+{media_rows}', 'updated_at': now},
        {'key': 'etl_ig_last_range', 'value': f'{since}..{until}', 'updated_at': now},
    ]
    try:
        requests.post(url, headers=HEADERS_SB, json=payload, timeout=30)
    except Exception as e:
        log(f'  ⚠ sync_meta: {e}')


# ===== MAIN =====
def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--mode', default='incremental',
                    choices=['incremental', 'initial', 'discover', 'range'])
    ap.add_argument('--since')
    ap.add_argument('--until')
    args = ap.parse_args()

    if not FB_TOKEN:
        log('❌ FB_ACCESS_TOKEN не задано'); sys.exit(1)

    if args.mode == 'discover':
        accts = discover_ig_accounts()
        if not accts:
            log('⚠ IG Business акаунтів не знайдено. Перевір скоупи токена (instagram_basic, pages_show_list) та звʼязок IG↔Page.')
        for a in accts:
            log(f'  IG_USER_ID={a["ig_user_id"]}  @{a.get("username")}  followers={a.get("followers")}  page="{a.get("page")}"')
        return

    if not SB_KEY:
        log('❌ SUPABASE_SERVICE_ROLE_KEY не задано'); sys.exit(1)

    ig_id = resolve_ig_user_id()
    if not ig_id:
        log('❌ Не вдалося визначити IG_USER_ID. Запусти --mode=discover.'); sys.exit(1)

    today = datetime.now(timezone.utc).date()
    if args.mode == 'initial':
        since = (today - timedelta(days=30)).isoformat(); until = today.isoformat()
    elif args.mode == 'range':
        if not (args.since and args.until):
            log('❌ --mode=range потребує --since і --until'); sys.exit(1)
        since, until = args.since, args.until
    else:
        since = (today - timedelta(days=3)).isoformat(); until = today.isoformat()

    log(f'🚀 IG ETL — mode={args.mode}, акаунт {ig_id}, range {since}..{until}, media lookback {LOOKBACK}d')

    snap = account_snapshot(ig_id)
    log(f'  ℹ @{snap.get("username")} · followers={snap.get("followers_count")} · media={snap.get("media_count")}')

    acct_rows = build_account_rows(ig_id, snap, since, until)
    n_acct = upsert('dashboard_ig_account_daily', acct_rows, 'ig_user_id,date')

    since_ts = datetime.now(timezone.utc) - timedelta(days=LOOKBACK)
    media_list = fetch_media(ig_id, since_ts)
    log(f'  ℹ медіа за {LOOKBACK}д: {len(media_list)}')
    media_rows = build_media_rows(ig_id, media_list)
    n_media = upsert('dashboard_ig_media', media_rows, 'media_id')

    update_sync_meta(n_acct, n_media, since, until)
    log(f'✅ DONE — акаунт {n_acct} рядків, медіа {n_media} рядків')


if __name__ == '__main__':
    main()
