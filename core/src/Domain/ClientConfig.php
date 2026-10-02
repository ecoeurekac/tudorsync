<?php

declare(strict_types=1);

namespace Tudorsync\Core\Domain;

/**
 * Per-store configuration: which TUDOR credentials, market and languages this installation
 * syncs under. Each platform module loads this from its own admin/config storage (Magento
 * system config, PrestaShop module config, WordPress Settings API) — core never assumes a
 * shared config store across clients, since the hybrid deployment model keeps every store
 * self-contained.
 *
 * Credentials are the OAuth2 client ID / client secret of the store's TUDOR (Okta)
 * application, one pair per Environment. TudorApiClient exchanges them for an access token.
 */
final class ClientConfig
{
    /**
     * @param string[] $languages ISO language codes this store offers, in the order URLs
     *                            should be tried before falling back to the default URL.
     */
    public function __construct(
        public readonly string $clientName,
        public readonly string $market,
        public readonly array $languages,
        public readonly Environment $environment,
        /**
         * @deprecated No longer used to authenticate: TUDOR uses OAuth2 client credentials
         *             ($clientId / $clientSecret). Kept so the platform modules keep working
         *             unchanged; to be removed once all three have migrated.
         */
        public readonly string $tudorApiKey = '',
        public readonly bool $offersClickAndCollect = false,
        public readonly string $clientId = '',
        #[\SensitiveParameter]
        public readonly string $clientSecret = '',
    ) {
    }
}
