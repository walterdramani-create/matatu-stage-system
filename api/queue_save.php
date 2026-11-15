<?php
// api/queue_save.php - marshal saves how many matatus are waiting
require_once '../includes/functions.php';
requirePost();
$user = requireRole(['marshal', 'admin']);

list($route, $stageIndex) = readRouteAndStage($pdo, $_POST);

$count = isset($_POST['count']) ? $_POST['count'] : '';
if (!ctype_digit((string)$count) || (int)$count > 100) {
    sendJson(['ok' => false, 'error' => 'Queue must be a whole number from 0 to 100.'], 400);
}

$stmt = $pdo->prepare('INSERT INTO queue_record (stage_id, queue_count, updated_timestamp, user_id)
    VALUES (?, ?, NOW(), ?)');
$stmt->execute([$route['stageIds'][$stageIndex], (int)$count, $user['id']]);

sendJson(['ok' => true]);
