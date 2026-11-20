<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

mapRedirectWithQuery(($_GET['mode'] ?? '') === 'register' ? '/register.php' : '/login.php', ['next' => 'suszic']);
