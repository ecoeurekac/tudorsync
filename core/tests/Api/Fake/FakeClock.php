<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Api\Fake;

use DateTimeImmutable;
use Tudorsync\Core\Api\Auth\ClockInterface;

final class FakeClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2026-10-02 10:00:00')
    {
        $this->now = new DateTimeImmutable($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d seconds', $seconds));
    }
}
