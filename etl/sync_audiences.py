#!/usr/bin/env python3
"""
Нічний синк аудиторій Supabase → Meta Custom Audiences (research-2026-07, важіль №5 external).

Що робить:
  1. Тягне з dashboard_deals (status='pay') три сегменти:
     - purchasers_all            → "DC · AUTO · Покупці (всі)"          [exclusion для acquisition]
     - purchasers_current_cycle  → "DC · AUTO · Покупці поточного циклу" [exclusion/дожим]
     - top20_ltv (365д)          → "DC · AUTO · Топ-20% LTV"             [value seed]
  2. Створює CA якщо нема (create-if-missing по імені), повний REPLACE даних щоночі
     (hashed EMAIL_SHA256 + PHONE_SHA256, батчі 5000, usersreplace sessions).
  3. Створює LAL від top20: "DC · AUTO · LAL 1% Top-LTV" і "DC · AUTO · LAL 1-3% Top-LTV".
  4. Best effort: engagement-аудиторії (IG engagers 30д, Page engaged 30д, video viewers 75%).
     Помилки тут НЕ валять скрипт — лише лог.

Env: FB_ACCESS_TOKEN, SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY,
     AD_ACCOUNT_ID (default 4136058269783354), DRY_RUN (default true)
"""
import os, sys, json, time, hashlib, re
import requests

FB_TOKEN = os.environ['FB_ACCESS_TOKEN']
ACT = os.environ.get('AD_ACCOUNT_ID', '4136058269783354')
VER = os.environ.get('FB_API_VERSION', 'v21.0')
SB_URL = os.environ.get('SUPABASE_URL', 'https://wotghlaehnvxyeacznvv.supabase.co')
SB_KEY = os.environ['SUPABASE_SERVICE_ROLE_KEY']
DRY = os.environ.get('DRY_RUN', 'true').lower() == 'true'
PAGE_ID = os.environ.get('PAGE_ID', '1676843282640684')
IG_USER = os.environ.get('IG_USER_ID', '17841403783002317')
BASE = f'https://graph.facebook.com/{VER}'


def log(m):
    print(f'[{time.strftime("%H:%M:%S")}] {m}', flush=True)


def sb_rpc_sql(query):
    """Читання через PostgREST: тут використовуємо тільки select-и на таблиці."""
    r = requests.post(f'{SB_URL}/rest/v1/rpc/exec_sql_readonly', json={'q': query},
                      headers={'apikey': SB_KEY, 'Authorization': f'Bearer {SB_KEY}'}, timeout=120)
    if r.status_code == 404:
        return None  # rpc відсутній — підемо REST-фільтрами
    r.raise_for_status()
    return r.json()


def sb_select(path):
    r = requests.get(f'{SB_URL}/rest/v1/{path}', headers={'apikey': SB_KEY, 'Authorization': f'Bearer {SB_KEY}'}, timeout=180)
    r.raise_for_status()
    return r.json()


def fb(method, path, **kw):
    p = kw.pop('params', {})
    p['access_token'] = FB_TOKEN
    r = requests.request(method, f'{BASE}/{path}', params=p if method == 'GET' else {'access_token': FB_TOKEN},
                         data=kw.pop('data', None), timeout=180)
    try:
        j = r.json()
    except Exception:
        j = {'raw': r.text[:300]}
    if r.status_code >= 400:
        raise RuntimeError(f'FB {method} {path}: {json.dumps(j)[:400]}')
    return j


# ---------- сегменти ----------
def norm_email(e):
    e = (e or '').strip().lower()
    return e if e and '@' in e else None


def norm_phone(p):
    d = re.sub(r'\D', '', p or '')
    if not d:
        return None
    if d.startswith('0') and len(d) == 10:
        d = '38' + d
    if d.startswith('80') and len(d) == 11:
        d = '3' + d
    return d if len(d) >= 10 else None


def sha(v):
    return hashlib.sha256(v.encode()).hexdigest() if v else ''


