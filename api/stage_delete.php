<?php
// api/stage_delete.php - admin deletes a stage that has no reports
require_once '../includes/functions.php';
requirePost();
requireRole(['admin']);

$stageId = isset($_POST['stage_id']) ? $_POST['stage_id'] : '';
if (!ctype_digit((string)$stageId)) { sendJson(['ok' => false, 'error' => 'Bad stage id.'], 400); }

$stmt = $pdo->prepare('SELECT stage_id, route_id, stage_sequence FROM stage WHERE stage_id = ?');
$stmt->execute([$stageId]);
$stage = $stmt->fetch();
if (!$stage) { sendJson(['ok' => false, 'error' => 'Stage not found.'], 404); }

// protect the data: a stage that already has reports cannot be deleted
$stmt = $pdo->prepare('SELECT COUNT(*) FROM departure_report WHERE stage_id = ?');
$stmt->execute([$stageId]);
$reports = (int)$stmt->fetchColumn();
if ($reports > 0) {
    sendJson(['ok' => false, 'error' => "This stage has $reports report(s), so it cannot be deleted. Rename it instead."], 409);
}

$routeId = $stage['route_id'];
$seq = $stage['stage_sequence'];

// the stages on each side and the travel times that will be joined together
$stmt = $pdo->prepare('SELECT stage_id FROM stage WHERE route_id = ? AND stage_sequence = ?');
$stmt->execute([$routeId, $seq - 1]);
$prev = $stmt->fetch();
$stmt->execute([$routeId, $seq + 1]);
$next = $stmt->fetch();

$into = $prev ? getThreshold($pdo, $prev['stage_id'], $stage['stage_id']) : null;
$outof = $next ? getThreshold($pdo, $stage['stage_id'], $next['stage_id']) : null;

$pdo->beginTransaction();

$stmt = $pdo->prepare('DELETE FROM stage WHERE stage_id = ?');   // its travel times go with it
$stmt->execute([$stage['stage_id']]);

$stmt = $pdo->prepare('UPDATE stage SET stage_sequence = stage_sequence - 1
    WHERE route_id = ? AND stage_sequence > ? ORDER BY stage_sequence ASC');
$stmt->execute([$routeId, $seq]);

// the stage before and the stage after are now neighbours: add the two travel times together
if ($prev && $next) {
    $baseline = ($into ? $into['baseline'] : 5) + ($outof ? $outof['baseline'] : 5);
    $limit = ($into ? $into['lim'] : 15) + ($outof ? $outof['lim'] : 15);
    setThreshold($pdo, $routeId, $prev['stage_id'], $next['stage_id'], $baseline, $limit);
}
$pdo->commit();

sendJson(['ok' => true]);
