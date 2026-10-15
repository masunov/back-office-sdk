<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Laravel\Commands;

use Illuminate\Console\Command;
use Mnd\BackOffice\Laravel\Outbox\OutboxMessage;

class OutboxStuckCommand extends Command
{
    protected $signature = 'back-office:outbox-stuck {--attempts=10 : a pending message with this many attempts is stuck}';

    protected $description = 'List the messages of the back-office outbox that need a person';

    public function handle(): int
    {
        $messages = OutboxMessage::stuck((int) $this->option('attempts'));

        if ($messages->isEmpty()) {
            $this->info('Nothing is stuck');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'status', 'attempts', 'request', 'stream', 'last error', 'created'],
            $messages->map(
                fn (OutboxMessage $message): array => [
                    $message->id,
                    $message->status,
                    $message->attempts,
                    $message->request['method'] . ' ' . $message->request['path'],
                    $message->stream,
                    $message->last_error,
                    $message->created_at?->toDateTimeString(),
                ]
            )->all()
        );

        return self::SUCCESS;
    }
}
