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

Архів закритих пунктів: `memory/tasks/done-2026-09.md`.

## Аудит дашборду 15.09.2026 — що лишилось відкритим

Закрите (три черги, з причинами й таблицею звірки) — у `memory/tasks/done-2026-09.md`.
Технічні причини — у `memory/traps.md`, записи від 15.09.2026.

- ⚪ **`crm_deals.pay_provider` у MySQL так і не заповнюється** — це сторона сайту (`/etron/api/quick-buy.php` знає провайдера, але в CRM-угоду його не пише). Ми обходимо через `checkout_events`, чого достатньо. Якщо колись правитимемо чекаут — писати шлюз одразу в угоду. [15.09.2026]
- ⚪ **1 144 pending-угоди вересня без платіжки (23 % покриття)** — частина людей кидає чекаут до вибору шлюзу, це нормально. Перевірити ще раз, якщо покриття по `pay` впаде нижче 90 %. [15.09.2026]
- ⚪ **AUDI Q7 Prestige true_roi_pct = −3,5 %** станом на 15.09 — приз і фіксовані витрати вже пораховані повністю, а цикл іде до 27.09. Не помилка, але варто дивитись на цю цифру в кінці циклу, не всередині. [15.09.2026]

## Доступи до БД — перевірено 15.09.2026

Закрито (SQL: `etl/migrations/20260915d_rls_and_cron_cleanup.sql`):

- ✅ **4 таблиці читалися ключем із публічного сайту** — `ads_executor_rules` і `_bak_ads_utm_20260915` (обидві цієї сесії), `_backup_deals_iphone_relabel_20260904` і `_cron_job_backup_verify_pub_20260904` (04.09). RLS увімкнено, гранти забрано. PII у них немає — це суми, мітки проєктів і назви угод.
- ✅ **`v_project_windows` був SECURITY DEFINER** і відкритий anon — віддавав би `dashboard_projects` і `launches` в обхід RLS. Тепер `security_invoker = true` + без грантів.
- ✅ **23 сміттєві крони `verify_pub_*`** прибрано. Розклад без року → спрацювали б у вересні 2027 і надіслали в SMM-групу торішні «Час публікації!». Було 87 джоб / 76 активних після чистки.

Відкрите — потребує окремого проходу, наосліп різати не можна:

- ⏸ **105 SECURITY DEFINER функцій викликаються `anon`, 116 — `authenticated`.** Велика поверхня, накопичена за рік. Потрібен окремий прохід: для кожної вирішити — забрати EXECUTE, перевести в SECURITY INVOKER, або лишити свідомо. [15.09.2026]
- ⏸ **5 старих SECURITY DEFINER view:** `projects`, `v_project_label_bleed`, `v_dashboard_webhook_health`, `inventory_variant_qty`, `v_creative_usages`. Для кожного спершу знайти, хто читає. [15.09.2026]
- ⚪ **`_dispatch_debounce`** — RLS off, гранти anon/authenticated. Таблиця мертва (один рядок від 11.09, жодної згадки в жодному репо org), але не наша — не чіпав. Крок: зʼясувати, хто її писав, і або прибрати, або закрити. [15.09.2026]
- ⚪ **Три TG-публікації від 05–06.09 висять `in_work` і неверифіковані** («характеристики», «ЩО НАСПРАВДІ ОЗНАЧАЄ PRESTIGE?», «хорошо едет и еще за 199грн»). Їхні крони прибрано як безкорисні, але сам статус ніхто не закрив — це до SMM, не до дашборду. [15.09.2026]

## Інцидент 15.09.2026 19:27 — ETL стояв 2 години через мій тригер

Закрито (SQL: `etl/migrations/20260915e_incident_drop_pay_provider_trigger.sql`):

- ✅ Тригер `tg_dashboard_deals_pay_provider` прибрано разом із функцією, щоб ніхто не причепив назад. Платіжку заповнює `reconcile_pay_provider()` кожні 10 хв (43 мс на прогін).
- ✅ ETL запущено вручну, дані наздогнали: за 2 години 49 оплат на 25 249 ₴ — збігається з трекером чекауту (46 успішних).
- ✅ `anomaly-alerter` v6: freshness-гейт (застарілі дані = алерт про пайплайн, не про виручку), звірка з `checkout_events`, і системний промпт ШІ більше не має права радити паузу/зупинку/зниження бюджетів. Джерело функції тепер у git — `etl/edge/anomaly-alerter/index.ts` (раніше жила лише в Supabase).

