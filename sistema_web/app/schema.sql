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

ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS imei VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS model VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS network_type VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS battery_level TINYINT NULL,
  ADD COLUMN IF NOT EXISTS carrier VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS signal_level TINYINT NULL,
  ADD COLUMN IF NOT EXISTS is_online TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS last_online_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS subscription_status ENUM('inactive','active','expired') NOT NULL DEFAULT 'inactive',
  ADD COLUMN IF NOT EXISTS subscription_until DATETIME NULL;

ALTER TABLE devices
  ADD UNIQUE KEY IF NOT EXISTS uk_devices_imei (imei);

ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS device_id VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS provider_tx_id VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS msisdn VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS currency VARCHAR(10) NOT NULL DEFAULT 'MZN';

ALTER TABLE payments
  ADD INDEX IF NOT EXISTS idx_payments_device_id (device_id);


ALTER TABLE devices
  ADD INDEX IF NOT EXISTS idx_devices_owner_last_seen (owner_user_id, last_seen),
  ADD INDEX IF NOT EXISTS idx_devices_subscription_status (subscription_status, subscription_until);

ALTER TABLE payments
  ADD INDEX IF NOT EXISTS idx_payments_status_created (status, created_at),
  ADD INDEX IF NOT EXISTS idx_payments_user_created (user_id, created_at);
