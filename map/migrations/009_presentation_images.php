<?php
declare(strict_types=1);
return ['version'=>9,'name'=>'presentation_images','up'=>static function(PDO $pdo):void {
 $pdo->exec("ALTER TABLE campaign_presentations ADD COLUMN image TEXT NOT NULL DEFAULT ''");
 $pdo->exec("ALTER TABLE campaign_presentations ADD COLUMN rich INTEGER NOT NULL DEFAULT 0");
}];
