<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

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

    mapRenderPageStart('Des - ' . (string) $campaign['name'], $currentUser, 'campaigns');
    ?>
    <section class="section-block">
      <div class="section-heading">
        <div>
          <p class="eyebrow">Espace de campagne</p>
          <h2>Des — <?= mapEscape((string) $campaign['name']) ?></h2>
          <p class="muted">Les resultats sont generes et journalises par le serveur.</p>
        </div>
        <div class="button-row">
          <a class="button button-secondary" href="<?= mapEscape(mapUrlWithQuery('/campaign.php', ['id' => $campaignId])) ?>">Retour a la campagne</a>
        </div>
      </div>
    </section>

    <section
      id="campaign-dice-app"
      class="dice-app"
      data-campaign-id="<?= $campaignId ?>"
      data-api-url="<?= mapEscape(mapUrl('/api/dice.php')) ?>"
      data-csrf-token="<?= mapEscape(mapGetCsrfToken()) ?>"
    >
      <section class="section-block dice-roller-panel">
        <div class="section-subheading">
          <div>
            <p class="eyebrow">Lanceur</p>
            <h3>Expression</h3>
          </div>
        </div>

        <form id="dice-form" class="field-stack" autocomplete="off">
          <div class="field-group">
            <label for="dice-expression">Des et modificateurs</label>
            <input id="dice-expression" type="text" maxlength="100" value="1d20" placeholder="Ex : 1d20+5 ou 4d6kh3" required autofocus>
          </div>

          <div class="field-group">
            <label for="dice-mode">Mode du premier d20</label>
            <select id="dice-mode">
              <option value="normal">Normal</option>
              <option value="advantage">Avantage — garde le meilleur</option>
              <option value="disadvantage">Desavantage — garde le plus petit</option>
            </select>
          </div>

          <div class="dice-presets" aria-label="Des rapides">
            <?php foreach ([4, 6, 8, 10, 12, 20, 100] as $sides): ?>
              <button type="button" class="button button-secondary button-small" data-dice-preset="1d<?= $sides ?>">d<?= $sides ?></button>
            <?php endforeach; ?>
          </div>

          <button id="dice-submit" type="submit" class="button dice-submit">Lancer</button>
          <p id="dice-request-status" class="muted dice-request-status" aria-live="polite">Pret a lancer.</p>
        </form>

        <div class="dice-help">
          <p><strong>Exemples :</strong> <code>1d20+5</code>, <code>2d8+1d6-3</code>, <code>4d6kh3</code>, <code>2d20kl1</code>.</p>
          <p class="muted">Le mode avantage/désavantage transforme le premier <code>1d20</code>.</p>
        </div>
      </section>

      <section class="section-block dice-result-panel">
        <div id="dice-result-empty" class="dice-result-empty">
          <p class="eyebrow">Dernier lancer</p>
          <h3>Aucun lancer pour le moment</h3>
          <p class="muted">Le resultat et sa source aleatoire apparaitront ici.</p>
        </div>

        <div id="dice-result" class="hidden">
          <div class="dice-result-heading">
            <div>
              <p class="eyebrow">Dernier lancer</p>
              <h3 id="dice-result-expression"></h3>
            </div>
            <strong id="dice-result-total" class="dice-total"></strong>
          </div>
          <div id="dice-result-details" class="dice-result-details"></div>
          <div class="dice-source-row">
            <span id="dice-result-source" class="dice-source-badge"></span>
            <span id="dice-result-meta" class="muted"></span>
          </div>
          <p id="dice-fallback-reason" class="notice notice-info hidden"></p>
        </div>
      </section>

      <section class="section-block dice-history-panel">
        <div class="section-subheading">
          <div>
            <p class="eyebrow">Campagne</p>
            <h3>Historique des lancers</h3>
          </div>
          <button id="dice-history-refresh" type="button" class="button button-secondary button-small">Actualiser</button>
        </div>
        <p id="dice-history-status" class="muted" aria-live="polite">Chargement...</p>
        <div id="dice-history" class="dice-history"></div>
      </section>
    </section>
    <?php
    mapRenderPageEnd(['/js/dice.js']);
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception, 'map/dice', $currentUser);
}
