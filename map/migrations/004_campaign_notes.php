<?php
declare(strict_types=1);

return [
    'version' => 4,
    'name' => 'campaign_notes',
    'up' => static function (PDO $pdo): void {
        $statements = [
            "CREATE TABLE IF NOT EXISTS campaign_notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                author_user_id INTEGER NOT NULL,
                title TEXT NOT NULL CHECK (length(title) BETWEEN 1 AND 160),
                content TEXT NOT NULL DEFAULT '' CHECK (length(content) <= 200000),
                folder TEXT DEFAULT NULL CHECK (folder IS NULL OR length(folder) <= 80),
                visibility TEXT NOT NULL CHECK (visibility IN ('personal', 'gm', 'shared')),
                revision INTEGER NOT NULL DEFAULT 1 CHECK (revision >= 1),
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS campaign_note_tags (
                note_id INTEGER NOT NULL,
                tag TEXT NOT NULL COLLATE NOCASE CHECK (length(tag) BETWEEN 1 AND 30),
                PRIMARY KEY (note_id, tag),
                FOREIGN KEY (note_id) REFERENCES campaign_notes(id) ON DELETE CASCADE ON UPDATE CASCADE
            )",
            'CREATE INDEX IF NOT EXISTS idx_campaign_notes_campaign_visibility ON campaign_notes(campaign_id, visibility)',
            'CREATE INDEX IF NOT EXISTS idx_campaign_notes_author ON campaign_notes(author_user_id)',
            'CREATE INDEX IF NOT EXISTS idx_campaign_notes_folder ON campaign_notes(campaign_id, folder COLLATE NOCASE)',
            'CREATE INDEX IF NOT EXISTS idx_campaign_notes_updated ON campaign_notes(campaign_id, updated_at DESC)',
            'CREATE INDEX IF NOT EXISTS idx_campaign_note_tags_tag ON campaign_note_tags(tag COLLATE NOCASE)',
        ];

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    },
];
