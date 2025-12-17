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

$errors = [];                 // per project-id of 'new' voor nieuw project
$flash  = $_GET['ok'] ?? null;

/* =================== HANDLERS =================== */

// Nieuw project — eigenaar vastleggen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'create_project') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }
  $name = trim($_POST['name'] ?? '');
  if ($name === '') { $errors['new']['name'] = 'Projectnaam is verplicht.'; }
  if (empty($errors['new'])) {
    try {
      $ins = $pdo->prepare("INSERT INTO projects (user_id, name, is_active) VALUES (?, ?, 1)");
      $ins->execute([$uid, $name]);
      redirect('projects.php?ok=project');
    } catch (PDOException $e) {
      if ($e->getCode() === '23000') { $errors['new']['name'] = 'Projectnaam bestaat al.'; }
      else { throw $e; }
    }
  }
}

// Uren snel toevoegen (alleen op eigen project)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'quick_add') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }

  $projectId  = (int)($_POST['project_id'] ?? 0);

  // eigenaarscheck: medewerker alleen eigen project, manager/admin alles
  if (!$isManager) {
    $own = $pdo->prepare("SELECT 1 FROM projects WHERE id=? AND user_id=?");
    $own->execute([$projectId, $uid]);
    if (!$own->fetch()) { http_response_code(403); die('Niet toegestaan: project is niet van jou.'); }
  }


  $entry_date = $_POST['entry_date'] ?? '';
  $task_id    = (int)($_POST['task_id'] ?? 0);
  $hours      = $_POST['hours'] ?? '';
  $note       = trim($_POST['note'] ?? '');

  if ($projectId <= 0) { $errors[$projectId]['project'] = 'Project ontbreekt.'; }
  if (!$entry_date || !DateTime::createFromFormat('Y-m-d', $entry_date)) { $errors[$projectId]['entry_date'] = 'Datum (YYYY-MM-DD)'; }
  if ($task_id <= 0) { $errors[$projectId]['task_id'] = 'Kies een taak.'; }
  if ($hours === '' || !is_numeric($hours) || $hours <= 0 || $hours > 24) { $errors[$projectId]['hours'] = '0 < uren ≤ 24.'; }

  if ($task_id > 0 && $projectId > 0) {
    $chk = $pdo->prepare("SELECT 1 FROM project_tasks WHERE id=? AND project_id=? AND is_active=1");
    $chk->execute([$task_id, $projectId]);
    if (!$chk->fetch()) { $errors[$projectId]['task_id'] = 'Ongeldige taak voor dit project.'; }
  }

  if (empty($errors[$projectId])) {
    $ins = $pdo->prepare("INSERT INTO time_entries (user_id, project_id, task_id, entry_date, hours, note)
                          VALUES (?,?,?,?,?,?)");
    $ins->execute([$uid, $projectId, $task_id, $entry_date, $hours, $note ?: null]);
    redirect('projects.php?ok=entry');
  }
}

// Deactiveren (alleen eigen project)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'deactivate_project') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }
  $pid = (int)($_POST['project_id'] ?? 0);

  $own = $pdo->prepare("SELECT 1 FROM projects WHERE id=? AND user_id=?");
  $own->execute([$pid, $uid]);
  if (!$own->fetch()) { http_response_code(403); die('Niet toegestaan'); }

  $pdo->prepare("UPDATE projects SET is_active=0 WHERE id=?")->execute([$pid]);
  redirect('projects.php?ok=deactivated');
}

// Activeren (alleen eigen project)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'activate_project') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }
  $pid = (int)($_POST['project_id'] ?? 0);

  $own = $pdo->prepare("SELECT 1 FROM projects WHERE id=? AND user_id=?");
  $own->execute([$pid, $uid]);
  if (!$own->fetch()) { http_response_code(403); die('Niet toegestaan'); }

  $pdo->prepare("UPDATE projects SET is_active=1 WHERE id=?")->execute([$pid]);
  redirect('projects.php?ok=activated');
}

