<?php

declare(strict_types=1);

namespace Food\Http;

use RuntimeException;

class HttpException extends RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(
        string $message,
        private readonly int $status = 400,
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public static function badRequest(string $message, array $errors = []): self
    {
        return new self($message, 422, $errors);
    }

    public static function unauthorized(string $message = 'Authentification requise.'): self
    {
        return new self($message, 401);
    }

    public static function forbidden(string $message = 'Accès refusé.'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'Ressource introuvable.'): self
    {
        return new self($message, 404);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }
}
