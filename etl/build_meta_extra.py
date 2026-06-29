#!/usr/bin/env python3
"""
ETL Meta Marketing API -> docs/meta-analytics/meta-extra.json

ІЗОЛЬОВАНИЙ додатковий pipeline для 4 нових табів сторінки /meta-analytics/:
  • Воронка (funnel: покази -> кліки -> покупки, Link CTR, CVR, AOV)
  • Втома доставки (CPM/CPC/частота/reach по кампаніях + поріг 4)
  • Кластери форматів (відео vs статика, KTM/iPhone/Mustang за патерном назв)
  • AI-пріоритети (матриця impact×зусилля + авто-алерти) — data-driven

НЕ чіпає data.json та БД-таблиць. Падіння цього скрипта не впливає на основний дашборд:
сторінка читає meta-extra.json окремо і graceful-fallback, якщо файлу нема.

Env:
  FB_ACCESS_TOKEN        — System User long-lived token
  FB_AD_ACCOUNT_IDS      — (опц.) перший ID береться як цільовий акаунт
  META_EXTRA_ACCOUNT     — (опц.) явний ID акаунту, дефолт 4136058269783354 (DreamCar.ua UAH)
  META_EXTRA_DAYS        — (опц.) розмір вікна, дефолт 30
"""
import os, sys, json, time
from datetime import datetime, timedelta, timezone
import requests

FB_TOKEN = os.getenv('FB_ACCESS_TOKEN', '')
FB_API = os.getenv('FB_API_VERSION', 'v21.0')
_accs = [a.strip() for a in os.getenv('FB_AD_ACCOUNT_IDS', '').split(',') if a.strip()]
ACCOUNT = os.getenv('META_EXTRA_ACCOUNT') or (_accs[0] if _accs else '4136058269783354')
WINDOW_DAYS = int(os.getenv('META_EXTRA_DAYS', '30'))
OUT_PATH = os.getenv('META_EXTRA_OUT', 'docs/meta-analytics/meta-extra.json')

BREAKEVEN_PIXEL = 2.9
REAL_FACTOR = 0.70
FATIGUE_FREQ = 4.0


def log(m):
    print(f'[{datetime.now(timezone.utc):%H:%M:%S}] {m}', flush=True)


def acct(a):
    return a if str(a).startswith('act_') else f'act_{a}'


def fb_get(path, params=None):
    url = f'https://graph.facebook.com/{FB_API}/{path}'
    params = dict(params or {})
    params['access_token'] = FB_TOKEN
    for attempt in range(5):
        r = requests.get(url, params=params, timeout=90)
        if r.status_code == 200:
            return r.json()
        if r.status_code in (4, 17, 32, 613) or 'throttl' in r.text.lower() or 'rate limit' in r.text.lower():
            wait = 2 ** attempt * 5
            log(f'  ⏳ rate limit, sleep {wait}s')
            time.sleep(wait)
            continue
        log(f'  ❌ FB {r.status_code}: {r.text[:300]}')
        r.raise_for_status()
    raise RuntimeError(f'FB API failed: {path}')


def _av(items, types):
    """Перше значення дії з пріоритетного списку типів (уникаємо подвійного рахунку)."""
    if not items:
        return 0.0
    by = {}
    for a in items:
        try:
            by[a.get('action_type', '')] = float(a.get('value', 0) or 0)
        except (TypeError, ValueError):
            pass
    for t in types:
        if t in by:
            return by[t]
    return 0.0


PURCH = ['omni_purchase', 'offsite_conversion.fb_pixel_purchase', 'purchase']
LINKC = ['link_click']


def fetch_insights(level, fields, since, until, extra=None):
    params = {
        'level': level,
        'fields': ','.join(fields),
        'time_range': json.dumps({'since': since, 'until': until}),
        'limit': 500,
    }
    if extra:
        params.update(extra)
    rows, nxt = [], None
    while True:
        if nxt:
            data = requests.get(nxt, timeout=90).json()
        else:
            data = fb_get(f'{acct(ACCOUNT)}/insights', params)
        rows.extend(data.get('data', []))
        nxt = (data.get('paging') or {}).get('next')
        if not nxt:
            break
    return rows


