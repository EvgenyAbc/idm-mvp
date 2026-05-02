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

final class ApprovalRoutes
{
    public static function register(Router $router): void
    {
        $router->add('GET', '/api/approvals', [self::class, 'list']);
        $router->add('POST', '#^/api/approvals/(\d+)/(approve|reject)$#', [self::class, 'decide'], true);
    }

    public static function list(Request $request, AppContext $ctx): void
    {
        $authCtx = Security::authContext($request, $ctx->rbac);
        Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_APPROVALS_VIEW, 'Missing permission: dashboard.approvals.view');
        $runId = 'run_' . gmdate('Ymd_His');
        $requestor = (string) ($authCtx['username'] ?? '');
        $items = $ctx->approvals->pending();
        AuditLogger::safe($ctx->audit, [
            'run_id' => $runId,
            'event_type' => 'approvals_view',
            'username' => $requestor !== '' ? $requestor : 'anonymous',
            'field_name' => 'approvals.list',
            'status' => 'allowed',
            'reason' => 'Approvals queue viewed',
            'payload' => ['count' => count($items)],
        ]);
        Response::json(['ok' => true, 'items' => $items]);
    }

    /** @param array<int, string> $m */
    public static function decide(Request $request, AppContext $ctx, array $m): void
    {
        try {
            $id = (int) $m[1];
            $action = (string) $m[2];
            $runId = 'run_' . gmdate('Ymd_His');
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_APPROVALS_VIEW, 'Missing permission: dashboard.approvals.view');
            $requestor = (string) ($authCtx['username'] ?? '');
            if (!Security::hasPermission($authCtx, RbacService::PERM_APPROVAL_DECIDE)) {
                $target = $ctx->approvals->find($id);
                $ctx->audit->log([
                    'run_id' => $runId,
                    'event_type' => 'approval',
                    'username' => $requestor !== '' ? $requestor : 'anonymous',
                    'field_name' => 'approval.decide',
                    'status' => 'denied',
                    'reason' => sprintf(
                        'Missing permission: approval.decide (attempted %s on approval id %d%s)',
                        $action,
                        $id,
                        $target !== null
                            ? sprintf('; subject %s / %s', (string) ($target['username'] ?? ''), (string) ($target['field_name'] ?? ''))
                            : ''
                    ),
                    'payload' => [
                        'approval_id' => $id,
                        'attempted_action' => $action,
                        'subject_username' => $target['username'] ?? null,
                        'subject_field' => $target['field_name'] ?? null,
                    ],
                ]);
                Response::json(['ok' => false, 'message' => 'Missing permission: approval.decide'], 403);
            }

            $item = $ctx->approvals->find($id);
            if ($item === null) {
                Response::json(['ok' => false, 'message' => 'Approval item not found'], 404);
            }

            if ($action === 'approve') {
                $ctx->approvals->decide($id, 'approved');
                $ctx->ldap->applyApprovedChange((string) $item['username'], (string) $item['field_name'], (string) $item['new_value']);
                $sourceRow = $ctx->sourceUsers->findByUser((string) $item['username']);
                if ($sourceRow !== null) {
                    $field = (string) ($item['field_name'] ?? '');
                    $newVal = (string) $item['new_value'];
                    if ($field === 'labeledURI') {
                        $ctx->sourceUsers->updateHttpUrl((string) $item['username'], $newVal);
                    } elseif ($field === 'mail') {
                        $ctx->sourceUsers->updateMail((string) $item['username'], $newVal);
                    } elseif ($field === 'telephoneNumber') {
                        $ctx->sourceUsers->updateTelephoneNumber((string) $item['username'], $newVal);
                    }
                }
                $ctx->audit->log([
                    'run_id' => $runId,
                    'event_type' => 'approval',
                    'username' => $item['username'],
                    'field_name' => $item['field_name'],
                    'status' => 'approved',
                ]);
            } else {
                $ctx->approvals->decide($id, 'rejected');
                $ctx->audit->log([
                    'run_id' => $runId,
                    'event_type' => 'approval',
                    'username' => $item['username'],
                    'field_name' => $item['field_name'],
                    'status' => 'rejected',
                ]);
            }
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage()], 503);
        }
    }
}
