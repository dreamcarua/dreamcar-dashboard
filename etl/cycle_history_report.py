#!/usr/bin/env python3
"""cycle_history_report.py — READ-ONLY кросциклова вибірка №16–№20 + когорти нових.
ENV: SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY.
"""
import os, json, urllib.request, urllib.parse
from collections import defaultdict
from datetime import datetime, timedelta, timezone

SB = os.getenv('SUPABASE_URL', 'https://wotghlaehnvxyeacznvv.supabase.co').rstrip('/')
KEY = os.getenv('SUPABASE_SERVICE_ROLE_KEY', '')
KYIV = timezone(timedelta(hours=3))
# цикли: name, kyiv start date, kyiv end date (включно)
CYCLES = [
    ('16 GLE', '2026-02-06', '2026-03-01'),
    ('17 X5H', '2026-03-24', '2026-04-19'),
    ('18 eTron', '2026-05-06', '2026-05-31'),
    ('19 Mustang', '2026-06-15', '2026-06-28'),
    ('20 X6M', '2026-07-09', '2026-08-02'),
]


def utc_bounds(d1, d2):
    a = datetime.fromisoformat(d1 + 'T00:00:00').replace(tzinfo=KYIV).astimezone(timezone.utc)
    b = (datetime.fromisoformat(d2 + 'T00:00:00') + timedelta(days=1)).replace(tzinfo=KYIV).astimezone(timezone.utc)
    return a.strftime('%Y-%m-%dT%H:%M:%S'), b.strftime('%Y-%m-%dT%H:%M:%S')


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


def main():
    if not KEY:
        print('NO KEY'); return
    # 0) які колонки-ідентифікатори клієнта існують
    one = fetch('dashboard_deals', {'select': '*', 'order': 'created_at.desc'}, 1)[:1]
    cols = list(one[0].keys()) if one else []
    print('COLUMNS:', cols)
    idcol = None
    for c in ('client_id', 'contact_id', 'customer_id', 'phone', 'email', 'user_id', 'sendpulse_contact_id'):
        if c in cols:
            idcol = c; break
    print('ID COLUMN:', idcol)

    AD_PREFIX = ('facebook_', 'instagram_', 'messenger_')
    cyc_clients = {}
    cyc_new_clients = {}
    print('\n=== ЦИКЛИ №16–№20 (paid, Kyiv-вікна) ===')
    print('цикл | днів | угод | виручка | сер.чек | нових | виручка нових | %бази | ads-угод | ads-виручка')
    for name, d1, d2 in CYCLES:
        a, b = utc_bounds(d1, d2)
        sel = 'created_at,amount,customer_type,utm_medium' + (',' + idcol if idcol else '')
        rows = fetch('dashboard_deals', {'select': sel, 'status': 'eq.pay',
                                         'and': f'(created_at.gte.{a},created_at.lt.{b})',
                                         'order': 'created_at.asc'})
        days = (datetime.fromisoformat(d2) - datetime.fromisoformat(d1)).days + 1
        n = len(rows); rev = sum(float(r.get('amount') or 0) for r in rows)
        new = [r for r in rows if r.get('customer_type') == 'new']
        nrev = sum(float(r.get('amount') or 0) for r in new)
        ads = [r for r in rows if str(r.get('utm_medium') or '').startswith(AD_PREFIX)]
        arev = sum(float(r.get('amount') or 0) for r in ads)
        base_share = (rev - nrev) / rev * 100 if rev else 0
        print(f'{name:10} | {days:3} | {n:6} | {rev:10.0f} | {rev/n if n else 0:6.0f} | {len(new):5} | {nrev:9.0f} | {base_share:4.1f}% | {len(ads):5} | {arev:9.0f}')
        if idcol:
            cyc_clients[name] = set(str(r.get(idcol)) for r in rows if r.get(idcol))
            cyc_new_clients[name] = set(str(r.get(idcol)) for r in new if r.get(idcol))

    if idcol:
        print('\n=== КОГОРТИ: нові циклу N, що купили у наступних циклах ===')
        names = [c[0] for c in CYCLES]
        for i, nm in enumerate(names[:-1]):
            newset = cyc_new_clients.get(nm, set())
            if not newset:
                print(f'{nm}: нових з id немає'); continue
            line = f'{nm}: нових {len(newset)}'
            for j in range(i + 1, len(names)):
                inter = len(newset & cyc_clients.get(names[j], set()))
                line += f' | у {names[j]}: {inter} ({inter/len(newset)*100:.0f}%)'
            print(line)
        print('\n=== АКТИВНІ ПОКУПЦІ по циклах (унікальні id) + перетини сусідніх ===')
        for i, nm in enumerate(names):
            s = cyc_clients.get(nm, set())
            prev = cyc_clients.get(names[i - 1], set()) if i else set()
            ret = len(s & prev) / len(prev) * 100 if prev else 0
            print(f'{nm}: активних {len(s)}' + (f' | повернулись з попереднього: {len(s & prev)} ({ret:.0f}% попереднього)' if prev else ''))


if __name__ == '__main__':
    main()
