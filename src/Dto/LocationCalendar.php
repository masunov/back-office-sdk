<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * The calendar of one location in `GET /v1/timetable/calendars`: its timezone and its days.
 */
final class LocationCalendar
{
    /**
     * @param  list<CalendarDay>  $days
     */
    public function __construct(
        public readonly string $locationId,
        public readonly string $timezone,
        public readonly array $days,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['location_id'],
            $data['timezone'],
            array_map(fn (array $day): CalendarDay => CalendarDay::fromArray($day), $data['days'])
        );
    }
}
