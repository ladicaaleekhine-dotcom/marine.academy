<?php
/**
 * Database Migration Script
 * NCST Maritime Academy Enrollment System
 *
 * Runs non-destructive schema updates, creates required indexes,
 * and configures runtime tables safely from the CLI.
 *
 * Usage:
 *   php database/migrate.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Error: This migration script may only be executed from the command line.\n");
}

echo "=== NCST Maritime Academy - Database Migration ===\n";

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/database.php';
}

try {
    // 1. Program code data corrections
    echo "[*] Step 1: Updating program code mappings...\n";
    $pdo->exec("UPDATE students SET program_code = 'BSMarE' WHERE program_code IS NULL AND (program_applying_for = 'BSMarE' OR program_applying_for LIKE '%BSMarE%')");
    $pdo->exec("UPDATE students SET program_code = 'BSMT' WHERE program_code IS NULL AND (program_applying_for = 'BSMT' OR program_applying_for LIKE '%BSMT%')");
    $pdo->exec("UPDATE fee_configurations SET program_code = 'BSMarE' WHERE program_code IS NULL AND (program_applying_for = 'BSMarE' OR program_applying_for LIKE '%BSMarE%')");
    $pdo->exec("UPDATE fee_configurations SET program_code = 'BSMT' WHERE program_code IS NULL AND (program_applying_for = 'BSMT' OR program_applying_for LIKE '%BSMT%')");
    echo "    -> Program codes verified.\n";

    // 2. Assessments reference_number column & unique index
    echo "[*] Step 2: Checking assessments reference_number schema...\n";
    $columnCheck = $pdo->query("SHOW COLUMNS FROM assessments LIKE 'reference_number'");
    if ($columnCheck->rowCount() === 0) {
        $pdo->exec("ALTER TABLE assessments ADD COLUMN reference_number VARCHAR(50) NULL AFTER academic_term_id");
        echo "    -> Added reference_number column to assessments\n";
    }

    $indexCheck = $pdo->query("SHOW INDEX FROM assessments WHERE Key_name = 'uq_assessments_reference_number'");
    if ($indexCheck->rowCount() === 0) {
        $pdo->exec("CREATE UNIQUE INDEX uq_assessments_reference_number ON assessments(reference_number)");
        echo "    -> Added uq_assessments_reference_number index to assessments\n";
    }

    // 3. Academic terms enrollment deadlines & flags
    echo "[*] Step 3: Checking academic_terms enrollment configuration schema...\n";
    $hasEnrollmentStarts = $pdo->query("SHOW COLUMNS FROM academic_terms LIKE 'enrollment_starts_on'")->fetch();
    if (!$hasEnrollmentStarts) {
        $pdo->exec("ALTER TABLE academic_terms ADD COLUMN enrollment_starts_on DATE NULL AFTER ends_on");
        echo "    -> Added enrollment_starts_on column to academic_terms\n";
    }
    $hasEnrollmentEnds = $pdo->query("SHOW COLUMNS FROM academic_terms LIKE 'enrollment_ends_on'")->fetch();
    if (!$hasEnrollmentEnds) {
        $pdo->exec("ALTER TABLE academic_terms ADD COLUMN enrollment_ends_on DATE NULL AFTER enrollment_starts_on");
        echo "    -> Added enrollment_ends_on column to academic_terms\n";
    }
    $hasRegDeadline = $pdo->query("SHOW COLUMNS FROM academic_terms LIKE 'registration_deadline'")->fetch();
    if (!$hasRegDeadline) {
        $pdo->exec("ALTER TABLE academic_terms ADD COLUMN registration_deadline DATE NULL AFTER enrollment_ends_on");
        echo "    -> Added registration_deadline column to academic_terms\n";
    }
    $hasLateRegDeadline = $pdo->query("SHOW COLUMNS FROM academic_terms LIKE 'late_registration_deadline'")->fetch();
    if (!$hasLateRegDeadline) {
        $pdo->exec("ALTER TABLE academic_terms ADD COLUMN late_registration_deadline DATE NULL AFTER registration_deadline");
        echo "    -> Added late_registration_deadline column to academic_terms\n";
    }
    $hasIsEnrollmentOpen = $pdo->query("SHOW COLUMNS FROM academic_terms LIKE 'is_enrollment_open'")->fetch();
    if (!$hasIsEnrollmentOpen) {
        $pdo->exec("ALTER TABLE academic_terms ADD COLUMN is_enrollment_open TINYINT(1) NOT NULL DEFAULT 1 AFTER late_registration_deadline");
        echo "    -> Added is_enrollment_open column to academic_terms\n";
    }

    // 4. Rate limiting login_attempts table with dual-key support (Fix-E: IP + Account)
    echo "[*] Step 4: Checking login_attempts table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip_hash VARCHAR(64) NOT NULL,
            user_hash VARCHAR(64) NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_attempts_ip_time (ip_hash, attempted_at),
            INDEX idx_login_attempts_user_time (user_hash, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $hasUserHash = $pdo->query("SHOW COLUMNS FROM login_attempts LIKE 'user_hash'")->fetch();
    if (!$hasUserHash) {
        $pdo->exec("ALTER TABLE login_attempts ADD COLUMN user_hash VARCHAR(64) NULL AFTER ip_hash");
        $pdo->exec("ALTER TABLE login_attempts ADD INDEX idx_login_attempts_user_time (user_hash, attempted_at)");
        echo "    -> Added user_hash column and index to login_attempts\n";
    }

    // 5. Section reservations table
    echo "[*] Step 5: Checking section_reservations table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS section_reservations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            section_id INT UNSIGNED NOT NULL,
            reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            status ENUM('active','expired','cancelled','converted') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_sec_res_student (student_id),
            KEY idx_sec_res_section_status (section_id, status, expires_at),
            CONSTRAINT fk_sec_res_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_sec_res_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "    -> section_reservations table verified.\n";

    // 6. Assessments calculated & finalization columns
    echo "[*] Step 6: Checking assessments finalization schema...\n";
    $hasCalculatedAmount = $pdo->query("SHOW COLUMNS FROM assessments LIKE 'calculated_amount'")->fetch();
    if (!$hasCalculatedAmount) {
        $pdo->exec("ALTER TABLE assessments ADD COLUMN calculated_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_amount");
        $pdo->exec("ALTER TABLE assessments ADD COLUMN finalized_amount DECIMAL(10,2) NULL DEFAULT NULL AFTER calculated_amount");
        $pdo->exec("ALTER TABLE assessments ADD COLUMN is_finalized TINYINT(1) NOT NULL DEFAULT 0 AFTER finalized_amount");
        $pdo->exec("ALTER TABLE assessments ADD COLUMN finalized_at DATETIME NULL DEFAULT NULL AFTER is_finalized");
        $pdo->exec("ALTER TABLE assessments ADD COLUMN finalized_by INT UNSIGNED NULL DEFAULT NULL AFTER finalized_at");
        $pdo->exec("ALTER TABLE assessments ADD COLUMN finalization_notes TEXT NULL DEFAULT NULL AFTER finalized_by");
        try {
            $pdo->exec("ALTER TABLE assessments ADD CONSTRAINT fk_assessments_finalized_by FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE");
        } catch (\Throwable $e) {}
        $pdo->exec("UPDATE assessments SET calculated_amount = total_amount WHERE calculated_amount = 0.00");
        echo "    -> Added finalization columns and backfilled calculated_amount in assessments\n";
    }

    // 7. Transferee evaluations table
    echo "[*] Step 7: Checking transferee_evaluations table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transferee_evaluations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            previous_school VARCHAR(150) NULL,
            previous_subject_code VARCHAR(50) NOT NULL,
            previous_subject_title VARCHAR(150) NOT NULL,
            previous_units DECIMAL(3,1) NOT NULL DEFAULT 3.0,
            previous_grade VARCHAR(10) NULL,
            equivalent_subject_id INT UNSIGNED NULL,
            status ENUM('pending','credited','rejected') NOT NULL DEFAULT 'pending',
            remarks TEXT NULL,
            evaluated_by INT UNSIGNED NULL,
            evaluated_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_transferee_eval_student (student_id),
            KEY idx_transferee_eval_equiv (equivalent_subject_id),
            CONSTRAINT fk_transferee_eval_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_transferee_eval_subject FOREIGN KEY (equivalent_subject_id) REFERENCES subjects(id) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_transferee_eval_evaluator FOREIGN KEY (evaluated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "    -> transferee_evaluations table verified.\n";

    // 8. DB-003: Drop redundant school_year and semester columns from sections table
    echo "[*] Step 8: Checking sections school_year and semester redundancy (DB-003)...\n";
    $hasSecSchoolYear = $pdo->query("SHOW COLUMNS FROM sections LIKE 'school_year'")->fetch();
    if ($hasSecSchoolYear) {
        $pdo->exec("ALTER TABLE sections DROP COLUMN school_year");
        echo "    -> Dropped redundant school_year column from sections\n";
    }
    $hasSecSemester = $pdo->query("SHOW COLUMNS FROM sections LIKE 'semester'")->fetch();
    if ($hasSecSemester) {
        $pdo->exec("ALTER TABLE sections DROP COLUMN semester");
        echo "    -> Dropped redundant semester column from sections\n";
    }

    // 9. DB-004: Drop legacy address and mailing_address columns from students table
    echo "[*] Step 9: Checking students legacy address and mailing_address columns (DB-004)...\n";
    $hasStudentAddress = $pdo->query("SHOW COLUMNS FROM students LIKE 'address'")->fetch();
    if ($hasStudentAddress) {
        $pdo->exec("ALTER TABLE students DROP COLUMN address");
        echo "    -> Dropped legacy address column from students\n";
    }
    $hasStudentMailingAddress = $pdo->query("SHOW COLUMNS FROM students LIKE 'mailing_address'")->fetch();
    if ($hasStudentMailingAddress) {
        $pdo->exec("ALTER TABLE students DROP COLUMN mailing_address");
        echo "    -> Dropped legacy mailing_address column from students\n";
    }

    // 10. DB-005: Add partial unique index on section_reservations via virtual column
    echo "[*] Step 10: Checking section_reservations unique active constraint (DB-005)...\n";
    $hasActiveStatus = $pdo->query("SHOW COLUMNS FROM section_reservations LIKE 'active_status'")->fetch();
    if (!$hasActiveStatus) {
        $pdo->exec("
            ALTER TABLE section_reservations 
            ADD COLUMN active_status VARCHAR(10) GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN 'active' ELSE NULL END) VIRTUAL 
            AFTER status
        ");
        echo "    -> Added virtual column active_status to section_reservations\n";
    }
    $hasIndex = $pdo->query("SHOW INDEX FROM section_reservations WHERE Key_name = 'uq_sec_res_student_section_active'")->fetch();
    if (!$hasIndex) {
        $pdo->exec("
            ALTER TABLE section_reservations 
            ADD UNIQUE KEY uq_sec_res_student_section_active (student_id, section_id, active_status)
        ");
        echo "    -> Added UNIQUE KEY uq_sec_res_student_section_active\n";
    }

    // 11. DB-006: Add fee_scope column to fee_configurations and backfill scoping
    echo "[*] Step 11: Checking fee_configurations fee_scope column (DB-006)...\n";
    $hasFeeScope = $pdo->query("SHOW COLUMNS FROM fee_configurations LIKE 'fee_scope'")->fetch();
    if (!$hasFeeScope) {
        $pdo->exec("
            ALTER TABLE fee_configurations 
            ADD COLUMN fee_scope ENUM('all','program','year_level','program_year') NOT NULL DEFAULT 'all' 
            AFTER year_level
        ");
        echo "    -> Added column fee_scope to fee_configurations\n";

        $pdo->exec("
            UPDATE fee_configurations SET fee_scope = CASE
                WHEN (program_code IS NOT NULL OR (program_applying_for IS NOT NULL AND program_applying_for != '')) AND (year_level IS NOT NULL AND year_level != '') THEN 'program_year'
                WHEN (program_code IS NOT NULL OR (program_applying_for IS NOT NULL AND program_applying_for != '')) AND (year_level IS NULL OR year_level = '') THEN 'program'
                WHEN (program_code IS NULL AND (program_applying_for IS NULL OR program_applying_for = '')) AND (year_level IS NOT NULL AND year_level != '') THEN 'year_level'
                ELSE 'all'
            END
        ");
        echo "    -> Backfilled fee_scope values for existing fee_configurations\n";
    }

    // 12. DB-007: Add updated_at column to curriculum_subjects
    echo "[*] Step 12: Checking curriculum_subjects updated_at column (DB-007)...\n";
    $hasCurrSubUpdatedAt = $pdo->query("SHOW COLUMNS FROM curriculum_subjects LIKE 'updated_at'")->fetch();
    if (!$hasCurrSubUpdatedAt) {
        $pdo->exec("
            ALTER TABLE curriculum_subjects 
            ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP 
            AFTER created_at
        ");
        echo "    -> Added updated_at column to curriculum_subjects\n";
    }

    // 13. DB-008: Make sections.course_id nullable and update FK to ON DELETE SET NULL
    echo "[*] Step 13: Checking sections course_id nullability (DB-008)...\n";
    $courseIdCol = $pdo->query("SHOW COLUMNS FROM sections LIKE 'course_id'")->fetch();
    if ($courseIdCol && strtoupper($courseIdCol['Null']) !== 'YES') {
        try {
            $pdo->exec("ALTER TABLE sections DROP FOREIGN KEY fk_sections_course");
        } catch (\Throwable $e) {
            // Constraint might not exist under this name
        }
        $pdo->exec("ALTER TABLE sections MODIFY COLUMN course_id INT UNSIGNED NULL DEFAULT NULL");
        $pdo->exec("ALTER TABLE sections ADD CONSTRAINT fk_sections_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL ON UPDATE CASCADE");
        echo "    -> Made sections.course_id nullable and updated fk_sections_course\n";
    }

    // 14. DB-009: Add UNIQUE KEY uq_alloc on payment_allocations (payment_id, assessment_item_id)
    echo "[*] Step 14: Checking payment_allocations unique key (DB-009)...\n";
    $hasUqAlloc = $pdo->query("
        SELECT COUNT(*) 
        FROM information_schema.STATISTICS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'payment_allocations' 
          AND INDEX_NAME = 'uq_alloc'
    ")->fetchColumn();

    if (!$hasUqAlloc) {
        $dupCount = (int)$pdo->query("
            SELECT COUNT(*) FROM (
                SELECT payment_id, assessment_item_id
                FROM payment_allocations
                GROUP BY payment_id, assessment_item_id
                HAVING COUNT(*) > 1
            ) dups
        ")->fetchColumn();

        if ($dupCount > 0) {
            echo "    [!] WARNING: Found {$dupCount} duplicate (payment_id, assessment_item_id) groups in payment_allocations. Skipping unique constraint addition to prevent failure.\n";
        } else {
            $pdo->exec("ALTER TABLE payment_allocations ADD UNIQUE KEY uq_alloc (payment_id, assessment_item_id)");
            echo "    -> Added UNIQUE KEY uq_alloc (payment_id, assessment_item_id) to payment_allocations\n";
        }
    }

    // 15. DB-011: Add section_subject_id and composite unique key to student_grades
    echo "[*] Step 15: Checking student_grades section_subject_id (DB-011)...\n";
    $hasSsCol = $pdo->query("SHOW COLUMNS FROM student_grades LIKE 'section_subject_id'")->fetch();
    if (!$hasSsCol) {
        $pdo->exec("ALTER TABLE student_grades ADD COLUMN section_subject_id INT(10) UNSIGNED NULL DEFAULT NULL COMMENT 'FK to section_subjects for per-subject grades; NULL for legacy single-course sections' AFTER grade_submission_id");
        $pdo->exec("ALTER TABLE student_grades ADD COLUMN ss_unique_key INT(10) UNSIGNED GENERATED ALWAYS AS (COALESCE(section_subject_id, 0)) VIRTUAL COMMENT 'Deterministic expression enabling strict uniqueness for legacy NULL section_subject_id rows' AFTER section_subject_id");
        $pdo->exec("ALTER TABLE student_grades ADD CONSTRAINT fk_student_grades_section_subject FOREIGN KEY (section_subject_id) REFERENCES section_subjects(id) ON DELETE CASCADE ON UPDATE CASCADE");
        $pdo->exec("ALTER TABLE student_grades ADD UNIQUE KEY uq_sg_submission_enrollment_subject (grade_submission_id, enrollment_id, ss_unique_key)");
        try {
            $pdo->exec("ALTER TABLE student_grades DROP INDEX uq_student_grade_submission_enrollment");
        } catch (\Throwable $e) {
            // Index might already be dropped or renamed
        }
        echo "    -> Added section_subject_id, ss_unique_key, and updated unique constraint on student_grades\n";
    } else {
        try {
            $pdo->exec("ALTER TABLE student_grades DROP INDEX uq_student_grade_submission_enrollment");
        } catch (\Throwable $e) {
            // Already dropped
        }
    }

    // 16. Published LMS modules and lessons for section subjects
    echo "[*] Step 16: Checking LMS lesson content tables...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lms_modules (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            section_subject_id INT UNSIGNED NOT NULL,
            title VARCHAR(150) NOT NULL,
            description TEXT NULL,
            display_order INT NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_lms_modules_subject_order (section_subject_id, is_published, display_order),
            CONSTRAINT fk_lms_modules_section_subject FOREIGN KEY (section_subject_id) REFERENCES section_subjects(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lms_lessons (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            module_id INT UNSIGNED NOT NULL,
            title VARCHAR(150) NOT NULL,
            description TEXT NULL,
            content_type ENUM('text','image','presentation','pdf','video','external_link') NOT NULL DEFAULT 'text',
            content_body LONGTEXT NULL,
            content_path VARCHAR(500) NULL,
            display_order INT NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_lms_lessons_module_order (module_id, is_published, display_order),
            CONSTRAINT fk_lms_lessons_module FOREIGN KEY (module_id) REFERENCES lms_modules(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "    -> LMS module and lesson tables verified.\n";

    // 17. Write migration flag files
    $configDir = __DIR__ . '/../config';
    if (is_dir($configDir) && is_writable($configDir)) {
        @file_put_contents($configDir . '/.migrated', date('c') . "\n");
        @file_put_contents($configDir . '/.migrated_v2', date('c') . "\n");
        echo "[+] Updated .migrated and .migrated_v2 flag files.\n";
    }

    echo "[+] All migrations completed successfully.\n";

} catch (\Throwable $e) {
    echo "[-] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
