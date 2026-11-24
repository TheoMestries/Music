<?php
declare(strict_types=1);

require_once __DIR__ . '/campaigns.php';
require_once __DIR__ . '/settings.php';

interface MapUInt16RandomProvider
{
    public function nextUInt16(): int;

    public function sourceCode(): string;

    public function sourceDetail(): string;
}

final class MapRandomProviderException extends RuntimeException
{
}

final class MapSystemRandomProvider implements MapUInt16RandomProvider
{
    public function nextUInt16(): int
    {
        return random_int(0, 65535);
    }

    public function sourceCode(): string
    {
        return 'system_crypto';
    }

    public function sourceDetail(): string
    {
        return 'PHP random_int()';
    }
}

final class MapAnuQuantumRandomProvider implements MapUInt16RandomProvider
{
    private array $buffer = [];

    public function __construct(
        private readonly string $endpoint,
        private readonly ?string $apiKey,
        private readonly string $apiKeyHeader,
        private readonly int $timeoutMilliseconds,
        private readonly int $batchSize = 256,
        private readonly ?Closure $requestOverride = null
    ) {
        $parts = parse_url($this->endpoint);

        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new MapRandomProviderException('Le fournisseur ANU doit utiliser une URL HTTPS valide.');
        }

        if (!preg_match('/^[A-Za-z0-9-]{1,80}$/', $this->apiKeyHeader)) {
            throw new MapRandomProviderException('Le nom de l en-tete API ANU est invalide.');
        }
    }

    public function nextUInt16(): int
    {
        if ($this->buffer === []) {
            $this->refill();
        }

        $value = array_shift($this->buffer);

        if (!is_int($value) || $value < 0 || $value > 65535) {
            throw new MapRandomProviderException('Le fournisseur ANU a renvoye une valeur invalide.');
        }

        return $value;
    }

    public function sourceCode(): string
    {
        return 'anu_quantum';
    }

    public function sourceDetail(): string
    {
        return 'ANU Quantum Numbers uint16';
    }

    private function refill(): void
    {
        $query = http_build_query([
            'length' => max(1, min($this->batchSize, 1024)),
            'type' => 'uint16',
        ], '', '&', PHP_QUERY_RFC3986);
        $url = $this->endpoint . (str_contains($this->endpoint, '?') ? '&' : '?') . $query;
        $headers = ['Accept: application/json'];

        if ($this->apiKey !== null && $this->apiKey !== '') {
            if (str_contains($this->apiKey, "\r") || str_contains($this->apiKey, "\n")) {
                throw new MapRandomProviderException('La cle API ANU est invalide.');
            }
            $headers[] = $this->apiKeyHeader . ': ' . $this->apiKey;
        }

        if ($this->requestOverride instanceof Closure) {
            $response = ($this->requestOverride)($url, $headers, $this->timeoutMilliseconds);
            if (!is_string($response)) {
                throw new MapRandomProviderException('Le client ANU a renvoye une reponse invalide.');
            }
        } else {
            $response = function_exists('curl_init')
                ? $this->requestWithCurl($url, $headers)
                : $this->requestWithStreams($url, $headers);
        }

        if (strlen($response) > 1048576) {
            throw new MapRandomProviderException('La reponse ANU est trop volumineuse.');
        }

        try {
            $payload = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MapRandomProviderException('La reponse ANU n est pas un JSON valide.', 0, $exception);
        }

        if (!is_array($payload) || ($payload['success'] ?? false) !== true || !is_array($payload['data'] ?? null)) {
            throw new MapRandomProviderException('Le fournisseur ANU a refuse la requete.');
        }

        $values = [];
        foreach ($payload['data'] as $value) {
            if (is_int($value)) {
                $integer = $value;
            } elseif (is_string($value) && preg_match('/^[0-9]{1,5}$/', $value)) {
                $integer = (int) $value;
            } else {
                throw new MapRandomProviderException('Le fournisseur ANU a renvoye des donnees invalides.');
            }

            if ($integer < 0 || $integer > 65535) {
                throw new MapRandomProviderException('Le fournisseur ANU a renvoye une valeur hors limites.');
            }

            $values[] = $integer;
        }

        if ($values === []) {
            throw new MapRandomProviderException('Le fournisseur ANU n a renvoye aucune valeur.');
        }

        $this->buffer = $values;
    }

    private function requestWithCurl(string $url, array $headers): string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new MapRandomProviderException('Impossible d initialiser la connexion ANU.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min($this->timeoutMilliseconds, 2000),
            CURLOPT_TIMEOUT_MS => $this->timeoutMilliseconds,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'Atlas-JDR/0.4',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        $caBundle = $this->resolveCaBundle();
        if ($caBundle !== null) {
            $options[CURLOPT_CAINFO] = $caBundle;
        }

        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlErrorNumber = curl_errno($handle);
        $curlError = curl_error($handle);
        curl_close($handle);

        if (!is_string($response) || $curlError !== '') {
            error_log(
                '[map/dice/anu/curl] errno=' . $curlErrorNumber
                . ' status=' . $statusCode
                . ' error=' . ($curlError !== '' ? $curlError : 'empty response')
            );

            if ($curlErrorNumber === CURLE_OPERATION_TIMEDOUT) {
                throw new MapRandomProviderException(
                    'Le service ANU n a pas repondu dans le delai de '
                    . number_format($this->timeoutMilliseconds / 1000, 1, ',', '')
                    . ' secondes.'
                );
            }

            throw new MapRandomProviderException('Impossible de joindre le service ANU.');
        }

        $this->assertSuccessfulStatus($statusCode);

        return $response;
    }

    private function requestWithStreams(string $url, array $headers): string
    {
        $sslOptions = [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ];
        $caBundle = $this->resolveCaBundle();
        if ($caBundle !== null) {
            $sslOptions['cafile'] = $caBundle;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $this->timeoutMilliseconds / 1000,
                'ignore_errors' => true,
            ],
            'ssl' => $sslOptions,
        ]);
        $response = @file_get_contents($url, false, $context);
        $statusCode = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $header, $matches)) {
                $statusCode = (int) $matches[1];
            }
        }

        if (!is_string($response)) {
            throw new MapRandomProviderException('Impossible de joindre le service ANU.');
        }

        $this->assertSuccessfulStatus($statusCode);

        return $response;
    }

    private function assertSuccessfulStatus(int $statusCode): void
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        if ($statusCode === 401 || $statusCode === 403) {
            throw new MapRandomProviderException('La cle API ANU est absente, invalide ou desactivee.');
        }

        if ($statusCode === 429) {
            throw new MapRandomProviderException('Le quota ou la limite de debit ANU est atteint.');
        }

        throw new MapRandomProviderException('Le service ANU est indisponible (HTTP ' . $statusCode . ').');
    }

    private function resolveCaBundle(): ?string
    {
        $mampRoot = dirname(PHP_BINARY, 4);
        $candidates = [
            getenv('ATLAS_CURL_CA_BUNDLE'),
            ini_get('curl.cainfo'),
            ini_get('openssl.cafile'),
            getenv('CURL_CA_BUNDLE'),
            getenv('SSL_CERT_FILE'),
            $mampRoot . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem',
            $mampRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'apache' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'cacert.pem',
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $resolved = realpath(trim($candidate));
            if (is_string($resolved) && is_file($resolved) && is_readable($resolved)) {
                return $resolved;
            }
        }

        return null;
    }
}

