#!/usr/bin/env python3
"""
ETL Meta Marketing API -> docs/meta-analytics/meta-extra.json  (v2 — періоди + фільтри)

Ізольований pipeline для 4 аналітичних табів /meta-analytics/ (воронка/втома/формати/AI)
з фільтрами: ПЕРІОД (today/yesterday/7d/30d/this_month/this_year/custom),
ПЛАТФОРМА (FB/IG), СТАТЬ, ТИП КАМПАНІЇ, ФОРМАТ (відео/статика).

Структура виходу:
  periods.<key> = { window, funnel, deltas, campaigns, clusters, video_vs_static,
                    by_platform, by_gender, ai }
  daily = [ {date, spend, impressions, reach, clicks, link_clicks, purchases, revenue} ]  (для custom range)
  thresholds = { min_conv, soft_conv, min_impr }  (нормалізація: відсікати шумні мікро-вибірки)
  + back-compat top-level (= periods.last_30d): funnel/campaigns/clusters/video_vs_static/ai/deltas/window

Env: FB_ACCESS_TOKEN, FB_AD_ACCOUNT_IDS|META_EXTRA_ACCOUNT, META_EXTRA_OUT
"""
import os, sys, json, time
from datetime import datetime, timedelta, timezone
import requests

FB_TOKEN = os.getenv('FB_ACCESS_TOKEN', '')
FB_API = os.getenv('FB_API_VERSION', 'v21.0')
_accs = [a.strip() for a in os.getenv('FB_AD_ACCOUNT_IDS', '').split(',') if a.strip()]
ACCOUNT = os.getenv('META_EXTRA_ACCOUNT') or (_accs[0] if _accs else '4136058269783354')
OUT_PATH = os.getenv('META_EXTRA_OUT', 'docs/meta-analytics/meta-extra.json')

BREAKEVEN_PIXEL = 2.9
REAL_FACTOR = 0.70
FATIGUE_FREQ = 4.0
PURCH = ['omni_purchase', 'offsite_conversion.fb_pixel_purchase', 'purchase']
LINKC = ['link_click']


def log(m):
    print(f'[{datetime.now(timezone.utc):%H:%M:%S}] {m}', flush=True)


def acct(a):
    return a if str(a).startswith('act_') else f'act_{a}'


def fb_get(path, params=None):
    url = f'https://graph.facebook.com/{FB_API}/{path}'
    params = dict(params or {}); params['access_token'] = FB_TOKEN
    for attempt in range(5):
        r = requests.get(url, params=params, timeout=90)
        if r.status_code == 200:
            return r.json()
        if r.status_code in (4, 17, 32, 613) or 'throttl' in r.text.lower() or 'rate limit' in r.text.lower():
            w = 2 ** attempt * 5; log(f'  ⏳ rate limit {w}s'); time.sleep(w); continue
        log(f'  ❌ FB {r.status_code}: {r.text[:300]}'); r.raise_for_status()
    raise RuntimeError(f'FB API failed: {path}')


def num(v):
    try:
        return float(v)
    except (TypeError, ValueError):
        return 0.0


def _av(items, types):
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


def insights(level, fields, tp, extra=None, breakdowns=None):
    """tp = {'date_preset': X} or {'time_range': {...}}"""
    params = {'level': level, 'fields': ','.join(fields), 'limit': 500}
    if 'date_preset' in tp:
        params['date_preset'] = tp['date_preset']
    else:
        params['time_range'] = json.dumps(tp['time_range'])
    if extra:
        params.update(extra)
    if breakdowns:
        params['breakdowns'] = ','.join(breakdowns)
    rows, nxt = [], None
    while True:
        data = requests.get(nxt, timeout=90).json() if nxt else fb_get(f'{acct(ACCOUNT)}/insights', params)
        rows.extend(data.get('data', []))
        nxt = (data.get('paging') or {}).get('next')
        if not nxt:
            break
    return rows


def funnel_metrics(r):
    spend = num(r.get('spend')); impr = num(r.get('impressions')); reach = num(r.get('reach'))
    clicks = num(r.get('clicks')); link = _av(r.get('actions'), LINKC)
    purch = _av(r.get('actions'), PURCH); rev = _av(r.get('action_values'), PURCH)
    return {
        'spend': round(spend), 'impressions': int(impr), 'reach': int(reach),
        'frequency': round(num(r.get('frequency')) or (impr / reach if reach else 0), 2),
        'clicks': int(clicks), 'link_clicks': int(link), 'purchases': int(purch), 'revenue': round(rev),
        'ctr': round(num(r.get('ctr')) or (clicks / impr * 100 if impr else 0), 2),
        'link_ctr': round(link / impr * 100, 2) if impr else 0,
        'cpc': round(num(r.get('cpc')) or (spend / clicks if clicks else 0), 2),
        'cpc_link': round(spend / link, 2) if link else 0,
        'cpm': round(num(r.get('cpm')) or (spend / impr * 1000 if impr else 0), 2),
        'cvr': round(purch / link * 100, 1) if link else 0,
        'cpa': round(spend / purch, 1) if purch else 0,
        'aov': round(rev / purch) if purch else 0,
        'roas': round(rev / spend, 2) if spend else 0,
        'real_roas': round(rev / spend * REAL_FACTOR, 2) if spend else 0,
    }


