<?php

declare(strict_types=1);

namespace Larena\Licensing\Enums;

enum LicenseDecisionStatus: string
{
    case Allowed = 'allowed';
    case DeniedUnknownCapability = 'denied_unknown_capability';
    case DeniedExpired = 'denied_expired';
    case DeniedInvalidSignature = 'denied_invalid_signature';
    case FallbackAllowed = 'fallback_allowed';
}
