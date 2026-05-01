<?php

declare(strict_types=1);

namespace IDM\Presentation\Http;

use IDM\Application\AppContext;

final class Router
{
    /** @var array<int, array{method: string, pattern: string, regex: bool, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, bool $regex = false): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $regex,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request, AppContext $context): bool
    {
        $method = strtoupper($request->method());
        $path = $request->path();
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if ($route['regex']) {
                $m = [];
                if (!preg_match($route['pattern'], $path, $m)) {
                    continue;
                }
                ($route['handler'])($request, $context, $m);
                return true;
            }

            if ($route['pattern'] !== $path) {
                continue;
            }

            ($route['handler'])($request, $context, []);
            return true;
        }

        return false;
    }
}
