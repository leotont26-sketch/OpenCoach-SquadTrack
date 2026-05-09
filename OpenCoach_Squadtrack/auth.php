<?php
// auth.php — Session, Login, Rollen
declare(strict_types=1);
require_once __DIR__ . '/storage.php';

if (session_status() === PHP_SESSION_NONE) {
  // robustere Session für Hoster
  ini_set('session.use_strict_mode', '1');
  ini_set('session.cookie_httponly', '1');
  session_start();
}

function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

function require_login(): void {
  if (!current_user()) {
    header('Location: index.php?page=login');
    exit;
  }
}

function require_admin(): void {
  $u = current_user();
  if (!$u || empty($u['is_admin'])) {
    http_response_code(403);
    echo '<h1>403</h1><p>Kein Zugriff.</p>';
    exit;
  }
}

function user_by_username(string $username): ?array {
  $list = read_json('trainers', []);
  $needle = mb_strtolower($username);
  foreach ($list as $u) {
    if (mb_strtolower($u['username']) === $needle) return $u;
  }
  return null;
}

function save_user(array $user): void {
  $list = read_json('trainers', []);
  $found = false;
  foreach ($list as $i => $u) {
    if ((int)$u['id'] === (int)$user['id']) { $list[$i] = $user; $found = true; break; }
  }
  if (!$found) $list[] = $user;
  write_json('trainers', $list);
}

function login_user(string $username, string $password): bool {
  $u = user_by_username($username);
  if (!$u) return false;
  if (!password_verify($password, $u['password_hash'])) return false;
  $_SESSION['user'] = [
    'id' => (int)$u['id'],
    'username' => $u['username'],
    'is_admin' => !empty($u['is_admin'])
  ];
  return true;
}

function logout_user(): void {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
}

function change_password(int $user_id, string $new_password): bool {
  $list = read_json('trainers', []);
  foreach ($list as &$u) {
    if ((int)$u['id'] === $user_id) {
      $u['password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
      write_json('trainers', $list);
      return true;
    }
  }
  return false;
}
