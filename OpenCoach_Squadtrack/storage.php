<?php
// storage.php — sicherer JSON-Dateispeicher mit Locks
declare(strict_types=1);

function data_dir(): string {
  $base = __DIR__ . DIRECTORY_SEPARATOR . 'data';
  if (!is_dir($base)) {
    mkdir($base, 0755, true);
  }
  return $base;
}

function json_path(string $name): string {
  return data_dir() . DIRECTORY_SEPARATOR . $name . '.json';
}

function read_json(string $name, $default = []) {
  $path = json_path($name);
  if (!file_exists($path)) return $default;
  $fp = fopen($path, 'r');
  if (!$fp) return $default;
  if (function_exists('flock')) { flock($fp, LOCK_SH); }
  $contents = stream_get_contents($fp);
  if (function_exists('flock')) { flock($fp, LOCK_UN); }
  fclose($fp);
  $data = json_decode($contents, true);
  return is_array($data) ? $data : $default;
}

function write_json(string $name, array $data): void {
  $path = json_path($name);
  $tmp = $path . '.tmp';
  $fp = fopen($tmp, 'c+');
  if (!$fp) { throw new Exception('Kann temporäre Datei nicht öffnen.'); }
  if (function_exists('flock')) { if (!flock($fp, LOCK_EX)) { throw new Exception('Kann Datei nicht sperren.'); } }
  ftruncate($fp, 0);
  fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  fflush($fp);
  if (function_exists('flock')) { flock($fp, LOCK_UN); }
  fclose($fp);
  rename($tmp, $path);
}

function next_id(array $collection, string $key = 'id'): int {
  $max = 0;
  foreach ($collection as $row) {
    if (isset($row[$key]) && (int)$row[$key] > $max) $max = (int)$row[$key];
  }
  return $max + 1;
}



function settings_path(): string {
  return data_dir() . DIRECTORY_SEPARATOR . 'settings.json';
}

function app_settings(): array {
  $defaults = [
    'club_name' => 'JSG Haßmersheim / Hüffenhardt',
    'youth_name' => 'E-Jugend',
    'tracker_name' => 'Anwesenheits-Tracker',
    'logo_file' => 'logo.png',
    'fairness_balanced_max' => 15,
    'fairness_notice_max' => 30,
  ];

  $path = settings_path();
  if (!file_exists($path)) {
    return $defaults;
  }

  $decoded = json_decode((string)file_get_contents($path), true);
  if (!is_array($decoded)) {
    return $defaults;
  }

  return array_merge($defaults, $decoded);
}

