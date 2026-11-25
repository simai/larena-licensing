<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

/**
 * Where the site's licence stands, for the admin banner (owner decisions 2026-11-25).
 *
 * - `active`: a signed snapshot younger than its lifetime (30 days, a file up to a year);
 * - `stale`: the snapshot is past its lifetime or the clock went back — installed code keeps its rights, the admin
 *   says «не удалось проверить лицензию»;
 * - `expired`: the licence ended (or was revoked) — installed code keeps working, updates only up to «updates until»;
 * - `missing`: no snapshot, or the registration server knows no licence for this site;
 * - `invalid`: the kept snapshot does not verify (signature, key, format, other site);
 * - `development`: a test address — every capability, no snapshot, «копия для разработки».
 */
enum LicensingState: string
{
    case Active = 'active';
    case Stale = 'stale';
    case Expired = 'expired';
    case Missing = 'missing';
    case Invalid = 'invalid';
    case Development = 'development';
}
