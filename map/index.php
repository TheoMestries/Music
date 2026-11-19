<?php
declare(strict_types=1);
require_once __DIR__.'/includes/layout.php';

$user = null;
try {
    $user = mapRequireLogin();
    $campaigns = mapListCampaignsForUser($user);
    $active = array_filter($campaigns,static fn($campaign) => !(int)$campaign['is_archived']);
    $archived = array_filter($campaigns,static fn($campaign) => (int)$campaign['is_archived']);
    mapRenderPageStart('Mes parties',$user,'campaigns');
    ?>
    <link rel="stylesheet" href="<?= mapEscape(mapUrl('/css/campaign-lobby.css?v=1')) ?>">
    <section id="campaigns-section" class="lobby-dashboard">
      <header class="lobby-heading">
        <h2>Mes parties</h2>
        <div class="button-row">
          <a class="button" href="<?= mapEscape(mapUrl('/campaign-create.php')) ?>">Créer une partie</a>
          <a class="button button-secondary" href="<?= mapEscape(mapUrl('/campaign-join.php')) ?>">Rejoindre une partie</a>
        </div>
      </header>
      <?php if (!$active): ?>
        <p class="empty-state">Tu n’as encore aucune partie en cours.</p>
      <?php else: ?>
        <div class="lobby-cards">
          <?php foreach ($active as $campaign): ?>
            <?php $id = (int)$campaign['id']; ?>
            <article class="lobby-card">
              <div class="lobby-cover">
                <?php if ($campaign['has_cover']): ?><img alt="" loading="lazy" src="<?= mapEscape(mapUrlWithQuery('/campaign-cover.php',['id'=>$id])) ?>"><?php endif; ?>
              </div>
              <div class="lobby-card-body">
                <p class="lobby-role"><?= mapEscape($campaign['current_user_role_label']) ?></p>
                <h3><a href="<?= mapEscape(mapUrlWithQuery('/campaign.php',['id'=>$id,'view'=>'manage'])) ?>"><?= mapEscape($campaign['name']) ?></a></h3>
                <a class="lobby-launch-link" href="<?= mapEscape(mapUrlWithQuery('/campaign.php',['id'=>$id])) ?>">Lancer la partie <span aria-hidden="true">↗</span></a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($archived): ?>
        <details class="lobby-archives">
          <summary>Parties archivées</summary>
          <ul>
            <?php foreach ($archived as $campaign): ?>
              <li><a href="<?= mapEscape(mapUrlWithQuery('/campaign.php',['id'=>(int)$campaign['id'],'view'=>'manage'])) ?>"><?= mapEscape($campaign['name']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
    </section>
    <?php
    mapRenderPageEnd();
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception,'map/index',$user);
}
