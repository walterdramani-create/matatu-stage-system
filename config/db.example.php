<?php
// config/db.example.php - COPY this file to config/db.php and put your real details in it.
// XAMPP defaults: user "root", empty password. Change these on a real server.

$host   = 'localhost';
$dbname = 'matatu_stage_db';   // on a live host: the name your host gives you
$dbuser = 'root';             // on a live host: the database user your host gives you
$dbpass = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $dbuser,
        $dbpass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
        ]
    );
    // use Nairobi time for NOW() so it matches PHP's time
    $pdo->exec("SET time_zone = '+03:00'");
} catch (PDOException $e) {
    // Never show the real error to visitors
    error_log($e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}
