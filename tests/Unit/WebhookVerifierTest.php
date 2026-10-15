<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Unit;

use Mnd\BackOffice\Exceptions\InvalidSignatureException;
use Mnd\BackOffice\Webhook\WebhookVerifier;
use PHPUnit\Framework\TestCase;

class WebhookVerifierTest extends TestCase
{
    private const SECRET = 'whsec_test';
    private const NOW    = 1760000000;

    /**
     * What the service sends (back-office WebhookSignature::sign).
     */
    public static function sign(string $body, int $time = self::NOW, string $secret = self::SECRET): string
    {
        return 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, $secret);
    }

    public static function body(string $type = 'finance.operation.created'): string
    {
        return json_encode(
            [
                'event_id'           => '01920000-0000-7000-8000-0000000000e1',
                'type'               => $type,
                'tenant_external_id' => 'org-1',
                'occurred_at'        => '2026-10-10T10:00:00+00:00',
                'version'            => 1,
                'data'               => ['id' => 'op-1'],
            ],
            JSON_THROW_ON_ERROR
        );
    }

    public function testSignedEventIsParsed(): void
    {
        $event = (new WebhookVerifier(self::SECRET))->verify(self::sign(self::body()), self::body(), self::NOW + 10);

        $this->assertSame('01920000-0000-7000-8000-0000000000e1', $event->eventId);
        $this->assertSame('finance.operation.created', $event->type);
        $this->assertSame('org-1', $event->tenantExternalId);
        $this->assertSame(['id' => 'op-1'], $event->data);
    }

    /**
     * @return array<string, array{0: string|null, 1: int}>
     */
    public static function badSignatures(): array
    {
        return [
            'missing'      => [null, self::NOW],
            'other secret' => [self::sign(self::body(), self::NOW, 'whsec_other'), self::NOW],
            'other body'   => [self::sign(self::body('platform.ping')), self::NOW],
            'too old'      => [self::sign(self::body()), self::NOW + 301],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badSignatures')]
    public function testBadSignatureIsRefused(?string $header, int $now): void
    {
        $this->expectException(InvalidSignatureException::class);

        (new WebhookVerifier(self::SECRET))->verify($header, self::body(), $now);
    }

    public function testEmptySecretRefusesEverything(): void
    {
        $this->expectException(InvalidSignatureException::class);

        (new WebhookVerifier(''))->verify(self::sign(self::body(), self::NOW, ''), self::body(), self::NOW);
    }
}
