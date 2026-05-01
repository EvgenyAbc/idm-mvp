<?php

declare(strict_types=1);

namespace IDM\Presentation\Http;

use IDM\Application\AppContext;
use IDM\Domain\Auth\RbacService;

final class Security
{
    public static function usernameFromAuthHeader(Request $request): string
    {
        $header = $request->authHeader();
        if ($header === '') {
            return '';
        }

        if (stripos($header, 'Bearer ') === 0) {
            $header = substr($header, 7);
        }

        $decoded = base64_decode(trim($header), true);
        if ($decoded === false) {
            return '';
        }

        return trim($decoded);
    }

    /** @return array<string, mixed> */
    public static function authContext(Request $request, RbacService $rbac): array
    {
        return $rbac->authContextForUsername(self::usernameFromAuthHeader($request));
    }

    /** @param array<string, mixed> $auth */
    public static function hasPermission(array $auth, string $permission): bool
    {
        $permissions = $auth['permissions'] ?? [];
        return is_array($permissions) && in_array($permission, $permissions, true);
    }

    /** @param array<string, mixed> $auth */
    public static function requirePermission(array $auth, string $permission, string $message = 'Forbidden'): void
    {
        if (!self::hasPermission($auth, $permission)) {
            Response::json(['ok' => false, 'message' => $message], 403);
        }
    }

    /** @param array<string, mixed> $auth */
    public static function requirePermissionWithAudit(
        array $auth,
        string $permission,
        string $message,
        AppContext $ctx,
        string $runId,
        string $eventType,
        string $fieldName,
        string $reason,
        array $payload = []
    ): void {
        if (self::hasPermission($auth, $permission)) {
            return;
        }

        $requestor = (string) ($auth['username'] ?? '');
        AuditLogger::safe($ctx->audit, [
            'run_id' => $runId,
            'event_type' => $eventType,
            'username' => $requestor !== '' ? $requestor : 'anonymous',
            'field_name' => $fieldName,
            'status' => 'denied',
            'reason' => $reason,
            'payload' => $payload,
        ]);
        Response::json(['ok' => false, 'message' => $message], 403);
    }

    public static function ldapDnsEqual(string $a, string $b): bool
    {
        return strtolower(trim($a)) === strtolower(trim($b));
    }
}
