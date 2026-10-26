-- Safe PAWS complete MySQL/MariaDB installer
-- Creates the schema used by the existing PHP and Python application.
-- Safe to rerun against a database created by this script: no tables are dropped,
-- and sample roles, accounts, pets, and images are inserted only once.
-- Existing data is retained; the rescue-intake and notification migrations below add only
-- missing columns and constraints. The default placeholder
-- gallery shelter is updated once.
-- Back up and migrate an older installation separately before using this installer.

CREATE DATABASE IF NOT EXISTS safe_paws
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE safe_paws;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NULL,
  password VARCHAR(255) NULL,
  role ENUM('user','volunteer','shelter','admin') NULL DEFAULT 'user',
  phone VARCHAR(30) NULL,
  address VARCHAR(255) NULL,
  status ENUM('active','inactive','pending_admin_approval') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at TIMESTAMP NULL DEFAULT NULL,
  seed_key VARCHAR(64) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_seed_key (seed_key),
  KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  role_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_name VARCHAR(50) NOT NULL,
  PRIMARY KEY (role_id),
  UNIQUE KEY uq_roles_name (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  assigned_by INT UNSIGNED NULL,
  PRIMARY KEY (user_id, role_id),
  KEY idx_user_roles_role (role_id),
  KEY idx_user_roles_assigned_by (assigned_by),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE RESTRICT,
  CONSTRAINT fk_user_roles_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NULL,
  purpose TEXT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_admin_requests_status_requested (status, requested_at),
  KEY idx_admin_requests_user (user_id),
  KEY idx_admin_requests_reviewer (reviewed_by),
  CONSTRAINT fk_admin_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_admin_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shelters (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  address VARCHAR(255) NULL,
  city VARCHAR(100) NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  image VARCHAR(255) NULL,
  operating_hours VARCHAR(255) NULL,
  status ENUM('pending','approved','suspended') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  seed_key VARCHAR(64) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shelters_user (user_id),
  UNIQUE KEY uq_shelters_seed_key (seed_key),
  KEY idx_shelters_status_city (status, city),
  CONSTRAINT fk_shelters_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  shelter_id INT UNSIGNED NOT NULL,
  rescue_report_id INT UNSIGNED NULL,
  name VARCHAR(100) NOT NULL,
  species ENUM('dog','cat','rabbit','other') NOT NULL DEFAULT 'dog',
  breed VARCHAR(100) NULL,
  sex ENUM('male','female','unknown') NOT NULL DEFAULT 'unknown',
  age VARCHAR(50) NULL,
  color VARCHAR(80) NULL,
  description TEXT NULL,
  image VARCHAR(255) NULL,
  health_status VARCHAR(255) NULL,
  vaccination_status VARCHAR(100) NULL,
  status ENUM('available','pending','adopted','unavailable') NOT NULL DEFAULT 'available',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  seed_key VARCHAR(64) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pets_seed_key (seed_key),
  UNIQUE KEY uq_pets_rescue_report (rescue_report_id),
  KEY idx_pets_status_species_created (status, species, created_at),
  KEY idx_pets_shelter_created (shelter_id, created_at),
  CONSTRAINT fk_pets_shelter FOREIGN KEY (shelter_id) REFERENCES shelters(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pet_images (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pet_id INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  alt_text VARCHAR(255) NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pet_images_pet_primary (pet_id, is_primary, sort_order),
  CONSTRAINT fk_pet_images_pet FOREIGN KEY (pet_id) REFERENCES pets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adoption_applications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pet_id INT UNSIGNED NOT NULL,
  applicant_id INT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  phone VARCHAR(30) NULL,
  address VARCHAR(255) NULL,
  household_type VARCHAR(80) NULL,
  experience TEXT NULL,
  other_pets TEXT NULL,
  preferred_contact ENUM('email','phone','either') NOT NULL DEFAULT 'either',
  status ENUM('submitted','under_review','approved','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
  shelter_notes TEXT NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  reference_number VARCHAR(32) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_application_pet_adopter (pet_id, applicant_id),
  UNIQUE KEY uq_application_reference (reference_number),
  KEY idx_applications_status_created (status, created_at),
  KEY idx_applications_applicant_created (applicant_id, created_at),
  KEY idx_applications_reviewer (reviewed_by),
  CONSTRAINT fk_applications_pet FOREIGN KEY (pet_id) REFERENCES pets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_applications_applicant FOREIGN KEY (applicant_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_applications_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id INT UNSIGNED NOT NULL,
  previous_status VARCHAR(32) NULL,
  new_status VARCHAR(32) NOT NULL,
  actor_id INT UNSIGNED NULL,
  notes TEXT NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_application_history_application (application_id, changed_at),
  KEY idx_application_history_actor (actor_id),
  CONSTRAINT fk_application_history_application FOREIGN KEY (application_id) REFERENCES adoption_applications(id) ON DELETE RESTRICT,
  CONSTRAINT fk_application_history_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stray_reports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_id INT UNSIGNED NULL,
  reporter_name VARCHAR(120) NOT NULL,
  reporter_email VARCHAR(190) NOT NULL,
  phone VARCHAR(30) NULL,
  animal_type VARCHAR(80) NULL,
  urgency ENUM('low','normal','high','emergency') NOT NULL DEFAULT 'normal',
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  location VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  image VARCHAR(255) NULL,
  staff_notes TEXT NULL,
  status ENUM('new','reviewed','assigned','in_progress','rescued','resolved','rejected','closed') NOT NULL DEFAULT 'new',
  reviewed_by INT UNSIGNED NULL,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_stray_reports_status_created (status, created_at),
  KEY idx_stray_reports_reporter (reporter_id),
  KEY idx_stray_reports_reviewer (reviewed_by),
  CONSTRAINT fk_stray_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_stray_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add the unique report-to-pet link to existing installations without
-- replacing any pet data. A single report can therefore create only one intake.
SET @safe_paws_has_rescue_report_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pets'
    AND COLUMN_NAME = 'rescue_report_id'
);
SET @safe_paws_rescue_report_column_migration = IF(
  @safe_paws_has_rescue_report_column = 0,
  'ALTER TABLE pets ADD COLUMN rescue_report_id INT UNSIGNED NULL AFTER shelter_id',
  'SELECT 1'
);
PREPARE safe_paws_rescue_report_column_statement FROM @safe_paws_rescue_report_column_migration;
EXECUTE safe_paws_rescue_report_column_statement;
DEALLOCATE PREPARE safe_paws_rescue_report_column_statement;

SET @safe_paws_has_rescue_report_index = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pets'
    AND INDEX_NAME = 'uq_pets_rescue_report'
);
SET @safe_paws_rescue_report_index_migration = IF(
  @safe_paws_has_rescue_report_index = 0,
  'ALTER TABLE pets ADD UNIQUE KEY uq_pets_rescue_report (rescue_report_id)',
  'SELECT 1'
);
PREPARE safe_paws_rescue_report_index_statement FROM @safe_paws_rescue_report_index_migration;
EXECUTE safe_paws_rescue_report_index_statement;
DEALLOCATE PREPARE safe_paws_rescue_report_index_statement;

SET @safe_paws_has_rescue_report_foreign_key = (
  SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pets'
    AND COLUMN_NAME = 'rescue_report_id'
    AND REFERENCED_TABLE_NAME = 'stray_reports'
    AND REFERENCED_COLUMN_NAME = 'id'
);
SET @safe_paws_rescue_report_foreign_key_migration = IF(
  @safe_paws_has_rescue_report_foreign_key = 0,
  'ALTER TABLE pets ADD CONSTRAINT fk_pets_rescue_report FOREIGN KEY (rescue_report_id) REFERENCES stray_reports(id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE safe_paws_rescue_report_foreign_key_statement FROM @safe_paws_rescue_report_foreign_key_migration;
EXECUTE safe_paws_rescue_report_foreign_key_statement;
DEALLOCATE PREPARE safe_paws_rescue_report_foreign_key_statement;

CREATE TABLE IF NOT EXISTS report_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id INT UNSIGNED NOT NULL,
  previous_status VARCHAR(32) NULL,
  new_status VARCHAR(32) NOT NULL,
  actor_id INT UNSIGNED NULL,
  notes TEXT NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_report_history_report (report_id, changed_at),
  KEY idx_report_history_actor (actor_id),
  CONSTRAINT fk_report_history_report FOREIGN KEY (report_id) REFERENCES stray_reports(id) ON DELETE RESTRICT,
  CONSTRAINT fk_report_history_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rescue_assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id INT UNSIGNED NOT NULL,
  volunteer_id INT UNSIGNED NOT NULL,
  shelter_id INT UNSIGNED NULL,
  notes TEXT NULL,
  status ENUM('assigned','in_progress','completed','cancelled') NOT NULL DEFAULT 'assigned',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rescue_assignment_report (report_id),
  KEY idx_rescue_assignments_volunteer_status (volunteer_id, status),
  KEY idx_rescue_assignments_shelter (shelter_id),
  CONSTRAINT fk_rescue_assignments_report FOREIGN KEY (report_id) REFERENCES stray_reports(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rescue_assignments_volunteer FOREIGN KEY (volunteer_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rescue_assignments_shelter FOREIGN KEY (shelter_id) REFERENCES shelters(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS volunteers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  skills TEXT NULL,
  availability TEXT NULL,
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE KEY uq_volunteers_user (user_id),
  CONSTRAINT fk_volunteers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_records (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  request_type ENUM('general','volunteer','shelter','pet') NOT NULL,
  description TEXT NOT NULL,
  status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  staff_notes TEXT NULL,
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_support_status_submitted (status, submitted_at),
  CONSTRAINT fk_support_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registration_attempts (
  attempt_key CHAR(64) NOT NULL,
  attempt_count INT NOT NULL DEFAULT 1,
  last_attempt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  blocked_until TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (attempt_key),
  KEY idx_registration_blocked (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  attempt_key CHAR(64) NOT NULL,
  attempt_count INT NOT NULL DEFAULT 1,
  last_attempt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  blocked_until TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (attempt_key),
  KEY idx_login_blocked (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  link VARCHAR(255) NULL,
  related_type VARCHAR(50) NULL,
  related_id INT UNSIGNED NULL,
  event_key VARCHAR(190) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user_read_created (user_id, is_read, created_at),
  KEY idx_notifications_related (related_type, related_id),
  UNIQUE KEY uq_notifications_user_event (user_id, event_key),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add notification metadata to existing installations without replacing notifications.
SET @safe_paws_notification_related_type_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='related_type'
);
SET @safe_paws_notification_related_type_statement = IF(
  @safe_paws_notification_related_type_exists=0,
  'ALTER TABLE notifications ADD COLUMN related_type VARCHAR(50) NULL AFTER link',
  'SELECT 1'
);
PREPARE safe_paws_notification_related_type_migration FROM @safe_paws_notification_related_type_statement;
EXECUTE safe_paws_notification_related_type_migration;
DEALLOCATE PREPARE safe_paws_notification_related_type_migration;

SET @safe_paws_notification_related_id_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='related_id'
);
SET @safe_paws_notification_related_id_statement = IF(
  @safe_paws_notification_related_id_exists=0,
  'ALTER TABLE notifications ADD COLUMN related_id INT UNSIGNED NULL AFTER related_type',
  'SELECT 1'
);
PREPARE safe_paws_notification_related_id_migration FROM @safe_paws_notification_related_id_statement;
EXECUTE safe_paws_notification_related_id_migration;
DEALLOCATE PREPARE safe_paws_notification_related_id_migration;

SET @safe_paws_notification_event_key_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='event_key'
);
SET @safe_paws_notification_event_key_statement = IF(
  @safe_paws_notification_event_key_exists=0,
  'ALTER TABLE notifications ADD COLUMN event_key VARCHAR(190) NULL AFTER related_id',
  'SELECT 1'
);
PREPARE safe_paws_notification_event_key_migration FROM @safe_paws_notification_event_key_statement;
EXECUTE safe_paws_notification_event_key_migration;
DEALLOCATE PREPARE safe_paws_notification_event_key_migration;

SET @safe_paws_notification_related_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND INDEX_NAME='idx_notifications_related'
);
SET @safe_paws_notification_related_index_statement = IF(
  @safe_paws_notification_related_index_exists=0,
  'ALTER TABLE notifications ADD KEY idx_notifications_related (related_type, related_id)',
  'SELECT 1'
);
PREPARE safe_paws_notification_related_index_migration FROM @safe_paws_notification_related_index_statement;
EXECUTE safe_paws_notification_related_index_migration;
DEALLOCATE PREPARE safe_paws_notification_related_index_migration;

SET @safe_paws_notification_event_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND INDEX_NAME='uq_notifications_user_event'
);
SET @safe_paws_notification_event_index_statement = IF(
  @safe_paws_notification_event_index_exists=0,
  'ALTER TABLE notifications ADD UNIQUE KEY uq_notifications_user_event (user_id, event_key)',
  'SELECT 1'
);
PREPARE safe_paws_notification_event_index_migration FROM @safe_paws_notification_event_index_statement;
EXECUTE safe_paws_notification_event_index_migration;
DEALLOCATE PREPARE safe_paws_notification_event_index_migration;

CREATE TABLE IF NOT EXISTS activity_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id INT UNSIGNED NULL,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activity_logs_user_created (user_id, created_at),
  KEY idx_activity_logs_entity (entity_type, entity_id),
  CONSTRAINT fk_activity_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adoption_api_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_data JSON NULL,
  response_data JSON NULL,
  status_code INT NOT NULL,
  api_endpoint VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_adoption_api_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stray_api_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_data JSON NULL,
  response_data JSON NULL,
  status_code INT NOT NULL,
  api_endpoint VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_stray_api_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- activity_logs is the application's existing audit trail.
CREATE OR REPLACE VIEW audit_logs AS
  SELECT id AS audit_id, user_id AS actor_id, action, entity_type, entity_id, details, created_at
  FROM activity_logs;

INSERT INTO roles (role_name)
SELECT 'administrator' WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'administrator');
INSERT INTO roles (role_name)
SELECT 'shelter_manager' WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'shelter_manager');
INSERT INTO roles (role_name)
SELECT 'volunteer' WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'volunteer');
INSERT INTO roles (role_name)
SELECT 'adopter' WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'adopter');
INSERT INTO roles (role_name)
SELECT 'pending_administrator_request'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'pending_administrator_request');

-- Local-development-only initial password for all six accounts: "password".
-- Passwords are stored only as PHP password_hash() values.
INSERT INTO users (name, email, password, role, status, seed_key)
SELECT 'Allan Arcilla Jr.', 'allan@safepaws.local', '$2y$10$XVQZ18Ja2Qj945IUEuMWJ.Q4IWQwd5apoKwuBB2qSxTSdrjy7wh4G', 'admin', 'active', 'initial-person-allan-arcilla-jr'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE seed_key = 'initial-person-allan-arcilla-jr');
UPDATE users
SET name = IF(password IS NULL, 'Allan Arcilla Jr.', name),
    email = IF(password IS NULL, 'allan@safepaws.local', email),
    role = IF(password IS NULL, 'admin', role),
    status = IF(password IS NULL, 'active', status),
    password = IF(password IS NULL, '$2y$10$XVQZ18Ja2Qj945IUEuMWJ.Q4IWQwd5apoKwuBB2qSxTSdrjy7wh4G', password)
WHERE seed_key = 'initial-person-allan-arcilla-jr';

INSERT INTO users (name, email, password, role, status, seed_key)
SELECT 'Terrence James Pagulon', 'terrence@safepaws.local', '$2y$10$XVQZ18Ja2Qj945IUEuMWJ.Q4IWQwd5apoKwuBB2qSxTSdrjy7wh4G', 'user', 'active', 'initial-person-terrence-james-pagulon'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE seed_key = 'initial-person-terrence-james-pagulon');
UPDATE users
SET name = IF(password IS NULL, 'Terrence James Pagulon', name),
    email = IF(password IS NULL, 'terrence@safepaws.local', email),
    role = IF(password IS NULL, 'user', role),
    status = IF(password IS NULL, 'active', status),
    password = IF(password IS NULL, '$2y$10$XVQZ18Ja2Qj945IUEuMWJ.Q4IWQwd5apoKwuBB2qSxTSdrjy7wh4G', password)
