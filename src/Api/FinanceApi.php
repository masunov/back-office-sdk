<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Api;

use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Dto\Budget;
use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Dto\FinanceCategory;
use Mnd\BackOffice\Dto\FinanceObligation;
use Mnd\BackOffice\Dto\FinanceOperation;
use Mnd\BackOffice\Dto\FinancePayment;
use Mnd\BackOffice\Dto\FinanceSummary;
use Mnd\BackOffice\Dto\OperationPage;
use Mnd\BackOffice\Dto\RefLock;
use Mnd\BackOffice\Http\OutgoingRequest;
use Mnd\BackOffice\Http\Transport;
use Mnd\BackOffice\Requests\FinanceRequests;

/**
 * The module finance, sent at once. A write the project must not lose goes through the outbox instead
 * (`BackOffice::outbox()->push(FinanceRequests::…)`); this API is for reads and for writes a person waits for.
 */
final class FinanceApi
{
    public function __construct(
        private readonly Transport $transport,
        private readonly ?string $tenant,
        private readonly ?Actor $actor,
    ) {
    }

    /**
     * @param  string  $amount  positive, with a dot: `1500.00`
     */
    public function createOperation(
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
    ): FinanceOperation {
        return $this->operation(
            FinanceRequests::createOperation(
                $category,
                $amount,
                $operationDate,
                $sourceType,
                $sourceId,
                $externalRef,
                $counterparty,
                $counterpartyRef,
                $comment,
                $kind,
                $obligation
            )
        );
    }

    public function reverse(string $operationId, ?string $operationDate = null, ?string $comment = null): FinanceOperation
    {
        return $this->operation(FinanceRequests::reverse($operationId, $operationDate, $comment));
    }

    public function reverseBySource(
        string $sourceType,
        string $sourceId,
        ?string $operationDate = null,
        ?string $comment = null,
    ): FinanceOperation {
        return $this->operation(FinanceRequests::reverseBySource($sourceType, $sourceId, $operationDate, $comment));
    }

    /**
     * Sends an obligation built by `FinanceRequests::createObligation()` at once, when a person waits for it.
     */
    public function createObligation(OutgoingRequest $obligation): FinanceObligation
    {
        return $this->obligationOf($obligation);
    }

    /**
     * By id or `<source_type>:<source_id>`.
     */
    public function obligation(string $obligation): FinanceObligation
    {
        return $this->obligationOf(FinanceRequests::getObligation($obligation));
    }

    /**
     * @param  array{fulfilled_amount?: string|null, counterparty_label?: string|null, comment?: string|null, documents?: list<array{title?: string|null, url: string}>}  $fields
     */
    public function updateObligation(string $obligation, ?string $status = null, array $fields = []): FinanceObligation
    {
        return $this->obligationOf(FinanceRequests::updateObligation($obligation, $status, $fields));
    }

    public function linkRecognition(string $obligation, string $sourceType, string $sourceId): FinanceObligation
    {
        return $this->obligationOf(FinanceRequests::linkRecognition($obligation, $sourceType, $sourceId));
    }

    /**
     * @param  list<array{key: string, amount: string, due_on?: string|null, label?: string|null}>  $lines
     */
    public function createPayment(
        string $obligation,
        string $amount,
        string $sourceType,
        string $sourceId,
        ?string $paidOn = null,
        string $kind = 'payment',
        string $method = 'money',
        ?string $comment = null,
    ): FinancePayment {
        return $this->payment(FinanceRequests::createPayment($obligation, $amount, $sourceType, $sourceId, $paidOn, $kind, $method, $comment));
    }

    public function reversePayment(string $payment, ?string $paidOn = null, ?string $comment = null): FinancePayment
    {
        return $this->payment(FinanceRequests::reversePayment($payment, $paidOn, $comment));
    }

    /**
     * A page of payments, the newest paid first, and the cursor of the next one.
     *
     * @return array{0: list<FinancePayment>, 1: string|null}
     */
    public function payments(
        ?string $obligation = null,
        ?ExternalRef $ref = null,
        ?ExternalRef $counterparty = null,
        ?string $from = null,
        ?string $to = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): array {
        $data = $this->send(FinanceRequests::listPayments($obligation, $ref, $counterparty, $from, $to, $perPage, $cursor));

        return [
            array_map(fn (array $payment): FinancePayment => FinancePayment::fromArray($payment), $data['data']),
            $data['meta']['next_cursor'] ?? null,
        ];
    }

