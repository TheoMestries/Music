<?php
declare(strict_types=1);
require_once __DIR__.'/notes.php';

const MAP_CHARACTER_SHEET_CONFLICT = 'Cette fiche a changé ailleurs. Rouvrez-la avant de reprendre vos modifications.';
const MAP_SHEET_TEMPLATE_SELECTABLE = "(
    (campaign_id = :campaign AND (author_user_id = :user OR visibility <> 'private'))
    OR (campaign_id <> :campaign AND :can_reuse = 1 AND (author_user_id = :user OR visibility = 'public'))
)";

function mapRequireSheetGm(int $campaignId, array $user): array {
    $access=mapRequireCampaignAccess($campaignId,$user);
    if (!mapCanUseGmNotes($access)) mapThrowForbidden('Seul un MJ peut créer ou choisir un modèle de fiche.');
    return $access;
}
function mapSheetCharacter(int $campaignId,int $noteId,array $user,bool $editing=false):array {
    $note=mapGetCampaignNote($campaignId,$noteId,$user);
    if (($note['options']['type'] ?? '')!=='character') throw new DomainException('Cette note n’est pas un personnage.');
    if ($editing && !$note['canEdit']) mapThrowForbidden('Vous ne pouvez pas modifier cette fiche.');
    return $note;
}
function mapSheetTemplates(int $campaignId,array $user):array {
    $access = mapRequireCampaignAccess($campaignId, $user);
    $stmt = mapGetDatabase()->prepare('SELECT id, name, visibility, created_at, campaign_id, author_user_id
        FROM character_sheet_templates WHERE deleted_at IS NULL AND ' . MAP_SHEET_TEMPLATE_SELECTABLE . ' ORDER BY name COLLATE NOCASE, id');
    $stmt->bindValue(':campaign', $campaignId, PDO::PARAM_INT);
    $stmt->bindValue(':user', (int) $user['user_id'], PDO::PARAM_INT);
    $stmt->bindValue(':can_reuse', (int) mapCanUseGmNotes($access), PDO::PARAM_INT);
    $stmt->execute();
    return array_map(static function (array $row) use ($user, $campaignId): array {
        $row['isOwner'] = (int) $row['author_user_id'] === (int) $user['user_id'];
        $row['requiresCopy'] = (int) $row['campaign_id'] !== $campaignId;
        unset($row['author_user_id'], $row['campaign_id']);
        return $row;
    }, $stmt->fetchAll());
}
function mapSheetTemplate(int $campaignId,int $id,array $user):array {
    mapRequireCampaignAccess($campaignId,$user);
    $stmt=mapGetDatabase()->prepare("SELECT id,name,pages,fields,visibility FROM character_sheet_templates
        WHERE campaign_id=? AND id=? AND deleted_at IS NULL AND (author_user_id=? OR visibility <> 'private')");
    $stmt->execute([$campaignId,$id,(int) $user['user_id']]); $row=$stmt->fetch();
    if (!$row) mapThrowNotFound('Modèle introuvable.');
    return mapDecodeSheetTemplate($row);
}
function mapDecodeSheetTemplate(array $row): array {
    return ['id'=>(int)$row['id'],'name'=>$row['name'],'pages'=>json_decode($row['pages'],true),'fields'=>json_decode($row['fields'],true)]
        + array_intersect_key($row, ['visibility' => true]);
}
function mapCreateSheetTemplate(int $campaignId,string $name,array $pages,array $fields,array $user):array {
    mapRequireSheetGm($campaignId,$user);
    [$name, $pages, $fields] = mapValidateSheetTemplate($name, $pages, $fields);
    $pdo = mapGetDatabase();
    $stmt = $pdo->prepare('INSERT INTO character_sheet_templates(campaign_id,author_user_id,name,pages,fields) VALUES (?,?,?,?,?)');
    $stmt->execute([$campaignId, $user['user_id'], $name, $pages, $fields]);
    return mapSheetTemplate($campaignId, (int)$pdo->lastInsertId(), $user);
}

function mapValidateSheetTemplate(string $name, array $pages, array $fields): array {
    $name=mapValidateNoteTitle($name);
    if (!array_is_list($pages) || count($pages)<1 || count($pages)>12) throw new DomainException('Le modèle doit contenir entre 1 et 12 pages.');
    $cleanPages=[]; $totalBytes=0;
    foreach ($pages as $page) {
        if (!is_string($page)) throw new DomainException('Page invalide.');
        $page=mapValidateDocumentImage($page);
        if ($page==='') throw new DomainException('Page vide.');
        $size=getimagesizefromstring(base64_decode(explode(',',$page,2)[1]));
        if ($size[0]>4000 || $size[1]>4000) throw new DomainException('Une page est trop grande (4000 pixels maximum).');
        $totalBytes+=strlen($page); $cleanPages[]=$page;
    }
    if ($totalBytes>6000000) throw new DomainException('Les pages du modèle dépassent 6 Mo.');
    if (!array_is_list($fields) || !$fields || count($fields)>300) throw new DomainException('Définissez entre 1 et 300 zones.');
    $cleanFields=[];$ids=[];
    foreach ($fields as $field) {
        if (!is_array($field)) throw new DomainException('Zone invalide.');
        $id=(string)($field['id']??'');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$id) || isset($ids[$id])) throw new DomainException('Identifiant de zone invalide ou en double.');
        $ids[$id]=true;
        $page=$field['page']??null;
        if (!is_int($page) || $page<0 || $page>=count($pages)) throw new DomainException('Page de zone invalide.');
        $type = $field['type'] ?? 'text';
        if (!in_array($type, ['text', 'checkbox'], true)) throw new DomainException('Type de zone invalide.');
        $clean=['id'=>$id,'page'=>$page,'label'=>mapValidateNoteTitle((string)($field['label']??'')),'type'=>$type];
        foreach (['x','y','w','h','font'] as $key) {
            $value=$field[$key]??null;
            if (!is_numeric($value) || !is_finite((float)$value)) throw new DomainException('Position de zone invalide.');
            $clean[$key]=(float)$value;
        }
        if ($clean['x']<0 || $clean['y']<0 || $clean['w']<0.005 || $clean['h']<0.005 || $clean['x']+$clean['w']>1.00001 || $clean['y']+$clean['h']>1.00001 || $clean['font']<0.005 || $clean['font']>0.08) throw new DomainException('La zone doit rester à l’intérieur de la page.');
        $cleanFields[]=$clean;
    }
    return [$name, json_encode($cleanPages, JSON_THROW_ON_ERROR), json_encode($cleanFields, JSON_THROW_ON_ERROR)];
}

