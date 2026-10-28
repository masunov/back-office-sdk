<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A booking. Times are ISO 8601 with the offset of the location. Also the `data` of the webhooks `booking.*`.
 */
final class Booking
{
    public const HELD      = 'held';
    public const CONFIRMED = 'confirmed';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const NO_SHOW   = 'no_show';
    public const EXPIRED   = 'expired';

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $extraResourceRefs  more resources the booking holds, e.g. an instructor beside a car
     */
    public function __construct(
        public readonly string $id,
        public readonly string $workScheduleId,
        public readonly string $locationId,
        public readonly string $status,
        public readonly ?string $outcomeCode,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly ?string $actualEndsAt,
        public readonly int $duration,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $resourceRef,
        public readonly ?ExternalRef $externalRef,
        public readonly array $metadata,
        public readonly bool $blocking,
        public readonly ?string $holdExpiresAt,
        public readonly Actor $createdBy,
        public readonly int $version,
        public readonly array $extraResourceRefs = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['work_schedule_id'],
            $data['location_id'],
            $data['status'],
            $data['outcome_code'] ?? null,
            $data['starts_at'],
            $data['ends_at'],
            $data['actual_ends_at'] ?? null,
            (int) $data['duration'],
            $data['title'],
            $data['description'] ?? null,
            $data['resource_ref'] ?? null,
            isset($data['external_ref']) ? ExternalRef::fromArray($data['external_ref']) : null,
            (array) ($data['metadata'] ?? []),
            (bool) $data['blocking'],
            $data['hold_expires_at'] ?? null,
            new Actor($data['created_by']['id'] ?? null, $data['created_by']['name'] ?? null),
            (int) $data['version'],
            array_values($data['extra_resource_refs'] ?? [])
        );
    }

    /**
     * Held or confirmed: takes its place and resources.
     */
    public function isActive(): bool
    {
        return $this->status === self::HELD || $this->status === self::CONFIRMED;
    }
}
