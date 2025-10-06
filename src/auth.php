<?php
declare(strict_types=1);

/**
 * Auth helper: inloggen/uitloggen + sessiecontrole.
 * Vereist dat session_start() al is aangeroepen in index.php/login.php.
 */

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function currentUserId(): ?int {
    return $_SESSION['user_id'] ?? null;
}

function currentUserEmail(): ?string {
    return $_SESSION['user_email'] ?? null;
}

function currentUserRole(): ?string {
    return $_SESSION['user_role'] ?? null;
}

/**
 * Sla minimale user-data op in de sessie.
 */
function loginUser(int $id, string $email, string $role = 'employee'): void {
    // Verhoog veiligheid van sessie-cookie
    if (PHP_SESSION_NONE === session_status()) {
        session_start();
    }
    // Nieuwe sessie-id na login helpt tegen session fixation
    session_regenerate_id(true);

    $_SESSION['user_id'] = $id;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_role'] = $role;
}

/** Verwijder sessiegegevens volledig. */
function logoutUser(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/** Redirect naar login als je niet ingelogd bent. */
function requireLogin(string $loginPath = 'login.php'): void {
    if (!isLoggedIn()) {
        header("Location: {$loginPath}");
        exit;
    }
}
