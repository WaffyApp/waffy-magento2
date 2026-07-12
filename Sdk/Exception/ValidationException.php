<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Exception;

/**
 * Thrown when input fails validation before being sent to Waffy — invalid
 * money amount, missing required order field, currency mismatch, etc.
 *
 * Distinct from ApiException (which reports failures from the Waffy
 * backend); ValidationException means we caught the bad input ourselves.
 */
class ValidationException extends WaffyException
{
}
