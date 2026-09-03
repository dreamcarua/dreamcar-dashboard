# reports/

One JSON file per report. Commit on `main` → `.github/workflows/report-to-telegram.yml` sends it to the dashboard notifications chat. Do not edit old reports; they are the delivery log.

File name: `YYYY-MM-DD-HHMM-<slug>.json` (CET). Content, plain text only (no markdown, no `<` `>`):

```json
{
  "project": "DreamCar Dashboard",
  "date": "03.09.2026 14:20",
  "summary": "Two or three sentences: what was done and what it changes for the owner. No list of steps.",
  "breaks_if_not_done": "One line: what would have broken or stayed broken.",
  "open_tasks": 6,
  "on_us": 2,
  "waiting_for": "Vadym — confirm X",
  "link": "https://…  (preview, screenshot, PR) — optional"
}
```

Send when a task changed project state. Do not send for questions, reading, estimates.
