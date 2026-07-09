#!/usr/bin/env python3
"""
Archive old ads in the current sales structure DC|01-05 (pre-launch cleanup).
Sets status=ARCHIVED on every non-archived ad in the target campaigns, so old
creatives don't clutter the campaigns before new-project ads are added.
SAFETY: only touches ads whose parent campaign is in TARGET_CAMPAIGNS. Reversible.
Idempotent + self-looping: re-fetches remaining each pass and retries until 0
(Meta throttles bulk writes on this account, so it grinds through with backoff).
DRY_RUN=true (default) -> report only.
env: FB_ACCESS_TOKEN, AD_ACCOUNT_ID, DRY_RUN, FB_API_VERSION, ARCHIVE_CAMPAIGN_IDS (CSV)
"""
import os, json, time, urllib.parse, urllib.request

TOKEN = os.environ['FB_ACCESS_TOKEN']
ACT = os.environ.get('AD_ACCOUNT_ID', '4136058269783354')
VER = os.environ.get('FB_API_VERSION', 'v21.0')
DRY = os.environ.get('DRY_RUN', 'true').lower() == 'true'
BASE = f'https://graph.facebook.com/{VER}'

DEFAULT_TARGETS = [
    '120249698602830624',  # DC | 01 Ядро
    '120249698605960624',  # DC | 02 Retargeting
    '120249698608790624',  # DC | 03 Prospecting
    '120249698612830624',  # DC | 04 Testing (ABO)
    '120249980882600624',  # DC | 05 Тест аудиторій
]
_env = os.environ.get('ARCHIVE_CAMPAIGN_IDS', '').strip()
TARGETS = [x.strip() for x in _env.split(',') if x.strip()] or DEFAULT_TARGETS
MAX_PASSES = int(os.environ.get('MAX_PASSES', '40'))


def api_get(path, params=None):
    p = dict(params or {}); p['access_token'] = TOKEN
    url = f'{BASE}/{path}?' + urllib.parse.urlencode(p)
    with urllib.request.urlopen(urllib.request.Request(url), timeout=120) as r:
        return json.loads(r.read().decode())


def paged(path, params):
    rows = []; res = api_get(path, params)
    while True:
        rows.extend(res.get('data', []))
        nxt = res.get('paging', {}).get('next')
        if not nxt:
            return rows
        with urllib.request.urlopen(nxt, timeout=120) as r:
            res = json.loads(r.read().decode())


def live_ids():
    ids = []
    for cid in TARGETS:
        try:
            ads = paged(f'{cid}/ads', {'fields': 'id,status', 'limit': 200})
        except Exception as e:
            print(f'  SKIP fetch {cid}: {e}', flush=True); continue
        ids += [a['id'] for a in ads if a.get('status') not in ('ARCHIVED', 'DELETED')]
    return ids


def archive_one(aid, tries=3):
    data = urllib.parse.urlencode({'access_token': TOKEN, 'status': 'ARCHIVED'}).encode()
    for t in range(tries):
        try:
            req = urllib.request.Request(f'{BASE}/{aid}', data=data, method='POST')
            with urllib.request.urlopen(req, timeout=60) as r:
                json.loads(r.read().decode())
            return True
        except Exception:
            if t == tries - 1:
                return False
            time.sleep(1.2 * (t + 1))
    return False


def main():
    print(f'== archive-old-ads | DRY={DRY} | act_{ACT} | api {VER} | targets {len(TARGETS)} ==', flush=True)
    if DRY:
        ids = live_ids()
        print(f'TOTAL to archive: {len(ids)} | sample {ids[:5]}')
        print('DONE'); return
    zero_streak = 0
    for p in range(1, MAX_PASSES + 1):
        ids = live_ids()
        print(f'PASS {p}: remaining {len(ids)}', flush=True)
        if not ids:
            print('ALL ARCHIVED'); break
        ok = fail = 0
        for aid in ids:
            if archive_one(aid):
                ok += 1
            else:
                fail += 1
            time.sleep(0.3)
        print(f'  pass {p}: ok={ok} fail={fail}', flush=True)
        zero_streak = zero_streak + 1 if ok == 0 else 0
        if zero_streak >= 4:
            print('  4 passes no progress -> stop (Meta throttle); re-run later to finish'); break
        time.sleep(30 if ok else 90)
    rem = live_ids()
    print(f'FINAL remaining={len(rem)}')
    print('DONE')


if __name__ == '__main__':
    main()
