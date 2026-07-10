<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$currentUser = null;

try {
    $currentUser = mapRequireLogin();
    $campaigns = mapListCampaignsForUser($currentUser);
    $activeCampaigns = [];
    $archivedCampaigns = [];

    foreach ($campaigns as $campaign) {
        $isArchived = (int) $campaign['is_archived'] === 1;

        if ($isArchived) {
            if ((string) $currentUser['role'] !== 'player') {
                $archivedCampaigns[] = $campaign;
            }
            continue;
        }

        $activeCampaigns[] = $campaign;
    }

    $canCreateCampaign = mapCanCreateCampaign($currentUser);
    mapRenderPageStart('Accueil', $currentUser, 'home');
    ?>
    <section class="hero-grid">
      <section class="section-block">
        <p class="eyebrow">Session active</p>
        <h2><?= mapEscape($currentUser['display_name']) ?></h2>
        <p class="muted">Role global : <span class="<?= mapEscape(mapBadgeClass('role', (string) $currentUser['role'])) ?>"><?= mapEscape(mapRoleLabel((string) $currentUser['role'])) ?></span></p>
        <p class="muted">Les campagnes ci-dessous definissent deja les acces et les membres de la future table virtuelle.</p>
        <div class="form-actions">
          <?php if ($canCreateCampaign): ?>
            <a class="button" href="<?= mapEscape(mapUrl('/campaign-create.php')) ?>">Creer une campagne</a>
          <?php endif; ?>
          <?php if ((string) $currentUser['role'] === 'admin'): ?>
            <a class="button button-secondary" href="<?= mapEscape(mapUrl('/admin/users.php')) ?>">Gerer les utilisateurs</a>
          <?php endif; ?>
        </div>
      </section>

      <section class="section-block">
        <p class="eyebrow">Vue d ensemble</p>
        <div class="stats-grid">
          <div class="stat-card">
            <span class="muted">Campagnes actives</span>
            <strong><?= mapEscape((string) count($activeCampaigns)) ?></strong>
          </div>
          <div class="stat-card">
            <span class="muted">Archives visibles</span>
            <strong><?= mapEscape((string) count($archivedCampaigns)) ?></strong>
          </div>
          <div class="stat-card">
            <span class="muted">Acces total</span>
            <strong><?= mapEscape((string) count($campaigns)) ?></strong>
          </div>
        </div>
      </section>
    </section>

    <section id="campaigns-section" class="section-block">
      <div class="section-heading">
        <div>
          <p class="eyebrow">Campagnes</p>
          <h2>Campagnes accessibles</h2>
          <p class="muted">Chaque carte ci-dessous respecte les permissions globales et de campagne.</p>
        </div>
      </div>

      <?php if ($activeCampaigns === []): ?>
        <div class="empty-state">Aucune campagne active accessible pour le moment.</div>
      <?php else: ?>
        <div class="campaign-grid">
          <?php foreach ($activeCampaigns as $campaign): ?>
            <article class="campaign-card">
              <h3><?= mapEscape((string) $campaign['name']) ?></h3>
              <p class="muted"><?= mapEscape(mapExcerpt((string) ($campaign['description'] ?? ''), 160)) ?></p>
              <div class="campaign-meta">
                <span>Proprietaire : <?= mapEscape((string) $campaign['owner_display_name']) ?><?= (int) $campaign['owner_is_active'] !== 1 ? ' (desactive)' : '' ?></span>
                <span>Votre role : <span class="<?= mapEscape(mapBadgeClass('access', (string) $campaign['current_user_role'])) ?>"><?= mapEscape((string) $campaign['current_user_role_label']) ?></span></span>
                <span>Membres : <?= mapEscape((string) $campaign['member_count']) ?></span>
                <span>Derniere mise a jour : <?= mapEscape(mapFormatDateTime((string) $campaign['updated_at'])) ?></span>
              </div>
              <div class="campaign-actions">
                <a class="button button-secondary" href="<?= mapEscape(mapUrlWithQuery('/campaign.php', ['id' => (int) $campaign['id']])) ?>">
                  <?= in_array((string) $campaign['current_user_role'], ['admin', 'owner'], true) ? 'Gerer' : 'Ouvrir' ?>
                </a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <?php if ((string) $currentUser['role'] !== 'player'): ?>
      <section class="section-block">
        <div class="section-heading">
          <div>
            <p class="eyebrow">Archives</p>
            <h2>Campagnes archivees</h2>
          </div>
        </div>

        <?php if ($archivedCampaigns === []): ?>
          <div class="empty-state">Aucune campagne archivee visible.</div>
        <?php else: ?>
          <div class="campaign-grid">
            <?php foreach ($archivedCampaigns as $campaign): ?>
              <article class="campaign-card">
                <h3><?= mapEscape((string) $campaign['name']) ?></h3>
                <p class="muted"><?= mapEscape(mapExcerpt((string) ($campaign['description'] ?? ''), 160)) ?></p>
                <div class="campaign-meta">
                  <span><span class="<?= mapEscape(mapBadgeClass('status', 'archived')) ?>">Archivee</span></span>
                  <span>Proprietaire : <?= mapEscape((string) $campaign['owner_display_name']) ?><?= (int) $campaign['owner_is_active'] !== 1 ? ' (desactive)' : '' ?></span>
                  <span>Votre role : <span class="<?= mapEscape(mapBadgeClass('access', (string) $campaign['current_user_role'])) ?>"><?= mapEscape((string) $campaign['current_user_role_label']) ?></span></span>
                  <span>Membres : <?= mapEscape((string) $campaign['member_count']) ?></span>
                </div>
                <div class="campaign-actions">
                  <a class="button button-secondary" href="<?= mapEscape(mapUrlWithQuery('/campaign.php', ['id' => (int) $campaign['id']])) ?>">Ouvrir</a>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
    <?php
    mapRenderPageEnd();
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception, 'map/index', $currentUser);
}
