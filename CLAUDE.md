# UTM Dashboard - Контекст проекта

## Что это

UTM Dashboard - аналитика лидов из SendPulse CRM. Дашборд показывает UTM-метки, источники трафика, конверсии по кампаниям DreamCar (розыгрыш BMW X5 Hybrid 2026).

## Архитектура: два сервера

Проект работает на ДВУХ серверах одновременно. Это НЕ ошибка - так задумано.

### Хостинг (dreamcar.ai-platform.space)

- **Сервер:** ukraine.com.ua, аккаунт serflow, PHP 8.5, LiteSpeed
- **Путь:** `/home/serflow/dreamcar.ai-platform.space/www/dashboard/utm-dashboard/`
- **Роль:** принимает POST webhook'и от внешних сервисов
- **URL:** `https://dreamcar.ai-platform.space/dashboard/utm-dashboard/`

Файлы на хостинге:
- `webhook_crm.php` - принимает POST от SendPulse CRM (события по сделкам)
- `handler_bulk.php` - принимает POST от Make.com (данные Facebook Ads)

**КРИТИЧНО:** URL webhook'ов в Make.com и SendPulse привязан к `dreamcar.ai-platform.space`. Менять нельзя без согласования.

### VPS (ticket.ai-platform.space)

- **Сервер:** 173.242.56.80, HestiaCP, пользователь serverflow, PHP 8.4
- **Путь:** `/home/serverflow/web/ticket.ai-platform.space/www/dashboard/utm-dashboard/`
- **Роль:** кабинет пользователя (UI дашборда)
- **URL:** `https://ticket.ai-platform.space/dashboard/utm-dashboard/`

### Маршрутизация (.htaccess на хостинге)

Файл `/home/serflow/dreamcar.ai-platform.space/www/dashboard/.htaccess`:

```apache
RewriteEngine On

# POST запросы НЕ редиректим - пропускаем к PHP (webhook Make, SendPulse)
RewriteCond %{REQUEST_METHOD} POST
RewriteRule .* - [L]

# GET/остальное - редирект кабинета на VPS
RewriteCond %{HTTP_HOST} dreamcar\.ai-platform\.space [NC]
RewriteRule ^(.*)$ https://ticket.ai-platform.space/dashboard/$1 [R=301,L,QSA]
```

**Логика:**
- Человек заходит в браузере → GET → 301 редирект на VPS
- Make/SendPulse шлют данные → POST → обрабатывается на хостинге
- Оба сервера пишут в ОДНУ БД

### БД

- **Хост:** `fincheck.mysql.network`
- **Порт:** `10145`
- **БД:** `dreamcar_utm`
- **Конфиг:** `config/database.php`
- Одна и та же БД используется и хостингом и VPS

## Git

- **Репозиторий:** `github.com/cemahalexandr/dashboard-dreamcar` (приватный)
- **Ветка:** `main`
- **Корень git repo:** папка `dashboard/` (НЕ `utm-dashboard/`)
- То есть `utm-dashboard/` - подпапка внутри git repo

### Git синхронизация

**Локалка → GitHub:** git-auto-multi (daemon на Mac)
- ID проекта: `dashboarddreamcar-4fab`
- Локальный путь: `/Users/tsemakhold/home/serflow/dreamcar.ai-platform.space/www/dashboard`
- rsync на хостинг **ОТКЛЮЧЕН** (SSH_USER="", SSH_PASS="")
- Коммиты создаются автоматически при изменении файлов

**GitHub → VPS:** git-server-sync (пакет pattern-frame v1.11.0)
- Webhook ID: `605106976`
- Systemd сервис: `dashboard-git-sync` (User=serverflow)
- Webhook endpoint: `https://ticket.ai-platform.space/dashboard/utm-dashboard/api/git_webhook.php`
- Daemon: `cron/git_server_sync.php` (коммитит серверные изменения, пушит в GitHub)

**ВАЖНО:** Хостинг НЕ получает автоматических обновлений из git. Файлы на хостинге обновляются только если вручную залить через FTP/SSH. Webhook'и (`webhook_crm.php`, `handler_bulk.php`) на хостинге работают со старой версией кода - это нормально, они редко меняются.

Если нужно обновить файл на хостинге:
```bash
# SSH на хостинг
ssh serflow@serflow.ftp.tools  # пароль: див. 1Password \"DreamCar Dashboard SSH\"
# Путь: /home/serflow/dreamcar.ai-platform.space/www/dashboard/utm-dashboard/
```

## Webhook'и - кто куда шлет

| Сервис | Метод | URL | Сервер |
|--------|-------|-----|--------|
| Make.com (Facebook Ads) | POST | `dreamcar.ai-platform.space/dashboard/utm-dashboard/handler_bulk.php` | Хостинг |
| SendPulse CRM | POST | `dreamcar.ai-platform.space/dashboard/utm-dashboard/webhook_crm.php` | Хостинг |
| GitHub (git push) | POST | `ticket.ai-platform.space/dashboard/utm-dashboard/api/git_webhook.php` | VPS |

SendPulse также может слать на VPS URL (`ticket.ai-platform.space`) - работает и там.

## Конфигурация

- `config/database.php` - MySQL credentials (одинаковый на обоих серверах)
- `config/app_config.php` - основной конфиг (API ключи, пути, Telegram)
- `config/dashboard_settings.json` - настройки проектов дашборда
- `config/users.json` - пользователи для авторизации

### Ключи в app_config.php

| Константа | Назначение |
|-----------|-----------|
| SENDPULSE_ID / SENDPULSE_SECRET | SendPulse API |
| GOOGLE_CREDENTIALS_FILE / GOOGLE_SPREADSHEET_ID | Google Sheets |
| TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID | Telegram уведомления дашборда |
| GITHUB_WEBHOOK_SECRET | Подпись GitHub webhook |
| GITHUB_REPO_URL | Git remote с PAT |
| OPENAI_API_KEY | AI коммит-сообщения (git-server-sync daemon) |
| GIT_SYNC_TG_TOKEN / GIT_SYNC_TG_CHAT | Telegram уведомления git sync |

## Telegram

- Бот: `@tsemakhalex_bot` (1769597114)
- Чат уведомлений дашборда: `-4800447687`
- Чат git sync: `-1003713547131`

## Другие разработчики

На VPS могут работать другие разработчики (коммиты от `Jorik-Squirtanov228` в истории). Git-server-sync daemon автоматически коммитит и пушит их серверные изменения.

## Частые проблемы

### "Ошибка в CRM webhook" в Telegram (GET запросы)
SendPulse перед каждым POST шлет GET-проверку доступности. `webhook_crm.php` обрабатывает GET тихо (200 OK). Если посыпались ошибки - проверь что GET handler не бросает Exception.

### Файл изменен на VPS но не на хостинге
Нормально. Git sync работает только VPS <-> GitHub <-> локалка. Хостинг обновлять вручную через SSH.

### .htaccess на хостинге сбросился
Git-auto-multi rsync отключен, но `.htaccess` в корне git repo (`dashboard/`). Если git-auto-multi вдруг включит rsync - файл перезапишется. Восстановить через SSH на хостинг.