    public function putObligationSchedule(string $obligation, array $lines, string $reason, ?string $amount = null): FinanceObligation
    {
        return $this->obligationOf(FinanceRequests::putObligationSchedule($obligation, $lines, $reason, $amount));
    }

    /**
     * A page of obligations, the newest first, and the cursor of the next one.
     *
     * @return array{0: list<FinanceObligation>, 1: string|null}
     */
    public function obligations(
        ?ExternalRef $ref = null,
        ?ExternalRef $counterparty = null,
        ?string $category = null,
        ?string $status = null,
        ?string $paymentStatus = null,
        ?bool $overdue = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): array {
        $data = $this->send(
            FinanceRequests::listObligations($ref, $counterparty, $category, $status, $paymentStatus, $overdue, $perPage, $cursor)
        );

        return [
            array_map(fn (array $obligation): FinanceObligation => FinanceObligation::fromArray($obligation), $data['data']),
            $data['meta']['next_cursor'] ?? null,
        ];
    }

    /**
     * Every change of a date or an amount of a line: key, change, from, to, reason, actor, changed_at.
     *
     * @return list<array<string, mixed>>
     */
    public function scheduleHistory(string $obligation): array
    {
        return $this->send(FinanceRequests::obligationScheduleHistory($obligation))['data'];
    }

    public function getOperation(string $operationId): FinanceOperation
    {
        return $this->operation(FinanceRequests::getOperation($operationId));
    }

    public function listOperations(
        ?ExternalRef $ref = null,
        ?string $from = null,
        ?string $to = null,
        ?string $category = null,
        ?string $direction = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): OperationPage {
        return OperationPage::fromArray(
            $this->send(FinanceRequests::listOperations($ref, $from, $to, $category, $direction, $perPage, $cursor))
        );
    }

    /**
     * @return list<FinanceCategory>
     */
    public function categories(): array
    {
        return array_map(
            fn (array $item): FinanceCategory => FinanceCategory::fromArray($item),
            $this->send(FinanceRequests::listCategories())['data']
        );
    }

    /**
     * A category of the project's set when the API has no tenant, a subcategory of the tenant otherwise.
     */
    public function putCategory(
        string $code,
        string $name,
        string $direction,
        ?string $parent = null,
        bool $archived = false,
    ): FinanceCategory {
        $level = $this->tenant === null ? 'project' : 'tenant';

        return FinanceCategory::fromArray(
            $this->send(FinanceRequests::putCategory($code, $name, $direction, $level, $parent, $archived))['data']
        );
    }

    public function deleteCategory(string $code): void
    {
        $this->send(FinanceRequests::deleteCategory($code, $this->tenant === null ? 'project' : 'tenant'));
    }

    public function budget(ExternalRef $ref): Budget
    {
        return Budget::fromArray($this->send(FinanceRequests::getBudget($ref))['data']);
    }

    /**
     * @param  array<string, string>  $amounts  category code => amount
     */
    public function putBudget(ExternalRef $ref, array $amounts): Budget
    {
        return Budget::fromArray($this->send(FinanceRequests::putBudget($ref, $amounts))['data']);
    }

    public function lock(ExternalRef $ref, bool $locked = true): RefLock
    {
        return RefLock::fromArray($this->send(FinanceRequests::lock($ref, $locked))['data']);
    }

    /**
     * @param  list<ExternalRef>  $refs
     */
    public function summary(array $refs, ?string $from = null, ?string $to = null): FinanceSummary
    {
        return FinanceSummary::fromArray($this->send(FinanceRequests::summary($refs, $from, $to))['data']);
    }

    /**
     * @param  list<ExternalRef>  $refs
     * @return string the xlsx file
     */
    public function export(array $refs, ?string $from = null, ?string $to = null): string
    {
        return $this->transport->sendRaw($this->withContext(FinanceRequests::export($refs, $from, $to)));
    }

    private function obligationOf(OutgoingRequest $request): FinanceObligation
    {
        return FinanceObligation::fromArray($this->send($request)['data']);
    }

    private function payment(OutgoingRequest $request): FinancePayment
    {
        return FinancePayment::fromArray($this->send($request)['data']);
    }

    private function operation(OutgoingRequest $request): FinanceOperation
    {
        return FinanceOperation::fromArray($this->send($request)['data']);
    }

    /**
     * @return array<string, mixed>
     */
    private function send(OutgoingRequest $request): array
    {
        return $this->transport->send($this->withContext($request));
    }

    private function withContext(OutgoingRequest $request): OutgoingRequest
    {
        return $request->forTenant($this->tenant)->actingAs($this->actor);
    }
}
