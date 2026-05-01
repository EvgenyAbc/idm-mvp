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

final class MetricsRoutes
{
    public static function register(Router $router): void
    {
        $router->add('GET', '/api/metrics', [self::class, 'metrics']);
    }

    public static function metrics(Request $request, AppContext $ctx): void
    {
        $authCtx = Security::authContext($request, $ctx->rbac);
        Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_METRICS_VIEW, 'Missing permission: dashboard.metrics.view');
        $runId = 'run_' . gmdate('Ymd_His');
        $requestor = (string) ($authCtx['username'] ?? '');
        $canEvents = Security::hasPermission($authCtx, RbacService::PERM_METRICS_EVENTS_VIEW);
        AuditLogger::safe($ctx->audit, [
            'run_id' => $runId,
            'event_type' => 'metrics_view',
            'username' => $requestor !== '' ? $requestor : 'anonymous',
            'field_name' => 'metrics.dashboard',
            'status' => $canEvents ? 'allowed' : 'denied',
            'reason' => $canEvents
                ? 'Metrics viewed with event visibility'
                : 'Metrics viewed without events permission',
            'payload' => ['can_events' => $canEvents],
        ]);
        $events = $canEvents ? $ctx->audit->recentEvents(100) : [];
        $securityEvents = $canEvents ? $ctx->audit->recentSecurityEvents(80) : [];
        Response::json([
            'ok' => true,
            'metrics' => $ctx->audit->metrics(),
            'events' => $events,
            'security_events' => $securityEvents,
        ]);
    }
}
