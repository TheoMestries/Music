<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/storage.php';

const MUSIC_PROJECT_FILE = MUSIC_DATA_DIR . '/music-projects.json';
const MUSIC_LEGACY_USERS_FILE = MUSIC_DATA_DIR . '/users.json';

function musicMigrateLegacyAdmin(array &$library): void
{
    if ($library['legacyAdminMigrated'] ?? false) {
        return;
    }

    $legacyAdmin = musicLegacyAdmin(musicReadJson(MUSIC_LEGACY_USERS_FILE));
    $admin = $legacyAdmin === null ? null : mapFindUserByUsername($legacyAdmin['username']);
    if ($admin === null || $admin['role'] !== 'admin' || (int) $admin['is_active'] !== 1) {
        $adminId = (int) mapGetDatabase()->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    } else {
        $adminId = (int) $admin['id'];
    }
    if ($adminId === 0) {
        return;
    }

    if ($legacyAdmin !== null) {
        musicMoveLegacyAccount($library, $legacyAdmin, $adminId);
    }
    if (!($library['legacyImported'] ?? false)) {
        foreach (musicReadJson(MUSIC_DATA_DIR . '/campaigns.json') as $campaign) {
            $library['projects'][] = ['owner' => $adminId, 'campaign' => $campaign];
        }
        $library['legacyImported'] = true;
    }
    $library['legacyAdminMigrated'] = true;
}

function musicLegacyAdmin(array $users): ?array
{
    $firstLegacy = $namedAdmin = null;
    foreach ($users as $user) {
        if (($user['role'] ?? null) === 'admin') {
            return $user;
        }
        if (!isset($user['role'])) {
            $firstLegacy ??= $user;
            if (strcasecmp($user['username'], 'admin') === 0) {
                $namedAdmin ??= $user;
            }
        }
    }
    return $namedAdmin ?? $firstLegacy;
}

function musicMoveLegacyAccount(array &$library, array $account, int $userId): void
{
    $username = $account['username'];
    foreach (array_keys($library['projects'] ?? []) as $index) {
        if ($library['projects'][$index]['owner'] === $username) {
            $library['projects'][$index]['owner'] = $userId;
        }
    }
    foreach ($library['pending'] ?? [] as $kind => $files) {
        foreach ($files as $file => $owner) {
            if ($owner === $username) {
                $library['pending'][$kind][$file] = $userId;
            }
        }
    }

    $legacyRoom = $account['room'] ?? null;
    if (musicValidRoom($legacyRoom)) {
        $token = $library['rooms'][$userId]['token'] ?? null;
        if ($token === null) {
            $library['rooms'][$userId] = ['token' => $legacyRoom, 'aliases' => []];
        } elseif ($token !== $legacyRoom) {
            $library['rooms'][$userId]['aliases'][] = $legacyRoom;
        }
    }
    $library['legacyClaims'][$username] = $userId;
}

function musicHasLegacyAccountsToClaim(): bool
{
    $library = musicReadJson(MUSIC_PROJECT_FILE);
    foreach (musicReadJson(MUSIC_LEGACY_USERS_FILE) as $account) {
        if (!isset($library['legacyClaims'][$account['username']])) {
            return true;
        }
    }
    return false;
}

function musicClaimLegacyAccount(int $userId, string $username, string $password): void
{
    $account = null;
    foreach (musicReadJson(MUSIC_LEGACY_USERS_FILE) as $candidate) {
        if (strcasecmp($candidate['username'], $username) === 0) {
            $account = $candidate;
            break;
        }
    }
    if ($account === null || !password_verify($password, (string) ($account['passwordHash'] ?? ''))) {
        throw new DomainException('Identifiant ou mot de passe de l’ancien compte incorrect.');
    }

    musicCampaignTransaction(static function (array &$library) use ($account, $userId): void {
        $claim = $library['legacyClaims'][$account['username']] ?? null;
        if ($claim === $userId) {
            return;
        }
        if ($claim !== null) {
            throw new DomainException('Cet ancien compte est déjà rattaché à un compte Sus20.');
        }
        musicMoveLegacyAccount($library, $account, $userId);
    });
}

function musicValidRoom(mixed $room): bool
{
    return is_string($room) && preg_match('/\A[a-f0-9]{32}\z/', $room) === 1;
}

function musicRoomForUser(int $userId): string
{
    $existingRoom = musicReadJson(MUSIC_PROJECT_FILE)['rooms'][$userId]['token'] ?? null;
    if ($existingRoom !== null) {
        return $existingRoom;
    }

    return musicCampaignTransaction(static function (array &$library) use ($userId): string {
        $library['rooms'][$userId] ??= ['token' => bin2hex(random_bytes(16)), 'aliases' => []];
        return $library['rooms'][$userId]['token'];
    });
}

function musicResolveRoom(mixed $room): ?array
{
    if (!musicValidRoom($room)) {
        return null;
    }
    foreach (musicReadJson(MUSIC_PROJECT_FILE)['rooms'] ?? [] as $userId => $entry) {
        if ($entry['token'] !== $room && !in_array($room, $entry['aliases'], true)) {
            continue;
        }
        $user = mapFindUserById((int) $userId);
        if ($user !== null && (int) $user['is_active'] === 1) {
            return ['owner' => (int) $userId, 'room' => $entry['token']];
        }
        break;
    }
    return null;
}
