<?php
declare(strict_types=1);

/** Wait without holding a session or SQLite read transaction open. */
function mapWaitForPresentation(PDO $pdo, int $campaignId, ?string $after, float $timeout = 20.0, ?int $afterBoard = null): ?array
{
    $deadline = microtime(true) + $timeout;
    $check = $pdo->prepare('SELECT event_id FROM campaign_presentations WHERE campaign_id = ?');
    $boardCheck = $afterBoard === null ? null : $pdo->prepare('SELECT revision FROM campaign_board_state WHERE campaign_id = ?');
    do {
        $check->execute([$campaignId]);
        $event = $check->fetchColumn();
        $check->closeCursor();
        $boardChanged = false;
        if ($boardCheck !== null) {
            $boardCheck->execute([$campaignId]);
            $boardChanged = (int) $boardCheck->fetchColumn() !== $afterBoard;
            $boardCheck->closeCursor();
        }
        if ($after === null || $boardChanged || ($event !== false && $event !== $after)) {
            $read = $pdo->prepare('SELECT event_id,title,content,image,rich FROM campaign_presentations WHERE campaign_id = ?');
            $read->execute([$campaignId]);
            $document = $read->fetch();
            $read->closeCursor();
            return $document ?: null;
        }
        if (connection_aborted() || microtime(true) >= $deadline) return null;
        usleep(100000);
    } while (true);
}
