<?php
declare(strict_types=1);
return ['version'=>11, 'name'=>'character_sheets', 'up'=>static function(PDO $pdo):void {
    $pdo->exec('CREATE TABLE character_sheet_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
        author_user_id INTEGER NOT NULL REFERENCES users(id),
        name TEXT NOT NULL,
        pages TEXT NOT NULL,
        fields TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE character_sheets (
        note_id INTEGER PRIMARY KEY REFERENCES campaign_notes(id) ON DELETE CASCADE,
        template_id INTEGER NOT NULL REFERENCES character_sheet_templates(id),
        field_values TEXT NOT NULL DEFAULT \'{}\',
        revision INTEGER NOT NULL DEFAULT 1,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE INDEX idx_sheet_templates_campaign ON character_sheet_templates(campaign_id)');
}];
