<?php
declare(strict_types=1);

return ['version' => 28, 'name' => 'codex_permissions', 'up' => static function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE campaign_codex_module_rules (
            campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
            category TEXT NOT NULL,
            user_id INTEGER,
            read TEXT NOT NULL CHECK (read IN ('inherit', 'allow', 'deny')),
            create_rule TEXT NOT NULL CHECK (create_rule IN ('inherit', 'allow', 'deny')),
            edit TEXT NOT NULL CHECK (edit IN ('inherit', 'allow', 'deny')),
            FOREIGN KEY (campaign_id, user_id) REFERENCES campaign_members(campaign_id, user_id) ON DELETE CASCADE
        );
        CREATE UNIQUE INDEX campaign_codex_module_audience
            ON campaign_codex_module_rules(campaign_id, category, coalesce(user_id, 0));
        CREATE INDEX campaign_codex_module_member
            ON campaign_codex_module_rules(campaign_id, user_id);
        CREATE TABLE campaign_codex_entry_rules (
            campaign_id INTEGER NOT NULL,
            entry_id INTEGER NOT NULL,
            user_id INTEGER,
            read TEXT NOT NULL CHECK (read IN ('inherit', 'allow', 'deny')),
            edit TEXT NOT NULL CHECK (edit IN ('inherit', 'allow', 'deny')),
            FOREIGN KEY (campaign_id, entry_id) REFERENCES campaign_codex_entries(campaign_id, id) ON DELETE CASCADE,
            FOREIGN KEY (campaign_id, user_id) REFERENCES campaign_members(campaign_id, user_id) ON DELETE CASCADE
        );
        CREATE UNIQUE INDEX campaign_codex_entry_audience
            ON campaign_codex_entry_rules(campaign_id, entry_id, coalesce(user_id, 0));
        CREATE INDEX campaign_codex_entry_member
            ON campaign_codex_entry_rules(campaign_id, user_id);
    ");
}];
