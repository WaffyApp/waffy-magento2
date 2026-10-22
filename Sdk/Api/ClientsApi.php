<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Api;

use Waffy\Ecommerce\Auth\TokenCache;
use Waffy\Ecommerce\Exception\ApiException;
use Waffy\Ecommerce\Exception\AuthException;
use Waffy\Ecommerce\Exception\ValidationException;
use Waffy\Ecommerce\Http\WaffyHttp;

/**
 * The auth host's client settings, managed by the organization's client admin.
 */
class ClientsApi
{
    public function __construct(
        private readonly WaffyHttp $http,
        private readonly TokenCache $tokens,
    ) {
    }

    /**
     * Register (or replace) the URL Waffy calls with this client's webhook
     * events, such as PARTIALLY_PAID and PAID.
     *
     * PATCH {authBase}/api/clients/{clientId} · Auth: Bearer merchantToken · Body: { webhookUrl }
     *
     * Only the client admin may call it, and only for its own client, so it
     * runs on the merchant token (step 1b). Waffy answers HTTP 200 with
     * `{"status": 204}`. A token without CLIENT_ADMIN is 403; every other
     * failure (blank URL, another client's id, a client missing from Waffy's
     * original client store) is 400 with a message. Waffy does not validate the
     * URL itself, so pass a public HTTPS endpoint.
     *
     * Retries once with a fresh merchant token on 401/403, the same rule
     * checkout applies.
     *
     * @throws ValidationException When $webhookUrl is blank.
     * @throws AuthException       When Waffy rejects the admin credentials.
     * @throws ApiException        When Waffy refuses the update.
     */
    public function setWebhookUrl(
        string $clientId,
        string $clientSecret,
        string $clientAdminEmail,
        string $clientAdminPassword,
        string $webhookUrl,
    ): void {
        $webhookUrl = trim($webhookUrl);
        if ($webhookUrl === '') {
            throw new ValidationException('setWebhookUrl: webhookUrl cannot be empty.');
        }

        TokenCache::retryOnUnauthorized(
            function (bool $useCache) use ($clientId, $clientSecret, $clientAdminEmail, $clientAdminPassword, $webhookUrl): void {
                $merchantToken = $this->tokens->ensureMerchantToken(
                    $clientId,
                    $clientSecret,
                    $clientAdminEmail,
                    $clientAdminPassword,
                    $useCache,
                );
                $path = '/api/clients/' . $clientId;
                $this->http->patchJson($merchantToken, $this->http->authUrl($path), $path, ['webhookUrl' => $webhookUrl]);
            },
        );
    }
}
