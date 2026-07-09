#!/usr/bin/env python3
"""
Archive old ads in the current sales structure DC|01-05 (pre-launch cleanup).
Sets status=ARCHIVED on every non-archived/non-deleted ad in the target campaigns,
so old creatives don't clutter the campaigns before new-project ads are added.
SAFETY: only touches ads whose parent campaign is in TARGET_CAMPAIGNS. Archiving is
reversible (un-archive in Meta). DRY_RUN=true (default) -> report only, change nothing.
Idempotent: already-ARCHIVED ads are skipped, so it is safe to re-run to finish leftovers.
Sequential POST + retry (Meta batch API aborts most items under write throttling).
env: FB_ACCESS_TOKEN, AD_ACCOUNT_ID, DRY_RUN, FB_API_VERSION, ARCHIVE_CAMPAIGN_IDS (CSV override)
"""
import os, json, time, urllib.parse, urllib.request

TOKEN = os.environ['FB_ACCESS_TOKEN']
ACT = os.environ.get('AD_ACCOUNT_ID', '4136058269783354')
VER = os.environ.get('FB_API_VERSION', 'v21.0')
DRY = os.environ.get('DRY_RUN', 'true').lower() == 'true'
BASE = f'https://graph.facebook.com/{VER}'

DEFAULT_TARGETS = [
    '120249698602830624',  # DC | 01 Ядро · Advantage+ Sales (broad)
    '120249698605960624',  # DC | 02 Retargeting · дожим теплих
    '120249698608790624',  # DC | 03 Prospecting · свіжа кров
    '120249698612830624',  # DC | 04 Testing · інкубатор (ABO)
    '120249980882600624',  # DC | 05 Тест аудиторій (vg)
]
_env = os.environ.get('ARCHIVE_CAMPAIGN_IDS', '').strip()
TARGETS = [x.strip() for x in _env.split(',') if x.strip()] or DEFAULT_TARGETS


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


def archive_one(aid, tries=4):
    data = urllib.parse.urlencode({'access_token': TOKEN, 'status': 'ARCHIVED'}).encode()
    for t in range(tries):
        try:
            req = urllib.request.Request(f'{BASE}/{aid}', data=data, method='POST')
            with urllib.request.urlopen(req, timeout=60) as r:
                json.loads(r.read().decode())
            return True
        except Exception as e:
            if t == tries - 1:
                print(f'   FAIL {aid}: {str(e)[:120]}', flush=True)
                return False
            time.sleep(1.5 * (t + 1))
    return False


def main():
    print(f'== archive-old-ads | DRY={DRY} | account act_{ACT} | api {VER} ==', flush=True)
    print(f'Target campaigns ({len(TARGETS)}): {TARGETS}')
    all_ids = []
    for cid in TARGETS:
        try:
            ads = paged(f'{cid}/ads', {'fields': 'id,name,status,effective_status', 'limit': 200})
        except Exception as e:
            print(f'  SKIP campaign {cid}: {e}'); continue
        live = [a for a in ads if a.get('status') not in ('ARCHIVED', 'DELETED')]
        print(f'  campaign {cid}: {len(ads)} ads total, {len(live)} to archive', flush=True)
        all_ids += [a['id'] for a in live]
    print(f'TOTAL to archive: {len(all_ids)}', flush=True)
    if DRY:
        print('DRY-RUN -> nothing changed. Sample ids:', all_ids[:5])
        print('DONE'); return
    ok = fail = 0
    for i, aid in enumerate(all_ids):
        if archive_one(aid):
            ok += 1
        else:
            fail += 1
        if (i + 1) % 40 == 0:
            print(f'  progress {i+1}/{len(all_ids)} ok={ok} fail={fail}', flush=True)
        time.sleep(0.35)
    print(f'ARCHIVED ok={ok} fail={fail} of {len(all_ids)}')
    print('DONE')


if __name__ == '__main__':
    main()
