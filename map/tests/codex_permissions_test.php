<?php
declare(strict_types=1);

putenv('ATLAS_JDR_DATABASE_PATH=:memory:');
require_once __DIR__ . '/../includes/codex-permissions.php';

function assertCodexPermissionTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = mapGetDatabase();
$pdo->exec("INSERT INTO users (username, display_name, password_hash, role)
    VALUES ('orga', 'MJ de test', 'test', 'gm'), ('alex', 'Alex', 'test', 'player'),
        ('nina', 'Nina', 'test', 'player'), ('lee', 'Lee', 'test', 'player')");
$pdo->exec("INSERT INTO campaigns (name, owner_user_id) VALUES ('Univers fictif', 1), ('Autre univers', 1);
    INSERT INTO campaign_members (campaign_id, user_id, member_role) VALUES (1, 2, 'player'), (1, 3, 'player'), (2, 4, 'player')");
$newEntry = static function (int $campaignId, string $category, string $title) use ($pdo): int {
    $pdo->prepare('INSERT INTO campaign_codex_entries (campaign_id, category, title) VALUES (?, ?, ?)')
        ->execute([$campaignId, $category, $title]);
    return (int) $pdo->lastInsertId();
};
$profile = $newEntry(1, 'Personnages', 'Personnage fictif');
$location = $newEntry(1, 'Lieux', 'Lieu fictif');
$secondLocation = $newEntry(2, 'Lieux', 'Lieu d’une autre campagne');
$access = static fn (int $id, int $campaignId = 1): array => mapCodexPermissionContext($pdo, $campaignId,
    mapRequireCampaignAccess($campaignId, ['user_id' => $id]), $id);

assertCodexPermissionTest(mapCodexCanManage($access(1)) && mapCodexCanRead($access(1), 'Lieux', $location), 'Le MJ doit conserver tous les droits.');
assertCodexPermissionTest(!mapCodexCanOpen($access(2)) && !mapCodexCanRead($access(2), 'Personnages', $profile), 'Une campagne existante doit rester privée tant que le MJ ne partage rien.');

mapCodexSaveModuleRule($pdo, 1, 'Personnages', 0, ['edit' => 'allow']);
assertCodexPermissionTest(mapCodexCanRead($access(2), 'Personnages', $profile) && mapCodexCanEdit($access(2), 'Personnages', $profile), 'Une autorisation de modifier implique celle de consulter.');
assertCodexPermissionTest(!mapCodexCanRead($access(2), 'Personnages', $profile, true)
    && !mapCodexCanEdit($access(2), 'Personnages', $profile, true), 'Les droits du module ne doivent pas ouvrir une fiche privée importée.');
assertCodexPermissionTest(!mapCodexCanCreate($access(2), 'Personnages'), 'Modifier ne doit pas autoriser à créer.');
mapCodexSaveModuleRule($pdo, 1, 'Personnages', 2, ['read' => 'deny']);
assertCodexPermissionTest(!mapCodexCanRead($access(2), 'Personnages', $profile) && !mapCodexCanEdit($access(2), 'Personnages', $profile), 'Un refus individuel de lecture doit interdire aussi la modification.');
assertCodexPermissionTest(mapCodexCanEdit($access(3), 'Personnages', $profile), 'Un refus individuel ne doit pas restreindre les autres joueurs.');

mapCodexSaveEntryRule($pdo, 1, $profile, 0, ['read' => 'allow', 'edit' => 'deny']);
assertCodexPermissionTest(mapCodexCanRead($access(2), 'Personnages', $profile) && !mapCodexCanEdit($access(2), 'Personnages', $profile), 'La règle de fiche prime sur celle du module.');
mapCodexSaveEntryRule($pdo, 1, $profile, 2, ['edit' => 'allow']);
assertCodexPermissionTest(mapCodexCanEdit($access(2), 'Personnages', $profile) && !mapCodexCanEdit($access(3), 'Personnages', $profile), 'La règle individuelle de fiche peut modifier les droits de la fiche pour un joueur.');
assertCodexPermissionTest(mapCodexCanEdit($access(2), 'Personnages', $profile, true), 'Une fiche privée doit pouvoir être partagée explicitement.');
mapCodexSaveEntryRule($pdo, 1, $profile, 2, []);
assertCodexPermissionTest(!mapCodexCanEdit($access(2), 'Personnages', $profile) && !isset(mapCodexEntryRules($pdo, 1, $profile)[2]), 'L’héritage complet doit retirer l’exception individuelle.');

mapCodexSaveEntryRule($pdo, 1, $location, 2, ['read' => 'allow']);
assertCodexPermissionTest(mapCodexCanOpen($access(2)) && mapCodexCanRead($access(2), 'Lieux', $location)
    && !mapCodexCanRead($access(2), 'Lieux'), 'Une fiche peut être ouverte indépendamment du reste de son module.');
assertCodexPermissionTest(!mapCodexCanRead($access(4, 2), 'Lieux', $secondLocation), 'Les règles restent isolées dans leur campagne.');

mapCodexSaveModuleRule($pdo, 1, 'Quêtes', 2, ['create' => 'allow']);
assertCodexPermissionTest(mapCodexCanCreate($access(2), 'Quêtes') && !mapCodexCanRead($access(2), 'Quêtes'), 'La création peut être autorisée sans accès aux autres fiches du module.');
$quest = $newEntry(1, 'Quêtes', 'Quête fictive');
mapCodexGrantCreatorAccess($pdo, 1, $quest, 2, $access(2), 'Quêtes');
assertCodexPermissionTest(mapCodexCanRead($access(2), 'Quêtes', $quest) && !mapCodexCanEdit($access(2), 'Quêtes', $quest)
    && !mapCodexCanRead($access(3), 'Quêtes', $quest), 'Une création est consultable par son auteur et ne devient pas publique.');
mapCodexSaveEntryRule($pdo, 1, $quest, 2, ['read' => 'deny']);
assertCodexPermissionTest(!mapCodexCanRead($access(2), 'Quêtes', $quest), 'Le MJ peut révoquer le droit accordé à l’auteur.');

try {
    mapCodexSaveModuleRule($pdo, 1, 'Lieux', 4, ['read' => 'allow']);
    throw new RuntimeException('Un utilisateur externe à la campagne a reçu un droit.');
} catch (DomainException) {
}

$pdo->exec("DELETE FROM campaign_members WHERE campaign_id = 1 AND user_id = 2;
    INSERT INTO campaign_members (campaign_id, user_id, member_role) VALUES (1, 2, 'player')");
assertCodexPermissionTest(!isset(mapCodexEntryRules($pdo, 1, $location)[2]) && !isset(mapCodexModuleRules($pdo, 1)['Quêtes'][2]),
    'Les règles individuelles doivent disparaître quand le joueur quitte la campagne.');
assertCodexPermissionTest(!mapCodexCanRead($access(2), 'Lieux', $location), 'Une réinscription ne doit pas restaurer d’anciens droits individuels.');

echo "OK - droits Codex par module, par fiche et par joueur.\n";
