-- ===================================
-- UTM Dashboard Database Schema
-- MySQL 8.4
-- ===================================

-- Таблица: crm_deals (Сделки из CRM)
CREATE TABLE IF NOT EXISTS crm_deals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deal_id VARCHAR(100) UNIQUE,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(50),
  full_name VARCHAR(255),
  created_at DATETIME NOT NULL,
  deal_updated_at DATETIME,

  -- Суммы
  amount DECIMAL(12,2) DEFAULT 0,
  amount_uah DECIMAL(12,2) DEFAULT 0,
  deal_price DECIMAL(12,2) DEFAULT 0,
  deal_currency VARCHAR(10) DEFAULT 'UAH',

  -- UTM метки
  utm_source VARCHAR(100),
  utm_medium VARCHAR(100),
  utm_campaign VARCHAR(255),
  utm_term VARCHAR(255),
  utm_content VARCHAR(255),

  -- Статусы
  deal_pipeline VARCHAR(100),
  deal_type ENUM('lead', 'paid', 'failed', 'pending') DEFAULT 'lead',
  deal_status VARCHAR(50),
  is_paid BOOLEAN DEFAULT FALSE,
  is_failed BOOLEAN DEFAULT FALSE,
  is_pending BOOLEAN DEFAULT FALSE,

  -- Дополнительно
  deal_name VARCHAR(255),
  deal_step VARCHAR(100),
  model VARCHAR(100),        -- Название проекта (VOLVO, OLD и т.д.) из name_deal или deal_step
  comment TEXT,              -- Комментарии к сделке
  product VARCHAR(255),
  tickets TEXT,
  tickets_count INT DEFAULT 0,
  list_name VARCHAR(100),
  tag_list VARCHAR(255),

  -- Служебные
  imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Индексы для КАЖДОЙ UTM метки
  INDEX idx_email (email),
  INDEX idx_created_at (created_at),
  INDEX idx_deal_type (deal_type),
  INDEX idx_is_paid (is_paid),
  INDEX idx_utm_source (utm_source),
  INDEX idx_utm_medium (utm_medium),
  INDEX idx_utm_campaign (utm_campaign),
  INDEX idx_utm_term (utm_term),
  INDEX idx_utm_content (utm_content),
  INDEX idx_deal_pipeline (deal_pipeline),

  -- Составные индексы (с ограничением длины для utf8mb4)
  INDEX idx_utm_full (utm_source(50), utm_medium(50), utm_campaign(100)),
  INDEX idx_date_type (created_at, deal_type),
  INDEX idx_date_paid (created_at, is_paid),
  INDEX idx_source_date (utm_source(50), created_at),
  INDEX idx_medium_date (utm_medium(50), created_at),
  INDEX idx_campaign_date (utm_campaign(100), created_at),
  INDEX idx_term_date (utm_term(100), created_at),
  INDEX idx_content_date (utm_content(100), created_at),

  -- Полнотекстовый поиск
  FULLTEXT INDEX ft_campaign (utm_campaign, utm_content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Таблица: ads_data (Детализированные данные рекламы)
CREATE TABLE IF NOT EXISTS ads_data (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Уникальность записи (детализация)
  date_start DATE NOT NULL,
  date_stop DATE NOT NULL,
  account_id VARCHAR(100) NOT NULL,
  campaign_id VARCHAR(100) NOT NULL,
  adset_id VARCHAR(100) NOT NULL,
  ad_id VARCHAR(100) NOT NULL,
  publisher_platform VARCHAR(50) NOT NULL,
  platform_position VARCHAR(100) NOT NULL,

  -- Названия
  account_name VARCHAR(255),
  campaign_name VARCHAR(255),
  adset_name VARCHAR(255),
  ad_name VARCHAR(255),

  -- UTM метки (преобразованные)
  utm_source VARCHAR(100),
  utm_medium VARCHAR(100),
  utm_campaign VARCHAR(255),
  utm_content VARCHAR(255),
  utm_term VARCHAR(255),

  -- Метрики рекламы
  spend DECIMAL(12,2) DEFAULT 0,
  clicks INT DEFAULT 0,
  impressions INT DEFAULT 0,
  reach INT DEFAULT 0,
  unique_clicks INT DEFAULT 0,
  cpm DECIMAL(10,2) DEFAULT 0,
  ctr DECIMAL(10,4) DEFAULT 0,

  -- Дополнительно
  account_currency VARCHAR(10) DEFAULT 'UAH',
  buying_type VARCHAR(50),
  objective VARCHAR(100),
  optimization_goal VARCHAR(100),

  -- Служебные
  imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Уникальный ключ (защита от дубликатов)
  UNIQUE KEY uk_ads_unique (
    date_start, date_stop, account_id, campaign_id,
    adset_id, ad_id, publisher_platform, platform_position
  ),

  -- Индексы для КАЖДОЙ UTM метки
  INDEX idx_date_start (date_start),
  INDEX idx_utm_source (utm_source),
  INDEX idx_utm_medium (utm_medium),
  INDEX idx_utm_campaign (utm_campaign),
  INDEX idx_utm_term (utm_term),
  INDEX idx_utm_content (utm_content),
  INDEX idx_platform (publisher_platform, platform_position),

  -- Составные индексы для связки (с ограничением длины)
  INDEX idx_utm_full (utm_source(50), utm_medium(50), utm_campaign(100)),
  INDEX idx_date_source (date_start, utm_source(50)),
  INDEX idx_date_medium (date_start, utm_medium(50)),
  INDEX idx_date_campaign (date_start, utm_campaign(100)),
  INDEX idx_date_term (date_start, utm_term(100)),
  INDEX idx_date_content (date_start, utm_content(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;




-- Таблица: utm_mapping (Соответствия старых UTM меток)
CREATE TABLE IF NOT EXISTS utm_mapping (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  old_value VARCHAR(100) NOT NULL,
  new_value VARCHAR(100) NOT NULL,
  field_type ENUM('source', 'medium', 'campaign', 'term', 'content') NOT NULL,

  UNIQUE KEY uk_mapping (old_value, field_type),
  INDEX idx_old_value (old_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Таблица: import_log (Лог импортов)
CREATE TABLE IF NOT EXISTS import_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_type ENUM('crm', 'ads') NOT NULL,
  file_name VARCHAR(255),
  records_total INT DEFAULT 0,
  records_new INT DEFAULT 0,
  records_updated INT DEFAULT 0,
  records_skipped INT DEFAULT 0,
  status ENUM('success', 'failed', 'partial') NOT NULL,
  error_message TEXT,
  started_at TIMESTAMP NOT NULL,
  finished_at TIMESTAMP,

  INDEX idx_import_type (import_type),
  INDEX idx_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Таблица: webhook_log (Лог webhook запросов)
CREATE TABLE IF NOT EXISTS webhook_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  webhook_type ENUM('crm', 'ads') NOT NULL,
  event_type VARCHAR(50),                    -- 'new', 'pay', 'fail' для CRM
  raw_data MEDIUMTEXT NOT NULL,              -- Сырые JSON данные
  processed_data JSON,                       -- Обработанные данные
  deal_id VARCHAR(100),                      -- ID сделки (для CRM)
  records_count INT DEFAULT 1,               -- Количество записей в запросе
  success BOOLEAN DEFAULT FALSE,
  error_message TEXT,
  processing_time DECIMAL(10,3),             -- Время обработки в секундах
  ip_address VARCHAR(50),                    -- IP отправителя
  user_agent VARCHAR(255),                   -- User-Agent
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_webhook_type (webhook_type),
  INDEX idx_event_type (event_type),
  INDEX idx_deal_id (deal_id),
  INDEX idx_success (success),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Предзаполнение utm_mapping
INSERT INTO utm_mapping (old_value, new_value, field_type) VALUES
('fb', 'facebook', 'source'),
('ig', 'instagram', 'source')
ON DUPLICATE KEY UPDATE new_value = VALUES(new_value);
