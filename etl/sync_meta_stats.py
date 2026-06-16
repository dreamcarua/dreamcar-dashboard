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

TG_TOKEN = os.getenv('TG_BOT_TOKEN', '')
TG_CHAT = os.getenv('TG_CHAT_ID', '')
GH_TEAM_TOKEN = os.getenv('GH_TEAM_NOTIFY_TOKEN', '')   # Варіант A: міст cowork-notify (write до dreamcar-team)
VADYM_ID = 'aaaaaaa1-aaaa-aaaa-aaaa-aaaaaaaaaaaa'        # CEO (created_by/assignee)
SEV_PRIORITY = {'cri': 'p1', 'mod': 'p2', 'inf': 'p4'}

def log(m): print(f'[{datetime.now(timezone.utc):%H:%M:%S}] {m}', flush=True)

def _kyiv_now():
    try:
        from zoneinfo import ZoneInfo
        return datetime.now(ZoneInfo('Europe/Kyiv'))
    except Exception:
        return datetime.now(timezone.utc)

def build_digest(payload):
    cur = [p for p in payload['projects'] if p.get('is_current')] or payload['projects'][-2:]
    lines = ['📊 <b>Meta Ads — щоденний дайджест</b>', f'<i>{_kyiv_now():%d.%m %H:%M} Київ</i>', '']
    for p in cur:
        sp = f'{int(p.get("spend",0)):,}'.replace(',', ' ')
        lines.append(f'🏁 <b>{p["name"]}</b> · {sp} ₴')
        lines.append(f'   ROAS піксель {p.get("pixel_roas")} · реал {p.get("real_ad_roas")} · CPA {p.get("cpa")} ₴')
        for r in (p.get('recommendations') or [])[:2]:
            mark = '🔴' if r['sev'] == 'cri' else ('🟡' if r['sev'] == 'mod' else 'ℹ️')
            lines.append(f'   {mark} {r["text"]}')
        lines.append('')
    lines.append('🔗 dashboard.dreamcar.ua/meta-analytics/')
    return '\n'.join(lines)

def post_tg_digest(payload):
    """Фаза 3. B: пряма відправка (TG_BOT_TOKEN+TG_CHAT_ID). A: міст cowork-notify (GH_TEAM_NOTIFY_TOKEN)."""
    text = build_digest(payload)
    if TG_TOKEN and TG_CHAT:
        try:
            r = requests.post(f'https://api.telegram.org/bot{TG_TOKEN}/sendMessage',
                json={'chat_id': TG_CHAT, 'text': text, 'parse_mode': 'HTML', 'disable_web_page_preview': True}, timeout=30)
            log(f'  {"✅" if r.status_code == 200 else "⚠"} TG (direct): {r.status_code}'); return
        except Exception as e:
            log(f'  ⚠ TG direct exc: {e}')
    if GH_TEAM_TOKEN:
        import base64
        fn = f'cowork-notify/{_kyiv_now():%Y-%m-%d-%H%M}-meta-digest.json'
        content = json.dumps({'text': text, 'type': 'info', 'link': 'https://dashboard.dreamcar.ua/meta-analytics/'}, ensure_ascii=False)
        try:
            r = requests.put(f'https://api.github.com/repos/dreamcarua/dreamcar-team/contents/{fn}',
                headers={'Authorization': f'Bearer {GH_TEAM_TOKEN}', 'Accept': 'application/vnd.github+json'},
                json={'message': 'meta digest', 'content': base64.b64encode(content.encode()).decode(), 'branch': 'main'}, timeout=30)
            log(f'  {"✅" if r.status_code in (200, 201) else "⚠"} TG (bridge): {r.status_code}'); return
        except Exception as e:
            log(f'  ⚠ TG bridge exc: {e}')
    log('  ℹ TG digest пропущено (нема TG_BOT_TOKEN/TG_CHAT_ID або GH_TEAM_NOTIFY_TOKEN)')

def _sb_get(path):
    try:
        r = requests.get(f'{SB_URL}/rest/v1/{path}', headers={'apikey': SB_KEY, 'Authorization': f'Bearer {SB_KEY}'}, timeout=30)
        if r.status_code == 200: return r.json()
    except Exception as e:
        log(f'  ⚠ sb_get: {e}')
    return None

