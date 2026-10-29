<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Api;

use Waffy\Ecommerce\Auth\TokenCache;
use Waffy\Ecommerce\Dto\CustomerInfo;
use Waffy\Ecommerce\Exception\AuthException;
use Waffy\Ecommerce\Http\WaffyHttp;
use Waffy\Ecommerce\Model\ClientSettings;
use Waffy\Ecommerce\Model\ConsentLinkResult;
use Waffy\Ecommerce\Model\ConsentLookup;
use Waffy\Ecommerce\Model\CustomerConsent;
use Waffy\Ecommerce\Model\CustomerRegistration;
use Waffy\Ecommerce\Model\OtpSendResult;

/**
 * The Waffy ID partner endpoints, `{idBase}/realms/waffy/waffy-partner/*`:
 * buyer registration, buyer consent (OTP and signed link) and the client's
 * settings. Keycloak organizations only.
 *
 * Buyer consent, before initiateCheckout():
 *   prepareCustomer() registers the buyer and reports consentRequired(). When
 *   it is true, the storefront asks for an OTP — sendOtp(), then verifyOtp()
 *   with the code the buyer typed — and then checks out. The ticket is the
 *   payment page's only buyer credential; the phone `login_hint` alternative
 *   is not implemented.
 *
 * Each call that takes credentials authenticates with the org token (step 1a),
 * served from the TokenStore when still valid, and retried once with a fresh
 * one on 401/403.
 */
class PartnerApi
{
    public function __construct(
        private readonly WaffyHttp $http,
        private readonly TokenCache $tokens,
    ) {
    }

    /**
     * Step 2 (Keycloak) — register or link the buyer to this organization.
     * Auth: Bearer orgToken (the client_credentials token from step 1a)
     * Body: { phoneNumber, firstName, lastName, email?, consentMethod? }
     * Returns: Waffy's ids for the buyer, plus the `consent` object.
     *
     * Replaces the legacy `/v2/api/users/sign-up` call and is likewise
     * idempotent: an already-linked buyer returns their existing identifiers and
     * current consent, and nothing is created or sent.
     *
     * Consent is the part with no legacy equivalent. Unless the organization
     * carries `auto-accept-consent`, the link starts PENDING and the buyer has
     * to approve it. This call reports the state and never asserts on it: a
     * payment ticket is still issued while consent is pending, and Waffy's
     * payment page falls back to a live login. Callers that want to collect
     * consent up front branch on consentRequired() — see prepareCustomer().
     *
     * @param string|null $consentMethod 'sms', 'otp' or null — see CheckoutRequest.
     */
    public function registerCustomer(string $orgToken, CustomerInfo $customer, ?string $consentMethod = null): CustomerRegistration
    {
        $body = [
            'phoneNumber' => $customer->phoneNumber,
            'firstName'   => $customer->firstName,
            'lastName'    => $customer->lastName,
        ];
        if ($customer->email !== null && $customer->email !== '') {
            $body['email'] = $customer->email;
        }
        if ($consentMethod !== null && $consentMethod !== '') {
            $body['consentMethod'] = $consentMethod;
        }

        $data                               = $this->http->partnerPost($orgToken, '/customers', 'Waffy customer registration failed', $body);
        [$waffyUserId, $userIdentification] = $this->customerIds($data, 'Waffy customer registration');

        return new CustomerRegistration(
            waffyUserId: $waffyUserId,
            userIdentification: $userIdentification,
            consent: CustomerConsent::fromResponse($data['consent'] ?? null),
        );
    }

    /**
     * Register or re-link the buyer ahead of checkout and report whether the
     * organization must collect their consent first.
     *
     * The entry point of the consent flow: call it when the buyer reaches
     * checkout, and when consentRequired() is true ask for an OTP — sendOtp(),
     * then verifyOtp() with the code they type — before initiateCheckout(). A
     * buyer who consented once answers consentRequired() false from then on.
     *
     * @throws AuthException On a legacy organization, or when Waffy rejects the call.
     */
    public function prepareCustomer(
        string $clientId,
        string $clientSecret,
        CustomerInfo $customer,
        ?string $consentMethod = null,
    ): CustomerRegistration {
        $this->requireKeycloak('prepareCustomer');

        return $this->withOrgToken(
            $clientId,
            $clientSecret,
            fn (string $orgToken): CustomerRegistration => $this->registerCustomer($orgToken, $customer, $consentMethod),
        );
    }

    /**
     * Send a Waffy-issued OTP to the buyer's phone.
     * POST {kc}/waffy-partner/otp/send · Body: { phoneNumber } → { sent: true }
     *
     * Needs no prior registration. The storefront collects the code and passes
     * it to verifyOtp(). Check OtpSendResult::$sent before showing a code field.
     *
     * @throws AuthException 429 when the org's hourly OTP limit is reached,
     *   400 for an unrecognisable phone, 502 when dispatch failed (retry).
     */
    public function sendOtp(string $clientId, string $clientSecret, string $phoneNumber): OtpSendResult
    {
        $this->requireKeycloak('sendOtp');

        $data = $this->withOrgToken(
            $clientId,
            $clientSecret,
            fn (string $orgToken): array => $this->http->partnerPost($orgToken, '/otp/send', 'Waffy OTP send failed', [
                'phoneNumber' => $phoneNumber,
            ]),
        );

        return OtpSendResult::fromResponse($data);
    }

