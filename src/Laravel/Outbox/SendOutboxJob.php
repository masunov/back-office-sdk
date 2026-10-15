<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Laravel\Outbox;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Sends due messages of the outbox right after a push; the scheduler picks up whatever this one misses.
 */
class SendOutboxJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function handle(OutboxSender $sender): void
    {
        $sender->sendDue();
    }
}
