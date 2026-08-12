<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/password_reset.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit();
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$matric = trim((string) ($body['matric_no'] ?? ''));

if ($matric === '') {
    echo json_encode(['ok' => false, 'message' => 'Enter your matric number.']);
    exit();
}

// DB-backed, not session-based — a session/cookie reset must not clear this
// (see the comment block above ensure_fp_lookup_attempts_table() in
// includes/password_reset.php for the full reasoning and the two counters
// involved).
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

// Pure-enumeration guard: too many matric numbers from this IP that didn't
// match ANY account. Checked first since it's the cheapest reject and
// doesn't require a DB lookup on $matric at all.
if (fp_lookup_lockout_remaining($ip) > 0) {
    echo json_encode([
        'ok'      => false,
        'locked'  => true,
        'message' => 'Still having trouble? Contact Technical Support for assistance.',
    ]);
    exit();
}

$user = pr_find_user_by_matric($matric);

if (!$user) {
    fp_record_lookup_failure($ip);
    $locked = fp_lookup_lockout_remaining($ip) > 0;
    echo json_encode([
        'ok'      => false,
        'locked'  => $locked,
        'message' => $locked
            ? 'Still having trouble? Contact Technical Support for assistance.'
            : "We couldn't verify your account information. Please check your matric number and try again.",
    ]);
    exit();
}

// Route-around-the-other-flow guard: this IS a real account, but if it's
// currently locked out from Task 10's login rate limiting, forgot-password
// must not offer an alternate way in — same shared users.lockout_until
// login itself checks. Deliberately reusing the generic "contact support"
// copy here too, NOT a distinct "this account is locked" message — that
// would confirm the matric number is real to whoever's asking, which is
// exactly the enumeration signal the block above is trying to avoid.
ensure_login_lockout_columns();
if (login_lockout_remaining($user) > 0) {
    echo json_encode([
        'ok'      => false,
        'locked'  => true,
        'message' => 'Still having trouble? Contact Technical Support for assistance.',
    ]);
    exit();
}

// Fresh, correctly-matched, unlocked matric number — start a clean wizard
// session and clear this IP's lookup-failure count (a genuine match is
// evidence this isn't an enumeration script).
fp_clear_lookup_lockout($ip);
$_SESSION['pwreset'] = [
    'user_id'   => (int) $user['id'],
    'matric_no' => $user['matric_no'],
];

echo json_encode(['ok' => true]);
