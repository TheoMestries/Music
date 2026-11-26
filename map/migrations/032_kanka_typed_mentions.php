<?php
declare(strict_types=1);

return [
    'version' => 32,
    'name' => 'kanka_typed_mentions',
    'up' => static function (PDO $pdo): void {
        require_once __DIR__ . '/../includes/campaign-codex.php';

        $targets = [];
        $sourcePattern = '~^kanka:([a-zA-Z0-9_-]{1,80}):(entity|gallery):([a-zA-Z0-9_-]{1,80})$~D';
        $index = $pdo->query("SELECT id, campaign_id, source_key FROM campaign_codex_entries WHERE source_key LIKE 'kanka:%'");
        foreach ($index as $entry) {
            if (preg_match($sourcePattern, $entry['source_key'], $source) && $source[2] === 'entity') {
                $targets[$entry['campaign_id']][$source[1]]['entity:' . $source[3]] = (int) $entry['id'];
            }
        }

        $entries = $pdo->query("SELECT id, campaign_id, source_key, body, body_html, details
            FROM campaign_codex_entries WHERE source_key LIKE 'kanka:%'");
        $save = $pdo->prepare('UPDATE campaign_codex_entries SET body = ?, body_html = ?, details = ?
            WHERE campaign_id = ? AND id = ?');
        foreach ($entries as $entry) {
            if (!preg_match($sourcePattern, $entry['source_key'], $source)) {
                continue;
            }
            $campaignId = (int) $entry['campaign_id'];
            $entryIds = $targets[$campaignId][$source[1]] ?? [];
            $refs = [];
            $body = mapCodexLocalizeKankaText($entry['body'], $entryIds, $refs);
            $html = $entry['body_html'];
            if (is_string($html) && preg_match(MAP_CODEX_KANKA_TOKEN, $html)) {
                $html = mapCodexLocalizeKankaMentions($html, $entryIds, $refs);
            }
            $details = json_decode($entry['details'], false, 32, JSON_THROW_ON_ERROR);
            $notesChanged = false;
            foreach (is_array($details->posts ?? null) ? $details->posts : [] as $post) {
                if (!is_string($post->entry ?? null) || !preg_match(MAP_CODEX_KANKA_TOKEN, $post->entry)) {
                    continue;
                }
                $localized = mapCodexLocalizeKankaMentions($post->entry, $entryIds, $refs);
                if ($localized !== $post->entry) {
                    $post->entry = $localized;
                    $notesChanged = true;
                }
            }
            if ($body === $entry['body'] && $html === $entry['body_html'] && !$notesChanged) {
                continue;
            }
            $entryId = (int) $entry['id'];
            $detailsJson = $notesChanged ? json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : $entry['details'];
            $save->execute([$body, $html, $detailsJson, $campaignId, $entryId]);
            mapCodexSaveMentions($pdo, $campaignId, $entryId, $html === null ? $body : mapCodexPlainText($html), $details);
        }
    },
];
