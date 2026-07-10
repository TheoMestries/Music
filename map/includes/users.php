<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function mapListUsers(): array
{
    $pdo = mapGetDatabase();
    $statement = $pdo->query(
        'SELECT id, username, display_name, role, is_active, created_at, updated_at
         FROM users
         ORDER BY LOWER(username) ASC'
    );

    return $statement->fetchAll();
}

function mapListActiveUsers(array $roles = []): array
{
    $pdo = mapGetDatabase();
    $sql = 'SELECT id, username, display_name, role, is_active, created_at, updated_at
            FROM users
            WHERE is_active = 1';
    $parameters = [];

    if ($roles !== []) {
        $placeholders = [];
        foreach ($roles as $index => $role) {
            $key = ':role_' . $index;
            $placeholders[] = $key;
            $parameters[$key] = mapValidateRole($role);
        }

        $sql .= ' AND role IN (' . implode(', ', $placeholders) . ')';
    }

    $sql .= ' ORDER BY CASE role WHEN \'admin\' THEN 0 WHEN \'gm\' THEN 1 ELSE 2 END, LOWER(display_name) ASC, LOWER(username) ASC';
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return $statement->fetchAll();
}

function mapCountActiveAdmins(?int $excludeUserId = null): int
{
    $pdo = mapGetDatabase();
    $sql = 'SELECT COUNT(*) FROM users WHERE role = :role AND is_active = 1';
    $parameters = [
        ':role' => 'admin',
    ];

    if ($excludeUserId !== null) {
        $sql .= ' AND id != :exclude_user_id';
        $parameters[':exclude_user_id'] = $excludeUserId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return (int) $statement->fetchColumn();
}

function mapCreateUser(
    string $username,
    string $displayName,
    string $role,
    string $password
): int {
    $pdo = mapGetDatabase();
    $username = mapValidateUsername($username);
    $displayName = mapValidateDisplayName($displayName);
    $role = mapValidateRole($role);
    $password = mapValidatePasswordStrength($password);
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if (!is_string($passwordHash) || $passwordHash === '') {
        throw new RuntimeException('Impossible de securiser le mot de passe utilisateur.');
    }

    if (mapFindUserByUsername($username) !== null) {
        throw new DomainException('Ce nom d utilisateur est deja utilise.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO users (username, display_name, password_hash, role, is_active, created_at, updated_at)
         VALUES (:username, :display_name, :password_hash, :role, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );

    try {
        $statement->execute([
            ':username' => $username,
            ':display_name' => $displayName,
            ':password_hash' => $passwordHash,
            ':role' => $role,
        ]);
    } catch (PDOException $exception) {
        if (str_contains($exception->getMessage(), 'UNIQUE')) {
            throw new DomainException('Ce nom d utilisateur est deja utilise.');
        }

        throw $exception;
    }

    return (int) $pdo->lastInsertId();
}

function mapAssertAdminRetention(array $user, string $nextRole, bool $nextIsActive): void
{
    if (
        (string) $user['role'] !== 'admin'
        || (int) $user['is_active'] !== 1
        || ($nextRole === 'admin' && $nextIsActive)
    ) {
        return;
    }

    if (mapCountActiveAdmins((int) $user['id']) < 1) {
        throw new DomainException('Le dernier administrateur actif ne peut pas etre desactive ou retrograde.');
    }
}

function mapUpdateUserProfile(
    int $targetUserId,
    string $displayName,
    string $role,
    int $actingUserId
): void {
    $pdo = mapGetDatabase();
    $user = mapFindUserById($targetUserId);

    if ($user === null) {
        mapThrowNotFound('Utilisateur introuvable.');
    }

    $displayName = mapValidateDisplayName($displayName);
    $role = mapValidateRole($role);
    $isActive = (int) $user['is_active'] === 1;
    mapAssertAdminRetention($user, $role, $isActive);

    $statement = $pdo->prepare(
        'UPDATE users
         SET display_name = :display_name,
             role = :role,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute([
        ':display_name' => $displayName,
        ':role' => $role,
        ':id' => $targetUserId,
    ]);
}

function mapSetUserActiveState(int $targetUserId, bool $isActive, int $actingUserId): void
{
    $pdo = mapGetDatabase();
    $user = mapFindUserById($targetUserId);

    if ($user === null) {
        mapThrowNotFound('Utilisateur introuvable.');
    }

    if ($targetUserId === $actingUserId && !$isActive) {
        throw new DomainException('Vous ne pouvez pas desactiver votre propre compte.');
    }

    mapAssertAdminRetention($user, (string) $user['role'], $isActive);

    $statement = $pdo->prepare(
        'UPDATE users
         SET is_active = :is_active,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute([
        ':is_active' => $isActive ? 1 : 0,
        ':id' => $targetUserId,
    ]);
}

function mapResetUserPassword(int $targetUserId, string $password): void
{
    $pdo = mapGetDatabase();
    $user = mapFindUserById($targetUserId);

    if ($user === null) {
        mapThrowNotFound('Utilisateur introuvable.');
    }

    $password = mapValidatePasswordStrength($password);
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if (!is_string($passwordHash) || $passwordHash === '') {
        throw new RuntimeException('Impossible de securiser le nouveau mot de passe.');
    }

    $statement = $pdo->prepare(
        'UPDATE users
         SET password_hash = :password_hash,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute([
        ':password_hash' => $passwordHash,
        ':id' => $targetUserId,
    ]);
}
