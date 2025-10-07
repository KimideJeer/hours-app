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

// -------------------------------------------------------------
// Input basis
// -------------------------------------------------------------
$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0) { http_response_code(400); die('Ongeldig project-id'); }

$errors = [];
$flash  = $_GET['ok'] ?? null;

// -------------------------------------------------------------
// Toegang: project moet van ingelogde gebruiker zijn
// -------------------------------------------------------------
$st = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
$st->execute([$projectId, $uid]);
$project = $st->fetch();
if (!$project) {
  http_response_code(403);
  die('Niet toegestaan / project niet gevonden');
}

// -------------------------------------------------------------
// HANDLERS (alleen eigenaar komt hier, want pagina is al gescoped)
// -------------------------------------------------------------

// 1) Taak aanmaken
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'create_task') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }
  $taskName = trim($_POST['task_name'] ?? '');
  if ($taskName === '') { $errors['task_new'] = 'Taaknaam is verplicht.'; }
  if (empty($errors['task_new'])) {
    try {
      $ins = $pdo->prepare("INSERT INTO project_tasks (project_id, name, is_active) VALUES (?, ?, 1)");
      $ins->execute([$projectId, $taskName]);
      redirect("project.php?id={$projectId}&ok=task_created");
    } catch (PDOException $e) {
      // unieke taak per project (uq_task_per_project) kan 23000 geven
      if ($e->getCode() === '23000') { $errors['task_new'] = 'Deze taak bestaat al in dit project.'; }
      else { throw $e; }
    }
  }
}

// 2) Taak activeren/deactiveren
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['mode'] ?? ''), ['deactivate_task','activate_task'], true)) {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }
  $taskId = (int)($_POST['task_id'] ?? 0);

  // eigenaarscheck via project_id
  $chk = $pdo->prepare("SELECT 1 FROM project_tasks WHERE id=? AND project_id=?");
  $chk->execute([$taskId, $projectId]);
  if (!$chk->fetch()) { http_response_code(403); die('Niet toegestaan'); }

  $new = ($_POST['mode'] === 'activate_task') ? 1 : 0;
  $pdo->prepare("UPDATE project_tasks SET is_active=? WHERE id=?")->execute([$new, $taskId]);

  redirect("project.php?id={$projectId}&ok=task_state");
}

// 3) Taak verwijderen (alleen als er geen uren op staan)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'delete_task') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }
  $taskId = (int)($_POST['task_id'] ?? 0);

  // eigenaarscheck
  $chk = $pdo->prepare("SELECT 1 FROM project_tasks WHERE id=? AND project_id=?");
  $chk->execute([$taskId, $projectId]);
  if (!$chk->fetch()) { http_response_code(403); die('Niet toegestaan'); }

  // mag alleen als er geen entries zijn (ongeacht user; veiligste)
  $st = $pdo->prepare("SELECT COUNT(*) FROM time_entries WHERE project_id=? AND task_id=?");
  $st->execute([$projectId, $taskId]);
  if ((int)$st->fetchColumn() > 0) {
    $errors["task_{$taskId}_delete"] = 'Kan niet verwijderen: er zijn uren op deze taak.';
  } else {
    $pdo->prepare("DELETE FROM project_tasks WHERE id=?")->execute([$taskId]);
    redirect("project.php?id={$projectId}&ok=task_deleted");
  }
}

// 4) Uren toevoegen op dit project (alleen jouw user)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'add_time') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }

  $entry_date = $_POST['entry_date'] ?? '';
  $task_id    = (int)($_POST['task_id'] ?? 0);
  $hours      = $_POST['hours'] ?? '';
  $note       = trim($_POST['note'] ?? '');

  if (!$entry_date || !DateTime::createFromFormat('Y-m-d', $entry_date)) { $errors['entry_date'] = 'Datum (YYYY-MM-DD)'; }
  if ($task_id <= 0) { $errors['task_id'] = 'Kies een taak.'; }
  if ($hours === '' || !is_numeric($hours) || $hours <= 0 || $hours > 24) { $errors['hours'] = '0 < uren ≤ 24.'; }

  // taak moet bij dit project horen en actief zijn
  if ($task_id > 0) {
    $chk = $pdo->prepare("SELECT 1 FROM project_tasks WHERE id=? AND project_id=? AND is_active=1");
    $chk->execute([$task_id, $projectId]);
    if (!$chk->fetch()) { $errors['task_id'] = 'Ongeldige taak voor dit project.'; }
  }

  if (!$errors) {
    $ins = $pdo->prepare("INSERT INTO time_entries (user_id, project_id, task_id, entry_date, hours, note)
                          VALUES (?,?,?,?,?,?)");
    $ins->execute([$uid, $projectId, $task_id, $entry_date, $hours, $note ?: null]);
    redirect("project.php?id={$projectId}&ok=entry_added");
  }
}

