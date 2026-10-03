<?php

declare(strict_types=1);

namespace Cryptunnel\Tests;

use Cryptunnel\Cryptunnel;
use Cryptunnel\Exception\ApiException;
use Cryptunnel\Exception\AuthenticationException;
use Cryptunnel\Exception\CryptunnelException;
use Cryptunnel\Exception\NotFoundException;
use Cryptunnel\Exception\RateLimitException;
use Cryptunnel\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A client whose transport is a canned response - the only seam the package needs for tests. */
final class StubClient extends Cryptunnel
{
    /** @var list<array{method: string, url: string, body: ?string}> */
    public array $calls = [];

    /** @param array<string, string> $stubHeaders */
    public function __construct(
        private readonly int $stubStatus,
        private readonly mixed $stubPayload,
        private readonly array $stubHeaders = [],
        string $baseUrl = Cryptunnel::DEFAULT_BASE_URL,
    ) {
        parent::__construct('merchant-id', 'ct_live_key', sandbox: true, baseUrl: $baseUrl);
    }

    protected function send(string $method, string $url, ?string $body): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];

        return ['status' => $this->stubStatus, 'headers' => $this->stubHeaders, 'body' => json_encode($this->stubPayload)];
    }
}

final class ClientTest extends TestCase
{
    public function testSandboxMarksCreatesAsTestPayments(): void
    {
        $client = new StubClient(200, ['id' => 'pay-1', 'url' => 'https://pay.cryptunnel.io/pay-1']);

        $payment = $client->createWidgetPayment(10, 'USD', 'order-1', successUrl: 'https://example.com/ok');

        self::assertSame('https://pay.cryptunnel.io/pay-1', $payment['url']);
        $body = json_decode((string) $client->calls[0]['body'], true);
        self::assertTrue($body['is_test']);
        self::assertSame('https://example.com/ok', $body['success_url']);
        self::assertArrayNotHasKey('fail_url', $body);
        self::assertSame('POST', $client->calls[0]['method']);
    }

    public function testSandboxAsksForTheTestnetCurrencyFamily(): void
    {
        $client = new StubClient(200, []);

        $client->listCurrencies();

        self::assertSame('https://api.cryptunnel.io/v1/currencies?is_test=true', $client->calls[0]['url']);
    }

    public function testH2hSendsTheTargetCurrency(): void
    {
        $client = new StubClient(200, ['id' => 'pay-1']);

        $client->createH2hPayment(10, 'USD', 'order-1', 'USDT');

        self::assertSame('USDT', json_decode((string) $client->calls[0]['body'], true)['target_currency']);
        self::assertStringEndsWith('/v1/payments/h2h', $client->calls[0]['url']);
    }

    public function testListPaymentsDefaultsToTheFirstPage(): void
    {
        $client = new StubClient(200, ['items' => [], 'total' => 0, 'limit' => 20, 'offset' => 0]);

        $client->listPayments();

        self::assertStringEndsWith('/v1/payments?limit=20&offset=0', $client->calls[0]['url']);
    }

    /** @return iterable<string, array{int, class-string<CryptunnelException>}> */
    public static function errorStatuses(): iterable
    {
        yield '400' => [400, ValidationException::class];
        yield '401' => [401, AuthenticationException::class];
        yield '404' => [404, NotFoundException::class];
        yield '500' => [500, ApiException::class];
    }

    #[DataProvider('errorStatuses')]
    public function testEachErrorStatusMapsToItsClass(int $status, string $expected): void
    {
        $client = new StubClient($status, ['code' => 'WALLET_NOT_FOUND', 'message' => 'Wallet not found']);

        try {
            $client->getMerchant();
            self::fail("$status should throw");
        } catch (CryptunnelException $error) {
            self::assertInstanceOf($expected, $error);
            self::assertSame('WALLET_NOT_FOUND', $error->apiCode);
            self::assertSame($status, $error->status);
            self::assertSame('Wallet not found', $error->getMessage());
        }
    }

    public function testRateLimitCarriesRetryAfterWhenTheHeaderIsThere(): void
    {
        $client = new StubClient(429, ['code' => 'TOO_MANY'], ['retry-after' => '12']);

        try {
            $client->getMerchant();
            self::fail('429 should throw');
        } catch (RateLimitException $error) {
            self::assertSame(12.0, $error->retryAfter);
        }
    }

    public function testRateLimitWithoutAHeaderLeavesRetryAfterUnset(): void
    {
        $client = new StubClient(429, ['code' => 'TOO_MANY']);

        try {
            $client->getMerchant();
            self::fail('429 should throw');
        } catch (RateLimitException $error) {
            self::assertNull($error->retryAfter);
        }
    }

    public function testTheUserAgentNamesThePackageRuntimeAndPlatform(): void
    {
        $client = new Cryptunnel('merchant-id', 'ct_live_key');

        self::assertMatchesRegularExpression('#^cryptunnel-php/\S+ php/\S+ curl/\S+ \(\S+ \S+\)$#', $client->userAgent());
    }

    public function testTheAppNameIsAppendedToTheUserAgent(): void
    {
        $client = new Cryptunnel('merchant-id', 'ct_live_key', app: 'my-shop/2.0');

        self::assertStringEndsWith(' my-shop/2.0', $client->userAgent());
    }

    public function testBaseUrlIsHonoured(): void
    {
        $client = new StubClient(200, [], baseUrl: 'http://localhost:3000/');

        $client->getMerchant();

        self::assertSame('http://localhost:3000/v1/merchants', $client->calls[0]['url']);
    }
}
