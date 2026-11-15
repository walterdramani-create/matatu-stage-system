<?php
// api/profile.php - logged-in user changes username and/or password
require_once '../includes/functions.php';
requirePost();
$user = requireRole(['marshal', 'admin']);

$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$current = isset($_POST['current']) ? $_POST['current'] : '';
$newPass = isset($_POST['newpass']) ? $_POST['newpass'] : '';

if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $name)) {
    sendJson(['ok' => false, 'error' => 'Username must be 3-50 letters, numbers, dot, dash or underscore.'], 400);
}
if ($newPass !== '' && strlen($newPass) < 8) {
    sendJson(['ok' => false, 'error' => 'The new password must be at least 8 characters.'], 400);
}

// the current password must be right before anything changes
$stmt = $pdo->prepare('SELECT password_hash FROM user_account WHERE user_id = ?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();
if (!$row || !password_verify($current, $row['password_hash'])) {
    sendJson(['ok' => false, 'error' => 'Your current password is not correct.'], 403);
}

// username must not belong to someone else
$stmt = $pdo->prepare('SELECT user_id FROM user_account WHERE username = ? AND user_id <> ?');
$stmt->execute([$name, $user['id']]);
if ($stmt->fetch()) {
    sendJson(['ok' => false, 'error' => 'That username is already taken.'], 409);
}

if ($newPass !== '') {
    $stmt = $pdo->prepare('UPDATE user_account SET username = ?, password_hash = ? WHERE user_id = ?');
    $stmt->execute([$name, password_hash($newPass, PASSWORD_DEFAULT), $user['id']]);
} else {
    $stmt = $pdo->prepare('UPDATE user_account SET username = ? WHERE user_id = ?');
    $stmt->execute([$name, $user['id']]);
}
$_SESSION['user']['username'] = $name;

sendJson(['ok' => true]);
