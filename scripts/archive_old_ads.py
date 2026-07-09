#!/usr/bin/env python3
"""
Archive old ads in the current sales structure DC|01-05 (pre-launch cleanup).
Sets status=ARCHIVED on every non-archived/non-deleted ad in the target campaigns,
so old creatives don't clutter the campaigns before new-project ads are added.
SAFETY: only touches ads whose parent campaign is in TARGET_CAMPAIGNS. Archiving is
reversible (un-archive in Meta). DRY_RUN=true (default) -> report only, change nothing.
env: FB_ACCESS_TOKEN, AD_ACCOUNT_ID, DRY_RUN, FB_API_VERSION, ARCHIVE_CAMPAIGN_IDS (CSV override)
"""
import os, json, time, urllib.parse, urllib.request

TOKEN = os.environ['FB_ACCESS_TOKEN']
ACT = os.environ.get('AD_ACCOUNT_ID', '4136058269783354')
VER = os.environ.get('FB_API_VERSION', 'v21.0')
DRY = os.environ.get('DRY_RUN', 'true').lower() == 'true'
BASE = f'https://graph.facebook.com/{VER}'

# DC|01-05 current sales structure (Ядро, Retargeting, Prospecting, Testing, Тест аудиторій)
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


def batch_archive(ad_ids):
    ok = fail = 0
    for i in range(0, len(ad_ids), 50):
        chunk = ad_ids[i:i+50]
        batch = [{'method': 'POST', 'relative_url': f'{aid}?status=ARCHIVED'} for aid in chunk]
        data = urllib.parse.urlencode({'access_token': TOKEN, 'batch': json.dumps(batch)}).encode()
        req = urllib.request.Request(BASE + '/', data=data, method='POST')
        with urllib.request.urlopen(req, timeout=180) as r:
            resp = json.loads(r.read().decode())
        for item in resp:
            if item and item.get('code') == 200:
                ok += 1
            else:
                fail += 1
                print('   FAIL', str(item)[:160])
        print(f'  batch {i//50+1}: ok={ok} fail={fail}', flush=True)
        time.sleep(1)
    return ok, fail


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
    ok, fail = batch_archive(all_ids)
    print(f'ARCHIVED ok={ok} fail={fail} of {len(all_ids)}')
    print('DONE')


if __name__ == '__main__':
    main()
