<?php
declare(strict_types=1);

const MUSIC_DATA_DIR = __DIR__ . '/data';

function musicReadJson(string $path, array $default = []): array
{
    if (!is_file($path)) {
        return $default;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Lecture du stockage SuSZic impossible.');
    }

    $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Stockage SuSZic invalide.');
    }

    return $data;
}

function musicUpdateJson(string $path, callable $update, ?callable $afterWrite = null): mixed
{
    $lock = fopen($path . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Ouverture du verrou SuSZic impossible.');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Verrouillage du stockage SuSZic impossible.');
        }

        $before = musicReadJson($path);
        $data = $before;
        $result = $update($data);
        if ($before !== $data) {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $temporary = tempnam(dirname($path), 'music-');
            if ($temporary === false) {
                throw new RuntimeException('Création du fichier temporaire SuSZic impossible.');
            }

            try {
                if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $path)) {
                    throw new RuntimeException('Écriture du stockage SuSZic impossible.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }

            if ($afterWrite !== null) {
                $afterWrite($before, $data);
            }
        }

        return $result;
    } finally {
        fclose($lock);
    }
}
