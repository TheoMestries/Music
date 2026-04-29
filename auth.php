<?php
declare(strict_types=1);

const AUTH_USER_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'users.json';

function startAuthSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function isAuthenticated(): bool
{
    startAuthSession();
    return isset($_SESSION['music_user']);
}

function requireLogin(): void
{
    if (isAuthenticated()) {
        return;
    }

    header('Location: login.php');
    exit;
}

function requireApiLogin(): void
{
    if (isAuthenticated()) {
        return;
    }

    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Authentification requise.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function hasConfiguredUsers(): bool
{
    return count(readUsers()) > 0;
}

function readUsers(): array
{
    if (!is_file(AUTH_USER_FILE)) {
        return [];
    }

    $content = file_get_contents(AUTH_USER_FILE);
    if ($content === false || trim($content) === '') {
        return [];
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

function authenticateUser(string $username, string $password): bool
{
    foreach (readUsers() as $user) {
        if (($user['username'] ?? '') !== $username) {
            continue;
        }

        if (password_verify($password, (string) ($user['passwordHash'] ?? ''))) {
            startAuthSession();
            session_regenerate_id(true);
            $_SESSION['music_user'] = $username;
            return true;
        }
    }

    return false;
}

function logoutUser(): void
{
    startAuthSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
