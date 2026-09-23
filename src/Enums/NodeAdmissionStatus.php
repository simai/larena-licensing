<?php

declare(strict_types=1);

namespace Larena\Licensing\Enums;

enum NodeAdmissionStatus: string
{
    case Admitted = 'admitted';
    case Refused = 'refused';

    public function isAdmitted(): bool
    {
        return $this === self::Admitted;
    }
}
