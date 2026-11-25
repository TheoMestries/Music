<?php
declare(strict_types=1);
require_once __DIR__.'/notes.php';

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
    mapRequireCampaignAccess($campaignId,$user);
    $stmt=mapGetDatabase()->prepare('SELECT id,name,created_at,author_user_id FROM character_sheet_templates WHERE campaign_id=? AND deleted_at IS NULL ORDER BY name COLLATE NOCASE,id');
    $stmt->execute([$campaignId]);
    return array_map(static function (array $row) use ($user): array {
        $row['isOwner'] = (int)$row['author_user_id'] === (int)$user['user_id'];
        unset($row['author_user_id']);
        return $row;
    }, $stmt->fetchAll());
}
function mapSheetTemplate(int $campaignId,int $id,array $user):array {
    mapRequireCampaignAccess($campaignId,$user);
    $stmt=mapGetDatabase()->prepare('SELECT id,name,pages,fields FROM character_sheet_templates WHERE campaign_id=? AND id=? AND deleted_at IS NULL');
    $stmt->execute([$campaignId,$id]); $row=$stmt->fetch();
    if (!$row) mapThrowNotFound('Modèle introuvable.');
    return mapDecodeSheetTemplate($row);
}
function mapDecodeSheetTemplate(array $row): array {
    return ['id'=>(int)$row['id'],'name'=>$row['name'],'pages'=>json_decode($row['pages'],true),'fields'=>json_decode($row['fields'],true)];
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
    if (!array_is_list($fields) || !$fields || count($fields)>300) throw new DomainException('Définissez entre 1 et 300 zones de texte.');
    $cleanFields=[];$ids=[];
    foreach ($fields as $field) {
        if (!is_array($field)) throw new DomainException('Zone invalide.');
        $id=(string)($field['id']??'');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$id) || isset($ids[$id])) throw new DomainException('Identifiant de zone invalide ou en double.');
        $ids[$id]=true;
        $page=$field['page']??null;
        if (!is_int($page) || $page<0 || $page>=count($pages)) throw new DomainException('Page de zone invalide.');
        $clean=['id'=>$id,'page'=>$page,'label'=>mapValidateNoteTitle((string)($field['label']??''))];
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
    mapRequireCampaignAccess($campaignId, $user);
    $pdo = mapGetDatabase();
    $stmt = $pdo->prepare("INSERT INTO character_sheet_templates(campaign_id,author_user_id,name,pages,fields)
        SELECT campaign_id,?, 'Copie de ' || substr(name,1,151),pages,fields
        FROM character_sheet_templates WHERE campaign_id=? AND id=? AND deleted_at IS NULL");
    $stmt->execute([$user['user_id'], $campaignId, $id]);
    if (!$stmt->rowCount()) mapThrowNotFound('Modèle introuvable.');
    return mapSheetTemplate($campaignId, (int)$pdo->lastInsertId(), $user);
}

function mapRequireOwnedSheetTemplate(PDO $pdo, int $campaignId, int $id, array $user): void {
    $stmt = $pdo->prepare('SELECT author_user_id FROM character_sheet_templates WHERE campaign_id=? AND id=? AND deleted_at IS NULL');
    $stmt->execute([$campaignId, $id]);
    $author = $stmt->fetchColumn();
    if ($author === false) mapThrowNotFound('Modèle introuvable.');
    if ((int)$author !== (int)$user['user_id']) mapThrowForbidden('Seul le créateur peut modifier ou supprimer ce modèle.');
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

        // Drop values for removed zones and invalidate saves from older versions of the sheet.
        $stmt = $pdo->prepare("UPDATE character_sheets SET field_values=(
            SELECT json_group_object(entry.key,entry.value) FROM json_each(character_sheets.field_values) entry
            WHERE entry.key IN (SELECT json_extract(zone.value,'$.id') FROM json_each(?) zone)
        ), revision=revision+1, updated_at=CURRENT_TIMESTAMP WHERE template_id=?");
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
    return ['template'=>mapDecodeSheetTemplate($row),'values'=>(object)json_decode($row['field_values'],true),'revision'=>(int)$row['revision']];
}
function mapAssignCharacterSheet(int $campaignId,int $noteId,int $templateId,array $user):array {
    mapRequireSheetGm($campaignId,$user);mapSheetCharacter($campaignId,$noteId,$user,true);
    $pdo=mapGetDatabase();
    // Values belong to a particular model. Changing it is explicit in the UI.
    $stmt=$pdo->prepare("INSERT INTO character_sheets(note_id,template_id)
        SELECT ?,id FROM character_sheet_templates WHERE id=? AND campaign_id=? AND deleted_at IS NULL
        ON CONFLICT(note_id) DO UPDATE SET field_values=CASE WHEN character_sheets.template_id=excluded.template_id THEN character_sheets.field_values ELSE '{}' END, template_id=excluded.template_id, revision=character_sheets.revision+1, updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$noteId,$templateId,$campaignId]);
    if (!$stmt->rowCount()) mapThrowNotFound('Modèle introuvable.');
    $pdo->prepare('UPDATE campaign_board_state SET revision=revision+1 WHERE campaign_id=?')->execute([$campaignId]);
    return mapGetCharacterSheet($campaignId,$noteId,$user);
}
function mapSaveCharacterSheet(int $campaignId,int $noteId,int $revision,array $values,array $user):array {
    mapSheetCharacter($campaignId,$noteId,$user,true);
    $sheet=mapGetCharacterSheet($campaignId,$noteId,$user);
    if (!$sheet) mapThrowNotFound('Choisissez d’abord un modèle.');
    $conflict = 'Cette fiche a changé ailleurs. Fermez puis rouvrez la fiche avant de reprendre vos modifications.';
    if ($sheet['revision'] !== $revision) mapThrowHttpException(409, $conflict);
    $allowed=array_column($sheet['template']['fields'],'id');$clean=[];$length=0;
    foreach($values as $id=>$value) {
        if (!in_array((string)$id,$allowed,true) || !is_string($value) || mb_strlen($value)>10000) throw new DomainException('Valeur de zone invalide.');
        $length+=strlen($value);$clean[$id]=$value;
    }
    if ($length>200000) throw new DomainException('La fiche contient trop de texte.');
    $stmt=mapGetDatabase()->prepare('UPDATE character_sheets SET field_values=?,revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE note_id=? AND revision=?');
    $stmt->execute([json_encode((object)$clean,JSON_THROW_ON_ERROR),$noteId,$revision]);
    if ($stmt->rowCount()!==1) mapThrowHttpException(409, $conflict);
    mapGetDatabase()->prepare('UPDATE campaign_board_state SET revision=revision+1 WHERE campaign_id=?')->execute([$campaignId]);
    return ['revision'=>$revision+1];
}
