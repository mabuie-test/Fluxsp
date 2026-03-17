CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(191) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(191) DEFAULT '',
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL UNIQUE,
  owner_user_id BIGINT UNSIGNED NULL,
  name VARCHAR(191) DEFAULT NULL,
  consent_accepted TINYINT(1) DEFAULT NULL,
  consent_ts DATETIME DEFAULT NULL,
  consent_text_version VARCHAR(64) DEFAULT NULL,
  last_seen DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_devices_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS telemetries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  payload_json JSON NOT NULL,
  ts DATETIME NOT NULL,
  INDEX idx_telemetry_device_ts(device_id, ts)
);

CREATE TABLE IF NOT EXISTS media_files (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  filename VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  originalname VARCHAR(255) DEFAULT NULL,
  content_type VARCHAR(191) DEFAULT 'application/octet-stream',
  checksum CHAR(64) NOT NULL,
  upload_date DATETIME NOT NULL,
  UNIQUE KEY uq_media_checksum (checksum),
  INDEX idx_media_device_date (device_id, upload_date)
);

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) DEFAULT NULL,
  currency VARCHAR(16) NOT NULL DEFAULT 'USD',
  status ENUM('pending','completed','rejected') NOT NULL DEFAULT 'pending',
  method VARCHAR(64) DEFAULT NULL,
  note TEXT,
  media_file_id BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL,
  processed_at DATETIME DEFAULT NULL,
  processed_by BIGINT UNSIGNED DEFAULT NULL,
  CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_processor FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_password_resets_user (user_id),
  UNIQUE KEY uq_password_reset_token_hash (token_hash),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
