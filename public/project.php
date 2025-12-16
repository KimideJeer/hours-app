<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

require __DIR__ . '/../src/auth.php';
requireLogin('login.php');
$uid  = currentUserId();
$role = currentUserRole();
$isManager = in_array($role, ['manager', 'admin'], true);

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


// Toegang:
// - medewerker: alleen eigen project
// - manager/admin: elk project
if ($isManager) {
  $st = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
  $st->execute([$projectId]);
} else {
  $st = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
  $st->execute([$projectId, $uid]);
}
$project = $st->fetch();
if (!$project) {
  http_response_code(403);
  die('Niet toegestaan / project niet gevonden');
}


// -------------------------------------------------------------
// HANDLERS (alleen eigenaar komt hier, want pagina is al gescoped)
// -------------------------------------------------------------

// 0) Project hernoemen + urenbudget bijwerken
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['mode'] ?? '') === 'rename_project')) {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    http_response_code(400);
    die('Invalid CSRF');
  }

  $newName = trim($_POST['project_name'] ?? '');
  if ($newName === '') {
    $errors['project_name'] = 'Projectnaam is verplicht.';
  }

  // Urenbudget uit formulier halen (optioneel)
  $plannedRaw = $_POST['planned_hours'] ?? '';
  $planned = null;
  if ($plannedRaw !== '') {
    if (!is_numeric($plannedRaw) || (float)$plannedRaw < 0 || (float)$plannedRaw > 10000) {
      $errors['planned_hours'] = 'Geef een geldig urenbudget tussen 0 en 10000.';
    } else {
      $planned = (float)$plannedRaw;
    }
  }

  if (!$errors) {
    try {
      $st = $pdo->prepare("
        UPDATE projects
        SET name = ?, planned_hours = ?
        WHERE id = ? AND user_id = ?
      ");
      $st->execute([$newName, $planned, $projectId, $uid]);

      redirect("project.php?id={$projectId}&ok=project_renamed");
    } catch (PDOException $e) {
      if ($e->getCode() === '23000') {
        $errors['project_name'] = 'Deze projectnaam bestaat al.';
      } else {
        throw $e;
      }
    }
  }
}


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
      if ($e->getCode() === '23000') { $errors['task_new'] = 'Deze taak bestaat al in dit project.'; }
      else { throw $e; }
    }
  }
}

// 1b) Taak hernoemen (gebruikt door het bewerk-paneel)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['mode'] ?? '') === 'rename_task')) {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }
  $taskId   = (int)($_POST['task_id'] ?? 0);
  $taskName = trim($_POST['task_name'] ?? '');
  if ($taskName === '') { $errors["task_{$taskId}_rename"] = 'Taaknaam is verplicht.'; }

  $chk = $pdo->prepare("SELECT 1 FROM project_tasks WHERE id=? AND project_id=?");
  $chk->execute([$taskId, $projectId]);
  if (!$chk->fetch()) { http_response_code(403); die('Niet toegestaan'); }

  if (empty($errors["task_{$taskId}_rename"])) {
    try {
      $pdo->prepare("UPDATE project_tasks SET name=? WHERE id=?")->execute([$taskName, $taskId]);
      redirect("project.php?id={$projectId}&ok=task_renamed");
    } catch (PDOException $e) {
      if ($e->getCode() === '23000') { $errors["task_{$taskId}_rename"] = 'Deze taaknaam bestaat al in dit project.'; }
      else { throw $e; }
    }
  }
}

// 2) Taak activeren/deactiveren
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['mode'] ?? ''), ['deactivate_task','activate_task'], true)) {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }
  $taskId = (int)($_POST['task_id'] ?? 0);

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

  $chk = $pdo->prepare("SELECT 1 FROM project_tasks WHERE id=? AND project_id=?");
  $chk->execute([$taskId, $projectId]);
  if (!$chk->fetch()) { http_response_code(403); die('Niet toegestaan'); }

  $st = $pdo->prepare("SELECT COUNT(*) FROM time_entries WHERE project_id=? AND task_id=?");
  $st->execute([$projectId, $taskId]);
  if ((int)$st->fetchColumn() > 0) {
    $errors["task_{$taskId}_delete"] = 'Kan niet verwijderen: er zijn uren op deze taak.';
  } else {
    $pdo->prepare("DELETE FROM project_tasks WHERE id=?")->execute([$taskId]);
    redirect("project.php?id={$projectId}&ok=task_deleted");
  }
}

