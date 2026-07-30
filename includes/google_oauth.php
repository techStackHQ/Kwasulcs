<?php

declare(strict_types=1);

// Reusable Google OAuth logic backing Settings → Connected Accounts
// (google_oauth_start.php / google_oauth_callback.php). Scoped narrowly:
// this only verifies/prefills a user's email and pulls their name + profile
// photo from Google — it never replaces matric+password login.

const GOOGLE_OAUTH_SCOPE = 'openid email profile';

/** True once real credentials have been pasted into config.php. */
function google_oauth_configured(): bool
{
    return GOOGLE_CLIENT_ID !== 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com'
        && GOOGLE_CLIENT_SECRET !== 'YOUR_GOOGLE_CLIENT_SECRET'
        && GOOGLE_CLIENT_ID !== ''
        && GOOGLE_CLIENT_SECRET !== '';
}

/** Builds the URL to send the browser to for the Google consent screen. */
function google_oauth_auth_url(string $state): string
{
    $params = [
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => GOOGLE_OAUTH_SCOPE,
        'state'         => $state,
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/** POST https://oauth2.googleapis.com/token — exchanges the auth code for tokens. */
function google_oauth_exchange_code(string $code): ?array
{
    $payload = [
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'code'          => $code,
        'grant_type'    => 'authorization_code',
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
    ];

    $result = google_oauth_curl_post('https://oauth2.googleapis.com/token', $payload);
    if ($result === null || empty($result['access_token'])) {
        error_log('[LCS] Google OAuth token exchange failed: ' . json_encode($result));
        return null;
    }
    return $result;
}

/** GET https://www.googleapis.com/oauth2/v3/userinfo — the profile the access token belongs to. */
function google_oauth_fetch_userinfo(string $accessToken): ?array
{
    $caBundle = '/Applications/XAMPP/xamppfiles/share/curl/curl-ca-bundle.crt';
    $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_SSL_VERIFYPEER => file_exists($caBundle),
        CURLOPT_SSL_VERIFYHOST => file_exists($caBundle) ? 2 : 0,
        CURLOPT_CAINFO         => file_exists($caBundle) ? $caBundle : null,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code !== 200 || !$body) {
        error_log('[LCS] Google OAuth userinfo fetch failed: HTTP ' . $code . ' ' . $err . ' body=' . $body);
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function google_oauth_disconnect(int $userId): void
{
    ensure_google_oauth_columns();
    db()->prepare('UPDATE users SET google_id = NULL, google_picture = NULL, google_connected_at = NULL WHERE id = ?')
        ->execute([$userId]);
}

/** Shared cURL POST helper (form-encoded) — mirrors config.php's fetch_url() SSL setup. */
function google_oauth_curl_post(string $url, array $fields): ?array
{
    $caBundle = '/Applications/XAMPP/xamppfiles/share/curl/curl-ca-bundle.crt';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => file_exists($caBundle),
        CURLOPT_SSL_VERIFYHOST => file_exists($caBundle) ? 2 : 0,
        CURLOPT_CAINFO         => file_exists($caBundle) ? $caBundle : null,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code !== 200 || !$body) {
        error_log('[LCS] Google OAuth POST to ' . $url . ' failed: HTTP ' . $code . ' ' . $err . ' body=' . $body);
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}
