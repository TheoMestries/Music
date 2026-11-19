<?php
declare(strict_types=1);

return ['version'=>20,'name'=>'board_entity_order','up'=>static function(PDO $pdo):void {
    $pdo->exec('ALTER TABLE board_entities ADD COLUMN stack_order INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('CREATE INDEX idx_board_entities_order ON board_entities(page_id,layer,stack_order,id)');
}];
