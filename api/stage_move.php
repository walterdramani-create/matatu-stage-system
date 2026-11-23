<?php
// api/stage_move.php - move a stage up or down one place in its route
require_once '../includes/functions.php';
requirePost();
requireRole(['admin']);

$stageId = isset($_POST['stage_id']) ? $_POST['stage_id'] : '';
$direction = isset($_POST['direction']) ? $_POST['direction'] : '';
if (!ctype_digit((string)$stageId) || ($direction !== 'up' && $direction !== 'down')) {
    sendJson(['ok' => false, 'error' => 'Bad request.'], 400);
}

$stmt = $pdo->prepare('SELECT stage_id, route_id, stage_sequence FROM stage WHERE stage_id = ?');
$stmt->execute([$stageId]);
$stage = $stmt->fetch();
if (!$stage) { sendJson(['ok' => false, 'error' => 'Stage not found.'], 404); }

$otherSeq = ($direction === 'up') ? $stage['stage_sequence'] - 1 : $stage['stage_sequence'] + 1;
$stmt = $pdo->prepare('SELECT stage_id, stage_sequence FROM stage WHERE route_id = ? AND stage_sequence = ?');
$stmt->execute([$stage['route_id'], $otherSeq]);
$other = $stmt->fetch();
if (!$other) {
    sendJson(['ok' => false, 'error' => ($direction === 'up') ? 'This is already the first stage.' : 'This is already the last stage.'], 400);
}

$routeId = $stage['route_id'];
$pdo->beginTransaction();

// remember the travel time of every "place" along the route (first to second stage, and so on).
// Moving a stage swaps two stages but the times stay in their places, so moving a stage
// up and then down again puts everything back exactly as it was.
$before = stagesOfRoute($pdo, $routeId);
$times = [];
for ($i = 0; $i < count($before) - 1; $i++) {
    $times[$i] = getThreshold($pdo, $before[$i]['stage_id'], $before[$i + 1]['stage_id']);
}

// swap the two sequence numbers (a temporary 0 stops the unique key from complaining)
$stmt = $pdo->prepare('UPDATE stage SET stage_sequence = ? WHERE stage_id = ?');
$stmt->execute([0, $stage['stage_id']]);
$stmt->execute([$stage['stage_sequence'], $other['stage_id']]);
$stmt->execute([$other['stage_sequence'], $stage['stage_id']]);

// write the times back for the new order
$stmt = $pdo->prepare('DELETE FROM travel_threshold WHERE route_id = ?');
$stmt->execute([$routeId]);
$after = stagesOfRoute($pdo, $routeId);
for ($i = 0; $i < count($after) - 1; $i++) {
    $t = $times[$i] ? $times[$i] : ['baseline' => 10, 'lim' => 30];
    setThreshold($pdo, $routeId, $after[$i]['stage_id'], $after[$i + 1]['stage_id'], $t['baseline'], $t['lim']);
}
$pdo->commit();

sendJson(['ok' => true]);
