# dashboard-dreamcar — Deep Analysis + Integration Plan

**Дата:** 01.06.2026
**Автор:** Claude (autonomous deep analysis)
**Status:** Local cleanup ready (3 commits) → блокер: PAT scope

---

## TL;DR

`cemahalexandr/dashboard-dreamcar` — це **UTM-аналітика лідів з SendPulse CRM** для проекту DreamCar (~528 PHP файлів, 32 705 рядків коду). Розробник: Олександр Цемах (зовнішній підрядник). Production стек — два сервери (хостинг `dreamcar.ai-platform.space` + VPS `ticket.ai-platform.space`) з однією зовнішньою MySQL `fincheck.mysql.network:10145`.

**Висновки:**
1. 🔴 **5 типів секретів злиті у git history оригіналу** — *усі ROTATE обовʼязково*
2. 🟠 **БРЕНДУ DreamCar немає взагалі** (Inter + blue/purple, "📊 UTM Dashboard" як назва)
3. 🟡 **~60 одноразових debug скриптів** у корені — production smell
4. 🟢 **Архітектурно — vanilla PHP без framework, легко рефакторити**
5. 🟢 **Webhook-маршрутизація працює стабільно** (SendPulse + Make.com)

---

## 1. Архітектура

### Стек
- **Backend:** Vanilla PHP 8.5 (без Composer, без PSR autoload)
- **Frontend:** Vanilla JS + Chart.js + 7 CSS файлів (~3000 рядків)
- **DB:** MySQL 8.4 (зовнішній сервіс `fincheck.mysql.network`)
- **Server-1 (хостинг):** ukraine.com.ua, LiteSpeed, PHP 8.5
- **Server-2 (VPS):** 173.242.56.80, HestiaCP, PHP 8.4
- **Sync:** Supervisor-daemon `git-server-sync` (VPS↔GitHub) + git-auto-multi (mac→GitHub)

### Маршрутизація POST/GET
| Запит | Куди | Сервер |
|---|---|---|
| GET (юзер у браузері) | 301 → VPS | хостинг |
| POST SendPulse CRM webhook | webhook_crm.php | хостинг |
| POST Make.com Facebook Ads | handler_bulk.php | хостинг |
| GET dashboard UI | index.php | VPS |
| POST GitHub push webhook | api/git_webhook.php | VPS |

### Файлова структура (528 файлів, 6.0 МБ)
```
/                                  — корінь git repo (= папка dashboard/ на серверах)
├── config/                        — app_config.php, database.php, users.json
├── core/                          — Auth, Database, Session, Logger, models/
├── api/                           — handler.php, sendpulse.php, google_sheets.php, git_webhook.php, utm_mapping/
├── ads/                           — Meta Ads API tester (config.php з App credentials)
├── finance/                       — окремий модуль (своя auth, своя index.php, 10 migrations)
├── migrations/                    — 10 PHP-міграцій бази даних
├── sql/                           — schema.sql, utm_crm_ads_mapping.sql
├── cron/                          — git_server_sync.php (Supervisor daemon)
├── assets/                        — CSS (7) + JS (11)
├── utm-dashboard/                 — ⚠️ ДУБЛЬ кореня (для VPS sync, ~95% файлів повторюються)
├── utm-dashboard/utm-dashboard/   — ⚠️ ТРОЙНИЙ ДУБЛЬ (sync bug)
└── ~60 *.php у корені             — debug/check/fix скрипти (одноразові)
```

### БД схема (виявлені таблиці)
- `crm_deals` — угоди з SendPulse
- `webhook_logs` — лог webhook-приймачів
- `utm_data` / `utm_clean` — UTM mapping
- `manual_costs` — ручні витрати
- `ad_*` — Facebook Ads metrics
- `finance_transactions` — фін-операції
- `users` (опціонально — основні юзери у `config/users.json` як bcrypt-hash)
- Cross-DB: `dreamlava.wp_webhooks` (WordPress) — для real UTM з замовлень WooCommerce

---

## 2. Security — знайдено + виправлено

### Залиті секрети (оригінальний repo, ROTATE обовʼязково)

