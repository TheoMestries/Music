<?php
declare(strict_types=1);
return ['version'=>12,'name'=>'board_pages','up'=>static function(PDO $pdo):void {
    $pdo->exec("CREATE TABLE campaign_board_pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
        name TEXT NOT NULL CHECK(length(name) BETWEEN 1 AND 160),
        image TEXT NOT NULL DEFAULT '',
        thumbnail TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE INDEX idx_board_pages_campaign ON campaign_board_pages(campaign_id,id)');
    $pdo->exec('CREATE TABLE campaign_board_state (
        campaign_id INTEGER PRIMARY KEY REFERENCES campaigns(id) ON DELETE CASCADE,
        player_page_id INTEGER REFERENCES campaign_board_pages(id) ON DELETE SET NULL,
        revision INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec('CREATE TABLE campaign_board_views (
        campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        page_id INTEGER NOT NULL REFERENCES campaign_board_pages(id) ON DELETE CASCADE,
        PRIMARY KEY(campaign_id,user_id)
    )');
}];
