#!/usr/bin/env python3
"""
sync_meta_stats.py — Meta Ads аналітика по ВСІХ проєктах -> docs/meta-analytics/data.json

ІЗОЛЬОВАНО від решти дашборду:
  - НЕ створює/не змінює таблиць у Supabase (читає лише наявні RPC).
  - Пише ТІЛЬКИ docs/meta-analytics/data.json (git), не БД.
  - Окремий workflow/concurrency-group.

Джерела:
  - Проєкти: Supabase RPC dashboard_projects_with_stats (усі проєкти + дати; нові підхоплюються самі).
  - Піксель + сегменти + креативи: Meta Marketing API (акаунт DreamCar.ua UAH).
  - Реальна виручка від реклами: Supabase RPC dashboard_agg_deals_with_traffic
    (по UTM-мітках utm_source = facebook + instagram; без ретеншну/органіки).

ENV:
  FB_ACCESS_TOKEN, SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY (як у sync_fb_ads.py)
  META_ANALYTICS_ACCOUNT (опц., дефолт 4136058269783354)
"""
import os, sys, json, time
from datetime import datetime, timezone
import requests

FB_API_VERSION = 'v21.0'
FB_TOKEN = os.getenv('FB_ACCESS_TOKEN', '')
SB_URL = os.getenv('SUPABASE_URL', 'https://wotghlaehnvxyeacznvv.supabase.co').rstrip('/')
SB_KEY = os.getenv('SUPABASE_SERVICE_ROLE_KEY', '') or os.getenv('SUPABASE_ANON_KEY', '')
ACCOUNT = os.getenv('META_ANALYTICS_ACCOUNT', '4136058269783354').replace('act_', '')
OUT_PATH = os.path.join(os.path.dirname(__file__), '..', 'docs', 'meta-analytics', 'data.json')

# проєкти-аліаси dashboard project code -> чи поточний цикл (для підсвітки)
AD_SOURCE_MARKS = ('facebook', 'instagram')   # рекламні UTM-мітки (Meta paid)
SEG_BREAKDOWNS = {
    'platform': 'publisher_platform',
    'age': 'age',
    'gender': 'gender',
    'device': 'impression_device',
}
# placement (platform_position) вимкнено: Meta API блокує його з action-полями на цьому акаунті.

def log(m): print(f'[{datetime.now(timezone.utc):%H:%M:%S}] {m}', flush=True)

# ---------------- Meta Graph API ----------------
def fb_get(path, params=None):
    params = dict(params or {}); params['access_token'] = FB_TOKEN
    url = f'https://graph.facebook.com/{FB_API_VERSION}/{path}'
    for attempt in range(3):
        try:
            r = requests.get(url, params=params, timeout=90)
            if r.status_code == 200:
                return r.json()
            log(f'  ⚠ FB {r.status_code}: {r.text[:200]}')
            if r.status_code in (400, 403):
                return None
        except Exception as e:
            log(f'  ⚠ FB exc: {e}')
        time.sleep(2 * (attempt + 1))
    return None

def _num(v):
    try: return float(v)
    except Exception: return 0.0

def _actions_purchases(actions):
    """omni_purchase count."""
    if not actions: return 0
    for a in actions:
        if a.get('action_type') == 'omni_purchase':
            return int(_num(a.get('value')))
    return 0

def _roas(purchase_roas):
    if not purchase_roas: return 0.0
    try: return _num(purchase_roas[0].get('value'))
    except Exception: return 0.0

