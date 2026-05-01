<?php

declare(strict_types=1);

namespace IDM\Presentation\Http;

final class Request
{
    private ?array $jsonBody = null;

    /**
     * @param array<string, string> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $files
     */
    public function __construct(
        private array $server,
        private array $query,
        private array $files
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_SERVER, $_GET, $_FILES);
    }

    public function method(): string
    {
        return (string) ($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        return parse_url((string) ($this->server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/';
    }

    public function query(string $key, string $default = ''): string
    {
        return trim((string) ($this->query[$key] ?? $default));
    }

    public function file(string $key): mixed
    {
        return $this->files[$key] ?? null;
    }

    public function authHeader(): string
    {
        return (string) ($this->server['HTTP_AUTHORIZATION'] ?? '');
    }

    /** @return array<string, mixed> */
    public function jsonBody(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            $this->jsonBody = [];
            return $this->jsonBody;
        }

        $decoded = json_decode($raw, true);
        $this->jsonBody = is_array($decoded) ? $decoded : [];
        return $this->jsonBody;
    }
}
