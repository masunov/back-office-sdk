<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

/**
 * Who acts in the project: goes to `X-Actor-Id`, `X-Actor-Name` and into the audit log of the service.
 */
final class Actor
{
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $name = null,
    ) {
    }
}
