<?php
declare(strict_types=1);

putenv('ATLAS_JDR_DATABASE_PATH=:memory:');
require_once __DIR__ . '/../includes/codex-element-access.php';

function assertCodexElementAccess(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = mapGetDatabase();
$pdo->exec("INSERT INTO users (username, display_name, password_hash, role)
    VALUES ('gamemaster', 'MJ fictif', 'test', 'gm'), ('player-one', 'Joueur un', 'test', 'player'),
        ('player-two', 'Joueur deux', 'test', 'player'), ('external', 'Joueur d’une autre campagne', 'test', 'player')");
$pdo->exec("INSERT INTO campaigns (name, owner_user_id) VALUES ('Campagne fictive', 1), ('Campagne extérieure', 1);
    INSERT INTO campaign_members (campaign_id, user_id, member_role) VALUES (1, 2, 'player'), (1, 3, 'player'), (2, 4, 'player');
    INSERT INTO campaign_codex_entries (campaign_id, category, title)
        VALUES (1, 'Personnages', 'Personnage fictif'), (1, 'Lieux', 'Lieu fictif'), (2, 'Personnages', 'Personnage extérieur')");

$context = static function (int $user, int $campaign = 1, int $entry = 1) use ($pdo): array {
    $access = mapRequireCampaignAccess($campaign, ['user_id' => $user]);
    return mapCodexElementPermissions($pdo, $campaign, $entry, mapCodexPermissionContext($pdo, $campaign, $access, $user));
};
$note = str_repeat('a', 24);
$otherNote = str_repeat('b', 24);

assertCodexElementAccess(mapCodexElementCanRead($context(1), 'notes', $note, true)
    && mapCodexElementCanEdit($context(1), 'notes', $note, false, true)
    && mapCodexElementCanCreate($context(1), 'notes', false), 'Le MJ doit conserver tous les droits.');
assertCodexElementAccess(mapCodexElementCanRead($context(2), 'notes', $note)
    && !mapCodexElementCanRead($context(2), 'notes', $note, true), 'Les notes publiques et privées doivent rester distinctes.');
assertCodexElementAccess(!mapCodexElementCanEdit($context(2), 'notes', $note, true)
    && !mapCodexElementCanCreate($context(2), 'notes', true), 'Modifier la fiche ne doit pas donner de droit sur ses éléments.');

mapCodexSaveElementRule($pdo, 1, 1, 'notes', $note, 0, ['read' => 'deny']);
mapCodexSaveElementRule($pdo, 1, 1, 'notes', $note, 2, ['read' => 'allow', 'edit' => 'allow']);
assertCodexElementAccess(mapCodexElementCanRead($context(2), 'notes', $note, true)
    && mapCodexElementCanEdit($context(2), 'notes', $note, false, true), 'Un droit individuel doit primer sur le droit de groupe.');
assertCodexElementAccess(!mapCodexElementCanRead($context(3), 'notes', $note)
    && !mapCodexElementCanRead($context(3), 'notes', $note, true)
    && mapCodexElementCanRead($context(3), 'notes', $otherNote), 'Un refus doit rester propre à l’élément ciblé.');
assertCodexElementAccess(!mapCodexElementCanRead($context(4, 2, 3), 'notes', $note, true),
    'Un droit ne doit pas se propager à une autre campagne.');
mapCodexSaveElementRule($pdo, 1, 1, 'notes', $otherNote, 0, ['edit' => 'allow']);
assertCodexElementAccess(!mapCodexElementCanEdit($context(2), 'notes', $otherNote, true, true)
    && mapCodexElementCanEdit($context(2), 'notes', $otherNote, false), 'Autoriser une modification ne doit pas dévoiler un élément privé.');

mapCodexSaveElementCreateRule($pdo, 1, 1, 'notes', 0, ['create' => 'allow']);
mapCodexSaveElementCreateRule($pdo, 1, 1, 'notes', 3, ['create' => 'deny']);
assertCodexElementAccess(mapCodexElementCanCreate($context(2), 'notes', false)
    && !mapCodexElementCanCreate($context(3), 'notes', true)
    && !mapCodexElementCanCreate($context(2), 'pouvoirs', true)
    && !mapCodexElementCanCreate($context(2, 1, 2), 'notes', true), 'La création doit respecter le type, la fiche et le destinataire.');
mapCodexSaveElementCreateRule($pdo, 1, 1, 'notes', 3, []);
assertCodexElementAccess(mapCodexElementCanCreate($context(3), 'notes', false), 'Hériter doit retirer l’exception individuelle.');

try {
    mapCodexSaveElementRule($pdo, 1, 1, 'notes', $note, 4, ['read' => 'allow']);
    throw new RuntimeException('Un joueur extérieur a reçu un droit.');
} catch (DomainException) {
}
try {
    mapCodexSaveElementRule($pdo, 1, 1, 'notes', 'invalide', 2, ['read' => 'allow']);
    throw new RuntimeException('Un identifiant invalide a été accepté.');
} catch (DomainException) {
}

$pdo->exec("INSERT INTO campaign_codex_links (campaign_id, entry_id, target_id, label)
    VALUES (1, 1, 2, 'Relation fictive');
    INSERT INTO campaign_codex_links (campaign_id, entry_id, target_id, label, kind)
    VALUES (1, 1, 2, 'Mention fictive', 'mention')");
$relation = $pdo->query("SELECT element_key FROM campaign_codex_links WHERE kind = 'relation'")->fetchColumn();
assertCodexElementAccess(is_string($relation) && preg_match('/^[a-f0-9]{24}$/D', $relation) === 1
    && $pdo->query("SELECT element_key FROM campaign_codex_links WHERE kind = 'mention'")->fetchColumn() === null,
    'Les relations doivent recevoir une clef stable indépendamment des mentions.');
mapCodexSaveElementRule($pdo, 1, 1, 'relations', $relation, 2, ['edit' => 'allow']);
$pdo->exec("DELETE FROM campaign_codex_entries WHERE id = 2");
assertCodexElementAccess(mapCodexElementRules($pdo, 1, 1, 'relations', $relation) === [],
    'La suppression de la cible doit retirer les autorisations de ses anciennes relations.');

mapCodexSaveElementCreateRule($pdo, 1, 1, 'notes', 2, ['create' => 'deny']);
$pdo->exec("DELETE FROM campaign_members WHERE campaign_id = 1 AND user_id = 2");
assertCodexElementAccess(!isset(mapCodexElementRules($pdo, 1, 1, 'notes', $note)[2])
    && !isset(mapCodexElementCreateRules($pdo, 1, 1, 'notes')[2]),
    'Quitter la campagne ne doit laisser aucun droit personnel.');
mapCodexDeleteElementRules($pdo, 1, 1, 'notes', $note);
assertCodexElementAccess(mapCodexElementRules($pdo, 1, 1, 'notes', $note) === [],
    'La suppression d’un élément doit retirer ses règles.');

echo "OK - droits de consultation, de création et de modification indépendants par élément Codex.\n";
