<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Api;

use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Dto\UnitHistory;
use Mnd\BackOffice\Dto\WarehouseBalance;
use Mnd\BackOffice\Dto\WarehouseIssue;
use Mnd\BackOffice\Dto\WarehouseItem;
use Mnd\BackOffice\Dto\WarehouseItemInput;
use Mnd\BackOffice\Dto\WarehouseLabel;
use Mnd\BackOffice\Dto\WarehouseLocation;
use Mnd\BackOffice\Dto\WarehouseMovement;
use Mnd\BackOffice\Dto\WarehouseReservation;
use Mnd\BackOffice\Dto\WarehouseUnit;
use Mnd\BackOffice\Http\OutgoingRequest;
use Mnd\BackOffice\Http\Transport;
use Mnd\BackOffice\Requests\WarehouseRequests;

/**
 * The module warehouse, sent at once: reads and the catalogue. A movement the project must not lose goes through the
 * outbox (`BackOffice::outbox()->push(WarehouseRequests::issue(...), tenant: …)`); `move()` sends one at once, when
 * a person waits for its answer (e.g. «not enough at the place»).
 */
final class WarehouseApi
{
    public function __construct(
        private readonly Transport $transport,
        private readonly ?string $tenant,
        private readonly ?Actor $actor,
    ) {
    }

    /**
     * @return array{0: list<WarehouseItem>, 1: string|null} the page and the cursor of the next one
     */
    public function items(
        ?string $search = null,
        ?string $category = null,
        bool $withArchived = false,
        ?int $perPage = null,
        ?string $cursor = null,
    ): array {
        $data = $this->send(WarehouseRequests::listItems($search, $category, $withArchived, $perPage, $cursor));

        return [
            array_map(fn (array $item): WarehouseItem => WarehouseItem::fromArray($item), $data['data']),
            $data['meta']['next_cursor'] ?? null,
        ];
    }

    public function item(string $id): WarehouseItem
    {
        return WarehouseItem::fromArray($this->send(WarehouseRequests::getItem($id))['data']);
    }

    public function createItem(WarehouseItemInput $item): WarehouseItem
    {
        return WarehouseItem::fromArray($this->send(WarehouseRequests::createItem($item))['data']);
    }

    public function updateItem(string $id, WarehouseItemInput $item, bool $archived = false): WarehouseItem
    {
        return WarehouseItem::fromArray($this->send(WarehouseRequests::updateItem($id, $item, $archived))['data']);
    }

    public function deleteItem(string $id): void
    {
        $this->send(WarehouseRequests::deleteItem($id));
    }

    /**
     * @return list<WarehouseLocation>
     */
    public function locations(
        ?string $kind = null,
        ?ExternalRef $externalRef = null,
        ?string $parentId = null,
        bool $withArchived = false,
    ): array {
        return array_map(
            fn (array $location): WarehouseLocation => WarehouseLocation::fromArray($location),
            $this->send(WarehouseRequests::listLocations($kind, $externalRef, $parentId, $withArchived))['data']
        );
    }

    public function location(string $id): WarehouseLocation
    {
        return WarehouseLocation::fromArray($this->send(WarehouseRequests::getLocation($id))['data']);
    }

    public function createLocation(
        string $name,
        string $kind,
        ?string $parentId = null,
        ?ExternalRef $externalRef = null,
    ): WarehouseLocation {
        return WarehouseLocation::fromArray(
            $this->send(WarehouseRequests::createLocation($name, $kind, $parentId, $externalRef))['data']
        );
    }

    public function updateLocation(
        string $id,
        string $name,
        string $kind,
        ?string $parentId = null,
        ?ExternalRef $externalRef = null,
        bool $archived = false,
    ): WarehouseLocation {
        return WarehouseLocation::fromArray(
            $this->send(WarehouseRequests::updateLocation($id, $name, $kind, $parentId, $externalRef, $archived))['data']
        );
    }

    public function deleteLocation(string $id): void
    {
        $this->send(WarehouseRequests::deleteLocation($id));
    }

    /**
     * @return array{purchase_category: string|null, write_off_category: string|null}
     */
    public function settings(): array
    {
        return $this->send(WarehouseRequests::getSettings())['data'];
    }

