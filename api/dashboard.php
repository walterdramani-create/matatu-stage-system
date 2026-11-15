<?php
// api/dashboard.php?route=33&stage=3
// Everything the commuter page needs: ETA, stage list, queue, recent departures
require_once '../includes/functions.php';

list($route, $stageIndex) = readRouteAndStage($pdo, $_GET);

// ETA (not for the first stage, matatus start there)
$eta = null;
if ($stageIndex > 0) {
    $eta = calculateEta($pdo, $route, $stageIndex);
}

// status of every stage: passed / arrived / next / upcoming
$latest = latestReports($pdo, $route);
$lastSeen = -1;
foreach ($latest as $i => $secs) {
    if ($i > $lastSeen) { $lastSeen = $i; }
}
$stages = [];
foreach ($route['stages'] as $i => $name) {
    if ($i < $lastSeen) { $status = 'passed'; }
    elseif ($i == $lastSeen) { $status = 'arrived'; }
    elseif ($i == $lastSeen + 1) { $status = 'next'; }
    else { $status = 'upcoming'; }
    $stages[] = ['name' => $name, 'status' => $status];
}

// newest queue number for this stage
$stmt = $pdo->prepare('SELECT queue_count, TIMESTAMPDIFF(MINUTE, updated_timestamp, NOW()) AS ago
    FROM queue_record WHERE stage_id = ? ORDER BY updated_timestamp DESC, queue_id DESC LIMIT 1');
$stmt->execute([$route['stageIds'][$stageIndex]]);
$queue = $stmt->fetch();
if (!$queue) { $queue = null; }

// last 5 valid departures on this route
$stmt = $pdo->prepare('SELECT s.stage_name AS stage, DATE_FORMAT(d.reported_timestamp, "%H:%i") AS time, d.source
    FROM departure_report d JOIN stage s ON s.stage_id = d.stage_id
    WHERE d.route_id = ? AND d.validation_status = "valid" AND d.reported_timestamp <= NOW()
    ORDER BY d.reported_timestamp DESC LIMIT 5');
$stmt->execute([$route['id']]);
$history = $stmt->fetchAll();

sendJson([
    'ok' => true, 'route' => $route['number'], 'stageName' => $route['stages'][$stageIndex],
    'firstStage' => ($stageIndex == 0),
    'eta' => $eta, 'stages' => $stages, 'queue' => $queue, 'history' => $history
]);
