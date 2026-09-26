<?php

declare(strict_types=1);

namespace CiContract;

final class CanarySlotSearchPolicy
{
    public static function productFutureBookingLimit(bool $slotDiscovery, ?int $productLimit): ?int
    {
        return $slotDiscovery ? $productLimit : null;
    }
}
