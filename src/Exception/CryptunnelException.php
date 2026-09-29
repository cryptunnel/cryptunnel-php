<?php

declare(strict_types=1);

namespace Cryptunnel\Exception;

/** Base for every error this package throws - branch on the class, read the API code from apiCode. */
class CryptunnelException extends \RuntimeException
{
    /**
     * @param string|null $apiCode The raw API code, e.g. PAYMENT_ALREADY_EXISTS, CURRENCY_NOT_FOUND, WALLET_NOT_FOUND
     * @param int|null $status The HTTP status, null for a transport failure
     */
    public function __construct(
        string $message,
        public readonly ?string $apiCode = null,
        public readonly ?int $status = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /** Map an API error response onto the exception hierarchy. */
    public static function fromResponse(int $status, mixed $payload, ?float $retryAfter = null): self
    {
        $body = is_array($payload) ? $payload : [];
        $apiCode = is_string($body['code'] ?? null) ? $body['code'] : null;
        $message = is_string($body['message'] ?? null) ? $body['message'] : "Cryptunnel API returned $status";

        return match ($status) {
            401 => new AuthenticationException($message, $apiCode, $status),
            404 => new NotFoundException($message, $apiCode, $status),
            429 => new RateLimitException($message, $apiCode, $status, $retryAfter),
            400 => new ValidationException($message, $apiCode, $status),
            default => new ApiException($message, $apiCode, $status),
        };
    }
}
