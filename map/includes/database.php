<?php
declare(strict_types=1);

function mapDatabasePath(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'map.sqlite';
}

function mapEnsureDataDirectoryExists(): void
{
    $directory = dirname(mapDatabasePath());

    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Impossible de creer le dossier de donnees de l outil de cartes.');
    }
}

function mapGetDatabase(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    mapEnsureDataDirectoryExists();

    try {
        $pdo = new PDO(
            'sqlite:' . mapDatabasePath(),
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $exception) {
        throw new RuntimeException('Impossible d initialiser la base SQLite de l outil de cartes.', 0, $exception);
    }

    $pdo->exec('PRAGMA foreign_keys = ON');
    mapInitializeDatabase($pdo);

    return $pdo;
}

function mapInitializeDatabase(PDO $pdo): void
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL COLLATE NOCASE UNIQUE,
            display_name TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK (role IN ('admin', 'gm', 'player')),
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT DEFAULT NULL,
            owner_user_id INTEGER NOT NULL,
            is_archived INTEGER NOT NULL DEFAULT 0 CHECK (is_archived IN (0, 1)),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS campaign_members (
            campaign_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            member_role TEXT NOT NULL CHECK (member_role IN ('gm', 'player')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (campaign_id, user_id),
            FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        )",
    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    mapRunDatabaseMigrations($pdo);
    mapEnsureIndexes($pdo);
}

function mapRunDatabaseMigrations(PDO $pdo): void
{
    if (!mapTableHasColumn($pdo, 'campaigns', 'is_archived')) {
        $pdo->exec('ALTER TABLE campaigns ADD COLUMN is_archived INTEGER NOT NULL DEFAULT 0');
    }

    if (!mapTableHasColumn($pdo, 'campaign_members', 'updated_at')) {
        $pdo->exec('ALTER TABLE campaign_members ADD COLUMN updated_at TEXT DEFAULT NULL');
        $pdo->exec('UPDATE campaign_members SET updated_at = created_at WHERE updated_at IS NULL');
    }

    if (!mapTableHasColumn($pdo, 'campaigns', 'updated_at')) {
        $pdo->exec('ALTER TABLE campaigns ADD COLUMN updated_at TEXT DEFAULT NULL');
        $pdo->exec('UPDATE campaigns SET updated_at = created_at WHERE updated_at IS NULL');
    }
}

function mapEnsureIndexes(PDO $pdo): void
{
    $statements = [
        'CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)',
        'CREATE INDEX IF NOT EXISTS idx_users_is_active ON users(is_active)',
        'CREATE INDEX IF NOT EXISTS idx_users_role_active ON users(role, is_active)',
        'CREATE INDEX IF NOT EXISTS idx_campaigns_owner_user_id ON campaigns(owner_user_id)',
        'CREATE INDEX IF NOT EXISTS idx_campaigns_is_archived ON campaigns(is_archived)',
        'CREATE INDEX IF NOT EXISTS idx_campaign_members_campaign_id ON campaign_members(campaign_id)',
        'CREATE INDEX IF NOT EXISTS idx_campaign_members_user_id ON campaign_members(user_id)',
        'CREATE INDEX IF NOT EXISTS idx_campaign_members_member_role ON campaign_members(member_role)',
    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

function mapTableHasColumn(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->query('PRAGMA table_info(' . $table . ')');
    $columns = $statement->fetchAll();

    foreach ($columns as $definition) {
        if (($definition['name'] ?? null) === $column) {
            return true;
        }
    }

    return false;
}
