<?php

declare(strict_types=1);

/**
 * PSR-4 autoload when Composer vendor/autoload.php is not installed.
 * Use: require __DIR__ . '/bootstrap_autoload.php';
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'IDM\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $base = __DIR__ . '/src/';
    $path = $base . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

spl_autoload_register(static function (string $class): void {
    $prefix = 'IDM\\Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $base = __DIR__ . '/tests/';
    $path = $base . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