    /**
     * @return array{purchase_category: string|null, write_off_category: string|null}
     */
    public function putSettings(?string $purchaseCategory, ?string $writeOffCategory): array
    {
        return $this->send(WarehouseRequests::putSettings($purchaseCategory, $writeOffCategory))['data'];
    }

    /**
     * Sends a movement built by WarehouseRequests (`receipt`, `writeOff`, `transfer`, `issue`, `issueReserved`,
     * `returnIssue`, `consumeIssue`, `inventory`, `adjustment`).
     *
     * @return WarehouseMovement|null null — an inventory without a difference wrote nothing
     */
    public function move(OutgoingRequest $movement): ?WarehouseMovement
    {
        $data = $this->send($movement)['data'];

        return $data === null ? null : WarehouseMovement::fromArray($data);
    }

    /**
     * @return array{0: list<WarehouseMovement>, 1: string|null}
     */
    public function movements(
        ?string $itemId = null,
        ?string $locationId = null,
        ?string $type = null,
        ?ExternalRef $externalRef = null,
        ?string $from = null,
        ?string $to = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): array {
        $data = $this->send(
            WarehouseRequests::listMovements($itemId, $locationId, $type, $externalRef, $from, $to, $perPage, $cursor)
        );

        return [
            array_map(fn (array $movement): WarehouseMovement => WarehouseMovement::fromArray($movement), $data['data']),
            $data['meta']['next_cursor'] ?? null,
        ];
    }

    public function movement(string $id): WarehouseMovement
    {
        return WarehouseMovement::fromArray($this->send(WarehouseRequests::getMovement($id))['data']);
    }

    /**
     * @return list<WarehouseBalance>
     */
    public function balances(?string $itemId = null, ?string $locationId = null): array
    {
        return array_map(
            fn (array $balance): WarehouseBalance => WarehouseBalance::fromArray($balance),
            $this->send(WarehouseRequests::balances($itemId, $locationId))['data']
        );
    }

    /**
     * @return list<WarehouseUnit>
     */
    public function units(?string $itemId = null, ?string $locationId = null, ?string $serialNumber = null): array
    {
        return array_map(
            fn (array $unit): WarehouseUnit => WarehouseUnit::fromArray($unit),
            $this->send(WarehouseRequests::units($itemId, $locationId, $serialNumber))['data']
        );
    }

    /**
     * A page of units by serial number and the cursor of the next one (null — no more). `units()` gives only the
     * first page of up to 1000; a walk over every unit (a check by QR labels) goes by pages.
     *
     * @return array{0: list<WarehouseUnit>, 1: string|null}
     */
    public function unitsPage(
        ?string $itemId = null,
        ?string $locationId = null,
        ?string $serialNumber = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): array {
        $data = $this->send(WarehouseRequests::units($itemId, $locationId, $serialNumber, $perPage, $cursor));

        return [
            array_map(fn (array $unit): WarehouseUnit => WarehouseUnit::fromArray($unit), $data['data']),
            $data['meta']['next_cursor'] ?? null,
        ];
    }

    public function unitHistory(string $id): UnitHistory
    {
        return UnitHistory::fromArray($this->send(WarehouseRequests::unitHistory($id))['data']);
    }

    public function setUnitCondition(string $id, string $condition, ?string $comment = null): WarehouseUnit
    {
        return WarehouseUnit::fromArray($this->send(WarehouseRequests::setUnitCondition($id, $condition, $comment))['data']);
    }

    /**
     * @return list<WarehouseIssue>
     */
    public function issues(
        bool $openOnly = false,
        bool $overdueOnly = false,
        ?ExternalRef $recipient = null,
        ?string $itemId = null,
    ): array {
        return array_map(
            fn (array $issue): WarehouseIssue => WarehouseIssue::fromArray($issue),
            $this->send(WarehouseRequests::issues($openOnly, $overdueOnly, $recipient, $itemId))['data']
        );
    }

    /**
     * A page of issues by due date (undated last) and the cursor of the next one; `issues()` — the first page only.
     *
     * @return array{0: list<WarehouseIssue>, 1: string|null}
     */
    public function issuesPage(
        bool $openOnly = false,
        bool $overdueOnly = false,
        ?ExternalRef $recipient = null,
        ?string $itemId = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): array {
        $data = $this->send(WarehouseRequests::issues($openOnly, $overdueOnly, $recipient, $itemId, $perPage, $cursor));

        return [
            array_map(fn (array $issue): WarehouseIssue => WarehouseIssue::fromArray($issue), $data['data']),
            $data['meta']['next_cursor'] ?? null,
        ];
    }

