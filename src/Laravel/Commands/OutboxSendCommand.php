<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Laravel\Commands;

use Illuminate\Console\Command;
use Mnd\BackOffice\Laravel\Outbox\OutboxSender;

class OutboxSendCommand extends Command
{
    protected $signature = 'back-office:outbox-send {--limit= : messages to take}';

    protected $description = 'Send the due messages of the back-office outbox';

    public function handle(OutboxSender $sender): int
    {
        $limit  = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $result = $sender->sendDue($limit);

        $this->info(
            "Sent: {$result['sent']}, retry later: {$result['retried']}, failed: {$result['failed']}, waiting: "
            . $result['waiting']
        );

        return self::SUCCESS;
    }
}
