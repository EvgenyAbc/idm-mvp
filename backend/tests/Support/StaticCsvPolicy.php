<?php

declare(strict_types=1);

namespace IDM\Tests\Support;

use IDM\Domain\Provisioning\SourcePolicy;

/** Fixed source rows for tests. */
final class StaticCsvPolicy implements SourcePolicy
{
    /**
     * @param list<array{user:string,password:string,httpUrl:string}> $rows
     */
    public function __construct(private array $rows)
    {
    }

    public function rows(): array
    {
        return $this->rows;
    }

    public function isAllowedUrl(string $url): bool
    {
        return (bool) filter_var($url, FILTER_VALIDATE_URL);
    }
}
