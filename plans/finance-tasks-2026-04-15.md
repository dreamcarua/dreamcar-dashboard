# План: Доработки финансового модуля (апрель 2026)

**Дата:** 2026-04-15

## Задачи клиента

1. Система управления пользователями с правами по разделам
2. Платный vs органический трафик в главном дашборде
3. Группировка рекламных расходов в журнале транзакций
4. Авто-расходы 2% + 10% при каждом входящем платеже (+ ретро)
5. Переименование "Виробництво" → "Подарунки"
6. Раздел "Витрати" показывает ВСЕ расходы (не только рекламные)
7. Исправить расхождение рекламных расходов (finance_transactions вместо ads_data)

## Ключевые факты из кода

- `finance/core/models/FinanceTransaction.php` строка 48: label = "🚗 Виробництво"
- `finance/assets/js/finance-expenses.js` строка 33: label = "🚗 Виробництво" в GROUP_OPTIONS
- `finance/api/handler.php` строка 356: `Analytics::getBySource($filters)` — читает из ads_data
- `finance/api/handler.php` строка 461: `FROM ads_data` для графика по дням
- `config/users.json`: 5 пользователей, НЕТ поля permissions
- `finance/core/FinanceAuth.php`: роли admin/finance_manager/manager — нет гранулярных прав
- Последняя миграция: finance/migrations/005_add_ads_data_id.php → следующая 006
- Таблица finance_transactions: нет поля parent_transaction_id

## ЧЕК-ЛИСТ РЕАЛИЗАЦИИ

### Этап 1: Переименование (Задача 5)

- [ ] Шаг 1.1: FinanceTransaction.php строка 48
  - Файл: finance/core/models/FinanceTransaction.php
  - Изменить: label "🚗 Виробництво" -> "🎁 Подарунки"
  - Ключ production НЕ менять
  - Проверка: curl POST finance/api/handler.php action=dashboard.summary | grep Подарунки

- [ ] Шаг 1.2: finance-expenses.js строка 33
  - Файл: finance/assets/js/finance-expenses.js
  - Изменить: label "🚗 Виробництво" -> "🎁 Подарунки" в GROUP_OPTIONS
  - Проверка: визуально UI дропдаун

### Этап 2: Источник данных в Витрати (Задачи 6 + 7)

- [ ] Шаг 2.1: Заменить by_day с ads_data на finance_transactions
  - Файл: finance/api/handler.php, строки ~453-470 (блок byDay)
  - БЫЛО: SELECT date_start, SUM(spend) FROM ads_data
  - СТАЛО: SELECT transaction_date AS d, COALESCE(SUM(amount_uah),0) AS s
           FROM finance_transactions
           WHERE type = "expense" AND deleted_at IS NULL
           [AND project_id = :pid] [AND date фильтры]
           GROUP BY transaction_date ORDER BY transaction_date ASC
  - Проверка: curl POST expenses.report → by_day суммы из finance_transactions

- [ ] Шаг 2.2: Заменить by_source с Analytics::getBySource на finance_transactions
  - Файл: finance/api/handler.php, строки ~355-414 (весь блок categoryGroup)
  - Для ЛЮБОГО categoryGroup (включая пустой и advertising) — брать из finance_transactions
  - SQL: SELECT category, SUM(amount_uah) AS spend FROM finance_transactions
         WHERE type = "expense" AND deleted_at IS NULL [+ фильтры]
         GROUP BY category ORDER BY spend DESC
  - Формат вывода: [{utm_source: label, source: label, spend: float, paid_amount:0, leads:0, paid_count:0}]
  - labels брать из FinanceTransaction::getExpenseCategoriesGrouped() -> items
  - Проверка: curl POST expenses.report category_group= → все категории из БД

### Этап 3: Авто-расходы 2% + 10% (Задача 4)