FUNNEL_FIELDS = ['spend', 'impressions', 'reach', 'frequency', 'clicks', 'ctr', 'cpc', 'cpm',
                 'actions', 'action_values']


def funnel_for(tp):
    rows = insights('account', FUNNEL_FIELDS, tp)
    if not rows:
        return None, None
    r = rows[0]
    win = {'since': r.get('date_start'), 'until': r.get('date_stop')}
    return funnel_metrics(r), win


def breakdown_funnel(tp, dim, keymap=None):
    rows = insights('account', FUNNEL_FIELDS, tp, breakdowns=[dim])
    out = []
    for r in rows:
        k = r.get(dim)
        if keymap:
            k = keymap.get(k, k)
        m = funnel_metrics(r)
        if m['spend'] <= 0:
            continue
        m['key'] = k
        out.append(m)
    out.sort(key=lambda x: -x['spend'])
    return out


def campaigns_for(tp):
    rows = insights('campaign', ['campaign_name', 'spend', 'cpm', 'cpc', 'ctr', 'frequency',
                                 'reach', 'impressions', 'actions', 'action_values'], tp)
    out = []
    for r in rows:
        spend = num(r.get('spend'))
        if spend < 1:
            continue
        purch = _av(r.get('actions'), PURCH); rev = _av(r.get('action_values'), PURCH)
        name = r.get('campaign_name', ''); low = name.lower()
        role = ('acquisition' if 'нов' in low else
                'retarget' if ('retarget' in low or 'ретаргет' in low or 'дожим' in low) else
                'prospecting' if 'prospect' in low else 'core')
        freq = round(num(r.get('frequency')), 2)
        out.append({'name': name, 'spend': round(spend), 'cpm': round(num(r.get('cpm')), 1),
                    'cpc': round(num(r.get('cpc')), 2), 'ctr': round(num(r.get('ctr')), 2),
                    'frequency': freq, 'reach': int(num(r.get('reach'))), 'purchases': int(purch),
                    'roas': round(rev / spend, 2) if spend else 0, 'role': role,
                    'fatigue': freq > FATIGUE_FREQ})
    out.sort(key=lambda x: -x['spend'])
    return out


def cluster_of(name):
    s = (name or '').lower()
    if 'відео' in s or 'video' in s: return 'Відео'
    if 'iphone' in s or 'айфон' in s: return 'iPhone/айфон'
    if s.startswith('ktm') or 'ktm' in s or 'мото' in s: return 'KTM/мото'
    if 'мустанг' in s or 'фінал пост' in s or 'фінал 2 пост' in s or 'мем пост' in s: return 'Mustang-пост'
    if 'картинка рекламна' in s: return 'Картинка-нова'
    if 'картинка' in s: return 'Картинка-нумерована'
    if 'пост х2' in s or 'пост x2' in s or 'тариф' in s or 'пакет' in s or 'промокод' in s: return 'Пост x2/тариф'
    return 'Інше'


VIDEO_CLUSTERS = {'Відео'}


def clusters_for(tp):
    rows = insights('ad', ['ad_name', 'spend', 'impressions', 'ctr', 'actions', 'action_values'],
                    tp, extra={'limit': 800})
    agg = {}
    for r in rows:
        spend = num(r.get('spend'))
        if spend <= 0:
            continue
        c = cluster_of(r.get('ad_name'))
        a = agg.setdefault(c, {'spend': 0.0, 'rev': 0.0, 'purch': 0.0, 'clicks': 0.0, 'impr': 0.0})
        a['spend'] += spend; a['rev'] += _av(r.get('action_values'), PURCH)
        a['purch'] += _av(r.get('actions'), PURCH); a['impr'] += num(r.get('impressions'))
        a['clicks'] += num(r.get('impressions')) * num(r.get('ctr')) / 100.0
    total = sum(a['spend'] for a in agg.values()) or 1
    out = []
    for c, a in agg.items():
        out.append({'cluster': c, 'spend': round(a['spend']), 'spend_pct': round(a['spend'] / total * 100, 1),
                    'roas': round(a['rev'] / a['spend'], 2) if a['spend'] else 0, 'purchases': int(a['purch']),
                    'ctr': round(a['clicks'] / a['impr'] * 100, 2) if a['impr'] else 0,
                    'cpa': round(a['spend'] / a['purch']) if a['purch'] else 0,
                    'is_video': c in VIDEO_CLUSTERS})
    out.sort(key=lambda x: -x['spend'])
    vid = next((x for x in out if x['cluster'] == 'Відео'), None)
    st = {'spend': 0.0, 'rev': 0.0, 'purch': 0}
    for c, a in agg.items():
        if c not in VIDEO_CLUSTERS:
            st['spend'] += a['spend']; st['rev'] += a['rev']; st['purch'] += a['purch']
    vs = {'video': ({'spend': round(vid['spend']), 'pct': vid['spend_pct'], 'roas': vid['roas'],
                     'purchases': vid['purchases']} if vid else None),
          'static': {'spend': round(st['spend']), 'pct': round(st['spend'] / total * 100, 1),
                     'roas': round(st['rev'] / st['spend'], 2) if st['spend'] else 0, 'purchases': int(st['purch'])}}
    return out, vs


