<?php

declare(strict_types=1);

// Reusable, self-contained logic backing the 6-step "Forgot Password" wizard
// (forgot_password.php + the fp_*.php endpoints at the project root). Kept
// separate from config.php so this security-sensitive flow's rules — code
// TTL, attempt limits, hashing — live in one place instead of being
// scattered across each endpoint.

const PR_CODE_TTL_SECONDS     = 120; // exactly 2 minutes, per spec
const PR_MAX_MATRIC_ATTEMPTS  = 3;   // Step 1: after this many misses, show the "contact support" message
const PR_MAX_CODE_ATTEMPTS    = 5;   // Step 5: after this many wrong codes, require CAPTCHA again before another send

/**
 * Step 1 — does this matric number belong to a real account?
 * Returns the row (id, full_name, matric_no, email) or null.
 */
function pr_find_user_by_matric(string $matric): ?array
{
    $matric = trim($matric);
    if ($matric === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT id, full_name, matric_no, email FROM users WHERE matric_no = ? LIMIT 1');
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
 * Self-hosted CAPTCHA (no external service — consistent with this app's
 * no-external-asset rule). Generates a random 5-character challenge string
 * and stashes the expected answer in the session; fp_captcha_image.php
 * renders it as a distorted GD image, pr_captcha_verify() checks answers.
 */
function pr_captcha_new(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I — avoids ambiguous glyphs
    $text = '';
    for ($i = 0; $i < 5; $i++) {
        $text .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $_SESSION['pwreset']['captcha_text'] = $text;
    return $text;
}

/** One-time check — correct or not, the stored answer is cleared so it can't be reused. */
function pr_captcha_verify(string $submitted): bool
{
    $expected = $_SESSION['pwreset']['captcha_text'] ?? null;
    unset($_SESSION['pwreset']['captcha_text']);
    if ($expected === null) {
        return false;
    }
    return strcasecmp(trim($submitted), $expected) === 0;
}

/** Branded HTML verification-code email, sent via the shared send_mail() transport. */
function pr_send_code_email(string $toEmail, string $toName, string $code): bool
{
    $html = '
    <div style="font-family: Arial, Helvetica, sans-serif; max-width: 480px; margin: 0 auto; padding: 32px 24px; background:#ffffff;">
        <div style="text-align:center; margin-bottom: 20px;">
            <img src="cid:kwasu_logo" alt="KWASU LCS" style="height:44px;">
        </div>
        <p style="text-align:center; color:#8a8a8a; font-size:12px; letter-spacing:1px; text-transform:uppercase; margin:0 0 24px;">KWASU Lecture Content System</p>
        <p style="color:#1a1a1a; font-size:15px;">Hello,</p>
        <p style="color:#1a1a1a; font-size:15px; line-height:1.6;">A request was made to reset the password for your KWASU LCS account.</p>
        <p style="color:#1a1a1a; font-size:15px; margin-bottom:6px;">Your verification code is:</p>
        <div style="font-size:32px; font-weight:700; letter-spacing:10px; color:#07A701; text-align:center; padding:18px 0; background:#f3fbf2; border-radius:12px; margin:12px 0;">' . h($code) . '</div>
        <p style="color:#666666; font-size:13px;">This code will expire in 2 minutes.</p>
        <p style="color:#666666; font-size:13px; border-top:1px solid #eeeeee; padding-top:14px; margin-top:18px;">If you did not request this password reset, please ignore this email — your account is still secure.</p>
        <p style="color:#1a1a1a; font-size:15px; margin-top:22px;">Regards,<br><strong>KWASU LCS Technical Team</strong></p>
    </div>';
    return send_mail($toEmail, $toName, 'KWASU LCS Password Reset Verification Code', $html);
}
