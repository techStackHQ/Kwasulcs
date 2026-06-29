<?php

/**
 * push_subscribe.php
 * Stores or removes a user's push subscription endpoint.
 * Called via fetch() from the browser when user grants push permission.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$user   = current_user();
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? 'subscribe'; // subscribe | unsubscribe

if ($action === 'unsubscribe') {
    try {
        db()->prepare('DELETE FROM push_subscriptions WHERE user_id = ?')->execute([$user['id']]);
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// subscribe
$endpoint = trim($body['endpoint'] ?? '');
$p256dh   = trim($body['keys']['p256dh'] ?? '');
$auth     = trim($body['keys']['auth']   ?? '');

if (!$endpoint) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing endpoint']);
    exit();
}

try {
    // Create table if it doesn't exist yet
    db()->exec('CREATE TABLE IF NOT EXISTS push_subscriptions (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        endpoint   TEXT NOT NULL,
        p256dh     VARCHAR(255) NULL,
        auth_key   VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Upsert: one subscription per user (replace old one)
    db()->prepare('
        INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth_key)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE endpoint=VALUES(endpoint), p256dh=VALUES(p256dh), auth_key=VALUES(auth_key)
    ')->execute([$user['id'], $endpoint, $p256dh, $auth]);

    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
