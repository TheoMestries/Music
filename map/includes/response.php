<?php
declare(strict_types=1);

function mapSendJson(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mapJsonSuccess(array $data = [], int $statusCode = 200): never
{
    mapSendJson([
        'success' => true,
        'data' => $data,
    ], $statusCode);
}

function mapJsonError(string $message, int $statusCode = 400): never
{
    mapSendJson([
        'success' => false,
        'error' => $message,
    ], $statusCode);
}
