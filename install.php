<?php
declare(strict_types=1);

/**
 * OpenCoach Remote Installer
 * Datei in einen leeren Zielordner hochladen und im Browser aufrufen.
 *
 * Zielstruktur:
 * /deinOrdner/install.php
 * /deinOrdner/matchday/...
 * /deinOrdner/squadtrack/...
 */

session_start();

const INSTALLER_VERSION = '1.0.0';

const MODULES = [
    'matchday' => [
        'label' => 'OpenCoach MatchDay',
        'target_dir' => 'matchday',
        'zip_url' => 'https://tont-online.de/download/OpenCoach_Matchday.zip',
        'min_php' => '8.3.0',
    ],
    'squadtrack' => [
        'label' => 'OpenCoach SquadTrack',
        'target_dir' => 'squadtrack',
        'zip_url' => 'https://tont-online.de/download/OpenCoach_Squadtrack.zip',
        'min_php' => '8.3.0',
    ],
];

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(string $path = ''): string {
    return __DIR__ . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token']);
}

function result_item(string $label, bool $ok, string $details = '', string $level = ''): array {
    return [
        'label' => $label,
        'ok' => $ok,
        'details' => $details,
        'level' => $level !== '' ? $level : ($ok ? 'ok' : 'error'),
    ];
}

function can_download_remote(): bool {
    return extension_loaded('curl') || (bool)ini_get('allow_url_fopen');
}

function write_test(string $dir): bool {
    if (!is_dir($dir)) {
        return false;
    }

    $testFile = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.opencoach_write_test_' . bin2hex(random_bytes(5)) . '.tmp';

    $written = @file_put_contents($testFile, 'opencoach-test');
    if ($written === false) {
        return false;
    }

    $content = @file_get_contents($testFile);
    @unlink($testFile);

    return $content === 'opencoach-test';
}

function chmod_and_verify(string $dir): array {
    $messages = [];

    if (!is_dir($dir)) {
        $messages[] = result_item('Ordner existiert nicht: ' . $dir, false);
        return [false, $messages];
    }

    if (write_test($dir)) {
        $messages[] = result_item('Schreibtest erfolgreich: ' . basename($dir), true);
        return [true, $messages];
    }

    @chmod($dir, 0755);
    if (write_test($dir)) {
        $messages[] = result_item('Rechte 755 gesetzt und geprüft: ' . basename($dir), true);
        return [true, $messages];
    }

    @chmod($dir, 0775);
    if (write_test($dir)) {
        $messages[] = result_item('Rechte 775 gesetzt und geprüft: ' . basename($dir), true);
        return [true, $messages];
    }

    $messages[] = result_item(
        'Schreibtest fehlgeschlagen: ' . basename($dir),
        false,
        'Bitte den Ordner manuell über Webhoster/FTP auf 755 oder 775 setzen.'
    );

    return [false, $messages];
}

function run_system_check(): array {
    $results = [];

    $results[] = result_item(
        'PHP-Version mindestens 8.3',
        version_compare(PHP_VERSION, '8.3.0', '>='),
        'Aktuell: ' . PHP_VERSION
    );

    $results[] = result_item(
        'JSON-Erweiterung verfügbar',
        extension_loaded('json'),
        extension_loaded('json') ? 'OK' : 'PHP-Erweiterung json fehlt.'
    );

    $results[] = result_item(
        'ZIP-Erweiterung / ZipArchive verfügbar',
        class_exists('ZipArchive'),
        class_exists('ZipArchive') ? 'OK' : 'PHP-Erweiterung zip/ZipArchive fehlt.'
    );

    $results[] = result_item(
        'Remote-Download möglich',
        can_download_remote(),
        can_download_remote()
            ? (extension_loaded('curl') ? 'cURL verfügbar' : 'allow_url_fopen verfügbar')
            : 'Benötigt cURL oder allow_url_fopen.'
    );

    $results[] = result_item(
        'Aktueller Installationsordner beschreibbar',
        write_test(__DIR__),
        __DIR__
    );

    $results[] = result_item(
        'Temporärer Ordner verfügbar',
        is_dir(sys_get_temp_dir()) && write_test(sys_get_temp_dir()),
        sys_get_temp_dir()
    );

    return $results;
}

