<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../src/auth.php';

if (isLoggedIn()) {
  header('Location: projects.php'); // ingelogd → projecten
} else {
  header('Location: login.php');    // niet ingelogd → login
}
exit;
