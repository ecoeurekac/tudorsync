<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api\Exception;

/**
 * An e-Stock API call answered with a non-2xx status. A 401 here means the access token was
 * refused even after requesting a fresh one.
 */
final class ApiResponseException extends TudorApiException
{
    /**
     * @param string $responseExcerpt Start of the response body, already redacted by the caller.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly int $statusCode,
        public readonly string $responseExcerpt = '',
    ) {
        $message = sprintf('TUDOR API error: HTTP %d on %s %s', $statusCode, $method, $path);

        if ($responseExcerpt !== '') {
            $message .= ': ' . $responseExcerpt;
        }

        parent::__construct($message);
    }
}
