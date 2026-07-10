#!/usr/bin/env python3
"""
Duplicate the working Ядро adset into DC|02-05 as NEW ACTIVE adsets, in two phases to
avoid Meta's deep-copy size limit (subcode 1885194 'too large copy request'):
  1) copy the adset settings only (deep_copy=false) -> new adset with Ядро's correct config
  2) copy each working 'картинка х6м' ad from Ядро into the new adset (per-ad copy)
All ACTIVE. DRY_RUN=true -> plan only.
env: FB_ACCESS_TOKEN, DRY_RUN, FB_API_VERSION
"""
import os, json, time, urllib.parse, urllib.request

TOKEN = os.environ['FB_ACCESS_TOKEN']
VER = os.environ.get('FB_API_VERSION', 'v21.0')
DRY = os.environ.get('DRY_RUN', 'true').lower() == 'true'
BASE = f'https://graph.facebook.com/{VER}'

SOURCE = '120249708049950624'  # Ядро working adset
TARGETS = {
    'DC|02': '120249698605960624',
    'DC|03': '120249698608790624',
    'DC|04': '120249698612830624',
    'DC|05': '120249980882600624',
}


def _get(url):
    with urllib.request.urlopen(urllib.request.Request(url), timeout=120) as r:
        return json.loads(r.read().decode())


def get(path, params):
    p = dict(params); p['access_token'] = TOKEN
    return _get(f'{BASE}/{path}?' + urllib.parse.urlencode(p))


def post(path, data):
    b = dict(data); b['access_token'] = TOKEN
    req = urllib.request.Request(f'{BASE}/{path}', data=urllib.parse.urlencode(b).encode(), method='POST')
    try:
        with urllib.request.urlopen(req, timeout=180) as r:
            return json.loads(r.read().decode()), None
    except Exception as e:
        m = str(e)
        if hasattr(e, 'read'):
            try:
                m = e.read().decode()[:300]
            except Exception:
                pass
        return None, m


def main():
    print(f'== duplicate-yadro v2 | DRY={DRY} ==', flush=True)
    ads = get(f'{SOURCE}/ads', {'fields': 'id,name,effective_status', 'limit': 100}).get('data', [])
    cards = [a['id'] for a in ads if 'картинк' in a.get('name', '').lower() and a.get('effective_status') == 'ACTIVE']
    print(f'source working картинки: {len(cards)}', flush=True)
    if DRY:
        print(f'would copy adset (no-deep) into {list(TARGETS.values())} + {len(cards)} картинки each, ACTIVE')
        print('DONE'); return
    for name, cid in TARGETS.items():
        r, e = post(f'{SOURCE}/copies', {'campaign_id': cid, 'deep_copy': 'false', 'status_option': 'ACTIVE'})
        if not r:
            print(f'  {name}: adset-copy FAIL {e}', flush=True); continue
        new = r.get('copied_adset_id') or r.get('ad_object_id') or (r.get('ad_object_ids') or [None])[0]
        print(f'  {name}: new adset {new} | {json.dumps(r)[:160]}', flush=True)
        if not new:
            continue
        ok = 0
        for aid in cards:
            cr, ce = post(f'{aid}/copies', {'adset_id': new, 'status_option': 'ACTIVE'})
            if cr:
                ok += 1
            else:
                print(f'    ad-copy FAIL {aid}: {ce}', flush=True)
            time.sleep(0.5)
        print(f'  {name}: copied {ok}/{len(cards)} картинки into new adset', flush=True)
        time.sleep(2)
    print('DONE')


if __name__ == '__main__':
    main()
