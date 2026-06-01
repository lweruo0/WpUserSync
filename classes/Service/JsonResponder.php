<?php

declare(strict_types=1);

namespace WpUserSync\classes\Service;

final class JsonResponder
{
    public static function send(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function sendError(ApiException $exception): void
    {
        self::send(array(
            'status' => 'error',
            'code' => $exception->getErrorCode(),
            'message' => $exception->getMessage(),
            'details' => $exception->getDetails(),
        ), $exception->getStatusCode());
    }
}
