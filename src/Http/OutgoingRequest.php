<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Http;

use Mnd\BackOffice\Dto\Actor;
use Ramsey\Uuid\Uuid;

/**
 * A request to the service as data: sent at once by the client or stored in the outbox and sent later.
 * A write gets its Idempotency-Key when it is built, so every retry of it is the same request to the service.
 * `stream` orders the outbox: requests of one stream go one after another (a reversal never before its operation).
 */
final class OutgoingRequest
{
    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly ?array $body = null,
        public readonly ?string $tenant = null,
        public readonly ?Actor $actor = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $stream = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public static function read(string $path, array $query = []): self
    {
        return new self('GET', $path, $query);
    }

    /**
     * A creating POST: needs an Idempotency-Key (ROADMAP.md, 2.4).
     *
     * @param  array<string, mixed>  $body
     */
    public static function create(string $path, array $body, ?string $stream = null): self
    {
        return new self('POST', $path, [], $body, null, null, Uuid::uuid7()->toString(), $stream);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function put(string $path, array $body, ?string $stream = null): self
    {
        return new self('PUT', $path, [], $body, null, null, null, $stream);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    public static function delete(string $path, ?array $body = null): self
    {
        return new self('DELETE', $path, [], $body);
    }

    public function forTenant(?string $tenant): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $tenant,
            $this->actor,
            $this->idempotencyKey,
            $this->stream
        );
    }

    public function actingAs(?Actor $actor): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->tenant,
            $actor,
            $this->idempotencyKey,
            $this->stream
        );
    }

    public function isRead(): bool
    {
        return $this->method === 'GET';
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return array_filter(
            [
                'X-Tenant'        => $this->tenant,
                'X-Actor-Id'      => $this->actor?->id,
                'X-Actor-Name'    => $this->actor?->name,
                'Idempotency-Key' => $this->idempotencyKey,
            ],
            fn (?string $value): bool => $value !== null && $value !== ''
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method'          => $this->method,
            'path'            => $this->path,
            'query'           => $this->query,
            'body'            => $this->body,
            'tenant'          => $this->tenant,
            'actor_id'        => $this->actor?->id,
            'actor_name'      => $this->actor?->name,
            'idempotency_key' => $this->idempotencyKey,
            'stream'          => $this->stream,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $actor = ($data['actor_id'] ?? null) !== null || ($data['actor_name'] ?? null) !== null
            ? new Actor($data['actor_id'] ?? null, $data['actor_name'] ?? null)
            : null;

        return new self(
            $data['method'],
            $data['path'],
            $data['query'] ?? [],
            $data['body'] ?? null,
            $data['tenant'] ?? null,
            $actor,
            $data['idempotency_key'] ?? null,
            $data['stream'] ?? null
        );
    }
}
