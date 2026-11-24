<?php
declare(strict_types=1);

require_once __DIR__ . '/campaigns.php';

function mapNavClass(string $item, string $activeItem): string
{
    return $item === $activeItem ? 'nav-link nav-link-active' : 'nav-link';
}

function mapBadgeClass(string $type, string $value): string
{
    $safeValue = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($value)) ?? 'default';
    return 'badge badge-' . $type . ' badge-' . $type . '-' . $safeValue;
}

function mapStatusLabel(bool $isActive): string
{
    return $isActive ? 'Actif' : 'Desactive';
}

function mapFormatDateTime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    try {
        $date = new DateTimeImmutable($value);
        return $date->format('d/m/Y H:i');
    } catch (Throwable) {
        return $value;
    }
}

function mapExcerpt(?string $value, int $length = 180): string
{
    $text = trim((string) $value);

    if ($text === '') {
        return 'Aucune description.';
    }

    if (mb_strlen($text, 'UTF-8') <= $length) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $length - 3, 'UTF-8')) . '...';
}

function mapRenderFieldError(array $errors, string $field): string
{
    if (!isset($errors[$field]) || $errors[$field] === '') {
        return '';
    }

    return '<p class="field-error">' . mapEscape((string) $errors[$field]) . '</p>';
}

function mapRenderFlashStack(): void
{
    $messages = mapConsumeFlashMessages();

    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }

        $type = (string) ($message['type'] ?? 'info');
        $class = match ($type) {
            'error' => 'notice notice-error',
            'success' => 'notice notice-success',
            default => 'notice notice-info',
        };

        echo '<p class="' . mapEscape($class) . '">' . mapEscape((string) ($message['message'] ?? '')) . '</p>';
    }
}

function mapRenderAppHeader(array $currentUser, string $activeNav = 'home'): void
{
    ?>
    <header class="app-header">
      <div class="header-bar">
        <div class="brand-block">
          <h1><?= mapEscape(MAP_APP_NAME) ?></h1>
        </div>

        <nav class="app-nav" aria-label="Navigation principale">
          <a class="<?= mapEscape(mapNavClass('campaigns', $activeNav)) ?>" href="<?= mapEscape(mapUrl('/index.php')) ?>">Mes parties</a>
          <a class="nav-link" href="<?= mapEscape(mapMusicUrl()) ?>">SuSZic</a>
          <?php if ((string) $currentUser['role'] === 'admin'): ?>
            <a class="<?= mapEscape(mapNavClass('users', $activeNav)) ?>" href="<?= mapEscape(mapUrl('/admin/users.php')) ?>">Utilisateurs</a>
            <a class="<?= mapEscape(mapNavClass('dice-settings', $activeNav)) ?>" href="<?= mapEscape(mapUrl('/admin/dice-settings.php')) ?>">Reglages des</a>
          <?php endif; ?>
        </nav>

        <div class="header-actions">
          <div class="account-chip">
            <span class="eyebrow eyebrow-tight">Compte connecte</span>
            <strong><?= mapEscape($currentUser['display_name']) ?></strong>
            <span class="muted"><?= mapEscape(mapRoleLabel((string) $currentUser['role'])) ?></span>
          </div>
          <form method="post" action="<?= mapEscape(mapUrl('/logout.php')) ?>" class="inline-form">
            <?= mapCsrfField() ?>
            <button type="submit" class="button button-secondary">Deconnexion</button>
          </form>
        </div>
      </div>
      <div class="flash-stack">
        <?php mapRenderFlashStack(); ?>
      </div>
    </header>
    <?php
}

function mapRenderPageStart(string $title, array $currentUser, string $activeNav = 'home', bool $isTable = false): void
{
    $bodyClass = $isTable ? 'table-page' : (($_GET['embedded'] ?? null) === '1' ? 'embedded-tool' : '');
    ?>
    <!doctype html>
    <html lang="fr">
      <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= mapEscape($title) ?> - <?= mapEscape(MAP_APP_NAME) ?></title>
        <link rel="stylesheet" href="<?= mapEscape(mapUrl('/css/map.css?v=board-settings-1')) ?>">
      </head>
      <body class="<?= $bodyClass ?>">
        <div class="app-layout">
          <?php if (!$isTable) mapRenderAppHeader($currentUser, $activeNav); ?>
          <main class="content-shell">
    <?php
}

function mapRenderPageEnd(array $additionalScripts = []): void
{
    ?>
          </main>
        </div>
        <script src="<?= mapEscape(mapUrl('/js/windows.js?v=3')) ?>"></script>
        <script src="<?= mapEscape(mapUrl('/js/character-sheets.js?v=sheets-1')) ?>"></script>
        <script src="<?= mapEscape(mapUrl('/js/app.js?v=sus20-1')) ?>"></script>
        <?php foreach ($additionalScripts as $script): ?>
          <script src="<?= mapEscape(mapUrl((string) $script) . '?v=board-sync-2') ?>"></script>
        <?php endforeach; ?>
      </body>
    </html>
    <?php
}

function mapRenderStatusPage(string $title, string $message, int $statusCode, ?array $currentUser = null): never
{
    http_response_code($statusCode);
    ?>
    <!doctype html>
    <html lang="fr">
      <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= mapEscape($title) ?> - <?= mapEscape(MAP_APP_NAME) ?></title>
        <link rel="stylesheet" href="<?= mapEscape(mapUrl('/css/map.css?v=board-settings-1')) ?>">
      </head>
      <body class="<?= isset($_GET['embedded']) && $_GET['embedded'] === '1' ? 'embedded-tool' : '' ?>">
        <?php if ($currentUser !== null): ?>
          <div class="app-layout">
            <?php mapRenderAppHeader($currentUser); ?>
            <main class="content-shell">
              <section class="section-block section-block-narrow">
                <h2><?= mapEscape($title) ?></h2>
                <p class="notice notice-error"><?= mapEscape($message) ?></p>
                <p><a href="<?= mapEscape(mapUrl('/index.php')) ?>">Retour a l accueil</a></p>
              </section>
            </main>
          </div>
        <?php else: ?>
          <main class="page-shell">
            <section class="panel panel-narrow">
              <h1><?= mapEscape($title) ?></h1>
              <p class="notice notice-error"><?= mapEscape($message) ?></p>
              <p><a href="<?= mapEscape(mapUrl('/login.php')) ?>">Retour a la connexion</a></p>
            </section>
          </main>
        <?php endif; ?>
      </body>
    </html>
    <?php
    exit;
}

function mapRenderExceptionPage(Throwable $exception, string $context, ?array $currentUser = null): never
{
    if ($exception instanceof MapHttpException) {
        mapRenderStatusPage(
            $exception->getStatusCode() === 404 ? 'Introuvable' : 'Acces restreint',
            $exception->getMessage(),
            $exception->getStatusCode(),
            $currentUser
        );
    }

    if ($exception instanceof DomainException) {
        mapRenderStatusPage(
            'Requete invalide',
            $exception->getMessage(),
            400,
            $currentUser
        );
    }

    error_log('[' . $context . '] ' . $exception->getMessage());
    mapRenderStatusPage(
        'Erreur interne',
        'Une erreur interne est survenue. Reessayez plus tard.',
        500,
        $currentUser
    );
}
