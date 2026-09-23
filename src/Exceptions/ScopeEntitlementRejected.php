<?php

declare(strict_types=1);

namespace Larena\Licensing\Exceptions;

use RuntimeException;

/**
 * A scope entitlement boundary refused. The reason code is part of the frozen
 * contract; the message is not.
 */
final class ScopeEntitlementRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = '',
    ) {
        parent::__construct($message === '' ? 'Scope entitlement refused: ' . $reasonCode : $message);
    }
}
