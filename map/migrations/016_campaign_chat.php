<?php
declare(strict_types=1);
return ['version'=>16,'name'=>'campaign_chat','up'=>static function(PDO $pdo):void {
    $pdo->exec("ALTER TABLE dice_rolls ADD COLUMN visibility TEXT NOT NULL DEFAULT 'public' CHECK(visibility IN ('public','gm','secret'))");
    $pdo->exec("CREATE TABLE campaign_chat (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
        author_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        body TEXT NOT NULL CHECK(length(body) BETWEEN 1 AND 2000),
        created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now'))
    )");
    $pdo->exec('CREATE INDEX idx_campaign_chat_feed ON campaign_chat(campaign_id,id DESC)');
}];
