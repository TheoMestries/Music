<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$currentUser = null;
$errors = [];
$formData = [
    'name' => '',
    'description' => '',
    'owner_user_id' => '',
];

try {
    $currentUser = mapRequireGlobalRole(['admin', 'gm']);
    $ownerOptions = mapListCampaignOwnerOptions($currentUser);

    if ((string) $currentUser['role'] !== 'admin') {
        $formData['owner_user_id'] = (string) $currentUser['user_id'];
    }

    if (mapIsPostRequest()) {
        mapEnforceCsrfToken();

        $formData['name'] = trim((string) ($_POST['name'] ?? ''));
        $formData['description'] = trim((string) ($_POST['description'] ?? ''));
        $formData['owner_user_id'] = (string) ($_POST['owner_user_id'] ?? $formData['owner_user_id']);

        try {
            $name = mapValidateCampaignName($formData['name']);
        } catch (DomainException $exception) {
            $errors['name'] = $exception->getMessage();
        }

        try {
            $description = mapValidateCampaignDescription($formData['description']);
        } catch (DomainException $exception) {
            $errors['description'] = $exception->getMessage();
        }

        if ((string) $currentUser['role'] === 'admin') {
            try {
                $ownerUserId = mapValidatePositiveInt($formData['owner_user_id'], 'Proprietaire');
            } catch (DomainException $exception) {
                $errors['owner_user_id'] = $exception->getMessage();
            }
        } else {
            $ownerUserId = (int) $currentUser['user_id'];
        }

        if ($errors === []) {
            try {
                $campaignId = mapCreateCampaign($name, $description, $ownerUserId, $currentUser);
                mapAddFlash('success', 'Campagne creee avec succes.');
                mapRedirectWithQuery('/campaign.php', ['id' => $campaignId]);
            } catch (DomainException $exception) {
                $errors['general'] = $exception->getMessage();
            }
        }
    }

    mapRenderPageStart('Creer une campagne', $currentUser, 'campaigns');
    ?>
    <section class="section-block section-block-narrow">
      <div class="section-heading">
        <div>
          <p class="eyebrow">Campagnes</p>
          <h2>Nouvelle campagne</h2>
          <p class="muted">Cette campagne servira de base a la future table virtuelle.</p>
        </div>
        <div class="button-row">
          <a class="button button-secondary" href="<?= mapEscape(mapUrl('/index.php#campaigns-section')) ?>">Retour aux campagnes</a>
        </div>
      </div>

      <?php if (isset($errors['general'])): ?>
        <p class="notice notice-error"><?= mapEscape((string) $errors['general']) ?></p>
      <?php endif; ?>

      <form method="post" class="field-stack">
        <?= mapCsrfField() ?>

        <div class="field-group">
          <label for="name">Nom de la campagne</label>
          <input id="name" name="name" type="text" value="<?= mapEscape($formData['name']) ?>" required autofocus>
          <?= mapRenderFieldError($errors, 'name') ?>
        </div>

        <div class="field-group">
          <label for="description">Description</label>
          <textarea id="description" name="description"><?= mapEscape($formData['description']) ?></textarea>
          <?= mapRenderFieldError($errors, 'description') ?>
        </div>

        <?php if ((string) $currentUser['role'] === 'admin'): ?>
          <div class="field-group">
            <label for="owner_user_id">Proprietaire principal</label>
            <select id="owner_user_id" name="owner_user_id" required>
              <option value="">Choisir un proprietaire</option>
              <?php foreach ($ownerOptions as $ownerOption): ?>
                <?php $optionValue = (string) $ownerOption['id']; ?>
                <option value="<?= mapEscape($optionValue) ?>" <?= $formData['owner_user_id'] === $optionValue ? 'selected' : '' ?>>
                  <?= mapEscape((string) $ownerOption['display_name']) ?> (<?= mapEscape(mapRoleLabel((string) $ownerOption['role'])) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <?= mapRenderFieldError($errors, 'owner_user_id') ?>
          </div>
        <?php else: ?>
          <div class="detail-item">
            <span>Proprietaire principal</span>
            <strong><?= mapEscape($currentUser['display_name']) ?></strong>
          </div>
        <?php endif; ?>

        <div class="form-actions">
          <button type="submit" class="button">Creer la campagne</button>
        </div>
      </form>
    </section>
    <?php
    mapRenderPageEnd();
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception, 'map/campaign-create', $currentUser);
}
