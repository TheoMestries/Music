<?php
declare(strict_types=1);

function mapReadPresentation(PDO $pdo, int $campaignId, ?string $after): ?array
{
    $sql = 'SELECT event_id,title,content,image,rich FROM campaign_presentations WHERE campaign_id = ?';
    $query = $pdo->prepare($after === null ? $sql : $sql . ' AND event_id <> ?');
    $query->execute($after === null ? [$campaignId] : [$campaignId, $after]);
    return $query->fetch() ?: null;
}
