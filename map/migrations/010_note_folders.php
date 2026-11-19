<?php
declare(strict_types=1);
return ['version' => 10, 'name' => 'note_folders', 'up' => static function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE campaign_note_folders (
        campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name TEXT NOT NULL CHECK(length(name) BETWEEN 1 AND 80),
        PRIMARY KEY(campaign_id, user_id, name)
    )');
}];
