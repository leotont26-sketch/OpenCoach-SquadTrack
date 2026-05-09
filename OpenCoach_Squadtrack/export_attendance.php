<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();

// 🔐 gleiche Login-Logik wie bei dir
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    exit('Not authorized');
}

// CSV Header
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="attendance_export.csv"');

$out = fopen('php://output', 'w');

// Kopfzeile
fputcsv($out, [
    'event_id',
    'event_date',
    'event_type',
    'event_note',
    'event_is_holiday',
    'player_id',
    'player_name',
    'status'
], ';');

// 🔹 JSON direkt laden (so wie dein Projekt es macht)
$players    = json_decode(file_get_contents(__DIR__ . '/data/players.json'), true);
$events     = json_decode(file_get_contents(__DIR__ . '/data/events.json'), true);
$attendance = json_decode(file_get_contents(__DIR__ . '/data/attendance.json'), true);

// Indexe bauen
$playerIndex = [];
foreach ($players as $p) {
    $playerIndex[$p['id']] = trim($p['first_name'] . ' ' . $p['last_name']);
}

$eventIndex = [];
foreach ($events as $e) {
    $eventIndex[$e['id']] = $e;
}

// Export
foreach ($attendance as $a) {
    $eid = $a['event_id'];
    $pid = $a['player_id'];

    if (!isset($eventIndex[$eid], $playerIndex[$pid])) {
        continue;
    }

    $e = $eventIndex[$eid];

    fputcsv($out, [
        $eid,
        $e['date'],
        $e['type'],
        $e['note'] ?? '',
        !empty($e['is_holiday']) ? '1' : '0',
        $pid,
        $playerIndex[$pid],
        (($a['status'] ?? '') === 'present' ? 'present' : 'absent')
    ], ';');
}

fclose($out);
exit;
