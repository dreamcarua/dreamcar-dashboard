-- =====================================
-- SQL Міграція: Додавання поля project
-- Файл: sql/add_project_field.sql
-- Призначення: Додати підтримку проектів для ручних витрат
-- Дата: 2025-12-11
-- =====================================

-- Додати поле project в таблицю ads_data
ALTER TABLE ads_data
ADD COLUMN project VARCHAR(100) DEFAULT NULL AFTER optimization_goal,
ADD INDEX idx_project (project);

-- Оновити існуючі записи (встановити дефолтний проект VOLVO)
UPDATE ads_data
SET project = 'VOLVO'
WHERE publisher_platform = 'manual' AND project IS NULL;

-- Перевірка результату
SELECT
    COUNT(*) as total_manual_costs,
    COUNT(CASE WHEN project IS NOT NULL THEN 1 END) as with_project,
    COUNT(CASE WHEN project IS NULL THEN 1 END) as without_project
FROM ads_data
WHERE publisher_platform = 'manual';
