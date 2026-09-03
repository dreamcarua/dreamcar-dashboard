# DreamCar Dashboard — tooling, access, reporting

Read before using any tool, MCP, server, database or account of this project. Never store secret values here; store where they live. This repo is PUBLIC: no hosts, IPs, chat IDs, usernames — names of secrets and where they live only.

## Tools and connectors

| Tool / MCP / connector | Used for | How to get in | Quirks |
|---|---|---|---|
| GitHub MCP (Cowork) | read/write this repo from any chat | authorised for `dreamcarua/*` | `push_files` = one commit; branch first for anything reviewable; the token cannot create repos, delete files, or push `.github/workflows/*` (no workflow scope) — those go via git from the Mac |
| GitHub Actions | all ETL, ads operations, reports | `.github/workflows/*.yml`; secrets in repo Settings → Secrets (`gh secret set`) | shared quota 3000 min/month across all repos; every job rounds up to 1 min; put `timeout-minutes` everywhere |
| Supabase MCP (`dreamcar-supabase` plugin) / GitHub Action | SQL, migrations, edge functions, pg_cron, logs | project ref `wotghlaehnvxyeacznvv` (HQ); deploy via commit under `etl/migrations/` or MCP `apply_migration` | pg_cron job for ETL trigger lives here — it is a second trigger channel (traps.md) |
| Meta Marketing API | ads sync, scaling, kill switches | `META_APP_ID` public in `.env.example`; secret/token in GitHub secrets | workflows `meta-scale`, `kill-all-ads`, `delete-ads`, `launch-*` spend or stop real money — ask first |
| SendPulse CRM | deals sync, webhooks | `SENDPULSE_ID/SECRET` in GitHub secrets / server `.env` | webhook URLs are bound to the legacy PHP host — see archive/CLAUDE.md; do not change without agreement |
| MySQL (legacy dashboard DB) | source of deals/webhooks for ETL | host/port/name/password in password manager and server `.env` | same DB used by both legacy servers |
| Legacy PHP servers (hosting + VPS) | webhooks receiver, legacy UI | SSH — hosts, users, passwords in 1Password "DreamCar Dashboard SSH" | hosting gets NO automatic git updates; VPS syncs via git-server-sync daemon |
| GitHub Pages | `dashboard.dreamcar.ua` from `docs/` | push to `main` | site is public even when the repo is private; data protected by RLS + auth-guard only |

## Identifiers (not secrets)

| What | Value | Where used |
|---|---|---|
| Supabase HQ project ref | `wotghlaehnvxyeacznvv` | MCP calls, REST from frontend (anon key) |
| Meta App ID | see `.env.example` | Marketing API |
| Everything else (DB host, servers, chat IDs) | password manager, server `.env` | never in this repo (decision 03.09.2026) |

## Secrets — where they live, never the values

| Secret | Lives in | Who can rotate |
|---|---|---|
| `DB_PASS`, `WP_DB_PASS`, `SENDPULSE_*`, `META_APP_SECRET`, `META_CLIENT_TOKEN`, `OPENAI_API_KEY`, `GITHUB_WEBHOOK_SECRET` | GitHub repo secrets + server `.env` | Vadym; history in SECURITY.md (old values compromised, rotated 06–08.2026) |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID` (dashboard alerts + agent reports), `GIT_SYNC_TG_*` | GitHub repo secrets / server `.env` | Vadym |
| Supabase access token | GitHub repo secret `SUPABASE_ACCESS_TOKEN` (org convention) | Vadym |

## Entry patterns — how a recurring action is actually done here

| Action | Steps | Fallback if the tool is down |
|---|---|---|
| Change ETL frequency | check BOTH channels (workflow `schedule:` + pg_cron job in Supabase) → edit both → note in commit | — |
| Run a migration on HQ | file under `etl/migrations/` → Action applies | Supabase MCP `apply_migration` |
| Trigger an ETL now | Actions → workflow → `workflow_dispatch` | run the script locally with `.env` |
| Publish frontend change | edit `docs/` → push `main` → Pages deploys | — |
| Update legacy PHP on hosting | manual SSH/FTP (see archive) — not git | — |
| Add or change a workflow file | git from the Mac (`gh` auth has workflow scope), not GitHub MCP | — |
| Send a session report | commit `reports/YYYY-MM-DD-HHMM-<slug>.json` on `main` → Action sends to Telegram | write the report in the reply and in tasks.md |

## Reporting

Mechanism: commit `reports/YYYY-MM-DD-HHMM-<slug>.json` on `main` → `.github/workflows/report-to-telegram.yml` → the dashboard notifications chat (decision 03.09.2026). Secrets: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID` in repo secrets. Until the workflow file and secrets are in place (tasks.md 🟡): report in the reply and in tasks.md.
When: at Exit of every task that changed project state. Not for questions, reading, estimates.
Format: `reports/README.md`. Plain text, no markup.

## Access limits — what the agent deliberately does not do

| Action | Who does it | Why not the agent |
|---|---|---|
| Change repository visibility | Vadym | switches CI minutes from free to billed (decision 24.08.2026) and exposes/hides files at once |
| Run `kill-all-ads`, `delete-ads`, `meta-scale`, `launch-*` | Vadym or with explicit OK | real ad money |
| Rotate any key | Vadym | agent cannot see consequences on both servers and Make/SendPulse |
| Change webhook URLs in Make/SendPulse | Vadym / Oleksandr | external systems bound to the legacy host |
