<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$error = '';
$info = '';
$username = '';
$hasUsers = false;
$flashMessages = [];

try {
    mapRedirectIfAuthenticated();
    $hasUsers = mapUsersExist();
    $flashMessages = mapConsumeFlashMessages();

    if (isset($_GET['setup']) && $_GET['setup'] === 'complete') {
        $info = 'Le premier administrateur a ete cree. Vous pouvez maintenant vous connecter.';
    } elseif (isset($_GET['setup']) && $_GET['setup'] === 'locked') {
        $info = 'L initialisation est deja terminee. Connectez vous avec un compte existant.';
    }

    if (mapIsPostRequest()) {
        mapEnforceCsrfToken();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (!$hasUsers) {
            $error = 'Aucun compte n existe encore. Lancez d abord la configuration initiale.';
        } else {
            mapAuthenticateUser($username, $password);
            mapRedirect('/index.php');
        }
    }
} catch (DomainException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('[map/login] ' . $exception->getMessage());
    $error = 'Une erreur interne est survenue. Reessayez plus tard.';
}
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= mapEscape(MAP_APP_NAME) ?> - Connexion</title>
    <link rel="stylesheet" href="./css/map.css">
  </head>
  <body>
    <main class="page-shell">
      <section class="panel panel-narrow">
        <p class="eyebrow">Outil de cartes JDR</p>
        <h1><?= mapEscape(MAP_APP_NAME) ?></h1>
        <p class="muted">Connexion au module de cartes, completement separe de l outil musical.</p>

        <?php foreach ($flashMessages as $flash): ?>
          <?php if (!is_array($flash)) { continue; } ?>
          <?php $flashClass = ($flash['type'] ?? '') === 'error' ? 'notice notice-error' : (($flash['type'] ?? '') === 'success' ? 'notice notice-success' : 'notice notice-info'); ?>
          <p class="<?= mapEscape($flashClass) ?>"><?= mapEscape((string) ($flash['message'] ?? '')) ?></p>
        <?php endforeach; ?>

        <?php if ($info !== ''): ?>
          <p class="notice notice-info"><?= mapEscape($info) ?></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
          <p class="notice notice-error"><?= mapEscape($error) ?></p>
        <?php endif; ?>

        <form method="post" class="form-grid" autocomplete="off">
          <?= mapCsrfField() ?>
          <div class="field-group">
            <label for="username">Nom d utilisateur</label>
            <input id="username" name="username" type="text" value="<?= mapEscape($username) ?>" required autofocus>
          </div>

          <div class="field-group">
            <label for="password">Mot de passe</label>
            <input id="password" name="password" type="password" required>
          </div>

          <button type="submit" class="button">Se connecter</button>
        </form>

        <?php if (!$hasUsers): ?>
          <p class="helper-text">Aucun compte configure. <a href="./setup.php">Creer le premier administrateur</a>.</p>
        <?php endif; ?>
      </section>
    </main>
  </body>
</html>