WHERE seed_key = 'initial-person-terrence-james-pagulon';

INSERT INTO users (name, email, password, role, status, seed_key)
SELECT 'Irish Alaon', 'irish@safepaws.local', '$2y$10$XVQZ18Ja2Qj945IUEuMWJ.Q4IWQwd5apoKwuBB2qSxTSdrjy7wh4G', 'user', 'active', 'initial-person-irish-alaon'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE seed_key = 'initial-person-irish-alaon');
UPDATE users
SET name = IF(password IS NULL, 'Irish Alaon', name),
    email = IF(password IS NULL, 'irish@safepaws.local', email),
    role = IF(password IS NULL, 'user', role),
    status = IF(password IS NULL, 'active', status),
    password = IF(password IS NULL, '$2y$10$XVQZ18Ja2Qj945IUEuMWJ.Q4IWQwd5apoKwuBB2qSxTSdrjy7wh4G', password)
WHERE seed_key = 'initial-person-irish-alaon';

INSERT INTO users (name, email, password, role, status, seed_key)
SELECT 'Raiza Monte', 'raiza@safepaws.local', '$2y$10$XVQZ18Ja2Qj945IUEuMWJ.Q4IWQwd5apoKwuBB2qSxTSdrjy7wh4G', 'user', 'active', 'initial-person-raiza-monte'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE seed_key = 'initial-person-raiza-monte');
UPDATE users
SET name = IF(password IS NULL, 'Raiza Monte', name),
    email = IF(password IS NULL, 'raiza@safepaws.local', email),
    role = IF(password IS NULL, 'user', role),
    status = IF(password IS NULL, 'active', status),
    password = IF(password IS NULL, '$2y$10$XVQZ18Ja2Qj945IUEuMWJ.Q4IWQwd5apoKwuBB2qSxTSdrjy7wh4G', password)
