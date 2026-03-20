-- Call Analysis application tables (prefix ca_)
-- Run once via db/install.php or mysql client.

CREATE TABLE IF NOT EXISTS ca_calls (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  original_filename VARCHAR(512) NOT NULL,
  stored_path VARCHAR(1024) NOT NULL,
  mime VARCHAR(128) DEFAULT NULL,
  size_bytes BIGINT UNSIGNED DEFAULT NULL,
  duration_seconds DECIMAL(12,3) DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status (status),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ca_transcript_segments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  call_id BIGINT UNSIGNED NOT NULL,
  segment_index INT UNSIGNED NOT NULL,
  start_sec DECIMAL(12,3) NOT NULL,
  end_sec DECIMAL(12,3) NOT NULL,
  text TEXT NOT NULL,
  UNIQUE KEY uq_call_seg (call_id, segment_index),
  KEY idx_call (call_id),
  CONSTRAINT fk_seg_call FOREIGN KEY (call_id) REFERENCES ca_calls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ca_analyses (
  call_id BIGINT UNSIGNED PRIMARY KEY,
  summary TEXT,
  purpose TEXT,
  main_topics TEXT,
  outcome TEXT,
  sentiment ENUM('positive','neutral','negative') NOT NULL DEFAULT 'neutral',
  sentiment_rationale TEXT,
  overall_score DECIMAL(4,2) DEFAULT NULL,
  agent_talk_pct DECIMAL(5,2) DEFAULT NULL,
  customer_talk_pct DECIMAL(5,2) DEFAULT NULL,
  quality_pacing DECIMAL(4,2) DEFAULT NULL,
  quality_structure DECIMAL(4,2) DEFAULT NULL,
  quality_engagement DECIMAL(4,2) DEFAULT NULL,
  quality_notes TEXT,
  keywords_json JSON NULL,
  patterns_json JSON NULL,
  conversation_shifts_json JSON NULL,
  questionnaire_coverage_json LONGTEXT NULL,
  top_discussed_json LONGTEXT NULL,
  positive_observations_json LONGTEXT NULL,
  negative_observations_json LONGTEXT NULL,
  analysis_model VARCHAR(64) DEFAULT NULL,
  processed_at DATETIME NULL,
  CONSTRAINT fk_analysis_call FOREIGN KEY (call_id) REFERENCES ca_calls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing deployments: add columns (idempotent on MariaDB 10.3.3+)
ALTER TABLE ca_analyses
  ADD COLUMN IF NOT EXISTS questionnaire_coverage_json LONGTEXT NULL,
  ADD COLUMN IF NOT EXISTS top_discussed_json LONGTEXT NULL,
  ADD COLUMN IF NOT EXISTS positive_observations_json LONGTEXT NULL,
  ADD COLUMN IF NOT EXISTS negative_observations_json LONGTEXT NULL;

-- Prefer LONGTEXT over JSON for these fields (PDO/MariaDB JSON binding can leave values unset)
ALTER TABLE ca_analyses
  MODIFY COLUMN questionnaire_coverage_json LONGTEXT NULL,
  MODIFY COLUMN top_discussed_json LONGTEXT NULL,
  MODIFY COLUMN positive_observations_json LONGTEXT NULL,
  MODIFY COLUMN negative_observations_json LONGTEXT NULL;

CREATE TABLE IF NOT EXISTS ca_agent_dimension_scores (
  call_id BIGINT UNSIGNED NOT NULL,
  dimension_key VARCHAR(64) NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  justification TEXT,
  PRIMARY KEY (call_id, dimension_key),
  CONSTRAINT fk_agent_call FOREIGN KEY (call_id) REFERENCES ca_calls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ca_action_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  call_id BIGINT UNSIGNED NOT NULL,
  item_text TEXT NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_call (call_id),
  CONSTRAINT fk_action_call FOREIGN KEY (call_id) REFERENCES ca_calls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
