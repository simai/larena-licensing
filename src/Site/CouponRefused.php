<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use RuntimeException;

/** A coupon the server refused, with its code (one of {@see SiteCoupon::SERVER_REFUSALS}); never the coupon or a key. */
final class CouponRefused extends RuntimeException
{
    public function reason(): string
    {
        return $this->getMessage();
    }
}
