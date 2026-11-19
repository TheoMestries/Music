<?php
declare(strict_types=1);
return [
 'version' => 7,
 'name' => 'document_presentations',
 'up' => static function(PDO $pdo): void {
   $pdo->exec("CREATE TABLE campaign_presentations (
    campaign_id INTEGER PRIMARY KEY REFERENCES campaigns(id) ON DELETE CASCADE,
    event_id TEXT NOT NULL,
    title TEXT NOT NULL,
    content TEXT NOT NULL
   )");
 }
];
