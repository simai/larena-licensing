<?php

declare(strict_types=1);

namespace Larena\Licensing\Enums;

enum LicenseDecisionStatus: string
{
    case Allowed = 'allowed';
    case Locked = 'locked';
    case Limited = 'limited';
    case Denied = 'denied';
    case Unknown = 'unknown';

    public function permitsExecution(): bool
    {
        return in_array($this, [self::Allowed, self::Limited], true);
    }
}
