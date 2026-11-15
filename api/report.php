<?php
// api/report.php - a commuter reports that a matatu left their stage
require_once '../includes/functions.php';
requirePost();

// small delay between reports from the same browser (stops spam clicking)
if (isset($_SESSION['last_report']) && time() - $_SESSION['last_report'] < 10) {
    sendJson(['ok' => false, 'error' => 'Please wait a few seconds before reporting again.'], 429);
}

list($route, $stageIndex) = readRouteAndStage($pdo, $_POST);
$result = saveReport($pdo, $route, $stageIndex, 'commuter', anonymousToken());
$_SESSION['last_report'] = time();

sendJson(['ok' => true, 'status' => $result['status'], 'reason' => $result['reason']]);
