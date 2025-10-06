<?php
declare(strict_types=1);
session_start();

/**
 * Uitlogpagina:
 * - Roept logoutUser() aan (leegt sessie en cookie)
 * - Stuurt je terug naar login
 */

require __DIR__ . '/../src/auth.php';

logoutUser();

header('Location: login.php');
exit;
