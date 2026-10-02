<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api\Exception;

/**
 * TUDOR's token endpoint (Okta) refused the client ID / client secret: wrong values, an
 * application not enabled for the e-Stock scope, or credentials from the other environment.
 */
final class CredentialsRejectedException extends TudorApiException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?string $oauthError = null,
    ) {
        $detail = $oauthError === null ? '' : ', ' . $oauthError;

        parent::__construct(sprintf('TUDOR rejected the client credentials (HTTP %d%s).', $statusCode, $detail));
    }
}
