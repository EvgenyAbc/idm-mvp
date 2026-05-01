<?php

declare(strict_types=1);

use IDM\Application\AppContext;
use IDM\Presentation\Http\AuditLogger;
use IDM\Presentation\Http\Request;
use IDM\Presentation\Http\Response;
use IDM\Presentation\Http\Router;
use IDM\Presentation\Http\Routes\ApprovalRoutes;
use IDM\Presentation\Http\Routes\AuthRoutes;
use IDM\Presentation\Http\Routes\LdapRoutes;
use IDM\Presentation\Http\Routes\MetricsRoutes;
use IDM\Presentation\Http\Routes\ProvisionRoutes;
use IDM\Presentation\Http\Routes\ReconcileRoutes;
use IDM\Presentation\Http\Routes\SourceUserRoutes;
use IDM\Presentation\Http\Routes\UserRoutes;
use IDM\Presentation\Http\Security;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    require_once __DIR__ . '/../bootstrap_autoload.php';
}

$request = Request::fromGlobals();
$context = AppContext::bootstrap();
$router = new Router();

AuthRoutes::register($router);
ProvisionRoutes::register($router);
ReconcileRoutes::register($router);
SourceUserRoutes::register($router);
ApprovalRoutes::register($router);
MetricsRoutes::register($router);
UserRoutes::register($router);
LdapRoutes::register($router);

if (str_starts_with($request->path(), '/api/') && $request->path() !== '/api/health') {
    $runId = 'run_' . gmdate('Ymd_His');
    $requestor = Security::usernameFromAuthHeader($request);
    AuditLogger::safe($context->audit, [
        'run_id' => $runId,
        'event_type' => 'api_action',
        'username' => $requestor !== '' ? $requestor : 'anonymous',
        'field_name' => 'api.request',
        'status' => 'attempted',
        'reason' => sprintf('API request %s %s', $request->method(), $request->path()),
        'payload' => [
            'method' => $request->method(),
            'path' => $request->path(),
        ],
    ]);
}

if (!$router->dispatch($request, $context)) {
    Response::json(['ok' => false, 'message' => 'Not found'], 404);
}
