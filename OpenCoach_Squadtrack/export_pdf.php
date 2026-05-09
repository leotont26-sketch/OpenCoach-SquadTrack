<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/export_helpers.php';

class SimplePdf {
    private array $pages = [];
    private string $content = '';
    private float $w = 842; // A4 landscape points
    private float $h = 595;
    private int $fontSize = 8;
    private array $images = [];

    public function __construct() { $this->addPage(); }
    public function addPage(): void { if ($this->content !== '') $this->pages[] = $this->content; $this->content = ''; }
    private function enc(string $s): string {
        $s = str_replace(["\r","\n","\t"], ' ', $s);
        $s = iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) ?: $s;
        return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $s);
    }
    public function text(float $x, float $y, string $txt, int $size=8, bool $bold=false, array $rgb=[15,23,42]): void {
        $font = $bold ? 'F2' : 'F1';
        [$r,$g,$b] = $rgb;
        $this->content .= sprintf("%.3f %.3f %.3f rg BT /%s %d Tf %.2f %.2f Td (%s) Tj ET\n", $r/255, $g/255, $b/255, $font, $size, $x, $this->h - $y, $this->enc($txt));
    }
    public function line(float $x1, float $y1, float $x2, float $y2, float $gray=.82): void {
        $this->content .= sprintf("%.3f g %.2f w %.2f %.2f m %.2f %.2f l S\n", $gray, .45, $x1, $this->h-$y1, $x2, $this->h-$y2);
    }
    public function rect(float $x, float $y, float $w, float $h, array $rgb=[0,0,0], bool $fill=true): void {
        [$r,$g,$b] = $rgb;
        $op = $fill ? 'f' : 'S';
        $this->content .= sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re %s\n", $r/255, $g/255, $b/255, $x, $this->h-$y-$h, $w, $h, $op);
    }
    public function polygon(array $points, array $rgb=[0,0,0]): void {
        if (count($points) < 3) return;
        [$r,$g,$b] = $rgb;
        $cmd = sprintf("%.3f %.3f %.3f rg ", $r/255, $g/255, $b/255);
        $first = array_shift($points);
        $cmd .= sprintf("%.2f %.2f m ", $first[0], $this->h - $first[1]);
        foreach ($points as $pt) {
            $cmd .= sprintf("%.2f %.2f l ", $pt[0], $this->h - $pt[1]);
        }
        $this->content .= $cmd . "h f\n";
    }

    public function image(string $path, float $x, float $y, float $w, float $h): bool {
        $img = $this->loadImage($path);
        if ($img === null) return false;
        $key = md5($img['data']);
        if (!isset($this->images[$key])) {
            $this->images[$key] = $img + ['name' => 'Im' . (count($this->images) + 1)];
        }
        $name = $this->images[$key]['name'];
        $this->content .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n", $w, $h, $x, $this->h - $y - $h, $name);
        return true;
    }
    private function loadImage(string $path): ?array {
        // Absichtlich defensiv: Erstmal nur JPG/JPEG direkt einbetten.
        // PNG-Konvertierung über GD ist auf Shared Hostings gern eine kleine Todesfalle.
        if (!is_file($path) || !is_readable($path)) return null;
        $info = @getimagesize($path);
        if (!$info) return null;
        $mime = strtolower((string)($info['mime'] ?? ''));
        if ($mime !== 'image/jpeg' && $mime !== 'image/jpg') return null;
        $data = @file_get_contents($path);
        if ($data === false || $data === '') return null;
        return ['width' => (int)$info[0], 'height' => (int)$info[1], 'data' => $data, 'filter' => 'DCTDecode'];
    }
    public function output(): string {
        if ($this->content !== '') { $this->pages[] = $this->content; $this->content = ''; }
        $objects = [];
        $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[] = ''; // Pages placeholder

        $pageCount = count($this->pages);
        $pageObjNums = [];
        $contentObjNums = [];
        $n = 3;
        for ($i = 0; $i < $pageCount; $i++) { $pageObjNums[$i] = $n++; $contentObjNums[$i] = $n++; }
        $fontRegularObj = $n++;
        $fontBoldObj = $n++;
        $imageObjNums = [];
        foreach ($this->images as $key => $_) $imageObjNums[$key] = $n++;

        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) $kids[] = $pageObjNums[$i] . ' 0 R';
        $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';

        $xObjects = '';
        foreach ($this->images as $key => $img) $xObjects .= '/' . $img['name'] . ' ' . $imageObjNums[$key] . ' 0 R ';
        $xObjectResource = $xObjects !== '' ? ' /XObject << ' . trim($xObjects) . ' >>' : '';

        foreach ($this->pages as $i => $stream) {
            $objects[] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0f %.0f] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >>%s >> /Contents %d 0 R >>', $this->w, $this->h, $fontRegularObj, $fontBoldObj, $xObjectResource, $contentObjNums[$i]);
            $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($this->images as $key => $img) {
            $objects[] = sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /%s /Length %d >>' . "\nstream\n" . $img['data'] . "\nendstream", (int)$img['width'], (int)$img['height'], $img['filter'], strlen($img['data']));
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $obj) {
            $offsets[$i+1] = strlen($pdf);
            $pdf .= ($i+1) . " 0 obj\n" . $obj . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects)+1) . "\n0000000000 65535 f \n";
        for ($i=1; $i<=count($objects); $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        $pdf .= "trailer\n<< /Size " . (count($objects)+1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }
}

