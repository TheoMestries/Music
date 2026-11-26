<?php
declare(strict_types=1);

return ['version' => 35, 'name' => 'campaign_scenarios', 'up' => static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE campaign_scenarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
        title TEXT NOT NULL,
        blocks TEXT NOT NULL DEFAULT '[]',
        revision INTEGER NOT NULL DEFAULT 1
    )");
    $pdo->exec('CREATE INDEX idx_campaign_scenarios_campaign ON campaign_scenarios(campaign_id, id)');
    $pdo->exec('CREATE TABLE campaign_scenario_images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        scenario_id INTEGER NOT NULL REFERENCES campaign_scenarios(id) ON DELETE CASCADE,
        mime TEXT NOT NULL,
        content BLOB NOT NULL
    )');
    $pdo->exec('CREATE INDEX idx_campaign_scenario_images_scenario ON campaign_scenario_images(scenario_id, id)');
}];
