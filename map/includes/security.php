<?php
declare(strict_types=1);

const MAP_APP_NAME = 'Atlas JDR';
const MAP_SESSION_NAME = 'jdr_map_session';
const MAP_CSRF_SESSION_KEY = 'map_csrf_token';
const MAP_FLASH_SESSION_KEY = 'map_flash_messages';

function mapBasePath(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $marker = '/map/';
    $position = strpos($scriptName, $marker);

    if ($position !== false) {
        return mapNormalizeUrlPath(substr($scriptName, 0, $position + 4));
    }

    if ($scriptName !== '' && str_ends_with($scriptName, '/map')) {
        return '/map';
    }

    return '/map';
}

function mapNormalizeUrlPath(string $path): string
{
    $trimmed = trim(str_replace('\\', '/', $path), '/');

    if ($trimmed === '') {
        return '/';
    }

    $segments = explode('/', $trimmed);
    $encodedSegments = array_map(
        static fn(string $segment): string => rawurlencode(rawurldecode($segment)),
        $segments
    );

    return '/' . implode('/', $encodedSegments);
}

function mapUrl(string $path = ''): string
{
    $basePath = mapBasePath();

    if ($path === '') {
        return $basePath . '/';
    }

    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    return $basePath . $path;
}

function mapUrlWithQuery(string $path, array $query = []): string
{
    $url = mapUrl($path);

    if ($query === []) {
        return $url;
    }

    return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function mapIsHttpsRequest(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }

    return false;
}

function mapEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mapStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() !== MAP_SESSION_NAME) {
            throw new RuntimeException('Une session PHP incompatible est deja active.');
        }

        return;
    }

    if (function_exists('ini_set')) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
    }

    session_name(MAP_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => rtrim(mapBasePath(), '/') . '/',
        'domain' => '',
        'secure' => mapIsHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function mapGenerateCsrfToken(): string
{
    mapStartSession();

    if (!isset($_SESSION[MAP_CSRF_SESSION_KEY]) || !is_string($_SESSION[MAP_CSRF_SESSION_KEY])) {
        $_SESSION[MAP_CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }

    return $_SESSION[MAP_CSRF_SESSION_KEY];
}

function mapGetCsrfToken(): string
{
    return mapGenerateCsrfToken();
}

function mapCsrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . mapEscape(mapGetCsrfToken()) . '">';
}

function mapVerifyCsrfToken(?string $token): bool
{
    mapStartSession();

    if (!is_string($token) || $token === '') {
        return false;
    }

    $sessionToken = $_SESSION[MAP_CSRF_SESSION_KEY] ?? null;

    return is_string($sessionToken) && hash_equals($sessionToken, $token);
}

function mapEnforceCsrfToken(?string $token = null): void
{
    $token ??= isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;

    if (!mapVerifyCsrfToken($token)) {
        throw new DomainException('Le formulaire a expire ou est invalide. Rechargez la page et reessayez.');
    }
}

function mapAddFlash(string $type, string $message): void
{
    mapStartSession();

    if (!isset($_SESSION[MAP_FLASH_SESSION_KEY]) || !is_array($_SESSION[MAP_FLASH_SESSION_KEY])) {
        $_SESSION[MAP_FLASH_SESSION_KEY] = [];
    }

    $_SESSION[MAP_FLASH_SESSION_KEY][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function mapConsumeFlashMessages(): array
{
    mapStartSession();

    $messages = $_SESSION[MAP_FLASH_SESSION_KEY] ?? [];
    unset($_SESSION[MAP_FLASH_SESSION_KEY]);

    return is_array($messages) ? $messages : [];
}

function mapIsPostRequest(): bool
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}

function mapRedirect(string $path): never
{
    header('Location: ' . mapUrl($path));
    exit;
}

function mapRedirectWithQuery(string $path, array $query = []): never
{
    header('Location: ' . mapUrlWithQuery($path, $query));
    exit;
}
