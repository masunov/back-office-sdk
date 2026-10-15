<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A rule to write: `working()` with its places, or `nonWorking()` — a break or a day off over the working windows.
 * Dates `Y-m-d`, times `HH:MM` local; a one-off date is `startDate = endDate` without a day of the week.
 */
final class WorkScheduleInput
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $locationId,
        public readonly string $type,
        public readonly string $startDate,
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly ?int $maxEntries = null,
        public readonly ?string $endDate = null,
        public readonly ?int $dayOfWeek = null,
        public readonly ?string $timezone = null,
        public readonly ?int $slotInterval = null,
        public readonly ?string $description = null,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function working(
        string $locationId,
        string $startDate,
        string $startTime,
        string $endTime,
        int $maxEntries,
        ?string $endDate = null,
        ?int $dayOfWeek = null,
        ?string $timezone = null,
        ?int $slotInterval = null,
        ?string $description = null,
        array $metadata = [],
    ): self {
        return new self(
            $locationId,
            'working',
            $startDate,
            $startTime,
            $endTime,
            $maxEntries,
            $endDate,
            $dayOfWeek,
            $timezone,
            $slotInterval,
            $description,
            $metadata
        );
    }

    public static function nonWorking(
        string $locationId,
        string $startDate,
        string $startTime,
        string $endTime,
        ?string $endDate = null,
        ?int $dayOfWeek = null,
        ?string $timezone = null,
        ?string $description = null,
    ): self {
        return new self(
            $locationId,
            'non_working',
            $startDate,
            $startTime,
            $endTime,
            null,
            $endDate,
            $dayOfWeek,
            $timezone,
            null,
            $description
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'location_id'   => $this->locationId,
                'type'          => $this->type,
                'start_date'    => $this->startDate,
                'end_date'      => $this->endDate,
                'day_of_week'   => $this->dayOfWeek,
                'start_time'    => $this->startTime,
                'end_time'      => $this->endTime,
                'timezone'      => $this->timezone,
                'max_entries'   => $this->maxEntries,
                'slot_interval' => $this->slotInterval,
                'description'   => $this->description,
                'metadata'      => $this->metadata === [] ? null : $this->metadata,
            ],
            fn (mixed $value): bool => $value !== null
        );
    }
}
