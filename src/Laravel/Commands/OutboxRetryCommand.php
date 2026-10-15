<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Laravel\Commands;

use Illuminate\Console\Command;
use Mnd\BackOffice\Laravel\Outbox\OutboxMessage;

class OutboxRetryCommand extends Command
{
    protected $signature = 'back-office:outbox-retry {id?* : ids of failed messages} {--all : every failed message}';

    protected $description = 'Send failed messages of the back-office outbox again, with the same Idempotency-Key';

    public function handle(): int
    {
        $ids      = array_map('intval', (array) $this->argument('id'));
        $messages = OutboxMessage::failed()->filter(
            fn (OutboxMessage $message): bool => $this->option('all') || \in_array($message->id, $ids, true)
        );

        if ($ids === [] && ! $this->option('all')) {
            $this->error('Pass ids of failed messages or --all');

            return self::FAILURE;
        }

        foreach ($messages as $message) {
            $message->retry();
        }

        $this->info('Queued again: ' . $messages->count());

        return self::SUCCESS;
    }
}
