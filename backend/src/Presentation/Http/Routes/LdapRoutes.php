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
use RuntimeException;

final class LdapRoutes
{
    public static function register(Router $router): void
    {
        $router->add('GET', '/api/ldap/tree', [self::class, 'tree']);
        $router->add('GET', '/api/ldap/subtree', [self::class, 'subtreeQuery']);
        $router->add('GET', '#^/api/ldap/subtree/(.+)$#', [self::class, 'subtreePath'], true);
        $router->add('GET', '/api/ldap/tree/node', [self::class, 'treeNode']);
        $router->add('GET', '/api/ldap/self/node', [self::class, 'selfNode']);
        $router->add('GET', '/api/ldap/search', [self::class, 'search']);
        $router->add('POST', '/api/ldap/entry/update', [self::class, 'entryUpdate']);
        $router->add('GET', '/api/ldap/export', [self::class, 'export']);
    }

    public static function tree(Request $request, AppContext $ctx): void
    {
        $requestor = '';
        $baseDn = '';
        try {
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_LDAP_VIEW, 'Missing permission: dashboard.ldap.view');
            Security::requirePermission($authCtx, RbacService::PERM_LDAP_BROWSE, 'Missing permission: ldap.browse');
            $runId = 'run_' . gmdate('Ymd_His');
            $requestor = (string) ($authCtx['username'] ?? '');
            $baseDn = $request->query('baseDn');
            $tree = $ctx->ldap->browseTreeFromDn($baseDn);
            $ctx->audit->log([
                'run_id' => $runId,
                'event_type' => 'ldap_browse',
                'username' => $requestor,
                'field_name' => 'ldap.tree',
                'status' => 'allowed',
                'reason' => 'LDAP tree viewed',
                'payload' => ['baseDn' => $baseDn],
            ]);
            Response::json(['ok' => true, 'tree' => $tree]);
        } catch (\Throwable $e) {
            AuditLogger::ldapRouteFailure('/api/ldap/tree', $requestor, ['baseDn' => $baseDn], $e);
            Response::json(['ok' => false, 'message' => $e->getMessage(), 'tree' => []], 503);
        }
    }

    public static function subtreeQuery(Request $request, AppContext $ctx): void
    {
        $requestor = '';
        $dn = '';
        try {
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_LDAP_VIEW, 'Missing permission: dashboard.ldap.view');
            Security::requirePermission($authCtx, RbacService::PERM_LDAP_BROWSE, 'Missing permission: ldap.browse');
            $runId = 'run_' . gmdate('Ymd_His');
            $requestor = (string) ($authCtx['username'] ?? '');
            $dn = $request->query('dn');
            if ($dn === '') {
                Response::json(['ok' => false, 'message' => 'Query parameter dn is required', 'tree' => []], 400);
            }
            $tree = $ctx->ldap->browseTreeFromDn($dn);
            $ctx->audit->log([
                'run_id' => $runId,
                'event_type' => 'ldap_subtree',
                'username' => $requestor,
                'field_name' => 'ldap.subtree',
                'status' => 'allowed',
                'reason' => 'LDAP subtree viewed',
                'payload' => ['dn' => $dn],
            ]);
            Response::json(['ok' => true, 'baseDn' => $dn, 'tree' => $tree]);
        } catch (\Throwable $e) {
            AuditLogger::ldapRouteFailure('/api/ldap/subtree', $requestor, ['dn' => $dn], $e);
            Response::json(['ok' => false, 'message' => $e->getMessage(), 'tree' => []], 503);
        }
    }

    /** @param array<int, string> $m */
    public static function subtreePath(Request $request, AppContext $ctx, array $m): void
    {
        $requestor = '';
        $dn = '';
        try {
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_LDAP_VIEW, 'Missing permission: dashboard.ldap.view');
            Security::requirePermission($authCtx, RbacService::PERM_LDAP_BROWSE, 'Missing permission: ldap.browse');
            $runId = 'run_' . gmdate('Ymd_His');
            $requestor = (string) ($authCtx['username'] ?? '');
            $dn = trim(urldecode((string) $m[1]));
            if ($dn === '') {
                Response::json(['ok' => false, 'message' => 'Path parameter dn is required', 'tree' => []], 400);
            }
            $tree = $ctx->ldap->browseTreeFromDn($dn);
            $ctx->audit->log([
                'run_id' => $runId,
                'event_type' => 'ldap_subtree',
                'username' => $requestor,
                'field_name' => 'ldap.subtree',
                'status' => 'allowed',
                'reason' => 'LDAP subtree viewed',
                'payload' => ['dn' => $dn, 'route' => 'path'],
            ]);
            Response::json(['ok' => true, 'baseDn' => $dn, 'tree' => $tree]);
        } catch (\Throwable $e) {
            AuditLogger::ldapRouteFailure('/api/ldap/subtree/{encodedDn}', $requestor, ['dn' => $dn], $e);
            Response::json(['ok' => false, 'message' => $e->getMessage(), 'tree' => []], 503);
        }
    }

    public static function treeNode(Request $request, AppContext $ctx): void
    {
        $requestor = '';
        $dn = '';
        try {
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_LDAP_VIEW, 'Missing permission: dashboard.ldap.view');
            $canBrowse = Security::hasPermission($authCtx, RbacService::PERM_LDAP_BROWSE);
            $canSearchPath = Security::hasPermission($authCtx, RbacService::PERM_LDAP_SEARCH)
                && Security::hasPermission($authCtx, RbacService::PERM_LDAP_VIEW_ATTRIBUTES);
            if (!$canBrowse && !$canSearchPath) {
                Response::json([
                    'ok' => false,
                    'message' => 'Missing permission: ldap.browse or ldap.search with ldap.view_attributes',
                ], 403);
            }
            $runId = 'run_' . gmdate('Ymd_His');
            $requestor = (string) ($authCtx['username'] ?? '');
            $dn = $request->query('dn');
            if ($dn === '') {
                Response::json(['ok' => false, 'message' => 'Query parameter dn is required'], 400);
            }
            $node = $ctx->ldap->treeNodeByDn($dn);
            $ctx->audit->log([
                'run_id' => $runId,
                'event_type' => 'ldap_browse_node',
                'username' => $requestor,
                'field_name' => 'ldap.tree.node',
                'status' => 'allowed',
                'reason' => 'LDAP tree node viewed',
                'payload' => ['dn' => $dn],
            ]);
            Response::json(['ok' => true, 'node' => $node]);
        } catch (\Throwable $e) {
            AuditLogger::ldapRouteFailure('/api/ldap/tree/node', $requestor, ['dn' => $dn], $e);
            $status = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 503;
            Response::json(['ok' => false, 'message' => $e->getMessage(), 'node' => null], $status);
        }
    }

    public static function selfNode(Request $request, AppContext $ctx): void
    {
        $requestor = '';
        try {
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_PROFILE_VIEW, 'Missing permission: dashboard.profile.view');
            $canBrowse = Security::hasPermission($authCtx, RbacService::PERM_LDAP_BROWSE);
            $canSearchPath = Security::hasPermission($authCtx, RbacService::PERM_LDAP_SEARCH)
                && Security::hasPermission($authCtx, RbacService::PERM_LDAP_VIEW_ATTRIBUTES);
            if (!$canBrowse && !$canSearchPath) {
                Response::json([
                    'ok' => false,
                    'message' => 'Missing permission: ldap.browse or ldap.search with ldap.view_attributes',
                ], 403);
            }
            $runId = 'run_' . gmdate('Ymd_His');
            $requestor = (string) ($authCtx['username'] ?? '');
            if ($requestor === '') {
                Response::json(['ok' => false, 'message' => 'Unauthenticated'], 401);
            }
            $dn = $ctx->ldap->dnForUid($requestor);
            if ($dn === null || trim($dn) === '') {
                Response::json(['ok' => true, 'dn' => null, 'node' => null]);
            }
            $node = $ctx->ldap->treeNodeByDn($dn);
            $ctx->audit->log([
                'run_id' => $runId,
                'event_type' => 'ldap_browse_node',
                'username' => $requestor,
                'field_name' => 'ldap.tree.node.self',
                'status' => 'allowed',
                'reason' => 'Self LDAP node viewed',
                'payload' => ['dn' => $dn],
            ]);
            Response::json(['ok' => true, 'dn' => $dn, 'node' => $node]);
        } catch (\Throwable $e) {
            AuditLogger::ldapRouteFailure('/api/ldap/self/node', $requestor, [], $e);
            $status = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 503;
            Response::json(['ok' => false, 'message' => $e->getMessage(), 'dn' => null, 'node' => null], $status);
        }
    }

    public static function search(Request $request, AppContext $ctx): void
    {
        try {
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_LDAP_VIEW, 'Missing permission: dashboard.ldap.view');
            Security::requirePermission($authCtx, RbacService::PERM_LDAP_SEARCH, 'Missing permission: ldap.search');
            Security::requirePermission($authCtx, RbacService::PERM_LDAP_VIEW_ATTRIBUTES, 'Missing permission: ldap.view_attributes');
            $runId = 'run_' . gmdate('Ymd_His');
            $requestor = (string) ($authCtx['username'] ?? '');
            $query = $request->query('q');
            if ($query === '') {
                Response::json(['ok' => true, 'items' => []]);
            }
            $items = $ctx->ldap->searchEntries($query);
            $ctx->audit->log([
                'run_id' => $runId,
                'event_type' => 'ldap_search',
                'username' => $requestor,
                'field_name' => 'ldap.query',
                'status' => 'allowed',
                'reason' => 'LDAP search executed',
                'payload' => ['q' => $query, 'count' => count($items)],
            ]);
            Response::json(['ok' => true, 'items' => $items]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage(), 'items' => []], 503);
        }
    }

    public static function entryUpdate(Request $request, AppContext $ctx): void
    {
        try {
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_PROFILE_VIEW, 'Missing permission: dashboard.profile.view');
            $requestor = (string) ($authCtx['username'] ?? '');
            $payload = $request->jsonBody();
            $dn = trim((string) ($payload['dn'] ?? ''));
            $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : [];
            $canGlobalEdit = Security::hasPermission($authCtx, RbacService::PERM_LDAP_EDIT);
            if (!$canGlobalEdit) {
                Security::requirePermission($authCtx, RbacService::PERM_LDAP_VIEW_ATTRIBUTES, 'Missing permission: ldap.view_attributes');
                $selfDn = $ctx->ldap->dnForUid($requestor);
                if ($selfDn === null || !Security::ldapDnsEqual($dn, $selfDn)) {
                    Response::json(['ok' => false, 'message' => 'Missing permission: ldap.edit (or you may only update your own entry)'], 403);
                }
            }

            /** Self-edit without ldap.edit: labeledURI, mail, telephoneNumber require approval (same policy). */
            $pendingApprovalFields = [];
            $changesForLdap = $changes;
            if (!$canGlobalEdit) {
                foreach (['labeledURI', 'mail', 'telephoneNumber'] as $attr) {
                    $newVal = self::changeValueForAttribute($changes, $attr);
                    if ($newVal === null) {
                        continue;
                    }
                    $oldRaw = match ($attr) {
                        'labeledURI' => $ctx->ldap->labeledUriForUid($requestor),
                        'mail' => $ctx->ldap->mailForUid($requestor),
                        'telephoneNumber' => $ctx->ldap->telephoneNumberForUid($requestor),
                        default => null,
                    };
                    $oldStr = $oldRaw ?? '';
                    $reason = match ($attr) {
                        'labeledURI' => 'labeledURI updates require administrator approval',
                        'mail' => 'mail updates require administrator approval',
                        'telephoneNumber' => 'telephoneNumber updates require administrator approval',
                        default => 'Attribute updates require administrator approval',
                    };
                    if ($newVal !== trim((string) $oldStr)) {
                        $ctx->approvals->create($requestor, $attr, $oldRaw, $newVal, $reason);
                        $ctx->audit->log([
                            'run_id' => 'run_' . gmdate('Ymd_His'),
                            'event_type' => 'ldap_edit',
                            'username' => $requestor,
                            'field_name' => $attr,
                            'status' => 'pending_approval',
                            'reason' => $reason,
                            'payload' => ['dn' => $dn],
                        ]);
                        $pendingApprovalFields[] = $attr;
                    }
                    $changesForLdap = self::withoutAttribute($changesForLdap, $attr);
                }
            }

            $applied = [];
            if ($changesForLdap !== []) {
                $applied = $ctx->ldap->updateEntryAttributes($dn, $changesForLdap);
                $ctx->audit->log([
                    'run_id' => 'run_' . gmdate('Ymd_His'),
                    'event_type' => 'ldap_edit',
                    'username' => $requestor,
                    'field_name' => 'ldap.entry',
                    'status' => 'allowed',
                    'reason' => 'LDAP attributes updated',
                    'payload' => ['dn' => $dn, 'changes' => $applied],
                ]);
            }

            if ($pendingApprovalFields === [] && $applied === []) {
                throw new RuntimeException('No editable attributes provided');
            }

            $response = ['ok' => true, 'applied' => $applied];
            if ($pendingApprovalFields !== []) {
                $response['pending_approval'] = true;
                $response['approval_fields'] = $pendingApprovalFields;
                $response['approval_field'] = $pendingApprovalFields[0];
            }
            Response::json($response);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage()], 503);
        }
    }

    public static function export(Request $request, AppContext $ctx): void
    {
        try {
            $authCtx = Security::authContext($request, $ctx->rbac);
            Security::requirePermission($authCtx, RbacService::PERM_DASHBOARD_LDAP_VIEW, 'Missing permission: dashboard.ldap.view');
            Security::requirePermission($authCtx, RbacService::PERM_LDAP_EXPORT, 'Missing permission: ldap.export');
            Security::requirePermission($authCtx, RbacService::PERM_LDAP_VIEW_ATTRIBUTES, 'Missing permission: ldap.view_attributes');
            $runId = 'run_' . gmdate('Ymd_His');
            $requestor = (string) ($authCtx['username'] ?? '');
            $query = $request->query('q');
            $items = $ctx->ldap->exportEntries($query);
            $ctx->audit->log([
                'run_id' => $runId,
                'event_type' => 'ldap_export',
                'username' => $requestor,
                'field_name' => 'ldap.export',
                'status' => 'allowed',
                'reason' => 'LDAP export generated',
                'payload' => ['q' => $query, 'count' => count($items)],
            ]);

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="ldap_export.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['dn', 'uid', 'cn', 'mail', 'labeledURI', 'telephoneNumber']);
            foreach ($items as $item) {
                $attrs = $item['attributes'] ?? [];
                fputcsv($out, [
                    $item['dn'] ?? '',
                    $attrs['uid'][0] ?? '',
                    $attrs['cn'][0] ?? '',
                    $attrs['mail'][0] ?? '',
                    $attrs['labeledURI'][0] ?? '',
                    $attrs['telephoneNumber'][0] ?? '',
                ]);
            }
            fclose($out);
            exit;
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage()], 503);
        }
    }

    /** @param array<string, mixed> $changes */
    private static function withoutAttribute(array $changes, string $attribute): array
    {
        $out = [];
        foreach ($changes as $key => $value) {
            if (strcasecmp((string) $key, $attribute) !== 0) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $changes */
    private static function changeValueForAttribute(array $changes, string $attribute): ?string
    {
        foreach ($changes as $key => $value) {
            if (strcasecmp((string) $key, $attribute) !== 0) {
                continue;
            }
            return trim((string) $value);
        }
        return null;
    }
}
