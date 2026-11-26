<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/database.php';

function assertMigrationTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name"
    );
    $statement->execute([':name' => $table]);

    return (int) $statement->fetchColumn() === 1;
}

function removeTestDatabase(string $databasePath): void
{
    foreach ([$databasePath, $databasePath . '-journal', $databasePath . '-shm', $databasePath . '-wal'] as $file) {
        if (is_file($file) && !unlink($file)) {
            throw new RuntimeException('Impossible de supprimer le fichier de test : ' . $file);
        }
    }
}

$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atlas_jdr_' . bin2hex(random_bytes(8));

if (!mkdir($testDirectory, 0700)) {
    throw new RuntimeException('Impossible de creer le dossier temporaire de test.');
}

$freshDatabasePath = $testDirectory . DIRECTORY_SEPARATOR . 'fresh.sqlite';
$legacyDatabasePath = $testDirectory . DIRECTORY_SEPARATOR . 'legacy.sqlite';
$currentDatabaseCopyPath = $testDirectory . DIRECTORY_SEPARATOR . 'current.sqlite';

try {
    $freshPdo = mapOpenDatabase($freshDatabasePath);
    assertMigrationTest(mapCurrentDatabaseVersion($freshPdo) === 35, 'La base vierge doit atteindre la version 35.');

    foreach (['users', 'campaigns', 'campaign_members', 'campaign_notes', 'campaign_note_tags', 'dice_rolls', 'system_settings', 'schema_migrations', 'campaign_presentations', 'campaign_note_folders', 'character_sheet_templates', 'character_sheets', 'campaign_board_pages', 'campaign_board_state', 'campaign_board_views', 'board_drawings', 'board_measurements', 'campaign_chat', 'board_entities', 'campaign_codex_entries', 'campaign_codex_links', 'campaign_codex_media', 'campaign_codex_favorites', 'campaign_codex_history', 'campaign_codex_module_rules', 'campaign_codex_entry_rules', 'campaign_codex_element_rules', 'campaign_codex_element_create_rules', 'campaign_codex_character_sheets'] as $table) {
        assertMigrationTest(tableExists($freshPdo, $table), 'Table manquante dans la base vierge : ' . $table);
    }

    assertMigrationTest(tableExists($freshPdo, 'campaign_scenarios'), 'Table campaign_scenarios manquante.');
    assertMigrationTest(tableExists($freshPdo, 'campaign_scenario_images'), 'Table campaign_scenario_images manquante.');
    assertMigrationTest(mapTableHasColumn($freshPdo, 'campaigns', 'is_archived'), 'Colonne campaigns.is_archived manquante.');
    assertMigrationTest(mapTableHasColumn($freshPdo, 'campaigns', 'updated_at'), 'Colonne campaigns.updated_at manquante.');
    assertMigrationTest(mapTableHasColumn($freshPdo, 'campaign_members', 'updated_at'), 'Colonne campaign_members.updated_at manquante.');
    assertMigrationTest((int) $freshPdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 35, 'Historique de migrations incomplet.');
    $freshPdo = null;

    $v22 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    mapEnsureMigrationTable($v22);
    $markApplied = $v22->prepare('INSERT INTO schema_migrations(version,name) VALUES (?,?)');
    foreach (mapLoadDatabaseMigrations() as $version => $migration) {
        if ($version > 22) break;
        $migration['up']($v22);
        $markApplied->execute([$version,$migration['name']]);
    }
    $v22->exec("INSERT INTO users (username,display_name,password_hash,role) VALUES
        ('mj-version-22','MJ version 22','test-hash','gm'),('autre-mj','Autre MJ','test-hash','gm')");
    $v22->exec("INSERT INTO campaigns (name,owner_user_id) VALUES ('Campagne version 22',1)");
    mapRunDatabaseMigrations($v22);
    assertMigrationTest((int)$v22->query('SELECT creator_user_id FROM campaigns')->fetchColumn() === 1, 'La version 22 sans créateur doit être mise à niveau.');
    $v22->exec('UPDATE campaigns SET owner_user_id=2');
    $v22->exec('DELETE FROM schema_migrations WHERE version=23');
    mapRunDatabaseMigrations($v22);
    assertMigrationTest((int)$v22->query('SELECT creator_user_id FROM campaigns')->fetchColumn() === 1, 'La version 22 avec créateur doit conserver le créateur initial.');

    $legacyPdo = new PDO('sqlite:' . $legacyDatabasePath);
    $legacyPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $initialMigration = require __DIR__ . '/../migrations/001_initial_schema.php';
    $initialMigration['up']($legacyPdo);
    $legacyPdo->exec(
        "INSERT INTO users (username, display_name, password_hash, role)
         VALUES ('legacy-gm', 'MJ historique', 'test-hash', 'gm')"
    );
    $legacyPdo->exec(
        "INSERT INTO campaigns (name, description, owner_user_id)
         VALUES ('Campagne conservee', 'Donnees anterieures aux migrations', 1)"
    );
    $legacyPdo = null;

    $migratedLegacyPdo = mapOpenDatabase($legacyDatabasePath);
    assertMigrationTest(mapCurrentDatabaseVersion($migratedLegacyPdo) === 35, 'La base historique doit atteindre la version 35.');
    assertMigrationTest((int) $migratedLegacyPdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'L utilisateur historique a ete perdu.');
    assertMigrationTest((int) $migratedLegacyPdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn() === 1, 'La campagne historique a ete perdue.');
    assertMigrationTest((string) $migratedLegacyPdo->query('SELECT name FROM campaigns WHERE id = 1')->fetchColumn() === 'Campagne conservee', 'La campagne historique a ete modifiee.');
    mapRunDatabaseMigrations($migratedLegacyPdo);
    assertMigrationTest((int) $migratedLegacyPdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 35, 'Une migration a ete rejouee.');
    $migratedLegacyPdo = null;

    if (is_file(mapDatabasePath())) {
        assertMigrationTest(copy(mapDatabasePath(), $currentDatabaseCopyPath), 'Impossible de copier la base actuelle pour le test.');
        $currentCopyPdo = mapOpenDatabase($currentDatabaseCopyPath);
        assertMigrationTest(mapCurrentDatabaseVersion($currentCopyPdo) === 35, 'La copie de la base actuelle doit atteindre la version 35.');
        $currentCopyPdo = null;
    }

    echo "OK - migrations SQLite versionnees (base vierge, historique et actuelle).\n";
} finally {
    $freshPdo = $legacyPdo = $migratedLegacyPdo = $currentCopyPdo = null;
    removeTestDatabase($freshDatabasePath);
    removeTestDatabase($legacyDatabasePath);
    removeTestDatabase($currentDatabaseCopyPath);

    if (is_dir($testDirectory) && !rmdir($testDirectory)) {
        throw new RuntimeException('Impossible de supprimer le dossier temporaire de test.');
    }
}