function mapValidateDiceMode(string $mode): string
{
    if (!in_array($mode, ['normal', 'advantage', 'disadvantage'], true)) {
        throw new DomainException('Mode de lancer invalide.');
    }

    return $mode;
}

function mapParseDiceExpression(string $expression, string $mode = 'normal'): array
{
    $originalExpression = trim($expression);
    if ($originalExpression === '' || mb_strlen($originalExpression, 'UTF-8') > 100) {
        throw new DomainException('L expression doit contenir entre 1 et 100 caracteres.');
    }

    $mode = mapValidateDiceMode($mode);
    $normalized = strtolower((string) preg_replace('/\s+/u', '', $originalExpression));
    if ($normalized === '') {
        throw new DomainException('L expression de des est vide.');
    }

    if ($normalized[0] !== '+' && $normalized[0] !== '-') {
        $normalized = '+' . $normalized;
    }

    $terms = [];
    $totalDice = 0;
    $remaining = $normalized;

    while ($remaining !== '') {
        if (!preg_match('/^([+-])(?:(\d*)d(\d+|f)(?:(kh|kl)(\d+))?|(\d+))/', $remaining, $matches)) {
            throw new DomainException('Expression de des invalide pres de « ' . mb_substr($remaining, 0, 20, 'UTF-8') . ' ».');
        }

        $token = $matches[0];
        $sign = $matches[1] === '-' ? -1 : 1;
        $isDiceTerm = isset($matches[3]) && $matches[3] !== '';

        if ($isDiceTerm) {
            $count = ($matches[2] ?? '') === '' ? 1 : (int) $matches[2];
            $fudge = $matches[3] === 'f';
            $sides = $fudge ? 3 : (int) $matches[3];
            $keepMode = isset($matches[4]) && $matches[4] !== '' ? $matches[4] : null;
            $keepCount = isset($matches[5]) && $matches[5] !== '' ? (int) $matches[5] : null;

            if ($count < 1) {
                throw new DomainException('Chaque terme doit lancer au moins un dé.');
            }

            if ($sides < 2 || $sides > 1000000) {
                throw new DomainException('Un de doit posseder entre 2 et 1 000 000 faces.');
            }

            if ($keepMode !== null && ($keepCount === null || $keepCount < 1 || $keepCount > $count)) {
                throw new DomainException('Le nombre de des conserves doit etre compris entre 1 et le nombre de des lances.');
            }

            if ($count >= PHP_INT_MAX - $totalDice) throw new DomainException('Quantité de dés hors capacité du serveur.');
            $totalDice += $count;

            $terms[] = [
                'type' => 'dice',
                'sign' => $sign,
                'count' => $count,
                'sides' => $sides,
                'fudge' => $fudge,
                'keepMode' => $keepMode,
                'keepCount' => $keepCount,
            ];
        } else {
            $constant = (int) ($matches[6] ?? 0);
            if ($constant > 1000000) {
                throw new DomainException('Une constante ne peut pas depasser 1 000 000.');
            }

            $terms[] = [
                'type' => 'constant',
                'sign' => $sign,
                'value' => $constant,
            ];
        }

        if (count($terms) > 30) {
            throw new DomainException('Une expression ne peut pas contenir plus de 30 termes.');
        }

        $remaining = substr($remaining, strlen($token));
    }

    if ($totalDice === 0) {
        throw new DomainException('L expression doit contenir au moins un lancer de de.');
    }

    if ($mode !== 'normal') {
        $transformed = false;

        foreach ($terms as &$term) {
            if (
                !$transformed
                && $term['type'] === 'dice'
                && $term['count'] === 1
                && $term['sides'] === 20
                && $term['keepMode'] === null
            ) {
                $term['count'] = 2;
                $term['keepMode'] = $mode === 'advantage' ? 'kh' : 'kl';
                $term['keepCount'] = 1;
                $transformed = true;
            }
        }
        unset($term);

        if (!$transformed) {
            throw new DomainException('L avantage ou le desavantage requiert un terme 1d20 sans modificateur de conservation.');
        }

        $totalDice++;
    }

    return [
        'expression' => $originalExpression,
        'canonicalExpression' => mapCanonicalizeDiceTerms($terms),
        'mode' => $mode,
        'terms' => $terms,
        'totalDice' => $totalDice,
    ];
}

