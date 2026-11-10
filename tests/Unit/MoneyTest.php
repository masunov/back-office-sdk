<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Unit;

use Mnd\BackOffice\BackOfficeClient;
use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Exceptions\ApiErrorException;
use Mnd\BackOffice\Http\Transport;
use Mnd\BackOffice\Requests\FinanceRequests;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    use MocksHttp;

    private const USD = '01a14100-0000-7000-8000-0000000000a2';
    private const RUB = '01a14100-0000-7000-8000-0000000000a1';

    private const ACCOUNT = [
        'id'           => self::USD,
        'name'         => 'Доллары',
        'kind'         => 'cash',
        'currency'     => 'USD',
        'balance'      => '170.00',
        'base_cost'    => '15810.00',
        'average_rate' => '93.000000000000',
        'comment'      => null,
        'archived'     => false,
        'archived_at'  => null,
        'updated_at'   => '2026-11-10T12:00:00+00:00',
    ];

    private const MOVEMENT = [
        'id'             => '01a14100-0000-7000-8000-0000000000f1',
        'kind'           => 'withdrawal',
        'moved_on'       => '2026-11-10',
        'basis'          => 'expense',
        'source_type'    => 'trip_cost_entry',
        'source_id'      => '41',
        'operation_id'   => null,
        'payment_id'     => null,
        'ref'            => ['type' => 'trip', 'id' => '42'],
        'effective_rate' => null,
        'reason'         => null,
        'comment'        => null,
        'reversal_of'    => null,
        'lines'          => [
            [
                'account_id'      => self::USD,
                'currency'        => 'USD',
                'role'            => 'debit',
                'amount'          => '30.00',
                'base_amount'     => '2790.00',
                'rate'            => '93.000000000000',
                'balance_after'   => '170.00',
                'base_cost_after' => '15810.00',
            ],
        ],
        'accounts'       => [self::ACCOUNT],
        'actor'          => ['id' => 'u-1', 'name' => 'Финансист'],
        'created_at'     => '2026-11-10T12:00:00+00:00',
    ];

    public function testMovementsGoInTheStreamOfTheirSource(): void
    {
        $withdraw = FinanceRequests::withdraw(self::USD, '30.00', 'expense', 'trip_cost_entry', '41', ref: new ExternalRef('trip', '42'));
        $exchange = FinanceRequests::exchange(self::RUB, '9000.00', self::USD, '100.00', 'trip_exchange', '7', fee: '100.00');
        $reverse  = FinanceRequests::reverseMoneyMovement('trip_cost_entry:41');

        $this->assertSame(['POST', '/v1/finance/money-movements'], [$withdraw->method, $withdraw->path]);
        $this->assertSame(
            ['kind' => 'withdrawal', 'source_type' => 'trip_cost_entry', 'source_id' => '41', 'account' => self::USD, 'amount' => '30.00', 'basis' => 'expense', 'ref' => 'trip:42'],
            $withdraw->body
        );
        $this->assertSame(['exchange', '100.00', self::USD], [$exchange->body['kind'], $exchange->body['fee'], $exchange->body['to_account']]);
        $this->assertNotNull($withdraw->idempotencyKey);
        $this->assertSame('source:trip_cost_entry:41', $withdraw->stream);
        $this->assertSame($withdraw->stream, $reverse->stream);
        $this->assertSame('/v1/finance/money-movements/trip_cost_entry%3A41/reverse', $reverse->path);
        $this->assertSame(['adjustment', '-5.00', 'Пересчёт'], array_values(array_intersect_key(FinanceRequests::adjust(self::RUB, '-5.00', 'Пересчёт', 'cash_count', '1')->body, array_flip(['kind', 'delta', 'reason']))));
        $this->assertSame('PATCH', FinanceRequests::updateAccount(self::USD, ['comment' => null])->method);
        $this->assertSame(['comment' => null], FinanceRequests::updateAccount(self::USD, ['comment' => null])->body);
    }

    public function testMovementNotThereYetIsRetriedAndNoMoneyIsNot(): void
    {
        $this->assertTrue((new ApiErrorException(409, 'money_movement_not_found', 'x'))->isRetriable());
        $this->assertFalse((new ApiErrorException(404, 'money_movement_not_found', 'x'))->isRetriable());
        $this->assertFalse((new ApiErrorException(409, 'insufficient_funds', 'x'))->isRetriable());
    }

    public function testMoneyIsMovedAndRead(): void
    {
        $finance = $this->client()->forTenant('org-1')->finance();
        $this->mock->append(
            self::jsonResponse(201, ['data' => self::MOVEMENT]),
            self::jsonResponse(200, ['data' => [self::ACCOUNT]]),
            self::jsonResponse(200, ['data' => [['accounts' => []] + self::MOVEMENT], 'meta' => ['next_cursor' => null]])
        );

        $movement = $finance->moveMoney(FinanceRequests::withdraw(self::USD, '30.00', 'expense', 'trip_cost_entry', '41'));
        $this->assertSame(['93.000000000000', '2790.00'], [$movement->debit()?->rate, $movement->debit(self::USD)?->baseAmount]);
        $this->assertSame('15810.00', $movement->account(self::USD)?->baseCost);
        $this->assertSame('trip:42', $movement->ref?->key());

        $accounts = $finance->accounts(archived: true);
        $this->assertSame('archived=1', $this->sentRequest(1)->getUri()->getQuery());
        $this->assertSame(['USD', '93.000000000000'], [$accounts[0]->currency, $accounts[0]->averageRate]);

        [$page, $next] = $finance->moneyMovements(account: self::USD, ref: new ExternalRef('trip', '42'));
        $this->assertSame('account=' . self::USD . '&ref=trip%3A42', $this->sentRequest(2)->getUri()->getQuery());
        $this->assertSame([[], null], [$page[0]->accounts, $next]);
    }

    private function client(): BackOfficeClient
    {
        return new BackOfficeClient(new Transport($this->mockHttp(), 'https://bo.test', 'bo_key', null, []));
    }
}
