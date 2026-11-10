<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * A movement of money (BO-FIN-007): `top_up`, `withdrawal`, `transfer`, `exchange`, `adjustment` or `reversal`, with
 * its lines. In the answer of a write, of a read by key and in the webhooks `finance.money_movement.*` `accounts` are
 * the accounts it touched as they are now; in a list they are empty.
 */
final class FinanceMoneyMovement
{
    /**
     * @param  list<FinanceMoneyLine>  $lines
     * @param  list<FinanceAccount>  $accounts
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $movedOn,
        public readonly ?string $basis,
        public readonly ?string $sourceType,
        public readonly ?string $sourceId,
        public readonly ?string $operationId,
        public readonly ?string $paymentId,
        public readonly ?ExternalRef $ref,
        public readonly ?string $effectiveRate,
        public readonly ?string $reason,
        public readonly ?string $comment,
        public readonly ?string $reversalOf,
        public readonly array $lines,
        public readonly array $accounts,
        public readonly Actor $actor,
        public readonly string $createdAt,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['kind'],
            $data['moved_on'],
            $data['basis'] ?? null,
            $data['source_type'] ?? null,
            $data['source_id'] ?? null,
            $data['operation_id'] ?? null,
            $data['payment_id'] ?? null,
            isset($data['ref']['type'], $data['ref']['id']) ? new ExternalRef($data['ref']['type'], (string) $data['ref']['id']) : null,
            $data['effective_rate'] ?? null,
            $data['reason'] ?? null,
            $data['comment'] ?? null,
            $data['reversal_of'] ?? null,
            array_map(fn (array $line): FinanceMoneyLine => FinanceMoneyLine::fromArray($line), $data['lines'] ?? []),
            array_map(fn (array $account): FinanceAccount => FinanceAccount::fromArray($account), $data['accounts'] ?? []),
            new Actor($data['actor']['id'] ?? null, $data['actor']['name'] ?? null),
            $data['created_at'],
        );
    }

    public function isReversal(): bool
    {
        return $this->kind === 'reversal';
    }

    /**
     * The first line of the money taken out of the account, if any: a withdrawal has one, its `rate` is what the
     * money spent cost.
     */
    public function debit(?string $accountId = null): ?FinanceMoneyLine
    {
        foreach ($this->lines as $line) {
            if ($line->role === 'debit' && ($accountId === null || $line->accountId === $accountId)) {
                return $line;
            }
        }

        return null;
    }

    public function account(string $id): ?FinanceAccount
    {
        foreach ($this->accounts as $account) {
            if ($account->id === $id) {
                return $account;
            }
        }

        return null;
    }
}
