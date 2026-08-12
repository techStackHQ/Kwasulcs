<?php

declare(strict_types=1);

// Reusable, self-contained logic backing the 6-step "Forgot Password" wizard
// (forgot_password.php + the fp_*.php endpoints at the project root). Kept
// separate from config.php so this security-sensitive flow's rules — code
// TTL, attempt limits, hashing — live in one place instead of being
// scattered across each endpoint.

const PR_CODE_TTL_SECONDS     = 120; // exactly 2 minutes, per spec
const PR_MAX_MATRIC_ATTEMPTS  = 3;   // Step 1: after this many misses, show the "contact support" message
const PR_MATRIC_LOCKOUT_SECONDS = 15 * 60; // same cooldown as the login lockout (Task 10), for consistency
const PR_MAX_CODE_ATTEMPTS    = 5;   // Step 5: after this many wrong codes, require CAPTCHA again before another send

// ── Step 1 brute-force / enumeration protection ─────────────────────────────
// Two different attackers to defend against here, needing two different
// counters:
//
//  1. Someone guessing matric numbers that DON'T belong to any account, to
//     find out which ones do (pure enumeration). There's no user row to
//     attach a per-account counter to for a guess that doesn't match
//     anyone, so this is tracked per-IP instead (fp_lookup_attempts below).
//     This replaces the OLD $_SESSION['pwreset']['matric_attempts'] counter,
//     which was trivially reset by clearing cookies / opening a fresh
//     session — the exact bug Task 10 fixed for login, now fixed here too.
//
//  2. Someone who already knows a REAL matric number and is locked out of
//     *login* (Task 10) for it, trying the "easier" forgot-password entry
//     point instead to route around that lockout. For this case we
//     deliberately reuse users.failed_login_attempts/lockout_until (the
//     exact same columns login checks) rather than a separate counter —
//     a lockout tripped by one flow is honored by both, so switching flows
//     buys an attacker nothing. See fp_step1_matric.php.
//
// Both cases return the SAME generic "contact support" message when locked
// — a message that says "this specific account is locked" (as opposed to
// "too many guesses from you") would itself confirm the matric number is
// real, reopening the exact enumeration channel this is meant to close.

function ensure_fp_lookup_attempts_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS fp_lookup_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL UNIQUE,
            failed_attempts INT NOT NULL DEFAULT 0,
            lockout_until DATETIME NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) { /* table already exists */
    }
}

/** Seconds remaining on an active per-IP lockout for $ip, or 0 if none. */
function fp_lookup_lockout_remaining(string $ip): int
{
    if ($ip === '') {
        return 0;
    }
    ensure_fp_lookup_attempts_table();
    $stmt = db()->prepare('SELECT lockout_until FROM fp_lookup_attempts WHERE ip_address = ? LIMIT 1');
    $stmt->execute([$ip]);
    $until = $stmt->fetchColumn();
    if (!$until) {
        return 0;
    }
    return max(0, strtotime((string) $until) - time());
}

/**
 * Records one failed (no-match) matric lookup from $ip. Same
 * increment-then-lock-at-threshold shape as record_failed_login() in
 * config.php. An UPSERT since there's no pre-existing row for a first-time
 * IP.
 */
function fp_record_lookup_failure(string $ip): void
{
    if ($ip === '') {
        return;
    }
    ensure_fp_lookup_attempts_table();
    $stmt = db()->prepare('SELECT failed_attempts FROM fp_lookup_attempts WHERE ip_address = ?');
    $stmt->execute([$ip]);
    $attempts = $stmt->fetchColumn();

    if ($attempts === false) {
        db()->prepare('INSERT INTO fp_lookup_attempts (ip_address, failed_attempts) VALUES (?, 1)')->execute([$ip]);
        return;
    }

    $attempts = (int) $attempts + 1;
    if ($attempts >= PR_MAX_MATRIC_ATTEMPTS) {
        db()->prepare('UPDATE fp_lookup_attempts SET failed_attempts = 0, lockout_until = ? WHERE ip_address = ?')
            ->execute([date('Y-m-d H:i:s', time() + PR_MATRIC_LOCKOUT_SECONDS), $ip]);
    } else {
        db()->prepare('UPDATE fp_lookup_attempts SET failed_attempts = ? WHERE ip_address = ?')
            ->execute([$attempts, $ip]);
    }
}

/** Clears any per-IP lockout state — called when a lookup from $ip finds a real, unlocked account. */
function fp_clear_lookup_lockout(string $ip): void
{
    if ($ip === '') {
        return;
    }
    ensure_fp_lookup_attempts_table();
    db()->prepare('UPDATE fp_lookup_attempts SET failed_attempts = 0, lockout_until = NULL WHERE ip_address = ?')
        ->execute([$ip]);
}

/**
 * Step 1 — does this matric number belong to a real account?
 * Returns the row (id, full_name, matric_no, email, lockout_until) or null.
 * lockout_until is included so callers can check the shared login-lockout
 * state (see fp_step1_matric.php) without a second query.
 */
