<?php
declare(strict_types=1);

function mapCodexPermissionMembers(array $members): array
{
    $players = [];
    foreach ($members as $member) {
        if (($member['member_role'] ?? null) === 'player' && ($member['global_role'] ?? null) !== 'admin'
            && !empty($member['is_active'])) {
            $players[(int) $member['user_id']] = $member;
        }
    }
    return $players;
}

function mapCodexPermissionTarget(PDO $pdo, int $campaignId, array $query, array $members, ?array $entries = null): array
{
    $scope = $query['scope'] ?? 'module';
    if (!in_array($scope, ['module', 'entry'], true)) {
        throw new DomainException('Choisissez un module ou une fiche.');
    }

    $requestedUser = $query['membre'] ?? '0';
    $userId = in_array($requestedUser, [0, '0'], true) ? 0 : mapValidatePositiveInt($requestedUser, 'Joueur');
    if ($userId !== 0 && !isset(mapCodexPermissionMembers($members)[$userId])) {
        mapThrowNotFound('Ce joueur ne fait pas partie des joueurs actifs de la campagne.');
    }

    $entryId = null;
    if ($scope === 'entry') {
        $requestedEntry = $query['cible'] ?? '';
        if ($requestedEntry !== '') {
            $entryId = mapValidatePositiveInt($requestedEntry, 'Fiche');
        }
        $entries ??= mapCodexEntries($pdo, $campaignId);
        if ($entryId !== null && !isset($entries[$entryId])) {
            mapThrowNotFound('Fiche introuvable.');
        }
        $category = $entries[$entryId]['category'] ?? '';
    } else {
        $category = mapCodexText($query['module'] ?? MAP_CODEX_CATEGORIES['characters'], 100, 'Le module', true);
    }
    return ['scope' => $scope, 'category' => $category, 'entryId' => $entryId, 'userId' => $userId];
}

