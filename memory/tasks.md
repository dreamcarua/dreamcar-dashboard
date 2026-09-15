# DreamCar Dashboard — open tasks

Updated: 15.09.2026
Tracker: none known (<?> — team.dreamcar.ua board?). This file holds what no tracker has.

🔴 breaks production · 🟡 unfinished tail · ⚪ queued · ⏸ waiting for a human decision

## 🔴 Breaks production

- (none found on 03.09.2026 — bots commit data normally, last human commit 24.08)

## 🟡 Tails — started, not finished

- **Daily Meta digest (`meta-stats-sync` → `post_tg_digest`) was silently skipped until 03.09** — found 03.09 by Claude: `TG_CHAT_ID` did not exist and `GH_TEAM_NOTIFY_TOKEN` is not set, so both delivery paths were dead and the script logs "skipped". `TG_CHAT_ID` now exists (set 03.09 21:40 → direct path should work). Next step: after the next scheduled run check the log for the digest send (`gh run list --workflow=meta-stats-sync.yml --limit 1` → `gh run view <id> --log | grep -i digest`) and that the message arrived. If the digest should go to a different chat than agent reports — split into a second secret, do not reuse `TG_CHAT_ID`. [handed over 03.09, waiting for Vadym]
- **Bot token was pasted into a Cowork chat on 03.09** — rotate when convenient: @BotFather → `/revoke` for `@dreamcar_team_bot`, then `gh secret set TG_BOT_TOKEN -R dreamcarua/dreamcar-dashboard` AND `-R dreamcarua/dreamcar-team` (same bot, two repos), plus the team-board backend that answers `/start`. Until rotated nothing breaks. [handed over 03.09, waiting for Vadym]

## ⚪ Queue

- **Мертвий воркфлоу `report-to-telegram.yml`** · з 05.09.2026 канал звітів один на всі проєкти — приватний міст `dreamcarua/memory-kit` (див. `memory/tooling.md` → Reporting). Цей воркфлоу більше не використовується (останній запуск 03.09.2026). Наступний крок: або вимкнути (`gh workflow disable report-to-telegram.yml -R dreamcarua/dreamcar-dashboard`), або переписати під коміт у міст. Файли в `.github/workflows/` через GitHub API не правляться — тільки з Mac (`gh` / git). [09.09.2026, аудит каналу звітів]
- **Root `README.md` describes the legacy PHP UTM dashboard, not this repo** — found 03.09 by Claude. `docs/README.md` says the site is served from `web/`, but the folder is `docs/`. Next step: rewrite `README.md` in 20 lines for what the repo is now (ETL + Pages + Actions); fix `web/` → `docs/` in `docs/README.md`.
- **`.env.example` still carries DB host and port** — identifiers in a public repo (decision 03.09: repo stays public, identifiers leave files). Next step: replace `DB_HOST`/`DB_PORT`/`WP_DB_*` values with placeholders; real values in the password manager and server `.env`.

## ⏸ Waiting for a decision

| Task | Why it waits | Whose call | Since |
|---|---|---|---|
| Where agent reports should land: Vadym's direct chat with the bot (current) or a group | current `TG_CHAT_ID` is the direct chat from `/start` on 03.09; a group needs its own negative id | Vadym | 03.09.2026 |
| Is there a task tracker at all — the `team.dreamcar.ua` board or nothing | line 4 of this file has said `Tracker: none known (<?>)` since 03.09.2026; until it is answered every session reads the guess as a fact, and nobody knows whether this file should hold everything or only what a tracker does not. Found 10.09.2026 by `verify-install.py` (`dreamcarua/repo-memory`) | Vadym | 10.09.2026 |

## Done, awaiting confirmation

- Report channel `reports/*.json` → Telegram: live, run c69b31f delivered 03.09 21:41. [Claude 03.09]

## Зібрано з HARVEST старих чатів 04.09.2026

- 🟡 **SMM Watchdog працює у двох каналах одночасно** — Edge-функція підключена до pg_cron (`25 4-19`), а GH-воркфлоу навмисно НЕ вимкнено: мали попрацювати добу паралельно й звіритись, звірка не відбулась. Крок: порівняти, чи Edge шле ті самі алерти, тоді `gh workflow disable "SMM Content Watchdog (IG stories/posts)" -R dreamcarua/dreamcar-dashboard`. [27.08]
- ⚪ **ETL FB Ads на Edge — ймовірно не робити.** Впирається в ліміт CPU 2 с; лишити в Actions. [27.08]
- ✅ **905 липневих оплат перенесено на липневий проєкт** (04.09.2026, рішення Вадима «обидва»). Червень: 3873/725 316 ₴ → 2968/557 456 ₴; липень: 1060/196 519 ₴ → 1965/364 379 ₴. Тригер зроблено date-aware, бекап `_backup_deals_iphone_relabel_20260904`, детектор `v_project_label_bleed`. [Claude]
- ⚠️ **Жорсткий фільтр по датах у MV НЕ впроваджено — і не треба.** Виміряно 04.09.2026: угоди легітимно приходять після закриття вікна (Архів −3771, e-tron −148, «Три iPhone» −125, X6M −33, X5 −24, Mustang −19, VOLVO −8). Жорсткий фільтр стер би зі звітності ~4100 реальних оплат. Клас помилки лікується на рівні тригера (мітка ставиться з урахуванням дати) + детектор перетікання, а не фільтром у MV.
- ⚪ **52 оплати з міткою AUDI E-TRON у вікні BMW X6M** (9831 ₴, 10.07-02.08) — єдине, що лишилось у `v_project_label_bleed`. Розтягнуті на 3 тижні, схоже на справжні пізні доплати, не на невимкнені лінки. Крок: подивитись префікси `deal_name`, якщо `DCI-audi-` з липневими датами — вирішити, чий це цикл.
- ⚪ **Budget на Actions ($20)** — просили поставити після аварії з квотою 24-27.08, підтвердження не було.

