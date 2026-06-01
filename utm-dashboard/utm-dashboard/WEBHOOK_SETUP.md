# 🔧 Налаштування системи webhook

## 📋 Що було реалізовано

### 1. ✅ Створена таблиця `webhook_log` в MySQL
- Зберігає всі вхідні webhook запити (CRM + Реклама)
- Логує сирі дані, оброблені дані, помилки, час обробки
- Індекси для швидкого пошуку

### 2. ✅ Створений `webhook_crm.php` - обробник CRM подій
- **URL:** `https://dreamcar.ai-platform.space/dashboard/utm-dashboard/webhook_crm.php`
- **Підтримує 3 типи подій:**
  - `new` - нова сделка (title: "new")
  - `pay` - успішна оплата (title: "pay")
  - `fail` - неуспішна оплата (stepName_deal: "fail")
- **Особливості:**
  - Ігнорує поля з суфіксом `_deal`
  - Використовує `deal_id` як унікальний ідентифікатор
  - Логує кожен запит в `webhook_log`
  - Повертає JSON відповідь з результатом

### 3. ✅ Покращений `handler_bulk.php` - обробник масових даних
- **URL:** `https://dreamcar.ai-platform.space/dashboard/utm-dashboard/handler_bulk.php`
- Приймає JSON з полями `crm_data` та/або `ads_data`
- Логує всі запити в `webhook_log`
- Підтримує HTML та JSON формат відповіді (`?format=html`)

### 4. ✅ Фільтр аномальних сум
- Додано в `CrmDeal.php` фільтр `amount_uah < 1000000`
- Виключає транзакції більше 1 млн UAH з розрахунків

### 5. ✅ Скрипт імпорту історичних даних
- **URL:** `https://dreamcar.ai-platform.space/dashboard/utm-dashboard/scripts/import_historical_data.php`
- Підтримує CSV (CRM) та JSON (Реклама)
- Можливість переопределити дату для всіх записів

### 6. ✅ Скрипт перевірки цілісності даних
- **URL:** `https://dreamcar.ai-platform.space/dashboard/utm-dashboard/scripts/verify_data_integrity.php`
- Перевіряє дублікати, пусті UTM, аномальні суми
- Порівнює розрахунки з дашбордом

### 7. ✅ Інтерфейс перегляду webhook логів
- **URL:** `https://dreamcar.ai-platform.space/dashboard/utm-dashboard/webhook_logs.php`
- Фільтрація за типом, статусом, датою
- Перегляд сирих та оброблених даних
- Статистика по типам webhook

---

## 🚀 Інструкції по запуску

### Крок 1: Створити таблицю webhook_log

Відкрий в браузері:
```
https://dreamcar.ai-platform.space/dashboard/utm-dashboard/scripts/add_webhook_log_table.php
```

Скрипт автоматично:
- Перевірить чи існує таблиця
- Створить `webhook_log` якщо потрібно
- Покаже структуру таблиці

### Крок 2: Налаштувати webhook для CRM

В Make.com (або іншому сервісі автоматизації) налаштуй відправку POST запиту:

**URL:**
```
https://dreamcar.ai-platform.space/dashboard/utm-dashboard/webhook_crm.php
```

**Формат JSON (new):**
```json
{
  "title": "new",
  "email": "user@example.com",
  "phone": "+380123456789",
  "variables": {
    "deal_id": "12345",
    "utm_source": "facebook",
    "utm_medium": "cpc",
    "utm_campaign": "summer_sale",
    "utm_content": "ad_creative_1",
    "utm_term": "my_account",
    "amount_uah": "50000",
    "amount": "1350",
    "name": "Іван Петров",
    "model": "Volvo XC90"
  }
}
```

**Формат JSON (pay):**
```json
{
  "title": "pay",
  "email": "user@example.com",
  "variables": {
    "deal_id": "12345",
    "amount_uah": "50000"
  }
}
```

**Формат JSON (fail):**
```json
{
  "title": "другой_текст",
  "email": "user@example.com",
  "variables": {
    "deal_id": "12345",
    "stepName_deal": "fail",
    "amount_uah": "50000"
  }
}
```

### Крок 3: Налаштувати webhook для Facebook Ads

В Make.com налаштуй відправку POST запиту щодня о 9:00:

**URL:**
```
https://dreamcar.ai-platform.space/dashboard/utm-dashboard/handler_bulk.php
```

