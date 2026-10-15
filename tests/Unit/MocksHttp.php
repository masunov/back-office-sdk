<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A Guzzle client that answers from a queue and remembers what it was asked.
 */
trait MocksHttp
{
    /** @var list<array{request: RequestInterface}> */
    protected array $history = [];

    protected MockHandler $mock;

    protected function mockHttp(): Client
    {
        $this->history = [];
        $this->mock    = new MockHandler();
        $stack         = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected static function jsonResponse(int $status, ?array $body): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected static function apiError(int $status, string $code, array $details = []): Response
    {
        return self::jsonResponse($status, ['message' => 'Отказ', 'error' => ['code' => $code, 'message' => 'Отказ', ...$details]]);
    }

    protected function sentRequest(int $index = 0): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function operationData(array $overrides = []): array
    {
        return $overrides + [
            'id'               => '01920000-0000-7000-8000-000000000001',
            'direction'        => 'income',
            'kind'             => 'regular',
            'category'         => 'entry_fee',
            'category_id'      => '01920000-0000-7000-8000-0000000000aa',
            'amount'           => '1500.00',
            'operation_date'   => '2026-10-10',
            'source_type'      => 'stage_entry_fee',
            'source_id'        => '7',
            'external_ref'     => ['type' => 'stage', 'id' => '1', 'label' => 'Этап 1'],
            'counterparty'     => null,
            'counterparty_ref' => null,
            'comment'          => null,
            'actor'            => ['id' => '5', 'name' => 'Иван'],
            'reversal_of'      => null,
            'created_at'       => '2026-10-10T10:00:00+00:00',
        ];
    }
}
