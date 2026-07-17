#!/usr/bin/env python3
"""
Архівація legacy-кампаній: прибирає з Ads Manager старі кампанії попередніх проєктів/років.

ЩО РОБИТЬ: ставить status=ARCHIVED кампаніям, які (а) створені ДО cutoff,
(б) НЕ активні ефективно, (в) не входять у поточну структуру DC|. Архів ховає
кампанію разом з усіма адсетами й оголошеннями з робочої видачі.

БЕЗПЕКА (кожна умова обов'язкова):
  - created_time < CUTOFF
  - effective_status != ACTIVE  (доставка не змінюється — вони вже стоять)
  - назва не містить DC| / DC |  (поточна бойова структура)
  - id не у PROTECT_IDS
  - вже ARCHIVED/DELETED — пропускаємо (ідемпотентно)

ВІДМІННІСТЬ ВІД legacy_pause_children.py: той паузить ДІТЕЙ (знімає revive-ризик),
цей ХОВАЄ кампанії з очей. Рекомендований порядок: спершу pause-children, потім archive.

ЧОМУ EXIT CODE ВАЖЛИВИЙ: Meta ріже rate-limit'ом і повертає HTTP 400. Якщо просто
ловити помилку й йти далі — прогін, що не зробив НІЧОГО, завершиться як success.
Тут: backoff на rate-limit + якщо доля помилок > FAIL_THRESHOLD → exit 1.

DRY_RUN=true (default) -> лише звіт.
env: FB_ACCESS_TOKEN, AD_ACCOUNT_ID, DRY_RUN, CUTOFF, FAIL_THRESHOLD, PROTECT_IDS, FB_API_VERSION
"""
import os, sys, json, time, urllib.parse, urllib.request

TOKEN = os.environ['FB_ACCESS_TOKEN']
ACT = os.environ.get('AD_ACCOUNT_ID', '4136058269783354')
VER = os.environ.get('FB_API_VERSION', 'v21.0')
DRY = os.environ.get('DRY_RUN', 'true').lower() == 'true'
CUTOFF = os.environ.get('CUTOFF', '2026-01-01')
FAIL_THRESHOLD = float(os.environ.get('FAIL_THRESHOLD', '0.2'))
BASE = f'https://graph.facebook.com/{VER}'

# Ніколи не чіпати, навіть якщо формально підходять під фільтр
PROTECT_IDS = set(filter(None, os.environ.get('PROTECT_IDS', '').split(','))) | {
    '120244383019000624',  # На НОВИХ продажі — acquisition, захищена
}
SKIP_NAME_MARKERS = ('DC|', 'DC |')

# Коди rate-limit / throttling у Meta Graph API
RATE_CODES = {4, 17, 32, 613, 80000, 80004}


def _req(req, tries=5):
    """HTTP з backoff на rate-limit. Кидає виняток лише якщо це НЕ throttle."""
    delay = 5
    for attempt in range(tries):
        try:
            with urllib.request.urlopen(req, timeout=120) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            body = {}
            try:
                body = json.loads(e.read().decode())
            except Exception:
                pass
            code = body.get('error', {}).get('code')
            msg = body.get('error', {}).get('message', str(e))
            if code in RATE_CODES and attempt < tries - 1:
                print(f'    rate-limit (code {code}), пауза {delay}s…', flush=True)
                time.sleep(delay)
                delay *= 2
                continue
            raise RuntimeError(f'HTTP {e.code} code={code}: {msg[:120]}')
    raise RuntimeError('rate-limit: вичерпано спроби')


def api(path, params=None, data=None, method='GET'):
    if method == 'GET':
        p = dict(params or {}); p['access_token'] = TOKEN
        return _req(urllib.request.Request(f'{BASE}/{path}?' + urllib.parse.urlencode(p)))
    body = dict(data or {}); body['access_token'] = TOKEN
    return _req(urllib.request.Request(f'{BASE}/{path}', data=urllib.parse.urlencode(body).encode(), method='POST'))


def paged(path, params):
    rows = []
    res = api(path, params)
    while True:
        rows.extend(res.get('data', []))
        nxt = res.get('paging', {}).get('next')
        if not nxt:
            return rows
        res = _req(urllib.request.Request(nxt))


def main():
    print(f'== archive-legacy-campaigns | DRY={DRY} | cutoff {CUTOFF} ==', flush=True)
    camps = paged(f'act_{ACT}/campaigns',
                  {'fields': 'id,name,status,effective_status,created_time', 'limit': 200})
    print(f'Кампаній в акаунті: {len(camps)}', flush=True)

    targets, skipped = [], {'свіжі': 0, 'ACTIVE': 0, 'DC|': 0, 'захищені': 0, 'вже в архіві': 0}
    for c in camps:
        name = c.get('name', '')
        if c.get('status') in ('ARCHIVED', 'DELETED'):
            skipped['вже в архіві'] += 1; continue
        if c.get('created_time', '9999')[:10] >= CUTOFF:
            skipped['свіжі'] += 1; continue
        if c.get('effective_status') == 'ACTIVE':
            skipped['ACTIVE'] += 1; continue
        if any(m in name for m in SKIP_NAME_MARKERS):
            skipped['DC|'] += 1; continue
        if c['id'] in PROTECT_IDS:
            skipped['захищені'] += 1; continue
        targets.append(c)

    print('Пропущено: ' + ' · '.join(f'{k} {v}' for k, v in skipped.items()), flush=True)
    print(f'ДО АРХІВУ: {len(targets)} кампаній', flush=True)
    for c in targets[:15]:
        print(f'  {c["created_time"][:10]}  {c["id"]}  {c["name"][:55]}', flush=True)
    if len(targets) > 15:
        print(f'  … і ще {len(targets)-15}', flush=True)

    if not targets:
        print('ПІДСУМОК: нічого архівувати.'); print('DONE'); return 0

    if DRY:
        print(f'ПІДСУМОК: {len(targets)} кандидатів. Змінено: 0 (dry-run)'); print('DONE'); return 0

    done, failed = 0, []
    for i, c in enumerate(targets, 1):
        try:
            api(c['id'], data={'status': 'ARCHIVED'}, method='POST')
            done += 1
            if i % 25 == 0:
                print(f'  [{i}/{len(targets)}] архівовано {done}, помилок {len(failed)}', flush=True)
            time.sleep(0.3)
        except Exception as e:
            failed.append((c['id'], str(e)))
            print(f'  FAIL {c["id"]} {c["name"][:35]}: {e}', flush=True)

    rate = len(failed) / len(targets)
    print(f'ПІДСУМОК: архівовано {done} з {len(targets)}. Помилок: {len(failed)} ({rate:.0%})')
    print('DONE')

    # ГОЛОВНЕ: не даємо мовчазному провалу виглядати як success
    if rate > FAIL_THRESHOLD:
        print(f'::error::Доля помилок {rate:.0%} > порогу {FAIL_THRESHOLD:.0%} — прогін вважається невдалим. '
              f'Найімовірніше rate-limit Meta: перезапустити через 2-4 год.')
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
