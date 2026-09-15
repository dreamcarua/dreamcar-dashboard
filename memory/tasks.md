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

## Третя хвиля 16.09.2026 — безпека, маршрути, чесний ROAS на Огляді

Закрито:

- ✅ **Логін-гейт був лише візуальним.** Публічним ключем без сесії читались: виручка й покупці (`dashboard_kpi_summary` → 1 966 121 ₴ / 3 691), щоденний P&L, P&L по проєктах, уся UTM-агрегація з виручкою, chat ID Telegram, ретеншн-історія, A/B-конфіг, залишки складу, креативи. Міграція `20260916a`: `anon` тепер має 0 таблиць і 0 функцій у `public`; знято anon із `ALTER DEFAULT PRIVILEGES`, щоб не відростало; RLS на `_dispatch_debounce`; 5 в'ю з SECURITY DEFINER переведено на `security_invoker`. Лінтер Supabase: усі 6 ERROR зникли. Перевірено: усі 7 дашбордів + HQ під логіном працюють.
- ✅ **Когорти рахувались по email і в UTC.** `mv_dashboard_cohort_retention` ігнорувала покупців без email і різала місяці за UTC, тож оплата о 01:30 Києва 1-го числа падала у ПОПЕРЕДНІЙ місяць. UI показував 432/758/1863/2607, правда — 476/774/1847/2611. Міграція `20260916b`.
- ✅ **ROAS 11,72× на першому екрані.** Блок «ЕКОНОМІКА» ділив усю виручку (з органікою) на рекламні витрати. Тепер два блоки: «ЕКОНОМІКА РЕКЛАМИ · платний трафік» (2,63×, CPL 127 ₴, CPA 150 ₴) і «ЗАГАЛОМ ПО ПРОЄКТУ · з органікою» з явним підписом «НЕ ефективність реклами».
- ✅ **Дельти.** Прочерк, коли база менша за 5 % поточного; кратність замість відсотка на 500–1900 %; база завжди у підказці.
- ✅ **«Угоди undefined»** при гранулярності «Година» — у мапі підписів не було ключа 'hour'.

Перевірено й виявилось справним (звірка з прямим SQL):

| Маршрут | Результат |
|---|---|
| Комбінації | 104 комбінації = SQL, топ-5 рядків збігаються |
| Cohort | після фікса 476/774/1847/2611/2529/1711 = SQL |
| Webhook логи | 1 082/1 082/35 і 4/4/0 за 24 год = SQL, час у Києві |
| Manual Costs | 0 записів — у базі теж 0, екран не зламаний |
| UTM Mapping, People Merge | 0 правил — у базі теж 0 |
| Settings | deals 249 983, webhooks 11 868, ads 12 374 = SQL |
| Гранулярність | година (297 точок), день (13), тиждень, місяць (1) — усі рахують правильно |
| Cards, USDT, Payroll | чесні заглушки «coming soon», не поламані екрани |

Лишилось:

- ✅ **Каса відкрита для Вадима** (міграція 20260916c). `kasa_is_allowed()` була хардкодом двох пошт без `vg@abrisart.com`, тож екран мовчки показував нулі (RLS віддає порожнє, а не помилку). Переведено на ролі ceo+cfo (як у сусідніх kasa-таблиць), старі пошти залишені як OR. coo НЕ додавав — це гроші, окреме рішення. [16.09.2026]
- ⚪ **249 SECURITY DEFINER функцій доступні `authenticated`.** Було 116 явних + решта через PUBLIC; міграція 20260916a зробила успадковані гранти явними (статус-кво не змінився, лінтер тепер їх бачить). Звузити до тих, що реально потрібні дашбордам, — окремий прохід із per-function рішенням. [16.09.2026]
- ⚪ **6 матв'ю читаються роллю `authenticated`** (P&L, upsell, UTM-агрегація). Для дашбордів це потрібно; звузити можна лише через RPC-обгортки з роль-чеком. [16.09.2026]
- ⚪ **Мобільна верстка** структурно є (медіа-запити до 480px, таблиці у `.table-wrap` зі скролом), але візуально не переглянута. [16.09.2026]
- ⚪ **`launches` (16) і `dashboard_projects` (13) — два реєстри**, як і записано у пастках. Новий розіграш треба заводити в обидва. [16.09.2026]


