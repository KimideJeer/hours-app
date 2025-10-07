<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

require __DIR__ . '/../src/auth.php';
requireLogin('login.php');
$uid = currentUserId();

$config = require __DIR__ . '/../config.php';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['dbname']);
$pdo = new PDO($dsn, $config['user'], $config['pass'], [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect($url){ header("Location: $url"); exit; }

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0) { http_response_code(400); die('Project id ontbreekt'); }

// Project ophalen
$st = $pdo->prepare("SELECT * FROM projects WHERE id=?");
$st->execute([$projectId]);
$project = $st->fetch();
if (!$project) { http_response_code(404); die('Project niet gevonden'); }

$errors = [];
$flash  = $_GET['ok'] ?? null;

// =================== HANDLERS ===================

// Uren toevoegen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'add_time') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }
  $entry_date = $_POST['entry_date'] ?? '';
  $task_id    = (int)($_POST['task_id'] ?? 0);
  $hours      = $_POST['hours'] ?? '';
  $note       = trim($_POST['note'] ?? '');

  if (!$entry_date || !DateTime::createFromFormat('Y-m-d', $entry_date)) { $errors['entry_date'] = 'Datum verplicht (YYYY-MM-DD)'; }
  if ($task_id <= 0) { $errors['task_id'] = 'Kies een taak'; }
  if ($hours === '' || !is_numeric($hours) || $hours <= 0 || $hours > 24) { $errors['hours'] = '0 < uren ≤ 24'; }

  if ($task_id > 0) {
    $chk = $pdo->prepare("SELECT 1 FROM project_tasks WHERE id=? AND project_id=? AND is_active=1");
    $chk->execute([$task_id, $projectId]);
    if (!$chk->fetch()) { $errors['task_id'] = 'Ongeldige taak voor dit project'; }
  }

  if (!$errors) {
    $ins = $pdo->prepare("INSERT INTO time_entries (user_id, project_id, task_id, entry_date, hours, note)
                          VALUES (?,?,?,?,?,?)");
    $ins->execute([$uid, $projectId, $task_id, $entry_date, $hours, $note ?: null]);
    redirect("project.php?id={$projectId}&ok=entry");
  }
}

// Nieuwe taak
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'create_task') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }
  $taskName = trim($_POST['task_name'] ?? '');

  if ($taskName === '') {
    $errors['task_create'] = 'Taaknaam is verplicht.';
  } else {
    $dupe = $pdo->prepare("SELECT 1 FROM project_tasks WHERE project_id=? AND LOWER(name)=LOWER(?)");
    $dupe->execute([$projectId, $taskName]);
    if ($dupe->fetch()) { $errors['task_create'] = 'Deze taak bestaat al binnen dit project.'; }
  }

  if (empty($errors['task_create'])) {
    try {
      $pdo->prepare("INSERT INTO project_tasks (project_id, name, is_active) VALUES (?,?,1)")
          ->execute([$projectId, $taskName]);
      redirect("project.php?id={$projectId}&ok=task");
    } catch (PDOException $e) {
      if ($e->getCode() === '23000') { $errors['task_create'] = 'Deze taak bestaat al binnen dit project.'; }
      else { throw $e; }
    }
  }
}

// Deactiveren / activeren project
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['mode'] ?? ''), ['deactivate_project','activate_project'], true)) {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }
  $activate = ($_POST['mode'] === 'activate_project') ? 1 : 0;
  $pdo->prepare("UPDATE projects SET is_active=? WHERE id=?")->execute([$activate, $projectId]);
  redirect("project.php?id={$projectId}&ok=" . ($activate ? 'activated' : 'deactivated'));
}

