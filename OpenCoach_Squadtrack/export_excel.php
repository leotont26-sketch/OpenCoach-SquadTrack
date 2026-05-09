<?php
declare(strict_types=1);
require_once __DIR__ . '/export_helpers.php';

$ctx = st_export_context($_GET);
$filters = $ctx['filters'];
$rows = $ctx['rows'];
$settings = function_exists('app_settings') ? app_settings() : [];
$clubName = trim((string)($settings['club_name'] ?? 'SquadTrack'));
$youthName = trim((string)($settings['youth_name'] ?? ''));

function st_excel_cols(array $filters): array {
    $eventType = (string)($filters['event_type'] ?? 'all');
    $holidayMode = (string)($filters['holiday_mode'] ?? 'include');
    $cols = [];

    if ($eventType !== 'match' && $holidayMode !== 'exclude') {
        $cols[] = ['key' => 'holiday', 'label' => 'Ferien'];
    }
    if ($eventType !== 'match' && $holidayMode !== 'only') {
        $cols[] = ['key' => 'training_regular', 'label' => 'Training o.F.'];
    }
    if ($eventType !== 'training' && $holidayMode !== 'only') {
        $cols[] = ['key' => 'match', 'label' => 'Spieltage'];
    }
    $cols[] = ['key' => 'total', 'label' => 'Gesamt'];
    return $cols;
}

function st_excel_percent_class(int $percent, int $total): string {
    if ($total <= 0) return 'pct-neutral';
    if ($percent < 50) return 'pct-red';
    if ($percent <= 75) return 'pct-yellow';
    return 'pct-green';
}

function st_excel_fairness_class(string $value): string {
    $v = mb_strtolower($value);
    if (str_contains($v, 'ausgeglichen') || str_contains($v, 'stark')) return 'fair-good';
    if (str_contains($v, 'auff')) return 'fair-warn';
    if (str_contains($v, 'frag') || str_contains($v, 'krit')) return 'fair-bad';
    return 'fair-neutral';
}

$metricCols = st_excel_cols($filters);
$fairnessActive = !empty($filters['fairness']);
$filename = 'squadtrack_export_' . date('Y-m-d_H-i') . '.xls';

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

echo "\xEF\xBB\xBF";
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
body{font-family:Arial,sans-serif;font-size:11pt;color:#0f172a;margin:0;padding:0;}
table{border-collapse:collapse;}
.title{font-size:20pt;font-weight:700;color:#021B33;margin-bottom:4px;}
.subtitle{font-size:12pt;color:#334155;margin-bottom:16px;}
.meta td{border:0;padding:3px 10px 3px 0;font-size:10pt;}
.meta .label{font-weight:700;color:#021B33;}
.data th{background:#021B33;color:#fff;font-weight:700;border:1px solid #9fb3c8;padding:6px 8px;text-align:left;}
.data td{border:1px solid #cbd5e1;padding:6px 8px;vertical-align:middle;}
.data tr:nth-child(even) td{background:#f8fafc;}
.textcell{mso-number-format:"\@";text-align:right;}
.name{font-weight:700;text-align:left;}
.pct-red{background:#f8c7c7;color:#7f1d1d;font-weight:700;text-align:right;}
.pct-yellow{background:#fff0b8;color:#854d0e;font-weight:700;text-align:right;}
.pct-green{background:#c9f2d8;color:#14532d;font-weight:700;text-align:right;}
.pct-neutral{background:#eef2f7;color:#475569;font-weight:700;text-align:right;}
.fair-good{background:#d9f7e6;color:#047857;font-weight:700;}
.fair-warn{background:#ffe5c2;color:#b45309;font-weight:700;}
.fair-bad{background:#ffd6d6;color:#b91c1c;font-weight:700;}
.fair-neutral{background:#eef2f7;color:#334155;font-weight:700;}
.note{white-space:normal;}
</style>
</head>
<body>
<table width="100%">
<tr><td class="title" colspan="20"><?= st_h($clubName !== '' ? $clubName : 'SquadTrack') ?></td></tr>
<tr><td class="subtitle" colspan="20"><?= st_h(($youthName !== '' ? $youthName . ' · ' : '') . 'SquadTrack Exportbericht') ?></td></tr>
</table>
<table class="meta">
<tr><td class="label">Zeitraum:</td><td><?= st_h(st_period_label($filters)) ?></td></tr>
<tr><td class="label">Modus:</td><td><?= st_h(st_event_type_label($filters['event_type'])) ?></td></tr>
<tr><td class="label">Ferien:</td><td><?= st_h(st_holiday_label($filters['holiday_mode'])) ?></td></tr>
<tr><td class="label">Fairness:</td><td><?= $fairnessActive ? 'Ja' : 'Nein' ?></td></tr>
</table>
<br>
<table class="data">
<thead>
<tr>
<th>Spieler</th>
<?php foreach ($metricCols as $metric): ?>
<th><?= st_h($metric['label']) ?></th>
<th><?= st_h($metric['label']) ?> %</th>
<?php endforeach; ?>
<?php if ($fairnessActive): ?>
<th>Fairness</th>
<th>Fairness Hinweis</th>
<?php endif; ?>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr>
<td class="name"><?= st_h($row['name']) ?></td>
<?php foreach ($metricCols as $metric):
    $bucket = $row[$metric['key']] ?? ['text' => '0/0', 'percent' => 0, 'total' => 0];
    $percent = (int)($bucket['percent'] ?? 0);
    $total = (int)($bucket['total'] ?? 0);
?>
<td class="textcell"><?= st_h((string)($bucket['text'] ?? '0/0')) ?></td>
<td class="<?= st_excel_percent_class($percent, $total) ?>"><?= $percent ?>%</td>
<?php endforeach; ?>
<?php if ($fairnessActive):
    $fairShort = (string)($row['fairness']['short'] ?? '');
    $fairText = (string)($row['fairness']['text'] ?? '');
?>
<td class="<?= st_excel_fairness_class($fairShort) ?>"><?= st_h($fairShort) ?></td>
<td class="note"><?= st_h($fairText) ?></td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