function save_app_settings(array $settings): void {
  $current = app_settings();
  $new = array_merge($current, $settings);
  $path = settings_path();
  file_put_contents($path, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function backups_dir(): string {
  $dir = data_dir() . DIRECTORY_SEPARATOR . 'backups';
  if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
  }
  return $dir;
}

function create_season_backup(): array {
  $stamp = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d_H-i-s');
  $folderName = 'season_backup_' . $stamp;
  $folderPath = backups_dir() . DIRECTORY_SEPARATOR . $folderName;

  if (!mkdir($folderPath, 0755, true) && !is_dir($folderPath)) {
    throw new Exception('Backup-Ordner konnte nicht erstellt werden.');
  }

  $files = ['players', 'events', 'attendance', 'trainers'];
  foreach ($files as $name) {
    $source = json_path($name);
    $target = $folderPath . DIRECTORY_SEPARATOR . $name . '.json';

    if (file_exists($source)) {
      if (!copy($source, $target)) {
        throw new Exception('Datei ' . $name . '.json konnte nicht gesichert werden.');
      }
    } else {
      file_put_contents($target, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
  }

  $meta = [
    'created_at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format(DATE_ATOM),
    'folder' => $folderName,
    'players_count' => count(read_json('players', [])),
    'events_count' => count(read_json('events', [])),
    'attendance_count' => count(read_json('attendance', [])),
    'created_by' => current_user()['username'] ?? 'system',
  ];
  file_put_contents(
    $folderPath . DIRECTORY_SEPARATOR . 'meta.json',
    json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
  );

  $zipPath = create_backup_archive($folderName);

  return [
    'folder_name' => $folderName,
    'folder_path' => $folderPath,
    'relative_path' => 'data/backups/' . $folderName,
    'zip_path' => $zipPath,
  ];
}

function list_backups(): array {
  $dir = backups_dir();
  $items = array_values(array_filter(scandir($dir) ?: [], fn($item) => $item !== '.' && $item !== '..'));
  $result = [];

  foreach ($items as $item) {
    $full = $dir . DIRECTORY_SEPARATOR . $item;
    if (!is_dir($full)) continue;

    $meta = [];
    $metaPath = $full . DIRECTORY_SEPARATOR . 'meta.json';
    if (file_exists($metaPath)) {
      $decoded = json_decode((string)file_get_contents($metaPath), true);
      if (is_array($decoded)) {
        $meta = $decoded;
      }
    }

    $zipFile = backups_dir() . DIRECTORY_SEPARATOR . $item . '.zip';
    $result[] = [
      'name' => $item,
      'path' => $full,
      'relative_path' => 'data/backups/' . $item,
      'zip_relative_path' => 'data/backups/' . $item . '.zip',
      'zip_exists' => is_file($zipFile),
      'zip_size' => is_file($zipFile) ? filesize($zipFile) : null,
      'created_at' => $meta['created_at'] ?? null,
      'players_count' => $meta['players_count'] ?? null,
      'events_count' => $meta['events_count'] ?? null,
      'attendance_count' => $meta['attendance_count'] ?? null,
      'created_by' => $meta['created_by'] ?? null,
    ];
  }

  usort($result, fn($a, $b) => strcmp((string)($b['name'] ?? ''), (string)($a['name'] ?? '')));
  return $result;
}


function backup_folder_path(string $folderName): string {
  if (!preg_match('/^season_backup_[A-Za-z0-9_-]+$/', $folderName)) {
    throw new Exception('Ungültiger Backup-Name.');
  }
  return backups_dir() . DIRECTORY_SEPARATOR . $folderName;
}

function backup_archive_path(string $folderName): string {
  return backup_folder_path($folderName) . '.zip';
}

function rrmdir(string $dir): void {
  if (!is_dir($dir)) return;
  $items = scandir($dir);
  if ($items === false) return;
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $dir . DIRECTORY_SEPARATOR . $item;
    if (is_dir($path)) {
      rrmdir($path);
    } else {
      @unlink($path);
    }
  }
  @rmdir($dir);
}

function backup_required_files(): array {
  return ['players.json', 'events.json', 'attendance.json', 'trainers.json'];
}

function backup_is_valid_directory(string $dir): bool {
  if (!is_dir($dir)) return false;
  foreach (backup_required_files() as $file) {
    if (!is_file($dir . DIRECTORY_SEPARATOR . $file)) {
      return false;
    }
  }
  return true;
}

function create_backup_archive(string $folderName): ?string {
  if (!class_exists('ZipArchive')) {
    return null;
  }

  $folderPath = backup_folder_path($folderName);
  if (!backup_is_valid_directory($folderPath)) {
    throw new Exception('Backup-Ordner ist unvollständig.');
  }

  $zipPath = backup_archive_path($folderName);
  $zip = new ZipArchive();
  if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new Exception('ZIP-Datei konnte nicht erstellt werden.');
  }

  $files = scandir($folderPath) ?: [];
  foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    $full = $folderPath . DIRECTORY_SEPARATOR . $file;
    if (is_file($full)) {
      $zip->addFile($full, $folderName . '/' . $file);
    }
  }
  $zip->close();
  return $zipPath;
}

function apply_backup_from_directory(string $dir): void {
  if (!backup_is_valid_directory($dir)) {
    throw new Exception('Backup ist unvollständig oder beschädigt.');
  }

  foreach (['players', 'events', 'attendance', 'trainers'] as $name) {
    $source = $dir . DIRECTORY_SEPARATOR . $name . '.json';
    $decoded = json_decode((string)file_get_contents($source), true);
    if (!is_array($decoded)) {
      throw new Exception('Datei ' . $name . '.json ist ungültig.');
    }
    write_json($name, $decoded);
  }
}

function restore_backup(string $folderName): void {
  $folderPath = backup_folder_path($folderName);
  apply_backup_from_directory($folderPath);
}

function delete_backup(string $folderName): void {
  $folderPath = backup_folder_path($folderName);
  if (!is_dir($folderPath)) {
    throw new Exception('Backup wurde nicht gefunden.');
  }
  rrmdir($folderPath);
  $zipPath = backups_dir() . DIRECTORY_SEPARATOR . $folderName . '.zip';
  if (is_file($zipPath)) {
    @unlink($zipPath);
  }
}

