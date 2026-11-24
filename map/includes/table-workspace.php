<?php
require_once __DIR__ . '/notes.php';
$notesUrl = mapUrlWithQuery('/notes.php', ['campaignId' => $campaignId, 'embedded' => 1]);
$presentationCursor = mapGetDatabase()->prepare('SELECT event_id FROM campaign_presentations WHERE campaign_id = ?');
$presentationCursor->execute([$campaignId]);
$initialPresentationId = (string) $presentationCursor->fetchColumn();
$presentationCursor->closeCursor();
?>
<link rel="stylesheet" href="<?= mapEscape(mapUrl('/css/table-workspace.css?v=board-view-1')) ?>">
<section id="table-workspace" data-campaign-id="<?= $campaignId ?>" data-initial-presentation-id="<?= mapEscape($initialPresentationId) ?>" data-presentation-api="<?= mapEscape(mapUrl('/api/presentation.php')) ?>" data-board-api="<?= mapEscape(mapUrl('/api/board-pages.php')) ?>" data-csrf-token="<?= mapEscape(mapGetCsrfToken()) ?>" data-board-clipboard="<?= isset($_SESSION['map_board_clipboard'][$currentUser['user_id']][$campaignId]) ? '1' : '0' ?>">
  <div class="table-layout">
    <section class="table-stage" aria-label="Plateau de jeu">
      <div class="table-stage-controls">
        <span id="board-page-name"></span>
        <button type="button" id="table-diagnostics-toggle" class="button button-secondary button-small" hidden>Démarrer le diagnostic</button>
        <?php if (mapCanUseGmNotes($access)): ?>
        <button id="board-player-preview-toggle" type="button" class="button button-secondary button-small" aria-haspopup="dialog" aria-controls="board-player-preview" title="Aperçu joueur">Aperçu joueur</button>
        <button id="board-pages-toggle" type="button" class="button button-secondary button-small" aria-expanded="false" aria-controls="board-pages-menu" title="Gérer les pages du plateau"><span aria-hidden="true">▣</span> Pages <span aria-hidden="true">▾</span></button>
        <?php endif; ?>
        <button id="table-sidebar-toggle" type="button" class="button button-secondary button-small" aria-expanded="true" aria-controls="table-sidebar" aria-label="Panneau latéral" title="Panneau latéral"><span aria-hidden="true">◫</span></button>
      </div>
      <div id="board-view-controls" class="board-view-controls" aria-label="Navigation dans la carte">
        <button type="button" data-board-pan aria-label="Déplacer la vue" aria-pressed="false" title="Déplacer la vue (ou glisser avec le bouton du milieu)">✋</button>
        <button type="button" data-board-zoom-out aria-label="Réduire le zoom" title="Réduire le zoom">−</button>
        <button type="button" data-board-reset-view aria-label="Recentrer et ajuster la carte" title="Recentrer et ajuster la carte"><span data-board-zoom-current>100 %</span></button>
        <button type="button" data-board-zoom-in aria-label="Agrandir le zoom" title="Agrandir le zoom">+</button>
      </div>
        <?php if (mapCanUseGmNotes($access)): ?>
        <section id="board-pages-menu" class="board-pages-menu" aria-label="Gestion des pages" hidden>
          <header><div><strong>Pages de la campagne</strong><p>Votre vue et celle des joueurs sont indépendantes.</p></div><button id="board-create-page" type="button" class="button button-small">+ Créer une page</button></header>
          <p id="board-menu-status" class="muted" aria-live="polite">Chargement…</p>
          <div id="board-pages-list" class="board-pages-list"></div>
        </section>
        <?php endif; ?>
      <div class="board-play-area">
        <nav class="board-layer-rail" aria-label="Outils et calques du plateau">
          <details id="table-navigation" class="table-navigation">
            <summary aria-label="Menu de la campagne" title="Menu de la campagne"><span aria-hidden="true">☰</span></summary>
            <div>
              <a href="<?= mapEscape(mapUrlWithQuery('/campaign.php', $managementQuery)) ?>" target="_blank" rel="noopener">Gestion de la campagne</a>
              <a href="<?= mapEscape(mapUrl('/index.php#campaigns-section')) ?>" target="_blank" rel="noopener">Mes campagnes</a>
            </div>
          </details>
          <button type="button" id="board-select-toggle" aria-pressed="false" title="Sélectionner et déplacer des éléments"><span aria-hidden="true">➤</span>Sélection</button>
          <div class="board-drawing-entry">
            <button type="button" id="board-drawing-toggle" aria-expanded="false" aria-controls="board-drawing-menu" title="Outils de dessin"><span aria-hidden="true">✎</span>Dessin</button>
            <div id="board-drawing-menu" class="board-drawing-menu" hidden>
              <button type="button" data-drawing-tool="freehand" aria-pressed="false">À main levée</button>
              <button type="button" data-drawing-tool="line" aria-pressed="false">Stylo / ligne</button>
              <button type="button" data-drawing-tool="rectangle" aria-pressed="false">Rectangle</button>
              <button type="button" data-drawing-tool="ellipse" aria-pressed="false">Ellipse</button>
              <button type="button" id="board-drawing-clear">Effacer les dessins et le texte</button>
            </div>
          </div>
          <button type="button" id="board-text-toggle" aria-pressed="false" aria-controls="board-text-options"><span aria-hidden="true">T</span>Texte</button>
          <div class="board-measure-entry">
            <button type="button" id="board-ruler-toggle" aria-pressed="false" aria-controls="board-measure-options"><span aria-hidden="true">↗</span>Mesure</button>
            <section id="board-measure-options" class="board-measure-options" aria-label="Réglages de mesure" hidden>
              <header><strong>Mesure</strong><button type="button" data-measure-close aria-label="Replier les réglages">‹</button></header>
              <div class="board-measure-fields">
                <label>Forme <select name="shape"><option value="line">Ligne</option><option value="square">Carré</option><option value="circle">Cercle</option><option value="cone">Cône</option><option value="beam">Rayon</option></select></label>
                <label>Alignement à la grille <select name="snap"><option value="center">Depuis le centre</option><option value="corner">Depuis le coin</option><option value="free">Libre</option></select></label>
              </div>
              <label data-measure-origin hidden>Point d’origine <select name="origin"><option value="center">Centre</option><option value="edge">Bordure</option><option value="corner">Coin</option></select></label>
              <div data-measure-cone hidden>
                <label><input type="checkbox" name="lock"> Verrouiller la largeur à la hauteur</label>
                <label>Angle du cône <input type="number" name="angle" min="1" max="179" step="1" value="45"></label>
                <div class="board-measure-presets"><button type="button" data-angle="45">45°</button><button type="button" data-angle="60">60°</button><button type="button" data-angle="90">90°</button></div>
                <label>Extrémité du cône <select name="coneEnd"><option value="flat">Plate</option><option value="round">Arrondie</option></select></label>
              </div>
              <div data-measure-beam hidden>
                <label><span data-beam-label>Largeur du rayon</span> <input type="number" name="beamWidth" min="0.0001" max="1000000000000" step="any"></label>
                <div class="board-measure-presets"><button type="button" data-beam="1"></button><button type="button" data-beam="2"></button><button type="button" data-beam="3"></button></div>
              </div>
              <label>Délai du fondu <select name="fade"><option value="instant">Instantané</option><option value="linger">Prolongé</option></select></label>
              <label title="Disponible avec le déplacement des jetons"><input type="checkbox" disabled> Mesurer le déplacement des jetons</label>
              <label><input type="checkbox" name="share"> Montrer aux autres</label>
              <small data-measure-status></small>
              <footer data-measure-scale></footer>
              <button type="button" data-measure-stop>Quitter la mesure</button>
            </section>
          </div>
          <button type="button" id="board-dice-toggle" aria-expanded="false" aria-controls="board-dice-panel"><span aria-hidden="true">⚄</span>Dés</button>
          <button type="button" id="board-turn-toggle" aria-expanded="false" aria-controls="board-turn-panel" title="Ordre des tours"><span aria-hidden="true">☷</span>Tours</button>
          <span class="board-layer-label">Calques</span>
          <button type="button" data-board-layer="players" aria-pressed="true" title="Éléments visibles par les joueurs"><span aria-hidden="true">♟</span>Joueurs</button>
          <?php if (mapCanUseGmNotes($access)): ?>
          <button type="button" data-board-layer="gm" aria-pressed="false" title="Éléments visibles uniquement par les MJ"><span aria-hidden="true">◆</span>MJ</button>
          <button type="button" data-board-layer="map" aria-pressed="false" title="Modifier la carte et ses réglages"><span aria-hidden="true">▦</span>Carte</button>
          <?php endif; ?>
        </nav>
      <div class="table-grid" id="table-grid">
        <p class="table-empty" role="status">Chargement du plateau…</p>
      </div>
      </div>
      <div class="board-turn-panels">
        <section id="board-turn-panel" aria-labelledby="board-turn-title" hidden>
          <header class="board-turn-header">
            <strong id="board-turn-title">Ordre des tours</strong>
            <button type="button" data-turn-minimize aria-expanded="true" aria-controls="board-turn-body" aria-label="Réduire l’ordre des tours">−</button>
            <button type="button" data-turn-close aria-label="Fermer l’ordre des tours">×</button>
          </header>
          <div id="board-turn-body">
            <div class="board-turn-summary"><span data-turn-count aria-live="polite"></span></div>
            <ol data-turn-list aria-label="Participants à l’ordre des tours"></ol>
            <p data-turn-empty>Aucune entrée visible. Sélectionnez un jeton puis utilisez « Ajouter un tour » au clic droit ou Ctrl+U.</p>
            <footer data-turn-management hidden>
              <button type="button" data-turn-command="turn_previous" aria-label="Tour précédent" title="Tour précédent">←</button>
              <button type="button" data-turn-settings aria-expanded="false" aria-controls="board-turn-settings" aria-label="Options de tri" title="Options de tri">⚙</button>
              <button type="button" data-turn-command="turn_next" aria-label="Tour suivant" title="Tour suivant">→</button>
            </footer>
          </div>
          <output data-turn-error aria-live="polite"></output>
        </section>
        <section id="board-turn-settings" aria-labelledby="board-turn-settings-title" hidden>
          <header class="board-turn-header">
            <strong id="board-turn-settings-title">Options de tri</strong>
            <button type="button" data-turn-close-settings aria-label="Fermer les options de tri">×</button>
          </header>
          <div class="board-turn-settings-body">
            <div class="board-turn-sorts">
              <span>Initiative</span>
              <div><button type="button" data-turn-sort="high">Décroissante</button><button type="button" data-turn-sort="low">Croissante</button></div>
              <span>Nom</span>
              <div><button type="button" data-turn-sort="az">De A à Z</button><button type="button" data-turn-sort="za">De Z à A</button></div>
              <button type="button" data-turn-sort="layer">Par calque et couleur</button>
            </div>
            <label class="board-turn-auto"><input type="checkbox" data-turn-auto> Trier automatiquement les nouvelles entrées</label>
            <form data-turn-custom>
              <label>Entrée personnalisée <input name="label" required maxlength="160" placeholder="Ex. Compteur de rounds"></label>
              <label>Variation en quittant ce tour <input name="increment" type="number" step=".01" min="-1000000000" max="1000000000" placeholder="Ex. 1"></label>
              <button type="submit">Ajouter</button>
            </form>
            <button type="button" data-turn-clear>Effacer tous les tours</button>
          </div>
        </section>
      </div>
      <div id="board-drawing-options" class="board-drawing-options" hidden>
        <label>Trait <input id="board-drawing-stroke" type="color" value="#c08b38" list="board-drawing-palette"></label>
        <label>Remplissage <input id="board-drawing-fill" type="color" value="#c08b38" list="board-drawing-palette"></label>
        <datalist id="board-drawing-palette">
          <?php foreach (['#000000','#444444','#888888','#cccccc','#ffffff','#a6291e','#e12e25','#ed9e35','#f5df57','#7bc452','#258b63','#5be5e7','#4273ce','#3a32bc','#8e45c4','#d044ba','#c08b38','#694420'] as $color): ?>
          <option value="<?= $color ?>"></option>
          <?php endforeach; ?>
        </datalist>
        <label><input id="board-drawing-transparent" type="checkbox" checked> Fond transparent</label>
        <label>Opacité du fond <input id="board-drawing-opacity" type="range" min="0" max="100" value="100" disabled><output id="board-drawing-opacity-value" for="board-drawing-opacity">100 %</output></label>
        <label>Épaisseur <select id="board-drawing-width"><option value="1">Fin</option><option value="2">Petit</option><option value="4" selected>Normal</option><option value="8">Grand</option><option value="14">Très grand</option></select></label>
        <button type="button" id="board-drawing-stop" class="button button-secondary button-small">Quitter le dessin</button>
      </div>
      <div id="board-text-options" class="board-drawing-options" hidden>
        <label>Texte <input id="board-text-color" type="color" value="#000000" list="board-drawing-palette"></label>
        <label>Contour <input id="board-text-outline" type="color" value="#f9edc6" list="board-drawing-palette"></label>
        <label>Police <select id="board-text-font"><option>Arial</option><option>Georgia</option><option>Verdana</option><option>Courier New</option><option>Times New Roman</option></select></label>
        <label>Taille <input id="board-text-size" type="number" min="8" max="160" step="1" value="14"></label>
        <button type="button" data-text-style="bold" aria-pressed="false" aria-label="Gras" class="button button-secondary button-small"><strong>B</strong></button>
        <button type="button" data-text-style="italic" aria-pressed="false" aria-label="Italique" class="button button-secondary button-small"><em>I</em></button>
      </div>
      <?php if (mapCanUseGmNotes($access)): ?>
      <div id="board-page-toolbar" class="board-page-toolbar" role="toolbar" aria-label="Outils de la page" hidden>
        <button type="button" class="board-toolbar-handle" aria-label="Déplacer la barre d’outils" title="Déplacer la barre d’outils">⠿</button>
        <?php foreach (['image'=>['▧','Importer un arrière-plan'],'align'=>['⌗','Aligner sur la grille'],'grid'=>['▦','Paramètres de la grille'],'appearance'=>['◉','Personnalisation de la page'],'dimensions'=>['◩','Dimensions de la page'],'scale'=>['⚒','Échelle et mesures'],'all'=>['⚙','Tous les paramètres de page']] as $tool=>[$symbol,$label]): ?>
        <button type="button" data-board-tool="<?= mapEscape($tool) ?>" aria-label="<?= mapEscape($label) ?>" title="<?= mapEscape($label) ?>"><span aria-hidden="true"><?= mapEscape($symbol) ?></span></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <output id="board-display-status" aria-live="polite"></output>
      <div class="board-entry-notices" aria-live="polite"><?php mapRenderFlashStack(); ?></div>
    </section>
    <aside id="table-sidebar" class="table-tools" aria-label="Outils de campagne">
      <div class="table-tabs" role="tablist" aria-label="Outils">
        <button type="button" role="tab" id="tab-library" aria-selected="true" aria-controls="panel-library" data-panel="panel-library">Bibliothèque</button>
        <button type="button" role="tab" id="tab-chat" aria-selected="false" aria-controls="panel-chat" data-panel="panel-chat" tabindex="-1">Chat</button>
        <button type="button" role="tab" id="tab-party" aria-selected="false" aria-controls="panel-party" data-panel="panel-party" tabindex="-1">Table</button>
      </div>
      <section id="panel-library" role="tabpanel" aria-labelledby="tab-library">
        <iframe title="Bibliothèque de notes de la campagne" src="<?= mapEscape($notesUrl) ?>"></iframe>
      </section>
      <section id="panel-chat" role="tabpanel" aria-labelledby="tab-chat" hidden data-chat-api="<?= mapEscape(mapUrl('/api/chat.php')) ?>" data-dice-api="<?= mapEscape(mapUrl('/api/dice.php')) ?>" data-user-id="<?= (int) $currentUser['user_id'] ?>">
        <div id="board-chat-feed" class="board-chat-feed" role="log" aria-label="Messages et jets de dés"></div>
        <form id="board-chat-form" class="board-chat-form">
          <textarea id="board-chat-message" aria-label="Message" rows="2" maxlength="2000" placeholder="Message ou /roll 2d20kh1+3" required></textarea>
          <button type="submit" class="button button-small">Envoyer</button>
          <output id="board-chat-status" aria-live="polite"></output>
        </form>
      </section>
      <section id="panel-party" role="tabpanel" aria-labelledby="tab-party" hidden>
        <h2 class="table-tool-title">Participants</h2>
        <div class="table-person"><strong><?= mapEscape((string) $campaign['owner_display_name']) ?></strong><span>MJ principal</span></div>
        <?php foreach ($members as $member): ?>
          <div class="table-person"><strong><?= mapEscape((string) $member['display_name']) ?></strong><span><?= $member['member_role'] === 'gm' ? 'MJ secondaire' : 'Joueur' ?></span></div>
        <?php endforeach; ?>
      </section>
    </aside>
  </div>