function all_ok(array $results): bool {
    foreach ($results as $r) {
        if (!$r['ok']) {
            return false;
        }
    }
    return true;
}

function download_file(string $url, string $targetFile): bool {
    if (extension_loaded('curl')) {
        $fp = @fopen($targetFile, 'wb');
        if (!$fp) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => 'OpenCoach Installer/' . INSTALLER_VERSION,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FAILONERROR => true,
        ]);

        $ok = curl_exec($ch);
        $errNo = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $errNo !== 0 || $httpCode >= 400 || !is_file($targetFile) || filesize($targetFile) <= 0) {
            @unlink($targetFile);
            return false;
        }

        return true;
    }

    if ((bool)ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 60,
                'follow_location' => 1,
                'user_agent' => 'OpenCoach Installer/' . INSTALLER_VERSION,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || strlen($data) === 0) {
            return false;
        }

        return @file_put_contents($targetFile, $data) !== false;
    }

    return false;
}

function remove_dir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            remove_dir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function safe_extract_zip(string $zipFile, string $extractTo): array {
    $messages = [];

    $zip = new ZipArchive();
    $open = $zip->open($zipFile);

    if ($open !== true) {
        return [false, [result_item('ZIP konnte nicht geöffnet werden', false, 'ZipArchive Fehlercode: ' . (string)$open)]];
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);

        if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $name)) {
            $zip->close();
            return [false, [result_item('Unsicherer Dateipfad im ZIP gefunden', false, $name)]];
        }
    }

    if (!is_dir($extractTo) && !@mkdir($extractTo, 0755, true)) {
        $zip->close();
        return [false, [result_item('Temporärer Entpackordner konnte nicht erstellt werden', false, $extractTo)]];
    }

    $ok = $zip->extractTo($extractTo);
    $zip->close();

    if (!$ok) {
        return [false, [result_item('ZIP konnte nicht entpackt werden', false)]];
    }

    $messages[] = result_item('ZIP erfolgreich entpackt', true, $extractTo);
    return [true, $messages];
}

function find_payload_root(string $extractDir): string {
    $items = array_values(array_filter(scandir($extractDir) ?: [], static function ($item) {
        return $item !== '.' && $item !== '..' && $item !== '__MACOSX';
    }));

    if (count($items) === 1 && is_dir($extractDir . DIRECTORY_SEPARATOR . $items[0])) {
        return $extractDir . DIRECTORY_SEPARATOR . $items[0];
    }

    return $extractDir;
}

function copy_dir(string $source, string $target): bool {
    if (!is_dir($source)) {
        return false;
    }

    if (!is_dir($target) && !@mkdir($target, 0755, true)) {
        return false;
    }

    $items = scandir($source);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '__MACOSX') {
            continue;
        }

        $src = $source . DIRECTORY_SEPARATOR . $item;
        $dst = $target . DIRECTORY_SEPARATOR . $item;

        if (is_dir($src) && !is_link($src)) {
            if (!copy_dir($src, $dst)) {
                return false;
            }
        } else {
            if (!@copy($src, $dst)) {
                return false;
            }
            @chmod($dst, 0644);
        }
    }

    return true;
}

function ensure_data_dirs(string $targetDir): array {
    $results = [];

    $dirs = [
        $targetDir,
        $targetDir . DIRECTORY_SEPARATOR . 'data',
        $targetDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'backups',
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            $created = @mkdir($dir, 0755, true);
            $results[] = result_item(
                'Ordner erstellt: ' . basename($dir),
                $created || is_dir($dir),
                $dir
            );
        } else {
            $results[] = result_item('Ordner vorhanden: ' . basename($dir), true, $dir);
        }

        [$ok, $checkMessages] = chmod_and_verify($dir);
        foreach ($checkMessages as $msg) {
            $results[] = $msg;
        }
    }

    $dataHtaccess = $targetDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_dir(dirname($dataHtaccess)) && !is_file($dataHtaccess)) {
        $ht = "Require all denied\nDeny from all\n";
        $results[] = result_item(
            'Datenschutz für /data/.htaccess erstellt',
            @file_put_contents($dataHtaccess, $ht) !== false,
            $dataHtaccess
        );
    }

    return $results;
}

