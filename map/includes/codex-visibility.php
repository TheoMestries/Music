<?php
declare(strict_types=1);

function mapCodexPlayerRowIsPublic(object|array $row, bool $allowMembers = true): bool
{
    $data = (array) $row;
    foreach (['is_private', 'is_hidden', 'isPrivate', 'isHidden', 'private', 'hidden'] as $key) {
        if (!empty($data[$key]) && !in_array($data[$key], ['false', 'off'], true)) {
            return false;
        }
    }
    if (!empty($data['deleted_at']) || !empty($data['deletedAt'])) {
        return false;
    }
    $allowedVisibility = [1, '1', 'all', 'public'];
    if ($allowMembers) {
        array_push($allowedVisibility, 5, '5', 'members');
    }
    foreach (['visibility_id', 'visibilityId', 'visibility'] as $key) {
        if (array_key_exists($key, $data) && $data[$key] !== null && !in_array($data[$key], $allowedVisibility, true)) {
            return false;
        }
    }
    return true;
}
