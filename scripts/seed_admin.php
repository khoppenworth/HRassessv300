<?php
require_once __DIR__ . '/../config.php';

$password = bin2hex(random_bytes(8));
$hash = password_hash($password, PASSWORD_DEFAULT);
$username = 'admin';
$fullName = 'System Admin';
$email = 'admin@example.com';

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        $update = $pdo->prepare('UPDATE users SET password = ?, role = "admin", full_name = ?, email = ?, profile_completed = 1, must_reset_password = 1, account_status = ? WHERE id = ?');
        $update->execute([$hash, $fullName, $email, 'active', $existing]);
        $userId = (int)$existing;
    } else {
        $insert = $pdo->prepare('INSERT INTO users (username, password, role, full_name, email, account_status, profile_completed, must_reset_password) VALUES (?,?,?,?,?,?,1,1)');
        $insert->execute([$username, $hash, 'admin', $fullName, $email, 'active']);
        $userId = (int)$pdo->lastInsertId();
    }
    $pdo->commit();
    $message = sprintf("Seeded admin user (ID: %d) with username '%s' and password '%s'", $userId, $username, $password);
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $message . PHP_EOL);
    } else {
        header('Content-Type: text/plain');
        echo $message;
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    $error = 'Failed to seed admin user: ' . $e->getMessage();
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $error . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo $error;
}
