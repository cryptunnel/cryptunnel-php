<?php

declare(strict_types=1);

namespace Cryptunnel\Exception;

/** 429: too many requests. retryAfter is null when the API sends no header. */
class RateLimitException extends CryptunnelException
{
    public function __construct(
        string $message,
        ?string $apiCode = null,
        ?int $status = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $apiCode, $status);
    }
}