// -------------------------------------------------------------
// DATA LADEN
// -------------------------------------------------------------

// 1) Project taken + jouw totalen per taak
$sql = "
SELECT t.id, t.name, t.is_active,
       COALESCE(SUM(te.hours),0) AS total_hours
FROM project_tasks t
LEFT JOIN time_entries te
  ON te.task_id = t.id
 AND te.user_id = ?
WHERE t.project_id = ?
GROUP BY t.id, t.name, t.is_active
ORDER BY t.name ASC
";
$st = $pdo->prepare($sql);
$st->execute([$uid, $projectId]);
$tasks = $st->fetchAll();

// 2) Urenlijst (alleen jouw entries)
$sql = "
SELECT te.id, te.entry_date, te.hours, te.note,
       pt.name AS task_name
FROM time_entries te
LEFT JOIN project_tasks pt ON pt.id = te.task_id
WHERE te.user_id = ? AND te.project_id = ?
ORDER BY te.entry_date DESC, te.id DESC
";
$st = $pdo->prepare($sql);
$st->execute([$uid, $projectId]);
$entries = $st->fetchAll();

// Totaal uren (alleen jouw uren binnen dit project)
$totalHours = 0.0; foreach ($entries as $e) { $totalHours += (float)$e['hours']; }

?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <title>Project – <?=h($project['name'])?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:1000px;margin:24px auto;padding:0 16px}
    h1,h2,h3{margin:0 0 12px}
    .muted{color:#6b7280}
    a.btn{display:inline-block;background:#111827;color:#fff;text-decoration:none;border-radius:8px;padding:8px 12px;margin-top:8px}
    .btn{background:#111827;color:#fff;border:0;border-radius:8px;padding:8px 12px;cursor:pointer}
    .btn-secondary{background:#6b7280}
    .btn-danger{background:#b91c1c}
    .btn-danger.disabled{ background:#e5e7eb; color:#9ca3af; border:1px dashed #d1d5db; cursor:not-allowed; }
    .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
    .card{border:1px solid #e5e7eb;border-radius:12px;padding:16px;background:#fff;margin-bottom:12px}
    input,select,textarea{width:100%;padding:9px;border:1px solid #d1d5db;border-radius:8px;margin:6px 0 10px}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid #e5e7eb;padding:8px;text-align:left}
    .badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.8rem;border:1px solid #e5e7eb}
    .badge.inactive{background:#fff7ed;border-color:#fdba74;color:#9a3412}
    .ok{background:#ecfdf5;border:1px solid #10b98133;padding:10px;border-radius:8px;color:#065f46;margin-bottom:12px}
    nav form { display:inline; margin:0; }
  </style>
</head>
<body>
  <header style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <div>
      <h1><?=h($project['name'])?></h1>
      <?php if ((int)$project['is_active'] === 0): ?><span class="badge inactive">inactief</span><?php endif; ?>
      <div class="muted">Totaal uren (jij): <strong><?=number_format($totalHours,2)?></strong></div>
    </div>
    <nav class="muted">
      Ingelogd als <strong><?=h(currentUserEmail())?></strong>
      —
      <form method="post" action="logout.php">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <button type="submit" class="btn btn-secondary">Uitloggen</button>
      </form>
    </nav>
  </header>

  <?php if ($flash): ?>
    <div class="ok">
      <?= [
        'task_created' => 'Taak toegevoegd.',
        'task_state'   => 'Taakstatus aangepast.',
        'task_deleted' => 'Taak verwijderd.',
        'entry_added'  => 'Uren toegevoegd.'
      ][$flash] ?? 'OK'; ?>
    </div>
  <?php endif; ?>

  <p><a class="btn" href="projects.php">← Terug naar projecten</a></p>

  <!-- Nieuwe taak -->
  <div class="card">
    <h3>Nieuwe taak</h3>
    <form method="post" action="project.php?id=<?=$projectId?>">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="mode" value="create_task">
      <label>Taaknaam</label>
      <input type="text" name="task_name" placeholder="Bijv. Stelling, Kassa" required>
      <?php if(isset($errors['task_new'])): ?><div class="muted" style="color:#b91c1c"><?=$errors['task_new']?></div><?php endif; ?>
      <button class="btn" type="submit">Toevoegen</button>
    </form>
  </div>

  <!-- Taken + acties -->
  <div class="card">
    <h3>Taken in dit project</h3>
    <?php if (!$tasks): ?>
      <p class="muted">Nog geen taken. Voeg er hierboven één toe.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Taak</th><th>Status</th><th>Totaal uren (jij)</th><th>Acties</th></tr></thead>
        <tbody>
          <?php foreach ($tasks as $t): $tid=(int)$t['id']; $inactive = !$t['is_active']; ?>
            <tr>
              <td><?=h($t['name'])?></td>
              <td><?= $inactive ? '<span class="badge inactive">inactief</span>' : 'actief' ?></td>
              <td><?=number_format((float)$t['total_hours'],2)?></td>
              <td style="display:flex;gap:8px;flex-wrap:wrap">
                <?php if ($inactive): ?>
                  <form method="post" action="project.php?id=<?=$projectId?>">
                    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                    <input type="hidden" name="mode" value="activate_task">
                    <input type="hidden" name="task_id" value="<?=$tid?>">
                    <button class="btn btn-secondary" type="submit">Activeer</button>
                  </form>

                  <!-- Verwijderen mag alleen als er geen uren zijn (server checkt ook) -->
                  <form method="post" action="project.php?id=<?=$projectId?>" onsubmit="return confirm('Echt verwijderen? Dit kan alleen als er geen uren zijn.');">
                    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                    <input type="hidden" name="mode" value="delete_task">
                    <input type="hidden" name="task_id" value="<?=$tid?>">
                    <button class="btn btn-danger" type="submit">Verwijder</button>
                  </form>
                <?php else: ?>
                  <form method="post" action="project.php?id=<?=$projectId?>" onsubmit="return confirm('Taak deactiveren?');">
                    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                    <input type="hidden" name="mode" value="deactivate_task">
                    <input type="hidden" name="task_id" value="<?=$tid?>">
                    <button class="btn btn-secondary" type="submit">Deactiveer</button>
                  </form>

                  <!-- Zichtbare maar uitgeschakelde delete -->
                  <button class="btn btn-danger disabled" type="button" title="Deactiveer eerst">Verwijder</button>
                <?php endif; ?>

                <?php if(isset($errors["task_{$tid}_delete"])): ?>
                  <div class="muted" style="color:#b91c1c"><?=$errors["task_{$tid}_delete"]?></div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Uren toevoegen -->
  <div class="card">
    <h3>Uren toevoegen</h3>
    <?php if (!$tasks): ?>
      <p class="muted">Je moet eerst minstens één <strong>actieve</strong> taak hebben om uren te kunnen boeken.</p>
    <?php else: ?>
      <form method="post" action="project.php?id=<?=$projectId?>">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="mode" value="add_time">

        <div class="grid">
          <div>
            <label>Datum</label>
            <input type="date" name="entry_date" required>
            <?php if(isset($errors['entry_date'])): ?><div class="muted" style="color:#b91c1c"><?=$errors['entry_date']?></div><?php endif; ?>
          </div>
          <div>
            <label>Uren</label>
            <input type="number" step="0.25" min="0.25" max="24" name="hours" required>
            <?php if(isset($errors['hours'])): ?><div class="muted" style="color:#b91c1c"><?=$errors['hours']?></div><?php endif; ?>
          </div>
        </div>

        <label>Taak</label>
        <select name="task_id" required>
          <option value="">— kies een taak —</option>
          <?php foreach ($tasks as $t): if (!$t['is_active']) continue; ?>
            <option value="<?=$t['id']?>"><?=h($t['name'])?></option>
          <?php endforeach; ?>
        </select>
        <?php if(isset($errors['task_id'])): ?><div class="muted" style="color:#b91c1c"><?=$errors['task_id']?></div><?php endif; ?>

        <label>Notitie (optioneel)</label>
        <textarea name="note" rows="2"></textarea>

        <button class="btn" type="submit">Opslaan</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- Urenoverzicht (jouw regels) -->
  <div class="card">
    <h3>Urenoverzicht</h3>
    <?php if (!$entries): ?>
      <p class="muted">Nog geen uren geboekt op dit project.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Datum</th><th>Taak</th><th>Uren</th><th>Notitie</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $e): ?>
            <tr>
              <td><?=h($e['entry_date'])?></td>
              <td><?=h($e['task_name'] ?? '—')?></td>
              <td><?=h($e['hours'])?></td>
              <td><?=nl2br(h($e['note']))?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</body>
</html>