function pr_find_user_by_matric(string $matric): ?array
{
    $matric = trim($matric);
    if ($matric === '') {
        return null;
    }
    ensure_login_lockout_columns();
    $stmt = db()->prepare('SELECT id, full_name, matric_no, email, lockout_until FROM users WHERE matric_no = ? LIMIT 1');
    $stmt->execute([$matric]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/**
 * Step 2 — does the submitted email belong to the SAME account as the
 * matric number verified in step 1? Callers must show one identical error
 * for "wrong email" and "no email on file" — never distinguish the two, to
 * avoid leaking which part of a guess was correct.
 */
function pr_email_matches(array $user, string $email): bool
{
    $email = trim($email);
    if ($email === '' || empty($user['email'])) {
        return false;
    }
    return strcasecmp($email, (string) $user['email']) === 0;
}

/** Cryptographically secure 6-digit code, zero-padded. */
function pr_generate_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Hash and store a fresh code for this user. The UNIQUE key on user_id
 * means this upsert atomically replaces any previous row, so there is only
 * ever one active code per account and generating a new one invalidates
 * the old one automatically.
 */
function pr_store_code(int $userId, string $code): void
{
    ensure_password_resets_table();
    $hash      = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + PR_CODE_TTL_SECONDS);
    $stmt = db()->prepare(
        'INSERT INTO password_resets (user_id, code_hash, expires_at, attempts)
         VALUES (?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), expires_at = VALUES(expires_at), attempts = 0, created_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$userId, $hash, $expiresAt]);
}

/**
 * Step 5 — check a submitted code. Returns ['ok' => bool, 'reason' => ...]
 * where reason is one of: ok, none, expired, locked, incorrect.
 * A correct code is deleted immediately (consumed) so it can never be
 * replayed even if the request is somehow resent.
 */
function pr_verify_code(int $userId, string $submitted): array
{
    ensure_password_resets_table();
    $stmt = db()->prepare('SELECT id, code_hash, expires_at, attempts FROM password_resets WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['ok' => false, 'reason' => 'none'];
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return ['ok' => false, 'reason' => 'expired'];
    }
    if ((int) $row['attempts'] >= PR_MAX_CODE_ATTEMPTS) {
        return ['ok' => false, 'reason' => 'locked'];
    }
    if (!password_verify($submitted, (string) $row['code_hash'])) {
        db()->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        $attemptsNow = (int) $row['attempts'] + 1;
        return ['ok' => false, 'reason' => $attemptsNow >= PR_MAX_CODE_ATTEMPTS ? 'locked' : 'incorrect'];
    }

    db()->prepare('DELETE FROM password_resets WHERE id = ?')->execute([$row['id']]);
    return ['ok' => true, 'reason' => 'ok'];
}

function pr_invalidate_code(int $userId): void
{
    ensure_password_resets_table();
    db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
}

/** Server-side mirror of the "Resend disabled until expiry" UI rule. */
function pr_has_active_code(int $userId): bool
{
    return pr_active_code_remaining($userId) > 0;
}

/** Seconds left on the current active code, or 0 if none/expired. */
function pr_active_code_remaining(int $userId): int
{
    ensure_password_resets_table();
    $stmt = db()->prepare('SELECT expires_at FROM password_resets WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 0;
    }
    return max(0, strtotime((string) $row['expires_at']) - time());
}

function pr_update_password(int $userId, string $newPassword): void
{
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $userId]);
}

/** Min 8 chars, 1 upper, 1 lower, 1 number, 1 special — mirrors the JS live-validator. */
function pr_password_meets_requirements(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password) === 1
        && preg_match('/[a-z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1
        && preg_match('/[^A-Za-z0-9]/', $password) === 1;
}

/**
 * Step 3 — verify a Google reCAPTCHA v3 token against Google's siteverify
 * endpoint. Unlike v2's checkbox, v3 runs invisibly and returns a 0.0-1.0
 * bot-likelihood score instead of a pass/fail — anything at or above
 * RECAPTCHA_SCORE_THRESHOLD is accepted. The action name is checked too, so
 * a token generated for a different action on the page can't be replayed
 * here.
 */
function pr_recaptcha_verify(string $token, string $expectedAction = 'password_reset'): bool
{
    if ($token === '') {
        return false;
    }

    $caBundle = '/Applications/XAMPP/xamppfiles/share/curl/curl-ca-bundle.crt';
    $sslOpts  = [
        CURLOPT_SSL_VERIFYPEER => file_exists($caBundle),
        CURLOPT_SSL_VERIFYHOST => file_exists($caBundle) ? 2 : 0,
    ];
    if (file_exists($caBundle)) {
        $sslOpts[CURLOPT_CAINFO] = $caBundle;
    }

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => RECAPTCHA_SECRET_KEY,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]),
    ] + $sslOpts);
    $body = curl_exec($ch);
    curl_close($ch);

    $result = $body ? json_decode($body, true) : null;
    if (empty($result['success'])) {
        return false;
    }
    if (($result['action'] ?? $expectedAction) !== $expectedAction) {
        return false;
    }
    return (float) ($result['score'] ?? 0) >= RECAPTCHA_SCORE_THRESHOLD;
}

/** Branded HTML verification-code email, sent via the shared send_mail() transport. */
function pr_send_code_email(string $toEmail, string $toName, string $code): bool
{
    $body = '
        <p style="color:#1a1a1a; font-size:15px;">Hello,</p>
        <p style="color:#1a1a1a; font-size:15px; line-height:1.6;">A request was made to reset the password for your KWASU LCS account.</p>
        <p style="color:#1a1a1a; font-size:15px; margin-bottom:6px;">Your verification code is:</p>
        <div style="font-size:32px; font-weight:700; letter-spacing:10px; color:#07A701; text-align:center; padding:18px 0; background:#f3fbf2; border-radius:12px; margin:12px 0;">' . h($code) . '</div>
        <p style="color:#666666; font-size:13px;">This code will expire in 2 minutes.</p>
        <p style="color:#666666; font-size:13px; border-top:1px solid #eeeeee; padding-top:14px; margin-top:18px;">If you did not request this password reset, please ignore this email — your account is still secure.</p>
        <p style="color:#1a1a1a; font-size:15px; margin-top:22px;">Regards,<br><strong>KWASU LCS Technical Team</strong></p>';
    return send_mail($toEmail, $toName, 'KWASU LCS Password Reset Verification Code', kwasu_branded_email($body));
}
