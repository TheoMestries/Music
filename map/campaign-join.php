<?php
declare(strict_types=1);
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/campaign-lobby.php';

$user = null;
$error = null;
try {
    header('Referrer-Policy: no-referrer');
    $invite = mapInvitationToken($_GET['invite'] ?? null);
    if (isset($_GET['invite']) && $invite === null) mapThrowNotFound('Ce lien d’invitation est invalide.');
    if ($invite !== null) $campaign = mapFindInvitedCampaign($invite);
    $user = mapGetCurrentUser();
    if ($user === null) {
        mapRedirectWithQuery('/login.php',$invite === null ? [] : ['invite'=>$invite]);
    }
    if ($invite !== null && mapGetCampaignAccess((int)$campaign['id'],(int)$user['user_id'])) {
        mapRedirectWithQuery('/campaign.php',['id'=>(int)$campaign['id'],'view'=>'manage']);
    }
    if (mapIsPostRequest()) {
        mapEnforceCsrfToken();
        if ($invite !== null) {
            $campaignId = mapAcceptCampaignInvite($invite,$user);
            mapRedirectWithQuery('/campaign.php',['id'=>$campaignId,'view'=>'manage']);
        }
        $link = trim((string)($_POST['link'] ?? ''));
        $query = [];
        parse_str((string)parse_url($link,PHP_URL_QUERY),$query);
        $token = mapInvitationToken($query['invite'] ?? $link);
        if ($token !== null) mapRedirectWithQuery('/campaign-join.php',['invite'=>$token]);
        $error = 'Collez un lien d’invitation valide.';
    }
    mapRenderPageStart('Rejoindre une partie',$user,'campaigns');
    ?>
    <section class="section-block section-block-narrow">
      <h2><?= $invite === null ? 'Rejoindre une partie' : mapEscape((string)$campaign['name']) ?></h2>
      <?php if ($error !== null): ?><p class="notice notice-error" role="alert"><?= mapEscape($error) ?></p><?php endif; ?>
      <form method="post" class="field-stack">
        <?= mapCsrfField() ?>
        <?php if ($invite === null): ?>
          <div class="field-group"><label for="invite-link">Lien d’invitation</label><input id="invite-link" name="link" maxlength="2048" required autofocus></div>
        <?php else: ?>
          <p class="muted">Vous avez été invité à rejoindre cette partie comme joueur.</p>
        <?php endif; ?>
        <div class="button-row"><button type="submit" class="button">Rejoindre la partie</button><a href="<?= mapEscape(mapUrl('/index.php')) ?>">Mes parties</a></div>
      </form>
    </section>
    <?php
    mapRenderPageEnd();
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception,'map/campaign-join',$user);
}
