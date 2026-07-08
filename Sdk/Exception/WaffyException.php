<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Exception;

use RuntimeException;

/**
 * Base class for every exception thrown by the Waffy SDK. Catch this in
 * platform adapters to handle SDK errors uniformly.
 */
class WaffyException extends RuntimeException
{
}
