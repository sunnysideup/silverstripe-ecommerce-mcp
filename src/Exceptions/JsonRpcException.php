<?php

declare(strict_types=1);

namespace Sunnysideup\EcommerceMCP\Exceptions;

use RuntimeException;

/**
 * An error that should surface to the client as a JSON-RPC error response
 * with a specific HTTP status.
 */
class JsonRpcException extends RuntimeException
{
    public function __construct(
        int $code,
        string $message,
        private readonly int $httpStatus = 400,
        private readonly ?array $data = null,
    ) {
        parent::__construct($message, $code);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getData(): ?array
    {
        return $this->data;
    }
}