function mapCopySheetTemplate(int $campaignId, int $id, array $user): array {
    $access = mapRequireCampaignAccess($campaignId, $user);
    $pdo = mapGetDatabase();
    $stmt = $pdo->prepare("INSERT INTO character_sheet_templates(campaign_id,author_user_id,name,pages,fields)
        SELECT :campaign, :user, 'Copie de ' || substr(name,1,151), pages, fields
        FROM character_sheet_templates WHERE id = :id AND deleted_at IS NULL AND " . MAP_SHEET_TEMPLATE_SELECTABLE);
    $stmt->bindValue(':campaign', $campaignId, PDO::PARAM_INT);
    $stmt->bindValue(':user', (int) $user['user_id'], PDO::PARAM_INT);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':can_reuse', (int) mapCanUseGmNotes($access), PDO::PARAM_INT);
    $stmt->execute();
    if (!$stmt->rowCount()) mapThrowNotFound('Modèle introuvable.');
    return mapSheetTemplate($campaignId, (int)$pdo->lastInsertId(), $user);
}

function mapRequireOwnedSheetTemplate(PDO $pdo, int $campaignId, int $id, array $user): void {
    $stmt = $pdo->prepare('SELECT author_user_id FROM character_sheet_templates WHERE campaign_id=? AND id=? AND deleted_at IS NULL');
    $stmt->execute([$campaignId, $id]);
    $author = $stmt->fetchColumn();
    if ($author === false) mapThrowNotFound('Modèle introuvable.');
    if ((int)$author !== (int)$user['user_id']) mapThrowForbidden('Seul le créateur peut gérer ce modèle.');
}

