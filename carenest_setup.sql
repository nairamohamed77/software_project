-- CareNest complete setup for MySQL 8+ (XAMPP)
-- Run this file in phpMyAdmin import or mysql client

DROP DATABASE IF EXISTS carenest;
CREATE DATABASE carenest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE carenest;

CREATE TABLE users (
    User_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Fname VARCHAR(50) NOT NULL,
    Lname VARCHAR(50) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role_type ENUM('Senior', 'Pal', 'FamilyProxy', 'Admin') NOT NULL,
    phone VARCHAR(20) UNIQUE,
    national_id_number VARCHAR(30) UNIQUE,
    profile_photo_url VARCHAR(255) DEFAULT 'default.png',
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    account_status ENUM('Pending', 'Active', 'Suspended', 'Deactivated') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_email (email),
    INDEX idx_role (role_type),
    INDEX idx_status (account_status)
) ENGINE=InnoDB;

CREATE TABLE permissions (
    permission_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(100) NOT NULL UNIQUE,
    label VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_type ENUM('Senior', 'Pal', 'FamilyProxy', 'Admin') NOT NULL,
    permission_key VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_type, permission_key),
    FOREIGN KEY (permission_key) REFERENCES permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE registration_documents (
    registration_document_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    User_ID INT UNSIGNED NOT NULL,
    document_type VARCHAR(60) NOT NULL,
    original_name VARCHAR(255),
    file_url VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120),
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
    INDEX idx_reg_doc_user (User_ID),
    INDEX idx_reg_doc_type (document_type)
) ENGINE=InnoDB;

CREATE TABLE senior_profiles (
    senior_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    User_ID INT UNSIGNED NOT NULL UNIQUE,
    age TINYINT UNSIGNED,
    address TEXT,
    latitude DECIMAL(10, 8) DEFAULT NULL,
    longitude DECIMAL(11, 8) DEFAULT NULL,
    comfort_profile TEXT COMMENT 'Senior preferences and habits',
    emergency_contact_name VARCHAR(100),
    emergency_contact_phone VARCHAR(20),
    emergency_contact_relation VARCHAR(50),
    points_balance INT UNSIGNED NOT NULL DEFAULT 0,
    subscription_tier ENUM('Basic', 'Standard', 'Premium') DEFAULT 'Basic',
    subscription_renewal_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
    INDEX idx_user (User_ID)
) ENGINE=InnoDB;

