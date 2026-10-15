<?php

declare(strict_types=1);

return [

    // Address of the service and the key of this project (`php artisan platform:key-issue` on the service).
    'url' => env('BACK_OFFICE_URL'),
    'key' => env('BACK_OFFICE_KEY'),

    // Seconds. A read is retried after these delays (ms); a write never is, the outbox does it.
    'timeout'         => (float) env('BACK_OFFICE_TIMEOUT', 5),
    'connect_timeout' => 2,
    'read_retry_ms'   => [100, 400],

    // After `threshold` failures in a row requests fail at once for `cooldown` seconds. The state is in this cache
    // store (null — the default one); it must be shared by all workers, e.g. redis.
    'circuit_breaker' => [
        'threshold'   => 5,
        'cooldown'    => 30,
        'cache_store' => env('BACK_OFFICE_CACHE_STORE'),
    ],

    // Secret of the webhook (`php artisan platform:project-webhook` on the service); events older than `tolerance`
    // seconds are refused.
    'webhook' => [
        'secret'    => env('BACK_OFFICE_WEBHOOK_SECRET'),
        'tolerance' => 300,
    ],

    'outbox' => [
        // Send right after the transaction that pushed the message commits; the scheduler picks up the rest.
        'send_after_commit' => true,
        'queue_connection'  => env('BACK_OFFICE_QUEUE_CONNECTION'),
        'queue'             => env('BACK_OFFICE_QUEUE'),

        // Run `back-office:outbox-send` every minute from the scheduler of the project.
        'schedule' => true,

        // Messages one run takes; seconds a message is held by the run that sends it.
        'batch' => 100,
        'lease' => 60,

        // Delay before the retry n: base * 2^(n-1) seconds, at most `backoff_max`. While the service is unavailable
        // a message is retried without limit; a refusal that may pass later — up to `max_attempts` times.
        'backoff_base' => 10,
        'backoff_max'  => 600,
        'max_attempts' => 30,
    ],

];
