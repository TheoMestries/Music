<?php
declare(strict_types=1);

function mapBoardDrawings(PDO $pdo, int $pageId, array $user, bool $canManage): array
{
    $sql = 'SELECT id,author_id,layer,drawing FROM board_drawings WHERE page_id=?';
    if (!$canManage) $sql .= " AND layer <> 'gm'";
    $stmt = $pdo->prepare($sql . ' ORDER BY id');
    $stmt->execute([$pageId]);
    $layers = ['map'=>[], 'players'=>[]];
    if ($canManage) $layers['gm'] = [];
    foreach ($stmt as $row) {
        $layers[$row['layer']][] = [
            ...json_decode($row['drawing'], true, 512, JSON_THROW_ON_ERROR),
            'id'=>(int)$row['id'], 'canEdit'=>$canManage || (int)$row['author_id'] === (int)$user['user_id'],
        ];
    }
    return $layers;
}

function mapValidateBoardPoint(mixed $point, array $settings): array
{
    if (!is_array($point) || !array_is_list($point) || count($point) !== 2) throw new DomainException('Point invalide.');
    foreach (['width', 'height'] as $axis => $dimension) {
        $value = $point[$axis];
        if ((!is_int($value) && !is_float($value)) || !is_finite($value) || $value < 0 || $value > $settings[$dimension]) {
            throw new DomainException('Le dessin doit rester dans la page.');
        }
        $point[$axis] = round($value, 2);
    }
    return $point;
}

function mapValidateBoardDrawing(mixed $input, array $settings): array
{
    if (!is_array($input)) throw new DomainException('Dessin invalide.');
    $type = $input['type'] ?? null;
    $points = $input['points'] ?? null;
    if ($type === 'text') {
        $label = $input['text'] ?? null;
        $font = $input['font'] ?? null;
        $size = $input['size'] ?? null;
        $bold = $input['bold'] ?? false;
        $italic = $input['italic'] ?? false;
        if (!is_string($label) || trim($label) === '' || mb_strlen($label) > 500 ||
            !in_array($font, ['Arial', 'Georgia', 'Verdana', 'Courier New', 'Times New Roman'], true) ||
            !is_int($size) || $size < 8 || $size > 160 || !is_bool($bold) || !is_bool($italic)) {
            throw new DomainException('Texte ou mise en forme invalide.');
        }
        foreach (['fill', 'stroke'] as $key) {
            $color = $input[$key] ?? null;
            if (!is_string($color) || !preg_match('/^#[a-f0-9]{6}$/iD', $color)) throw new DomainException('Couleur invalide.');
        }
        if (!is_array($points) || count($points) !== 1 || !isset($points[0])) throw new DomainException('Position du texte invalide.');
        $position = mapValidateBoardPoint($points[0], $settings);
        return ['type'=>'text', 'points'=>[$position], 'text'=>trim($label), 'font'=>$font, 'size'=>$size,
            'bold'=>$bold, 'italic'=>$italic, 'fill'=>$input['fill'], 'stroke'=>$input['stroke']];
    }
    if (!in_array($type, ['freehand','line','rectangle','ellipse'], true) ||
        !is_array($points) || !array_is_list($points) || count($points) < 2 ||
        count($points) > ($type === 'freehand' ? 1024 : 2)) {
        throw new DomainException('Tracé invalide.');
    }
    $points = array_map(static fn($point) => mapValidateBoardPoint($point, $settings), $points);
    $colors = [];
    foreach (['stroke', 'fill'] as $key) {
        $color = $input[$key] ?? '';
        if (!is_string($color) || !preg_match('/^(?:#[a-f0-9]{6}|transparent)$/iD', $color)) throw new DomainException('Couleur invalide.');
        $colors[$key] = strtolower($color);
    }
    $fillOpacity = $input['fillOpacity'] ?? 100;
    if (!is_int($fillOpacity) || $fillOpacity < 0 || $fillOpacity > 100) throw new DomainException('Opacité de remplissage invalide.');
    if ($type === 'line' || $fillOpacity === 0) $colors['fill'] = 'transparent';
    if ($colors['stroke'] === 'transparent' && $colors['fill'] === 'transparent') throw new DomainException('Choisissez une couleur visible.');
    $width = $input['width'] ?? null;
    if (!in_array($width, [1, 2, 4, 8, 14], true)) throw new DomainException('Épaisseur invalide.');
    return ['type'=>$type, 'points'=>$points, 'width'=>$width, 'fillOpacity'=>$fillOpacity, ...$colors];
}

