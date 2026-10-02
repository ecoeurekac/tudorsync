<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api\Auth;

use DateTimeImmutable;

/**
 * Same shape as PSR-20's ClockInterface, kept local so core doesn't add a dependency just
 * for this. Lets tests move time forward to expire a cached access token.
 */
interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
