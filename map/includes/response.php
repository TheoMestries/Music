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

function mapReadJsonBody(int $maximumBytes = 524288): array
{
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;
    if ($contentLength !== null && $contentLength > $maximumBytes) {
        throw new DomainException('La requete est trop volumineuse.');
    }

    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
        throw new DomainException('Le corps JSON de la requete est manquant.');
    }

    if (strlen($rawBody) > $maximumBytes) {
        throw new DomainException('La requete est trop volumineuse.');
    }

    try {
        $payload = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new DomainException('Le corps JSON de la requete est invalide.', 0, $exception);
    }

    if (!is_array($payload)) {
        throw new DomainException('Le corps JSON doit etre un objet.');
    }

    return $payload;
}
