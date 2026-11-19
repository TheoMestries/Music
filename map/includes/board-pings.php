<?php
declare(strict_types=1);

const MAP_BOARD_PING_DURATION = 3500;

function mapBoardParticipantColor(PDO $pdo, int $campaignId, int $userId): string
{
    $query = $pdo->prepare('SELECT slot FROM board_participant_colors WHERE campaign_id=? AND user_id=?');
    $query->execute([$campaignId,$userId]);
    $slot = $query->fetchColumn();
    $query->closeCursor();
    if ($slot === false) {
        $pdo->prepare('INSERT OR IGNORE INTO board_participant_colors(campaign_id,user_id,slot)
            SELECT ?,?,COALESCE(MAX(slot)+1,0) FROM board_participant_colors WHERE campaign_id=?')
            ->execute([$campaignId,$userId,$campaignId]);
        $query->execute([$campaignId,$userId]);
        $slot = $query->fetchColumn();
        $query->closeCursor();
    }

    // Space adjacent assignments around the color wheel.
    $hue = fmod(39 + (int)$slot * 137.508,360) / 60;
    $chroma = .68;
    $middle = $chroma * (1 - abs(fmod($hue,2) - 1));
    [$r,$g,$b] = match ((int)$hue) {
        0 => [$chroma,$middle,0],
        1 => [$middle,$chroma,0],
        2 => [0,$chroma,$middle],
        3 => [0,$middle,$chroma],
        4 => [$middle,0,$chroma],
        default => [$chroma,0,$middle],
    };
    return sprintf('#%02x%02x%02x',round(($r+.19)*255),round(($g+.19)*255),round(($b+.19)*255));
}

function mapBoardPings(PDO $pdo, int $pageId): array
{
    $now = (int) (microtime(true)*1000);
    $query = $pdo->prepare('SELECT id,color,x,y,created_ms FROM board_pings WHERE page_id=? AND created_ms>? ORDER BY id');
    $query->execute([$pageId,$now-MAP_BOARD_PING_DURATION]);
    return array_map(static fn(array $row): array => [
        'id'=>(int)$row['id'], 'color'=>$row['color'], 'x'=>(float)$row['x'], 'y'=>(float)$row['y'],
        'age'=>$now-(int)$row['created_ms'],
    ],$query->fetchAll());
}

function mapPublishBoardPing(int $campaignId, array $payload, array $user): array
{
    $access = mapRequireCampaignAccess($campaignId,$user);
    $pageId = mapValidatePositiveInt($payload['pageId'] ?? null,'Page');
    $point = $payload['point'] ?? null;
    if (!is_array($point) || !array_is_list($point) || count($point) !== 2) {
        throw new DomainException('Position du signal invalide.');
    }
    foreach ($point as $coordinate) {
        if ((!is_int($coordinate) && !is_float($coordinate)) || !is_finite($coordinate) || abs($coordinate)>1000000) {
            throw new DomainException('Position du signal invalide.');
        }
    }
    $pdo = mapGetDatabase();
    $color = mapBoardParticipantColor($pdo,$campaignId,(int)$user['user_id']);
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE campaign_board_state SET revision=revision+1 WHERE campaign_id=?')->execute([$campaignId]);
        mapRequireActiveBoardPage($pdo,$campaignId,$pageId,$user,mapCanUseGmNotes($access));
        $now = (int) (microtime(true)*1000);
        $pdo->prepare('DELETE FROM board_pings WHERE created_ms<=?')->execute([$now-MAP_BOARD_PING_DURATION]);
        $pdo->prepare('INSERT INTO board_pings(page_id,color,x,y,created_ms) VALUES (?,?,?,?,?)')
            ->execute([$pageId,$color,$point[0],$point[1],$now]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return mapBoardSnapshot($campaignId,$user);
}
