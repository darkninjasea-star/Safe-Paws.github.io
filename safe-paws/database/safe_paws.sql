CREATE DATABASE IF NOT EXISTS safe_paws CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE safe_paws;

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS admin_requests, volunteers, support_records, registration_attempts, login_attempts, pet_photos, adoption_history, adoption_api_logs, stray_api_logs;
DROP TABLE IF EXISTS users, shelters, pets, stray_reports, rescue_assignments, adoption_applications, notifications, pet_images, activity_logs;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('user','volunteer','shelter','admin') NOT NULL DEFAULT 'user',
  phone VARCHAR(30) NULL,
  address VARCHAR(255) NULL,
  status ENUM('active','inactive','pending_admin_approval') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_login_at TIMESTAMP NULL,
  INDEX idx_users_role_status (role, status)
) ENGINE=InnoDB;

CREATE TABLE admin_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  purpose VARCHAR(255) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at TIMESTAMP NULL,
  INDEX idx_admin_requests_status_requested (status, requested_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE shelters (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT,
  address VARCHAR(255) NOT NULL,
  city VARCHAR(100) NOT NULL,
  phone VARCHAR(30),
  email VARCHAR(190),
  image VARCHAR(255),
  status ENUM('pending','approved','suspended') NOT NULL DEFAULT 'approved',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY one_shelter_per_user (user_id),
  INDEX idx_shelters_status_city (status, city),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE pets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shelter_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  species ENUM('dog','cat','rabbit','other') NOT NULL DEFAULT 'dog',
  breed VARCHAR(100),
  sex ENUM('male','female','unknown') NOT NULL DEFAULT 'unknown',
  age VARCHAR(50),
  color VARCHAR(80),
  description TEXT,
  image VARCHAR(255),
  status ENUM('available','pending','adopted','unavailable') NOT NULL DEFAULT 'available',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pets_status_species_created (status, species, created_at),
  INDEX idx_pets_shelter_created (shelter_id, created_at),
  FOREIGN KEY (shelter_id) REFERENCES shelters(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE stray_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reporter_id INT UNSIGNED NULL,
  reporter_name VARCHAR(120) NOT NULL,
  reporter_email VARCHAR(190) NOT NULL,
  phone VARCHAR(30),
  animal_type VARCHAR(80),
  urgency ENUM('low','normal','high','emergency') NOT NULL DEFAULT 'normal',
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  location VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  image VARCHAR(255),
  staff_notes TEXT,
  status ENUM('new','reviewed','assigned','in_progress','rescued','resolved','rejected','closed') NOT NULL DEFAULT 'new',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE INDEX idx_stray_reports_status_created ON stray_reports (status, created_at);

CREATE TABLE rescue_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id INT UNSIGNED NOT NULL,
  volunteer_id INT UNSIGNED NOT NULL,
  shelter_id INT UNSIGNED NULL,
  notes TEXT,
  status ENUM('assigned','in_progress','completed','cancelled') NOT NULL DEFAULT 'assigned',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY one_assignment (report_id),
  FOREIGN KEY (report_id) REFERENCES stray_reports(id) ON DELETE CASCADE,
  FOREIGN KEY (volunteer_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (shelter_id) REFERENCES shelters(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE adoption_applications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pet_id INT UNSIGNED NOT NULL,
  applicant_id INT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  phone VARCHAR(30),
  address VARCHAR(255),
  household_type VARCHAR(80),
  experience TEXT,
  other_pets TEXT,
  preferred_contact ENUM('email','phone','either') NOT NULL DEFAULT 'either',
  status ENUM('submitted','under_review','approved','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
  shelter_notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY one_application (pet_id, applicant_id),
  FOREIGN KEY (pet_id) REFERENCES pets(id) ON DELETE CASCADE,
  FOREIGN KEY (applicant_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE INDEX idx_adoption_applications_status ON adoption_applications (status, created_at);

CREATE TABLE volunteers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL UNIQUE,
  skills TEXT,
  availability TEXT,
  joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE support_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  request_type ENUM('general','volunteer','shelter','pet') NOT NULL,
  description TEXT NOT NULL,
  status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  staff_notes TEXT,
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  INDEX idx_support_status_submitted (status, submitted_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE registration_attempts (
  attempt_key CHAR(64) NOT NULL PRIMARY KEY,
  attempt_count INT NOT NULL DEFAULT 1,
  last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  blocked_until TIMESTAMP NULL,
  INDEX idx_registration_blocked (blocked_until)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  attempt_key CHAR(64) NOT NULL PRIMARY KEY,
  attempt_count INT NOT NULL DEFAULT 1,
  last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  blocked_until TIMESTAMP NULL,
  INDEX idx_login_blocked (blocked_until)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  link VARCHAR(255),
  related_type VARCHAR(50) NULL,
  related_id INT UNSIGNED NULL,
  event_key VARCHAR(190) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notifications_user_read_created (user_id, is_read, created_at),
  INDEX idx_notifications_related (related_type, related_id),
  UNIQUE KEY uq_notifications_user_event (user_id, event_key),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE pet_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pet_id INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pet_images_pet (pet_id),
  FOREIGN KEY (pet_id) REFERENCES pets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id INT UNSIGNED NULL,
  details JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_logs_user_created (user_id, created_at),
  INDEX idx_activity_logs_entity (entity_type, entity_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE adoption_api_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_data JSON NULL,
  response_data JSON NULL,
  status_code INT NOT NULL,
  api_endpoint VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE stray_api_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_data JSON NULL,
  response_data JSON NULL,
  status_code INT NOT NULL,
  api_endpoint VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Demo password for every account: password
INSERT INTO users (name,email,password,role,phone,address,status) VALUES
('Site Administrator','admin@safe-paws.test','$2y$10$SRf0TBR8hVOTRsr1rLGkruQ05RsgMsJxWPzcDezky6WVYAuegnKze','admin','555-0100','Safe Paws HQ','active'),
('Happy Tails Shelter','shelter@safe-paws.test','$2y$10$SRf0TBR8hVOTRsr1rLGkruQ05RsgMsJxWPzcDezky6WVYAuegnKze','shelter','555-0101','12 Paw Lane','active'),
('Rescue Volunteer','volunteer@safe-paws.test','$2y$10$SRf0TBR8hVOTRsr1rLGkruQ05RsgMsJxWPzcDezky6WVYAuegnKze','volunteer','555-0102','Volunteer County','active'),
('Demo Adopter','user@safe-paws.test','$2y$10$SRf0TBR8hVOTRsr1rLGkruQ05RsgMsJxWPzcDezky6WVYAuegnKze','user','555-0103','42 Home Street','active');

INSERT INTO users (name,email,password,role,status) VALUES
('Pending Admin Applicant','admin.request.test@safe-paws.test','$2y$10$SRf0TBR8hVOTRsr1rLGkruQ05RsgMsJxWPzcDezky6WVYAuegnKze','admin','pending_admin_approval');

INSERT INTO admin_requests (user_id,full_name,email,purpose,status) VALUES
(5,'Pending Admin Applicant','admin.request.test@safe-paws.test','To manage the Safe PAWS system and help animals in need.','pending');

INSERT INTO shelters (user_id,name,description,address,city,phone,email) VALUES
(2,'Happy Tails Rescue','A warm, foster-first rescue giving every animal a second chance.','12 Paw Lane','Pawsville','555-0101','shelter@safe-paws.test');

INSERT INTO pets (shelter_id,name,species,breed,sex,age,color,description,status) VALUES
(1,'Milo','dog','Labrador mix','male','2 years','Golden','Friendly, playful and ready for a family.','available'),
(1,'Luna','cat','Domestic shorthair','female','1 year','Tabby','A gentle lap cat who loves sunny windows.','available'),
(1,'Pepper','rabbit','Mini lop','female','8 months','Black and white','Calm, curious and litter trained.','available');

INSERT INTO pet_images (pet_id,image_path,sort_order) VALUES
(1,'assets/images/paw-placeholder.svg',0),
(2,'assets/images/paw-placeholder.svg',0),
(3,'assets/images/paw-placeholder.svg',0);

INSERT INTO volunteers (user_id,skills,availability) VALUES
(3,'Animal handling, First aid, Driving','Weekends, Evenings');

INSERT INTO support_records (user_id,request_type,description) VALUES
(4,'general','Need help with shelter volunteer application process');

INSERT INTO activity_logs (user_id,action,entity_type,details) VALUES
(1,'seeded_demo_data','system','{"source":"safe_paws.sql"}');
