<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Laravel\Events;

use Mnd\BackOffice\Webhook\WebhookEvent;

/**
 * An event of the service with a valid signature, seen for the first time. Listen to it in the project; a listener
 * that throws makes the service retry the event later.
 */
class WebhookReceived
{
    public function __construct(public readonly WebhookEvent $event)
    {
    }
}
