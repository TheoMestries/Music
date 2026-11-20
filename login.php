<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$query = ($_GET['next'] ?? null) === 'suszic' ? ['next' => 'suszic'] : [];
mapRedirectWithQuery(($_GET['mode'] ?? '') === 'register' ? '/register.php' : '/login.php', $query);
