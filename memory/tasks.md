# DreamCar Dashboard — open tasks

Updated: 03.09.2026
Tracker: none known (<?> — team.dreamcar.ua board?). This file holds what no tracker has.

🔴 breaks production · 🟡 unfinished tail · ⚪ queued · ⏸ waiting for a human decision

## 🔴 Breaks production

- (none found on 03.09.2026 — bots commit data normally, last human commit 24.08)

## 🟡 Tails — started, not finished

- **Report channel: workflow file + two secrets** — Vadym, from the Mac: copy `memory-kit/templates/.github/workflows/report-to-telegram.yml` into this repo and push; then `gh secret set TELEGRAM_BOT_TOKEN -R dreamcarua/dreamcar-dashboard`, `gh secret set TELEGRAM_CHAT_ID -R dreamcarua/dreamcar-dashboard` (values = dashboard `.env`). Next step after that: commit the first report `reports/<date>-memory-installed.json` and check it arrives. [handed over 03.09, waiting for Vadym]

## ⚪ Queue

- **Root `README.md` describes the legacy PHP UTM dashboard, not this repo** — found 03.09 by Claude. `docs/README.md` says the site is served from `web/`, but the folder is `docs/`. Next step: rewrite `README.md` in 20 lines for what the repo is now (ETL + Pages + Actions); fix `web/` → `docs/` in `docs/README.md`.
- **`.env.example` still carries DB host and port** — identifiers in a public repo (decision 03.09: repo stays public, identifiers leave files). Next step: replace `DB_HOST`/`DB_PORT`/`WP_DB_*` values with placeholders; real values in the password manager and server `.env`.

## ⏸ Waiting for a decision

| Task | Why it waits | Whose call | Since |
|---|---|---|---|
| (none) | | | |
