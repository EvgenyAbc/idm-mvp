<?php

declare(strict_types=1);

namespace IDM\Tests\Support;

use IDM\Domain\Provisioning\SourcePolicy;

/** Fixed source rows for tests. */
final class StaticCsvPolicy implements SourcePolicy
{
    /**
     * @param list<array<string, string>> $rows must include user, password, httpUrl; mail and telephoneNumber optional
     */
    public function __construct(private array $rows)
    {
    }

    /**
     * @return list<array{user:string,password:string,httpUrl:string,mail:string,telephoneNumber:string}>
     */
    public function rows(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            $out[] = [
                'user' => (string) ($row['user'] ?? ''),
                'password' => (string) ($row['password'] ?? ''),
                'httpUrl' => (string) ($row['httpUrl'] ?? ''),
                'mail' => trim((string) ($row['mail'] ?? '')),
                'telephoneNumber' => trim((string) ($row['telephoneNumber'] ?? '')),
            ];
        }

        return $out;
    }

    public function isAllowedUrl(string $url): bool
    {
        return (bool) filter_var($url, FILTER_VALIDATE_URL);
    }
}