def num(v):
    try:
        return float(v)
    except (TypeError, ValueError):
        return 0.0


def funnel_for(since, until):
    rows = fetch_insights('account',
                          ['spend', 'impressions', 'reach', 'frequency', 'clicks', 'ctr',
                           'cpc', 'cpm', 'actions', 'action_values'], since, until)
    if not rows:
        return None
    r = rows[0]
    spend = num(r.get('spend'))
    impr = num(r.get('impressions'))
    reach = num(r.get('reach'))
    clicks = num(r.get('clicks'))
    link = _av(r.get('actions'), LINKC)
    purch = _av(r.get('actions'), PURCH)
    rev = _av(r.get('action_values'), PURCH)
    return {
        'spend': round(spend), 'impressions': int(impr), 'reach': int(reach),
        'frequency': round(num(r.get('frequency')), 2),
        'clicks': int(clicks), 'link_clicks': int(link), 'purchases': int(purch),
        'revenue': round(rev),
        'ctr': round(num(r.get('ctr')), 2),
        'link_ctr': round(link / impr * 100, 2) if impr else 0,
        'cpc': round(num(r.get('cpc')), 2),
        'cpc_link': round(spend / link, 2) if link else 0,
        'cpm': round(num(r.get('cpm')), 2),
        'cvr': round(purch / link * 100, 1) if link else 0,
        'cpa': round(spend / purch, 1) if purch else 0,
        'aov': round(rev / purch) if purch else 0,
        'roas': round(rev / spend, 2) if spend else 0,
        'real_roas': round(rev / spend * REAL_FACTOR, 2) if spend else 0,
    }


def campaigns_for(since, until):
    rows = fetch_insights('campaign',
                          ['campaign_name', 'spend', 'cpm', 'cpc', 'ctr', 'frequency',
                           'reach', 'impressions', 'actions', 'action_values'], since, until)
    out = []
    for r in rows:
        spend = num(r.get('spend'))
        if spend < 1:
            continue
        purch = _av(r.get('actions'), PURCH)
        rev = _av(r.get('action_values'), PURCH)
        name = r.get('campaign_name', '')
        low = name.lower()
        role = 'acquisition' if ('нов' in low) else ('retarget' if 'retarget' in low or 'ретаргет' in low or 'дожим' in low else 'prospecting' if 'prospect' in low else 'core')
        freq = round(num(r.get('frequency')), 2)
        out.append({
            'name': name, 'spend': round(spend),
            'cpm': round(num(r.get('cpm')), 1), 'cpc': round(num(r.get('cpc')), 2),
            'ctr': round(num(r.get('ctr')), 2), 'frequency': freq,
            'reach': int(num(r.get('reach'))), 'purchases': int(purch),
            'roas': round(rev / spend, 2) if spend else 0,
            'role': role,
            'fatigue': freq > FATIGUE_FREQ,
        })
    out.sort(key=lambda x: -x['spend'])
    return out


def cluster_of(name):
    s = (name or '').lower()
    if 'відео' in s or 'video' in s:
        return 'Відео'
    if 'iphone' in s or 'айфон' in s:
        return 'iPhone/айфон'
    if s.startswith('ktm') or 'ktm' in s or 'мото' in s:
        return 'KTM/мото'
    if 'мустанг' in s or 'фінал пост' in s or 'фінал 2 пост' in s or 'мем пост' in s:
        return 'Mustang-пост'
    if 'картинка рекламна' in s:
        return 'Картинка-нова'
    if 'картинка' in s:
        return 'Картинка-нумерована'
    if 'пост х2' in s or 'пост x2' in s or 'тариф' in s or 'пакет' in s or 'промокод' in s:
        return 'Пост x2/тариф'
    return 'Інше'


