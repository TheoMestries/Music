<?php
declare(strict_types=1);

putenv('ATLAS_JDR_DATABASE_PATH=:memory:');

require_once __DIR__ . '/../includes/notes.php';

function assertNotesTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectNotesException(string $exceptionClass, int $statusCode, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        assertNotesTest($exception instanceof $exceptionClass, $message . ' Mauvais type d exception.');

        if ($exception instanceof MapHttpException) {
            assertNotesTest($exception->getStatusCode() === $statusCode, $message . ' Mauvais statut HTTP.');
        }

        return;
    }

    throw new RuntimeException($message . ' Aucune exception recue.');
}

try {
    $pdo = mapGetDatabase();
    $insertUser = $pdo->prepare(
        'INSERT INTO users (username, display_name, password_hash, role, is_active)
         VALUES (:username, :display_name, :password_hash, :role, 1)'
    );

    foreach ([
        ['owner', 'MJ principal', 'gm'],
        ['secondary-gm', 'MJ secondaire', 'gm'],
        ['player-one', 'Joueuse une', 'player'],
        ['player-two', 'Joueur deux', 'player'],
    ] as [$username, $displayName, $role]) {
        $insertUser->execute([
            ':username' => $username,
            ':display_name' => $displayName,
            ':password_hash' => 'test-only',
            ':role' => $role,
        ]);
    }

    $pdo->exec(
        "INSERT INTO campaigns (name, description, owner_user_id, is_archived, created_at, updated_at)
         VALUES ('Test notes', NULL, 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
    );
    $pdo->exec(
        "INSERT INTO campaign_members (campaign_id, user_id, member_role, created_at, updated_at)
         VALUES (1, 2, 'gm', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (1, 3, 'player', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (1, 4, 'player', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
    );

    $owner = ['user_id' => 1, 'username' => 'owner', 'display_name' => 'MJ principal', 'role' => 'gm'];
    $secondaryGm = ['user_id' => 2, 'username' => 'secondary-gm', 'display_name' => 'MJ secondaire', 'role' => 'gm'];
    $playerOne = ['user_id' => 3, 'username' => 'player-one', 'display_name' => 'Joueuse une', 'role' => 'player'];
    $playerTwo = ['user_id' => 4, 'username' => 'player-two', 'display_name' => 'Joueur deux', 'role' => 'player'];

    $playerPersonal = mapCreateCampaignNote(1, 'Journal personnel', 'Secret joueur', 'Journal', ['session 1', 'Session 1', 'perso'], 'personal', $playerOne);
    $playerShared = mapCreateCampaignNote(1, 'Plan partage', 'Allons au nord', 'Plans', ['groupe'], 'shared', $playerOne);
    $gmNote = mapCreateCampaignNote(1, 'Secret MJ', 'Le roi est un dragon', 'Secrets', ['PNJ'], 'gm', $owner);
    $ownerPersonal = mapCreateCampaignNote(1, 'Preparation personnelle', '', null, [], 'personal', $owner);

    assertNotesTest($playerPersonal['tags'] === ['perso', 'session 1'], 'Les tags doivent etre normalises et dedupliques.');
    assertNotesTest($playerPersonal['folder'] === 'Journal', 'Le dossier doit etre conserve.');

    $ownerNotes = mapListCampaignNotes(1, $owner);
    $secondaryGmNotes = mapListCampaignNotes(1, $secondaryGm);
    $playerOneNotes = mapListCampaignNotes(1, $playerOne);
    $playerTwoNotes = mapListCampaignNotes(1, $playerTwo);

    assertNotesTest(count($ownerNotes) === 3, 'Le proprietaire doit voir ses notes, les notes MJ et partagees, mais pas les notes personnelles des joueurs.');
    assertNotesTest(count($secondaryGmNotes) === 2, 'Un MJ secondaire doit voir les notes MJ et partagees.');
    assertNotesTest(count($playerOneNotes) === 2, 'Un joueur doit voir ses notes personnelles et les notes partagees.');
    assertNotesTest(count($playerTwoNotes) === 1, 'Un autre joueur ne doit voir que les notes partagees.');

    expectNotesException(
        MapHttpException::class,
        404,
        static fn() => mapGetCampaignNote(1, $playerPersonal['id'], $playerTwo),
        'Une note personnelle ne doit pas fuiter vers un autre joueur.'
    );
    expectNotesException(
        MapHttpException::class,
        403,
        static fn() => mapCreateCampaignNote(1, 'Fausse note MJ', '', null, [], 'gm', $playerOne),
        'Un joueur ne doit pas pouvoir creer une note MJ.'
    );
    expectNotesException(
        MapHttpException::class,
        403,
        static fn() => mapUpdateCampaignNote(1, $playerShared['id'], 1, 'Intrusion', '', null, [], 'shared', $playerTwo),
        'Un joueur ne doit pas pouvoir modifier la note partagee d un autre joueur.'
    );

    $gmEditedShared = mapUpdateCampaignNote(
        1,
        $playerShared['id'],
        1,
        'Plan partage relu',
        'Allons au nord demain',
        'Plans',
        ['groupe', 'valide'],
        'shared',
        $secondaryGm
    );
    assertNotesTest($gmEditedShared['revision'] === 2, 'La revision doit avancer apres une sauvegarde.');
    assertNotesTest($gmEditedShared['title'] === 'Plan partage relu', 'Un MJ doit pouvoir corriger une note partagee.');

    expectNotesException(
        MapHttpException::class,
        403,
        static fn() => mapUpdateCampaignNote(1, $playerShared['id'], 2, 'Plan secret', '', null, [], 'gm', $secondaryGm),
        'Seul l auteur doit pouvoir changer la visibilite de sa note.'
    );

    $updatedPersonal = mapUpdateCampaignNote(
        1,
        $playerPersonal['id'],
        1,
        'Journal personnel',
        'Secret joueur complete',
        'Journal',
        ['perso'],
        'personal',
        $playerOne
    );
    assertNotesTest($updatedPersonal['revision'] === 2, 'La note personnelle doit etre sauvegardee en revision 2.');

    try {
        mapUpdateCampaignNote(1, $playerPersonal['id'], 1, 'Version obsolete', '', null, [], 'personal', $playerOne);
        throw new RuntimeException('Une revision obsolete doit produire un conflit.');
    } catch (MapNoteConflictException $exception) {
        assertNotesTest($exception->getLatestNote()['revision'] === 2, 'Le conflit doit renvoyer la derniere revision.');
        assertNotesTest($exception->getLatestNote()['content'] === 'Secret joueur complete', 'Le conflit doit renvoyer le dernier contenu.');
    }

    mapDeleteCampaignNote(1, $gmNote['id'], $secondaryGm);
    expectNotesException(
        MapHttpException::class,
        404,
        static fn() => mapGetCampaignNote(1, $gmNote['id'], $owner),
        'La note supprimee ne doit plus exister.'
    );

    echo "OK - permissions, visibilites, tags, revisions et suppression des notes.\n";
    $pdo = null;
} finally {
    putenv('ATLAS_JDR_DATABASE_PATH');
}
