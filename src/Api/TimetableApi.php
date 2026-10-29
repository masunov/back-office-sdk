<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Api;

use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Dto\Booking;
use Mnd\BackOffice\Dto\BookingPage;
use Mnd\BackOffice\Dto\CalendarDay;
use Mnd\BackOffice\Dto\CloseResult;
use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Dto\LocationCalendar;
use Mnd\BackOffice\Dto\Slot;
use Mnd\BackOffice\Dto\WorkSchedule;
use Mnd\BackOffice\Dto\WorkScheduleInput;
use Mnd\BackOffice\Http\OutgoingRequest;
use Mnd\BackOffice\Http\Transport;
use Mnd\BackOffice\Requests\TimetableRequests;

/**
 * The module timetable, sent at once. A booking with a check of the project's own conditions goes
 * `hold()` → the project's transaction → `confirm()` or `cancel()`; the confirm and the cancel may go through the
 * outbox (`BackOffice::outbox()->push(TimetableRequests::confirm($id), …)`).
 */
final class TimetableApi
{
    public function __construct(
        private readonly Transport $transport,
        private readonly ?string $tenant,
        private readonly ?Actor $actor,
    ) {
    }

    /**
     * @return list<WorkSchedule>
     */
    public function workSchedules(?string $locationId = null, ?string $type = null, ?string $activeOn = null): array
    {
        return array_map(
            fn (array $item): WorkSchedule => WorkSchedule::fromArray($item),
            $this->send(TimetableRequests::listWorkSchedules($locationId, $type, $activeOn))['data']
        );
    }

    /**
     * @return array{0: WorkSchedule, 1: list<string>} the rule and the active bookings it covers (with `force`)
     */
    public function createWorkSchedule(WorkScheduleInput $rule, bool $force = false): array
    {
        return $this->rule(TimetableRequests::createWorkSchedule($rule, $force));
    }

    /**
     * @return array{0: WorkSchedule, 1: list<string>}
     */
    public function updateWorkSchedule(string $id, WorkScheduleInput $rule, bool $force = false): array
    {
        return $this->rule(TimetableRequests::updateWorkSchedule($id, $rule, $force));
    }

    public function closeWorkSchedule(string $id, string $fromDate, ?WorkScheduleInput $replacement = null): CloseResult
    {
        return CloseResult::fromArray($this->send(TimetableRequests::closeWorkSchedule($id, $fromDate, $replacement))['data']);
    }

    public function deleteWorkSchedule(string $id): void
    {
        $this->send(TimetableRequests::deleteWorkSchedule($id));
    }

    /**
     * @return list<CalendarDay>
     */
    public function calendar(string $locationId, string $from, string $to): array
    {
        return array_map(
            fn (array $day): CalendarDay => CalendarDay::fromArray($day),
            $this->send(TimetableRequests::calendar($locationId, $from, $to))['data']['days']
        );
    }

    /**
     * The calendars of several locations in one request, in the order given.
     *
     * @param  list<string>  $locationIds
     * @return list<LocationCalendar>
     */
    public function calendars(array $locationIds, string $from, string $to): array
    {
        return array_map(
            fn (array $calendar): LocationCalendar => LocationCalendar::fromArray($calendar),
            $this->send(TimetableRequests::calendars($locationIds, $from, $to))['data']
        );
    }

    /**
     * @param  list<string>  $resourceRefs
     * @param  list<string>  $extraResourceRefs  a second pool, e.g. the instructors
     * @return list<Slot>
     */
    public function slots(
        string $locationId,
        string $date,
        int $duration,
        bool $blocking = true,
        array $resourceRefs = [],
        array $extraResourceRefs = [],
    ): array {
        return array_map(
            fn (array $slot): Slot => Slot::fromArray($slot),
            $this->send(
                TimetableRequests::slots($locationId, $date, $duration, $blocking, $resourceRefs, $extraResourceRefs)
            )['data']
        );
    }

    /**
     * @param  list<string>  $statuses
     */
    public function bookings(
        ?string $from = null,
        ?string $to = null,
        ?string $locationId = null,
        array $statuses = [],
        ?ExternalRef $externalRef = null,
        ?string $resourceRef = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): BookingPage {
        return BookingPage::fromArray(
            $this->send(
                TimetableRequests::listBookings(
                    $from,
                    $to,
                    $locationId,
                    $statuses,
                    $externalRef,
                    $resourceRef,
                    $perPage,
                    $cursor
                )
            )
        );
    }

    public function booking(string $id): Booking
    {
        return $this->bookingOf(TimetableRequests::getBooking($id));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $extraResourceRefs
     */
    public function book(
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
    ): Booking {
        return $this->bookingOf(
            TimetableRequests::book(
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
            )
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $extraResourceRefs
     */
    public function hold(
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
    ): Booking {
        return $this->bookingOf(
            TimetableRequests::hold(
                $locationId,
                $startsAt,
                $endsAt,
                $title,
                $ttl,
                $resourceRef,
                $externalRef,
                $metadata,
                $blocking,
                $description,
                $extraResourceRefs
            )
        );
    }

    public function confirm(string $id, ?int $version = null): Booking
    {
        return $this->bookingOf(TimetableRequests::confirm($id, $version));
    }

    public function complete(
        string $id,
        ?string $outcomeCode = null,
        ?string $actualEndsAt = null,
        ?int $version = null,
    ): Booking {
        return $this->bookingOf(TimetableRequests::complete($id, $outcomeCode, $actualEndsAt, $version));
    }

    public function cancel(string $id, ?string $outcomeCode = null, ?int $version = null): Booking
    {
        return $this->bookingOf(TimetableRequests::cancel($id, $outcomeCode, $version));
    }

    public function noShow(string $id, ?int $version = null): Booking
    {
        return $this->bookingOf(TimetableRequests::noShow($id, $version));
    }

    /**
     * @param  string|false|null  $resourceRef  false — the booking keeps its resource, null — frees it
     * @param  list<string>|null  $extraResourceRefs  null — the booking keeps its extra resources
     */
    public function reschedule(
        string $id,
        string $startsAt,
        string $endsAt,
        int $version,
        string|false|null $resourceRef = false,
        ?array $extraResourceRefs = null,
    ): Booking {
        return $this->bookingOf(
            TimetableRequests::reschedule($id, $startsAt, $endsAt, $version, $resourceRef, $extraResourceRefs)
        );
    }

    /**
     * @return array{0: WorkSchedule, 1: list<string>}
     */
    private function rule(OutgoingRequest $request): array
    {
        $data = $this->send($request);

        return [WorkSchedule::fromArray($data['data']), array_values($data['covered_bookings'] ?? [])];
    }

    private function bookingOf(OutgoingRequest $request): Booking
    {
        return Booking::fromArray($this->send($request)['data']);
    }

    /**
     * @return array<string, mixed>
     */
    private function send(OutgoingRequest $request): array
    {
        return $this->transport->send($request->forTenant($this->tenant)->actingAs($this->actor));
    }
}
