<?php
declare(strict_types=1);
return ['version'=>8,'name'=>'document_options','up'=>static function(PDO $pdo):void {
 $pdo->exec("ALTER TABLE campaign_notes ADD COLUMN options TEXT NOT NULL DEFAULT '{}'");
}];
