#!/usr/bin/env python3
"""cycle20_report.py — READ-ONLY фінансовий зріз циклу №20 (BMW X6M, 09.07–02.08.2026 Kyiv).
Друкує агрегати у лог Action: виручка по днях, база vs нові, атрибуція реклами (utm_medium fb/ig),
чек-мікс, промо-дні, dashboard_project_pnl. ENV: SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY.
"""
import os, json, urllib.request, urllib.parse
from collections import defaultdict
from datetime import datetime, timedelta, timezone

SB = os.getenv('SUPABASE_URL', 'https://wotghlaehnvxyeacznvv.supabase.co').rstrip('/')
KEY = os.getenv('SUPABASE_SERVICE_ROLE_KEY', '')
AD_PREFIX = ('facebook_', 'instagram_', 'messenger_')
# Київ = UTC+3 влітку. Цикл: 09.07 00:00 — 02.08 23:59:59 Kyiv
UTC_FROM = '2026-07-08T21:00:00'
UTC_TO = '2026-08-02T21:00:00'
KYIV = timezone(timedelta(hours=3))


def fetch(table, params, page=1000):
    rows, offset = [], 0
    while True:
        p = dict(params); p['limit'] = str(page); p['offset'] = str(offset)
        url = f'{SB}/rest/v1/{table}?' + urllib.parse.urlencode(p)
        req = urllib.request.Request(url, headers={'apikey': KEY, 'Authorization': f'Bearer {KEY}'})
        with urllib.request.urlopen(req, timeout=90) as r:
            chunk = json.load(r)
        rows.extend(chunk)
        if len(chunk) < page:
            return rows
        offset += page


def kyiv_day(iso):
    s = iso.replace('Z', '+00:00')
    if '+' not in s[10:] and '-' not in s[11:]:
        s += '+00:00'
    return datetime.fromisoformat(s).astimezone(KYIV).strftime('%d.%m')


def main():
    if not KEY:
        print('NO KEY'); return
    # калібрування статусів
    sample = fetch('dashboard_deals', {'select': 'status', 'created_at': f'gte.{UTC_FROM}', 'limit': '2000'}, 2000)[:2000]
    st = defaultdict(int)
    for r in sample:
        st[str(r.get('status'))] += 1
    print('STATUSES sample:', dict(st))
    paid = 'paid' if 'paid' in st else (max(st, key=st.get) if st else 'paid')

    rows = fetch('dashboard_deals', {
        'select': 'created_at,amount,status,customer_type,utm_source,utm_medium,utm_content,utm_term',
        'status': f'eq.{paid}', 'created_at': f'gte.{UTC_FROM}', 'order': 'created_at.asc'})
    rows = [r for r in rows if r.get('created_at', '') < '2026-08-02T21:30:00' or True]
    rows = [r for r in rows if r.get('created_at', '')[:19] < UTC_TO]
    print(f'PAID deals in cycle: {len(rows)}')

    by_day = defaultdict(lambda: [0, 0.0])          # day -> [count, revenue]
    by_day_ads = defaultdict(lambda: [0, 0.0])      # ads-attributed
    by_day_new = defaultdict(lambda: [0, 0.0])      # customer_type=new
    ctype = defaultdict(lambda: [0, 0.0])
    med = defaultdict(lambda: [0, 0.0])
    amounts = defaultdict(lambda: [0, 0.0])
    term = defaultdict(lambda: [0, 0.0])
    tot = [0, 0.0]
    for r in rows:
        d = kyiv_day(r['created_at']); a = float(r.get('amount') or 0)
        m = str(r.get('utm_medium') or '')
        c = str(r.get('customer_type') or '∅')
        by_day[d][0] += 1; by_day[d][1] += a
        tot[0] += 1; tot[1] += a
        ctype[c][0] += 1; ctype[c][1] += a
        mk = m.split('_')[0] if m else '∅'
        med[('ADS:' + m) if m.startswith(AD_PREFIX) else ('other:' + (mk or '∅'))][0] += 1
        med[('ADS:' + m) if m.startswith(AD_PREFIX) else ('other:' + (mk or '∅'))][1] += a
        if m.startswith(AD_PREFIX):
            by_day_ads[d][0] += 1; by_day_ads[d][1] += a
        if c == 'new':
            by_day_new[d][0] += 1; by_day_new[d][1] += a
        amounts[round(a)][0] += 1; amounts[round(a)][1] += a
        t = str(r.get('utm_term') or '∅')
        term[t][0] += 1; term[t][1] += a

    print('\n=== BY KYIV DAY: deals | revenue | ads-deals | ads-rev | new-deals ===')
    for d in sorted(by_day, key=lambda x: (x[3:5], x[0:2])):
        c, rev = by_day[d]; ac, arev = by_day_ads.get(d, [0, 0]); nc, _ = by_day_new.get(d, [0, 0])
        print(f'  {d} | {c:5d} | {rev:10.0f} | {ac:5d} | {arev:9.0f} | {nc:4d}')
    print(f'TOTAL: deals {tot[0]} | revenue {tot[1]:.0f} UAH')

    print('\n=== CUSTOMER TYPE ===')
    for k, v in sorted(ctype.items(), key=lambda kv: -kv[1][1]):
        print(f'  {k:12} | {v[0]:6d} | {v[1]:10.0f}')

    print('\n=== UTM MEDIUM groups (top 20 by revenue) ===')
    for k, v in sorted(med.items(), key=lambda kv: -kv[1][1])[:20]:
        print(f'  {k[:40]:40} | {v[0]:6d} | {v[1]:10.0f}')

    print('\n=== UTM TERM (executor marks, top 10) ===')
    for k, v in sorted(term.items(), key=lambda kv: -kv[1][1])[:10]:
        print(f'  {k[:30]:30} | {v[0]:6d} | {v[1]:10.0f}')

    print('\n=== CHECK MIX (top 15 amounts by revenue) ===')
    for k, v in sorted(amounts.items(), key=lambda kv: -kv[1][1])[:15]:
        print(f'  {k:7d} UAH | n={v[0]:6d} | {v[1]:10.0f}')

    # P&L
    for t in ('dashboard_project_pnl', 'dashboard_projects', 'active_cycles'):
        try:
            pr = fetch(t, {'select': '*', 'limit': '40'}, 40)[:40]
            print(f'\n=== {t} (rows {len(pr)}) ===')
            for r in pr[:40]:
                s = json.dumps(r, ensure_ascii=False)
                if any(x in s.lower() for x in ('x6', 'х6', '20', 'bmw')) or len(pr) <= 12:
                    print(' ', s[:400])
        except Exception as e:
            print(f'\n=== {t}: ERR {str(e)[:120]} ===')


if __name__ == '__main__':
    main()