WHERE seed_key = 'initial-person-raiza-monte';

INSERT INTO users (name, email, password, role, status, seed_key)
SELECT 'John Mike Asuncion', 'john@safepaws.local', '$2y$10$XVQZ18Ja2Qj945IUEuMWJ.Q4IWQwd5apoKwuBB2qSxTSdrjy7wh4G', 'shelter', 'active', 'initial-person-john-mike-asuncion'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE seed_key = 'initial-person-john-mike-asuncion');
UPDATE users
SET name = IF(password IS NULL, 'John Mike Asuncion', name),
    email = IF(password IS NULL, 'john@safepaws.local', email),
    role = IF(password IS NULL, 'shelter', role),
    status = IF(password IS NULL, 'active', status),
    password = IF(password IS NULL, '$2y$10$XVQZ18Ja2Qj945IUEuMWJ.Q4IWQwd5apoKwuBB2qSxTSdrjy7wh4G', password)
WHERE seed_key = 'initial-person-john-mike-asuncion';

INSERT INTO users (name, email, password, role, status, seed_key)
SELECT 'Mark Jeremy Manuel', 'mark@safepaws.local', '$2y$10$XVQZ18Ja2Qj945IUEuMWJ.Q4IWQwd5apoKwuBB2qSxTSdrjy7wh4G', 'volunteer', 'active', 'initial-person-mark-jeremy-manuel'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE seed_key = 'initial-person-mark-jeremy-manuel');
UPDATE users
SET name = IF(password IS NULL, 'Mark Jeremy Manuel', name),
    email = IF(password IS NULL, 'mark@safepaws.local', email),
    role = IF(password IS NULL, 'volunteer', role),
    status = IF(password IS NULL, 'active', status),
    password = IF(password IS NULL, '$2y$10$XVQZ18Ja2Qj945IUEuMWJ.Q4IWQwd5apoKwuBB2qSxTSdrjy7wh4G', password)
