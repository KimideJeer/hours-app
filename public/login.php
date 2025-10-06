<?php
declare(strict_types=1);
session_start();

/**
 * Simpele loginpagina:
 * - Form POST naar zichzelf
 * - CSRF-token bescherming
 * - Zoekt user op email en vergelijkt wachtwoord met password_verify()
 * - Slaat beperkte userinfo op in sessie en redirect naar index
 */

// CSRF-token genereren (voorkomt CSRF op login POST)
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

require __DIR__ . '/../config.php';  // DB-config (host, dbname, user, pass)
require __DIR__ . '/../src/auth.php'; // auth helpers

// DB-verbinding opzetten (PDO)
$cfg = require __DIR__ . '/../config.php';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['dbname']);

try {
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Exceptions bij DB-fouten
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Associatieve arrays
    ]);
} catch (PDOException $e) {
    // In productie toon je hier geen raw error; nu voor demo oké
    http_response_code(500);
    echo "<h1>DB error</h1><pre>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</pre>";
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1) CSRF-check
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors['csrf'] = 'Ongeldige sessie, probeer opnieuw.';
    } else {
        // 2) Invoer ophalen
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        // 3) Basis validatie
        if ($email === '' || $password === '') {
            $errors['login'] = 'Vul e-mail en wachtwoord in.';
        } else {
            // 4) User opzoeken en wachtwoord verifiëren
            $st = $pdo->prepare('SELECT id, email, password_hash, role FROM users WHERE email = ?');
            $st->execute([$email]);
            $user = $st->fetch();

            // password_verify vergelijkt plaintext tegen de hash uit de database
            if ($user && password_verify($password, $user['password_hash'])) {
                // 5) Inloggen en door naar de app
                loginUser((int)$user['id'], $user['email'], $user['role']);
                header('Location: index.php');
                exit;
            } else {
                $errors['login'] = 'Onjuiste inloggegevens.';
            }
        }
    }
}

// Kleine helper voor HTML-escaping (XSS-preventie)
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <title>Inloggen</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    /* Simpele, nette styling zonder externe libs */
    body{font-family:system-ui,Segoe UI,Arial,sans-serif;display:grid;place-items:center;height:100dvh;margin:0;background:#f8fafc}
    .card{width:min(480px,90vw);background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.06)}
    h1{margin:0 0 12px}
    label{font-weight:600}
    input{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;margin:6px 0 12px}
    .btn{background:#111827;color:#fff;border:0;border-radius:8px;padding:10px 14px;cursor:pointer;width:100%}
    .error{color:#b91c1c;margin-top:4px}
    .muted{color:#6b7280;font-size:.9rem}
  </style>
</head>
<body>
  <!-- Login formulier -->
  <form class="card" method="post" action="login.php">
    <h1>Inloggen</h1>

    <!-- CSRF token -->
    <input type="hidden" name="csrf" value="<?=h($csrf)?>">

    <label>E-mailadres</label>
    <input type="email" name="email" placeholder="you@example.com" required>

    <label>Wachtwoord</label>
    <input type="password" name="password" placeholder="••••••••" required>

    <button class="btn" type="submit">Inloggen</button>

    <!-- Foutmelding tonen als er iets mis ging -->
    <?php if(!empty($errors)): ?>
      <div class="error">
        <?=h(implode(' ', $errors))?>
      </div>
    <?php endif; ?>

    <p class="muted">
      Demo: <code>admin@example.com</code> / <code>admin123</code>
    
    </p>
  </form>
</body>
</html>
