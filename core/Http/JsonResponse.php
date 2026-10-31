<?php
declare(strict_types=1);

namespace Biolab\Core\Http;

final class JsonResponse
{
    /** @param array<string, mixed> $data */
    public static function send(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
