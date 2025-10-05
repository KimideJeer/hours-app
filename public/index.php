<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];


// -------------------------------------------------------------
// Databaseconnectie via PDO (prepared statements voor veiligheid).
// Config lezen uit config.php (host, dbname, user, pass).
// -------------------------------------------------------------
$config = require __DIR__ . '/../config.php';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['dbname']);
try {
  $pdo = new PDO($dsn, $config['user'], $config['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
} catch (PDOException $e) {
  http_response_code(500);
  echo "<h1>DB connection failed</h1><pre>{$e->getMessage()}</pre>";
  exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect($url){ header("Location: $url"); exit; }

// -------------------------------------------------------------
// Router-achtige afhandeling via querystring ?action=...
//   - list (default): tonen van formulier + tabel
//   - create (POST): nieuwe uren opslaan
//   - edit (GET/POST): formulier vullen en wijzigingen opslaan
//   - delete (POST): rij verwijderen
// -------------------------------------------------------------
$action = $_GET['action'] ?? 'list';

/**
 * $errors: validatieberichten per veld.
 * Wordt gevuld als controle faalt, en weergegeven naast inputs.
 */
$errors = [];


// -------------------------------------------------------------
// CREATE — nieuwe uren opslaan
// -------------------------------------------------------------
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die("Invalid CSRF"); }
      // Lees en “normaliseer” invoer
  $entry_date = $_POST['entry_date'] ?? '';
  $project = trim($_POST['project'] ?? '');
  $task = trim($_POST['task'] ?? '');
  $hours = $_POST['hours'] ?? '';
  $note = trim($_POST['note'] ?? '');

  if (!$entry_date || !DateTime::createFromFormat('Y-m-d', $entry_date)) { $errors['entry_date'] = 'Required (YYYY-MM-DD)'; }
  if ($project === '') { $errors['project'] = 'Required'; }
  if ($task === '') { $errors['task'] = 'Required'; }
  if ($hours === '' || !is_numeric($hours) || $hours <= 0 || $hours > 24) { $errors['hours'] = '0 < hours ≤ 24'; }

  if (!$errors) {
    $stmt = $pdo->prepare("INSERT INTO time_entries (entry_date, project, task, hours, note) VALUES (?,?,?,?,?)");
    $stmt->execute([$entry_date, $project, $task, $hours, $note ?: null]);
    redirect('index.php');
  }
}

// -------------------------------------------------------------
// DELETE — record verwijderen
// - We gebruiken een POST met verborgen CSRF-token + confirm()
// -------------------------------------------------------------
if ($action === 'delete' && isset($_GET['id'])) {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die("Invalid CSRF"); }
    $id = (int)($_GET['id'] ?? 0);
    $pdo->prepare("DELETE FROM time_entries WHERE id=?")->execute([$id]);
    redirect('index.php');
  }
}

// -------------------------------------------------------------
// EDIT — record ophalen (GET) of bijwerken (POST)
// -------------------------------------------------------------
$editRow = null;
if ($action === 'edit' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); die("Invalid CSRF"); }
           // Zelfde validatie als bij create
    $entry_date = $_POST['entry_date'] ?? '';
    $project = trim($_POST['project'] ?? '');
    $task = trim($_POST['task'] ?? '');
    $hours = $_POST['hours'] ?? '';
    $note = trim($_POST['note'] ?? '');
    if (!$entry_date || !DateTime::createFromFormat('Y-m-d', $entry_date)) { $errors['entry_date'] = 'Required (YYYY-MM-DD)'; }
    if ($project === '') { $errors['project'] = 'Required'; }
    if ($task === '') { $errors['task'] = 'Required'; }
    if ($hours === '' || !is_numeric($hours) || $hours <= 0 || $hours > 24) { $errors['hours'] = '0 < hours ≤ 24'; }
    if (!$errors) {
      $stmt = $pdo->prepare("UPDATE time_entries SET entry_date=?, project=?, task=?, hours=?, note=?, updated_at=NOW() WHERE id=?");
      $stmt->execute([$entry_date, $project, $task, $hours, $note ?: null, $id]);
      redirect('index.php');
    } else {
       // Validatie faalde: vul het formulier opnieuw met de geposte waarden
      $editRow = ['id'=>$id,'entry_date'=>$entry_date,'project'=>$project,'task'=>$task,'hours'=>$hours,'note'=>$note];
    }
  } else {
        // GET: haal bestaande rij op om formulier te vullen
    $st = $pdo->prepare("SELECT * FROM time_entries WHERE id=?");
    $st->execute([$id]);
    $editRow = $st->fetch();
    if (!$editRow) { http_response_code(404); die("Not found"); }
  }
}
// -------------------------------------------------------------
// LIST — ophalen van rijen + eenvoudige filters en totalen
// -------------------------------------------------------------
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$where = []; $params = [];

