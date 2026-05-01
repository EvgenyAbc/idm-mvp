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

final class UserRoutes
{
    public static function register(Router $router): void
    {
        $router->add('GET', '/api/users', [self::class, 'list']);
        $router->add('POST', '#^/api/users/([^/]+)/password$#', [self::class, 'changePassword'], true);
    }

    public static function list(Request $request, AppContext $ctx): void
    {
        try {
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_USERS_VIEW, 'Missing permission: dashboard.users.view');
            Security::requirePermission($authCtx, RbacService::PERM_LDAP_BROWSE, 'Missing permission: ldap.browse');
            $runId = 'run_' . gmdate('Ymd_His');
            $requestor = (string) ($authCtx['username'] ?? '');
            $users = $ctx->ldap->searchPeople();
            AuditLogger::safe($ctx->audit, [
                'run_id' => $runId,
                'event_type' => 'users_view',
                'username' => $requestor !== '' ? $requestor : 'anonymous',
                'field_name' => 'users.list',
                'status' => 'allowed',
                'reason' => 'LDAP users list viewed',
                'payload' => ['count' => count($users)],
            ]);
            Response::json(['ok' => true, 'users' => $users]);
        } catch (\Throwable $e) {
            $status = str_contains(strtolower($e->getMessage()), 'missing permission') ? 403 : 503;
            Response::json(['ok' => false, 'message' => $e->getMessage(), 'users' => []], $status);
        }
    }

    /** @param array<int, string> $m */
    public static function changePassword(Request $request, AppContext $ctx, array $m): void
    {
        try {
            $username = urldecode($m[1]);
            $payload = $request->jsonBody();
            $newPassword = (string) ($payload['newPassword'] ?? '');
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_USERS_VIEW, 'Missing permission: dashboard.users.view');
            $runId = 'run_' . gmdate('Ymd_His');
            $requestor = (string) ($authCtx['username'] ?? '');
            if ($username === '' || $newPassword === '') {
                Response::json(['ok' => false, 'message' => 'username and newPassword are required'], 400);
            }
            if (!Security::hasPermission($authCtx, RbacService::PERM_USERS_PASSWORD_CHANGE)) {
                $ctx->audit->log([
                    'run_id' => $runId,
                    'event_type' => 'user_password_change',
                    'username' => $username,
                    'field_name' => 'userPassword',
                    'status' => 'denied',
                    'reason' => sprintf(
                        'Missing permission users.password.change (requestor: %s)',
                        $requestor !== '' ? $requestor : 'anonymous'
                    ),
                ]);
                Response::json(['ok' => false, 'message' => 'Missing permission: users.password.change'], 403);
            }

            $ctx->ldap->applyApprovedChange($username, 'userPassword', $newPassword);
            $ctx->audit->log([
                'run_id' => $runId,
                'event_type' => 'user_password_change',
                'username' => $username,
                'field_name' => 'userPassword',
                'status' => 'allowed',
                'reason' => 'Password changed from dashboard user action',
            ]);
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage()], 503);
        }
    }
}
