<?php
// api/admin_routes.php - everything the "Manage routes" page needs
require_once '../includes/functions.php';
requireRole(['admin']);

$routes = $pdo->query('SELECT route_id, route_number, route_name, route_details FROM route ORDER BY route_id')->fetchAll();

$stmt = $pdo->prepare('SELECT s.stage_id, s.stage_name, s.stage_sequence,
        t.baseline_travel_time AS baseline, t.threshold_limit AS lim,
        (SELECT COUNT(*) FROM departure_report d WHERE d.stage_id = s.stage_id) AS reports
    FROM stage s
    LEFT JOIN travel_threshold t ON t.from_stage_id = s.stage_id
    WHERE s.route_id = ?
    ORDER BY s.stage_sequence');

$out = [];
foreach ($routes as $r) {
    $stmt->execute([$r['route_id']]);
    $stages = [];
    foreach ($stmt->fetchAll() as $s) {
        $stages[] = [
            'id' => (int)$s['stage_id'], 'name' => $s['stage_name'], 'sequence' => (int)$s['stage_sequence'],
            'baseline' => ($s['baseline'] === null) ? null : (float)$s['baseline'],
            'limit' => ($s['lim'] === null) ? null : (float)$s['lim'],
            'reports' => (int)$s['reports']
        ];
    }
    $out[] = [
        'id' => (int)$r['route_id'], 'number' => $r['route_number'], 'name' => $r['route_name'],
        'details' => $r['route_details'], 'stages' => $stages
    ];
}
sendJson(['ok' => true, 'routes' => $out]);
