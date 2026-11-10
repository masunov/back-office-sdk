<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * What a money movement did to one account: `debit` — out, `credit` — in, `fee` — the fee of an exchange out of the
 * account it is paid from. `amount` is in the currency of the account, `baseAmount` — its cost in the currency of the
 * tenant, `rate` — the one over the other: for money out of a foreign account, the actual rate of the money spent.
 */
final class FinanceMoneyLine
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $currency,
        public readonly string $role,
        public readonly string $amount,
        public readonly string $baseAmount,
        public readonly string $rate,
        public readonly string $balanceAfter,
        public readonly string $baseCostAfter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['account_id'],
            $data['currency'],
            $data['role'],
            $data['amount'],
            $data['base_amount'],
            $data['rate'],
            $data['balance_after'],
            $data['base_cost_after'],
        );
    }

    public function isOut(): bool
    {
        return $this->role !== 'credit';
    }
}