CREATE TABLE health_records (
    record_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    senior_ID INT UNSIGNED NOT NULL UNIQUE,
    medical_notes TEXT COMMENT 'Chronic conditions, medications',
    allergies TEXT,
    mobility_level ENUM('Full', 'Limited', 'Wheelchair', 'Bedridden') DEFAULT 'Full',
    mobility_notes TEXT,
    emergency_instructions TEXT COMMENT 'Instructions for Pal in emergency',
    must_acknowledge TINYINT(1) DEFAULT 1 COMMENT 'Pal must read before visit',
    updated_by INT UNSIGNED,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (senior_ID) REFERENCES senior_profiles(senior_ID) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(User_ID) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE pal_profiles (
    pal_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    User_ID INT UNSIGNED NOT NULL UNIQUE,
    bio TEXT,
    date_of_birth DATE,
    gender ENUM('Male', 'Female', 'Other'),
    verification_status ENUM('Pending', 'Documents_Uploaded', 'Identity_Verified', 'Criminal_Check', 'Approved', 'Rejected') DEFAULT 'Pending',
    background_check_date DATE,
    background_check_expiry DATE,
    rating_avg DECIMAL(3, 2) DEFAULT 0.00,
    total_ratings INT UNSIGNED DEFAULT 0,
    total_visits_completed INT UNSIGNED DEFAULT 0,
    points_balance INT UNSIGNED DEFAULT 0,
    travel_radius_km INT UNSIGNED DEFAULT 5,
    transport_mode ENUM('Walking', 'Cycling', 'Driving') DEFAULT 'Walking',
    is_available TINYINT(1) DEFAULT 1,
    latitude DECIMAL(10, 8) DEFAULT NULL,
    longitude DECIMAL(11, 8) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
    INDEX idx_user (User_ID),
    INDEX idx_status (verification_status),
    INDEX idx_available (is_available)
) ENGINE=InnoDB;

CREATE TABLE proxy_senior_link (
    link_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    proxy_User_ID INT UNSIGNED NOT NULL,
    senior_ID INT UNSIGNED NOT NULL,
    relationship_type ENUM('Son', 'Daughter', 'Spouse', 'Sibling', 'Friend', 'Caregiver', 'Other') NOT NULL,
    can_schedule TINYINT(1) DEFAULT 1,
    can_view_health TINYINT(1) DEFAULT 1,
    can_manage_points TINYINT(1) DEFAULT 0,
    is_primary TINYINT(1) DEFAULT 0,
    linked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proxy_User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
    FOREIGN KEY (senior_ID) REFERENCES senior_profiles(senior_ID) ON DELETE CASCADE,
    UNIQUE KEY unique_link (proxy_User_ID, senior_ID),
    INDEX idx_proxy (proxy_User_ID),
    INDEX idx_senior (senior_ID)
) ENGINE=InnoDB;

CREATE TABLE service_categories (
    category_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    icon VARCHAR(100) DEFAULT 'fa-hand-holding-heart',
    base_points_cost INT UNSIGNED NOT NULL DEFAULT 10,
    cost_per_extra_hour INT UNSIGNED DEFAULT 5,
    requires_badge VARCHAR(100) DEFAULT NULL COMMENT 'Badge name required to offer this service',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE skill_badges (
    badge_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pal_ID INT UNSIGNED NOT NULL,
    badge_name VARCHAR(100) NOT NULL,
    description TEXT,
    verification_status ENUM('Pending', 'Verified', 'Rejected', 'Expired') DEFAULT 'Pending',
    certificate_url VARCHAR(255),
    verified_by INT UNSIGNED DEFAULT NULL,
    issued_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pal_ID) REFERENCES pal_profiles(pal_ID) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(User_ID) ON DELETE SET NULL,
    INDEX idx_pal (pal_ID),
    INDEX idx_status (verification_status)
) ENGINE=InnoDB;

CREATE TABLE background_checks (
    check_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pal_ID INT UNSIGNED NOT NULL,
    status ENUM('Pending', 'In_Progress', 'Passed', 'Failed') DEFAULT 'Pending',
    id_document_url VARCHAR(255),
    criminal_record_url VARCHAR(255),
    reference_1_name VARCHAR(100),
    reference_1_phone VARCHAR(20),
    reference_1_verified TINYINT(1) DEFAULT 0,
    reference_2_name VARCHAR(100),
    reference_2_phone VARCHAR(20),
    reference_2_verified TINYINT(1) DEFAULT 0,
    reviewed_by INT UNSIGNED DEFAULT NULL,
    reviewed_at TIMESTAMP NULL,
    expiry_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pal_ID) REFERENCES pal_profiles(pal_ID) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(User_ID) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE visit_requests (
    visit_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    senior_ID INT UNSIGNED NOT NULL,
    pal_ID INT UNSIGNED DEFAULT NULL,
    requested_by_proxy INT UNSIGNED DEFAULT NULL,
    category_ID INT UNSIGNED NOT NULL,
    request_type ENUM('Standard', 'Urgent', 'Recurring') DEFAULT 'Standard',
    status ENUM(
        'Pending',
        'Accepted',
        'En_Route',
        'Live',
        'Completed',
        'Cancelled',
        'Rejected',
        'No_Show'
    ) DEFAULT 'Pending',
    scheduled_start DATETIME NOT NULL,
    scheduled_end DATETIME NOT NULL,
    actual_checkin DATETIME DEFAULT NULL,
    actual_checkout DATETIME DEFAULT NULL,
    service_address TEXT,
    task_details TEXT,
    special_instructions TEXT,
    mood_observation ENUM('Great', 'Good', 'Neutral', 'Sad', 'Unwell') DEFAULT NULL,
    health_observation TEXT,
    points_reserved INT UNSIGNED DEFAULT 0,
    points_paid INT UNSIGNED DEFAULT 0,
    cancellation_reason TEXT,
    cancelled_by INT UNSIGNED DEFAULT NULL,
    cancelled_at TIMESTAMP NULL,
    is_extended TINYINT(1) DEFAULT 0,
    extension_minutes INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (senior_ID) REFERENCES senior_profiles(senior_ID),
    FOREIGN KEY (pal_ID) REFERENCES pal_profiles(pal_ID) ON DELETE SET NULL,
    FOREIGN KEY (category_ID) REFERENCES service_categories(category_ID),
    FOREIGN KEY (requested_by_proxy) REFERENCES users(User_ID) ON DELETE SET NULL,
    FOREIGN KEY (cancelled_by) REFERENCES users(User_ID) ON DELETE SET NULL,
    INDEX idx_senior (senior_ID),
    INDEX idx_pal (pal_ID),
    INDEX idx_status (status),
    INDEX idx_scheduled (scheduled_start)
) ENGINE=InnoDB;

CREATE TABLE pal_passed_requests (
    pass_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visit_ID INT UNSIGNED NOT NULL,
    pal_ID INT UNSIGNED NOT NULL,
    action_type ENUM('Rejected', 'Ignored', 'Unavailable') DEFAULT 'Rejected',
    reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_ID) REFERENCES visit_requests(visit_ID) ON DELETE CASCADE,
    FOREIGN KEY (pal_ID) REFERENCES pal_profiles(pal_ID) ON DELETE CASCADE,
    UNIQUE KEY unique_pass (visit_ID, pal_ID)
) ENGINE=InnoDB;

