<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Progress;

/**
 * The individual API calls that make up a Waffy checkout.
 *
 * These are the units a storefront can report on: each one is either performed
 * or served from the token cache, and every platform shows the same sequence in
 * the same order. Keeping the list here (rather than in each plugin's UI code)
 * means a change to the flow updates every progress display at once.
 *
 * @see \Waffy\Ecommerce\Orchestrator\EcomCheckoutOrchestrator
 */
enum CheckoutStep: string
{
    case AppToken        = 'app_token';         // 1a · client_credentials grant
    case MerchantToken   = 'merchant_token';    // 1b · admin password grant
    case CustomerSignUp  = 'customer_sign_up';  // 2  · register/lookup the buyer
    case CustomerToken   = 'customer_token';    // 3  · buyer password grant
    case CreateContract  = 'create_contract';   // 4
    case CreateMilestone = 'create_milestone';  // 5
    case AddParties      = 'add_parties';       // 6
    case StartPayment    = 'start_payment';     // 7 · returns the payment URL

    /**
     * Short buyer-facing description. Deliberately plain English rather than API
     * jargon: on a sandbox storefront these are read by whoever is testing the
     * integration, not only by developers.
     */
    public function label(): string
    {
        return match ($this) {
            self::AppToken        => 'Authorising store',
            self::MerchantToken   => 'Signing in merchant',
            self::CustomerSignUp  => 'Registering buyer',
            self::CustomerToken   => 'Signing in buyer',
            self::CreateContract  => 'Creating escrow contract',
            self::CreateMilestone => 'Adding payment milestone',
            self::AddParties      => 'Adding contract parties',
            self::StartPayment    => 'Preparing payment page',
        };
    }

    /**
     * True for the four auth steps, which a warm token cache can skip entirely.
     * The contract steps (4–7) always run — there is a new contract every order.
     */
    public function isCacheable(): bool
    {
        return match ($this) {
            self::AppToken, self::MerchantToken, self::CustomerSignUp, self::CustomerToken => true,
            default => false,
        };
    }

    /**
     * Every step in flow order — the sequence a progress display should render
     * before any of them has run.
     *
     * @return self[]
     */
    public static function sequence(): array
    {
        return self::cases();
    }
}
