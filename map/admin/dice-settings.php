<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dice.php';

$currentUser = null;
$pageError = null;
$connectionValue = null;

try {
    $currentUser = mapRequireAdmin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        mapEnforceCsrfToken();
        $action = (string) ($_POST['action'] ?? '');
        $configuration = mapAnuApiKeyConfiguration();

        if ($action === 'save_and_test') {
            if ($configuration['origin'] === 'environment') {
                throw new DomainException('La cle ANU est geree par l environnement du serveur et ne peut pas etre remplacee ici.');
            }

            $apiKey = mapValidateAnuApiKey((string) ($_POST['api_key'] ?? ''));
            $connectionValue = mapTestAnuRandomProvider($apiKey);
            mapSaveAnuApiKey($apiKey, (int) $currentUser['user_id']);
            mapAddFlash('success', 'Connexion ANU validee. La cle est maintenant utilisee pour les lancers.');
            mapRedirect('/admin/dice-settings.php');
        }

        if ($action === 'test_current') {
            $connectionValue = mapTestAnuRandomProvider();
            mapAddFlash('success', 'Connexion ANU operationnelle. Valeur quantique de test recue : ' . $connectionValue . '.');
            mapRedirect('/admin/dice-settings.php');
        }

        if ($action === 'delete') {
            if ($configuration['origin'] !== 'database') {
                throw new DomainException('Aucune cle ANU enregistree ne peut etre supprimee ici.');
            }

            mapDeleteStoredAnuApiKey();
            mapAddFlash('success', 'La cle ANU enregistree a ete supprimee. Le secours cryptographique reste actif.');
            mapRedirect('/admin/dice-settings.php');
        }

        throw new DomainException('Action de configuration inconnue.');
    }
} catch (MapRandomProviderException | DomainException $exception) {
    $pageError = $exception->getMessage();
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception, 'map/admin/dice-settings', $currentUser);
}

try {
    $currentUser ??= mapRequireAdmin();
    $configuration = mapAnuApiKeyConfiguration();
    $configured = $configuration['apiKey'] !== null;
    $originLabel = match ($configuration['origin']) {
        'environment' => 'Variable d environnement du serveur',
        'database' => 'Base SQLite protegee',
        default => 'Aucune cle',
    };

    mapRenderPageStart('Reglages des', $currentUser, 'dice-settings');
    ?>
    <section class="section-block section-block-narrow">
      <div class="section-heading">
        <div>
          <p class="eyebrow">Administration</p>
          <h2>Hasard quantique ANU</h2>
          <p class="muted">Configuration commune a toutes les campagnes et test direct du fournisseur.</p>
        </div>
      </div>

      <?php if ($pageError !== null): ?>
        <p class="notice notice-error"><?= mapEscape($pageError) ?></p>
      <?php endif; ?>

      <div class="settings-summary">
        <div>
          <span class="eyebrow eyebrow-tight">Etat</span>
          <strong><?= $configured ? 'Cle configuree' : 'Configuration requise' ?></strong>
        </div>
        <div>
          <span class="eyebrow eyebrow-tight">Source</span>
          <strong><?= mapEscape($originLabel) ?></strong>
        </div>
        <div>
          <span class="eyebrow eyebrow-tight">Cle</span>
          <strong class="monospace"><?= mapEscape(mapMaskSecret($configuration['apiKey'])) ?></strong>
        </div>
      </div>

      <?php if ($configured): ?>
        <p class="notice notice-success">ANU est configure. Utilisez le test ci-dessous pour verifier la connexion et le quota.</p>
      <?php else: ?>
        <p class="notice notice-info">Sans cle, les lancers restent fiables mais utilisent <code>random_int()</code> et sont marques « secours cryptographique ».</p>
      <?php endif; ?>

      <div class="settings-actions">
        <?php if ($configured): ?>
          <form method="post" class="inline-form">
            <?= mapCsrfField() ?>
            <input type="hidden" name="action" value="test_current">
            <button class="button" type="submit">Tester la connexion ANU</button>
          </form>
        <?php endif; ?>

        <?php if ($configuration['origin'] === 'database'): ?>
          <form method="post" class="inline-form" data-confirm="Supprimer la cle ANU enregistree ?">
            <?= mapCsrfField() ?>
            <input type="hidden" name="action" value="delete">
            <button class="button button-danger" type="submit">Supprimer la cle</button>
          </form>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($configuration['origin'] !== 'environment'): ?>
      <section class="section-block section-block-narrow">
        <div class="section-subheading">
          <div>
            <p class="eyebrow">Activation</p>
            <h3><?= $configured ? 'Remplacer la cle' : 'Ajouter la cle gratuite' ?></h3>
          </div>
        </div>

        <ol class="settings-steps">
          <li>Creez une cle sur <a href="https://quantumnumbers.anu.edu.au/api-key" target="_blank" rel="noopener noreferrer">ANU Quantum Numbers</a>.</li>
          <li>Collez-la ci-dessous. Elle sera testee avant d etre enregistree.</li>
          <li>Faites ensuite un lancer : la source affichera « Quantique · ANU QRNG ».</li>
        </ol>

        <form method="post" class="field-stack" autocomplete="off">
          <?= mapCsrfField() ?>
          <input type="hidden" name="action" value="save_and_test">
          <div class="field-group">
            <label for="anu-api-key">Cle API ANU</label>
            <input id="anu-api-key" name="api_key" type="password" minlength="8" maxlength="512" required spellcheck="false" autocomplete="new-password">
            <p class="field-help">La cle n est jamais renvoyee au navigateur apres son enregistrement.</p>
          </div>
          <button class="button" type="submit">Tester et enregistrer</button>
        </form>
      </section>
    <?php endif; ?>
    <?php
    mapRenderPageEnd();
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception, 'map/admin/dice-settings/render', $currentUser);
}
