<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api\Auth;

use JsonException;
use Tudorsync\Core\Api\Exception\CredentialsRejectedException;
use Tudorsync\Core\Api\Exception\MissingCredentialsException;
use Tudorsync\Core\Api\Exception\TokenRequestException;
use Tudorsync\Core\Api\HttpClientInterface;
use Tudorsync\Core\Domain\ClientConfig;

/**
 * Gets the access token for TUDOR's e-Stock API: OAuth2 client credentials against TUDOR's
 * Okta, one authorization server per environment. Token URLs and scope come from TUDOR's
 * official Postman collection (doc/eStock collections/) and were verified against PREPROD on
 * 2026-09-30 (intercambio/2026-09-30-juanjo-peticion-auth-y-url-tudor.md).
 *
 * The request is application/x-www-form-urlencoded, as Okta expects — TUDOR's HTML docs show
 * a JSON body, which doesn't work. Tokens last 300 s; one is kept in memory and reused until
 * EXPIRY_MARGIN_SECONDS before it expires.
 */
final class AccessTokenProvider
{
    public const EXPIRY_MARGIN_SECONDS = 30;

    private const TOKEN_URLS = [
        'staging' => 'https://login.rolex.com/oauth2/aus3qkuvb8CliPktG417/v1/token',
        'production' => 'https://login.rolex.com/oauth2/aus3rz4418Eok4GHr417/v1/token',
    ];

    private const SCOPE = 'com.myrolex.api.estock.publish app_owner';

    /** Used when the token response omits expires_in; it's what TUDOR returns today. */
    private const DEFAULT_EXPIRES_IN = 300;

    private ?string $accessToken = null;

    private int $expiresAt = 0;

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly ClientConfig $config,
        private readonly HttpClientInterface $httpClient,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * @throws MissingCredentialsException  no client ID / secret in ClientConfig (nothing is sent)
     * @throws CredentialsRejectedException the token endpoint refused the credentials
     * @throws TokenRequestException        any other token endpoint failure
     */
    public function getAccessToken(): string
    {
        if ($this->accessToken === null || $this->clock->now()->getTimestamp() >= $this->expiresAt) {
            $this->requestToken();
        }

        return $this->accessToken;
    }

    /**
     * Forget the cached token, e.g. after the API answered 401 with it.
     */
    public function invalidate(): void
    {
        $this->accessToken = null;
        $this->expiresAt = 0;
    }

    private function requestToken(): void
    {
        $this->invalidate();

        if (trim($this->config->clientId) === '' || trim($this->config->clientSecret) === '') {
            throw MissingCredentialsException::create();
        }

        $response = $this->httpClient->post(
            self::TOKEN_URLS[$this->config->environment->value],
            http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $this->config->clientId,
                'client_secret' => $this->config->clientSecret,
                'scope' => self::SCOPE,
            ]),
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
        );

        $data = $this->decode($response->body);

        if (in_array($response->statusCode, [400, 401, 403], true)) {
            throw new CredentialsRejectedException($response->statusCode, $this->oauthError($data));
        }

        if ($response->statusCode !== 200) {
            throw new TokenRequestException($response->statusCode);
        }

        $token = $data['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new TokenRequestException($response->statusCode, 'response has no access_token');
        }

        $tokenType = $data['token_type'] ?? 'Bearer';
        if (!is_string($tokenType) || strcasecmp($tokenType, 'Bearer') !== 0) {
            throw new TokenRequestException($response->statusCode, 'unexpected token_type');
        }

        $expiresIn = $data['expires_in'] ?? self::DEFAULT_EXPIRES_IN;
        $expiresIn = is_int($expiresIn) || ctype_digit((string) $expiresIn) ? (int) $expiresIn : self::DEFAULT_EXPIRES_IN;

        $this->accessToken = $token;
        $this->expiresAt = $this->clock->now()->getTimestamp() + max($expiresIn - self::EXPIRY_MARGIN_SECONDS, 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Okta's machine-readable error code (invalid_client, invalid_scope...), only if it looks
     * like one — never error_description or anything else from the body.
     *
     * @param array<string, mixed> $data
     */
    private function oauthError(array $data): ?string
    {
        $error = $data['error'] ?? null;

        return is_string($error) && preg_match('/^[a-z_]{1,64}$/', $error) === 1 ? $error : null;
    }
}
