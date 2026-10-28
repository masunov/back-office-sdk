<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A free slot: its time, the rule of its window, free places and the free resources of each pool asked for.
 */
final class Slot
{
    /**
     * @param  list<string>  $freeResourceRefs
     * @param  list<string>  $freeExtraResourceRefs  of the second pool, e.g. the instructors
     */
    public function __construct(
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly string $workScheduleId,
        public readonly int $freeEntries,
        public readonly array $freeResourceRefs,
        public readonly array $freeExtraResourceRefs = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['starts_at'],
            $data['ends_at'],
            $data['work_schedule_id'],
            (int) $data['free_entries'],
            array_values($data['free_resource_refs'] ?? []),
            array_values($data['free_extra_resource_refs'] ?? [])
        );
    }
}