def fetch_segments():
    log('Тягну платежі з Supabase (paged REST)...')
    rows, offset, page = [], 0, 1000  # PostgREST server cap = 1000/запит
    while True:
        chunk = sb_select(f'dashboard_deals?select=customer_email,customer_phone,amount,paid_at&status=eq.pay&paid_at=not.is.null&order=paid_at.desc&limit={page}&offset={offset}')
        rows.extend(chunk)
        if len(rows) % 20000 < page:
            log(f'  ...{len(rows)}')
        if len(chunk) < page:
            break
        offset += page
    log(f'Всього платежів: {len(rows)}')

    # активний цикл: dashboard_projects status=active, найсвіжіший date_start
    projs = sb_select('dashboard_projects?select=code,date_start,status&status=eq.active&order=date_start.desc&limit=1')
    cycle_start = projs[0]['date_start'] if projs else None
    log(f'Активний цикл: {projs[0]["code"] if projs else "нема"} (з {cycle_start})')

    from collections import defaultdict
    per_cust = defaultdict(lambda: {'email': None, 'phone': None, 'total365': 0.0, 'cycle': False})
    now = time.time()
    for r in rows:
        e, p = norm_email(r.get('customer_email')), norm_phone(r.get('customer_phone'))
        if not e and not p:
            continue
        key = e or p
        c = per_cust[key]
        c['email'] = c['email'] or e
        c['phone'] = c['phone'] or p
        paid = r.get('paid_at') or ''
        try:
            ts = time.mktime(time.strptime(paid[:10], '%Y-%m-%d'))
        except Exception:
            ts = 0
        amt = float(r.get('amount') or 0)
        if amt > 0 and amt < 100000 and now - ts < 365 * 86400:
            c['total365'] += amt
        if cycle_start and paid[:10] >= cycle_start:
            c['cycle'] = True

    custs = list(per_cust.values())
    seg_all = [(c['email'], c['phone']) for c in custs]
    seg_cycle = [(c['email'], c['phone']) for c in custs if c['cycle']]
    payers365 = sorted([c for c in custs if c['total365'] > 0], key=lambda x: -x['total365'])
    top_n = max(100, int(len(payers365) * 0.2))
    seg_top = [(c['email'], c['phone']) for c in payers365[:top_n]]
    log(f'Сегменти: all={len(seg_all)} · cycle={len(seg_cycle)} · top20%365d={len(seg_top)} (поріг топу: {payers365[top_n-1]["total365"] if payers365 else 0:.0f} грн)')
    return {'DC · AUTO · Покупці (всі)': seg_all,
            'DC · AUTO · Покупці поточного циклу': seg_cycle,
            'DC · AUTO · Топ-20% LTV': seg_top}


# ---------- Meta CA ----------
def existing_audiences():
    res, out, after = {}, [], None
    path = f'act_{ACT}/customaudiences'
    params = {'fields': 'id,name,subtype,approximate_count_lower_bound', 'limit': 200}
    while True:
        j = fb('GET', path, params=dict(params, **({'after': after} if after else {})))
        out.extend(j.get('data', []))
        after = j.get('paging', {}).get('cursors', {}).get('after')
        if not after or not j.get('data'):
            break
    for a in out:
        res[a['name']] = a
    return res


def ensure_custom(name, existing):
    if name in existing:
        return existing[name]['id'], False
    if DRY:
        log(f'DRY: створив би CA "{name}"')
        return None, True
    j = fb('POST', f'act_{ACT}/customaudiences', data={
        'name': name, 'subtype': 'CUSTOM', 'customer_file_source': 'USER_PROVIDED_ONLY',
        'description': 'Auto-sync з Supabase щоночі (research-2026-07)'})
    log(f'CA created: {name} -> {j["id"]}')
    return j['id'], True


