<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

/**
 * Contract/payment statuses Waffy sends in the webhook `status` field.
 *
 * The real inbound payload is `{ "contractId": "...", "status": "...",
 * "referenceId": "..." }`. Unknown values resolve to null via tryFrom() so
 * adapters can log-and-ignore rather than crash.
 *
 * CONFIRMED from live captures (2026-08-03, dev org "woocommerce"): after a real
 * buyer payment, the escrow sends `PARTIALLY_PAID` (funds now held in escrow) and
 * later `ITEM_ACCEPTED` (buyer accepted the delivered item). These are the
 * statuses the mapping below is keyed on.
 *
 * TBD(backend): the full authoritative lifecycle is not yet documented — likely
 * also a fully-paid state, a funds-released/completed state, and cancel/refund/
 * dispute states. See project-docs/04-open-questions.md. The cases marked
 * "assumed" below predate the live capture and are retained as harmless
 * fallbacks; confirm or prune them once Waffy provides the definitive list.
 */
enum WebhookStatus: string
{
    // ── Confirmed from live webhooks ────────────────────────────────────────
    /** Buyer's funds are secured in escrow (not yet released). Payment secured. */
    case PARTIALLY_PAID      = 'PARTIALLY_PAID';
    /** Buyer accepted the delivered item; triggers release of funds. */
    case ITEM_ACCEPTED       = 'ITEM_ACCEPTED';
    /** Settlement done — merchant cash-out approved, funds released. Terminal success. */
    case CASH_OUT_APPROVED   = 'CASH_OUT_APPROVED';

    // ── Assumed (pre-2026-08-03), retained pending backend confirmation ─────
    case CREATED             = 'CREATED';
    case PAYMENT_PROCESSING  = 'PAYMENT_PROCESSING';
    case PAID                = 'PAID';
    case ACCEPTED            = 'ACCEPTED';
    case CASHOUT_IN_PROGRESS = 'CASHOUT_IN_PROGRESS';
    case COMPLETED           = 'COMPLETED';
}
