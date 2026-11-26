<?php
declare(strict_types=1);

$entryQuery = ['fiche' => $entryId];
$editUrl = $url($entryQuery + ['mode' => 'edit', 'section' => $tab] + $filterQuery);
$profileFields = array_intersect_key((array) $structured, MAP_CODEX_MODULES[$entry['category']] ?? []);
$profileLinks = ['Familles' => 'Famille', 'Peuples' => 'Peuple', 'Lieux' => 'Lieu'];
$linkedProfile = $tags = [];
foreach ($relations as $link) {
    $relatedId = (int) $link['target_id'] === $entryId ? (int) $link['entry_id'] : (int) $link['target_id'];
    $row = $entries[$relatedId] ?? null;
    if ($row === null) {
        continue;
    }
    if (isset($profileLinks[$row['category']])) {
        $linkedProfile[$profileLinks[$row['category']]][$relatedId] = $row['title'];
    } elseif ($row['category'] === 'Étiquettes') {
        $tags[$relatedId] = $row['title'];
    }
}
$pins = mapCodexSheetPins($structured, $entry['category'], $entries);
$posts = is_array($structured->posts ?? null) ? $structured->posts : [];
$activityLabels = ['created' => 'Création', 'updated' => 'Modification', 'imported' => 'Import Kanka'];
$type = is_string($structured->type ?? null) ? $structured->type : '';
$linkedDescription = mapCodexLinkedDescriptions($pdo, $campaignId, $entries, $url);
$childSources = [$entryId => $rawEntry];
$elementActions = static function (string $section, string $key, ?int $source = null) use (
    $canManageCodex, $canEditEntry, $elementContextFor, $entryId, $entries, $url, $pdo, $campaignId, &$childSources
): ?array {
    $source ??= $entryId;
    if (!isset($entries[$source])) {
        return null;
    }
    $childSources[$source] ??= mapCodexEntry($pdo, $campaignId, $source);
    $item = mapCodexElementRows($childSources[$source], $section)[$key] ?? null;
    if ($item === null) {
        return null;
    }
    $canEdit = mapCodexElementCanEdit($elementContextFor($source), $section, $key, $canEditEntry, $item['private'])
        && ($canManageCodex || !mapCodexPlayerHasHiddenTokens($item['fields'], $entries));
    $query = ['fiche' => $source, 'section' => $section, 'element' => $key];
    return [
        'private' => $item['private'],
        'edit' => $canEdit ? $url(['mode' => 'element'] + $query) : null,
        'rights' => $canManageCodex ? $url(['mode' => 'element-rights'] + $query) : null,
    ];
};
$renderElementActions = static function (string $section, string $key, ?int $source = null) use ($elementActions): void {
    $actions = $elementActions($section, $key, $source);
    if ($actions === null || ($actions['edit'] === null && $actions['rights'] === null)) {
        return;
    }
    ?>
    <div class="codex-element-actions">
      <?php if ($actions['rights'] !== null && $actions['private']): ?><small class="muted">Diffusion limitée</small><?php endif; ?>
      <?php if ($actions['edit'] !== null): ?><a class="button button-secondary button-small" href="<?= mapEscape($actions['edit']) ?>">Modifier</a><?php endif; ?>
      <?php if ($actions['rights'] !== null): ?><a class="button button-secondary button-small" href="<?= mapEscape($actions['rights']) ?>">Droits de l’élément</a><?php endif; ?>
    </div>
    <?php
};
$renderCreationActions = static function (string $section) use ($canManageCodex, $canEditEntry, $elementPermissions, $entryId, $url): void {
    $canCreate = mapCodexElementCanCreate($elementPermissions, $section, $canEditEntry);
    if (!$canCreate && !$canManageCodex) {
        return;
    }
    $labels = [
        'relations' => 'Ajouter une relation', 'notes' => 'Ajouter une note', 'organisations' => 'Ajouter une appartenance',
        'inventaire' => 'Ajouter un objet', 'medias' => 'Ajouter un média', 'pouvoirs' => 'Ajouter un pouvoir',
        'proprietes' => 'Ajouter une propriété', 'rappels' => 'Ajouter un rappel',
    ];
    $query = ['fiche' => $entryId, 'section' => $section];
    ?>
    <div class="codex-element-actions">
      <?php if ($canCreate): ?><a class="button button-small" href="<?= mapEscape($url(['mode' => 'element'] + $query)) ?>"><?= mapEscape($labels[$section]) ?></a><?php endif; ?>
      <?php if ($canManageCodex): ?><a class="button button-secondary button-small" href="<?= mapEscape($url(['mode' => 'element-rights'] + $query)) ?>">Droits de création</a><?php endif; ?>
    </div>
    <?php
};
$renderPosts = static function (array $posts) use ($entries, $url, $entryId, $elementActions): void {
    foreach ($posts as $index => $post) {
        if (!$post instanceof stdClass) {
            continue;
        }
        $title = is_string($post->name ?? null) ? $post->name : 'Note';
        $key = mapCodexIsElementKey($post->codex_element_key ?? null) ? $post->codex_element_key : null;
        $actions = $key === null ? null : $elementActions('notes', $key);
        ?>
        <article class="codex-note-card" <?= $key === null ? '' : 'id="codex-note-' . $key . '"' ?>>
          <details class="codex-story" <?= $index === 0 || !empty($post->is_pinned) ? 'open' : '' ?>>
            <summary><?= mapEscape($title) ?></summary>
            <div class="codex-rich-body"><?= mapCodexRichMentionMarkup(mapCodexRichHtml(is_string($post->entry ?? null) ? $post->entry : ''), $entries, $url) ?></div>
          </details>
          <?php if ($key !== null): ?>
            <div class="codex-note-actions">
              <?php if (isset($actions['rights'])): ?>
                <?php $visibility = ($actions['private'] ? 'Diffusion limitée' : 'Lecteurs de la fiche') . ' · Droits de la note'; ?>
                <a class="codex-note-visibility" href="<?= mapEscape($actions['rights']) ?>" title="<?= mapEscape($visibility) ?>"
                  aria-label="<?= mapEscape($visibility . ' ' . $title) ?>"><span aria-hidden="true"><?= $actions['private'] ? '◈' : '◉' ?></span></a>
              <?php endif; ?>
              <details class="codex-note-menu">
                <summary aria-label="<?= mapEscape('Actions de la note ' . $title) ?>" title="Actions"><span aria-hidden="true">⋯</span></summary>
                <div class="codex-note-menu-panel">
                  <?php if (isset($actions['edit'])): ?><a href="<?= mapEscape($actions['edit']) ?>">Modifier</a><?php endif; ?>
                  <?php if (isset($actions['rights'])): ?><a href="<?= mapEscape($actions['rights']) ?>">Droits de la note</a><?php endif; ?>
                  <a href="<?= mapEscape(mapCodexElementReturnUrl($url, $entryId, 'notes', $key)) ?>" data-copy-note-link>Copier le lien de la note</a>
                </div>
              </details>
            </div>
          <?php endif; ?>
        </article>
        <?php
    }
};
?>
<header class="codex-record-header">
  <?php if ($imageUrl !== null): ?>
    <a class="codex-portrait" href="<?= mapEscape($imageUrl) ?>" target="_blank" rel="noopener" aria-label="Ouvrir l’illustration de la fiche">
      <img src="<?= mapEscape($imageUrl) ?>" alt="<?= mapEscape($entry['title']) ?>">
    </a>
  <?php endif; ?>
  <div class="codex-record-name">
    <a class="codex-category" href="<?= mapEscape($url(['categorie' => $entry['category']])) ?>"><?= mapEscape($entry['category']) ?></a>
    <h2><?= mapEscape($entry['title']) ?></h2>
    <?php if ($type !== '' || $tags): ?>
      <ul class="codex-tags" aria-label="Type et étiquettes">
        <?php if ($type !== ''): ?><li class="codex-type"><?= mapEscape($type) ?></li><?php endif; ?>
        <?php foreach ($tags as $id => $title): ?><li><a href="<?= mapEscape($url(['fiche' => $id])) ?>"><?= mapEscape($title) ?></a></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <div class="codex-record-actions button-row">
    <?php if ($canManageCodex): ?>
      <form class="codex-publication" method="post">
        <?= mapCsrfField() ?>
        <?php if (!empty($entry['published_note_id'])): ?>
          <a class="codex-publication-status" href="<?= mapEscape(mapUrlWithQuery('/notes.php', [
            'campaignId' => $campaignId, 'documentId' => $entry['published_note_id'],
          ])) ?>">Publié dans Sus20</a>
          <button class="button button-secondary button-small" name="action" value="unpublish_sus20">Dépublier</button>
        <?php else: ?>
          <span class="codex-publication-status muted">Non publié</span>
          <button class="button button-secondary button-small" name="action" value="publish_sus20">Publier dans Sus20</button>
        <?php endif; ?>
      </form>
    <?php endif; ?>
    <?php $favorite = isset($favorites[$entryId]); ?>
    <form method="post"><?= mapCsrfField() ?>
      <button class="button button-secondary button-small" name="action" value="<?= $favorite ? 'unstar' : 'star' ?>"><?= $favorite ? '★ Retirer des favoris' : '☆ Ajouter aux favoris' ?></button>
    </form>
    <?php if ($canEditEntry): ?><a class="button button-small" href="<?= mapEscape($editUrl) ?>">Modifier</a><?php endif; ?>
    <?php if ($canManageCodex): ?>
    <details class="codex-record-menu">
      <summary class="button button-secondary button-small">Actions</summary>
      <form class="codex-delete" method="post" data-delete-entry>
        <a class="button button-secondary button-small" href="<?= mapEscape($url(['mode' => 'permissions', 'scope' => 'entry', 'cible' => $entryId])) ?>">Droits de cette fiche</a>
        <?= mapCsrfField() ?><input type="hidden" name="revision" value="<?= (int) $entry['revision'] ?>">
        <button class="button button-secondary button-small" name="action" value="delete">Supprimer la fiche</button>
      </form>
    </details>
    <?php endif; ?>
  </div>
