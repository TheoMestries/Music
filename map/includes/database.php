<?php
declare(strict_types=1);

require_once __DIR__ . '/table-timing.php';

const MAP_MIGRATIONS_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'migrations';

function mapDatabasePath(): string
{
    $overridePath = getenv('ATLAS_JDR_DATABASE_PATH');

    if (is_string($overridePath) && trim($overridePath) !== '') {
        return $overridePath;
    }

    return __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'map.sqlite';
}

function mapEnsureDatabaseDirectoryExists(string $databasePath): void
{
    $directory = dirname($databasePath);

    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Impossible de créer le dossier de données.');
    }
}

function mapGetDatabase(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    mapTableTimingMark('before_db');
    $pdo = mapOpenDatabase(mapDatabasePath());
    mapTableTimingMark('db_open');

    return $pdo;
}

function mapOpenDatabase(string $databasePath): PDO
{
    mapEnsureDatabaseDirectoryExists($databasePath);

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('L’extension PHP pdo_sqlite doit être active pour utiliser Sus20.');
    }

    try {
        $pdo = new PDO(
            'sqlite:' . $databasePath,
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $exception) {
        throw new RuntimeException('Impossible d’initialiser la base SQLite.', 0, $exception);
    }

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    mapRunDatabaseMigrations($pdo);

    return $pdo;
}

function mapEnsureMigrationTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS schema_migrations (
            version INTEGER PRIMARY KEY,
            name TEXT NOT NULL UNIQUE,
            applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
}

function mapLoadDatabaseMigrations(): array
{
    $files = glob(MAP_MIGRATIONS_DIRECTORY . DIRECTORY_SEPARATOR . '*.php');

    if ($files === false) {
        throw new RuntimeException('Impossible de lire le dossier des migrations SQLite.');
    }

    sort($files, SORT_STRING);
    $migrations = [];

    foreach ($files as $file) {
        $definition = require $file;
        $fileName = basename($file);

        if (!preg_match('/^(\d+)_([a-z0-9_]+)\.php$/', $fileName, $matches)) {
            throw new RuntimeException('Nom de fichier de migration invalide : ' . $fileName);
        }

        if (!is_array($definition)) {
            throw new RuntimeException('Definition de migration invalide : ' . $fileName);
        }

        $version = $definition['version'] ?? null;
        $name = $definition['name'] ?? null;
        $up = $definition['up'] ?? null;

        if (!is_int($version) || $version < 1 || $version !== (int) $matches[1]) {
            throw new RuntimeException('Version de migration invalide : ' . $fileName);
        }

        if (!is_string($name) || $name === '' || $name !== $matches[2]) {
            throw new RuntimeException('Nom de migration incoherent : ' . $fileName);
        }

        if (!is_callable($up)) {
            throw new RuntimeException('Action de migration manquante : ' . $fileName);
        }

        if (isset($migrations[$version])) {
            throw new RuntimeException('Version de migration dupliquee : ' . $version);
        }

        $migrations[$version] = [
            'version' => $version,
            'name' => $name,
            'up' => $up,
        ];
    }

    ksort($migrations, SORT_NUMERIC);

    return $migrations;
}

function mapRunDatabaseMigrations(PDO $pdo): void
{
    mapEnsureMigrationTable($pdo);
    $migrations = mapLoadDatabaseMigrations();
    $appliedMigrations = [];

    foreach ($pdo->query('SELECT version, name FROM schema_migrations ORDER BY version ASC') as $row) {
        $appliedMigrations[(int) $row['version']] = (string) $row['name'];
    }

    foreach ($appliedMigrations as $version => $name) {
        if (!isset($migrations[$version])) {
            throw new RuntimeException('La base utilise une migration inconnue du code : ' . $version);
        }

        if ($migrations[$version]['name'] !== $name) {
            throw new RuntimeException('Le nom de la migration ' . $version . ' ne correspond pas a la base.');
        }
    }

    foreach ($migrations as $version => $migration) {
        if (isset($appliedMigrations[$version])) {
            continue;
        }

        try {
            $pdo->beginTransaction();

            // Une autre requete peut avoir termine cette migration pendant
            // notre attente du verrou SQLite.
            $alreadyAppliedName = mapAppliedMigrationName($pdo, $version);
            if ($alreadyAppliedName !== null) {
                if ($alreadyAppliedName !== $migration['name']) {
                    throw new RuntimeException('Le nom de la migration ' . $version . ' ne correspond pas a la base.');
                }

                $pdo->commit();
                continue;
            }

            $migration['up']($pdo);

            $statement = $pdo->prepare(
                'INSERT INTO schema_migrations (version, name, applied_at)
                 VALUES (:version, :name, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                ':version' => $version,
                ':name' => $migration['name'],
            ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Deux premiers appels concurrents peuvent lire le meme historique
            // avant que l un des deux n enregistre la version. Si l autre appel
            // l a correctement appliquee, la base est deja dans l etat attendu.
            if (mapAppliedMigrationName($pdo, $version) === $migration['name']) {
                continue;
            }

            throw new RuntimeException(
                'Echec de la migration SQLite ' . $version . ' (' . $migration['name'] . ').',
                0,
                $exception
            );
        }
    }
}

function mapAppliedMigrationName(PDO $pdo, int $version): ?string
{
    $statement = $pdo->prepare('SELECT name FROM schema_migrations WHERE version = :version LIMIT 1');
    $statement->execute([':version' => $version]);
    $name = $statement->fetchColumn();

    return is_string($name) ? $name : null;
}

function mapCurrentDatabaseVersion(PDO $pdo): int
{
    mapEnsureMigrationTable($pdo);

    return (int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn();
}

function mapTableHasColumn(PDO $pdo, string $table, string $column): bool
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $table)) {
        throw new InvalidArgumentException('Nom de table SQLite invalide.');
    }

    $statement = $pdo->query('PRAGMA table_info(' . $table . ')');
    $columns = $statement->fetchAll();

    foreach ($columns as $definition) {
        if (($definition['name'] ?? null) === $column) {
            return true;
        }
    }

    return false;
}
