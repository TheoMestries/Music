<?php
declare(strict_types=1);

return [
    'version' => 6,
    'name' => 'system_settings',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS system_settings (
                setting_key TEXT PRIMARY KEY CHECK (length(setting_key) BETWEEN 1 AND 80),
                setting_value TEXT NOT NULL CHECK (length(setting_value) BETWEEN 1 AND 4096),
                updated_by_user_id INTEGER DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
            )"
        );
    },
];