</header>
<nav class="codex-tabs" aria-label="Sections de la fiche">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="<?= mapEscape($url($entryQuery + ['section' => $key] + $filterQuery)) ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>>
      <?= mapEscape($label) ?><?= $key === 'mentions' && $mentions ? ' (' . count($mentions) . ')' : '' ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'apercu'): ?>
  <div class="codex-record-overview">
    <section class="codex-record-article" aria-label="Description et notes">
      <?php if ($entry['summary'] !== '' || $entryBody !== ''): ?>
        <div class="codex-description-panel">
          <?php if ($entry['summary'] !== ''): ?><p class="codex-summary"><?= mapEscape($entry['summary']) ?></p><?php endif; ?>
          <?php if ($entryBody !== ''): ?>
            <div class="codex-rich-body codex-description"><?= mapCodexRichMentionMarkup($entryBody, $entries, $url) ?></div>
          <?php endif; ?>
        </div>
      <?php elseif (!$posts): ?>
        <p class="muted">Aucune description.</p>
      <?php endif; ?>
      <?php if ($posts || mapCodexElementCanCreate($elementPermissions, 'notes', $canEditEntry)): ?>
        <section id="codex-notes" class="codex-posts" aria-labelledby="codex-notes-heading">
          <h3 id="codex-notes-heading">Notes</h3>
          <?php $renderCreationActions('notes'); ?>
          <?php $renderPosts($posts); ?>
          <span class="visually-hidden" data-note-status role="status"></span>
        </section>
      <?php endif; ?>
    </section>
    <aside class="codex-record-aside" aria-label="Informations de la fiche">
      <details class="codex-info-card" open>
        <summary>Épingles</summary>
        <?php if ($pins): ?>
          <dl class="codex-values">
          <?php foreach ($pins as $pin): ?>
            <div><dt><a href="<?= mapEscape($url($entryQuery + ['section' => $pin['section']])) ?>"><?= mapEscape($pin['label']) ?></a></dt>
            <dd><?= mapEscape($pin['value']) ?></dd></div>
          <?php endforeach; ?>
          </dl>
        <?php else: ?>
          <p class="muted">Aucun élément épinglé.</p>
        <?php endif; ?>
      </details>
      <details class="codex-info-card" open>
        <summary>Profil</summary>
        <?php if ($profileFields): mapRenderCodexDetails($profileFields); endif; ?>
        <?php if ($linkedProfile): ?>
          <dl class="codex-values">
            <?php foreach ($linkedProfile as $label => $items): ?><div><dt><?= mapEscape($label) ?></dt>
              <?php foreach ($items as $id => $title): ?><dd><a href="<?= mapEscape($url(['fiche' => $id])) ?>"><?= mapEscape($title) ?></a></dd><?php endforeach; ?>
            </div><?php endforeach; ?>
          </dl>
        <?php elseif (!$profileFields): ?><p class="muted">Aucune information.</p><?php endif; ?>
      </details>
      <?php if ($canManageCodex): ?>
      <details class="codex-info-card" open>
        <summary>Historique</summary>
        <?php if ($activity):
            $latest = $activity[0];
            $first = $activity[array_key_last($activity)];
            $milestones = $latest === $first || $first['action'] === 'updated' ? [$latest] : [$first, $latest];
        ?>
          <dl class="codex-values">
            <?php foreach ($milestones as $event): ?>
              <div>
                <dt><?= mapEscape($event['action'] === 'updated' ? 'Dernière modification' : $activityLabels[$event['action']]) ?></dt>
                <dd><?= mapEscape(mapFormatDateTime($event['created_at'])) ?>
                  <?php if ($event['actor_name'] !== null): ?> · <?= mapEscape($event['actor_name']) ?><?php endif; ?>
                </dd>
              </div>
            <?php endforeach; ?>
          </dl>
          <a href="<?= mapEscape($url($entryQuery + ['section' => 'historique'])) ?>">Voir l’historique</a>
        <?php else: ?><p class="muted">Aucune activité enregistrée.</p><?php endif; ?>
      </details>
      <?php endif; ?>
    </aside>
  </div>
