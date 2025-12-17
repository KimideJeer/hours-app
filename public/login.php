<?php
declare(strict_types=1);
session_start();

/**
 * Login + Registratie in één pagina
 */

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

require __DIR__ . '/../config.php';
require __DIR__ . '/../src/auth.php';

// DB connectie
$cfg = require __DIR__ . '/../config.php';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['dbname']);
try {
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo "<h1>DB error</h1><pre>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</pre>";
    exit;
}

$view   = $_GET['view'] ?? 'login';   // 'login' of 'register'
$errors = [];
$flash  = null;                        // optioneel infobericht (boven form)

// ============ LOGIN HANDLER ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'login') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['csrf'] = 'Ongeldige sessie, probeer opnieuw.';
    } else {
        $email = mb_strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        if ($email === '' || $password === '') {
            $errors['login'] = 'Vul e-mail en wachtwoord in.';
        } else {
            $st = $pdo->prepare('SELECT id, email, password_hash, role FROM users WHERE email = ?');
            $st->execute([$email]);
            $user = $st->fetch();
            if ($user && password_verify($password, $user['password_hash'])) {
                loginUser((int)$user['id'], $user['email'], $user['role']);
                header('Location: index.php');
                exit;
            } else {
                $errors['login'] = 'Onjuiste inloggegevens.';
            }
        }
    }
    $view = 'login';
}

// ============ REGISTRATIE HANDLER ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'register') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['csrf'] = 'Ongeldige sessie, probeer opnieuw.';
    } else {
        $email = mb_strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm']  ?? '';

        // Basisvalidatie
        if ($email === '')                 { $errors['email'] = 'E-mail is verplicht.'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Ongeldig e-mailadres.'; }
        if ($password === '')              { $errors['password'] = 'Wachtwoord is verplicht.'; }
        if (strlen($password) < 8)         { $errors['password'] = 'Minimaal 8 tekens.'; }
        if ($confirm !== $password)        { $errors['confirm'] = 'Wachtwoorden komen niet overeen.'; }

        // Uniek email?
        if (!$errors) {
            $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $check->execute([$email]);
            if ($check->fetch()) {
                $errors['email'] = 'E-mailadres bestaat al.';
            }
        }

        // Aanmaken
        if (!$errors) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            try {
                $ins = $pdo->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)');
                $ins->execute([$email, $hash, 'employee']); // standaard rol
            } catch (PDOException $e) {
                // 23000 = duplicate key (fallback)
                if ($e->getCode() === '23000') {
                    $errors['email'] = 'E-mailadres bestaat al.';
                } else {
                    throw $e;
                }
            }

            if (!$errors) {
                // Automatisch inloggen
                $uid = (int)$pdo->lastInsertId();
                loginUser($uid, $email, 'employee');
                header('Location: index.php');
                exit;
            }
        }
    }
    $view = 'register';
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <title>Inloggen / Registreren</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
      <link rel="stylesheet" href="css/style.css">
</head>
<body>
  <div class="card">
    <h1><?= $view === 'register' ? 'Account aanmaken' : 'Inloggen' ?></h1>

    <div class="tabs">
      <a class="tab <?= $view==='login' ? 'active':'' ?>" href="login.php?view=login">Inloggen</a>
      <a class="tab <?= $view==='register' ? 'active':'' ?>" href="login.php?view=register">Account aanmaken</a>
    </div>

    <?php if ($view === 'login'): ?>
    <!-- LOGIN FORM -->
    <form method="post" action="login.php?view=login">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="mode" value="login">

      <label>E-mailadres</label>
      <input type="email" name="email" placeholder="you@example.com" required>

      <label>Wachtwoord</label>
      <input type="password" name="password" placeholder="••••••••" required>

      <button class="btn" type="submit">Inloggen</button>
      <?php if(!empty($errors)): ?>
        <div class="error"><?=h(implode(' ', $errors))?></div>
      <?php endif; ?>

      <p class="muted" style="margin-top:12px;">
        Nog geen account? <a href="login.php?view=register">Maak er één aan</a>.
      </p>
    </form>

    <?php else: ?>
    <!-- REGISTER FORM -->
    <form method="post" action="login.php?view=register">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="mode" value="register">

      <label>E-mailadres</label>
      <input type="email" name="email" placeholder="you@example.com" required>

      <label>Wachtwoord (min. 8 tekens)</label>
      <input type="password" name="password" minlength="8" required>

      <label>Bevestig wachtwoord</label>
      <input type="password" name="confirm" minlength="8" required>

      <button class="btn" type="submit">Account aanmaken</button>
      <?php if(!empty($errors)): ?>
        <div class="error"><?=h(implode(' ', $errors))?></div>
      <?php endif; ?>

      <p class="muted" style="margin-top:12px;">
        Al een account? <a href="login.php?view=login">Log dan in</a>.
      </p>
    </form>
    <?php endif; ?>
  </div>
</body>
</html>