WHERE seed_key = 'initial-person-mark-jeremy-manuel';

DELETE ur FROM user_roles ur
JOIN users u ON u.id = ur.user_id
JOIN roles r ON r.role_id = ur.role_id
WHERE u.seed_key IN (
  'initial-person-allan-arcilla-jr',
  'initial-person-terrence-james-pagulon',
  'initial-person-irish-alaon',
  'initial-person-raiza-monte',
  'initial-person-john-mike-asuncion',
  'initial-person-mark-jeremy-manuel'
)
AND r.role_name <> CASE u.role
  WHEN 'admin' THEN 'administrator'
  WHEN 'user' THEN 'adopter'
  WHEN 'shelter' THEN 'shelter_manager'
  WHEN 'volunteer' THEN 'volunteer'
END;
INSERT INTO user_roles (user_id, role_id)
SELECT u.id, r.role_id
FROM users u
JOIN roles r ON r.role_name = CASE u.role
  WHEN 'admin' THEN 'administrator'
  WHEN 'user' THEN 'adopter'
  WHEN 'shelter' THEN 'shelter_manager'
  WHEN 'volunteer' THEN 'volunteer'
END
WHERE u.seed_key IN (
  'initial-person-allan-arcilla-jr',
  'initial-person-terrence-james-pagulon',
  'initial-person-irish-alaon',
  'initial-person-raiza-monte',
  'initial-person-john-mike-asuncion',
  'initial-person-mark-jeremy-manuel'
)
AND NOT EXISTS (
  SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = r.role_id
);

