<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Unit;

use Mnd\BackOffice\BackOfficeClient;
use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Dto\WorkScheduleInput;
use Mnd\BackOffice\Http\Transport;
use Mnd\BackOffice\Requests\TimetableRequests;
use PHPUnit\Framework\TestCase;

class TimetableTest extends TestCase
{
    use MocksHttp;

    private function client(): BackOfficeClient
    {
        return new BackOfficeClient(new Transport($this->mockHttp(), 'https://bo.test', 'bo_key', null, []));
    }

    /**
     * @return array<string, mixed>
     */
    private static function bookingData(array $overrides = []): array
    {
        return $overrides + [
            'id'               => '01a14000-0000-7000-8000-0000000000b1',
            'work_schedule_id' => '01a14000-0000-7000-8000-0000000000a1',
            'location_id'      => 'track-1',
            'status'           => 'held',
            'outcome_code'     => null,
            'starts_at'        => '2026-11-03T10:00:00+03:00',
            'ends_at'          => '2026-11-03T11:00:00+03:00',
            'actual_ends_at'   => null,
            'duration'         => 60,
            'title'            => 'Иванов',
            'description'      => null,
            'resource_ref'     => 'car-7',
            'external_ref'     => ['type' => 'certificate', 'id' => '123', 'label' => '000123'],
            'metadata'         => ['pilot_id' => 5],
            'blocking'         => true,
            'hold_expires_at'  => '2026-11-02T09:05:00+03:00',
            'created_by'       => ['id' => '1', 'name' => 'Менеджер'],
            'version'          => 1,
        ];
    }

    public function testHoldSendsTtlAndParsesBooking(): void
    {
        $client = $this->client();
        $this->mock->append(self::jsonResponse(201, ['data' => self::bookingData()]));

        $booking = $client->forTenant('school-1')->actingAs(new Actor('1', 'Менеджер'))->timetable()->hold(
            'track-1',
            '2026-11-03T10:00:00+03:00',
            '2026-11-03T11:00:00+03:00',
            'Иванов',
            300,
            'car-7',
            new ExternalRef('certificate', '123', '000123'),
            ['pilot_id' => 5]
        );

        $this->assertSame(
            [
                'location_id'  => 'track-1',
                'starts_at'    => '2026-11-03T10:00:00+03:00',
                'ends_at'      => '2026-11-03T11:00:00+03:00',
                'status'       => 'held',
                'ttl'          => 300,
                'title'        => 'Иванов',
                'resource_ref' => 'car-7',
                'external_ref' => ['type' => 'certificate', 'id' => '123', 'label' => '000123'],
                'metadata'     => ['pilot_id' => 5],
            ],
            json_decode((string) $this->sentRequest()->getBody(), true)
        );
        $this->assertNotSame('', $this->sentRequest()->getHeaderLine('Idempotency-Key'));
        $this->assertTrue($booking->isActive());
        $this->assertSame('certificate', $booking->externalRef?->type);
        $this->assertSame(5, $booking->metadata['pilot_id']);
        $this->assertSame(60, $booking->duration);
    }

    public function testConfirmedBookingHasNoTtlAndNonBlockingSaysSo(): void
    {
        $book = TimetableRequests::book('track-1', '2026-11-03T10:00:00+03:00', '2026-11-03T10:15:00+03:00', 'Такси', blocking: false);

        $this->assertSame('confirmed', $book->body['status']);
        $this->assertArrayNotHasKey('ttl', $book->body);
        $this->assertFalse($book->body['blocking']);
        $this->assertArrayNotHasKey(
            'blocking',
            TimetableRequests::book('track-1', '2026-11-03T10:00:00+03:00', '2026-11-03T11:00:00+03:00', 'A')->body
        );
    }

    public function testChangesOfOneBookingShareStream(): void
    {
        $id = '01a14000-0000-7000-8000-0000000000b1';

        $this->assertSame('booking:' . $id, TimetableRequests::confirm($id)->stream);
        $this->assertSame('booking:' . $id, TimetableRequests::cancel($id, 'refund')->stream);
        $this->assertSame('booking:' . $id, TimetableRequests::complete($id, 'interrupted', '2026-11-03T10:40:00+03:00')->stream);
        $this->assertSame(['outcome_code' => 'refund'], TimetableRequests::cancel($id, 'refund')->body);
        $this->assertNull(TimetableRequests::confirm($id)->idempotencyKey, 'Transitions are idempotent by themselves');
    }

    public function testRescheduleKeepsOrFreesResource(): void
    {
        $id = '01a14000-0000-7000-8000-0000000000b1';

        $this->assertArrayNotHasKey('resource_ref', TimetableRequests::reschedule($id, 'a', 'b', 2)->body);
        $this->assertArrayHasKey('resource_ref', TimetableRequests::reschedule($id, 'a', 'b', 2, null)->body);
        $this->assertNull(TimetableRequests::reschedule($id, 'a', 'b', 2, null)->body['resource_ref']);
        $this->assertSame('car-8', TimetableRequests::reschedule($id, 'a', 'b', 2, 'car-8')->body['resource_ref']);
    }

