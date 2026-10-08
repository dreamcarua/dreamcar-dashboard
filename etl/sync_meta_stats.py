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
  - Реальна виручка ВІД РЕКЛАМИ: Supabase RPC dashboard_agg_deals_with_traffic,
    по placement-мітках utm_medium (facebook_*/instagram_*/messenger_*) — лише реклама,
    БЕЗ органіки бренд-акаунтів (account/post/stories), Telegram, email.

ENV:
  FB_ACCESS_TOKEN, SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY (як у sync_fb_ads.py)
  META_ANALYTICS_ACCOUNT (опц., дефолт 4136058269783354)
"""
import os, sys, json, time
from datetime import datetime, timezone, timedelta
import requests

FB_API_VERSION = 'v21.0'
FB_TOKEN = os.getenv('FB_ACCESS_TOKEN', '')
SB_URL = os.getenv('SUPABASE_URL', 'https://wotghlaehnvxyeacznvv.supabase.co').rstrip('/')
SB_KEY = os.getenv('SUPABASE_SERVICE_ROLE_KEY', '') or os.getenv('SUPABASE_ANON_KEY', '')
ACCOUNT = os.getenv('META_ANALYTICS_ACCOUNT', '4136058269783354').replace('act_', '')
OUT_PATH = os.path.join(os.path.dirname(__file__), '..', 'docs', 'meta-analytics', 'data.json')

# Атрибуція реклами Meta — по placement-мітках utm_medium ({{placement}}), НЕ по utm_source.
# utm_source=facebook/instagram включає ОРГАНІКУ (акаунти бренду без реклами) — її треба виключати.
# Реклама: facebook_*/instagram_*/messenger_* (feed/stories/reels/...). Органіка: account/post/stories/∅.
AD_MEDIUM_PREFIXES = ('facebook_', 'instagram_', 'messenger_')
SEG_BREAKDOWNS = {
    'platform': 'publisher_platform',
    'age': 'age',
    'gender': 'gender',
    'device': 'impression_device',
}
# placement (platform_position) вимкнено: Meta API блокує його з action-полями на цьому акаунті.


def log(m): print(f'[{datetime.now(timezone.utc):%H:%M:%S}] {m}', flush=True)

def _kyiv_now():
    try:
        from zoneinfo import ZoneInfo
        return datetime.now(ZoneInfo('Europe/Kyiv'))
    except Exception:
        return datetime.now(timezone.utc)

def _sb_get(path):
    try:
        r = requests.get(f'{SB_URL}/rest/v1/{path}', headers={'apikey': SB_KEY, 'Authorization': f'Bearer {SB_KEY}'}, timeout=30)
        if r.status_code == 200: return r.json()
    except Exception as e:
        log(f'  ⚠ sb_get: {e}')
    return None

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

def insights(level, since, until, breakdown=None, limit=None, time_increment=None):
    base = 'spend,impressions,clicks,ctr,cpc,reach,frequency'
    if level == 'ad':
        base += ',ad_name,ad_id'
    elif level == 'adset':
        base += ',adset_name,adset_id'
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
    if time_increment:
        params['time_increment'] = time_increment
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
            'name': r.get('ad_name') or r.get('ad_id') or '—', 'ad_id': r.get('ad_id'),
            'spend': round(spend, 2), 'purchases': _actions_purchases(r.get('actions')),
            'roas': round(_roas(r.get('purchase_roas')), 2), 'ctr': round(_num(r.get('ctr')), 2),
        })
    out.sort(key=lambda x: -x['roas'])
    return out[:top]

def creative_thumbs(crv, n=6, embed=False):
    """Прев'ю топ-N оголошень. Для поточних циклів (embed=True) — завантажити й вшити base64
    (fbcdn URL хотлінк-захищені й з expiry, на сторонньому домені не вантажаться)."""
    import base64
    if not embed:
        return crv
    ok = 0
    for c in crv[:n]:
        aid = c.get('ad_id')
        if not aid:
            continue
        d = fb_get(aid, {'fields': 'creative{thumbnail_url,image_url}'})
        cr = (d or {}).get('creative') or {}
        url = cr.get('thumbnail_url') or cr.get('image_url')
        if not url:
            continue
        # НЕ міняти параметри розміру в URL — це ламає підпис (oh=) і дає 403
        try:
            r = requests.get(url, timeout=25, headers={
                'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'})
            if r.status_code == 200 and r.content and len(r.content) < 200000:
                c['thumb'] = 'data:image/jpeg;base64,' + base64.b64encode(r.content).decode()
                ok += 1
            else:
                log(f'    ⚠ thumb {aid}: HTTP {r.status_code}, {len(r.content)}b')
        except Exception as e:
            log(f'    ⚠ thumb {aid} exc: {e}')
        time.sleep(0.2)
    log(f'  ✓ прев\'ю вшито: {ok}/{min(n, len(crv))}')
    return crv

def daily_series(since, until):
    """Денні криві (account-level) у межах циклу — для трендів spend/ROAS/CPA."""
    rows = insights('account', since, until, time_increment=1)
    out = []
    for r in rows:
        sp = _num(r.get('spend')); pur = _actions_purchases(r.get('actions')); roas = _roas(r.get('purchase_roas'))
        out.append({'date': r.get('date_start'), 'spend': round(sp, 2), 'purchases': pur,
                    'roas': round(roas, 2), 'cpa': round(sp / pur, 2) if pur else None})
    out.sort(key=lambda x: x['date'])
    return out[-30:]  # не більше 30 точок

def adsets(since, until, top=8):
    """Розбивка по adset (аудиторіях) — куди перекидати бюджет."""
    rows = insights('adset', since, until)
    out = []
    for r in rows:
        sp = _num(r.get('spend'))
        if sp < 1:
            continue
        pur = _actions_purchases(r.get('actions')); roas = _roas(r.get('purchase_roas'))
        out.append({'name': r.get('adset_name') or r.get('adset_id') or '—',
                    'spend': round(sp, 2), 'purchases': pur, 'roas': round(roas, 2),
                    'ctr': round(_num(r.get('ctr')), 2), 'cpa': round(sp / pur, 2) if pur else None})
    out.sort(key=lambda x: -x['spend'])
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

def _is_ad_medium(k):
    return str(k or '').lower().startswith(AD_MEDIUM_PREFIXES)

def _agg_medium(since, until):
    body = {'p_field': 'utm_medium', 'p_from': f'{since}T00:00:00+03:00', 'p_to': f'{until}T23:59:59+03:00',
            'p_project_values': None, 'p_customer_type': None, 'p_tariff': None,
            'p_pay_provider': None, 'p_traffic_type': None}
    return sb_rpc('dashboard_agg_deals_with_traffic', body) or []

def real_ad_revenue(since, until):
    """Виручка ЛИШЕ від реклами Meta — по placement-мітках utm_medium (facebook_*/instagram_*),
    БЕЗ органіки (account/post/stories) та інших каналів (telegram/email)."""
    return round(sum(_num(r.get('sum_amount')) for r in _agg_medium(since, until) if _is_ad_medium(r.get('key'))))

def real_by_placement(since, until, top=8):
    """Реальна виручка по рекламних плейсментах Meta (utm_medium) — звідки реальні оплати від реклами."""
    out = [{'placement': str(r.get('key')), 'revenue': round(_num(r.get('sum_amount'))), 'paid': int(_num(r.get('paid')))}
           for r in _agg_medium(since, until) if _is_ad_medium(r.get('key'))]
    out.sort(key=lambda x: -x['revenue'])
    return out[:top]

def account_range(since, until):
    """Зведення по акаунту за діапазон (для тижневих порівнянь)."""
    px = account_pixel(since, until) or {}
    sp = px.get('spend') or 0
    real = real_ad_revenue(since, until)
    return {'from': since, 'to': until, 'spend': round(sp), 'purchases': px.get('purchases'),
            'pixel_roas': px.get('pixel_roas'), 'cpa': px.get('cpa'),
            'real_revenue': real, 'real_roas': round(real / sp, 2) if sp else None}

def week_compare(y_ord):
    """7 днів, що завершуються вчора, проти попередніх 7 днів."""
    def ds(o): return datetime.fromordinal(o).strftime('%Y-%m-%d')
    this_ = account_range(ds(y_ord - 6), ds(y_ord))
    prev_ = account_range(ds(y_ord - 13), ds(y_ord - 7))
    def ch(a, b): return round((a - b) / b * 100, 1) if (a and b) else None
    return {'this': this_, 'prev': prev_, 'deltas': {
        'spend': ch(this_['spend'], prev_['spend']),
        'real_roas': ch(this_['real_roas'], prev_['real_roas']),
        'pixel_roas': ch(this_['pixel_roas'], prev_['pixel_roas']),
        'cpa': ch(this_['cpa'], prev_['cpa']),
        'purchases': ch(this_['purchases'], prev_['purchases'])}}

def daily_snapshot(active_names):
    """Зріз за ВЧОРА (повна доба, Київ) — account-level Meta + реал по UTM + РЕАЛЬНІ оголошення за добу.
    Лідер/слабкі рахуються з ad-level за вчора (не з кумулятиву циклу — інакше у зріз протікають
    старі оголошення з перекритих за датами кампаній)."""
    y = (_kyiv_now().date().toordinal() - 1)
    yd = datetime.fromordinal(y).strftime('%Y-%m-%d')
    yd2 = datetime.fromordinal(y - 1).strftime('%Y-%m-%d')   # позавчора (для дельт)
    px = account_pixel(yd, yd) or {}
    px2 = account_pixel(yd2, yd2) or {}
    spend = px.get('spend') or 0
    real = real_ad_revenue(yd, yd)

    def _delta(a, b):
        if a is None or not b:
            return None
        return round((a - b) / b * 100, 1)
    deltas = {
        'spend': _delta(spend, px2.get('spend')),
        'pixel_roas': _delta(px.get('pixel_roas'), px2.get('pixel_roas')),
        'purchases': _delta(px.get('purchases'), px2.get('purchases')),
        'cpa': _delta(px.get('cpa'), px2.get('cpa')),
    }
    # оголошення, що РЕАЛЬНО крутилися вчора
    crv = creatives(yd, yd, top=200)
    top = [c for c in crv if c['spend'] >= 300 and c['purchases'] >= 1]
    top.sort(key=lambda c: -c['roas'])
    weak = sorted([c for c in crv if c['roas'] < BREAKEVEN and c['spend'] >= 500], key=lambda c: -c['spend'])
    return {
        'date': yd,
        'prev_date': yd2,
        'spend': spend,
        'impressions': px.get('impressions'),
        'clicks': px.get('clicks'),
        'purchases': px.get('purchases'),
        'pixel_roas': px.get('pixel_roas'),
        'cpa': px.get('cpa'),
        'frequency': px.get('frequency'),
        'real_ad_revenue': real,
        'real_ad_roas': round(real / spend, 2) if spend else None,
        'deltas': deltas,
        'week': week_compare(y),
        'real_by_placement': real_by_placement(yd, yd),
        'active_cycles': active_names,
        'top_creatives': top[:3],
        'weak_creatives': weak[:3],
    }

BREAKEVEN = 2.0     # беззбитковий ad-ROAS для списку слабких оголошень у денному зрізі

# ---------------- strategy structure (2026) ----------------
STRATEGY_CAMPAIGNS = [
    ('120249698602830624', '01 · Ядро', 'Advantage+ Sales · broad · A+A',
     '70-80% бюджету. Головний драйвер. Увесь робочий креатив-пул (12-20). Highest Volume на старті → Highest Value, коли стабільно.'),
    ('120249698605960624', '02 · Retargeting', 'Сайт 180д + гаряча 30д, excl. покупці',
     '15-20%. Дожим теплих: дедлайн, соц-доказ, апсел токенів. Строгий таргет (A+A off).'),
    ('120249698608790624', '03 · Prospecting', 'Broad, EXCL. усі наші аудиторії',
     '5-10%. «Свіжа кров» — лише нові люди (виключені відвідувачі сайту + покупці), щоб алгоритм не крутився в нашій бульбашці.'),
    ('120249698612830624', '04 · Testing', 'ABO малий · broad',
     'Інкубатор нових концептів окремо від ядра. Переможців переносимо в Ядро.'),
]
STRATEGY_AUDIENCES = [
    ('120249698494260624', 'Сайт · усі відвідувачі 180д', 'Retargeting + база для виключення'),
    ('120249698499330624', 'Покупці 180д', 'Апсел токенів + виключення'),
    ('120249698502970624', 'Сайт · гаряча 30д', 'Дожим гарячих'),
]

# Знімок якості сигналу (перевірено через Meta MCP; оновлюється при ревізії Claude)
SIGNAL_QUALITY = {
    'checked': '2026-06-16',
    'pixel': 'AI DreamCar',
    'emq': 9.3,
    'opp_score': 97,
    'capi': 'server-side · real-time',
    'capi_purchase_server_pct': 100,
    'freshness': 'real-time',
    'match_keys': [['email', 100], ['телефон', 100], ["ім'я", 100], ['external_id', 99.7],
                   ['fbp', 97.8], ['ip', 99], ['fbc', 77.8], ['прізвище', 12.2]],
    'note': 'Знімок перевірки через Meta API. EMQ 9.3/10 і Opportunity Score 97/100 — майже стеля. CAPI: усі Purchase надходять із сервера в реальному часі.',
}

def build_strategy():
    out = []
    for cid, role, sub, desc in STRATEGY_CAMPAIGNS:
        ent = fb_get(cid, {'fields': 'name,effective_status,daily_budget'}) or {}
        item = {'id': cid, 'role': role, 'sub': sub, 'desc': desc,
                'name': ent.get('name'), 'status': ent.get('effective_status'),
                'daily_budget': round(_num(ent.get('daily_budget')) / 100) if ent.get('daily_budget') else None,
                'spend': 0, 'purchases': 0, 'roas': 0, 'ctr': 0}
        ins = fb_get(f'{cid}/insights', {'fields': 'spend,impressions,actions,purchase_roas,ctr', 'date_preset': 'maximum'})
        if ins and ins.get('data'):
            r = ins['data'][0]
            item.update({'spend': round(_num(r.get('spend'))), 'purchases': _actions_purchases(r.get('actions')),
                         'roas': round(_roas(r.get('purchase_roas')), 2), 'ctr': round(_num(r.get('ctr')), 2)})
        out.append(item)
        time.sleep(0.3)
    return {'campaigns': out, 'audiences': [{'id': a, 'name': n, 'use': u} for a, n, u in STRATEGY_AUDIENCES]}

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
    is_cur = until >= today
    # для поточного циклу — вікно останніх 7 днів (тільки активні оголошення/аудиторії, без протікання старих кампаній)
    as_since = since
    if is_cur:
        d7 = (datetime.strptime(today, '%Y-%m-%d') - timedelta(days=7)).strftime('%Y-%m-%d')
        as_since = max(since, d7)
    crv = creatives(as_since if is_cur else since, until, top=100)
    crv = creative_thumbs(crv, embed=is_cur)
    real = real_ad_revenue(since, until)
    spend = px.get('spend') or 0
    real_roas = round(real / spend, 2) if spend else None
    pix_rev = px.get('pixel_revenue') or 0
    gap = round((real / pix_rev - 1) * 100, 1) if pix_rev else None
    # денні криві
    series = daily_series(since, until)
    # adset-розбивка (те саме вікно для поточних циклів)
    adset_rows = adsets(as_since, until)
    out = {
        'code': proj['code'], 'name': proj['name'], 'car_model': proj.get('car_model'),
        'date_from': since, 'date_to': until,
        **px,
        'real_ad_revenue': real, 'real_ad_roas': real_roas, 'gap_pct': gap,
        'segments': segs, 'creatives': crv,
        'series': series, 'adsets': adset_rows, 'adsets_window': as_since,
    }
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
    # денний зріз (за вчора) для щоденного дайджесту
    active = [b['name'] for b in built if b['date_to'] >= today]
    try:
        daily = daily_snapshot(active)
        log(f'  ✓ daily {daily["date"]}: spend {daily["spend"]} · pxROAS {daily["pixel_roas"]} · realROAS {daily["real_ad_roas"]}')
    except Exception as e:
        log(f'  ⚠ daily snapshot failed: {e}'); daily = None
    payload = {
        'generated': datetime.now(timezone.utc).isoformat(),
        'account': ACCOUNT, 'currency': 'UAH',
        'note': 'Реал ROAS = виручка ЛИШЕ від реклами Meta — по placement-мітках utm_medium (facebook_*/instagram_*). Виключено органіку (account/post/stories), Telegram, email.',
        'daily': daily,
        'projects': built,
    }
    try:
        payload['strategy'] = build_strategy()
    except Exception as e:
        log(f'  ⚠ strategy failed: {e}'); payload['strategy'] = None
    payload['signal_quality'] = SIGNAL_QUALITY
    os.makedirs(os.path.dirname(OUT_PATH), exist_ok=True)
    with open(OUT_PATH, 'w', encoding='utf-8') as f:
        json.dump(payload, f, ensure_ascii=False, indent=1)
    log(f'✅ data.json: {len(built)} проєктів -> {OUT_PATH}')

if __name__ == '__main__':
    main()