INSERT INTO admin_requests (user_id, full_name, email, purpose, status)
SELECT u.id, u.name, u.email, 'Development account administrator access request; approval is required.', 'pending'
FROM users u
WHERE u.seed_key = 'initial-person-terrence-james-pagulon'
  AND NOT EXISTS (SELECT 1 FROM admin_requests ar WHERE ar.user_id = u.id);
UPDATE admin_requests ar
JOIN users u ON u.id = ar.user_id
SET ar.full_name = u.name,
    ar.email = u.email,
    ar.purpose = 'Development account administrator access request; approval is required.'
WHERE u.seed_key = 'initial-person-terrence-james-pagulon'
  AND ar.status = 'pending';

INSERT INTO volunteers (user_id, status)
SELECT id, 'active' FROM users
WHERE seed_key = 'initial-person-mark-jeremy-manuel'
  AND NOT EXISTS (SELECT 1 FROM volunteers v WHERE v.user_id = users.id);

INSERT INTO shelters (user_id, name, description, address, city, status, seed_key)
SELECT NULL, 'Safe Paws Animal Shelter – Cainta City', 'A community-focused animal shelter helping dogs and cats find loving homes while supporting responsible pet adoption and stray animal reporting.', NULL, 'Cainta, Rizal, Philippines', 'approved', 'initial-gallery-shelter'
WHERE NOT EXISTS (SELECT 1 FROM shelters WHERE seed_key = 'initial-gallery-shelter');