def clusters_for(since, until):
    rows = fetch_insights('ad',
                          ['ad_name', 'spend', 'impressions', 'ctr', 'actions', 'action_values'],
                          since, until, extra={'limit': 800})
    agg = {}
    for r in rows:
        spend = num(r.get('spend'))
        if spend <= 0:
            continue
        c = cluster_of(r.get('ad_name'))
        a = agg.setdefault(c, {'spend': 0.0, 'rev': 0.0, 'purch': 0.0, 'clicks': 0.0, 'impr': 0.0})
        a['spend'] += spend
        a['rev'] += _av(r.get('action_values'), PURCH)
        a['purch'] += _av(r.get('actions'), PURCH)
        a['impr'] += num(r.get('impressions'))
        a['clicks'] += num(r.get('impressions')) * num(r.get('ctr')) / 100.0
    total = sum(a['spend'] for a in agg.values()) or 1
    out = []
    for c, a in agg.items():
        out.append({
            'cluster': c, 'spend': round(a['spend']),
            'spend_pct': round(a['spend'] / total * 100, 1),
            'roas': round(a['rev'] / a['spend'], 2) if a['spend'] else 0,
            'purchases': int(a['purch']),
            'ctr': round(a['clicks'] / a['impr'] * 100, 2) if a['impr'] else 0,
            'cpa': round(a['spend'] / a['purch']) if a['purch'] else 0,
        })
    out.sort(key=lambda x: -x['spend'])
    vid = next((x for x in out if x['cluster'] == 'Відео'), None)
    stat = {'spend': 0.0, 'rev_w': 0.0, 'purch': 0}
    for c, a in agg.items():
        if c != 'Відео':
            stat['spend'] += a['spend']
            stat['rev_w'] += a['rev']
            stat['purch'] += a['purch']
    vs = {
        'video': {'spend': round(vid['spend']) if vid else 0,
                  'pct': vid['spend_pct'] if vid else 0,
                  'roas': vid['roas'] if vid else 0,
                  'purchases': vid['purchases'] if vid else 0} if vid else None,
        'static': {'spend': round(stat['spend']),
                   'pct': round(stat['spend'] / total * 100, 1),
                   'roas': round(stat['rev_w'] / stat['spend'], 2) if stat['spend'] else 0,
                   'purchases': int(stat['purch'])},
    }
    return out, vs


def pct_delta(a, b):
    if not b:
        return None
    return round((a - b) / b * 100, 1)


def build_ai(funnel, prev, camps, clusters, vs):
    acc_roas = funnel['roas'] if funnel else 0
    pri, alerts = [], []

    if clusters:
        big_weak = [c for c in clusters if c['spend_pct'] >= 15 and c['roas'] < acc_roas]
        tops = sorted([c for c in clusters if c['roas'] >= acc_roas], key=lambda x: -x['roas'])[:3]
        if big_weak and tops:
            w = max(big_weak, key=lambda x: x['spend_pct'])
            pri.append({'id': 'P1',
                        'action': f"Перелити бюджет з «{w['cluster']}» у топ-кластери ({', '.join(t['cluster'] for t in tops)})",
                        'why': f"{w['cluster']} {w['spend_pct']}% бюджету / ROAS {w['roas']} проти {tops[0]['cluster']} {tops[0]['roas']}",
                        'impact': 'Високий', 'effort': 'Низьке',
                        'expect': '+0.3–0.7 ROAS'})

    if vs and vs.get('video') and vs['video']['roas'] >= acc_roas and vs['video']['pct'] < 20:
        pri.append({'id': 'P2', 'action': 'Подвоїти частку відео (більше 9:16 варіацій)',
                    'why': f"Відео ROAS {vs['video']['roas']} при лише {vs['video']['pct']}% бюджету",
                    'impact': 'Високий', 'effort': 'Середнє', 'expect': '+обсяг при ROAS вище середнього'})

    fat = [c for c in camps if c['fatigue']]
    if fat:
        pri.append({'id': 'P3',
                    'action': 'Ротувати креативи де частота >4 (' + ', '.join(c['name'][:22] for c in fat[:3]) + ')',
                    'why': 'Частота↑ + CTR↓ + CPC↑ = втома',
                    'impact': 'Високий', 'effort': 'Середнє', 'expect': 'CTR↑, CPC↓'})

    if funnel and funnel['frequency'] > FATIGUE_FREQ:
        alerts.append({'sev': 'mod', 'text': f"Частота акаунту {funnel['frequency']} — висока (поріг {FATIGUE_FREQ}). Ризик вигорання — освіжати креативи/аудиторії."})
    for c in camps:
        if c['fatigue']:
            alerts.append({'sev': 'mod', 'text': f"«{c['name']}»: частота {c['frequency']}, CTR {c['ctr']}% — втома, ротувати креативи."})
        if c['roas'] < 1.0 and c['spend'] > 500 and c['role'] != 'acquisition':
            alerts.append({'sev': 'cri', 'text': f"«{c['name']}»: ROAS {c['roas']} при витратах {c['spend']} ₴ — зламана/злив, ревізія."})
    for c in clusters:
        if c['spend_pct'] >= 20 and c['roas'] < BREAKEVEN_PIXEL:
            alerts.append({'sev': 'mod', 'text': f"Кластер «{c['cluster']}» з'їдає {c['spend_pct']}% бюджету при ROAS {c['roas']} (<{BREAKEVEN_PIXEL}) — скоротити."})

    summary = ''
    if funnel:
        d = ''
        if prev:
            dr = pct_delta(funnel['roas'], prev['roas'])
            if dr is not None:
                d = f" ROAS {'↑' if dr>=0 else '↓'}{abs(dr)}% до попереднього періоду."
        summary = (f"Реал ROAS ~{funnel['real_roas']} (pixel {funnel['roas']}), CPA {funnel['cpa']} ₴, "
                   f"частота {funnel['frequency']}.{d} Головні важелі — реалокація бюджету у топ-кластери, "
                   f"подвоєння відео та ротація креативів при частоті >4.")
    return {'summary': summary, 'priorities': pri, 'alerts': alerts}


