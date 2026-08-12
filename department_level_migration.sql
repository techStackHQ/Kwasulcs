-- Department & Level multi-tenancy migration (Task 13)
--
-- Adds support for multiple departments (Computer Science, Mass
-- Communication, Library and Information Science, ...) and levels (100-400)
-- on ONE shared database — department/level are DATA, scoped the same way
-- course_id already scopes chat/quiz/calendar features throughout this
-- codebase, not separate databases per department.
--
-- This file is a standalone, manually-reapplicable mirror of what
-- ensure_department_schema() in config.php already does automatically and
-- idempotently on first use (same pattern as every other ensure_*_table()
-- migration in this codebase) — run this by hand against the AlwaysData
-- production DB when deploying, rather than relying on the lazy runtime
-- path there. Safe to re-run: every statement either uses IF NOT EXISTS or
-- is wrapped in a way that tolerates already having been applied.

-- ── departments ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(10) NOT NULL UNIQUE,          -- e.g. CS, MCM, LIS
    slug VARCHAR(50) NOT NULL UNIQUE,          -- e.g. computer-science
    primary_color VARCHAR(7) NOT NULL DEFAULT '#07a701',
    logo_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── users: department_id + level ─────────────────────────────────────────
-- No FK constraint on department_id, deliberately — this codebase hit a
-- real bug before where an FK silently blocked legitimate inserts
-- (ai_chat_sessions); validated at the application layer instead.
-- MySQL/MariaDB has no "ADD COLUMN IF NOT EXISTS" — run each ALTER once;
-- if it errors with "Duplicate column name", that column already exists
-- and it's safe to move to the next statement.
ALTER TABLE users ADD COLUMN department_id INT NULL AFTER role;
ALTER TABLE users ADD INDEX idx_users_department (department_id);
ALTER TABLE users ADD COLUMN level INT NULL AFTER department_id;

-- ── courses: department_id + level ───────────────────────────────────────
-- A cross-listed/"borrowed" course taught in more than one department is
-- represented as separate course rows, one per department — no
-- many-to-many course<->department table needed.
ALTER TABLE courses ADD COLUMN department_id INT NULL AFTER lecturer_id;
ALTER TABLE courses ADD INDEX idx_courses_department (department_id);
ALTER TABLE courses ADD COLUMN level INT NULL AFTER department_id;

-- ── backfill: attribute all pre-existing data to Computer Science ────────
-- Zero data loss — every user/course that existed before this migration
-- keeps working exactly as before, now explicitly attributed to CS (the
-- only department that existed pre-migration).
INSERT INTO departments (name, code, slug, primary_color)
SELECT 'Computer Science', 'CS', 'computer-science', '#07a701'
WHERE NOT EXISTS (SELECT 1 FROM departments WHERE code = 'CS');

UPDATE users
SET department_id = (SELECT id FROM departments WHERE code = 'CS')
WHERE department_id IS NULL;

UPDATE courses
SET department_id = (SELECT id FROM departments WHERE code = 'CS')
WHERE department_id IS NULL;

-- Course level backfill: this project's course codes follow the standard
-- "SUBJ ###" convention where the numeric part's leading digit IS the
-- level (e.g. "CSC 402" -> 400 level, "MCB 322" -> 300 level) — confirmed
-- against every course code in the local DB copy before relying on this.
-- There is deliberately NO equivalent backfill for users.level: a matric
-- number's embedded admission year does not reliably equal a student's
-- CURRENT level (carry-over students, part-time students, etc. break that
-- assumption), so it's left NULL rather than guessed on live accounts.
-- This does not remove any existing student's course access — that's
-- preserved entirely via the enrollments-based carry-over match in
-- courses_for_user() (config.php), independent of department/level.
UPDATE courses
SET level = CAST(LEFT(REGEXP_SUBSTR(code, '[0-9]+'), 1) AS UNSIGNED) * 100
WHERE level IS NULL AND code REGEXP '[0-9]';
