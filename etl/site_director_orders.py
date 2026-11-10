#!/usr/bin/env python3
"""site_director_orders.py — paid deals for site-director, one line per deal, no personal data.

Output (stdout and orders.tsv): paid_at_utc <TAB> channel <TAB> amount <TAB> currency.
Paid = status 'pay'. A paid deal without paid_at is counted at created_at (trap: paid_at can be null).
Channel: 'utm' when utm_source is set, otherwise 'unknown' (the CRM does not record organic vs direct).
Read-only: GET on PostgREST only. ENV: SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY. Args: since until (YYYY-MM-DD, UTC, until exclusive).
"""
import json, os, re, sys, urllib.parse, urllib.request

SB = os.environ['SUPABASE_URL'].rstrip('/')
KEY = os.environ['SUPABASE_SERVICE_ROLE_KEY']
DAY = re.compile(r'^\d{4}-\d{2}-\d{2}$')


def fetch(params):
    out, off, page = [], 0, 1000
    while True:
        q = dict(params, limit=str(page), offset=str(off), order='id.asc')
        req = urllib.request.Request(f'{SB}/rest/v1/dashboard_deals?' + urllib.parse.urlencode(q),
                                     headers={'apikey': KEY, 'Authorization': f'Bearer {KEY}'})
        with urllib.request.urlopen(req, timeout=60) as r:
            chunk = json.load(r)
        out += chunk
        if len(chunk) < page:
            return out
        off += page


def main():
    since, until = sys.argv[1], sys.argv[2]
    if not (DAY.match(since) and DAY.match(until)):
        sys.exit('since/until must be YYYY-MM-DD')
    sel = 'id,paid_at,created_at,utm_source,amount,currency'
    rows = fetch({'select': sel, 'status': 'eq.pay', 'and': f'(paid_at.gte.{since},paid_at.lt.{until})'})
    rows += fetch({'select': sel, 'status': 'eq.pay', 'paid_at': 'is.null',
                   'and': f'(created_at.gte.{since},created_at.lt.{until})'})
    lines = []
    for r in rows:
        ts = (r.get('paid_at') or r.get('created_at') or '')[:19].replace('T', ' ')
        ch = 'utm' if (r.get('utm_source') or '').strip() else 'unknown'
        lines.append(f"{ts}\t{ch}\t{float(r.get('amount') or 0):.2f}\t{(r.get('currency') or 'UAH').upper()}")
    lines.sort()
    with open('orders.tsv', 'w') as f:
        f.write('\n'.join(lines) + ('\n' if lines else ''))
    print(f'{len(lines)} paid deals {since}..{until}', file=sys.stderr)


if __name__ == '__main__':
    main()
