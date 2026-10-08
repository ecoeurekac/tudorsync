<?php

declare(strict_types=1);

namespace Tudorsync\Core\Sync;

use RuntimeException;
use Tudorsync\Core\Rules\Exclusion;

/**
 * The connector returned watches but none passed AvailabilityFilter. Nothing was sent: an
 * empty batch would set the retailer's whole catalog to 0 at TUDOR.
 */
final class NothingPassedReviewException extends RuntimeException
{
    /**
     * @param list<Exclusion> $exclusions
     */
    public function __construct(private readonly int $received, private readonly array $exclusions)
    {
        $byReason = [];
        foreach ($exclusions as $exclusion) {
            $byReason[$exclusion->message] = ($byReason[$exclusion->message] ?? 0) + 1;
        }
        arsort($byReason);

        $summary = [];
        foreach ($byReason as $message => $count) {
            $summary[] = sprintf('%d × %s', $count, $message);
        }

        parent::__construct(sprintf(
            'Ningún reloj ha superado la revisión: no se envía nada para no vaciar el catálogo en TUDOR (%d fichas recibidas; %s)',
            $received,
            $summary === [] ? 'sin motivos registrados' : implode('; ', $summary),
        ));
    }

    public function getReceived(): int
    {
        return $this->received;
    }

    /**
     * @return list<Exclusion>
     */
    public function getExclusions(): array
    {
        return $this->exclusions;
    }
}
