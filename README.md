# Kwasu LMS Starter

This is a PHP + MySQL starter system that upgrades the earlier course registration portal into a lecture-focused academic resource management system.

## What it supports

- Matric-number login
- Roles: admin, lecturer, student
- Course registration
- Week-by-week topics
- YouTube lecture embeds
- Protected document downloads
- Tutorial update section
- Exam update section
- Bookmarking topics
- Marking videos as watched
- Admin/lecturer management page

## Installation

1. Import `database.sql` into phpMyAdmin.
2. Update the DB constants in `config.php` if needed.
3. Put the project inside XAMPP `htdocs`.
4. Create users in the `users` table with `password_hash()` values.

## Seed courses

The SQL file contains the tables. Insert the earlier course-registration courses into the `courses` table after you create a lecturer/admin account.

## Important

- Files are stored under `private_uploads/`.
- `private_uploads/.htaccess` blocks direct access.
- Users must be logged in to download files.
# Kwasulcs
