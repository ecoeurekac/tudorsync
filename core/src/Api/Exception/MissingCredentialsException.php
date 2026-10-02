<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api\Exception;

/**
 * ClientConfig has no client ID or no client secret: nothing was sent to TUDOR.
 */
final class MissingCredentialsException extends TudorApiException
{
    public static function create(): self
    {
        return new self('TUDOR client credentials are not configured (client ID and client secret are required).');
    }
}
