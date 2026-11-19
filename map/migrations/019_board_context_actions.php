<?php
declare(strict_types=1);

return ['version'=>19,'name'=>'board_context_actions','up'=>static function(PDO $pdo):void {
    $pdo->exec('CREATE TABLE board_participant_colors (
        campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        slot INTEGER NOT NULL,
        PRIMARY KEY(campaign_id,user_id),
        UNIQUE(campaign_id,slot)
    )');
    $pdo->exec('CREATE TABLE board_pings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_id INTEGER NOT NULL REFERENCES campaign_board_pages(id) ON DELETE CASCADE,
        color TEXT NOT NULL,
        x REAL NOT NULL,
        y REAL NOT NULL,
        created_ms INTEGER NOT NULL
    )');
    $pdo->exec('CREATE INDEX idx_board_pings_created ON board_pings(created_ms)');
    $pdo->exec("CREATE TABLE board_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_id INTEGER NOT NULL REFERENCES campaign_board_pages(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        layer TEXT NOT NULL CHECK(layer IN ('players','map','gm')),
        changes TEXT NOT NULL,
        undone INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec('CREATE INDEX idx_board_history_user ON board_history(page_id,user_id,undone,id)');
}];
