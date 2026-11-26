<?php
declare(strict_types=1);

function mapCodexFavoriteIds(PDO $pdo, int $campaignId, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT entry_id FROM campaign_codex_favorites WHERE user_id = ? AND campaign_id = ? ORDER BY entry_id'
    );
    $statement->execute([$userId, $campaignId]);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

function mapCodexSetFavorite(PDO $pdo, int $campaignId, int $entryId, int $userId, bool $favorite): void
{
    if ($favorite) {
        $statement = $pdo->prepare(
            'INSERT INTO campaign_codex_favorites (campaign_id, entry_id, user_id)
             SELECT campaign_id, id, :user FROM campaign_codex_entries WHERE campaign_id = :campaign AND id = :entry
             ON CONFLICT (campaign_id, entry_id, user_id) DO NOTHING'
        );
    } else {
        $statement = $pdo->prepare(
            'DELETE FROM campaign_codex_favorites WHERE campaign_id = :campaign AND entry_id = :entry AND user_id = :user'
        );
    }
    $statement->execute(['campaign' => $campaignId, 'entry' => $entryId, 'user' => $userId]);
}

function mapCodexRecordActivity(PDO $pdo, int $campaignId, int $entryId, int $revision, string $action, ?int $actorId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO campaign_codex_history (campaign_id, entry_id, revision, action, actor_user_id) VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([$campaignId, $entryId, $revision, $action, $actorId]);
}

function mapCodexHistory(PDO $pdo, int $campaignId, int $entryId): array
{
    $statement = $pdo->prepare(
        'SELECT h.revision, h.action, h.created_at, u.display_name AS actor_name
         FROM campaign_codex_history h
         LEFT JOIN users u ON u.id = h.actor_user_id
         WHERE h.campaign_id = ? AND h.entry_id = ?
         ORDER BY h.revision DESC'
    );
    $statement->execute([$campaignId, $entryId]);
    return $statement->fetchAll();
}