function install_module(string $moduleKey): array {
    $messages = [];

    if (!isset(MODULES[$moduleKey])) {
        return [false, [result_item('Ungültiges Modul', false)]];
    }

    $module = MODULES[$moduleKey];
    $targetDir = base_path($module['target_dir']);

    if (is_dir($targetDir)) {
        $existing = array_values(array_filter(scandir($targetDir) ?: [], static fn($i) => $i !== '.' && $i !== '..'));
        if (count($existing) > 0) {
            return [false, [
                result_item(
                    'Zielordner ist nicht leer: /' . $module['target_dir'] . '/',
                    false,
                    'Bitte leeren/löschen oder anderen Installationsordner verwenden.'
                )
            ]];
        }
    } else {
        if (!@mkdir($targetDir, 0755, true)) {
            return [false, [result_item('Zielordner konnte nicht erstellt werden', false, $targetDir)]];
        }
        $messages[] = result_item('Zielordner erstellt: /' . $module['target_dir'] . '/', true);
    }

    [$targetWritable, $targetChecks] = chmod_and_verify($targetDir);
    $messages = array_merge($messages, $targetChecks);

    if (!$targetWritable) {
        return [false, $messages];
    }

    $tmpBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'opencoach_installer_' . bin2hex(random_bytes(8));
    $zipFile = $tmpBase . DIRECTORY_SEPARATOR . $moduleKey . '.zip';
    $extractDir = $tmpBase . DIRECTORY_SEPARATOR . 'extract';

    if (!@mkdir($tmpBase, 0755, true)) {
        return [false, [result_item('Temporärer Installationsordner konnte nicht erstellt werden', false, $tmpBase)]];
    }

    $messages[] = result_item('Download gestartet', true, $module['zip_url']);

    if (!download_file($module['zip_url'], $zipFile)) {
        remove_dir($tmpBase);
        return [false, array_merge($messages, [
            result_item(
                'ZIP konnte nicht heruntergeladen werden',
                false,
                'Prüfen: URL erreichbar, cURL/allow_url_fopen aktiv, Server darf externe HTTPS-Verbindungen öffnen.'
            )
        ])];
    }

    $messages[] = result_item('ZIP heruntergeladen', true, basename($zipFile) . ' · ' . number_format((float)filesize($zipFile) / 1024 / 1024, 2, ',', '.') . ' MB');

    [$extractOk, $extractMessages] = safe_extract_zip($zipFile, $extractDir);
    $messages = array_merge($messages, $extractMessages);

    if (!$extractOk) {
        remove_dir($tmpBase);
        return [false, $messages];
    }

    $payloadRoot = find_payload_root($extractDir);

    if (!copy_dir($payloadRoot, $targetDir)) {
        remove_dir($tmpBase);
        return [false, array_merge($messages, [
            result_item('Dateien konnten nicht vollständig kopiert werden', false, 'Ziel: ' . $targetDir)
        ])];
    }

    $messages[] = result_item('Dateien kopiert', true, '/' . $module['target_dir'] . '/');

    $dataChecks = ensure_data_dirs($targetDir);
    $messages = array_merge($messages, $dataChecks);

    if (!all_ok(array_filter($dataChecks, static fn($item) => $item['level'] !== 'warning'))) {
        remove_dir($tmpBase);
        return [false, $messages];
    }

    $lockFile = $targetDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'installed_by_remote_installer.lock';
    @file_put_contents($lockFile, 'Installed: ' . date('c') . PHP_EOL . 'Installer: ' . INSTALLER_VERSION . PHP_EOL);

    $messages[] = result_item('Installationsmarker erstellt', is_file($lockFile), $lockFile);

    remove_dir($tmpBase);

    $messages[] = result_item('Installation abgeschlossen', true, 'Öffne /' . $module['target_dir'] . '/');

    return [all_ok($messages), $messages];
}

$systemCheck = run_system_check();
$systemOk = all_ok($systemCheck);

