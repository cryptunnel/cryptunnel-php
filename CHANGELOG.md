# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package follows semantic versioning.

## [1.0.0] - 2026-10-03

### Added

- `Cryptunnel` client on ext-curl - no Composer dependencies.
- Payment creation (widget and h2h), payment read and list, currency list, merchant info.
- `Webhook::verify` - constant-time HMAC-SHA256 verification with a timestamp tolerance, accepting
  `getallheaders()`, framework header bags and `$_SERVER` alike.
- `waitForPayment` - polling with exponential backoff for scripts and development.
- Exception hierarchy mapping API status codes, keeping the raw API code on `apiCode`.
- `sandbox: true` argument marking every created payment as a test payment.

### Notes

- Releases reach Packagist through its GitHub hook: tag `v1.0.0`, push the tag, done. There is no
  publish workflow and no token to keep.
