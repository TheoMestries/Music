<?php
declare(strict_types=1);
return ['version'=>17,'name'=>'board_entities','up'=>static function(PDO $pdo):void {
    $pdo->exec("CREATE TABLE board_entities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_id INTEGER NOT NULL REFERENCES campaign_board_pages(id) ON DELETE CASCADE,
        note_id INTEGER NOT NULL REFERENCES campaign_notes(id) ON DELETE CASCADE,
        author_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        layer TEXT NOT NULL CHECK(layer IN ('players','map','gm')),
        x REAL NOT NULL, y REAL NOT NULL, cells REAL NOT NULL DEFAULT 1
    )");
    $pdo->exec('CREATE INDEX idx_board_entities_page ON board_entities(page_id,layer,id)');
}];
