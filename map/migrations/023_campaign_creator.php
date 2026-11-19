<?php
declare(strict_types=1);

return ['version'=>23,'name'=>'campaign_creator','up'=>static function(PDO $pdo):void {
    if (!mapTableHasColumn($pdo,'campaigns','creator_user_id')) {
        $pdo->exec('ALTER TABLE campaigns ADD COLUMN creator_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL');
    }
    $pdo->exec('UPDATE campaigns SET creator_user_id=owner_user_id WHERE creator_user_id IS NULL');
}];
