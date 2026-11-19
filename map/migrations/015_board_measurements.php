<?php
declare(strict_types=1);
return ['version'=>15,'name'=>'board_measurements','up'=>static function(PDO $pdo):void {
    $pdo->exec('CREATE TABLE board_measurements (
        page_id INTEGER NOT NULL REFERENCES campaign_board_pages(id) ON DELETE CASCADE,
        author_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        measurement TEXT NOT NULL,
        expires_at INTEGER NOT NULL,
        PRIMARY KEY (page_id, author_id)
    )');
}];
