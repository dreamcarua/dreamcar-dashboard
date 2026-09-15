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
