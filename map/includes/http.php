<?php
declare(strict_types=1);

final class MapHttpException extends RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        string $publicMessage,
        ?Throwable $previous = null
    ) {
        parent::__construct($publicMessage, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

function mapThrowHttpException(int $statusCode, string $message): never
{
    throw new MapHttpException($statusCode, $message);
}

function mapThrowForbidden(string $message = 'Acces refuse.'): never
{
    mapThrowHttpException(403, $message);
}

function mapThrowNotFound(string $message = 'Ressource introuvable.'): never
{
    mapThrowHttpException(404, $message);
}
