# DreamCar Dashboard — Setup (для Вадима)

> Phase 1–3, 5 готові. Залишилось 2 кроки для запуску `dashboard.dreamcar.ua`.

## Що готово ✅

| Phase | Що | Status |
|---|---|---|
| 1 | Supabase schema (6 таблиць `dashboard_*` у HQ) | ✅ |
| 2 | Edge Functions: `webhook-dashboard-sendpulse` + `webhook-dashboard-make-com` | ✅ |
| 3 | Frontend `docs/` (HTML + JS + Chart.js + DreamCar brand) | ✅ |
| 5a | GitHub Pages enabled (main / /docs) | ✅ |
| **5b** | **DNS CNAME для `dashboard.dreamcar.ua`** | ⏳ **1 крок від тебе** |
| 4 | ETL MySQL → Supabase (для синхронізації legacy даних) | ⏳ після DNS |

---

## Крок 1 — додати DNS CNAME (Cloudflare)

DNS dreamcar.ua у Cloudflare (zita.ns.cloudflare.com).

1. Зайди на https://dash.cloudflare.com → вибери `dreamcar.ua` зону
2. **DNS → Records → Add record**
3. Введи:
   - **Type:** `CNAME`
   - **Name:** `dashboard`
   - **Target:** `dreamcarua.github.io`
   - **Proxy status:** 🟠 Proxied (помаранчева хмаринка)
   - **TTL:** Auto
4. **Save**

Через 1-2 хв `https://dashboard.dreamcar.ua` стане доступний.

## Крок 2 — увімкнути HTTPS (опціонально, GH зробить автоматично за ~5 хв)

GitHub Pages автоматично згенерує Let's Encrypt сертифікат, як тільки CNAME валідується. Якщо за 10 хв SSL не активний — натисни:

```
Settings → Pages → Enforce HTTPS ✓
```

---

## Архітектура (як працює зараз)

```
                                ┌─────────────────────────────┐
                                │ dashboard.dreamcar.ua       │
                                │ (GitHub Pages / docs/)      │
                                │                              │
                                │ - index.html + Chart.js     │
                                │ - DreamCar brand            │
                                │ - Supabase Auth guard       │
                                │ - READ-ONLY режим           │
                                └──────────────┬──────────────┘
                                               │ SELECT
                                               ▼
                                ┌─────────────────────────────┐
                                │ Supabase HQ                 │
                                │ wotghlaehnvxyeacznvv        │
                                │                              │
                                │ dashboard_deals  ←──────┐   │
                                │ dashboard_webhooks      │   │
                                │ dashboard_ads_data      │   │
                                │ dashboard_manual_costs  │   │
                                │ dashboard_utm_mapping   │   │
                                │ dashboard_settings      │   │
                                └─────────────────────────┼───┘
                                                          │
                                          ┌───────────────┴───────────────┐
                                          │                                │
                  ┌───────────────────────▼──────┐    ┌───────────────────▼──────────┐
                  │ webhook-dashboard-sendpulse  │    │ webhook-dashboard-make-com   │
                  │ (Edge Function, public POST) │    │ (Edge Function, public POST) │
                  └──────────────┬───────────────┘    └───────────┬──────────────────┘
                                 │                                 │
                                 │ (ПОКИ НЕ ВИКОРИСТОВУЮТЬСЯ —    │
                                 │  SendPulse/Make.com шлють       │
                                 │  на старі URL, як і раніше)     │
                                 │                                 │
                                 ▼                                 ▼
                  ┌──────────────────────────────────────────────────────┐
                  │ LEGACY (Олександр Цемах)                             │
                  │ dreamcar.ai-platform.space/...webhook_crm.php        │
                  │ dreamcar.ai-platform.space/...handler_bulk.php       │
                  │ ↓                                                    │
                  │ MySQL fincheck.mysql.network:10145 (dreamcar_utm)    │
                  └──────────────────────────────────────────────────────┘
                                          ▲
                                          │ READ-ONLY ETL (Phase 4, наступний крок)
                                          │ GH Action: щогодинна синхронізація
                                          │ legacy MySQL → Supabase dashboard_*
                                          ▼
                                   (поки не запущено)
```

**Ключове:** Нічого з legacy не зачеплено. SendPulse/Make.com шлють webhook'и на ті ж URL що й раніше. Дашборд показує дані з Supabase (поки тільки тестові).

---

## Тестовий запуск

Після Кроку 1 (DNS):

1. Відкрий `https://dashboard.dreamcar.ua` у браузері
2. Має зʼявитись overlay «Перевіряю доступ…» (auth-guard працює)
3. Якщо не залогінений у HQ — буде кнопка «Увійти через /hq»
4. Після логіну — побачиш 1 тестову угоду (TEST-001, 499 UAH)

---

## Що далі (Phase 4 — ETL)

Скрипт що читає MySQL `fincheck.mysql.network:10145` (READ-ONLY) → пише у Supabase `dashboard_deals`/`dashboard_webhooks`/`dashboard_ads_data`. Запускається через GitHub Action раз на годину.

Це дозволить побачити **справжні** дані угод/Ads без переключення webhook URLs.

Запитай — і запущу.

---

## URL endpoints

| Сервіс | URL |
|---|---|
| Frontend | `https://dashboard.dreamcar.ua` |
| Supabase | `https://wotghlaehnvxyeacznvv.supabase.co` |
| Edge SendPulse | `https://wotghlaehnvxyeacznvv.supabase.co/functions/v1/webhook-dashboard-sendpulse` |
| Edge Make.com | `https://wotghlaehnvxyeacznvv.supabase.co/functions/v1/webhook-dashboard-make-com` |
| Legacy hosting | `https://dreamcar.ai-platform.space/dashboard/utm-dashboard/` |
| Legacy VPS | `https://ticket.ai-platform.space/dashboard/utm-dashboard/` |
| Repo | `https://github.com/dreamcarua/dreamcar-dashboard` |
