<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$currentUser = null;
$errors = [];

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

    if (mapIsPostRequest()) {
        mapEnforceCsrfToken();
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

        try {
            $validatedPassword = mapValidateInitialPassword($password, $passwordConfirmation);
        } catch (DomainException $exception) {
            if (strlen($password) < 8) {
                $errors['password'] = $exception->getMessage();
            } else {
                $errors['password_confirmation'] = $exception->getMessage();
            }
        }

        if ($errors === []) {
            mapResetUserPassword($userId, $validatedPassword);
            mapAddFlash('success', 'Mot de passe reinitialise avec succes.');
            mapRedirectWithQuery('/admin/user-edit.php', ['id' => $userId]);
        }
    }

    mapRenderPageStart('Reinitialiser le mot de passe', $currentUser, 'users');
    ?>
    <section class="section-block section-block-narrow">
      <div class="section-heading">
        <div>
          <p class="eyebrow">Administration</p>
          <h2>Reinitialiser le mot de passe</h2>
          <p class="muted">Utilisateur cible : <span class="monospace"><?= mapEscape((string) $targetUser['username']) ?></span></p>
        </div>
        <div class="button-row">
          <a class="button button-secondary" href="<?= mapEscape(mapUrlWithQuery('/admin/user-edit.php', ['id' => $userId])) ?>">Retour au profil</a>
        </div>
      </div>

      <form method="post" class="field-stack" autocomplete="off" onsubmit="return confirm('Confirmer la reinitialisation du mot de passe ?');">
        <?= mapCsrfField() ?>

        <div class="field-group">
          <label for="password">Nouveau mot de passe</label>
          <input id="password" name="password" type="password" required>
          <?= mapRenderFieldError($errors, 'password') ?>
        </div>

        <div class="field-group">
          <label for="password_confirmation">Confirmation du nouveau mot de passe</label>
          <input id="password_confirmation" name="password_confirmation" type="password" required>
          <?= mapRenderFieldError($errors, 'password_confirmation') ?>
        </div>

        <div class="form-actions">
          <button type="submit" class="button">Mettre a jour le mot de passe</button>
        </div>
      </form>
    </section>
    <?php
    mapRenderPageEnd();
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception, 'map/admin/user-password', $currentUser);
}
