<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

/**
 * Answer of the read-only `GET waffy-partner/customers/consent` lookup:
 * `{ linked, status, type, required }`.
 *
 * An unknown phone and a phone not linked to this client both answer
 * `linked: false` — the lookup never reveals whether the buyer exists
 * elsewhere.
 */
readonly class ConsentLookup
{
    public function __construct(
        public bool $linked,
        public ?string $status,
        public ?string $type,
        public bool $required,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromResponse(array $body): self
    {
        $consent = CustomerConsent::fromResponse($body);

        return new self(
            linked: ($body['linked'] ?? null) === true,
            status: $consent?->status,
            type: $consent?->type,
            required: $consent?->required ?? true,
        );
    }
}
