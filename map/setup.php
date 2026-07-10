<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$error = '';
$formData = [
    'username' => '',
    'display_name' => '',
];

try {
    if (mapUsersExist()) {
        mapRedirectWithQuery('/login.php', ['setup' => 'locked']);
    }

    if (mapIsPostRequest()) {
        mapEnforceCsrfToken();

        $formData['username'] = trim((string) ($_POST['username'] ?? ''));
        $formData['display_name'] = trim((string) ($_POST['display_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

        $username = mapValidateUsername($formData['username']);
        $displayName = mapValidateDisplayName($formData['display_name']);
        $validatedPassword = mapValidateInitialPassword($password, $passwordConfirmation);

        mapCreateInitialAdministrator($username, $displayName, $validatedPassword);
        mapRedirectWithQuery('/login.php', ['setup' => 'complete']);
    }
} catch (DomainException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('[map/setup] ' . $exception->getMessage());
    $error = 'Une erreur interne est survenue. Reessayez plus tard.';
}
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= mapEscape(MAP_APP_NAME) ?> - Initialisation</title>
    <link rel="stylesheet" href="./css/map.css">
  </head>
  <body>
    <main class="page-shell">
      <section class="panel panel-narrow">
        <p class="eyebrow">Configuration initiale</p>
        <h1><?= mapEscape(MAP_APP_NAME) ?></h1>
        <p class="muted">Creation du premier compte administrateur. Cette page sera verrouillee des qu un utilisateur existera.</p>

        <?php if ($error !== ''): ?>
          <p class="notice notice-error"><?= mapEscape($error) ?></p>
        <?php endif; ?>

        <form method="post" class="field-stack" autocomplete="off">
          <?= mapCsrfField() ?>

          <div class="field-group">
            <label for="username">Nom d utilisateur</label>
            <input id="username" name="username" type="text" value="<?= mapEscape($formData['username']) ?>" required autofocus>
          </div>

          <div class="field-group">
            <label for="display_name">Nom affiche</label>
            <input id="display_name" name="display_name" type="text" value="<?= mapEscape($formData['display_name']) ?>" required>
          </div>

          <div class="field-group">
            <label for="password">Mot de passe</label>
            <input id="password" name="password" type="password" required>
          </div>

          <div class="field-group">
            <label for="password_confirmation">Confirmation du mot de passe</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required>
          </div>

          <button type="submit" class="button">Creer l administrateur</button>
        </form>

        <p class="helper-text"><a href="./login.php">Retour a la connexion</a></p>
      </section>
    </main>
  </body>
</html>
