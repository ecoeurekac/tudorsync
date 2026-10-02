<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api\Exception;

/**
 * The token endpoint failed for a reason other than the credentials themselves (server error,
 * unexpected response): worth retrying later, nothing to change in the configuration.
 */
final class TokenRequestException extends TudorApiException
{
    public function __construct(
        public readonly int $statusCode,
        string $reason = 'request failed',
    ) {
        parent::__construct(sprintf('TUDOR token endpoint: %s (HTTP %d).', $reason, $statusCode));
    }
}
