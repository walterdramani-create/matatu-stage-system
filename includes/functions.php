<?php
// includes/functions.php - helper functions used by the files in /api
// Every api file starts with:  require_once '../includes/functions.php';

date_default_timezone_set('Africa/Nairobi');
require_once __DIR__ . '/../config/db.php';

// ---- settings (same numbers as the project document) ----
define('DUPLICATE_MINUTES', 3);    // same stage twice within 3 minutes = duplicate
define('OLD_MINUTES', 60);         // reports older than this are not used for ETA
define('MIN_FACTOR', 0.4);         // faster than 40% of the baseline = too short
define('RECENT_GAPS', 5);          // rolling average uses the last 5 travel times

// ---- sessions ----
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

// ---- JSON replies ----
function sendJson($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// if anything unexpected breaks, show a safe message (the real error goes to the log)
function handleError($e) {
    error_log($e->getMessage());
    sendJson(['ok' => false, 'error' => 'Server error. Please try again.'], 500);
}
set_exception_handler('handleError');

function requirePost() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(['ok' => false, 'error' => 'POST only.'], 405);
    }
}

// ---- login checks ----
function currentUser() {
    if (isset($_SESSION['user'])) { return $_SESSION['user']; }
    return null;
}

// $roles is a list like ['admin'] or ['marshal', 'admin']
function requireRole($roles) {
    $user = currentUser();
    if ($user === null) {
        sendJson(['ok' => false, 'loginNeeded' => true, 'error' => 'Please log in.'], 401);
    }
    if (!in_array($user['role'], $roles)) {
        sendJson(['ok' => false, 'error' => 'You are not allowed to do this.'], 403);
    }
    return $user;
}

// ---- routes and stages ----
// Returns one route as an array, or null.  Stage "index" starts at 0 (0 = first stage).
//   stages[i]   = name of stage i
//   stageIds[i] = stage_id in the database
//   baseline[i] = normal minutes from stage i to stage i+1
//   limit[i]    = longest believable minutes from stage i to stage i+1
function loadRoute($pdo, $routeNumber) {
    $stmt = $pdo->prepare('SELECT route_id, route_number, route_name FROM route WHERE route_number = ?');
    $stmt->execute([$routeNumber]);
    $r = $stmt->fetch();
    if (!$r) { return null; }

    $route = [
        'id' => (int)$r['route_id'], 'number' => $r['route_number'], 'name' => $r['route_name'],
        'stages' => [], 'stageIds' => [], 'baseline' => [], 'limit' => []
    ];

    $stmt = $pdo->prepare('SELECT stage_id, stage_name FROM stage WHERE route_id = ? ORDER BY stage_sequence');
    $stmt->execute([$route['id']]);
    $indexOfStage = [];
    foreach ($stmt->fetchAll() as $i => $s) {
        $route['stages'][] = $s['stage_name'];
        $route['stageIds'][] = (int)$s['stage_id'];
        $indexOfStage[$s['stage_id']] = $i;
    }

    // start with defaults, then fill from travel_threshold
    $count = count($route['stages']);
    for ($i = 0; $i < $count - 1; $i++) {
        $route['baseline'][$i] = 10;
        $route['limit'][$i] = 30;
    }
    $stmt = $pdo->prepare('SELECT from_stage_id, baseline_travel_time, threshold_limit FROM travel_threshold WHERE route_id = ?');
    $stmt->execute([$route['id']]);
    foreach ($stmt->fetchAll() as $t) {
        if (isset($indexOfStage[$t['from_stage_id']])) {
            $i = $indexOfStage[$t['from_stage_id']];
            $route['baseline'][$i] = (float)$t['baseline_travel_time'];
            $route['limit'][$i] = (float)$t['threshold_limit'];
        }
    }
    return $route;
}

function loadAllRoutes($pdo) {
    $routes = [];
    $numbers = $pdo->query('SELECT route_number FROM route ORDER BY route_id')->fetchAll();
    foreach ($numbers as $n) {
        $routes[$n['route_number']] = loadRoute($pdo, $n['route_number']);
    }
    return $routes;
}

// ---- reading recent reports ----

// newest valid report for each stage in the last 60 minutes.
// returns [stageIndex => seconds ago]
function latestReports($pdo, $route) {
    $sql = 'SELECT s.stage_sequence AS seq,
                   TIMESTAMPDIFF(SECOND, MAX(d.reported_timestamp), NOW()) AS secs
            FROM departure_report d
            JOIN stage s ON s.stage_id = d.stage_id
            WHERE d.route_id = ? AND d.validation_status = "valid"
              AND d.reported_timestamp <= NOW()
              AND d.reported_timestamp > NOW() - INTERVAL ' . OLD_MINUTES . ' MINUTE
            GROUP BY s.stage_sequence';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$route['id']]);

    $latest = [];
    foreach ($stmt->fetchAll() as $row) {
        $latest[(int)$row['seq'] - 1] = (int)$row['secs'];
    }
    return $latest;
}