// 4) Uren toevoegen (alleen jouw user)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'add_time') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }

  $entry_date = $_POST['entry_date'] ?? '';
  $task_id    = (int)($_POST['task_id'] ?? 0);
  $hours      = $_POST['hours'] ?? '';
  $note       = trim($_POST['note'] ?? '');

  if (!$entry_date || !DateTime::createFromFormat('Y-m-d', $entry_date)) { $errors['entry_date'] = 'Datum (YYYY-MM-DD)'; }
  if ($task_id <= 0) { $errors['task_id'] = 'Kies een taak.'; }
  if ($hours === '' || !is_numeric($hours) || $hours <= 0 || $hours > 24) { $errors['hours'] = '0 < uren ≤ 24.'; }

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

// 5) Urenregel verwijderen (alleen eigen & alleen pending)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['mode'] ?? '') === 'delete_entry')) {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }
  $entryId = (int)($_POST['entry_id'] ?? 0);

  // alleen eigen pending-regel mag weg
  $chk = $pdo->prepare("
    SELECT 1
    FROM time_entries
    WHERE id=? AND user_id=? AND project_id=? AND status='pending'
  ");
  $chk->execute([$entryId, $uid, $projectId]);
  if (!$chk->fetch()) {
    http_response_code(403);
    die('Niet toegestaan');
  }

  $pdo->prepare("
    DELETE FROM time_entries
    WHERE id=? AND user_id=? AND project_id=? AND status='pending'
  ")->execute([$entryId, $uid, $projectId]);

  redirect("project.php?id={$projectId}&ok=entry_deleted");
}

// 5b) Status van urenregel aanpassen (alleen manager/admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['mode'] ?? '') === 'set_entry_status')) {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }

  if (!$isManager) {
    http_response_code(403);
    die('Niet toegestaan');
  }

  $entryId = (int)($_POST['entry_id'] ?? 0);
  $status  = $_POST['status'] ?? 'pending';

  if (!in_array($status, ['pending','approved','rejected'], true)) {
    http_response_code(400);
    die('Ongeldige status');
  }

  // check: regel hoort bij dit project
  $chk = $pdo->prepare("SELECT 1 FROM time_entries WHERE id=? AND project_id=?");
  $chk->execute([$entryId, $projectId]);
  if (!$chk->fetch()) {
    http_response_code(404);
    die('Urenregel niet gevonden');
  }

  $upd = $pdo->prepare("UPDATE time_entries SET status=?, updated_at=NOW() WHERE id=?");
  $upd->execute([$status, $entryId]);

  if ($status === 'approved') {
    $ok = 'entry_approved';
} elseif ($status === 'rejected') {
    $ok = 'entry_rejected';
} else {
    $ok = 'status_updated';
}

redirect("project.php?id={$projectId}&ok={$ok}");

}


