<?php
declare(strict_types=1);

function mapRenderCodexDetails(array $details): void
{
    $labels = [
        'age' => 'Âge', 'author' => 'Auteur', 'charges' => 'Charges', 'date' => 'Date',
        'entity_attributes' => 'Attributs', 'character_traits' => 'Traits', 'abilities' => 'Capacités',
        'height' => 'Hauteur', 'width' => 'Largeur', 'months' => 'Mois', 'weekdays' => 'Jours de la semaine',
        'years' => 'Années', 'seasons' => 'Saisons', 'moons' => 'Lunes', 'inventories' => 'Inventaire',
        'markers' => 'Repères', 'posts' => 'Notes associées', 'entry' => 'Texte', 'name' => 'Nom',
        'title' => 'Titre', 'type' => 'Type', 'price' => 'Prix', 'size' => 'Taille', 'weight' => 'Poids',
        'pronouns' => 'Pronoms', 'sex' => 'Sexe', 'value' => 'Valeur', 'amount' => 'Quantité',
        'description' => 'Description', 'metadata' => 'Dimensions', 'relation' => 'Relation',
        'relationships' => 'Relations', 'members' => 'Membres', 'elements' => 'Étapes',
    ];
    foreach (MAP_CODEX_MODULES as $fields) {
        $labels += $fields;
    }
    $hiddenKeys = ['id', 'created_at', 'updated_at', 'created_by', 'updated_by', 'words',
        'entity', 'map', 'item', 'family', 'race', 'marketplace_uuid'];
    if (array_is_list($details)) {
        echo '<ul class="codex-detail-list">';
        foreach ($details as $value) {
            echo '<li>';
            if (is_array($value)) {
                mapRenderCodexDetails($value);
            } elseif (is_scalar($value)) {
                echo mapEscape((string) $value);
            }
            echo '</li>';
        }
        echo '</ul>';
        return;
    }
    echo '<dl class="codex-values">';
    foreach ($details as $key => $value) {
        if ($value === null || $value === '' || $value === [] || in_array($key, $hiddenKeys, true)
            || str_ends_with((string) $key, '_id') || str_starts_with((string) $key, 'is_')) {
            continue;
        }
        $label = $labels[$key] ?? mb_convert_case(str_replace('_', ' ', (string) $key), MB_CASE_TITLE, 'UTF-8');
        if (is_array($value)) {
            echo '<div><dt><span class="visually-hidden">' . mapEscape($label) . '</span></dt><dd><details><summary>'
                . mapEscape($label) . '</summary>';
            mapRenderCodexDetails($value);
            echo '</details></dd></div>';
            continue;
        }
        $display = is_bool($value) ? ($value ? 'Oui' : 'Non') : (string) $value;
        if (in_array($key, ['entry', 'description'], true)) {
            $display = mapCodexPlainText($display);
        }
        echo '<div><dt>' . mapEscape($label) . '</dt><dd>' . mapEscape($display) . '</dd></div>';
    }
    echo '</dl>';
}

