<?php
declare(strict_types=1);

require_once __DIR__.'/character-sheets.php';

function mapTokenDefaults(): array
{
    return ['name'=>'','showName'=>false,'opacity'=>100,'tint'=>'','tooltip'=>'','showTooltip'=>false,
        'gmTooltip'=>false,'barPosition'=>'above','barStyle'=>'standard','markers'=>[],
        'bars'=>array_fill(0,3,['value'=>null,'max'=>null,'field'=>'','show'=>true])];
}

function mapTokenOptions(string $json): array
{
    return array_replace(mapTokenDefaults(), json_decode($json, true, 512, JSON_THROW_ON_ERROR));
}

function mapTokenNumber(mixed $value): int|float|null
{
    if ($value === null || $value === '') return null;
    if (!is_numeric($value) || !is_finite((float)$value) || abs((float)$value) > 1000000000) {
        throw new DomainException('Valeur de barre invalide.');
    }
    return (float)$value;
}

function mapTokenSheetFields(PDO $pdo, int $noteId): array
{
    $stmt = $pdo->prepare('SELECT t.fields FROM character_sheets s JOIN character_sheet_templates t ON t.id=s.template_id WHERE s.note_id=?');
    $stmt->execute([$noteId]);
    $fields = json_decode($stmt->fetchColumn() ?: '[]', true);
    $fields = array_filter($fields, static fn (array $field): bool => ($field['type'] ?? 'text') === 'text');
    return array_values(array_map(static fn(array $field): array => ['id'=>(string)$field['id'],'label'=>$field['label']],$fields));
}

function mapValidateTokenOptions(array $options, array $fields): array
{
    foreach (['showName','showTooltip','gmTooltip'] as $key) {
        if (!is_bool($options[$key] ?? null)) throw new DomainException('Option de jeton invalide.');
    }
    foreach (['name'=>160,'tooltip'=>150] as $key=>$length) {
        if (!is_string($options[$key] ?? null) || mb_strlen($options[$key])>$length) throw new DomainException('Texte du jeton trop long.');
        $options[$key] = trim($options[$key]);
    }
    if (!is_int($options['opacity'] ?? null) || $options['opacity']<0 || $options['opacity']>100 ||
        !in_array($options['barPosition'] ?? null,['above','below'],true) ||
        !in_array($options['barStyle'] ?? null,['standard','compact'],true)) throw new DomainException('Affichage du jeton invalide.');
    if (!is_string($options['tint'] ?? null) || !preg_match('/^(?:#[a-f0-9]{6})?$/iD',$options['tint'])) throw new DomainException('Teinte invalide.');
    $catalog = ['mort','poison','blesse','feu','ralenti','protege','entrave','endormi','aveugle','effraye','etourdi','invisible','benediction','froid','silence','desarme','concentration','attente','combat','soin','dot-red','dot-blue','dot-green','dot-orange','dot-purple','dot-pink','dot-yellow'];
    if (!is_array($options['markers'] ?? null) || !array_is_list($options['markers']) ||
        count($options['markers'])>count($catalog)) throw new DomainException('Marqueurs invalides.');
    foreach ($options['markers'] as $marker) if (!in_array($marker,$catalog,true)) throw new DomainException('Marqueur inconnu.');
    $options['markers'] = array_values(array_unique($options['markers']));
    if (!is_array($options['bars'] ?? null) || !array_is_list($options['bars']) || count($options['bars'])!==3) {
        throw new DomainException('Trois barres sont attendues.');
    }
    $fieldIds = array_column($fields,'id');
    foreach ($options['bars'] as $index=>$bar) {
        if (!is_array($bar) || !is_bool($bar['show'] ?? null) || !is_string($bar['field'] ?? null) ||
            ($bar['field'] !== '' && !in_array($bar['field'],$fieldIds,true))) throw new DomainException('Caractéristique de barre invalide.');
        $max = mapTokenNumber($bar['max'] ?? null);
        if ($max !== null && $max < 0) throw new DomainException('Le maximum doit être positif ou nul.');
        $options['bars'][$index] = ['value'=>mapTokenNumber($bar['value'] ?? null),'max'=>$max,'show'=>$bar['show'],'field'=>$bar['field']];
    }
    return array_intersect_key($options,mapTokenDefaults());
}

function mapPublicTokenOptions(array $row, bool $canManage, bool $canControl): array
{
    $options = mapTokenOptions($row['token_settings']);
    $values = json_decode($row['sheet_values'] ?? '{}',true);
    foreach ($options['bars'] as &$bar) {
        if ($bar['field'] !== '') {
            $value = $values[$bar['field']] ?? null;
            $bar['value'] = is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
        }
        if (!$bar['show'] && !$canControl) $bar = ['value'=>null,'max'=>null,'show'=>false,'field'=>''];
        if (!$canControl) unset($bar['field']);
    }
    unset($bar);
    if ((!$options['showTooltip'] && !$canControl) || ($options['gmTooltip'] && !$canManage)) $options['tooltip'] = '';
    return $options;
}

