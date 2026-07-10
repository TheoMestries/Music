<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$currentUser = null;
$campaignErrors = [];
$memberErrors = [];

try {
    $currentUser = mapRequireLogin();
    try {
        $campaignId = mapValidatePositiveInt($_GET['id'] ?? null, 'Identifiant de campagne');
    } catch (DomainException) {
        mapThrowNotFound('Campagne introuvable.');
    }
    $access = mapRequireCampaignAccess($campaignId, $currentUser);
    $campaign = $access['campaign'];
    $campaignFormData = [
        'name' => (string) $campaign['name'],
        'description' => (string) ($campaign['description'] ?? ''),
        'owner_user_id' => (string) $campaign['owner_user_id'],
    ];
    $memberFormData = [
        'user_id' => '',
        'member_role' => 'player',
    ];

    if (mapIsPostRequest()) {
        mapEnforceCsrfToken();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'update_campaign') {
            $campaignFormData['name'] = trim((string) ($_POST['name'] ?? ''));
            $campaignFormData['description'] = trim((string) ($_POST['description'] ?? ''));
            $campaignFormData['owner_user_id'] = (string) ($_POST['owner_user_id'] ?? $campaign['owner_user_id']);

            try {
                $name = mapValidateCampaignName($campaignFormData['name']);
            } catch (DomainException $exception) {
                $campaignErrors['name'] = $exception->getMessage();
            }

            try {
                $description = mapValidateCampaignDescription($campaignFormData['description']);
            } catch (DomainException $exception) {
                $campaignErrors['description'] = $exception->getMessage();
            }

            try {
                $ownerUserId = mapValidatePositiveInt($campaignFormData['owner_user_id'], 'Proprietaire');
            } catch (DomainException $exception) {
                $campaignErrors['owner_user_id'] = $exception->getMessage();
            }

            if ($campaignErrors === []) {
                try {
                    mapUpdateCampaignDetails($campaignId, $name, $description, $ownerUserId, $currentUser);
                    mapAddFlash('success', 'Campagne mise a jour.');

                    if ((string) $currentUser['role'] !== 'admin' && $ownerUserId !== (int) $currentUser['user_id']) {
                        mapAddFlash('info', 'La propriete a ete transferee. Vous n avez plus acces a cette campagne.');
                        mapRedirect('/index.php');
                    }

                    mapRedirectWithQuery('/campaign.php', ['id' => $campaignId]);
                } catch (DomainException $exception) {
                    $campaignErrors['general'] = $exception->getMessage();
                }
            }
        } elseif ($action === 'archive_campaign') {
            mapSetCampaignArchivedState($campaignId, true, $currentUser);
            mapAddFlash('success', 'Campagne archivee.');
            mapRedirectWithQuery('/campaign.php', ['id' => $campaignId]);
        } elseif ($action === 'restore_campaign') {
            mapSetCampaignArchivedState($campaignId, false, $currentUser);
            mapAddFlash('success', 'Campagne restauree.');
            mapRedirectWithQuery('/campaign.php', ['id' => $campaignId]);
        } elseif ($action === 'add_member') {
            $memberFormData['user_id'] = (string) ($_POST['user_id'] ?? '');
            $memberFormData['member_role'] = (string) ($_POST['member_role'] ?? 'player');

            try {
                $targetUserId = mapValidatePositiveInt($memberFormData['user_id'], 'Utilisateur');
            } catch (DomainException $exception) {
                $memberErrors['user_id'] = $exception->getMessage();
            }

            try {
                $memberRole = mapValidateCampaignMemberRole($memberFormData['member_role']);
            } catch (DomainException $exception) {
                $memberErrors['member_role'] = $exception->getMessage();
            }

            if ($memberErrors === []) {
                try {
                    mapAddCampaignMember($campaignId, $targetUserId, $memberRole, $currentUser);
                    mapAddFlash('success', 'Membre ajoute a la campagne.');
                    mapRedirectWithQuery('/campaign.php', ['id' => $campaignId]);
                } catch (DomainException $exception) {
                    $memberErrors['general'] = $exception->getMessage();
                }
            }
        } elseif ($action === 'update_member_role') {
            $targetUserId = mapValidatePositiveInt($_POST['user_id'] ?? null, 'Utilisateur');
            $memberRole = mapValidateCampaignMemberRole((string) ($_POST['member_role'] ?? ''));

            try {
                mapUpdateCampaignMemberRole($campaignId, $targetUserId, $memberRole, $currentUser);
                mapAddFlash('success', 'Role de membre mis a jour.');
            } catch (DomainException $exception) {
                mapAddFlash('error', $exception->getMessage());
            }

            mapRedirectWithQuery('/campaign.php', ['id' => $campaignId]);
        } elseif ($action === 'remove_member') {
            $targetUserId = mapValidatePositiveInt($_POST['user_id'] ?? null, 'Utilisateur');

            try {
                mapRemoveCampaignMember($campaignId, $targetUserId, $currentUser);
                mapAddFlash('success', 'Membre retire de la campagne.');
            } catch (DomainException $exception) {
                mapAddFlash('error', $exception->getMessage());
            }

            mapRedirectWithQuery('/campaign.php', ['id' => $campaignId]);
        }

        $access = mapRequireCampaignAccess($campaignId, $currentUser);
        $campaign = $access['campaign'];
    }

    $campaign = $access['campaign'];
    $members = mapGetCampaignMembers($campaignId);
    $secondaryGms = array_values(array_filter($members, static fn(array $member): bool => (string) $member['member_role'] === 'gm'));
    $players = array_values(array_filter($members, static fn(array $member): bool => (string) $member['member_role'] === 'player'));
    $ownerOptions = $access['can_manage_campaign'] ? mapListCampaignOwnerOptions($currentUser) : [];
    $memberOptions = $access['can_manage_members'] ? mapListEligibleCampaignMembers($campaignId) : [];

    mapRenderPageStart((string) $campaign['name'], $currentUser, 'campaigns');
    ?>
    <section class="section-block">
      <div class="section-heading">
        <div>
          <p class="eyebrow">Campagne</p>
          <h2><?= mapEscape((string) $campaign['name']) ?></h2>
          <p class="muted"><?= mapEscape((string) ($campaign['description'] ?: 'Aucune description pour cette campagne.')) ?></p>
        </div>
        <div class="button-row">
          <a class="button button-secondary" href="<?= mapEscape(mapUrl('/index.php#campaigns-section')) ?>">Retour aux campagnes</a>
          <?php if ($access['can_manage_campaign']): ?>
            <a class="button button-secondary" href="<?= mapEscape(mapUrl('/campaign-create.php')) ?>">Nouvelle campagne</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="detail-list">
        <div class="detail-item">
          <span>Role actuel</span>
          <strong><span class="<?= mapEscape(mapBadgeClass('access', (string) $access['current_user_role'])) ?>"><?= mapEscape((string) $access['current_user_role_label']) ?></span></strong>
        </div>
        <div class="detail-item">
          <span>Proprietaire</span>
          <strong><?= mapEscape((string) $campaign['owner_display_name']) ?></strong>
          <span><?= mapEscape(mapRoleLabel((string) $campaign['owner_role'])) ?><?= (int) $campaign['owner_is_active'] !== 1 ? ' - desactive' : '' ?></span>
        </div>
        <div class="detail-item">
          <span>Membres</span>
          <strong><?= mapEscape((string) $campaign['member_count']) ?></strong>
          <span><?= mapEscape((string) count($secondaryGms)) ?> MJ secondaires, <?= mapEscape((string) count($players)) ?> joueurs</span>
        </div>
        <div class="detail-item">
          <span>Etat</span>
          <strong><span class="<?= mapEscape(mapBadgeClass('status', (int) $campaign['is_archived'] === 1 ? 'archived' : 'active')) ?>"><?= (int) $campaign['is_archived'] === 1 ? 'Archivee' : 'Active' ?></span></strong>
          <span>Mise a jour : <?= mapEscape(mapFormatDateTime((string) $campaign['updated_at'])) ?></span>
        </div>
      </div>
    </section>

    <?php if ($access['can_manage_campaign']): ?>
      <div class="section-grid">
        <section class="section-block">
          <div class="section-subheading">
            <div>
              <p class="eyebrow">Parametres</p>
              <h3>Modifier la campagne</h3>
            </div>
          </div>

          <?php if (isset($campaignErrors['general'])): ?>
            <p class="notice notice-error"><?= mapEscape((string) $campaignErrors['general']) ?></p>
          <?php endif; ?>

          <form method="post" class="field-stack">
            <?= mapCsrfField() ?>
            <input type="hidden" name="action" value="update_campaign">

            <div class="field-group">
              <label for="name">Nom</label>
              <input id="name" name="name" type="text" value="<?= mapEscape($campaignFormData['name']) ?>" required>
              <?= mapRenderFieldError($campaignErrors, 'name') ?>
            </div>

            <div class="field-group">
              <label for="description">Description</label>
              <textarea id="description" name="description"><?= mapEscape($campaignFormData['description']) ?></textarea>
              <?= mapRenderFieldError($campaignErrors, 'description') ?>
            </div>

            <div class="field-group">
              <label for="owner_user_id">Proprietaire principal</label>
              <select id="owner_user_id" name="owner_user_id" required>
                <?php foreach ($ownerOptions as $ownerOption): ?>
                  <?php $optionValue = (string) $ownerOption['id']; ?>
                  <option value="<?= mapEscape($optionValue) ?>" <?= $campaignFormData['owner_user_id'] === $optionValue ? 'selected' : '' ?>>
                    <?= mapEscape((string) $ownerOption['display_name']) ?> (<?= mapEscape(mapRoleLabel((string) $ownerOption['role'])) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <?= mapRenderFieldError($campaignErrors, 'owner_user_id') ?>
            </div>

            <div class="form-actions">
              <button type="submit" class="button">Enregistrer les changements</button>
            </div>
          </form>
        </section>

        <section class="section-block">
          <div class="section-subheading">
            <div>
              <p class="eyebrow">Archivage</p>
              <h3>Etat de la campagne</h3>
            </div>
          </div>

          <p class="muted">L archivage retire la campagne de la liste principale des joueurs sans supprimer les membres.</p>
          <div class="danger-zone">
            <p><strong>Etat actuel :</strong> <?= (int) $campaign['is_archived'] === 1 ? 'Archivee' : 'Active' ?></p>
            <form method="post" class="inline-form" onsubmit="return confirm('Confirmer cette operation sur la campagne ?');">
              <?= mapCsrfField() ?>
              <input type="hidden" name="action" value="<?= (int) $campaign['is_archived'] === 1 ? 'restore_campaign' : 'archive_campaign' ?>">
              <button type="submit" class="<?= (int) $campaign['is_archived'] === 1 ? 'button button-secondary' : 'button button-warning' ?>">
                <?= (int) $campaign['is_archived'] === 1 ? 'Restaurer la campagne' : 'Archiver la campagne' ?>
              </button>
            </form>
          </div>
        </section>
      </div>
    <?php endif; ?>

    <div class="member-grid">
      <section class="section-block">
        <div class="section-subheading">
          <div>
            <p class="eyebrow">Equipe MJ</p>
            <h3>MJ secondaires</h3>
          </div>
        </div>

        <?php if ($secondaryGms === []): ?>
          <div class="empty-state">Aucun MJ secondaire pour cette campagne.</div>
        <?php else: ?>
          <div class="field-stack">
            <?php foreach ($secondaryGms as $member): ?>
              <article class="member-card">
                <h3><?= mapEscape((string) $member['display_name']) ?></h3>
                <div class="campaign-meta">
                  <span class="monospace"><?= mapEscape((string) $member['username']) ?></span>
                  <span><span class="<?= mapEscape(mapBadgeClass('role', (string) $member['global_role'])) ?>"><?= mapEscape(mapRoleLabel((string) $member['global_role'])) ?></span></span>
                  <span><span class="<?= mapEscape(mapBadgeClass('status', (int) $member['is_active'] === 1 ? 'active' : 'inactive')) ?>"><?= mapEscape(mapStatusLabel((int) $member['is_active'] === 1)) ?></span></span>
                </div>

                <?php if ($access['can_manage_members']): ?>
                  <div class="campaign-actions">
                    <form method="post" class="inline-actions">
                      <?= mapCsrfField() ?>
                      <input type="hidden" name="action" value="update_member_role">
                      <input type="hidden" name="user_id" value="<?= (int) $member['user_id'] ?>">
                      <select name="member_role" class="select-inline" required>
                        <option value="gm" selected>MJ secondaire</option>
                        <option value="player">Joueur</option>
                      </select>
                      <button type="submit" class="button button-secondary button-small">Mettre a jour</button>
                    </form>

                    <form method="post" class="inline-form" onsubmit="return confirm('Retirer ce membre de la campagne ?');">
                      <?= mapCsrfField() ?>
                      <input type="hidden" name="action" value="remove_member">
                      <input type="hidden" name="user_id" value="<?= (int) $member['user_id'] ?>">
                      <button type="submit" class="button button-danger button-small">Retirer</button>
                    </form>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="section-block">
        <div class="section-subheading">
          <div>
            <p class="eyebrow">Joueurs</p>
            <h3>Membres joueurs</h3>
          </div>
        </div>

        <?php if ($players === []): ?>
          <div class="empty-state">Aucun joueur membre pour cette campagne.</div>
        <?php else: ?>
          <div class="field-stack">
            <?php foreach ($players as $member): ?>
              <article class="member-card">
                <h3><?= mapEscape((string) $member['display_name']) ?></h3>
                <div class="campaign-meta">
                  <span class="monospace"><?= mapEscape((string) $member['username']) ?></span>
                  <span><span class="<?= mapEscape(mapBadgeClass('role', (string) $member['global_role'])) ?>"><?= mapEscape(mapRoleLabel((string) $member['global_role'])) ?></span></span>
                  <span><span class="<?= mapEscape(mapBadgeClass('status', (int) $member['is_active'] === 1 ? 'active' : 'inactive')) ?>"><?= mapEscape(mapStatusLabel((int) $member['is_active'] === 1)) ?></span></span>
                </div>

                <?php if ($access['can_manage_members']): ?>
                  <div class="campaign-actions">
                    <form method="post" class="inline-actions">
                      <?= mapCsrfField() ?>
                      <input type="hidden" name="action" value="update_member_role">
                      <input type="hidden" name="user_id" value="<?= (int) $member['user_id'] ?>">
                      <select name="member_role" class="select-inline" required>
                        <option value="player" selected>Joueur</option>
                        <option value="gm">MJ secondaire</option>
                      </select>
                      <button type="submit" class="button button-secondary button-small">Mettre a jour</button>
                    </form>

                    <form method="post" class="inline-form" onsubmit="return confirm('Retirer ce membre de la campagne ?');">
                      <?= mapCsrfField() ?>
                      <input type="hidden" name="action" value="remove_member">
                      <input type="hidden" name="user_id" value="<?= (int) $member['user_id'] ?>">
                      <button type="submit" class="button button-danger button-small">Retirer</button>
                    </form>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>

    <?php if ($access['can_manage_members']): ?>
      <section class="section-block section-block-narrow">
        <div class="section-subheading">
          <div>
            <p class="eyebrow">Membres</p>
            <h3>Ajouter un membre</h3>
          </div>
        </div>

        <?php if (isset($memberErrors['general'])): ?>
          <p class="notice notice-error"><?= mapEscape((string) $memberErrors['general']) ?></p>
        <?php endif; ?>

        <?php if ($memberOptions === []): ?>
          <div class="empty-state">Aucun utilisateur actif disponible a ajouter.</div>
        <?php else: ?>
          <form method="post" class="field-stack">
            <?= mapCsrfField() ?>
            <input type="hidden" name="action" value="add_member">

            <div class="field-group">
              <label for="user_id">Utilisateur</label>
              <select id="user_id" name="user_id" required>
                <option value="">Choisir un utilisateur</option>
                <?php foreach ($memberOptions as $memberOption): ?>
                  <?php $optionValue = (string) $memberOption['id']; ?>
                  <option value="<?= mapEscape($optionValue) ?>" <?= $memberFormData['user_id'] === $optionValue ? 'selected' : '' ?>>
                    <?= mapEscape((string) $memberOption['display_name']) ?> (<?= mapEscape((string) $memberOption['username']) ?> - <?= mapEscape(mapRoleLabel((string) $memberOption['role'])) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <?= mapRenderFieldError($memberErrors, 'user_id') ?>
            </div>

            <div class="field-group">
              <label for="member_role">Role dans la campagne</label>
              <select id="member_role" name="member_role" required>
                <option value="player" <?= $memberFormData['member_role'] === 'player' ? 'selected' : '' ?>>Joueur</option>
                <option value="gm" <?= $memberFormData['member_role'] === 'gm' ? 'selected' : '' ?>>MJ secondaire</option>
              </select>
              <?= mapRenderFieldError($memberErrors, 'member_role') ?>
            </div>

            <div class="form-actions">
              <button type="submit" class="button">Ajouter le membre</button>
            </div>
          </form>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="section-block">
      <div class="section-subheading">
        <div>
          <p class="eyebrow">Table virtuelle</p>
          <h3>Emplacement reserve</h3>
        </div>
      </div>
      <p class="muted">Le canvas, les cartes, les pions et les outils temps reel seront developpes dans les prochains commits.</p>
      <div class="form-actions">
        <button type="button" class="button button-secondary" disabled>Table virtuelle bientot disponible</button>
      </div>
    </section>
    <?php
    mapRenderPageEnd();
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception, 'map/campaign', $currentUser);
}