**Формат JSON:**
```json
{
  "ads_data": [
    {
      "date_start": "2024-01-15",
      "date_stop": "2024-01-15",
      "account_id": "123456",
      "campaign_id": "789012",
      "adset_id": "345678",
      "ad_id": "901234",
      "publisher_platform": "facebook",
      "platform_position": "feed",
      "account_name": "My Account",
      "campaign_name": "Summer Sale",
      "adset_name": "Target Audience 1",
      "ad_name": "Ad Creative 1",
      "spend": "150.50",
      "clicks": "45",
      "impressions": "1200",
      "reach": "980",
      "cpm": "12.54",
      "ctr": "3.75"
    }
  ]
}
```

### Крок 4: Перевірити webhook логи

Відкрий в браузері:
```
https://dreamcar.ai-platform.space/dashboard/utm-dashboard/webhook_logs.php
```

Тут можна:
- Переглянути всі вхідні webhook запити
- Фільтрувати за типом, статусом, датою
- Переглянути сирі дані для відлагодження
- Перевірити помилки обробки

---

## 📊 UTM маппінг (Facebook Ads → UTM)

Система автоматично перетворює поля з Facebook Ads в UTM мітки:

```
utm_source   = platform_position (наприклад: "feed", "story")
utm_medium   = platform_position (те саме що utm_source)
utm_campaign = campaign_name     (назва кампанії)
utm_content  = adset_name + "_" + ad_name
utm_term     = account_name      (назва рекламного акаунта)
```

Всі значення приводяться до lowercase.

---

## 🔍 Діагностика та тестування

### Перевірити цілісність даних
```
https://dreamcar.ai-platform.space/dashboard/utm-dashboard/scripts/verify_data_integrity.php
```

Покаже:
- ✅ Статус підключення до БД
- ✅ Кількість записів в кожній таблиці
- ⚠️ Дублікати deal_id
- ⚠️ Пусті UTM мітки
- ⚠️ Аномальні суми (> 1 млн UAH)
- 📊 Порівняння розрахунків з дашбордом

### Імпортувати історичні дані
```
https://dreamcar.ai-platform.space/dashboard/utm-dashboard/scripts/import_historical_data.php
```

Можна завантажити:
- CSV файл з CRM даними
- JSON файл з рекламними даними
- Переопределити дату для всіх записів

---

## 📝 Структура таблиці webhook_log

```sql
id                  - Унікальний ID
webhook_type        - 'crm' або 'ads'
event_type          - 'new', 'pay', 'fail', 'bulk'
raw_data            - Сирі JSON дані (як прийшло)
processed_data      - Оброблені дані (JSON)
deal_id             - ID сделки (для CRM)
records_count       - Кількість записів в запиті
success             - TRUE/FALSE
error_message       - Текст помилки
processing_time     - Час обробки (секунди)
ip_address          - IP відправника
user_agent          - User-Agent
created_at          - Дата створення
```

---

## ⚙️ Файли системи

### Моделі (core/models/)
- `WebhookLog.php` - робота з таблицею webhook_log
- `CrmDeal.php` - робота з CRM даними (з фільтром аномальних сум)
- `AdsData.php` - робота з рекламними даними

### Webhook обробники
- `webhook_crm.php` - обробка CRM подій (new/pay/fail)
- `handler_bulk.php` - масова обробка даних (CRM + Ads)

### Скрипти (scripts/)
- `add_webhook_log_table.php` - створення таблиці webhook_log
- `import_historical_data.php` - імпорт історичних даних
- `verify_data_integrity.php` - перевірка цілісності БД

### Інтерфейси
- `webhook_logs.php` - перегляд webhook логів
- `index.php` - головний дашборд

### API (api/)
- `get_webhook_log.php` - отримання деталей лога по ID

---

## ✅ Чек-лист налаштування

- [ ] Виконати `scripts/add_webhook_log_table.php` для створення таблиці
- [ ] Налаштувати webhook CRM в Make.com → `webhook_crm.php`
- [ ] Налаштувати webhook Facebook Ads в Make.com → `handler_bulk.php`
- [ ] Протестувати відправку тестового запиту
- [ ] Перевірити логи в `webhook_logs.php`
- [ ] Запустити `scripts/verify_data_integrity.php` для перевірки
- [ ] Переконатись що дашборд показує коректні дані

---

## 🆘 Вирішення проблем

### Webhook не працює
1. Перевір `webhook_logs.php` - чи приходять запити
2. Подивись на `error_message` в логах
3. Перевір формат JSON - він має співпадати з прикладами

### Дублікати даних
- Для CRM: кожен `deal_id` має бути унікальним
- Для Ads: унікальність за комбінацією полів (date_start + account_id + campaign_id + adset_id + ad_id + publisher_platform + platform_position)

### Неправильні розрахунки
- Запусти `scripts/verify_data_integrity.php`
- Перевір чи немає аномальних сум (> 1 млн UAH)
- Порівняй з `test_calculations.php`

---

**Готово! Система webhook готова до роботи. 🎉**
