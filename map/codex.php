<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/codex-kanka.php';
require_once __DIR__ . '/includes/codex-permission-view.php';
require_once __DIR__ . '/includes/codex-player-view.php';
require_once __DIR__ . '/includes/codex-element-projection.php';
require_once __DIR__ . '/includes/codex-element-view.php';

$user = null;
try {
    $user = mapRequireLogin();
    $campaignId = mapValidatePositiveInt($_GET['campaignId'] ?? null, 'Campagne');
    $access = mapRequireCampaignAccess($campaignId, $user);
    $pdo = mapGetDatabase();
    $userId = (int) $user['user_id'];
    $permissions = mapCodexPermissionContext($pdo, $campaignId, $access, $userId);
    $canManageCodex = mapCodexCanManage($permissions);
    $url = static fn (array $query = []): string => mapUrlWithQuery('/codex.php', ['campaignId' => $campaignId] + $query);
    $mode = match ($_GET['mode'] ?? null) {
        'import' => 'import',
        'permissions' => 'permissions',
        'element' => 'element',
        'element-rights' => 'element-rights',
        'edit' => 'edit',
        'favoris' => 'favoris',
        default => 'view',
    };
    if (!$canManageCodex && in_array($mode, ['import', 'permissions', 'element-rights'], true)) {
        mapThrowForbidden('Cette action est réservée aux MJ.');
    }

    $entryId = isset($_GET['fiche']) ? mapValidatePositiveInt($_GET['fiche'], 'Fiche') : null;
    $allEntries = mapCodexEntries($pdo, $campaignId);
    $entries = $canManageCodex ? $allEntries : array_filter(
        $allEntries, static fn (array $row, int $id): bool => mapCodexCanRead($permissions, $row['category'], $id, (bool) $row['private_origin']),
        ARRAY_FILTER_USE_BOTH
    );
    if (!$canManageCodex) {
        $entries = mapCodexProjectPlayerIndex($entries);
    }
    if ($entryId !== null && !isset($entries[$entryId])) {
        mapThrowNotFound('Fiche introuvable.');
    }
    $rawEntry = $entryId === null ? null : mapCodexEntry($pdo, $campaignId, $entryId);
    $elementContextFor = mapCodexElementContextLoader($pdo, $campaignId, $permissions);
    $elementPermissions = $entryId === null ? [] : $elementContextFor($entryId);
    $entry = $rawEntry !== null && !$canManageCodex
        ? mapCodexProjectElementAwareEntry($pdo, $campaignId, $rawEntry, $entries, $elementContextFor) : $rawEntry;
    $canEditEntry = $entryId !== null && mapCodexCanEdit($permissions, $entry['category'], $entryId, (bool) $rawEntry['private_origin']);
    $availableModules = array_values(array_unique(array_merge(
        array_values(MAP_CODEX_CATEGORIES),
        array_column($entries, 'category'),
        array_keys($permissions['modules'])
    )));
    $creatableModules = array_values(array_filter($availableModules, static fn (string $category): bool => mapCodexCanCreate($permissions, $category)));
    $visibleModules = array_values(array_filter($availableModules, static fn (string $category): bool =>
        mapCodexCanRead($permissions, $category) || in_array($category, $creatableModules, true)
    ));
    if ($mode === 'edit' && ($entryId === null ? !$canManageCodex && !$creatableModules : !$canEditEntry)) {
        mapThrowForbidden('Vous n’avez pas le droit de modifier ou de créer cette fiche.');
    }
    $editableSections = !$canManageCodex && ($entryId === null ? (bool) $creatableModules : $canEditEntry)
        ? mapCodexPlayerEditableSections($rawEntry, $entries) : [];
    $canEditSection = static fn (string $section): bool => $canManageCodex || !empty($editableSections[$section]);
    $permissionMembers = $mode === 'permissions' ? mapGetCampaignMembers($campaignId) : [];
    $permissionTarget = $mode === 'permissions' ? mapCodexPermissionTarget($pdo, $campaignId, $_GET, $permissionMembers, $entries) : null;
    $element = null;
    $elementSection = '';
    $elementKey = null;
    $elementAudience = 0;
    $elementMembers = [];
    if (in_array($mode, ['element', 'element-rights'], true)) {
        if ($entryId === null) {
            mapThrowNotFound('Fiche introuvable.');
        }
        $elementSection = mapCodexText($_GET['section'] ?? '', 35, 'La section', true);
        $elementKey = isset($_GET['element']) ? mapCodexText($_GET['element'], 24, 'L’identifiant', true) : null;
        mapCodexElementRuleScope($elementSection, $elementKey);
        if ($elementKey !== null) {
            $element = mapCodexElement($pdo, $campaignId, $entryId, $elementSection, $elementKey);
            if (!$canManageCodex) {
                if (!isset(mapCodexElementRows($entry, $elementSection)[$elementKey])) {
                    mapThrowNotFound('Élément introuvable.');
                }
                if (!mapCodexElementCanEdit($elementPermissions, $elementSection, $elementKey, $canEditEntry, $element['private'])
                    || mapCodexPlayerHasHiddenTokens($element['fields'], $entries)) {
                    mapThrowForbidden('Vous n’avez pas le droit de modifier cet élément.');
                }
                $element = mapCodexPlayerProjectedChildFields($element, $entries);
            }
        } elseif ($mode === 'element' && !mapCodexElementCanCreate($elementPermissions, $elementSection, $canEditEntry)) {
            mapThrowForbidden('Vous n’avez pas le droit de créer un élément dans cette section.');
        }
        if ($mode === 'element-rights') {
            $elementMembers = mapGetCampaignMembers($campaignId);
            $elementAudience = mapCodexElementAudience($_GET['membre'] ?? '0', $elementMembers);
        }
    }
    $error = null;

    header('Cache-Control: private, no-store');
    if (!mapIsPostRequest() && $mode === 'view' && $entryId !== null && ($_GET['section'] ?? null) === 'notes') {
        header('Location: ' . mapCodexElementReturnUrl($url, $entryId, 'notes'));
        exit;
    }
    if (mapIsPostRequest()) {
        mapEnforceCsrfToken();
        try {
            $action = $_POST['action'] ?? null;
            if (in_array($action, ['publish_sus20', 'unpublish_sus20'], true)) {
                if (!$canManageCodex || $entryId === null) {
                    mapThrowForbidden('Seul un MJ peut publier une fiche dans Sus20.');
                }
                $published = $action === 'publish_sus20';
                mapSetCodexPublished($pdo, $campaignId, $entryId, $published, $user);
                mapAddFlash('success', $published ? 'Fiche publiée dans la bibliothèque Sus20.' : 'Publication retirée de la bibliothèque Sus20.');
                $section = $_GET['section'] ?? null;
                mapRedirectWithQuery('/codex.php', ['campaignId' => $campaignId, 'fiche' => $entryId]
                    + (is_string($section) && $section !== '' ? ['section' => $section] : []));
            }
            if ($mode === 'element') {
                if ($action === 'save_element') {
                    $form = $_POST['element'] ?? null;
                    if (!is_array($form)) {
                        throw new DomainException('Élément invalide.');
                    }
                    $form['revision'] = $_POST['revision'] ?? null;
                    $savedKey = mapCodexSaveElement($pdo, $campaignId, $entryId, $elementSection, $elementKey, $form, $user);
                    mapAddFlash('success', 'Élément enregistré.');
                    header('Location: ' . mapCodexElementReturnUrl($url, $entryId, $elementSection, $savedKey));
                    exit;
                }
                if ($action === 'delete_element' && $elementKey !== null && $canManageCodex) {
                    mapCodexDeleteElement($pdo, $campaignId, $entryId, $elementSection, $elementKey,
                        mapValidatePositiveInt($_POST['revision'] ?? null, 'Version de la fiche'), $user);
                    mapAddFlash('success', 'Élément supprimé.');
                    header('Location: ' . mapCodexElementReturnUrl($url, $entryId, $elementSection));
                    exit;
                }
                mapThrowForbidden('Cette action n’est pas autorisée.');
            }
            if ($mode === 'element-rights' && $action === 'save_element_rights') {
                $rules = $_POST['permissions'] ?? null;
                if (!is_array($rules)) {
                    throw new DomainException('Droits invalides.');
                }
                $pdo->beginTransaction();
                try {
                    mapRequireCampaignGm($campaignId, $user);
                    if ($elementKey === null) {
                        mapCodexSaveElementCreateRule($pdo, $campaignId, $entryId, $elementSection, $elementAudience, $rules);
                    } else {
                        mapCodexElement($pdo, $campaignId, $entryId, $elementSection, $elementKey);
                        mapCodexSaveElementRule($pdo, $campaignId, $entryId, $elementSection, $elementKey, $elementAudience, $rules);
                    }
                    $pdo->commit();
                } catch (Throwable $exception) {
                    $pdo->rollBack();
                    throw $exception;
                }
                mapAddFlash('success', 'Droits enregistrés.');
                mapRedirectWithQuery('/codex.php', ['campaignId' => $campaignId, 'mode' => 'element-rights',
                    'fiche' => $entryId, 'section' => $elementSection, 'element' => $elementKey, 'membre' => $elementAudience]);
            }
            if ($mode === 'element-rights') {
                mapThrowForbidden('Cette action n’est pas autorisée.');
            }
            if ($action === 'save_permissions') {
                if (!$canManageCodex || $permissionTarget === null) {
                    mapThrowForbidden('Cette action est réservée aux MJ.');
                }
                $rule = $_POST['permissions'] ?? null;
                if (!is_array($rule)) {
                    throw new DomainException('Droits invalides.');
                }
                if ($permissionTarget['scope'] === 'module') {
                    mapCodexSaveModuleRule($pdo, $campaignId, $permissionTarget['category'], $permissionTarget['userId'], $rule);
                } else {
                    if ($permissionTarget['entryId'] === null) {
                        throw new DomainException('Choisissez une fiche.');
                    }
                    mapCodexSaveEntryRule($pdo, $campaignId, $permissionTarget['entryId'], $permissionTarget['userId'], $rule);
                }
                mapAddFlash('success', 'Droits du codex enregistrés.');
                mapRedirectWithQuery('/codex.php', ['campaignId' => $campaignId, 'mode' => 'permissions', 'scope' => $permissionTarget['scope'],
                    'module' => $permissionTarget['category'], 'cible' => $permissionTarget['entryId'], 'membre' => $permissionTarget['userId']]);
            }
            if ($entry !== null && in_array($action, ['star', 'unstar'], true)) {
                mapCodexSetFavorite($pdo, $campaignId, $entryId, $userId, $action === 'star');
                $section = $_GET['section'] ?? '';
                mapRedirectWithQuery('/codex.php', ['campaignId' => $campaignId, 'fiche' => $entryId]
                    + (is_string($section) && $section !== '' ? ['section' => $section] : []));
            }
            if ($action === 'delete' && $entry !== null) {
                if (!$canManageCodex) {
                    mapThrowForbidden('Seuls les MJ peuvent supprimer une fiche.');
                }
                mapCodexDeleteEntry($pdo, $campaignId, $entry, mapValidatePositiveInt($_POST['revision'] ?? null, 'Version de la fiche'));
                mapAddFlash('success', 'Fiche supprimée.');
                mapRedirectWithQuery('/codex.php', ['campaignId' => $campaignId]);
            }
            if ($action !== 'save') {
                throw new DomainException('Action inconnue.');
            }
            $mode = 'edit';
            $categoryToSave = mapCodexText($entry['category'] ?? $_POST['category'] ?? '', 100, 'La catégorie', true);
            if ($entryId === null ? !mapCodexCanCreate($permissions, $categoryToSave) : !$canEditEntry) {
                mapThrowForbidden('Vous n’avez pas le droit de modifier ou de créer cette fiche.');
            }
            $mainForm = mapCodexMainFormInput($_POST);
            $form = $canManageCodex ? $mainForm : mapCodexPlayerSaveForm($mainForm, $rawEntry, $entries);
            $image = null;
            $file = $_FILES['image'] ?? null;
            if ($file !== null && $file['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size'] > MAP_CODEX_IMAGE_BYTES) {
                    throw new DomainException('Impossible d’envoyer cette image. Vérifiez sa taille et réessayez.');
                }
                $image = file_get_contents($file['tmp_name']);
                if ($image === false) {
                    throw new DomainException('Impossible de lire cette image.');
                }
            }
            $savedId = mapCodexSaveEntry($pdo, $campaignId, $rawEntry, $form, $image, $userId, $canManageCodex);
            mapAddFlash('success', 'Fiche enregistrée.');
            $section = $_GET['section'] ?? '';
            mapRedirectWithQuery('/codex.php', ['campaignId' => $campaignId, 'fiche' => $savedId]
                + (is_string($section) && $section !== '' ? ['section' => $section] : []));
        } catch (DomainException $exception) {
            $error = $exception->getMessage();
        }
    }

    $favorites = array_intersect_key(array_fill_keys(mapCodexFavoriteIds($pdo, $campaignId, $userId), true), $entries);
    $activity = $entryId === null || !$canManageCodex ? [] : mapCodexHistory($pdo, $campaignId, $entryId);
    $categories = array_count_values(array_column($entries, 'category'));
    uksort($categories, 'strnatcasecmp');
    $category = mapCodexText($_GET['categorie'] ?? ($entry['category'] ?? ''), 100, 'La catégorie');
    $search = mapCodexText($_GET['q'] ?? '', 150, 'La recherche');
    $lowerSearch = mb_strtolower($search);
    $browseEntries = $mode === 'favoris' ? array_intersect_key($entries, $favorites) : $entries;
    $matches = array_filter($browseEntries, static fn (array $row): bool =>
        ($category === '' || $row['category'] === $category)
        && ($search === '' || str_contains(mb_strtolower($row['title'] . ' ' . $row['summary']), $lowerSearch))
    );
    $pageCount = max(1, (int) ceil(count($matches) / 24));
    $page = min($pageCount, max(1, (int) ($_GET['page'] ?? 1)));
    $pageEntries = array_slice($matches, ($page - 1) * 24, 24, true);
    $filterQuery = array_filter(['categorie' => $category, 'q' => $search], static fn (string $value): bool => $value !== '');
    if ($mode === 'favoris') {
        $filterQuery['mode'] = 'favoris';
    }
    $mediaUrl = static fn (int $imageId): string => mapUrlWithQuery('/codex-image.php', ['campaignId' => $campaignId, 'imageId' => $imageId]);
    $imageUrl = isset($entry['image_id']) ? $mediaUrl((int) $entry['image_id']) : null;
    $formValue = static fn (string $key, string $default = ''): string => is_string($_POST[$key] ?? null) && $error !== null
        ? $_POST[$key] : (string) ($entry[$key] ?? $default);

    mapRenderPageStart('Codex', $user, 'campaigns');
    require __DIR__ . '/includes/codex-view.php';
    mapRenderPageEnd(['/js/codex.js', '/js/codex-mentions.js', '/js/codex-maps.js'], 'codex-8');
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception, 'codex', $user);
}
