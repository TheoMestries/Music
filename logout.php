<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

header('Location: ' . mapUrl('/logout.php'), true, 307);
exit;