$installMessages = [];
$installOk = null;
$selectedModule = isset($_POST['module']) ? (string)$_POST['module'] : '';
$deleteInstallerRequested = false;
$deleteInstallerOk = null;
$deleteInstallerMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_installer') {
    $deleteInstallerRequested = true;

    if (!verify_csrf()) {
        $deleteInstallerOk = false;
        $deleteInstallerMessage = 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden.';
    } else {
        $self = __FILE__;

        if (!is_file($self)) {
            $deleteInstallerOk = true;
            $deleteInstallerMessage = 'install.php ist bereits nicht mehr vorhanden.';
        } elseif (@unlink($self)) {
            $deleteInstallerOk = true;
            $deleteInstallerMessage = 'install.php wurde erfolgreich gelöscht.';
        } else {
            $deleteInstallerOk = false;
            $deleteInstallerMessage = 'install.php konnte nicht automatisch gelöscht werden. Bitte manuell über FTP/Webhoster löschen.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install') {
    if (!verify_csrf()) {
        $installOk = false;
        $installMessages[] = result_item('Sicherheitsprüfung fehlgeschlagen', false, 'Bitte Seite neu laden.');
    } elseif (!$systemOk) {
        $installOk = false;
        $installMessages[] = result_item('Systemcheck nicht bestanden', false, 'Installation wurde nicht gestartet.');
    } else {
        [$installOk, $installMessages] = install_module($selectedModule);
    }
}

function render_results(array $items): void {
    foreach ($items as $item) {
        $class = $item['ok'] ? 'ok' : 'error';
        if (($item['level'] ?? '') === 'warning') {
            $class = 'warning';
        }
        echo '<div class="result ' . h($class) . '">';
        echo '<div class="icon">' . ($item['ok'] ? '?' : '!') . '</div>';
        echo '<div>';
        echo '<strong>' . h($item['label']) . '</strong>';
        if (($item['details'] ?? '') !== '') {
            echo '<span>' . h($item['details']) . '</span>';
        }
        echo '</div>';
        echo '</div>';
    }
}
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
	<link rel="icon" type="image/svg+xml" href="https://tont-online.de/Logos/favicon.svg">
	<link rel="shortcut icon" href="https://tont-online.de/Logos/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OpenCoach Installer</title>
    <style>
        :root {
            --bg: #050912;
            --panel: rgba(255,255,255,.075);
            --border: rgba(255,255,255,.14);
            --text: #f5f8ff;
            --muted: #97a6bd;
            --ok: #18d39e;
            --warn: #ffbd4a;
            --error: #ff4d6d;
            --blue: #27b3ff;
            --cyan: #13d0c1;
            --shadow: 0 30px 110px rgba(0,0,0,.45);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 20% 10%, rgba(39,179,255,.28), transparent 28%),
                radial-gradient(circle at 80% 70%, rgba(19,208,193,.22), transparent 30%),
                linear-gradient(145deg, #071427, #02040a 70%);
            padding: 28px;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
            background-size: 56px 56px;
            mask-image: linear-gradient(to bottom, #000, transparent 86%);
        }

        .wrap {
            position: relative;
            z-index: 1;
            width: min(1080px, 100%);
            margin: 0 auto;
        }

        .hero {
            padding: 34px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,.065);
            backdrop-filter: blur(18px);
            border-radius: 32px;
            box-shadow: var(--shadow);
            margin-bottom: 18px;
        }

        .badge {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,.07);
            color: rgba(255,255,255,.78);
            font-size: 13px;
            margin-bottom: 18px;
        }

        h1 {
            margin: 0 0 12px;
            font-size: clamp(38px, 7vw, 78px);
            line-height: .88;
            letter-spacing: -.075em;
        }

        .gradient {
            background: linear-gradient(90deg, #fff, #8fdcff, #13d0c1);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        p {
            color: var(--muted);
            line-height: 1.65;
            font-size: 16px;
            max-width: 760px;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .card {
            padding: 24px;
            border: 1px solid var(--border);
            background: var(--panel);
            backdrop-filter: blur(18px);
            border-radius: 28px;
            box-shadow: 0 20px 70px rgba(0,0,0,.26);
        }

        h2 {
            margin: 0 0 16px;
            letter-spacing: -.035em;
        }

        .result {
            display: grid;
            grid-template-columns: 34px 1fr;
            gap: 12px;
            padding: 13px;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,.1);
            background: rgba(255,255,255,.052);
            margin-bottom: 10px;
        }

        .result .icon {
            width: 34px;
            height: 34px;
            display: grid;
            place-items: center;
            border-radius: 999px;
            font-weight: 900;
            background: rgba(255,255,255,.1);
        }

        .result.ok .icon {
            background: rgba(24,211,158,.16);
            color: var(--ok);
        }

        .result.error .icon {
            background: rgba(255,77,109,.16);
            color: var(--error);
        }

        .result.warning .icon {
            background: rgba(255,189,74,.16);
            color: var(--warn);
        }

        .result strong {
            display: block;
            font-size: 15px;
        }

        .result span {
            display: block;
            color: var(--muted);
            font-size: 13px;
            margin-top: 3px;
            word-break: break-word;
        }

        form {
            display: grid;
            gap: 12px;
        }

        .choice {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 14px;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,.12);
            background: rgba(255,255,255,.055);
            cursor: pointer;
        }

        .choice input {
            margin-top: 4px;
            transform: scale(1.15);
        }

        .choice b {
            display: block;
            margin-bottom: 4px;
        }

        .choice span {
            color: var(--muted);
            font-size: 13px;
            word-break: break-all;
        }

        button, .button {
            appearance: none;
            border: 0;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            padding: 14px 18px;
            border-radius: 999px;
            background: linear-gradient(90deg, var(--blue), var(--cyan));
            color: #03101b;
            font-weight: 900;
            text-decoration: none;
            cursor: pointer;
            box-shadow: 0 18px 46px rgba(39,179,255,.2);
        }

        .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-top: 14px;
        }

        .danger-button {
            background: linear-gradient(90deg, #ff4d6d, #ffbd4a);
            color: #160309;
        }

        .secondary-button {
            background: rgba(255,255,255,.11);
            border: 1px solid rgba(255,255,255,.14);
            color: var(--text);
            box-shadow: none;
        }

        .login-warning {
            margin-top: 14px;
            padding: 18px;
            border-radius: 22px;
            background: rgba(255,77,109,.13);
            border: 1px solid rgba(255,77,109,.34);
            color: #ffd7df;
            box-shadow: 0 18px 60px rgba(255,77,109,.09);
        }

        .login-warning strong {
            display: block;
            color: #fff;
            font-size: 18px;
            margin-bottom: 8px;
        }

        .login-warning .creds {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 7px 12px;
            margin: 12px 0;
            font-size: 16px;
        }

        .login-warning code {
            color: #fff;
            background: rgba(255,255,255,.14);
            font-weight: 900;
        }

        button:disabled {
            opacity: .45;
            cursor: not-allowed;
            box-shadow: none;
        }

        .hint {
            margin-top: 14px;
            padding: 14px;
            border-radius: 18px;
            background: rgba(255,189,74,.1);
            border: 1px solid rgba(255,189,74,.22);
            color: #ffe4ad;
            font-size: 14px;
            line-height: 1.55;
        }

        .success {
            margin-top: 14px;
            padding: 14px;
            border-radius: 18px;
            background: rgba(24,211,158,.1);
            border: 1px solid rgba(24,211,158,.22);
            color: #b8ffea;
            font-size: 14px;
            line-height: 1.55;
        }

        code {
            color: #c8f4ff;
            background: rgba(255,255,255,.08);
            padding: 2px 6px;
            border-radius: 7px;
        }

        @media (max-width: 860px) {
            body { padding: 16px; }
            .grid { grid-template-columns: 1fr; }
            .hero, .card { padding: 20px; border-radius: 24px; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <section class="hero">
        <div class="badge">OpenCoach Remote Installer · Version <?= h(INSTALLER_VERSION) ?></div>
        <h1><span class="gradient">Erstinstallation<br>OpenCoach</span></h1>
        <p>
            Diese Datei installiert MatchDay oder SquadTrack in einen Unterordner dieses Verzeichnisses.
            Erst wird geprüft, ob der Server geeignet ist. Dann wird das Paket geladen, entpackt und danach werden Schreibrechte geprüft.
        </p>
    </section>

    <div class="grid">
        <section class="card">
            <h2>1. Systemprüfung</h2>
            <?php render_results($systemCheck); ?>

            <?php if (!$systemOk): ?>
                <div class="hint">
                    <strong>Installation blockiert.</strong><br>
                    Bitte behebe die roten Punkte.
                </div>
            <?php else: ?>
                <div class="success">
                    <strong>Systemcheck bestanden.</strong><br>
                    Der Installer kann starten.
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>2. Modul auswählen</h2>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="install">

                <?php foreach (MODULES as $key => $module): ?>
                    <label class="choice">
                        <input type="radio" name="module" value="<?= h($key) ?>" <?= $key === 'matchday' ? 'checked' : '' ?>>
                        <span>
                            <b><?= h($module['label']) ?></b>
                            <span>Ziel: <code>/<?= h($module['target_dir']) ?>/</code></span><br>
                            <span>Quelle: <?= h($module['zip_url']) ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>

                <button type="submit" <?= !$systemOk ? 'disabled' : '' ?>>
                    Installation starten
                </button>
            </form>

            <div class="hint">
                Installiert wird ausschließlich in diesem Ordner darunter:
                <code>/matchday/</code> oder <code>/squadtrack/</code>.
                Bestehende nicht-leere Zielordner werden nicht überschrieben.
            </div>
        </section>
    </div>

    <?php if ($installOk !== null): ?>
        <section id="install-status" class="card" style="margin-top:18px;">
            <h2>3. Installationsstatus</h2>
            <?php render_results($installMessages); ?>

            <?php if ($installOk): ?>
                <?php
                    $target = isset(MODULES[$selectedModule]) ? MODULES[$selectedModule]['target_dir'] : '';
                    $targetLabel = isset(MODULES[$selectedModule]) ? MODULES[$selectedModule]['label'] : 'OpenCoach';
                ?>
                <div class="success">
                    <strong>Installation abgeschlossen.</strong><br>
                    <?= h($targetLabel) ?> wurde installiert.
                    <?php if ($target !== ''): ?>
                        <div class="button-row">
                            <a class="button" href="<?= h($target) ?>/">/<?= h($target) ?>/ öffnen</a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="login-warning">
                    <strong>WICHTIG: Erstlogin sofort ändern</strong>
                    <div class="creds">
                        <span>Benutzer:</span><code>admin</code>
                        <span>Passwort:</span><code>12345678</code>
                    </div>
                    Bitte nach dem ersten Login direkt in den Einstellungen ändern.
                    Dieses Passwort ist nur für die Erstinstallation gedacht.
                </div>

                <div class="hint">
                    <strong>Installer aufräumen</strong><br>
                    Soll <code>install.php</code> jetzt automatisch gelöscht werden?
                    Wenn du danach noch das andere Modul installieren willst, wähle <strong>Nein</strong> und lösche die Datei nach der nächsten Installation oder manuell.
                    <form method="post" style="margin-top:12px;">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_installer">
                        <div class="button-row">
                            <button class="danger-button" type="submit">Ja, install.php löschen</button>
                            <?php if ($target !== ''): ?>
                                <a class="button secondary-button" href="<?= h($target) ?>/">Nein, Anwendung öffnen</a>
                            <?php else: ?>
                                <a class="button secondary-button" href="<?= h($_SERVER['PHP_SELF'] ?? 'install.php') ?>">Nein</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <div class="hint">
                    <strong>Installation nicht abgeschlossen.</strong><br>
                    Prüfe die roten Meldungen.
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($deleteInstallerRequested): ?>
        <section id="install-status" class="card" style="margin-top:18px;">
            <h2>Installer löschen</h2>
            <div class="<?= $deleteInstallerOk ? 'success' : 'hint' ?>">
                <strong><?= $deleteInstallerOk ? 'Erledigt.' : 'Nicht gelöscht.' ?></strong><br>
                <?= h($deleteInstallerMessage) ?>
            </div>
        </section>
    <?php endif; ?>
</div>
<?php if ($installOk !== null || $deleteInstallerRequested): ?>
<script>
    window.addEventListener('load', function () {
        var target = document.getElementById('install-status');
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
</script>
<?php endif; ?>
</body>
</html>
