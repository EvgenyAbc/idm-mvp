<?php

declare(strict_types=1);

namespace IDM\Presentation\Http\Routes;

use IDM\Domain\Auth\RbacService;
use IDM\Shared\Config\Config;
use IDM\Application\AppContext;
use IDM\Presentation\Http\Request;
use IDM\Presentation\Http\Response;
use IDM\Presentation\Http\Router;
use IDM\Presentation\Http\Security;

final class ProvisionRoutes
{
    public static function register(Router $router): void
    {
        $router->add('POST', '/api/provision/upload', [self::class, 'upload']);
        $router->add('POST', '/api/provision/run-poll', [self::class, 'runPoll']);
    }

    public static function upload(Request $request, AppContext $ctx): void
    {
        try {
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_PROVISION_RUN, 'Missing permission: provision.run');
            $csv = $request->file('csv');
            if (!is_array($csv) || !isset($csv['tmp_name'])) {
                Response::json(['ok' => false, 'message' => 'Missing csv file'], 400);
            }
            $target = Config::storagePath('csv/upload_' . gmdate('Ymd_His') . '.csv');
            if (!move_uploaded_file((string) $csv['tmp_name'], $target)) {
                Response::json(['ok' => false, 'message' => 'Cannot move uploaded file'], 500);
            }
            $rows = $ctx->provisioner->importCsvFile($target);
            Response::json(['ok' => true, 'imported' => count($rows), 'source' => 'sqlite', 'uploaded_file' => $target]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage()], 503);
        }
    }

    public static function runPoll(Request $request, AppContext $ctx): void
    {
        try {
            set_time_limit(0);
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_PROVISION_RUN, 'Missing permission: provision.run');
            $runId = 'run_' . gmdate('Ymd_His');
            $result = $ctx->provisioner->run($runId);
            Response::json(['ok' => true, 'run_id' => $runId, 'result' => $result, 'source' => 'sqlite']);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage()], 503);
        }
    }
}