function import_backup_zip(array $file): array {
  if (!class_exists('ZipArchive')) {
    throw new Exception('ZIP-Import ist auf diesem Server nicht verfügbar.');
  }
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new Exception('Backup-Datei konnte nicht hochgeladen werden.');
  }

  $tmpFile = (string)($file['tmp_name'] ?? '');
  if ($tmpFile === '' || !is_uploaded_file($tmpFile)) {
    throw new Exception('Ungültiger Upload.');
  }

  $baseName = pathinfo((string)($file['name'] ?? 'backup.zip'), PATHINFO_FILENAME);
  $safeBaseName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $baseName) ?: 'import';
  $stamp = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d_H-i-s');
  $folderName = 'season_backup_import_' . $stamp . '_' . trim($safeBaseName, '_');
  $extractDir = backups_dir() . DIRECTORY_SEPARATOR . '__import_' . uniqid('', true);
  if (!mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
    throw new Exception('Temporärer Import-Ordner konnte nicht erstellt werden.');
  }

  $zip = new ZipArchive();
  if ($zip->open($tmpFile) !== true) {
    rrmdir($extractDir);
    throw new Exception('ZIP-Datei konnte nicht geöffnet werden.');
  }
  $zip->extractTo($extractDir);
  $zip->close();

  $candidateDirs = [$extractDir];
  foreach (scandir($extractDir) ?: [] as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $extractDir . DIRECTORY_SEPARATOR . $item;
    if (is_dir($path)) {
      $candidateDirs[] = $path;
    }
  }

  $backupDir = null;
  foreach ($candidateDirs as $candidate) {
    if (backup_is_valid_directory($candidate)) {
      $backupDir = $candidate;
      break;
    }
  }

  if ($backupDir === null) {
    rrmdir($extractDir);
    throw new Exception('In der ZIP wurde kein gültiges Backup gefunden.');
  }

  $targetDir = backups_dir() . DIRECTORY_SEPARATOR . $folderName;
  if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
    rrmdir($extractDir);
    throw new Exception('Zielordner für den Import konnte nicht erstellt werden.');
  }

  foreach (array_merge(backup_required_files(), ['meta.json']) as $fileName) {
    $source = $backupDir . DIRECTORY_SEPARATOR . $fileName;
    if (is_file($source)) {
      copy($source, $targetDir . DIRECTORY_SEPARATOR . $fileName);
    }
  }

  $metaPath = $targetDir . DIRECTORY_SEPARATOR . 'meta.json';
  $meta = [];
  if (is_file($metaPath)) {
    $decoded = json_decode((string)file_get_contents($metaPath), true);
    if (is_array($decoded)) {
      $meta = $decoded;
    }
  }
  $meta['imported_at'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format(DATE_ATOM);
  $meta['imported_by'] = current_user()['username'] ?? 'system';
  $meta['folder'] = $folderName;
  file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

  create_backup_archive($folderName);
  rrmdir($extractDir);

  return [
    'folder_name' => $folderName,
    'folder_path' => $targetDir,
    'relative_path' => 'data/backups/' . $folderName,
    'zip_path' => $zipPath,
  ];
}

function reset_season_data(?array $keepPlayerIds = null): array {
  $playersBefore = read_json('players', []);
  $eventsBefore = read_json('events', []);
  $attendanceBefore = read_json('attendance', []);

  if ($keepPlayerIds === null) {
    $playersAfter = [];
  } else {
    $keepMap = [];
    foreach ($keepPlayerIds as $id) {
      $id = (int)$id;
      if ($id > 0) {
        $keepMap[$id] = true;
      }
    }

    $playersAfter = array_values(array_filter($playersBefore, function($player) use ($keepMap) {
      $id = (int)($player['id'] ?? 0);
      return $id > 0 && isset($keepMap[$id]);
    }));
  }

  write_json('players', $playersAfter);
  write_json('events', []);
  write_json('attendance', []);

  return [
    'players_before' => count($playersBefore),
    'players_after' => count($playersAfter),
    'players_removed' => max(0, count($playersBefore) - count($playersAfter)),
    'events_removed' => count($eventsBefore),
    'attendance_removed' => count($attendanceBefore),
  ];
}

