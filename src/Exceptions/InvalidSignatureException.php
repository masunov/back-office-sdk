<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Exceptions;

/**
 * A webhook without a valid signature, or signed too long ago.
 */
class InvalidSignatureException extends BackOfficeException
{
}
