<?php
// api/flagged.php - list of flagged reports waiting for the admin
require_once '../includes/functions.php';
requireRole(['admin']);

$sql = 'SELECT d.report_id, r.route_number, s.stage_name AS stage,
               DATE_FORMAT(d.reported_timestamp, "%d %b %H:%i") AS time, f.details AS reason
        FROM flagged_report f
        JOIN departure_report d ON d.report_id = f.report_id
        JOIN stage s ON s.stage_id = d.stage_id
        JOIN route r ON r.route_id = d.route_id
        WHERE f.status = "open" AND d.validation_status = "flagged"
        ORDER BY d.reported_timestamp DESC LIMIT 100';
sendJson(['ok' => true, 'flagged' => $pdo->query($sql)->fetchAll()]);
