<?php
declare(strict_types=1);

return ['version' => 26, 'name' => 'codex_activity', 'up' => static function (PDO $pdo): void {
    $pdo->exec("
        ALTER TABLE campaign_codex_entries ADD COLUMN body_html TEXT;

        CREATE TABLE campaign_codex_history (
            campaign_id INTEGER NOT NULL,
            entry_id INTEGER NOT NULL,
            revision INTEGER NOT NULL,
            action TEXT NOT NULL CHECK (action IN ('created', 'updated', 'imported')),
            actor_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (campaign_id, entry_id, revision),
            FOREIGN KEY (campaign_id, entry_id) REFERENCES campaign_codex_entries(campaign_id, id) ON DELETE CASCADE
        );

        CREATE TABLE campaign_codex_favorites (
            campaign_id INTEGER NOT NULL,
            entry_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            PRIMARY KEY (campaign_id, entry_id, user_id),
            FOREIGN KEY (campaign_id, entry_id) REFERENCES campaign_codex_entries(campaign_id, id) ON DELETE CASCADE
        );
        CREATE INDEX campaign_codex_favorites_user ON campaign_codex_favorites(user_id, campaign_id);
    ");
}];
