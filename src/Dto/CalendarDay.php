<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * One local date of a location: its working windows, non-working intervals and bookings of every status.
 * Windows are arrays `{work_schedule_id, starts_at, ends_at, max_entries|description}`.
 */
final class CalendarDay
{
    /**
     * @param  list<array<string, mixed>>  $working
     * @param  list<array<string, mixed>>  $nonWorking
     * @param  list<Booking>  $bookings
     */
    public function __construct(
        public readonly string $date,
        public readonly array $working,
        public readonly array $nonWorking,
        public readonly array $bookings,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['date'],
            $data['working'],
            $data['non_working'],
            array_map(fn (array $item): Booking => Booking::fromArray($item), $data['bookings'])
        );
    }
}
