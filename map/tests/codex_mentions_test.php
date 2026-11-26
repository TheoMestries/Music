<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/codex-kanka.php';

function assertCodexMention(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = mapOpenDatabase(':memory:');
$pdo->exec("INSERT INTO users (username, display_name, password_hash, role) VALUES ('gm', 'MJ fictif', 'test', 'gm')");
$pdo->exec("INSERT INTO campaigns (name, owner_user_id) VALUES ('Campagne fictive', 1), ('Autre campagne', 1)");
$foreignId = mapCodexSaveEntry($pdo, 2, null, ['category' => 'Lieux', 'title' => 'Fiche étrangère'], null);
$archivePath = tempnam(sys_get_temp_dir(), 'codex-mentions-');
$zip = new ZipArchive();
try {
    assertCodexMention($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Archive de test inaccessible.');
    $json = [
        'campaign.json' => ['id' => 1399],
        'locations/gare.json' => ['entity' => ['id' => 103, 'name' => 'Gare <du sud>']],
        'items/boussole.json' => ['id' => 27, 'entity' => ['id' => 105, 'name' => 'Boussole fictive']],
        'characters/agent.json' => ['entity' => [
            'id' => 104,
            'name' => 'Agent fictif',
            'entry' => '<p>Consulter la <a data-mention="[entity:103]" href="#">gare</a> et le '
                . '<a data-mention="[entity:999]" href="https://example.invalid/introuvable">dépôt absent</a> avec [item:105].</p>',
            'mentions' => [['entity_id' => 104, 'target_id' => 103]],
            'posts' => [['name' => 'Mission fictive', 'entry' => '<p>Retour à [location:103].</p>']],
        ]],
    ];
    foreach ($json as $file => $record) {
        $zip->addFromString($file, json_encode($record, JSON_THROW_ON_ERROR));
    }
    $zip->close();
    mapImportKankaCodex($pdo, 1, $archivePath);
} finally {
    unlink($archivePath);
}

$entries = mapCodexEntries($pdo, 1);
$agentId = (int) $pdo->query("SELECT id FROM campaign_codex_entries WHERE source_key = 'kanka:1399:entity:104'")->fetchColumn();
$gareId = (int) $pdo->query("SELECT id FROM campaign_codex_entries WHERE source_key = 'kanka:1399:entity:103'")->fetchColumn();
$itemId = (int) $pdo->query("SELECT id FROM campaign_codex_entries WHERE source_key = 'kanka:1399:entity:105'")->fetchColumn();
$agent = mapCodexEntry($pdo, 1, $agentId);
$token = '[[fiche:' . $gareId . ']]';
$itemToken = '[[fiche:' . $itemId . ']]';
assertCodexMention(count(mapCodexMentionLinks($agent)) === 2, 'Les mentions inline et structurées du même lien doivent être dédupliquées.');
assertCodexMention(str_contains($agent['body_html'], $token) && str_contains($agent['body'], 'Consulter la gare'),
    'Le texte riche doit contenir le lien local et le texte plat doit garder le libellé source.');
assertCodexMention(str_contains($agent['body_html'], $itemToken) && str_contains($agent['body'], $itemToken),
    'Une mention typée doit utiliser l’identifiant de l’entité Kanka dans les deux formats.');
$notes = json_decode($agent['details'], false, 32, JSON_THROW_ON_ERROR)->posts;
assertCodexMention(str_contains($notes[0]->entry, $token), 'Les mentions des notes associées doivent se résoudre localement.');
$href = static fn (array $query): string => '/map/codex.php?campaignId=1&' . http_build_query($query);
$markup = mapCodexRichMentionMarkup($agent['body_html'], $entries, $href);
assertCodexMention(str_contains($markup, 'Gare &lt;du sud&gt;') && str_contains($markup, '&amp;fiche=' . $gareId),
    'Les liens et titres des fiches doivent être échappés.');
assertCodexMention(!str_contains($markup, 'example.invalid') && str_contains($markup, 'dépôt absent'),
    'Le texte orphelin doit rester lisible et sans lien externe.');

$modified = ['category' => 'Personnages', 'title' => 'Agent fictif', 'revision' => 1,
    'body' => $token . ' [[fiche:' . $foreignId . ']]', 'target' => [$gareId], 'label' => ['Mention']];
mapCodexSaveEntry($pdo, 1, $agent, $modified, null);
$updated = mapCodexEntry($pdo, 1, $agentId);
assertCodexMention(count($updated['links']) === 2 && count(mapCodexMentionLinks($updated)) === 1,
    'Une relation nommée Mention doit rester distincte et une cible hors campagne ne doit pas être liée.');
assertCodexMention(str_contains(mapCodexMentionMarkup($modified['body'], $entries, $href), 'Fiche indisponible'),
    'Une mention hors campagne ne doit révéler aucune donnée.');

$modified['body'] = 'Texte modifié sans mention';
$modified['revision'] = 2;
mapCodexSaveEntry($pdo, 1, $updated, $modified, null);
$updated = mapCodexEntry($pdo, 1, $agentId);
assertCodexMention(count($updated['links']) === 1 && !mapCodexMentionLinks($updated),
    'Retirer la dernière mention visible doit supprimer son lien entrant et préserver les relations.');

$legacyId = mapCodexSaveEntry($pdo, 1, null, [
    'category' => 'Notes', 'title' => 'Une ancienne fiche',
    'body_html' => '<p><em>[location:103]</em> [item:404]</p>',
    'details' => json_encode(['posts' => [['name' => 'Note privée', 'entry' => '<p>[item:105]</p>', 'codex_element_private' => true]]], JSON_THROW_ON_ERROR),
], null);
$unrelated = mapCodexSaveEntry($pdo, 1, null, ['category' => 'Objets', 'title' => 'Autre export'], null);
$pdo->prepare('UPDATE campaign_codex_entries SET source_key = ? WHERE id = ?')->execute(['kanka:1399:entity:210', $legacyId]);
$pdo->prepare('UPDATE campaign_codex_entries SET source_key = ? WHERE id = ?')->execute(['kanka:9999:entity:404', $unrelated]);
$migration = require __DIR__ . '/../migrations/032_kanka_typed_mentions.php';
$migration['up']($pdo);
$legacy = mapCodexEntry($pdo, 1, $legacyId);
assertCodexMention($legacy['body_html'] === '<p><em>' . $token . '</em> [item:404]</p>' && $legacy['revision'] === 1,
    'La mise à niveau doit préserver le format et la révision et ignorer une cible d’un autre export.');
$legacyMentions = array_column(mapCodexMentionLinks($legacy), 'players_visible', 'target_id');
assertCodexMention($legacyMentions === [$gareId => 1, $itemId => 0] || $legacyMentions === [$itemId => 0, $gareId => 1],
    'La mise à niveau doit enregistrer les mentions des notes privées sans les publier.');
echo "OK - mentions importées et rédigées dans le codex.\n";
