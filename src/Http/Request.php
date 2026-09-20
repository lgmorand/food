<?php

declare(strict_types=1);

namespace Food\Http;

final class Request
{
    private array $body;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        ?array $body = null,
        public readonly array $files = [],
        public readonly string $basePath = '',
    ) {
        $this->body = $body ?? [];
    }

    /**
     * Préfixe d'URL sous lequel l'application est publiée, sans barre oblique
     * finale : vide à la racine du domaine, « /food » dans un sous-dossier.
     */
    public static function detectBasePath(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');

        return $base === '/' ? '' : $base;
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $basePath = self::detectBasePath();
        if ($basePath !== '' && (str_starts_with($path, $basePath . '/') || $path === $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        $body = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        } else {
            $body = $_POST;
        }

        return new self($method, rtrim($path, '/') ?: '/', $_GET, $body, $_FILES, $basePath);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->body[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->body[$key] ?? $default;
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, ['1', 1, 'true', 'on', 'yes'], true);
    }

    public function array(string $key): array
    {
        $value = $this->body[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    public function queryString(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }
}
