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
    /** The most ids of one request of items by ids. */
    public const ITEMS_BY_IDS = 200;

    /**
     * The items with these ids, archived ones too; foreign and unknown ids are just not in the answer.
     *
     * @param  list<string>  $ids  at most ITEMS_BY_IDS
     */
    public static function itemsByIds(array $ids): OutgoingRequest
    {
        if (\count($ids) > self::ITEMS_BY_IDS) {
            throw new \InvalidArgumentException('At most ' . self::ITEMS_BY_IDS . ' ids per request');
        }

        return OutgoingRequest::read('/v1/warehouse/items', ['ids' => array_values($ids), 'per_page' => self::ITEMS_BY_IDS]);
    }

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

    /**
     * The units of the nomenclature as the service has them; the same list is ItemUnit.
     */
    public static function itemUnits(): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/warehouse/item-units');
    }

    public static function getSettings(): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/warehouse/settings');
    }

    /**
     * Codes of expense categories of finance for purchases and write-offs; null — no operation for that kind.
     */
    /**
     * `expiryWarningDays` — how many days before its date a lot is told as expiring (0–30); null — as it was.
     */
    public static function putSettings(?string $purchaseCategory, ?string $writeOffCategory, ?int $expiryWarningDays = null): OutgoingRequest
    {
        return OutgoingRequest::put(
            '/v1/warehouse/settings',
            [
                'purchase_category'  => $purchaseCategory,
                'write_off_category' => $writeOffCategory,
                ...($expiryWarningDays !== null ? ['expiry_warning_days' => $expiryWarningDays] : []),
            ]
        );
    }

    /**
     * Stock comes to a place. A serial item — one unit, by its `serialNumber`. A packaged item (a bottle of 0.75 l)
     * may come by `packages` instead of `quantity` (4 → 3 l) and at `packageCost` instead of `unitCost`.
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
        ?string $packages = null,
        ?string $packageCost = null,
        ?string $lotId = null,
        ?string $lotNumber = null,
        ?string $expiresOn = null,
    ): OutgoingRequest {
        return self::movement(
            'receipt',
            $itemId,
            [
            'to_location_id' => $toLocationId,
            'quantity'       => $quantity,
            'unit_cost'      => $unitCost,
            'serial_number'  => $serialNumber,
            'packages'       => $packages,
            'package_cost'   => $packageCost,
            'lot_id' => $lotId,
            'lot_number' => $lotNumber,
            'expires_on' => $expiresOn,
            ],
            $sourceType,
            $sourceId,
            $externalRef,
            $comment,
            $occurredAt
        );
    }

    /**
     * Stock leaves a place for good: lost, broken, used up. A serial item — one unit, by `unitId`. A packaged item is
     * written off by volume or weight in its `stockUnit` (`quantity: '0.25'` — a quarter of a litre), or by `packages`.
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
        ?string $packages = null,
        ?string $lotId = null,
    ): OutgoingRequest {
        return self::movement(
            'write_off',
            $itemId,
            [
            'from_location_id' => $fromLocationId,
            'quantity'         => $quantity,
            'unit_id'          => $unitId,
            'packages'       => $packages,
            'lot_id' => $lotId,
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
        ?string $packages = null,
        ?string $lotId = null,
    ): OutgoingRequest {
        return self::movement(
            'transfer',
            $itemId,
            [
            'from_location_id' => $fromLocationId,
            'to_location_id'   => $toLocationId,
            'quantity'         => $quantity,
            'unit_id'          => $unitId,
            'packages'       => $packages,
            'lot_id' => $lotId,
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
        ?string $packages = null,
        ?string $lotId = null,
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
            'packages'       => $packages,
            'lot_id' => $lotId,
            ],
            $sourceType,
            $sourceId,
            $externalRef,
            $comment,
            $occurredAt
        );
    }

    /**
     * An issue by a reservation: from its place, without a quantity — what is left of it, a serial one — its unit.
     */
    public static function issueReserved(
        string $itemId,
        string $reservationId,
        ExternalRef $recipient,
        ?string $quantity = null,
        ?string $unitId = null,
        ?string $dueAt = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?ExternalRef $externalRef = null,
        ?string $comment = null,
        ?string $occurredAt = null,
        ?string $packages = null,
        ?string $lotId = null,
    ): OutgoingRequest {
        return self::movement(
            'issue',
            $itemId,
            [
            'reservation_id' => $reservationId,
            'recipient'      => $recipient->toArray(),
            'quantity'       => $quantity,
            'unit_id'        => $unitId,
            'due_at'         => $dueAt,
            'packages'       => $packages,
            'lot_id' => $lotId,
            ],
            $sourceType,
            $sourceId,
            $externalRef,
            $comment,
            $occurredAt
        );
    }

    /**
     * An issue by the reservation made with this source, for the outbox: it goes right behind the reservation in the
     * stream of the item before its id is known. The reservation not there yet — `409 reservation_not_found`, retried.
     */
    public static function issueReservedBySource(
        string $itemId,
        string $reservationSourceType,
        string $reservationSourceId,
        ExternalRef $recipient,
        ?string $quantity = null,
        ?string $unitId = null,
        ?string $dueAt = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?ExternalRef $externalRef = null,
        ?string $comment = null,
        ?string $occurredAt = null,
        ?string $packages = null,
        ?string $lotId = null,
    ): OutgoingRequest {
        return self::movement(
            'issue',
            $itemId,
            [
            'reservation_source_type' => $reservationSourceType,
            'reservation_source_id'   => $reservationSourceId,
            'recipient'               => $recipient->toArray(),
            'quantity'                => $quantity,
            'unit_id'                 => $unitId,
            'due_at'                  => $dueAt,
            'packages'       => $packages,
            'lot_id' => $lotId,
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
        ?string $packages = null,
        ?string $lotId = null,
    ): OutgoingRequest {
        return self::movement(
            'return',
            $itemId,
            [
            'issue_id'       => $issueId,
            'quantity'       => $quantity,
            'to_location_id' => $toLocationId,
            'condition'      => $condition,
            'packages'       => $packages,
            'lot_id' => $lotId,
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
        ?string $packages = null,
        ?string $lotId = null,
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
            'packages'       => $packages,
            'lot_id' => $lotId,
            ],
            $sourceType,
            $sourceId,
            null,
            $comment,
            $occurredAt
        );
    }

    /**
     * Quantity stock used directly from a place: good FEFO lots (or lotId), no intermediate issue.
     * Requires settings.data.capabilities.consume_from_location=true on the service.
     */
    public static function consumeFromLocation(
        string $itemId,
        string $fromLocationId,
        ?string $quantity = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?ExternalRef $externalRef = null,
        ?string $comment = null,
        ?string $occurredAt = null,
        ?string $packages = null,
        ?string $lotId = null,
    ): OutgoingRequest {
        return self::movement(
            'consume',
            $itemId,
            ['from_location_id' => $fromLocationId, 'quantity' => $quantity, 'packages' => $packages, 'lot_id' => $lotId],
            $sourceType,
            $sourceId,
            $externalRef,
            $comment,
            $occurredAt
        );
    }

    /**
     * Issued stock used up where it went (oil at a trip, a destroyed tyre): it settles the issue without coming back.
     * Without a quantity — what is left of the issue; a serial unit is the one of the issue and is written off. More
     * than is left — `409 consume_exceeds_issue`.
     */
    public static function consumeIssue(
        string $itemId,
        string $issueId,
        ?string $quantity = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?ExternalRef $externalRef = null,
        ?string $comment = null,
        ?string $occurredAt = null,
        ?string $packages = null,
        ?string $lotId = null,
    ): OutgoingRequest {
        return self::movement(
            'consume',
            $itemId,
            [
            'issue_id' => $issueId,
            'quantity' => $quantity,
            'packages'       => $packages,
            'lot_id' => $lotId,
            ],
            $sourceType,
            $sourceId,
            $externalRef,
            $comment,
            $occurredAt
        );
    }

    /**
     * A consumption of the issue moved with this source, for the outbox, as `returnBySource`: the issue not there
     * yet — `409 issue_not_found`, retried.
     */
    public static function consumeBySource(
        string $itemId,
        string $issueSourceType,
        string $issueSourceId,
        ?string $quantity = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?ExternalRef $externalRef = null,
        ?string $comment = null,
        ?string $occurredAt = null,
        ?string $packages = null,
        ?string $lotId = null,
    ): OutgoingRequest {
        return self::movement(
            'consume',
            $itemId,
            [
            'issue_source_type' => $issueSourceType,
            'issue_source_id'   => $issueSourceId,
            'quantity'          => $quantity,
            'packages'       => $packages,
            'lot_id' => $lotId,
            ],
            $sourceType,
            $sourceId,
            $externalRef,
            $comment,
            $occurredAt
        );
    }

    /**
     * The counted quantity at a place; the service writes the difference, or nothing. With `lotId` — of that lot
     * only; what was found over it comes to the lot of `lotNumber` and `expiresOn`.
     */
    public static function inventory(
        string $itemId,
        string $locationId,
        string $actualQuantity,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $comment = null,
        ?string $occurredAt = null,
        ?string $lotId = null,
        ?string $lotNumber = null,
        ?string $expiresOn = null,
    ): OutgoingRequest {
        return self::movement(
            'inventory',
            $itemId,
            [
            'location_id'     => $locationId,
            'actual_quantity' => $actualQuantity,
            'lot_id' => $lotId,
            'lot_number' => $lotNumber,
            'expires_on' => $expiresOn,
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
        ?string $lotId = null,
        ?string $lotNumber = null,
        ?string $expiresOn = null,
    ): OutgoingRequest {
        return self::movement(
            'adjustment',
            $itemId,
            [
            'location_id' => $locationId,
            'delta'       => $delta,
            'unit_cost'   => $unitCost,
            'lot_id' => $lotId,
            'lot_number' => $lotNumber,
            'expires_on' => $expiresOn,
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

    /**
     * Without `perPage` — a page of up to 1000, as the list was before it had pages; the next one by `cursor`.
     */
    /**
     * Lots by expiry date, the earliest first (the order stock leaves in). Without `withEmpty` — those with stock (at
     * `locationId`, if given); `expiredOnly` — expired on the day of the tenant; `expiresBefore` (Y-m-d) — dated
     * earlier than that day.
     */
    public static function lots(
        ?string $itemId = null,
        ?string $locationId = null,
        bool $expiredOnly = false,
        ?string $expiresBefore = null,
        bool $withEmpty = false,
        ?int $perPage = null,
        ?string $cursor = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/warehouse/lots',
            [
                'item_id'        => $itemId,
                'location_id'    => $locationId,
                'expired'        => $expiredOnly ? 1 : null,
                'expires_before' => $expiresBefore,
                'with_empty'     => $withEmpty ? 1 : null,
                'per_page'       => $perPage,
                'cursor'         => $cursor,
            ]
        );
    }

    public static function getLot(string $id): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/warehouse/lots/' . rawurlencode($id));
    }

    public static function units(
        ?string $itemId = null,
        ?string $locationId = null,
        ?string $serialNumber = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/warehouse/units',
            [
                'item_id'       => $itemId,
                'location_id'   => $locationId,
                'serial_number' => $serialNumber,
                'per_page'      => $perPage,
                'cursor'        => $cursor,
            ]
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
        ?int $perPage = null,
        ?string $cursor = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/warehouse/issues',
            [
                'open'      => $openOnly ? 1 : null,
                'overdue'   => $overdueOnly ? 1 : null,
                'recipient' => $recipient?->key(),
                'item_id'   => $itemId,
                'per_page'  => $perPage,
                'cursor'    => $cursor,
            ]
        );
    }

    /**
     * Holds stock at a place for `holder` (e.g. a stage) until it is issued by the reservation, cancelled or
     * `expiresAt`. A quantity item — `quantity`; a serial one — one unit by `unitId`, or a whole `quantity` of its
     * free units. Goes in the stream of the item: an issue by it never overtakes it.
     */
    public static function reserve(
        string $itemId,
        string $locationId,
        ExternalRef $holder,
        ?string $quantity = null,
        ?string $unitId = null,
        ?string $expiresAt = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $comment = null,
    ): OutgoingRequest {
        return OutgoingRequest::create(
            '/v1/warehouse/reservations',
            array_filter(
                [
                    'item_id'     => $itemId,
                    'location_id' => $locationId,
                    'holder'      => $holder->toArray(),
                    'quantity'    => $quantity,
                    'unit_id'     => $unitId,
                    'expires_at'  => $expiresAt,
                    'source_type' => $sourceType,
                    'source_id'   => $sourceId,
                    'comment'     => $comment,
                ],
                fn (mixed $value): bool => $value !== null
            ),
            'warehouse_item:' . $itemId
        );
    }

    /**
     * What is left of the reservation is free again. Idempotent by itself: a reservation that holds nothing is
     * returned as it is.
     */
    public static function cancelReservation(string $itemId, string $id): OutgoingRequest
    {
        return self::reservationAction('/v1/warehouse/reservations/' . rawurlencode($id) . '/cancel', [], $itemId);
    }

    /**
     * A cancel of the reservation made with this source, for the outbox: before its id is known. The reservation not
     * there yet — `409 reservation_not_found`, retried.
     */
    public static function cancelReservationBySource(string $itemId, string $sourceType, string $sourceId): OutgoingRequest
    {
        return self::reservationAction(
            '/v1/warehouse/reservations/cancel',
            ['source_type' => $sourceType, 'source_id' => $sourceId],
            $itemId
        );
    }

    public static function reservations(
        bool $activeOnly = false,
        ?string $itemId = null,
        ?string $locationId = null,
        ?ExternalRef $holder = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/warehouse/reservations',
            [
                'active'      => $activeOnly ? 1 : null,
                'item_id'     => $itemId,
                'location_id' => $locationId,
                'holder'      => $holder?->key(),
                'per_page'    => $perPage,
                'cursor'      => $cursor,
            ]
        );
    }

    /**
     * A batch of free QR labels to print; a retry of the request gets the same batch.
     */
    public static function issueLabels(int $count): OutgoingRequest
    {
        return OutgoingRequest::create('/v1/warehouse/labels', ['count' => $count]);
    }

    /**
     * @param  'available'|'assigned'|'retired'|null  $status
     */
    public static function labels(
        ?string $status = null,
        ?string $batchId = null,
        ?string $unitId = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): OutgoingRequest {
        return OutgoingRequest::read(
            '/v1/warehouse/labels',
            ['status' => $status, 'batch_id' => $batchId, 'unit_id' => $unitId, 'per_page' => $perPage, 'cursor' => $cursor]
        );
    }

    /**
     * What was scanned or typed: the code of the QR or the short code (Q-000017, Q17).
     */
    public static function resolveLabel(string $code): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/warehouse/labels/' . rawurlencode($code));
    }

    /**
     * Idempotent by itself: a repeat for the same unit finds the label assigned. The label the unit had is retired
     * as `replaced`. Goes in the stream of the unit, so two labels for it go in the order they were scanned.
     */
    public static function assignLabel(string $code, string $unitId, ?string $comment = null): OutgoingRequest
    {
        return new OutgoingRequest(
            'POST',
            '/v1/warehouse/labels/' . rawurlencode($code) . '/assign',
            [],
            array_filter(['unit_id' => $unitId, 'comment' => $comment], fn (?string $value): bool => $value !== null),
            null,
            null,
            null,
            'warehouse_unit:' . $unitId
        );
    }

    /**
     * For good; idempotent by itself.
     *
     * @param  'lost'|'damaged'|'other'  $reason
     */
    public static function retireLabel(string $code, string $reason, ?string $comment = null): OutgoingRequest
    {
        return new OutgoingRequest(
            'POST',
            '/v1/warehouse/labels/' . rawurlencode($code) . '/retire',
            [],
            array_filter(['reason' => $reason, 'comment' => $comment], fn (?string $value): bool => $value !== null)
        );
    }

    public static function getReservation(string $id): OutgoingRequest
    {
        return OutgoingRequest::read('/v1/warehouse/reservations/' . rawurlencode($id));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function reservationAction(string $path, array $body, string $itemId): OutgoingRequest
    {
        return new OutgoingRequest('POST', $path, [], $body, null, null, null, 'warehouse_item:' . $itemId);
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
