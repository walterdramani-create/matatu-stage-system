<?php
// api/login.php - marshal / admin login
require_once '../includes/functions.php';
requirePost();

// lock for one minute after 5 wrong tries
if (isset($_SESSION['lock_until']) && time() < $_SESSION['lock_until']) {
    sendJson(['ok' => false, 'error' => 'Too many tries. Wait a minute and try again.'], 429);
}

$username = isset($_POST['username']) ? trim($_POST['username']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';
if ($username === '' || $password === '') {
    sendJson(['ok' => false, 'error' => 'Please enter your username and password.'], 400);
}

$stmt = $pdo->prepare('SELECT user_id, username, password_hash, role FROM user_account WHERE username = ?');
$stmt->execute([$username]);
$user = $stmt->fetch();

// a fake hash is checked when the user does not exist, so both cases take the same time
$hash = $user ? $user['password_hash'] : '$2y$10$abcdefghijklmnopqrstuuMxqV3CZqz5Z8Xk3e8hQy1kq6xQn9v2e';

if ($user && password_verify($password, $hash)) {
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$user['user_id'], 'username' => $user['username'], 'role' => $user['role']
    ];
    unset($_SESSION['fails'], $_SESSION['lock_until']);

    $go = ($user['role'] === 'admin') ? 'analytics.html' : 'marshal.html';
    sendJson(['ok' => true, 'role' => $user['role'], 'redirect' => $go]);
}

// wrong login
$_SESSION['fails'] = (isset($_SESSION['fails']) ? $_SESSION['fails'] : 0) + 1;
if ($_SESSION['fails'] >= 5) {
    $_SESSION['lock_until'] = time() + 60;
    $_SESSION['fails'] = 0;
}
usleep(500000);   // half a second pause slows down guessing
sendJson(['ok' => false, 'error' => 'Wrong username or password.'], 401);
