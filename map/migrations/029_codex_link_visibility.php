<?php
declare(strict_types=1);

return ['version' => 29, 'name' => 'codex_link_visibility', 'up' => static function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE campaign_codex_visible_links (
            campaign_id INTEGER NOT NULL,
            entry_id INTEGER NOT NULL,
            target_id INTEGER NOT NULL,
            label TEXT NOT NULL DEFAULT '',
            kind TEXT NOT NULL DEFAULT 'relation' CHECK (kind IN ('relation', 'mention')),
            players_visible INTEGER NOT NULL DEFAULT 1 CHECK (players_visible IN (0, 1)),
            PRIMARY KEY (campaign_id, entry_id, target_id, kind, label, players_visible),
            CHECK (entry_id <> target_id),
            FOREIGN KEY (campaign_id, entry_id) REFERENCES campaign_codex_entries(campaign_id, id) ON DELETE CASCADE,
            FOREIGN KEY (campaign_id, target_id) REFERENCES campaign_codex_entries(campaign_id, id) ON DELETE CASCADE
        );
        INSERT INTO campaign_codex_visible_links (campaign_id, entry_id, target_id, label, kind, players_visible)
        SELECT link.campaign_id, link.entry_id, link.target_id, link.label, link.kind,
            CASE WHEN entry.source_key IS NULL THEN 1 ELSE 0 END
        FROM campaign_codex_links link
        JOIN campaign_codex_entries entry ON entry.campaign_id = link.campaign_id AND entry.id = link.entry_id;
        DROP TABLE campaign_codex_links;
        ALTER TABLE campaign_codex_visible_links RENAME TO campaign_codex_links;
        CREATE INDEX campaign_codex_link_target ON campaign_codex_links(campaign_id, target_id);
        CREATE INDEX campaign_codex_entry_image ON campaign_codex_entries(campaign_id, image_id);
    ");
}];
