# DreamCar Dashboard — Web Frontend

> Read-only JAMstack frontend для UTM-аналітики
> URL: **dashboard.dreamcar.ua**
> Hosted: GitHub Pages (з `web/` папки цього repo)

## Що це

Перепис legacy PHP-дашборду (на `dreamcar.ai-platform.space`) на чистий статичний JS+HTML.
Backend — Supabase HQ (`wotghlaehnvxyeacznvv`), таблиці `dashboard_*`.

## Read-only режим

Frontend ТІЛЬКИ читає з `dashboard_deals`, `dashboard_webhooks`, `dashboard_ads_data`,
`dashboard_manual_costs` через Supabase REST (anon key). Жодних writes.

Webhooks (SendPulse + Make.com) поки шлють у legacy PHP — нічого не змінено.

## Як запустити локально

```bash
cd web
python3 -m http.server 8080
# Відкрити http://localhost:8080
```

## Deploy

GitHub Pages автоматично після push у `main` branch (Settings → Pages → Source: main → /web).

## DNS

`dashboard.dreamcar.ua` CNAME → `dreamcarua.github.io`
(Файл `CNAME` у корені папки `web/`)

## Архітектура

```
web/
├── index.html              — Main dashboard
├── assets/
│   ├── js/
│   │   ├── auth-guard.js   — Захист сторінки (копія з HQ)
│   │   └── app.js          — Fetch + render
│   └── css/
│       └── dashboard.css   — Overrides поверх brand styles.css
├── CNAME                   — dashboard.dreamcar.ua
└── README.md
```

Глобальні стилі + шапка беруться з brand.dreamcar.ua CDN (як HQ/Tasks).
