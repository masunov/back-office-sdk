<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * The result of closing a rule: the closed rule (null — it was closed before it started and is removed), the rule
 * that replaces it and the bookings that went to it.
 */
final class CloseResult
{
    /**
     * @param  list<string>  $reboundBookings
     */
    public function __construct(
        public readonly ?WorkSchedule $closed,
        public readonly ?WorkSchedule $replacement,
        public readonly array $reboundBookings,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['closed']) ? WorkSchedule::fromArray($data['closed']) : null,
            isset($data['replacement']) ? WorkSchedule::fromArray($data['replacement']) : null,
            array_values($data['rebound_bookings'])
        );
    }
}