- [ ] Шаг 3.1: Миграция 006 — parent_transaction_id
  - Создать: finance/migrations/006_add_parent_transaction_id.php
  - ALTER TABLE finance_transactions ADD COLUMN parent_transaction_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER source_type
  - ADD INDEX idx_parent_tx (parent_transaction_id)
  - Идемпотентно: SHOW COLUMNS LIKE "parent_transaction_id" BEFORE ALTER
  - Проверка: curl migration URL -> JSON success

- [ ] Шаг 3.2: createAutoExpenses() в FinanceTransaction.php
  - Файл: finance/core/models/FinanceTransaction.php — добавить метод после add()
  - Сигнатура: public static function createAutoExpenses(int $parentId, float $amount, int $projectId, string $date, ?string $createdBy = null): array
  - Создает 2 expense:
    1. category=bank_fees, amount=round($amount*0.02,2), description="Комiсiя еквайрингу 2% вiд #{parentId}"
    2. category=taxes, amount=round($amount*0.10,2), description="Податки та бух. витрати 10% вiд #{parentId}"
  - Оба: source_type=crm_auto, parent_transaction_id=$parentId
  - Returns: ["fee_id" => int, "tax_id" => int]
  - Зависимости: Шаг 3.1

- [ ] Шаг 3.3: Вызов createAutoExpenses() при transactions.add
  - Файл: finance/api/handler.php, case transactions.add, после строки 243
  - Добавить: если type IN (income, income_extra) -> createAutoExpenses()
  - Зависимости: Шаги 3.1 + 3.2

- [ ] Шаг 3.4: Каскадное удаление авто-расходов в softDelete()
  - Файл: finance/core/models/FinanceTransaction.php, метод softDelete()
  - После основного soft-delete: если type = income/income_extra -> soft delete WHERE parent_transaction_id = id
  - Зависимости: Шаги 3.1 + 3.2

- [ ] Шаг 3.5: Ретроспективная миграция 007
  - Создать: finance/migrations/007_retroactive_auto_expenses.php
  - require_once FinanceTransaction.php
  - SELECT income транзакции WHERE deleted_at IS NULL
  - Для каждой: если нет parent (COUNT WHERE parent_transaction_id=id = 0) -> createAutoExpenses()
  - Батчами по 100, счетчики created/skipped в JSON
  - Зависимости: Шаги 3.1 + 3.2

### Этап 4: Фильтр в журнале транзакций (Задача 3)

- [ ] Шаг 4.1: category_group фильтр в transactions.list
  - Файл: finance/api/handler.php, case transactions.list (~строка 222)
  - Принять POST параметр category_group
  - Если указан: WHERE category IN (items из соответствующей группы EXPENSE_CATEGORIES_GROUPED)
  - Для значения "other_all" (все НЕ рекламные): WHERE category NOT IN (advertising items)
  - Проверка: curl transactions.list category_group=advertising

