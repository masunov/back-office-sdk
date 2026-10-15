<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A page of bookings; the next one is asked for with `nextCursor`.
 */
final class BookingPage
{
    /**
     * @param  list<Booking>  $bookings
     */
    public function __construct(
        public readonly array $bookings,
        public readonly ?string $nextCursor,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(fn (array $item): Booking => Booking::fromArray($item), $data['data']),
            $data['meta']['next_cursor'] ?? null
        );
    }
}
