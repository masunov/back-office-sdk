<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Webhook;

use Mnd\BackOffice\Exceptions\BackOfficeException;

/**
 * An event of the service. Handle it idempotently by `eventId`: a retry or a replay brings the same id
 * (ROADMAP.md, 2.5). `data` is the snapshot of the object, e.g. a FinanceOperation::fromArray().
 */
final class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly ?string $tenantExternalId,
        public readonly string $occurredAt,
        public readonly int $version,
        public readonly array $data,
    ) {
    }

    public static function fromJson(string $body): self
    {
        $payload = json_decode($body, true);

        if (! \is_array($payload) || ! isset($payload['event_id'], $payload['type'], $payload['occurred_at'])) {
            throw new BackOfficeException('Webhook body is not an event of back-office');
        }

        return new self(
            (string) $payload['event_id'],
            (string) $payload['type'],
            $payload['tenant_external_id'] ?? null,
            (string) $payload['occurred_at'],
            (int) ($payload['version'] ?? 1),
            \is_array($payload['data'] ?? null) ? $payload['data'] : []
        );
    }
}
