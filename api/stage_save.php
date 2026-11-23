<?php
// api/stage_save.php
//   Edit a stage: send stage_id, name, and (if it has a next stage) baseline + limit.
//   Add a stage:  send route_id, name, after ("end" or the stage_id it goes after), baseline, limit
//                 (baseline and limit = travel time from the stage before it to this new stage).
require_once '../includes/functions.php';
requirePost();
requireRole(['admin']);

$name = readText(isset($_POST['name']) ? $_POST['name'] : '', 'Stage name', 100);

// ================= EDIT =================
if (isset($_POST['stage_id']) && $_POST['stage_id'] !== '') {
    $stageId = $_POST['stage_id'];
    if (!ctype_digit((string)$stageId)) { sendJson(['ok' => false, 'error' => 'Bad stage id.'], 400); }

    $stmt = $pdo->prepare('SELECT stage_id, route_id, stage_sequence FROM stage WHERE stage_id = ?');
    $stmt->execute([$stageId]);
    $stage = $stmt->fetch();
    if (!$stage) { sendJson(['ok' => false, 'error' => 'Stage not found.'], 404); }

    if (stageNameTaken($pdo, $stage['route_id'], $name, $stage['stage_id'])) {
        sendJson(['ok' => false, 'error' => 'Another stage on this route already has that name.'], 409);
    }

    // the next stage (if any) needs travel times
    $stmt = $pdo->prepare('SELECT stage_id FROM stage WHERE route_id = ? AND stage_sequence = ?');
    $stmt->execute([$stage['route_id'], $stage['stage_sequence'] + 1]);
    $next = $stmt->fetch();

    $baseline = null;
    $limit = null;
    if ($next) {
        $baseline = readMinutes(isset($_POST['baseline']) ? $_POST['baseline'] : '', 'Minutes to next stage', 240);
        $limit = readMinutes(isset($_POST['limit']) ? $_POST['limit'] : '', 'Longest believable time', 480);
        if ($limit < $baseline) {
            sendJson(['ok' => false, 'error' => 'The longest believable time cannot be less than the normal time.'], 400);
        }
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('UPDATE stage SET stage_name = ? WHERE stage_id = ?');
    $stmt->execute([$name, $stage['stage_id']]);
    if ($next) {
        setThreshold($pdo, $stage['route_id'], $stage['stage_id'], $next['stage_id'], $baseline, $limit);
    }
    $pdo->commit();
    sendJson(['ok' => true]);
}

// ================= ADD =================
$routeId = isset($_POST['route_id']) ? $_POST['route_id'] : '';
if (!ctype_digit((string)$routeId)) { sendJson(['ok' => false, 'error' => 'Choose a route first.'], 400); }

$stmt = $pdo->prepare('SELECT route_id FROM route WHERE route_id = ?');
$stmt->execute([$routeId]);
if (!$stmt->fetch()) { sendJson(['ok' => false, 'error' => 'Route not found.'], 404); }

if (stageNameTaken($pdo, $routeId, $name, 0)) {
    sendJson(['ok' => false, 'error' => 'This route already has a stage with that name.'], 409);
}

$stages = stagesOfRoute($pdo, $routeId);

// work out which stage the new one goes after
$prev = null;
if (count($stages) > 0) {
    $after = isset($_POST['after']) ? $_POST['after'] : 'end';
    if ($after === 'end' || $after === '') {
        $prev = $stages[count($stages) - 1];
    } else {
        foreach ($stages as $s) {
            if ((string)$s['stage_id'] === (string)$after) { $prev = $s; }
        }
        if ($prev === null) { sendJson(['ok' => false, 'error' => 'The stage you chose is not on this route.'], 400); }
    }
}

// the stage that will come after the new one (if the new stage goes in the middle)
$next = null;
if ($prev !== null) {
    foreach ($stages as $s) {
        if ($s['stage_sequence'] == $prev['stage_sequence'] + 1) { $next = $s; }
    }
}

$baseline = null;
$limit = null;
if ($prev !== null) {
    $baseline = readMinutes(isset($_POST['baseline']) ? $_POST['baseline'] : '', 'Minutes from the stage before', 240);
    $limit = readMinutes(isset($_POST['limit']) ? $_POST['limit'] : '', 'Longest believable time', 480);
    if ($limit < $baseline) {
        sendJson(['ok' => false, 'error' => 'The longest believable time cannot be less than the normal time.'], 400);
    }
}

$newSequence = ($prev === null) ? 1 : $prev['stage_sequence'] + 1;

$pdo->beginTransaction();

// move the later stages down one place (highest first, so the sequence numbers never clash)
$stmt = $pdo->prepare('UPDATE stage SET stage_sequence = stage_sequence + 1
    WHERE route_id = ? AND stage_sequence >= ? ORDER BY stage_sequence DESC');
$stmt->execute([$routeId, $newSequence]);

$stmt = $pdo->prepare('INSERT INTO stage (route_id, stage_name, stage_sequence) VALUES (?, ?, ?)');
$stmt->execute([$routeId, $name, $newSequence]);
$newId = (int)$pdo->lastInsertId();

if ($prev !== null) {
    // the old time from the stage before to the stage after is now split in two
    $old = null;
    if ($next) {
        $old = getThreshold($pdo, $prev['stage_id'], $next['stage_id']);
        $stmt = $pdo->prepare('DELETE FROM travel_threshold WHERE from_stage_id = ? AND to_stage_id = ?');
        $stmt->execute([$prev['stage_id'], $next['stage_id']]);
    }
    setThreshold($pdo, $routeId, $prev['stage_id'], $newId, $baseline, $limit);
    if ($next) {
        $nb = $old ? $old['baseline'] : $baseline;
        $nl = $old ? $old['lim'] : $limit;
        setThreshold($pdo, $routeId, $newId, $next['stage_id'], $nb, $nl);
    }
}
$pdo->commit();

sendJson(['ok' => true, 'stage_id' => $newId]);