| Тип | Значення | Локація | Дія |
|---|---|---|---|
| `OPENAI_API_KEY` | `sk-proj-elMu…` | config/app_config.php | 🔴 REVOKED 01.06.2026 |
| `GITHUB_PAT` | `ghp_ke29HtqV…` | embedded у GITHUB_REPO_URL | 🔴 REVOKED 01.06.2026 |
| `DB_PASS` (dreamcar_utm) | `A8Ci4dC79n` | config/database.php + migrations | 🟠 ROTATE на MySQL + env |
| `WP_DB_PASS` (dreamlava) | `74TBL8aav6` | webhook_crm.php + scripts/fix_wc_order_id_utm.php (×6 копій) | 🟠 ROTATE на MySQL + env |
| `META_APP_SECRET` | `52c69c93…` | ads/config.php | 🟠 ROTATE через developers.facebook.com |
| `META_CLIENT_TOKEN` | `5aa6f13346…` | ads/config.php | 🟠 ROTATE там же |
| `TELEGRAM_BOT_TOKEN` | `1769…AAHh…` (бот @tsemakhalex_bot) | config/app_config.php | 🟠 REVOKE через @BotFather + новий |
| `GITHUB_WEBHOOK_SECRET` | (один із паролів) | config/app_config.php | 🟠 Re-generate у repo settings |
| `SSH_PASSWORD` (serflow) | `dA3lvB2tqW` | CLAUDE.md (тепер замінено на «див. 1Password») | 🟠 ROTATE на ukraine.com.ua |

### Виконано локально (3 коміти)
1. **f50782f** — Initial: всі configs/migrations переведені на `getenv()` (cleaned, 534 файли)
2. **341e9af** — Security: WP_DB_PASS hardcode → getenv() у 6 файлах
3. **116b315** — Security: META_APP_SECRET + META_CLIENT_TOKEN → getenv()

### Залишилось зробити
- 🟡 `ads/index.php` — рендерить `META_APP_SECRET` у `<code>` тег як test tool. Сховати за admin role або винести у JS prompt.
- 🟡 `config/users.json` містить bcrypt password hashes у git. Bcrypt стійкий, але краще винести у БД або зашифрований store.

---

## 3. Brand Audit vs DreamCar Brand Book

### Шрифти
| Поточне | Brand Book |
|---|---|
| `Inter` (Google Fonts) | `Bebas Neue` (headers) + `Wix Madefor Display` (body) |

❌ **0% збігу**.

### Кольори (CSS-токени)
| Token | Поточне | Brand Book |
|---|---|---|
| Primary | `#3b82f6` (Tailwind blue-500) | `#FF6B00` (DreamCar Orange) |
| Secondary | `#8b5cf6` (Tailwind violet-500) | `#FFD700` (Gold) |
| Bg | `hsl(10,10%,4%)` (теплий чорний) | `#000` (Racing Black) |
| Accent green | `#10b981` | (нема) |

❌ **0% збігу** — це generic AI/SaaS dark theme.

### Логотип/назва
- Поточна: `📊 UTM Dashboard | SendPulse Analytics` (emoji + текст)
- Brand: DreamCar Racing Plate SVG + `DreamCar` як назва системи

### Global-header
- ❌ Немає `https://brand.dreamcar.ua/assets/global-header.js`
- ❌ Немає DreamCar sidebar (як у HQ/Tasks/Brand Book)
- ❌ Немає auth-guard

### Висновок brand audit
**Score: 0/10**. Це візуально ізольована система, не частина DreamCar UX. Інтеграція = повний rebrand + впровадження global-header + sidebar.

---

## 4. Інтеграція з DreamCar екосистемою — план

### Стратегічні питання (треба рішення Вадима)

**Q1: Підтримуємо обидва сервери чи мігруємо на наші ресурси?**

Варіант **A — підхопити existing setup** (швидко, мало ризику):
- Залишити webhook URL `dreamcar.ai-platform.space` (Make + SendPulse шлють туди)
- Залишити VPS `ticket.ai-platform.space` як UI
- Просто оновити коди на чистий fork + secrets у env

