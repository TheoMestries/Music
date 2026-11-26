<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/codex-kanka.php';
require_once __DIR__ . '/../includes/response.php';

const MAP_CODEX_CHUNK_BYTES = 1024 * 1024;

try {
    if (!mapIsPostRequest()) {
        header('Allow: POST');
        mapJsonError('Méthode non autorisée.', 405);
    }
    $user = mapGetCurrentUser();
    if ($user === null) {
        mapJsonError('Authentification requise.', 401);
    }
    $campaignId = mapValidatePositiveInt($_GET['campaignId'] ?? null, 'Campagne');
    mapRequireCampaignGm($campaignId, $user);
    mapEnforceCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    $action = $_GET['action'] ?? '';
    $upload = $_SESSION['map_codex_upload'] ?? null;
    if ($action === 'start') {
        if (!class_exists(ZipArchive::class)) {
            throw new DomainException('L’extension PHP zip est nécessaire pour importer un export Kanka.');
        }
        $size = mapValidatePositiveInt($_GET['size'] ?? null, 'Taille de l’archive');
        if ($size > MAP_CODEX_ARCHIVE_BYTES) {
            throw new DomainException('L’archive ZIP dépasse 768 Mio.');
        }
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sus20-codex-' . substr(hash('sha256', __DIR__), 0, 16);
        if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le dossier temporaire.');
        }
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.part') ?: [] as $stale) {
            if (is_file($stale) && filemtime($stale) < time() - 86400) {
                @unlink($stale);
            }
        }
        $token = bin2hex(random_bytes(24));
        $path = $directory . DIRECTORY_SEPARATOR . $token . '.part';
        $stream = fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Impossible de préparer l’archive temporaire.');
        }
        fclose($stream);
        if ($upload !== null && is_file($upload['path'])) {
            @unlink($upload['path']);
        }
        $_SESSION['map_codex_upload'] = [
            'token' => $token, 'path' => $path, 'size' => $size, 'campaign' => $campaignId, 'user' => (int) $user['user_id'],
        ];
        mapJsonSuccess(['token' => $token, 'chunkBytes' => MAP_CODEX_CHUNK_BYTES]);
    }

    if ($upload === null || $upload['campaign'] !== $campaignId || $upload['user'] !== (int) $user['user_id']
        || !hash_equals($upload['token'], (string) ($_GET['token'] ?? '')) || !is_file($upload['path'])) {
        throw new DomainException('Le transfert a expiré. Sélectionnez de nouveau le fichier ZIP.');
    }
    $path = $upload['path'];
    if ($action === 'append') {
        $offset = filter_var($_GET['offset'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $currentSize = filesize($path);
        $length = min(MAP_CODEX_CHUNK_BYTES, $upload['size'] - $currentSize);
        if ($offset === false || $currentSize !== $offset || $length <= 0) {
            throw new DomainException('Le transfert est incomplet. Relancez l’import.');
        }
        $bytes = file_get_contents('php://input', false, null, 0, $length + 1);
        if ($bytes === false || strlen($bytes) !== $length) {
            throw new DomainException('Une partie de l’archive n’a pas été reçue. Relancez l’import.');
        }
        if (file_put_contents($path, $bytes, FILE_APPEND | LOCK_EX) !== $length) {
            throw new RuntimeException('Impossible d’écrire l’archive temporaire.');
        }
        mapJsonSuccess();
    }
    if ($action !== 'finish') {
        throw new DomainException('Action d’import inconnue.');
    }
    if (filesize($path) !== $upload['size']) {
        throw new DomainException('Le transfert est incomplet. Relancez l’import.');
    }
    unset($_SESSION['map_codex_upload']);
    session_write_close();
    try {
        set_time_limit(180);
        $result = mapImportKankaCodex(mapGetDatabase(), $campaignId, $path, (int) $user['user_id']);
    } finally {
        @unlink($path);
    }
    mapJsonSuccess($result);
} catch (MapHttpException $error) {
    mapJsonError($error->getMessage(), $error->getStatusCode());
} catch (DomainException $error) {
    mapJsonError($error->getMessage());
} catch (Throwable $error) {
    error_log('[codex-import] ' . $error::class);
    mapJsonError('L’import a échoué. Réessayez dans un instant.', 500);
}