CREATE TABLE ratings (
    rating_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visit_ID INT UNSIGNED NOT NULL UNIQUE,
    senior_ID INT UNSIGNED NOT NULL,
    pal_ID INT UNSIGNED NOT NULL,
    rating_score DECIMAL(2, 1) NOT NULL CHECK (rating_score BETWEEN 1 AND 5),
    comment TEXT,
    is_public TINYINT(1) DEFAULT 1,
    flagged TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_ID) REFERENCES visit_requests(visit_ID) ON DELETE CASCADE,
    FOREIGN KEY (senior_ID) REFERENCES senior_profiles(senior_ID),
    FOREIGN KEY (pal_ID) REFERENCES pal_profiles(pal_ID),
    INDEX idx_pal (pal_ID)
) ENGINE=InnoDB;

CREATE TABLE silverpoints_ledger (
    ledger_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    User_ID INT UNSIGNED NOT NULL,
    visit_ID INT UNSIGNED DEFAULT NULL,
    entry_type ENUM(
        'TopUp',
        'Booking_Reserve',
        'Booking_Release',
        'Visit_Payment',
        'Insurance_Deduction',
        'Karma_Bonus',
        'Referral_Credit',
        'Gift_Received',
        'Cancellation_Refund',
        'Cancellation_Penalty',
        'CashOut',
        'Subscription_TopUp',
        'Admin_Adjustment'
    ) NOT NULL,
    points_amount INT NOT NULL COMMENT 'Positive = credit, Negative = debit',
    balance_after INT UNSIGNED NOT NULL,
    description VARCHAR(500),
    reference_id INT UNSIGNED DEFAULT NULL COMMENT 'Related entity ID',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
    FOREIGN KEY (visit_ID) REFERENCES visit_requests(visit_ID) ON DELETE SET NULL,
    INDEX idx_user (User_ID),
    INDEX idx_type (entry_type),
    INDEX idx_date (created_at)
) ENGINE=InnoDB;

CREATE TABLE escrow (
    escrow_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visit_ID INT UNSIGNED NOT NULL UNIQUE,
    senior_ID INT UNSIGNED NOT NULL,
    points_locked INT UNSIGNED NOT NULL,
    status ENUM('Locked', 'Released_To_Pal', 'Returned_To_Senior', 'Disputed') DEFAULT 'Locked',
    locked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    released_at TIMESTAMP NULL,
    FOREIGN KEY (visit_ID) REFERENCES visit_requests(visit_ID) ON DELETE CASCADE,
    FOREIGN KEY (senior_ID) REFERENCES senior_profiles(senior_ID)
) ENGINE=InnoDB;

