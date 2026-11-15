<?php
// api/routes.php - list of routes with their stages (used to fill the dropdowns)
require_once '../includes/functions.php';

$routes = loadAllRoutes($pdo);
$out = [];
foreach ($routes as $number => $r) {
    $out[(string)$number] = [
        'id' => $r['id'], 'name' => $r['name'],
        'stages' => $r['stages'], 'baseline' => array_values($r['baseline'])
    ];
}
sendJson(['ok' => true, 'routes' => (object)$out]);
