<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Config;

use Waffy\Ecommerce\Dto\Party;
use Waffy\Ecommerce\Exception\ValidationException;
use Waffy\Ecommerce\Support\PhoneNumber;

/**
 * The connection settings a merchant gets from the Waffy portal: the Postman
 * environment file it exports for a client, or the same values typed in by hand.
 *
 * Every platform's setup wizard validates through here, so all of them accept
 * the same files and show the same errors. Only these keys are read; the rest
 * of the file (customer_*, consent_*, …) is ignored:
 *
 *   id_base_url, auth_base_url, api_base_url — the Waffy hosts
 *   client_id                                — the API client created in the portal
 *   org_admin_username                       — the client admin's sign-in: an E.164 mobile or an email
 *   org_account_id                           — the organization's Waffy account (the contract PROVIDER)
 *   organization_code                        — optional, shown for reference
 *   client_secret, org_admin_password        — optional; the portal leaves them empty,
 *                                              so the merchant types them in
 *
 * A host whose name has a `dev` or `stg` label (id-dev, dev-auth, stg-api, …)
 * is a sandbox host; anything else is live. Values for one environment are
 * refused when the merchant selected the other.
 */
readonly class WaffyEnvFile
{
    public const REQUIRED_KEYS = [
        'id_base_url',
        'auth_base_url',
        'api_base_url',
        'client_id',
        'org_admin_username',
        'org_account_id',
    ];

    public const URL_KEYS = ['id_base_url', 'auth_base_url', 'api_base_url'];

    public function __construct(
        public Environment $environment,
        public string $idBaseUrl,
        public string $authBaseUrl,
        public string $apiBaseUrl,
        public string $clientId,
        public string $adminUsername,
        public string $orgAccountId,
        public ?string $organizationCode = null,
        public ?string $clientSecret = null,
        public ?string $adminPassword = null,
    ) {
    }

    /**
     * Parse and validate a Postman environment export.
     *
     * @throws ValidationException With one line per problem; see errors() for
     *                             the same problems keyed by field.
     */
    public static function fromPostmanJson(string $json, Environment $expected): self
    {
        $values = self::valuesFromPostmanJson($json);
        if ($values === null) {
            throw new ValidationException('This is not a Waffy environment file: expected the JSON file exported from the Waffy portal.');
        }

        return self::fromValues($values, $expected);
    }

    /**
     * Validate values typed in by hand, keyed like the file (client_id, …).
     *
     * @param array<string, string|null> $values
     * @throws ValidationException
     */
    public static function fromValues(array $values, Environment $expected): self
    {
        $errors = self::errors($values, $expected);
        if ($errors !== []) {
            throw new ValidationException(implode("\n", $errors));
        }

        $get      = static fn (string $key): string => trim((string) ($values[$key] ?? ''));
        $optional = static fn (string $key): ?string => $get($key) === '' ? null : $get($key);

        return new self(
            environment:      $expected,
            idBaseUrl:        rtrim($get('id_base_url'), '/'),
            authBaseUrl:      rtrim($get('auth_base_url'), '/'),
            apiBaseUrl:       rtrim($get('api_base_url'), '/'),
            clientId:         $get('client_id'),
            adminUsername:    (string) self::adminUsername($get('org_admin_username')),
            orgAccountId:     $get('org_account_id'),
            organizationCode: $optional('organization_code'),
            clientSecret:     $optional('client_secret'),
            adminPassword:    $optional('org_admin_password'),
        );
    }

    /**
     * Every problem with these values, keyed by field — empty when they are valid.
     * The messages are written for the merchant.
     *
     * @param array<string, string|null> $values
     * @return array<string, string>
     */
    public static function errors(array $values, Environment $expected): array
    {
        $errors = [];
        $get    = static fn (string $key): string => trim((string) ($values[$key] ?? ''));

        foreach (self::REQUIRED_KEYS as $key) {
            if ($get($key) === '') {
                $errors[$key] = sprintf('%s is missing.', $key);
            }
        }

        $detected = [];
        foreach (self::URL_KEYS as $key) {
            $url = $get($key);
            if ($url === '') {
                continue;
            }
            $host = self::waffyHost($url);
            if ($host === null) {
                $errors[$key] = sprintf('%s must be an https://….waffyapp.com address, got "%s".', $key, $url);
                continue;
            }
            $detected[] = self::environmentOf($url);
        }

        $detected = array_values(array_unique(array_map(static fn (Environment $e): string => $e->value, $detected)));
        if (count($detected) > 1) {
            $errors['environment'] = 'These addresses mix sandbox (dev/stg) and live hosts — use the file of a single environment.';
        } elseif ($detected !== [] && $detected[0] !== $expected->value) {
            $errors['environment'] = $detected[0] === Environment::Sandbox->value
                ? 'These are sandbox values (dev/stg hosts) but Live is selected. Switch to Sandbox, or use the live file.'
                : 'These are live values but Sandbox is selected. Switch to Live, or use the sandbox file.';
        }

        $clientId = $get('client_id');
        if ($clientId !== '' && preg_match('/\s/', $clientId) === 1) {
            $errors['client_id'] = 'client_id must not contain spaces.';
        }

        $admin = $get('org_admin_username');
        if ($admin !== '' && self::adminUsername($admin) === null) {
            $errors['org_admin_username'] = sprintf('org_admin_username must be the admin\'s mobile number or email, got "%s".', $admin);
        }

        $account = $get('org_account_id');
        if ($account !== '' && !Party::isAccountId($account)) {
            $errors['org_account_id'] = sprintf('org_account_id must be a number, got "%s".', $account);
        }

        return $errors;
    }

    /**
     * The client admin's sign-in, normalised: an email (lower-cased) or a
     * mobile (E.164) — Waffy ID accepts either as the username. Null when it
     * is neither.
     */
    public static function adminUsername(string $value): ?string
    {
        $value = trim($value);
        if (str_contains($value, '@')) {
            return preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $value) === 1 ? strtolower($value) : null;
        }
        $mobile = PhoneNumber::toE164($value);

        return PhoneNumber::isValidE164($mobile) ? $mobile : null;
    }

    /**
     * Which environment a Waffy host belongs to: Sandbox when any part of its
     * name is `dev` or `stg` (id-dev, dev-auth, stg-api, api-stg, …), else Live.
     */
    public static function environmentOf(string $url): Environment
    {
        $host   = strtolower((string) parse_url($url, PHP_URL_HOST));
        $labels = preg_split('/[.\-]/', $host) ?: [];

        return array_intersect($labels, ['dev', 'stg']) !== [] ? Environment::Sandbox : Environment::Production;
    }

    /**
     * The `values[]` of a Postman environment as key => value, skipping
     * disabled rows; null when the text is not such a file.
     *
     * @return array<string, string>|null
     */
    public static function valuesFromPostmanJson(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !is_array($decoded['values'] ?? null)) {
            return null;
        }

        $values = [];
        foreach ($decoded['values'] as $row) {
            if (!is_array($row) || !is_string($row['key'] ?? null) || ($row['enabled'] ?? true) === false) {
                continue;
            }
            $value               = $row['value'] ?? '';
            $values[$row['key']] = is_scalar($value) ? (string) $value : '';
        }

        return $values;
    }

    /** The lower-cased host when $url is https on waffyapp.com, else null. */
    private static function waffyHost(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);

        return $host === 'waffyapp.com' || str_ends_with($host, '.waffyapp.com') ? $host : null;
    }
}
