<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Requests;

use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Http\OutgoingRequest;

/**
 * Requests of the module finance (docs/openapi.yaml, /v1/finance). The writes a project makes from its own events
 * carry a source and are ordered by it in the outbox: the stream of an operation is `<source_type>:<source_id>`,
 * so its reversal by source never overtakes it.
 */
final class FinanceRequests
{
    /**
     * @param  string  $amount  positive, with a dot: `1500.00`
     * @param  string  $operationDate  `Y-m-d`
     * @param  string  $kind  `regular`, or `correction` for a closed ref
     * @param  string|null  $obligation  the obligation it recognizes: an id or `<source_type>:<source_id>`; needs the
     *                                   source. Not there yet — `409 obligation_not_found`, retried
     */
    public static function createOperation(
        string $category,
        string $amount,
        string $operationDate,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?ExternalRef $externalRef = null,
        ?string $counterparty = null,
        ?ExternalRef $counterpartyRef = null,
        ?string $comment = null,
        string $kind = 'regular',
        ?string $obligation = null,
    ): OutgoingRequest {
        return OutgoingRequest::create(
            '/v1/finance/operations',
            array_filter(
                [
                    'category'         => $category,
                    'amount'           => $amount,
                    'operation_date'   => $operationDate,
                    'kind'             => $kind,
                    'source_type'      => $sourceType,
                    'source_id'        => $sourceId,
                    'external_ref'     => $externalRef?->toArray(),
                    'counterparty'     => $counterparty,
                    'counterparty_ref' => $counterpartyRef?->toArray(),
                    'comment'          => $comment,
                    'obligation'       => $obligation,
                ],
                fn (mixed $value): bool => $value !== null
            ),
            self::stream($sourceType, $sourceId)
        );
    }

    /**
     * The reversal of an operation the project knows by id.
     */
    public static function reverse(string $operationId, ?string $operationDate = null, ?string $comment = null): OutgoingRequest
    {
        return OutgoingRequest::create(
            '/v1/finance/operations/' . rawurlencode($operationId) . '/reverse',
            array_filter(['operation_date' => $operationDate, 'comment' => $comment], fn (?string $v): bool => $v !== null),
            'operation:' . $operationId
        );
    }

    /**
     * The reversal of an operation the project knows by its source, e.g. an unmarked payment of an entry fee.
     */
    public static function reverseBySource(
        string $sourceType,
        string $sourceId,
        ?string $operationDate = null,
        ?string $comment = null,
    ): OutgoingRequest {
        return OutgoingRequest::create(
            '/v1/finance/operations/reverse',
            array_filter(
                [
                    'source_type'    => $sourceType,
                    'source_id'      => $sourceId,
                    'operation_date' => $operationDate,
                    'comment'        => $comment,
                ],
                fn (?string $value): bool => $value !== null
            ),
            self::stream($sourceType, $sourceId)
        );
    }

