<?php

declare(strict_types=1);

namespace IDM\Presentation\Http;

use IDM\Infrastructure\Persistence\AuditRepository;

final class AuditLogger
{
    /** @param array<string, mixed> $event */
    public static function safe(AuditRepository $audit, array $event): void
    {
        try {
            $audit->log($event);
        } catch (\Throwable $e) {
            error_log('IDM audit log failure: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $context */
    public static function ldapRouteFailure(string $route, string $requestor, array $context, \Throwable $e): void
    {
        $payload = array_merge(
            [
                'route' => $route,
                'requestor' => $requestor !== '' ? $requestor : 'anonymous',
                'error' => $e->getMessage(),
            ],
            $context
        );
        error_log('IDM LDAP route failure: ' . json_encode($payload));
    }
}
