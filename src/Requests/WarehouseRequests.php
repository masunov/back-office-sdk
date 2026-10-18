<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Requests;

use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Dto\WarehouseItemInput;
use Mnd\BackOffice\Http\OutgoingRequest;

/**
 * Requests of the module warehouse (docs/openapi.yaml, /v1/warehouse). Movements of one item share the stream
 * `warehouse_item:<id>` in the outbox: a return never overtakes its issue, a write-off never overtakes the receipt.
 * Quantities and costs are strings: `2.500`, `250.50`.
 */
final class WarehouseRequests
{
    public static function listItems(
        ?string $search = null,
        ?string $category = null,
        bool $withArchived = false,
        ?int $perPage = null,
        ?string $cursor = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/warehouse/items',
            [
                'search'   => $search,
                'category' => $category,
                'archived' => $withArchived ? 1 : null,
                'per_page' => $perPage,
                'cursor'   => $cursor,
            ]
        );
    }

    public static function getItem(string $id): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/warehouse/items/' . rawurlencode($id));
    }

    public static function createItem(WarehouseItemInput $item): OutgoingRequest
    {
        return OutgoingRequest::create('/v1/warehouse/items', $item->toArray());
    }

    public static function updateItem(string $id, WarehouseItemInput $item, bool $archived = false): OutgoingRequest
    {
        return OutgoingRequest::put(
            '/v1/warehouse/items/' . rawurlencode($id),
            $item->toArray() + ['archived' => $archived],
            'warehouse_item:' . $id
        );
    }

    public static function deleteItem(string $id): OutgoingRequest
    {
        return OutgoingRequest::delete('/v1/warehouse/items/' . rawurlencode($id));
    }

    /**
     * @param  'warehouse'|'vehicle'|'box'|'field'|'other'|null  $kind
     */
    public static function listLocations(
        ?string $kind = null,
        ?ExternalRef $externalRef = null,
        ?string $parentId = null,
        bool $withArchived = false,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/warehouse/locations',
            [
                'kind'         => $kind,
                'external_ref' => $externalRef?->key(),
                'parent_id'    => $parentId,
                'archived'     => $withArchived ? 1 : null,
            ]
        );
    }

    public static function getLocation(string $id): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/warehouse/locations/' . rawurlencode($id));
    }

    /**
     * @param  'warehouse'|'vehicle'|'box'|'field'|'other'  $kind
     */
    public static function createLocation(
        string $name,
        string $kind,
        ?string $parentId = null,
        ?ExternalRef $externalRef = null,
    ): OutgoingRequest {
        return OutgoingRequest::create(
            '/v1/warehouse/locations',
            array_filter(
                ['name' => $name, 'kind' => $kind, 'parent_id' => $parentId, 'external_ref' => $externalRef?->toArray()],
                fn (mixed $value): bool => $value !== null
            )
        );
    }

    public static function updateLocation(
        string $id,
        string $name,
        string $kind,
        ?string $parentId = null,
        ?ExternalRef $externalRef = null,
        bool $archived = false,
    ): OutgoingRequest {
        return OutgoingRequest::put(
            '/v1/warehouse/locations/' . rawurlencode($id),
            [
                'name'         => $name,
                'kind'         => $kind,
                'parent_id'    => $parentId,
                'external_ref' => $externalRef?->toArray(),
                'archived'     => $archived,
            ],
            'warehouse_location:' . $id
        );
    }

    public static function deleteLocation(string $id): OutgoingRequest
    {
        return OutgoingRequest::delete('/v1/warehouse/locations/' . rawurlencode($id));
    }

    public static function getSettings(): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/warehouse/settings');
    }

    /**
     * Codes of expense categories of finance for purchases and write-offs; null — no operation for that kind.
     */
    public static function putSettings(?string $purchaseCategory, ?string $writeOffCategory): OutgoingRequest
    {
        return OutgoingRequest::put(
            '/v1/warehouse/settings',
            ['purchase_category' => $purchaseCategory, 'write_off_category' => $writeOffCategory]
        );
    }

    /**
     * Stock comes to a place. A serial item — one unit, by its `serialNumber`.
     */
    public static function receipt(
        string $itemId,
        string $toLocationId,
        ?string $quantity = null,
        ?string $unitCost = null,
        ?string $serialNumber = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?ExternalRef $externalRef = null,
        ?string $comment = null,
        ?string $occurredAt = null,
    ): OutgoingRequest {
        return self::movement(
            'receipt',
            $itemId,
            [
            'to_location_id' => $toLocationId,
            'quantity'       => $quantity,
            'unit_cost'      => $unitCost,
            'serial_number'  => $serialNumber,
            ],
            $sourceType,
            $sourceId,
            $externalRef,
            $comment,
            $occurredAt
        );
    }

    /**
     * Stock leaves a place for good: lost, broken, used up. A serial item — one unit, by `unitId`.
     */
    public static function writeOff(
        string $itemId,
        string $fromLocationId,
        ?string $quantity = null,
        ?string $unitId = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?ExternalRef $externalRef = null,
        ?string $comment = null,
        ?string $occurredAt = null,
    ): OutgoingRequest {
        return self::movement(
            'write_off',
            $itemId,
            [
            'from_location_id' => $fromLocationId,
            'quantity'         => $quantity,
            'unit_id'          => $unitId,
            ],
            $sourceType,
            $sourceId,
            $externalRef,
            $comment,
            $occurredAt
        );
    }

    /**
     * Stock goes from one place to another, e.g. from the warehouse to the place of a stage.
     */
    public static function transfer(
        string $itemId,
        string $fromLocationId,
        string $toLocationId,
        ?string $quantity = null,
        ?string $unitId = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?ExternalRef $externalRef = null,
        ?string $comment = null,
        ?string $occurredAt = null,
    ): OutgoingRequest {
        return self::movement(
            'transfer',
            $itemId,
            [
            'from_location_id' => $fromLocationId,
            'to_location_id'   => $toLocationId,
            'quantity'         => $quantity,
            'unit_id'          => $unitId,
            ],
            $sourceType,
            $sourceId,
            $externalRef,
            $comment,
            $occurredAt
        );
    }

    /**
     * Stock is issued to a person of the project until `dueAt`; the issue is open until it is returned.
     */
    public static function issue(
        string $itemId,
        string $fromLocationId,
        ExternalRef $recipient,
        ?string $quantity = null,
        ?string $unitId = null,
        ?string $dueAt = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?ExternalRef $externalRef = null,
        ?string $comment = null,
        ?string $occurredAt = null,
    ): OutgoingRequest {
        return self::movement(
            'issue',
            $itemId,
            [
            'from_location_id' => $fromLocationId,
            'recipient'        => $recipient->toArray(),
            'quantity'         => $quantity,
            'unit_id'          => $unitId,
            'due_at'           => $dueAt,
            ],
            $sourceType,
            $sourceId,
            $externalRef,
            $comment,
            $occurredAt
        );
    }

    /**
     * A return of an issue: without a quantity — what is left of it, without a place — where it was issued from.
     *
     * @param  'ok'|'damaged'|'in_repair'|null  $condition  of a serial unit
     */
    public static function returnIssue(
        string $itemId,
        string $issueId,
        ?string $quantity = null,
        ?string $toLocationId = null,
        ?string $condition = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $comment = null,
        ?string $occurredAt = null,
    ): OutgoingRequest {
        return self::movement(
            'return',
            $itemId,
            [
            'issue_id'       => $issueId,
            'quantity'       => $quantity,
            'to_location_id' => $toLocationId,
            'condition'      => $condition,
            ],
            $sourceType,
            $sourceId,
            null,
            $comment,
            $occurredAt
        );
    }

    /**
     * A return of the issue moved with this source, for the outbox: it goes right behind the issue in the stream of
     * the item before the id of the issue is known. The issue not there yet — `409 issue_not_found`, retried.
     *
     * @param  'ok'|'damaged'|'in_repair'|null  $condition  of a serial unit
     */
    public static function returnBySource(
        string $itemId,
        string $issueSourceType,
        string $issueSourceId,
        ?string $quantity = null,
        ?string $toLocationId = null,
        ?string $condition = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $comment = null,
        ?string $occurredAt = null,
    ): OutgoingRequest {
        return self::movement(
            'return',
            $itemId,
            [
            'issue_source_type' => $issueSourceType,
            'issue_source_id'   => $issueSourceId,
            'quantity'          => $quantity,
            'to_location_id'    => $toLocationId,
            'condition'         => $condition,
            ],
            $sourceType,
            $sourceId,
            null,
            $comment,
            $occurredAt
        );
    }

    /**
     * The counted quantity at a place; the service writes the difference, or nothing.
     */
    public static function inventory(
        string $itemId,
        string $locationId,
        string $actualQuantity,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $comment = null,
        ?string $occurredAt = null,
    ): OutgoingRequest {
        return self::movement(
            'inventory',
            $itemId,
            [
            'location_id'     => $locationId,
            'actual_quantity' => $actualQuantity,
            ],
            $sourceType,
            $sourceId,
            null,
            $comment,
            $occurredAt
        );
    }

    /**
     * A correction of a past mistake by a signed delta: `-1.5`, `2`.
     */
    public static function adjustment(
        string $itemId,
        string $locationId,
        string $delta,
        ?string $unitCost = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $comment = null,
        ?string $occurredAt = null,
    ): OutgoingRequest {
        return self::movement(
            'adjustment',
            $itemId,
            [
            'location_id' => $locationId,
            'delta'       => $delta,
            'unit_cost'   => $unitCost,
            ],
            $sourceType,
            $sourceId,
            null,
            $comment,
            $occurredAt
        );
    }

    public static function listMovements(
        ?string $itemId = null,
        ?string $locationId = null,
        ?string $type = null,
        ?ExternalRef $externalRef = null,
        ?string $from = null,
        ?string $to = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/warehouse/movements',
            [
                'item_id'      => $itemId,
                'location_id'  => $locationId,
                'type'         => $type,
                'external_ref' => $externalRef?->key(),
                'from'         => $from,
                'to'           => $to,
                'per_page'     => $perPage,
                'cursor'       => $cursor,
            ]
        );
    }

    public static function getMovement(string $id): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/warehouse/movements/' . rawurlencode($id));
    }

    public static function balances(?string $itemId = null, ?string $locationId = null): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/warehouse/balances', ['item_id' => $itemId, 'location_id' => $locationId]);
    }

    public static function units(?string $itemId = null, ?string $locationId = null, ?string $serialNumber = null): OutgoingRequest
    {
        return OutgoingRequest::read(
            '/v1/warehouse/units',
            ['item_id' => $itemId, 'location_id' => $locationId, 'serial_number' => $serialNumber]
        );
    }

    public static function unitHistory(string $id): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/warehouse/units/' . rawurlencode($id) . '/history');
    }

    /**
     * @param  'ok'|'damaged'|'in_repair'  $condition
     */
    public static function setUnitCondition(string $id, string $condition, ?string $comment = null): OutgoingRequest
    {
        return OutgoingRequest::put(
            '/v1/warehouse/units/' . rawurlencode($id),
            array_filter(['condition' => $condition, 'comment' => $comment], fn (?string $value): bool => $value !== null),
            'warehouse_unit:' . $id
        );
    }

    public static function issues(
        bool $openOnly = false,
        bool $overdueOnly = false,
        ?ExternalRef $recipient = null,
        ?string $itemId = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/warehouse/issues',
            [
                'open'      => $openOnly ? 1 : null,
                'overdue'   => $overdueOnly ? 1 : null,
                'recipient' => $recipient?->key(),
                'item_id'   => $itemId,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function movement(
        string $type,
        string $itemId,
        array $fields,
        ?string $sourceType,
        ?string $sourceId,
        ?ExternalRef $externalRef,
        ?string $comment,
        ?string $occurredAt,
    ): OutgoingRequest {
        return OutgoingRequest::create(
            '/v1/warehouse/movements',
            array_filter(
                [
                    'type'         => $type,
                    'item_id'      => $itemId,
                    ...$fields,
                    'source_type'  => $sourceType,
                    'source_id'    => $sourceId,
                    'external_ref' => $externalRef?->toArray(),
                    'comment'      => $comment,
                    'occurred_at'  => $occurredAt,
                ],
                fn (mixed $value): bool => $value !== null
            ),
            'warehouse_item:' . $itemId
        );
    }
}
