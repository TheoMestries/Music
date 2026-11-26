<?php
declare(strict_types=1);

return ['version' => 31, 'name' => 'codex_element_keys', 'up' => static function (PDO $pdo): void {
    require_once __DIR__ . '/../includes/codex-elements.php';

    // Relation identity must be independent of its content. Otherwise trying to add a relation
    // could reveal a private sibling with the same label and target through a uniqueness error.
    $pdo->exec("
        CREATE TABLE campaign_codex_identified_links (
            campaign_id INTEGER NOT NULL,
            entry_id INTEGER NOT NULL,
            target_id INTEGER NOT NULL,
            label TEXT NOT NULL DEFAULT '',
            kind TEXT NOT NULL DEFAULT 'relation' CHECK (kind IN ('relation', 'mention')),
            players_visible INTEGER NOT NULL DEFAULT 1 CHECK (players_visible IN (0, 1)),
            element_key TEXT CHECK (element_key IS NULL OR (kind = 'relation' AND length(element_key) = 24
                AND element_key NOT GLOB '*[^0-9a-f]*')),
            CHECK (entry_id <> target_id),
            FOREIGN KEY (campaign_id, entry_id) REFERENCES campaign_codex_entries(campaign_id, id) ON DELETE CASCADE,
            FOREIGN KEY (campaign_id, target_id) REFERENCES campaign_codex_entries(campaign_id, id) ON DELETE CASCADE
        );
        INSERT INTO campaign_codex_identified_links (campaign_id, entry_id, target_id, label, kind, players_visible, element_key)
        SELECT campaign_id, entry_id, target_id, label, kind, players_visible, element_key FROM campaign_codex_links;
        DROP TRIGGER campaign_codex_relation_remove_rules;
        DROP TABLE campaign_codex_links;
        ALTER TABLE campaign_codex_identified_links RENAME TO campaign_codex_links;
        CREATE INDEX campaign_codex_link_source ON campaign_codex_links(campaign_id, entry_id);
        CREATE INDEX campaign_codex_link_target ON campaign_codex_links(campaign_id, target_id);
        CREATE UNIQUE INDEX campaign_codex_unique_mention
            ON campaign_codex_links(campaign_id, entry_id, target_id, label, players_visible) WHERE kind = 'mention';
        CREATE UNIQUE INDEX campaign_codex_relation_element_key
            ON campaign_codex_links(campaign_id, entry_id, element_key) WHERE element_key IS NOT NULL;
        CREATE TRIGGER campaign_codex_relation_assign_key AFTER INSERT ON campaign_codex_links
        WHEN NEW.kind = 'relation' AND NEW.element_key IS NULL
        BEGIN
            UPDATE campaign_codex_links SET element_key = lower(hex(randomblob(12))) WHERE rowid = NEW.rowid;
        END;
        CREATE TRIGGER campaign_codex_relation_remove_rules AFTER DELETE ON campaign_codex_links
        WHEN OLD.element_key IS NOT NULL
        BEGIN
            DELETE FROM campaign_codex_element_rules WHERE campaign_id = OLD.campaign_id AND entry_id = OLD.entry_id
                AND section = 'relations' AND element_key = OLD.element_key;
        END;
    ");

    $read = $pdo->prepare('SELECT id, category, details FROM campaign_codex_entries WHERE id > ? ORDER BY id LIMIT 100');
    $write = $pdo->prepare('UPDATE campaign_codex_entries SET details = ? WHERE id = ?');
    $lastId = 0;
    while (true) {
        $read->execute([$lastId]);
        $entries = $read->fetchAll();
        $read->closeCursor();
        if ($entries === []) {
            break;
        }
        foreach ($entries as $entry) {
            $lastId = (int) $entry['id'];
            $details = json_decode($entry['details'], false, 32, JSON_THROW_ON_ERROR);
            if ($details instanceof stdClass && mapCodexAssignElementKeys($details, $entry['category'])) {
                $write->execute([json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $lastId]);
            }
        }
    }
}];
