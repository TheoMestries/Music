<?php
declare(strict_types=1);
return ['version'=>18,'name'=>'board_token_settings','up'=>static function(PDO $pdo):void {
    $pdo->exec("ALTER TABLE board_entities ADD COLUMN token_settings TEXT NOT NULL DEFAULT '{}'");
    $pdo->exec("ALTER TABLE campaign_notes ADD COLUMN token_defaults TEXT NOT NULL DEFAULT '{}'");
}];
