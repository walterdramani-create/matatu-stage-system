<?php
// api/upload.php - admin uploads a CSV of past departures
// CSV columns:  route_number, stage_name, reported_timestamp   (e.g. 33,Quarry,2026-10-05 08:15:00)
// XLSX needs an extra library (PhpSpreadsheet), so only CSV is supported for now.
require_once '../includes/functions.php';
requirePost();
requireRole(['admin']);

if (!isset($_FILES['report_file']) || $_FILES['report_file']['error'] !== UPLOAD_ERR_OK) {
    sendJson(['ok' => false, 'error' => 'Please choose a file.'], 400);
}
$file = $_FILES['report_file'];

if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
    sendJson(['ok' => false, 'error' => 'Only CSV files are supported for now.'], 400);
}
if ($file['size'] > 2 * 1024 * 1024) {
    sendJson(['ok' => false, 'error' => 'The file is too big (maximum 2 MB).'], 400);
}

// look-up tables: route number -> route, stage name -> index
$routes = loadAllRoutes($pdo);

$handle = fopen($file['tmp_name'], 'r');
$stmt = $pdo->prepare('INSERT INTO departure_report
    (route_id, stage_id, reported_timestamp, validation_status, source) VALUES (?, ?, ?, "valid", "upload")');

$added = 0;
$skipped = 0;
$problems = [];
$line = 0;

$pdo->beginTransaction();
while (($row = fgetcsv($handle)) !== false) {
    $line++;
    if (count($row) < 3) { $skipped++; continue; }
    if ($line == 1 && strtolower(trim($row[0])) == 'route_number') { continue; }   // header row
    if ($line > 5000) { $problems[] = 'Only the first 5000 rows were read.'; break; }

    $number = trim($row[0]);
    $stageName = trim($row[1]);
    $time = trim($row[2]);

    $when = DateTime::createFromFormat('Y-m-d H:i:s', $time);
    $stageIndex = false;
    if (isset($routes[$number])) {
        $stageIndex = array_search(strtolower($stageName), array_map('strtolower', $routes[$number]['stages']));
    }

    if (!$when || $when->format('Y-m-d H:i:s') !== $time || $when->getTimestamp() > time()) {
        $skipped++;
        if (count($problems) < 5) { $problems[] = "Line $line: bad date/time."; }
    } elseif ($stageIndex === false) {
        $skipped++;
        if (count($problems) < 5) { $problems[] = "Line $line: unknown route or stage."; }
    } else {
        $stmt->execute([$routes[$number]['id'], $routes[$number]['stageIds'][$stageIndex], $time]);
        $added++;
    }
}
$pdo->commit();
fclose($handle);

sendJson(['ok' => true, 'added' => $added, 'skipped' => $skipped, 'problems' => $problems]);
