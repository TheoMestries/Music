<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/security.php';

try {
    $pdo = mapGetDatabase();
    $version = mapCurrentDatabaseVersion($pdo);
    $migrationCount = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();

    echo MAP_APP_NAME . ' : schema SQLite version ' . $version . ' (' . $migrationCount . " migrations appliquees).\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration impossible : ' . $exception->getMessage() . "\n");
    exit(1);
}
