# DreamCar Dashboard — open tasks

Updated: 03.09.2026
Tracker: none known (<?> — team.dreamcar.ua board?). This file holds what no tracker has.

🔴 breaks production · 🟡 unfinished tail · ⚪ queued · ⏸ waiting for a human decision

## 🔴 Breaks production

- (none found on 03.09.2026 — bots commit data normally, last human commit 24.08)

## 🟡 Tails — started, not finished

- **Daily Meta digest (`meta-stats-sync` → `post_tg_digest`) was silently skipped until 03.09** — found 03.09 by Claude: `TG_CHAT_ID` did not exist and `GH_TEAM_NOTIFY_TOKEN` is not set, so both delivery paths were dead and the script logs "skipped". `TG_CHAT_ID` now exists (set 03.09 21:40 → direct path should work). Next step: after the next scheduled run check the log for the digest send (`gh run list --workflow=meta-stats-sync.yml --limit 1` → `gh run view <id> --log | grep -i digest`) and that the message arrived. If the digest should go to a different chat than agent reports — split into a second secret, do not reuse `TG_CHAT_ID`. [handed over 03.09, waiting for Vadym]
- **Bot token was pasted into a Cowork chat on 03.09** — rotate when convenient: @BotFather → `/revoke` for `@dreamcar_team_bot`, then `gh secret set TG_BOT_TOKEN -R dreamcarua/dreamcar-dashboard` AND `-R dreamcarua/dreamcar-team` (same bot, two repos), plus the team-board backend that answers `/start`. Until rotated nothing breaks. [handed over 03.09, waiting for Vadym]

## ⚪ Queue

- **Root `README.md` describes the legacy PHP UTM dashboard, not this repo** — found 03.09 by Claude. `docs/README.md` says the site is served from `web/`, but the folder is `docs/`. Next step: rewrite `README.md` in 20 lines for what the repo is now (ETL + Pages + Actions); fix `web/` → `docs/` in `docs/README.md`.
- **`.env.example` still carries DB host and port** — identifiers in a public repo (decision 03.09: repo stays public, identifiers leave files). Next step: replace `DB_HOST`/`DB_PORT`/`WP_DB_*` values with placeholders; real values in the password manager and server `.env`.

## ⏸ Waiting for a decision

| Task | Why it waits | Whose call | Since |
|---|---|---|---|
| Where agent reports should land: Vadym's direct chat with the bot (current) or a group | current `TG_CHAT_ID` is the direct chat from `/start` on 03.09; a group needs its own negative id | Vadym | 03.09.2026 |

## Done, awaiting confirmation

- Report channel `reports/*.json` → Telegram: live, run c69b31f delivered 03.09 21:41. [Claude 03.09]

## Зібрано з HARVEST старих чатів 04.09.2026

- 🟡 **SMM Watchdog працює у двох каналах одночасно** — Edge-функція підключена до pg_cron (`25 4-19`), а GH-воркфлоу навмисно НЕ вимкнено: мали попрацювати добу паралельно й звіритись, звірка не відбулась. Крок: порівняти, чи Edge шле ті самі алерти, тоді `gh workflow disable "SMM Content Watchdog (IG stories/posts)" -R dreamcarua/dreamcar-dashboard`. [27.08]
- ⚪ **ETL FB Ads на Edge — ймовірно не робити.** Впирається в ліміт CPU 2 с; лишити в Actions. [27.08]
- ⏸ **Фільтр по датах у MV проєктів** (виправляє подвійний рахунок і розподіл 905 липневих оплат) — перерахує історичні цифри всіх проєктів, потрібне рішення Вадима. [17.08]
- ⚪ **Budget на Actions ($20)** — просили поставити після аварії з квотою 24-27.08, підтвердження не було.