def create_tasks(payload):
    """Авто-задачі у team_tasks з КРИТИЧНИХ рекомендацій поточних циклів (service-role + дедуп по title)."""
    if not SB_KEY:
        return
    import urllib.parse
    created = 0
    for p in payload['projects']:
        if not p.get('is_current'):
            continue
        for r in (p.get('recommendations') or []):
            if r['sev'] != 'cri':
                continue
            title = f"Meta · {p['name']}: {r['text'][:70]}"
            ex = _sb_get(f"team_tasks?select=id&title=eq.{urllib.parse.quote(title)}&status=neq.done&limit=1")
            if ex:
                continue
            body = {'title': title,
                    'description': f"{r['text']}\n\nПроєкт: {p['name']} ({p['date_from']}→{p['date_to']})\n"
                                   f"ROAS піксель {p.get('pixel_roas')} · реал {p.get('real_ad_roas')}\n\n"
                                   f"https://dashboard.dreamcar.ua/meta-analytics/",
                    'priority': SEV_PRIORITY.get(r['sev'], 'p3'),
                    'assignee_id': VADYM_ID, 'created_by': VADYM_ID,
                    'tags': ['meta', 'etl', 'recommendation']}
            try:
                resp = requests.post(f'{SB_URL}/rest/v1/team_tasks',
                    headers={'apikey': SB_KEY, 'Authorization': f'Bearer {SB_KEY}',
                             'Content-Type': 'application/json', 'Prefer': 'return=minimal'},
                    json=body, timeout=30)
                if resp.status_code in (200, 201): created += 1
                else: log(f'  ⚠ task {resp.status_code}: {resp.text[:120]}')
            except Exception as e:
                log(f'  ⚠ task exc: {e}')
    log(f'  ✓ задач створено: {created}')

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

# ---------------- recommendations (Фаза 2) ----------------
BREAKEVEN = 2.0     # беззбитковий ad-ROAS (висока маржа токенів DreamCar)
TARGET_ROAS = 5.0   # робоча ціль
FREQ_WARN = 4.5
FREQ_CRIT = 6.5
CPA_WARN = 60.0     # ₴ — комфортний поріг ціни покупки

def _sp(n):  # форматування грошей
    return f'{int(n):,}'.replace(',', ' ')

def _seg_pick(rows, total, min_share=0.05):
    """повертає (best, worst) сегменти за ROAS серед значущих (частка>=min_share, >=3 покупки)."""
    if not rows or not total:
        return None, None
    sig = [r for r in rows if r[1] >= total * min_share and r[2] >= 3]
    if len(sig) < 2:
        return (sig[0] if sig else None), None
    return max(sig, key=lambda r: r[3]), min(sig, key=lambda r: r[3])

def recommend(p):
    """Корисні, конкретні, пріоритезовані рекомендації. Тільки sev='cri' стає задачею."""
    recs = []
    spend = p.get('spend') or 0
    px = p.get('pixel_roas') or 0
    real = p.get('real_ad_roas')
    freq = p.get('frequency') or 0
    cur = p.get('is_current')
    crv = p.get('creatives') or []
    segs = p.get('segments') or {}

    # 1) Збиткові креативи -> ЗАДАЧА
    losers = sorted([c for c in crv if (c.get('roas') or 0) < BREAKEVEN and (c.get('spend') or 0) > 1000],
                    key=lambda c: -(c.get('spend') or 0))
    if losers:
        waste = sum(c['spend'] for c in losers)
        names = ', '.join(f'«{c["name"][:32]}» (ROAS {c["roas"]})' for c in losers[:3])
        recs.append({'sev': 'cri', 'text': f'Вимкнути {len(losers)} збитков. креатив(ів): {names}. Зливають ~{_sp(waste)} ₴ при ROAS<{BREAKEVEN}.'})

    # 2) Вигорання частоти (сумарна за цикл)
    if freq >= FREQ_CRIT:
        recs.append({'sev': 'cri', 'text': f'Сумарна частота {freq} за цикл — аудиторія вигоряє. Терміново освіжити креативи або розширити аудиторію/гео.'})
    elif freq >= FREQ_WARN:
        recs.append({'sev': 'mod', 'text': f'Сумарна частота {freq} за цикл близько до порогу вигорання — ротувати креатив кожні 4-5 днів або розширити аудиторію.'})

    # 3) Масштабування (тільки поточні, здорові, із запасом охоплення)
    if cur and px >= TARGET_ROAS and 0 < freq < 3.5:
        recs.append({'sev': 'mod', 'text': f'ROAS {px} при сумарній частоті {freq} — є запас охоплення. Підняти денний бюджет на 20-30%.'})

    # 4) Лідер-креатив -> масштабувати
    winners = [c for c in crv if (c.get('roas') or 0) >= TARGET_ROAS and (c.get('spend') or 0) > 500]
    if winners:
        w = max(winners, key=lambda c: c['roas'])
        recs.append({'sev': 'inf', 'text': f'Лідер: «{w["name"][:38]}» ROAS {w["roas"]}, CTR {w.get("ctr")}% — масштабувати й дублювати в інші adset.'})

    # 5) Стать — звуження
    gen = {str(r[0]).lower(): r for r in segs.get('gender', [])}
    male, female = gen.get('male'), gen.get('female')
    if male and female and male[1] > 100 and female[1] > 100:
        if male[3] >= female[3] * 1.5:
            sf = female[1] / spend * 100 if spend else 0
            recs.append({'sev': 'mod', 'text': f'Чоловіки ROAS {male[3]} vs жінки {female[3]}. Жінки (~{sf:.0f}% бюджету) тягнуть униз — тестово звузити на чоловіків.'})
        elif female[3] >= male[3] * 1.5:
            recs.append({'sev': 'mod', 'text': f'Жінки ROAS {female[3]} vs чоловіки {male[3]} — цей приз краще заходить жінкам, посилити жіночу аудиторію.'})

    # 6) Платформа — перерозподіл
    b, w2 = _seg_pick(segs.get('platform', []), spend)
    if b and w2 and b[0] != w2[0] and b[3] >= w2[3] * 1.4:
        recs.append({'sev': 'inf', 'text': f'{b[0]} ROAS {b[3]} (найкраще) проти {w2[0]} {w2[3]} — змістити бюджет на {b[0]}.'})

    # 7) Вік — ядро
    ab, _ = _seg_pick(segs.get('age', []), spend)
    if ab:
        recs.append({'sev': 'inf', 'text': f'Найефективніший вік: {ab[0]} (ROAS {ab[3]}) — пріоритет у таргетингу.'})

    # 8) Pixel vs Real
    if real and px:
        if real >= px * 1.3:
            recs.append({'sev': 'inf', 'text': f'Реальний ad-ROAS {real} > піксель {px} — реклама ефективніша, ніж показує піксель (він недооцінює конверсії).'})
        elif real <= px * 0.7:
            recs.append({'sev': 'mod', 'text': f'Піксель завищує: реальний ad-ROAS лише {real} vs піксель {px}. Орієнтуватись на реальний.'})

    # 9) CPA
    cpa = p.get('cpa')
    if cur and cpa and cpa > CPA_WARN:
        recs.append({'sev': 'mod', 'text': f'Ціна покупки {cpa} ₴ — вище комфортного ({int(CPA_WARN)} ₴). Шукати дешевші зв\'язки (плейсмент/аудиторія/креатив).'})

    order = {'cri': 0, 'mod': 1, 'inf': 2}
    recs.sort(key=lambda r: order.get(r['sev'], 3))
    return recs

