<?php
declare(strict_types=1);

return [
    'version' => 2,
    'name' => 'campaign_archiving_and_timestamps',
    'up' => static function (PDO $pdo): void {
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
    },
];