</section>
<?php if (mapCanUseGmNotes($access)): ?>
<dialog id="board-player-preview" class="board-player-preview" aria-labelledby="board-player-preview-title">
  <header>
    <strong id="board-player-preview-title">Aperçu joueur</strong>
    <span data-preview-page-name></span>
    <form method="dialog"><button type="submit" class="button button-secondary button-small">Fermer</button></form>
  </header>
  <div class="board-preview-stage">
    <div class="table-grid" data-preview-grid aria-label="Carte affichée aux joueurs"></div>
    <p data-preview-status aria-live="polite"></p>
    <div class="board-view-controls" data-preview-controls aria-label="Navigation dans l’aperçu">
      <button type="button" data-board-zoom-out aria-label="Réduire le zoom">−</button>
      <button type="button" data-board-reset-view aria-label="Recentrer et ajuster la carte"><span data-board-zoom-current>100 %</span></button>
      <button type="button" data-board-zoom-in aria-label="Agrandir le zoom">+</button>
    </div>
  </div>
</dialog>
<?php endif; ?>
<dialog id="board-dice-panel" class="library-modal" aria-label="Lanceur de dés">
  <div class="board-dice-content">
    <fieldset class="board-roll-visibility">
      <legend>Visibilité</legend>
      <label><input type="radio" name="roll-visibility" value="public" checked> Tous</label>
      <label><input type="radio" name="roll-visibility" value="gm"> MJ</label>
      <label><input type="radio" name="roll-visibility" value="secret"> Secret</label>
    </fieldset>
    <section id="board-dice-quick"><h3>Jet rapide</h3><div id="board-dice-grid"></div></section>
    <button type="button" id="board-dice-advanced-toggle" class="button button-secondary button-small" aria-expanded="false" aria-controls="board-dice-advanced">Jet avancé</button>
    <form id="board-dice-advanced" hidden>
      <div class="board-dice-builder">
        <label>Quantité <input name="quantity" type="number" min="1" step="1" value="1" required></label>
        <label>Dé <select name="die"></select></label>
        <label>Modificateur <input name="modifier" type="number" min="-1000000" max="1000000" value="0" required></label>
      </div>
      <label>Mode <select name="mode"><option value="normal">Normal</option><option value="advantage">Avantage</option><option value="disadvantage">Désavantage</option></select></label>
      <label>Formule personnalisée <input name="formula" maxlength="100" placeholder="4d6kh3 + 2"></label>
      <label class="board-dice-check"><input type="checkbox" name="countSuccess"> Compter les réussites par dé</label>
      <div class="board-dice-threshold" hidden>
        <label>Comparaison <select name="operator"><option>&gt;</option><option>&gt;=</option><option>=</option><option>&lt;=</option><option>&lt;</option></select></label>
        <label>Seuil <input name="target" type="number" min="-1000000" max="1000000" value="0" required></label>
      </div>
      <button type="submit" class="button button-small">Lancer</button>
    </form>
    <output id="board-dice-status" aria-live="polite"></output>
    <section><h3>Mes 10 derniers jets</h3><div id="board-dice-history"></div></section>
  </div>
</dialog>

<dialog id="table-document" aria-labelledby="table-document-title">
 <form method="dialog"><button class="button button-secondary" aria-label="Fermer le document">Fermer</button></form>
 <h2 id="table-document-title"></h2>
 <div id="table-document-content"></div>
</dialog>
