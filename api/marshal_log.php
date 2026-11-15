<?php
// api/marshal_log.php - marshal logs an official departure
require_once '../includes/functions.php';
requirePost();
requireRole(['marshal', 'admin']);

list($route, $stageIndex) = readRouteAndStage($pdo, $_POST);
$result = saveReport($pdo, $route, $stageIndex, 'marshal', null);

sendJson(['ok' => true, 'status' => $result['status'], 'reason' => $result['reason']]);
