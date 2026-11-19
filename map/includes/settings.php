<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

const MAP_SETTING_ANU_API_KEY = 'dice.anu_api_key';

function mapGetSystemSetting(string $key): ?string
{
    $statement = mapGetDatabase()->prepare(
        'SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1'
    );
    $statement->execute([':setting_key' => $key]);
    $value = $statement->fetchColumn();

    return is_string($value) ? $value : null;
}

function mapSetSystemSetting(string $key, string $value, int $userId): void
{
    if (!preg_match('/^[a-z0-9._-]{1,80}$/', $key)) {
        throw new InvalidArgumentException('Cle de reglage invalide.');
    }

    if ($value === '' || strlen($value) > 4096) {
        throw new DomainException('Valeur de reglage invalide.');
    }

    $statement = mapGetDatabase()->prepare(
        "INSERT INTO system_settings (
            setting_key,
            setting_value,
            updated_by_user_id,
            created_at,
            updated_at
         ) VALUES (
            :setting_key,
            :setting_value,
            :updated_by_user_id,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
         )
         ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            updated_by_user_id = excluded.updated_by_user_id,
            updated_at = CURRENT_TIMESTAMP"
    );
    $statement->execute([
        ':setting_key' => $key,
        ':setting_value' => $value,
        ':updated_by_user_id' => $userId,
    ]);
}

function mapDeleteSystemSetting(string $key): void
{
    $statement = mapGetDatabase()->prepare('DELETE FROM system_settings WHERE setting_key = :setting_key');
    $statement->execute([':setting_key' => $key]);
}

function mapValidateAnuApiKey(string $apiKey): string
{
    $apiKey = trim($apiKey);

    if (strlen($apiKey) < 8 || strlen($apiKey) > 512 || str_contains($apiKey, "\r") || str_contains($apiKey, "\n")) {
        throw new DomainException('La cle API ANU doit contenir entre 8 et 512 caracteres.');
    }

    return $apiKey;
}

function mapStoredAnuApiKey(): ?string
{
    $apiKey = mapGetSystemSetting(MAP_SETTING_ANU_API_KEY);

    return is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
}

function mapSaveAnuApiKey(string $apiKey, int $userId): void
{
    mapSetSystemSetting(MAP_SETTING_ANU_API_KEY, mapValidateAnuApiKey($apiKey), $userId);
}

function mapDeleteStoredAnuApiKey(): void
{
    mapDeleteSystemSetting(MAP_SETTING_ANU_API_KEY);
}

function mapAnuApiKeyConfiguration(): array
{
    $environmentKey = getenv('ATLAS_ANU_QRNG_API_KEY');
    if (is_string($environmentKey) && trim($environmentKey) !== '') {
        return [
            'apiKey' => trim($environmentKey),
            'origin' => 'environment',
        ];
    }

    $storedKey = mapStoredAnuApiKey();

    return [
        'apiKey' => $storedKey,
        'origin' => $storedKey === null ? 'none' : 'database',
    ];
}

function mapMaskSecret(?string $secret): string
{
    if ($secret === null || $secret === '') {
        return 'Non configuree';
    }

    $visibleLength = min(4, strlen($secret));

    return str_repeat('•', 12) . substr($secret, -$visibleLength);
}
