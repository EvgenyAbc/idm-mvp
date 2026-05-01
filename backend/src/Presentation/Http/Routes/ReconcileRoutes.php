<?php

declare(strict_types=1);

namespace IDM\Presentation\Http\Routes;

use IDM\Domain\Auth\RbacService;
use IDM\Application\AppContext;
use IDM\Presentation\Http\Request;
use IDM\Presentation\Http\Response;
use IDM\Presentation\Http\Router;
use IDM\Presentation\Http\Security;

final class ReconcileRoutes
{
    public static function register(Router $router): void
    {
        $router->add('POST', '/api/reconcile/run', [self::class, 'run']);
    }

    public static function run(Request $request, AppContext $ctx): void
    {
        try {
            set_time_limit(0);
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_RECONCILE_RUN, 'Missing permission: reconcile.run');
            $payload = $request->jsonBody();
            $options = null;
            if (array_key_exists('syncPasswords', $payload)) {
                $options = ['syncPasswords' => filter_var($payload['syncPasswords'], FILTER_VALIDATE_BOOLEAN)];
            }
            $runId = 'run_' . gmdate('Ymd_His');
            $result = $ctx->reconciler->run($runId, $options);
            Response::json(['ok' => true, 'run_id' => $runId, 'result' => $result]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage()], 503);
        }
    }
}
