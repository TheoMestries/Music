<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

startAuthSession();

if (isAuthenticated()) {
    header('Location: index.php');
    exit;
}

$register = ($_GET['mode'] ?? '') === 'register';
$heading = $register ? 'Créer un compte' : 'Connexion';
$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    try {
        if (!validCsrfToken($_POST['csrf'] ?? null)) {
            throw new DomainException('Le formulaire a expiré. Réessayez.');
        }
        if ($register) {
            $confirmation = is_string($_POST['confirmation'] ?? null) ? $_POST['confirmation'] : '';
            registerMusicUser($username, $password, $confirmation);
        } elseif (!authenticateUser($username, $password)) {
            throw new DomainException('Identifiant ou mot de passe incorrect.');
        }
        header('Location: index.php');
        exit;
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log((string) $exception);
        $error = 'Impossible d’accéder au compte pour le moment.';
    }
}
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $heading ?> - SuSZic</title>
    <link rel="stylesheet" href="styles.css">
  </head>
  <body>
    <div class="login-topbar">
      <button id="theme-toggle" type="button" class="secondary-button">Mode sombre</button>
    </div>
    <main class="login-shell">
      <section class="login-panel">
        <div class="brand login-brand">
          <div class="brand-mark" aria-hidden="true">♪</div>
          <div>
            <h1>SuSZic</h1>
            <p><?= $heading ?></p>
          </div>
        </div>

        <?php if ($error !== ''): ?>
          <p class="login-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form method="post" class="panel-form login-form">
          <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
          <label for="username">Identifiant</label>
          <input id="username" name="username" type="text" autocomplete="username" value="<?= htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $register ? 'minlength="3" maxlength="40"' : '' ?> required autofocus>

          <label for="password">Mot de passe</label>
          <input id="password" name="password" type="password" autocomplete="<?= $register ? 'new-password' : 'current-password' ?>" <?= $register ? 'minlength="8"' : '' ?> required>
          <?php if ($register): ?>
            <label for="confirmation">Confirmer le mot de passe</label>
            <input id="confirmation" name="confirmation" type="password" autocomplete="new-password" required>
          <?php endif; ?>

          <button type="submit" class="secondary-button">
            <?= $register ? 'Créer mon compte' : 'Se connecter' ?>
          </button>
        </form>
        <p>
          <?php if ($register): ?>
            Déjà un compte ? <a href="login.php">Se connecter</a>
          <?php else: ?>
            <a href="login.php?mode=register">Créer un compte</a>
          <?php endif; ?>
        </p>
      </section>
    </main>
    <script src="theme.js?v=1"></script>
  </body>
</html>
