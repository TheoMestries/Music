<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

startAuthSession();

if (isAuthenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!hasConfiguredUsers()) {
        $error = 'Aucun identifiant serveur configuré.';
    } elseif (authenticateUser($username, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Identifiant ou mot de passe incorrect.';
    }
}
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion - SuSZic</title>
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
            <p>Connexion requise</p>
          </div>
        </div>

        <?php if ($error !== ''): ?>
          <p class="login-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form method="post" class="panel-form login-form" autocomplete="off">
          <label for="username">Identifiant</label>
          <input id="username" name="username" type="text" required autofocus>

          <label for="password">Mot de passe</label>
          <input id="password" name="password" type="password" required>

          <button type="submit" class="secondary-button">
            Se connecter
          </button>
        </form>
      </section>
    </main>
    <script src="theme.js?v=1"></script>
  </body>
</html>
