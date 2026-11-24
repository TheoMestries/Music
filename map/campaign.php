<?php
declare(strict_types=1);

require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/campaign-lobby.php';

$currentUser = null;
try {
    $currentUser = mapRequireLogin();
    try {
        $campaignId = mapValidatePositiveInt($_GET['id'] ?? null,'Identifiant de partie');
    } catch (DomainException) {
        mapThrowNotFound('Partie introuvable.');
    }
    $access = mapRequireCampaignAccess($campaignId,$currentUser);
    $campaign = $access['campaign'];
    $managementQuery = ['id'=>$campaignId,'view'=>'manage'];
    $archived = (bool)$campaign['is_archived'];
    if (!mapIsPostRequest() && ($_GET['view'] ?? null) !== 'manage') {
        if ($archived) mapRedirectWithQuery('/campaign.php',$managementQuery);
        $members = mapGetCampaignMembers($campaignId);
        mapRenderPageStart($campaign['name'],$currentUser,'campaigns',isTable:true);
        require __DIR__.'/includes/table-workspace.php';
        mapRenderPageEnd(['/js/table-diagnostics.js','/js/board-renderer.js','/js/board-navigation.js','/js/board-settings.js','/js/board-alignment.js','/js/board-drawing-tools.js','/js/board-measure-tools.js','/js/board-entity-tools.js','/js/board-token-tools.js','/js/board-selection-tools.js','/js/board-context-menu.js','/js/board-turns.js','/js/board-pages.js','/js/table-workspace.js','/js/board-chat.js']);
        return;
    }

    $name = $campaign['name'];
    $description = $campaign['description'] ?? '';
    $ownerId = (int)$campaign['owner_user_id'];
    $error = null;
    $editingSettings = false;
    if (mapIsPostRequest()) {
        mapEnforceCsrfToken();
        try {
            $action = $_POST['action'] ?? '';
            switch ($action) {
                case 'update_campaign':
                    $editingSettings = true;
                    mapRequireCampaignOwnerOrAdmin($campaignId,$currentUser);
                    $name = trim((string)($_POST['name'] ?? ''));
                    $description = trim((string)($_POST['description'] ?? ''));
                    $ownerId = mapValidatePositiveInt($_POST['owner_user_id'] ?? $ownerId,'Propriétaire');
                    if ($currentUser['role'] === 'player' && $ownerId !== (int)$campaign['owner_user_id']) {
                        mapThrowForbidden('Ce compte ne peut pas transférer la partie.');
                    }
                    $cover = mapCampaignCoverUpload($_FILES['cover'] ?? null,isset($_POST['remove_cover']));
                    mapUpdateCampaignDetails($campaignId,$name,$description,$ownerId,$currentUser,$cover);
                    if (mapGetCampaignAccess($campaignId,(int)$currentUser['user_id']) === null) mapRedirect('/index.php');
                    mapAddFlash('success','Paramètres enregistrés.');
                    break;
                case 'create_invite':
                    mapCreateCampaignInvite($campaignId,$currentUser);
                    break;
                case 'revoke_invite':
                    mapRequireCampaignOwnerOrAdmin($campaignId,$currentUser);
                    mapGetDatabase()->prepare('DELETE FROM campaign_invites WHERE campaign_id=?')->execute([$campaignId]);
                    break;
                case 'archive_campaign':
                case 'restore_campaign':
                    mapSetCampaignArchivedState($campaignId,$action === 'archive_campaign',$currentUser);
                    break;
                case 'add_member':
                case 'update_member_role':
                    $memberId = mapValidatePositiveInt($_POST['user_id'] ?? null,'Utilisateur');
                    $role = mapValidateCampaignMemberRole((string)($_POST['member_role'] ?? ''));
                    if ($action === 'add_member') {
                        if ($currentUser['role'] === 'player') mapThrowForbidden('Utilisez un lien d’invitation.');
                        mapAddCampaignMember($campaignId,$memberId,$role,$currentUser);
                    }
                    else mapUpdateCampaignMemberRole($campaignId,$memberId,$role,$currentUser);
                    break;
                case 'remove_member':
                    mapRemoveCampaignMember($campaignId,mapValidatePositiveInt($_POST['user_id'] ?? null,'Utilisateur'),$currentUser);
                    break;
                default:
                    throw new DomainException('Action inconnue.');
            }
            mapRedirectWithQuery('/campaign.php',$managementQuery);
        } catch (DomainException $exception) {
            $error = $exception->getMessage();
        }
    }

    $canManage = $access['can_manage_campaign'];
    $members = mapGetCampaignMembers($campaignId);
    $owners = $canManage && $currentUser['role'] !== 'player' ? mapListCampaignOwnerOptions($currentUser) : [];
    $eligible = $canManage && $currentUser['role'] !== 'player' ? mapListEligibleCampaignMembers($campaignId) : [];
    $invite = $canManage && !$archived ? mapGetCampaignInvite($campaignId) : null;
    $inviteUrl = $invite === null ? null : mapUrlWithQuery('/campaign-join.php',['invite'=>$invite]);
    mapRenderPageStart($campaign['name'],$currentUser,'campaigns');
    ?>
    <link rel="stylesheet" href="<?= mapEscape(mapUrl('/css/campaign-lobby.css?v=1')) ?>">
    <div class="lobby-detail">
      <section class="lobby-hero" aria-labelledby="lobby-title">
        <div class="lobby-cover">
          <?php if ($campaign['has_cover']): ?><img alt="" src="<?= mapEscape(mapUrlWithQuery('/campaign-cover.php',['id'=>$campaignId])) ?>"><?php endif; ?>
        </div>
        <div class="lobby-hero-body">
          <a href="<?= mapEscape(mapUrl('/index.php#campaigns-section')) ?>">← Mes parties</a>
          <h2 id="lobby-title"><?= mapEscape($campaign['name']) ?></h2>
          <?php if ($campaign['description']): ?><p class="lobby-description"><?= mapEscape($campaign['description']) ?></p><?php endif; ?>
          <?php if ($error !== null): ?><p class="notice notice-error" role="alert"><?= mapEscape($error) ?></p><?php endif; ?>
          <?php if (!$archived): ?>
            <a class="button" href="<?= mapEscape(mapUrlWithQuery('/campaign.php',['id'=>$campaignId])) ?>">Lancer la partie <span aria-hidden="true">→</span></a>
          <?php else: ?><p class="muted">Partie archivée</p><?php endif; ?>
          <?php if ($canManage): ?>
          <details class="lobby-settings" <?= $editingSettings ? 'open' : '' ?>>
            <summary>Paramètres</summary>
            <form method="post" enctype="multipart/form-data" class="field-stack">
              <?= mapCsrfField() ?><input type="hidden" name="action" value="update_campaign">
              <div class="field-group"><label for="name">Nom de la partie</label><input id="name" name="name" maxlength="120" value="<?= mapEscape($name) ?>" required></div>
              <div class="field-group"><label for="description">Description</label><textarea id="description" name="description" rows="3"><?= mapEscape($description) ?></textarea></div>
              <div class="field-group"><label for="cover">Image de couverture</label><input id="cover" name="cover" type="file" accept="image/png,image/jpeg,image/gif,image/webp"></div>
              <?php if ($campaign['has_cover']): ?><label class="lobby-check"><input type="checkbox" name="remove_cover"> Retirer l’image</label><?php endif; ?>
              <?php if ($owners): ?><div class="field-group"><label for="owner">Propriétaire</label><select id="owner" name="owner_user_id">
                <?php if (!in_array((int)$campaign['owner_user_id'],array_map('intval',array_column($owners,'id')),true)): ?>
                  <option value="<?= (int)$campaign['owner_user_id'] ?>"><?= mapEscape($campaign['owner_display_name']) ?></option>
                <?php endif; ?>
                <?php foreach ($owners as $owner): ?>
                  <option value="<?= (int)$owner['id'] ?>" <?= (int)$owner['id'] === $ownerId ? 'selected' : '' ?>><?= mapEscape($owner['display_name']) ?></option>
                <?php endforeach; ?>
              </select></div><?php endif; ?>
              <button class="button" type="submit">Enregistrer les paramètres</button>
            </form>
            <form method="post" class="lobby-archive-action">
              <?= mapCsrfField() ?>
              <button class="button button-secondary button-small" name="action" value="<?= $archived ? 'restore_campaign' : 'archive_campaign' ?>"><?= $archived ? 'Restaurer la partie' : 'Archiver la partie' ?></button>
            </form>
          </details>
          <?php endif; ?>
        </div>
      </section>
      <aside class="lobby-people" aria-label="Participants">
        <section class="lobby-person-panel">
          <h3>Créée par</h3>
          <p class="lobby-owner"><span aria-hidden="true" class="lobby-avatar"><?= mapEscape(mb_strtoupper(mb_substr($campaign['creator_display_name'],0,1))) ?></span><strong><?= mapEscape($campaign['creator_display_name']) ?></strong></p>
        </section>
        <section class="lobby-person-panel">
          <h3>Participants <span><?= count($members) ?></span></h3>
          <?php if ($canManage && !$archived): ?>
            <?php if ($inviteUrl === null): ?>
              <form method="post"><?= mapCsrfField() ?><button class="button button-small" name="action" value="create_invite">Inviter des joueurs</button></form>
            <?php else: ?>
              <div class="lobby-invite">
                <label for="invite-url">Lien d’invitation</label>
                <input id="invite-url" readonly value="<?= mapEscape($inviteUrl) ?>">
                <button type="button" id="copy-invite" class="button button-small">Copier le lien</button>
                <output id="invite-status" aria-live="polite"></output>
                <form method="post"><?= mapCsrfField() ?><button class="button button-secondary button-small" name="action" value="revoke_invite">Révoquer le lien</button></form>
              </div>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!$members): ?><p class="muted">Aucun participant invité pour le moment.</p><?php else: ?>
            <label for="member-search" class="lobby-search-label">Rechercher un participant</label>
            <input id="member-search" type="search" autocomplete="off">
            <ul class="lobby-members">
              <?php foreach ($members as $member): ?>
                <li data-member>
                  <div><strong data-member-name><?= mapEscape($member['display_name']) ?></strong><small><?= $member['member_role'] === 'gm' ? 'MJ secondaire' : 'Joueur' ?></small></div>
                  <?php if ($canManage): ?>
                  <details><summary>Gérer</summary>
                    <?php if ($member['global_role'] !== 'player' || $member['member_role'] === 'gm'): ?>
                      <form method="post"><?php echo mapCsrfField(); ?><input type="hidden" name="user_id" value="<?= (int)$member['user_id'] ?>"><input type="hidden" name="member_role" value="<?= $member['member_role'] === 'gm' ? 'player' : 'gm' ?>"><button class="button button-secondary button-small" name="action" value="update_member_role"><?= $member['member_role'] === 'gm' ? 'Passer joueur' : 'Passer MJ secondaire' ?></button></form>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('Retirer ce participant de la partie ?');"><?= mapCsrfField() ?><input type="hidden" name="user_id" value="<?= (int)$member['user_id'] ?>"><button class="button button-secondary button-small" name="action" value="remove_member">Retirer</button></form>
                  </details>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if ($eligible && !$archived): ?>
            <details class="lobby-settings"><summary>Ajouter un compte existant</summary>
              <form method="post" class="field-stack">
                <?= mapCsrfField() ?><input type="hidden" name="action" value="add_member">
                <label for="add-member">Compte</label><select id="add-member" name="user_id" required><?php foreach ($eligible as $option): ?><option value="<?= (int)$option['id'] ?>"><?= mapEscape($option['display_name'].' · '.$option['username']) ?></option><?php endforeach; ?></select>
                <label for="add-role">Rôle</label><select id="add-role" name="member_role"><option value="player">Joueur</option><option value="gm">MJ secondaire</option></select>
                <button class="button button-small" type="submit">Ajouter</button>
              </form>
            </details>
          <?php endif; ?>
        </section>
      </aside>
    </div>
    <?php
    mapRenderPageEnd(['/js/campaign-lobby.js']);
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception,'map/campaign',$currentUser);
}
