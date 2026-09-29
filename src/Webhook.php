<?php

declare(strict_types=1);

namespace Cryptunnel;

/** Webhook signature verification - the one part of an integration that must not be hand-rolled. */
final class Webhook
{
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Verify a callback signed by Cryptunnel.
     *
     * $rawBody must be the bytes as received - file_get_contents('php://input') - because parsing and
     * re-serialising the JSON changes the signature. $headers accepts what any framework hands over:
     * getallheaders(), a Laravel/Symfony header bag, or $_SERVER with its HTTP_ keys. Returns false for
     * anything that does not verify - it never throws.
     *
     * @param array<string, string|string[]> $headers
     */
    public static function verify(string $secret, array $headers, string $rawBody, int $tolerance = self::DEFAULT_TOLERANCE): bool
    {
        $timestamp = self::header($headers, 'x-webhook-timestamp');
        $signature = self::header($headers, 'x-webhook-signature');
        if ($timestamp === null || $signature === null) {
            return false;
        }

        if (preg_match('/^\d+$/', $timestamp) !== 1 || abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', "$timestamp.$rawBody", $secret);
        // hash_equals is constant time and safe for signatures of the wrong length
        return hash_equals($expected, $signature);
    }

    /** @param array<string, string|string[]> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            // $_SERVER spells the header HTTP_X_WEBHOOK_TIMESTAMP
            $normalized = strtolower(str_replace('_', '-', preg_replace('/^HTTP_/', '', (string) $key) ?? ''));
            if ($normalized !== $name) {
                continue;
            }
            $value = is_array($value) ? ($value[0] ?? null) : $value;
            return $value === null || $value === '' ? null : (string) $value;
        }

        return null;
    }
}
