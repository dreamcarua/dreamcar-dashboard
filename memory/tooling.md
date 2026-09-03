# DreamCar Dashboard — tooling, access, reporting

Read before using any tool, MCP, server, database or account of this project. Never store secret values here; store where they live. This repo is PUBLIC: no hosts, IPs, chat IDs, usernames — names of secrets and where they live only.

## Tools and connectors

| Tool / MCP / connector | Used for | How to get in | Quirks |
|---|---|---|---|
| GitHub MCP (Cowork) | read/write this repo from any chat | authorised for `dreamcarua/*` | `push_files` = one commit; branch first for anything reviewable |
| GitHub Actions | all ETL, ads operations, reports | `.github/workflows/*.yml`; secrets in repo Settings → Secrets | shared quota 3000 min/month across all org repos; every job rounds up to 1 min; put `timeout-minutes` everywhere |
| Supabase MCP (`dreamcar-supabase` plugin) / GitHub Action | SQL, migrations, edge functions, pg_cron, logs | project ref `wotghlaehnvxyeacznvv` (HQ); deploy via commit under `etl/migrations/` or MCP `apply_migration` | pg_cron job for ETL trigger lives here — it is a second trigger channel (traps.md) |
| Meta Marketing API | ads sync, scaling, kill switches | `META_APP_ID` public in `.env.example`; secret/token in GitHub secrets | workflows `meta-scale`, `kill-all-ads`, `delete-ads`, `launch-*` spend or stop real money — ask first |
| SendPulse CRM | deals sync, webhooks | `SENDPULSE_ID/SECRET` in GitHub secrets / server `.env` | webhook URLs are bound to the legacy PHP host — see archive/CLAUDE.md; do not change without agreement |
| MySQL (legacy dashboard DB) | source of deals/webhooks for ETL | host/port/name in `.env.example` (`DB_*`, `WP_DB_*`); password in password manager | same DB used by both legacy servers |
| Legacy PHP servers (hosting + VPS) | webhooks receiver, legacy UI | SSH — hosts and users in `memory/archive/CLAUDE.md.2026-09-03.md`; passwords in password manager | hosting gets NO automatic git updates; VPS syncs via git-server-sync daemon |
| GitHub Pages | `dashboard.dreamcar.ua` from `docs/` | push to `main` | site is public even when the repo is private; data protected by RLS + auth-guard only |

## Identifiers (not secrets)

| What | Value | Where used |
|---|---|---|
| Supabase HQ project ref | `wotghlaehnvxyeacznvv` | MCP calls, REST from frontend (anon key) |
| Meta App ID | see `.env.example` | Marketing API |
| Everything else (DB host, servers, chat IDs) | `.env.example`, `memory/archive/CLAUDE.md.2026-09-03.md` | do not copy into new files |

## Secrets — where they live, never the values

| Secret | Lives in | Who can rotate |
|---|---|---|
| `DB_PASS`, `WP_DB_PASS`, `SENDPULSE_*`, `META_APP_SECRET`, `META_CLIENT_TOKEN`, `OPENAI_API_KEY`, `GITHUB_WEBHOOK_SECRET` | GitHub repo secrets + server `.env` | Vadym; history in SECURITY.md (old values compromised, rotated 06–08.2026) |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`, `GIT_SYNC_TG_*` | GitHub repo secrets / server `.env` | Vadym |
| Supabase access token | GitHub repo secret `SUPABASE_ACCESS_TOKEN` (org convention) | Vadym |

## Entry patterns — how a recurring action is actually done here

| Action | Steps | Fallback if the tool is down |
|---|---|---|
| Change ETL frequency | check BOTH channels (workflow `schedule:` + pg_cron job in Supabase) → edit both → note in commit | — |
| Run a migration on HQ | file under `etl/migrations/` → Action applies | Supabase MCP `apply_migration` |
| Trigger an ETL now | Actions → workflow → `workflow_dispatch` | run the script locally with `.env` |
| Publish frontend change | edit `docs/` → push `main` → Pages deploys | — |
| Update legacy PHP on hosting | manual SSH/FTP (see archive) — not git | — |

## Reporting

Mechanism: <?> not configured for agent session reports (03.09.2026). Existing Telegram channels serve ETL alerts and git-sync notifications. Proposed: commit `reports/YYYY-MM-DD-HHMM-<slug>.json` on `main` → `.github/workflows/report-to-telegram.yml` (memory-kit A.8) with secrets `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`. Waiting for Vadym: which chat (tasks.md ⏸).
Until then: report in the reply and in `memory/tasks.md`.

## Access limits — what the agent deliberately does not do

| Action | Who does it | Why not the agent |
|---|---|---|
| Change repository visibility | Vadym | switches CI minutes from free to billed (decision 24.08.2026) and exposes/hides files at once |
| Run `kill-all-ads`, `delete-ads`, `meta-scale`, `launch-*` | Vadym or with explicit OK | real ad money |
| Rotate any key | Vadym | agent cannot see consequences on both servers and Make/SendPulse |
| Change webhook URLs in Make/SendPulse | Vadym / Oleksandr | external systems bound to the legacy host |
