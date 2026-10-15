<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * Whether an external ref is closed: a closed one takes only reversals and corrections.
 */
final class RefLock
{
    public function __construct(
        public readonly ExternalRef $externalRef,
        public readonly bool $locked,
        public readonly ?string $lockedAt,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(ExternalRef::fromArray($data['external_ref']), (bool) $data['locked'], $data['locked_at'] ?? null);
    }
}
