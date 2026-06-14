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

def daily_snapshot(active_names):
    """Зріз за ВЧОРА (повна доба, Київ) — account-level Meta + реал по UTM. Для щоденного дайджесту."""
    y = (_kyiv_now().date().toordinal() - 1)
    yd = datetime.fromordinal(y).strftime('%Y-%m-%d')
    px = account_pixel(yd, yd) or {}
    spend = px.get('spend') or 0
    real = real_ad_revenue(yd, yd)
    return {
        'date': yd,
        'spend': spend,
        'impressions': px.get('impressions'),
        'clicks': px.get('clicks'),
        'purchases': px.get('purchases'),
        'pixel_roas': px.get('pixel_roas'),
        'cpa': px.get('cpa'),
        'frequency': px.get('frequency'),
        'real_ad_revenue': real,
        'real_ad_roas': round(real / spend, 2) if spend else None,
        'active_cycles': active_names,
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

    # 2) Вигорання частоти
    if freq >= FREQ_CRIT:
        recs.append({'sev': 'cri', 'text': f'Частота {freq} — аудиторія вигоряє. Терміново освіжити креативи або розширити аудиторію/гео.'})
    elif freq >= FREQ_WARN:
        recs.append({'sev': 'mod', 'text': f'Частота {freq} близько до порогу вигорання — ротувати креатив кожні 4-5 днів або розширити аудиторію.'})

    # 3) Масштабування (тільки поточні, здорові, із запасом частоти)
    if cur and px >= TARGET_ROAS and 0 < freq < 3.5:
        recs.append({'sev': 'mod', 'text': f'ROAS {px} при низькій частоті {freq} — є запас охоплення. Підняти денний бюджет на 20-30%.'})

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
        'note': 'Реал ROAS = виручка по UTM-мітках facebook+instagram (без ретеншну/органіки).',
        'daily': daily,
        'projects': built,
    }
    os.makedirs(os.path.dirname(OUT_PATH), exist_ok=True)
    with open(OUT_PATH, 'w', encoding='utf-8') as f:
        json.dump(payload, f, ensure_ascii=False, indent=1)
    log(f'✅ data.json: {len(built)} проєктів -> {OUT_PATH}')
    create_tasks(payload)
    post_tg_digest(payload)

if __name__ == '__main__':
    main()
