<?php
declare(strict_types=1);

function mapBoardMeasurements(PDO $pdo, int $pageId, int $viewerId): array
{
    $stmt = $pdo->prepare('SELECT measurement,expires_at FROM board_measurements WHERE page_id=? AND author_id<>? AND expires_at>?');
    $now = (int) (microtime(true) * 1000);
    $stmt->execute([$pageId, $viewerId, $now]);
    return array_map(static fn(array $row): array => [
        ...json_decode($row['measurement'], true, 512, JSON_THROW_ON_ERROR),
        'remainingMs'=>(int) $row['expires_at'] - $now,
    ], $stmt->fetchAll());
}

function mapPublishBoardMeasurement(int $campaignId, array $payload, array $user): array
{
    $access = mapRequireCampaignAccess($campaignId, $user);
    $pageId = mapValidatePositiveInt($payload['pageId'] ?? null, 'Page');
    $data = $payload['measurement'] ?? null;
    if (!is_array($data) || !in_array($data['shape'] ?? null, ['line','square','circle','cone','beam'], true) ||
        !in_array($data['origin'] ?? null, ['center','edge','corner'], true) ||
        !in_array($data['coneEnd'] ?? null, ['flat','round'], true) ||
        !is_bool($data['lock'] ?? null) || !is_bool($data['linger'] ?? null)) {
        throw new DomainException('Mesure invalide.');
    }
    $angle = $data['angle'] ?? null;
    $beamWidth = $data['beamWidth'] ?? null;
    if (!is_int($angle) || $angle < 1 || $angle > 179 ||
        (!is_int($beamWidth) && !is_float($beamWidth)) || !is_finite($beamWidth) || $beamWidth <= 0 || $beamWidth > 1e12) {
        throw new DomainException('Dimensions de mesure invalides.');
    }
    $pdo = mapGetDatabase();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE campaign_board_state SET revision=revision+1 WHERE campaign_id=?');
        $stmt->execute([$campaignId]);
        $page = mapBoardPage($campaignId, $pageId);
        $stmt = $pdo->prepare('SELECT player_page_id FROM campaign_board_state WHERE campaign_id=?');
        $stmt->execute([$campaignId]);
        $published = (int) $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($pageId !== $published || ($payload['layer'] ?? '') === 'gm' ||
            !in_array($payload['layer'] ?? '', mapCanUseGmNotes($access) ? ['players','map'] : ['players'], true)) {
            mapThrowForbidden('Le partage est réservé aux calques publics de la page des joueurs.');
        }
        $measurement = array_intersect_key($data, array_flip(['shape','origin','coneEnd','lock','angle','beamWidth']));
        foreach (['start','end'] as $key) {
            $point = $data[$key] ?? null;
            if (!is_array($point) || !array_is_list($point) || count($point) !== 2) throw new DomainException('Position invalide.');
            foreach (['width','height'] as $axis => $dimension) {
                $value = $point[$axis];
                if ((!is_int($value) && !is_float($value)) || !is_finite($value) ||
                    $value < -20000 || $value > $page['settings'][$dimension] + 20000) {
                    throw new DomainException('Position hors du plateau.');
                }
            }
            $measurement[$key] = $point;
        }
        $now = (int) (microtime(true) * 1000);
        $pdo->prepare('DELETE FROM board_measurements WHERE expires_at<=?')->execute([$now]);
        $stmt = $pdo->prepare('INSERT INTO board_measurements VALUES (?,?,?,?) ON CONFLICT(page_id,author_id) DO UPDATE SET measurement=excluded.measurement,expires_at=excluded.expires_at');
        $stmt->execute([$pageId,$user['user_id'],json_encode($measurement, JSON_THROW_ON_ERROR),$now + ($data['linger'] ? 8000 : 1200)]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return mapBoardSnapshot($campaignId, $user);
}