function mapCanonicalizeDiceTerms(array $terms): string
{
    $parts = [];

    foreach ($terms as $index => $term) {
        $sign = (int) $term['sign'] < 0 ? '-' : ($index === 0 ? '' : '+');

        if ($term['type'] === 'dice') {
            $value = $term['count'] . 'd' . (!empty($term['fudge']) ? 'F' : $term['sides']);
            if ($term['keepMode'] !== null) {
                $value .= $term['keepMode'] . $term['keepCount'];
            }
        } else {
            $value = (string) $term['value'];
        }

        $parts[] = $sign . $value;
    }

    return implode('', $parts);
}

function mapUniformDieValue(MapUInt16RandomProvider $provider, int $sides): int
{
    for ($attempt = 0; $attempt < 1000; $attempt++) {
        if ($sides <= 65536) {
            $range = 65536;
            $sample = $provider->nextUInt16();
        } else {
            $range = 4294967296;
            $sample = ($provider->nextUInt16() * 65536) + $provider->nextUInt16();
        }

        $limit = intdiv($range, $sides) * $sides;
        if ($sample < $limit) {
            return ($sample % $sides) + 1;
        }
    }

    throw new MapRandomProviderException('La source aleatoire a produit trop de valeurs rejetees.');
}

function mapEvaluateDiceTerms(array $parsedExpression, MapUInt16RandomProvider $provider): array
{
    $evaluatedTerms = [];
    $total = 0;

    foreach ($parsedExpression['terms'] as $term) {
        if ($term['type'] === 'constant') {
            $signedValue = $term['sign'] * $term['value'];
            $evaluatedTerms[] = [
                'type' => 'constant',
                'sign' => $term['sign'],
                'value' => $term['value'],
                'signedSubtotal' => $signedValue,
            ];
            $total += $signedValue;
            continue;
        }

        $values = [];
        for ($index = 0; $index < $term['count']; $index++) {
            $values[] = mapUniformDieValue($provider, $term['sides']) - (!empty($term['fudge']) ? 2 : 0);
        }

        $keptIndexes = array_keys($values);
        if ($term['keepMode'] !== null) {
            usort($keptIndexes, static function (int $indexA, int $indexB) use ($values, $term): int {
                $comparison = $values[$indexA] <=> $values[$indexB];
                if ($term['keepMode'] === 'kh') {
                    $comparison *= -1;
                }

                return $comparison !== 0 ? $comparison : $indexA <=> $indexB;
            });
            $keptIndexes = array_slice($keptIndexes, 0, $term['keepCount']);
        }
        $keptLookup = array_fill_keys($keptIndexes, true);
        $rolls = [];
        $subtotal = 0;

        foreach ($values as $index => $value) {
            $kept = isset($keptLookup[$index]);
            $rolls[] = ['value' => $value, 'kept' => $kept];
            if ($kept) $subtotal += $value;
        }

        $signedSubtotal = $term['sign'] * $subtotal;
        $evaluatedTerms[] = [
            'type' => 'dice',
            'sign' => $term['sign'],
            'count' => $term['count'],
            'sides' => $term['sides'],
            'fudge' => !empty($term['fudge']),
            'keepMode' => $term['keepMode'],
            'keepCount' => $term['keepCount'],
            'rolls' => $rolls,
            'subtotal' => $subtotal,
            'signedSubtotal' => $signedSubtotal,
        ];
        $total += $signedSubtotal;
    }

    return [
        'canonicalExpression' => $parsedExpression['canonicalExpression'],
        'mode' => $parsedExpression['mode'],
        'terms' => $evaluatedTerms,
        'total' => $total,
    ];
}

