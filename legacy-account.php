<?php
declare(strict_types=1);

require_once __DIR__ . '/campaigns.php';

$user = requireLogin();
header('Cache-Control: no-store');
$error = '';
$username = '';

if (mapIsPostRequest()) {
    try {
        mapEnforceCsrfToken();
        $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        musicClaimLegacyAccount($user['user_id'], $username, $password);
        header('Location: ' . mapSiteUrl());
        exit;
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log((string) $exception);
        $error = 'Impossible de récupérer les projets pour le moment.';
    }
}
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Récupérer mes projets - SuSZic</title>
    <link rel="stylesheet" href="styles.css">
  </head>
  <body>
    <main class="login-shell">
      <section class="login-panel">
        <h1>Récupérer mes anciens projets</h1>
        <p>Utilise les identifiants de ton ancien compte SuSZic pour rattacher ses projets et son lien d’écoute au compte Sus20 <strong><?= mapEscape($user['username']) ?></strong>.</p>
        <?php if ($error !== ''): ?>
          <p class="login-error" role="alert"><?= mapEscape($error) ?></p>
        <?php endif; ?>
        <form class="panel-form login-form" method="post">
          <?= mapCsrfField() ?>
          <label for="username">Ancien identifiant SuSZic</label>
          <input id="username" name="username" type="text" autocomplete="username" value="<?= mapEscape($username) ?>" required autofocus>
          <label for="password">Ancien mot de passe SuSZic</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required>
          <button class="secondary-button" type="submit">Récupérer mes projets</button>
        </form>
        <p><a href="<?= mapEscape(mapSiteUrl()) ?>">Retour à SuSZic</a></p>
      </section>
    </main>
    <script src="theme.js?v=1"></script>
  </body>
</html>
