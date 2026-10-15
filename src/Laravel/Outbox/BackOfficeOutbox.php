<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Laravel\Outbox;

use Mnd\BackOffice\Dto\Actor;
use Mnd\BackOffice\Http\OutgoingRequest;

/**
 * Where the project puts the writes it must not lose (back-office ROADMAP.md, 2.7). Call `push` inside the
 * transaction of the change: the message is committed with it and sent after the commit, or later, when the service
 * is back.
 *
 *     DB::transaction(function () use ($entry, $user) {
 *         $entry->markFeePaid();
 *         BackOffice::outbox()->push(
 *             FinanceRequests::createOperation('entry_fee', $entry->fee, $entry->paid_at->toDateString(),
 *                 sourceType: 'stage_entry_fee', sourceId: $entry->id, externalRef: $entry->stage->ref()),
 *             tenant: $entry->organization_id,
 *             actor: new Actor($user->id, $user->name),
 *         );
 *     });
 */
class BackOfficeOutbox
{
    /**
     * @param  string|null  $stream  overrides the stream of the request; messages of one stream keep their order
     */
    public function push(
        OutgoingRequest $request,
        ?string $tenant,
        ?Actor $actor = null,
        ?string $stream = null,
    ): OutboxMessage {
        $message = OutboxMessage::enqueue(
            $request->forTenant($tenant)->actingAs($actor),
            $stream ?? $request->stream
        );

        if (config('back-office.outbox.send_after_commit')) {
            $job = new SendOutboxJob();
            $job->onConnection(config('back-office.outbox.queue_connection'))
                ->onQueue(config('back-office.outbox.queue'))
                ->afterCommit();
            dispatch($job);
        }

        return $message;
    }
}