def main():
    if not FB_TOKEN:
        log('❌ FB_ACCESS_TOKEN не задано')
        sys.exit(1)
    today = datetime.now(timezone.utc).date()
    until = today - timedelta(days=1)
    since = until - timedelta(days=WINDOW_DAYS - 1)
    p_until = since - timedelta(days=1)
    p_since = p_until - timedelta(days=WINDOW_DAYS - 1)
    log(f'🚀 meta-extra · acct {ACCOUNT} · {since}..{until} (prev {p_since}..{p_until})')

    try:
        name = fb_get(acct(ACCOUNT), {'fields': 'name'}).get('name', '')
    except Exception as e:
        log(f'  ⚠ account info: {e}'); name = ''

    funnel = funnel_for(since.isoformat(), until.isoformat())
    prev = None
    try:
        prev = funnel_for(p_since.isoformat(), p_until.isoformat())
    except Exception as e:
        log(f'  ⚠ prev funnel: {e}')
    camps = campaigns_for(since.isoformat(), until.isoformat())
    clusters, vs = clusters_for(since.isoformat(), until.isoformat())

    deltas = {}
    if funnel and prev:
        for k in ('spend', 'roas', 'cpa', 'ctr', 'cpm', 'frequency', 'purchases', 'link_ctr'):
            deltas[k] = pct_delta(funnel.get(k, 0), prev.get(k, 0))

    out = {
        'account': ACCOUNT, 'account_name': name,
        'generated': datetime.now(timezone.utc).isoformat(),
        'window': {'since': since.isoformat(), 'until': until.isoformat(), 'days': WINDOW_DAYS},
        'prev_window': {'since': p_since.isoformat(), 'until': p_until.isoformat()},
        'funnel': funnel, 'funnel_prev': prev, 'deltas': deltas,
        'campaigns': camps, 'clusters': clusters, 'video_vs_static': vs,
        'ai': build_ai(funnel, prev, camps, clusters, vs),
    }
    os.makedirs(os.path.dirname(OUT_PATH), exist_ok=True)
    with open(OUT_PATH, 'w', encoding='utf-8') as f:
        json.dump(out, f, ensure_ascii=False, indent=1)
    log(f'✅ wrote {OUT_PATH} · funnel={"ok" if funnel else "—"} · {len(camps)} camp · {len(clusters)} clusters · {len(out["ai"]["priorities"])} pri · {len(out["ai"]["alerts"])} alerts')


if __name__ == '__main__':
    main()
