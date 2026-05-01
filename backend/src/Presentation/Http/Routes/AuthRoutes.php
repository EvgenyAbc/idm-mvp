<?php

declare(strict_types=1);

namespace IDM\Presentation\Http\Routes;

use IDM\Presentation\Http\AuditLogger;
use IDM\Application\AppContext;
use IDM\Presentation\Http\Request;
use IDM\Presentation\Http\Response;
use IDM\Presentation\Http\Router;
use IDM\Presentation\Http\Security;

final class AuthRoutes
{
    public static function register(Router $router): void
    {
        $router->add('GET', '/api/health', [self::class, 'health']);
        $router->add('POST', '/api/auth/login', [self::class, 'login']);
        $router->add('GET', '/api/auth/me', [self::class, 'me']);
    }

    public static function health(Request $request, AppContext $ctx): void
    {
        try {
            Response::json(['ok' => true, 'ldap' => $ctx->ldap->health()]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'ldap' => false, 'message' => $e->getMessage()], 503);
        }
    }

    public static function login(Request $request, AppContext $ctx): void
    {
        $payload = $request->jsonBody();
        $username = (string) ($payload['username'] ?? '');
        $ok = $ctx->auth->login($username, (string) ($payload['password'] ?? ''));
        if (!$ok) {
            Response::json(['ok' => false, 'message' => 'Invalid LDAP credentials'], 401);
        }
        $authCtx = $ctx->rbac->authContextForUsername($username);
        Response::json([
            'ok' => true,
            'token' => base64_encode($username),
            'username' => $username,
            'groups' => $authCtx['groups'],
            'permissions' => $authCtx['permissions'],
        ]);
    }

    public static function me(Request $request, AppContext $ctx): void
    {
        try {
            $authCtx = Security::authContext($request, $ctx->rbac);
            if (($authCtx['username'] ?? '') === '') {
                Response::json(['ok' => false, 'message' => 'Unauthorized'], 401);
            }
            Response::json(['ok' => true, 'auth' => $authCtx]);
        } catch (\Throwable $e) {
            AuditLogger::ldapRouteFailure('/api/auth/me', Security::usernameFromAuthHeader($request), [], $e);
            Response::json(['ok' => false, 'message' => $e->getMessage()], 503);
        }
    }
}
