#!/usr/bin/env python3
"""
Duplicate the WORKING Ядро adset (with its ads + correct settings) into DC|02-05 as NEW
PAUSED adsets via Meta's /copies endpoint (same as Ads Manager 'Дублювати'). This carries
Ядро's correct placement config so the картинки deliver (they error in the API-built adsets).
Copies are PAUSED for review. DRY_RUN=true -> plan only.
env: FB_ACCESS_TOKEN, DRY_RUN, FB_API_VERSION
"""
import os, json, time, urllib.parse, urllib.request

TOKEN = os.environ['FB_ACCESS_TOKEN']
VER = os.environ.get('FB_API_VERSION', 'v21.0')
DRY = os.environ.get('DRY_RUN', 'true').lower() == 'true'
BASE = f'https://graph.facebook.com/{VER}'

SOURCE = '120249708049950624'  # DC|01 Ядро · Broad UA 18-45 · A+A · VALUE (working)
TARGETS = {
    'DC|02 Retargeting': '120249698605960624',
    'DC|03 Prospecting': '120249698608790624',
    'DC|04 Testing': '120249698612830624',
    'DC|05 Тест аудиторій': '120249980882600624',
}


def post(path, data):
    b = dict(data); b['access_token'] = TOKEN
    req = urllib.request.Request(f'{BASE}/{path}', data=urllib.parse.urlencode(b).encode(), method='POST')
    with urllib.request.urlopen(req, timeout=180) as r:
        return json.loads(r.read().decode())


def main():
    print(f'== duplicate-yadro | DRY={DRY} | source adset {SOURCE} ==', flush=True)
    if DRY:
        print(f'Would deep-copy adset {SOURCE} (PAUSED) into: {list(TARGETS.values())}')
        print('DONE'); return
    for name, cid in TARGETS.items():
        try:
            r = post(f'{SOURCE}/copies', {'campaign_id': cid, 'deep_copy': 'true', 'status_option': 'PAUSED'})
            print(f'  {name} ({cid}): OK -> {json.dumps(r)[:200]}', flush=True)
        except Exception as e:
            m = str(e)
            if hasattr(e, 'read'):
                try:
                    m = e.read().decode()[:400]
                except Exception:
                    pass
            print(f'  {name} ({cid}): FAIL {m}', flush=True)
        time.sleep(2)
    print('DONE')


if __name__ == '__main__':
    main()
