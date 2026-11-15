<?php
// api/logout.php - ends the session and goes back to the login page
require_once '../includes/functions.php';

$_SESSION = [];
session_destroy();
header('Location: ../login.html');
exit;