def replace_users(aud_id, pairs):
    """Повний replace: schema EMAIL_SHA256+PHONE_SHA256, батчі 5000."""
    data = [[sha(e), sha(p)] for e, p in pairs]
    if DRY:
        log(f'DRY: залив би {len(data)} юзерів у {aud_id}')
        return
    session_id = int(time.time()) % 2147480000
    B = 5000
    n_batches = max(1, (len(data) + B - 1) // B)
    for i in range(n_batches):
        chunk = data[i * B:(i + 1) * B]
        payload = {'schema': ['EMAIL_SHA256', 'PHONE_SHA256'], 'data': chunk}
        session = {'session_id': session_id, 'batch_seq': i + 1, 'last_batch_flag': i == n_batches - 1}
        fb('POST', f'{aud_id}/usersreplace', data={'payload': json.dumps(payload), 'session': json.dumps(session)})
        log(f'  replace batch {i+1}/{n_batches} ({len(chunk)})')
        time.sleep(0.4)


def ensure_lal(name, seed_id, spec, existing):
    if name in existing:
        return existing[name]['id']
    if DRY:
        log(f'DRY: створив би LAL "{name}" від {seed_id} spec={spec}')
        return None
    j = fb('POST', f'act_{ACT}/customaudiences', data={
        'name': name, 'subtype': 'LOOKALIKE', 'origin_audience_id': seed_id,
        'lookalike_spec': json.dumps(spec)})
    log(f'LAL created: {name} -> {j["id"]}')
    return j['id']


def ensure_engagement(name, rule, existing):
    if name in existing:
        return existing[name]['id']
    if DRY:
        log(f'DRY: створив би ENGAGEMENT "{name}"')
        return None
    try:
        j = fb('POST', f'act_{ACT}/customaudiences', data={
            'name': name, 'subtype': 'ENGAGEMENT', 'rule': json.dumps(rule),
            'description': 'Auto-created (research-2026-07)'})
        log(f'ENG created: {name} -> {j["id"]}')
        return j['id']
    except Exception as e:
        log(f'ENG skip "{name}": {e}')
        return None


def eng_rule(source_type, source_id, event, days):
    return {'inclusions': {'operator': 'or', 'rules': [{
        'event_sources': [{'type': source_type, 'id': source_id}],
        'retention_seconds': days * 86400,
        'filter': {'operator': 'and', 'filters': [{'field': 'event', 'operator': '=', 'value': event}]}}]}}


def main():
    log(f'== audience-sync | act {ACT} | DRY={DRY} ==')
    segs = fetch_segments()
    existing = existing_audiences()
    log(f'В акаунті аудиторій: {len(existing)}')

    ids = {}
    for name, pairs in segs.items():
        aud_id, _ = ensure_custom(name, existing)
        if aud_id:
            try:
                replace_users(aud_id, pairs)
            except Exception as e:
                if '1870145' in str(e) or '2650' in str(e):
                    log(f'SKIP replace "{name}": аудиторія ще оновлюється з попереднього запуску (не критично)')
                else:
                    log(f'REPLACE FAIL "{name}": {e}')
        ids[name] = aud_id

    top_id = ids.get('DC · AUTO · Топ-20% LTV')
    if top_id or DRY:
        ensure_lal('DC · AUTO · LAL 1% Top-LTV', top_id, {'ratio': 0.01, 'country': 'UA'}, existing)
        ensure_lal('DC · AUTO · LAL 1-3% Top-LTV', top_id, {'starting_ratio': 0.01, 'ratio': 0.03, 'country': 'UA'}, existing)

    # best effort engagement
    ensure_engagement('DC · AUTO · IG engagers 30д', eng_rule('ig_business', IG_USER, 'ig_business_profile_all', 30), existing)
    ensure_engagement('DC · AUTO · Page engaged 30д', eng_rule('page', PAGE_ID, 'page_engaged', 30), existing)
    for ev in ('video_watched_75_percent', 'video_view_75_percent', 'video_completed'):
        if ensure_engagement('DC · AUTO · Video viewers 75% 90д', eng_rule('page', PAGE_ID, ev, 90), existing):
            break

    log('SUMMARY ' + json.dumps(ids, ensure_ascii=False))
    log('DONE')


if __name__ == '__main__':
    main()