// Verwijderen (alleen eigen project; inactief; geen uren)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'delete_project') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die('Invalid CSRF'); }
  $pid = (int)($_POST['project_id'] ?? 0);

  // eigenaarscheck
  $own = $pdo->prepare("SELECT 1 FROM projects WHERE id=? AND user_id=?");
  $own->execute([$pid, $uid]);
  if (!$own->fetch()) { http_response_code(403); die('Niet toegestaan'); }

  // 1) Moet eerst inactief zijn
  $st = $pdo->prepare("SELECT is_active FROM projects WHERE id=?");
  $st->execute([$pid]);
  $isActive = (int)$st->fetchColumn();

  if ($isActive === 1) {
    $errors[$pid]['delete'] = 'Deactiveer dit project eerst voordat je het kunt verwijderen.';
  } else {
    // 2) Mag alleen als er geen uren bestaan (voor iedereen)
    $st = $pdo->prepare("SELECT COUNT(*) FROM time_entries WHERE project_id=?");
    $st->execute([$pid]);
    $hasEntries = (int)$st->fetchColumn() > 0;

    if ($hasEntries) {
      $errors[$pid]['delete'] = 'Kan niet verwijderen: er zijn uren op dit project. Verwijder eerst die uren.';
    } else {
      // taken verwijderen en daarna het project
      $pdo->prepare("DELETE FROM project_tasks WHERE project_id=?")->execute([$pid]);
      $pdo->prepare("DELETE FROM projects WHERE id=?")->execute([$pid]);
      redirect('projects.php?ok=deleted');
    }
  }
}

/* =================== DATA LADEN =================== */

// Filter: toon ook inactieve? (via ?show=all)
$showAll = ($_GET['show'] ?? '') === 'all';

$params = [];
$where  = [];

// Manager/admin ziet alle projecten + alle goedgekeurde uren op actieve taken
if ($isManager) {
    if (!$showAll) {
        $where[] = "p.is_active = 1";
    }

    $sql = "
    SELECT
        p.id,
        p.name,
        p.is_active,
        p.planned_hours,
        COALESCE(
            SUM(
                CASE
                    WHEN te.status = 'approved'
                         AND (t.is_active = 1 OR t.id IS NULL)
                    THEN te.hours
                    ELSE 0
                END
            ),
            0
        ) AS total_hours
    FROM projects p
    LEFT JOIN time_entries te
      ON te.project_id = p.id
    LEFT JOIN project_tasks t
      ON t.id = te.task_id
    ";
} else {
    // Medewerker: alleen eigen projecten + eigen goedgekeurde uren op actieve taken
    $sql = "
    SELECT
        p.id,
        p.name,
        p.is_active,
        p.planned_hours,
        COALESCE(
            SUM(
                CASE
                    WHEN te.status = 'approved'
                         AND (t.is_active = 1 OR t.id IS NULL)
                    THEN te.hours
                    ELSE 0
                END
            ),
            0
        ) AS total_hours
    FROM projects p
    LEFT JOIN time_entries te
      ON te.project_id = p.id
     AND te.user_id    = ?
    LEFT JOIN project_tasks t
      ON t.id = te.task_id
    ";

    if (!$showAll) {
        $where[] = "p.is_active = 1";
    }
    // alleen projecten van deze gebruiker
    $where[] = "p.user_id = ?";

    // twee ? in de query: te.user_id en p.user_id
    $params = [$uid, $uid];
}

// WHERE-deel toevoegen
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

// Groeperen en sorteren
$sql .= "
  GROUP BY p.id, p.name, p.is_active, p.planned_hours
  ORDER BY p.is_active DESC, p.name ASC
";

$st = $pdo->prepare($sql);
$st->execute($params);
$projects = $st->fetchAll();