// Travel times seen between two stages. A report at stage i is matched with the
// nearest earlier report at stage i-1. Times that are too short or too long are
// thrown away (outlier removal). Returns a list of [segment, minutes, time].
function observedGaps($pdo, $route, $fromTime, $toTime) {
    $sql = 'SELECT s.stage_sequence AS seq, UNIX_TIMESTAMP(d.reported_timestamp) AS t
            FROM departure_report d
            JOIN stage s ON s.stage_id = d.stage_id
            WHERE d.route_id = ? AND d.validation_status = "valid"
              AND d.reported_timestamp >= ? AND d.reported_timestamp <= ?
            ORDER BY d.reported_timestamp';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$route['id'], $fromTime, $toTime]);
    $rows = $stmt->fetchAll();

    $gaps = [];
    foreach ($rows as $r) {
        $index = (int)$r['seq'] - 1;
        if ($index == 0) { continue; }

        // find the nearest earlier report at the stage before
        $before = null;
        foreach ($rows as $p) {
            if ((int)$p['seq'] - 1 == $index - 1 && $p['t'] < $r['t']) {
                if ($before === null || $p['t'] > $before) { $before = $p['t']; }
            }
        }
        if ($before === null) { continue; }

        $minutes = ($r['t'] - $before) / 60;
        $segment = $index - 1;
        if ($minutes >= $route['baseline'][$segment] * MIN_FACTOR && $minutes <= $route['limit'][$segment]) {
            $gaps[] = ['segment' => $segment, 'minutes' => $minutes, 'time' => (int)$r['t']];
        }
    }
    return $gaps;
}

// Minutes for each leg of the route: the rolling average of the last 5 travel
// times seen in the past 3 hours. If there is no data, the baseline is used.
function legMinutes($pdo, $route) {
    $from = date('Y-m-d H:i:s', time() - 3 * 3600);
    $to = date('Y-m-d H:i:s');
    $gaps = observedGaps($pdo, $route, $from, $to);

    $legs = [];
    foreach ($route['baseline'] as $i => $baseline) {
        $times = [];
        foreach ($gaps as $g) {
            if ($g['segment'] == $i) { $times[] = $g['minutes']; }
        }
        $times = array_slice($times, -RECENT_GAPS);     // last 5 only
        if (count($times) > 0) {
            $legs[$i] = array_sum($times) / count($times);
        } else {
            $legs[$i] = $baseline;
        }
    }
    return $legs;
}

// ETA for a stage. Finds the nearest earlier stage that has a recent report,
// adds up the legs from there, and takes away the minutes already passed.
// returns ['minutes', 'from', 'ago'] or null
function calculateEta($pdo, $route, $stageIndex) {
    $latest = latestReports($pdo, $route);
    $legs = legMinutes($pdo, $route);

    for ($j = $stageIndex - 1; $j >= 0; $j--) {
        if (isset($latest[$j])) {
            $travel = 0;
            for ($k = $j; $k < $stageIndex; $k++) { $travel += $legs[$k]; }
            $ago = $latest[$j] / 60;
            return [
                'minutes' => (int)max(0, round($travel - $ago)),
                'from' => $route['stages'][$j],
                'ago' => (int)round($ago)
            ];
        }
    }
    return null;
}

// ---- checking and saving a report ----

// returns [type, reason]. type is '' when the report is fine.
function checkReport($pdo, $route, $stageIndex) {
    // rule 1: same stage reported again within 3 minutes
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM departure_report
        WHERE stage_id = ? AND validation_status = "valid"
          AND reported_timestamp > NOW() - INTERVAL ' . DUPLICATE_MINUTES . ' MINUTE');
    $stmt->execute([$route['stageIds'][$stageIndex]]);
    if ($stmt->fetchColumn() > 0) {
        return ['duplicate', 'Duplicate report within ' . DUPLICATE_MINUTES . ' minutes'];
    }

    // rule 2: the travel time from the stage before must be believable
    if ($stageIndex > 0) {
        $latest = latestReports($pdo, $route);
        $before = $stageIndex - 1;
        if (isset($latest[$before])) {
            $gap = $latest[$before] / 60;
            if ($gap < $route['baseline'][$before] * MIN_FACTOR) {
                return ['outlier', 'Travel time too short'];
            }
            if ($gap > $route['limit'][$before]) {
                return ['outlier', 'Travel time too long'];
            }
        }
    }
    return ['', ''];
}

