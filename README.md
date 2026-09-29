# cryptunnel

PHP SDK for [Cryptunnel](https://cryptunnel.io) - accept crypto payments straight into your own
wallets. No Composer dependencies: `ext-curl` and `ext-json`, which every PHP build ships.

```bash
composer require cryptunnel/cryptunnel
```

PHP 8.1+.

## Create a sandbox payment

```php
<?php

require 'vendor/autoload.php';

use Cryptunnel\Cryptunnel;

$cryptunnel = new Cryptunnel('<merchant id>', '<api key>', sandbox: true);

print_r($cryptunnel->getMerchant()); // your credentials work if this prints your merchant

$payment = $cryptunnel->createWidgetPayment(
    amount: 10,
    currency: 'USD',
    externalId: 'order-1',
    successUrl: 'https://example.com/thanks',
);
echo $payment['url']; // send the buyer here
```

`sandbox: true` sets `is_test` on every payment it creates: the payer is offered testnet currencies
only, and the payment is excluded from your stats and fees. It is the same host and the same key -
the sandbox is a flag, not a second account.

## Verify a webhook

```php
<?php

require 'vendor/autoload.php';

use Cryptunnel\Webhook;

$rawBody = file_get_contents('php://input'); // the exact bytes - never re-encode the JSON

if (!Webhook::verify('<whsec_...>', $_SERVER, $rawBody)) {
    http_response_code(401);
    exit;
}

$payment = json_decode($rawBody, true);
if (in_array($payment['status'], ['confirmed', 'confirmed_manual'], true)) {
    deliver($payment['external_id']); // deduplicate on (id, status): retries repeat the same pair
}
http_response_code(200);
```

`Webhook::verify` reads the headers from whatever you have: `$_SERVER` as above, `getallheaders()`,
or a framework bag such as Laravel's `$request->headers->all()` next to `$request->getContent()`.
A failed delivery is retried 60 times, once a minute, for one hour, each attempt re-signed with a
fresh timestamp over the same body.

## The whole surface

| Method | Call |
| --- | --- |
| `createWidgetPayment($amount, $currency, $externalId, ...)` | `POST /v1/payments/widget` |
| `createH2hPayment($amount, $currency, $externalId, $targetCurrency, ...)` | `POST /v1/payments/h2h` |
| `getPayment($paymentId)` | `GET /v1/payments/{id}` |
| `listPayments($limit, $offset)` | `GET /v1/payments` |
| `listCurrencies()` | `GET /v1/currencies` |
| `getMerchant()` | `GET /v1/merchants` |
| `Webhook::verify($secret, $headers, $rawBody)` | local, no request |
| `waitForPayment($paymentId)` | polls `GET /v1/payments/{id}` |

Every call returns the decoded JSON of its endpoint as an array.

Payment creation is idempotent on `externalId`: a retry after a network timeout returns the payment
you already created instead of a second one. Repeating an `externalId` with a different amount or
currency is rejected with `PAYMENT_ALREADY_EXISTS`.

`getPayment` returns two shapes, and the status tells them apart: a `created` or `expired` payment
carries no `amount`, `currency` or `wallet_address`, because nobody has picked a currency for it
yet. Read them with `$payment['amount'] ?? null` unless you already know the status.

`waitForPayment` is for scripts and development - it polls every 5 seconds, backing off to 30, and
throws `PaymentTimeoutException` after 30 minutes. In production the webhook is the guarantee: a
buyer who closes the page still produces a callback.

## Errors

```php
use Cryptunnel\Exception\AuthenticationException;
use Cryptunnel\Exception\RateLimitException;
use Cryptunnel\Exception\ValidationException;

try {
    $cryptunnel->createH2hPayment(10, 'USD', 'order-3', 'DOGE');
} catch (ValidationException $error) {
    echo $error->apiCode; // WALLET_NOT_FOUND - you have no active DOGE wallet
} catch (RateLimitException $error) {
    echo $error->retryAfter; // seconds to wait; null when the API sends no Retry-After header
} catch (AuthenticationException $error) {
    echo 'check the merchant id and the api key';
}
```

`CryptunnelException` is the base; `AuthenticationException` (401), `NotFoundException` (404),
`ValidationException` (400), `RateLimitException` (429) and `ApiException` (5xx and transport
failures) extend it. The raw API code is always on `apiCode`, and the HTTP status on `status`.

## Links

- [Quickstart](https://docs.cryptunnel.io/docs/quickstart) - registration to first payment
- [Sandbox and faucets](https://docs.cryptunnel.io/docs/sandbox) - test coins without spending any
- [API reference](https://docs.cryptunnel.io)
- Support: [GitHub Issues](https://github.com/cryptunnel/cryptunnel-php/issues)

MIT licensed.
