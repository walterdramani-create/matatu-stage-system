<?php
// api/me.php - who is logged in? (pages use this to protect themselves)
require_once '../includes/functions.php';

$user = currentUser();
if ($user === null) {
    sendJson(['ok' => true, 'loggedIn' => false]);
}
sendJson(['ok' => true, 'loggedIn' => true, 'role' => $user['role'], 'username' => $user['username']]);