def pct_delta(a, b):
    return None if not b else round((a - b) / b * 100, 1)


def build_ai(funnel, prev, camps, clusters, vs):
    acc = funnel['roas'] if funnel else 0
    pri, alerts = [], []
    if clusters:
        weak = [c for c in clusters if c['spend_pct'] >= 15 and c['roas'] < acc]
        tops = sorted([c for c in clusters if c['roas'] >= acc], key=lambda x: -x['roas'])[:3]
        if weak and tops:
            w = max(weak, key=lambda x: x['spend_pct'])
            pri.append({'id': 'P1', 'action': f"Перелити бюджет з «{w['cluster']}» у топ ({', '.join(t['cluster'] for t in tops)})",
                        'why': f"{w['cluster']} {w['spend_pct']}% / ROAS {w['roas']} проти {tops[0]['cluster']} {tops[0]['roas']}",
                        'impact': 'Високий', 'effort': 'Низьке', 'expect': '+0.3–0.7 ROAS'})
    if vs and vs.get('video') and vs['video']['roas'] >= acc and vs['video']['pct'] < 20:
        pri.append({'id': 'P2', 'action': 'Подвоїти частку відео (9:16 варіації)',
                    'why': f"Відео ROAS {vs['video']['roas']} при {vs['video']['pct']}% бюджету",
                    'impact': 'Високий', 'effort': 'Середнє', 'expect': '+обсяг при ROAS вище середнього'})
    fat = [c for c in camps if c['fatigue']]
    if fat:
        pri.append({'id': 'P3', 'action': 'Ротувати креативи де частота >4 (' + ', '.join(c['name'][:22] for c in fat[:3]) + ')',
                    'why': 'Частота↑ + CTR↓ + CPC↑ = втома', 'impact': 'Високий', 'effort': 'Середнє', 'expect': 'CTR↑, CPC↓'})
    if funnel and funnel['frequency'] > FATIGUE_FREQ:
        alerts.append({'sev': 'mod', 'text': f"Частота {funnel['frequency']} — висока (поріг {FATIGUE_FREQ}). Освіжати креативи/аудиторії."})
    for c in camps:
        if c['fatigue']:
            alerts.append({'sev': 'mod', 'text': f"«{c['name']}»: частота {c['frequency']}, CTR {c['ctr']}% — втома."})
        if c['roas'] < 1.0 and c['spend'] > 500 and c['role'] != 'acquisition':
            alerts.append({'sev': 'cri', 'text': f"«{c['name']}»: ROAS {c['roas']} при {c['spend']} ₴ — зламана, ревізія."})
    for c in clusters:
        if c['spend_pct'] >= 20 and c['roas'] < BREAKEVEN_PIXEL:
            alerts.append({'sev': 'mod', 'text': f"Кластер «{c['cluster']}» {c['spend_pct']}% бюджету при ROAS {c['roas']} — скоротити."})
    summary = ''
    if funnel:
        d = ''
        if prev:
            dr = pct_delta(funnel['roas'], prev['roas'])
            if dr is not None:
                d = f" ROAS {'↑' if dr >= 0 else '↓'}{abs(dr)}% до попер. періоду."
        summary = (f"Реал ROAS ~{funnel['real_roas']} (pixel {funnel['roas']}), CPA {funnel['cpa']} ₴, "
                   f"частота {funnel['frequency']}.{d} Важелі — реалокація у топ-кластери, відео, ротація при частоті >4.")
    return {'summary': summary, 'priorities': pri, 'alerts': alerts}


