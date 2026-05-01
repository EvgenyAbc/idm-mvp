<?php

declare(strict_types=1);

namespace IDM\Domain\Provisioning;

/** Authoritative source rows and URL policy used by reconciliation/provisioning. */
interface SourcePolicy
{
    /**
     * @return list<array{user:string,password:string,httpUrl:string}>
     */
    public function rows(): array;

    public function isAllowedUrl(string $url): bool;
}
