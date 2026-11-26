<?php
declare(strict_types=1);

function mapCodexElementReturnUrl(callable $url, int $entryId, string $section, ?string $elementKey = null): string
{
    if ($section !== 'notes') {
        return $url(['fiche' => $entryId, 'section' => $section]);
    }
    $anchor = $elementKey === null ? 'codex-notes' : 'codex-note-' . $elementKey;
    return $url(['fiche' => $entryId, 'section' => 'apercu']) . '#' . $anchor;
}

function mapCodexElementAudience(mixed $value, array $members): int
{
    $id = in_array($value, [null, 0, '0'], true) ? 0 : mapValidatePositiveInt($value, 'Joueur');
    if ($id !== 0 && !isset(mapCodexPermissionMembers($members)[$id])) {
        mapThrowNotFound('Ce joueur ne fait pas partie des joueurs actifs de la campagne.');
    }
    return $id;
}

function mapCodexElementDisplayTitle(?array $element, string $label): string
{
    return ($element['fields']['name'] ?? '') ?: (($element['fields']['label'] ?? '') ?: $label);
}

function mapRenderCodexElementEditor(
    array $entry,
    string $section,
    ?array $element,
    array $entries,
    callable $url,
    ?array $submitted = null,
    bool $canDelete = false,
    bool $canManage = false,
): void {
    $definition = mapCodexElementDefinition($section, $entry['category']);
    $fields = $definition['fields'];
    $targetCategory = $definition['targetCategory'];
    $id = (int) $entry['id'];
    $sectionQuery = ['fiche' => $id, 'section' => $section];
    $elementQuery = $sectionQuery + ($element === null ? [] : ['element' => $element['key']]);
    $sectionUrl = mapCodexElementReturnUrl($url, $id, $section, $element['key'] ?? null);
    $editQuery = ['mode' => 'element'] + $elementQuery;
    $values = $element['fields'] ?? [];
    $values['target'] = (string) ($element['targetId'] ?? '');
    $values['private'] = ($element['private'] ?? true) ? '1' : '0';
    $revision = filter_var($submitted['revision'] ?? $entry['revision'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $submitted = $submitted['element'] ?? $submitted ?? [];
    $submitted = is_array($submitted) ? $submitted : [];
    $value = static function (string $key, string $default = '') use ($values, $submitted): string {
        $selected = $submitted[$key] ?? $values[$key] ?? $default;
        return is_scalar($selected) ? (string) $selected : $default;
    };
    $targets = $targetCategory === null ? [] : array_filter(
        $entries,
        static fn (array $row, int $candidateId): bool => $candidateId !== $id
            && ($targetCategory === '' || $row['category'] === $targetCategory),
        ARRAY_FILTER_USE_BOTH
    );
    $heading = $element === null ? match ($section) {
        'relations' => 'Ajouter une relation',
        'notes' => 'Ajouter une note',
        'organisations' => $entry['category'] === 'Organisations' ? 'Ajouter un membre' : 'Ajouter une organisation',
        'pouvoirs' => 'Ajouter un pouvoir',
        'inventaire' => 'Ajouter un objet',
        'medias' => 'Ajouter un média',
        'proprietes' => 'Ajouter une propriété',
        'rappels' => 'Ajouter un rappel',
        default => 'Ajouter un élément',
    } : 'Modifier l’élément';
    ?>
    <div class="codex-element-editor">
      <div class="section-heading">
        <div>
          <a class="codex-category" href="<?= mapEscape($sectionUrl) ?>"><?= mapEscape($entry['title']) ?></a>
          <h2><?= mapEscape($heading) ?></h2>
        </div>
        <?php if ($canManage && $element !== null): ?>
          <a class="button button-secondary button-small" href="<?= mapEscape($url(['mode' => 'element-rights'] + $elementQuery)) ?>">Droits de cet élément</a>
        <?php endif; ?>
      </div>

      <form method="post" action="<?= mapEscape($url($editQuery)) ?>" class="field-stack">
        <?= mapCsrfField() ?>
        <input type="hidden" name="revision" value="<?= $revision === false ? (int) $entry['revision'] : $revision ?>">
        <?php if ($targetCategory !== null): ?>
          <label class="field-group"><?= $section === 'rappels' ? 'Calendrier' : 'Fiche liée' ?>
            <select name="element[target]" <?= $section === 'relations' ? 'required' : '' ?>>
              <option value=""><?= $section === 'relations' ? 'Choisir une fiche' : 'Aucune fiche' ?></option>
              <?php foreach ($targets as $candidateId => $candidate): ?>
                <option value="<?= $candidateId ?>" <?= $value('target') === (string) $candidateId ? 'selected' : '' ?>><?= mapEscape($candidate['title'] . ' · ' . $candidate['category']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
        <?php foreach ($fields as $field => [$label, $type]): ?>
          <?php
          $text = $value($field, $field === 'amount' ? '1' : '');
          $fieldId = 'codex-element-field-' . $field;
          ?>
          <label class="field-group" for="<?= mapEscape($fieldId) ?>"><?= mapEscape($label) ?>
            <?php if ($type === 'flag'): ?>
              <select id="<?= mapEscape($fieldId) ?>" name="element[<?= mapEscape($field) ?>]">
                <option value="0">Non</option>
                <option value="1" <?= $text === '1' ? 'selected' : '' ?>>Oui</option>
              </select>
            <?php elseif ($type === 'memo'): ?>
              <textarea id="<?= mapEscape($fieldId) ?>" name="element[<?= mapEscape($field) ?>]" rows="<?= $section === 'notes' ? 12 : 5 ?>" maxlength="<?= $section === 'notes' ? 100000 : 20000 ?>"><?= mapEscape($text) ?></textarea>
            <?php else: ?>
              <input id="<?= mapEscape($fieldId) ?>" type="<?= $type === 'number' ? 'number' : ($type === 'url' ? 'url' : 'text') ?>"
                name="element[<?= mapEscape($field) ?>]" value="<?= mapEscape($text) ?>"
                <?= $type === 'number' ? 'min="0" max="999999" step="1"' : 'maxlength="250"' ?>
                <?= $type === 'date' ? 'placeholder="AAAA-MM-JJ"' : '' ?>
                <?= ($section === 'notes' || $section === 'rappels') && $field === 'name' ? 'required' : '' ?>>
            <?php endif; ?>
          </label>
          <?php if ($section === 'notes' && $field === 'entry' && $entries !== []): ?>
            <div class="codex-mention-control" data-mention-control>
              <label>Fiche à mentionner
                <select data-mention-select>
                  <option value="">Choisir une fiche</option>
                  <?php foreach ($entries as $candidateId => $candidate): if ($candidateId === $id) continue; ?>
                    <option value="<?= $candidateId ?>"><?= mapEscape($candidate['title'] . ' · ' . $candidate['category']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button class="button button-secondary button-small" type="button" data-mention-insert data-mention-target="<?= mapEscape($fieldId) ?>">Insérer la mention</button>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($canManage): ?>
          <label class="field-group">Visibilité par défaut
            <select name="element[private]" aria-describedby="codex-element-default-visibility">
              <option value="1" <?= $value('private') === '1' ? 'selected' : '' ?>>MJ et joueurs autorisés sur cet élément</option>
              <option value="0" <?= $value('private') === '0' ? 'selected' : '' ?>>Lecteurs de la fiche</option>
            </select>
          </label>
          <p id="codex-element-default-visibility" class="muted">Les droits de l’élément permettent de faire des exceptions pour tous les joueurs ou pour un joueur précis.</p>
        <?php endif; ?>

        <div class="button-row">
          <button class="button" name="action" value="save_element"><?= $element === null ? 'Ajouter' : 'Enregistrer' ?></button>
          <a class="button button-secondary" href="<?= mapEscape($sectionUrl) ?>">Annuler</a>
        </div>
      </form>

      <?php if ($canDelete && $element !== null): ?>
        <form method="post" action="<?= mapEscape($url($editQuery)) ?>" data-confirm="Supprimer cet élément du codex ?">
          <?= mapCsrfField() ?>
          <input type="hidden" name="revision" value="<?= (int) $entry['revision'] ?>">
          <button class="button button-secondary button-small codex-element-delete" name="action" value="delete_element">Supprimer cet élément</button>
        </form>
      <?php endif; ?>
    </div>
    <?php
}

function mapRenderCodexElementPermissions(
    array $entry,
    string $section,
    ?array $element,
    array $members,
    array $rules,
    int $audience,
    callable $url,
): void {
    $players = mapCodexPermissionMembers($members);
    $sectionQuery = ['fiche' => (int) $entry['id'], 'section' => $section];
    $targetQuery = $sectionQuery + ($element === null ? [] : ['element' => $element['key']]);
    $sectionUrl = mapCodexElementReturnUrl($url, (int) $entry['id'], $section, $element['key'] ?? null);
    $rightsQuery = ['mode' => 'element-rights'] + $targetQuery;
    $choices = ['inherit' => 'Hériter', 'allow' => 'Autoriser', 'deny' => 'Refuser'];
    $headings = $element === null
        ? ['create' => 'Créer des éléments dans cette section']
        : ['read' => 'Consulter cet élément', 'edit' => 'Modifier cet élément'];
    $targetTitle = $element === null
        ? (MAP_CODEX_SHEET_SECTIONS[$section] ?? ['relations' => 'Relations', 'notes' => 'Notes associées'][$section])
        : mapCodexElementDisplayTitle($element, mapCodexElementDefinition($section, $entry['category'])['label']);
    ?>
    <div class="codex-permissions">
      <div class="section-heading">
        <div>
          <a class="codex-category" href="<?= mapEscape($sectionUrl) ?>"><?= mapEscape($entry['title']) ?></a>
          <h2><?= $element === null ? 'Droits de création de la section' : 'Droits de l’élément' ?></h2>
        </div>
        <a class="button button-secondary button-small"
          href="<?= mapEscape($element === null ? $sectionUrl : $url(['mode' => 'element'] + $targetQuery)) ?>">
          <?= $element === null ? 'Retour à la section' : 'Retour à l’élément' ?>
        </a>
      </div>
      <div>
        <h3><?= mapEscape($targetTitle) ?></h3>
        <p class="muted"><?= $element === null
            ? 'Ce droit s’applique à cette section, sur cette fiche. Les joueurs doivent pouvoir consulter la fiche pour ajouter un élément.'
            : 'Le destinataire doit pouvoir consulter la fiche. Ses lecteurs peuvent consulter un élément ouvert par défaut. Un élément privé exige une autorisation explicite.' ?></p>
      </div>
      <form method="get" action="<?= mapEscape(mapUrl('/codex.php')) ?>" class="codex-permission-filter codex-element-audience">
        <?php foreach (['campaignId' => (int) $entry['campaign_id']] + $rightsQuery as $key => $value): ?>
          <input type="hidden" name="<?= mapEscape($key) ?>" value="<?= mapEscape((string) $value) ?>">
        <?php endforeach; ?>
        <label class="field-group">Destinataire
          <select name="membre">
            <option value="0">Tous les joueurs</option>
            <?php foreach ($players as $id => $player): ?>
              <option value="<?= $id ?>" <?= $id === $audience ? 'selected' : '' ?>><?= mapEscape($player['display_name'] . ' (@' . $player['username'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div><button class="button button-secondary" type="submit">Afficher les droits</button></div>
      </form>
      <form method="post" action="<?= mapEscape($url($rightsQuery + ['membre' => $audience])) ?>" class="field-stack">
        <?= mapCsrfField() ?>
        <p><strong><?= $audience === 0 ? 'Tous les joueurs' : mapEscape($players[$audience]['display_name']) ?></strong></p>
        <?php foreach ($headings as $right => $heading): ?>
          <fieldset class="codex-fieldset codex-permission-rule">
            <legend><?= mapEscape($heading) ?></legend>
            <div class="codex-permission-options">
              <?php foreach ($choices as $value => $caption): ?>
                <label>
                  <input type="radio" name="permissions[<?= $right ?>]" value="<?= $value ?>" <?= ($rules[$audience][$right] ?? 'inherit') === $value ? 'checked' : '' ?>>
                  <?= mapEscape($caption) ?>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>
        <?php endforeach; ?>
        <p class="muted">Un réglage individuel prime sur celui de tous les joueurs. Les droits de création et de modification des éléments se règlent séparément des droits sur la fiche.</p>
        <div><button class="button" name="action" value="save_element_rights">Enregistrer les droits</button></div>
      </form>
    </div>
    <?php
}
