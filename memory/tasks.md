# DreamCar Dashboard — open tasks

Updated: 03.09.2026
Tracker: none known (<?> — team.dreamcar.ua board?). This file holds what no tracker has.

🔴 breaks production · 🟡 unfinished tail · ⚪ queued · ⏸ waiting for a human decision

## 🔴 Breaks production

- (none found on 03.09.2026 — bots commit data normally, last human commit 24.08)

## 🟡 Tails — started, not finished

- **Memory system v8 installed on branch `memory-v8`, not on `main`** — Vadym reviews and merges, or asks for changes. Next step: merge; then delete this line when confirmed. [handed over 03.09, waiting for Vadym]

## ⚪ Queue

- **Root `CLAUDE.md`/`README.md` described the legacy PHP UTM dashboard, not this repo** — found 03.09 by Claude (memory install). README still says "utm-dashboard/ index.php…"; `docs/README.md` says the site is served from `web/`, but the folder is `docs/`. Next step: rewrite `README.md` in 20 lines for what the repo is now (ETL + Pages + Actions); fix `web/` → `docs/` in `docs/README.md`.
- **Public repo carries infrastructure identifiers** — found 03.09 by Claude: the old `CLAUDE.md` (now `memory/archive/`) lists a VPS IP, DB host/port/name, Telegram bot and chat IDs, SSH host and a local Mac path. SECURITY.md already treats old secrets as compromised. Next step: Vadym decides — (a) keep public and strip the archive file to non-identifying text, or (b) make the repo private (costs CI minutes — decision of 24.08 was to stay public). Recorded in ⏸ below.
- **Reporting channel not configured for agent reports** — the repo has Telegram for ETL alerts (`TELEGRAM_*`, `GIT_SYNC_TG_*` in `.env.example`) but no "commit JSON → Action → TG" path for session reports. Next step: after Vadym confirms which chat, add `.github/workflows/report-to-telegram.yml` from memory-kit A.8 (two secrets: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`).

## ⏸ Waiting for a decision

| Task | Why it waits | Whose call | Since |
|---|---|---|---|
| Repo visibility vs identifiers in public files | private = CI minutes billed (decision 24.08); public = identifiers visible | Vadym | 03.09.2026 |
| Which Telegram chat receives agent session reports | two chats exist (dashboard alerts, git sync); reports may need a third | Vadym | 03.09.2026 |
