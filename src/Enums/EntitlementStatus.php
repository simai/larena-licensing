<?php

declare(strict_types=1);

namespace Larena\Licensing\Enums;

enum EntitlementStatus: string
{
    case Missing = 'missing';
    case Active = 'active';
    case Trial = 'trial';
    case Grace = 'grace';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case InvalidSignature = 'invalid_signature';
    case Tampered = 'tampered';

    public function canUnlockPaidCapabilities(): bool
    {
        return in_array($this, [self::Active, self::Trial, self::Grace], true);
    }
}
