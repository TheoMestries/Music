<?php
declare(strict_types=1);

putenv('ATLAS_JDR_DATABASE_PATH=:memory:');
require_once __DIR__ . '/../includes/campaign-codex.php';
require_once __DIR__ . '/../includes/codex-sections.php';

function assertCodexSections(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = mapGetDatabase();
$pdo->exec("INSERT INTO users (username, display_name, password_hash, role) VALUES ('fixture', 'MJ', 'fixture', 'gm')");
$pdo->exec("INSERT INTO campaigns (name, owner_user_id) VALUES ('Premier monde', 1), ('Autre monde', 1)");
$item = mapCodexSaveEntry($pdo, 1, null, ['category' => 'Objets', 'title' => 'Lanterne'], null);
$otherItem = mapCodexSaveEntry($pdo, 2, null, ['category' => 'Objets', 'title' => 'Autre lanterne'], null);

$details = json_decode('{
    "age":"37",
    "inventories":[{"item_id":21,"item":{"name":"Lanterne"},"amount":2,"description":"<em>Grand modèle</em>","visibility_id":4}],
    "entity_attributes":[{"name":"Vigilance","value":"28","is_pinned":true}],
    "assets":[{"name":"Croquis","metadata":{"url":"javascript:alert(1)","icon":"photo"}}]
}', false, 32, JSON_THROW_ON_ERROR);
assertCodexSections(mapCodexBindImportedSectionReferences($details, 'Personnages', ['items:21' => $item]), 'La référence à un objet doit être résolue après import.');
assertCodexSections($details->inventories[0]->codex_entry_id === $item, 'La référence doit utiliser l’identifiant Sus20.');

$form = ['codexSection' => ['inventaire' => [
    'submitted' => '1', 'original' => ['0'], 'codex_entry_id' => [(string) $item], 'name' => ['Lanterne'],
    'amount' => ['3'], 'description' => ['Grand modèle'], 'is_equipped' => ['1'], 'is_pinned' => ['1'],
]]];
mapCodexApplySheetSections($details, 'Personnages', $form);
mapCodexValidateSheetTargets($pdo, 1, null, 'Personnages', $form);
assertCodexSections($details->inventories[0]->amount === 3 && $details->inventories[0]->is_equipped, 'La quantité et l’équipement doivent être enregistrés.');
assertCodexSections($details->inventories[0]->description === '<em>Grand modèle</em>' && $details->inventories[0]->visibility_id === 4, 'Une édition doit préserver les autres données importées.');
assertCodexSections($details->age === '37', 'Une édition doit laisser les autres sections intactes.');
$pins = mapCodexSheetPins($details, 'Personnages');
assertCodexSections(array_column($pins, 'label') === ['Lanterne', 'Vigilance'], 'Les épingles doivent inclure les propriétés et objets choisis.');

$form['codexSection']['inventaire']['codex_entry_id'] = [(string) $otherItem];
try {
    mapCodexValidateSheetTargets($pdo, 1, null, 'Personnages', $form);
    throw new RuntimeException('Une référence à une autre campagne a été acceptée.');
} catch (DomainException) {
}
assertCodexSections(mapCodexMediaAddress('javascript:alert(1)') === null, 'Une adresse de média doit utiliser HTTP ou HTTPS.');
assertCodexSections(mapCodexMediaAddress('https://example.org/croquis') !== null, 'Un lien HTTPS valide doit être accepté.');
assertCodexSections(!mapCodexSectionDate('2026-02-29') && mapCodexSectionDate('2028-02-29'), 'Les échéances doivent être vérifiées.');

$calendar = mapCodexSaveEntry($pdo, 1, null, [
    'category' => 'Calendriers', 'title' => 'Calendrier fictif', 'codexCalendarSubmitted' => '1',
    'codexCalendar' => ['monthName' => ['Lune du vent'], 'monthLength' => ['35'], 'monthOriginal' => [''],
        'weekday' => ['Aube', 'Midi'], 'date' => '2-1-1'],
], null);
$reminderForm = ['codexSection' => ['rappels' => [
    'submitted' => '1', 'original' => [''], 'codex_entry_id' => [(string) $calendar],
    'name' => ['Rendez-vous'], 'date' => ['2-1-34'], 'done' => ['0'], 'description' => [''], 'is_pinned' => ['1'],
]]];
$reminderEntry = mapCodexSaveEntry($pdo, 1, null, ['category' => 'Personnages', 'title' => 'Arpenteuse'] + $reminderForm, null);
$reminders = mapCodexCalendarReminders($pdo, 1, $calendar);
assertCodexSections(count($reminders['2/1/34']) === 1 && $reminders['2/1/34'][0]['title'] === 'Rendez-vous'
    && $reminders['2/1/34'][0]['entry'] === $reminderEntry && mapCodexIsElementKey($reminders['2/1/34'][0]['codex_element_key']),
    'Le rappel doit apparaître au jour du calendrier choisi.');
assertCodexSections(mapCodexCalendarReminders($pdo, 2, $calendar) === [], 'Les rappels doivent rester dans leur campagne.');
$reminderForm['codexSection']['rappels']['date'] = ['2-1-36'];
try {
    mapCodexSaveEntry($pdo, 1, null, ['category' => 'Personnages', 'title' => 'Date invalide'] + $reminderForm, null);
    throw new RuntimeException('Un rappel hors des jours du calendrier a été accepté.');
} catch (DomainException) {
}

$visiblePower = mapCodexSaveEntry($pdo, 1, null, [
    'category' => 'Capacités', 'title' => 'Un pouvoir de test',
    'body_html' => '<p><strong>Effet visible</strong> [[fiche:' . $item . ']] [[fiche:' . $otherItem . ']]</p>',
], null);
$hiddenPower = mapCodexSaveEntry($pdo, 1, null, [
    'category' => 'Capacités', 'title' => 'Un pouvoir privé', 'body' => 'Effet privé',
], null);
$index = mapCodexEntries($pdo, 1);
unset($index[$hiddenPower]);
$url = static fn (array $query): string => '/codex.php?' . http_build_query($query);
$preview = mapCodexLinkedDescriptions($pdo, 1, $index, $url);
$details = json_decode(json_encode(['abilities' => [
    ['codex_entry_id' => $visiblePower], ['codex_entry_id' => $hiddenPower],
]], JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
ob_start();
mapRenderCodexSheetSection('pouvoirs', $details, ['id' => $reminderEntry, 'category' => 'Personnages', 'links' => []],
    $index, $url, static fn (int $id): string => '', linkedDescription: $preview);
$section = ob_get_clean();
assertCodexSections(str_contains($section, '<strong>Effet visible</strong>') && str_contains($section, 'href="/codex.php?fiche=' . $item . '"'),
    'Une carte de pouvoir doit afficher sa description et ses liens sans ouvrir une autre fiche.');
assertCodexSections(!str_contains($section, 'Effet privé') && !str_contains($section, 'Autre lanterne')
    && $preview($hiddenPower) === '', 'Un aperçu ne doit pas révéler une fiche ou une mention inaccessible.');

echo "OK - édition par section, import des références, épingles et contrôle des médias.\n";
