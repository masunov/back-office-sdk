<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * Plan, fact and deviation (fact − plan), strings with two decimals.
 */
final class Totals
{
    public function __construct(
        public readonly string $plan,
        public readonly string $fact,
        public readonly string $deviation,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['plan'], $data['fact'], $data['deviation']);
    }
}
