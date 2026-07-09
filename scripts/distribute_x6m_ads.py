#!/usr/bin/env python3
"""
Distribute X6M creatives from DC|01 source adset into target sales adsets (DC|02-05)
then LAUNCH (activate target campaigns + adsets + source video). IDEMPOTENT.
Robust to Meta throttling: retries GETs; a throttled adset fetch is retried next pass;
loop continues until every target adset has all source creatives (or no progress for
several passes). ACTIVATION always runs. DRY_RUN=true -> report only.
env: FB_ACCESS_TOKEN, AD_ACCOUNT_ID, DRY_RUN, FB_API_VERSION
NOTE: DC|03s Stories template (120250907727750624) intentionally excluded (Vadym enables it).
"""
import os, json, time, urllib.parse, urllib.request

TOKEN = os.environ['FB_ACCESS_TOKEN']
ACT = os.environ.get('AD_ACCOUNT_ID', '4136058269783354')
VER = os.environ.get('FB_API_VERSION', 'v21.0')
DRY = os.environ.get('DRY_RUN', 'true').lower() == 'true'
BASE = f'https://graph.facebook.com/{VER}'
EFF = '["ACTIVE","PAUSED","CAMPAIGN_PAUSED","ADSET_PAUSED","PENDING_REVIEW","DISAPPROVED","WITH_ISSUES","IN_PROCESS","PREAPPROVED"]'

SOURCE_ADSET = '120249708049950624'
TARGET_ADSETS = [
    '120249883647100624',  # DC|02 Retarget
    '120250384146330624',  # DC|03 45-54 діамант
    '120250384145220624',  # DC|03 LAL 1% покупці AI
    '120250384142630624',  # DC|03 Broad чол 25-54
    '120249883739070624',  # DC|03 Cold свіжа кров v2
    '120249883742020624',  # DC|04 Test broad 18-45 v2
    '120250383367390624',  # DC|05 FB+IG Stories 35-54
    '120249980894940624',  # DC|05 LAL 1% покупці 90д
    '120249980891620624',  # DC|05 35-54 sweet spot
    '120249980888590624',  # DC|05 45-54 діамант
]
TARGET_CAMPAIGNS = ['120249698605960624', '120249698608790624', '120249698612830624', '120249980882600624']


def _get_url(url, tries=6):
    for t in range(tries):
        try:
            with urllib.request.urlopen(urllib.request.Request(url), timeout=120) as r:
                return json.loads(r.read().decode())
        except Exception:
            if t == tries - 1:
                raise
            time.sleep(2 * (t + 1))


def api_get(path, params=None):
    p = dict(params or {}); p['access_token'] = TOKEN
    return _get_url(f'{BASE}/{path}?' + urllib.parse.urlencode(p))


def paged(path, params):
    rows = []; res = api_get(path, params)
    while True:
        rows.extend(res.get('data', []))
        nx = res.get('paging', {}).get('next')
        if not nx:
            return rows
        res = _get_url(nx)


def post(path, data, tries=4):
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
                    msg = e.read().decode()[:200]
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


def activate():
    print('-- ACTIVATION --', flush=True)
    for cid in TARGET_CAMPAIGNS:
        res, err = post(cid, {'status': 'ACTIVE'})
        print(f'  campaign {cid}: {"ok" if res else err}', flush=True)
    for adset in TARGET_ADSETS:
        res, err = post(adset, {'status': 'ACTIVE'})
        print(f'  adset {adset}: {"ok" if res else err}', flush=True)


def main():
    print(f'== distribute-x6m | DRY={DRY} | act_{ACT} | api {VER} ==', flush=True)
    creatives = creatives_from(source_ads())
    print(f'Source creatives: {len(creatives)}', flush=True)
    if not creatives:
        print('No source creatives; abort.'); return

    if DRY:
        grand = 0
        for adset in TARGET_ADSETS:
            try:
                miss = [c for c, _ in creatives if c not in existing_creatives(adset)]
                print(f'  adset {adset}: missing {len(miss)}', flush=True); grand += len(miss)
            except Exception as e:
                print(f'  adset {adset}: FETCH ERR {str(e)[:60]}')
        print(f'TOTAL to create: {grand}'); print('DONE'); return

    no_prog = 0
    for p in range(1, 16):
        created = missing = fetch_ok = 0
        for adset in TARGET_ADSETS:
            try:
                ex = existing_creatives(adset); fetch_ok += 1
            except Exception as e:
                print(f'  skip fetch {adset}: {str(e)[:70]}', flush=True)
                missing += len(creatives); continue
            for cid, name in creatives:
                if cid in ex:
                    continue
                res, err = post(f'act_{ACT}/ads', {'name': name, 'adset_id': adset,
                                                    'creative': json.dumps({'creative_id': cid}), 'status': 'ACTIVE'})
                if res and res.get('id'):
                    created += 1
                else:
                    missing += 1
                    print(f'   FAIL {adset} {cid}: {err}', flush=True)
                time.sleep(0.5)
        print(f'PASS {p}: created={created} still_missing~{missing} fetch_ok={fetch_ok}/{len(TARGET_ADSETS)}', flush=True)
        if missing == 0 and fetch_ok == len(TARGET_ADSETS):
            break
        no_prog = no_prog + 1 if created == 0 else 0
        if no_prog >= 6:
            print('  6 passes no progress -> stop (throttle); re-run later', flush=True); break
        time.sleep(60)

    activate()

    try:
        for a in source_ads():
            if a.get('status') != 'ACTIVE':
                post(a['id'], {'status': 'ACTIVE'})
    except Exception:
        pass

    for adset in TARGET_ADSETS:
        try:
            print(f'  FINAL adset {adset}: {len(existing_creatives(adset))}', flush=True)
        except Exception:
            pass
    print('DONE')


if __name__ == '__main__':
    main()
