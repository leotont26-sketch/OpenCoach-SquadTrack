<?php
declare(strict_types=1);

if (file_exists(__DIR__ . '/demo_config.php')) {
    require_once __DIR__ . '/demo_config.php';

    if (defined('DEMO_EXPIRES_AT') && time() > DEMO_EXPIRES_AT) {
        exit('Diese Demo ist abgelaufen. Bitte starte eine neue Demo.');
    }
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/fairness.php';
require_once __DIR__ . '/export_helpers.php';
if (is_file(__DIR__ . '/install_report.php')) {
    require_once __DIR__ . '/install_report.php';
    if (function_exists('squadtrack_install_report_bootstrap')) {
        squadtrack_install_report_bootstrap();
    }
}

$APP_SETTINGS = app_settings();

function setting_value(string $key, string $default = ''): string {
  global $APP_SETTINGS;
  return (string)($APP_SETTINGS[$key] ?? $default);
}

$page = $_GET['page'] ?? (current_user() ? 'dashboard' : 'login');
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

function set_flash(string $msg, string $type='success'){ $_SESSION['flash']=['msg'=>$msg,'type'=>$type]; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function post($key,$default=null){ return $_POST[$key] ?? $default; }
function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_check(){ if(($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { http_response_code(400); echo 'CSRF ungültig'; exit; }}

function normalize_event_type(string $type): string {
  $type = strtolower(trim($type));
  if (in_array($type, ['match', 'spiel', 'spieltag', 'game', 'turnier'], true)) return 'match';
  return 'training';
}

function event_type_label(string $type): string {
  return normalize_event_type($type) === 'match' ? 'Spieltag' : 'Training';
}

function event_type_icon(string $type): string {
  return normalize_event_type($type) === 'match' ? '⚽' : '🏃';
}

function is_holiday_event(array $event): bool {
  return !empty($event['is_holiday']);
}

function event_filter_options(): array {
  return [
    'all' => 'Alle Termine',
    'training_regular' => 'Training regulär (ohne Ferien)',
    'training_all' => 'Training gesamt',
    'match' => 'Nur Spieltage',
  ];
}

function normalize_filter_type(string $filterType): string {
  $allowed = array_keys(event_filter_options());
  return in_array($filterType, $allowed, true) ? $filterType : 'all';
}

function player_display_name(array $player): string {
  return trim((string)($player['first_name'] ?? '') . ' ' . (string)($player['last_name'] ?? ''));
}

function build_attendance_index(array $attendance): array {
  $map = [];
  foreach ($attendance as $a) {
    $eid = (int)($a['event_id'] ?? 0);
    $pid = (int)($a['player_id'] ?? 0);
    if ($eid > 0 && $pid > 0) {
      $map[$eid][$pid] = (string)($a['status'] ?? '');
    }
  }
  return $map;
}

function filter_events_by_date_range(array $events, string $dateFrom='', string $dateTo=''): array {
  if ($dateFrom === '' && $dateTo === '') return array_values($events);
  return array_values(array_filter($events, function($e) use ($dateFrom, $dateTo) {
    $eventDate = (string)($e['date'] ?? '');
    if ($eventDate === '') return false;
    if ($dateFrom !== '' && $eventDate < $dateFrom) return false;
    if ($dateTo !== '' && $eventDate > $dateTo) return false;
    return true;
  }));
}

function get_filtered_events(array $events, string $filterType, string $dateFrom='', string $dateTo=''): array {
  $events = filter_events_by_date_range($events, $dateFrom, $dateTo);
  $filterType = normalize_filter_type($filterType);
  return array_values(array_filter($events, function($e) use ($filterType) {
    $type = normalize_event_type((string)($e['type'] ?? 'training'));
    $isHoliday = is_holiday_event($e);

    return match ($filterType) {
      'training_regular' => $type === 'training' && !$isHoliday,
      'training_all' => $type === 'training',
      'match' => $type === 'match',
      default => true,
    };
  }));
}

function build_player_stats(array $players, array $events, array $attendance, string $filterType='all', string $dateFrom='', string $dateTo=''): array {
  $events = get_filtered_events($events, $filterType, $dateFrom, $dateTo);
  $eventIds = array_map('intval', array_column($events, 'id'));
  $stats = [];
  foreach ($players as $p) {
    $stats[(int)$p['id']] = [
      'id' => (int)$p['id'],
      'name' => player_display_name($p),
      'present' => 0,
      'absent' => 0,
      'total' => 0,
    ];
  }
  foreach ($attendance as $a) {
    if (!in_array((int)$a['event_id'], $eventIds, true)) continue;
    $pid = (int)($a['player_id'] ?? 0);
    if (!isset($stats[$pid])) continue;
    $status = (string)($a['status'] ?? '');
    if ($status === 'present') $stats[$pid]['present']++;
    else $stats[$pid]['absent']++;
    $stats[$pid]['total']++;
  }
  foreach ($stats as &$row) {
    $row['quote'] = $row['total'] > 0 ? (int)round(100 * $row['present'] / $row['total']) : 0;
  }
  unset($row);
  return $stats;
}

function build_monthly_summary(array $events, array $attendance, string $filterType='all', string $dateFrom='', string $dateTo='', array $playerIds=[]): array {
  $summary = [];
  $events = get_filtered_events($events, $filterType, $dateFrom, $dateTo);
  $playerIds = array_values(array_unique(array_filter(array_map('intval', $playerIds), fn($id) => $id > 0)));
  $filterPlayers = !empty($playerIds);
  $attIndex = build_attendance_index($attendance);
  $today = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
  foreach ($events as $e) {
    $eventDate = (string)($e['date'] ?? '');
    if ($eventDate === '' || $eventDate >= $today) continue;
    $monthKey = substr($eventDate, 0, 7);
    if ($monthKey === '') continue;
    if (!isset($summary[$monthKey])) {
      $summary[$monthKey] = ['key'=>$monthKey,'events'=>0,'present'=>0,'total'=>0];
    }
    $summary[$monthKey]['events']++;
    foreach (($attIndex[(int)$e['id']] ?? []) as $playerId => $status) {
      if ($filterPlayers && !in_array((int)$playerId, $playerIds, true)) continue;
      $summary[$monthKey]['total']++;
      if ($status === 'present') $summary[$monthKey]['present']++;
    }
  }
  krsort($summary);
  foreach ($summary as &$row) {
    $row['quote'] = $row['total'] > 0 ? (int)round(100 * $row['present'] / $row['total']) : 0;
    $dt = DateTime::createFromFormat('Y-m', $row['key']);
    $row['label'] = $dt ? $dt->format('m/Y') : $row['key'];
  }
  unset($row);
  return array_values($summary);
}

function build_event_summary(array $events, array $attendance, string $filterType='all', string $dateFrom='', string $dateTo='', array $playerIds=[]): array {
  $rows = [];
  $playerIds = array_values(array_unique(array_filter(array_map('intval', $playerIds), fn($id) => $id > 0)));
  $filterPlayers = !empty($playerIds);
  $attIndex = build_attendance_index($attendance);
  $events = get_filtered_events($events, $filterType, $dateFrom, $dateTo);
  $today = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
  $events = array_values(array_filter($events, function($e) use ($today) {
    $eventDate = (string)($e['date'] ?? '');
    return $eventDate !== '' && $eventDate < $today;
  }));
  usort($events, fn($a,$b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
  foreach ($events as $e) {
    $present = 0; $total = 0;
    foreach (($attIndex[(int)$e['id']] ?? []) as $playerId => $status) {
      if ($filterPlayers && !in_array((int)$playerId, $playerIds, true)) continue;
      $total++;
      if ($status === 'present') $present++;
    }
    $rows[] = [
      'id' => (int)$e['id'],
      'date' => (string)($e['date'] ?? ''),
      'type' => normalize_event_type((string)($e['type'] ?? 'training')),
      'note' => (string)($e['note'] ?? ''),
      'is_holiday' => is_holiday_event($e),
      'present' => $present,
      'total' => $total,
      'quote' => $total > 0 ? (int)round(100 * $present / $total) : 0,
    ];
  }
  return $rows;
}

function layout_header($title=''){ global $flash;
  $clubName = setting_value('club_name', 'JSG Haßmersheim / Hüffenhardt');
  $youthName = setting_value('youth_name', 'E-Jugend');
  $trackerName = setting_value('tracker_name', 'Anwesenheits-Tracker');
  $logoFile = setting_value('logo_file', 'logo.png');
  $pageTitle = trim('TeamBoard - ' . $clubName . ($youthName !== '' ? ' - ' . $youthName : ''));
?>
<!doctype html><html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?></title>


<link rel="icon" type="image/png" href="favicon-32.png">
<link rel="stylesheet" href="style.css">

<style>
.fairness-pill{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:6px 10px;
  border-radius:999px;
  font-size:12px;
  font-weight:700;
  line-height:1.2;
  white-space:nowrap;
}
.fairness-pill.good{background:#dcfce7;color:#166534;}
.fairness-pill.mid{background:#fef3c7;color:#92400e;}
.fairness-pill.bad{background:#fee2e2;color:#991b1b;}
.fairness-pill.muted{background:#e5e7eb;color:#4b5563;}
.fairness-note{
  display:block;
  margin-top:4px;
  font-size:12px;
}
.fairness-cell{
  min-width:220px;
}
</style>

</head><body>
</head><body>

<?php if (defined('DEMO_MODE') && DEMO_MODE === true): ?>
  <div class="demo-banner" data-demo-expires="<?= (int)DEMO_EXPIRES_AT ?>">
    <strong>DEMO-MODUS</strong>
    <span>Login: <b>admin</b></span>
    <span>Passwort: <b>12345678</b></span>
    <span>Noch verfügbar: <b id="demo-timer">30:00</b></span>
  </div>
<?php endif; ?>

<header class="site-header">
  <div class="header-content">
    <div class="logos">
      <img src="<?= h($logoFile) ?>" alt="<?= h($clubName) ?>" />
    </div>
    <div class="title">
      <h1><?= h($clubName) ?></h1>
      <?php if ($youthName !== ''): ?><div class="subtitle"><?= h($youthName) ?></div><?php endif; ?>
      <?php if ($trackerName !== ''): ?><div class="subtitle"><span class="muted"><?= h($trackerName) ?></span></div><?php endif; ?>
    </div>
  </div>
</header>

<?php if(current_user()): ?>
<div class="subnav-wrap">
<nav class="subnav">
  <a href="index.php?page=dashboard">Dashboard</a>
  <a href="index.php?page=attendance">Anwesenheit</a>
  <a href="index.php?page=analytics">Auswertung</a>
  <a href="index.php?page=players">Spieler</a>
  <a href="index.php?page=events">Termine</a>
  <?php if(current_user()['is_admin']): ?><a href="index.php?page=trainers">Trainer</a><?php endif; ?>
  <a href="index.php?page=account">Konto</a>
  <a href="index.php?page=logout">Logout</a>
</nav>
</div>
<?php endif; ?>
<main>
<?php if(!empty($flash)): ?>
  <div class="flash <?=$flash['type']??'success'?>"><?php echo h($flash['msg']??''); ?></div>
<?php endif; ?>
<?php }

function layout_footer(){
  $clubName = setting_value('club_name', 'JSG Haßmersheim / Hüffenhardt');
  $youthName = setting_value('youth_name', 'E-Jugend');
  $scrollTitle = trim('' . $clubName . ($youthName !== '' ? ' - ' . $youthName : '') . ' - ');
?>
</main>
<footer>
    <small><center>© <?=date('Y')?> OpenCoach - SquadTrack 
  <div>
    <a href="https://www.sportfreundehassmersheim.de" target="_blank" rel="noopener">
      by Spfr. Haßmersheim
    </a></center></small>
</div>  
</footer>
<script>
(function(){
  var titleText = <?= json_encode($scrollTitle, JSON_UNESCAPED_UNICODE) ?>;
  var i = 0;
  function scrollTitle(){
    document.title = titleText.slice(i) + titleText.slice(0, i);
    i = (i + 1) % titleText.length;
  }
  setInterval(scrollTitle, 250);
})();

(function(){
  document.querySelectorAll('[data-player-picker]').forEach(function(picker){
    var toggle = picker.querySelector('[data-player-picker-toggle]');
    var menu = picker.querySelector('[data-player-picker-menu]');
    var label = picker.querySelector('[data-player-picker-label]');
    var search = picker.querySelector('[data-player-picker-search]');
    var checks = Array.prototype.slice.call(picker.querySelectorAll('[data-player-picker-checkbox]'));
    var options = Array.prototype.slice.call(picker.querySelectorAll('[data-player-picker-option]'));
    var allBtn = picker.querySelector('[data-player-picker-all]');
    var noneBtn = picker.querySelector('[data-player-picker-none]');
    var closeBtn = picker.querySelector('[data-player-picker-close]');

    function updateLabel(){
      var selected = checks.filter(function(cb){ return cb.checked; });
      if (!selected.length) {
        label.textContent = 'Alle Spieler';
      } else if (selected.length === 1) {
        label.textContent = selected[0].closest('[data-player-picker-option]').querySelector('span').textContent.trim();
      } else {
        label.textContent = selected.length + ' Spieler ausgewählt';
      }
    }

    function setOpen(open){
      picker.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.body.classList.toggle('player-picker-open', open && window.matchMedia('(max-width: 720px)').matches);
      if (open && search) setTimeout(function(){ search.focus(); }, 40);
    }

    toggle.addEventListener('click', function(){
      setOpen(!picker.classList.contains('is-open'));
    });

    checks.forEach(function(cb){ cb.addEventListener('change', updateLabel); });

    if (allBtn) {
      allBtn.addEventListener('click', function(){
        checks.forEach(function(cb){ cb.checked = true; });
        updateLabel();
      });
    }

    if (noneBtn) {
      noneBtn.addEventListener('click', function(){
        checks.forEach(function(cb){ cb.checked = false; });
        updateLabel();
      });
    }

    if (closeBtn) {
      closeBtn.addEventListener('click', function(){ setOpen(false); });
    }

    if (search) {
      search.addEventListener('input', function(){
        var q = search.value.trim().toLowerCase();
        options.forEach(function(option){
          var text = option.textContent.toLowerCase();
          option.style.display = text.indexOf(q) !== -1 ? '' : 'none';
        });
      });
    }

    document.addEventListener('click', function(event){
      if (!picker.contains(event.target)) setOpen(false);
    });

    document.addEventListener('keydown', function(event){
      if (event.key === 'Escape') setOpen(false);
    });

    updateLabel();
  });
})();
</script>
<?php if (defined('DEMO_MODE') && DEMO_MODE === true): ?>
<script>
(function(){
  var banner = document.querySelector('[data-demo-expires]');
  var timer = document.getElementById('demo-timer');

  if (!banner || !timer) return;

  var expiresAt = parseInt(banner.getAttribute('data-demo-expires'), 10) * 1000;

  function updateDemoTimer(){
    var diff = Math.max(0, expiresAt - Date.now());
    var totalSeconds = Math.floor(diff / 1000);
    var minutes = Math.floor(totalSeconds / 60);
    var seconds = totalSeconds % 60;

    timer.textContent =
      String(minutes).padStart(2, '0') + ':' +
      String(seconds).padStart(2, '0');

    if (totalSeconds <= 0) {
      timer.textContent = 'abgelaufen';
    }
  }

  updateDemoTimer();
  setInterval(updateDemoTimer, 1000);
})();
</script>
<?php endif; ?>

</body></html>
<?php }

// ACTIONS
if ($page === 'login' && $_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  if (login_user((string)post('username'), (string)post('password'))) {
    set_flash('Login erfolgreich.');
    header('Location: index.php?page=dashboard'); exit;
  } else {
    set_flash('Login fehlgeschlagen.', 'error');
    header('Location: index.php?page=login'); exit;
  }
}

if ($page === 'logout') { logout_user(); header('Location: index.php?page=login'); exit; }

if ($page === 'players' && $_SERVER['REQUEST_METHOD']==='POST') { require_login(); csrf_check();
  $players = read_json('players', []);
if (post('action')==='create') {
  $first = trim((string)post('first_name'));
  $last  = trim((string)post('last_name'));

  if ($first === '') {
    set_flash('Vorname ist erforderlich.','error');
    header('Location: index.php?page=players'); exit;
  }

  // Prüfen, ob der Vorname schon existiert
  $exists = false;
  foreach ($players as $p) {
    if (strcasecmp($p['first_name'], $first) === 0) {
      $exists = true;
      break;
    }
  }

  // Wenn der Vorname doppelt ist, muss ein Nachname angegeben werden
  if ($exists && $last === '') {
    set_flash('Vorname existiert bereits. Bitte Nachname eintragen.','error');
    header('Location: index.php?page=players'); exit;
  }

  $players[] = [
    'id' => next_id($players),
    'first_name' => $first,
    'last_name' => $last,
    'active' => true
  ];
  write_json('players',$players);
  set_flash('Spieler angelegt.');
}

  if (post('action')==='update') {
    foreach ($players as &$p) if ((int)$p['id']==(int)post('id')) { $p['first_name']=trim((string)post('first_name')); $p['last_name']=trim((string)post('last_name')); $p['active']=isset($_POST['active']); }
    write_json('players',$players); set_flash('Spieler gespeichert.');
  }
  if (post('action')==='delete') {
    $players = array_values(array_filter($players, fn($p)=>(int)$p['id']!=(int)post('id')));
    write_json('players',$players); set_flash('Spieler gelöscht.');
  }
  header('Location: index.php?page=players'); exit;
}

if ($page === 'events' && $_SERVER['REQUEST_METHOD']==='POST') { require_login(); csrf_check();
  $events = read_json('events', []);
  if (post('action')==='create') {
    $type = normalize_event_type((string)post('type'));
    $events[] = [
      'id' => next_id($events),
      'date' => (string)post('date'),
      'type' => $type, // training | match
      'note' => trim((string)post('note')),
      'is_holiday' => $type === 'training' && isset($_POST['is_holiday']),
    ];
    write_json('events',$events); set_flash('Termin angelegt.');
  }
  if (post('action')==='delete') {
    $id = (int)post('id');
    $events = array_values(array_filter($events, fn($e)=>(int)$e['id']!==$id));
    write_json('events',$events);
    $att = read_json('attendance', []);
    $att = array_values(array_filter($att, fn($a)=>(int)$a['event_id']!==$id));
    write_json('attendance', $att);
    set_flash('Termin und zugehörige Anwesenheiten gelöscht.');
  }
  header('Location: index.php?page=events'); exit;
}

if ($page === 'attendance' && $_SERVER['REQUEST_METHOD']==='POST') { require_login(); csrf_check();
  $event_id = (int)post('event_id');
  $statuses = $_POST['status'] ?? []; // [player_id => present|absent]
  $att = read_json('attendance', []);
  $att = array_values(array_filter($att, fn($a)=>(int)$a['event_id']!==$event_id));
  foreach ($statuses as $pid=>$st) {
    $att[] = [ 'event_id'=>$event_id, 'player_id'=>(int)$pid, 'status'=>(string)$st ];
  }
  write_json('attendance', $att);
  set_flash('Anwesenheit gespeichert.');
  header('Location: index.php?page=attendance&event_id='.$event_id); exit;
}

if ($page === 'trainers' && $_SERVER['REQUEST_METHOD']==='POST') { require_login(); require_admin(); csrf_check();
  $users = read_json('trainers', []);
  if (post('action')==='create') {
    $username = trim((string)post('username'));
    foreach ($users as $u) { if (mb_strtolower($u['username'])===mb_strtolower($username)) { set_flash('Username existiert bereits.','error'); header('Location: index.php?page=trainers'); exit; } }
    $users[] = [
      'id' => next_id($users),
      'username' => $username,
      'password_hash' => password_hash((string)post('password'), PASSWORD_DEFAULT),
      'is_admin' => isset($_POST['is_admin'])
    ];
    write_json('trainers',$users); set_flash('Trainer angelegt.');
  }
  if (post('action')==='resetpw') {
    foreach ($users as &$u) if ((int)$u['id']===(int)post('id')) { $u['password_hash']=password_hash((string)post('password'), PASSWORD_DEFAULT); }
    write_json('trainers',$users); set_flash('Passwort zurückgesetzt.');
  }
  if (post('action')==='toggleadmin') {
    foreach ($users as &$u) if ((int)$u['id']===(int)post('id')) { $u['is_admin']=!((bool)$u['is_admin']); }
    write_json('trainers',$users); set_flash('Rolle geändert.');
  }
  if (post('action')==='delete') {
    $users = array_values(array_filter($users, fn($u)=>(int)$u['id']!=(int)post('id')));
    write_json('trainers',$users); set_flash('Trainer gelöscht.');
  }
  header('Location: index.php?page=trainers'); exit;
}

if ($page === 'account' && $_SERVER['REQUEST_METHOD']==='POST') { require_login(); csrf_check();
  $action = (string)post('action', 'password');

  try {

  if ($action === 'password') {
    $uid = (int)current_user()['id'];
    if (strlen((string)post('new_password'))<6) { set_flash('Passwort zu kurz.','error'); header('Location: index.php?page=account'); exit; }
    change_password($uid, (string)post('new_password'));
    set_flash('Passwort aktualisiert.');
    header('Location: index.php?page=account'); exit;
  }

  require_admin();

  if ($action === 'create_backup') {
    $backup = create_season_backup();
    $msg = 'Backup erstellt: ' . $backup['folder_name'];
    if (!empty($backup['zip_path'])) {
      $msg .= ' (inkl. ZIP-Datei)';
    }
    set_flash($msg);
    header('Location: index.php?page=account'); exit;
  }

  if ($action === 'download_backup') {
    $folderName = (string)post('backup_name');
    $zipPath = create_backup_archive($folderName) ?? '';
    if ($zipPath === '' || !is_file($zipPath)) {
      set_flash('ZIP-Download ist auf diesem Server nicht verfügbar.','error');
      header('Location: index.php?page=account'); exit;
    }

    header('Content-Type: application/zip');
    header('Content-Length: ' . (string)filesize($zipPath));
    header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($zipPath);
    exit;
  }

  if ($action === 'restore_backup') {
    $folderName = (string)post('backup_name');
    if ((string)post('confirm_restore') !== 'WIEDERHERSTELLEN') {
      set_flash('Wiederherstellung abgebrochen. Bitte WIEDERHERSTELLEN eingeben.','error');
      header('Location: index.php?page=account'); exit;
    }

    if (isset($_POST['create_backup_first'])) {
      create_season_backup();
    }

    restore_backup($folderName);
    set_flash('Backup ' . $folderName . ' wurde wiederhergestellt.');
    header('Location: index.php?page=account'); exit;
  }

  if ($action === 'delete_backup') {
    $folderName = (string)post('backup_name');
    if ((string)post('confirm_delete') !== 'LÖSCHEN') {
      set_flash('Löschen abgebrochen. Bitte LÖSCHEN eingeben.','error');
      header('Location: index.php?page=account'); exit;
    }

    delete_backup($folderName);
    set_flash('Backup ' . $folderName . ' wurde gelöscht.');
    header('Location: index.php?page=account'); exit;
  }

  if ($action === 'import_backup') {
    $backup = import_backup_zip($_FILES['backup_zip'] ?? []);
    set_flash('Backup importiert: ' . $backup['folder_name']);
    header('Location: index.php?page=account'); exit;
  }

  if ($action === 'save_branding') {
    $clubName = trim((string)post('club_name'));
    $youthName = trim((string)post('youth_name'));
    $trackerName = trim((string)post('tracker_name'));

    if ($clubName === '') {
      set_flash('Vereinsname darf nicht leer sein.','error');
      header('Location: index.php?page=account'); exit;
    }

    $settingsUpdate = [
      'club_name' => mb_substr($clubName, 0, 120),
      'youth_name' => mb_substr($youthName, 0, 80),
      'tracker_name' => mb_substr($trackerName, 0, 120),
    ];

    $file = $_FILES['club_logo'] ?? null;
    if (is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception('Logo konnte nicht hochgeladen werden.');
      }
      $tmp = (string)($file['tmp_name'] ?? '');
      if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new Exception('Ungültiger Logo-Upload.');
      }
      $info = @getimagesize($tmp);
      $mime = (string)($info['mime'] ?? '');
      $extMap = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
      ];
      if (!isset($extMap[$mime])) {
        throw new Exception('Logo muss PNG, JPG, WEBP oder GIF sein.');
      }
      $ext = $extMap[$mime];
      $targetFile = 'club_logo_custom.' . $ext;
      $targetPath = __DIR__ . DIRECTORY_SEPARATOR . $targetFile;
      foreach (glob(__DIR__ . DIRECTORY_SEPARATOR . 'club_logo_custom.*') ?: [] as $existingLogo) {
        if (is_file($existingLogo)) {
          @unlink($existingLogo);
        }
      }
      if (!move_uploaded_file($tmp, $targetPath)) {
        throw new Exception('Logo konnte nicht gespeichert werden.');
      }
      $settingsUpdate['logo_file'] = $targetFile;
    }

    save_app_settings($settingsUpdate);
    $GLOBALS['APP_SETTINGS'] = app_settings();
    set_flash('Vereinsdaten wurden aktualisiert.');
    header('Location: index.php?page=account'); exit;
  }

  if ($action === 'reset_season') {
    if ((string)post('confirm_reset') !== 'RESET') {
      set_flash('Reset abgebrochen. Bitte zur Bestätigung RESET eingeben.','error');
      header('Location: index.php?page=account'); exit;
    }

    $createBackupFirst = isset($_POST['create_backup_first']);
    if ($createBackupFirst) {
      create_season_backup();
    }

    $resetMode = (string)post('reset_player_mode', 'keep_selected');
    $keepPlayerIds = [];
    if ($resetMode === 'keep_selected') {
      foreach (($_POST['keep_player_ids'] ?? []) as $playerId) {
        $playerId = (int)$playerId;
        if ($playerId > 0) {
          $keepPlayerIds[] = $playerId;
        }
      }
    } elseif ($resetMode !== 'delete_all') {
      set_flash('Ungültige Reset-Auswahl. Schön versucht, kleines Formular-Goblin.', 'error');
      header('Location: index.php?page=account'); exit;
    }

    $result = reset_season_data($resetMode === 'keep_selected' ? $keepPlayerIds : null);
    set_flash('Saison zurückgesetzt: ' . (int)$result['players_after'] . ' Spieler übernommen, ' . (int)$result['players_removed'] . ' Spieler entfernt, ' . (int)$result['events_removed'] . ' Termine und ' . (int)$result['attendance_removed'] . ' Anwesenheiten gelöscht.');
    header('Location: index.php?page=account'); exit;
  }

  set_flash('Unbekannte Aktion.','error');
  header('Location: index.php?page=account'); exit;
  } catch (Throwable $e) {
    set_flash($e->getMessage(), 'error');
    header('Location: index.php?page=account'); exit;
  }
}

// VIEWS
if ($page === 'login') {
  layout_header('Login'); ?>
  <section class="card narrow">
    <h2>Anmeldung</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <label>Username<input name="username" autocomplete="username" required></label>
      <label>Passwort<input type="password" name="password" autocomplete="current-password" required></label>
      <button type="submit">Login</button>
    </form>
  </section>
<?php layout_footer(); exit; }

require_login();

if ($page === 'dashboard') { layout_header('Dashboard');
  $players = read_json('players', []);
  $events = read_json('events', []);
  $att = read_json('attendance', []);
  $total = count($players);
  $trainings = array_filter($events, fn($e)=>$e['type']==='training');
  $matches   = array_filter($events, fn($e)=>$e['type']==='match');
  ?>
  
<?php
// Heutigen Termin finden
$today = (new DateTime())->format('Y-m-d');
$todayEvent = null;
foreach ($events as $e) {
  if (($e['date'] ?? '') === $today) { $todayEvent = $e; break; }
}

if ($todayEvent) {
  // Map der heute bereits erfassten Stati
  $statusMap = [];
  foreach ($att as $a) {
    if ((int)$a['event_id'] === (int)$todayEvent['id']) {
      $statusMap[(int)$a['player_id']] = $a['status'];
    }
  }

  // aktive Spieler ohne gesetzten Status ermitteln
  $activePlayers = array_values(array_filter($players, fn($p)=>!isset($p['active']) || $p['active']));
  $missing = [];
  foreach ($activePlayers as $p) {
    $pid = (int)$p['id'];
    if (!isset($statusMap[$pid]) || $statusMap[$pid] === '') {
      $missing[] = trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? ''));
    }
  }

// ===== ZÄHLER: anwesend von aktiv (auch wenn noch unvollständig) =====
$presentCount = 0;
foreach ($statusMap as $st) {
  if ($st === 'present') $presentCount++;
}
$totalActive = count($activePlayers);

  if (!empty($missing)) {
    // Liste schön kürzen, falls es sehr viele sind
    $maxShow = 10;
    $shown = array_slice($missing, 0, $maxShow);
    $more  = max(0, count($missing) - $maxShow);
    ?>

	
	
	
    <?php
  }
    if (!empty($missing)) {
    // WARNBOX mit fehlenden Namen
    ?>
    <section class="alert-card">
  <h2>⚠️ Anwesenheit unvollständig</h2>
  <p>Beim heutigen Termin fehlt der Status für:</p>
  <p><strong><?= h(implode(', ', $shown)) ?></strong><?= $more ? ' … und '.$more.' weitere' : '' ?></p>

  <p class="attendance-counter">
    <strong><?= (int)$presentCount ?> von <?= (int)$totalActive ?></strong> anwesend
  </p>

  <p><a href="index.php?page=attendance&event_id=<?= (int)$todayEvent['id'] ?>">Jetzt vervollständigen</a></p>
</section>
    <?php
  } else {
    // ALLE ERFASST: heutige Anwesenheitszahl anzeigen
    $presentCount = 0;
    foreach ($statusMap as $st) {
      if ($st === 'present') $presentCount++;
    }
    $totalActive = count($activePlayers);
    ?>
    <section class="today-card good">
      <p><strong>Heute anwesend: <?= (int)$presentCount ?> von <?= (int)$totalActive ?></strong></p>
      <?php if(!empty($todayEvent['note'])): ?>
        
      <?php endif; ?>
      <p><a href="index.php?page=attendance&event_id=<?= (int)$todayEvent['id'] ?>">Anwesenheit öffnen</a></p>
    </section>
    <?php
  }

}
?>


  
  <?php
  $today = (new DateTime())->format('Y-m-d');
  $todayEvents = array_filter($events, fn($e) => $e['date'] === $today);
?>
<?php if ($todayEvents): ?>
  <?php foreach ($todayEvents as $ev): ?>
    <section class="today-card good">
      <h2><?= $ev['type']==='training' ? '⚽ Training heute 🥅' : '🏆 Spieltag heute 🏆' ?></h2>
      <p><strong><?= h($ev['date']) ?></strong> in 
      <?php if(!empty($ev['note'])): ?>
        <class="muted"><?= h($ev['note']) ?></p>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
<?php else: ?>
  <section class="today-card none">
    <h2>Heute kein Termin</h2>
    <p class="muted">Nächster Termin siehe unten "Diese Woche"</p>
  </section>
<?php endif; ?>

  
<section class="dashboard-summary">
  <h2>Diese Woche</h2>
  <?php
    // aktuelle Kalenderwoche bestimmen
    $start = new DateTimeImmutable('monday this week');
    $end   = (clone $start)->modify('+6 days');

    // NEU: ab heute zählen, nicht seit Wochenbeginn
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');

    $weekEvents = array_filter($events, fn($e) =>
      ($e['date'] >= $today && $e['date'] <= $end->format('Y-m-d'))
    );
    $weekTrainings = array_filter($weekEvents, fn($e)=>$e['type']==='training');
    $weekMatches   = array_filter($weekEvents, fn($e)=>$e['type']==='match');

    // nächsten Termin finden (nutzt das oben gesetzte $today)
    $next = null;
    foreach ($events as $e) {
      if (($e['date'] ?? '') >= $today) { $next = $e; break; }
    }

  ?>
  <div class="summary-grid">
    <div class="summary-item">
      <div class="number"><?= count($weekTrainings) ?></div>
      <div class="label">Training</div>
    </div>
    <div class="summary-item">
      <div class="number"><?= count($weekMatches) ?></div>
      <div class="label">Spieltage</div>
    </div>
  </div>

<?php
  // aktuelle Kalenderwoche bestimmen
  $start = new DateTimeImmutable('monday this week');
  $end   = (clone $start)->modify('+6 days');

  // Events sicher chronologisch sortieren (älteste -> jüngste)
  usort($events, fn($a,$b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));

  // Wochenfilter
  $weekEvents = array_filter($events, fn($e) =>
    (($e['date'] ?? '') >= $start->format('Y-m-d') && ($e['date'] ?? '') <= $end->format('Y-m-d'))
  );
  $weekTrainings = array_filter($weekEvents, fn($e)=>($e['type'] ?? '')==='training');
  $weekMatches   = array_filter($weekEvents, fn($e)=>($e['type'] ?? '')==='match');

  // Nächster Termin ab heute
  $today = (new DateTime('today'))->format('Y-m-d');
  $next = null;
  foreach ($events as $e) {
    if (($e['date'] ?? '') >= $today) { $next = $e; break; }
  }
?>

  <?php if($next): ?>
    <div class="next-event">
      Nächster Termin: <strong><?= h($next['date']) ?></strong>
      – <?= $next['type']==='training' ? 'Training' : 'Spieltag' ?>
      <?= !empty($next['note']) ? '(' . h($next['note']) . ')' : '' ?>
    </div>
  <?php else: ?>
    <div class="next-event muted">Keine weiteren Termine geplant.</div>
  <?php endif; ?>
</section>

  
<?php
// ====== BASIS-DATEN UND HEUTE ======
$today      = new DateTimeImmutable('today');
$todayStr   = $today->format('Y-m-d');

// Spieler gesamt (passe ggf. den Variablennamen an)
$players_total = isset($players) ? count($players) : (isset($allPlayers) ? count($allPlayers) : 0);

// ====== EVENTS AUFTEILEN ======
$byType = ['training' => [], 'match' => []];

foreach ($events as $e) {
    if (!isset($e['type'], $e['date'])) continue;

    // Robust: deutsch/englisch tolerieren
    $t = strtolower($e['type']);
    if ($t === 'spieltag' || $t === 'match' || $t === 'game') {
        $key = 'match';
    } else {
        $key = 'training';
    }
    $byType[$key][] = $e;
}

// Gesamt
$trainings_total = count($byType['training']);
$matches_total   = count($byType['match']);

// Bevorstehend / Vergangen relativ zu heute
$trainings_upcoming = 0; $trainings_past = 0;
foreach ($byType['training'] as $e) {
    ($e['date'] >= $todayStr) ? $trainings_upcoming++ : $trainings_past++;
}

$matches_upcoming = 0; $matches_past = 0;
foreach ($byType['match'] as $e) {
    ($e['date'] >= $todayStr) ? $matches_upcoming++ : $matches_past++;
}

// Heutiges Event (Training ODER Spieltag) für "heute anwesend"
$currentEvent = null;
foreach ($events as $e) {
    if (($e['date'] ?? '') === $todayStr) { $currentEvent = $e; break; }
}

// Anwesend heute zählen (passe diesen Block an dein Attendance-Schema an!)
$attending_today = 0;
if ($currentEvent) {
    // Variante A: $attendance['YYYY-mm-dd'][playerId] = 'anwesend'|'abwesend'|'krank'|true|false
    if (isset($attendance[$todayStr]) && is_array($attendance[$todayStr])) {
        foreach ($attendance[$todayStr] as $pid => $status) {
            if ($status === 'anwesend' || $status === 'present' || $status === 1 || $status === true) {
                $attending_today++;
            }
        }
    }
    // Variante B: $attendance ist Liste pro Spieler mit 'dates' Map
    elseif (isset($attendance) && is_array($attendance)) {
        foreach ($attendance as $rec) {
            $status = $rec['dates'][$todayStr] ?? null;
            if ($status === 'anwesend' || $status === 'present' || $status === 1 || $status === true) {
                $attending_today++;
            }
        }
    }
}
?>

<section class="stats">
  <!-- SPIELER -->
  <div class="card">
    <div class="stat-main"><?= $players_total ?></div>
    <div class="stat-sub">Spieler gesamt</div>
  </div>

  <!-- TRAININGS -->
  <div class="card">
    <div class="stat-main"><?= $trainings_total ?></div>
    <div class="stat-sub">Trainingseinheiten gesamt</div>
    <div class="stat-extra">
      Bevorstehend: <?= $trainings_upcoming ?> &nbsp;|&nbsp; Vergangene: <?= $trainings_past ?>
    </div>
  </div>

  <!-- SPIELTAGE -->
  <div class="card">
    <div class="stat-main"><?= $matches_total ?></div>
    <div class="stat-sub">Spieltage gesamt</div>
    <div class="stat-extra">
      Bevorstehend: <?= $matches_upcoming ?> &nbsp;|&nbsp; Vergangene: <?= $matches_past ?>
    </div>
  </div>
</section>
  
  
  
  <section class="teamstats">
  <h2>Team-Statistik</h2>
  <?php
    // Ø Spieler pro Training
    $trainEventIds = array_column(array_filter($events, fn($e)=>($e['type'] ?? '')==='training'), 'id');
    $trainingsAtt  = array_filter($att, fn($a)=>in_array($a['event_id'], $trainEventIds));
    $byEvent = [];
    foreach ($trainingsAtt as $a) {
      $eid = (int)$a['event_id'];
      if (!isset($byEvent[$eid])) $byEvent[$eid] = ['present'=>0,'total'=>0];
      $byEvent[$eid]['total']++;
      if (($a['status'] ?? '') === 'present') $byEvent[$eid]['present']++;
    }
    $avgPlayers = count($byEvent)
      ? round(array_sum(array_column($byEvent,'present')) / count($byEvent), 1)
      : 0;

    // Ø Abwesende pro Woche (letzte 4 Wochen)
    $monthAgo = (new DateTime('-4 weeks'))->format('Y-m-d');
    $recentEventIds = array_column(array_filter($events, fn($e)=>($e['date'] ?? '') >= $monthAgo), 'id');
    $absentRecent = 0; $totalRecent = 0;
    foreach ($att as $a) {
      if (in_array($a['event_id'], $recentEventIds)) {
        $totalRecent++;
        if (($a['status'] ?? '') !== 'present') $absentRecent++;
      }
    }
    $avgAbsent = $totalRecent ? round($absentRecent / 4, 1) : 0;
  ?>
  <ul>
    <li>Ø Spieler pro Training: <strong><?= h((string)$avgPlayers) ?></strong></li>
  </ul>
</section>

<?php
  $trainingStats = build_player_stats($players, $events, $att, 'training');
  $trainingLeaderboard = array_values(array_filter($trainingStats, fn($row) => $row['total'] > 0));
  usort($trainingLeaderboard, fn($a,$b) => ($b['quote'] <=> $a['quote']) ?: ($b['total'] <=> $a['total']) ?: strcasecmp($a['name'], $b['name']));
  $trainingLeaderboard = array_slice($trainingLeaderboard, 0, 5);

  $trainingMuffels = array_values(array_filter($trainingStats, fn($row) => $row['total'] > 0));
  usort($trainingMuffels, fn($a,$b) => ($a['quote'] <=> $b['quote']) ?: ($b['total'] <=> $a['total']) ?: strcasecmp($a['name'], $b['name']));
  $trainingMuffels = array_slice($trainingMuffels, 0, 5);
?>
<section class="dashboard-extras-grid">
  <div class="card">
    <h2>Trainingsmuffel 😴</h2>
    <?php if (empty($trainingMuffels)): ?>
      <p class="muted">Noch keine Trainingsdaten vorhanden.</p>
    <?php else: ?>
      <div class="leaderboard compact">
        <?php
          $lastQuote = null;
          $rank = 0;
          foreach ($trainingMuffels as $row):
            if ($lastQuote === null || (int)$row['quote'] !== (int)$lastQuote) {
              $rank++;
              $lastQuote = (int)$row['quote'];
            }
        ?>
          <div class="leaderboard-item">
            <span class="rank">Top <?= (int)$rank ?></span>
            <a href="index.php?page=player&id=<?= (int)$row['id'] ?>&type=training"><?= h($row['name']) ?></a>
            <strong><?= (int)$row['quote'] ?>%</strong>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Trainingstars ⭐</h2>
    <?php if (empty($trainingLeaderboard)): ?>
      <p class="muted">Noch keine Trainingsdaten vorhanden.</p>
    <?php else: ?>
      <div class="leaderboard compact">
        <?php
          $lastQuote = null;
          $rank = 0;
          foreach ($trainingLeaderboard as $row):
            if ($lastQuote === null || (int)$row['quote'] !== (int)$lastQuote) {
              $rank++;
              $lastQuote = (int)$row['quote'];
            }
        ?>
          <div class="leaderboard-item">
            <span class="rank">Top <?= (int)$rank ?></span>
            <a href="index.php?page=player&id=<?= (int)$row['id'] ?>&type=training"><?= h($row['name']) ?></a>
            <strong><?= (int)$row['quote'] ?>%</strong>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
 
<?php layout_footer(); exit; }

if ($page === 'players') { layout_header('Spieler');
  $players = read_json('players', []);
  usort($players, fn($a, $b) => strcasecmp($a['first_name'], $b['first_name']));
  ?>
  <section class="card">
    <h2>Spieler hinzufügen</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="create">
      <div class="row">
        <label>Vorname<input name="first_name" required></label>
        <label>Nachname<input name="last_name"></label>
        <button type="submit">Anlegen</button>
      </div>
    </form>
  </section>
  <section class="card">
    <h2>Spieler-Liste</h2>
    <div class="tablewrap">
    <table class="responsive-table players-table">
      <thead><tr><th>Name</th><th>Aktiv</th><th>Aktion</th></tr></thead>
      <tbody>
      <?php foreach ($players as $p): ?>
        <tr>
          <td><?= h($p['first_name'].' '.$p['last_name']) ?></td>
          <td><?= !empty($p['active'])?'✓':'✗' ?></td>
          <td>
            <form method="post" class="inline">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <input name="first_name" value="<?= h($p['first_name']) ?>">
              <input name="last_name" value="<?= h($p['last_name']) ?>">
              <label class="chk"><input type="checkbox" name="active" <?= !empty($p['active'])?'checked':'' ?>> aktiv</label>
              <button type="submit" class="btn-save">Speichern</button>
            </form>
            <form method="post" class="inline" onsubmit="return confirm('Löschen?')">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="danger">Löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>
<?php layout_footer(); exit; }

if ($page === 'events') { layout_header('Termine');
  $events = read_json('events', []);
  usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));

  $todayStr = (new DateTime('today'))->format('Y-m-d');
  $upcomingEvents = array_values(array_filter($events, fn($e) => ($e['date'] ?? '') >= $todayStr));
  $pastEvents     = array_values(array_filter($events, fn($e) => ($e['date'] ?? '') < $todayStr));
  usort($pastEvents, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
  ?>
  <section class="card">
    <h2>Termin anlegen</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="create">
      <div class="row">
        <label>Datum<input type="date" name="date" required></label>
        <label>Typ
          <select name="type">
            <option value="training">Training</option>
            <option value="match">Spieltag</option>
          </select>
        </label>
        <label>Ort<input name="note" placeholder="optional"></label>
        <label class="chk holiday-check"><input type="checkbox" name="is_holiday"> Ferien</label>
        <button type="submit" class="btn-save">Speichern</button>
      </div>
    </form>
  </section>
  <section class="card">
    <div class="events-header-row">
      <div>
        <h2>Bevorstehende Termine</h2>
        <div class="muted"><?= count($upcomingEvents) ?> sichtbar<?= $pastEvents ? ' · '.count($pastEvents).' vergangene eingeklappt' : '' ?></div>
      </div>
    </div>

    <?php if ($pastEvents): ?>
      <details class="past-events-toggle">
        <summary>Vergangene Termine anzeigen (<?= count($pastEvents) ?>)</summary>
        <div class="tablewrap past-events-table">
          <table class="responsive-table events-table">
            <thead><tr><th>Datum</th><th>Typ</th><th>Ort</th><th>Aktion</th></tr></thead>
            <tbody>
            <?php foreach ($pastEvents as $e): ?>
              <tr>
                <td><?= h($e['date']) ?></td>
                <td>
                  <?= h(($e['type']==='training')?'Training':'Spieltag') ?>
                  <?php if (!empty($e['is_holiday']) && ($e['type'] ?? '') === 'training'): ?><span class="holiday-badge">Ferien</span><?php endif; ?>
                </td>
                <td><?= h($e['note']??'') ?></td>
                <td>
                  <form method="post" class="inline" onsubmit="return confirm('Termin löschen inkl. Anwesenheiten?')">
                    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                    <button class="danger">Löschen</button>
                  </form>
                  <a class="btn success" href="index.php?page=attendance&event_id=<?= (int)$e['id'] ?>">Anwesenheit</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    <?php endif; ?>

    <?php if ($upcomingEvents): ?>
      <div class="tablewrap">
        <table class="responsive-table events-table">
          <thead><tr><th>Datum</th><th>Typ</th><th>Ort</th><th>Aktion</th></tr></thead>
          <tbody>
          <?php foreach ($upcomingEvents as $e): ?>
            <tr>
              <td><?= h($e['date']) ?></td>
              <td>
                <?= h(($e['type']==='training')?'Training':'Spieltag') ?>
                <?php if (!empty($e['is_holiday']) && ($e['type'] ?? '') === 'training'): ?><span class="holiday-badge">Ferien</span><?php endif; ?>
              </td>
              <td><?= h($e['note']??'') ?></td>
              <td>
                <form method="post" class="inline" onsubmit="return confirm('Termin löschen inkl. Anwesenheiten?')">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                  <button class="danger">Löschen</button>
                </form>
                <a class="btn success" href="index.php?page=attendance&event_id=<?= (int)$e['id'] ?>">Anwesenheit</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="muted">Keine bevorstehenden Termine vorhanden.</p>
    <?php endif; ?>


  </section>
<?php layout_footer(); exit; }

if ($page === 'attendance') { layout_header('Anwesenheit');
  $players = array_values(array_filter(read_json('players', []), fn($p)=>!isset($p['active']) || $p['active']));
  usort($players, fn($a,$b) => strcasecmp($a['first_name'], $b['first_name']));
  $events = read_json('events', []);

usort($events, fn($a,$b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));

$today     = (new DateTime('today'))->format('Y-m-d');
$requested = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
$validIds  = array_map('intval', array_column($events, 'id'));

if (in_array($requested, $validIds, true)) {
  // Nutzer hat bewusst einen Termin gewählt: nimm den.
  $event_id = $requested;
} else {
  // Keine gültige Auswahl: nimm den nächsten anstehenden, sonst den letzten vergangenen.
  $event_id = 0;
  foreach ($events as $e) {
    if (($e['date'] ?? '') >= $today) { $event_id = (int)$e['id']; break; }
  }
  if (!$event_id && $events) {
    $event_id = (int)$events[count($events)-1]['id'];
  }
}

  $att = read_json('attendance', []);
  $map = [];
  foreach ($att as $a) if ((int)$a['event_id']===$event_id) $map[(int)$a['player_id']]=$a['status'];
  ?>
  <section class="card">
    <form method="get" class="row">
      <input type="hidden" name="page" value="attendance">
      <label>Termin
        <select name="event_id" onchange="this.form.submit()">
          <?php foreach ($events as $e): ?>
            <option value="<?= (int)$e['id'] ?>" <?= $event_id===(int)$e['id']?'selected':'' ?>><?= h($e['date'].' · '.($e['type']==='training'?'Training':'Spieltag').(!empty($e['is_holiday']) && ($e['type'] ?? '') === 'training' ? ' · Ferien' : '')) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Von
        <input type="date" name="from" value="<?= h($dateFrom) ?>">
      </label>
      <label>Bis
        <input type="date" name="to" value="<?= h($dateTo) ?>">
      </label>
      <button class="btn" type="submit">Filtern</button>
    </form>
  </section>
  <section class="card">
    <h2>Erfassen</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="event_id" value="<?= (int)$event_id ?>">
      <table class="responsive-table attendance-table">
        <thead><tr><th>Spieler</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($players as $p): $pid=(int)$p['id']; $st=$map[$pid]??''; ?>
            <tr>
              <td><?= h($p['first_name'].' '.$p['last_name']) ?></td>
              <td>
                <div class="status-group">
  <label class="status-btn status-present">
    <input type="radio" name="status[<?= $pid ?>]" value="present" <?= $st==='present'?'checked':'' ?>>
    <span>Anwesend</span>
  </label>

  <label class="status-btn status-absent">
    <input type="radio" name="status[<?= $pid ?>]" value="absent" <?= $st==='absent'?'checked':'' ?>>
    <span>Abwesend</span>
  </label>
</div>

              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <button type="submit" class="btn-save">Speichern</button>
    </form>
  </section>
<?php layout_footer(); exit; }


if ($page === 'player') { require_login(); layout_header('Spielerprofil');
  $playerId = (int)($_GET['id'] ?? 0);
  $filterType = normalize_filter_type((string)($_GET['type'] ?? 'all'));
  $players = read_json('players', []);
  $events = read_json('events', []);
  $attendance = read_json('attendance', []);
  $player = null;
  foreach ($players as $p) {
    if ((int)($p['id'] ?? 0) === $playerId) { $player = $p; break; }
  }

  if (!$player) {
    echo '<section class="card"><h2>Spieler nicht gefunden</h2><p class="muted">Der Spieler existiert nicht oder wurde gelöscht.</p><p><a class="btn" href="index.php?page=analytics">Zurück zur Auswertung</a></p></section>';
    layout_footer(); exit;
  }

  $playerName = player_display_name($player);
  $attendanceIndex = build_attendance_index($attendance);
  $dateFrom = trim((string)($_GET['from'] ?? ''));
  $dateTo = trim((string)($_GET['to'] ?? ''));
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = '';
  $events = get_filtered_events($events, $filterType, $dateFrom, $dateTo);
  usort($events, fn($a,$b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));

  $history = [];
  $present = 0; $total = 0;
  $trainingPresent = 0; $trainingTotal = 0;
  $matchPresent = 0; $matchTotal = 0;

  foreach ($events as $e) {
    $status = $attendanceIndex[(int)$e['id']][$playerId] ?? null;
    if ($status === null || $status === '') continue;
    $type = normalize_event_type((string)($e['type'] ?? 'training'));
    $isPresent = $status === 'present';
    $history[] = [
      'date' => (string)($e['date'] ?? ''),
      'type' => $type,
      'note' => (string)($e['note'] ?? ''),
      'status' => $status,
      'icon' => event_type_icon($type),
      'label' => event_type_label($type),
      'is_holiday' => is_holiday_event($e),
    ];
    $total++;
    if ($isPresent) $present++;
    if ($type === 'training') {
      $trainingTotal++;
      if ($isPresent) $trainingPresent++;
    } else {
      $matchTotal++;
      if ($isPresent) $matchPresent++;
    }
  }

  $quote = $total > 0 ? (int)round(100 * $present / $total) : 0;
  $trainingQuote = $trainingTotal > 0 ? (int)round(100 * $trainingPresent / $trainingTotal) : 0;
  $matchQuote = $matchTotal > 0 ? (int)round(100 * $matchPresent / $matchTotal) : 0;
  ?>
  <section class="card player-profile-hero">
    <div class="player-profile-head">
      <div>
        <div class="muted">Spielerprofil</div>
        <h2><?= h($playerName) ?></h2>
      </div>
      <a class="btn" href="index.php?page=analytics&type=<?= h($filterType) ?>&from=<?= h($dateFrom) ?>&to=<?= h($dateTo) ?>">Zurück zur Auswertung</a>
    </div>

    <form method="get" class="row profile-filter-row">
      <input type="hidden" name="page" value="player">
      <input type="hidden" name="id" value="<?= (int)$playerId ?>">
      <label>Ansicht
        <select name="type" onchange="this.form.submit()">
          <?php foreach (event_filter_options() as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= $filterType===$value?'selected':'' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Von
        <input type="date" name="from" value="<?= h($dateFrom) ?>">
      </label>
      <label>Bis
        <input type="date" name="to" value="<?= h($dateTo) ?>">
      </label>
      <button class="btn" type="submit">Filtern</button>
    </form>

    <div class="profile-kpis">
      <div class="summary-item"><div class="number"><?= (int)$quote ?>%</div><div class="label">Gesamtquote</div></div>
      <div class="summary-item"><div class="number"><?= (int)$trainingQuote ?>%</div><div class="label">Training</div></div>
      <div class="summary-item"><div class="number"><?= (int)$matchQuote ?>%</div><div class="label">Spieltage</div></div>
      <div class="summary-item"><div class="number"><?= (int)$present ?>/<?= (int)$total ?></div><div class="label">Anwesend / Erfasst</div></div>
    </div>
    <?php
      $fairness = squadtrack_calculate_fairness($trainingQuote, $matchQuote, $trainingTotal, $matchTotal);
    ?>
    <div style="margin-top:14px;">
      <span class="fairness-pill <?= h($fairness['class']) ?>">Fairness: <?= h($fairness['short']) ?></span>
      <span class="fairness-note muted"><?= h($fairness['text']) ?></span>
    </div>
  </section>

  <section class="card">
    <h2>Historie</h2>

    <?php if (empty($history)): ?>
      <p class="muted">Für diesen Spieler sind in der gewählten Ansicht noch keine Anwesenheiten erfasst.</p>
    <?php else: ?>
      <div class="player-history-table tablewrap">
        <table class="responsive-table history-table">
          <thead><tr><th>Datum</th><th>Typ</th><th>Status</th><th>Ort</th></tr></thead>
          <tbody>
          <?php foreach ($history as $row): ?>
            <tr>
              <td data-label="Datum"><?= h($row['date']) ?></td>
              <td data-label="Typ"><span class="type-pill <?= h($row['type']) ?>"><?= h($row['icon']) ?> <?= h($row['label']) ?></span><?php if (!empty($row['is_holiday']) && $row['type'] === 'training'): ?><span class="holiday-badge">Ferien</span><?php endif; ?></td>
              <td><span class="status-mark <?= $row['status']==='present' ? 'ok' : 'nope' ?>"><?= $row['status']==='present' ? '✓' : '✗' ?></span></td>
              <td data-label="Ort"><?= h($row['note']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="player-history-cards">
        <?php foreach ($history as $row): ?>
          <div class="history-card">
            <div class="history-top">
              <strong><?= h($row['date']) ?></strong>
              <span class="status-mark <?= $row['status']==='present' ? 'ok' : 'nope' ?>"><?= $row['status']==='present' ? '✓' : '✗' ?></span>
            </div>
            <div class="history-meta">
              <span class="type-pill <?= h($row['type']) ?>"><?= h($row['icon']) ?> <?= h($row['label']) ?></span>
              <?php if (!empty($row['is_holiday']) && $row['type'] === 'training'): ?><span class="holiday-badge">Ferien</span><?php endif; ?>
              <?php if ($row['note'] !== ''): ?><span class="muted"><?= h($row['note']) ?></span><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
<?php layout_footer(); exit; }


if ($page === 'export') { layout_header('Export');
  require_login();
  $players = read_json('players', []);
  usort($players, fn($a, $b) => strcasecmp(st_player_name($a), st_player_name($b)));
  $filters = st_normalize_export_filters($_GET, $players);

  $selectedPlayerNames = [];
  foreach ($players as $playerOption) {
    $optionId = (int)($playerOption['id'] ?? 0);
    if (in_array($optionId, $filters['player_ids'], true)) {
      $selectedPlayerNames[] = st_player_name($playerOption);
    }
  }
  $playerFilterLabel = 'Alle Spieler';
  if (count($selectedPlayerNames) === 1) {
    $playerFilterLabel = $selectedPlayerNames[0];
  } elseif (count($selectedPlayerNames) > 1) {
    $playerFilterLabel = count($selectedPlayerNames) . ' Spieler ausgewählt';
  }
  ?>
  <section class="card analytics-filter-card">
    <h2>Export erstellen</h2>

    <form method="get" class="analytics-filter-form">
      <div class="analytics-filter-primary">
        <div class="analytics-player-picker" data-player-picker>
          <div class="filter-label">Spieler</div>
          <button class="player-picker-toggle" type="button" data-player-picker-toggle aria-expanded="false">
            <span data-player-picker-label><?= h($playerFilterLabel) ?></span>
            <span class="player-picker-caret">▾</span>
          </button>
          <div class="player-picker-menu" data-player-picker-menu>
            <div class="player-picker-mobile-head">
              <strong>Spieler auswählen</strong>
              <button type="button" class="mini-btn" data-player-picker-close>Fertig</button>
            </div>
            <input class="player-picker-search" type="search" placeholder="Spieler suchen..." data-player-picker-search>
            <div class="player-picker-actions">
              <button type="button" class="mini-btn" data-player-picker-all>Alle auswählen</button>
              <button type="button" class="mini-btn" data-player-picker-none>Leeren</button>
            </div>
            <div class="player-picker-list">
              <?php foreach ($players as $playerOption): $optionId = (int)($playerOption['id'] ?? 0); $optionName = st_player_name($playerOption); ?>
                <label class="player-picker-option" data-player-picker-option>
                  <input type="checkbox" name="player_ids[]" value="<?= $optionId ?>" <?= in_array($optionId, $filters['player_ids'], true) ? 'checked' : '' ?> data-player-picker-checkbox>
                  <span><?= h($optionName) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="analytics-filter-grid">
        <label>Von
          <input type="date" name="from" value="<?= h($filters['from']) ?>">
        </label>
        <label>Bis
          <input type="date" name="to" value="<?= h($filters['to']) ?>">
        </label>
        <label>Event-Typ
          <select name="event_type">
            <option value="all" <?= $filters['event_type']==='all'?'selected':'' ?>>Training + Spieltage</option>
            <option value="training" <?= $filters['event_type']==='training'?'selected':'' ?>>Nur Training</option>
            <option value="match" <?= $filters['event_type']==='match'?'selected':'' ?>>Nur Spieltage</option>
          </select>
        </label>
        <label>Ferien
          <select name="holiday_mode">
            <option value="include" <?= $filters['holiday_mode']==='include'?'selected':'' ?>>Ferien einbeziehen</option>
            <option value="exclude" <?= $filters['holiday_mode']==='exclude'?'selected':'' ?>>Ferien ausschließen</option>
            <option value="only" <?= $filters['holiday_mode']==='only'?'selected':'' ?>>Nur Ferien-Training</option>
          </select>
        </label>
      </div>

      <div class="analytics-filter-actions">
        <label class="analytics-switch">
          <input type="checkbox" name="fairness" value="1" <?= $filters['fairness'] ? 'checked' : '' ?>>
          <span class="analytics-switch-ui" aria-hidden="true"></span>
          <span>Fairness anzeigen</span>
        </label>
        <div class="analytics-action-buttons">
          <button class="btn success" type="submit" formaction="export_pdf.php">Als PDF exportieren</button>
          <button class="btn" type="submit" formaction="export_excel.php">Als Excel exportieren</button>
        </div>
      </div>
    </form>
  </section>

<?php layout_footer(); exit; }

if ($page === 'analytics') { layout_header('Auswertung');
  $players = read_json('players', []);
  $events = read_json('events', []);
  $att = read_json('attendance', []);

  $filter_type = normalize_filter_type((string)($_GET['type'] ?? 'all'));
  $show_fairness = isset($_GET['fairness']) && $_GET['fairness'] === '1';
  $sort = $_GET['sort'] ?? 'name_asc';
  $date_from = trim((string)($_GET['from'] ?? ''));
  $date_to = trim((string)($_GET['to'] ?? ''));
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = '';

  $selected_player_ids = [];
  foreach (($_GET['player_ids'] ?? []) as $rawPlayerId) {
    $playerId = (int)$rawPlayerId;
    if ($playerId > 0) $selected_player_ids[] = $playerId;
  }
  $selected_player_ids = array_values(array_unique($selected_player_ids));
  $availablePlayerIds = array_map(fn($p) => (int)($p['id'] ?? 0), $players);
  $selected_player_ids = array_values(array_intersect($selected_player_ids, $availablePlayerIds));
  $players_for_stats = !empty($selected_player_ids)
    ? array_values(array_filter($players, fn($p) => in_array((int)($p['id'] ?? 0), $selected_player_ids, true)))
    : $players;
  usort($players, fn($a, $b) => strcasecmp(player_display_name($a), player_display_name($b)));

  $byPlayer = build_player_stats($players_for_stats, $events, $att, $filter_type, $date_from, $date_to);
  $trainingFairnessStats = build_player_stats($players_for_stats, $events, $att, 'training_regular', $date_from, $date_to);
  $matchFairnessStats = build_player_stats($players_for_stats, $events, $att, 'match', $date_from, $date_to);
  $fairnessByPlayer = [];
  foreach ($players_for_stats as $playerRow) {
    $pid = (int)($playerRow['id'] ?? 0);
    $trainingRow = $trainingFairnessStats[$pid] ?? ['quote'=>0,'total'=>0];
    $matchRow = $matchFairnessStats[$pid] ?? ['quote'=>0,'total'=>0];
    $fairnessByPlayer[$pid] = squadtrack_calculate_fairness(
      (int)($trainingRow['quote'] ?? 0),
      (int)($matchRow['quote'] ?? 0),
      (int)($trainingRow['total'] ?? 0),
      (int)($matchRow['total'] ?? 0)
    );
  }
  uasort($byPlayer, function($a, $b) use ($sort) {
    $rateA = $a['quote'] ?? 0;
    $rateB = $b['quote'] ?? 0;
    return match ($sort) {
      'name_desc' => strcasecmp($b['name'], $a['name']),
      'present_desc' => ($b['present'] <=> $a['present']) ?: strcasecmp($a['name'], $b['name']),
      'present_asc' => ($a['present'] <=> $b['present']) ?: strcasecmp($a['name'], $b['name']),
      'absent_desc' => ($b['absent'] <=> $a['absent']) ?: strcasecmp($a['name'], $b['name']),
      'absent_asc' => ($a['absent'] <=> $b['absent']) ?: strcasecmp($a['name'], $b['name']),
      'total_desc' => ($b['total'] <=> $a['total']) ?: strcasecmp($a['name'], $b['name']),
      'total_asc' => ($a['total'] <=> $b['total']) ?: strcasecmp($a['name'], $b['name']),
      'quote_desc' => ($rateB <=> $rateA) ?: strcasecmp($a['name'], $b['name']),
      'quote_asc' => ($rateA <=> $rateB) ?: strcasecmp($a['name'], $b['name']),
      default => strcasecmp($a['name'], $b['name']),
    };
  });

  $monthlyStats = build_monthly_summary($events, $att, $filter_type, $date_from, $date_to, $selected_player_ids);
  $eventStats = build_event_summary($events, $att, $filter_type, $date_from, $date_to, $selected_player_ids);
  $topPlayers = array_values(array_filter($byPlayer, fn($row) => $row['total'] > 0));
  usort($topPlayers, fn($a,$b) => ($b['quote'] <=> $a['quote']) ?: ($b['total'] <=> $a['total']) ?: strcasecmp($a['name'], $b['name']));
  $topPlayers = array_slice($topPlayers, 0, 5);

  $selectedPlayerNames = [];
  foreach ($players as $playerOption) {
    $optionId = (int)($playerOption['id'] ?? 0);
    if (in_array($optionId, $selected_player_ids, true)) {
      $selectedPlayerNames[] = player_display_name($playerOption);
    }
  }
  $playerFilterLabel = 'Alle Spieler';
  if (count($selectedPlayerNames) === 1) {
    $playerFilterLabel = $selectedPlayerNames[0];
  } elseif (count($selectedPlayerNames) > 1) {
    $playerFilterLabel = count($selectedPlayerNames) . ' Spieler ausgewählt';
  }
  ?>
  <section class="card analytics-filter-card">
    <form method="get" class="analytics-filter-form">
      <input type="hidden" name="page" value="analytics">

      <div class="analytics-filter-primary">
        <div class="analytics-player-picker" data-player-picker>
          <div class="filter-label">Spieler</div>
          <button class="player-picker-toggle" type="button" data-player-picker-toggle aria-expanded="false">
            <span data-player-picker-label><?= h($playerFilterLabel) ?></span>
            <span class="player-picker-caret">▾</span>
          </button>
          <div class="player-picker-menu" data-player-picker-menu>
            <div class="player-picker-mobile-head">
              <strong>Spieler auswählen</strong>
              <button type="button" class="mini-btn" data-player-picker-close>Fertig</button>
            </div>
            <input class="player-picker-search" type="search" placeholder="Spieler suchen..." data-player-picker-search>
            <div class="player-picker-actions">
              <button type="button" class="mini-btn" data-player-picker-all>Alle auswählen</button>
              <button type="button" class="mini-btn" data-player-picker-none>Leeren</button>
            </div>
            <div class="player-picker-list">
              <?php foreach ($players as $playerOption): $optionId = (int)($playerOption['id'] ?? 0); $optionName = player_display_name($playerOption); ?>
                <label class="player-picker-option" data-player-picker-option>
                  <input type="checkbox" name="player_ids[]" value="<?= $optionId ?>" <?= in_array($optionId, $selected_player_ids, true) ? 'checked' : '' ?> data-player-picker-checkbox>
                  <span><?= h($optionName) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="analytics-filter-grid">
        <label>Filter
          <select name="type" onchange="this.form.submit()">
            <?php foreach (event_filter_options() as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $filter_type===$value?'selected':'' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Von
          <input type="date" name="from" value="<?= h($date_from) ?>">
        </label>
        <label>Bis
          <input type="date" name="to" value="<?= h($date_to) ?>">
        </label>
        <label>Sortierung
          <select name="sort" onchange="this.form.submit()">
            <option value="name_asc" <?= $sort==='name_asc'?'selected':'' ?>>Name A-Z</option>
            <option value="name_desc" <?= $sort==='name_desc'?'selected':'' ?>>Name Z-A</option>
            <option value="present_desc" <?= $sort==='present_desc'?'selected':'' ?>>Anwesend absteigend</option>
            <option value="present_asc" <?= $sort==='present_asc'?'selected':'' ?>>Anwesend aufsteigend</option>
            <option value="absent_desc" <?= $sort==='absent_desc'?'selected':'' ?>>Abwesend absteigend</option>
            <option value="absent_asc" <?= $sort==='absent_asc'?'selected':'' ?>>Abwesend aufsteigend</option>
            <option value="quote_desc" <?= $sort==='quote_desc'?'selected':'' ?>>Quote absteigend</option>
            <option value="quote_asc" <?= $sort==='quote_asc'?'selected':'' ?>>Quote aufsteigend</option>
          </select>
        </label>
      </div>

      <div class="analytics-filter-actions">
        <label class="analytics-switch">
          <input type="checkbox" name="fairness" value="1" <?= $show_fairness ? 'checked' : '' ?> onchange="this.form.submit()">
          <span class="analytics-switch-ui" aria-hidden="true"></span>
          <span>Fairness anzeigen</span>
        </label>
        <div class="analytics-action-buttons">
          <button class="btn success" type="submit">Filtern</button>
          <a class="btn" href="index.php?page=export">📤 Export</a>
        </div>
      </div>
    </form>
  </section>

  <?php if ($date_from !== '' || $date_to !== '' || !empty($selected_player_ids)): ?>
    <section class="card">
      <?php if (!empty($selected_player_ids)): ?><p class="muted">Spielerfilter aktiv: <strong><?= count($selected_player_ids) ?></strong> Spieler ausgewählt. <a href="index.php?page=analytics&type=<?= h($filter_type) ?>&from=<?= h($date_from) ?>&to=<?= h($date_to) ?>&sort=<?= h($sort) ?><?= $show_fairness ? '&fairness=1' : '' ?>">Spielerfilter zurücksetzen</a></p><?php endif; ?>
      <p class="muted">Zeitraum: <strong><?= h($date_from !== '' ? $date_from : 'Anfang') ?></strong> bis <strong><?= h($date_to !== '' ? $date_to : 'Heute') ?></strong></p>
      <?php if ($show_fairness): ?><p class="muted">Fairness vergleicht reguläres Training mit Spieltagen im gewählten Zeitraum.</p><?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="card analytics-stack-card">
    <details class="past-events-toggle">
      <summary>📊 Statistikbereich (nur vergangene Termine)</summary>
      <div class="analytics-panels">
        <div class="analytics-panel">
          <h3>Top 5</h3>
		  <p>(<?= h(event_filter_options()[$filter_type] ?? 'Alle Termine') ?>)</p>
          <?php if (empty($topPlayers)): ?>
            <p class="muted">Noch keine erfassten Daten.</p>
          <?php else: ?>
            <div class="leaderboard">
              <?php foreach ($topPlayers as $i => $row): ?>
                <div class="leaderboard-item">
                  <span class="rank"><?= (int)($i+1) ?></span>
                  <a href="index.php?page=player&id=<?= (int)$row['id'] ?>&type=<?= h($filter_type) ?>"><?= h($row['name']) ?></a>
                  <strong><?= (int)$row['quote'] ?>%</strong>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="analytics-panel">
          <h3>Monatsstatistik</h3>
          <?php if (empty($monthlyStats)): ?>
            <p class="muted">Noch keine Monatsdaten vorhanden.</p>
          <?php else: ?>
            <div class="mini-stat-list">
              <?php foreach ($monthlyStats as $row): ?>
                <div class="mini-stat-row">
                  <div>
                    <strong><?= h($row['label']) ?></strong>
                    <div class="muted"><?= (int)$row['events'] ?> Termine</div>
                  </div>
                  <div class="mini-stat-value"><?= (int)$row['quote'] ?>%</div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="analytics-panel full-width-panel">
        <h3>Termine im Überblick</h3>
        <?php if (empty($eventStats)): ?>
          <p class="muted">Noch keine Termine mit erfasster Anwesenheit vorhanden.</p>
        <?php else: ?>
          <div class="event-overview-list">
            <?php foreach ($eventStats as $row): ?>
              <div class="event-overview-item">
                <div>
                  <strong><?= h($row['date']) ?></strong>
                  <div class="muted"><?= h(event_type_icon($row['type'])) ?> <?= h(event_type_label($row['type'])) ?><?= !empty($row['is_holiday']) && $row['type'] === 'training' ? ' · Ferien' : '' ?><?= $row['note'] !== '' ? ' · '.h($row['note']) : '' ?></div>
                </div>
                <div class="event-overview-badge"><?= (int)$row['present'] ?>/<?= (int)$row['total'] ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </details>
  </section>

  <section class="card">
    <h2>Übersicht</h2>
    <div class="tablewrap analytics-table-wrap">
      <table class="responsive-table analytics-table">
        <thead><tr><th>Spieler</th><th>Anwesend</th><th>Abwesend</th><th>Gesamt</th><th>Quote</th><?php if ($show_fairness): ?><th>Fairness</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($byPlayer as $pid => $row): ?>
          <?php
            $q = (int)($row['quote'] ?? 0);
            $cls = $q >= 75 ? 'good' : ($q >= 50 ? 'mid' : 'bad');
            $fairness = $fairnessByPlayer[(int)$pid] ?? squadtrack_calculate_fairness(0, 0, 0, 0);
          ?>
          <tr>
            <td data-label="Spieler"><a class="player-link" href="index.php?page=player&id=<?= (int)$pid ?>&type=<?= h($filter_type) ?>&from=<?= h($date_from) ?>&to=<?= h($date_to) ?>"><?= h($row['name']) ?></a></td>
            <td data-label="Anwesend"><?= (int)$row['present'] ?></td>
            <td data-label="Abwesend"><?= (int)$row['absent'] ?></td>
            <td data-label="Gesamt"><?= (int)$row['total'] ?></td>
            <td data-label="Quote">
              <div class="progress <?= $cls ?>">
                <div class="fill" style="width: <?= $q ?>%"></div>
              </div>
              <span class="label"><?= $q ?>%</span>
            </td>
            <?php if ($show_fairness): ?>
            <td data-label="Fairness" class="fairness-cell">
              <span class="fairness-pill <?= h($fairness['class']) ?>"><?= h($fairness['short']) ?></span>
              <span class="fairness-note muted"><?= h($fairness['text']) ?></span>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php layout_footer(); exit; }

if ($page === 'trainers') { require_admin(); layout_header('Trainer');
  $users = read_json('trainers', []);
  ?>
  <section class="card">
    <h2>Trainer anlegen</h2>
    <form method="post" class="row">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="create">
      <label>Username<input name="username" required></label>
      <label>Startpasswort<input name="password" required></label>
      <label class="chk"><input type="checkbox" name="is_admin"> Admin</label>
      <button type="submit" class="btn-save">Speichern</button>
    </form>
  </section>
  <section class="card">
    <h2>Trainer</h2>
    <div class="tablewrap">
    <table class="responsive-table trainers-table">
      <thead><tr><th>ID</th><th>Username</th><th>Rolle</th><th>Aktionen</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td data-label="ID"><?= (int)$u['id'] ?></td>
            <td data-label="Username"><?= h($u['username']) ?></td>
            <td data-label="Rolle"><?= !empty($u['is_admin'])?'Admin':'Trainer' ?></td>
            <td data-label="Aktionen">
              <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="resetpw">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <input name="password" placeholder="neues Passwort" required>
                <button>Passwort setzen</button>
              </form>
              <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="toggleadmin">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button><?= !empty($u['is_admin'])?'Admin entziehen':'Zu Admin machen' ?></button>
              </form>
              <form method="post" class="inline" onsubmit="return confirm('Trainer löschen?')">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="danger">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>
<?php layout_footer(); exit; }

if ($page === 'account') { layout_header('Konto');
  $isAdmin = !empty(current_user()['is_admin']);
  $backups = $isAdmin ? list_backups() : [];
  $seasonResetPlayers = $isAdmin ? read_json('players', []) : [];
  usort($seasonResetPlayers, fn($a, $b) => strcasecmp(player_display_name($a), player_display_name($b)));
  ?>
  <section class="card narrow">
    <h2>Passwort ändern</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="password">
      <label>Neues Passwort<input type="password" name="new_password" minlength="6" required></label>
      <button>Aktualisieren</button>
    </form>
  </section>

  <?php if($isAdmin): ?>
    <section class="card">
      <h2>Vereinsdaten / Branding</h2>
      <p class="muted">Anpassung der Daten in der Überschrift</p>
      <form method="post" enctype="multipart/form-data" class="stack-form-gap">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="save_branding">
        <label>Vereinsname
          <input type="text" name="club_name" maxlength="120" value="<?= h(setting_value('club_name', 'JSG Haßmersheim / Hüffenhardt')) ?>" required>
        </label>
        <label>Jugend / Team
          <input type="text" name="youth_name" maxlength="80" value="<?= h(setting_value('youth_name', 'E-Jugend')) ?>">
        </label>
        <label>Tracker-Untertitel
          <input type="text" name="tracker_name" maxlength="120" value="<?= h(setting_value('tracker_name', 'Anwesenheits-Tracker')) ?>">
        </label>
        <label>Logo ändern (PNG, JPG, WEBP, GIF)
          <input type="file" name="club_logo" accept=".png,.jpg,.jpeg,.webp,.gif,image/png,image/jpeg,image/webp,image/gif">
        </label>
        <div class="branding-preview">
          <span class="muted">Aktuelles Logo:</span><br>
          <img src="<?= h(setting_value('logo_file', 'logo.png')) ?>" alt="Aktuelles Logo" class="branding-preview-image">
        </div>
        <button class="btn-save" type="submit">Vereinsdaten speichern</button>
      </form>
    </section>

    <section class="card">
      <h2>Backup erstellen</h2>
      <p class="muted">Sichert Spieler, Termine, Anwesenheiten und Trainerdaten.</p>
      <form method="post" class="inline-form-gap">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="create_backup">
        <button class="btn-save" type="submit">Backup erstellen</button>
      </form>
    </section>

    <section class="card">
      <h2>Backup importieren</h2>
      <p class="muted">Lädt ein zuvor heruntergeladenes ZIP-Backup wieder ins System.</p>
      <form method="post" enctype="multipart/form-data" class="stack-form-gap">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="import_backup">
        <label>Backup-ZIP auswählen
          <input type="file" name="backup_zip" accept=".zip" required>
        </label>
        <button type="submit">Backup importieren</button>
      </form>
    </section>

    <section class="card">
      <h2>Vorhandene Backups</h2>
      <p class="muted">Um Backups Wiederherzustellen oder zu Löschen müssen die jeweiligen Wörter als Sicherheit eingetippt werden.</p>
      <?php if(empty($backups)): ?>
        <p class="muted">Noch keine Backups vorhanden.</p>
      <?php else: ?>
        <div class="tablewrap">
        <table class="responsive-table backups-table">
          <thead>
            <tr>
              <th>Ordner</th>
              <th>Erstellt am</th>
              <th>Spieler</th>
              <th>Termine</th>
              <th>Datensätze</th>
              <th>Von</th>
              <th>Aktionen</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($backups as $backup): ?>
              <tr>
                <td data-label="Ordner">
                  <code><?= h($backup['relative_path']) ?></code>
                  <?php if(!empty($backup['zip_exists'])): ?>
                    <div class="muted small"><?= h($backup['zip_relative_path']) ?></div>
                  <?php endif; ?>
                </td>
                <td data-label="Erstellt am"><?= h($backup['created_at'] ?? 'unbekannt') ?></td>
                <td data-label="Spieler"><?= h((string)($backup['players_count'] ?? '-')) ?></td>
                <td data-label="Termine"><?= h((string)($backup['events_count'] ?? '-')) ?></td>
                <td data-label="Anwesenheiten"><?= h((string)($backup['attendance_count'] ?? '-')) ?></td>
                <td data-label="Von"><?= h($backup['created_by'] ?? '-') ?></td>
                <td data-label="Aktionen">
                  <div class="table-actions-col">
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="action" value="download_backup">
                      <input type="hidden" name="backup_name" value="<?= h($backup['name']) ?>">
                      <button type="submit">Download ZIP</button>
                    </form>

                    <form method="post" onsubmit="return confirm('Backup wirklich wiederherstellen? Aktuelle Daten werden dabei überschrieben.');">
                      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="action" value="restore_backup">
                      <input type="hidden" name="backup_name" value="<?= h($backup['name']) ?>">
                      <label class="chk"><input type="checkbox" name="create_backup_first" checked>Backup erstellen</label>
                      <input type="text" name="confirm_restore" required placeholder="WIEDERHERSTELLEN">
                      <button type="submit">Wiederherstellen</button>
                    </form>

                    <form method="post" onsubmit="return confirm('Backup wirklich löschen? Dieser Schritt kann nicht rückgängig gemacht werden.');">
                      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="action" value="delete_backup">
                      <input type="hidden" name="backup_name" value="<?= h($backup['name']) ?>">
                      <input type="text" name="confirm_delete" required placeholder="LÖSCHEN">
                      <button class="danger" type="submit">Löschen</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="card danger-zone season-reset-card">
      <div class="season-reset-head">
        <div>
          <h2>Saison zurücksetzen</h2>
          <p class="muted">Termine und Anwesenheiten werden gelöscht. Spieler können gezielt in die neue Saison übernommen werden. Trainer-Logins bleiben bestehen.</p>
        </div>
        <label class="season-backup-toggle">
          <input form="seasonResetForm" type="checkbox" name="create_backup_first" checked>
          <span>Backup vorher erstellen</span>
        </label>
      </div>

      <form id="seasonResetForm" method="post" onsubmit="return confirm('Saison wirklich zurücksetzen? Termine und Anwesenheiten werden gelöscht. Nur die ausgewählten Spieler bleiben erhalten.');">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="reset_season">

        <div class="season-reset-box">
          <div class="season-reset-section-head">
            <div>
              <strong>Spieler für die neue Saison</strong>
              <p class="muted small">Wähle, welche Spieler im Kader bleiben. Anwesenheiten und Termine werden immer zurückgesetzt.</p>
            </div>
          </div>

          <div class="season-mode-tabs" role="group" aria-label="Reset-Modus wählen">
            <label class="season-mode-tab">
              <input type="radio" name="reset_player_mode" value="keep_selected" checked>
              <span>Ausgewählte Spieler behalten</span>
            </label>
            <label class="season-mode-tab season-mode-danger">
              <input type="radio" name="reset_player_mode" value="delete_all">
              <span>Alle Spieler löschen</span>
            </label>
          </div>

          <?php if(empty($seasonResetPlayers)): ?>
            <p class="muted">Keine Spieler vorhanden.</p>
          <?php else: ?>
            <div class="season-reset-tools">
              <button type="button" class="btn" onclick="document.querySelectorAll('.season-player-check').forEach(cb => cb.checked = true)">Alle</button>
              <button type="button" class="btn" onclick="document.querySelectorAll('.season-player-check').forEach(cb => cb.checked = false)">Keine</button>
              <button type="button" class="btn" onclick="document.querySelectorAll('.season-player-check').forEach(cb => cb.checked = cb.dataset.active === '1')">Nur aktive</button>
            </div>

            <div class="season-player-grid" aria-label="Spieler für die neue Saison auswählen">
              <?php foreach($seasonResetPlayers as $player): ?>
                <?php $isActive = !empty($player['active']); ?>
                <label class="season-player-item<?= !$isActive ? ' is-inactive' : '' ?>">
                  <input class="season-player-check" type="checkbox" name="keep_player_ids[]" value="<?= (int)$player['id'] ?>" data-active="<?= $isActive ? '1' : '0' ?>" <?= $isActive ? 'checked' : '' ?>>
                  <span><?= h(player_display_name($player)) ?></span>
                  <?php if(!$isActive): ?><em>inaktiv</em><?php endif; ?>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="season-reset-confirm">
          <div>
            <strong>Letzter Schritt</strong>
            <p class="muted small">Zur Bestätigung <code>RESET</code> eingeben.</p>
          </div>
          <label class="season-confirm-input">
            <input type="text" name="confirm_reset" required placeholder="RESET" autocomplete="off">
          </label>
          <button class="danger" type="submit">Saison zurücksetzen</button>
        </div>
      </form>
    </section>
  <?php endif; ?>
<?php layout_footer(); exit; }
