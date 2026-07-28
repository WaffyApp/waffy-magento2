<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Support;

/**
 * Phone-number helpers shared by every platform integration.
 *
 * The Waffy API requires E.164 numbers (see the validation in
 * {@see \Waffy\Ecommerce\Dto\CustomerInfo} and {@see \Waffy\Ecommerce\Dto\Party}),
 * but storefronts hand us whatever the buyer typed — usually a local Saudi
 * number. Turning that into E.164 is the same rule for Magento, WooCommerce,
 * Shopify, Salla and Zid, so it lives here rather than in each adapter.
 */
class PhoneNumber
{
    // TODO(TBD): the +966 default assumes a Saudi market. When Waffy expands
    // beyond SA this should be parameterised (per-store calling code, or ask the
    // buyer for E.164 directly at checkout). See project-docs/04-open-questions.md.
    private const DEFAULT_CALLING_CODE = '966';

    /**
     * Best-effort normalisation of a local phone number to E.164.
     *
     *  - strips spaces, dashes and brackets;
     *  - a number already starting with "+" is left as-is;
     *  - "00…" (international prefix) becomes "+…";
     *  - a leading "0" is treated as a Saudi local number: 05XXXXXXXX → +9665XXXXXXXX;
     *  - anything else is assumed to be a Saudi number missing its trunk 0.
     *
     * Returns an empty string when there is nothing to normalise. Callers decide
     * how to handle a missing number — we never fabricate a placeholder.
     */
    public static function toE164(string $raw): string
    {
        $phone = preg_replace('/[\s\-().]/', '', $raw) ?? '';

        if ($phone === '') {
            return '';
        }
        if (str_starts_with($phone, '+')) {
            return $phone;
        }
        if (str_starts_with($phone, '00')) {
            return '+' . substr($phone, 2);
        }
        if (str_starts_with($phone, '0')) {
            return '+' . self::DEFAULT_CALLING_CODE . substr($phone, 1);
        }

        return '+' . self::DEFAULT_CALLING_CODE . $phone;
    }

    /**
     * Derive the stable Waffy identity key for a buyer from their E.164 number:
     * the digits with the leading "+" removed. This is the identifier used for
     * sign-up, login and the customer-token cache key, so sign-up and login
     * always resolve to the same user.
     */
    public static function toClientUserId(string $e164): string
    {
        return ltrim($e164, '+');
    }
}
