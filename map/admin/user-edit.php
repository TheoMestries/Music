<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$currentUser = null;
$errors = [];
$statusError = '';

try {
    $currentUser = mapRequireAdmin();
    try {
        $userId = mapValidatePositiveInt($_GET['id'] ?? null, 'Identifiant utilisateur');
    } catch (DomainException) {
        mapThrowNotFound('Utilisateur introuvable.');
    }
    $targetUser = mapFindUserById($userId);

    if ($targetUser === null) {
        mapThrowNotFound('Utilisateur introuvable.');
    }

    $formData = [
        'display_name' => (string) $targetUser['display_name'],
        'role' => (string) $targetUser['role'],
    ];

    if (mapIsPostRequest()) {
        mapEnforceCsrfToken();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'profile') {
            $formData['display_name'] = trim((string) ($_POST['display_name'] ?? ''));
            $formData['role'] = (string) ($_POST['role'] ?? $targetUser['role']);

            try {
                $displayName = mapValidateDisplayName($formData['display_name']);
            } catch (DomainException $exception) {
                $errors['display_name'] = $exception->getMessage();
            }

            try {
                $role = mapValidateRole($formData['role']);
            } catch (DomainException $exception) {
                $errors['role'] = $exception->getMessage();
            }

            if ($errors === []) {
                try {
                    mapUpdateUserProfile($userId, $displayName, $role, (int) $currentUser['user_id']);
                    mapAddFlash('success', 'Profil utilisateur mis a jour.');

                    if ((int) $currentUser['user_id'] === $userId && $role !== 'admin') {
                        mapRedirect('/index.php');
                    }

                    mapRedirectWithQuery('/admin/user-edit.php', ['id' => $userId]);
                } catch (DomainException $exception) {
                    $errors['general'] = $exception->getMessage();
                }
            }
        } elseif ($action === 'status') {
            $desiredState = (string) ($_POST['is_active'] ?? '') === '1';

            try {
                mapSetUserActiveState($userId, $desiredState, (int) $currentUser['user_id']);
                mapAddFlash('success', 'Statut du compte mis a jour.');
                mapRedirectWithQuery('/admin/user-edit.php', ['id' => $userId]);
            } catch (DomainException $exception) {
                $statusError = $exception->getMessage();
            }
        }

        $targetUser = mapFindUserById($userId);
        if ($targetUser === null) {
            mapThrowNotFound('Utilisateur introuvable.');
        }
    }

    mapRenderPageStart('Modifier un utilisateur', $currentUser, 'users');
    ?>
    <div class="split-grid">
      <section class="section-block">
        <div class="section-heading">
          <div>
            <p class="eyebrow">Administration</p>
            <h2>Modifier un utilisateur</h2>
            <p class="muted">Nom d utilisateur : <span class="monospace"><?= mapEscape((string) $targetUser['username']) ?></span></p>
          </div>
          <div class="button-row">
            <a class="button button-secondary" href="<?= mapEscape(mapUrl('/admin/users.php')) ?>">Retour a la liste</a>
            <a class="button button-secondary" href="<?= mapEscape(mapUrlWithQuery('/admin/user-password.php', ['id' => $userId])) ?>">Reinitialiser le mot de passe</a>
          </div>
        </div>

        <div class="detail-list">
          <div class="detail-item">
            <span>Role actuel</span>
            <strong><span class="<?= mapEscape(mapBadgeClass('role', (string) $targetUser['role'])) ?>"><?= mapEscape(mapRoleLabel((string) $targetUser['role'])) ?></span></strong>
          </div>
          <div class="detail-item">
            <span>Statut</span>
            <strong><span class="<?= mapEscape(mapBadgeClass('status', (int) $targetUser['is_active'] === 1 ? 'active' : 'inactive')) ?>"><?= mapEscape(mapStatusLabel((int) $targetUser['is_active'] === 1)) ?></span></strong>
          </div>
          <div class="detail-item">
            <span>Cree le</span>
            <strong><?= mapEscape(mapFormatDateTime((string) $targetUser['created_at'])) ?></strong>
          </div>
          <div class="detail-item">
            <span>Derniere mise a jour</span>
            <strong><?= mapEscape(mapFormatDateTime((string) $targetUser['updated_at'])) ?></strong>
          </div>
        </div>

        <form method="post" class="field-stack" autocomplete="off">
          <?= mapCsrfField() ?>
          <input type="hidden" name="action" value="profile">

          <?php if (isset($errors['general'])): ?>
            <p class="notice notice-error"><?= mapEscape((string) $errors['general']) ?></p>
          <?php endif; ?>

          <div class="field-group">
            <label for="display_name">Nom affiche</label>
            <input id="display_name" name="display_name" type="text" value="<?= mapEscape($formData['display_name']) ?>" required>
            <?= mapRenderFieldError($errors, 'display_name') ?>
          </div>

          <div class="field-group">
            <label for="role">Role global</label>
            <select id="role" name="role" required>
              <?php foreach (['admin', 'gm', 'player'] as $roleOption): ?>
                <option value="<?= mapEscape($roleOption) ?>" <?= $formData['role'] === $roleOption ? 'selected' : '' ?>><?= mapEscape(mapRoleLabel($roleOption)) ?></option>
              <?php endforeach; ?>
            </select>
            <?= mapRenderFieldError($errors, 'role') ?>
          </div>

          <div class="form-actions">
            <button type="submit" class="button">Enregistrer les changements</button>
          </div>
        </form>
      </section>

      <section class="section-block">
        <p class="eyebrow">Statut du compte</p>
        <h2>Activation</h2>
        <p class="muted">La desactivation preserve les relations avec les campagnes tout en bloquant la connexion.</p>

        <?php if ($statusError !== ''): ?>
          <p class="notice notice-error"><?= mapEscape($statusError) ?></p>
        <?php endif; ?>

        <div class="danger-zone">
          <p><strong>Etat actuel :</strong> <?= mapEscape(mapStatusLabel((int) $targetUser['is_active'] === 1)) ?></p>
          <form method="post" class="inline-form" onsubmit="return confirm('Confirmer cette modification du statut du compte ?');">
            <?= mapCsrfField() ?>
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="is_active" value="<?= (int) $targetUser['is_active'] === 1 ? '0' : '1' ?>">
            <button type="submit" class="<?= (int) $targetUser['is_active'] === 1 ? 'button button-danger' : 'button button-secondary' ?>">
              <?= (int) $targetUser['is_active'] === 1 ? 'Desactiver ce compte' : 'Reactiver ce compte' ?>
            </button>
          </form>
        </div>
      </section>
    </div>
    <?php
    mapRenderPageEnd();
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception, 'map/admin/user-edit', $currentUser);
}
