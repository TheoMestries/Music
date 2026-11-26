<?php
declare(strict_types=1);

putenv('ATLAS_JDR_DATABASE_PATH=:memory:');
require_once __DIR__ . '/../includes/codex-kanka.php';

function assertCodexTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function codexTestArchive(string $path, array $records, string $image): void
{
    $zip = new ZipArchive();
    assertCodexTest($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Archive de test inaccessible.');
    foreach ($records as $name => $value) {
        $zip->addFromString('export-fictif/' . $name, json_encode($value, JSON_THROW_ON_ERROR));
    }
    $zip->addFromString('export-fictif/gallery/survol.png', $image);
    $zip->close();
}

$pdo = mapGetDatabase();
$pdo->exec("INSERT INTO users (username, display_name, password_hash, role)
    VALUES ('mj', 'Le MJ', 'test', 'gm'), ('assistant', 'MJ secondaire', 'test', 'gm'), ('joueur', 'Joueur', 'test', 'player')");
$pdo->exec("INSERT INTO campaigns (name, owner_user_id) VALUES ('Campagne A', 1), ('Campagne B', 2)");
$pdo->exec("INSERT INTO campaign_members (campaign_id, user_id, member_role) VALUES (1, 2, 'gm'), (1, 3, 'player')");
mapRequireCampaignGm(1, ['user_id' => 1]);
mapRequireCampaignGm(1, ['user_id' => 2]);
try {
    mapRequireCampaignGm(1, ['user_id' => 3]);
    throw new RuntimeException('Le joueur a accès au codex.');
} catch (MapHttpException $error) {
    assertCodexTest($error->getStatusCode() === 403, 'Le joueur doit recevoir un refus.');
}

$image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jMqQAAAAASUVORK5CYII=', true);
$records = [
    'campaign.json' => ['id' => 900, 'name' => 'Campagne fictive'],
    'settings/custom-modules.json' => [['id' => 81, 'code' => 'stations', 'plural' => 'Stations orbitales']],
    'characters/pilote.json' => [
        'id' => 12, 'age' => '33 ans',
        'entity' => [
            'id' => 71, 'entity_id' => 12, 'name' => 'Pilote du courrier',
            'entry' => '<p>Premier contact</p><p>Deuxième paragraphe <img data-gallery-id="survol"></p><script>texte à retirer</script>',
            'image_uuid' => 'survol', 'entityLocations' => [['location_id' => 131]],
            'relationships' => [['target_id' => 72, 'relation' => 'Signaux reçus de']],
            'posts' => [['name' => 'Consignes', 'entry' => '<p>Couper les transmissions</p>']],
        ],
    ],
    'locations/dune.json' => ['id' => 131, 'entity' => ['id' => 73, 'name' => 'Dune de verre']],
    'observatoires_81/quai.json' => ['entity' => ['id' => 72, 'name' => 'Quai extérieur']],
    'gallery/survol.json' => ['id' => 'survol', 'name' => 'Photo de test', 'is_folder' => false],
    'gallery/dossier.json' => ['id' => 'dossier', 'name' => 'Dossier fictif', 'is_folder' => true],
];
$path = tempnam(sys_get_temp_dir(), 'codex-test-');
try {
    codexTestArchive($path, $records, $image);
    $result = mapImportKankaCodex($pdo, 1, $path);
    assertCodexTest($result === ['created' => 4, 'skipped' => 0, 'missingImages' => 0], 'Nombre de fiches importées inattendu.');
    $entries = mapCodexEntries($pdo, 1);
    $pilotId = (int) $pdo->query("SELECT id FROM campaign_codex_entries WHERE source_key = 'kanka:900:entity:71'")->fetchColumn();
    $pilot = mapCodexEntry($pdo, 1, $pilotId);
    assertCodexTest($entries[$pilotId]['title'] === 'Pilote du courrier', 'Index des fiches incomplet.');
    assertCodexTest(isset($pilot['image_id']) && (int) $pdo->query('SELECT COUNT(*) FROM campaign_codex_media')->fetchColumn() === 1,
        'L’image de couverture doit être liée et dédupliquée.');
    assertCodexTest($pilot['body'] === "Premier contact\nDeuxième paragraphe", 'La description doit conserver ses paragraphes sans HTML actif.');
    $details = json_decode($pilot['details'], true, 32, JSON_THROW_ON_ERROR);
    assertCodexTest($details['age'] === '33 ans' && $details['posts'][0]['name'] === 'Consignes', 'Les champs et notes associées doivent être conservés.');
    $relationLabels = array_column($pilot['links'], 'label');
    assertCodexTest(count($relationLabels) === 3 && in_array('Signaux reçus de', $relationLabels, true)
        && in_array('Lieu', $relationLabels, true) && in_array('Illustration', $relationLabels, true), 'Les relations et illustrations doivent pointer vers les fiches importées.');
    assertCodexTest(in_array('Stations orbitales', array_column($entries, 'category'), true), 'Le nom de la catégorie personnalisée doit venir du manifeste.');

    mapCodexSaveEntry($pdo, 1, $pilot, [
        'category' => 'Personnages', 'title' => 'Pilote renommé', 'revision' => 1, 'fieldName' => ['Poste'], 'fieldValue' => ['Messager'],
        'details' => $pilot['details'], 'moduleFields' => ['age' => '34 ans', 'price' => '12'],
        'editPosts' => '1', 'postName' => ['Rappel', 'Note personnelle'], 'postOriginal' => ['0', ''],
        'postBody' => ['Couper les transmissions', 'Écrire <silence> dans le rapport'],
    ], null);
    $repeat = mapImportKankaCodex($pdo, 1, $path);
    assertCodexTest($repeat['skipped'] === 4 && $repeat['created'] === 0, 'Un second import doit ignorer les doublons.');
    assertCodexTest(mapCodexEntry($pdo, 1, $pilotId)['title'] === 'Pilote renommé', 'Un nouvel import doit préserver les modifications.');
    $editedDetails = json_decode(mapCodexEntry($pdo, 1, $pilotId)['details'], true, 32, JSON_THROW_ON_ERROR);
    assertCodexTest($editedDetails['age'] === '34 ans' && !isset($editedDetails['price']), 'Les champs du module doivent être validés et enregistrés.');
    assertCodexTest($editedDetails['posts'][0]['name'] === 'Rappel'
        && $editedDetails['posts'][0]['entry'] === '<p>Couper les transmissions</p>'
        && $editedDetails['posts'][0]['codex_element_key'] === $details['posts'][0]['codex_element_key'],
        'Renommer une note doit préserver son texte importé.');
    assertCodexTest(mapCodexPlainText($editedDetails['posts'][1]['entry']) === 'Écrire <silence> dans le rapport',
        'Les notes rédigées dans le Codex doivent conserver leur texte à la lecture.');
    assertCodexTest(mapCodexEntries($pdo, 2) === [], 'Les fiches sont isolées par campagne.');
    try {
        mapCodexEntry($pdo, 2, $pilotId);
        throw new RuntimeException('La fiche est accessible depuis une autre campagne.');
    } catch (MapHttpException $error) {
        assertCodexTest($error->getStatusCode() === 404, 'Une fiche hors campagne doit être introuvable.');
    }

    $records['characters/invalide.json'] = ['entity' => ['id' => 999]];
    codexTestArchive($path, $records, $image);
    try {
        mapImportKankaCodex($pdo, 2, $path);
        throw new RuntimeException('L’archive invalide a été importée.');
    } catch (DomainException) {
        assertCodexTest(mapCodexEntries($pdo, 2) === [], 'Une erreur doit annuler tout l’import.');
        assertCodexTest((int) $pdo->query('SELECT COUNT(*) FROM campaign_codex_media WHERE campaign_id = 2')->fetchColumn() === 0,
            'Une annulation ne doit laisser aucune image dans la campagne.');
    }
    mapCodexDeleteEntry($pdo, 1, mapCodexEntry($pdo, 1, $pilotId), 2);
    assertCodexTest(count(mapCodexEntries($pdo, 1)) === 3, 'La suppression doit retirer la fiche.');
    assertCodexTest((int) $pdo->query('SELECT COUNT(*) FROM campaign_codex_media')->fetchColumn() === 1, 'La galerie doit garder son image.');
} finally {
    unlink($path);
}

echo "OK - Codex, droits MJ, import Kanka et isolation des campagnes.\n";
