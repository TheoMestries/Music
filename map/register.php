<?php
declare(strict_types=1);
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/campaign-lobby.php';

$invite = mapInvitationToken($_GET['invite'] ?? null);
$username = $displayName = '';
$error = null;
try {
    if (mapGetCurrentUser() !== null) mapFinishAccountEntry($invite);
    if (!mapUsersExist()) mapRedirectWithQuery('/setup.php', mapAccountEntryQuery($invite));
    if (mapIsPostRequest()) {
        mapEnforceCsrfToken();
        $username = trim((string)($_POST['username'] ?? ''));
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $password = mapValidateInitialPassword((string)($_POST['password'] ?? ''),(string)($_POST['confirmation'] ?? ''));
        $id = mapCreateUser($username,$displayName,'player',$password);
        $user = mapFindUserById($id);
        if ($user === null) throw new RuntimeException('Compte introuvable après inscription.');
        mapLoginUser($user);
        mapFinishAccountEntry($invite);
    }
} catch (DomainException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception,'map/register');
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Créer un compte - <?= mapEscape(MAP_APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= mapEscape(mapUrl('/css/map.css')) ?>">
</head>
<body>
<main class="page-shell">
  <section class="panel panel-narrow">
    <h1>Créer un compte</h1>
    <?php if ($error !== null): ?><p class="notice notice-error" role="alert"><?= mapEscape($error) ?></p><?php endif; ?>
    <form class="form-grid" method="post">
      <?= mapCsrfField() ?>
      <div class="field-group"><label for="display-name">Nom affiché</label><input id="display-name" name="display_name" autocomplete="nickname" maxlength="80" value="<?= mapEscape($displayName) ?>" required autofocus></div>
      <div class="field-group"><label for="username">Identifiant</label><input id="username" name="username" autocomplete="username" minlength="3" maxlength="40" pattern="[a-zA-Z0-9_\-]{3,40}" value="<?= mapEscape($username) ?>" required></div>
      <div class="field-group"><label for="password">Mot de passe</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required></div>
      <div class="field-group"><label for="confirmation">Confirmer le mot de passe</label><input id="confirmation" name="confirmation" type="password" autocomplete="new-password" required></div>
      <button class="button" type="submit">Créer mon compte</button>
    </form>
    <p class="helper-text">Déjà un compte ? <a href="<?= mapEscape(mapUrlWithQuery('/login.php', mapAccountEntryQuery($invite))) ?>">Se connecter</a></p>
  </section>
</main>
</body>
</html>
