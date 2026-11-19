<?php
declare(strict_types=1);

return [
    'version' => 5,
    'name' => 'dice_rolls',
    'up' => static function (PDO $pdo): void {
        $statements = [
            "CREATE TABLE IF NOT EXISTS dice_rolls (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                roller_user_id INTEGER NOT NULL,
                expression TEXT NOT NULL CHECK (length(expression) BETWEEN 1 AND 100),
                canonical_expression TEXT NOT NULL CHECK (length(canonical_expression) BETWEEN 1 AND 140),
                roll_mode TEXT NOT NULL CHECK (roll_mode IN ('normal', 'advantage', 'disadvantage')),
                total INTEGER NOT NULL,
                result_json TEXT NOT NULL,
                random_source TEXT NOT NULL CHECK (random_source IN ('anu_quantum', 'system_crypto')),
                random_source_detail TEXT NOT NULL,
                fallback_reason TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f', 'now')),
                FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (roller_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
            )",
            'CREATE INDEX IF NOT EXISTS idx_dice_rolls_campaign_created ON dice_rolls(campaign_id, created_at DESC, id DESC)',
            'CREATE INDEX IF NOT EXISTS idx_dice_rolls_roller ON dice_rolls(roller_user_id, created_at DESC)',
        ];

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    },
];
