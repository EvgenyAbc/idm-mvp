<?php

declare(strict_types=1);

$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require_once $vendor;
} else {
    require_once dirname(__DIR__) . '/bootstrap_autoload.php';
}
