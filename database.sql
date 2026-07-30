CREATE DATABASE IF NOT EXISTS lasu_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lasu_portal;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS section_resources;
DROP TABLE IF EXISTS course_sections;
DROP TABLE IF EXISTS documents;
DROP TABLE IF EXISTS videos;
DROP TABLE IF EXISTS bookmarks;
DROP TABLE IF EXISTS video_progress;
DROP TABLE IF EXISTS enrollments;
DROP TABLE IF EXISTS topics;
DROP TABLE IF EXISTS courses;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    matric_no VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(120) NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'lecturer', 'student') NOT NULL DEFAULT 'student',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    pref_email_notifications TINYINT(1) NOT NULL DEFAULT 1,
    pref_web_notifications TINYINT(1) NOT NULL DEFAULT 1,
    pref_chat_notifications TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    code VARCHAR(30) NOT NULL UNIQUE,
    semester ENUM('rain', 'harmattan') NOT NULL,
    lecturer_id INT NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#2563eb',
    is_approved TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_courses_lecturer FOREIGN KEY (lecturer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student_course (student_id, course_id),
    CONSTRAINT fk_enrollments_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_enrollments_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE topics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    week_number INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_topics_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE videos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    topic_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    original_url VARCHAR(500) NOT NULL,
    embed_url VARCHAR(500) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_videos_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    topic_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_documents_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE course_sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    section_type ENUM('tutorial_update', 'exam_update') NOT NULL,
    title VARCHAR(200) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sections_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE section_resources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    resource_type ENUM('video', 'document') NOT NULL,
    original_url VARCHAR(500) NULL,
    embed_url VARCHAR(500) NULL,
    file_path VARCHAR(500) NULL,
    file_type VARCHAR(20) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_section_resources_section FOREIGN KEY (section_id) REFERENCES course_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE bookmarks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    topic_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_bookmark (student_id, topic_id),
    CONSTRAINT fk_bookmarks_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_bookmarks_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE video_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    video_id INT NOT NULL,
    watched TINYINT(1) NOT NULL DEFAULT 0,
    last_position INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_progress (student_id, video_id),
    CONSTRAINT fk_progress_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_progress_video FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed sample courses from the earlier registration portal.
-- Lecturer/admin accounts should be inserted by the project owner with password_hash() values.

