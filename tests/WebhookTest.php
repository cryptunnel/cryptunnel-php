<?php

declare(strict_types=1);

namespace Cryptunnel\Tests;

use Cryptunnel\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test';
    private const BODY = '{"id":"n9cdFaTccYbXecVekHKW8Q","status":"confirmed"}';

    /** @return array<string, string> */
    private static function sign(string $body, int $timestamp, string $secret = self::SECRET): array
    {
        return [
            'x-webhook-timestamp' => (string) $timestamp,
            'x-webhook-signature' => hash_hmac('sha256', "$timestamp.$body", $secret),
        ];
    }

    public function testAcceptsAGenuineCallback(): void
    {
        self::assertTrue(Webhook::verify(self::SECRET, self::sign(self::BODY, time()), self::BODY));
    }

    public function testAcceptsHeadersInAnyCasing(): void
    {
        $headers = array_combine(array_map('ucwords', array_keys(self::sign(self::BODY, time()))), self::sign(self::BODY, time()));
        self::assertTrue(Webhook::verify(self::SECRET, $headers, self::BODY));
    }

    public function testAcceptsServerStyleHeadersAndArrayValues(): void
    {
        $signed = self::sign(self::BODY, time());
        $server = [
            'HTTP_X_WEBHOOK_TIMESTAMP' => $signed['x-webhook-timestamp'],
            'HTTP_X_WEBHOOK_SIGNATURE' => [$signed['x-webhook-signature']],
            'REQUEST_METHOD' => 'POST',
        ];
        self::assertTrue(Webhook::verify(self::SECRET, $server, self::BODY));
    }

    public function testRejectsATamperedBody(): void
    {
        self::assertFalse(Webhook::verify(self::SECRET, self::sign(self::BODY, time()), self::BODY . ' '));
    }

    public function testRejectsAStaleTimestamp(): void
    {
        self::assertFalse(Webhook::verify(self::SECRET, self::sign(self::BODY, time() - 301), self::BODY));
    }

    public function testAcceptsAStaleTimestampWithinAWiderTolerance(): void
    {
        self::assertTrue(Webhook::verify(self::SECRET, self::sign(self::BODY, time() - 301), self::BODY, 600));
    }

    public function testRejectsASignatureOfTheWrongLength(): void
    {
        $headers = self::sign(self::BODY, time());
        $headers['x-webhook-signature'] = substr($headers['x-webhook-signature'], 0, 10);
        self::assertFalse(Webhook::verify(self::SECRET, $headers, self::BODY));
    }

    public function testRejectsAnotherSecret(): void
    {
        self::assertFalse(Webhook::verify(self::SECRET, self::sign(self::BODY, time(), 'whsec_other'), self::BODY));
    }

    public function testRejectsMissingOrUnparsableHeaders(): void
    {
        self::assertFalse(Webhook::verify(self::SECRET, [], self::BODY));
        $headers = self::sign(self::BODY, time());
        $headers['x-webhook-timestamp'] = 'not-a-number';
        self::assertFalse(Webhook::verify(self::SECRET, $headers, self::BODY));
    }
}
