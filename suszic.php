<?php
declare(strict_types=1);

require_once __DIR__ . '/campaigns.php';
$user = requireLogin();
$room = musicRoomForUser($user['user_id']);
$csrf = mapGetCsrfToken();
$showLegacyAccountLink = musicHasLegacyAccountsToClaim();
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campagnes musicales</title>
    <meta name="csrf-token" content="<?= $csrf ?>">
    <link rel="stylesheet" href="styles.css?v=accounts-1">
  </head>
  <body>
    <main class="app-shell">
      <section class="workspace" aria-live="polite">
        <header class="app-topbar">
          <div class="brand">
            <div class="brand-mark" aria-hidden="true">♪</div>
            <div>
              <h1>SuSZic</h1>
              <p>Bibliothèque musicale par projets audio</p>
            </div>
          </div>
          <div class="topbar-actions">
            <div class="broadcast-control" aria-label="Diffusion en direct">
              <button id="broadcast-toggle" type="button" class="broadcast-toggle" aria-pressed="false" disabled>
                <span class="broadcast-dot" aria-hidden="true"></span>
                <span id="broadcast-label">Diffusion OFF</span>
              </button>
              <a id="broadcast-link" class="secondary-button broadcast-link" href="diffusion.php?room=<?= $room ?>" target="_blank" rel="noopener">Ma salle</a>
              <button id="broadcast-copy" type="button" class="secondary-button">Copier le lien</button>
              <span id="broadcast-copy-status" role="status"></span>
            </div>
            <a class="secondary-button" href="<?= mapEscape(mapUrl()) ?>">Sus20</a>
            <button id="theme-toggle" type="button" class="secondary-button">Mode sombre</button>
            <button id="campaign-switch" type="button" class="secondary-button">Changer de projet audio</button>
            <form method="post" action="<?= mapEscape(mapUrl('/logout.php')) ?>">
              <?= mapCsrfField() ?>
              <button type="submit" class="secondary-button logout-button" title="<?= mapEscape($user['username']) ?>">Déconnexion</button>
            </form>
          </div>
        </header>

        <div id="empty-state" class="empty-state">
          <div class="record-visual" aria-hidden="true">
            <span></span>
          </div>
          <h2>Choisis un projet audio pour commencer</h2>
          <p>Chaque projet audio contient ses dossiers, playlists et musiques.</p>
        </div>

        <div id="campaign-view" class="campaign-view hidden">
          <header class="workspace-header">
            <div>
              <p class="eyebrow">Projet audio actif</p>
              <h2 id="active-campaign-name"></h2>
            </div>
            <div class="stats">
              <div><strong id="folder-total">0</strong><span>Dossiers</span></div>
              <div><strong id="playlist-total">0</strong><span>Playlists</span></div>
              <div><strong id="track-total">0</strong><span>Musiques</span></div>
            </div>
          </header>

          <section class="library">
            <div class="section-title">
              <span>Organisation</span>
              <strong id="active-updated"></strong>
            </div>
            <div id="library-tree" class="library-tree"></div>
          </section>
        </div>
      </section>
    </main>

    <div id="campaign-modal" class="campaign-modal hidden" role="dialog" aria-modal="true" aria-labelledby="campaign-modal-title">
      <div class="campaign-modal-panel">
        <div class="campaign-modal-header">
          <div>
            <p class="eyebrow">Projets audio</p>
            <h2 id="campaign-modal-title">Choisir un projet audio</h2>
          </div>
          <button id="campaign-modal-close" type="button" class="modal-close-button" aria-label="Fermer">×</button>
        </div>

        <form id="campaign-form" class="panel-form">
          <label for="campaign-name">Nouveau projet audio</label>
          <div class="input-row">
            <input id="campaign-name" name="campaign-name" type="text" placeholder="Nom du projet audio" autocomplete="off" required>
            <button type="submit" class="icon-button" aria-label="Créer le projet audio" title="Créer le projet audio" disabled>+</button>
          </div>
        </form>

        <div class="section-title">
          <span>Projets audio disponibles</span>
          <strong id="campaign-count">0</strong>
        </div>
        <div id="campaign-list" class="campaign-list"></div>
        <?php if ($showLegacyAccountLink): ?>
          <a class="secondary-button" href="legacy-account.php">Récupérer mes anciens projets SuSZic</a>
        <?php endif; ?>
      </div>
    </div>

    <div id="folder-modal" class="campaign-modal hidden" role="dialog" aria-modal="true" aria-labelledby="folder-modal-title">
      <div class="campaign-modal-panel">
        <div class="campaign-modal-header">
          <div>
            <p class="eyebrow">Dossier</p>
            <h2 id="folder-modal-title">Ajouter un dossier</h2>
          </div>
          <button type="button" class="modal-close-button" data-close-modal="folder-modal" aria-label="Fermer">×</button>
        </div>
        <form id="folder-form" class="panel-form">
          <label for="folder-name">Dossier de tri</label>
          <div class="input-row">
            <input id="folder-name" type="text" placeholder="Ex : Villes, Donjons, PNJ" autocomplete="off" required>
            <button type="submit" class="icon-button" aria-label="Ajouter un dossier" title="Ajouter un dossier">+</button>
          </div>
        </form>
      </div>
    </div>

    <div id="playlist-modal" class="campaign-modal hidden" role="dialog" aria-modal="true" aria-labelledby="playlist-modal-title">
      <div class="campaign-modal-panel">
        <div class="campaign-modal-header">
          <div>
            <p class="eyebrow">Playlist</p>
            <h2 id="playlist-modal-title">Ajouter une playlist</h2>
          </div>
          <button type="button" class="modal-close-button" data-close-modal="playlist-modal" aria-label="Fermer">×</button>
        </div>
        <form id="playlist-form" class="panel-form">
          <label for="playlist-folder">Dossier</label>
          <select id="playlist-folder"></select>
          <label for="playlist-name">Nom de la playlist</label>
          <div class="input-row">
            <input id="playlist-name" type="text" placeholder="Ex : Combat tendu" autocomplete="off" required>
            <button type="submit" class="icon-button" aria-label="Ajouter une playlist" title="Ajouter une playlist">+</button>
          </div>
        </form>
      </div>
    </div>

    <div id="track-modal" class="campaign-modal hidden" role="dialog" aria-modal="true" aria-labelledby="track-modal-title">
      <div class="campaign-modal-panel">
        <div class="campaign-modal-header">
          <div>
            <p class="eyebrow">Musique</p>
            <h2 id="track-modal-title">Ajouter une musique</h2>
          </div>
          <button type="button" class="modal-close-button" data-close-modal="track-modal" aria-label="Fermer">×</button>
        </div>
        <form id="track-form" class="panel-form">
          <label for="track-playlist">Emplacement</label>
          <select id="track-playlist"></select>
          <label for="track-title">Titre</label>
          <input id="track-title" type="text" placeholder="Titre" autocomplete="off" required>
          <label for="track-file">Fichier audio</label>
          <div class="input-row stacked-mobile">
            <input id="track-file" type="file" accept="audio/*" required>
            <button type="submit" class="icon-button" aria-label="Ajouter une musique" title="Ajouter une musique">+</button>
          </div>
        </form>
      </div>
    </div>

    <div id="rename-modal" class="campaign-modal hidden" role="dialog" aria-modal="true" aria-labelledby="rename-modal-title">
      <div class="campaign-modal-panel">
        <div class="campaign-modal-header">
          <div>
            <p class="eyebrow">Modification</p>
            <h2 id="rename-modal-title">Modifier le nom</h2>
          </div>
          <button type="button" class="modal-close-button" data-close-modal="rename-modal" aria-label="Fermer">×</button>
        </div>
        <form id="rename-form" class="panel-form">
          <label id="rename-label" for="rename-name">Nom</label>
          <input id="rename-name" type="text" autocomplete="off" required>
          <button type="submit" class="secondary-button">Enregistrer</button>
        </form>
      </div>
    </div>

    <div id="transfer-modal" class="campaign-modal hidden" role="dialog" aria-modal="true" aria-labelledby="transfer-modal-title">
      <div class="campaign-modal-panel">
        <div class="campaign-modal-header">
          <h2 id="transfer-modal-title">Transférer le projet audio</h2>
          <button type="button" class="modal-close-button" data-close-modal="transfer-modal" aria-label="Fermer">×</button>
        </div>
        <form id="transfer-form" class="panel-form">
          <p id="transfer-summary"></p>
          <label for="transfer-username">Identifiant Sus20 du compte destinataire</label>
          <input id="transfer-username" type="text" autocomplete="off" required>
          <button type="submit" class="secondary-button">Confirmer le transfert</button>
        </form>
      </div>
    </div>

    <script src="theme.js?v=1"></script>
    <script src="app.js?v=accounts-1"></script>
  </body>
</html>
