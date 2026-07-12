<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Exception;

/**
 * Thrown when an inbound webhook from Waffy fails verification — missing
 * signature, invalid signature, expired timestamp, or unparseable payload.
 *
 * The platform adapter should respond with HTTP 401 / 400 on these and NOT
 * apply any side effects to the order.
 */
class WebhookException extends WaffyException
{
}
