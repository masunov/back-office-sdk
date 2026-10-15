<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Api;

use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Dto\Budget;
use Mnd\BackOffice\Dto\ExternalRef;
use Mnd\BackOffice\Dto\FinanceCategory;
use Mnd\BackOffice\Dto\FinanceOperation;
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
                $kind
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
