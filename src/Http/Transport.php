<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use Mnd\BackOffice\Exceptions\ApiErrorException;
use Mnd\BackOffice\Exceptions\CircuitOpenException;
use Mnd\BackOffice\Exceptions\ServiceUnavailableException;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends requests to the service: the project key, the headers of the request context, retries of reads, the circuit
 * breaker. A write is never retried here: it either got an answer or goes back to the outbox of the project.
 */
final class Transport
{
    /**
     * @param  list<int>  $readRetryDelaysMs  delays before the retries of a read; their number is the number of retries
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $baseUrl,
        private readonly string $key,
        private readonly ?CircuitBreaker $circuitBreaker = null,
        private readonly array $readRetryDelaysMs = [100, 400],
        private readonly float $timeout = 5.0,
        private readonly float $connectTimeout = 2.0,
    ) {
    }

    /**
     * @return array<string, mixed> the decoded JSON body; empty for 204
     */
    public function send(OutgoingRequest $request): array
    {
        $response = $this->dispatch($request);
        $body     = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        $data = json_decode($body, true);

        if (! \is_array($data)) {
            throw new ServiceUnavailableException("Back-office answered not JSON to {$request->method} {$request->path}");
        }

        return $data;
    }

    /**
     * For a file, e.g. the xlsx export.
     */
    public function sendRaw(OutgoingRequest $request): string
    {
        return (string) $this->dispatch($request)->getBody();
    }

    private function dispatch(OutgoingRequest $request): ResponseInterface
    {
        if ($this->circuitBreaker?->isOpen()) {
            throw new CircuitOpenException('Back-office is unavailable: the circuit breaker is open');
        }

        $delays = $request->isRead() ? $this->readRetryDelaysMs : [];

        for ($attempt = 0;; $attempt++) {
            try {
                $response = $this->attempt($request);
                $this->circuitBreaker?->recordSuccess();

                return $response;
            } catch (ServiceUnavailableException $e) {
                $this->circuitBreaker?->recordFailure();

                if ($attempt >= \count($delays) || $this->circuitBreaker?->isOpen()) {
                    throw $e;
                }

                usleep($delays[$attempt] * 1000);
            }
        }
    }

    private function attempt(OutgoingRequest $request): ResponseInterface
    {
        $options = [
            RequestOptions::HEADERS         => [
                'Authorization' => 'Bearer ' . $this->key,
                'Accept'        => 'application/json',
                ...$request->headers(),
            ],
            RequestOptions::TIMEOUT         => $this->timeout,
            RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
            RequestOptions::HTTP_ERRORS     => false,
        ];

        if ($request->query !== []) {
            $options[RequestOptions::QUERY] = $this->query($request->query);
        }

        if ($request->body !== null) {
            $options[RequestOptions::JSON] = $request->body;
        }

        try {
            $response = $this->http->request($request->method, rtrim($this->baseUrl, '/') . $request->path, $options);
        } catch (TransferException | GuzzleException $e) {
            throw new ServiceUnavailableException(
                "Back-office is unreachable: {$e->getMessage()}",
                0,
                $e
            );
        }

        $status = $response->getStatusCode();

        if ($status >= 500) {
            throw new ServiceUnavailableException("Back-office answered {$status} to {$request->method} {$request->path}");
        }

        if ($status >= 400) {
            throw $this->apiError($response);
        }

        return $response;
    }

    private function apiError(ResponseInterface $response): ApiErrorException
    {
        $data  = json_decode((string) $response->getBody(), true);
        $error = \is_array($data) && \is_array($data['error'] ?? null) ? $data['error'] : [];
        $code  = \is_string($error['code'] ?? null) ? $error['code'] : 'http_' . $response->getStatusCode();

        $message = \is_string($error['message'] ?? null) ? $error['message'] : $response->getReasonPhrase();
        unset($error['code'], $error['message']);

        return new ApiErrorException($response->getStatusCode(), $code, $message, $error);
    }

    /**
     * `refs[]=a&refs[]=b`, as Laravel reads arrays, instead of the indexed `refs[0]=a` of http_build_query.
     *
     * @param  array<string, mixed>  $query
     */
    private function query(array $query): string
    {
        $parts = [];

        foreach ($query as $name => $value) {
            if ($value === null) {
                continue;
            }

            foreach ((array) $value as $item) {
                $parts[] = rawurlencode(\is_array($value) ? $name . '[]' : $name) . '=' . rawurlencode((string) $item);
            }
        }

        return implode('&', $parts);
    }
}