function st_pdf_trim(string $text, int $max): string {
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max - 3, 'UTF-8') . '...' : $text;
    }
    return strlen($text) > $max ? substr($text, 0, $max - 3) . '...' : $text;
}

function pdf_bar(SimplePdf $pdf, float $x, float $y, int $percent, array $color): void {
    $w = 72; $h = 3.0;
    $pdf->rect($x, $y, $w, $h, [226,232,240]);
    $pdf->rect($x, $y, max(0, min(100, $percent)) / 100 * $w, $h, $color);
}

function st_pdf_settings(): array {
    $settings = function_exists('app_settings') ? app_settings() : [];
    $club = trim((string)($settings['club_name'] ?? ''));
    $youth = trim((string)($settings['youth_name'] ?? ''));
    return ['club_name' => $club, 'youth_name' => $youth];
}

$ctx = st_export_context($_GET);
$filters = $ctx['filters'];
$rows = $ctx['rows'];
$pdf = new SimplePdf();

$colors = [
    'holiday' => [168,85,247],
    'training_regular' => [34,197,94],
    'training_all' => [14,165,233],
    'match' => [249,115,22],
    'total' => [100,116,139],
];
$settings = st_pdf_settings();


function st_pdf_metric_columns(array $filters, array $rows): array {
    $hasHolidayValues = false;
    foreach ($rows as $row) {
        if ((int)($row['holiday']['total'] ?? 0) > 0) { $hasHolidayValues = true; break; }
    }

    $eventType = (string)($filters['event_type'] ?? 'all');
    $holidayMode = (string)($filters['holiday_mode'] ?? 'include');

    $cols = [];

    // Ferien anzeigen, sobald sie im Filter einbezogen oder explizit ausgewertet werden.
    // Nicht erst abhängig von vorhandenen Werten, sonst fehlt die Spalte genau dann,
    // wenn der Nutzer sie bewusst ausgewählt hat. Ja, vorher war das dämlich.
    if ($eventType !== 'match' && $holidayMode !== 'exclude') {
        $cols[] = ['key' => 'holiday', 'label' => 'Ferien'];
    }

    // Normales Training nur anzeigen, wenn Training im Filter enthalten ist und nicht ausschließlich Ferien ausgewertet werden.
    if ($eventType !== 'match' && $holidayMode !== 'only') {
        $cols[] = ['key' => 'training_regular', 'label' => 'Training ohne Ferien'];
    }

    // Spieltage nur anzeigen, wenn Spieltage im Filter enthalten sind.
    if ($eventType !== 'training' && $holidayMode !== 'only') {
        $cols[] = ['key' => 'match', 'label' => 'Spieltage'];
    }

    $cols[] = ['key' => 'total', 'label' => 'Gesamt'];
    return $cols;
}

$metricCols = st_pdf_metric_columns($filters, $rows);

