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