function mapSetSheetTemplateVisibility(int $campaignId, int $id, mixed $visibility, array $user): void {
    mapRequireCampaignAccess($campaignId, $user);
    if (!in_array($visibility, ['private', 'campaign', 'public'], true)) {
        throw new DomainException('Visibilité du modèle invalide.');
    }
    $pdo = mapGetDatabase();
    mapRequireOwnedSheetTemplate($pdo, $campaignId, $id, $user);
    $stmt = $pdo->prepare('UPDATE character_sheet_templates SET visibility = ?
        WHERE id = ? AND campaign_id = ? AND deleted_at IS NULL');
    $stmt->execute([$visibility, $id, $campaignId]);
    if (!$stmt->rowCount()) mapThrowNotFound('Modèle introuvable.');
}

function mapUpdateSheetTemplate(int $campaignId, int $id, string $name, array $pages, array $fields, array $user): array {
    mapRequireCampaignAccess($campaignId, $user);
    $pdo = mapGetDatabase();
    mapRequireOwnedSheetTemplate($pdo, $campaignId, $id, $user);
    [$name, $pages, $fields] = mapValidateSheetTemplate($name, $pages, $fields);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE character_sheet_templates SET name=?,pages=?,fields=? WHERE campaign_id=? AND id=? AND deleted_at IS NULL');
        $stmt->execute([$name, $pages, $fields, $campaignId, $id]);
        if (!$stmt->rowCount()) mapThrowNotFound('Modèle introuvable.');

        // Keep compatible values and preserve JSON booleans, which json_each exposes as integers.
        $matchingValues = "SELECT json_group_object(entry.key, json(CASE entry.type
                WHEN 'true' THEN 'true' WHEN 'false' THEN 'false' ELSE json_quote(entry.value) END))
            FROM json_each(field_values) entry
            JOIN json_each(?) zone ON json_extract(zone.value, '$.id') = entry.key
            WHERE CASE json_extract(zone.value, '$.type')
                WHEN 'checkbox' THEN entry.type IN ('true', 'false') ELSE entry.type = 'text' END";
        $stmt = $pdo->prepare("UPDATE character_sheets SET field_values=($matchingValues),
            revision=revision+1, updated_at=CURRENT_TIMESTAMP WHERE template_id=?");
        $stmt->execute([$fields, $id]);
        $stmt = $pdo->prepare("UPDATE campaign_codex_character_sheets SET field_values=($matchingValues),
            revision=revision+1 WHERE template_id=?");
        $stmt->execute([$fields, $id]);
        $pdo->prepare('UPDATE campaign_board_state SET revision=revision+1 WHERE campaign_id=?')->execute([$campaignId]);
        $template = mapSheetTemplate($campaignId, $id, $user);
        $pdo->commit();
        return $template;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function mapDeleteSheetTemplate(int $campaignId, int $id, array $user): void {
    mapRequireCampaignAccess($campaignId, $user);
    $pdo = mapGetDatabase();
    mapRequireOwnedSheetTemplate($pdo, $campaignId, $id, $user);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE character_sheet_templates SET deleted_at=CURRENT_TIMESTAMP WHERE campaign_id=? AND id=? AND deleted_at IS NULL');
        $stmt->execute([$campaignId, $id]);
        if (!$stmt->rowCount()) mapThrowNotFound('Modèle introuvable.');
        $pdo->prepare('UPDATE campaigns SET default_sheet_template_id=NULL WHERE id=? AND default_sheet_template_id=?')->execute([$campaignId, $id]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
function mapGetCharacterSheet(int $campaignId,int $noteId,array $user):?array {
    mapSheetCharacter($campaignId,$noteId,$user);
    $stmt=mapGetDatabase()->prepare('SELECT t.id,t.name,t.pages,t.fields,s.field_values,s.revision
        FROM character_sheets s JOIN character_sheet_templates t ON t.id=s.template_id
        WHERE s.note_id=? AND t.campaign_id=?');
    $stmt->execute([$noteId,$campaignId]);$row=$stmt->fetch();
    if (!$row) return null;
    return mapDecodeCharacterSheet($row);
}
function mapDecodeCharacterSheet(array $row): array {
    return ['template'=>mapDecodeSheetTemplate($row),'values'=>(object)json_decode($row['field_values'],true),'revision'=>(int)$row['revision']];
}
function mapAssignCharacterSheet(int $campaignId,int $noteId,int $templateId,array $user):array {
    $pdo=mapGetDatabase();
    $pdo->beginTransaction();
    try {
        mapRequireSheetGm($campaignId,$user);
        mapSheetCharacter($campaignId,$noteId,$user,true);
        $stmt=$pdo->prepare("INSERT INTO character_sheets(note_id,template_id)
            SELECT ?,id FROM character_sheet_templates WHERE id=? AND campaign_id=? AND deleted_at IS NULL
                AND (author_user_id=? OR visibility <> 'private')
            ON CONFLICT(note_id) DO UPDATE SET field_values=CASE WHEN character_sheets.template_id=excluded.template_id THEN character_sheets.field_values ELSE '{}' END, template_id=excluded.template_id, revision=character_sheets.revision+1, updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([$noteId,$templateId,$campaignId,(int) $user['user_id']]);
        if (!$stmt->rowCount()) mapThrowNotFound('Modèle introuvable.');
        mapSyncCodexPublicationSheet($pdo, $campaignId, $noteId, false);
        $pdo->prepare('UPDATE campaign_board_state SET revision=revision+1 WHERE campaign_id=?')->execute([$campaignId]);
        $sheet = mapGetCharacterSheet($campaignId,$noteId,$user);
        $pdo->commit();
        return $sheet;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
function mapSaveCharacterSheet(int $campaignId,int $noteId,int $revision,array $values,array $user):array {
    $pdo = mapGetDatabase();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        mapSheetCharacter($campaignId,$noteId,$user,true);
        $sheet=mapGetCharacterSheet($campaignId,$noteId,$user);
        $clean = mapCharacterSheetValues($sheet, $revision, $values);
        $stmt=$pdo->prepare('UPDATE character_sheets SET field_values=?,revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE note_id=? AND revision=?');
        $stmt->execute([$clean,$noteId,$revision]);
        if ($stmt->rowCount()!==1) mapThrowHttpException(409, MAP_CHARACTER_SHEET_CONFLICT);
        mapSyncCodexPublicationSheet($pdo, $campaignId, $noteId, false);
        $pdo->prepare('UPDATE campaign_board_state SET revision=revision+1 WHERE campaign_id=?')->execute([$campaignId]);
        if ($ownsTransaction) $pdo->commit();
        return ['revision'=>$revision+1];
    } catch (Throwable $error) {
        if ($ownsTransaction) $pdo->rollBack();
        throw $error;
    }
}

function mapCharacterSheetValues(?array $sheet, int $revision, array $values): string {
    if (!$sheet) mapThrowNotFound('Choisissez d’abord un modèle.');
    if ($sheet['revision'] !== $revision) mapThrowHttpException(409, MAP_CHARACTER_SHEET_CONFLICT);
    $allowed = array_column($sheet['template']['fields'], null, 'id');
    $clean = [];
    $length = 0;
    foreach ($values as $id => $value) {
        $field = $allowed[$id] ?? null;
        $valid = (($field['type'] ?? 'text') === 'checkbox') ? is_bool($value) : is_string($value) && mb_strlen($value) <= 10000;
        if ($field === null || !$valid) throw new DomainException('Valeur de zone invalide.');
        if (is_string($value)) $length += strlen($value);
        $clean[$id] = $value;
    }
    if ($length>200000) throw new DomainException('La fiche contient trop de texte.');
    return json_encode((object)$clean, JSON_THROW_ON_ERROR);
}
