<?php
// api/analytics.php?from=2026-11-01&to=2026-11-14&generate=1
// Stats for the admin: average travel time, trips, flagged reports, 6-day chart
require_once '../includes/functions.php';
requireRole(['admin']);

// dates are optional. They must look like YYYY-MM-DD.
function readDate($value) {
    if ($value === null || $value === '') { return null; }
    $d = DateTime::createFromFormat('Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        sendJson(['ok' => false, 'error' => 'Bad date.'], 400);
    }
    return $value;
}
$from = readDate(isset($_GET['from']) ? $_GET['from'] : null);
$to = readDate(isset($_GET['to']) ? $_GET['to'] : null);
if ($from !== null && $to !== null && $from > $to) {
    sendJson(['ok' => false, 'error' => "The 'From' date must be before the 'To' date."], 400);
}
$start = ($from === null) ? '2000-01-01 00:00:00' : $from . ' 00:00:00';
$end = ($to === null) ? date('Y-m-d H:i:s') : $to . ' 23:59:59';

// counts
$stmt = $pdo->prepare('SELECT validation_status, COUNT(*) AS n FROM departure_report
    WHERE reported_timestamp >= ? AND reported_timestamp <= ? GROUP BY validation_status');
$stmt->execute([$start, $end]);
$trips = 0;
$flagged = 0;
foreach ($stmt->fetchAll() as $row) {
    if ($row['validation_status'] == 'valid') { $trips = (int)$row['n']; }
    if ($row['validation_status'] == 'flagged') { $flagged = (int)$row['n']; }
}

// average travel time (all routes) and per route
$routes = loadAllRoutes($pdo);
$total = 0;
$count = 0;
$perRoute = [];
foreach ($routes as $number => $route) {
    $gaps = observedGaps($pdo, $route, $start, $end);
    $sum = 0;
    foreach ($gaps as $g) { $sum += $g['minutes']; }
    $total += $sum;
    $count += count($gaps);
    $perRoute[$number] = ['route' => $route, 'sum' => $sum, 'n' => count($gaps)];
}
$average = ($count > 0) ? (int)round($total / $count) : null;

// chart: average travel time for each of the last 6 days
$chart = [];
for ($i = 5; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $sum = 0;
    $n = 0;
    foreach ($routes as $route) {
        foreach (observedGaps($pdo, $route, $day . ' 00:00:00', $day . ' 23:59:59') as $g) {
            $sum += $g['minutes'];
            $n++;
        }
    }
    $label = ($i == 0) ? 'Today' : date('d M', strtotime($day));
    $chart[] = ['label' => $label, 'value' => ($n > 0) ? (int)round($sum / $n) : null];
}

// pressing "Generate" also saves a summary row for each route (performance_log)
if (isset($_GET['generate']) && $_GET['generate'] == '1') {
    $stmt = $pdo->prepare('INSERT INTO performance_log (route_id, period_start, period_end, delay_metrics, created_timestamp)
        VALUES (?, ?, ?, ?, NOW())');
    foreach ($perRoute as $p) {
        $avg = ($p['n'] > 0) ? round($p['sum'] / $p['n'], 1) : null;
        $stmt->execute([$p['route']['id'], $start, $end, json_encode(['average_minutes' => $avg, 'observations' => $p['n']])]);
    }
}

sendJson(['ok' => true, 'average' => $average, 'trips' => $trips, 'flagged' => $flagged, 'chart' => $chart]);