def bundle(tp, prev_tp):
    funnel, win = funnel_for(tp)
    if funnel is None:
        return None
    prev = None
    if prev_tp:
        try:
            prev, _ = funnel_for(prev_tp)
        except Exception as e:
            log(f'  ⚠ prev: {e}')
    deltas = {}
    if funnel and prev:
        for k in ('spend', 'roas', 'cpa', 'ctr', 'cpm', 'frequency', 'purchases', 'link_ctr'):
            deltas[k] = pct_delta(funnel.get(k, 0), prev.get(k, 0))
    camps = campaigns_for(tp)
    clusters, vs = clusters_for(tp)
    try:
        by_platform = breakdown_funnel(tp, 'publisher_platform')
    except Exception as e:
        log(f'  ⚠ platform bd: {e}'); by_platform = []
    try:
        by_gender = breakdown_funnel(tp, 'gender')
    except Exception as e:
        log(f'  ⚠ gender bd: {e}'); by_gender = []
    return {'window': win, 'funnel': funnel, 'deltas': deltas, 'campaigns': camps,
            'clusters': clusters, 'video_vs_static': vs,
            'by_platform': by_platform, 'by_gender': by_gender,
            'ai': build_ai(funnel, prev, camps, clusters, vs)}


def daily_series(days=395):
    today = datetime.now(timezone.utc).date()
    since = (today - timedelta(days=days)).isoformat()
    until = today.isoformat()
    rows = insights('account', ['spend', 'impressions', 'reach', 'clicks', 'actions', 'action_values'],
                    {'time_range': {'since': since, 'until': until}}, extra={'time_increment': 1})
    out = []
    for r in rows:
        out.append({'date': r.get('date_start'), 'spend': round(num(r.get('spend')), 2),
                    'impressions': int(num(r.get('impressions'))), 'reach': int(num(r.get('reach'))),
                    'clicks': int(num(r.get('clicks'))), 'link_clicks': int(_av(r.get('actions'), LINKC)),
                    'purchases': int(_av(r.get('actions'), PURCH)), 'revenue': round(_av(r.get('action_values'), PURCH), 2)})
    out.sort(key=lambda x: x['date'] or '')
    return out


def manual_prev(date_preset, length_days):
    """Попереднє рівне вікно для 7d/30d (закінчується вчора-Nднів)."""
    today = datetime.now(timezone.utc).date()
    end = today - timedelta(days=length_days + 1)
    start = end - timedelta(days=length_days - 1)
    return {'time_range': {'since': start.isoformat(), 'until': end.isoformat()}}


def main():
    if not FB_TOKEN:
        log('❌ FB_ACCESS_TOKEN не задано'); sys.exit(1)
    log(f'🚀 meta-extra v2 · acct {ACCOUNT}')
    try:
        name = fb_get(acct(ACCOUNT), {'fields': 'name'}).get('name', '')
    except Exception as e:
        log(f'  ⚠ acct info: {e}'); name = ''

    presets = [
        ('today',      {'date_preset': 'today'},      {'date_preset': 'yesterday'}),
        ('yesterday',  {'date_preset': 'yesterday'},  manual_prev('yesterday', 1)),
        ('last_7d',    {'date_preset': 'last_7d'},    manual_prev('last_7d', 7)),
        ('last_30d',   {'date_preset': 'last_30d'},   manual_prev('last_30d', 30)),
        ('this_month', {'date_preset': 'this_month'}, {'date_preset': 'last_month'}),
        ('this_year',  {'date_preset': 'this_year'},  {'date_preset': 'last_year'}),
    ]
    periods = {}
    for key, tp, prev_tp in presets:
        try:
            b = bundle(tp, prev_tp)
            if b:
                periods[key] = b
                log(f'  ✓ {key}: spend {b["funnel"]["spend"]} roas {b["funnel"]["roas"]} ({len(b["campaigns"])} camp, {len(b["clusters"])} cl)')
            else:
                log(f'  — {key}: no data')
        except Exception as e:
            log(f'  ❌ {key}: {e}')

    try:
        daily = daily_series()
        log(f'  ✓ daily: {len(daily)} days')
    except Exception as e:
        log(f'  ⚠ daily: {e}'); daily = []

    base = periods.get('last_30d') or {}
    out = {
        'account': ACCOUNT, 'account_name': name,
        'generated': datetime.now(timezone.utc).isoformat(),
        'default_period': 'last_30d',
        'thresholds': {'min_conv': 5, 'soft_conv': 3, 'min_impr': 1500},
        'periods': periods, 'daily': daily,
        # back-compat (= last_30d) для старого фронта
        'window': base.get('window'), 'funnel': base.get('funnel'), 'deltas': base.get('deltas'),
        'campaigns': base.get('campaigns'), 'clusters': base.get('clusters'),
        'video_vs_static': base.get('video_vs_static'), 'ai': base.get('ai'),
    }
    os.makedirs(os.path.dirname(OUT_PATH), exist_ok=True)
    with open(OUT_PATH, 'w', encoding='utf-8') as f:
        json.dump(out, f, ensure_ascii=False, indent=1)
    log(f'✅ wrote {OUT_PATH} · {len(periods)} periods · {len(daily)} daily')


if __name__ == '__main__':
    main()