$renderHeader = function(SimplePdf $pdf) use ($filters, $colors, $settings, $metricCols) {
    // Clean Pro Report Header: dunkler Report-Look, dezente rechte Flächen, Info-Card darunter.
    $clubName = trim((string)($settings['club_name'] ?? ''));
    $youthName = trim((string)($settings['youth_name'] ?? ''));
    $titleName = $clubName !== '' ? $clubName : 'SquadTrack';
    $subName = trim(($youthName !== '' ? $youthName . ' · ' : '') . 'SquadTrack Exportbericht');

    $pdf->rect(0, 0, 842, 104, [6, 31, 56]);
    $pdf->rect(0, 0, 842, 5, [39, 179, 255]);
    $pdf->polygon([[540, 0], [842, 0], [842, 36], [570, 54]], [8, 47, 82]);
    $pdf->polygon([[665, 0], [842, 0], [842, 104], [715, 104]], [11, 64, 101]);
    $pdf->polygon([[610, 104], [842, 64], [842, 104]], [13, 78, 121]);

    $pdf->rect(31, 25, 5, 47, [39, 179, 255]);
    $pdf->text(45, 38, st_pdf_trim($titleName, 64), 22, true, [255, 255, 255]);
    $pdf->text(46, 61, st_pdf_trim($subName, 82), 11, false, [219, 234, 254]);

    // Legende nur für tatsächlich sichtbare Werte anzeigen. Kein unnötiger Kram, sensationell eigentlich.
    $lx = 595;
    $ly = 52;
    foreach ($metricCols as $i => $col) {
        $key = (string)$col['key'];
        $x = $lx + ($i % 2) * 112;
        $yLegend = $ly + (int)floor($i / 2) * 18;
        $pdf->rect($x, $yLegend - 8, 8, 8, $colors[$key] ?? [100,116,139]);
        $pdf->text($x + 13, $yLegend - 1, (string)$col['label'], 7, false, [235, 245, 255]);
    }

    // Info-Card
    $cardY = 116;
    $pdf->rect(28, $cardY, 786, 31, [255, 255, 255]);
    $pdf->line(28, $cardY, 814, $cardY, .86);
    $pdf->line(28, $cardY + 31, 814, $cardY + 31, .86);

    $infoY = $cardY + 19;
    $pdf->text(45, $infoY, 'Zeitraum:', 8, true, [15, 23, 42]);
    $pdf->text(92, $infoY, st_period_label($filters), 8, false, [51, 65, 85]);

    $pdf->text(245, $infoY, 'Modus:', 8, true, [15, 23, 42]);
    $pdf->text(282, $infoY, st_event_type_label($filters['event_type']), 8, false, [51, 65, 85]);

    $pdf->text(425, $infoY, 'Ferien:', 8, true, [15, 23, 42]);
    $pdf->text(465, $infoY, st_holiday_label($filters['holiday_mode']), 8, false, [51, 65, 85]);

    if ($filters['fairness']) {
        $pdf->text(610, $infoY, 'Fairness:', 8, true, [15, 23, 42]);
        $pdf->text(660, $infoY, 'aktiv', 8, false, [51, 65, 85]);
    }

    // Dynamischer Tabellenkopf
    $y = 171;
    $pdf->text(28, $y, 'Name', 8, true);

    $fairnessActive = !empty($filters['fairness']);
    $diagramX = $fairnessActive ? 565 : 665;
    $fairnessX = 690;
    $metricStartX = 185;
    $metricEndX = $diagramX - 25;
    $metricCount = max(1, count($metricCols));
    $metricStep = ($metricEndX - $metricStartX) / $metricCount;

    foreach ($metricCols as $i => $col) {
        $x = $metricStartX + ($i * $metricStep);
        $pdf->text($x, $y, (string)$col['label'], 7, true);
    }

    $pdf->text($diagramX, $y, 'Diagramm', 7, true);
    if ($fairnessActive) $pdf->text($fairnessX, $y, 'Fairness', 7, true);
    $pdf->line(28, 183, 814, 183, .70);
};

$renderHeader($pdf);
$y = 201;
$rowIndex = 0;
foreach ($rows as $row) {
    if ($y > 548) { $pdf->addPage(); $renderHeader($pdf); $y = 201; $rowIndex = 0; }
    if ($rowIndex % 2 === 1) {
        $pdf->rect(28, $y - 10, 786, 25, [248,250,252]);
    }

    $pdf->text(28, $y, st_pdf_trim((string)$row['name'], 30), 8, true);

    $fairnessActive = !empty($filters['fairness']);
    $diagramX = $fairnessActive ? 565 : 665;
    $fairnessX = 690;
    $metricStartX = 185;
    $metricEndX = $diagramX - 25;
    $metricCount = max(1, count($metricCols));
    $metricStep = ($metricEndX - $metricStartX) / $metricCount;

    foreach ($metricCols as $i => $col) {
        $key = (string)$col['key'];
        $bucket = $row[$key] ?? st_bucket_empty();
        $x = $metricStartX + ($i * $metricStep);
        $pdf->text($x, $y-2, (string)$bucket['text'], 8, true);
        $pdf->text($x, $y+9, (int)$bucket['percent'] . ' %', 7);
    }

    // Diagramm: exakt die sichtbaren Werte, untereinander. Keine 0/0-Geisterspalten mehr.
    foreach ($metricCols as $i => $col) {
        $key = (string)$col['key'];
        $bucket = $row[$key] ?? st_bucket_empty();
        pdf_bar($pdf, $diagramX, $y - 5 + ($i * 5), (int)$bucket['percent'], $colors[$key] ?? [100,116,139]);
    }
    $total = $row['total'] ?? st_bucket_empty();
    $pdf->text($diagramX + 77, $y + 3, (int)$total['percent'] . '%', 7, true);

    if ($fairnessActive) {
        $class = (string)($row['fairness']['class'] ?? 'muted');
        $c = [75,85,99];
        if ($class === 'good') { $c = [22,101,52]; }
        elseif ($class === 'mid') { $c = [146,64,14]; }
        elseif ($class === 'bad') { $c = [153,27,27]; }
        $pdf->rect($fairnessX, $y-6, 58, 12, $c);
        $pdf->text($fairnessX + 6, $y+2, (string)$row['fairness']['short'], 7, true, [255,255,255]);
        $pdf->text($fairnessX + 64, $y+2, 'Diff: ' . (int)($row['fairness']['diff'] ?? 0) . '%', 7, false, $c);
    }
    $pdf->line(28, $y+17, 814, $y+17, .88);
    $y += 29;
    $rowIndex++;
}

$filename = 'squadtrack_export_' . date('Y-m-d_H-i') . '.pdf';
if (ob_get_length()) { ob_end_clean(); }
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
echo $pdf->output();
