<?php
declare(strict_types=1);

define('MAP_SITE_ROOT_SCRIPT', true);
require_once __DIR__ . '/map/includes/auth.php';

function requireLogin(): array
{
    $user = mapGetCurrentUser();
    if ($user !== null) {
        return $user;
    }

    mapRedirectWithQuery('/login.php', ['next' => 'suszic']);
}

function requireApiLogin(): int
{
    $user = mapGetCurrentUser();
    if ($user !== null) {
        return $user['user_id'];
    }

    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Authentification requise.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function validCsrfToken(mixed $token): bool
{
    return is_string($token) && mapVerifyCsrfToken($token);
}
