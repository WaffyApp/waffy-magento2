<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Exception;

/**
 * Thrown when a feature is scaffolded but not yet implemented — currently
 * OAuthProvider, pending Waffy Auth Service support.
 */
class NotImplementedException extends WaffyException
{
}
