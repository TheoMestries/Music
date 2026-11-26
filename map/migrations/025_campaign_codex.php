<?php
declare(strict_types=1);

return ['version' => 25, 'name' => 'campaign_codex', 'up' => static function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE campaign_codex_media (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
            fingerprint TEXT NOT NULL,
            mime TEXT NOT NULL,
            content BLOB NOT NULL,
            UNIQUE (campaign_id, fingerprint),
            UNIQUE (campaign_id, id)
        );
        CREATE TABLE campaign_codex_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
            category TEXT NOT NULL,
            title TEXT NOT NULL,
            summary TEXT NOT NULL DEFAULT '',
            body TEXT NOT NULL DEFAULT '',
            details TEXT NOT NULL DEFAULT '{}',
            source_key TEXT,
            image_id INTEGER,
            revision INTEGER NOT NULL DEFAULT 1,
            UNIQUE (campaign_id, source_key),
            UNIQUE (campaign_id, id),
            FOREIGN KEY (campaign_id, image_id) REFERENCES campaign_codex_media(campaign_id, id)
        );
        CREATE INDEX campaign_codex_category ON campaign_codex_entries(campaign_id, category, title);
        CREATE TABLE campaign_codex_links (
            campaign_id INTEGER NOT NULL,
            entry_id INTEGER NOT NULL,
            target_id INTEGER NOT NULL,
            label TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (campaign_id, entry_id, target_id, label),
            CHECK (entry_id <> target_id),
            FOREIGN KEY (campaign_id, entry_id) REFERENCES campaign_codex_entries(campaign_id, id) ON DELETE CASCADE,
            FOREIGN KEY (campaign_id, target_id) REFERENCES campaign_codex_entries(campaign_id, id) ON DELETE CASCADE
        );
        CREATE INDEX campaign_codex_link_target ON campaign_codex_links(campaign_id, target_id);
    ");
}];
