<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api\Exception;

use RuntimeException;

/**
 * Base class for every error tudorsync/core raises while talking to TUDOR, so a platform
 * module can catch them all in one place and still tell them apart by subclass.
 *
 * Messages are meant to be shown as-is in an admin screen or a log: they never contain the
 * client secret or an access token.
 */
abstract class TudorApiException extends RuntimeException
{
}
