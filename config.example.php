<?php

declare(strict_types=1);

session_start();

date_default_timezone_set('Africa/Lagos');

const DB_HOST             = 'localhost';
const DB_NAME             = 'lasu_lms';
const DB_USER             = 'root';
const DB_PASS             = '';
const UPLOAD_MAX_BYTES    = 20971520; // 20 MB
const PRIVATE_UPLOAD_ROOT = __DIR__ . '/private_uploads';

// ── Anthropic API ─────────────────────────────────────────────────────────────
const ANTHROPIC_API_KEY = 'YOUR_ANTHROPIC_API_KEY_HERE';

// ── YouTube Data API ──────────────────────────────────────────────────────────
// Get from: console.cloud.google.com → APIs & Services → Credentials
// Enable "YouTube Data API v3" in the API Library first.
const YOUTUBE_API_KEY = 'YOUR_YOUTUBE_API_KEY_HERE';

// ── Mail (Gmail SMTP with App Password) ──────────────────────────────────────
// Get App Password: Google Account → Security → 2-Step Verification → App Passwords
const MAIL_HOST       = 'smtp.gmail.com';
const MAIL_PORT       = 465;
const MAIL_USERNAME   = 'your-email@gmail.com';
const MAIL_PASSWORD   = 'YOUR_GMAIL_APP_PASSWORD_HERE';
const MAIL_FROM_NAME  = 'KWASU LCS';
const MAIL_FROM_EMAIL = 'your-email@gmail.com';