// Verwijderen project (alleen als inactief én geen uren)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'delete_project') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }

  // 1) Project moet inactief zijn
  $st = $pdo->prepare("SELECT is_active FROM projects WHERE id=?");
  $st->execute([$projectId]);
  $isActive = (int)$st->fetchColumn();

  if ($isActive === 1) {
    $errors['delete'] = 'Deactiveer dit project eerst voordat je het kunt verwijderen.';
  } else {
    // 2) Alleen verwijderen als er geen uren zijn
    $st = $pdo->prepare("SELECT COUNT(*) FROM time_entries WHERE project_id=?");
    $st->execute([$projectId]);
    $hasEntries = (int)$st->fetchColumn() > 0;

    if ($hasEntries) {
      $errors['delete'] = 'Kan niet verwijderen: er zijn uren op dit project. Verwijder eerst die uren.';
    } else {
      $pdo->prepare("DELETE FROM project_tasks WHERE project_id=?")->execute([$projectId]);
      $pdo->prepare("DELETE FROM projects WHERE id=?")->execute([$projectId]);
      redirect("projects.php?ok=deleted");
    }
  }
}


// =================== DATA LADEN ===================

// Taken + totaal per taak (alleen eigen uren)
$st = $pdo->prepare("
SELECT t.id, t.name,
       COALESCE(SUM(te.hours), 0) AS total_hours
FROM project_tasks t
LEFT JOIN time_entries te
  ON te.task_id = t.id
 AND te.user_id = ?
WHERE t.project_id = ? AND t.is_active = 1
GROUP BY t.id, t.name
ORDER BY t.name ASC");
$st->execute([$uid, $projectId]);
$tasks = $st->fetchAll();

// Urenlijst (details)
$st = $pdo->prepare("
SELECT te.id, te.entry_date, te.hours, te.note, t.name AS task_name
FROM time_entries te
LEFT JOIN project_tasks t ON t.id = te.task_id
WHERE te.user_id=? AND te.project_id=?
ORDER BY te.entry_date DESC, te.id DESC");
$st->execute([$uid, $projectId]);
$entries = $st->fetchAll();

// Totale uren project
$totalProject = 0.0;
foreach ($tasks as $t) { $totalProject += (float)$t['total_hours']; }

// Taken voor dropdown
$st = $pdo->prepare("SELECT id, name FROM project_tasks WHERE project_id=? AND is_active=1 ORDER BY name ASC");
$st->execute([$projectId]);
$taskOptions = $st->fetchAll();

$isActive = (int)$project['is_active'] === 1;
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <title>Project – <?=h($project['name'])?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:1000px;margin:24px auto;padding:0 16px}
    h1{margin:0 0 8px}
    .muted{color:#6b7280}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
    .card{border:1px solid #e5e7eb;border-radius:12px;padding:16px;background:#fff}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid #e5e7eb;padding:8px;text-align:left}
    .error{color:#b91c1c}
    .ok{background:#ecfdf5;border:1px solid #10b98133;padding:10px;border-radius:8px;color:#065f46;margin-bottom:12px}
    input,select,textarea{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;margin:6px 0 12px}
    .btn{background:#111827;color:#fff;border:0;border-radius:8px;padding:10px 14px;cursor:pointer}
    .btn-danger{background:#b91c1c;color:#fff;border:0;border-radius:8px;padding:10px 14px;cursor:pointer}
    .btn-secondary{background:#6b7280;color:#fff;border:0;border-radius:8px;padding:10px 14px;cursor:pointer}
    a.link{color:#111827}
    .toolbar{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 16px}
    .btn-danger.disabled {
  background: #e5e7eb;
  color: #9ca3af;
  border: 1px dashed #d1d5db;
  cursor: not-allowed;
}
.btn-danger .icon { margin-right: 6px; }

  </style>
</head>
<body>
  <p class="muted"><a class="link" href="projects.php">← Terug naar projecten</a></p>
  <h1><?=h($project['name'])?></h1>
  <p class="muted">Status: <strong><?= $isActive ? 'actief' : 'inactief' ?></strong> — Totaal uren: <strong><?=number_format($totalProject,2)?></strong></p>

  <?php if ($flash): ?>
    <div class="ok">
      <?php
        echo [
          'task'       => 'Taak toegevoegd.',
          'entry'      => 'Uren opgeslagen.',
          'deactivated'=> 'Project gedeactiveerd.',
          'activated'  => 'Project geactiveerd.'
        ][$flash] ?? 'OK';
      ?>
    </div>
  <?php endif; ?>

  <!-- Beheerknoppen -->
  <div class="toolbar">
    <?php if ($isActive): ?>
      <form method="post" action="project.php?id=<?=$projectId?>" onsubmit="return confirm('Project deactiveren?');">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="mode" value="deactivate_project">
        <button class="btn-secondary" type="submit">Deactiveer project</button>
      </form>
    <?php else: ?>
      <form method="post" action="project.php?id=<?=$projectId?>" onsubmit="return confirm('Project activeren?');">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="mode" value="activate_project">
        <button class="btn-secondary" type="submit">Activeer project</button>
      </form>
    <?php endif; ?>

 <?php if (!$isActive): ?>
  <form method="post" action="project.php?id=<?=$projectId?>" onsubmit="return confirm('Echt verwijderen? Dit kan alleen als er geen uren zijn.');">
    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
    <input type="hidden" name="mode" value="delete_project">
    <button class="btn-danger" type="submit">
      <span class="icon">🗑️</span>Verwijder project
    </button>
  </form>
<?php else: ?>
  <button class="btn-danger disabled" type="button" aria-disabled="true" title="Deactiveer eerst">
    <span class="icon">🔒</span>Verwijder project
  </button>
<?php endif; ?>


  <div class="grid">
    <div class="card">
      <h3 style="margin-top:0">Uren toevoegen</h3>
      <?php if (empty($taskOptions)): ?>
        <p class="muted">Nog geen taken. Voeg eerst een taak toe (rechts) en kom dan terug om uren te boeken.</p>
      <?php else: ?>
      <form method="post" action="project.php?id=<?=$projectId?>">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="mode" value="add_time">

        <label>Datum</label>
        <input type="date" name="entry_date" required>
        <?php if(isset($errors['entry_date'])): ?><div class="error"><?=$errors['entry_date']?></div><?php endif; ?>

        <label>Taak</label>
        <select name="task_id" required>
          <option value="">— kies een taak —</option>
          <?php foreach ($taskOptions as $opt): ?>
            <option value="<?=$opt['id']?>"><?=h($opt['name'])?></option>
          <?php endforeach; ?>
        </select>
        <?php if(isset($errors['task_id'])): ?><div class="error"><?=$errors['task_id']?></div><?php endif; ?>

        <label>Uren</label>
        <input type="number" step="0.25" min="0.25" max="24" name="hours" required>
        <?php if(isset($errors['hours'])): ?><div class="error"><?=$errors['hours']?></div><?php endif; ?>

        <label>Notitie (optioneel)</label>
        <textarea name="note" rows="3"></textarea>

        <button class="btn" type="submit">Opslaan</button>
      </form>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3 style="margin-top:0">Taken (totaal uren)</h3>

      <!-- Taak toevoegen -->
      <form method="post" action="project.php?id=<?=$projectId?>" style="margin-bottom:12px">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="mode" value="create_task">

        <label>Nieuwe taak</label>
        <input type="text" name="task_name" placeholder="Taaknaam" required>
        <?php if(isset($errors['task_create'])): ?><div class="error"><?=$errors['task_create']?></div><?php endif; ?>

        <button class="btn" type="submit">Taak toevoegen</button>
      </form>

      <table>
        <thead><tr><th>Taak</th><th style="text-align:right">Uren</th></tr></thead>
        <tbody>
        <?php foreach ($tasks as $t): ?>
          <tr>
            <td><?=h($t['name'])?></td>
            <td style="text-align:right"><?=number_format((float)$t['total_hours'],2)?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($tasks)): ?>
          <tr><td colspan="2" class="muted">Nog geen taken.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="margin-top:16px">
    <h3 style="margin-top:0">Uren (details)</h3>
    <table>
      <thead><tr><th>Datum</th><th>Taak</th><th>Uren</th><th>Notitie</th></tr></thead>
      <tbody>
      <?php foreach ($entries as $e): ?>
        <tr>
          <td><?=h($e['entry_date'])?></td>
          <td><?=h($e['task_name'])?></td>
          <td><?=h($e['hours'])?></td>
          <td><?=nl2br(h($e['note']))?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($entries)): ?>
        <tr><td colspan="4" class="muted">Nog geen uren voor dit project.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
