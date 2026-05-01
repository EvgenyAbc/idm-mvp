<?php

declare(strict_types=1);

namespace IDM\Presentation\Http;

final class Response
{
    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($data);
        exit;
    }
}