// saves the report (valid or flagged). returns ['status', 'reason', 'report_id']
function saveReport($pdo, $route, $stageIndex, $source, $token) {
    list($type, $reason) = checkReport($pdo, $route, $stageIndex);
    $status = ($type === '') ? 'valid' : 'flagged';

    $stmt = $pdo->prepare('INSERT INTO departure_report
        (route_id, stage_id, reported_timestamp, validation_status, source, session_token)
        VALUES (?, ?, NOW(), ?, ?, ?)');
    $stmt->execute([$route['id'], $route['stageIds'][$stageIndex], $status, $source, $token]);
    $reportId = (int)$pdo->lastInsertId();

    if ($status === 'flagged') {
        $stmt = $pdo->prepare('INSERT INTO flagged_report (report_id, log_type, details, status, created_timestamp)
            VALUES (?, ?, ?, "open", NOW())');
        $stmt->execute([$reportId, $type, $reason]);
    }
    return ['status' => $status, 'reason' => $reason, 'report_id' => $reportId];
}

// anonymous token for commuters (no name, no phone number)
function anonymousToken() {
    if (!isset($_SESSION['token'])) {
        $_SESSION['token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['token'];
}

// reads route + stage from the form and checks them. returns [route, stageIndex]
function readRouteAndStage($pdo, $source) {
    $number = isset($source['route']) ? trim($source['route']) : '';
    $index = isset($source['stage']) ? $source['stage'] : '';

    $route = loadRoute($pdo, $number);
    if ($route === null || !ctype_digit((string)$index) || (int)$index >= count($route['stages'])) {
        sendJson(['ok' => false, 'error' => 'Unknown route or stage.'], 400);
    }
    return [$route, (int)$index];
}

// ---- helpers for the admin "Manage routes" files ----

// reads a piece of text from the form. Stops with an error if it is empty or too long.
function readText($value, $label, $maxLength, $required = true) {
    $text = trim((string)$value);
    if ($required && $text === '') {
        sendJson(['ok' => false, 'error' => "$label is required."], 400);
    }
    $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);   // works with or without mbstring
    if ($length > $maxLength) {
        sendJson(['ok' => false, 'error' => "$label is too long (maximum $maxLength characters)."], 400);
    }
    return $text;
}

// reads a number of minutes. Stops with an error if it is not a number from 1 to $max.
function readMinutes($value, $label, $max) {
    if (!is_numeric($value) || $value < 1 || $value > $max) {
        sendJson(['ok' => false, 'error' => "$label must be a number from 1 to $max."], 400);
    }
    return round((float)$value, 2);
}

// all stages of a route in order: [stage_id, stage_name, stage_sequence]
function stagesOfRoute($pdo, $routeId) {
    $stmt = $pdo->prepare('SELECT stage_id, stage_name, stage_sequence FROM stage WHERE route_id = ? ORDER BY stage_sequence');
    $stmt->execute([$routeId]);
    return $stmt->fetchAll();
}

// true if another stage on this route already uses the name
function stageNameTaken($pdo, $routeId, $name, $ignoreStageId) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM stage WHERE route_id = ? AND LOWER(stage_name) = LOWER(?) AND stage_id <> ?');
    $stmt->execute([$routeId, $name, $ignoreStageId]);
    return $stmt->fetchColumn() > 0;
}

// saves the travel time between two stages (replaces the old row if there is one)
function setThreshold($pdo, $routeId, $fromId, $toId, $baseline, $limit) {
    $stmt = $pdo->prepare('DELETE FROM travel_threshold WHERE from_stage_id = ? AND to_stage_id = ?');
    $stmt->execute([$fromId, $toId]);
    $stmt = $pdo->prepare('INSERT INTO travel_threshold (route_id, from_stage_id, to_stage_id, baseline_travel_time, threshold_limit)
        VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$routeId, $fromId, $toId, $baseline, $limit]);
}

// the saved travel time between two stages, or null
function getThreshold($pdo, $fromId, $toId) {
    $stmt = $pdo->prepare('SELECT baseline_travel_time AS baseline, threshold_limit AS lim FROM travel_threshold
        WHERE from_stage_id = ? AND to_stage_id = ?');
    $stmt->execute([$fromId, $toId]);
    $row = $stmt->fetch();
    return $row ? $row : null;
}
