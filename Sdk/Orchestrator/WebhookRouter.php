<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Orchestrator;

use Closure;
use Waffy\Ecommerce\Exception\WebhookException;
use Waffy\Ecommerce\Model\WebhookEvent;
use Waffy\Ecommerce\Model\WebhookEventType;
use Waffy\Ecommerce\Webhook\SignatureVerifier;

/**
 * Verifies and dispatches inbound webhook events to platform-specific
 * handlers.
 *
 * Platform adapters call dispatch() from their webhook controller passing
 * the raw HTTP body and signature header. The router:
 *   1. Verifies the signature (throws WebhookException on any failure)
 *   2. Parses the payload into a WebhookEvent
 *   3. Looks up handlers registered for the event type and invokes them
 *
 * Handlers are registered via on(WebhookEventType, Closure). Multiple
 * handlers can be registered for the same event — they all run in
 * registration order. If a handler throws, subsequent handlers still run;
 * the first thrown exception is rethrown after all handlers complete so the
 * caller can react.
 */
class WebhookRouter
{
    /**
     * @var array<string, list<Closure(WebhookEvent): void>>
     */
    private array $handlers = [];

    /**
     * @var list<Closure(WebhookEvent): void>
     */
    private array $defaultHandlers = [];

    public function __construct(private readonly SignatureVerifier $verifier)
    {
    }

    /**
     * Register a handler for a specific known event type.
     *
     * @param Closure(WebhookEvent): void $handler
     */
    public function on(WebhookEventType $type, Closure $handler): self
    {
        $this->handlers[$type->value][] = $handler;

        return $this;
    }

    /**
     * Register a fallback handler that runs for any event (known or
     * unknown). Useful for logging.
     *
     * @param Closure(WebhookEvent): void $handler
     */
    public function onAny(Closure $handler): self
    {
        $this->defaultHandlers[] = $handler;

        return $this;
    }

    /**
     * Verify, parse, and dispatch a webhook request.
     */
    public function dispatch(string $rawBody, string $signatureHeader): WebhookEvent
    {
        $this->verifier->verify($rawBody, $signatureHeader);

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new WebhookException('Webhook body is not a JSON object.');
        }

        $event = WebhookEvent::fromArray($payload);

        $thrown = null;
        $typeHandlers = $event->eventType !== null
            ? ($this->handlers[$event->eventType->value] ?? [])
            : [];

        foreach (array_merge($typeHandlers, $this->defaultHandlers) as $handler) {
            try {
                $handler($event);
            } catch (\Throwable $e) {
                $thrown ??= $e;
            }
        }

        if ($thrown !== null) {
            throw $thrown;
        }

        return $event;
    }
}
