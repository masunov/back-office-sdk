<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * The plan of an external ref by categories. Also the `data` of the webhook `finance.budget.changed`.
 */
final class Budget
{
    /**
     * @param  array<string, string>  $amounts  category code => amount
     */
    public function __construct(
        public readonly ExternalRef $externalRef,
        public readonly array $amounts,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ExternalRef::fromArray($data['external_ref']),
            array_column($data['items'], 'amount', 'category')
        );
    }
}
