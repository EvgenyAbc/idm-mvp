<?php

declare(strict_types=1);

namespace IDM\Presentation\Http\Routes;

use IDM\Domain\Auth\RbacService;
use IDM\Presentation\Http\AuditLogger;
use IDM\Application\AppContext;
use IDM\Presentation\Http\Request;
use IDM\Presentation\Http\Response;
use IDM\Presentation\Http\Router;
use IDM\Presentation\Http\Security;

final class SourceUserRoutes
{
    public static function register(Router $router): void
    {
        $router->add('GET', '/api/source-users', [self::class, 'list']);
        $router->add('POST', '/api/source-users', [self::class, 'create']);
        $router->add('PUT', '#^/api/source-users/([^/]+)$#', [self::class, 'update'], true);
        $router->add('DELETE', '#^/api/source-users/([^/]+)$#', [self::class, 'delete'], true);
    }

    public static function list(Request $request, AppContext $ctx): void
    {
        try {
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_OPERATIONS_VIEW, 'Missing permission: dashboard.operations.view');
            $runId = 'run_' . gmdate('Ymd_His');
            $requestor = (string) ($authCtx['username'] ?? '');
            $items = $ctx->sourceUsers->all();
            AuditLogger::safe($ctx->audit, [
                'run_id' => $runId,
                'event_type' => 'source_users_view',
                'username' => $requestor !== '' ? $requestor : 'anonymous',
                'field_name' => 'source_users.list',
                'status' => 'allowed',
                'reason' => 'Source users list viewed',
                'payload' => ['count' => count($items)],
            ]);
            Response::json(['ok' => true, 'items' => $items]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage()], 503);
        }
    }

    public static function create(Request $request, AppContext $ctx): void
    {
        try {
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_OPERATIONS_VIEW, 'Missing permission: dashboard.operations.view');
            $runId = 'run_' . gmdate('Ymd_His');
            Security::requirePermissionWithAudit(
                $authCtx,
                RbacService::PERM_PROVISION_RUN,
                'Missing permission: provision.run',
                $ctx,
                $runId,
                'source_user_create',
                'source_users.create',
                'Missing permission: provision.run',
                ['path' => $request->path(), 'method' => $request->method()]
            );
            $requestor = (string) ($authCtx['username'] ?? '');
            $payload = $request->jsonBody();
            $user = trim((string) ($payload['user'] ?? ''));
            $password = (string) ($payload['password'] ?? '');
            $httpUrl = trim((string) ($payload['httpUrl'] ?? ''));
            if ($user === '' || $password === '' || $httpUrl === '') {
                Response::json(['ok' => false, 'message' => 'user, password, and httpUrl are required'], 400);
            }
            $ctx->sourceUsers->upsert($user, $password, $httpUrl);
            AuditLogger::safe($ctx->audit, [
                'run_id' => $runId,
                'event_type' => 'source_user_create',
                'username' => $requestor !== '' ? $requestor : 'anonymous',
                'field_name' => 'source_users.create',
                'status' => 'allowed',
                'reason' => 'Source user created or replaced',
                'payload' => ['user' => $user, 'httpUrl' => $httpUrl],
            ]);
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage()], 503);
        }
    }

    /** @param array<int, string> $m */
    public static function update(Request $request, AppContext $ctx, array $m): void
    {
        try {
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_OPERATIONS_VIEW, 'Missing permission: dashboard.operations.view');
            $runId = 'run_' . gmdate('Ymd_His');
            Security::requirePermissionWithAudit(
                $authCtx,
                RbacService::PERM_PROVISION_RUN,
                'Missing permission: provision.run',
                $ctx,
                $runId,
                'source_user_update',
                'source_users.update',
                'Missing permission: provision.run',
                ['path' => $request->path(), 'method' => $request->method()]
            );
            $requestor = (string) ($authCtx['username'] ?? '');
            $user = trim(urldecode((string) $m[1]));
            if ($user === '') {
                Response::json(['ok' => false, 'message' => 'user is required'], 400);
            }
            $payload = $request->jsonBody();
            $password = (string) ($payload['password'] ?? '');
            $httpUrl = trim((string) ($payload['httpUrl'] ?? ''));
            if ($password === '' || $httpUrl === '') {
                Response::json(['ok' => false, 'message' => 'password and httpUrl are required'], 400);
            }
            $ctx->sourceUsers->upsert($user, $password, $httpUrl);
            AuditLogger::safe($ctx->audit, [
                'run_id' => $runId,
                'event_type' => 'source_user_update',
                'username' => $requestor !== '' ? $requestor : 'anonymous',
                'field_name' => 'source_users.update',
                'status' => 'allowed',
                'reason' => 'Source user updated',
                'payload' => ['user' => $user, 'httpUrl' => $httpUrl],
            ]);
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage()], 503);
        }
    }

    /** @param array<int, string> $m */
    public static function delete(Request $request, AppContext $ctx, array $m): void
    {
        try {
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_OPERATIONS_VIEW, 'Missing permission: dashboard.operations.view');
            $runId = 'run_' . gmdate('Ymd_His');
            Security::requirePermissionWithAudit(
                $authCtx,
                RbacService::PERM_PROVISION_RUN,
                'Missing permission: provision.run',
                $ctx,
                $runId,
                'source_user_delete',
                'source_users.delete',
                'Missing permission: provision.run',
                ['path' => $request->path(), 'method' => $request->method()]
            );
            $requestor = (string) ($authCtx['username'] ?? '');
            $user = trim(urldecode((string) $m[1]));
            if ($user === '') {
                Response::json(['ok' => false, 'message' => 'user is required'], 400);
            }
            $ctx->sourceUsers->delete($user);
            AuditLogger::safe($ctx->audit, [
                'run_id' => $runId,
                'event_type' => 'source_user_delete',
                'username' => $requestor !== '' ? $requestor : 'anonymous',
                'field_name' => 'source_users.delete',
                'status' => 'allowed',
                'reason' => 'Source user deleted',
                'payload' => ['user' => $user],
            ]);
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage()], 503);
        }
    }
}
