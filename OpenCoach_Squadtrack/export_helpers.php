<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/fairness.php';

function st_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function st_player_name(array $p): string {
    return trim((string)($p['first_name'] ?? '') . ' ' . (string)($p['last_name'] ?? ''));
}

function st_event_type(string $type): string {
    $type = strtolower(trim($type));
    return in_array($type, ['match','spiel','spieltag','game','turnier'], true) ? 'match' : 'training';
}

function st_event_type_label(string $mode): string {
    return match ($mode) {
        'training' => 'Nur Training',
        'match' => 'Nur Spieltage',
        default => 'Training + Spieltage',
    };
}

function st_holiday_label(string $mode): string {
    return match ($mode) {
        'exclude' => 'Ferien ausgeschlossen',
        'only' => 'Nur Ferien-Training',
        default => 'Ferien einbezogen',
    };
}

function st_normalize_export_filters(array $input, array $players): array {
    $from = trim((string)($input['from'] ?? ''));
    $to = trim((string)($input['to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = '';
    if ($from !== '' && $to !== '' && $from > $to) { $tmp = $from; $from = $to; $to = $tmp; }

    $eventType = (string)($input['event_type'] ?? 'all');
    if (!in_array($eventType, ['all','training','match'], true)) $eventType = 'all';

    $holidayMode = (string)($input['holiday_mode'] ?? 'include');
    if (!in_array($holidayMode, ['include','exclude','only'], true)) $holidayMode = 'include';

    $playerIds = [];
    $rawIds = $input['player_ids'] ?? [];
    if (!is_array($rawIds)) $rawIds = [$rawIds];
    foreach ($rawIds as $rawId) {
        $id = (int)$rawId;
        if ($id > 0) $playerIds[] = $id;
    }
    $available = array_map(fn($p) => (int)($p['id'] ?? 0), $players);
    $playerIds = array_values(array_unique(array_intersect($playerIds, $available)));

    $fairness = isset($input['fairness']) && (string)$input['fairness'] === '1';

    return [
        'from' => $from,
        'to' => $to,
        'event_type' => $eventType,
        'holiday_mode' => $holidayMode,
        'player_ids' => $playerIds,
        'fairness' => $fairness,
    ];
}

function st_export_filter_events(array $events, array $filters): array {
    return array_values(array_filter($events, function($e) use ($filters) {
        $date = (string)($e['date'] ?? '');
        if ($date === '') return false;
        if ($filters['from'] !== '' && $date < $filters['from']) return false;
        if ($filters['to'] !== '' && $date > $filters['to']) return false;

        $type = st_event_type((string)($e['type'] ?? 'training'));
        $isHoliday = !empty($e['is_holiday']);

        if ($filters['event_type'] === 'training' && $type !== 'training') return false;
        if ($filters['event_type'] === 'match' && $type !== 'match') return false;

        if ($filters['holiday_mode'] === 'exclude' && $type === 'training' && $isHoliday) return false;
        if ($filters['holiday_mode'] === 'only' && !($type === 'training' && $isHoliday)) return false;

        return true;
    }));
}

function st_attendance_index(array $attendance): array {
    $map = [];
    foreach ($attendance as $a) {
        $eid = (int)($a['event_id'] ?? 0);
        $pid = (int)($a['player_id'] ?? 0);
        if ($eid > 0 && $pid > 0) $map[$eid][$pid] = (string)($a['status'] ?? '');
    }
    return $map;
}

function st_bucket_empty(): array {
    return ['present'=>0,'total'=>0,'percent'=>0,'text'=>'0/0'];
}

function st_bucket_add(array &$bucket, bool $present): void {
    $bucket['total']++;
    if ($present) $bucket['present']++;
}

function st_bucket_finalize(array $b): array {
    $b['percent'] = $b['total'] > 0 ? (int)round(100 * $b['present'] / $b['total']) : 0;
    $b['text'] = (int)$b['present'] . '/' . (int)$b['total'];
    return $b;
}

function st_build_export_rows(array $players, array $events, array $attendance, array $filters): array {
    $selected = $filters['player_ids'];
    $players = array_values(array_filter($players, function($p) use ($selected) {
        if (empty($selected)) return true;
        return in_array((int)($p['id'] ?? 0), $selected, true);
    }));
    usort($players, fn($a,$b) => strcasecmp(st_player_name($a), st_player_name($b)));

    $events = st_export_filter_events($events, $filters);
    $eventById = [];
    foreach ($events as $e) $eventById[(int)($e['id'] ?? 0)] = $e;
    $attIndex = st_attendance_index($attendance);

    $rows = [];
    foreach ($players as $p) {
        $pid = (int)($p['id'] ?? 0);
        $buckets = [
            // Klar getrennt: Ferien-Training, normales Training, Spieltage und Gesamt.
            // 'training_all' bleibt bewusst als Alias für ältere Exporte bestehen,
            // wird aber in der PDF nicht mehr als eigene Spalte verwendet.
            'holiday' => st_bucket_empty(),
            'training_regular' => st_bucket_empty(),
            'training_all' => st_bucket_empty(),
            'match' => st_bucket_empty(),
            'total' => st_bucket_empty(),
        ];
        foreach ($eventById as $eid => $e) {
            if (!isset($attIndex[$eid][$pid])) continue;
            $present = $attIndex[$eid][$pid] === 'present';
            $type = st_event_type((string)($e['type'] ?? 'training'));
            $isHoliday = !empty($e['is_holiday']);

            st_bucket_add($buckets['total'], $present);

            if ($type === 'training') {
                st_bucket_add($buckets['training_all'], $present);
                if ($isHoliday) {
                    st_bucket_add($buckets['holiday'], $present);
                } else {
                    st_bucket_add($buckets['training_regular'], $present);
                }
            } else {
                st_bucket_add($buckets['match'], $present);
            }
        }
        foreach ($buckets as $key => $bucket) $buckets[$key] = st_bucket_finalize($bucket);
        $fairness = squadtrack_calculate_fairness(
            (int)$buckets['training_regular']['percent'],
            (int)$buckets['match']['percent'],
            (int)$buckets['training_regular']['total'],
            (int)$buckets['match']['total']
        );
        $rows[] = [
            'id' => $pid,
            'name' => st_player_name($p),
            'holiday' => $buckets['holiday'],
            'training_regular' => $buckets['training_regular'],
            'training_all' => $buckets['training_all'],
            'match' => $buckets['match'],
            'total' => $buckets['total'],
            'fairness' => $fairness,
        ];
    }
    return $rows;
}

function st_export_context(array $input): array {
    require_login();
    $players = read_json('players', []);
    $events = read_json('events', []);
    $attendance = read_json('attendance', []);
    $filters = st_normalize_export_filters($input, $players);
    $rows = st_build_export_rows($players, $events, $attendance, $filters);
    return ['players'=>$players, 'events'=>$events, 'attendance'=>$attendance, 'filters'=>$filters, 'rows'=>$rows];
}

function st_period_label(array $filters): string {
    $from = $filters['from'] !== '' ? $filters['from'] : 'Anfang';
    $to = $filters['to'] !== '' ? $filters['to'] : 'Heute';
    return $from . ' bis ' . $to;
}
