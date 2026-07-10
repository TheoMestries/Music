<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

try {
    $currentUser = mapGetCurrentUser();

    if ($currentUser === null) {
        mapJsonSuccess([
            'authenticated' => false,
            'user' => null,
        ]);
    }

    mapJsonSuccess([
        'authenticated' => true,
        'user' => mapPublicUserData($currentUser),
    ]);
} catch (Throwable $exception) {
    error_log('[map/api/session] ' . $exception->getMessage());
    mapJsonError('Une erreur interne est survenue.', 500);
}