def insights(level, since, until, breakdown=None, limit=None):
    base = 'spend,impressions,clicks,ctr,cpc,reach,frequency'
    if level == 'ad':
        base += ',ad_name,ad_id'
    # Meta: platform_position не комбінується з action_type-полями (actions).
    # Лишаємо purchase_roas (працює), але прибираємо actions для цього breakdown.
    if breakdown == 'platform_position':
        fields = base  # platform_position конфліктує з усіма action-полями -> тільки spend/clicks
    else:
        fields = base + ',actions,purchase_roas'
    params = {
        'level': level,
        'time_range': json.dumps({'since': since, 'until': until}),
        'fields': fields,
        'limit': limit or 500,
    }
    if breakdown:
        params['breakdowns'] = breakdown
    rows, data = [], fb_get(f'act_{ACCOUNT}/insights', params)
    while data and 'data' in data:
        rows.extend(data['data'])
        nxt = data.get('paging', {}).get('next')
        if not nxt or len(rows) > 4000:
            break
        try:
            data = requests.get(nxt, timeout=90).json()
        except Exception:
            break
    return rows

def account_pixel(since, until):
    rows = insights('account', since, until)
    if not rows: return {}
    r = rows[0]
    spend = _num(r.get('spend')); pur = _actions_purchases(r.get('actions')); roas = _roas(r.get('purchase_roas'))
    return {
        'spend': round(spend, 2), 'impressions': int(_num(r.get('impressions'))),
        'clicks': int(_num(r.get('clicks'))), 'ctr': round(_num(r.get('ctr')), 2),
        'reach': int(_num(r.get('reach'))), 'frequency': round(_num(r.get('frequency')), 2),
        'purchases': pur, 'pixel_roas': round(roas, 2),
        'cpa': round(spend / pur, 2) if pur else None,
        'pixel_revenue': round(spend * roas),
    }

def segment(field, since, until):
    rows = insights('account', since, until, breakdown=field)
    out = []
    for r in rows:
        key = r.get(field) or '—'
        out.append([str(key), round(_num(r.get('spend')), 2),
                    _actions_purchases(r.get('actions')), round(_roas(r.get('purchase_roas')), 2)])
    out.sort(key=lambda x: -x[1])
    return out

def creatives(since, until, top=12):
    rows = insights('ad', since, until)
    out = []
    for r in rows:
        spend = _num(r.get('spend'))
        if spend < 1: continue
        out.append({
            'name': r.get('ad_name') or r.get('ad_id') or '—',
            'spend': round(spend, 2), 'purchases': _actions_purchases(r.get('actions')),
            'roas': round(_roas(r.get('purchase_roas')), 2), 'ctr': round(_num(r.get('ctr')), 2),
        })
    out.sort(key=lambda x: -x['roas'])
    return out[:top]

# ---------------- Supabase RPC ----------------
def sb_rpc(fn, body):
    try:
        r = requests.post(f'{SB_URL}/rest/v1/rpc/{fn}',
                          headers={'apikey': SB_KEY, 'Authorization': f'Bearer {SB_KEY}',
                                   'Content-Type': 'application/json'},
                          json=body, timeout=120)
        if r.status_code == 200:
            return r.json()
        log(f'  ⚠ SB {fn} {r.status_code}: {r.text[:150]}')
    except Exception as e:
        log(f'  ⚠ SB exc {fn}: {e}')
    return None

def get_projects():
    rows = sb_rpc('dashboard_projects_with_stats', {}) or []
    out = []
    for r in rows:
        ds, de = r.get('date_start'), r.get('date_end')
        if not ds or not de: continue
        out.append({'code': r.get('code'), 'name': r.get('name'),
                    'car_model': r.get('car_model'), 'date_start': ds, 'date_end': de})
    out.sort(key=lambda p: p['date_start'])
    return out

def real_ad_revenue(since, until):
    body = {'p_field': 'utm_source', 'p_from': f'{since}T00:00:00+03:00', 'p_to': f'{until}T23:59:59+03:00',
            'p_project_values': None, 'p_customer_type': None, 'p_tariff': None,
            'p_pay_provider': None, 'p_traffic_type': None}
    data = sb_rpc('dashboard_agg_deals_with_traffic', body) or []
    rev = 0.0
    for r in data:
        if str(r.get('key', '')).lower() in AD_SOURCE_MARKS:
            rev += _num(r.get('sum_amount'))
    return round(rev)