## Рішення Вадима 16.09.2026 — доступ до грошей

**Гроші на рівні БД бачать ceo / coo / lead** (той самий список, що у JS-гейті головного
дашборду). **Самореєстрацію через Google лишаємо відкритою**, покладаємось на ролі.

Реалізовано:

- ✅ `dashboard_money_visible(roles)` — гейт: службові виклики (pg_cron без JWT,
  service_role) проходять; anon ні; залогінений — лише з дозволеною роллю і `is_active`.
- ✅ Вшито у 7 грошових RPC (`kpi_summary`, `kpi_with_delta`, `extended_kpi`,
  `daily_series`, `hourly_series`, `hourly_heatmap`, `traffic_type_summary`).
- ✅ `dashboard_agg_deals_with_traffic`: гілка `else` (повний доступ будь-якому
  залогіненому) звужена до дозволених ролей. Роль `buyer` лишилась як була.
- ✅ `dashboard_rpc_cache` — знято політику `rpc_cache_read` (вона тримала сирі
  результати грошових RPC у jsonb і читалась будь-ким залогіненим, в обхід гейту).
- ✅ Знято `authenticated` з `mv_finance_daily_pnl`, `mv_dashboard_project_pnl`,
  `mv_dashboard_utm_agg`, `mv_dashboard_projects_stats` (жодна сторінка не читає їх
  напряму — тільки SECURITY DEFINER RPC і серверні Edge-функції).

Перевірено підстановкою JWT: ceo/coo/lead → повні дані; member/buyer/cfo/anon → нулі;
service_role і postgres → повні. Живі сторінки після змін: головний дашборд, Аналітика,
/finance/ (Net Profit 479,5k грн), /pricing-analysis/ (13 проєктів) — усе працює.

Лишилось по цій темі:

- ⚪ **Фінансові RPC (`dashboard_finance_overview`, `period_pnl`, `project_pnl`,
  `project_pnl_cached`, `additional_income`) ще не мають роль-чеку.** Вони
  SECURITY DEFINER і великі (7,5–11,5 КБ plpgsql); вшивати гард автоматично у них
  ризиковано — на них висять щоденні фінансові звіти. Робити окремим проходом,
  ролі ceo/coo/cfo (як у JS-гейті /finance/). [16.09.2026]
- ⚪ **`kasa_bank_page` і `kasa_stale_accounts`** — єдині SECURITY DEFINER серед
  kasa-функцій, тобто обходять RLS `kasa_is_allowed()`. Решта kasa-RPC —
  SECURITY INVOKER і вже захищені. [16.09.2026]
- ⚪ **`upsell_daily` має два перевантаження**, одне SECURITY INVOKER — через нього
  `mv_upsell_daily` мусить лишатись читабельним для `authenticated`. Звести до
  одного definer-варіанта, потім закрити матв'ю. [16.09.2026]
- ⚪ **Німий екран при відмові RLS.** RLS віддає ПОРОЖНЄ, а не помилку: Каса під
  `vg@abrisart.com` показувала нулі й ніде не писала «немає доступу». Той самий клас,
  що й UTM-фільтри без банера. Треба: порожньо при непорожньому періоді → підпис
  «доступ обмежено». [16.09.2026]
- ⚪ **CFO не має доступу до головного дашборду** — ні у JS-гейті (`ceo/coo/lead`
  з червня), ні тепер у БД. Артем працює у /finance/, /kasa/, /pricing-analysis/.
  Якщо це не навмисно — додати `cfo` в обидва місця. [16.09.2026]
