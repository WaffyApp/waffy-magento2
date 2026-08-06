<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Webhook;

use Waffy\Ecommerce\Model\WebhookEvent;
use Waffy\Ecommerce\Model\WebhookStatus;

/**
 * The platform-neutral result of processing a webhook: what should happen to
 * the order, plus suggested admin and buyer-facing comments.
 *
 * This is the reusable "what does this status mean" business logic that used
 * to live inline in each integration's webhook controller. Adapters call
 * {@see forEvent()} and then translate {@see $action} into their own order
 * state and persist the comments however the platform does it.
 */
readonly class WebhookOutcome
{
    public function __construct(
        public OrderAction $action,
        public string $adminComment,
        public string $customerComment,
    ) {
    }

    /**
     * Resolve the outcome for a parsed webhook event. Unknown/absent statuses
     * yield a NONE action with empty comments — callers can detect that via
     * `$event->status === null` and log it as unrecognised.
     */
    public static function forEvent(WebhookEvent $event): self
    {
        $ref = $event->referenceId !== '' ? ' (ref: ' . $event->referenceId . ')' : '';

        return match ($event->status) {
            // ── Confirmed live statuses (2026-08-03) ────────────────────────
            // Funds are secured in escrow — the merchant should start fulfilling.
            WebhookStatus::PARTIALLY_PAID => new self(
                OrderAction::MARK_PAYMENT_SECURED,
                'Waffy: payment secured in escrow (PARTIALLY_PAID). Milestone: ' . $event->contractId . $ref,
                'Your payment has been received and secured.',
            ),
            // Buyer accepted the delivered item; funds will be released. The order
            // is already Processing from PARTIALLY_PAID, so record a note only.
            // TBD(backend): confirm whether a distinct "completed/released" status
            // follows, and whether ITEM_ACCEPTED should complete the order.
            WebhookStatus::ITEM_ACCEPTED => new self(
                OrderAction::NONE,
                'Waffy: buyer accepted the item (ITEM_ACCEPTED)' . $ref . '.',
                'You have accepted the item. Thank you!',
            ),
            // Settlement done — merchant paid out. Terminal success → complete the order.
            WebhookStatus::CASH_OUT_APPROVED => new self(
                OrderAction::MARK_COMPLETED,
                'Waffy: settlement complete, funds released to merchant (CASH_OUT_APPROVED). Milestone: ' . $event->contractId . $ref,
                'Your order is complete.',
            ),

            WebhookStatus::CREATED => new self(
                OrderAction::NONE,
                'Waffy: contract created' . $ref . '.',
                '',
            ),
            WebhookStatus::PAYMENT_PROCESSING => new self(
                OrderAction::NONE,
                'Waffy: payment is being processed' . $ref . '.',
                'Your payment is being processed.',
            ),
            WebhookStatus::PAID => new self(
                OrderAction::MARK_PAYMENT_SECURED,
                'Waffy: payment secured in escrow. Milestone: ' . $event->contractId . $ref,
                'Your payment has been received and secured.',
            ),
            WebhookStatus::ACCEPTED => new self(
                OrderAction::MARK_PAYMENT_SECURED,
                'Waffy: payment accepted, contract awaiting settlement' . $ref . '.',
                'Your payment has been confirmed.',
            ),
            WebhookStatus::CASHOUT_IN_PROGRESS => new self(
                OrderAction::NONE,
                'Waffy: funds release in progress' . $ref . '.',
                'Your funds are being released.',
            ),
            WebhookStatus::COMPLETED => new self(
                OrderAction::NONE,
                'Waffy: escrow completed, funds released to merchant' . $ref . '.',
                'Your order has been completed.',
            ),
            null => new self(OrderAction::NONE, '', ''),
        };
    }
}