$formFields = [];
$structured = $entry === null ? new stdClass() : json_decode($entry['details'], false, 32, JSON_THROW_ON_ERROR);
$formCategory = $formValue('category', $category);
if (!$canManageCodex && $entry === null && !in_array($formCategory, $creatableModules, true)) {
    $formCategory = count($creatableModules) === 1 ? $creatableModules[0] : '';
}
$fieldsByModule = MAP_CODEX_MODULES;
$activeFields = $fieldsByModule[$formCategory] ?? [];
if ($error === null && $mode === 'edit') {
    foreach ($structured as $key => $value) {
        if (!isset($activeFields[$key]) && !($formCategory === 'Calendriers' && $key === 'date')
            && is_string($value) && mb_strlen($key) <= 120 && mb_strlen($value) <= 5000) {
            $formFields[] = [$key, $value];
            unset($structured->{$key});
        }
    }
} elseif (is_array($_POST['fieldName'] ?? null)) {
    foreach ($_POST['fieldName'] as $index => $name) {
        $value = $_POST['fieldValue'][$index] ?? '';
        $formFields[] = [is_string($name) ? $name : '', is_string($value) ? $value : ''];
    }
}
$formDetails = json_encode(mapCodexWithoutIndividualCollections($structured), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$moduleValues = $error !== null
    ? (is_array($_POST['moduleFields'] ?? null) ? $_POST['moduleFields'] : [])
    : (array) $structured;
$moduleNames = array_values(array_unique(array_merge($visibleModules, array_keys($categories))));
$quickModules = array_intersect_key(['Galerie' => 'Médias'], array_fill_keys($moduleNames, true));
$moduleGroups = [
    'Monde' => ['Personnages', 'Lieux', 'Cartes', 'Organisations', 'Familles', 'Créatures', 'Peuples'],
    'Temps' => ['Calendriers', 'Événements', 'Journaux', 'Chronologies'],
    'Campagne' => ['Quêtes', 'Notes'],
    'Références' => ['Objets', 'Capacités', 'Étiquettes'],
];
$customModules = array_diff($moduleNames, array_keys($quickModules), ...array_values($moduleGroups));
if ($customModules) {
    array_push($moduleGroups['Monde'], ...array_values($customModules));
}
$moduleGroups = array_filter(array_map(static fn (array $modules): array => array_intersect($modules, $moduleNames), $moduleGroups));
$specialModules = [
    'Cartes' => ['carte' => 'Carte interactive'],
    'Calendriers' => ['calendrier' => 'Calendrier'],
    'Chronologies' => ['chronologie' => 'Chronologie'],
];
$specialTab = $specialModules[$formCategory] ?? [];
$createModule = mapCodexCanCreate($permissions, $category) && $category !== '' ? $category :
    (!$canManageCodex && count($creatableModules) === 1 ? $creatableModules[0] : '');
$createSection = array_key_first($specialModules[$createModule] ?? []);
$createQuery = ['mode' => 'edit'] + ($createSection === null ? [] : ['section' => $createSection])
    + ($createModule === '' ? [] : ['categorie' => $createModule]) + $filterQuery;
$tabs = ['apercu' => 'Aperçu', 'relations' => 'Relations', 'profil' => 'Profil'] + $specialTab
    + MAP_CODEX_SHEET_SECTIONS
    + ['mentions' => 'Mentions', 'historique' => 'Historique'];
if (!$canManageCodex) {
    unset($tabs['historique']);
}
$tab = $_GET['section'] ?? 'apercu';
$tab = $tab === 'details' ? 'profil' : $tab;
if (!is_string($tab) || !isset($tabs[$tab])) {
    $tab = 'apercu';
}
$relations = array_filter($entry['links'] ?? [], static fn (array $link): bool => !mapCodexIsMention($link));
$mentions = $entry === null ? [] : mapCodexMentionLinks($entry);
$entryBody = $entry['body_html'] ?? mapCodexTextAsHtml((string) ($entry['body'] ?? ''));
if ($error !== null && is_string($_POST['body_html'] ?? null)) {
    $entryBody = mapCodexRichHtml($_POST['body_html']);
} elseif ($error !== null && is_string($_POST['body'] ?? null)) {
    $entryBody = mapCodexTextAsHtml($_POST['body']);
}

?>
<link rel="stylesheet" href="<?= mapEscape(mapUrl('/css/codex.css?v=11')) ?>">
<link rel="stylesheet" href="<?= mapEscape(mapUrl('/css/codex-maps.css?v=1')) ?>">
<div class="codex-workspace codex-layout">
  <aside class="section-block codex-library" aria-label="Codex de campagne">
    <h1 class="visually-hidden">Codex de campagne</h1>
    <a class="codex-campaign-card" href="<?= mapEscape(mapUrlWithQuery('/campaign.php', ['id' => $campaignId, 'view' => 'manage'])) ?>">
      <?php if ($access['campaign']['has_cover']): ?>
        <img src="<?= mapEscape(mapUrlWithQuery('/campaign-cover.php', ['id' => $campaignId])) ?>" alt="">
      <?php endif; ?>
      <span class="codex-campaign-info">
        <strong><?= mapEscape($access['campaign']['name']) ?></strong>
        <span>← Gestion de la campagne</span>
      </span>
    </a>
    <div class="codex-rail-actions">
      <form class="codex-global-search" method="get" role="search">
        <input type="hidden" name="campaignId" value="<?= $campaignId ?>">
        <input type="search" name="q" maxlength="150" placeholder="Rechercher" aria-label="Rechercher dans le codex" value="<?= mapEscape($search) ?>">
        <button type="submit" class="button button-secondary button-small" aria-label="Rechercher dans le codex" title="Rechercher"><span aria-hidden="true">⌕</span></button>
      </form>
      <?php if ($canManageCodex || $creatableModules): ?>
        <div class="codex-rail-buttons">
          <a class="button button-small" href="<?= mapEscape($url($createQuery)) ?>">+ Nouvelle fiche</a>
          <?php if ($canManageCodex): ?>
            <a class="button button-secondary button-small" href="<?= mapEscape($url(['mode' => 'import'])) ?>" <?= $mode === 'import' ? 'aria-current="page"' : '' ?>>Importer</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <details open class="codex-rail-navigation" data-codex-rail>
      <summary>Navigation du codex</summary>
      <nav aria-label="Modules du codex">
        <div class="codex-quick-links">
          <a class="codex-home" href="<?= mapEscape($url()) ?>" <?= $category === '' && $search === '' && $mode === 'view' ? 'aria-current="page"' : '' ?>>Tableau de bord</a>
          <a href="<?= mapEscape($url(['mode' => 'favoris'])) ?>" <?= $mode === 'favoris' ? 'aria-current="page"' : '' ?>>Favoris <small><?= count($favorites) ?></small></a>
          <?php if ($canManageCodex): ?>
            <a href="<?= mapEscape($url(['mode' => 'permissions'])) ?>" <?= $mode === 'permissions' ? 'aria-current="page"' : '' ?>>Droits d’accès</a>
          <?php endif; ?>
          <?php foreach ($quickModules as $name => $label): ?>
            <a href="<?= mapEscape($url(['categorie' => $name])) ?>" <?= $category === $name ? 'aria-current="page"' : '' ?>><?= mapEscape($label) ?> <small><?= $categories[$name] ?? 0 ?></small></a>
          <?php endforeach; ?>
        </div>
        <?php foreach ($moduleGroups as $group => $modules): ?>
          <h3 class="codex-module-group-label"><?= mapEscape($group) ?></h3>
          <ul>
            <?php foreach ($modules as $name): ?>
              <li><a href="<?= mapEscape($url(['categorie' => $name])) ?>" <?= (string) $name === $category ? 'aria-current="page"' : '' ?>>
              <span><?= mapEscape((string) $name) ?></span><small><?= $categories[$name] ?? 0 ?></small>
              </a></li>
            <?php endforeach; ?>
          </ul>
        <?php endforeach; ?>
      </nav>
    </details>
  </aside>

    <section class="section-block codex-entry<?= $entry !== null && $mode !== 'edit' ? ' codex-entry-record' : '' ?>">
      <?php if ($error !== null): ?><p class="notice notice-error" role="alert"><?= mapEscape($error) ?></p><?php endif; ?>
      <?php if ($mode === 'element'): ?>
        <?php mapRenderCodexElementEditor($entry, $elementSection, $element, $entries, $url,
            $error !== null && is_array($_POST['element'] ?? null) ? $_POST : null, $canManageCodex, $canManageCodex); ?>
      <?php elseif ($mode === 'element-rights'): ?>
        <?php
        $elementRules = $elementKey === null ? mapCodexElementCreateRules($pdo, $campaignId, $entryId, $elementSection)
            : mapCodexElementRules($pdo, $campaignId, $entryId, $elementSection, $elementKey);
        mapRenderCodexElementPermissions($entry, $elementSection, $element, $elementMembers, $elementRules, $elementAudience, $url);
        ?>
      <?php elseif ($mode === 'permissions'): ?>
        <?php
        $moduleRules = mapCodexModuleRules($pdo, $campaignId);
        $entryRules = $permissionTarget['entryId'] === null ? [] : mapCodexEntryRules($pdo, $campaignId, $permissionTarget['entryId']);
        mapRenderCodexPermissions($campaignId, $permissionTarget, $entries, $permissionMembers, $moduleRules, $entryRules, $url);
        ?>
      <?php elseif ($mode === 'import'): ?>
        <div class="codex-import">
          <div class="section-heading"><h2>Importer depuis Kanka</h2><a href="<?= mapEscape($url()) ?>">Retour au codex</a></div>
          <p class="muted">Compressez le dossier complet de l’export en ZIP, en gardant <code>campaign.json</code> et ses sous-dossiers. Les droits du codex déterminent quelles fiches sont visibles pour les joueurs.</p>
          <form id="codex-import-form" class="field-stack" data-endpoint="<?= mapEscape(mapUrlWithQuery('/api/codex-import.php', ['campaignId' => $campaignId])) ?>" data-token="<?= mapEscape(mapGetCsrfToken()) ?>" data-max="<?= MAP_CODEX_ARCHIVE_BYTES ?>">
            <label class="field-group">Archive Kanka (.zip)<input id="codex-archive" type="file" accept=".zip,application/zip" required></label>
            <div><button class="button" type="submit" disabled>Importer dans cette campagne</button></div>
            <progress id="codex-import-progress" max="100" value="0" hidden aria-label="Progression de l’import"></progress>
            <p id="codex-import-status" role="status" aria-live="polite"></p>
          </form>
        </div>
      <?php elseif ($mode === 'edit'): ?>
        <div class="section-heading"><h2><?= $entry === null ? 'Nouvelle fiche' : 'Modifier la fiche' ?></h2>
          <a href="<?= mapEscape($url(($entryId === null ? [] : ['fiche' => $entryId]) + $filterQuery)) ?>">Annuler</a>
        </div>
        <form method="post" enctype="multipart/form-data" class="field-stack" data-codex-editor
          data-module-fields="<?= mapEscape(json_encode($fieldsByModule, JSON_THROW_ON_ERROR)) ?>">
          <?= mapCsrfField() ?>
          <?php if ($entry !== null): ?><input type="hidden" name="revision" value="<?= mapEscape($formValue('revision')) ?>"><?php endif; ?>
          <div class="codex-form-grid">
            <label class="field-group">Titre<input name="title" maxlength="250" value="<?= mapEscape($formValue('title')) ?>"
              <?= !empty($entry['player_title_locked']) ? 'readonly' : 'required' ?>></label>
            <?php if ($canManageCodex): ?>
              <label class="field-group">Module<input name="category" list="codex-categories" maxlength="100" value="<?= mapEscape($formCategory) ?>" required></label>
              <datalist id="codex-categories">
                <?php foreach ($moduleNames as $name): ?>
                  <option value="<?= mapEscape((string) $name) ?>"></option>
                <?php endforeach; ?>
              </datalist>
            <?php elseif ($entry !== null): ?>
              <input name="category" type="hidden" value="<?= mapEscape($formCategory) ?>">
            <?php else: ?>
              <label class="field-group">Module<select name="category" required>
                <option value="">Choisir un module</option>
                <?php foreach ($creatableModules as $name): ?>
                  <option value="<?= mapEscape($name) ?>" <?= $name === $formCategory ? 'selected' : '' ?>><?= mapEscape($name) ?></option>
                <?php endforeach; ?>
              </select></label>
            <?php endif; ?>
          </div>
          <fieldset class="codex-fieldset" data-module-panel <?= $activeFields === [] ? 'hidden' : '' ?>>
            <legend>Informations du module</legend>
            <div class="codex-form-grid" data-module-inputs>
              <?php foreach ($activeFields as $key => $label): ?>
                <label class="field-group"><?= mapEscape($label) ?><input name="moduleFields[<?= mapEscape($key) ?>]" data-field="<?= mapEscape($key) ?>"
                  maxlength="5000" value="<?= mapEscape(is_scalar($moduleValues[$key] ?? null) ? (string) $moduleValues[$key] : '') ?>"></label>
              <?php endforeach; ?>
            </div>
          </fieldset>
          <?php if (!empty($entry['player_summary_locked'])): ?>
            <p class="muted">Le résumé contient des mentions privées. Le MJ peut modifier ce texte.</p>
          <?php else: ?>
            <label class="field-group">Résumé<input name="summary" maxlength="5000" value="<?= mapEscape($formValue('summary')) ?>"></label>
          <?php endif; ?>
          <?php if (!empty($entry['player_description_locked'])): ?>
            <p class="muted">La description contient des mentions privées. Le MJ peut modifier ce texte.</p>
          <?php else: ?>
          <fieldset class="codex-fieldset" data-codex-rich>
            <legend>Description</legend>
            <div class="codex-formatting" data-formatting hidden>
              <button type="button" data-format="bold" title="Gras"><strong>Gras</strong></button>
              <button type="button" data-format="italic" title="Italique"><em>Italique</em></button>
              <button type="button" data-format="formatBlock" data-value="h3">Titre</button>
              <button type="button" data-format="insertUnorderedList">Liste</button>
              <button type="button" data-format="formatBlock" data-value="blockquote">Citation</button>
            </div>
            <textarea name="body" id="codex-description" rows="10" maxlength="250000" aria-label="Description"><?= mapEscape($formValue('body')) ?></textarea>
            <div id="codex-description-editor" class="codex-rich-editor" role="textbox" aria-label="Description" aria-multiline="true" hidden><?= $entryBody ?></div>
            <div class="codex-mention-control" data-mention-control>
              <label>Fiche à mentionner <select data-mention-select><option value="">Choisir une fiche</option>
                <?php foreach ($entries as $id => $row): if ($id === $entryId) continue; ?>
                  <option value="<?= $id ?>" data-mention-label="<?= mapEscape($row['title']) ?>"><?= mapEscape($row['title'] . ' · ' . $row['category']) ?></option>
                <?php endforeach; ?>
              </select></label>
              <button type="button" class="button button-secondary button-small" data-mention-insert data-mention-target="codex-description">Insérer la mention</button>
            </div>
          </fieldset>
          <?php endif; ?>
          <label class="field-group">Illustration<input name="image" type="file" accept="image/png,image/jpeg,image/webp,image/gif"></label>
          <?php if ($imageUrl !== null): ?><label><input type="checkbox" name="removeImage" <?= isset($_POST['removeImage']) ? 'checked' : '' ?>> Retirer l’illustration actuelle</label><?php endif; ?>
          <?php if ($tab === 'calendrier' && $canEditSection($tab)): ?>
            <?php mapRenderCodexCalendarEditor($structured, $error !== null ? $_POST : null); ?>
          <?php elseif ($tab === 'chronologie' && $canEditSection($tab)): ?>
            <fieldset class="codex-fieldset"><legend>Événements de la chronologie</legend>
              <?php mapRenderCodexTimelineEditor($structured, $entries, $entryId, $error !== null ? $_POST : null); ?>
            </fieldset>
          <?php elseif ($tab === 'carte' && $canEditSection($tab)): ?>
            <?php mapRenderCodexMapEditor($formCategory, $imageUrl, $structured->codex_pins ?? [], $entries, $entryId, $error !== null ? $_POST : []); ?>
          <?php endif; ?>
          <?php if ($canManageCodex): ?>
          <fieldset class="codex-fieldset">
            <legend>Champs complémentaires</legend>
            <div id="codex-fields">
              <?php foreach ($formFields as [$name, $value]): ?>
                <div class="codex-link-fields" data-edit-row>
                  <label>Nom<input name="fieldName[]" maxlength="120" value="<?= mapEscape($name) ?>"></label>
                  <label>Valeur<textarea name="fieldValue[]" rows="2" maxlength="5000"><?= mapEscape($value) ?></textarea></label>
                  <button type="button" class="button button-secondary button-small" data-remove-row>Retirer</button>
                </div>
              <?php endforeach; ?>
            </div>
            <template id="codex-field-template"><div class="codex-link-fields" data-edit-row>
              <label>Nom<input name="fieldName[]" maxlength="120" placeholder="Ex. Date"></label>
              <label>Valeur<textarea name="fieldValue[]" rows="2" maxlength="5000"></textarea></label>
              <button type="button" class="button button-secondary button-small" data-remove-row>Retirer</button>
            </div></template>
            <button type="button" class="button button-secondary button-small" data-add-row="codex-field-template" data-rows="codex-fields">Ajouter un champ</button>
            <details><summary>Données structurées (JSON)</summary>
              <label class="field-group">Listes et objets<textarea name="details" rows="8" spellcheck="false"><?= mapEscape(is_string($_POST['details'] ?? null) && $error !== null ? $_POST['details'] : $formDetails) ?></textarea></label>
            </details>
          </fieldset>
          <?php endif; ?>
          <div>
            <?php if ($entryId !== null): ?>
              <p class="muted">Les notes se modifient dans l’aperçu. Les autres éléments associés ont leurs propres onglets.</p>
            <?php endif; ?>
            <button class="button" name="action" value="save">Enregistrer la fiche</button>
          </div>
        </form>
      <?php elseif ($entry !== null): ?>
        <?php require __DIR__ . '/codex-record-view.php'; ?>
      <?php else: ?>
        <div class="codex-browse-heading">
          <h2><?= mapEscape($mode === 'favoris' ? 'Favoris' : ($category !== '' ? $category : ($search === '' ? 'Tableau de bord' : 'Résultats de recherche'))) ?></h2>
          <?php if ($canManageCodex && $category !== '' && $mode !== 'favoris'): ?>
            <a class="button button-secondary button-small" href="<?= mapEscape($url(['mode' => 'permissions', 'scope' => 'module', 'module' => $category])) ?>">Droits de ce module</a>
          <?php endif; ?>
          <form method="get" role="search">
            <input type="hidden" name="campaignId" value="<?= $campaignId ?>">
            <?php if ($mode === 'favoris'): ?><input type="hidden" name="mode" value="favoris"><?php endif; ?>
            <?php if ($category !== ''): ?><input type="hidden" name="categorie" value="<?= mapEscape($category) ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= mapEscape($search) ?>" maxlength="150" placeholder="Rechercher une fiche" aria-label="Titre ou résumé">
            <button class="button button-secondary button-small">Rechercher</button>
          </form>
        </div>
        <?php if ($mode !== 'favoris' && $category === '' && $search === ''): ?>
          <?php if ($moduleNames === []): ?>
            <div class="codex-empty"><p class="muted">Le MJ n’a encore partagé aucune fiche avec vous.</p></div>
          <?php endif; ?>
          <?php foreach ($moduleGroups as $group => $modules): ?>
            <section class="codex-module-group">
              <h3><?= mapEscape($group) ?></h3>
              <ul class="codex-cards">
                <?php foreach ($modules as $name): ?>
                  <?php $count = $categories[$name] ?? 0; ?>
                  <li><a href="<?= mapEscape($url(['categorie' => $name])) ?>">
                    <strong><?= mapEscape((string) $name) ?></strong><small><?= $count ?> <?= $count > 1 ? 'fiches' : 'fiche' ?></small>
                  </a></li>
                <?php endforeach; ?>
              </ul>
            </section>
          <?php endforeach; ?>
        <?php elseif ($pageEntries === []): ?>
          <div class="codex-empty">
            <p class="muted"><?= $search !== '' ? 'Aucune fiche ne correspond.' : ($mode === 'favoris' ? 'Ajoutez une fiche à vos favoris pour la retrouver ici.' : 'Ce module est vide.') ?></p>
            <?php if ($category !== '' && $search === '' && mapCodexCanCreate($permissions, $category)): ?>
              <a class="button" href="<?= mapEscape($url($createQuery)) ?>">Créer une fiche</a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <ul class="codex-cards codex-entry-cards">
            <?php foreach ($pageEntries as $id => $row): ?>
              <li><a href="<?= mapEscape($url(['fiche' => $id] + $filterQuery)) ?>">
                <?php if ($row['image_id'] !== null): ?><img src="<?= mapEscape($mediaUrl((int) $row['image_id'])) ?>" alt="" loading="lazy"><?php endif; ?>
                <strong><?= mapEscape($row['title']) ?></strong>
                <?php if ($canManageCodex): ?><small><?= !empty($row['published_note_id']) ? 'Publié dans Sus20' : 'Non publié' ?></small><?php endif; ?>
                <?php if ($category === ''): ?><small><?= mapEscape($row['category']) ?></small><?php endif; ?>
                <?php if ($row['summary'] !== ''): ?><span><?= mapEscape($row['summary']) ?></span><?php endif; ?>
              </a></li>
            <?php endforeach; ?>
          </ul>
          <?php if ($pageCount > 1): ?>
            <nav class="codex-pagination" aria-label="Pages du module">
              <?php if ($page > 1): ?><a href="<?= mapEscape($url(['page' => $page - 1] + $filterQuery)) ?>">← Précédente</a><?php endif; ?>
              <span><?= $page ?> / <?= $pageCount ?></span>
              <?php if ($page < $pageCount): ?><a href="<?= mapEscape($url(['page' => $page + 1] + $filterQuery)) ?>">Suivante →</a><?php endif; ?>
            </nav>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </section>
</div>
