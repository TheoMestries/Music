<?php
declare(strict_types=1);
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/campaign-lobby.php';

$user = null;
$name = $template = '';
$error = null;
try {
    $user = mapRequireLogin();
    $admin = $user['role'] === 'admin';
    $owners = $admin ? mapListCampaignOwnerOptions($user) : [];
    $templates = mapReusableSheetTemplates(mapGetDatabase(),$user);
    $ownerId = $user['user_id'];
    if (mapIsPostRequest()) {
        mapEnforceCsrfToken();
        $name = trim((string)($_POST['name'] ?? ''));
        $template = (string)($_POST['template_id'] ?? '');
        try {
            if ($admin) $ownerId = mapValidatePositiveInt($_POST['owner_user_id'] ?? null,'Propriétaire');
            $templateId = $template === '' ? null : mapValidatePositiveInt($template,'Modèle de fiche');
            $id = mapCreateCampaignWithTemplate($name,$ownerId,$templateId,$user);
            mapRedirectWithQuery('/campaign.php',['id'=>$id,'view'=>'manage']);
        } catch (DomainException $exception) {
            $error = $exception->getMessage();
        }
    }
    mapRenderPageStart('Nouvelle partie',$user,'campaigns');
    ?>
    <section class="section-block section-block-narrow">
      <h2>Créer une partie</h2>
      <?php if ($error !== null): ?><p class="notice notice-error" role="alert"><?= mapEscape($error) ?></p><?php endif; ?>
      <form method="post" class="field-stack">
        <?= mapCsrfField() ?>
        <div class="field-group"><label for="name">Nom de la partie</label><input id="name" name="name" value="<?= mapEscape($name) ?>" maxlength="120" required autofocus></div>
        <div class="field-group">
          <label for="template-id">Modèle de fiche par défaut</label>
          <select id="template-id" name="template_id">
            <option value="">Aucun modèle</option>
            <?php foreach ($templates as $option): ?>
              <option value="<?= (int)$option['id'] ?>" <?= (string)$option['id'] === $template ? 'selected' : '' ?>><?= mapEscape($option['name'].' · '.($option['campaign_name'] ?? 'Public')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($admin): ?>
          <div class="field-group"><label for="owner-id">Propriétaire</label>
            <select id="owner-id" name="owner_user_id">
              <?php foreach ($owners as $owner): ?>
                <option value="<?= (int)$owner['id'] ?>" <?= (int)$owner['id'] === $ownerId ? 'selected' : '' ?>><?= mapEscape($owner['display_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <div class="button-row"><button class="button" type="submit">Créer la partie</button><a href="<?= mapEscape(mapUrl('/index.php')) ?>">Mes parties</a></div>
      </form>
    </section>
    <?php
    mapRenderPageEnd();
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception,'map/campaign-create',$user);
}