# ---------------- recommendations (Фаза 2) ----------------
def recommend(p):
    recs = []
    px = p.get('pixel_roas') or 0
    if px and px < 3:
        recs.append({'sev': 'cri', 'text': f'Піксельний ROAS {px} низький — переглянути креативи/аудиторію.'})
    freq = p.get('frequency') or 0
    if freq >= 5:
        recs.append({'sev': 'mod', 'text': f'Частота {freq} висока — ризик вигорання, ротувати креатив/розширити аудиторію.'})
    # найгірші креативи
    losers = [c for c in p.get('creatives', []) if c['roas'] < 3 and c['spend'] > 500]
    if losers:
        recs.append({'sev': 'cri', 'text': f'{len(losers)} креатив(и) з ROAS<3 зливають бюджет — вимкнути.'})
    # сегмент-лідер
    seg = p.get('segments', {}).get('platform', [])
    if seg:
        best = max(seg, key=lambda x: x[3] if x[1] > 100 else 0)
        recs.append({'sev': 'inf', 'text': f'Найкраща платформа: {best[0]} (ROAS {best[3]}) — пріоритет бюджету.'})
    gap = p.get('gap_pct')
    if gap is not None and gap < -20:
        recs.append({'sev': 'mod', 'text': f'Піксель завищує на {abs(gap):.0f}% vs реальні продажі — не переоцінювати.'})
    return recs

# ---------------- main ----------------
def build_project(proj):
    since, until = proj['date_start'], proj['date_end']
    # clamp future end to today
    today = datetime.now(timezone.utc).strftime('%Y-%m-%d')
    if until > today: until = today
    log(f'  • {proj["name"]} [{since}..{until}]')
    px = account_pixel(since, until)
    if not px:
        return None
    segs = {}
    for name, bd in SEG_BREAKDOWNS.items():
        segs[name] = segment(bd, since, until)
        time.sleep(0.4)
    crv = creatives(since, until)
    real = real_ad_revenue(since, until)
    spend = px.get('spend') or 0
    real_roas = round(real / spend, 2) if spend else None
    pix_rev = px.get('pixel_revenue') or 0
    gap = round((real / pix_rev - 1) * 100, 1) if pix_rev else None
    out = {
        'code': proj['code'], 'name': proj['name'], 'car_model': proj.get('car_model'),
        'date_from': since, 'date_to': until,
        **px,
        'real_ad_revenue': real, 'real_ad_roas': real_roas, 'gap_pct': gap,
        'segments': segs, 'creatives': crv,
    }
    out['recommendations'] = recommend(out)
    return out

def main():
    if not FB_TOKEN:
        log('❌ FB_ACCESS_TOKEN не задано'); sys.exit(1)
    log(f'Meta-stats ETL · account act_{ACCOUNT}')
    projects = get_projects()
    log(f'  проєктів від dashboard_projects_with_stats: {len(projects)}')
    built, today = [], datetime.now(timezone.utc).strftime('%Y-%m-%d')
    for proj in projects:
        if proj['date_start'] > today:
            continue  # майбутній — поки пропускаємо
        try:
            b = build_project(proj)
            if b: built.append(b)
        except Exception as e:
            log(f'  ⚠ проєкт {proj.get("name")} failed: {e}')
        time.sleep(0.5)
    # позначити поточні (date_end >= today)
    for b in built:
        b['is_current'] = b['date_to'] >= today or b['date_from'][:7] == today[:7]
    payload = {
        'generated': datetime.now(timezone.utc).isoformat(),
        'account': ACCOUNT, 'currency': 'UAH',
        'note': 'Реал ROAS = виручка по UTM-мітках facebook+instagram (без ретеншну/органіки).',
        'projects': built,
    }
    os.makedirs(os.path.dirname(OUT_PATH), exist_ok=True)
    with open(OUT_PATH, 'w', encoding='utf-8') as f:
        json.dump(payload, f, ensure_ascii=False, indent=1)
    log(f'✅ data.json: {len(built)} проєктів -> {OUT_PATH}')

if __name__ == '__main__':
    main()
