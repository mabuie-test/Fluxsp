CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(191) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(191) NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  active TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL UNIQUE,
  owner_user_id BIGINT UNSIGNED NULL,
  name VARCHAR(191) NULL,
  consent_accepted TINYINT(1) NULL,
  consent_ts DATETIME NULL,
  consent_text_version VARCHAR(50) NULL,
  last_seen DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_devices_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS telemetry (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  payload JSON NOT NULL,
  ts DATETIME NOT NULL,
  INDEX idx_telemetry_device_ts (device_id, ts)
);

CREATE TABLE IF NOT EXISTS media (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file_id CHAR(36) NOT NULL UNIQUE,
  device_id VARCHAR(191) NOT NULL,
  filename VARCHAR(255) NOT NULL,
  content_type VARCHAR(120) NOT NULL,
  checksum CHAR(64) NOT NULL UNIQUE,
  storage_path VARCHAR(255) NOT NULL,
  upload_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_media_device (device_id)
);

CREATE TABLE IF NOT EXISTS device_media_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  file_id CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_device_media_link (device_id, file_id),
  INDEX idx_device_media_links_file (file_id)
);

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'USD',
  status ENUM('pending','completed','rejected') NOT NULL DEFAULT 'pending',
  method VARCHAR(100) NULL,
  note TEXT NULL,
  media_file_id CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  processed_by BIGINT UNSIGNED NULL,
  CONSTRAINT fk_pay_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_processed_by FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
);


CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS support_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id CHAR(32) NOT NULL UNIQUE,
  device_id VARCHAR(191) NOT NULL,
  request_type ENUM('screen','ambient_audio') NOT NULL,
  requested_by_user_id BIGINT UNSIGNED NOT NULL,
  approved_by_user_id BIGINT UNSIGNED NULL,
  status ENUM('pending','approved','rejected','expired','stopped','cancelled') NOT NULL DEFAULT 'pending',
  note VARCHAR(255) NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  response_deadline_at DATETIME NULL,
  responded_at DATETIME NULL,
  session_expires_at DATETIME NULL,
  stop_requested_at DATETIME NULL,
  stopped_at DATETIME NULL,
  CONSTRAINT fk_support_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_support_approved_by FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_support_sessions_device_status (device_id, status),
  INDEX idx_support_sessions_device_requested (device_id, requested_at)
);


CREATE TABLE IF NOT EXISTS media_metadata (
  file_id CHAR(36) NOT NULL PRIMARY KEY,
  capture_mode VARCHAR(50) NULL,
  capture_kind VARCHAR(50) NULL,
  support_session_id CHAR(32) NULL,
  segment_started_at DATETIME NULL,
  segment_duration_ms INT NULL,
  metadata_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_media_metadata_session (support_session_id, created_at),
  INDEX idx_media_metadata_kind (capture_kind, created_at)
);

CREATE TABLE IF NOT EXISTS system_metrics (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NULL,
  metric_type VARCHAR(50) NOT NULL,
  metric_name VARCHAR(100) NOT NULL,
  status VARCHAR(50) NULL,
  value_ms INT NULL,
  value_num DECIMAL(12,2) NULL,
  context_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_system_metrics_device_type (device_id, metric_type, created_at),
  INDEX idx_system_metrics_name (metric_name, created_at)
);


ALTER TABLE payments ADD COLUMN phone_msisdn VARCHAR(30) NULL;
ALTER TABLE payments ADD COLUMN provider VARCHAR(50) NULL;
ALTER TABLE payments ADD COLUMN provider_reference VARCHAR(100) NULL;
ALTER TABLE payments ADD COLUMN provider_status VARCHAR(50) NULL;
ALTER TABLE payments ADD COLUMN provider_payload_json JSON NULL;
ALTER TABLE payments ADD COLUMN debito_reference VARCHAR(100) NULL;
ALTER TABLE payments ADD COLUMN status_checked_at DATETIME NULL;
ALTER TABLE payments MODIFY COLUMN currency VARCHAR(10) NOT NULL DEFAULT 'MZN';
ALTER TABLE devices ADD COLUMN imei VARCHAR(191) NULL;
ALTER TABLE devices ADD COLUMN model VARCHAR(191) NULL;
ALTER TABLE devices ADD COLUMN manufacturer VARCHAR(191) NULL;

CREATE TABLE IF NOT EXISTS device_locations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lon DECIMAL(10,7) NOT NULL,
  accuracy DECIMAL(10,2) NULL,
  observed_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_device_locations_device_time (device_id, observed_at)
);

CREATE TABLE IF NOT EXISTS device_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  source VARCHAR(50) NULL,
  sender VARCHAR(191) NULL,
  contact_name VARCHAR(191) NULL,
  app_package VARCHAR(191) NULL,
  direction VARCHAR(20) NULL,
  body TEXT NULL,
  sync_key VARCHAR(191) NULL,
  observed_at_ms BIGINT NULL,
  observed_at DATETIME NOT NULL,
  UNIQUE KEY uniq_device_messages (device_id, sender, observed_at),
  UNIQUE KEY uniq_device_messages_sync (device_id, sync_key),
  INDEX idx_device_messages_device_time (device_id, observed_at)
);

CREATE TABLE IF NOT EXISTS device_calls (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  number VARCHAR(191) NULL,
  contact_name VARCHAR(191) NULL,
  direction VARCHAR(50) NULL,
  duration_seconds INT NULL,
  sync_key VARCHAR(191) NULL,
  observed_at_ms BIGINT NULL,
  observed_at DATETIME NOT NULL,
  UNIQUE KEY uniq_device_calls (device_id, number, observed_at, duration_seconds),
  UNIQUE KEY uniq_device_calls_sync (device_id, sync_key),
  INDEX idx_device_calls_device_time (device_id, observed_at)
);

CREATE TABLE IF NOT EXISTS device_contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  contact_key VARCHAR(191) NOT NULL,
  display_name VARCHAR(191) NULL,
  phone_number VARCHAR(191) NULL,
  email VARCHAR(191) NULL,
  updated_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_device_contact (device_id, contact_key),
  INDEX idx_device_contacts_device_name (device_id, display_name)
);

ALTER TABLE device_messages ADD COLUMN source VARCHAR(50) NULL;
ALTER TABLE device_messages ADD COLUMN contact_name VARCHAR(191) NULL;
ALTER TABLE device_messages ADD COLUMN app_package VARCHAR(191) NULL;
ALTER TABLE device_messages ADD COLUMN direction VARCHAR(20) NULL;
ALTER TABLE device_messages ADD COLUMN sync_key VARCHAR(191) NULL;
ALTER TABLE device_messages ADD COLUMN observed_at_ms BIGINT NULL;
ALTER TABLE device_messages ADD UNIQUE KEY uniq_device_messages_sync (device_id, sync_key);

ALTER TABLE device_calls ADD COLUMN sync_key VARCHAR(191) NULL;
ALTER TABLE device_calls ADD COLUMN observed_at_ms BIGINT NULL;
ALTER TABLE device_calls ADD UNIQUE KEY uniq_device_calls_sync (device_id, sync_key);
