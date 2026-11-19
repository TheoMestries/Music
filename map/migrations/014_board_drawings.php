<?php
declare(strict_types=1);

return ['version'=>14,'name'=>'board_drawings','up'=>static function(PDO $pdo):void {
    $pdo->exec("CREATE TABLE board_drawings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_id INTEGER NOT NULL REFERENCES campaign_board_pages(id) ON DELETE CASCADE,
        author_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        layer TEXT NOT NULL CHECK(layer IN ('players','map','gm')),
        drawing TEXT NOT NULL
    )");
    $pdo->exec('CREATE INDEX idx_board_drawings_page ON board_drawings(page_id,layer,id)');
}];
