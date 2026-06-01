<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

use RuntimeException;

final class ApiException extends RuntimeException
{
    private int $statusCode;
    private string $errorCode;
    private array $details;

    public function __construct(string $message, string $errorCode, int $statusCode = 400, array $details = array())
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->details = $details;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
