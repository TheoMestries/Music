<?php
declare(strict_types=1);

return ['version'=>21,'name'=>'board_turns','up'=>static function(PDO $pdo):void {
    $pdo->exec("CREATE TABLE board_turn_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_id INTEGER NOT NULL REFERENCES campaign_board_pages(id) ON DELETE CASCADE,
        entity_id INTEGER REFERENCES board_entities(id) ON DELETE CASCADE,
        label TEXT NOT NULL DEFAULT '',
        initiative REAL NOT NULL DEFAULT 0,
        increment REAL NOT NULL DEFAULT 0,
        position INTEGER NOT NULL,
        UNIQUE(page_id,entity_id)
    )");
    $pdo->exec('CREATE INDEX idx_board_turn_entries_order ON board_turn_entries(page_id,position,id)');
    $pdo->exec("CREATE TABLE board_turn_trackers (
        page_id INTEGER PRIMARY KEY REFERENCES campaign_board_pages(id) ON DELETE CASCADE,
        active_entry_id INTEGER REFERENCES board_turn_entries(id) ON DELETE SET NULL,
        sort_mode TEXT NOT NULL DEFAULT 'high',
        auto_sort INTEGER NOT NULL DEFAULT 0
    )");
}];
