<?php
declare(strict_types=1);

putenv('ATLAS_JDR_DATABASE_PATH=:memory:');
putenv('ATLAS_DICE_RANDOM_SOURCE=system');
putenv('ATLAS_ANU_QRNG_API_KEY');

require_once __DIR__ . '/../includes/dice.php';

final class MapSequenceRandomProvider implements MapUInt16RandomProvider
{
    public int $consumed = 0;

    public function __construct(private array $values)
    {
    }

    public function nextUInt16(): int
    {
        if ($this->values === []) {
            throw new RuntimeException('La sequence de test est epuisee.');
        }

        $this->consumed++;

        return (int) array_shift($this->values);
    }

    public function sourceCode(): string
    {
        return 'system_crypto';
    }

    public function sourceDetail(): string
    {
        return 'Sequence de test';
    }
}

function assertDiceTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectDiceDomainException(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (DomainException) {
        return;
    }

    throw new RuntimeException($message);
}

try {
    $requestedUrl = null;
    $requestedHeaders = [];
    $anuProvider = new MapAnuQuantumRandomProvider(
        'https://api.quantumnumbers.anu.edu.au',
        'test-api-key-1234',
        'x-api-key',
        1000,
        3,
        static function (string $url, array $headers) use (&$requestedUrl, &$requestedHeaders): string {
            $requestedUrl = $url;
            $requestedHeaders = $headers;

            return '{"success":true,"type":"uint16","length":3,"data":[42,65535,0]}';
        }
    );
    assertDiceTest($anuProvider->nextUInt16() === 42, 'Le fournisseur ANU doit lire les entiers uint16 recus.');
    assertDiceTest(is_string($requestedUrl) && str_contains($requestedUrl, 'length=3&type=uint16'), 'La requete ANU moderne est incorrecte.');
    assertDiceTest(in_array('x-api-key: test-api-key-1234', $requestedHeaders, true), 'La cle API ANU doit etre envoyee dans l en-tete officiel.');

    $simple = mapParseDiceExpression('1d20 + 5');
    assertDiceTest($simple['canonicalExpression'] === '1d20+5', 'La forme canonique de 1d20+5 est incorrecte.');
    $simpleResult = mapEvaluateDiceTerms($simple, new MapSequenceRandomProvider([9]));
    assertDiceTest($simpleResult['total'] === 15, 'Le resultat de 1d20+5 est incorrect.');

    $characteristic = mapParseDiceExpression('4d6kh3');
    $characteristicResult = mapEvaluateDiceTerms($characteristic, new MapSequenceRandomProvider([0, 1, 2, 3]));
    assertDiceTest($characteristicResult['total'] === 9, '4d6kh3 doit conserver les trois meilleurs des.');
    assertDiceTest($characteristicResult['terms'][0]['rolls'][0]['kept'] === false, 'Le plus petit de doit etre ecarte par kh3.');

    $advantage = mapParseDiceExpression('1d20+5', 'advantage');
    assertDiceTest($advantage['canonicalExpression'] === '2d20kh1+5', 'L avantage doit devenir 2d20kh1.');
    $advantageResult = mapEvaluateDiceTerms($advantage, new MapSequenceRandomProvider([2, 18]));
    assertDiceTest($advantageResult['total'] === 24, 'L avantage doit conserver le meilleur d20.');

    $disadvantage = mapParseDiceExpression('d20+2', 'disadvantage');
    assertDiceTest($disadvantage['canonicalExpression'] === '2d20kl1+2', 'Le desavantage doit devenir 2d20kl1.');
    $disadvantageResult = mapEvaluateDiceTerms($disadvantage, new MapSequenceRandomProvider([19, 4]));
    assertDiceTest($disadvantageResult['total'] === 7, 'Le desavantage doit conserver le plus petit d20.');

    $rejectionProvider = new MapSequenceRandomProvider([65535, 0]);
    $unbiasedValue = mapUniformDieValue($rejectionProvider, 6);
    assertDiceTest($unbiasedValue === 1, 'La valeur acceptee apres rejet doit etre convertie en 1.');
    assertDiceTest($rejectionProvider->consumed === 2, 'La valeur 65535 doit etre rejetee pour un d6 afin d eviter le biais modulo.');

    expectDiceDomainException(
        static fn() => mapParseDiceExpression('4d6kh5'),
        'Il est interdit de conserver plus de des qu il n en est lance.'
    );
    assertDiceTest(mapParseDiceExpression('201d6')['totalDice'] === 201, 'Un terme accepte plus de 200 dés.');
    expectDiceDomainException(
        static fn() => mapParseDiceExpression('1d20;DROP TABLE users'),
        'Le parseur doit refuser tout texte hors grammaire.'
    );
    expectDiceDomainException(
        static fn() => mapParseDiceExpression('2d20kh1', 'advantage'),
        'Le mode avantage ne doit pas etre applique deux fois.'
    );
    assertDiceTest(mapParseDiceExpression('1d20+100d6+99d8', 'advantage')['totalDice'] === 201, 'L’avantage accepte plus de 200 dés au total.');

    $pdo = mapGetDatabase();
    $pdo->exec(
        "INSERT INTO users (username, display_name, password_hash, role, is_active)
         VALUES ('gm', 'MJ', 'test-only', 'gm', 1),
                ('player', 'Joueur', 'test-only', 'player', 1),
                ('outsider', 'Exterieur', 'test-only', 'player', 1)"
    );
    $pdo->exec(
        "INSERT INTO campaigns (name, description, owner_user_id, is_archived, created_at, updated_at)
         VALUES ('Campagne des', NULL, 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
    );
    $pdo->exec(
        "INSERT INTO campaign_members (campaign_id, user_id, member_role, created_at, updated_at)
         VALUES (1, 2, 'player', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
    );

    $gm = ['user_id' => 1, 'username' => 'gm', 'display_name' => 'MJ', 'role' => 'gm'];
    $player = ['user_id' => 2, 'username' => 'player', 'display_name' => 'Joueur', 'role' => 'player'];
    $outsider = ['user_id' => 3, 'username' => 'outsider', 'display_name' => 'Exterieur', 'role' => 'player'];

    mapSaveAnuApiKey('stored-test-key-1234', 1);
    $storedAnuConfiguration = mapAnuApiKeyConfiguration();
    assertDiceTest($storedAnuConfiguration['origin'] === 'database', 'La cle ANU doit pouvoir etre configuree dans SQLite.');
    assertDiceTest($storedAnuConfiguration['apiKey'] === 'stored-test-key-1234', 'La cle ANU enregistree doit etre relue sans alteration.');
    mapDeleteStoredAnuApiKey();
    assertDiceTest(mapAnuApiKeyConfiguration()['origin'] === 'none', 'La cle ANU enregistree doit pouvoir etre supprimee.');

    $storedRoll = mapCreateCampaignDiceRoll(1, '178d100+4', 'normal', $player);
    assertDiceTest(count($storedRoll['result']['terms'][0]['rolls']) === 178, 'Les 178 dés sont lancés et enregistrés.');
    assertDiceTest($storedRoll['roller']['id'] === 2, 'Le serveur doit enregistrer l utilisateur de session comme lanceur.');
    assertDiceTest($storedRoll['randomSource'] === 'system_crypto', 'La source systeme configuree doit etre journalisee.');
    assertDiceTest($storedRoll['total'] === $storedRoll['result']['total'], 'Le total journalise doit correspondre au detail serveur.');
    assertDiceTest(count(mapListCampaignDiceRolls(1, $gm)) === 1, 'Le MJ doit voir l historique de la campagne.');
    assertDiceTest(count(mapListCampaignDiceRolls(1, $player)) === 1, 'Le joueur doit voir l historique de la campagne.');

    $fudge = mapEvaluateDiceTerms(mapParseDiceExpression('3dF'), new MapSequenceRandomProvider([0, 1, 2]));
    assertDiceTest(array_column($fudge['terms'][0]['rolls'], 'value') === [-1, 0, 1], 'Les trois faces Fudge sont correctes.');
    $secret = mapCreateCampaignDiceRoll(1, '1d6', 'normal', $player, 'secret', ['operator'=>'>=', 'target'=>1]);
    $gmRoll = mapCreateCampaignDiceRoll(1, '1d6', 'normal', $gm, 'gm');
    $playerGmRoll = mapCreateCampaignDiceRoll(1, '1d6', 'normal', $player, 'gm');
    $gmIds = array_column(mapListCampaignDiceRolls(1, $gm), 'id');
    $playerIds = array_column(mapListCampaignDiceRolls(1, $player), 'id');
    assertDiceTest($secret['result']['success']['count'] === 1, 'Le seuil compte les dés retenus.');
    assertDiceTest(!in_array($secret['id'], $gmIds, true) && in_array($secret['id'], $playerIds, true), 'Un jet secret appartient à son auteur.');
    assertDiceTest(!in_array($gmRoll['id'], $playerIds, true) && in_array($playerGmRoll['id'], $gmIds, true), 'Les jets MJ sont réservés aux MJ et à leur auteur.');
    assertDiceTest(count(mapListCampaignDiceRolls(1, $player, 10, true)) === 3, 'L historique personnel contient uniquement les jets de son auteur.');

    $large = mapCreateCampaignDiceRoll(1, '10000d6', 'normal', $player, 'secret', ['operator'=>'>=', 'target'=>4]);
    $compactRolls = array_column(mapListCampaignDiceRolls(1, $player, compact: true), null, 'id');
    $summary = $compactRolls[$large['id']];
    assertDiceTest(!isset($summary['result']['terms']) && $summary['total'] === $large['total'], 'Le gros jet est résumé avec son total.');
    assertDiceTest($summary['result']['success'] === $large['result']['success'], 'Le résumé conserve le seuil et le nombre de réussites.');
    assertDiceTest(isset($compactRolls[$secret['id']]['result']['terms']), 'Les petits jets gardent leurs valeurs dans le chat.');
    assertDiceTest(strlen(json_encode($summary)) < strlen(json_encode($large)) / 100, 'Le résumé réduit le volume du gros jet.');
    assertDiceTest(mapGetCampaignDiceRoll(1, $large['id'], $player) === $large, 'Le détail restitue toutes les valeurs au lanceur.');
    try {
        mapGetCampaignDiceRoll(1, $large['id'], $gm);
        throw new RuntimeException('Le MJ ne doit pas obtenir le détail d’un jet secret.');
    } catch (MapHttpException $exception) {
        assertDiceTest($exception->getStatusCode() === 404, 'Le détail ne révèle pas un jet secret du joueur.');
    }

    try {
        mapListCampaignDiceRolls(1, $outsider);
        throw new RuntimeException('Un utilisateur externe ne doit pas voir l historique.');
    } catch (MapHttpException $exception) {
        assertDiceTest($exception->getStatusCode() === 403, 'L historique externe doit etre interdit avec un statut 403.');
    }

    putenv('ATLAS_DICE_RANDOM_SOURCE=anu');
    putenv('ATLAS_ANU_QRNG_API_KEY=test-invalid-endpoint-key');
    putenv('ATLAS_ANU_QRNG_URL=http://invalid.local/qrng');
    $fallbackRoll = mapCreateCampaignDiceRoll(1, '1d6', 'normal', $gm);
    assertDiceTest($fallbackRoll['randomSource'] === 'system_crypto', 'Une configuration ANU invalide doit utiliser le secours cryptographique.');
    assertDiceTest($fallbackRoll['fallbackReason'] !== null, 'Le recours au secours doit etre visible dans le journal.');

    echo "OK - parseur, avantage, conservation, rejet sans biais, journal et secours des des.\n";
} finally {
    putenv('ATLAS_JDR_DATABASE_PATH');
    putenv('ATLAS_DICE_RANDOM_SOURCE');
    putenv('ATLAS_ANU_QRNG_URL');
    putenv('ATLAS_ANU_QRNG_API_KEY');
}
