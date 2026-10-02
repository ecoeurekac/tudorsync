<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api\Auth;

use DateTimeImmutable;

final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
