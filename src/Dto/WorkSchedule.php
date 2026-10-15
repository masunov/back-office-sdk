<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A rule of the working schedule of a location. Times are local, `HH:MM`, in `timezone` or, without one, in the
 * timezone of the tenant. Also `data.work_schedule` of the webhook `work_schedule.changed`.
 */
final class WorkSchedule
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $locationId,
        public readonly string $type,
        public readonly string $startDate,
        public readonly ?string $endDate,
        public readonly ?int $dayOfWeek,
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly ?string $timezone,
        public readonly ?int $maxEntries,
        public readonly ?int $slotInterval,
        public readonly ?string $description,
        public readonly array $metadata,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['location_id'],
            $data['type'],
            $data['start_date'],
            $data['end_date'] ?? null,
            $data['day_of_week'] ?? null,
            $data['start_time'],
            $data['end_time'],
            $data['timezone'] ?? null,
            $data['max_entries'] ?? null,
            $data['slot_interval'] ?? null,
            $data['description'] ?? null,
            (array) ($data['metadata'] ?? [])
        );
    }

    public function isWorking(): bool
    {
        return $this->type === 'working';
    }
}
