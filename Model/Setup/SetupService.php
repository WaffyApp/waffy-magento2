<?php

declare(strict_types=1);

namespace Waffy\Payment\Model\Setup;

use Magento\Store\Model\StoreManagerInterface;
use Waffy\Ecommerce\Config\Environment;
use Waffy\Ecommerce\Config\WaffyEnvFile;
use Waffy\Ecommerce\Exception\ValidationException;
use Waffy\Ecommerce\Exception\WaffyException;
use Waffy\Ecommerce\Webhook\WaffyWebhookIps;
use Waffy\Payment\Model\OrchestratorFactory;

/**
 * The setup wizard's logic, independent of its controllers — the same rules as
 * the Shopify app's wizard:
 *
 *   connect() — turn an uploaded env file (or typed values) into a Connection.
 *               Secrets: typed, else from the file, else — only for the SAME
 *               client in the same environment — the saved ones.
 *   check()   — the live checklist, one Waffy credential at a time, ending with
 *               the webhook registration.
 */
class SetupService
{
    public function __construct(
        private readonly OrchestratorFactory $orchestratorFactory,
        private readonly StoreManagerInterface $storeManager,
    ) {}

    /**
     * @param array<string, string> $input  mode=file|manual, envJson, clientId,
     *        adminUsername, orgAccountId, idBaseUrl, authBaseUrl, apiBaseUrl,
     *        clientSecret, adminPassword
     *
     * @throws ValidationException With the merchant-facing reason.
     */
    public function connect(Environment $environment, array $input, ?Connection $saved): Connection
    {
        $field = static fn(string $name): string => trim((string) ($input[$name] ?? ''));

        if ($field('mode') === 'file') {
            $values = WaffyEnvFile::valuesFromPostmanJson($field('envJson'));
            if ($values === null) {
                throw new ValidationException('This is not a Waffy environment file: expected the JSON file exported from the Waffy portal.');
            }
        } else {
            $values = [
                'id_base_url'        => $field('idBaseUrl') ?: $environment->idBaseUrl(),
                'auth_base_url'      => $field('authBaseUrl') ?: $environment->authBaseUrl(),
                'api_base_url'       => $field('apiBaseUrl') ?: $environment->apiBaseUrl(),
                'client_id'          => $field('clientId'),
                'org_admin_username' => $field('adminUsername'),
                'org_account_id'     => $field('orgAccountId'),
            ];
        }

        $file = WaffyEnvFile::fromValues($values, $environment);

        // Reusing a saved secret with another client pairs one org's id with
        // another org's secret ("Bad credentials") — so only for the same one.
        $sameClient = $saved !== null && $saved->clientId === $file->clientId && $saved->environment === $environment;
        $sameAdmin  = $sameClient && $saved->adminUsername === $file->adminUsername;
        $secret     = $field('clientSecret') ?: ($file->clientSecret ?? '') ?: ($sameClient ? $saved->clientSecret : '');
        $password   = $field('adminPassword') ?: ($file->adminPassword ?? '') ?: ($sameAdmin ? $saved->adminPassword : '');

        if ($secret === '') {
            throw new ValidationException($saved ? 'Client changed — enter the client secret for the new client.' : 'Enter the client secret.');
        }
        if ($password === '') {
            throw new ValidationException($saved ? 'Admin changed — enter the admin password for the new admin.' : 'Enter the admin password.');
        }

        return new Connection(
            environment:      $environment,
            idBaseUrl:        $file->idBaseUrl,
            authBaseUrl:      $file->authBaseUrl,
            apiBaseUrl:       $file->apiBaseUrl,
            clientId:         $file->clientId,
            clientSecret:     $secret,
            adminUsername:    $file->adminUsername,
            adminPassword:    $password,
            orgAccountId:     $file->orgAccountId,
            organizationCode: $file->organizationCode,
        );
    }

