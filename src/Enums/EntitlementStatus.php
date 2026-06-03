<?php

declare(strict_types=1);

namespace Larena\Licensing\Enums;

enum EntitlementStatus: string
{
    case Active = 'active';
    case Trial = 'trial';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case InvalidSignature = 'invalid_signature';
}