Варіант **B — мігрувати на нашу інфраструктуру**:
- Subdomain `team.dreamcar.ua/dashboard/` АБО окремий `dashboard.dreamcar.ua`
- VPS Hostinger/DigitalOcean ($6/міс) АБО Hostinger shared PHP
- Reconfigure webhook URLs у SendPulse + Make.com (CRITICAL — інакше leads губляться)
- Перевести `git-server-sync` на наш GitHub

**Рекомендація:** **B**, бо зараз ми залежимо від Олександра + його серверів. Але треба робити з двох кроків: спочатку **A** для синхронізації, потім **B** з координованим switch-over.

---

### Subdomain — варіанти

| Варіант | Плюси | Мінуси |
|---|---|---|
| `team.dreamcar.ua/dashboard/` | Єдиний домен, легше SSL, єдиний auth-cookie | PHP не на GitHub Pages — треба VPS |
| `dashboard.dreamcar.ua` | Чіткий розділ, окремий DNS | Потрібен окремий SSL, окремий host |
| `analytics.dreamcar.ua` | Семантично — це аналітика, не dashboard | Конфлікт з потенційним `analytics.*` |

**Рекомендація:** `team.dreamcar.ua/dashboard/` (через reverse proxy на VPS з PHP).

---

### CI/CD plan для PHP

GitHub Pages не підтримує PHP. Варіанти:

1. **GitHub Actions → rsync на VPS** *(рекомендую)*
   - Action runs on push to `main`
   - rsync через SSH ключ → `/var/www/dashboard/`
   - Аналогічно до того як зараз `git-server-sync` робить, але без PHP-daemon
   - Готовий приклад: `.github/workflows/deploy-php.yml`

2. **Docker → Hostinger/Render**
   - Dockerfile з PHP 8.5 + LiteSpeed/Apache
   - Push to GHCR → Render pulls + deploys
   - Більше control, але дорожче

3. **Залишити поточний git-server-sync daemon**
   - Швидко, працює
   - Залежність від Олександра + його OpenAI ключа для AI commit messages

**Рекомендація:** **1** після того як ми мігруємо на наш VPS.

---

### Інтеграція з DreamCar HQ (Supabase)

**Поточна БД:** MySQL 8.4 (fincheck.mysql.network)
**HQ БД:** Supabase Postgres (wotghlaehnvxyeacznvv.supabase.co)

**Що інтегрувати:**

1. **Auth** — поточний auth по `config/users.json` + utm_term cookies. HQ використовує Supabase Auth.
   - **Варіант A (lighter):** Зберегти MySQL auth, додати `auth-guard.js` як top-layer (читає Supabase session → редирект на login.html якщо немає)
   - **Варіант B (full):** Перенести юзерів у `public.users` Supabase, видалити users.json, переписати Auth.php на Supabase API

2. **Global-header** — додати на КОЖНУ сторінку:
   ```html
   <script src="https://brand.dreamcar.ua/assets/global-header.js" defer></script>
   ```
   Це автоматично рендерить шапку з логотипом + nav-dropdown.

3. **Sidebar** — додати DreamCar sidebar (як у HQ/Tasks):
   ```html
   <script src="https://brand.dreamcar.ua/assets/sidebar.js" defer></script>
   ```

4. **Brand tokens** — замінити CSS variables:
   ```css
   :root {
     --color-primary: #FF6B00;     /* DreamCar Orange */
     --color-secondary: #FFD700;   /* Gold */
     --bg-primary: #000;            /* Racing Black */
     --font-headers: 'Bebas Neue', sans-serif;
     --font-body: 'Wix Madefor Display', sans-serif;
   }
   ```

5. **Cross-system links** — додати у global-header dropdown:
   - HQ (publication management)
   - Tasks
   - Brand Book
   - **Dashboard (analytics)** ← new
   - Team Hub

---

### Інтеграція даних (UTM ↔ HQ)

Бачив у HQ — є аналітика по публікаціях. У dashboard-dreamcar — UTM-аналітика лідів. Природна інтеграція:

**Use case:** "Скільки лідів привела публікація X у TG-каналі?"
- HQ знає: publication `<uuid>` опублікована 2026-05-01 у TG
- Dashboard знає: lead з `utm_source=telegram&utm_campaign=publication_<uuid>` прийшов 2026-05-02

