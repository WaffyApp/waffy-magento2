<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Exception;

/**
 * Thrown when authentication fails — invalid token, expired credentials,
 * refresh failure, or missing required auth configuration.
 */
class AuthException extends WaffyException
{
}