    /**
     * Sends a reservation built by `WarehouseRequests::reserve()` at once, when a person waits for «not enough free
     * at the place».
     */
    public function reserve(OutgoingRequest $reservation): WarehouseReservation
    {
        return WarehouseReservation::fromArray($this->send($reservation)['data']);
    }

    public function cancelReservation(string $itemId, string $id): WarehouseReservation
    {
        return WarehouseReservation::fromArray($this->send(WarehouseRequests::cancelReservation($itemId, $id))['data']);
    }

    /**
     * @return list<WarehouseReservation>
     */
    public function reservations(
        bool $activeOnly = false,
        ?string $itemId = null,
        ?string $locationId = null,
        ?ExternalRef $holder = null,
    ): array {
        return array_map(
            fn (array $reservation): WarehouseReservation => WarehouseReservation::fromArray($reservation),
            $this->send(WarehouseRequests::reservations($activeOnly, $itemId, $locationId, $holder))['data']
        );
    }

    /**
     * A page of reservations, the newest first, and the cursor of the next one; `reservations()` — the first page.
     *
     * @return array{0: list<WarehouseReservation>, 1: string|null}
     */
    public function reservationsPage(
        bool $activeOnly = false,
        ?string $itemId = null,
        ?string $locationId = null,
        ?ExternalRef $holder = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): array {
        $data = $this->send(WarehouseRequests::reservations($activeOnly, $itemId, $locationId, $holder, $perPage, $cursor));

        return [
            array_map(fn (array $reservation): WarehouseReservation => WarehouseReservation::fromArray($reservation), $data['data']),
            $data['meta']['next_cursor'] ?? null,
        ];
    }

    /**
     * A batch of free QR labels for the project to print: the batch and its labels by number.
     *
     * @return array{0: string, 1: list<WarehouseLabel>}
     */
    public function issueLabels(int $count): array
    {
        $data = $this->send(WarehouseRequests::issueLabels($count))['data'];

        return [$data['batch_id'], array_map(fn (array $label): WarehouseLabel => WarehouseLabel::fromArray($label), $data['labels'])];
    }

    /**
     * A page of labels by number and the cursor of the next one.
     *
     * @param  'available'|'assigned'|'retired'|null  $status
     * @return array{0: list<WarehouseLabel>, 1: string|null}
     */
    public function labels(
        ?string $status = null,
        ?string $batchId = null,
        ?string $unitId = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): array {
        $data = $this->send(WarehouseRequests::labels($status, $batchId, $unitId, $perPage, $cursor));

        return [
            array_map(fn (array $label): WarehouseLabel => WarehouseLabel::fromArray($label), $data['data']),
            $data['meta']['next_cursor'] ?? null,
        ];
    }

    /**
     * A scan: the label and, if it is or was on one, the card of its unit (`$label->unit`). No such label of the
     * tenant — ApiErrorException `label_not_found`.
     */
    public function resolveLabel(string $code): WarehouseLabel
    {
        return WarehouseLabel::fromArray($this->send(WarehouseRequests::resolveLabel($code))['data']);
    }

    /**
     * At once, when a person scanned it: taken by another unit or retired — ApiErrorException `label_unavailable`.
     */
    public function assignLabel(string $code, string $unitId, ?string $comment = null): WarehouseLabel
    {
        return WarehouseLabel::fromArray($this->send(WarehouseRequests::assignLabel($code, $unitId, $comment))['data']);
    }

    /**
     * @param  'lost'|'damaged'|'other'  $reason
     */
    public function retireLabel(string $code, string $reason, ?string $comment = null): WarehouseLabel
    {
        return WarehouseLabel::fromArray($this->send(WarehouseRequests::retireLabel($code, $reason, $comment))['data']);
    }

    public function reservation(string $id): WarehouseReservation
    {
        return WarehouseReservation::fromArray($this->send(WarehouseRequests::getReservation($id))['data']);
    }

    /**
     * @return array<string, mixed>
     */
    private function send(OutgoingRequest $request): array
    {
        return $this->transport->send($request->forTenant($this->tenant)->actingAs($this->actor));
    }
}