<?php elseif ($tab === 'calendrier'): ?>
  <?php if ($canEditSection($tab)): ?><p><a href="<?= mapEscape($editUrl) ?>">Configurer le calendrier</a></p><?php endif; ?>
  <?php
  $reminders = mapCodexCalendarReminders($pdo, $campaignId, $entryId);
  if (!$canManageCodex) {
      foreach ($reminders as &$dayReminders) {
          $dayReminders = array_values(array_filter($dayReminders, static fn (array $reminder): bool =>
              mapCodexPlayerCanReadChildSummary($entries, (int) $reminder['entry'], 'rappels', $reminder, $elementContextFor)
          ));
          foreach ($dayReminders as &$reminder) {
              $reminder['title'] = mapCodexPlayerRedactMentions($reminder['title'], $entries);
          }
          unset($reminder);
      }
      unset($dayReminders);
  }
  mapRenderCodexCalendar($structured, $_GET, static fn (int $year, int $month): string => $url($entryQuery + [
      'section' => 'calendrier', 'annee' => $year, 'mois' => $month,
  ]), $reminders,
      static fn (int $reminderEntry): string => $url(['fiche' => $reminderEntry, 'section' => 'rappels'])); ?>
<?php elseif ($tab === 'chronologie'): ?>
  <?php if ($canEditSection($tab)): ?><p><a href="<?= mapEscape($editUrl) ?>">Gérer les événements</a></p><?php endif; ?>
  <?php mapRenderCodexTimeline($structured, $entries, $url); ?>