    /**
     * Check the OTP the buyer typed. Success records consent as
     * LIVE_OTP_VERIFIED — reversing an earlier REJECTED — and resolves-or-creates
     * the buyer in the same call.
     * POST {kc}/waffy-partner/otp/verify
     *   Body: { phoneNumber, otp } — or, with $customer, also firstName,
     *   lastName and email, used only if the buyer has no Waffy account yet.
     *   → { waffyUserId, userIdentification }
     *
     * @return array{waffyUserId: string, userIdentification: ?string}
     *
     * @throws AuthException 400 for a wrong or expired code (no side effects;
     *   getUserMessage() carries Waffy's text), 502 for a downstream failure.
     */
    public function verifyOtp(
        string $clientId,
        string $clientSecret,
        string $phoneNumber,
        string $otp,
        ?CustomerInfo $customer = null,
    ): array {
        $this->requireKeycloak('verifyOtp');

        $body = ['phoneNumber' => $phoneNumber, 'otp' => $otp];
        if ($customer !== null) {
            $body['firstName'] = $customer->firstName;
            $body['lastName']  = $customer->lastName;
            if ($customer->email !== null && $customer->email !== '') {
                $body['email'] = $customer->email;
            }
        }

        $data = $this->withOrgToken(
            $clientId,
            $clientSecret,
            fn (string $orgToken): array => $this->http->partnerPost($orgToken, '/otp/verify', 'Waffy OTP verification failed', $body),
        );
        [$waffyUserId, $userIdentification] = $this->customerIds($data, 'Waffy OTP verification');

        return ['waffyUserId' => $waffyUserId, 'userIdentification' => $userIdentification];
    }

    /**
     * (Re)send the signed consent link by SMS to an already-linked buyer.
     * POST {kc}/waffy-partner/consent/send · Body: { phoneNumber }
     *
     * Sends nothing, without failing, when the buyer is already VERIFIED or a
     * link went out less than 60 seconds ago — see ConsentLinkResult.
     *
     * @throws AuthException 400 when the buyer is not linked to this client,
     *   429 on the org's hourly limit, 502 when the SMS could not be dispatched.
     */
    public function sendConsentLink(string $clientId, string $clientSecret, string $phoneNumber): ConsentLinkResult
    {
        $this->requireKeycloak('sendConsentLink');

        $data = $this->withOrgToken(
            $clientId,
            $clientSecret,
            fn (string $orgToken): array => $this->http->partnerPost($orgToken, '/consent/send', 'Waffy consent link send failed', [
                'phoneNumber' => $phoneNumber,
            ]),
        );

        return ConsentLinkResult::fromResponse($data);
    }

    /**
     * Where a buyer stands with this client, without registering anyone.
     * GET {kc}/waffy-partner/customers/consent?phoneNumber=… → { linked, status, type, required }
     *
     * Read-only; shares the hourly limit of sendConsentLink().
     */
    public function getConsentStatus(string $clientId, string $clientSecret, string $phoneNumber): ConsentLookup
    {
        $this->requireKeycloak('getConsentStatus');

        $data = $this->withOrgToken(
            $clientId,
            $clientSecret,
            fn (string $orgToken): array => $this->http->partnerGet($orgToken, '/customers/consent', 'Waffy consent lookup failed', [
                'phoneNumber' => $phoneNumber,
            ]),
        );

        return ConsentLookup::fromResponse($data);
    }

    /**
     * The calling client's settings, read live.
     * GET {kc}/waffy-partner/client → { clientId, autoAcceptConsent, checkoutLoginMode }
     */
    public function getClientSettings(string $clientId, string $clientSecret): ClientSettings
    {
        $this->requireKeycloak('getClientSettings');

        $data = $this->withOrgToken(
            $clientId,
            $clientSecret,
            fn (string $orgToken): array => $this->http->partnerGet($orgToken, '/client', 'Waffy client settings request failed'),
        );

        return ClientSettings::fromResponse($data);
    }

    private function requireKeycloak(string $method): void
    {
        if (!$this->http->isKeycloak()) {
            throw new AuthException(
                $method . '() is a Waffy ID (keycloak) method: legacy organizations have no buyer consent step.',
            );
        }
    }

    /**
     * Run one partner call with the org token, under the 401/403 retry rule.
     *
     * @template T
     * @param callable(string $orgToken): T $call
     * @return T
     */
    private function withOrgToken(string $clientId, string $clientSecret, callable $call): mixed
    {
        return TokenCache::retryOnUnauthorized(
            fn (bool $useCache): mixed => $call($this->tokens->ensureAppToken($clientId, $clientSecret, $useCache)),
        );
    }

    /**
     * The two buyer ids every partner customer/OTP response carries.
     *
     * @param array<string, mixed> $data
     * @return array{0: string, 1: ?string}
     */
    private function customerIds(array $data, string $context): array
    {
        $waffyUserId = $data['waffyUserId'] ?? null;
        if (!is_string($waffyUserId) && !is_int($waffyUserId) && !is_float($waffyUserId)) {
            throw new AuthException($context . ' response missing waffyUserId.');
        }

        $userIdentification = $data['userIdentification'] ?? null;

        return [
            (string) $waffyUserId,
            is_string($userIdentification) || is_int($userIdentification) || is_float($userIdentification)
                ? (string) $userIdentification
                : null,
        ];
    }
}
