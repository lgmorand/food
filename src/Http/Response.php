<?php

declare(strict_types=1);

namespace Food\Http;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly mixed $payload,
        public readonly array $headers = [],
        public readonly int $jsonFlags = 0,
    ) {
    }

    public static function json(mixed $payload, int $status = 200): self
    {
        return new self($status, $payload);
    }

    /** Réponse JSON lisible, proposée au téléchargement par le navigateur. */
    public static function download(mixed $payload, string $filename): self
    {
        return new self(
            200,
            $payload,
            ['Content-Disposition' => 'attachment; filename="' . $filename . '"'],
            JSON_PRETTY_PRINT
        );
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
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | $this->jsonFlags);
    }
}
