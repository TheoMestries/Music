<?php
declare(strict_types=1);

return [
    'version' => 33,
    'name' => 'codex_character_sheets',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("CREATE UNIQUE INDEX idx_sheet_templates_campaign_id
                ON character_sheet_templates(campaign_id, id);
            CREATE TABLE campaign_codex_character_sheets (
                entry_id INTEGER PRIMARY KEY,
                campaign_id INTEGER NOT NULL,
                template_id INTEGER NOT NULL,
                field_values TEXT NOT NULL DEFAULT '{}',
                revision INTEGER NOT NULL DEFAULT 1,
                FOREIGN KEY (campaign_id, entry_id) REFERENCES campaign_codex_entries(campaign_id, id) ON DELETE CASCADE,
                FOREIGN KEY (campaign_id, template_id) REFERENCES character_sheet_templates(campaign_id, id)
            );
            CREATE INDEX idx_codex_character_sheets_template ON campaign_codex_character_sheets(template_id);");
    },
];