## Аудит дашборду 15.09.2026 (запит Вадима: «працює дивно — фільтри, дати і тп»)

Виправлено в цьому ж коміті (деталі й причини — `memory/traps.md`, записи від 15.09.2026):

- ✅ **Активний розіграш показував 0 оплат** — `launches` #21 отримав `code='audi_q7_prestige'` і `deal_aliases=['AUDI Q7 PRESTIGE']`; у `loadProjects()` список значень `project` тепер з `mv_dashboard_filter_options`, fallback-матчинг не краде чужі alias-и.
- ✅ **«Останні N днів» = N+1 день** — пресети 7d/30d/60d рахують рівно N діб.
- ✅ **Success rate рахувався по різних охопленнях** — `PROJECT_REF_MAP` перероблено на `code`, без мапінгу `fetchLeadsCount()` повертає `null` і знаменником стає кількість угод того ж проєкту.
- ✅ **Витрати fortunatos осідали на Артемі** — `transform_row()` більше не штампує account-level виконавця поверх реальних `url_tags`; додано carry-forward по `ad_id`. Історія виправлена: 80 рядків, 28 402 ₴ за 08–15.09 повернуто на `fortunatos`; бекап `_bak_ads_utm_20260915`.

Знайдено, не виправлено:

- ✅ **Фільтр «Платіжка» заповнено** (15.09.2026, друга черга) — `pay_provider` заповнений у 79 із 5 934 вересневих угод (1,3 %). Будь-який вибір ховає ~99 % даних. Рішення Вадима: або заповнювати поле в ETL/вебхуку, або прибрати фільтр з панелі. [15.09.2026]
- ✅ **Витрати звужуються по проєкту** (15.09.2026, друга черга) — при виборі розіграшу виручка звужується, а spend ні. Для одночасних циклів ROI/ROAS будуть завищені по витратах. Крок: звузити `adsBaseRange()` по `utm_campaign`/`launch` або додати `project` у `dashboard_ads_data`. [15.09.2026]
- ✅ **Виконавці: кілька на один акаунт** (15.09.2026, друга черга) — таблиця мапить акаунт → ОДНОГО виконавця, а в CLUB UAH їх двоє. Поки url_tags приходять — не болить; після 15.09 fallback уже не переписує реальні мітки. Крок: або дробити акаунти по виконавцях, або визнати таблицю legacy. [15.09.2026]

### Друга черга аудиту — деталі (15.09.2026)

- **Платіжка.** Справжнє джерело — `checkout_events.meta->>'gateway'` (наш трекер чекауту), join по `order_reference`. Бекфіл 36 737 угод; тригер `tg_dashboard_deals_pay_provider` + `reconcile_pay_provider()` на pg_cron (:17 щогодини). Покриття: 93,6 % оплачених угод вересня, 16 значень у списку фільтра. Ручні мітки операторів (`monobank_*`, `privat_*`, `otp_*`, `ukrsib_*`, `oschad_*`, `pumb_*`) не перетираються.
- **Витрати по проєкту.** Нова колонка `dashboard_ads_data.project` + тригер `tg_dashboard_ads_stamp_project` через `resolve_ads_project(date_start)` і view `v_project_windows`. На фронті `narrowAdsByProject()` у 5 місцях. Свідомий виняток — розрізи по UTM-полях (там і бік угод не звужується).
- **Виконавці.** `ads_executor_rules` (патерн назви кампанії → виконавець, priority) + `ads_account_to_executor.is_exclusive`. CLUB UAH/USD/TEST позначені як не-ексклюзивні: там account-level дефолт більше не застосовується. Перше правило: `Fortunatos |%` → `fortunatos`.
- **Першопричина зникнення url_tags:** FB віддавав **500** на `/act_1057590556523878/ads` з важкою експансією `creative{object_story_spec,asset_feed_spec}` при `limit=200`. Додано сходи деградації набору полів (останній схід — лише `id,name,url_tags`).
- **Висновок по циклу #21:** Артем витрат на рекламу НЕ мав узагалі. Усі 11 402 ₴, що показувались на ньому, — Fortunatos. 1 097 оплат Артема (433 738 ₴) — органіка / TG / розсилки, а не платний трафік.

Лишилось на потім:

- ⚪ **`crm_deals.pay_provider` у MySQL так і не заповнюється** — це сторона сайту (`/etron/api/quick-buy.php` знає провайдера, але в CRM-угоду його не пише). Ми обходимо через `checkout_events`, чого достатньо. Якщо колись правитимемо чекаут — писати шлюз одразу в угоду. [15.09.2026]
- ⚪ **1 144 pending-угоди вересня без платіжки (23 % покриття)** — частина людей кидає чекаут до вибору шлюзу, це нормально. Перевірити ще раз, якщо покриття по `pay` впаде нижче 90 %. [15.09.2026]
