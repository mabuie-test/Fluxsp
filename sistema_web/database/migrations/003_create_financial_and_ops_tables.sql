CREATE TABLE order_requirements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  needs_institution_cover TINYINT(1) DEFAULT 0,
  needs_abstract TINYINT(1) DEFAULT 1,
  needs_bilingual_abstract TINYINT(1) DEFAULT 0,
  needs_methodology_review TINYINT(1) DEFAULT 0,
  needs_humanized_revision TINYINT(1) DEFAULT 0,
  needs_slides TINYINT(1) DEFAULT 0,
  needs_defense_summary TINYINT(1) DEFAULT 0,
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE order_attachments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  attachment_type VARCHAR(50) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NULL,
  created_at DATETIME NOT NULL
);

CREATE TABLE invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  invoice_number VARCHAR(80) UNIQUE NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency VARCHAR(10) NOT NULL,
  status VARCHAR(30) NOT NULL,
  issued_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(50) NOT NULL,
  method VARCHAR(50) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency VARCHAR(10) NOT NULL,
  msisdn VARCHAR(20) NOT NULL,
  status VARCHAR(40) NOT NULL,
  internal_reference VARCHAR(80) UNIQUE NOT NULL,
  external_reference VARCHAR(120) NULL,
  provider_transaction_id VARCHAR(120) NULL,
  provider_status VARCHAR(80) NULL,
  status_message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  paid_at DATETIME NULL
);

CREATE TABLE debito_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL,
  wallet_id VARCHAR(40) NOT NULL,
  debito_reference VARCHAR(120) NULL,
  request_payload_json JSON NOT NULL,
  response_payload_json JSON NOT NULL,
  last_status_payload_json JSON NULL,
  provider_response_code VARCHAR(40) NULL,
  provider_response_message VARCHAR(255) NULL,
  status VARCHAR(40) NOT NULL,
  last_checked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE payment_status_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL,
  previous_status VARCHAR(40) NULL,
  new_status VARCHAR(40) NOT NULL,
  source VARCHAR(30) NOT NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL
);

CREATE TABLE pricing_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(50) NOT NULL,
  scope_ref VARCHAR(80) NULL,
  key_name VARCHAR(120) NOT NULL,
  value_numeric DECIMAL(10,2) NULL,
  value_json JSON NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE pricing_extras (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  extra_code VARCHAR(80) UNIQUE NOT NULL,
  name VARCHAR(120) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE coupons (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) UNIQUE NOT NULL,
  discount_percent DECIMAL(5,2) NOT NULL,
  starts_at DATETIME NULL,
  expires_at DATETIME NULL,
  usage_limit INT NULL,
  used_count INT DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE user_discounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  discount_type ENUM('percent','fixed','extra_waiver') NOT NULL,
  discount_value DECIMAL(10,2) NOT NULL,
  work_type_id BIGINT UNSIGNED NULL,
  extra_code VARCHAR(80) NULL,
  usage_limit INT NULL,
  used_count INT DEFAULT 0,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by_admin_id BIGINT UNSIGNED NOT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE discount_usage_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_discount_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  amount_discounted DECIMAL(10,2) NOT NULL,
  details_json JSON NULL,
  created_at DATETIME NOT NULL
);

CREATE TABLE generated_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  doc_type VARCHAR(40) NOT NULL DEFAULT 'docx',
  version INT DEFAULT 1,
  is_final TINYINT(1) DEFAULT 0,
  created_at DATETIME NOT NULL
);

CREATE TABLE revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  reviewer_id BIGINT UNSIGNED NULL,
  status VARCHAR(40) NOT NULL,
  comment TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE human_review_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  reviewer_id BIGINT UNSIGNED NULL,
  status VARCHAR(40) NOT NULL,
  decision_notes TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(60) NOT NULL,
  title VARCHAR(120) NOT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) DEFAULT 0,
  created_at DATETIME NOT NULL
);

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  old_values_json JSON NULL,
  new_values_json JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL
);

CREATE TABLE ai_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  stage VARCHAR(60) NOT NULL,
  status VARCHAR(40) NOT NULL,
  payload_json JSON NULL,
  result_json JSON NULL,
  error_message TEXT NULL,
  attempts INT DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);
