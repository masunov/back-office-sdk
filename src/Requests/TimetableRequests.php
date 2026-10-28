<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Requests;

use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Dto\WorkScheduleInput;
use Mnd\BackOffice\Http\OutgoingRequest;

/**
 * Requests of the module timetable (docs/openapi.yaml, /v1/timetable). Changes of one booking share the stream
 * `booking:<id>` in the outbox, so a cancel never overtakes the confirm before it.
 */
final class TimetableRequests
{
    /**
     * @param  'working'|'non_working'|null  $type
     */
    public static function listWorkSchedules(
        ?string $locationId = null,
        ?string $type = null,
        ?string $activeOn = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/timetable/work-schedules',
            ['location_id' => $locationId, 'type' => $type, 'active_on' => $activeOn]
        );
    }

    /**
     * @param  bool  $force  a non-working rule over active bookings is written anyway; they come in `covered_bookings`
     */
    public static function createWorkSchedule(WorkScheduleInput $rule, bool $force = false): OutgoingRequest
    {
        return OutgoingRequest::create(
            '/v1/timetable/work-schedules',
            $rule->toArray() + ($force ? ['force' => true] : []),
            'location:' . $rule->locationId
        );
    }

    /**
     * The whole rule, as PUT means.
     */
    public static function updateWorkSchedule(string $id, WorkScheduleInput $rule, bool $force = false): OutgoingRequest
    {
        return OutgoingRequest::put(
            '/v1/timetable/work-schedules/' . rawurlencode($id),
            $rule->toArray() + ($force ? ['force' => true] : []),
            'location:' . $rule->locationId
        );
    }

    /**
     * The rule stops from `$fromDate`; its bookings from then go to `$replacement`.
     */
    public static function closeWorkSchedule(
        string $id,
        string $fromDate,
        ?WorkScheduleInput $replacement = null,
    ): OutgoingRequest {
        return OutgoingRequest::create(
            '/v1/timetable/work-schedules/' . rawurlencode($id) . '/close',
            array_filter(['from_date' => $fromDate, 'replacement' => $replacement?->toArray()], fn ($v) => $v !== null),
            $replacement !== null ? 'location:' . $replacement->locationId : null
        );
    }

    public static function deleteWorkSchedule(string $id): OutgoingRequest
    {
        return OutgoingRequest::delete('/v1/timetable/work-schedules/' . rawurlencode($id));
    }

    public static function calendar(string $locationId, string $from, string $to): OutgoingRequest
    {
        return OutgoingRequest::read(
            '/v1/timetable/calendar',
            ['location_id' => $locationId, 'from' => $from, 'to' => $to]
        );
    }

    /**
     * @param  int  $duration  minutes
     * @param  list<string>  $resourceRefs  the pool of resources, e.g. the cars of the window
     * @param  list<string>  $extraResourceRefs  a second pool, e.g. the instructors: a slot needs one free of each
     */
    public static function slots(
        string $locationId,
        string $date,
        int $duration,
        bool $blocking = true,
        array $resourceRefs = [],
        array $extraResourceRefs = [],
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/timetable/slots',
            [
                'location_id'   => $locationId,
                'date'          => $date,
                'duration'      => $duration,
                'blocking'      => $blocking ? null : 0,
                'resource_refs' => $resourceRefs === [] ? null : $resourceRefs,
                'extra_resource_refs' => $extraResourceRefs === [] ? null : $extraResourceRefs,
            ]
        );
    }

    /**
     * @param  list<string>  $statuses
     */
    public static function listBookings(
        ?string $from = null,
        ?string $to = null,
        ?string $locationId = null,
        array $statuses = [],
        ?ExternalRef $externalRef = null,
        ?string $resourceRef = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/timetable/bookings',
            [
                'from'         => $from,
                'to'           => $to,
                'location_id'  => $locationId,
                'status'       => $statuses === [] ? null : $statuses,
                'external_ref' => $externalRef?->key(),
                'resource_ref' => $resourceRef,
                'per_page'     => $perPage,
                'cursor'       => $cursor,
            ]
        );
    }

    public static function getBooking(string $id): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/timetable/bookings/' . rawurlencode($id));
    }

    /**
     * A confirmed booking. Times ISO 8601 with an offset.
     *
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $extraResourceRefs  more resources it takes besides `$resourceRef`, e.g. an instructor
     */
    public static function book(
        string $locationId,
        string $startsAt,
        string $endsAt,
        string $title,
        ?string $resourceRef = null,
        ?ExternalRef $externalRef = null,
        array $metadata = [],
        bool $blocking = true,
        ?string $description = null,
        array $extraResourceRefs = [],
    ): OutgoingRequest {
        return self::createBooking(
            'confirmed',
            null,
            $locationId,
            $startsAt,
            $endsAt,
            $title,
            $resourceRef,
            $externalRef,
            $metadata,
            $blocking,
            $description,
            $extraResourceRefs
        );
    }

    /**
     * A hold for `$ttl` seconds: the project checks its own conditions, then confirms or cancels it
     * (back-office ROADMAP.md, 4.5).
     *
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $extraResourceRefs  more resources it takes besides `$resourceRef`, e.g. an instructor
     */
    public static function hold(
        string $locationId,
        string $startsAt,
        string $endsAt,
        string $title,
        int $ttl = 300,
        ?string $resourceRef = null,
        ?ExternalRef $externalRef = null,
        array $metadata = [],
        bool $blocking = true,
        ?string $description = null,
        array $extraResourceRefs = [],
    ): OutgoingRequest {
        return self::createBooking(
            'held',
            $ttl,
            $locationId,
            $startsAt,
            $endsAt,
            $title,
            $resourceRef,
            $externalRef,
            $metadata,
            $blocking,
            $description,
            $extraResourceRefs
        );
    }

    public static function confirm(string $id, ?int $version = null): OutgoingRequest
    {
        return self::transition($id, 'confirm', ['version' => $version]);
    }

    public static function complete(
        string $id,
        ?string $outcomeCode = null,
        ?string $actualEndsAt = null,
        ?int $version = null,
    ): OutgoingRequest {
        return self::transition(
            $id,
            'complete',
            ['outcome_code' => $outcomeCode, 'actual_ends_at' => $actualEndsAt, 'version' => $version]
        );
    }

    public static function cancel(string $id, ?string $outcomeCode = null, ?int $version = null): OutgoingRequest
    {
        return self::transition($id, 'cancel', ['outcome_code' => $outcomeCode, 'version' => $version]);
    }

    public static function noShow(string $id, ?int $version = null): OutgoingRequest
    {
        return self::transition($id, 'no-show', ['version' => $version]);
    }

    /**
     * @param  int  $version  the version of the booking the project saw
     * @param  string|false|null  $resourceRef  false — the booking keeps its resource, null — frees it
     * @param  list<string>|null  $extraResourceRefs  null — the booking keeps its extra resources; a list replaces them
     */
    public static function reschedule(
        string $id,
        string $startsAt,
        string $endsAt,
        int $version,
        string|false|null $resourceRef = false,
        ?array $extraResourceRefs = null,
    ): OutgoingRequest {
        $body = ['starts_at' => $startsAt, 'ends_at' => $endsAt, 'version' => $version];

        if ($resourceRef !== false) {
            $body['resource_ref'] = $resourceRef;
        }

        if ($extraResourceRefs !== null) {
            $body['extra_resource_refs'] = array_values($extraResourceRefs);
        }

        return new OutgoingRequest(
            'POST',
            '/v1/timetable/bookings/' . rawurlencode($id) . '/reschedule',
            [],
            $body,
            null,
            null,
            null,
            'booking:' . $id
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $extraResourceRefs
     */
    private static function createBooking(
        string $status,
        ?int $ttl,
        string $locationId,
        string $startsAt,
        string $endsAt,
        string $title,
        ?string $resourceRef,
        ?ExternalRef $externalRef,
        array $metadata,
        bool $blocking,
        ?string $description,
        array $extraResourceRefs,
    ): OutgoingRequest {
        return OutgoingRequest::create(
            '/v1/timetable/bookings',
            array_filter(
                [
                    'location_id'  => $locationId,
                    'starts_at'    => $startsAt,
                    'ends_at'      => $endsAt,
                    'status'       => $status,
                    'ttl'          => $ttl,
                    'title'        => $title,
                    'description'  => $description,
                    'blocking'     => $blocking ? null : false,
                    'resource_ref' => $resourceRef,
                    'extra_resource_refs' => $extraResourceRefs === [] ? null : array_values($extraResourceRefs),
                    'external_ref' => $externalRef?->toArray(),
                    'metadata'     => $metadata === [] ? null : $metadata,
                ],
                fn (mixed $value): bool => $value !== null
            )
        );
    }

    /**
     * Transitions are idempotent by themselves: the same one again returns the booking as it is.
     *
     * @param  array<string, mixed>  $body
     */
    private static function transition(string $id, string $action, array $body): OutgoingRequest
    {
        return new OutgoingRequest(
            'POST',
            '/v1/timetable/bookings/' . rawurlencode($id) . '/' . $action,
            [],
            array_filter($body, fn (mixed $value): bool => $value !== null),
            null,
            null,
            null,
            'booking:' . $id
        );
    }
}
