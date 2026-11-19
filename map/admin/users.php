<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$currentUser = null;

try {
    $currentUser = mapRequireAdmin();
    $users = mapListUsers();
    mapRenderPageStart('Utilisateurs', $currentUser, 'users');
    ?>
    <section class="section-block">
      <div class="section-heading">
        <div>
          <p class="eyebrow">Administration</p>
          <h2>Utilisateurs</h2>
          <p class="muted">Gestion des comptes Sus20.</p>
        </div>
        <div class="button-row">
          <a class="button" href="<?= mapEscape(mapUrl('/admin/user-create.php')) ?>">Creer un utilisateur</a>
        </div>
      </div>

      <?php if ($users === []): ?>
        <div class="empty-state">Aucun utilisateur disponible.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Nom d utilisateur</th>
                <th>Nom affiche</th>
                <th>Role</th>
                <th>Statut</th>
                <th>Cree le</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $user): ?>
                <tr>
                  <td class="monospace"><?= mapEscape((string) $user['username']) ?></td>
                  <td><?= mapEscape((string) $user['display_name']) ?></td>
                  <td><span class="<?= mapEscape(mapBadgeClass('role', (string) $user['role'])) ?>"><?= mapEscape(mapRoleLabel((string) $user['role'])) ?></span></td>
                  <td><span class="<?= mapEscape(mapBadgeClass('status', (int) $user['is_active'] === 1 ? 'active' : 'inactive')) ?>"><?= mapEscape(mapStatusLabel((int) $user['is_active'] === 1)) ?></span></td>
                  <td><?= mapEscape(mapFormatDateTime((string) $user['created_at'])) ?></td>
                  <td>
                    <div class="inline-actions">
                      <a class="button button-secondary button-small" href="<?= mapEscape(mapUrlWithQuery('/admin/user-edit.php', ['id' => (int) $user['id']])) ?>">Modifier</a>
                      <a class="button button-secondary button-small" href="<?= mapEscape(mapUrlWithQuery('/admin/user-password.php', ['id' => (int) $user['id']])) ?>">Mot de passe</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
    <?php
    mapRenderPageEnd();
} catch (Throwable $exception) {
    mapRenderExceptionPage($exception, 'map/admin/users', $currentUser);
}
