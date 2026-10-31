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
     * An obligation addressed by its source goes in the stream of its creation; by id — it is there already.
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
