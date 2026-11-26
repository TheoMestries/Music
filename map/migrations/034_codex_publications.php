<?php
declare(strict_types=1);

return ['version' => 34, 'name' => 'codex_publications', 'up' => static function (PDO $pdo): void {
    $pdo->exec('ALTER TABLE campaign_notes ADD COLUMN codex_entry_id INTEGER REFERENCES campaign_codex_entries(id) ON DELETE CASCADE');
    $pdo->exec('ALTER TABLE campaign_notes ADD COLUMN codex_published INTEGER NOT NULL DEFAULT 0 CHECK (codex_published IN (0, 1))');
    $pdo->exec('CREATE UNIQUE INDEX idx_campaign_notes_codex_entry ON campaign_notes(codex_entry_id)');
}];
