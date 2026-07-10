#!/usr/bin/env python3
"""
Fix broken X6M картинки: ARCHIVE ads with effective_status=WITH_ISSUES in DC|02-05.
Root cause: the 'картинка х6м' creatives are Advantage+ dynamic ({{product.name}}) and
render only in DC|01 Ядро (Advantage+ Sales); in standard OFFSITE_CONVERSIONS adsets they
throw the 191x100 crop delivery error. This removes those non-deliverable ads.
SAFETY: targets ONLY DC|02/03/04/05 and ONLY effective_status=WITH_ISSUES ads (the broken
картинки). Does NOT touch DC|01 Ядро or the working 'перший пост'. Reversible (un-archive).
Idempotent + throttle-robust. DRY_RUN=true -> report only.
env: FB_ACCESS_TOKEN, AD_ACCOUNT_ID, DRY_RUN, FB_API_VERSION
"""
import os, json, time, urllib.parse, urllib.request

TOKEN = os.environ['FB_ACCESS_TOKEN']
ACT = os.environ.get('AD_ACCOUNT_ID', '4136058269783354')
VER = os.environ.get('FB_API_VERSION', 'v21.0')
DRY = os.environ.get('DRY_RUN', 'true').lower() == 'true'
BASE = f'https://graph.facebook.com/{VER}'
CAMPS = ['120249698605960624', '120249698608790624', '120249698612830624', '120249980882600624']  # DC|02,03,04,05 (NOT Ядро)


def _get(url, tries=6):
    for t in range(tries):
        try:
            with urllib.request.urlopen(urllib.request.Request(url), timeout=120) as r:
                return json.loads(r.read().decode())
        except Exception:
            if t == tries - 1:
                raise
            time.sleep(2 * (t + 1))


def api_get(path, params):
    p = dict(params); p['access_token'] = TOKEN
    return _get(f'{BASE}/{path}?' + urllib.parse.urlencode(p))


def paged(path, params):
    rows = []; res = api_get(path, params)
    while True:
        rows.extend(res.get('data', []))
        nx = res.get('paging', {}).get('next')
        if not nx:
            return rows
        res = _get(nx)


def post(path, data, tries=4):
    for t in range(tries):
        try:
            b = dict(data); b['access_token'] = TOKEN
            req = urllib.request.Request(f'{BASE}/{path}', data=urllib.parse.urlencode(b).encode(), method='POST')
            with urllib.request.urlopen(req, timeout=60) as r:
                return json.loads(r.read().decode()), None
        except Exception as e:
            m = str(e)
            if hasattr(e, 'read'):
                try:
                    m = e.read().decode()[:180]
                except Exception:
                    pass
            if t == tries - 1:
                return None, m
            time.sleep(1.5 * (t + 1))
    return None, '?'


def broken():
    ids = []
    for cid in CAMPS:
        try:
            ads = paged(f'{cid}/ads', {'fields': 'id,name,effective_status', 'effective_status': '["WITH_ISSUES"]', 'limit': 300})
        except Exception as e:
            print(f'  skip fetch {cid}: {str(e)[:60]}', flush=True); continue
        ids += [a['id'] for a in ads]
    return ids


def main():
    print(f'== fix-broken-x6m | DRY={DRY} | act_{ACT} ==', flush=True)
    if DRY:
        b = broken()
        print(f'WITH_ISSUES ads to archive in DC|02-05: {len(b)} | sample {b[:5]}')
        print('DONE'); return
    no_prog = 0
    for p in range(1, 12):
        ids = broken()
        print(f'PASS {p}: remaining WITH_ISSUES {len(ids)}', flush=True)
        if not ids:
            print('ALL FIXED'); break
        ok = fail = 0
        for aid in ids:
            r, e = post(aid, {'status': 'ARCHIVED'})
            if r:
                ok += 1
            else:
                fail += 1
                print(f'   FAIL {aid}: {e}', flush=True)
            time.sleep(0.35)
        print(f'  pass {p}: ok={ok} fail={fail}', flush=True)
        no_prog = no_prog + 1 if ok == 0 else 0
        if no_prog >= 5:
            print('  stop (throttle); re-run later', flush=True); break
        time.sleep(30)
    print(f'FINAL remaining WITH_ISSUES: {len(broken())}')
    print('DONE')


if __name__ == '__main__':
    main()
