#!/usr/bin/env python3
"""
Legacy-гігієна (research-2026-07 §2 п.10): у 332 legacy-кампаніях (created < 2026-01-01,
самі PAUSED) висять config-ACTIVE адсети (~374) і ади (~1886) — revive-ризик при випадковому
увімкненні кампанії. Скрипт ставить дітям явний status=PAUSED.
БЕЗПЕКА: кампанії ефективно PAUSED — доставка НЕ змінюється; це лише знімає ризик.
DRY_RUN=true (default) — тільки список. НЕ чіпає: кампанії 2026 року і все, що ACTIVE ефективно.
env: FB_ACCESS_TOKEN, AD_ACCOUNT_ID, DRY_RUN, CUTOFF (default 2026-01-01)
"""
import os, sys, json, time, urllib.parse, urllib.request

TOKEN = os.environ['FB_ACCESS_TOKEN']
ACT = os.environ.get('AD_ACCOUNT_ID', '4136058269783354')
VER = os.environ.get('FB_API_VERSION', 'v21.0')
DRY = os.environ.get('DRY_RUN', 'true').lower() == 'true'
CUTOFF = os.environ.get('CUTOFF', '2026-01-01')
BASE = f'https://graph.facebook.com/{VER}'


def api(path, params=None, data=None, method='GET'):
    params = dict(params or {})
    if method == 'GET':
        params['access_token'] = TOKEN
        url = f'{BASE}/{path}?' + urllib.parse.urlencode(params)
        req = urllib.request.Request(url)
    else:
        body = dict(data or {})
        body['access_token'] = TOKEN
        req = urllib.request.Request(f'{BASE}/{path}', data=urllib.parse.urlencode(body).encode(), method='POST')
    with urllib.request.urlopen(req, timeout=120) as r:
        return json.loads(r.read().decode())


def paged(path, params):
    params = dict(params)
    rows = []
    res = api(path, params)
    while True:
        rows.extend(res.get('data', []))
        nxt = res.get('paging', {}).get('next')
        if not nxt:
            return rows
        with urllib.request.urlopen(nxt, timeout=120) as r:
            res = json.loads(r.read().decode())


def main():
    print(f'== legacy-pause-children | DRY={DRY} | cutoff {CUTOFF} ==', flush=True)
    camps = paged(f'act_{ACT}/campaigns', {'fields': 'id,name,status,effective_status,created_time', 'limit': 200})
    legacy = [c for c in camps if c.get('created_time', '9999')[:10] < CUTOFF and c.get('effective_status') != 'ACTIVE']
    print(f'Кампаній всього {len(camps)}, legacy до {CUTOFF} і не-ACTIVE: {len(legacy)}')

    total_as, total_ads, changed, skips = 0, 0, 0, 0
    for i, c in enumerate(legacy):
        try:
            adsets = paged(f'{c["id"]}/adsets', {'fields': 'id,name,status', 'limit': 100})
            ads = paged(f'{c["id"]}/ads', {'fields': 'id,name,status', 'limit': 200})
        except Exception as e:
            print(f'  SKIP {c["id"]} {c["name"][:40]}: {e}')
            skips += 1
            continue
        act_as = [a for a in adsets if a.get('status') == 'ACTIVE']
        act_ads = [a for a in ads if a.get('status') == 'ACTIVE']
        total_as += len(act_as)
        total_ads += len(act_ads)
        if act_as or act_ads:
            print(f'[{i+1}/{len(legacy)}] {c["name"][:50]} ({c["created_time"][:10]}): adsets ACTIVE {len(act_as)}, ads ACTIVE {len(act_ads)}')
        if not DRY:
            for a in act_as + act_ads:
                try:
                    api(a['id'], data={'status': 'PAUSED'}, method='POST')
                    changed += 1
                    time.sleep(0.2)
                except Exception as e:
                    print(f'    FAIL {a["id"]}: {e}')
    skip_rate = skips / len(legacy) if legacy else 0
    print(f'ПІДСУМОК: config-ACTIVE адсетів {total_as}, адів {total_ads} у {len(legacy)} legacy-кампаніях. '
          f'Змінено: {changed if not DRY else "0 (dry-run)"}. Не прочитано кампаній: {skips} ({skip_rate:.0%})')
    print('DONE')

    # Без цього прогін, що впав на rate-limit і не зробив НІЧОГО, завершувався як success.
    threshold = float(os.environ.get('SKIP_THRESHOLD', '0.2'))
    if skip_rate > threshold:
        print(f'::error::Не вдалося прочитати {skip_rate:.0%} legacy-кампаній (поріг {threshold:.0%}) — '
              f'результат недостовірний. Найімовірніше rate-limit Meta: перезапустити через 2-4 год.')
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