UPDATE shelters
SET name = 'Safe Paws Animal Shelter – Cainta City',
    description = 'A community-focused animal shelter helping dogs and cats find loving homes while supporting responsible pet adoption and stray animal reporting.',
    address = NULL,
    city = 'Cainta, Rizal, Philippines',
    status = 'approved'
WHERE seed_key = 'initial-gallery-shelter'
  AND name = 'Shelter details to be confirmed';

INSERT INTO pets (shelter_id, name, species, breed, sex, age, color, description, image, health_status, vaccination_status, status, seed_key)
SELECT s.id, 'Shadow', 'dog', 'Unknown', 'male', '2 years', NULL, 'Sample gallery metadata only. Confirm this animal information before publishing.', 'assets/images/pets/shadow.jpg', 'Not yet assessed', 'Unknown', 'available', 'initial-gallery-shadow'
FROM shelters s WHERE s.seed_key = 'initial-gallery-shelter'
  AND NOT EXISTS (SELECT 1 FROM pets WHERE seed_key = 'initial-gallery-shadow');
INSERT INTO pets (shelter_id, name, species, breed, sex, age, color, description, image, health_status, vaccination_status, status, seed_key)
SELECT s.id, 'Bruno', 'dog', 'Unknown', 'male', '4 years', NULL, 'Sample gallery metadata only. Confirm this animal information before publishing.', 'assets/images/pets/bruno.jpg', 'Not yet assessed', 'Unknown', 'available', 'initial-gallery-bruno'
FROM shelters s WHERE s.seed_key = 'initial-gallery-shelter'
  AND NOT EXISTS (SELECT 1 FROM pets WHERE seed_key = 'initial-gallery-bruno');