- [ ] Шаг 4.2: UI переключатель фильтра
  - Файл: finance/assets/js/finance-projects.js, строка ~317 — ZK.Api('transactions.list', params, fn)
  - Grep подтвержден (Запуск #2): именно здесь вызывается transactions.list
  - Добавить кнопки "Всi витрати / Рекламнi / Iнше" над таблицей транзакций в finance-projects.js
  - localStorage ключ: finance_tx_group_filter
  - При клике — перезагружать транзакции с нужным category_group
  - Зависимости: Шаг 4.1

### Этап 5: Платный/Органический трафик (Задача 2)

- [ ] Шаг 5.1: paid_sources в config/dashboard_settings.json
  - Добавить: "paid_sources": ["instagram","facebook","audience_network","threads","messenger"]

- [ ] Шаг 5.2: traffic_type в Analytics::getBySource()
  - Файл: core/models/Analytics.php
  - filters["traffic_type"] = "paid" | "organic" | ""
  - paid: WHERE utm_source IN (paid_sources)
  - organic: WHERE utm_source NOT IN (paid_sources)
  - Загружать paid_sources из dashboard_settings.json

- [ ] Шаг 5.3: traffic_type в api/handler.php главного дашборда
  - Файл: api/handler.php (ГЛАВНЫЙ), cases get_data + get_stats
  - Принять $_POST["traffic_type"], передать в Analytics

- [ ] Шаг 5.4: Переключатель в главном UI
  - Файл: index.php — HTML кнопки "Весь / Платний / Органiчний"
  - Файл: assets/js/app.js — логика переключения, localStorage utm_traffic_type

### Этап 6: Система управления пользователями (Задача 1)

- [ ] Шаг 6.1: Добавить permissions в users.json
  - admin_vadym: все true
  - остальные: main_dashboard:true, остальные false

- [ ] Шаг 6.2: hasPermission() в core/Auth.php
  - public static function hasPermission(string $section): bool
  - Читает $_SESSION["user"]["permissions"][$section] ?? false

- [ ] Шаг 6.3: Проверка при входе в финансы
  - finance/index.php: если !Auth::hasPermission("finance") → redirect

- [ ] Шаг 6.4: Скрыть вкладки без прав
  - finance/index.php: PHP условия для salary, cards, usdt вкладок

- [ ] Шаг 6.5: Вкладка Налаштування
  - finance/index.php: добавить вкладку (только finance_role=admin)

- [ ] Шаг 6.6: API users.list, users.save, users.delete
  - finance/api/handler.php: новые cases
  - Все требуют FinanceAuth::requireAdmin()
  - users.save: password_hash() для новых паролей, валидация уникальности username

- [ ] Шаг 6.7: finance/assets/js/finance-settings.js
  - Создать: ZK.Settings модуль
  - Таблица пользователей + модальная форма + checkboxes прав
  - Подключить в finance/index.php с timestamp ?v=<?php echo time(); ?>

## ЧЕК-ЛИСТ ПРОВЕРКИ

### Функциональные сценарии:
- [ ] [1.1+1.2] Фiнанси → Витрати → дропдаун Группа → "🎁 Подарунки"
- [ ] [2.1] Фiнанси → Витрати → линейный график — суммы из finance_transactions
- [ ] [2.2] Фiнанси → Витрати → таблица каналов совпадает с ручными записями журнала
- [ ] [3.3] Создать income 1000 грн → появились expense 20 грн + 100 грн
- [ ] [3.4] Удалить income → авто-расходы тоже удалены
- [ ] [3.5] Ретро-миграция → у старых income есть авто-расходы, повторный запуск не дублирует
- [ ] [4.2] Журнал транзакций: фильтр-кнопки работают, localStorage сохраняет
- [ ] [5.4] Главный дашборд: переключатель платный/органический влияет на ROAS
- [ ] [6.5+6.7] Налаштування: список пользователей, создание/редактирование/удаление

### Серверные проверки (sleep 7 перед curl!):
```bash
BASE="https://ticket.ai-platform.space/dashboard/utm-dashboard"

# Задача 5
sleep 7 && curl -s "$BASE/finance/api/handler.php" -d "action=dashboard.summary" | grep -c Подарунки

# Миграции задача 4
sleep 7 && curl -s "$BASE/finance/migrations/006_add_parent_transaction_id.php"
sleep 7 && curl -s "$BASE/finance/migrations/007_retroactive_auto_expenses.php"

# Задача 7 — by_day из finance_transactions
sleep 7 && curl -s "$BASE/finance/api/handler.php" -d "action=expenses.report" | python3 -m json.tool | grep -A5 by_day
```

### Регрессионные проверки:
- [ ] transactions.list без category_group — работает
- [ ] transactions.add_expense (expense тип) — авто-расходы НЕ создаются
- [ ] dashboard.summary — bank_fees и taxes в группе operations
- [ ] Главный UTM дашборд без traffic_type — работает как раньше
- [ ] Авторизация существующих пользователей — не сломана

## АУДИТ ПЛАНА

### Запуск #1 — 2026-04-15

| Критерий | Баллы | Макс | Комментарий |
|----------|-------|------|-------------|
| Полнота | 13/15 | 15 | Все 7 задач покрыты. JS файл finance-expenses.js найден. Для шага 4.2 нужен Grep перед реализацией. |
| Логика и последовательность | 14/15 | 15 | Правильный порядок везде. Каскадное удаление учтено. |
| Тех. корректность | 13/15 | 15 | PHP 8.4+, PDO, SPA — ОК. Не прописан timestamp для finance-settings.js при подключении. |
| Edge cases и риски | 11/15 | 15 | Каскадное удаление авто-расходов добавлено. Защита от дублей в ретро-миграции. Race condition при двойном income не описан. |
| Верификация через код | 12/15 | 15 | Ключевые файлы прочитаны. Analytics::getBySource grep выполнен. Не проверен JS файл для transactions.list (шаг 4.2). |
| Соответствие CLAUDE.MD | 9/10 | 10 | Timestamp для finance-settings.js не прописан явно. Остальное ОК. |
| Чеклисты | 13/15 | 15 | Самодостаточные, sleep 7 добавлен, SQL прописан. |
| **ИТОГО** | **85/100** | **100** | |

---

### Запуск #2 — 2026-04-15

**Исправленные проблемы с Запуска #1:**
- Grep "transactions.list" выполнен: `finance/assets/js/finance-projects.js` строка 317 — `ZK.Api('transactions.list', params, function(data) {` — Шаг 4.2 обновлен с точным файлом
- finance/index.php уже содержит `?v=<?php echo time(); ?>` для finance-settings.js (строка 223) — ОК
- core/Auth.php прочитан: нет hasPermission() — подтверждено, нужно добавить
- finance/index.php прочитан: tabs без условий по правам — подтверждено

**Проверки кода:**
- finance-projects.js строка 317: transactions.list вызов найден — Шаг 4.2 точно определен
- finance/index.php строка 223: `finance-settings.js?v=<?php echo time(); ?>` уже есть — не нужно добавлять
- core/Auth.php: нет hasPermission(), есть getUser(), getRole(), checkAccess() — план корректен

| Критерий | Баллы | Макс | Комментарий |
|----------|-------|------|-------------|
| Полнота | 15/15 | 15 | Шаг 4.2 уточнен: finance-projects.js строка ~317. ВСЕ 7 задач покрыты. |
| Логика и последовательность | 14/15 | 15 | Порядок корректен. Каскадное удаление. Race condition при параллельных income не описан (низкий риск). |
| Тех. корректность | 14/15 | 15 | PHP 8.4, PDO, SPA — ОК. finance-settings.js timestamp уже есть. |
| Edge cases и риски | 11/15 | 15 | Защита дублей в ретро-миграции есть. Race condition двойного income остается как замечание. |
| Верификация через код | 14/15 | 15 | Все ключевые файлы прочитаны. transactions.list JS файл подтвержден Grep. |
| Соответствие CLAUDE.MD | 10/10 | 10 | Все требования соблюдены. Timestamp проверен. |
| Чеклисты | 14/15 | 15 | Самодостаточные, SQL прописан, curl-проверки есть. |
| **ИТОГО** | **92/100** | **100** | |

### Найденные проблемы (Запуск #2):

**ВАЖНЫЕ:** нет

**ЗАМЕЧАНИЯ:**
1. users.save: валидировать уникальность username + пароль >= 8 символов.
2. Ретро-миграция 007: батчевая обработка по 100 записей (если income транзакций много).
3. После Этапа 3: группа "operations" в dashboard.summary вырастет на все авто-расходы — предупредить клиента.
4. Race condition при двойном income (два одновременных запроса) — низкий риск, не критично.

### Сравнение: Запуск #1: 85/100 → Запуск #2: 92/100 (+7 баллов)
Исправлено: JS файл для шага 4.2 точно определен (finance-projects.js:317), timestamp для settings.js проверен.

### Статус: ✅ ПЛАН ГОТОВ К РЕАЛИЗАЦИИ (92/100). Все задачи покрыты, файлы верифицированы.
