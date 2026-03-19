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
  response_deadline_at DATETIME NOT NULL,
  responded_at DATETIME NULL,
  session_expires_at DATETIME NULL,
  stop_requested_at DATETIME NULL,
  stopped_at DATETIME NULL,
  CONSTRAINT fk_support_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_support_approved_by FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_support_sessions_device_status (device_id, status),
  INDEX idx_support_sessions_device_requested (device_id, requested_at)
);

CREATE TABLE IF NOT EXISTS support_session_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id CHAR(32) NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_support_event_session FOREIGN KEY (session_id) REFERENCES support_sessions(session_id) ON DELETE CASCADE,
  CONSTRAINT fk_support_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_support_session_events_session (session_id, created_at)
);
