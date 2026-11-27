<?php
declare(strict_types=1);

return ['version' => 36, 'name' => 'sheet_template_visibility', 'up' => static function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE character_sheet_templates ADD COLUMN visibility TEXT NOT NULL DEFAULT 'private'
        CHECK (visibility IN ('private', 'campaign', 'public'))");
    $pdo->exec("UPDATE character_sheet_templates SET visibility = 'campaign'");
}];