    /**
     * Preview an uploaded env file without saving it: which environment it is
     * for, its main values, and every problem with it.
     *
     * @return array{detected: ?string, switchTo: ?string, values: ?array<string, string>, errors: list<string>}
     */
    public function preview(Environment $environment, string $json): array
    {
        $values = WaffyEnvFile::valuesFromPostmanJson($json);
        if ($values === null) {
            return [
                'detected' => null,
                'switchTo' => null,
                'values'   => null,
                'errors'   => ['This is not a Waffy environment file: expected the JSON file exported from the Waffy portal.'],
            ];
        }
        $detected = ($values['api_base_url'] ?? '') !== '' ? WaffyEnvFile::environmentOf($values['api_base_url']) : null;

        return [
            'detected' => $detected?->value,
            'switchTo' => $detected !== null && $detected !== $environment ? $detected->value : null,
            'values'   => [
                'clientId'      => $values['client_id'] ?? '',
                'adminUsername' => $values['org_admin_username'] ?? '',
                'orgAccountId'  => $values['org_account_id'] ?? '',
            ],
            'errors'   => array_values(WaffyEnvFile::errors($values, $environment)),
        ];
    }

    /**
     * The live checklist. A step that depends on a failed one is skipped.
     *
     * @return list<array{label: string, state: string, detail?: string}>
     */
    public function check(Connection $c): array
    {
        $orchestrator = $this->orchestratorFactory->forConnection($c);
        $checks = [];
        $attempt = function (string $label, bool $needs, callable $run) use (&$checks): bool {
            if (!$needs) {
                $checks[] = ['label' => $label, 'state' => 'skip'];

                return false;
            }
            try {
                $detail = $run();
                $checks[] = ['label' => $label, 'state' => 'pass'] + ($detail ? ['detail' => $detail] : []);

                return true;
            } catch (\Throwable $e) {
                $message = $e instanceof WaffyException ? ($e->getUserMessage() ?? $e->getMessage()) : $e->getMessage();
                $checks[] = ['label' => $label, 'state' => 'fail', 'detail' => $message];

                return false;
            }
        };

        $client = $attempt('Client ID and secret accepted by Waffy', true, function () use ($orchestrator, $c): ?string {
            $orchestrator->auth->fetchAppToken($c->clientId, $c->clientSecret);

            return null;
        });
        $admin = $attempt('Admin username and password accepted by Waffy', true, function () use ($orchestrator, $c): ?string {
            $orchestrator->auth->fetchMerchantToken($c->clientId, $c->clientSecret, $c->adminUsername, $c->adminPassword);

            return null;
        });
        $attempt('Client settings read', $client, function () use ($orchestrator, $c): string {
            $settings = $orchestrator->partner->getClientSettings($c->clientId, $c->clientSecret);

            return sprintf(
                'Buyer consent %s · checkout login: %s',
                $settings->autoAcceptConsent ? 'is automatic' : 'is asked by OTP at checkout',
                $settings->checkoutLoginMode ?? 'default',
            );
        });
        $webhookUrl = $this->webhookUrl();
        $attempt('Webhook URL registered with Waffy', $admin, function () use ($orchestrator, $c, $webhookUrl): string {
            $orchestrator->clients->setWebhookUrl($c->clientId, $c->clientSecret, $c->adminUsername, $c->adminPassword, $webhookUrl);

            return $webhookUrl;
        });
        $checks[] = [
            'label' => WaffyWebhookIps::isEnforced($c->environment)
                ? 'Webhooks accepted from Waffy addresses only'
                : 'Webhook sender check pending — Waffy has not published its addresses yet',
            'state' => 'info',
        ];

        return $checks;
    }

    /** This store's webhook endpoint, which the verify step registers with Waffy. */
    public function webhookUrl(): string
    {
        return rtrim($this->storeManager->getDefaultStoreView()->getBaseUrl(), '/') . '/waffy/webhook';
    }
}