INSERT INTO pets (shelter_id, name, species, breed, sex, age, color, description, image, health_status, vaccination_status, status, seed_key)
SELECT s.id, 'Daisy', 'dog', 'Unknown', 'female', '3 years', NULL, 'Sample gallery metadata only. Confirm this animal information before publishing.', 'assets/images/pets/daisy.jpg', 'Not yet assessed', 'Unknown', 'available', 'initial-gallery-daisy'
FROM shelters s WHERE s.seed_key = 'initial-gallery-shelter'
  AND NOT EXISTS (SELECT 1 FROM pets WHERE seed_key = 'initial-gallery-daisy');
INSERT INTO pets (shelter_id, name, species, breed, sex, age, color, description, image, health_status, vaccination_status, status, seed_key)
SELECT s.id, 'Mochi', 'dog', 'Unknown', 'female', '1 year', NULL, 'Sample gallery metadata only. Confirm this animal information before publishing.', 'assets/images/pets/mochi.png', 'Not yet assessed', 'Unknown', 'available', 'initial-gallery-mochi'
FROM shelters s WHERE s.seed_key = 'initial-gallery-shelter'
  AND NOT EXISTS (SELECT 1 FROM pets WHERE seed_key = 'initial-gallery-mochi');
INSERT INTO pets (shelter_id, name, species, breed, sex, age, color, description, image, health_status, vaccination_status, status, seed_key)
SELECT s.id, 'Luna', 'cat', 'Unknown', 'female', '2 years', NULL, 'Sample gallery metadata only. Confirm this animal information before publishing.', 'assets/images/pets/luna.jpg', 'Not yet assessed', 'Unknown', 'available', 'initial-gallery-luna'
FROM shelters s WHERE s.seed_key = 'initial-gallery-shelter'
  AND NOT EXISTS (SELECT 1 FROM pets WHERE seed_key = 'initial-gallery-luna');
INSERT INTO pets (shelter_id, name, species, breed, sex, age, color, description, image, health_status, vaccination_status, status, seed_key)
SELECT s.id, 'Milo', 'cat', 'Unknown', 'male', '1 year', NULL, 'Sample gallery metadata only. Confirm this animal information before publishing.', 'assets/images/pets/milo.jpg', 'Not yet assessed', 'Unknown', 'available', 'initial-gallery-milo'
FROM shelters s WHERE s.seed_key = 'initial-gallery-shelter'
  AND NOT EXISTS (SELECT 1 FROM pets WHERE seed_key = 'initial-gallery-milo');
INSERT INTO pets (shelter_id, name, species, breed, sex, age, color, description, image, health_status, vaccination_status, status, seed_key)
SELECT s.id, 'Ginger', 'cat', 'Unknown', 'male', '3 years', NULL, 'Sample gallery metadata only. Confirm this animal information before publishing.', 'assets/images/pets/ginger.jpg', 'Not yet assessed', 'Unknown', 'available', 'initial-gallery-ginger'
FROM shelters s WHERE s.seed_key = 'initial-gallery-shelter'
  AND NOT EXISTS (SELECT 1 FROM pets WHERE seed_key = 'initial-gallery-ginger');
