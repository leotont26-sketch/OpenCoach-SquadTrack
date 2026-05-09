<?php

declare(strict_types=1);

if (!function_exists('squadtrack_install_report_bootstrap')) {
    function squadtrack_install_report_bootstrap(): void
    {
        if (defined('DEMO_MODE') && DEMO_MODE === true) {
        return;
        }
        static $booted = false;
        if ($booted) {
            return;
        }
        $booted = true;

        $config = squadtrack_install_report_config();
        if (empty($config['enabled']) || empty($config['endpoint']) || empty($config['api_key'])) {
            return;
        }

        $now = time();
        $lastReport = squadtrack_install_report_last_time();
        $minInterval = max(300, (int)($config['min_interval_seconds'] ?? 43200));
        if ($lastReport > 0 && ($now - $lastReport) < $minInterval) {
            return;
        }

        $payload = squadtrack_install_report_build_payload();
        $ok = squadtrack_install_report_send((string)$config['endpoint'], $payload, (string)$config['api_key']);
        if ($ok) {
            squadtrack_install_report_write_last_time($now);
        }
    }

    function squadtrack_install_report_config(): array
    {
        return [
            'enabled' => true,
            'endpoint' => 'https://tont-online.de/zentrale/api/report.php',
            'api_key' => '1987091720150121201604272009080319870707',
            'min_interval_seconds' => 43200,
            'timeout_seconds' => 4,
            'product' => 'opencoach',
            'module' => 'squadtrack',
            'app_version' => '1.1.0',
        ];
    }

    function squadtrack_install_report_build_payload(): array
    {
        $config = squadtrack_install_report_config();
        $settings = function_exists('app_settings') ? app_settings() : [];
        $installId = squadtrack_install_report_install_id();
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
        $scriptDir = rtrim($scriptDir, '/.');
        $baseUrl = $host !== '' ? $scheme . '://' . $host . ($scriptDir !== '' ? $scriptDir : '') : '';

        $players = squadtrack_install_report_read_json_file(__DIR__ . '/data/players.json');
        $events = squadtrack_install_report_read_json_file(__DIR__ . '/data/events.json');
        $attendance = squadtrack_install_report_read_json_file(__DIR__ . '/data/attendance.json');
        $trainers = squadtrack_install_report_read_json_file(__DIR__ . '/data/trainers.json');

        $filesForActivity = [
            __DIR__ . '/data/players.json',
            __DIR__ . '/data/events.json',
            __DIR__ . '/data/attendance.json',
            __DIR__ . '/data/trainers.json',
            __DIR__ . '/data/settings.json',
        ];

        return [
            'install_id' => $installId,
            'product' => trim((string)($config['product'] ?? 'opencoach')),
            'module' => trim((string)($config['module'] ?? 'squadtrack')),
            'domain' => $host,
            'base_url' => $baseUrl,
            'php_version' => PHP_VERSION,
            'matchday_version' => trim((string)($config['app_version'] ?? '1.0.0')),
            'app_version' => trim((string)($config['app_version'] ?? '1.0.0')),
            'last_generated_at' => squadtrack_install_report_latest_timestamp($filesForActivity),
            'last_activity_at' => squadtrack_install_report_latest_timestamp($filesForActivity),
            'players_count' => count($players),
            'events_count' => count($events),
            'attendance_count' => count($attendance),
            'trainers_count' => count($trainers),
            'reported_at' => date('Y-m-d H:i:s'),
            'portal_title' => trim((string)($settings['tracker_name'] ?? 'SquadTrack')),
            'club_name' => trim((string)($settings['club_name'] ?? '')),
        ];
    }

    function squadtrack_install_report_latest_timestamp(array $files): string
    {
        $latest = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                $mtime = (int)@filemtime($file);
                if ($mtime > $latest) {
                    $latest = $mtime;
                }
            }
        }
        return $latest > 0 ? date('Y-m-d H:i:s', $latest) : '';
    }

    function squadtrack_install_report_read_json_file(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    function squadtrack_install_report_install_id(): string
    {
        $file = __DIR__ . '/data/install_id.txt';
        if (is_file($file)) {
            $id = trim((string)@file_get_contents($file));
            if ($id !== '') {
                return $id;
            }
        }

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }

        try {
            $id = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            $id = md5(uniqid((string)mt_rand(), true));
        }

        @file_put_contents($file, $id);
        return $id;
    }

    function squadtrack_install_report_last_time(): int
    {
        $file = __DIR__ . '/data/install_report_last.txt';
        if (!is_file($file)) {
            return 0;
        }
        return (int)trim((string)@file_get_contents($file));
    }

    function squadtrack_install_report_write_last_time(int $timestamp): void
    {
        $file = __DIR__ . '/data/install_report_last.txt';
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        @file_put_contents($file, (string)$timestamp);
    }

    function squadtrack_install_report_send(string $endpoint, array $payload, string $apiKey): bool
    {
        $timeout = max(2, (int)(squadtrack_install_report_config()['timeout_seconds'] ?? 4));
        $json = json_encode([
            'api_key' => $apiKey,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return false;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $status >= 200 && $status < 300;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($endpoint, false, $context);
        if ($result === false && empty($http_response_header)) {
            return false;
        }

        $statusLine = $http_response_header[0] ?? '';
        return preg_match('~\s2\d\d\s~', $statusLine) === 1;
    }
}