function mapCreateAnuRandomProvider(?string $apiKeyOverride = null): MapAnuQuantumRandomProvider
{
    $endpoint = getenv('ATLAS_ANU_QRNG_URL');
    $endpoint = is_string($endpoint) && trim($endpoint) !== ''
        ? trim($endpoint)
        : 'https://api.quantumnumbers.anu.edu.au';
    $apiKeyConfiguration = mapAnuApiKeyConfiguration();
    try {
        $apiKey = $apiKeyOverride !== null
            ? mapValidateAnuApiKey($apiKeyOverride)
            : $apiKeyConfiguration['apiKey'];
    } catch (DomainException $exception) {
        throw new MapRandomProviderException($exception->getMessage(), 0, $exception);
    }

    if (!is_string($apiKey) || $apiKey === '') {
        throw new MapRandomProviderException('La cle API ANU n est pas configuree.');
    }

    $apiKeyHeader = getenv('ATLAS_ANU_QRNG_API_KEY_HEADER');
    $apiKeyHeader = is_string($apiKeyHeader) && trim($apiKeyHeader) !== '' ? trim($apiKeyHeader) : 'x-api-key';
    $timeout = getenv('ATLAS_ANU_QRNG_TIMEOUT_MS');
    $timeout = is_string($timeout) && ctype_digit($timeout) ? (int) $timeout : 4000;
    $timeout = max(500, min($timeout, 5000));

    return new MapAnuQuantumRandomProvider($endpoint, $apiKey, $apiKeyHeader, $timeout);
}

function mapTestAnuRandomProvider(?string $apiKeyOverride = null): int
{
    return mapCreateAnuRandomProvider($apiKeyOverride)->nextUInt16();
}

