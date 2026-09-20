<?php

declare(strict_types=1);

namespace Food\Http;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly mixed $payload,
    ) {
    }

    public static function json(mixed $payload, int $status = 200): self
    {
        return new self($status, $payload);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }

    public function send(): void
    {
        http_response_code($this->status);
        if ($this->status === 204 || $this->payload === null) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
