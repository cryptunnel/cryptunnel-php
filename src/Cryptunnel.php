<?php

declare(strict_types=1);

namespace Cryptunnel;

use Cryptunnel\Exception\ApiException;
use Cryptunnel\Exception\CryptunnelException;
use Cryptunnel\Exception\PaymentTimeoutException;
use Cryptunnel\Exception\RateLimitException;

/**
 * The Cryptunnel API client.
 *
 *     $cryptunnel = new Cryptunnel('<merchant id>', '<api key>', sandbox: true);
 *     $payment = $cryptunnel->createWidgetPayment(10, 'USD', 'order-1');
 *     echo $payment['url'];
 *
 * Every call returns the decoded JSON of its endpoint as an array and throws a CryptunnelException
 * subclass on failure.
 */
class Cryptunnel
{
    public const DEFAULT_BASE_URL = 'https://api.cryptunnel.io';
    public const DEFAULT_TIMEOUT = 30;

    /** Statuses a payment never leaves. */
    public const TERMINAL_STATUSES = ['confirmed', 'confirmed_manual', 'failed', 'expired'];

    private readonly string $baseUrl;

    /**
     * @param bool $sandbox Mark every created payment as a test payment and list the testnet currencies
     * @param int $timeout Seconds before a request is abandoned
     * @param string|null $app Your application, appended to the User-Agent, e.g. "my-shop/2.0"
     */
    public function __construct(
        private readonly string $merchantId,
        private readonly string $apiKey,
        public readonly bool $sandbox = false,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
        private readonly ?string $app = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * The User-Agent every request carries: package, PHP, curl and the platform, plus your $app if
     * given. Cryptunnel uses it to see which SDK versions merchants integrate with. Nothing identifying
     * is included - no hostname, no paths.
     */
    public function userAgent(): string
    {
        $base = sprintf(
            'cryptunnel-php/%s php/%s curl/%s (%s %s)',
            self::version(),
            PHP_VERSION,
            curl_version()['version'] ?? 'unknown',
            PHP_OS_FAMILY,
            php_uname('m'),
        );

        return $this->app === null ? $base : "$base {$this->app}";
    }

    private static function version(): string
    {
        try {
            $version = \Composer\InstalledVersions::getPrettyVersion('cryptunnel/cryptunnel');
            // Inside this repository Composer reports the root package as "1.0.0+no-version-set"
            if ($version === null || str_contains($version, 'no-version-set')) {
                return 'dev';
            }

            return ltrim($version, 'v');
        } catch (\Throwable) {
            return 'dev';
        }
    }

    /**
     * Create a payment and get the widget url to send the payer to.
     *
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>
     */
    public function createWidgetPayment(
        float|int $amount,
        string $currency,
        string $externalId,
        ?string $successUrl = null,
        ?string $failUrl = null,
        ?string $callbackUrl = null,
        ?array $metadata = null,
        ?string $feePayer = null,
    ): array {
        $body = $this->paymentBody($amount, $currency, $externalId, $callbackUrl, $metadata, $feePayer)
            + self::present(['success_url' => $successUrl, 'fail_url' => $failUrl]);

        return $this->request('POST', '/v1/payments/widget', body: $body);
    }

    /**
     * Create a payment and get the wallet address and crypto amount to show yourself.
     *
     * @param string $targetCurrency One of the codes listCurrencies() returns
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>
     */
    public function createH2hPayment(
        float|int $amount,
        string $currency,
        string $externalId,
        string $targetCurrency,
        bool $autoTrace = false,
        ?string $callbackUrl = null,
        ?array $metadata = null,
        ?string $feePayer = null,
    ): array {
        $body = $this->paymentBody($amount, $currency, $externalId, $callbackUrl, $metadata, $feePayer)
            + ['target_currency' => $targetCurrency, 'auto_trace' => $autoTrace];

        return $this->request('POST', '/v1/payments/h2h', body: $body);
    }

    /**
     * Read one payment by its Cryptunnel id.
     *
     * @return array<string, mixed>
     */
    public function getPayment(string $paymentId): array
    {
        return $this->request('GET', '/v1/payments/' . rawurlencode($paymentId));
    }

    /**
     * List your payments, newest first.
     *
     * @return array<string, mixed>
     */
    public function listPayments(int $limit = 20, int $offset = 0): array
    {
        return $this->request('GET', '/v1/payments', query: ['limit' => $limit, 'offset' => $offset]);
    }

    /**
     * List the currencies you can receive - exactly the values $targetCurrency accepts.
     *
     * @return list<array<string, mixed>>
     */
    public function listCurrencies(): array
    {
        return $this->request('GET', '/v1/currencies', query: ['is_test' => $this->sandbox ? 'true' : 'false']);
    }

    /**
     * Read your merchant - the call that tells you the credentials work.
     *
     * @return array<string, mixed>
     */
    public function getMerchant(): array
    {
        return $this->request('GET', '/v1/merchants');
    }

    /**
     * Poll until the payment reaches a terminal status.
     *
     * For scripts and development. In production the webhook is the guarantee: a buyer who closes the
     * page still produces a callback, a polling process that dies does not.
     *
     * @param int $timeout Give up after this many seconds
     * @param int $firstDelay Seconds before the first poll
     * @param int $maxDelay Cap for the exponential backoff, in seconds
     * @return array<string, mixed>
     */
    public function waitForPayment(string $paymentId, int $timeout = 1800, int $firstDelay = 5, int $maxDelay = 30): array
    {
        $deadline = microtime(true) + $timeout;
        $backoff = (float) $firstDelay;
        // The API sends Retry-After on a 429, but the backoff has to stand on its own if it ever stops
        $nextDelay = function (?float $retryAfter = null) use (&$backoff, $maxDelay, $deadline): float {
            $delay = $retryAfter ?? $backoff;
            $backoff = min($backoff * 2, (float) $maxDelay);

            return max(0.0, min($delay, $deadline - microtime(true)));
        };

        $delay = $nextDelay();
        while (true) {
            usleep((int) ($delay * 1_000_000));
            if (microtime(true) >= $deadline) {
                throw new PaymentTimeoutException("Payment $paymentId did not settle within $timeout seconds");
            }
            try {
                $payment = $this->getPayment($paymentId);
            } catch (RateLimitException $error) {
                $delay = $nextDelay($error->retryAfter);
                continue;
            }
            if (in_array($payment['status'] ?? null, self::TERMINAL_STATUSES, true)) {
                return $payment;
            }
            $delay = $nextDelay();
        }
    }

    /**
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>
     */
    private function paymentBody(
        float|int $amount,
        string $currency,
        string $externalId,
        ?string $callbackUrl,
        ?array $metadata,
        ?string $feePayer,
    ): array {
        return [
            'amount' => $amount,
            'currency' => $currency,
            'external_id' => $externalId,
            'is_test' => $this->sandbox,
        ] + self::present(['callback_url' => $callbackUrl, 'metadata' => $metadata, 'fee_payer' => $feePayer]);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, scalar>|null $query
     * @return array<mixed>
     */
    private function request(string $method, string $path, ?array $body = null, ?array $query = null): array
    {
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $response = $this->send($method, $url, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $payload = json_decode($response['body'], true);

        if ($response['status'] >= 200 && $response['status'] < 300) {
            return is_array($payload) ? $payload : [];
        }

        throw CryptunnelException::fromResponse($response['status'], $payload, self::retryAfter($response['headers']));
    }

    /**
     * One HTTP exchange over ext-curl. Tests override it to stub the transport.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    protected function send(string $method, string $url, ?string $body): array
    {
        $headers = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'user-agent: ' . $this->userAgent(),
                'x-merchant-id: ' . $this->merchantId,
                'x-api-key: ' . $this->apiKey,
            ],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            throw new ApiException("Request to $url failed: " . curl_error($handle));
        }

        return [
            'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'headers' => $headers,
            'body' => (string) $responseBody,
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function present(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, string> $headers */
    private static function retryAfter(array $headers): ?float
    {
        $value = $headers['retry-after'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