function mapGenerateDiceOutcome(array $parsedExpression): array
{
    // Every die is retained in the result. Check the available memory before generating it.
    $memoryLimit = ini_parse_quantity((string) ini_get('memory_limit'));
    if ($memoryLimit > 0 && $parsedExpression['totalDice'] > max(0, $memoryLimit - memory_get_usage(true)) / 1024) {
        throw new DomainException('Ce jet dépasse la mémoire disponible sur le serveur.');
    }
    $preference = strtolower(trim((string) (getenv('ATLAS_DICE_RANDOM_SOURCE') ?: 'anu')));
    $systemProvider = new MapSystemRandomProvider();

    if ($preference === 'system') {
        return [
            'result' => mapEvaluateDiceTerms($parsedExpression, $systemProvider),
            'randomSource' => $systemProvider->sourceCode(),
            'randomSourceDetail' => $systemProvider->sourceDetail(),
            'fallbackReason' => null,
        ];
    }

    if ($preference !== 'anu') {
        return [
            'result' => mapEvaluateDiceTerms($parsedExpression, $systemProvider),
            'randomSource' => $systemProvider->sourceCode(),
            'randomSourceDetail' => $systemProvider->sourceDetail(),
            'fallbackReason' => 'Configuration de source inconnue ; secours cryptographique utilise.',
        ];
    }

    try {
        $anuProvider = mapCreateAnuRandomProvider();

        return [
            'result' => mapEvaluateDiceTerms($parsedExpression, $anuProvider),
            'randomSource' => $anuProvider->sourceCode(),
            'randomSourceDetail' => $anuProvider->sourceDetail(),
            'fallbackReason' => null,
        ];
    } catch (MapRandomProviderException $exception) {
        error_log('[map/dice/anu] ' . $exception->getMessage());

        return [
            'result' => mapEvaluateDiceTerms($parsedExpression, $systemProvider),
            'randomSource' => $systemProvider->sourceCode(),
            'randomSourceDetail' => $systemProvider->sourceDetail(),
            'fallbackReason' => 'ANU indisponible : ' . $exception->getMessage()
                . ' Le lancer a ete recommence avec le secours cryptographique.',
        ];
    }
}