<?php elseif ($tab === 'carte'): ?>
  <?php if ($canEditSection($tab)): ?><p><a href="<?= mapEscape($editUrl) ?>">Placer des repères</a></p><?php endif; ?>
  <?php mapRenderCodexMap($imageUrl, $entry['title'], $structured->codex_pins ?? [], $entries, $url); ?>
<?php elseif ($tab === 'profil'): ?>
  <?php
  $profile = json_decode($entry['details'], true, 32, JSON_THROW_ON_ERROR);
  foreach (array_merge(['posts', 'mentions', 'relationships', 'entity_tags', 'codex_pins', 'codex_timeline'], mapCodexSheetDetailKeys($entry['category'])) as $key) {
      unset($profile[$key]);
  }
  ?>
  <?php if ($profile): mapRenderCodexDetails($profile); else: ?><p class="muted">Aucune information complémentaire.</p><?php endif; ?>
<?php elseif ($tab === 'historique'): ?>
  <?php if (!$activity): ?><p class="muted">Aucune activité enregistrée.</p><?php else: ?>
    <ul class="codex-activity">
      <?php foreach ($activity as $event): ?>
        <li><strong><?= mapEscape($activityLabels[$event['action']]) ?></strong>
          <span><?= mapEscape(mapFormatDateTime($event['created_at'])) ?></span>
          <?php if ($event['actor_name'] !== null): ?><span><?= mapEscape($event['actor_name']) ?></span><?php endif; ?>
          <small>Révision <?= (int) $event['revision'] ?></small>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
