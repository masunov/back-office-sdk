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

    private static function stream(?string $sourceType, ?string $sourceId): ?string
    {
        return $sourceType !== null && $sourceId !== null ? 'source:' . $sourceType . ':' . $sourceId : null;
    }
}
