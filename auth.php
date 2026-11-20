<?php
declare(strict_types=1);

require_once __DIR__ . '/storage.php';

const AUTH_USER_FILE = MUSIC_DATA_DIR . '/users.json';

function startAuthSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function currentMusicUser(): ?string
{
    startAuthSession();
    $username = $_SESSION['music_user'] ?? null;
    return is_string($username) && findMusicUser($username) !== null ? $username : null;
}

function isAuthenticated(): bool
{
    return currentMusicUser() !== null;
}

function requireLogin(): string
{
    $username = currentMusicUser();
    if ($username !== null) {
        return $username;
    }

    header('Location: login.php');
    exit;
}

function requireApiLogin(): string
{
    $username = currentMusicUser();
    if ($username !== null) {
        return $username;
    }

    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Authentification requise.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function csrfToken(): string
{
    startAuthSession();
    return $_SESSION['music_csrf'] ??= bin2hex(random_bytes(32));
}

function validCsrfToken(mixed $token): bool
{
    return is_string($token) && hash_equals(csrfToken(), $token);
}

function readUsers(): array
{
    return musicReadJson(AUTH_USER_FILE);
}

function findMusicUser(string $username, bool $ignoreCase = false): ?array
{
    foreach (readUsers() as $user) {
        if ($ignoreCase ? strcasecmp($user['username'], $username) === 0 : $user['username'] === $username) {
            return $user;
        }
    }

    return null;
}

function legacyMusicOwner(): ?string
{
    $fallback = null;
    $namedAdmin = null;
    foreach (readUsers() as $user) {
        if (($user['role'] ?? null) === 'admin') {
            return $user['username'];
        }
        if (!isset($user['role'])) {
            $fallback ??= $user['username'];
            if (strcasecmp($user['username'], 'admin') === 0) {
                $namedAdmin ??= $user['username'];
            }
        }
    }
    return $namedAdmin ?? $fallback;
}

function registerMusicUser(string $username, string $password, string $confirmation): void
{
    if (!preg_match('/\A[a-zA-Z0-9_-]{3,40}\z/', $username)) {
        throw new DomainException('Utilisez entre 3 et 40 lettres, chiffres, tirets ou underscores pour votre identifiant.');
    }
    if (strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new DomainException('Utilisez un mot de passe de 8 caractères minimum et de 72 octets maximum.');
    }
    if ($password !== $confirmation) {
        throw new DomainException('Les mots de passe doivent être identiques.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    musicUpdateJson(AUTH_USER_FILE, static function (array &$users) use ($username, $passwordHash): void {
        foreach ($users as $user) {
            if (strcasecmp($user['username'], $username) === 0) {
                throw new DomainException('Cet identifiant est déjà utilisé.');
            }
        }
        $users[] = [
            'username' => $username,
            'passwordHash' => $passwordHash,
            'role' => 'member',
            'room' => bin2hex(random_bytes(16)),
        ];
    });
    loginMusicUser($username);
}

function musicRoomForUser(string $username): string
{
    $room = findMusicUser($username)['room'] ?? null;
    if (is_string($room)) {
        return $room;
    }

    return musicUpdateJson(AUTH_USER_FILE, static function (array &$users) use ($username): string {
        foreach ($users as &$user) {
            if ($user['username'] === $username) {
                return $user['room'] ??= bin2hex(random_bytes(16));
            }
        }
        throw new RuntimeException('Compte SuSZic introuvable.');
    });
}

function musicRoomOwner(mixed $room): ?string
{
    if (!is_string($room) || !preg_match('/\A[a-f0-9]{32}\z/', $room)) {
        return null;
    }
    foreach (readUsers() as $user) {
        if (($user['room'] ?? null) === $room) {
            return $user['username'];
        }
    }
    return null;
}

function loginMusicUser(string $username): void
{
    startAuthSession();
    session_regenerate_id(true);
    $_SESSION['music_user'] = $username;
    unset($_SESSION['music_csrf']);
}

function authenticateUser(string $username, string $password): bool
{
    $user = findMusicUser($username);
    if ($user !== null && password_verify($password, $user['passwordHash'])) {
        loginMusicUser($user['username']);
        return true;
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
