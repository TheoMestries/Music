<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (!mapIsPostRequest()) {
    mapRedirect(mapGetCurrentUser() !== null ? '/index.php' : '/login.php');
}

try {
    mapEnforceCsrfToken();
    mapLogoutUser(true);
    mapAddFlash('info', 'Vous etes deconnecte.');
    mapRedirect('/login.php');
} catch (DomainException $exception) {
    mapRenderStatusPage('Deconnexion refusee', $exception->getMessage(), 400, mapGetCurrentUser());
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception, 'map/logout', mapGetCurrentUser());
}