function mapBoardTokenSettings(int $campaignId,int $pageId,int $id,string $layer,array $user): array
{
    $access = mapRequireCampaignAccess($campaignId,$user);
    $canManage = mapCanUseGmNotes($access);
    $pdo = mapGetDatabase();
    $page = mapBoardSnapshot($campaignId,$user)['activePage'];
    if ((int)$page['id'] !== $pageId || (!$canManage && $layer !== 'players')) mapThrowNotFound('Jeton introuvable.');
    $entity = mapRequireBoardEntityControl($pdo,$campaignId,$pageId,$id,$layer,$access,$user);
    $note = mapGetCampaignNoteRecord($pdo,$campaignId,(int)$entity['note_id']);
    if ((mapDocumentOptions($note)['type'] ?? null) !== 'character') throw new DomainException('Cet élément n’est pas un jeton de personnage.');
    $characters = [];
    $stmt = $pdo->prepare("SELECT id,title,author_user_id,visibility,codex_entry_id,codex_published,
        json_remove(options,'$.image','$.tokenImage','$.gmNotes') AS options
        FROM campaign_notes WHERE campaign_id=? AND json_extract(options,'$.type')='character' ORDER BY title");
    $stmt->execute([$campaignId]);
    foreach ($stmt as $candidate) {
        if (mapCanViewCampaignNote($candidate,$access,$user) && mapCanEditCampaignNote($candidate,$access,$user)) {
            $characters[] = ['id'=>(int)$candidate['id'],'name'=>$candidate['title'],'fields'=>mapTokenSheetFields($pdo,(int)$candidate['id'])];
        }
    }
    foreach ($page['entities'] as $entry) {
        if ($entry['id'] !== $id) continue;
        return ['options'=>$entry['token'],'noteId'=>(int)$entity['note_id'],'name'=>$note['title'],
            'canManage'=>$canManage,'canAccessSheet'=>mapCanViewCampaignNote($note,$access,$user),
            'canSaveDefault'=>mapCanViewCampaignNote($note,$access,$user)&&mapCanEditCampaignNote($note,$access,$user),
            'characters'=>$characters];
    }
    mapThrowNotFound('Jeton introuvable.');
}

function mapUpdateBoardToken(PDO $pdo,int $campaignId,array $entity,array $payload,array $access,array $user): void
{
    $noteId = (int)$entity['note_id'];
    $note = mapGetCampaignNoteRecord($pdo,$campaignId,$noteId);
    if ((mapDocumentOptions($note)['type'] ?? null) !== 'character') throw new DomainException('Cet élément n’est pas un jeton de personnage.');
    $options = mapTokenOptions($entity['token_settings']);
    $canManage = mapCanUseGmNotes($access);
    $fields = mapTokenSheetFields($pdo,$noteId);
    $fieldIds = array_column($fields,'id');
    foreach ($options['bars'] as &$bar) {
        if ($bar['field'] !== '' && !in_array($bar['field'],$fieldIds,true)) $bar['field'] = '';
    }
    unset($bar);
    $change = $payload['change'] ?? '';
    if ($change === 'configure') {
        $newNoteId = mapValidatePositiveInt($payload['noteId'] ?? $noteId,'Personnage');
        if ($newNoteId !== $noteId) {
            $newNote = mapGetCampaignNote($campaignId,$newNoteId,$user);
            if (!$newNote['canEdit'] || ($newNote['options']['type'] ?? null)!=='character') mapThrowForbidden('Personnage non modifiable.');
            $noteId = $newNoteId;
            $fields = mapTokenSheetFields($pdo,$noteId);
        }
        $patch = $payload['options'] ?? null;
        if (!is_array($patch)) throw new DomainException('Paramètres invalides.');
        if (!$canManage) {
            unset($patch['gmTooltip']);
            if ($options['gmTooltip']) unset($patch['tooltip'],$patch['showTooltip']);
        }
        unset($patch['markers']);
        $options = array_replace($options,$patch);
    } elseif ($change === 'markers') {
        $options['markers'] = $payload['markers'] ?? null;
    } elseif ($change === 'bar') {
        $index = $payload['index'] ?? null;
        if (!in_array($index,[0,1,2],true)) throw new DomainException('Barre inconnue.');
        $value = mapTokenNumber($payload['value'] ?? null);
        $field = $options['bars'][$index]['field'];
        if ($field !== '') {
            $sheet = mapGetCharacterSheet($campaignId,$noteId,$user);
            if (!$sheet) throw new DomainException('Fiche introuvable.');
            $values = (array)$sheet['values'];
            $values[$field] = $value === null ? '' : (string)$value;
            mapSaveCharacterSheet($campaignId,$noteId,$sheet['revision'],$values,$user);
        } else $options['bars'][$index]['value'] = $value;
    } elseif ($change !== 'default') throw new DomainException('Action de jeton inconnue.');

    $options = mapValidateTokenOptions($options,$fields);
    $encoded = json_encode($options,JSON_THROW_ON_ERROR);
    if ($change === 'default' || ($change === 'configure' && ($payload['saveDefault'] ?? false) === true)) {
        mapSheetCharacter($campaignId,$noteId,$user,true);
        $pdo->prepare('UPDATE campaign_notes SET token_defaults=? WHERE id=?')->execute([$encoded,$noteId]);
    }
    if ($change !== 'default') $pdo->prepare('UPDATE board_entities SET note_id=?, token_settings=? WHERE id=?')->execute([$noteId,$encoded,$entity['id']]);
}
