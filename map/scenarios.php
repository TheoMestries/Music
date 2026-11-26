<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/scenarios.php';

$user = null;
try {
    $user = mapRequireLogin();
    $campaignId = mapValidatePositiveInt($_GET['campaignId'] ?? null, 'Campagne');
    $access = mapRequireCampaignGm($campaignId, $user);
    $pdo = mapGetDatabase();
    $pageQuery = ['campaignId' => $campaignId];
    $reading = ($_GET['view'] ?? null) === 'read';
    $error = '';
    if (mapIsPostRequest()) {
        if ($reading) {
            header('Allow: GET, HEAD');
            throw new MapHttpException(405, 'Cette page est en lecture seule.');
        }
        mapEnforceCsrfToken();
        try {
            $title = mapCodexText($_POST['title'] ?? null, 160, 'Le nom du scénario', true);
            $pdo->prepare('INSERT INTO campaign_scenarios (campaign_id, title) VALUES (?, ?)')->execute([$campaignId, $title]);
            mapRedirectWithQuery('/scenarios.php', $pageQuery + ['scenarioId' => (int) $pdo->lastInsertId()]);
        } catch (DomainException $exception) {
            $error = $exception->getMessage();
        }
    }

    header('Cache-Control: private, no-store');
    $list = $pdo->prepare('SELECT id, title FROM campaign_scenarios WHERE campaign_id = ? ORDER BY id DESC');
    $list->execute([$campaignId]);
    $scenarios = $list->fetchAll();
    $scenarioId = isset($_GET['scenarioId']) ? mapValidatePositiveInt($_GET['scenarioId'], 'Scénario') : (int) ($scenarios[0]['id'] ?? 0);
    $styleUrl = mapUrl('/css/scenarios.css?v=3');
    if ($reading && $scenarioId === 0) {
        mapThrowNotFound('Scénario introuvable.');
    }
    if ($scenarioId > 0) {
        $scenarioQuery = $pageQuery + ['scenarioId' => $scenarioId];
        $scenario = mapScenarioFind($pdo, $campaignId, $scenarioId);
        $codexEntries = mapCodexEntries($pdo, $campaignId);
        $imageIds = mapScenarioImageIds($pdo, $scenarioId);
        $codexUrl = static fn (array $query): string => mapUrlWithQuery('/codex.php', $pageQuery + $query);
        $imageUrl = static fn (int $id): string => mapScenarioImageUrl($campaignId, $scenarioId, $id);
        $mentionMarkup = $reading ? static fn (string $text): string => mapCodexRichMentionMarkup($text, $codexEntries, $codexUrl, true) : null;
        foreach ($scenario['blocks'] as &$block) {
            $block['html'] = mapScenarioHtml($block['html'], $imageIds, $imageUrl, $mentionMarkup);
        }
        unset($block);
        if ($reading) {
            mapRenderPageStart($scenario['title'], $user, 'campaigns', showHeader: false);
            ?>
            <link rel="stylesheet" href="<?= mapEscape($styleUrl) ?>">
            <article class="scenario-reader" aria-labelledby="scenario-reader-title">
                <header class="scenario-reader-heading">
                    <h1 id="scenario-reader-title"><?= mapEscape($scenario['title']) ?></h1>
                    <a class="button button-secondary button-small" href="<?= mapEscape(mapUrlWithQuery('/scenarios.php', $scenarioQuery)) ?>">Retour à l’éditeur</a>
                </header>
                <div class="scenario-blocks">
                    <?php foreach ($scenario['blocks'] as $index => $block): ?>
                        <details class="scenario-block" <?= $block['collapsed'] ? '' : 'open' ?>>
                            <summary><?= mapEscape($block['title'] !== '' ? $block['title'] : 'Bloc de texte ' . ($index + 1)) ?></summary>
                            <div class="scenario-text scenario-reading-text"><?= $block['html'] ?></div>
                        </details>
                    <?php endforeach; ?>
                    <?php if ($scenario['blocks'] === []): ?><p>Ce scénario est vide.</p><?php endif; ?>
                </div>
            </article>
            <?php
            mapRenderPageEnd();
            return;
        }
        $entries = [];
        foreach ($codexEntries as $entryId => $entry) {
            $entries[] = ['id' => (string) $entryId, 'title' => $entry['title'], 'category' => $entry['category'],
                'url' => $codexUrl(['fiche' => $entryId])];
        }
        $editorData = ['blocks' => $scenario['blocks'], 'entries' => $entries, 'revision' => (int) $scenario['revision'],
            'csrf' => mapGetCsrfToken(), 'saveUrl' => mapUrlWithQuery('/api/scenarios.php', $pageQuery + ['scenarioId' => $scenarioId]),
            'uploadUrl' => mapUrlWithQuery('/api/scenarios.php', $pageQuery + ['scenarioId' => $scenarioId, 'action' => 'image']),
            'imageLimit' => MAP_SCENARIO_IMAGE_BYTES];
    }

    mapRenderPageStart('Scénarios', $user, 'campaigns');
    ?>
    <link rel="stylesheet" href="<?= mapEscape($styleUrl) ?>">
    <div class="scenarios-layout">
        <aside class="scenarios-sidebar" aria-label="Scénarios de la campagne">
            <a href="<?= mapEscape(mapUrlWithQuery('/campaign.php', ['id' => $campaignId, 'view' => 'manage'])) ?>">← <?= mapEscape($access['campaign']['name']) ?></a>
            <h1>Scénarios</h1>
            <nav aria-label="Choisir un scénario">
                <?php foreach ($scenarios as $item): ?>
                    <a href="<?= mapEscape(mapUrlWithQuery('/scenarios.php', $pageQuery + ['scenarioId' => $item['id']])) ?>"
                        <?= (int) $item['id'] === $scenarioId ? 'aria-current="page" data-scenario-current' : '' ?>><?= mapEscape($item['title']) ?></a>
                <?php endforeach; ?>
            </nav>
            <form method="post" action="<?= mapEscape(mapUrlWithQuery('/scenarios.php', $pageQuery)) ?>">
                <?= mapCsrfField() ?>
                <label for="scenario-new-title">Nom du nouveau scénario</label>
                <input id="scenario-new-title" name="title" maxlength="160" required>
                <button class="button button-secondary" type="submit">Créer un scénario</button>
                <?php if ($error !== ''): ?><p role="alert"><?= mapEscape($error) ?></p><?php endif; ?>
            </form>
        </aside>
        <?php if (isset($editorData)): ?>
            <section id="scenario-editor" class="scenario-workspace" aria-label="Éditeur du scénario">
                <div class="scenario-heading">
                    <div>
                        <label for="scenario-title">Nom du scénario</label>
                        <input id="scenario-title" maxlength="160" value="<?= mapEscape($scenario['title']) ?>" required>
                    </div>
                    <div class="scenario-save">
                        <button class="button button-secondary button-small" type="button" data-scenario-save>Enregistrer</button>
                        <a class="button button-small" data-scenario-read href="<?= mapEscape(mapUrlWithQuery('/scenarios.php', $scenarioQuery + ['view' => 'read'])) ?>">Mode lecture</a>
                    </div>
                </div>
                <p class="scenario-status" data-scenario-status role="status">Enregistré</p>
                <p class="scenario-help" id="scenario-editor-help">Tapez <kbd>#</kbd> puis le nom d’une fiche du Codex pour la citer. Vous pouvez aussi coller une image directement dans le texte.</p>
                <div class="scenario-blocks" data-scenario-blocks></div>
                <button class="button scenario-add" type="button" data-scenario-add>+ Ajouter un bloc de texte</button>
                <noscript>Activez JavaScript pour modifier ce scénario.</noscript>
            </section>
            <template id="scenario-block-template">
                <details class="scenario-block" open>
                    <summary>
                        <span data-block-label>Nouveau bloc</span>
                        <span class="scenario-block-actions">
                            <button type="button" data-move="-1" aria-label="Monter le bloc" title="Monter le bloc">↑</button>
                            <button type="button" data-move="1" aria-label="Descendre le bloc" title="Descendre le bloc">↓</button>
                            <button type="button" data-remove-block aria-label="Supprimer le bloc" title="Supprimer le bloc">×</button>
                        </span>
                    </summary>
                    <div class="scenario-block-content">
                        <input data-block-title aria-label="Titre du bloc" placeholder="Titre du bloc (facultatif)" maxlength="160">
                        <div class="scenario-toolbar" role="group" aria-label="Mise en forme">
                            <label class="scenario-format-label">Style
                                <select data-format="formatBlock" aria-label="Style du texte">
                                    <option value="p">Paragraphe</option><option value="h2">Titre</option><option value="h3">Sous-titre</option><option value="h4">Petit titre</option><option value="blockquote">Citation</option><option value="pre">Code</option>
                                </select>
                            </label>
                            <button type="button" data-command="bold" aria-label="Gras" aria-pressed="false" title="Gras"><b>G</b></button>
                            <button type="button" data-command="italic" aria-label="Italique" aria-pressed="false" title="Italique"><i>I</i></button>
                            <button type="button" data-command="underline" aria-label="Souligné" aria-pressed="false" title="Souligné"><u>S</u></button>
                            <button type="button" data-command="strikeThrough" aria-label="Barré" aria-pressed="false" title="Barré"><s>B</s></button>
                            <button type="button" data-command="superscript" aria-label="Exposant" aria-pressed="false" title="Exposant">x²</button>
                            <button type="button" data-command="subscript" aria-label="Indice" aria-pressed="false" title="Indice">x₂</button>
                            <button type="button" data-command="insertUnorderedList" aria-label="Liste à puces" aria-pressed="false" title="Liste à puces">• Liste</button>
                            <button type="button" data-command="insertOrderedList" aria-label="Liste numérotée" aria-pressed="false" title="Liste numérotée">1. Liste</button>
                            <select data-format="alignment" aria-label="Alignement">
                                <option value="">Aligner</option><option value="justifyLeft">Gauche</option><option value="justifyCenter">Centré</option><option value="justifyRight">Droite</option><option value="justifyFull">Justifié</option>
                            </select>
                            <button type="button" data-command="insertHorizontalRule" aria-label="Séparateur" title="Séparateur">―</button>
                            <button type="button" data-table>Tableau</button>
                            <button type="button" data-link>Lien</button>
                            <button type="button" data-command="unlink" aria-label="Retirer le lien" title="Retirer le lien">Sans lien</button>
                            <button type="button" data-image>Image</button>
                            <input type="file" data-image-file accept="image/png,image/jpeg,image/webp,image/gif" hidden>
                            <button type="button" data-command="removeFormat" aria-label="Effacer la mise en forme" title="Effacer la mise en forme">Tx</button>
                            <button type="button" data-command="undo" aria-label="Annuler la dernière saisie" title="Annuler la dernière saisie">↶</button>
                            <button type="button" data-command="redo" aria-label="Rétablir la dernière saisie" title="Rétablir la dernière saisie">↷</button>
                        </div>
                        <div class="scenario-writing">
                            <div class="scenario-text" data-block-editor contenteditable="true" role="textbox" aria-label="Texte du bloc" aria-multiline="true" aria-describedby="scenario-editor-help"></div>
                            <div class="scenario-suggestions" data-suggestions hidden></div>
                        </div>
                        <p class="scenario-image-error" data-image-error role="alert" hidden></p>
                    </div>
                </details>
            </template>
            <script type="application/json" id="scenario-data"><?= json_encode($editorData, JSON_HEX_TAG | JSON_THROW_ON_ERROR) ?></script>
        <?php else: ?>
            <section class="scenario-workspace scenario-empty"><p>Créez un scénario pour commencer à rédiger votre préparation.</p></section>
        <?php endif; ?>
    </div>
    <?php
    mapRenderPageEnd(['/js/scenarios.js'], 'scenario-3');
} catch (Throwable $error) {
    mapRenderExceptionPage($error, 'scenarios', $user);
}
