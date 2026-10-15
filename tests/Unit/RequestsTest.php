<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Unit;

use InvalidArgumentException;
use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Http\OutgoingRequest;
use Mnd\BackOffice\Requests\FinanceRequests;
use PHPUnit\Framework\TestCase;

class RequestsTest extends TestCase
{
    public function testOperationAndItsReversalBySourceShareStream(): void
    {
        $create  = FinanceRequests::createOperation('entry_fee', '100', '2026-10-10', 'stage_entry_fee', '7');
        $reverse = FinanceRequests::reverseBySource('stage_entry_fee', '7');

        $this->assertSame('source:stage_entry_fee:7', $create->stream);
        $this->assertSame($create->stream, $reverse->stream);
        $this->assertNotSame($create->idempotencyKey, $reverse->idempotencyKey);
    }

    public function testRequestSurvivesStorage(): void
    {
        $request = FinanceRequests::createOperation('entry_fee', '100', '2026-10-10')
            ->forTenant('org-1')
            ->actingAs(new Actor('5', 'Иван'));

        $restored = OutgoingRequest::fromArray(json_decode(json_encode($request->toArray()), true));

        $this->assertEquals($request, $restored);
        $this->assertSame($request->idempotencyKey, $restored->idempotencyKey);
    }

    public function testRefsGoToPathAsKey(): void
    {
        $request = FinanceRequests::putBudget(new ExternalRef('stage', '42', 'Этап 3'), ['entry_fee' => '10000']);

        $this->assertSame('/v1/finance/budgets/stage:42', $request->path);
        $this->assertSame(
            ['label' => 'Этап 3', 'items' => [['category' => 'entry_fee', 'amount' => '10000']]],
            $request->body
        );
        $this->assertNull($request->idempotencyKey, 'PUT is idempotent by itself');
    }

    public function testExternalRefRejectsWhatThePathCannotCarry(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExternalRef('stage', '1/2');
    }
}
