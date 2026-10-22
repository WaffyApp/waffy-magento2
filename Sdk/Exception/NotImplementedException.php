<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Exception;

/**
 * Thrown by an SDK call whose Waffy endpoint does not exist yet. Callers should
 * treat it as a soft failure: log it and retry later, never block a buyer on it.
 */
class NotImplementedException extends WaffyException
{
}