// Taken per project
if ($isManager) {
    // Manager/admin: taken van alle projecten
    $taskStmt = $pdo->query("
        SELECT pt.id, pt.project_id, pt.name, pt.is_active
        FROM project_tasks pt
        WHERE pt.is_active = 1
        ORDER BY pt.project_id, pt.name
    ");
} else {
    // Medewerker: alleen taken van eigen projecten
    $taskStmt = $pdo->prepare("
        SELECT pt.id, pt.project_id, pt.name, pt.is_active
        FROM project_tasks pt
        JOIN projects p ON p.id = pt.project_id
        WHERE pt.is_active = 1
          AND p.user_id = ?
        ORDER BY pt.project_id, pt.name
    ");
    $taskStmt->execute([$uid]);
}

$tasksByProject = [];
foreach ($taskStmt as $row) {
    $tasksByProject[(int)$row['project_id']][] = $row;
}
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <title>Projecten – Uren</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<header>
  <h1>Projecten</h1>
  <nav class="muted">
    Ingelogd als <strong><?=h(currentUserEmail())?></strong>
    —
    <form method="post" action="logout.php">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <button type="submit" class="btn-secondary">Uitloggen</button>
    </form>
  </nav>
</header>

<?php if ($flash): ?>
  <div class="ok">
    <?php
      echo [
        'project'    => 'Project aangemaakt.',
        'entry'      => 'Uren opgeslagen.',
        'deactivated'=> 'Project gedeactiveerd.',
        'activated'  => 'Project geactiveerd.',
        'deleted'    => 'Project verwijderd.'
      ][$flash] ?? 'OK';
    ?>
  </div>
<?php endif; ?>

<!-- Nieuw project -->
<div class="card" style="margin-bottom:12px">
  <h3 style="margin:0 0 8px">Nieuw project</h3>
  <form method="post" action="projects.php">
    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
    <input type="hidden" name="mode" value="create_project">

    <label>Projectnaam</label>
    <input type="text" name="name" placeholder="Projectnaam" required>
    <?php if(isset($errors['new']['name'])): ?><div class="error"><?=$errors['new']['name']?></div><?php endif; ?>

    <button class="btn-primary" type="submit">Opslaan</button>
  </form>

  <div class="muted" style="margin-top:6px">
    <?php if ($showAll): ?>
      <!-- Knop zet filter UIT (alleen actieve) -->
      <form method="get" action="projects.php" style="display:inline">
        <button type="submit" class="btn-secondary">Alleen actieve tonen</button>
      </form>
    <?php else: ?>
      <!-- Knop zet filter AAN (ook inactieve) -->
      <form method="get" action="projects.php" style="display:inline">
        <input type="hidden" name="show" value="all">
        <button type="submit" class="btn-secondary">Alle (incl. inactief) tonen</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<p class="muted">Klik een project voor taken/uren of voeg snel uren toe via het uitklapformulier.</p>

<div class="grid">
  <?php foreach ($projects as $p): $pid=(int)$p['id']; $inactive = !$p['is_active']; ?>
    <div class="card">
  <h3 style="margin:0 0 8px">
    <?=h($p['name'])?>
    <?php if ($inactive): ?><span class="badge inactive">inactief</span><?php endif; ?>
  </h3>

  <div><strong>Totaal uren:</strong> <?=number_format((float)$p['total_hours'],2)?></div>

  <?php
    $planned = $p['planned_hours'] ?? null;
    if ($planned !== null && $planned !== ''):
        $plannedFloat = (float)$planned;
        $ratio = $plannedFloat > 0 ? ($p['total_hours'] / $plannedFloat) : 0;
        $perc  = round($ratio * 100);
  ?>
    <div class="muted" style="margin-top:4px;">
      Budget: <strong><?=number_format($plannedFloat,2)?></strong> uur —
      gebruikt: <strong><?=number_format((float)$p['total_hours'],2)?></strong> (<?=$perc?>%)
      <?php if ($ratio > 1): ?>
        <span class="badge status-rejected">Over budget</span>
      <?php elseif ($ratio >= 0.8): ?>
        <span class="badge status-pending">Bijna vol</span>
      <?php else: ?>
        <span class="badge status-approved">Binnen budget</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
        <a class="btn" href="project.php?id=<?=$pid?>">Open project</a>

        <?php if ($inactive): ?>
          <form method="post" action="projects.php" onsubmit="return confirm('Project activeren?');">
            <input type="hidden" name="csrf" value="<?=h($csrf)?>">
            <input type="hidden" name="mode" value="activate_project">
            <input type="hidden" name="project_id" value="<?=$pid?>">
            <button class="btn-secondary" type="submit">Activeer</button>
          </form>
        <?php else: ?>
          <form method="post" action="projects.php" onsubmit="return confirm('Project deactiveren?');">
            <input type="hidden" name="csrf" value="<?=h($csrf)?>">
            <input type="hidden" name="mode" value="deactivate_project">
            <input type="hidden" name="project_id" value="<?=$pid?>">
            <button class="btn-secondary" type="submit">Deactiveer</button>
          </form>
        <?php endif; ?>

        <?php if ($inactive): ?>
          <!-- Project is INACTIEF: verwijderen toegestaan (mits geen uren) -->
          <form method="post" action="projects.php" onsubmit="return confirm('Echt verwijderen? Dit kan alleen als er geen uren zijn.');">
            <input type="hidden" name="csrf" value="<?=h($csrf)?>">
            <input type="hidden" name="mode" value="delete_project">
            <input type="hidden" name="project_id" value="<?=$pid?>">
            <button class="btn-danger" type="submit"><span class="icon">🗑️</span>Verwijder</button>
          </form>
        <?php else: ?>
          <!-- Project is ACTIEF: eerst deactiveren -->
          <button class="btn-danger disabled" type="button" aria-disabled="true" title="Deactiveer eerst"><span class="icon">🔒</span>Verwijder</button>
        <?php endif; ?>
      </div>

      <?php if(isset($errors[$pid]['delete'])): ?>
        <div class="error" style="margin-top:8px"><?=h($errors[$pid]['delete'])?></div>
      <?php endif; ?>

      <details>
        <summary>Uren toevoegen</summary>
        <?php if (empty($tasksByProject[$pid])): ?>
          <p class="muted">Nog geen taken voor dit project. Ga naar de projectpagina om taken toe te voegen.</p>
        <?php else: ?>
        <form method="post" action="projects.php">
          <input type="hidden" name="csrf" value="<?=h($csrf)?>">
          <input type="hidden" name="mode" value="quick_add">
          <input type="hidden" name="project_id" value="<?=$pid?>">

          <div class="row">
            <div>
              <label>Datum</label>
              <input type="date" name="entry_date" required>
              <?php if(isset($errors[$pid]['entry_date'])): ?><div class="error"><?=$errors[$pid]['entry_date']?></div><?php endif; ?>
            </div>
            <div>
              <label>Uren</label>
              <input type="number" step="0.25" min="0.25" max="24" name="hours" required>
              <?php if(isset($errors[$pid]['hours'])): ?><div class="error"><?=$errors[$pid]['hours']?></div><?php endif; ?>
            </div>
          </div>

          <label>Taak</label>
          <select name="task_id" required>
            <option value="">— kies een taak —</option>
            <?php foreach ($tasksByProject[$pid] as $t): ?>
              <option value="<?=$t['id']?>"><?=h($t['name'])?></option>
            <?php endforeach; ?>
          </select>
          <?php if(isset($errors[$pid]['task_id'])): ?><div class="error"><?=$errors[$pid]['task_id']?></div><?php endif; ?>

          <label>Notitie (optioneel)</label>
          <textarea name="note" rows="2"></textarea>

          <button class="btn-primary" type="submit">Opslaan</button>
        </form>
        <?php endif; ?>
      </details>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
