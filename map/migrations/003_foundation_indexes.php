<?php
declare(strict_types=1);

return [
    'version' => 3,
    'name' => 'foundation_indexes',
    'up' => static function (PDO $pdo): void {
        $statements = [
            'CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)',
            'CREATE INDEX IF NOT EXISTS idx_users_is_active ON users(is_active)',
            'CREATE INDEX IF NOT EXISTS idx_users_role_active ON users(role, is_active)',
            'CREATE INDEX IF NOT EXISTS idx_campaigns_owner_user_id ON campaigns(owner_user_id)',
            'CREATE INDEX IF NOT EXISTS idx_campaigns_is_archived ON campaigns(is_archived)',
            'CREATE INDEX IF NOT EXISTS idx_campaign_members_campaign_id ON campaign_members(campaign_id)',
            'CREATE INDEX IF NOT EXISTS idx_campaign_members_user_id ON campaign_members(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_campaign_members_member_role ON campaign_members(member_role)',
        ];

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    },
];
