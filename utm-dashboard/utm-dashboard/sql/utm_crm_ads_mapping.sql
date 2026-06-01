-- ====================================
-- Таблиця відповідностей CRM-ADS міток
-- ====================================
-- ПРИЗНАЧЕННЯ: Зберігання зіставлень між мітками з CRM та рекламних систем
-- ПРИКЛАД: vadym (CRM) = dreamcar.ua uah (ADS)

CREATE TABLE IF NOT EXISTS utm_crm_ads_mapping (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Тип UTM поля
  field_type ENUM('utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content') NOT NULL,

  -- Значення міток
  crm_value VARCHAR(255) NOT NULL COMMENT 'Значення з CRM (SendPulse webhook)',
  ads_value VARCHAR(255) NOT NULL COMMENT 'Значення з ADS (Facebook/Google)',

  -- Об'єднана назва для відображення
  merged_name VARCHAR(255) NOT NULL COMMENT 'Назва для відображення в dashboard',

  -- Метадані
  notes TEXT COMMENT 'Коментарі/опис',
  created_by VARCHAR(100) COMMENT 'Хто створив mapping',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Унікальність: одна пара CRM-ADS значень для одного поля
  UNIQUE KEY uk_field_crm_ads (field_type, crm_value, ads_value),

  -- Індекси для швидкого пошуку
  INDEX idx_field_type (field_type),
  INDEX idx_crm_value (crm_value),
  INDEX idx_ads_value (ads_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Зіставлення міток CRM та ADS для об\'єднання аналітики';

-- ====================================
-- Приклади початкових даних
-- ====================================

INSERT INTO utm_crm_ads_mapping (field_type, crm_value, ads_value, merged_name, notes, created_by) VALUES
('utm_term', 'vadym', 'dreamcar.ua uah', 'vadym', 'Таргетолог Вадим - основний акаунт Facebook Ads', 'admin_vadym'),
('utm_term', 'vira', 'vira-ads', 'vira', 'Таргетолог Віра', 'admin_vadym'),
('utm_term', 'artem', 'dreamcar', 'artem', 'Таргетолог Артем - резервний акаунт', 'admin_vadym')
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;
