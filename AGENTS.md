# DreamCar Dashboard

Internal analytics for DreamCar: ETL from Meta/Google Ads, SendPulse CRM, MySQL (legacy PHP UTM dashboard) into Supabase HQ (`wotghlaehnvxyeacznvv`, tables `dashboard_*`); static read-only frontend on GitHub Pages (`dashboard.dreamcar.ua`, served from `docs/`); 20+ scheduled GitHub Actions. Public repository — see Rules.
Memory carrier: `github.com/dreamcarua/dreamcar-dashboard` (this repo), folder `memory/` (`docs/` is the Pages site, not memory).
Project hub: `github.com/dreamcarua/dreamcar-memory` — project-level memory (launches, marketing, strategy, team, decisions). This repo's `memory/` is about this codebase only.
Owner: Vadym (vg@abrisart.com). Tasks are closed by whoever set them; we hand over.

## Rules

- Talk to the user in Ukrainian (or the language they write in). Dates DD.MM.YYYY, time CET/CEST.
- Do on your own: code edits, workflow edits, migrations under `etl/migrations/`, commits to `main` for non-destructive changes.
- Always ask first: anything that changes CI minutes by more than ~10% (shared quota, 3000 min/month across all repos), repository visibility, ad campaigns money (`kill-all-ads`, `meta-scale`, `launch-*`, `delete-ads`), rotating any key.
- Never: commit secrets or `.env`; put hosts, IPs, chat IDs or usernames into files — this repo is PUBLIC (SECURITY.md); write "see GitHub secrets / password manager" instead.
- `docs/` is the published site. Nothing private goes there, and memory does not live there.

## Entry — before the first action that changes project state

Chat without a folder? Nothing was loaded automatically: fetch this file and `memory/` from the carrier first.

1. `memory/tasks.md` — what is open, what is handed over and waiting, where the next move is ours.
2. `memory/handoff.md` — not empty means a previous session stopped mid-task. Continue, do not restart.
3. `memory/traps.md` — before the first edit of code, workflow or config. Always.
4. `memory/tooling.md` — before using any tool, MCP, server, database or account of this project.
5. Recent commits — `dreamcar-bot` commits data twice a day; a human or another agent commit in the last hours means someone else is working here.
6. The task touches marketing, launches, participants, money or people outside this codebase → fetch `AGENTS.md`, `docs/tasks.md`, `docs/decisions.md` from the project hub too.

Say one sentence: how many tasks are open, which are on us, what you start with. If a move is ours, say that first, even if asked about something else.

A task you were just given goes into `memory/tasks.md` now, verbatim, with the author's name. Before starting it, check it is not already done in the code.

## Context loss — when you can no longer quote the original task verbatim

You detect this yourself; nobody will tell you. Your context was compacted. Before the next action re-read `memory/handoff.md` and `memory/tasks.md`. Do not trust the summary for paths, numbers or what is already done; re-read the file.

## Checkpoint — during long tasks

Automatic. The user never asks for a checkpoint and is never reminded to.

After each completed step of a multi-step task and before any long operation: rewrite `memory/handoff.md` (task verbatim, done, not done, next action, numbers with sources). Rewrite, do not append. Empty it when the task is handed over.

## Pre-flight — before an irreversible action, money, or a shared resource

Answer out loud in the reply. No answer to a line = no action.

1. WHOSE. Who else changes this? Are there commits in the last hours that are not mine?
2. SOURCE. Number · source · date. Primary source (Billing → Usage, Supabase, Meta Ads Manager) or a convenient sample (`run list --limit N`)?
3. WHOLE. The population or the first N rows? Did I ask the system for the total?
4. WORST. Which single check, if it came out differently, would cancel this? Do it first.
5. ROLLBACK. Exact command. Backup made and verified.

## Exit — automatic, before the word "done"

You run this unasked, every time a task changed project state — the user does not say "Exit" and does not remember these files exist. Also run it when the user says the task is finished, changes subject, or leaves; a task interrupted mid-way gets a Checkpoint instead.

1. What did I learn about this project? → `memory/traps.md`, `memory/tooling.md`
   About the business rather than this code (market, participants, a decision of Vadym's) → the project hub `dreamcar-memory/docs/`, via the GitHub tool, not here.
2. What did I decide and why? → `memory/decisions.md`
3. What is left open, including side findings nobody asked for? → `memory/tasks.md`
4. Can the owner see the result without effort? If not: screenshot, preview or link with the handover.
5. Report through the project channel → `memory/tooling.md` → Reporting.

Records go in the same commit as the change. Hand over now, in this reply. A line leaves `tasks.md` when its author confirms, not when the work is done.
Two people ask for opposite things: pick one, name the conflict, tell Vadym.

## Map

| File | What | Read when |
|---|---|---|
| `memory/tasks.md` | open tasks, handed-over-and-waiting | entry. Always |
| `memory/handoff.md` | mid-task state of the last session | entry; after context loss |
| `memory/traps.md` | traps of this project | before the first edit. Always |
| `memory/tooling.md` | tools, access, entry patterns, reporting channel | before using any tool |
| `memory/archive/CLAUDE.md.2026-09-03.md` | previous CLAUDE.md (legacy PHP UTM dashboard, two servers, webhooks) | when touching the legacy PHP side or webhooks |
| `github.com/dreamcarua/dreamcar-memory` (hub) | project-level memory: launches, marketing, strategy, team, decisions | when the task goes beyond this codebase |
| `SECURITY.md` | compromised-secrets history, how to add secrets | before adding any credential |
| `.env.example` | names of all secrets and identifiers | before configuring anything |

## Overrides of global rules

| Global rule | Here | Why | Since |
|---|---|---|---|
| "commit on your own to dreamcarua/*" | ad-money and CI-quota workflows need an explicit OK first | one workflow run here can spend real ad budget or the shared 3000-minute quota | 03.09.2026 |