function mapMutateBoardDrawing(int $campaignId, string $action, array $payload, array $user): array
{
    if ($action !== 'draw' && $action !== 'clear_drawings') throw new DomainException('Action de dessin inconnue.');
    $access = mapRequireCampaignAccess($campaignId, $user);
    $canManage = mapCanUseGmNotes($access);
    $layer = $payload['layer'] ?? '';
    if (!in_array($layer, ['players','map','gm'], true)) throw new DomainException('Calque invalide.');
    if (!$canManage && $layer !== 'players') mapThrowForbidden('Ce calque est réservé au MJ.');
    $pageId = mapValidatePositiveInt($payload['pageId'] ?? null, 'Page');
    $pdo = mapGetDatabase();
    mapTableTimingMark('draw_validation');
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('UPDATE campaign_board_state SET revision=revision WHERE campaign_id=?');
        $lock->execute([$campaignId]);
        mapTableTimingMark('draw_write_lock');
        $page = mapBoardPage($campaignId, $pageId);
        $state = $pdo->prepare('SELECT player_page_id FROM campaign_board_state WHERE campaign_id=?');
        $state->execute([$campaignId]);
        $playerPage = (int) $state->fetchColumn();
        $state->closeCursor();
        if (!$canManage && $playerPage !== $pageId) mapThrowForbidden('Cette page n’est pas affichée aux joueurs.');
        if ($action === 'draw') {
            $drawing = mapValidateBoardDrawing($payload['drawing'] ?? null, $page['settings']);
            $count = $pdo->prepare('SELECT COUNT(*) FROM board_drawings WHERE page_id=? AND layer=?');
            $count->execute([$pageId, $layer]);
            $total = (int) $count->fetchColumn();
            $count->closeCursor();
            if ($total >= 500) throw new DomainException('Ce calque a atteint la limite de 500 dessins.');
            $stmt = $pdo->prepare('INSERT INTO board_drawings(page_id,author_id,layer,drawing) VALUES (?,?,?,?)');
            $stmt->execute([$pageId, $user['user_id'], $layer, json_encode($drawing, JSON_THROW_ON_ERROR)]);
            $before = [];
            $after = mapBoardHistoryRows($pdo,$pageId,$layer,'board_drawings',[(int)$pdo->lastInsertId()]);
        } else {
            $before = mapBoardHistoryRows($pdo,$pageId,$layer,'board_drawings');
            if (!$canManage) {
                $before = array_filter($before,static fn($row) => (int)$row['author_id'] === (int)$user['user_id']);
            }
            $after = [];
            $sql = 'DELETE FROM board_drawings WHERE page_id=? AND layer=?';
            $parameters = [$pageId, $layer];
            if (!$canManage) {
                $sql .= ' AND author_id=?';
                $parameters[] = $user['user_id'];
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($parameters);
        }
        if ($stmt->rowCount()) {
            mapRecordBoardHistory($pdo,$pageId,$layer,$user,['board_drawings'=>$before],[
                'board_drawings'=>$after,
            ]);
            $update = $pdo->prepare('UPDATE campaign_board_state SET revision=revision+1 WHERE campaign_id=?');
            $update->execute([$campaignId]);
        }
        $pdo->commit();
        mapTableTimingMark('draw_write');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return mapBoardSnapshot($campaignId, $user);
}
