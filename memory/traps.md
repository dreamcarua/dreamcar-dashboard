# DreamCar Dashboard — traps

Read before the first edit of code, workflow or config. Add an entry whenever something cost more than 15 minutes of surprise, in the same commit as the fix.
Entries below were harvested from commit messages on 03.09.2026 (source: `git log`, commits of 24.08.2026 by "Claude (DreamCar AI)"); each carries the commit date. Text in Ukrainian on purpose — people read this file.

### Назви секретів у GitHub ≠ назви в `.env`
**Symptom:** новий воркфлоу читає `secrets.TELEGRAM_BOT_TOKEN` — порожньо; власника просять поставити секрет, який уже є.
**Cause:** у GitHub Secrets цього репо Telegram-секрети називаються `TG_BOT_TOKEN` і `TG_CHAT_ID` (так їх читає `meta-stats-sync.yml`); `.env.example` легасі-дашборду називає ті самі речі `TELEGRAM_BOT_TOKEN` / `TELEGRAM_CHAT_ID`. Інші наявні секрети: `FB_ACCESS_TOKEN`, `IG_USER_ID`, `SUPABASE_URL`, `SUPABASE_SERVICE_ROLE_KEY`, `GH_TEAM_NOTIFY_TOKEN`. SMM-чат заданий літералом у `smm-content-watchdog.yml` (`SMM_CHAT_ID`).
**Do:** перед тим як просити новий секрет — `grep -h 'secrets\.' .github/workflows/*.yml | sort -u`: список того, що вже є. Нові воркфлоу вживають `TG_BOT_TOKEN` / `TG_CHAT_ID`.
**Seen:** 03.09.2026 · встановлення воркфлоу звітів — помилка Claude, власник зупинив

### Один процес, два канали запуску (ETL MySQL → Supabase)
**Symptom:** ETL стартував 96 разів на добу замість 48; половина з 324 хв/міс CI йшла в нікуди.
**Cause:** `schedule:` у воркфлоу (0,30) і pg_cron job у Supabase через `etl-trigger` (15,45) запускали той самий процес. Кожен ран 21 с, білиться як повна хвилина.
**Do:** перед зміною частоти будь-якого ETL — знайти ВСІ канали: `schedule:` у `.github/workflows/`, pg_cron у Supabase, `workflow_dispatch`, вебхуки. Головний канал — pg_cron; `schedule:` лише резерв 3×/добу зі зсувом :05.
**Seen:** 24.08.2026 · commit 6ef8963

### GitHub cron ходить у 2–4 рази рідше за розклад
**Symptom:** воркфлоу з `cron: */15` реально запускається кілька разів на годину або рідше.
**Cause:** планувальник GitHub Actions придушує й пропускає запуски; вираз `cron:` — не фактична частота.
**Do:** навантаження рахувати з логу ранів (Actions → workflow → runs) або з Billing → Usage, а не з виразу `cron:`. Для критичних за часом процесів — pg_cron.
**Seen:** 24.08.2026 · commit 6ef8963

### Білінг округлює кожен job угору до хвилини
**Symptom:** воркфлоу з 10-секундними холостими ранами коштує стільки ж, скільки хвилинний; `fb-ads-sync` — 1257 хв/міс.
**Cause:** одиниця тарифікації — job-хвилина з округленням угору; вартість визначає кількість ранів, а не тривалість.
**Do:** оптимізувати кількість запусків (нічний throttle, обʼєднання), не швидкість коротких скриптів. Кожен воркфлоу — з `timeout-minutes` (без нього зависла job тримає один із 20 конкурентних слотів акаунта до 6 годин).
**Seen:** 24.08.2026 · commits 1efdb39, 438c6ab

### Дорога підготовка перед перевіркою, чи є робота (FB Ads ETL)
**Symptom:** у міжпромовий період ETL 12 разів на добу тягнув сотні оголошень заради нуля рядків, ~3 хв на ран.
**Cause:** порядок кроків: спершу повний список оголошень (пагінація по 200), потім insights.
**Do:** найдешевша перевірка («чи були покази») — першою; дорогі кроки — лише якщо є що обробляти. Правило дешевого вартового: якщо перевірка не відповіла — виконувати повний цикл, а не пропускати.
**Seen:** 24.08.2026 · commit 0ba6f63

### Назва воркфлоу описує стару поведінку
**Symptom:** «every 15min», «2 iterations × 15 min» у назвах — при cron 0,30 і без internal loop з 08.08.
**Cause:** поведінку змінили, підписи й шапки файлів — ні.
**Do:** міняти назву, шапку файлу й коментарі в тому ж коміті, що й поведінку.
**Seen:** 24.08.2026 · commits a52c726, 6ef8963

### Документ описує інший репозиторій
**Symptom:** корінний `CLAUDE.md` і `README.md` описують legacy PHP `utm-dashboard` на двох серверах; `docs/README.md` каже, що сайт у `web/`, а тека — `docs/`.
**Cause:** репо — clean copy (SECURITY.md) з іншого проєкту; документи переїхали, код — еволюціонував.
**Do:** документ, що суперечить коду, — вір коду й виправ документ у тій самій відповіді. Старий `CLAUDE.md` — у `memory/archive/`, він досі корисний для legacy PHP і вебхуків.
**Seen:** 03.09.2026 · встановлення системи памʼяті