    public static function getOperation(string $operationId): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/finance/operations/' . rawurlencode($operationId));
    }

    public static function listOperations(
        ?ExternalRef $ref = null,
        ?string $from = null,
        ?string $to = null,
        ?string $category = null,
        ?string $direction = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/finance/operations',
            [
                'ref'       => $ref?->key(),
                'from'      => $from,
                'to'        => $to,
                'category'  => $category,
                'direction' => $direction,
                'per_page'  => $perPage,
                'cursor'    => $cursor,
            ]
        );
    }

    public static function listCategories(): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/finance/categories');
    }

    /**
     * @param  string  $level  `project` — the project's set (send without a tenant), `tenant` — a subcategory
     */
    public static function putCategory(
        string $code,
        string $name,
        string $direction,
        string $level = 'project',
        ?string $parent = null,
        bool $archived = false,
    ): OutgoingRequest {
        return OutgoingRequest::put(
            '/v1/finance/categories/' . rawurlencode($code),
            ['level' => $level, 'name' => $name, 'direction' => $direction, 'parent' => $parent, 'archived' => $archived],
            'category:' . $code
        );
    }

    public static function deleteCategory(string $code, string $level = 'project'): OutgoingRequest
    {
        return OutgoingRequest::delete('/v1/finance/categories/' . rawurlencode($code), ['level' => $level]);
    }

    public static function getBudget(ExternalRef $ref): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/finance/budgets/' . $ref->key());
    }

    /**
     * The whole plan of the ref: a category missing here leaves the plan.
     *
     * @param  array<string, string>  $amounts  category code => amount
     */
    public static function putBudget(ExternalRef $ref, array $amounts): OutgoingRequest
    {
        $items = [];

        foreach ($amounts as $category => $amount) {
            $items[] = ['category' => (string) $category, 'amount' => $amount];
        }

        return OutgoingRequest::put(
            '/v1/finance/budgets/' . $ref->key(),
            ['label' => $ref->label, 'items' => $items],
            'budget:' . $ref->key()
        );
    }

    public static function lock(ExternalRef $ref, bool $locked = true): OutgoingRequest
    {
        return OutgoingRequest::put(
            '/v1/finance/locks/' . $ref->key(),
            ['locked' => $locked, 'label' => $ref->label],
            'lock:' . $ref->key()
        );
    }

    /**
     * @param  list<ExternalRef>  $refs  the whole hierarchy: a championship is its stages plus itself
     */
    public static function summary(array $refs, ?string $from = null, ?string $to = null): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/finance/reports/summary', self::reportQuery($refs, $from, $to));
    }

    /**
     * @param  list<ExternalRef>  $refs
     */
    public static function export(array $refs, ?string $from = null, ?string $to = null): OutgoingRequest
    {
        return OutgoingRequest::read(
            '/v1/finance/reports/export',
            self::reportQuery($refs, $from, $to) + ['format' => 'xlsx']
        );
    }

    /**
     * @param  list<ExternalRef>  $refs
     * @return array<string, mixed>
     */
    private static function reportQuery(array $refs, ?string $from, ?string $to): array
    {
        return [
            'refs' => array_map(fn (ExternalRef $ref): string => $ref->key(), $refs),
            'from' => $from,
            'to'   => $to,
        ];
    }

    /**
     * A new obligation (docs/FINANCE_OBLIGATIONS.md): the direction is the category's; without a schedule — one line
     * without a due date. The source is required and has no slash: later requests address the obligation by
     * `<sourceType>:<sourceId>`, in the same stream, so they never overtake it.
     *
     * @param  list<array{key: string, amount: string, due_on?: string|null, label?: string|null, external_ref?: array{type: string, id: string, label?: string|null}|null}>  $schedule
     * @param  'planned'|'agreed'  $status
     * @param  list<array{title?: string|null, url: string}>  $documents
     */
    public static function createObligation(
        string $category,
        ExternalRef $counterparty,
        string $amount,
        string $sourceType,
        string $sourceId,
        ?ExternalRef $externalRef = null,
        array $schedule = [],
        ?string $agreedOn = null,
        string $status = 'agreed',
        ?string $comment = null,
        array $documents = [],
    ): OutgoingRequest {
        return OutgoingRequest::create(
            '/v1/finance/obligations',
            array_filter(
                [
                    'category'     => $category,
                    'counterparty' => $counterparty->toArray(),
                    'external_ref' => $externalRef?->toArray(),
                    'amount'       => $amount,
                    'agreed_on'    => $agreedOn,
                    'status'       => $status,
                    'schedule'     => $schedule === [] ? null : $schedule,
                    'source_type'  => $sourceType,
                    'source_id'    => $sourceId,
                    'comment'      => $comment,
                    'documents'    => $documents === [] ? null : $documents,
                ],
                fn (mixed $value): bool => $value !== null
            ),
            self::stream($sourceType, $sourceId)
        );
    }

    /**
     * Fulfilment and description; only what is given changes. `$obligation` — the id or `<source_type>:<source_id>`.
     *
     * @param  'planned'|'agreed'|'partially_fulfilled'|'fulfilled'|'cancelled'|null  $status
     * @param  array{fulfilled_amount?: string|null, counterparty_label?: string|null, comment?: string|null, documents?: list<array{title?: string|null, url: string}>}  $fields
     */
    public static function updateObligation(string $obligation, ?string $status = null, array $fields = []): OutgoingRequest
    {
        return new OutgoingRequest(
            'PATCH',
            '/v1/finance/obligations/' . rawurlencode($obligation),
            [],
            ($status === null ? [] : ['status' => $status]) + $fields,
            null,
            null,
            null,
            self::obligationStream($obligation)
        );
    }

    /**
     * Links the operation of a source to the obligation it recognizes, before the operation too: in the stream of the
     * obligation, so it does not overtake the creation. The usual way is `obligation` of `createOperation()`; this
     * one is for an operation sent before its obligation. Taken by another obligation — `recognition_taken`.
     */
    public static function linkRecognition(string $obligation, string $sourceType, string $sourceId): OutgoingRequest
    {
        return OutgoingRequest::create(
            '/v1/finance/obligations/' . rawurlencode($obligation) . '/recognitions',
            ['source_type' => $sourceType, 'source_id' => $sourceId],
            self::obligationStream($obligation)
        );
    }

    /**
     * The whole schedule, a new amount if it changes too, and why: every moved date goes to the history. A line may
     * have its own object (`external_ref`): a sponsor contract of a championship pays by stages.
     *
     * @param  list<array{key: string, amount: string, due_on?: string|null, label?: string|null, external_ref?: array{type: string, id: string, label?: string|null}|null}>  $lines
     */
    /**
     * A payment (`kind` payment) or a refund of an obligation; the source is required and addresses it later. In the
     * stream of its source, so its reversal does not overtake it. The obligation not there yet —
     * `409 obligation_not_found`, retried; more than is left — `payment_exceeds_obligation`, not retried.
     *
     * @param  string  $obligation  an id or `<source_type>:<source_id>`
     * @param  string  $method  `money`, or `barter` — services or goods at an agreed value
     * @param  string|null  $paidOn  `Y-m-d`; null — today of the tenant
     */
    public static function createPayment(
        string $obligation,
        string $amount,
        string $sourceType,
        string $sourceId,
        ?string $paidOn = null,
        string $kind = 'payment',
        string $method = 'money',
        ?string $comment = null,
    ): OutgoingRequest {
        return OutgoingRequest::create(
            '/v1/finance/payments',
            array_filter(
                [
                    'obligation'  => $obligation,
                    'kind'        => $kind,
                    'method'      => $method,
                    'amount'      => $amount,
                    'paid_on'     => $paidOn,
                    'source_type' => $sourceType,
                    'source_id'   => $sourceId,
                    'comment'     => $comment,
                ],
                fn (?string $value): bool => $value !== null
            ),
            self::stream($sourceType, $sourceId)
        );
    }

    /**
     * The reversal of a payment by its id or `<source_type>:<source_id>`; by the source it goes after the payment.
     */
    public static function reversePayment(string $payment, ?string $paidOn = null, ?string $comment = null): OutgoingRequest
    {
        return OutgoingRequest::create(
            '/v1/finance/payments/' . rawurlencode($payment) . '/reverse',
            array_filter(['paid_on' => $paidOn, 'comment' => $comment], fn (?string $value): bool => $value !== null),
            self::obligationStream($payment)
        );
    }

    public static function listPayments(
        ?string $obligation = null,
        ?ExternalRef $ref = null,
        ?ExternalRef $counterparty = null,
        ?string $from = null,
        ?string $to = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/finance/payments',
            [
                'obligation'   => $obligation,
                'ref'          => $ref?->key(),
                'counterparty' => $counterparty?->key(),
                'from'         => $from,
                'to'           => $to,
                'per_page'     => $perPage,
                'cursor'       => $cursor,
            ]
        );
    }

    /**
     * @param  string  $from  `Y-m-d`, included
     * @param  string  $to  `Y-m-d`, included
     */
    public static function paymentCalendar(
        string $from,
        string $to,
        ?string $direction = null,
        ?ExternalRef $ref = null,
        ?ExternalRef $counterparty = null,
        ?string $category = null,
        bool $includePlanned = false,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/finance/payment-calendar',
            [
                'from'            => $from,
                'to'              => $to,
                'direction'       => $direction,
                'ref'             => $ref?->key(),
                'counterparty'    => $counterparty?->key(),
                'category'        => $category,
                'include_planned' => $includePlanned ? 1 : null,
            ]
        );
    }

    public static function putObligationSchedule(string $obligation, array $lines, string $reason, ?string $amount = null): OutgoingRequest
    {
        return OutgoingRequest::put(
            '/v1/finance/obligations/' . rawurlencode($obligation) . '/schedule',
            array_filter(['amount' => $amount, 'lines' => $lines, 'reason' => $reason], fn (mixed $value): bool => $value !== null),
            self::obligationStream($obligation)
        );
    }

    public static function getObligation(string $obligation): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/finance/obligations/' . rawurlencode($obligation));
    }

    /**
     * @param  'planned'|'agreed'|'partially_fulfilled'|'fulfilled'|'cancelled'|null  $status
     * @param  'unpaid'|'partially_paid'|'paid'|null  $paymentStatus
     */
    public static function listObligations(
        ?ExternalRef $ref = null,
        ?ExternalRef $counterparty = null,
        ?string $category = null,
        ?string $status = null,
        ?string $paymentStatus = null,
        ?bool $overdue = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/finance/obligations',
            [
                'ref'            => $ref?->key(),
                'counterparty'   => $counterparty?->key(),
                'category'       => $category,
                'status'         => $status,
                'payment_status' => $paymentStatus,
                'overdue'        => $overdue === null ? null : (int) $overdue,
                'per_page'       => $perPage,
                'cursor'         => $cursor,
            ]
        );
    }

    public static function obligationScheduleHistory(string $obligation): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/finance/obligations/' . rawurlencode($obligation) . '/schedule/history');
    }

    /**
     * The money accounts of the tenant (BO-FIN-007); with archived ones on request.
     */
    public static function listAccounts(bool $archived = false): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/finance/accounts', ['archived' => $archived ? 1 : null]);
    }

    public static function getAccount(string $id): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/finance/accounts/' . rawurlencode($id));
    }

    /**
     * An empty account; its currency never changes.
     *
     * @param  'cash'|'card'|'bank'|'ewallet'|'other'  $kind
     */
    public static function createAccount(string $name, string $kind, string $currency, ?string $comment = null): OutgoingRequest
    {
        return OutgoingRequest::create(
            '/v1/finance/accounts',
            array_filter(['name' => $name, 'kind' => $kind, 'currency' => $currency, 'comment' => $comment], fn (?string $value): bool => $value !== null)
        );
    }

    /**
     * @param  array{name?: string, kind?: string, comment?: string|null}  $fields  a comment given as null clears it
     */
    public static function updateAccount(string $id, array $fields): OutgoingRequest
    {
        return OutgoingRequest::patch('/v1/finance/accounts/' . rawurlencode($id), $fields);
    }

    /**
     * Only an empty account goes to the archive — `409 account_not_empty`; a repeat changes nothing.
     */
    public static function archiveAccount(string $id, bool $archived = true): OutgoingRequest
    {
        return OutgoingRequest::create('/v1/finance/accounts/' . rawurlencode($id) . ($archived ? '/archive' : '/unarchive'), []);
    }

    /**
     * Money came to an account. For an account not in the currency of the tenant `baseAmount` — what it cost in that
     * currency — is required (`422 valuation_required`).
     *
     * @param  'owner_contribution'|'accountable_return'|'income'|'advance'|'deposit'|'financing'|'other'  $basis
     */
    public static function topUp(
        string $account,
        string $amount,
        string $basis,
        string $sourceType,
        string $sourceId,
        ?string $baseAmount = null,
        ?string $movedOn = null,
        ?string $payment = null,
        ?ExternalRef $ref = null,
        ?string $comment = null,
    ): OutgoingRequest {
        return self::moneyMovement('top_up', $sourceType, $sourceId, ['account' => $account, 'amount' => $amount, 'basis' => $basis, 'base_amount' => $baseAmount, 'payment' => $payment], $movedOn, $ref, $comment);
    }

    /**
     * Money left an account, valued at its average rate: the debit line of the answer has `rate` — what the money
     * spent cost. More than there is — `409 insufficient_funds`, not retried. An operation or a payment by its source
     * not there yet — `409 operation_not_found` / `payment_not_found`, retried.
     *
     * @param  'expense'|'payment'|'accountable'|'owner_withdrawal'|'other'  $basis
     * @param  string|null  $operation  an id or `<source_type>:<source_id>`
     * @param  string|null  $payment  an id or `<source_type>:<source_id>`
     */
    public static function withdraw(
        string $account,
        string $amount,
        string $basis,
        string $sourceType,
        string $sourceId,
        ?string $movedOn = null,
        ?string $operation = null,
        ?string $payment = null,
        ?ExternalRef $ref = null,
        ?string $comment = null,
    ): OutgoingRequest {
        return self::moneyMovement('withdrawal', $sourceType, $sourceId, ['account' => $account, 'amount' => $amount, 'basis' => $basis, 'operation' => $operation, 'payment' => $payment], $movedOn, $ref, $comment);
    }

    /**
     * Between accounts of one currency; the cost goes with the money. Different currencies — `currency_mismatch`.
     */
    public static function transfer(
        string $fromAccount,
        string $toAccount,
        string $amount,
        string $sourceType,
        string $sourceId,
        ?string $movedOn = null,
        ?ExternalRef $ref = null,
        ?string $comment = null,
    ): OutgoingRequest {
        return self::moneyMovement('transfer', $sourceType, $sourceId, ['from_account' => $fromAccount, 'to_account' => $toAccount, 'amount' => $amount], $movedOn, $ref, $comment);
    }

    /**
     * One currency for another: `fromAmount` and `fee` leave `fromAccount`, `toAmount` comes to `toAccount`. The fee is
     * part of the price of the currency bought; `effectiveRate` of the answer is how much was given for one unit.
     */
    public static function exchange(
        string $fromAccount,
        string $fromAmount,
        string $toAccount,
        string $toAmount,
        string $sourceType,
        string $sourceId,
        ?string $fee = null,
        ?string $movedOn = null,
        ?ExternalRef $ref = null,
        ?string $comment = null,
    ): OutgoingRequest {
        return self::moneyMovement('exchange', $sourceType, $sourceId, ['from_account' => $fromAccount, 'from_amount' => $fromAmount, 'fee' => $fee, 'to_account' => $toAccount, 'to_amount' => $toAmount], $movedOn, $ref, $comment);
    }

    /**
     * A count of the money differs from the balance: `delta` with its sign, the reason is required. Up on an empty
     * foreign account needs `baseAmount`.
     */
    public static function adjust(
        string $account,
        string $delta,
        string $reason,
        string $sourceType,
        string $sourceId,
        ?string $baseAmount = null,
        ?string $movedOn = null,
        ?string $comment = null,
    ): OutgoingRequest {
        return self::moneyMovement('adjustment', $sourceType, $sourceId, ['account' => $account, 'delta' => $delta, 'reason' => $reason, 'base_amount' => $baseAmount], $movedOn, null, $comment);
    }

    /**
     * The reversal of a money movement by its id or `<source_type>:<source_id>`; by the source it goes after the
     * movement. The money and its cost come back at the rate of the movement undone.
     */
    public static function reverseMoneyMovement(string $movement, ?string $movedOn = null, ?string $comment = null): OutgoingRequest
    {
        return OutgoingRequest::create(
            '/v1/finance/money-movements/' . rawurlencode($movement) . '/reverse',
            array_filter(['moved_on' => $movedOn, 'comment' => $comment], fn (?string $value): bool => $value !== null),
            self::obligationStream($movement)
        );
    }

    public static function getMoneyMovement(string $movement): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/finance/money-movements/' . rawurlencode($movement));
    }

    /**
     * @param  'top_up'|'withdrawal'|'transfer'|'exchange'|'adjustment'|'reversal'|null  $kind
     */
    public static function listMoneyMovements(
        ?string $account = null,
        ?string $kind = null,
        ?ExternalRef $ref = null,
        ?string $from = null,
        ?string $to = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/finance/money-movements',
            ['account' => $account, 'kind' => $kind, 'ref' => $ref?->key(), 'from' => $from, 'to' => $to, 'per_page' => $perPage, 'cursor' => $cursor]
        );
    }

    /**
     * @param  array<string, string|null>  $fields
     */
    private static function moneyMovement(string $kind, string $sourceType, string $sourceId, array $fields, ?string $movedOn, ?ExternalRef $ref, ?string $comment): OutgoingRequest
    {
        return OutgoingRequest::create(
            '/v1/finance/money-movements',
            array_filter(
                ['kind' => $kind, 'source_type' => $sourceType, 'source_id' => $sourceId, ...$fields, 'moved_on' => $movedOn, 'ref' => $ref?->key(), 'comment' => $comment],
                fn (?string $value): bool => $value !== null
            ),
            self::stream($sourceType, $sourceId)
        );
    }

    /**
     * An obligation, a payment or a money movement addressed by its source goes in the stream of its creation; by id
     * — it is there already.
     */
    private static function obligationStream(string $obligation): ?string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $obligation) === 1) {
            return null;
        }

        [$sourceType, $sourceId] = array_pad(explode(':', $obligation, 2), 2, null);

        return self::stream($sourceType, $sourceId);
    }

    private static function stream(?string $sourceType, ?string $sourceId): ?string
    {
        return $sourceType !== null && $sourceId !== null ? 'source:' . $sourceType . ':' . $sourceId : null;
    }
}
