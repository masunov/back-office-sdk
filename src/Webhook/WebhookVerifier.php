<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Webhook;

use Mnd\BackOffice\Exceptions\InvalidSignatureException;

/**
 * Checks `X-Signature: t=<unix time>,v1=<hex>`: HMAC-SHA256 of `<t>.<body>` with the webhook secret of the project
 * (docs/openapi.yaml). An old signature is refused, so a captured request cannot be replayed later.
 */
final class WebhookVerifier
{
    public const HEADER = 'X-Signature';

    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {
    }

    /**
     * @throws InvalidSignatureException
     */
    public function verify(?string $header, string $body, ?int $now = null): WebhookEvent
    {
        if ($this->secret === '') {
            throw new InvalidSignatureException('Webhook secret of back-office is not configured');
        }

        $parts = [];

        foreach (explode(',', (string) $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$name]   = $value;
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
            throw new InvalidSignatureException('Webhook signature is missing or malformed');
        }

        if (abs(($now ?? time()) - (int) $parts['t']) > $this->toleranceSeconds) {
            throw new InvalidSignatureException('Webhook signature is too old');
        }

        $expected = hash_hmac('sha256', $parts['t'] . '.' . $body, $this->secret);

        if (! hash_equals($expected, $parts['v1'])) {
            throw new InvalidSignatureException('Webhook signature does not match');
        }

        return WebhookEvent::fromJson($body);
    }
}