Лишилось:

- ⚪ **Інші Edge-функції теж поза git.** `anomaly-alerter` була не в жодному репо — перевірити решту (`list_edge_functions` проти вмісту репозиторіїв) і покласти джерела під контроль версій. [15.09.2026]
- ⚪ **`rangeRevenue()` в алертері сумує клієнтськи з `.limit(20000)`** — той самий клас тихого обрізання, що був в Огляді. На 2-годинному вікні безпечно, на добових — уже ні, якщо день перевалить 20k оплат. [15.09.2026]
- ⏸ **ETL MySQL має один канал розкладу — pg_cron `30 * * * *` (щогодини).** Один пропущений прогін = година без даних. Варто або підняти частоту, або додати резервний `schedule:` у воркфлоу. Рішення за Вадимом — це CI-хвилини. [15.09.2026]

## Звірка маршрутів із SQL — 15.09.2026

Період звірки: AUDI Q7 Prestige, 03.09–27.09, кожне число проти прямого SQL.

| Маршрут | Результат |
|---|---|
| Огляд | точно (після фіксу знаменника і ліміту 30k) |
| Проекти | точно — 5 циклів, ліди й оплати до одиниці |
| Аналітика | точно — замовлення, оплати, виручка, AOV, success rate |
| Кампанії | точно — 6 рядків × 4 колонки |
| Джерела | точно — 6 рядків × 3 колонки |
| Виконавець | точно (+2 угоди — навмисно, UTM-розрізи не звужуються по проєкту) |
| Ads Campaigns | точно — 3 кампанії × 3 колонки, звуження по проєкту працює |
| Таблиця | рядки точно; **знайдено і виправлено** час на годину раніше за київський |
| Аналітика, тайли покупців | **знайдено і виправлено** 3 654 vs 3 651 — чотири різні визначення |

Виправлено під час звірки:

- ✅ **Час у таблицях по браузеру, а не по Києву.** `fmtDate`/`fmtDateTime` без `timeZone`. У Польщі все на годину раніше за Київ, біля півночі розійшлась би й дата.
- ✅ **Чотири визначення «унікального покупця»** зведено до одного: email, а якщо немає — телефон. Троє людей у циклі #21 заплатили без email і зникали з трьох із чотирьох лічильників.

Перевірено окремо:

- ✅ **Експорт CSV** — BOM для Excel, екранування лапок подвоєнням, лапки при комі/переносі. Віддає сирий ISO зі зсувом, а не відформатований час: для Excel це правильно, але відрізняється від екрана.

Лишилось непровіреним (менший ризик, окремі code paths):

- ⚪ Комбінації, Тип трафіка, Оголошення — той самий RPC-шлях, що «Джерела» і «Виконавець», який звірено двічі з точним збігом. [15.09.2026]
- ⚪ Cohort Retention (matview, read-only), Webhook логи, UTM Mapping, People Merge, Manual Costs, Settings. [15.09.2026]
- ✅ **Гранулярність «Тиждень»** — перевірено і виправлено: підписи були неділями замість понеділків (`toISOString` на локальній півночі). Групування було правильне, брехав лише підпис.
- ✅ **UTM free-text фільтри** — перевірено: на Огляді працюють точно (740/625/230 195 ₴ = SQL), на RPC-розділах мовчки ігнорувались. Поставлено банер; правильний фікс нижче.
- ⏸ **Додати UTM-параметри у RPC** (`dashboard_kpi_with_delta`, `dashboard_extended_kpi`, `dashboard_agg_deals_with_traffic`) — щоб фільтр працював скрізь, а не лише там, де угоди читаються порядково. Після сьогоднішнього інциденту з тригером фінансові функції чіпаю лише окремим проходом із заміром до/після. [15.09.2026]
- ⚪ Гранулярність «Година» і «Місяць», комбінації UTM (`f-combo-type`). [15.09.2026]
- ⚪ Мобільна верстка / drawer фільтрів. [15.09.2026]
