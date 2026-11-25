<?php
declare(strict_types=1);

return ['version' => 24, 'name' => 'deleted_sheet_templates', 'up' => static function (PDO $pdo): void {
    $pdo->exec('ALTER TABLE character_sheet_templates ADD COLUMN deleted_at TEXT');
}];
