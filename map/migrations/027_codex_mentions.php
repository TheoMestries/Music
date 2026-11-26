<?php
declare(strict_types=1);

return ['version' => 27, 'name' => 'codex_mentions', 'up' => static function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE campaign_codex_typed_links (
            campaign_id INTEGER NOT NULL,
            entry_id INTEGER NOT NULL,
            target_id INTEGER NOT NULL,
            label TEXT NOT NULL DEFAULT '',
            kind TEXT NOT NULL DEFAULT 'relation' CHECK (kind IN ('relation', 'mention')),
            PRIMARY KEY (campaign_id, entry_id, target_id, kind, label),
            CHECK (entry_id <> target_id),
            FOREIGN KEY (campaign_id, entry_id) REFERENCES campaign_codex_entries(campaign_id, id) ON DELETE CASCADE,
            FOREIGN KEY (campaign_id, target_id) REFERENCES campaign_codex_entries(campaign_id, id) ON DELETE CASCADE
        );
        INSERT INTO campaign_codex_typed_links (campaign_id, entry_id, target_id, label, kind)
        SELECT campaign_id, entry_id, target_id, label,
            CASE WHEN label = 'Mention' THEN 'mention' ELSE 'relation' END
        FROM campaign_codex_links;
        DROP TABLE campaign_codex_links;
        ALTER TABLE campaign_codex_typed_links RENAME TO campaign_codex_links;
        CREATE INDEX campaign_codex_link_target ON campaign_codex_links(campaign_id, target_id);
    ");
}];