INSERT INTO pets (shelter_id, name, species, breed, sex, age, color, description, image, health_status, vaccination_status, status, seed_key)
SELECT s.id, 'Snowy', 'dog', 'Unknown', 'female', '2 years', NULL, 'Sample gallery metadata only. Confirm this animal information before publishing.', 'assets/images/pets/snowy.jpg', 'Not yet assessed', 'Unknown', 'available', 'initial-gallery-snowy'
FROM shelters s WHERE s.seed_key = 'initial-gallery-shelter'
  AND NOT EXISTS (SELECT 1 FROM pets WHERE seed_key = 'initial-gallery-snowy');
INSERT INTO pets (shelter_id, name, species, breed, sex, age, color, description, image, health_status, vaccination_status, status, seed_key)
SELECT s.id, 'Rocky', 'dog', 'Unknown', 'male', '5 years', NULL, 'Sample gallery metadata only. Confirm this animal information before publishing.', 'assets/images/pets/rocky.jpg', 'Not yet assessed', 'Unknown', 'available', 'initial-gallery-rocky'
FROM shelters s WHERE s.seed_key = 'initial-gallery-shelter'
  AND NOT EXISTS (SELECT 1 FROM pets WHERE seed_key = 'initial-gallery-rocky');
INSERT INTO pets (shelter_id, name, species, breed, sex, age, color, description, image, health_status, vaccination_status, status, seed_key)
SELECT s.id, 'Cotton', 'cat', 'Unknown', 'female', '1 year', NULL, 'Sample gallery metadata only. Confirm this animal information before publishing.', 'assets/images/pets/cotton.jpg', 'Not yet assessed', 'Unknown', 'available', 'initial-gallery-cotton'
FROM shelters s WHERE s.seed_key = 'initial-gallery-shelter'
  AND NOT EXISTS (SELECT 1 FROM pets WHERE seed_key = 'initial-gallery-cotton');
INSERT INTO pets (shelter_id, name, species, breed, sex, age, color, description, image, health_status, vaccination_status, status, seed_key)
SELECT s.id, 'Peanut', 'dog', 'Unknown', 'male', '6 months', NULL, 'Sample gallery metadata only. Confirm this animal information before publishing.', 'assets/images/pets/peanut.jpg', 'Not yet assessed', 'Unknown', 'available', 'initial-gallery-peanut'
FROM shelters s WHERE s.seed_key = 'initial-gallery-shelter'
  AND NOT EXISTS (SELECT 1 FROM pets WHERE seed_key = 'initial-gallery-peanut');

INSERT INTO pet_images (pet_id, image_path, alt_text, is_primary, sort_order)
SELECT p.id, p.image, CONCAT(p.name, ', a ', p.species, ' in the sample adoption gallery'), 1, 0
FROM pets p
WHERE p.seed_key LIKE 'initial-gallery-%'
  AND p.seed_key <> 'initial-gallery-shelter'
  AND NOT EXISTS (
    SELECT 1 FROM pet_images pi WHERE pi.pet_id = p.id AND pi.image_path = p.image
  );

-- Verification queries: expected counts are 5 roles, 6 named accounts,
-- 1 pending administrator request, 11 gallery pets, and 11 primary images.
SELECT role_name FROM roles ORDER BY role_name;
SELECT name, role, status, email IS NULL AS credentials_not_set
FROM users WHERE seed_key LIKE 'initial-person-%' ORDER BY name;
SELECT status, COUNT(*) AS request_count FROM admin_requests GROUP BY status;
SELECT species, COUNT(*) AS pet_count FROM pets WHERE seed_key LIKE 'initial-gallery-%' GROUP BY species;
SELECT COUNT(*) AS gallery_image_count
FROM pet_images pi JOIN pets p ON p.id = pi.pet_id
WHERE p.seed_key LIKE 'initial-gallery-%' AND pi.is_primary = 1;
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE IN ('BASE TABLE', 'VIEW')
ORDER BY TABLE_NAME;