def build_signals(payload):
    """Консолідований блок сигналів: акаунт-рівень (тиждень-до-тижня, денні зриви) +
    критичні/важливі рекомендації поточних циклів. Сортування за серйозністю."""
    sig = []
    daily = payload.get('daily') or {}
    wk = daily.get('week') or {}
    t, p = wk.get('this') or {}, wk.get('prev') or {}
    wd = wk.get('deltas') or {}
    # тижневий реал-ROAS
    if t.get('real_roas') and p.get('real_roas'):
        ch = wd.get('real_roas')
        if ch is not None and ch <= -25:
            sig.append({'sev': 'cri', 'scope': 'Акаунт · тиждень',
                        'text': f'Реал ROAS тижня {t["real_roas"]} vs попереднього {p["real_roas"]} ({ch:.0f}%) — перевірити креативи/аудиторію/бюджет.'})
        elif ch is not None and ch >= 25:
            sig.append({'sev': 'inf', 'scope': 'Акаунт · тиждень',
                        'text': f'Реал ROAS тижня зріс до {t["real_roas"]} (+{ch:.0f}%) — є простір масштабувати переможців.'})
    # тижневий CPA
    if t.get('cpa') and p.get('cpa') and wd.get('cpa') is not None and wd['cpa'] >= 30:
        sig.append({'sev': 'mod', 'scope': 'Акаунт · тиждень',
                    'text': f'CPA тижня {t["cpa"]} ₴ vs {p["cpa"]} ₴ (+{wd["cpa"]:.0f}%) — залучення дорожчає.'})
    # денний зрив pixel ROAS
    dd = daily.get('deltas') or {}
    if dd.get('pixel_roas') is not None and dd['pixel_roas'] <= -30:
        sig.append({'sev': 'mod', 'scope': f'Вчора ({daily.get("date","")[5:]})',
                    'text': f'Pixel ROAS вчора впав на {abs(dd["pixel_roas"]):.0f}% до дня раніше — стежити, чи не тренд.'})
    # рекомендації поточних циклів (cri/mod)
    for pr in payload.get('projects', []):
        if not pr.get('is_current'):
            continue
        for r in (pr.get('recommendations') or []):
            if r.get('sev') in ('cri', 'mod'):
                sig.append({'sev': r['sev'], 'scope': pr['name'], 'text': r['text']})
    order = {'cri': 0, 'mod': 1, 'inf': 2}
    sig.sort(key=lambda s: order.get(s['sev'], 3))
    return sig

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
    crv = creatives(as_since if is_cur else since, until)
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
    payload['signals'] = build_signals(payload)
    os.makedirs(os.path.dirname(OUT_PATH), exist_ok=True)
    with open(OUT_PATH, 'w', encoding='utf-8') as f:
        json.dump(payload, f, ensure_ascii=False, indent=1)
    log(f'✅ data.json: {len(built)} проєктів -> {OUT_PATH}')
    create_tasks(payload)
    post_tg_digest(payload)

if __name__ == '__main__':
    main()
