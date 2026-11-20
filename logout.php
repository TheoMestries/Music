<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validCsrfToken($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit;
}

logoutUser();
header('Location: login.php');
exit;
