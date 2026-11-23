<?php
// api/route_save.php - admin adds a new route, or edits an existing one (when route_id is sent)
require_once '../includes/functions.php';
requirePost();
requireRole(['admin']);

$id = isset($_POST['route_id']) ? $_POST['route_id'] : '';
$number = readText(isset($_POST['number']) ? $_POST['number'] : '', 'Route number', 10);
$name = readText(isset($_POST['name']) ? $_POST['name'] : '', 'Route name', 100);
$details = readText(isset($_POST['details']) ? $_POST['details'] : '', 'Details', 500, false);

if (!preg_match('/^[A-Za-z0-9-]+$/', $number)) {
    sendJson(['ok' => false, 'error' => 'Route number can only have letters, numbers and dashes.'], 400);
}

// the route number must be unique
$ignore = ctype_digit((string)$id) ? (int)$id : 0;
$stmt = $pdo->prepare('SELECT COUNT(*) FROM route WHERE route_number = ? AND route_id <> ?');
$stmt->execute([$number, $ignore]);
if ($stmt->fetchColumn() > 0) {
    sendJson(['ok' => false, 'error' => 'Another route already uses that number.'], 409);
}

if ($id === '') {
    $stmt = $pdo->prepare('INSERT INTO route (route_number, route_name, route_details) VALUES (?, ?, ?)');
    $stmt->execute([$number, $name, $details]);
    sendJson(['ok' => true, 'route_id' => (int)$pdo->lastInsertId()]);
}

if (!ctype_digit((string)$id)) {
    sendJson(['ok' => false, 'error' => 'Bad route id.'], 400);
}
$stmt = $pdo->prepare('UPDATE route SET route_number = ?, route_name = ?, route_details = ? WHERE route_id = ?');
$stmt->execute([$number, $name, $details, $id]);
sendJson(['ok' => true, 'route_id' => (int)$id]);