function mapRenderCodexPermissions(
    int $campaignId,
    array $target,
    array $entries,
    array $members,
    array $moduleRules,
    array $entryRules,
    callable $url,
): void {
    $scope = $target['scope'];
    $category = $target['category'];
    $entryId = $target['entryId'];
    $userId = $target['userId'];
    $players = mapCodexPermissionMembers($members);
    $scopeParams = ['mode' => 'permissions', 'scope' => $scope, 'membre' => $userId];
    $saveParams = $scopeParams + ($scope === 'entry' ? ['cible' => $entryId] : ['module' => $category]);
    $selectedRules = $scope === 'entry' ? ($entryRules[$userId] ?? []) : ($moduleRules[$category][$userId] ?? []);
    $targetTitle = $scope === 'entry' ? ($entries[$entryId]['title'] ?? '') : $category;
    $audience = $userId === 0 ? 'Tous les joueurs' : $players[$userId]['display_name'];
    $modules = array_unique(array_merge(array_values(MAP_CODEX_CATEGORIES), array_column($entries, 'category'), array_keys($moduleRules)));
    natcasesort($modules);
    $groups = [];
    foreach ($entries as $id => $entry) {
        $groups[$entry['category']][$id] = $entry['title'];
    }
    uksort($groups, 'strnatcasecmp');

    $rightLabels = [
        'read' => ['Consulter', 'Afficher le contenu de la fiche.'],
        'create' => ['Créer', 'Ajouter des fiches dans ce module et consulter celles que l’on vient de créer.'],
        'edit' => ['Modifier', 'Éditer le contenu des fiches existantes.'],
    ];
    if ($scope === 'entry') {
        unset($rightLabels['create']);
    }
    $effectiveContext = [
        'manager' => false,
        'userId' => $userId,
        'modules' => $moduleRules,
        'entries' => $entryId === null ? [] : [$entryId => $entryRules],
    ];
    $importPrivate = !empty($entries[$entryId]['private_origin']);
    $effective = [
        'read' => mapCodexCanRead($effectiveContext, $category, $entryId, $importPrivate),
        'create' => mapCodexCanCreate($effectiveContext, $category),
        'edit' => mapCodexCanEdit($effectiveContext, $category, $entryId, $importPrivate),
    ];
    $choices = ['inherit' => 'Hériter', 'allow' => 'Autoriser', 'deny' => 'Refuser'];
    ?>
    <div class="codex-permissions">
      <div class="section-heading">
        <h2>Droits d’accès</h2>
        <a href="<?= mapEscape($url($entryId === null ? [] : ['fiche' => $entryId])) ?>"><?= $entryId === null ? 'Retour au codex' : 'Retour à la fiche' ?></a>
      </div>
      <p class="muted">Sans autorisation, le contenu reste privé. Les MJ gardent tous les droits.</p>
      <nav class="codex-tabs" aria-label="Portée des droits">
        <a href="<?= mapEscape($url(['mode' => 'permissions', 'scope' => 'module', 'membre' => $userId]
            + ($category === '' ? [] : ['module' => $category]))) ?>" <?= $scope === 'module' ? 'aria-current="page"' : '' ?>>Par module</a>
        <a href="<?= mapEscape($url(['mode' => 'permissions', 'scope' => 'entry', 'membre' => $userId]
            + ($entryId === null ? [] : ['cible' => $entryId]))) ?>" <?= $scope === 'entry' ? 'aria-current="page"' : '' ?>>Par fiche</a>
      </nav>

      <form method="get" action="<?= mapEscape(mapUrl('/codex.php')) ?>" class="codex-permission-filter">
        <input type="hidden" name="campaignId" value="<?= $campaignId ?>">
        <input type="hidden" name="mode" value="permissions">
        <input type="hidden" name="scope" value="<?= mapEscape($scope) ?>">
        <?php if ($scope === 'module'): ?>
          <label class="field-group">
            Module<input name="module" list="codex-permission-modules" value="<?= mapEscape($category) ?>" maxlength="100" required>
            <datalist id="codex-permission-modules">
              <?php foreach ($modules as $name): ?><option value="<?= mapEscape((string) $name) ?>"></option><?php endforeach; ?>
            </datalist>
          </label>
        <?php else: ?>
          <label class="field-group">
            Fiche<select name="cible" required>
              <option value="">Choisir une fiche</option>
              <?php foreach ($groups as $module => $titles): ?>
                <optgroup label="<?= mapEscape((string) $module) ?>">
                  <?php foreach ($titles as $id => $title): ?>
                    <option value="<?= $id ?>" <?= $id === $entryId ? 'selected' : '' ?>><?= mapEscape($title) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
        <label class="field-group">
          Destinataire<select name="membre">
            <option value="0">Tous les joueurs</option>
            <?php foreach ($players as $id => $member): ?>
              <option value="<?= $id ?>" <?= $id === $userId ? 'selected' : '' ?>><?= mapEscape($member['display_name'] . ' (@' . $member['username'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div><button class="button button-secondary" type="submit">Afficher les droits</button></div>
      </form>

      <?php if ($targetTitle === ''): ?>
        <p class="muted"><?= $entries === [] ? 'Ajoutez une fiche pour définir ses droits.' : 'Choisissez la fiche à régler.' ?></p>
      <?php else: ?>
        <form method="post" action="<?= mapEscape($url($saveParams)) ?>" class="field-stack">
          <?= mapCsrfField() ?>
          <div class="codex-permission-target">
            <h3><?= mapEscape($targetTitle) ?></h3>
            <p class="muted"><?= mapEscape($audience) ?></p>
          </div>
          <?php foreach ($rightLabels as $right => [$label, $description]): ?>
            <fieldset class="codex-fieldset codex-permission-rule">
              <legend><?= mapEscape($label) ?></legend>
              <p class="muted"><?= mapEscape($description) ?></p>
              <p class="muted small"><?= $effective[$right] ? 'Droit actuel accordé' : 'Droit actuel refusé' ?></p>
              <div class="codex-permission-options">
                <?php foreach ($choices as $value => $choice): ?>
                  <label>
                    <input type="radio" name="permissions[<?= $right ?>]" value="<?= $value ?>" <?= ($selectedRules[$right] ?? 'inherit') === $value ? 'checked' : '' ?>>
                    <?= mapEscape($choice) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </fieldset>
          <?php endforeach; ?>
          <p class="muted">Une règle de fiche s’applique avant celles du module. À chaque niveau, un réglage individuel prime sur celui de tous les joueurs. Un refus de consultation empêche aussi la modification. Une fiche marquée privée à l’import nécessite une autorisation sur la fiche.</p>
          <div><button class="button" name="action" value="save_permissions">Enregistrer les droits</button></div>
        </form>
      <?php endif; ?>
    </div>
    <?php
}
