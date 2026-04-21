CREATE TABLE work_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) UNIQUE NOT NULL,
  description TEXT NULL,
  is_active TINYINT(1) DEFAULT 1,
  base_price DECIMAL(10,2) NOT NULL,
  default_complexity VARCHAR(20) DEFAULT 'medium',
  allows_full_auto_generation TINYINT(1) DEFAULT 1,
  requires_human_review TINYINT(1) DEFAULT 0,
  is_premium_type TINYINT(1) DEFAULT 0,
  display_order INT DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE work_type_structures (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_type_id BIGINT UNSIGNED NOT NULL,
  section_code VARCHAR(80) NOT NULL,
  section_title VARCHAR(190) NOT NULL,
  section_order INT NOT NULL,
  is_required TINYINT(1) DEFAULT 1,
  min_words INT NULL,
  max_words INT NULL,
  applies_to_level BIGINT UNSIGNED NULL,
  notes TEXT NULL
);

CREATE TABLE institution_work_type_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NOT NULL,
  work_type_id BIGINT UNSIGNED NOT NULL,
  custom_structure_json JSON NULL,
  custom_visual_rules_json JSON NULL,
  custom_reference_rules_json JSON NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE academic_level_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academic_level_id BIGINT UNSIGNED NOT NULL,
  rules_json JSON NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE language_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  locale VARCHAR(20) NOT NULL,
  vocabulary_rules_json JSON NULL,
  syntax_rules_json JSON NULL,
  anti_ai_patterns_json JSON NULL,
  academic_tone_level VARCHAR(40) NOT NULL,
  is_active TINYINT(1) DEFAULT 1
);

CREATE TABLE citation_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  style_code VARCHAR(20) NOT NULL,
  rules_json JSON NULL,
  is_active TINYINT(1) DEFAULT 1
);

CREATE TABLE templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NULL,
  work_type_id BIGINT UNSIGNED NULL,
  type VARCHAR(50) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  institution_id BIGINT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  discipline_id BIGINT UNSIGNED NOT NULL,
  academic_level_id BIGINT UNSIGNED NOT NULL,
  work_type_id BIGINT UNSIGNED NOT NULL,
  title_or_theme VARCHAR(255) NOT NULL,
  subtitle VARCHAR(255) NULL,
  problem_statement TEXT NULL,
  general_objective TEXT NULL,
  specific_objectives_json JSON NULL,
  hypothesis TEXT NULL,
  keywords_json JSON NULL,
  target_pages INT NOT NULL,
  complexity_level VARCHAR(20) NOT NULL,
  deadline_date DATETIME NOT NULL,
  notes TEXT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'draft',
  final_price DECIMAL(10,2) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);
