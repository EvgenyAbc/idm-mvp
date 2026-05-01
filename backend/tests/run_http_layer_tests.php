<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap_autoload.php';

use IDM\Application\AppContext;
use IDM\Presentation\Http\Request;
use IDM\Presentation\Http\Router;
use IDM\Presentation\Http\Security;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function request(string $method, string $uri, string $auth = ''): Request
{
    return new Request(
        [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'HTTP_AUTHORIZATION' => $auth,
        ],
        [],
        []
    );
}

$router = new Router();
$hit = [];

$router->add('GET', '/api/auth/me', function () use (&$hit): void {
    $hit[] = 'auth';
});
$router->add('POST', '/api/provision/run-poll', function () use (&$hit): void {
    $hit[] = 'provision';
});
$router->add('PUT', '#^/api/source-users/([^/]+)$#', function () use (&$hit): void {
    $hit[] = 'source';
}, true);
$router->add('POST', '#^/api/approvals/(\d+)/(approve|reject)$#', function () use (&$hit): void {
    $hit[] = 'approval';
}, true);
$router->add('GET', '#^/api/ldap/subtree/(.+)$#', function () use (&$hit): void {
    $hit[] = 'ldap';
}, true);

$dummyCtx = (new ReflectionClass(Context::class))->newInstanceWithoutConstructor();

assertTrue($router->dispatch(request('GET', '/api/auth/me'), $dummyCtx), 'auth route not dispatched');
assertTrue($router->dispatch(request('POST', '/api/provision/run-poll'), $dummyCtx), 'provision route not dispatched');
assertTrue($router->dispatch(request('PUT', '/api/source-users/john'), $dummyCtx), 'source-user route not dispatched');
assertTrue($router->dispatch(request('POST', '/api/approvals/22/approve'), $dummyCtx), 'approval route not dispatched');
assertTrue($router->dispatch(request('GET', '/api/ldap/subtree/cn%3Djohn'), $dummyCtx), 'ldap subtree route not dispatched');
assertTrue(!$router->dispatch(request('GET', '/api/missing'), $dummyCtx), 'unknown route should not dispatch');

$decodedUser = Security::usernameFromAuthHeader(request('GET', '/api/auth/me', 'Bearer ' . base64_encode('admin')));
assertTrue($decodedUser === 'admin', 'auth username decode failed');
assertTrue(Security::hasPermission(['permissions' => ['x.read']], 'x.read'), 'permission check failed');
assertTrue(Security::ldapDnsEqual('cn=John,dc=example', 'CN=John,DC=example'), 'DN equality should be case-insensitive');

echo "HTTP layer tests passed.\n";