// Optionele filter op datumbereik
if ($from && DateTime::createFromFormat('Y-m-d', $from)) { $where[] = "entry_date >= ?"; $params[] = $from; }
if ($to && DateTime::createFromFormat('Y-m-d', $to)) { $where[] = "entry_date <= ?"; $params[] = $to; }
// Query opbouwen met filters
$sql = "SELECT * FROM time_entries";
if ($where) { $sql .= " WHERE " . implode(" AND ", $where); }
sql:;
$sql .= " ORDER BY entry_date DESC, id DESC"; // volgorde
$rows = $pdo->prepare($sql); $rows->execute($params); $rows = $rows->fetchAll();

$totalHours = 0.0; foreach ($rows as $r) { $totalHours += (float)$r['hours']; } // Totaal aantal uren berekenen (server-side)
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <title>Urenregistratie (simpel)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:960px;margin:24px auto;padding:0 16px;}
    h1{margin:0 0 12px}
    form.card{background:#fafafa;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin:12px 0;box-shadow:0 1px 2px rgba(0,0,0,.04)}
    input,select,textarea{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;margin:6px 0 12px}
    label{font-weight:600}
    .row{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
    .actions{display:flex;gap:8px;flex-wrap:wrap}
    button,.btn{background:#111827;color:white;border:0;border-radius:8px;padding:10px 14px;cursor:pointer}
    .btn.secondary{background:#6b7280}
    table{width:100%;border-collapse:collapse;margin-top:16px}
    th,td{border-bottom:1px solid #e5e7eb;padding:8px;text-align:left}
    .error{color:#b91c1c;font-size:.9rem;margin-top:-6px;margin-bottom:8px}
    .total{font-weight:700}
    .muted{color:#6b7280}
  </style>
</head>
<body>
  <h1>Urenregistratie (simpel)</h1>

  <form class="card" method="get">
    <div class="row">
      <div>
        <label>Van (YYYY-MM-DD)</label>
        <input type="date" name="from" value="<?=h($from)?>">
      </div>
      <div>
        <label>Tot en met (YYYY-MM-DD)</label>
        <input type="date" name="to" value="<?=h($to)?>">
      </div>
    </div>
    <div class="actions">
      <button type="submit">Filteren</button>
      <a class="btn secondary" href="index.php">Reset</a>
    </div>
  </form>

  <?php if ($action === 'edit' && $editRow): ?>
  <h2>Bewerk uren</h2>
  <form class="card" method="post" action="index.php?action=edit&id=<?=h($editRow['id'])?>">
    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
    <div class="row">
      <div>
        <label>Datum</label>
        <input type="date" name="entry_date" value="<?=h($editRow['entry_date'])?>">
        <?php if(isset($errors['entry_date'])): ?><div class="error"><?=$errors['entry_date']?></div><?php endif; ?>
      </div>
      <div>
        <label>Uren</label>
        <input type="number" step="0.25" min="0.25" max="24" name="hours" value="<?=h($editRow['hours'])?>">
        <?php if(isset($errors['hours'])): ?><div class="error"><?=$errors['hours']?></div><?php endif; ?>
      </div>
    </div>
    <div class="row">
      <div>
        <label>Project</label>
        <input type="text" name="project" value="<?=h($editRow['project'])?>" placeholder="bijv. Portfolio Project">
        <?php if(isset($errors['project'])): ?><div class="error"><?=$errors['project']?></div><?php endif; ?>
      </div>
      <div>
        <label>Taak</label>
        <input type="text" name="task" value="<?=h($editRow['task'])?>" placeholder="bijv. Ontwikkeling">
        <?php if(isset($errors['task'])): ?><div class="error"><?=$errors['task']?></div><?php endif; ?>
      </div>
    </div>
    <label>Notitie (optioneel)</label>
    <textarea name="note" rows="3" placeholder="Wat heb je gedaan?"><?=h($editRow['note'] ?? '')?></textarea>
    <div class="actions">
      <button type="submit">Opslaan</button>
      <a class="btn secondary" href="index.php">Annuleren</a>
    </div>
  </form>
  <?php else: ?>
  <h2>Nieuwe uren</h2>
  <form class="card" method="post" action="index.php?action=create">
    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
    <div class="row">
      <div>
        <label>Datum</label>
        <input type="date" name="entry_date" value="<?=h($_POST['entry_date'] ?? '')?>">
        <?php if(isset($errors['entry_date'])): ?><div class="error"><?=$errors['entry_date']?></div><?php endif; ?>
      </div>
      <div>
        <label>Uren</label>
        <input type="number" step="0.25" min="0.25" max="24" name="hours" value="<?=h($_POST['hours'] ?? '')?>">
        <?php if(isset($errors['hours'])): ?><div class="error"><?=$errors['hours']?></div><?php endif; ?>
      </div>
    </div>
    <div class="row">
      <div>
        <label>Project</label>
        <input type="text" name="project" value="<?=h($_POST['project'] ?? '')?>" placeholder="bijv. Portfolio Project">
        <?php if(isset($errors['project'])): ?><div class="error"><?=$errors['project']?></div><?php endif; ?>
      </div>
      <div>
        <label>Taak</label>
        <input type="text" name="task" value="<?=h($_POST['task'] ?? '')?>" placeholder="bijv. Ontwikkeling">
        <?php if(isset($errors['task'])): ?><div class="error"><?=$errors['task']?></div><?php endif; ?>
      </div>
    </div>
    <label>Notitie (optioneel)</label>
    <textarea name="note" rows="3" placeholder="Wat heb je gedaan?"><?=h($_POST['note'] ?? '')?></textarea>
    <div class="actions">
      <button type="submit">Opslaan</button>
      <button class="btn secondary" type="reset">Reset</button>
    </div>
  </form>
  <?php endif; ?>

  <h2>Overzicht (<?=count($rows)?> rijen) — Totaal uren: <span class="total"><?=number_format($totalHours,2)?></span></h2>
  <table>
    <thead>
      <tr>
        <th>Datum</th><th>Project</th><th>Taak</th><th>Uren</th><th>Notitie</th><th class="muted">Acties</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?=h($r['entry_date'])?></td>
        <td><?=h($r['project'])?></td>
        <td><?=h($r['task'])?></td>
        <td><?=h($r['hours'])?></td>
        <td><?=nl2br(h($r['note']))?></td>
        <td>
          <a class="btn secondary" href="index.php?action=edit&id=<?=h($r['id'])?>">Bewerk</a>
          <form style="display:inline" method="post" action="index.php?action=delete&id=<?=h($r['id'])?>" onsubmit="return confirm('Verwijderen?');">
            <input type="hidden" name="csrf" value="<?=h($csrf)?>">
            <button type="submit" class="btn">Verwijder</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <p class="muted">Simpel demo-systeem (geen users/auth). Uitbreidbaar met projecten/taken-tabellen en login.</p>
</body>
</html>