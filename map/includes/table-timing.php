<?php
declare(strict_types=1);

function mapTableTimingMark(string $phase): void
{
    if (($_SERVER['HTTP_X_MAP_DIAGNOSTIC'] ?? '') !== '1') return;

    $now = microtime(true);
    $previous = $GLOBALS['map_table_timing_last'] ?? $_SERVER['REQUEST_TIME_FLOAT'] ?? $now;
    $GLOBALS['map_table_timing_last'] = $now;
    $GLOBALS['map_table_timings'][$phase] = ($GLOBALS['map_table_timings'][$phase] ?? 0) + ($now - $previous);
}

function mapSendTableTiming(): void
{
    if (($_SERVER['HTTP_X_MAP_DIAGNOSTIC'] ?? '') !== '1') return;

    $timings = $GLOBALS['map_table_timings'] ?? [];
    $timings['php'] = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    $metrics = [];
    foreach ($timings as $phase => $seconds) {
        $metrics[] = $phase . ';dur=' . number_format($seconds * 1000, 2, '.', '');
    }
    header('Server-Timing: ' . implode(', ', $metrics));
}