CREATE TABLE cashout_destinations (
    destination_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pal_ID INT UNSIGNED NOT NULL,
    destination_type ENUM('Bank_Transfer', 'Gift_Card', 'Wallet', 'Other') NOT NULL,
    provider_name VARCHAR(100),
    account_identifier VARCHAR(150) COMMENT 'IBAN or account number (masked)',
    is_default TINYINT(1) DEFAULT 0,
    is_verified TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pal_ID) REFERENCES pal_profiles(pal_ID) ON DELETE CASCADE,
    INDEX idx_pal (pal_ID)
) ENGINE=InnoDB;

CREATE TABLE cashout_requests (
    cashout_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pal_ID INT UNSIGNED NOT NULL,
    destination_ID INT UNSIGNED NOT NULL,
    points_requested INT UNSIGNED NOT NULL,
    cash_equivalent DECIMAL(10, 2) DEFAULT NULL,
    status ENUM('Pending', 'Processing', 'Completed', 'Rejected', 'Cancelled') DEFAULT 'Pending',
    rejection_reason TEXT,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    processed_by INT UNSIGNED DEFAULT NULL,
    FOREIGN KEY (pal_ID) REFERENCES pal_profiles(pal_ID) ON DELETE CASCADE,
    FOREIGN KEY (destination_ID) REFERENCES cashout_destinations(destination_ID),
    FOREIGN KEY (processed_by) REFERENCES users(User_ID) ON DELETE SET NULL,
    INDEX idx_pal (pal_ID),
    INDEX idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE emergency_threads (
    thread_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    senior_ID INT UNSIGNED NOT NULL,
    visit_ID INT UNSIGNED DEFAULT NULL,
    triggered_by INT UNSIGNED NOT NULL COMMENT 'User who pressed panic button',
    status ENUM('Open', 'Acknowledged', 'Resolved', 'False_Alarm') DEFAULT 'Open',
    priority_level ENUM('Critical', 'High', 'Medium') DEFAULT 'Critical',
    senior_location TEXT,
    senior_medical_snapshot TEXT COMMENT 'Copy of medical notes at time of emergency',
    resolved_by INT UNSIGNED DEFAULT NULL,
    resolved_at TIMESTAMP NULL,
    resolution_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (senior_ID) REFERENCES senior_profiles(senior_ID),
    FOREIGN KEY (visit_ID) REFERENCES visit_requests(visit_ID) ON DELETE SET NULL,
    FOREIGN KEY (triggered_by) REFERENCES users(User_ID),
    FOREIGN KEY (resolved_by) REFERENCES users(User_ID) ON DELETE SET NULL,
    INDEX idx_senior (senior_ID),
    INDEX idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE emergency_messages (
    message_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_ID INT UNSIGNED NOT NULL,
    sender_User_ID INT UNSIGNED NOT NULL,
    message_text TEXT,
    location_snapshot VARCHAR(255),
    medical_snapshot TEXT,
    message_type ENUM('Alert', 'Update', 'Resolution', 'System') DEFAULT 'Alert',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (thread_ID) REFERENCES emergency_threads(thread_ID) ON DELETE CASCADE,
    FOREIGN KEY (sender_User_ID) REFERENCES users(User_ID),
    INDEX idx_thread (thread_ID)
) ENGINE=InnoDB;

CREATE TABLE notifications (
    notification_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    User_ID INT UNSIGNED NOT NULL,
    type ENUM(
        'Visit_Confirmed',
        'Visit_Cancelled',
        'Visit_Reminder',
        'Visit_Started',
        'Visit_Completed',
        'Emergency_Alert',
        'Points_Update',
        'Badge_Awarded',
        'Background_Approved',
        'Background_Rejected',
        'Welfare_Check',
        'Admin_Broadcast',
        'System'
    ) NOT NULL,
    title VARCHAR(150) NOT NULL,
    message_body TEXT,
    entity_type VARCHAR(50) DEFAULT NULL COMMENT 'visit, emergency, etc.',
    entity_ID INT UNSIGNED DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
    INDEX idx_user (User_ID),
    INDEX idx_read (is_read),
    INDEX idx_date (created_at)
) ENGINE=InnoDB;

CREATE TABLE admin_broadcasts (
    broadcast_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_ID INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    message_body TEXT NOT NULL,
    target_role ENUM('All', 'Senior', 'Pal', 'FamilyProxy') DEFAULT 'All',
    severity_level ENUM('Info', 'Warning', 'Critical') DEFAULT 'Info',
    expires_at TIMESTAMP NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_ID) REFERENCES users(User_ID),
    INDEX idx_active (is_active),
    INDEX idx_target (target_role)
) ENGINE=InnoDB;

CREATE TABLE referral_tracking (
    referral_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    referrer_User_ID INT UNSIGNED NOT NULL,
    referred_User_ID INT UNSIGNED NOT NULL UNIQUE,
    referral_code VARCHAR(20) NOT NULL UNIQUE,
    status ENUM('Pending', 'Completed', 'Rewarded') DEFAULT 'Pending',
    reward_points INT UNSIGNED DEFAULT 50,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (referrer_User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
    FOREIGN KEY (referred_User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
    INDEX idx_referrer (referrer_User_ID),
    INDEX idx_code (referral_code)
) ENGINE=InnoDB;

CREATE TABLE gift_transactions (
    gift_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sender_User_ID INT UNSIGNED NOT NULL,
    recipient_senior_ID INT UNSIGNED NOT NULL,
    points_gifted INT UNSIGNED NOT NULL,
    message TEXT,
    status ENUM('Pending', 'Delivered', 'Failed') DEFAULT 'Pending',
    delivered_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
    FOREIGN KEY (recipient_senior_ID) REFERENCES senior_profiles(senior_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- UC-31: Dispute mediation (Senior / Pal / Proxy raise; Admin resolves)
CREATE TABLE disputes (
    dispute_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visit_ID INT UNSIGNED NOT NULL,
    raised_by_User_ID INT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    evidence_url VARCHAR(500) DEFAULT NULL,
    status ENUM('Open', 'Awaiting_Info', 'Resolved') NOT NULL DEFAULT 'Open',
    resolution ENUM('Refund_Senior', 'Release_Pal') DEFAULT NULL COMMENT 'Set when status=Resolved',
    resolution_notes TEXT,
    resolved_by_User_ID INT UNSIGNED DEFAULT NULL,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_ID) REFERENCES visit_requests(visit_ID) ON DELETE CASCADE,
    FOREIGN KEY (raised_by_User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by_User_ID) REFERENCES users(User_ID) ON DELETE SET NULL,
    INDEX idx_dispute_status (status),
    INDEX idx_dispute_visit (visit_ID)
) ENGINE=InnoDB;

-- UC-34: Append-only system audit trail (replaces per-dispute message/audit side tables)
CREATE TABLE IF NOT EXISTS system_audit_log (
    log_ID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_User_ID INT UNSIGNED DEFAULT NULL,
    action_type VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80) DEFAULT NULL,
    entity_ID BIGINT UNSIGNED DEFAULT NULL,
    details TEXT,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_actor (actor_User_ID),
    INDEX idx_audit_entity (entity_type, entity_ID),
    INDEX idx_audit_action (action_type),
    FOREIGN KEY (actor_User_ID) REFERENCES users(User_ID) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Pal-requested visit extension (Senior / Proxy approve)
CREATE TABLE IF NOT EXISTS visit_extension_requests (
    request_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visit_ID INT UNSIGNED NOT NULL,
    pal_ID INT UNSIGNED NOT NULL,
    extra_minutes INT UNSIGNED NOT NULL,
    extra_points INT UNSIGNED NOT NULL,
    status ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    resolved_by_User_ID INT UNSIGNED DEFAULT NULL,
    reject_reason VARCHAR(500) DEFAULT NULL,
    INDEX idx_ext_visit (visit_ID),
    INDEX idx_ext_status (status),
    FOREIGN KEY (visit_ID) REFERENCES visit_requests(visit_ID) ON DELETE CASCADE,
    FOREIGN KEY (pal_ID) REFERENCES pal_profiles(pal_ID) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by_User_ID) REFERENCES users(User_ID) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE welfare_checks (
    check_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    senior_ID INT UNSIGNED NOT NULL,
    triggered_by ENUM('System', 'FamilyProxy', 'Admin') DEFAULT 'System',
    trigger_reason VARCHAR(255) COMMENT 'e.g. No login for 3 days',
    status ENUM('Pending', 'Contacted', 'Resolved', 'Escalated') DEFAULT 'Pending',
    resolution_notes TEXT,
    checked_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (senior_ID) REFERENCES senior_profiles(senior_ID) ON DELETE CASCADE,
    FOREIGN KEY (checked_by) REFERENCES users(User_ID) ON DELETE SET NULL,
    INDEX idx_senior (senior_ID),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Admin user
INSERT INTO users (Fname, Lname, email, password_hash, role_type, phone, is_active, account_status)
VALUES ('System', 'Admin', 'admin@carenest.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', '01000000000', 1, 'Active');

-- Senior user
INSERT INTO users (Fname, Lname, email, password_hash, role_type, phone, is_active, account_status)
VALUES ('Mohamed', 'Ahmed', 'senior@carenest.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Senior', '01111111111', 1, 'Active');

-- Pal user
INSERT INTO users (Fname, Lname, email, password_hash, role_type, phone, is_active, account_status)
VALUES ('Ahmed', 'Hassan', 'pal@carenest.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Pal', '01222222222', 1, 'Active');

-- FamilyProxy user
INSERT INTO users (Fname, Lname, email, password_hash, role_type, phone, is_active, account_status)
VALUES ('Sara', 'Mohamed', 'proxy@carenest.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'FamilyProxy', '01333333333', 1, 'Active');

INSERT INTO permissions (permission_key, label) VALUES
('manage_users', 'Manage users'),
('manage_service_categories', 'Manage service categories'),
('manage_welfare_checks', 'Manage welfare checks'),
('view_audit_logs', 'View audit logs'),
('resolve_disputes', 'Resolve disputes'),
('book_visit', 'Create bookings'),
('request_visit_extension', 'Request visit extension'),
('approve_visit_extension', 'Approve visit extension'),
('raise_dispute', 'Raise dispute');

INSERT INTO role_permissions (role_type, permission_key) VALUES
('Admin', 'manage_users'),
('Admin', 'manage_service_categories'),
('Admin', 'manage_welfare_checks'),
('Admin', 'view_audit_logs'),
('Admin', 'resolve_disputes'),
('Admin', 'book_visit'),
('Admin', 'request_visit_extension'),
('Admin', 'approve_visit_extension'),
('Admin', 'raise_dispute'),
('Senior', 'book_visit'),
('Senior', 'approve_visit_extension'),
('Senior', 'raise_dispute'),
('Pal', 'request_visit_extension'),
('Pal', 'raise_dispute'),
('FamilyProxy', 'book_visit'),
('FamilyProxy', 'approve_visit_extension'),
('FamilyProxy', 'raise_dispute');

-- Note: All test passwords are "password"

-- Senior Profile
INSERT INTO senior_profiles (User_ID, address, comfort_profile, emergency_contact_name, emergency_contact_phone, emergency_contact_relation, points_balance, subscription_tier)
VALUES (2, '15 Nile Street, Maadi, Cairo', 'Prefers quiet conversation. Enjoys chess. Allergic to pet hair.', 'Sara Mohamed', '01333333333', 'Daughter', 350, 'Standard');

-- Health Record
INSERT INTO health_records (senior_ID, medical_notes, allergies, mobility_level, emergency_instructions)
VALUES (1, 'Type 2 Diabetic. Takes insulin twice daily at 8am and 8pm.', 'Penicillin, Pet hair', 'Limited', 'Call daughter Sara immediately. Senior carries insulin in kitchen fridge.');

-- Pal Profile
INSERT INTO pal_profiles (User_ID, bio, verification_status, rating_avg, total_visits_completed, points_balance, travel_radius_km, transport_mode, is_available)
VALUES (3, 'Friendly helper with 2 years experience caring for elderly.', 'Approved', 4.80, 24, 1200, 5, 'Cycling', 1);

-- Background check dossier sample (shows in Admin → Background Checks)
INSERT INTO background_checks (pal_ID, status, id_document_url, criminal_record_url, reference_1_name, reference_1_phone)
VALUES (1, 'Pending', 'uploads/documents/pal_sample_id.pdf', 'uploads/documents/pal_sample_criminal.pdf', 'Neighbor Adel', '01001234567');

-- Proxy Link
INSERT INTO proxy_senior_link (proxy_User_ID, senior_ID, relationship_type, can_schedule, can_view_health, is_primary)
VALUES (4, 1, 'Daughter', 1, 1, 1);

-- Service Categories
INSERT INTO service_categories (category_name, description, icon, base_points_cost, cost_per_extra_hour) VALUES
('Grocery Shopping', 'Help with shopping and carrying groceries', 'fa-shopping-cart', 20, 10),
('Tech Support', 'Help with phone, tablet, or computer issues', 'fa-laptop', 25, 12),
('Companionship', 'Friendly conversation and social interaction', 'fa-heart', 10, 8),
('Gardening', 'Light gardening and plant care', 'fa-seedling', 30, 15),
('Medicine Pickup', 'Collecting prescriptions from pharmacy', 'fa-pills', 20, 10),
('Meal Preparation', 'Help with cooking simple meals', 'fa-utensils', 25, 12),
('House Cleaning', 'Light cleaning and tidying up', 'fa-broom', 30, 15),
('Transportation', 'Accompany senior to appointments', 'fa-car', 35, 18),
('Letter & Errands', 'Post office, bank, and other errands', 'fa-envelope', 20, 10),
('Exercise & Walk', 'Accompany senior for walks or light exercise', 'fa-walking', 15, 8);

-- Sample Visit
INSERT INTO visit_requests (senior_ID, pal_ID, category_ID, status, scheduled_start, scheduled_end, task_details, points_reserved, points_paid)
VALUES (1, 1, 1, 'Completed', '2026-04-20 10:00:00', '2026-04-20 11:00:00', 'Need milk, bread, cheese, and vegetables from the supermarket.', 20, 20);

-- Sample Rating
INSERT INTO ratings (visit_ID, senior_ID, pal_ID, rating_score, comment)
VALUES (1, 1, 1, 5.0, 'Ahmed was very helpful and arrived on time. Very kind and patient.');

-- Sample Notification
INSERT INTO notifications (User_ID, type, title, message_body, entity_type, entity_ID)
VALUES (2, 'Visit_Completed', 'Visit Completed', 'Your visit with Ahmed Hassan has been completed successfully.', 'visit', 1);

-- Skill Badge for Pal
INSERT INTO skill_badges (pal_ID, badge_name, description, verification_status, issued_at)
VALUES (1, 'First Aid Certified', 'Completed Red Cross First Aid training', 'Verified', NOW());

DELIMITER //

CREATE PROCEDURE BookVisit(
    IN p_senior_ID INT,
    IN p_pal_ID INT,
    IN p_category_ID INT,
    IN p_scheduled_start DATETIME,
    IN p_scheduled_end DATETIME,
    IN p_task_details TEXT
)
BEGIN
    DECLARE v_points_cost INT;
    DECLARE v_senior_balance INT;
    DECLARE v_visit_ID INT;

    SELECT base_points_cost INTO v_points_cost
    FROM service_categories WHERE category_ID = p_category_ID;

    SELECT points_balance INTO v_senior_balance
    FROM senior_profiles WHERE senior_ID = p_senior_ID;

    IF v_senior_balance < v_points_cost THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient SilverPoints';
    END IF;

    INSERT INTO visit_requests (senior_ID, pal_ID, category_ID, status, scheduled_start, scheduled_end, task_details, points_reserved)
    VALUES (p_senior_ID, p_pal_ID, p_category_ID, 'Pending', p_scheduled_start, p_scheduled_end, p_task_details, v_points_cost);

    SET v_visit_ID = LAST_INSERT_ID();

    INSERT INTO escrow (visit_ID, senior_ID, points_locked, status)
    VALUES (v_visit_ID, p_senior_ID, v_points_cost, 'Locked');

    UPDATE senior_profiles SET points_balance = points_balance - v_points_cost
    WHERE senior_ID = p_senior_ID;

    INSERT INTO silverpoints_ledger (User_ID, visit_ID, entry_type, points_amount, balance_after, description)
    VALUES (
        (SELECT User_ID FROM senior_profiles WHERE senior_ID = p_senior_ID),
        v_visit_ID, 'Booking_Reserve', -v_points_cost,
        v_senior_balance - v_points_cost,
        CONCAT('Points reserved for visit #', v_visit_ID)
    );

    SELECT v_visit_ID AS visit_ID, 'Visit booked successfully' AS message;
END//

CREATE PROCEDURE CompleteVisit(IN p_visit_ID INT)
BEGIN
    DECLARE v_points INT;
    DECLARE v_pal_ID INT;
    DECLARE v_senior_ID INT;
    DECLARE v_insurance INT;
    DECLARE v_pal_gets INT;
    DECLARE v_pal_user_id INT;

    SELECT points_reserved, pal_ID, senior_ID
    INTO v_points, v_pal_ID, v_senior_ID
    FROM visit_requests WHERE visit_ID = p_visit_ID;

    SET v_insurance = CEIL(v_points * 0.05);
    SET v_pal_gets = v_points - v_insurance;

    SELECT User_ID INTO v_pal_user_id FROM pal_profiles WHERE pal_ID = v_pal_ID;

    UPDATE visit_requests
    SET status = 'Completed', actual_checkout = NOW(), points_paid = v_pal_gets
    WHERE visit_ID = p_visit_ID;

    UPDATE escrow SET status = 'Released_To_Pal', released_at = NOW()
    WHERE visit_ID = p_visit_ID;

    UPDATE pal_profiles SET points_balance = points_balance + v_pal_gets,
    total_visits_completed = total_visits_completed + 1
    WHERE pal_ID = v_pal_ID;

    INSERT INTO silverpoints_ledger (User_ID, visit_ID, entry_type, points_amount, balance_after, description)
    SELECT v_pal_user_id, p_visit_ID, 'Visit_Payment', v_pal_gets,
    points_balance, CONCAT('Payment for visit #', p_visit_ID)
    FROM pal_profiles WHERE pal_ID = v_pal_ID;

    SELECT 'Visit completed successfully' AS message;
END//

DELIMITER ;

CREATE VIEW vw_active_pals AS
SELECT
    pp.pal_ID,
    CONCAT(u.Fname, ' ', u.Lname) AS pal_name,
    u.profile_photo_url,
    pp.rating_avg,
    pp.total_visits_completed,
    pp.travel_radius_km,
    pp.transport_mode,
    pp.points_balance,
    pp.is_available,
    pp.verification_status
FROM pal_profiles pp
JOIN users u ON pp.User_ID = u.User_ID
WHERE pp.verification_status = 'Approved'
AND u.is_active = 1
AND u.account_status = 'Active';

CREATE VIEW vw_senior_summary AS
SELECT
    sp.senior_ID,
    CONCAT(u.Fname, ' ', u.Lname) AS senior_name,
    u.profile_photo_url,
    sp.points_balance,
    sp.subscription_tier,
    sp.emergency_contact_name,
    sp.emergency_contact_phone,
    hr.medical_notes,
    hr.allergies,
    hr.mobility_level,
    (SELECT COUNT(*) FROM visit_requests vr WHERE vr.senior_ID = sp.senior_ID) AS total_visits,
    (SELECT COUNT(*) FROM visit_requests vr WHERE vr.senior_ID = sp.senior_ID AND vr.status = 'Completed') AS completed_visits
FROM senior_profiles sp
JOIN users u ON sp.User_ID = u.User_ID
LEFT JOIN health_records hr ON sp.senior_ID = hr.senior_ID;

CREATE VIEW vw_visit_details AS
SELECT
    vr.visit_ID,
    vr.status,
    vr.scheduled_start,
    vr.scheduled_end,
    vr.task_details,
    vr.points_reserved,
    vr.points_paid,
    sc.category_name,
    sc.base_points_cost,
    CONCAT(su.Fname, ' ', su.Lname) AS senior_name,
    CONCAT(pu.Fname, ' ', pu.Lname) AS pal_name,
    pp.rating_avg AS pal_rating
FROM visit_requests vr
JOIN service_categories sc ON vr.category_ID = sc.category_ID
JOIN senior_profiles sp ON vr.senior_ID = sp.senior_ID
JOIN users su ON sp.User_ID = su.User_ID
LEFT JOIN pal_profiles pp ON vr.pal_ID = pp.pal_ID
LEFT JOIN users pu ON pp.User_ID = pu.User_ID;
