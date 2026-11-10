<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Unit;

use Mnd\BackOffice\BackOfficeClient;
use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Dto\FinancePayment;
use Mnd\BackOffice\Exceptions\ApiErrorException;
use Mnd\BackOffice\Http\Transport;
use Mnd\BackOffice\Requests\FinanceRequests;
use PHPUnit\Framework\TestCase;

class PaymentTest extends TestCase
{
    use MocksHttp;

    private const PAYMENT = [
        'id'            => '01a14100-0000-7000-8000-0000000000e1',
        'obligation_id' => '01a14100-0000-7000-8000-0000000000d1',
        'direction'     => 'expense',
        'kind'          => 'payment',
        'method'        => 'money',
        'amount'        => '10000.00',
        'paid_on'       => '2026-11-08',
        'source_type'   => 'trip_payment',
        'source_id'     => 'p-1',
        'reversal_of'   => null,
        'comment'       => null,
        'actor'         => ['id' => 'u-1', 'name' => 'Финансист'],
        'created_at'    => '2026-11-08T12:00:00+00:00',
    ];

    public function testPaymentAndItsReversalGoInTheStreamOfItsSource(): void
    {
        $payment = FinanceRequests::createPayment('trip_obligation:ob-1', '10000.00', 'trip_payment', 'p-1', method: 'barter');
        $reverse = FinanceRequests::reversePayment('trip_payment:p-1');

        $this->assertSame('POST', $payment->method);
        $this->assertSame('/v1/finance/payments', $payment->path);
        $this->assertSame(
            ['obligation' => 'trip_obligation:ob-1', 'kind' => 'payment', 'method' => 'barter', 'amount' => '10000.00', 'source_type' => 'trip_payment', 'source_id' => 'p-1'],
            $payment->body
        );
        $this->assertNotNull($payment->idempotencyKey);
        $this->assertSame('source:trip_payment:p-1', $payment->stream);
        $this->assertSame($payment->stream, $reverse->stream);
        $this->assertSame('/v1/finance/payments/trip_payment%3Ap-1/reverse', $reverse->path);
        $this->assertNull(FinanceRequests::reversePayment(self::PAYMENT['id'])->stream);
    }

    public function testPaymentNotThereYetIsRetriedAndTooMuchIsNot(): void
    {
        $this->assertTrue((new ApiErrorException(409, 'payment_not_found', 'x'))->isRetriable());
        $this->assertFalse((new ApiErrorException(404, 'payment_not_found', 'x'))->isRetriable());
        $this->assertFalse((new ApiErrorException(409, 'payment_exceeds_obligation', 'x'))->isRetriable());
    }

    public function testPaymentsAreWrittenAndRead(): void
    {
        $finance = $this->client()->forTenant('org-1')->finance();
        $this->mock->append(
            self::jsonResponse(201, ['data' => self::PAYMENT + ['obligation' => ['id' => 'x', 'payment_status' => 'partially_paid', 'totals' => ['remaining' => '14000.00']]]]),
            self::jsonResponse(200, ['data' => [self::PAYMENT], 'meta' => ['next_cursor' => 'c2']])
        );

        $payment = $finance->createPayment('trip_obligation:ob-1', '10000.00', 'trip_payment', 'p-1', '2026-11-08');
        $this->assertSame(['14000.00', 'partially_paid'], [$payment->obligationTotals['remaining'], $payment->obligationPaymentStatus]);
        $this->assertSame('2026-11-08', json_decode((string) $this->sentRequest()->getBody(), true)['paid_on']);

        [$page, $next] = $finance->payments(obligation: 'trip_obligation:ob-1', ref: new ExternalRef('trip', '42'), from: '2026-11-01');
        $this->assertSame('obligation=trip_obligation%3Aob-1&ref=trip%3A42&from=2026-11-01', $this->sentRequest(1)->getUri()->getQuery());
        $this->assertSame(['money', 'c2', []], [$page[0]->method, $next, $page[0]->obligationTotals]);
    }

    public function testPaymentWithAnAccountCarriesItsMoney(): void
    {
        $request = FinanceRequests::createPayment('rent_receivable:r-1', '9300.00', 'rent_receipt', 'p-1', account: 'acc-usd', accountAmount: '100.00');
        $this->assertSame(['acc-usd', '100.00'], [$request->body['account'], $request->body['account_amount']]);
        $this->assertArrayNotHasKey('account', FinanceRequests::createPayment('rent_receivable:r-1', '1.00', 'rent_receipt', 'p-2')->body);

        $finance = $this->client()->forTenant('org-1')->finance();
        $this->mock->append(
            self::jsonResponse(
                201,
                [
                    'data' => ['direction' => 'income'] + self::PAYMENT + [
                        'movement' => [
                            'id'          => 'm-1',
                            'kind'        => 'top_up',
                            'moved_on'    => '2026-11-08',
                            'basis'       => 'income',
                            'source_type' => 'finance_payment',
                            'source_id'   => self::PAYMENT['id'],
                            'payment_id'  => self::PAYMENT['id'],
                            'lines'       => [['account_id' => 'acc-usd', 'currency' => 'USD', 'role' => 'credit', 'amount' => '100.00', 'base_amount' => '9300.00', 'rate' => '93.000000000000', 'balance_after' => '100.00', 'base_cost_after' => '9300.00']],
                            'actor'       => ['id' => 'u-1', 'name' => 'Финансист'],
                            'created_at'  => '2026-11-08T12:00:00+00:00',
                        ],
                    ],
                ]
            ),
        );

        $payment = $finance->createPayment('rent_receivable:r-1', '9300.00', 'rent_receipt', 'p-1', account: 'acc-usd', accountAmount: '100.00');
        $this->assertSame(['top_up', '100.00'], [$payment->movement?->kind, $payment->movement?->lines[0]->amount]);
        $this->assertSame('acc-usd', json_decode((string) $this->sentRequest()->getBody(), true)['account']);
        $this->assertNull(FinancePayment::fromArray(self::PAYMENT)->movement);
    }

    public function testCalendarOfATrip(): void
    {
        $finance = $this->client()->forTenant('org-1')->finance();
        $data    = [
            'lines'  => [['source_id' => 'ob-1', 'key' => 'checkin', 'bucket' => 'in_period', 'remaining' => '12000.00']],
            'totals' => ['expense' => ['overdue' => '0.00', 'in_period' => '12000.00', 'undated' => '0.00', 'paid_in_period' => '12000.00']],
        ];
        $this->mock->append(self::jsonResponse(200, ['data' => $data]));

        $calendar = $finance->paymentCalendar('2026-11-01', '2026-11-30', ref: new ExternalRef('trip', '42'), includePlanned: true);

        $this->assertSame('from=2026-11-01&to=2026-11-30&ref=trip%3A42&include_planned=1', $this->sentRequest()->getUri()->getQuery());
        $this->assertSame(['12000.00', '0.00'], [$calendar->total('expense', 'in_period'), $calendar->total('income', 'overdue')]);
        $this->assertSame('checkin', $calendar->lines[0]['key']);
    }

    private function client(): BackOfficeClient
    {
        return new BackOfficeClient(new Transport($this->mockHttp(), 'https://bo.test', 'bo_key', null, []));
    }
}
