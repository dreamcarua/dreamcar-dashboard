#!/usr/bin/env python3
"""
Distribute X6M creatives from the DC|01 source adset into the target sales adsets
(DC|02 Retargeting, DC|03 Prospecting, DC|04 Testing, DC|05 Тест аудиторій), then
LAUNCH: activate target campaigns + adsets, and turn on the source video in DC|01.
IDEMPOTENT: for each target adset only creates ads for creatives it doesn't already
have (safe to re-run under Meta throttling). DRY_RUN=true -> report only.
env: FB_ACCESS_TOKEN, AD_ACCOUNT_ID, DRY_RUN, FB_API_VERSION
NOTE: DC|03s Stories template (120250907727750624) is intentionally excluded (Vadym enables it).
"""
import os, json, time, urllib.parse, urllib.request

TOKEN = os.environ['FB_ACCESS_TOKEN']
ACT = os.environ.get('AD_ACCOUNT_ID', '4136058269783354')
VER = os.environ.get('FB_API_VERSION', 'v21.0')
DRY = os.environ.get('DRY_RUN', 'true').lower() == 'true'
BASE = f'https://graph.facebook.com/{VER}'
EFF = '["ACTIVE","PAUSED","CAMPAIGN_PAUSED","ADSET_PAUSED","PENDING_REVIEW","DISAPPROVED","WITH_ISSUES","IN_PROCESS","PREAPPROVED"]'

SOURCE_ADSET = '120249708049950624'  # DC|01 Ядро · Broad UA 18-45 · A+A · VALUE
TARGET_ADSETS = [
    '120249883647100624',  # DC|02 Retarget · сайт 180д+30д
    '120250384146330624',  # DC|03 · 45-54 діамант
    '120250384145220624',  # DC|03 · LAL 1% покупці AI
    '120250384142630624',  # DC|03 · Broad чол 25-54
    '120249883739070624',  # DC|03 · Cold свіжа кров v2
    '120249883742020624',  # DC|04 · Test broad 18-45 v2
    '120250383367390624',  # DC|05 · FB+IG Stories 35-54
    '120249980894940624',  # DC|05 · LAL 1% покупці 90д
    '120249980891620624',  # DC|05 · 35-54 sweet spot
    '120249980888590624',  # DC|05 · 45-54 діамант
]
TARGET_CAMPAIGNS = [
    '120249698605960624',  # DC|02 Retargeting
    '120249698608790624',  # DC|03 Prospecting
    '120249698612830624',  # DC|04 Testing
    '120249980882600624',  # DC|05 Тест аудиторій
]


def api_get(path, params=None):
    p = dict(params or {}); p['access_token'] = TOKEN
    with urllib.request.urlopen(urllib.request.Request(f'{BASE}/{path}?' + urllib.parse.urlencode(p)), timeout=120) as r:
        return json.loads(r.read().decode())


def paged(path, params):
    rows = []; res = api_get(path, params)
    while True:
        rows.extend(res.get('data', []))
        nx = res.get('paging', {}).get('next')
        if not nx:
            return rows
        with urllib.request.urlopen(nx, timeout=120) as r:
            res = json.loads(r.read().decode())


def post(path, data, tries=3):
    for t in range(tries):
        try:
            body = dict(data); body['access_token'] = TOKEN
            req = urllib.request.Request(f'{BASE}/{path}', data=urllib.parse.urlencode(body).encode(), method='POST')
            with urllib.request.urlopen(req, timeout=60) as r:
                return json.loads(r.read().decode()), None
        except Exception as e:
            msg = str(e)
            if hasattr(e, 'read'):
                try:
                    msg = e.read().decode()[:220]
                except Exception:
                    pass
            if t == tries - 1:
                return None, msg
            time.sleep(1.5 * (t + 1))
    return None, '?'


def source_ads():
    return paged(f'{SOURCE_ADSET}/ads', {'fields': 'id,name,status,creative{id}', 'effective_status': EFF, 'limit': 100})


def creatives_from(ads):
    out, seen = [], set()
    for a in ads:
        cid = (a.get('creative') or {}).get('id')
        if cid and cid not in seen:
            seen.add(cid); out.append((cid, a.get('name', 'X6M')))
    return out


def existing_creatives(adset):
    ads = paged(f'{adset}/ads', {'fields': 'creative{id}', 'effective_status': EFF, 'limit': 200})
    return {(a.get('creative') or {}).get('id') for a in ads if a.get('creative')}


def main():
    print(f'== distribute-x6m | DRY={DRY} | act_{ACT} | api {VER} ==', flush=True)
    src = source_ads()
    creatives = creatives_from(src)
    print(f'Source creatives: {len(creatives)} ids={[c for c, _ in creatives]}', flush=True)
    if len(creatives) < 1:
        print('No source creatives found; abort.'); return

    if DRY:
        grand = 0
        for adset in TARGET_ADSETS:
            ex = existing_creatives(adset)
            miss = [c for c, _ in creatives if c not in ex]
            print(f'  adset {adset}: has {len(ex)} of source, missing {len(miss)}', flush=True)
            grand += len(miss)
        print(f'TOTAL ads to create: {grand} across {len(TARGET_ADSETS)} adsets (+activate {len(TARGET_CAMPAIGNS)} campaigns)')
        print('DONE'); return

    # 1) create missing ads (idempotent loop for throttle resilience)
    for p in range(1, 9):
        created = fail = 0
        for adset in TARGET_ADSETS:
            ex = existing_creatives(adset)
            for cid, name in creatives:
                if cid in ex:
                    continue
                res, err = post(f'act_{ACT}/ads', {'name': name, 'adset_id': adset,
                                                    'creative': json.dumps({'creative_id': cid}), 'status': 'ACTIVE'})
                if res and res.get('id'):
                    created += 1
                else:
                    fail += 1
                    print(f'   FAIL adset {adset} cre {cid}: {err}', flush=True)
                time.sleep(0.4)
        print(f'PASS {p}: created {created} fail {fail}', flush=True)
        if created == 0:
            break
        time.sleep(20)

    # 2) launch: activate target campaigns then adsets
    for cid in TARGET_CAMPAIGNS:
        res, err = post(cid, {'status': 'ACTIVE'})
        print(f'  activate campaign {cid}: {"ok" if res else err}', flush=True)
    for adset in TARGET_ADSETS:
        res, err = post(adset, {'status': 'ACTIVE'})
        print(f'  activate adset {adset}: {"ok" if res else err}', flush=True)

    # 3) turn on the source video (any paused ad in DC|01 source adset)
    for a in src:
        if a.get('status') != 'ACTIVE':
            res, err = post(a['id'], {'status': 'ACTIVE'})
            print(f'  activate source ad {a["id"]} ({a.get("name","")[:20]}): {"ok" if res else err}', flush=True)

    # 4) final report
    for adset in TARGET_ADSETS:
        ex = existing_creatives(adset)
        print(f'  FINAL adset {adset}: {len(ex)} source creatives present', flush=True)
    print('DONE')


if __name__ == '__main__':
    main()
