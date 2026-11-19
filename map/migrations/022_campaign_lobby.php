<?php
declare(strict_types=1);

return ['version'=>22,'name'=>'campaign_lobby','up'=>static function(PDO $pdo):void {
    $pdo->exec("ALTER TABLE campaigns ADD COLUMN cover_image TEXT NOT NULL DEFAULT ''");
    $pdo->exec('ALTER TABLE campaigns ADD COLUMN default_sheet_template_id INTEGER REFERENCES character_sheet_templates(id) ON DELETE SET NULL');
    $pdo->exec('CREATE TABLE campaign_invites (
        campaign_id INTEGER PRIMARY KEY REFERENCES campaigns(id) ON DELETE CASCADE,
        token TEXT UNIQUE NOT NULL
    )');
}];
