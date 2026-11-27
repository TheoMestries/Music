<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/notes.php';

$currentUser = null;

try {
    $currentUser = mapRequireLogin();

    try {
        $campaignId = mapValidatePositiveInt($_GET['campaignId'] ?? null, 'Identifiant de campagne');
    } catch (DomainException) {
        mapThrowNotFound('Campagne introuvable.');
    }

    $access = mapRequireCampaignAccess($campaignId, $currentUser);
    $campaign = $access['campaign'];
    $canUseGmNotes = mapCanUseGmNotes($access);

    mapRenderPageStart('Notes - ' . (string) $campaign['name'], $currentUser, 'campaigns');
    ?>
    <section class="section-block">
      <div class="section-heading">
        <div>
          <p class="eyebrow">Espace de campagne</p>
          <h2>Notes — <?= mapEscape((string) $campaign['name']) ?></h2>
          <p class="muted">Notes personnelles, partagees avec la table ou reservees aux MJ.</p>
        </div>
        <div class="button-row">
          <a class="button button-secondary" href="<?= mapEscape(mapUrlWithQuery('/campaign.php', ['id' => $campaignId])) ?>">Retour a la campagne</a>
        </div>
      </div>
    </section>

    <section
      id="campaign-notes-app"
      class="notes-app"
      data-campaign-id="<?= $campaignId ?>"
      data-api-url="<?= mapEscape(mapUrl('/api/notes.php')) ?>"
      data-csrf-token="<?= mapEscape(mapGetCsrfToken()) ?>"
      data-image-limit="<?= MAP_DOCUMENT_IMAGE_BYTES ?>"
      data-portrait-limit="<?= MAP_LIBRARY_PORTRAIT_BYTES ?>"
      data-portrait-source-limit="<?= MAP_LIBRARY_PORTRAIT_SOURCE_BYTES ?>"
      data-can-use-gm-notes="<?= $canUseGmNotes ? 'true' : 'false' ?>"
    >
      <aside class="notes-sidebar section-block" aria-label="Liste des notes">
        <div class="section-subheading notes-sidebar-heading">
          <div>
            <p class="eyebrow">Bibliotheque</p>
            <h3>Notes</h3>
          </div>
          <button id="note-create" type="button" class="button button-small">+ Document</button><button type="button" class="button button-secondary button-small" id="note-new-folder">+ Dossier</button><button type="button" class="button button-secondary button-small" id="note-new-character">+ Personnage</button>
        </div>

        <div class="notes-filters">
          <label class="visually-hidden" for="note-search">Rechercher</label>
          <input id="note-search" type="search" placeholder="Rechercher une note..." autocomplete="off">

          <div class="notes-filter-row">
            <div class="field-group">
              <label for="note-visibility-filter">Visibilite</label>
              <select id="note-visibility-filter">
                <option value="all">Toutes</option>
                <option value="personal">Personnelles</option>
                <?php if ($canUseGmNotes): ?>
                  <option value="gm">Reservees aux MJ</option>
                <?php endif; ?>
                <option value="shared">Partagees</option>
              </select>
            </div>

            <div class="field-group">
              <label for="note-folder-filter">Dossier</label>
              <select id="note-folder-filter">
                <option value="all">Tous</option>
              </select>
            </div>
          </div>
        </div>

        <p id="notes-list-status" class="notes-list-status muted" aria-live="polite">Chargement...</p>
        <div id="notes-list" class="notes-list"></div>
      </aside>

      <section class="notes-editor section-block" aria-label="Editeur de note">
        <div id="note-editor-empty" class="notes-editor-empty">
          <p class="eyebrow">Editeur</p>
          <h3>Aucune note selectionnee</h3>
          <p class="muted">Choisissez une note dans la liste ou creez-en une nouvelle.</p>
        </div>

        <form id="note-editor-form" class="notes-editor-form hidden" autocomplete="off">
          <div class="notes-editor-topbar">
            <div class="field-group notes-title-field">
              <label for="note-title">Titre</label>
              <input id="note-title" type="text" maxlength="160" required>
            </div>
</div>

          <div class="notes-metadata-row">
<div class="field-group">
              <label for="note-tags">Tags</label>
              <input id="note-tags" type="text" placeholder="PNJ, secret, session 4">
            </div>
          </div>

          <div class="document-permissions">
          <?php foreach (['readers' => 'Dans le journal des joueurs', 'editors' => 'Peut être modifié par'] as $kind => $label): ?>
            <div class="field-group"><label for="document-<?= $kind ?>"><?= $label ?></label>
            <select id="document-<?= $kind ?>" multiple size="4">
              <option value="*">Tous les joueurs</option>
              <?php foreach (mapGetCampaignMembers($campaignId) as $member): ?>
              <option value="<?= (int)$member['user_id'] ?>"><?= mapEscape((string)$member['display_name']) ?></option>
              <?php endforeach; ?>
            </select><small>Ctrl / Cmd + clic pour sélectionner plusieurs personnes. Aucune sélection : privé.</small></div>
          <?php endforeach; ?>
          </div>
          <div class="document-drop" id="document-drop"><label for="document-image">Déposer une image ou choisir un fichier</label>
          <input id="document-image" type="file" accept="image/png,image/jpeg,image/gif,image/webp">
          <small id="document-image-help"></small>
          <img id="document-image-preview" alt="Illustration du document" hidden>
          <button id="document-image-remove" type="button" class="button button-secondary">Retirer l’image</button></div>
          <div class="document-drop" id="character-token-drop" hidden>
            <label for="character-token">Jeton par défaut (facultatif)</label>
            <p class="muted">Ajoutez une image pour le jeton. Sa configuration sera disponible plus tard.</p>
            <input id="character-token" type="file" accept="image/png,image/jpeg,image/gif,image/webp">
            <img id="character-token-preview" alt="Image du jeton" hidden>
            <button id="character-token-remove" type="button" class="button button-secondary">Retirer le jeton</button>
            <small>PNG, JPEG, GIF ou WebP · 2 Mo maximum</small>
          </div>
          <div class="field-group notes-content-field">
            <label for="note-content">Description &amp; notes</label>
            <textarea id="note-content" maxlength="200000" placeholder="Ecrivez votre note ici..."></textarea>
          </div>

          <?php if ($canUseGmNotes): ?>
          <div class="field-group"><label for="document-gm">Notes du MJ (visibles uniquement par les MJ)</label><textarea id="document-gm" maxlength="200000"></textarea></div>
          <?php endif; ?>
          <div class="notes-editor-footer">
            <div>
              <p id="note-save-status" class="note-save-status muted" aria-live="polite">Toutes les modifications sont enregistrees.</p>
              <p id="note-author" class="note-author muted"></p>
            </div>
            <button id="note-delete" type="button" class="button button-danger">Supprimer</button>
          </div>

          <div id="note-conflict" class="notice notice-error hidden" role="alert">
            <p>Cette note a change ailleurs. Rechargez sa derniere version avant de continuer.</p>
            <button id="note-conflict-reload" type="button" class="button button-secondary button-small">Charger la derniere version</button>
          </div>
        </form>
      </section>
    </section>
    <?php
    mapRenderPageEnd(['/js/notes.js'], 'notes-6');
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception, 'map/notes', $currentUser);
}