function mapPublicDiceRollData(array $row): array
{
    try {
        $result = json_decode((string) $row['result_json'], true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Un lancer enregistre contient un resultat invalide.', 0, $exception);
    }

    return [
        'id' => (int) $row['id'],
        'campaignId' => (int) $row['campaign_id'],
        'expression' => (string) $row['expression'],
        'canonicalExpression' => (string) $row['canonical_expression'],
        'mode' => (string) $row['roll_mode'],
        'visibility' => (string) ($row['visibility'] ?? 'public'),
        'total' => (int) $row['total'],
        'result' => $result,
        'randomSource' => (string) $row['random_source'],
        'randomSourceDetail' => (string) $row['random_source_detail'],
        'fallbackReason' => $row['fallback_reason'] !== null ? (string) $row['fallback_reason'] : null,
        'createdAt' => (string) $row['created_at'],
        'roller' => [
            'id' => (int) $row['roller_user_id'],
            'username' => (string) $row['roller_username'],
            'displayName' => (string) $row['roller_display_name'],
        ],
    ];
}

function mapListCampaignDiceRolls(
    int $campaignId,
    array $currentUser,
    int $limit = 50,
    bool $ownOnly = false,
    bool $compact = false,
    ?int $rollId = null
): array
{
    $access = mapRequireCampaignAccess($campaignId, $currentUser);
    $limit = max(1, min($limit, 100));
    $pdo = mapGetDatabase();
    // Keep ordinary rolls inline. Large results remain available by ID.
    $resultColumn = $compact
        ? "CASE WHEN length(r.result_json) <= 4096 THEN r.result_json
             ELSE json_object('success', json_extract(r.result_json, '$.success')) END"
        : 'r.result_json';
    $rollFilter = $rollId === null ? '' : ' AND r.id = :roll_id';
    $statement = $pdo->prepare(
        'SELECT r.id,
                r.campaign_id,
                r.roller_user_id,
                r.expression,
                r.canonical_expression,
                r.roll_mode,
                r.visibility,
                r.total,
                ' . $resultColumn . ' AS result_json,
                r.random_source,
                r.random_source_detail,
                r.fallback_reason,
                r.created_at,
                roller.username AS roller_username,
                roller.display_name AS roller_display_name
         FROM dice_rolls AS r
         INNER JOIN users AS roller ON roller.id = r.roller_user_id
         WHERE r.campaign_id = :campaign_id
           AND (r.roller_user_id = :viewer OR (:own_only = 0 AND (r.visibility = \'public\' OR (r.visibility = \'gm\' AND :gm = 1))))
         ' . $rollFilter . '
         ORDER BY r.created_at DESC, r.id DESC
         LIMIT :limit'
    );
    $statement->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
    $statement->bindValue(':viewer', (int) $currentUser['user_id'], PDO::PARAM_INT);
    $statement->bindValue(':own_only', (int) $ownOnly, PDO::PARAM_INT);
    $statement->bindValue(':gm', in_array($access['current_user_role'], ['admin','owner','gm'], true) ? 1 : 0, PDO::PARAM_INT);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    if ($rollId !== null) $statement->bindValue(':roll_id', $rollId, PDO::PARAM_INT);
    $statement->execute();

    return array_map('mapPublicDiceRollData', $statement->fetchAll());
}

function mapGetCampaignDiceRoll(int $campaignId, int $rollId, array $currentUser): array
{
    $roll = mapListCampaignDiceRolls($campaignId, $currentUser, 1, rollId: $rollId)[0] ?? null;
    if ($roll === null) mapThrowNotFound('Jet de dés introuvable.');
    return $roll;
}

function mapCreateCampaignDiceRoll(
    int $campaignId,
    string $expression,
    string $mode,
    array $currentUser,
    string $visibility = 'public',
    ?array $success = null
): array {
    mapRequireCampaignAccess($campaignId, $currentUser);
    if (!in_array($visibility, ['public','gm','secret'], true)) throw new DomainException('Visibilité du jet invalide.');
    if ($success !== null && (!in_array($success['operator'] ?? null, ['>','>=','=','<=','<'], true) ||
        !is_int($success['target'] ?? null) || abs($success['target']) > 1000000)) {
        throw new DomainException('Seuil de réussite invalide.');
    }
    $parsedExpression = mapParseDiceExpression($expression, $mode);
    mapTableTimingMark('dice_validation');
    $outcome = mapGenerateDiceOutcome($parsedExpression);
    mapTableTimingMark('dice_randomness');
    $result = $outcome['result'];
    if ($success !== null) {
        $count = 0;
        foreach ($result['terms'] as $term) {
            foreach ($term['rolls'] ?? [] as $die) {
                if (!$die['kept']) continue;
                $value = $die['value'];
                $target = $success['target'];
                $count += (int) match ($success['operator']) {
                    '>' => $value > $target, '>=' => $value >= $target, '=' => $value === $target,
                    '<=' => $value <= $target, '<' => $value < $target,
                };
            }
        }
        $result['success'] = [...$success, 'count'=>$count];
    }

    try {
        $resultJson = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new RuntimeException('Impossible d enregistrer le resultat du lancer.', 0, $exception);
    }

    $pdo = mapGetDatabase();

    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            "INSERT INTO dice_rolls (
                campaign_id,
                roller_user_id,
                expression,
                canonical_expression,
                roll_mode,
                visibility,
                total,
                result_json,
                random_source,
                random_source_detail,
                fallback_reason,
                created_at
             ) VALUES (
                :campaign_id,
                :roller_user_id,
                :expression,
                :canonical_expression,
                :roll_mode,
                :visibility,
                :total,
                :result_json,
                :random_source,
                :random_source_detail,
                :fallback_reason,
                strftime('%Y-%m-%d %H:%M:%f', 'now')
             )"
        );
        $statement->execute([
            ':campaign_id' => $campaignId,
            ':roller_user_id' => (int) $currentUser['user_id'],
            ':expression' => $parsedExpression['expression'],
            ':canonical_expression' => $result['canonicalExpression'],
            ':roll_mode' => $result['mode'],
            ':visibility' => $visibility,
            ':total' => $result['total'],
            ':result_json' => $resultJson,
            ':random_source' => $outcome['randomSource'],
            ':random_source_detail' => $outcome['randomSourceDetail'],
            ':fallback_reason' => $outcome['fallbackReason'],
        ]);
        $rollId = (int) $pdo->lastInsertId();
        mapTouchCampaign($pdo, $campaignId);
        $pdo->commit();
        mapTableTimingMark('dice_write');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    return mapGetCampaignDiceRoll($campaignId, $rollId, $currentUser);
}