    public function testSlotsQueryCarriesPoolAndBlocking(): void
    {
        $client = $this->client();
        $this->mock->append(
            self::jsonResponse(
                200,
                ['data' => [[
                'starts_at'          => '2026-11-03T10:00:00+03:00',
                'ends_at'            => '2026-11-03T11:00:00+03:00',
                'work_schedule_id'   => 'r1',
                'free_entries'       => 1,
                'free_resource_refs' => ['car-2'],
                ]]]
            ),
            self::jsonResponse(200, ['data' => []])
        );

        $slots = $client->forTenant('school-1')->timetable()->slots('track-1', '2026-11-03', 60, true, ['car-1', 'car-2']);
        $this->assertSame(
            'location_id=track-1&date=2026-11-03&duration=60&resource_refs%5B%5D=car-1&resource_refs%5B%5D=car-2',
            $this->sentRequest()->getUri()->getQuery()
        );
        $this->assertSame(['car-2'], $slots[0]->freeResourceRefs);

        $client->forTenant('school-1')->timetable()->slots('track-1', '2026-11-03', 15, false);
        $this->assertStringContainsString('blocking=0', $this->sentRequest(1)->getUri()->getQuery());
    }

    public function testCalendarsOfSeveralLocationsComeInOneRequest(): void
    {
        $client = $this->client();
        $day    = ['date' => '2026-11-03', 'working' => [['work_schedule_id' => 'r1', 'starts_at' => '2026-11-03T10:00:00+03:00', 'ends_at' => '2026-11-03T18:00:00+03:00', 'max_entries' => 2]], 'non_working' => [], 'bookings' => []];
        $this->mock->append(
            self::jsonResponse(
                200,
                ['data' => [
                    ['location_id' => 'track-2', 'timezone' => 'Europe/Moscow', 'days' => [$day]],
                    ['location_id' => 'track-1', 'timezone' => 'Europe/Moscow', 'days' => [['date' => '2026-11-03', 'working' => [], 'non_working' => [], 'bookings' => []]]],
                ]]
            )
        );

        $calendars = $client->forTenant('school-1')->timetable()->calendars(['track-2', 'track-1'], '2026-11-03', '2026-11-03');

        $this->assertSame('GET', $this->sentRequest()->getMethod());
        $this->assertSame('/v1/timetable/calendars', $this->sentRequest()->getUri()->getPath());
        $this->assertSame(
            'location_ids%5B%5D=track-2&location_ids%5B%5D=track-1&from=2026-11-03&to=2026-11-03',
            $this->sentRequest()->getUri()->getQuery()
        );
        $this->assertSame(['track-2', 'track-1'], array_map(fn ($calendar): string => $calendar->locationId, $calendars));
        $this->assertSame(2, $calendars[0]->days[0]->working[0]['max_entries']);
        $this->assertSame([], $calendars[1]->days[0]->working);
    }

    public function testExtraResourcesAreSentAndParsed(): void
    {
        $id   = '01a14000-0000-7000-8000-0000000000b1';
        $hold = TimetableRequests::hold('track-1', 'a', 'b', 'A', 300, 'car-1', extraResourceRefs: ['instructor-1']);

        $this->assertSame(['instructor-1'], $hold->body['extra_resource_refs']);
        $this->assertArrayNotHasKey('extra_resource_refs', TimetableRequests::book('track-1', 'a', 'b', 'A')->body);
        $this->assertArrayNotHasKey('extra_resource_refs', TimetableRequests::reschedule($id, 'a', 'b', 2)->body);
        $this->assertSame([], TimetableRequests::reschedule($id, 'a', 'b', 2, false, [])->body['extra_resource_refs']);
        $this->assertSame(
            ['instructor-1'],
            TimetableRequests::slots('track-1', '2026-11-03', 60, true, ['car-1'], ['instructor-1'])->query['extra_resource_refs']
        );

        $client = $this->client();
        $this->mock->append(self::jsonResponse(200, ['data' => self::bookingData(['extra_resource_refs' => ['instructor-1']])]));
        $this->assertSame(['instructor-1'], $client->forTenant('school-1')->timetable()->booking($id)->extraResourceRefs);
    }

    public function testRuleInputOmitsWhatIsNotSet(): void
    {
        $this->assertSame(
            [
                'location_id' => 'track-1',
                'type'        => 'non_working',
                'start_date'  => '2026-11-03',
                'end_date'    => '2026-11-03',
                'start_time'  => '13:00',
                'end_time'    => '14:00',
                'description' => 'Обед',
            ],
            WorkScheduleInput::nonWorking('track-1', '2026-11-03', '13:00', '14:00', '2026-11-03', description: 'Обед')->toArray()
        );

        $close = TimetableRequests::closeWorkSchedule(
            'r1',
            '2026-11-09',
            WorkScheduleInput::working('track-1', '2026-11-09', '09:00', '18:00', 1)
        );
        $this->assertSame('2026-11-09', $close->body['from_date']);
        $this->assertSame(1, $close->body['replacement']['max_entries']);
    }
}
