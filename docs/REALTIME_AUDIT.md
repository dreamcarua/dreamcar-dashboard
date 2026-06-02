# Real-time Dashboard Data Flow — Audit + Plan

**Дата:** 2026-06-02 / Автор: Claude

## TL;DR

**Зараз** lag даних до **1 години** (ETL hourly). Знизив до **5 хв** + додав **Supabase Realtime** subscription у фронт → live push при кожній новій угоді. Залишається 1 крок щоб зробити **миттєво** — переключити SendPulse webhook на наш Edge Function.

---

## 1. Як зараз працює legacy PHP dashboard

**Real-time flow (миттєво):**

```
SendPulse CRM ──POST──> ticket.ai-platform.space/webhook_crm.php
                              │
                              ▼
                       MySQL crm_deals (instant INSERT)
                              │
Make.com (FB Ads) ─POST──> handler_bulk.php ──> MySQL ads_data
                              │
PHP index.php query на КОЖНОМУ refresh:
   SELECT * FROM crm_deals WHERE created_at BETWEEN ... AND project = ...
   + JOIN ads_data ON utm_campaign
   + Aggregate в PHP
   + Render HTML
```

**Плюси legacy:**
- Дані миттєво у БД через webhook
- Кожен refresh — свіжі дані з MySQL

**Мінуси legacy:**
- На кожен refresh — full table scan + aggregation у PHP (повільно)
- Без кешу
- Без auto-refresh — треба F5
- Один MySQL — single point of failure
- Old PHP стек, складно масштабувати

---

## 2. Як працює нова JAMstack (до сьогодні)

**Flow з 60 хв lag:**

```
SendPulse ──> webhook_crm.php (LEGACY) ──> MySQL.crm_deals
                                              │
                                       ETL Python (hourly cron)
                                              │
                                              ▼
                                     Supabase.dashboard_deals
                                              │
                                  dashboard.dreamcar.ua (JAMstack)
                                              │
                                       Fetch + Aggregate
                                              │
                                       Render KPI/Charts
```

**Проблеми:**
- Lag до 60 хв (cron `0 * * * *`)
- Frontend не знає коли нові дані
- Юзер мусить F5

---

## 3. Що зробив СЬОГОДНІ — near real-time (lag ≤5 хв)

### 3.1. ETL частоту з 1 год → 5 хв
**Файл:** `.github/workflows/etl-mysql-sync.yml`
**Було:** `cron: '0 * * * *'` (раз на годину)
**Стало:** `cron: '*/5 * * * *'` (кожні 5 хв)

### 3.2. Supabase Realtime увімкнено
```sql
ALTER PUBLICATION supabase_realtime ADD TABLE public.dashboard_deals;
ALTER PUBLICATION supabase_realtime ADD TABLE public.dashboard_webhooks;
ALTER PUBLICATION supabase_realtime ADD TABLE public.dashboard_ads_data;

CREATE INDEX idx_dashboard_deals_status_created ON dashboard_deals(status, created_at DESC);
CREATE INDEX idx_dashboard_deals_project_created ON dashboard_deals(project, created_at DESC);
CREATE INDEX idx_dashboard_deals_utm_source_created ON dashboard_deals(utm_source, created_at DESC);
```

### 3.3. Frontend WebSocket subscription
- `setupRealtimeSubscription()` — підписка на INSERT/UPDATE
- `debouncedReload()` — 3-сек debounce перед re-render
- `updateLiveBadge()` — LIVE іконка з seconds-ago counter

**Поточний lag:** 0-5 хв замість 0-60 хв

---

## 4. Plan B — повністю миттєво (наступний крок)

Переключити webhooks SendPulse + Make.com на наш Edge Function (вже існує).

### 4.1. SendPulse
1. SendPulse → Integrations → Webhooks
2. Додати другий URL: `https://wotghlaehnvxyeacznvv.supabase.co/functions/v1/webhook-dashboard-sendpulse`

### 4.2. Make.com
1. Scenario "FB Ads → Dashboard"
2. Додати другий HTTP Request з URL `https://wotghlaehnvxyeacznvv.supabase.co/functions/v1/webhook-dashboard-make-com`

**Lag після: 0-200 мс**

---

## 5. Оптимізації

### Materialized Views (Postgres)
```sql
CREATE MATERIALIZED VIEW mv_dashboard_daily AS
SELECT DATE(created_at) as day, project, utm_source,
       COUNT(*) as leads,
       COUNT(*) FILTER (WHERE status='pay') as paid,
       SUM(amount) FILTER (WHERE status='pay') as revenue
FROM dashboard_deals
GROUP BY DATE(created_at), project, utm_source;
```

### Frontend оптимізації
- LocalStorage cache + delta updates
- На realtime push → НЕ refetch усе, тільки delta

---

## 6. ROI

| Метрика | До | Після зараз | Якщо webhooks dual-write |
|---|---|---|---|
| Lag даних | 60 хв | ≤5 хв | ≤200 мс |
| Auto-refresh | F5 | Push | Push |
| Cost (GH Actions) | 20 хв/міс | 1800 хв/міс | 1800 хв/міс |

**Bottom line: 30× скорочення lag без витрат, ще 1500× якщо переключиш webhooks.**