<?php elseif (isset(MAP_CODEX_SHEET_SECTIONS[$tab])): ?>
  <?php
  $showCharacterSheet = $tab === 'proprietes' && $entry['category'] === 'Personnages';
  $showProperties = !$showCharacterSheet || !empty($structured->entity_attributes)
      || mapCodexElementCanCreate($elementPermissions, 'proprietes', $canEditEntry);
  ?>
  <?php if ($showCharacterSheet): ?>
    <section class="section-block codex-character-sheet" aria-labelledby="codex-character-sheet-title">
      <h3 id="codex-character-sheet-title">Fiche de personnage</h3>
      <div data-codex-character-sheet data-entry-id="<?= $entryId ?>" data-campaign-id="<?= $campaignId ?>"
        data-api-url="<?= mapEscape(mapUrl('/api/character-sheets.php')) ?>" data-csrf-token="<?= mapEscape(mapGetCsrfToken()) ?>">
        <p class="muted">Chargement de la fiche…</p>
      </div>
    </section>
    <?php if ($showProperties): ?><details class="codex-structured-editor codex-individual-properties"><summary>Propriétés individuelles</summary><?php endif; ?>
  <?php endif; ?>
  <?php if ($showProperties): ?>
  <?php $renderCreationActions($tab); ?>
  <?php
  $sectionDetails = $structured;
  if ($tab === 'organisations') {
      $sectionDetails = clone $structured;
      [$membershipKey] = mapCodexSectionDefinition($tab, $entry['category']);
      $memberships = mapCodexSectionRows($structured, $membershipKey);
      $inverse = mapCodexInverseMemberships($pdo, $campaignId, $entryId, $entry['category']);
      if (!$canManageCodex) {
          $inverse = array_filter($inverse, static fn (object $row): bool =>
              mapCodexPlayerCanReadChildSummary($entries, $row->codex_source_entry_id, 'organisations', $row, $elementContextFor));
          foreach ($inverse as $row) {
              $row->role = mapCodexPlayerRedactMentions($row->role, $entries);
          }
      }
      $directRoles = [];
      foreach ($memberships as $membership) {
          if (isset($membership->codex_entry_id)) {
              $directRoles[(int) $membership->codex_entry_id][mapCodexSectionValue($membership, 'role', 'text')] = true;
          }
      }
      $inverse = array_filter($inverse, static fn (object $row): bool =>
          empty($directRoles[$row->codex_source_entry_id][$row->role]));
      $sectionDetails->{$membershipKey} = array_merge($memberships, array_values($inverse));
  }
  mapRenderCodexSheetSection($tab, $sectionDetails, $entry, $entries, $url, $mediaUrl, $renderElementActions, $linkedDescription);
  ?>
  <?php if ($showCharacterSheet): ?></details><?php endif; ?>
  <?php endif; ?>
<?php else: ?>
  <?php if ($tab === 'relations') $renderCreationActions('relations'); ?>
  <?php
  $visibleLinks = $tab === 'mentions' ? $mentions : $relations;
  $headings = $tab === 'mentions'
      ? ['Fiches mentionnées', 'Mentionnée dans']
      : ['Relations de cette fiche', 'Fiches qui la référencent'];
  ?>
  <?php if (!$visibleLinks): ?><p class="muted"><?= $tab === 'mentions' ? 'Aucune mention enregistrée.' : 'Aucune relation enregistrée.' ?></p><?php endif; ?>
  <?php foreach ($headings as $incoming => $heading): ?>
    <?php $links = array_filter($visibleLinks, static fn (array $link): bool => ((int) $link['target_id'] === $entryId) === (bool) $incoming); ?>
    <?php if (!$links) continue; ?>
    <h3><?= mapEscape($heading) ?></h3>
    <ul class="codex-related">
      <?php foreach ($links as $link):
          $relatedId = $incoming ? $link['entry_id'] : $link['target_id'];
          $related = $entries[$relatedId] ?? null;
          if ($related === null) continue;
      ?>
        <li><a href="<?= mapEscape($url(['fiche' => $relatedId])) ?>"><?= mapEscape($related['title']) ?></a>
          <small><?= mapEscape($related['category'] . ($tab === 'mentions' || $link['label'] === '' ? '' : ' · ' . $link['label'])) ?></small>
          <?php $description = $tab === 'relations' ? $linkedDescription((int) $relatedId) : ''; ?>
          <?php if ($description !== ''): ?>
            <details class="codex-related-description">
              <summary>Description</summary>
              <div class="codex-rich-body codex-linked-description"><?= $description ?></div>
            </details>
          <?php endif; ?>
          <?php if ($tab === 'relations' && is_string($link['element_key'] ?? null)):
              $renderElementActions('relations', $link['element_key'], (int) $link['entry_id']);
          endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endforeach; ?>
<?php endif; ?>
