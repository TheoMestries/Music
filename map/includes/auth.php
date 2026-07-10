<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/validation.php';

const MAP_USER_SESSION_KEY = 'map_authenticated_user';

function mapUsersExist(): bool
{
    $pdo = mapGetDatabase();

    return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

function mapFindUserById(int $userId): ?array
{
    $pdo = mapGetDatabase();
    $statement = $pdo->prepare(
        'SELECT id, username, display_name, password_hash, role, is_active, created_at, updated_at
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute([
        ':id' => $userId,
    ]);

    $user = $statement->fetch();

    return is_array($user) ? $user : null;
}

function mapFindUserByUsername(string $username): ?array
{
    $pdo = mapGetDatabase();
    $statement = $pdo->prepare(
        'SELECT id, username, display_name, password_hash, role, is_active, created_at, updated_at
         FROM users
         WHERE username = :username
         LIMIT 1'
    );
    $statement->execute([
        ':username' => $username,
    ]);

    $user = $statement->fetch();

    return is_array($user) ? $user : null;
}

function mapCreateInitialAdministrator(string $username, string $displayName, string $password): int
{
    $pdo = mapGetDatabase();
    $password = mapValidatePasswordStrength($password);
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if (!is_string($passwordHash) || $passwordHash === '') {
        throw new RuntimeException('Impossible de securiser le mot de passe administrateur.');
    }

    $username = mapValidateUsername($username);
    $displayName = mapValidateDisplayName($displayName);
    mapValidateRole('admin');

    try {
        $pdo->beginTransaction();

        $existingUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($existingUsers > 0) {
            throw new DomainException('L initialisation a deja ete effectuee. Connectez vous a la place.');
        }

        $statement = $pdo->prepare(
            'INSERT INTO users (username, display_name, password_hash, role, is_active, created_at, updated_at)
             VALUES (:username, :display_name, :password_hash, :role, :is_active, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            ':username' => $username,
            ':display_name' => $displayName,
            ':password_hash' => $passwordHash,
            ':role' => 'admin',
            ':is_active' => 1,
        ]);

        $userId = (int) $pdo->lastInsertId();
        $pdo->commit();

        return $userId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($exception instanceof DomainException) {
            throw $exception;
        }

        if ($exception instanceof PDOException && str_contains($exception->getMessage(), 'UNIQUE')) {
            throw new DomainException('Ce nom d utilisateur est deja utilise.');
        }

        throw $exception;
    }
}

function mapSyncSessionUser(array $user): array
{
    mapStartSession();

    $_SESSION[MAP_USER_SESSION_KEY] = [
        'user_id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'display_name' => (string) $user['display_name'],
        'role' => (string) $user['role'],
    ];

    return [
        'user_id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'display_name' => (string) $user['display_name'],
        'role' => (string) $user['role'],
    ];
}

function mapLoginUser(array $user): void
{
    mapStartSession();
    session_regenerate_id(true);
    mapSyncSessionUser($user);
}

function mapAuthenticateUser(string $username, string $password): void
{
    $username = mapValidateUsername($username);
    $password = mapValidatePasswordInput($password);
    $user = mapFindUserByUsername($username);

    if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
        throw new DomainException('Identifiant ou mot de passe incorrect.');
    }

    if ((int) $user['is_active'] !== 1) {
        throw new DomainException('Ce compte est desactive.');
    }

    mapLoginUser($user);
}

function mapLogoutUser(bool $restartSession = false): void
{
    mapStartSession();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();

    if ($restartSession) {
        mapStartSession();
    }
}

function mapIsAuthenticated(): bool
{
    return mapGetCurrentUser() !== null;
}

function mapGetCurrentUser(): ?array
{
    static $resolved = false;
    static $currentUser = null;

    if ($resolved) {
        return $currentUser;
    }

    $resolved = true;
    mapStartSession();
    $sessionUser = $_SESSION[MAP_USER_SESSION_KEY] ?? null;

    if (
        !is_array($sessionUser)
        || !isset($sessionUser['user_id'])
        || !is_numeric($sessionUser['user_id'])
    ) {
        return null;
    }

    $user = mapFindUserById((int) $sessionUser['user_id']);

    if ($user === null || (int) $user['is_active'] !== 1) {
        mapLogoutUser(true);
        mapAddFlash('error', 'Votre session n est plus valide ou votre compte a ete desactive.');
        return null;
    }

    $currentUser = mapSyncSessionUser($user);

    return $currentUser;
}

function mapHasGlobalRole(array $user, array $roles): bool
{
    return in_array((string) ($user['role'] ?? ''), $roles, true);
}

function mapRequireLogin(): array
{
    $currentUser = mapGetCurrentUser();

    if ($currentUser !== null) {
        return $currentUser;
    }

    mapRedirect('/login.php');
}

function mapRequireGlobalRole(array $roles): array
{
    $currentUser = mapRequireLogin();

    if (!mapHasGlobalRole($currentUser, $roles)) {
        mapThrowForbidden('Vous n avez pas les droits necessaires pour acceder a cette page.');
    }

    return $currentUser;
}

function mapRequireAdmin(): array
{
    return mapRequireGlobalRole(['admin']);
}

function mapRedirectIfAuthenticated(): void
{
    if (mapGetCurrentUser() === null) {
        return;
    }

    mapRedirect('/index.php');
}

function mapRoleLabel(string $role): string
{
    return match ($role) {
        'admin' => 'Administrateur',
        'gm' => 'Maître du jeu',
        'player' => 'Joueur',
        default => 'Rôle inconnu',
    };
}

function mapPublicUserData(array $user): array
{
    $userId = $user['user_id'] ?? $user['id'] ?? null;
    $displayName = $user['display_name'] ?? $user['displayName'] ?? null;

    return [
        'id' => (int) $userId,
        'username' => (string) ($user['username'] ?? ''),
        'displayName' => (string) $displayName,
        'role' => (string) ($user['role'] ?? ''),
    ];
}