// 6) Edit-mode voor urenregel (GET) + update (POST)
$editEntryId = (int)($_GET['edit_entry'] ?? 0);
$editEntry = null;
if ($editEntryId > 0) {
  $st = $pdo->prepare("
    SELECT te.id, te.entry_date, te.hours, te.note, te.task_id
    FROM time_entries te
    WHERE te.id=? AND te.user_id=? AND te.project_id=? AND te.status = 'pending'");
  $st->execute([$editEntryId, $uid, $projectId]);
  $editEntry = $st->fetch();
}

// 6) Urenregel bijwerken (alleen eigen & alleen pending)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['mode'] ?? '') === 'update_entry')) {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    http_response_code(400);
    die('Invalid CSRF');
  }

  $entryId    = (int)($_POST['entry_id'] ?? 0);
  $entry_date = $_POST['entry_date'] ?? '';
  $task_id    = (int)($_POST['task_id'] ?? 0);
  $hours      = $_POST['hours'] ?? '';
  $note       = trim($_POST['note'] ?? '');

  // alleen eigen PENDING-regel mag geüpdatet worden
  $chk = $pdo->prepare("
    SELECT 1
    FROM time_entries
    WHERE id=? AND user_id=? AND project_id=? AND status='pending'
  ");
  $chk->execute([$entryId, $uid, $projectId]);
  if (!$chk->fetch()) {
    http_response_code(403);
    die('Niet toegestaan');
  }

  // validatie
  if (!$entry_date || !DateTime::createFromFormat('Y-m-d', $entry_date)) {
    $errors['edit_entry_date'] = 'Datum (YYYY-MM-DD)';
  }
  if ($task_id <= 0) {
    $errors['edit_task_id'] = 'Kies een taak.';
  }
  if ($hours === '' || !is_numeric($hours) || $hours <= 0 || $hours > 24) {
    $errors['edit_hours'] = '0 < uren ≤ 24.';
  }

  if ($task_id > 0) {
    $chk2 = $pdo->prepare("SELECT 1 FROM project_tasks WHERE id=? AND project_id=?");
    $chk2->execute([$task_id, $projectId]);
    if (!$chk2->fetch()) {
      $errors['edit_task_id'] = 'Ongeldige taak voor dit project.';
    }
  }

  if (!$errors) {
    $upd = $pdo->prepare("
      UPDATE time_entries
      SET entry_date=?, task_id=?, hours=?, note=?, updated_at=NOW()
      WHERE id=? AND user_id=? AND project_id=? AND status='pending'
    ");
    $upd->execute([$entry_date, $task_id, $hours, $note ?: null, $entryId, $uid, $projectId]);
    redirect("project.php?id={$projectId}&ok=entry_updated");
  } else {
    $editEntry = [
      'id'         => $entryId,
      'entry_date' => $entry_date,
      'task_id'    => $task_id,
      'hours'      => $hours,
      'note'       => $note,
    ];
  }
}

// -------------------------------------------------------------
// EDIT-MODES voor project & taak (zoals bij Uren)
// -------------------------------------------------------------
$editProject = isset($_GET['edit_project']);           // ?edit_project=1
$editTaskId  = (int)($_GET['edit_task'] ?? 0);         // ?edit_task=ID
$editTask    = null;
if ($editTaskId > 0) {
  $st = $pdo->prepare("SELECT id, name FROM project_tasks WHERE id=? AND project_id=?");
  $st->execute([$editTaskId, $projectId]);
  $editTask = $st->fetch();
}

// -------------------------------------------------------------
// DATA LADEN
// -------------------------------------------------------------

// 1) Project taken + totalen per taak
if ($isManager) {
  // Manager/admin: alle goedgekeurde uren binnen dit project
  $sql = "
  SELECT t.id, t.name, t.is_active,
         COALESCE(SUM(te.hours),0) AS total_hours
  FROM project_tasks t
  LEFT JOIN time_entries te
    ON te.task_id   = t.id
   AND te.project_id = ?
   AND te.status    = 'approved'
  WHERE t.project_id = ?
  GROUP BY t.id, t.name, t.is_active
  ORDER BY t.name ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$projectId, $projectId]);
} else {
  // Medewerker: alleen eigen goedgekeurde uren tellen mee
  $sql = "
  SELECT t.id, t.name, t.is_active,
         COALESCE(SUM(te.hours),0) AS total_hours
  FROM project_tasks t
  LEFT JOIN time_entries te
    ON te.task_id = t.id
   AND te.user_id = ?
   AND te.status  = 'approved'
  WHERE t.project_id = ?
  GROUP BY t.id, t.name, t.is_active
  ORDER BY t.name ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$uid, $projectId]);
}
$tasks = $st->fetchAll();

