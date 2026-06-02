# Facebook Ads ETL — Token Setup

Інструкція для отримання Facebook System User access token (long-lived, не expires) для ETL `sync_fb_ads.py`.

## Що це

Замінює Make.com scenario "FB Ads → ads_data". Тягне Facebook Marketing API insights кожні 15 хв і пише у Supabase `dashboard_ads_data`.

---

## Крок 1: Створити System User у Business Manager

System User — це non-human account у Meta Business Manager, який:
- Видає **non-expiring** access token (на відміну від user token що expires кожні 60 днів)
- Працює навіть якщо ти зніметеш доступ до свого user account
- Має дрібний rate-limit (200 calls/год)

**Кроки:**

1. https://business.facebook.com → твій Business Manager
2. **Налаштування бізнесу** (Business Settings) → **Користувачі** (Users) → **Системні користувачі** (System Users)
3. **Додати** → ім'я `dreamcar-dashboard-etl`, роль **Admin** (потрібно для cross-account access)
4. Create

## Крок 2: Призначити Ad Account(s)

1. Той самий System User → **Призначити активи** (Assign Assets)
2. Вибрати **Облікові записи реклами** (Ad Accounts) → твій DreamCar account
3. Дозволи: **Manage campaigns** (всі)
4. Save

> Коли додаси 2-й і 3-й ad account — повтори цей крок.

## Крок 3: Згенерувати access token

1. У System User → **Згенерувати новий токен** (Generate new token)
2. Вибрати **DreamCar App** (якщо немає → створи на developers.facebook.com → New App → Type: Business)
3. Scopes (Permissions):
   - `ads_read`
   - `business_management`
   - `read_insights`
4. Натиснути **Згенерувати** → скопіювати token (він показується ТІЛЬКИ ОДИН РАЗ)

**Token виглядає так:** `EAAxxxxxxxxxxxx...` (200+ символів)

## Крок 4: Знайти Ad Account ID

1. https://business.facebook.com/adsmanager/manage/accounts
2. Вибрати DreamCar account
3. URL виглядає так: `...act=123456789...` — це твій ID
4. Або у Налаштуваннях акаунта → ID акаунта

**Формат:** числовий `123456789` (без `act_` префіксу, скрипт додасть сам)

## Крок 5: Додати secrets у GitHub

1. https://github.com/dreamcarua/dreamcar-dashboard/settings/secrets/actions
2. **New repository secret** для кожного:

| Name | Value |
|---|---|
| `FB_ACCESS_TOKEN` | `EAAxxxxxxxxxxxx...` з кроку 3 |
| `FB_AD_ACCOUNT_IDS` | `123456789` (або `123,456` коли буде 2-3 акаунти, через кому) |

> Ці secrets вже мають `SUPABASE_URL` і `SUPABASE_SERVICE_ROLE_KEY` для існуючого MySQL ETL — їх використає і новий workflow.

## Крок 6: Запустити перший backfill

1. https://github.com/dreamcarua/dreamcar-dashboard/actions/workflows/fb-ads-sync.yml
2. **Run workflow** → mode: `initial` (тягне 30 днів) → **Run**
3. Дочекатися (~2-5 хв)
4. Перевірити Supabase: `SELECT COUNT(*), MAX(date_end) FROM dashboard_ads_data WHERE ad_account_id LIKE '%';`

Після успіху — cron `*/15 * * * *` автоматично тягне incremental (вчора+сьогодні).

---

## Як вимкнути Make.com

Тільки **після успіху** першого backfill:

1. Make.com Dashboard → Scenarios
2. Знайти "FB Ads → Dashboard" (або як він названий)
3. **Pause** (не Delete — на випадок rollback)
4. Перевірити 24 год що наш ETL стабільний
5. Через тиждень можна повністю видалити Make.com scenario

**Економія:** $9-29/міс на Make.com Pro плані.

---

## Як перевірити що працює

```sql
-- Останній sync
SELECT key, value, updated_at FROM dashboard_settings
WHERE key LIKE 'etl_fbads%' ORDER BY key;

-- Кількість записів за вчора
SELECT COUNT(*), SUM(spend), SUM(clicks)
FROM dashboard_ads_data
WHERE date_start = CURRENT_DATE - 1;

-- UTM покриття
SELECT
  COUNT(*) FILTER (WHERE utm_source IS NOT NULL) as with_utm,
  COUNT(*) FILTER (WHERE utm_source IS NULL) as without_utm
FROM dashboard_ads_data
WHERE date_start >= CURRENT_DATE - 7;
```

---

## Troubleshooting

**Token expired / OAuth error:**
- System User tokens НЕ expire, але якщо хтось видалить System User → token відминиться
- Перегенерувати token у Business Settings

**Rate limit (Code 17 / 4):**
- ETL автоматично робить exponential backoff (5/10/20/40 сек)
- Якщо часто триггерить → збільшити `*/15` → `*/30` у YAML

**UTM не парсяться:**
- Скрипт перевіряє 3 поля: `creative.link_url`, `object_story_spec.link_data.link`, `video_data.call_to_action.value.link`
- Якщо ваші ads використовують Asset Feed → треба додати парсинг `asset_feed_spec.link_urls`

**Конфлікт з legacy ads_data у MySQL:**
- Дві системи можуть працювати паралельно — наш ETL пише у Supabase, Make.com — у MySQL
- Після переходу можна вимкнути MySQL sync для ads (залишивши тільки crm_deals)

---

**Питання?** Пиши Claude у Cowork.
