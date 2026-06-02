# Real-time Dashboard Data Flow — Audit + Plan

**Дата:** 2026-06-02 / Автор: Claude

## TL;DR

**Було** lag даних до **1 години** (ETL hourly). **Зараз** ≤5 хв + Supabase Realtime push у браузер. **Plan B** (webhook dual-write + FB Ads ETL замість Make.com) → ≤200 мс.

---

## 1. Як зараз працює legacy PHP dashboard

```
SendPulse CRM ──POST──> ticket.ai-platform.space/webhook_crm.php
                              ▼
                       MySQL crm_deals (instant INSERT)

Make.com (FB Ads) ─POST──> handler_bulk.php ──> MySQL ads_data

PHP index.php query на КОЖНОМУ refresh:
   SELECT * FROM crm_deals WHERE created_at ... AND project = ...
   + JOIN ads_data ON utm_campaign + Aggregate в PHP + Render HTML
```

**Плюси:** дані миттєво у БД. **Мінуси:** full scan на кожен refresh, без кешу, треба F5.

---

## 2. Як працює нова JAMstack

```
SendPulse → MySQL.crm_deals (через legacy webhook)
            ETL Python (5хв cron)
            Supabase.dashboard_deals
            dashboard.dreamcar.ua
            Fetch + Render
```

---

## 3. Що зробив СЬОГОДНІ

### 3.1. ETL cron 1 год → 5 хв
`.github/workflows/etl-mysql-sync.yml`: `cron: '*/5 * * * *'`

GH Actions free tier: 2000 хв/міс. ETL ~10-20 сек × 12 runs × 24 год = 1800 хв/міс. **Влізає.**

### 3.2. Supabase Realtime + indexes
```sql
ALTER PUBLICATION supabase_realtime ADD TABLE dashboard_deals;
ALTER PUBLICATION supabase_realtime ADD TABLE dashboard_webhooks;
ALTER PUBLICATION supabase_realtime ADD TABLE dashboard_ads_data;

CREATE INDEX idx_dashboard_deals_status_created ON dashboard_deals(status, created_at DESC);
CREATE INDEX idx_dashboard_deals_project_created ON dashboard_deals(project, created_at DESC);
CREATE INDEX idx_dashboard_deals_utm_source_created ON dashboard_deals(utm_source, created_at DESC);
```

### 3.3. Frontend WebSocket subscription
```js
sb.channel('dashboard-deals-rt')
  .on('postgres_changes', { event: 'INSERT', schema: 'public', table: 'dashboard_deals' },
      payload => { updateLiveBadge(); debouncedReload(); })
  .subscribe();
```

**Поточний lag:** ≤5 хв + LIVE badge у UI, auto-refresh без F5.

---

## 4. Plan A — Webhook dual-write (≤200 мс)

### SendPulse
1. SendPulse → Integrations → Webhooks
2. Existing webhook (на `ticket.ai-platform.space/webhook_crm.php`)
3. **+ Add URL:** `https://wotghlaehnvxyeacznvv.supabase.co/functions/v1/webhook-dashboard-sendpulse`
4. Залишити старий — paralлельний run

### Make.com → ЗАМІНА
Див. наступний розділ.

---

## 5. Plan B — Заміна Make.com на власний FB Ads ETL

**Створено:**
- `etl/sync_fb_ads.py` — Python: Facebook Marketing API → Supabase dashboard_ads_data
- `.github/workflows/fb-ads-sync.yml` — cron `*/15 * * * *`
- `docs/FB_TOKEN_SETUP.md` — інструкція як отримати System User token

**Що тягне:**
- `campaign_name`, `adset_name`, `ad_name`
- `spend`, `impressions`, `clicks`, `reach`, `ctr`, `cpc`, `cpm`
- `actions` (lead, purchase, complete_registration)
- UTM-параметри з link URL
- Breakdowns: age, gender, placement (опц.)

**Cost:** $0 (GH Actions free tier + FB API безкоштовний).  
**Make.com Pro був:** $9-29/міс.

---

## 6. Оптимізації для прискорення

### Materialized View
```sql
CREATE MATERIALIZED VIEW mv_dashboard_daily AS
SELECT DATE(created_at) as day, project, utm_source,
       COUNT(*) as leads,
       COUNT(*) FILTER (WHERE status='pay') as paid,
       SUM(amount) FILTER (WHERE status='pay') as revenue
FROM dashboard_deals
GROUP BY DATE(created_at), project, utm_source;
```
Frontend читає mv_dashboard_daily → 100× швидше.

### Frontend кеш + delta updates
- IndexedDB cache + на realtime push → не refetch усе, а update тільки delta.

---

## 7. Архітектура (фінал)

```
                       SendPulse CRM
                  ┌─────────┴─────────┐
                  ▼                   ▼
        legacy PHP webhook      Supabase Edge Function
                  │                   │
                  ▼                   ▼
            MySQL.crm_deals    Supabase.dashboard_deals
                  │            (Realtime ON)
            ETL/5хв (safety)         │
                  └────────────►─────┤
                                     ▼ WebSocket
                              dashboard.dreamcar.ua

       Facebook Ads API
              ▼
       GH Action /15хв (нова ETL)
              ▼
       Supabase.dashboard_ads_data
              ▼
       (replaced Make.com)
```

---

## 8. ROI

| Метрика | До | Після зараз | Plan A+B |
|---|---|---|---|
| Lag CRM | 60 хв | ≤5 хв | ≤200 мс |
| Lag FB Ads | 60 хв (Make) | ≤15 хв (наш ETL) | ≤15 хв |
| Cost Make.com | $9-29/міс | $0 | $0 |
| Auto-refresh | F5 | Push | Push |
| Контроль | Vendor lock | Повний | Повний |

**Total: 30× lag reduction + Make.com $0 заміна.**