// 2) Urenlijst
if ($isManager) {
  // Manager/admin: alle uren binnen dit project
  $sql = "
  SELECT te.id,
         te.user_id,
         te.entry_date,
         te.hours,
         te.note,
         te.status,
         pt.name AS task_name,
         u.email AS user_email
  FROM time_entries te
  LEFT JOIN project_tasks pt ON pt.id = te.task_id
  LEFT JOIN users u ON u.id = te.user_id
  WHERE te.project_id = ?
  ORDER BY te.entry_date DESC, te.id DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$projectId]);
} else {
  // Medewerker: alleen eigen uren binnen dit project
  $sql = "
  SELECT te.id,
         te.user_id,
         te.entry_date,
         te.hours,
         te.note,
         te.status,
         pt.name AS task_name,
         u.email AS user_email
  FROM time_entries te
  LEFT JOIN project_tasks pt ON pt.id = te.task_id
  LEFT JOIN users u ON u.id = te.user_id
  WHERE te.user_id = ? AND te.project_id = ?
  ORDER BY te.entry_date DESC, te.id DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$uid, $projectId]);
}
$entries = $st->fetchAll();

// Totaal uren opnieuw berekenen via SQL,
// - alleen 'approved' uren
// - alleen taken die nog actief zijn (t.is_active = 1)
// - manager: alle users, medewerker: alleen eigen user
if ($isManager) {
    $sumStmt = $pdo->prepare("
        SELECT COALESCE(SUM(te.hours), 0) AS total
        FROM time_entries te
        JOIN project_tasks t ON t.id = te.task_id
        WHERE te.project_id = ?
          AND te.status = 'approved'
          AND t.is_active = 1
    ");
    $sumStmt->execute([$projectId]);
} else {
    $sumStmt = $pdo->prepare("
        SELECT COALESCE(SUM(te.hours), 0) AS total
        FROM time_entries te
        JOIN project_tasks t ON t.id = te.task_id
        WHERE te.project_id = ?
          AND te.user_id = ?
          AND te.status = 'approved'
          AND t.is_active = 1
    ");
    $sumStmt->execute([$projectId, $uid]);
}

$totalHours = (float)$sumStmt->fetchColumn();





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
  <h1 style="margin-bottom:6px"><?=h($project['name'])?></h1>
  <?php if ((int)$project['is_active'] === 0): ?>
    <span class="badge inactive">inactief</span>
  <?php endif; ?>

  <div class="muted">
    Totaal uren: <strong><?=number_format($totalHours,2)?></strong>
  </div>

  <?php
  $planned = $project['planned_hours'] ?? null;
  if ($planned !== null && $planned > 0):
      $plannedFloat = (float)$planned;
      $ratio = $plannedFloat > 0 ? ($totalHours / $plannedFloat) : 0;
      $perc  = round($ratio * 100);
  ?>
    <div class="muted" style="margin-top:4px;">
      Budget: <strong><?=number_format($plannedFloat,2)?></strong> uur —
      gebruikt: <strong><?=number_format($totalHours,2)?></strong> (<?=$perc?>%)
      <?php if ($ratio > 1): ?>
        <span class="badge status-rejected">Over budget</span>
      <?php elseif ($ratio >= 0.8): ?>
        <span class="badge status-pending">Bijna vol</span>
      <?php else: ?>
        <span class="badge status-approved">Binnen budget</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Actie: project bewerken via edit-mode -->
  <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn-secondary" href="project.php?id=<?=$projectId?>&edit_project=1">Bewerk</a>
  </div>
</div>

  </header>

  <?php if ($flash): ?>
  <div class="ok">
    <?= [
      'project_renamed'=> 'Projectnaam aangepast.',
      'task_created'   => 'Taak toegevoegd.',
      'task_state'     => 'Taakstatus aangepast.',
      'task_deleted'   => 'Taak verwijderd.',
      'task_renamed'   => 'Taaknaam aangepast.',
      'entry_added'    => 'Uren toegevoegd.',
      'entry_deleted'  => 'Urenregel verwijderd.',
      'entry_updated'  => 'Urenregel bijgewerkt.',
      'entry_approved' => 'De uren zijn goedgekeurd.',
      'entry_rejected' => 'De uren zijn afgewezen.',
      'status_updated' => 'De status van de uren is bijgewerkt.',
    ][$flash] ?? 'OK'; ?>
  </div>
<?php endif; ?>


  <!-- Project bewerken (paneel) -->
<?php if ($editProject): ?>
  <div class="card">
    <h3>Project bewerken</h3>
    <form method="post" action="project.php?id=<?=$projectId?>">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="mode" value="rename_project">

      <label>Projectnaam</label>
      <input type="text" name="project_name" value="<?=h($project['name'])?>" required>
      <?php if(isset($errors['project_name'])): ?>
        <div class="muted" style="color:#b91c1c"><?=$errors['project_name']?></div>
      <?php endif; ?>

      <label>Urenbudget (optioneel)</label>
      <input
        type="number"
        name="planned_hours"
        step="0.25"
        min="0"
        max="10000"
        value="<?=h($project['planned_hours'] ?? '')?>"
        placeholder="Bijv. 40"
      >
      <?php if(isset($errors['planned_hours'])): ?>
        <div class="muted" style="color:#b91c1c"><?=$errors['planned_hours']?></div>
      <?php endif; ?>

      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn" type="submit">Opslaan</button>
        <a class="btn btn-secondary" href="project.php?id=<?=$projectId?>">Annuleren</a>
      </div>
    </form>
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
        <thead><tr><th>Taak</th><th>Status</th><th>Totaal uren: </th><th>Acties</th></tr></thead>
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
                  <button class="btn btn-danger disabled" type="button" title="Deactiveer eerst">Verwijder</button>
                <?php endif; ?>

                <!-- Taak bewerken via edit-mode -->
                <a class="btn btn-secondary" href="project.php?id=<?=$projectId?>&edit_task=<?=$tid?>">Bewerk</a>

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

  <!-- Uren bewerken (indien in edit-mode) -->
  <?php if ($editEntry): ?>
    <div class="card">
      <h3>Uren bewerken</h3>
      <form method="post" action="project.php?id=<?=$projectId?>">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="mode" value="update_entry">
        <input type="hidden" name="entry_id" value="<?=h($editEntry['id'])?>">

        <div class="grid">
          <div>
            <label>Datum</label>
            <input type="date" name="entry_date" value="<?=h($editEntry['entry_date'])?>" required>
            <?php if(isset($errors['edit_entry_date'])): ?><div class="muted" style="color:#b91c1c"><?=$errors['edit_entry_date']?></div><?php endif; ?>
          </div>
          <div>
            <label>Uren</label>
            <input type="number" step="0.25" min="0.25" max="24" name="hours" value="<?=h($editEntry['hours'])?>" required>
            <?php if(isset($errors['edit_hours'])): ?><div class="muted" style="color:#b91c1c"><?=$errors['edit_hours']?></div><?php endif; ?>
          </div>
        </div>

        <label>Taak</label>
        <select name="task_id" required>
          <option value="">— kies een taak —</option>
          <?php foreach ($tasks as $t): ?>
            <option value="<?=$t['id']?>" <?=$t['id']==$editEntry['task_id']?'selected':''?>><?=h($t['name'])?><?=$t['is_active']?'':' (inactief)'?></option>
          <?php endforeach; ?>
        </select>
        <?php if(isset($errors['edit_task_id'])): ?><div class="muted" style="color:#b91c1c"><?=$errors['edit_task_id']?></div><?php endif; ?>

        <label>Notitie (optioneel)</label>
        <textarea name="note" rows="2"><?=h($editEntry['note'])?></textarea>

        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn" type="submit">Opslaan</button>
          <a class="btn btn-secondary" href="project.php?id=<?=$projectId?>">Annuleren</a>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <!-- Taak bewerken (indien in edit-mode) -->
  <?php if ($editTask): ?>
    <div class="card">
      <h3>Taak bewerken</h3>
      <form method="post" action="project.php?id=<?=$projectId?>">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="mode" value="rename_task">
        <input type="hidden" name="task_id" value="<?=h($editTask['id'])?>">

        <label>Taaknaam</label>
        <input type="text" name="task_name" value="<?=h($editTask['name'])?>" required>
        <?php if(isset($errors["task_{$editTaskId}_rename"])): ?>
          <div class="muted" style="color:#b91c1c"><?=$errors["task_{$editTaskId}_rename"]?></div>
        <?php endif; ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn" type="submit">Opslaan</button>
          <a class="btn btn-secondary" href="project.php?id=<?=$projectId?>">Annuleren</a>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <!-- Urenoverzicht (jouw regels) -->
  <div class="card">
    <h3>Urenoverzicht</h3>
    <?php if (!$entries): ?>
      <p class="muted">Nog geen uren geboekt op dit project.</p>
    <?php else: ?>
          <table>
  <thead>
    <tr>
      <th>Datum</th>
      <th>Taak</th>
      <th>Uren</th>
      <?php if ($isManager): ?>
        <th>Gebruiker</th>
      <?php endif; ?>
      <th>Status</th>
      <th>Notitie</th>
      <th>Acties</th>
    </tr>
  </thead>
  <tbody>
   <?php foreach ($entries as $e): ?>
  <?php
    $canEditOwn = (
      ((int)($e['user_id'] ?? 0) === $uid)
      && ($e['status'] === 'pending')
    );
  ?>

      <tr>
        <td><?=h($e['entry_date'])?></td>
        <td><?=h($e['task_name'] ?? '—')?></td>
        <td><?=h($e['hours'])?></td>

        <?php if ($isManager): ?>
          <td><?=h($e['user_email'] ?? '')?></td>
        <?php endif; ?>

        <td>
          <?php if ($e['status'] === 'approved'): ?>
            <span class="badge status-approved">Goedgekeurd</span>
          <?php elseif ($e['status'] === 'rejected'): ?>
            <span class="badge status-rejected">Afgewezen</span>
          <?php else: ?>
            <span class="badge status-pending">In afwachting</span>
          <?php endif; ?>

          <?php if ($isManager): ?>
            <div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap">
              <form method="post" action="project.php?id=<?=$projectId?>">
                <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                <input type="hidden" name="mode" value="set_entry_status">
                <input type="hidden" name="entry_id" value="<?=$e['id']?>">
                <input type="hidden" name="status" value="approved">
                <button type="submit" class="btn btn-secondary" style="padding:2px 6px;font-size:.75rem;">Keur goed</button>
              </form>
              <form method="post" action="project.php?id=<?=$projectId?>">
                <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                <input type="hidden" name="mode" value="set_entry_status">
                <input type="hidden" name="entry_id" value="<?=$e['id']?>">
                <input type="hidden" name="status" value="rejected">
                <button type="submit" class="btn btn-danger" style="padding:2px 6px;font-size:.75rem;">Wijs af</button>
              </form>
            </div>
          <?php endif; ?>
        </td>

        <td><?=nl2br(h($e['note']))?></td>
        <td style="white-space:nowrap">
          <?php if ($canEditOwn): ?>
            <a class="btn btn-secondary" href="project.php?id=<?=$projectId?>&edit_entry=<?=$e['id']?>">Bewerk</a>
            <form method="post" action="project.php?id=<?=$projectId?>" style="display:inline" onsubmit="return confirm('Urenregel verwijderen?');">
              <input type="hidden" name="csrf" value="<?=h($csrf)?>">
              <input type="hidden" name="mode" value="delete_entry">
              <input type="hidden" name="entry_id" value="<?=$e['id']?>">
              <button class="btn btn-danger" type="submit">Verwijder</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

    <?php endif; ?>
  </div>
</body>
</html>
