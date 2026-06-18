#!/usr/bin/env python3
"""
new_customers_by_ad.py — топ оголошень за кількістю НОВИХ клієнтів (customer_type='new').
Читає dashboard_deals через service-role (anon не має доступу). Друкує у лог + пише json.
ENV: SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY. Аргумент: к-ть днів (дефолт 3).
"""
import os, sys, json, datetime, urllib.request, urllib.parse
from collections import defaultdict

SB = os.getenv('SUPABASE_URL', 'https://wotghlaehnvxyeacznvv.supabase.co').rstrip('/')
KEY = os.getenv('SUPABASE_SERVICE_ROLE_KEY', '')
DAYS = int(sys.argv[1]) if len(sys.argv) > 1 else 3
AD_PREFIX = ('facebook_', 'instagram_', 'messenger_')

since = (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=DAYS)).strftime('%Y-%m-%dT%H:%M:%S')


def fetch(params):
    url = f'{SB}/rest/v1/dashboard_deals?' + urllib.parse.urlencode(params)
    req = urllib.request.Request(url, headers={'apikey': KEY, 'Authorization': f'Bearer {KEY}'})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.load(r)


def main():
    if not KEY:
        print('❌ no service key'); sys.exit(1)
    # 1) калібрування: які статуси існують у нових за період
    sample = fetch({'select': 'status', 'customer_type': 'eq.new',
                    'created_at': f'gte.{since}', 'limit': '2000'})
    statuses = defaultdict(int)
    for r in sample:
        statuses[str(r.get('status'))] += 1
    print(f'НОВІ угоди за {DAYS} дн (від {since[:10]}): {len(sample)} | статуси:', dict(statuses))
    paid_status = 'paid' if 'paid' in statuses else max(statuses, key=statuses.get) if statuses else 'paid'

    # 2) нові ОПЛАЧЕНІ угоди з полями оголошення
    rows = fetch({'select': 'utm_content,utm_medium,amount,status',
                  'customer_type': 'eq.new', 'status': f'eq.{paid_status}',
                  'created_at': f'gte.{since}', 'limit': '5000'})
    by_ad = defaultdict(lambda: {'new': 0, 'amount': 0.0, 'ad': False})
    for r in rows:
        k = r.get('utm_content') or '∅'
        med = str(r.get('utm_medium') or '')
        by_ad[k]['new'] += 1
        by_ad[k]['amount'] += float(r.get('amount') or 0)
        if med.startswith(AD_PREFIX):
            by_ad[k]['ad'] = True
    ranked = sorted(by_ad.items(), key=lambda kv: -kv[1]['new'])

    print(f"\n=== ТОП оголошень за НОВИМИ клієнтами (paid='{paid_status}', {DAYS} дн) ===")
    print(f"усього нових-оплачених: {len(rows)}")
    for k, v in ranked[:15]:
        tag = 'AD ' if v['ad'] else 'org'
        print(f"  {tag} {k[:46]:46} | нових {v['new']:3} | {round(v['amount'])} грн")

    out = {'generated': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'days': DAYS,
           'paid_status': paid_status, 'total_new_paid': len(rows),
           'top': [{'ad': k, 'new': v['new'], 'amount': round(v['amount']), 'is_ad': v['ad']} for k, v in ranked[:20]]}
    os.makedirs(os.path.join(os.path.dirname(__file__), '..', 'docs', 'meta-analytics'), exist_ok=True)
    with open(os.path.join(os.path.dirname(__file__), '..', 'docs', 'meta-analytics', 'new_by_ad.json'), 'w', encoding='utf-8') as f:
        json.dump(out, f, ensure_ascii=False, indent=1)
    print('\n✅ docs/meta-analytics/new_by_ad.json')


if __name__ == '__main__':
    main()