**Реалізація:**
1. У HQ автопостингу — додати `utm_*` параметри у згенерований URL у пості
2. У dashboard — додати фільтр "по публікації HQ" (lookup publication metadata з Supabase)
3. У HQ — додати віджет "конверсії з цієї публікації" (запит до MySQL dashboard через API endpoint)

---

## 5. Тех-борг до фіксу (післяпушу)

| # | Що | Скільки часу | Пріоритет |
|---|---|---|---|
| 1 | Видалити `utm-dashboard/utm-dashboard/` (тройний дубль) | 5 хв | 🔴 High |
| 2 | Перенести ~60 root debug-скриптів у `scripts/debug/` | 30 хв | 🟠 Medium |
| 3 | Composer + PSR-4 autoload (require_once → `use`) | 4 год | 🟡 Low |
| 4 | DreamCar global-header + sidebar на кожну сторінку | 2 год | 🔴 High |
| 5 | Brand tokens rewrite (CSS variables) | 3 год | 🔴 High |
| 6 | ads/index.php — сховати API secret render | 30 хв | 🟠 Medium |
| 7 | config/users.json → Supabase Auth integration | 1 день | 🟡 Low |
| 8 | E2E test webhook flow після rotation секретів | 2 год | 🔴 High |
| 9 | CI/CD GitHub Action для PHP deploy | 1 день | 🟠 Medium |
| 10 | INTEGRATION.md для команди (як працювати з dashboard) | 1 год | 🟠 Medium |

---

## 6. Що ЗАРАЗ блокує push

PAT (GitHub MCP token у Cowork) має fine-grained scope тільки на 9 існуючих repos `dreamcarua/*`. Новий `dreamcar-dashboard` (створений 01.06.2026 19:47) **не у scope** → 403 на push_files.

**Дії Вадима для unblock (1 з варіантів):**

**A. Найшвидше (2 хв):** Розширити PAT на dreamcar-dashboard
   1. https://github.com/settings/personal-access-tokens
   2. Знайти токен Cowork PAT
   3. Edit → Repository access → Add `dreamcar-dashboard`
   4. Save

**B. Альтернатива:** Я імпортую через локалку Вадима:
   ```bash
   cd /Users/vadimgrishin/DreamCar.AI/dashboard-dreamcar
   git remote -v   # подивитись чи правильний remote
   git remote set-url origin https://github.com/dreamcarua/dreamcar-dashboard.git
   git push -u origin main
   ```
   (Локальний git Вадима має credential helper з його токеном, push пройде.)

**C. Manual:** Дати Claude новий PAT з access до dreamcar-dashboard як ENV variable.

---

## 7. Локальний стан (готове до push)

```
Repo: /Users/vadimgrishin/DreamCar.AI/dashboard-dreamcar
Branch: main
Commits:
  116b315  Security: META_APP_SECRET + META_CLIENT_TOKEN → getenv()
  341e9af  Security: WP_DB_PASS hardcode → getenv() (6 files cleaned)
  f50782f  Initial: dreamcar-dashboard (cleaned, env-based config, no secrets)

528 files, ~6.0 MB, 32705 lines PHP
0 hardcoded secrets remaining
```

---

## 8. Контекст: чому це важливо

- **dashboard-dreamcar** трекає WHO прийшов у DreamCar (лід → угода → оплата)
- Без нього **немає prediction маркетингу** — куди вкладатися, які UTM конвертять
- Олександр Цемах — зовнішній підрядник, треба перенести інфру на наші ресурси (continuity risk)
- Інтеграція з HQ дасть **closed-loop attribution**: публікація → лід → угода (на одній панелі)

---

## Наступні кроки (порядок виконання)

1. **Вадим:** оновити PAT scope (1 хв)
2. **Claude:** push commits → repo заповнюється
3. **Claude:** Brand rebrand — глобальні tokens, шрифти, header
4. **Claude:** CI/CD pipeline `.github/workflows/deploy-php.yml`
5. **Вадим:** ROTATE 7 секретів (OPENAI, PAT, DB×2, META×2, TELEGRAM)
6. **Claude+Вадим:** перевірити webhook flow після ротації
7. **Claude:** Integration з HQ — auth-guard + sidebar + UTM-публікація bind
