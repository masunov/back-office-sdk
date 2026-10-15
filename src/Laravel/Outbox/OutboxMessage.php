<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Laravel\Outbox;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Mnd\BackOffice\Http\OutgoingRequest;
use Ramsey\Uuid\Uuid;

/**
 * A request to back-office waiting to be sent (back-office ROADMAP.md, 2.7). Written in the transaction of the change
 * in the project, so the change and its request are kept or lost together. Messages of one stream are sent in the
 * order of their ids; a failed message holds its stream until it is retried.
 *
 * @property int                       $id
 * @property string|null               $stream
 * @property array<string, mixed>      $request
 * @property string                    $status
 * @property int                       $attempts
 * @property Carbon|null               $next_attempt_at
 * @property int|null                  $last_status
 * @property string|null               $last_error
 * @property array<string, mixed>|null $response
 * @property Carbon|null               $sent_at
 * @property Carbon|null               $failed_at
 * @property Carbon|null               $created_at
 * @property Carbon|null               $updated_at
 */
class OutboxMessage extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';

    protected $table = 'back_office_outbox';

    protected $fillable = [
        'id',
        'stream',
        'request',
        'status',
        'next_attempt_at',
    ];

    /**
     * A property, not casts(): the package supports Laravel 10.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'request'         => 'array',
        'response'        => 'array',
        'attempts'        => 'integer',
        'last_status'     => 'integer',
        'next_attempt_at' => 'datetime',
        'sent_at'         => 'datetime',
        'failed_at'       => 'datetime',
    ];

    public static function enqueue(OutgoingRequest $request, ?string $stream): self
    {
        return self::query()->create(
            [
                'id'              => Uuid::uuid7()->toString(),
                'stream'          => $stream,
                'request'         => $request->toArray(),
                'status'          => self::STATUS_PENDING,
                'next_attempt_at' => Carbon::now(),
            ]
        );
    }

    /**
     * @return list<int>
     */
    public static function dueIds(int $limit): array
    {
        return self::query()
                   ->where('status', self::STATUS_PENDING)
                   ->where('next_attempt_at', '<=', Carbon::now())
                   ->orderBy('id')
                   ->limit($limit)
                   ->pluck('id')
                   ->map(fn($id): int => (int)$id)
                   ->all();
    }

    /**
     * Takes the message for one attempt with a single conditional UPDATE: of two senders one gets it,
     * and a sender that died midway leaves it due again after the lease.
     */
    public static function claim(int $id, int $leaseSeconds): ?self
    {
        $now     = Carbon::now();
        $claimed = self::query()
                       ->whereKey($id)
                       ->where('status', self::STATUS_PENDING)
                       ->where('next_attempt_at', '<=', $now)
                       ->update(['next_attempt_at' => $now->copy()->addSeconds($leaseSeconds), 'updated_at' => $now]);

        return $claimed === 1 ? self::query()->find($id) : null;
    }

    /**
     * Messages that need a person: refused by the service, or retried for too long (the «stuck» screen).
     *
     * @return Collection<int, self>
     */
    public static function stuck(int $attemptsAtLeast = 10): Collection
    {
        return self::query()
                   ->where('status', self::STATUS_FAILED)
                   ->orWhere(
                       fn($query) => $query->where('status', self::STATUS_PENDING)->where('attempts', '>=', $attemptsAtLeast)
                   )
                   ->orderBy('id')
                   ->get();
    }

    /**
     * @return Collection<int, self>
     */
    public static function failed(): Collection
    {
        return self::query()->where('status', self::STATUS_FAILED)->orderBy('id')->get();
    }

    public function outgoingRequest(): OutgoingRequest
    {
        return OutgoingRequest::fromArray($this->request);
    }

    /**
     * An earlier message of the stream is not sent yet: this one waits for it.
     */
    public function hasUnsentPredecessor(): bool
    {
        if ($this->stream === null) {
            return false;
        }

        return self::query()
                   ->where('stream', $this->stream)
                   ->where('id', '<', $this->id)
                   ->where('status', '!=', self::STATUS_SENT)
                   ->exists();
    }

    /**
     * Back to the queue without an attempt: it waited for its predecessor or for the circuit breaker.
     */
    public function release(?Carbon $at = null): void
    {
        $this->next_attempt_at = $at ?? Carbon::now();
        $this->save();
    }

    /**
     * @param array<string, mixed> $response
     */
    public function markSent(array $response): void
    {
        $this->attempts++;
        $this->status          = self::STATUS_SENT;
        $this->sent_at         = Carbon::now();
        $this->next_attempt_at = null;
        $this->response        = $response;
        $this->last_error      = null;
        $this->save();
    }

    public function scheduleRetry(Carbon $at, ?int $status, string $error): void
    {
        $this->attempts++;
        $this->next_attempt_at = $at;
        $this->last_status     = $status;
        $this->last_error      = $error;
        $this->save();
    }

    public function markFailed(?int $status, string $error): void
    {
        $this->attempts++;
        $this->status          = self::STATUS_FAILED;
        $this->failed_at       = Carbon::now();
        $this->next_attempt_at = null;
        $this->last_status     = $status;
        $this->last_error      = $error;
        $this->save();
    }

    /**
     * Sends a failed message again, with the same Idempotency-Key: after the cause was fixed in the project or the
     * service. Its stream goes on after it.
     */
    public function retry(): void
    {
        $this->status          = self::STATUS_PENDING;
        $this->failed_at       = null;
        $this->next_attempt_at = Carbon::now();
        $this->save();
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }
}
