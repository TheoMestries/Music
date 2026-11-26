<?php
declare(strict_types=1);

return ['version' => 30, 'name' => 'codex_element_access', 'up' => static function (PDO $pdo): void {
    $pdo->exec("
        ALTER TABLE campaign_codex_links ADD COLUMN element_key TEXT
            CHECK (element_key IS NULL OR (kind = 'relation' AND length(element_key) = 24
                AND element_key NOT GLOB '*[^0-9a-f]*'));
        UPDATE campaign_codex_links SET element_key = lower(hex(randomblob(12))) WHERE kind = 'relation';
        CREATE UNIQUE INDEX campaign_codex_relation_element_key
            ON campaign_codex_links(campaign_id, entry_id, element_key) WHERE element_key IS NOT NULL;
        CREATE TABLE campaign_codex_element_rules (
            campaign_id INTEGER NOT NULL,
            entry_id INTEGER NOT NULL,
            section TEXT NOT NULL,
            element_key TEXT NOT NULL CHECK (length(element_key) = 24 AND element_key NOT GLOB '*[^0-9a-f]*'),
            user_id INTEGER,
            read TEXT NOT NULL CHECK (read IN ('inherit', 'allow', 'deny')),
            edit TEXT NOT NULL CHECK (edit IN ('inherit', 'allow', 'deny')),
            FOREIGN KEY (campaign_id, entry_id) REFERENCES campaign_codex_entries(campaign_id, id) ON DELETE CASCADE,
            FOREIGN KEY (campaign_id, user_id) REFERENCES campaign_members(campaign_id, user_id) ON DELETE CASCADE
        );
        CREATE UNIQUE INDEX campaign_codex_element_audience
            ON campaign_codex_element_rules(campaign_id, entry_id, section, element_key, coalesce(user_id, 0));
        CREATE INDEX campaign_codex_element_member ON campaign_codex_element_rules(campaign_id, user_id);
        CREATE TABLE campaign_codex_element_create_rules (
            campaign_id INTEGER NOT NULL,
            entry_id INTEGER NOT NULL,
            section TEXT NOT NULL,
            user_id INTEGER,
            create_rule TEXT NOT NULL CHECK (create_rule IN ('allow', 'deny')),
            FOREIGN KEY (campaign_id, entry_id) REFERENCES campaign_codex_entries(campaign_id, id) ON DELETE CASCADE,
            FOREIGN KEY (campaign_id, user_id) REFERENCES campaign_members(campaign_id, user_id) ON DELETE CASCADE
        );
        CREATE UNIQUE INDEX campaign_codex_element_create_audience
            ON campaign_codex_element_create_rules(campaign_id, entry_id, section, coalesce(user_id, 0));
        CREATE INDEX campaign_codex_element_create_member ON campaign_codex_element_create_rules(campaign_id, user_id);
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
}];
