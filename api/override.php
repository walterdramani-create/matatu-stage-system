<?php
// api/override.php - admin says a flagged report was actually correct
require_once '../includes/functions.php';
requirePost();
$user = requireRole(['admin']);

$id = isset($_POST['report_id']) ? $_POST['report_id'] : '';
if (!ctype_digit((string)$id)) {
    sendJson(['ok' => false, 'error' => 'Bad report id.'], 400);
}

$pdo->beginTransaction();
$stmt = $pdo->prepare('UPDATE departure_report SET validation_status = "valid"
    WHERE report_id = ? AND validation_status = "flagged"');
$stmt->execute([$id]);

if ($stmt->rowCount() == 0) {
    $pdo->rollBack();
    sendJson(['ok' => false, 'error' => 'That report is not flagged.'], 404);
}

$stmt = $pdo->prepare('UPDATE flagged_report SET status = "overridden", user_id = ?
    WHERE report_id = ? AND status = "open"');
$stmt->execute([$user['id'], $id]);
$pdo->commit();

sendJson(['ok' => true]);
