<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$currentUser = null;
$errors = [];
$formData = [
    'username' => '',
    'display_name' => '',
    'role' => 'player',
];

try {
    $currentUser = mapRequireAdmin();

    if (mapIsPostRequest()) {
        mapEnforceCsrfToken();

        $formData['username'] = trim((string) ($_POST['username'] ?? ''));
        $formData['display_name'] = trim((string) ($_POST['display_name'] ?? ''));
        $formData['role'] = (string) ($_POST['role'] ?? 'player');
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

        try {
            $username = mapValidateUsername($formData['username']);
        } catch (DomainException $exception) {
            $errors['username'] = $exception->getMessage();
        }

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
            try {
                $userId = mapCreateUser($username, $displayName, $role, $validatedPassword);
                mapAddFlash('success', 'Utilisateur cree avec succes.');
                mapRedirectWithQuery('/admin/user-edit.php', ['id' => $userId]);
            } catch (DomainException $exception) {
                $errors['username'] = $exception->getMessage();
            }
        }
    }

    mapRenderPageStart('Creer un utilisateur', $currentUser, 'users');
    ?>
    <section class="section-block section-block-narrow">
      <div class="section-heading">
        <div>
          <p class="eyebrow">Administration</p>
          <h2>Creer un utilisateur</h2>
          <p class="muted">Les comptes peuvent etre crees avec les roles administrateur, MJ ou joueur.</p>
        </div>
        <div class="button-row">
          <a class="button button-secondary" href="<?= mapEscape(mapUrl('/admin/users.php')) ?>">Retour a la liste</a>
        </div>
      </div>

      <form method="post" class="field-stack" autocomplete="off">
        <?= mapCsrfField() ?>

        <div class="field-group">
          <label for="username">Nom d utilisateur</label>
          <input id="username" name="username" type="text" value="<?= mapEscape($formData['username']) ?>" required autofocus>
          <?= mapRenderFieldError($errors, 'username') ?>
        </div>

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

        <div class="field-group">
          <label for="password">Mot de passe</label>
          <input id="password" name="password" type="password" required>
          <?= mapRenderFieldError($errors, 'password') ?>
        </div>

        <div class="field-group">
          <label for="password_confirmation">Confirmation du mot de passe</label>
          <input id="password_confirmation" name="password_confirmation" type="password" required>
          <?= mapRenderFieldError($errors, 'password_confirmation') ?>
        </div>

        <div class="form-actions">
          <button type="submit" class="button">Creer le compte</button>
        </div>
      </form>
    </section>
    <?php
    mapRenderPageEnd();
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception, 'map/admin/user-create', $currentUser);
}
